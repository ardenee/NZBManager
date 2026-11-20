[CmdletBinding()]
param(
    [string]$Version,
    [string]$OutputDir,
    [string]$Verify
)

$ErrorActionPreference = 'Stop'
$builder = Join-Path $PSScriptRoot 'build.py'
$arguments = @($builder)

if ($Version) { $arguments += @('--version', $Version) }
if ($OutputDir) { $arguments += @('--output-dir', $OutputDir) }
if ($Verify) { $arguments += @('--verify', $Verify) }

& python @arguments
if ($LASTEXITCODE -ne 0) {
    throw "DSM SPK builder failed with exit code $LASTEXITCODE"
}
