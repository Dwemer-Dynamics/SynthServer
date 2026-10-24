# SPDX-License-Identifier: GPL-3.0-only
$ErrorActionPreference = 'Stop'
$launcher = Join-Path $PSScriptRoot 'SYNTH.exe'
$runtimeStarter = Join-Path (Split-Path $PSScriptRoot -Parent) 'Start-SYNTH.ps1'
$statusPath = Join-Path $PSScriptRoot 'codex-startup-status.json'
$status = [ordered]@{ schema = 'synth.codex.launcher.startup.v2'; checked_utc = [DateTime]::UtcNow.ToString('o'); bridge_ready = $false; status = 'starting'; model = 'gpt-6.1-sol'; provider_mode = 'chatgpt_only' }
function Write-StartupStatus {
    $temporary = $statusPath + '.' + [Guid]::NewGuid().ToString('N') + '.tmp'
    [IO.File]::WriteAllText($temporary, ($status | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $statusPath -Force
}
$mutex = [Threading.Mutex]::new($false, 'Local\SYNTHCodexLauncherStartup')
$ownsMutex = $false
try {
    try { $ownsMutex = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $ownsMutex = $true }
    if (!$ownsMutex) { return }
    Write-StartupStatus
    if (!(Test-Path -LiteralPath $launcher -PathType Leaf) -or !(Test-Path -LiteralPath $runtimeStarter -PathType Leaf)) {
        throw 'SYNTH startup files are missing.'
    }
    # Existing startup owns backend/speech checks and the verified ChatGPT bridge startup.
    & $runtimeStarter | Out-Null
    $status.bridge_ready = $true
    $status.status = 'ready'
    Write-StartupStatus
    # Do not expose the Launch Fallout 4 button before the primary LLM is ready.
    Start-Process -FilePath $launcher -WorkingDirectory $PSScriptRoot | Out-Null
} catch {
    $status.status = 'chatgpt_startup_blocked'
    Write-StartupStatus
    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show('ChatGPT is not ready. Close Fallout 4, open Codex and check your ChatGPT sign-in, then restart SYNTH. No other LLM was selected.', 'SYNTH ChatGPT startup') | Out-Null
} finally {
    if ($ownsMutex) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
