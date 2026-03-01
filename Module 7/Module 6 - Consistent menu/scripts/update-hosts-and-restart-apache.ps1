# Run this script as Administrator in PowerShell
# It will append a hosts entry for module6.local and restart Apache via XAMPP.

$hostsPath = "$env:windir\System32\drivers\etc\hosts"
$hostsEntry = "127.0.0.1`tmodule6.local"

# Append hosts entry if missing
$hosts = Get-Content -Path $hostsPath -ErrorAction Stop
if ($hosts -notcontains $hostsEntry) {
    Add-Content -Path $hostsPath -Value "`n# Added by helper script`n$hostsEntry"
    Write-Host "Appended hosts entry: $hostsEntry"
} else {
    Write-Host "Hosts entry already present."
}

# Restart Apache using XAMPP services if present
$xamppPath = "C:\xampp"
if (Test-Path "$xamppPath\xampp_stop.exe") {
    & "$xamppPath\xampp_stop.exe"
    Start-Sleep -Seconds 2
    & "$xamppPath\xampp_start.exe"
    Write-Host "Restarted XAMPP (Apache) via xampp_stop/xampp_start."
} else {
    # Fallback: try service name Apache2.4
    try {
        Stop-Service -Name 'Apache2.4' -Force -ErrorAction Stop
        Start-Sleep -Seconds 1
        Start-Service -Name 'Apache2.4' -ErrorAction Stop
        Write-Host "Restarted Apache2.4 service."
    } catch {
        Write-Host "Could not restart Apache automatically. Please restart XAMPP Control Panel as Administrator." -ForegroundColor Yellow
    }
}
