<?php

namespace App\Domains\Quotation\Console\Commands;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Models\Quotation;
use Illuminate\Console\Command;

/**
 * Q-3a: penawaran terkirim yang masa berlakunya sudah lewat otomatis
 * ditandai kedaluwarsa (`markExpired()`), transisi sent -> expired.
 *
 * Berbeda dengan `crm:quotations-expiring` (Q-2) yang hanya mengirim
 * pengingat H-3 tanpa mengubah status. Dijadwalkan harian di
 * bootstrap/app.php.
 */
class QuotationsExpireCommand extends Command
{
    protected $signature = 'crm:quotations-expire';

    protected $description = 'Tandai penawaran terkirim yang valid_until-nya sudah lewat sebagai kedaluwarsa';

    public function handle(): int
    {
        $quotations = Quotation::query()
            ->where('status', QuotationStatus::Sent)
            ->whereDate('valid_until', '<', today())
            ->get();

        if ($quotations->isEmpty()) {
            $this->info('Tidak ada penawaran yang kedaluwarsa.');

            return self::SUCCESS;
        }

        foreach ($quotations as $quotation) {
            $quotation->markExpired();
            ActivityLog::record(
                $quotation,
                'expired',
                "Penawaran {$quotation->number} otomatis ditandai kedaluwarsa (valid_until {$quotation->valid_until->toDateString()})."
            );
        }

        $this->info("{$quotations->count()} penawaran ditandai kedaluwarsa.");

        return self::SUCCESS;
    }
}
