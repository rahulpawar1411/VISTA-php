# Zip backend-php for Hostinger upload (excludes local .env / logs)
$root = Split-Path $PSScriptRoot -Parent
if (-not (Test-Path (Join-Path $root "public\index.php"))) {
  $root = "c:\Users\Lenovo\Desktop\VISTA PHP\backend-php"
}
$out = Join-Path (Split-Path $root -Parent) "reeferon-api-hostinger.zip"
if (Test-Path $out) { Remove-Item $out -Force }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$tmp = Join-Path $env:TEMP ("reeferon-api-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $tmp | Out-Null
robocopy $root $tmp /E /NFL /NDL /NJH /NJS /nc /ns /np `
  /XD logs `
  /XF .env *.log | Out-Null

# Ensure production-looking .env is NOT included; user creates on server
[System.IO.Compression.ZipFile]::CreateFromDirectory($tmp, $out)
Remove-Item $tmp -Recurse -Force
Write-Host "Created: $out"
Write-Host "Upload + unzip on Hostinger, then create .env from .env.example"
