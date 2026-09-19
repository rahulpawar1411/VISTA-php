# Start ReeferON PHP API on port 5080 (Node stays on 5000 if needed)
$php = 'C:\wamp64\bin\php\php8.3.28\php.exe'
if (-not (Test-Path $php)) {
  Write-Error "PHP not found at $php - update path in start.ps1"
  exit 1
}
Set-Location $PSScriptRoot
Write-Host "PHP API -> http://127.0.0.1:5080  (Ctrl+C to stop)"
& $php -S 0.0.0.0:5080 -t public public\router.php
