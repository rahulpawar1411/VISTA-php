# Smoke-test PHP API routes (expect 200 health, 401 on protected)
$base = 'http://127.0.0.1:5080'
$script:fail = 0

function Probe([string]$path, [int[]]$okCodes) {
  try {
    $r = Invoke-WebRequest -Uri "$base$path" -UseBasicParsing -TimeoutSec 5
    $code = [int]$r.StatusCode
  } catch {
    if ($_.Exception.Response) {
      $code = [int]$_.Exception.Response.StatusCode.value__
    } else {
      Write-Host "FAIL $path (no response: $($_.Exception.Message))"
      $script:fail++
      return
    }
  }
  if ($okCodes -contains $code) {
    Write-Host "OK   $code $path"
  } else {
    Write-Host "FAIL $code $path (expected $($okCodes -join '|'))"
    $script:fail++
  }
}

Probe '/api/health' @(200)
Probe '/api/auth/me' @(401)
Probe '/api/masters/warehouses' @(401)
Probe '/api/do-operators' @(401)
Probe '/api/customers' @(401)
Probe '/api/sub-admins' @(401)
Probe '/api/chambers' @(401)
Probe '/api/chamber-temp' @(401)
Probe '/api/temp-logs' @(401)
Probe '/api/inward-logs' @(401)
Probe '/api/outward-logs' @(401)
Probe '/api/permission-requests' @(401)
Probe '/api/operator-activities' @(401)
Probe '/api/dashboard/stats' @(401)
Probe '/api/leads' @(401)
Probe '/api/customer-reports' @(401)
Probe '/api/customer-notes' @(401)

if ($script:fail -gt 0) {
  Write-Host "`n$($script:fail) route(s) failed."
  exit 1
}
Write-Host "`nAll smoke checks passed."
exit 0
