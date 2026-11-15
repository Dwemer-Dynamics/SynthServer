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

# Read-only: refuse an unpatched PHP CLI or Apache PHP runtime in this distro before creating or syncing anything.
& wsl.exe -d $Distro -- sudo bash "$sourceLinux/tools/check-php-runtime-security.sh"
if ($LASTEXITCODE -ne 0) {
    throw "PHP runtime security preflight failed in WSL distro '$Distro'; nothing was deployed. Update the distro's PHP packages, restart Apache and PHP-FPM, then deploy again."
}

# Read-only: the dependency install below runs Composer as the unprivileged nobody user through sudo, so look for it
# on that same PATH before touching the deployment target.
& wsl.exe -d $Distro -- sudo -u nobody sh -c 'command -v composer >/dev/null'
if ($LASTEXITCODE -ne 0) {
    throw "Composer is missing from the install user's PATH in WSL distro '$Distro'. Install Composer with the distribution's package manager, then retry."
}

& wsl.exe -d $Distro -- sudo install -d -m 0755 -o www-data -g www-data $Target
if ($LASTEXITCODE -ne 0) { throw "Failed to create the Synthserver deployment directory." }

# Production PHP dependencies come only from the tracked composer.lock, installed with the distro's own PHP and
# Composer as an unprivileged user in a private staging directory (no plugins, no package scripts). They are
# synchronized before the source, so an install or upgrade never serves new code against an older vendor tree.
$vendorStage = (& wsl.exe -d $Distro -- sudo mktemp -d /tmp/synthserver-vendor.XXXXXXXX | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $vendorStage -notmatch '^/tmp/synthserver-vendor\.[A-Za-z0-9]{8}$') {
    throw "Failed to create the dependency staging directory; nothing was deployed."
}
try {
    & wsl.exe -d $Distro -- sudo install -m 0644 -o nobody -g nogroup `
        "$sourceLinux/composer.json" "$sourceLinux/composer.lock" "$vendorStage/"
    if ($LASTEXITCODE -ne 0) { throw "composer.json or composer.lock is missing from the checkout; nothing was deployed." }
    & wsl.exe -d $Distro -- sudo chown nobody:nogroup $vendorStage
    if ($LASTEXITCODE -ne 0) { throw "Failed to prepare the dependency staging directory; nothing was deployed." }
    $composer = @('sudo', '-u', 'nobody', 'env', "COMPOSER_HOME=$vendorStage/.composer",
        "COMPOSER_CACHE_DIR=$vendorStage/.cache", 'composer', "--working-dir=$vendorStage")
    & wsl.exe -d $Distro -- @composer validate --no-check-publish --no-check-all
    if ($LASTEXITCODE -ne 0) { throw "composer.lock does not match composer.json; nothing was deployed." }
    & wsl.exe -d $Distro -- @composer install --no-dev --no-interaction --no-progress --prefer-dist `
        --no-plugins --no-scripts --optimize-autoloader
    if ($LASTEXITCODE -ne 0) { throw "Failed to install the locked production dependencies; nothing was deployed." }
    & wsl.exe -d $Distro -- @composer check-platform-reqs --no-dev
    if ($LASTEXITCODE -ne 0) { throw "This distro's PHP does not meet the locked dependency requirements; nothing was deployed." }
    & wsl.exe -d $Distro -- sudo rsync -a --delete "$vendorStage/vendor/" "$Target/vendor/"
    if ($LASTEXITCODE -ne 0) { throw "Failed to synchronize the production dependencies." }
} finally {
    & wsl.exe -d $Distro -- sudo rm -rf --one-file-system -- $vendorStage
}

# Exclusions protect destination state from --delete and keep local test jobs/worktree metadata out of runtime.
& wsl.exe -d $Distro -- sudo rsync -a `
    --delete `
    --exclude=.git `
    --exclude=.github/ `
    --exclude=.env `
    --exclude='.env.*' `
    --exclude=.phpunit.result.cache `
    --exclude=/vendor/ `
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
