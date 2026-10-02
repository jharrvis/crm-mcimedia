<?php

namespace App\Domains\Security\Enums;

/**
 * Sumber temuan insiden keamanan (F3-3).
 *
 * Nilai ini dipakai oleh API ingest (POST /api/security/events) sehingga
 * script monitoring di server klien melaporkan temuan dengan kategori yang
 * konsisten.
 */
enum IncidentSource: string
{
    case Firewall = 'firewall';
    case Wpscan = 'wpscan';
    case FileIntegrity = 'file-integrity';
    case Monitor = 'monitor';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Firewall => 'Firewall',
            self::Wpscan => 'WPScan',
            self::FileIntegrity => 'Integritas file',
            self::Monitor => 'Monitoring',
            self::Manual => 'Manual',
        };
    }
}
