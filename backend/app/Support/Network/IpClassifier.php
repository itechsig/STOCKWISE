<?php

namespace App\Support\Network;

/**
 * Klasifikasi IP permintaan: apakah dari jaringan kantor atau luar.
 * Dipakai untuk validasi lokasi request barang (peminta pakai internet kantor atau bukan).
 */
class IpClassifier
{
    public const OFFICE = 'OFFICE';

    public const EXTERNAL = 'EXTERNAL';

    public const UNKNOWN = 'UNKNOWN';

    /** @param  list<string>|null  $ranges  daftar IP / CIDR kantor */
    public static function classify(?string $ip, ?array $ranges = null): string
    {
        $ranges ??= config('stockwise.office.ip_ranges', []);

        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::UNKNOWN;
        }
        if (empty($ranges)) {
            return self::UNKNOWN;
        }

        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return self::OFFICE;
            }
        }

        return self::EXTERNAL;
    }

    public static function label(string $classification): string
    {
        return match ($classification) {
            self::OFFICE => 'Jaringan Kantor',
            self::EXTERNAL => 'Di Luar Kantor',
            default => 'Tidak Diketahui',
        };
    }

    private static function matches(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }

        if (! str_contains($range, '/')) {
            return inet_pton($ip) === inet_pton($range);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
