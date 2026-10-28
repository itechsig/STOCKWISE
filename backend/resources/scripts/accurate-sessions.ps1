# Read-only: who is connected to Accurate right now (STOCKWISE -> Accurate Online).
# Source: Windows' own TCP table (Get-NetTCPConnection). Never connects to
# Firebird, never touches Accurate. Output: one JSON object on stdout.
param(
    [int]$DbPort = 3051,
    [int[]]$LicensePorts = @(6688, 6605, 34615),
    [int]$ResolveTimeoutMs = 2500
)
$ErrorActionPreference = 'Stop'
$ports = @($DbPort) + $LicensePorts
$rows = @(Get-NetTCPConnection -State Established -ErrorAction SilentlyContinue | Where-Object { $ports -contains $_.LocalPort })

$connections = foreach ($c in $rows) {
    [ordered]@{
        kind          = $(if ($c.LocalPort -eq $DbPort) { 'database' } else { 'license' })
        local_address = [string]$c.LocalAddress
        local_port    = [int]$c.LocalPort
        ip            = [string]$c.RemoteAddress
        remote_port   = [int]$c.RemotePort
        since         = $(if ($c.CreationTime) { $c.CreationTime.ToString('o') } else { $null })
    }
}

$ips = @($rows | ForEach-Object { [string]$_.RemoteAddress } | Sort-Object -Unique)

# Reverse DNS for every IP in parallel, bounded by one overall timeout.
$tasks = @{}
foreach ($ip in $ips) { try { $tasks[$ip] = [System.Net.Dns]::GetHostEntryAsync($ip) } catch {} }
if ($tasks.Count -gt 0) { try { [void][System.Threading.Tasks.Task]::WaitAll([System.Threading.Tasks.Task[]]@($tasks.Values), $ResolveTimeoutMs) } catch {} }

$neighbors = @{}
Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue | ForEach-Object { $neighbors[[string]$_.IPAddress] = [string]$_.LinkLayerAddress }

$hosts = [ordered]@{}
foreach ($ip in $ips) {
    $name = $null
    $t = $tasks[$ip]
    if ($t -and $t.Status -eq 'RanToCompletion' -and $t.Result.HostName -ne $ip) { $name = $t.Result.HostName }
    $mac = $neighbors[$ip]
    if ($mac -eq '00-00-00-00-00-00') { $mac = $null }
    $hosts[$ip] = [ordered]@{ hostname = $name; mac = $mac }
}

[ordered]@{
    generated_at  = (Get-Date).ToString('o')
    db_port       = $DbPort
    license_ports = $LicensePorts
    connections   = @($connections)
    hosts         = $hosts
} | ConvertTo-Json -Depth 5 -Compress
