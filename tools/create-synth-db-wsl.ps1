param(
    [switch]$Reset
)

$ErrorActionPreference = "Stop"

$database = "synth"
$owner = "dwemer"
$password = "dwemer"
$serverRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$baselineWin = Resolve-Path (Join-Path $serverRoot "data\database_default.sql")
$bootstrapWin = Resolve-Path (Join-Path $serverRoot "tools\bootstrap-database.php")
$baselineWslPath = $baselineWin.Path.Replace('\', '/')
$bootstrapWslPath = $bootstrapWin.Path.Replace('\', '/')
$baselineLinux = (& wsl.exe -- wslpath -a -u -- $baselineWslPath).Trim()
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

$eventlogExists = & wsl.exe -- env "PGPASSWORD=$password" psql -h 127.0.0.1 -U $owner -d $database -Atqc "SELECT to_regclass('synth.eventlog') IS NOT NULL;"
$eventlogExists = if ($null -eq $eventlogExists) { "" } else { ([string]$eventlogExists).Trim() }
if ($eventlogExists -ne "t") {
    Write-Host "Importing SYNTH baseline schema into '$database'."
    & wsl.exe -- env "PGPASSWORD=$password" psql -h 127.0.0.1 -U $owner -d $database -v ON_ERROR_STOP=1 -f $baselineLinux
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to import baseline schema into '$database'."
    }
    & wsl.exe -- env "PGPASSWORD=$password" psql -h 127.0.0.1 -U $owner -d $database -v ON_ERROR_STOP=1 -c "TRUNCATE TABLE synth.worldknowledge RESTART IDENTITY;"
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to clear legacy WorldKnowledge rows from '$database'."
    }
}

Write-Host "Running SYNTH database updates."
& wsl.exe -- php $bootstrapLinux
if ($LASTEXITCODE -ne 0) {
    throw "SYNTH database updates failed."
}

Write-Host "Database '$database' is ready."
