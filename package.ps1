[CmdletBinding()]
param(
	[string] $OutputPath = 'wp-facility-booking.zip',
	[string[]] $PluginFiles = @(
		'wpplugin.php',
		'calendar.css',
		'calendar.js',
		'facility-admin.css',
		'facility-admin.js',
		'uninstall.php',
		'readme.txt'
	)
)

$ErrorActionPreference = 'Stop'
$PluginFiles = @(
	$PluginFiles | ForEach-Object { $_ -split '\s+' } | Where-Object { $_ }
)
$root = $PSScriptRoot
$zipPath = if ([System.IO.Path]::IsPathRooted($OutputPath)) {
	$OutputPath
} else {
	Join-Path $root $OutputPath
}
$temporaryPath = "$zipPath.tmp"

$missingFiles = @(
	$PluginFiles | Where-Object {
		-not (Test-Path -LiteralPath (Join-Path $root $_) -PathType Leaf)
	}
)
if ($missingFiles.Count -gt 0) {
	throw "Missing package files: $($missingFiles -join ', ')"
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

if (Test-Path -LiteralPath $temporaryPath) {
	Remove-Item -LiteralPath $temporaryPath -Force
}

try {
	$archive = [System.IO.Compression.ZipFile]::Open(
		$temporaryPath,
		[System.IO.Compression.ZipArchiveMode]::Create
	)
	try {
		foreach ($file in $PluginFiles) {
			$sourcePath = Join-Path $root $file
			$entryName = "wp-facility-booking/$file"
			[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
				$archive,
				$sourcePath,
				$entryName,
				[System.IO.Compression.CompressionLevel]::Optimal
			) | Out-Null
		}
	} finally {
		$archive.Dispose()
	}

	Move-Item -LiteralPath $temporaryPath -Destination $zipPath -Force
	Write-Output "Created $zipPath"
} catch {
	if (Test-Path -LiteralPath $temporaryPath) {
		Remove-Item -LiteralPath $temporaryPath -Force
	}
	throw
}
