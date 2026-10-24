# SPDX-License-Identifier: GPL-3.0-only
param([Parameter(Mandatory)][string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
$launcherSource = [IO.Path]::GetFullPath($PSScriptRoot)
$launcherRepo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$launcherOutput = [IO.Path]::GetFullPath($OutputDirectory)
$launcherAncestor = $launcherOutput
while ($launcherAncestor) {
    if ((Test-Path -LiteralPath $launcherAncestor) -and
        ((Get-Item -LiteralPath $launcherAncestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Choose a build output path without symbolic links or directory junctions.'
    }
    $launcherParent = [IO.Directory]::GetParent($launcherAncestor)
    $launcherAncestor = if ($launcherParent) { $launcherParent.FullName } else { $null }
}
if ($launcherOutput.Equals($launcherRepo, [StringComparison]::OrdinalIgnoreCase) -or
    $launcherOutput.StartsWith($launcherRepo.TrimEnd('\','/') + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a build output directory outside the source checkout.'
}
$launcherDotnet = (Get-Command dotnet -ErrorAction Stop).Source
$launcherArtifacts = Join-Path $launcherOutput 'artifacts'
$launcherDestination = Join-Path $launcherOutput 'launcher'
foreach ($launcherGeneratedTree in @($launcherArtifacts, $launcherDestination)) {
    if (Test-Path -LiteralPath $launcherGeneratedTree) {
        $launcherTreeItems = @((Get-Item -LiteralPath $launcherGeneratedTree -Force)) +
            @(Get-ChildItem -LiteralPath $launcherGeneratedTree -Recurse -Force)
        if ($launcherTreeItems | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }) {
            throw 'Build destinations must contain no symbolic links or directory junctions.'
        }
    }
}
$launcherRestoreConfig = '-p:RestoreConfigFile=' + (Join-Path $launcherSource 'NuGet.Config')
New-Item -ItemType Directory -Path $launcherOutput -Force | Out-Null
& $launcherDotnet build (Join-Path $launcherSource 'SYNTH.csproj') --configuration Release --artifacts-path $launcherArtifacts $launcherRestoreConfig --property:RestoreIgnoreFailedSources=true
if ($LASTEXITCODE -ne 0) { throw 'SYNTH launcher build failed.' }
& $launcherDotnet build (Join-Path $launcherSource 'Tests/Tests.csproj') --configuration Release --artifacts-path $launcherArtifacts $launcherRestoreConfig --property:RestoreIgnoreFailedSources=true
if ($LASTEXITCODE -ne 0) { throw 'SYNTH launcher test build failed.' }
& $launcherDotnet (Join-Path $launcherArtifacts 'bin/Tests/release/Tests.dll')
if ($LASTEXITCODE -ne 0) { throw 'SYNTH launcher tests failed.' }
$launcherBinary = Join-Path $launcherArtifacts 'bin/SYNTH/release'
New-Item -ItemType Directory -Path $launcherDestination -Force | Out-Null
Get-ChildItem -LiteralPath $launcherBinary -File | Where-Object { $_.Extension -in '.exe','.dll','.json' } | Copy-Item -Destination $launcherDestination
$launcherConfig = Join-Path $launcherDestination 'SYNTH.launcher.json'
if (-not (Test-Path -LiteralPath $launcherConfig)) {
    Copy-Item -LiteralPath (Join-Path $launcherSource 'SYNTH.launcher.sample.json') -Destination $launcherConfig
}
Write-Output "Built and tested: $(Join-Path $launcherDestination 'SYNTH.exe')"
