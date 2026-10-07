param(
    [string]$PhpExecutable = "php"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot

function Fail([string]$Message) {
    throw "SYNTH Server release audit failed: $Message"
}

Push-Location $root
try {
    $trackedIndex = @(git ls-files)
    if ($LASTEXITCODE -ne 0 -or $trackedIndex.Count -eq 0) {
        Fail "unable to read tracked files"
    }
    # Audit the proposed working tree, excluding tracked paths deliberately removed by this change.
    $tracked = @($trackedIndex | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf })

    # Keep the shared Herika feature layout; do not grow a second implementation tree.
    $legacyLayoutPaths = @($tracked | Where-Object {
        $_ -match '^(?:public|src|config|resources|database|workers)/'
    })
    if ($legacyLayoutPaths.Count -gt 0) {
        Fail "noncanonical server layout: $($legacyLayoutPaths -join ', '); see docs/SERVER-FILE-LAYOUT.md"
    }
    $sharedRuntimeDirectories = @(
        'conf', 'connector', 'data', 'debug', 'functions', 'lib',
        'processor', 'prompts', 'service', 'stt', 'tts', 'ui'
    )
    foreach ($directory in $sharedRuntimeDirectories) {
        if (@($tracked | Where-Object { $_.StartsWith("$directory/", [StringComparison]::Ordinal) }).Count -eq 0) {
            Fail "required Herika-style runtime directory is missing or incorrectly cased: $directory/"
        }
    }

    $forbiddenTrackedPatterns = @(
        '^unittests/vendor/',
        '^connector/vendor/',
        '^uploads/',
        '^data/tmp/',
        '^ui/tmp/(?!\.placeholder$)',
        '^soundcache/(?!\.gitkeep$)',
        '^logs/',
        '^log/(?!\.gitkeep$)',
        '^conf/conf\.php$',
        '\.(?:bak|orig|swp|swo|tmp|log)$'
    )

    foreach ($pattern in $forbiddenTrackedPatterns) {
        $matches = @($tracked | Where-Object { $_ -match $pattern })
        if ($matches.Count -gt 0) {
            Fail "generated or vendored files are tracked for '$pattern': $($matches -join ', ')"
        }
    }

    $obsoleteFiles = @(
        'data/.placeholder',
        'ui/lib/ui/bootstrap/bootstrap.bundle.min.js.map',
        'ui/lib/ui/bootstrap/bootstrap.min.css.map',
        'tts/composer.json',
        'tts/data/put_your_json_voices_here.txt'
    )
    foreach ($obsoleteFile in $obsoleteFiles) {
        if ($obsoleteFile -in $tracked) {
            Fail "obsolete file remains tracked: $obsoleteFile"
        }
    }

    # Removed for missing redistribution or consent evidence; local copies stay ignored.
    $unverifiedAssets = @(
        'data/voices/TheNarrator.wav',
        'debug/data/test.wav',
        'ui/css/font/Gothic821CondensedRegular.otf',
        'ui/css/font/MonofontoRg.otf',
        'ui/images/npc/vaultboy.png',
        'ui/images/npc/vaultgirl.png',
        'ui/images/npc/roboicon.webp',
        'ui/images/worldknowledge_infinium.png',
        'ui/images/navbarback.png',
        'ui/images/serverlogo.png',
        'ui/images/serverlogodev.png'
    )
    foreach ($unverifiedAsset in $unverifiedAssets) {
        if ($unverifiedAsset -in $tracked) {
            Fail "asset without verified redistribution evidence is tracked: $unverifiedAsset"
        }
    }

    $requiredFiles = @(
        'main.php',
        'main_synth_pipeline.php',
        'gamedata.php',
        'csv_import.php',
        'stt.php',
        'vsx.php',
        'player_rewrite.php',
        'prompt.includes.php',
        'conf/conf_loader.php',
        'conf/conf_schema.json',
        'connector/openrouterjson.php',
        'functions/functions.php',
        'lib/core/core_profiles.class.php',
        'lib/core/database_schema/core_profiles.sql',
        'processor/request.php',
        'prompts/prompts.php',
        'service/manager.php',
        'debug/db_updates.php',
        'data/database_default.sql',
        'ui/home.php',
        'ui/core/config_hub.php',
        'ui/tmpl/head.html',
        'ui/tmpl/navbar.php',
        'ui/tmpl/footer.html',
        'ui/css/synth-theme.css',
        'data/fonts/GloriaHallelujah-Regular.ttf',
        'data/fonts/OFL.txt',
        'ui/tools/server_version.php'
    )
    foreach ($requiredFile in $requiredFiles) {
        if ($requiredFile -notin $tracked -or -not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
            Fail "required runtime file is missing: $requiredFile"
        }
    }

    $schema = Get-Content -Raw -LiteralPath 'conf/conf_schema.json' | ConvertFrom-Json
    $ttsDrivers = @($schema.TTSFUNCTION.values | Where-Object { $_ -ne 'none' })
    $sttDrivers = @($schema.STTFUNCTION.values | Where-Object { $_ -ne 'none' })
    $ittDrivers = @($schema.ITTFUNCTION.values | Where-Object { $_ -ne 'none' })

    foreach ($driver in $ttsDrivers) {
        $driverFile = "tts/tts-$driver.php"
        if ($driverFile -notin $tracked -or -not (Test-Path -LiteralPath $driverFile -PathType Leaf)) {
            Fail "selectable TTS driver '$driver' has no tracked implementation at $driverFile"
        }
    }
    foreach ($driver in $sttDrivers) {
        $driverFile = "stt/stt-$driver.php"
        if ($driverFile -notin $tracked -or -not (Test-Path -LiteralPath $driverFile -PathType Leaf)) {
            Fail "selectable STT driver '$driver' has no tracked implementation at $driverFile"
        }
    }
    foreach ($driver in $ittDrivers) {
        $driverFile = "itt/itt-$driver.php"
        if ($driverFile -notin $tracked -or -not (Test-Path -LiteralPath $driverFile -PathType Leaf)) {
            Fail "selectable ITT driver '$driver' has no tracked implementation at $driverFile"
        }
    }

    $phpFiles = @($tracked | Where-Object { $_ -like '*.php' })
    foreach ($phpFile in $phpFiles) {
        & $PhpExecutable -l $phpFile | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Fail "PHP lint failed: $phpFile"
        }
    }

    $jsonFiles = @($tracked | Where-Object { $_ -like '*.json' })
    foreach ($jsonFile in $jsonFiles) {
        try {
            Get-Content -Raw -LiteralPath $jsonFile | ConvertFrom-Json | Out-Null
        } catch {
            Fail "invalid JSON: $jsonFile ($($_.Exception.Message))"
        }
    }

    # Check deployed bytes, not just valid JSON: CRLF conversion can break catalog bootstrap on Windows.
    $catalogManifest = Get-Content -Raw -LiteralPath 'data/fallout_worldknowledge_manifest.json' | ConvertFrom-Json
    $catalogChecks = @(
        @{ File = $catalogManifest.csv_file; Hash = $catalogManifest.checksum_sha256 },
        @{ File = $catalogManifest.knowledge_vocabulary.file; Hash = $catalogManifest.knowledge_vocabulary.checksum_sha256 },
        @{ File = $catalogManifest.source_snapshot.file; Hash = $catalogManifest.source_snapshot.checksum_sha256 }
    )
    foreach ($catalogCheck in $catalogChecks) {
        $catalogFile = [string]$catalogCheck.File
        if ([string]::IsNullOrWhiteSpace($catalogFile) -or [IO.Path]::GetFileName($catalogFile) -cne $catalogFile -or
            "data/$catalogFile" -cnotin $tracked -or [string]$catalogCheck.Hash -notmatch '^[a-f0-9]{64}$') {
            Fail 'invalid catalog checksum manifest reference'
        }
        if ((Get-FileHash -LiteralPath "data/$catalogFile" -Algorithm SHA256).Hash -ine $catalogCheck.Hash) {
            Fail "catalog checksum mismatch: data/$catalogFile; preserve its canonical LF line endings"
        }
    }

    $uiTextFiles = @($tracked | Where-Object {
        $_ -match '^ui/' -and $_ -match '\.(?:php|css|html|js)$'
    })
    $legacyUiPatterns = @(
        @{ Pattern = 'MagicCards'; Label = 'legacy MagicCards font' },
        @{ Pattern = 'rgb\(\s*242\s*,\s*124\s*,\s*17\s*\)'; Label = 'legacy Dialectic orange' },
        @{ Pattern = 'Gothic821CondensedRegular\.otf|MonofontoRg\.otf'; Label = 'removed unverified font file reference' },
        @{ Pattern = 'vaultboy\.png|vaultgirl\.png|roboicon\.webp|worldknowledge_infinium\.png|navbarback\.png|serverlogo(?:dev)?\.png'; Label = 'removed inherited image reference' }
    )
    foreach ($legacyPattern in $legacyUiPatterns) {
        $matches = @($uiTextFiles | Select-String -Pattern $legacyPattern.Pattern -CaseSensitive:$false)
        if ($matches.Count -gt 0) {
            $locations = @($matches | ForEach-Object { "$($_.Path):$($_.LineNumber)" })
            Fail "$($legacyPattern.Label) remains in UI source: $($locations -join ', ')"
        }
    }

    Write-Host "SYNTH Server release audit passed"
    Write-Host "Tracked files: $($tracked.Count)"
    Write-Host "Herika-style runtime directories verified: $($sharedRuntimeDirectories.Count)"
    Write-Host "PHP files linted: $($phpFiles.Count)"
    Write-Host "JSON files validated: $($jsonFiles.Count)"
    Write-Host "World Knowledge checksums verified: $($catalogChecks.Count)"
    Write-Host "TTS drivers verified: $($ttsDrivers.Count)"
    Write-Host "STT drivers verified: $($sttDrivers.Count)"
    Write-Host "ITT drivers verified: $($ittDrivers.Count)"
    Write-Host "UI theme sources checked: $($uiTextFiles.Count)"
} finally {
    Pop-Location
}
