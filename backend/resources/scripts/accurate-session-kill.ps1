# Terminates ONE TCP connection to the Accurate/Firebird port (manual action
# from STOCKWISE -> Accurate Online, Super Admin only). Firebird sees the
# connection drop and rolls back that client's uncommitted transaction;
# committed data is unaffected. Output: one JSON object on stdout.
param(
    [Parameter(Mandatory)] [string]$LocalAddress,
    [Parameter(Mandatory)] [int]$LocalPort,
    [Parameter(Mandatory)] [string]$RemoteAddress,
    [Parameter(Mandatory)] [int]$RemotePort,
    [int[]]$AllowedPorts = @(3051)
)
$ErrorActionPreference = 'Stop'
function Out-Result($ok, $code, $message) { [ordered]@{ ok = $ok; code = $code; message = $message } | ConvertTo-Json -Compress; exit 0 }

if ($AllowedPorts -notcontains $LocalPort) { Out-Result $false 'port_not_allowed' "Hanya koneksi ke port $($AllowedPorts -join ',') yang boleh diputus." }
$remote = [System.Net.IPAddress]::Parse($RemoteAddress)
$local = [System.Net.IPAddress]::Parse($LocalAddress)
if ($remote.AddressFamily -ne 'InterNetwork' -or $local.AddressFamily -ne 'InterNetwork') { Out-Result $false 'ipv4_only' 'Hanya koneksi IPv4 yang didukung.' }
if ([System.Net.IPAddress]::IsLoopback($remote)) { Out-Result $false 'loopback_refused' 'Koneksi dari server ini sendiri (127.0.0.1) tidak boleh diputus.' }

$match = Get-NetTCPConnection -State Established -LocalPort $LocalPort -RemotePort $RemotePort -ErrorAction SilentlyContinue |
    Where-Object { [string]$_.RemoteAddress -eq $RemoteAddress -and [string]$_.LocalAddress -eq $LocalAddress }
if (-not $match) { Out-Result $false 'not_found' 'Koneksi tidak ditemukan (mungkin sudah terputus).' }

Add-Type -Namespace StockwiseNet -Name Tcp -MemberDefinition @'
[StructLayout(LayoutKind.Sequential)]
public struct MIB_TCPROW { public uint dwState; public uint dwLocalAddr; public uint dwLocalPort; public uint dwRemoteAddr; public uint dwRemotePort; }
[DllImport("iphlpapi.dll")] public static extern int SetTcpEntry(ref MIB_TCPROW row);
'@
function To-Addr([System.Net.IPAddress]$a) { [BitConverter]::ToUInt32($a.GetAddressBytes(), 0) }
function To-Port([int]$p) { [uint32]((($p -band 0xFF) -shl 8) -bor (($p -shr 8) -band 0xFF)) }

$row = New-Object StockwiseNet.Tcp+MIB_TCPROW
$row.dwState = 12   # MIB_TCP_STATE_DELETE_TCB
$row.dwLocalAddr = To-Addr $local
$row.dwLocalPort = To-Port $LocalPort
$row.dwRemoteAddr = To-Addr $remote
$row.dwRemotePort = To-Port $RemotePort
$rc = [StockwiseNet.Tcp]::SetTcpEntry([ref]$row)
if ($rc -ne 0) { Out-Result $false "win32_$rc" "Windows menolak memutus koneksi (kode $rc; perlu hak administrator)." }
Out-Result $true 'terminated' "Koneksi $RemoteAddress`:$RemotePort diputus."
