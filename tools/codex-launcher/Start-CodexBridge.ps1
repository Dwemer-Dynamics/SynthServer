# SPDX-License-Identifier: GPL-3.0-only
param([string]$Distro = 'Ubuntu')
$ErrorActionPreference = 'Stop'
if ($Distro -notmatch '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$') { throw 'Invalid local WSL distribution.' }
if (Get-Process -Name 'Fallout4','Fallout4VR' -ErrorAction SilentlyContinue) {
    throw 'Close Fallout 4 before restarting the ChatGPT connection.'
}
$bridgeDirectory = Join-Path $PSScriptRoot 'codex'
$runner = Join-Path $bridgeDirectory 'run-bridge.sh'
$stopper = Join-Path $bridgeDirectory 'stop-bridge.sh'
foreach ($path in @($runner,$stopper)) {
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw 'The ChatGPT startup files are missing.' }
}
$wslRunner = & wsl.exe -d $Distro --exec wslpath -a -u $runner
if ($LASTEXITCODE -ne 0) { throw 'Could not resolve the ChatGPT startup path.' }
$wslStopper = & wsl.exe -d $Distro --exec wslpath -a -u $stopper
if ($LASTEXITCODE -ne 0) { throw 'Could not resolve the ChatGPT stop path.' }
if ($wslRunner -match '["\r\n]' -or $wslStopper -match '["\r\n]') { throw 'Unsupported ChatGPT startup path.' }
# The existing stop helper only stops its recorded, matching bridge process.
& wsl.exe -d $Distro --exec bash $wslStopper.Trim()
if ($LASTEXITCODE -ne 0) { throw 'The owned ChatGPT bridge could not be stopped safely.' }
$process = Start-Process -FilePath 'wsl.exe' -ArgumentList ('-d ' + $Distro + ' --exec bash "' + $wslRunner.Trim() + '"') -WindowStyle Hidden -PassThru
$startupHelper = & wsl.exe -d $Distro --exec wslpath -a -u (Join-Path $bridgeDirectory 'installed/codex_startup.py')
if ($LASTEXITCODE -ne 0) { throw 'Could not resolve the ChatGPT readiness helper.' }
$bridgeUrl = & wsl.exe -d $Distro --exec python3 $startupHelper.Trim() --health-url
if ($LASTEXITCODE -ne 0) { throw 'Private ChatGPT bridge configuration is unavailable.' }
$deadline = [DateTime]::UtcNow.AddSeconds(90)
while ([DateTime]::UtcNow -lt $deadline) {
    $response = & wsl.exe -d $Distro --exec docker exec synth-stu-server curl --max-time 1 --fail --silent $bridgeUrl.Trim()
    if ($LASTEXITCODE -eq 0) {
        try { $health = $response | ConvertFrom-Json } catch { $health = $null }
        if ($health -and $health.service -eq 'SYNTH Codex bridge' -and $health.status -eq 'ready' -and
            $health.model -eq 'gpt-6.1-sol' -and $health.provider_mode -eq 'chatgpt_only' -and $health.startup_verified -eq $true) {
            Write-Output 'ChatGPT is ready: gpt-6.1-sol. Startup isolation and a real reply are verified. Other LLM fallback is disabled.'
            return
        }
    }
    if ($process.HasExited) { break }
    Start-Sleep -Milliseconds 500
}
throw 'ChatGPT startup was blocked. Open Codex, check your ChatGPT sign-in, then restart SYNTH. No other LLM was selected.'
