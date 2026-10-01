<?php

namespace App\Domains\Services\Console\Commands;

use App\Domains\Services\Models\Service;
use Illuminate\Console\Command;

class ServicesExpiringCommand extends Command
{
    protected $signature = 'crm:services-expiring {--days=30 : Batas hari jatuh tempo}';

    protected $description = 'Tampilkan layanan yang jatuh tempo dalam N hari (pengingat untuk admin)';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $expiring = Service::expiringSoon($days)->with('client')->orderBy('end_date')->get();
        $overdue = Service::overdue()->with('client')->orderBy('end_date')->get();

        if ($overdue->isNotEmpty()) {
            $this->error("Layanan sudah lewat jatuh tempo: {$overdue->count()}");
            $this->table(
                ['Klien', 'Layanan', 'Berakhir', 'Terlambat'],
                $overdue->map(fn ($s) => [
                    $s->client?->name ?? '—',
                    $s->name,
                    tgl_id($s->end_date),
                    abs($s->daysUntilEnd()).' hari',
                ])
            );
        }

        if ($expiring->isNotEmpty()) {
            $this->info("Layanan jatuh tempo ≤ {$days} hari: {$expiring->count()}");
            $this->table(
                ['Klien', 'Layanan', 'Berakhir', 'Sisa', 'Harga'],
                $expiring->map(fn ($s) => [
                    $s->client?->name ?? '—',
                    $s->name,
                    tgl_id($s->end_date),
                    $s->daysUntilEnd().' hari',
                    rupiah($s->price),
                ])
            );
        }

        if ($overdue->isEmpty() && $expiring->isEmpty()) {
            $this->info('Tidak ada layanan yang jatuh tempo.');
        }

        // Fase 2: kirim ringkasan ini ke admin via WhatsApp (Fonnte) / email.
        return self::SUCCESS;
    }
}
