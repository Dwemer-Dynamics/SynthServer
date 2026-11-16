param(
    [switch]$Reset
)

$ErrorActionPreference = "Stop"

$database = "synth"
$owner = "dwemer"
$serverRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
# The schema migration owner needs the locked production dependencies. Refuse before touching the database.
if (-not (Test-Path -LiteralPath (Join-Path $serverRoot "vendor\autoload.php") -PathType Leaf)) {
    throw "Synthserver production dependencies are missing: run 'composer install --no-dev' in $serverRoot (it installs the tracked composer.lock), then rerun this script. No database was changed."
}
$bootstrapWin = Resolve-Path (Join-Path $serverRoot "tools\bootstrap-database.php")
$bootstrapWslPath = $bootstrapWin.Path.Replace('\', '/')
$bootstrapLinux = (& wsl.exe -- wslpath -a -u -- $bootstrapWslPath).Trim()

Write-Host "Ensuring WSL PostgreSQL database '$database' exists with owner '$owner'."

if ($Reset) {
    Write-Host "Reset requested; dropping '$database'."
    & wsl.exe -- sudo -n -u postgres dropdb --maintenance-db=template1 --if-exists $database
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to drop PostgreSQL database '$database'."
    }
}

$databaseExists = & wsl.exe -- sudo -n -u postgres psql -d template1 -Atqc "SELECT 1 FROM pg_database WHERE datname='$database';"
$databaseExists = if ($null -eq $databaseExists) { "" } else { ([string]$databaseExists).Trim() }
if ($databaseExists -ne "1") {
    & wsl.exe -- sudo -n -u postgres createdb --maintenance-db=template1 -O $owner --encoding=UTF8 --locale=C --template=template0 $database
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to create PostgreSQL database '$database'."
    }
} else {
    Write-Host "Database '$database' already exists."
}

$databaseEncoding = & wsl.exe -- sudo -n -u postgres psql -d $database -Atqc "SHOW server_encoding;"
$databaseEncoding = if ($null -eq $databaseEncoding) { "" } else { ([string]$databaseEncoding).Trim() }
if ($databaseEncoding -ne 'UTF8') {
    throw "Database '$database' uses $databaseEncoding. Run tools/migrate-synth-db-utf8-wsl.sh inside WSL before continuing."
}

& wsl.exe -- sudo -n -u postgres psql -d $database -v ON_ERROR_STOP=1 `
    -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;" `
    -c "CREATE EXTENSION IF NOT EXISTS vector;" `
    -c "ALTER DATABASE $database OWNER TO $owner;"

if ($LASTEXITCODE -ne 0) {
    throw "Failed to prepare PostgreSQL database '$database'."
}

# No separate schema import: on an empty database the schema migration owner installs the complete baseline schema
# and stock seeds in one transaction. An existing database is upgraded or verified as before, never reset here.
Write-Host "Running SYNTH schema migrations."
& wsl.exe -- php $bootstrapLinux
if ($LASTEXITCODE -ne 0) {
    throw "SYNTH database updates failed."
}

Write-Host "Database '$database' is ready."
