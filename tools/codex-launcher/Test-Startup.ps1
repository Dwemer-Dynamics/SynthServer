# SPDX-License-Identifier: GPL-3.0-only
$ErrorActionPreference = 'Stop'
$starter = Join-Path $PSScriptRoot 'Start-CodexBridge.ps1'
$global:synthStartupFixture = 'ready'
$global:synthStartupStarted = 0
function Get-Process { param($Name,$ErrorAction) if ($global:synthStartupFixture -eq 'game_running') { [pscustomobject]@{ ProcessName = 'Fallout4' } } }
function Test-Path { param($LiteralPath,$PathType) return $true }
function Start-Sleep { param($Milliseconds) }
function Start-Process { param($FilePath,$ArgumentList,$WindowStyle,[switch]$PassThru)
    if ($WindowStyle -ne 'Hidden') { throw 'Background bridge must stay hidden.' }
    $global:synthStartupStarted++
    return [pscustomobject]@{ HasExited = ($global:synthStartupFixture -ne 'ready') }
}
function wsl.exe {
    $global:LASTEXITCODE = 0
    $command = $args -join ' '
    if ($command -match 'wslpath') { return '/fixture/start.sh' }
    if ($command -match '--exec bash') { return }
    if ($command -match '--exec python3') { return 'http://127.0.0.1:8033/health' }
    if ($command -match 'docker exec') {
        $health = @{ service='SYNTH Codex bridge'; status='ready'; model='gpt-6.1-sol'; provider_mode='chatgpt_only'; startup_verified=$true }
        if ($global:synthStartupFixture -eq 'old_health') { $health.Remove('provider_mode') }
        if ($global:synthStartupFixture -eq 'wrong_model') { $health.model='other' }
        if ($global:synthStartupFixture -eq 'unverified') { $health.startup_verified=$false }
        if ($global:synthStartupFixture -eq 'unavailable') { $global:LASTEXITCODE=22; return }
        return ($health | ConvertTo-Json -Compress)
    }
    throw 'Unexpected operation in startup test.'
}
$passed = 0
foreach ($case in @('ready','old_health','wrong_model','unverified','unavailable','game_running')) {
    $global:synthStartupFixture=$case
    $before=$global:synthStartupStarted
    $failed=$false
    try { & $starter | Out-Null } catch { $failed=$true }
    if ($failed -ne ($case -ne 'ready')) { throw "Incorrect startup outcome: $case" }
    if ($case -eq 'game_running' -and $global:synthStartupStarted -ne $before) { throw 'A running game was interrupted.' }
    $passed++
}
$entry = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'Start-SYNTH-Launcher.ps1')
if ($entry.IndexOf('& $runtimeStarter') -ge $entry.IndexOf('Start-Process -FilePath $launcher')) { throw 'Launcher was exposed before ChatGPT readiness.' }
if ($entry -match 'bridge_unavailable_openrouter_fallback') { throw 'The entry point still claims fallback.' }
Write-Output "ChatGPT startup wrapper checks passed: $passed behavior cases; launcher opens after verified startup."
