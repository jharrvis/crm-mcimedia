<?php

namespace App\Domains\Services\Enums;

enum ServiceType: string
{
    case Domain = 'domain';
    case Hosting = 'hosting';
    case ServerManagement = 'server_management';
    case Maintenance = 'maintenance';
    case Seo = 'seo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Domain => 'Domain',
            self::Hosting => 'Hosting',
            self::ServerManagement => 'Management Server',
            self::Maintenance => 'Maintenance',
            self::Seo => 'SEO',
            self::Other => 'Lainnya',
        };
    }
}
