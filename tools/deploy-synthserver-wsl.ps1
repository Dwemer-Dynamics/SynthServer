param(
    [string]$Distro = "DwemerAI4Skyrim3",
    [string]$Target = "/var/www/html/Synthserver"
)

$ErrorActionPreference = "Stop"

$sourceRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path.Replace('\', '/')
$sourceLinux = (& wsl.exe -d $Distro -- wslpath -a -u -- $sourceRoot).Trim()
$expectedTarget = "/var/www/html/Synthserver"
if ($Target -ne $expectedTarget) {
    throw "Refusing unexpected deployment target '$Target'. Expected '$expectedTarget'."
}

& wsl.exe -d $Distro -- sudo install -d -m 0755 -o www-data -g www-data $Target
if ($LASTEXITCODE -ne 0) { throw "Failed to create the Synthserver deployment directory." }

# Exclusions protect destination state from --delete and keep local test jobs/worktree metadata out of runtime.
& wsl.exe -d $Distro -- sudo rsync -a `
    --delete `
    --exclude=.git `
    --exclude=.github/ `
    --exclude=.env `
    --exclude='.env.*' `
    --exclude=.phpunit.result.cache `
    --exclude=connector/vendor/ `
    --exclude=unittests/vendor/ `
    --exclude=conf/conf.php `
    --include=/conf/conf_loader.php `
    --exclude='/conf/conf_*.php' `
    --exclude=conf/character_map.json `
    --exclude=log/ `
    --exclude=logs/ `
    --exclude=soundcache/ `
    --exclude=cache/ `
    --exclude=media/ `
    --exclude=db/ `
    --exclude=uploads/ `
    --exclude=tmp/ `
    --exclude=data/tmp/ `
    --exclude=ui/tmp/ `
    --exclude=ui/data/manualbackup/ `
    --exclude='data/CurrentModel_*.json' `
    --exclude=data/.manager.state.json `
    --exclude=data/pipeline_status.json `
    --exclude=data/voice_sync_status.json `
    --exclude=data/pictures/gallery/ `
    --exclude=data/plugin_packages/ `
    --exclude=data/voices/ `
    "$sourceLinux/" "$Target/"
if ($LASTEXITCODE -ne 0) { throw "Failed to synchronize Synthserver source." }

& wsl.exe -d $Distro -- sudo install -d -m 0755 -o www-data -g www-data `
    "$Target/log" "$Target/logs" "$Target/soundcache" "$Target/cache" `
    "$Target/media" "$Target/db" "$Target/uploads" "$Target/data/pictures/gallery" `
    "$Target/data/plugin_packages" "$Target/data/voices"
if ($LASTEXITCODE -ne 0) { throw "Failed to prepare Synthserver runtime directories." }

& wsl.exe -d $Distro -- bash -lc "if [ ! -f '$Target/conf/conf.php' ]; then sudo cp '$Target/conf/conf.sample.php' '$Target/conf/conf.php'; fi"
if ($LASTEXITCODE -ne 0) { throw "Failed to initialize Synthserver configuration." }

& wsl.exe -d $Distro -- sudo chown -R www-data:www-data $Target
if ($LASTEXITCODE -ne 0) { throw "Failed to apply Synthserver runtime ownership." }

# Rsync preserves the Windows source-root mode, so restore least-privilege runtime modes afterward.
& wsl.exe -d $Distro -- sudo chmod 0755 `
    $Target "$Target/log" "$Target/logs" "$Target/soundcache" "$Target/cache" `
    "$Target/media" "$Target/db" "$Target/uploads" "$Target/data/pictures/gallery" `
    "$Target/data/plugin_packages" "$Target/data/voices"
if ($LASTEXITCODE -ne 0) { throw "Failed to apply Synthserver runtime directory permissions." }

& wsl.exe -d $Distro -- sudo chmod 0640 "$Target/conf/conf.php"
if ($LASTEXITCODE -ne 0) { throw "Failed to protect Synthserver configuration." }

& wsl.exe -d $Distro -- sudo -u www-data php "$Target/tools/bootstrap-database.php"
if ($LASTEXITCODE -ne 0) { throw "Synthserver database bootstrap or schema verification failed." }

$apacheSource = "$sourceLinux/tools/apache/synthserver.conf"
& wsl.exe -d $Distro -- sudo install -m 0644 $apacheSource /etc/apache2/sites-available/synthserver.conf
if ($LASTEXITCODE -ne 0) { throw "Failed to install the Synthserver Apache site." }

& wsl.exe -d $Distro -- sudo a2ensite synthserver.conf
if ($LASTEXITCODE -ne 0) { throw "Failed to enable the Synthserver Apache site." }

& wsl.exe -d $Distro -- sudo apache2ctl configtest
if ($LASTEXITCODE -ne 0) { throw "Apache rejected the Synthserver site configuration." }

& wsl.exe -d $Distro -- sudo apache2ctl graceful
if ($LASTEXITCODE -ne 0) { throw "Failed to reload Apache." }

Write-Host "Synthserver deployed to $Target on http://127.0.0.1:8087/Synthserver/."
