Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$manifestPath = Join-Path $repoRoot 'casauth_sch.xml'
$buildRoot = Join-Path $repoRoot 'build'
$stageRoot = Join-Path $buildRoot 'stage'
$outputRoot = Join-Path $buildRoot 'output'
$packagePrefix = 'plg_system_casauth_sch'
$packagePaths = @(
    'casauth_sch.xml',
    'composer.json',
    'README.md',
    'services',
    'src',
    'forms',
    'language',
    'vendor'
)

function Ensure-CleanDirectory {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    if (Test-Path $Path) {
        Remove-Item -Path $Path -Recurse -Force
    }

    New-Item -ItemType Directory -Path $Path | Out-Null
}

function Clear-PreviousPackages {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Directory,

        [Parameter(Mandatory = $true)]
        [string] $PackagePrefix
    )

    if (-not (Test-Path $Directory)) {
        return
    }

    Get-ChildItem -Path $Directory -Force | Where-Object {
        $_.Name -like "$PackagePrefix-v*"
    } | Remove-Item -Recurse -Force
}

function New-ZipFromDirectoryContents {
    param(
        [Parameter(Mandatory = $true)]
        [string] $SourceDirectory,

        [Parameter(Mandatory = $true)]
        [string] $DestinationZip
    )

    if (Test-Path $DestinationZip) {
        Remove-Item -Path $DestinationZip -Force
    }

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $destinationStream = [System.IO.File]::Open($DestinationZip, [System.IO.FileMode]::Create)

    try {
        $archive = New-Object System.IO.Compression.ZipArchive(
            $destinationStream,
            [System.IO.Compression.ZipArchiveMode]::Create,
            $false
        )

        try {
            $rootPath = [System.IO.Path]::GetFullPath($SourceDirectory)

            Get-ChildItem -Path $SourceDirectory -Recurse -File | ForEach-Object {
                $filePath = [System.IO.Path]::GetFullPath($_.FullName)
                $entryPath = $filePath.Substring($rootPath.Length).TrimStart('\', '/').Replace('\', '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $archive,
                    $filePath,
                    $entryPath,
                    [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        }
        finally {
            $archive.Dispose()
        }
    }
    finally {
        $destinationStream.Dispose()
    }
}

function Get-ManifestVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ManifestPath
    )

    if (-not (Test-Path $ManifestPath)) {
        throw "Manifest not found: $ManifestPath"
    }

    [xml]$manifest = Get-Content $ManifestPath -Raw
    $versionNode = $manifest.SelectSingleNode('/extension/version')
    $version = if ($null -ne $versionNode) { $versionNode.InnerText.Trim() } else { '' }

    if ([string]::IsNullOrWhiteSpace($version)) {
        throw "Version element not found in $ManifestPath"
    }

    return $version
}

function Assert-ZipContainsRootEntry {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ZipPath,

        [Parameter(Mandatory = $true)]
        [string] $EntryName
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)

    try {
        $entry = $zip.Entries | Where-Object { $_.FullName -eq $EntryName } | Select-Object -First 1

        if ($null -eq $entry) {
            throw "Expected '$EntryName' at the ZIP root, but it was not found."
        }
    }
    finally {
        $zip.Dispose()
    }
}

$version = Get-ManifestVersion -ManifestPath $manifestPath

Ensure-CleanDirectory -Path $stageRoot
New-Item -ItemType Directory -Force -Path $outputRoot | Out-Null
Clear-PreviousPackages -Directory $buildRoot -PackagePrefix $packagePrefix
Clear-PreviousPackages -Directory $outputRoot -PackagePrefix $packagePrefix

$pluginStage = Join-Path $stageRoot 'plugin'
New-Item -ItemType Directory -Path $pluginStage | Out-Null

foreach ($relativePath in $packagePaths) {
    $sourcePath = Join-Path $repoRoot $relativePath

    if (-not (Test-Path $sourcePath)) {
        throw "Package source path not found: $sourcePath"
    }

    $destinationPath = Join-Path $pluginStage $relativePath

    if ((Get-Item $sourcePath) -is [System.IO.DirectoryInfo]) {
        Copy-Item -Path $sourcePath -Destination $destinationPath -Recurse -Force
    } else {
        $destinationDirectory = Split-Path -Parent $destinationPath

        if ($destinationDirectory -and -not (Test-Path $destinationDirectory)) {
            New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        }

        Copy-Item -Path $sourcePath -Destination $destinationPath -Force
    }
}

$zipName = '{0}-v{1}.zip' -f $packagePrefix, $version
$zipPath = Join-Path $outputRoot $zipName
New-ZipFromDirectoryContents -SourceDirectory $pluginStage -DestinationZip $zipPath
Assert-ZipContainsRootEntry -ZipPath $zipPath -EntryName 'casauth_sch.xml'

Copy-Item -Path $zipPath -Destination (Join-Path $buildRoot $zipName) -Force

Write-Host ('Created: {0}' -f $zipPath)
