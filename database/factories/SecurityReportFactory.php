<?php

namespace Database\Factories;

use App\Domains\Security\Enums\ReportStatus;
use App\Domains\Security\Models\SecurityReport;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityReportFactory extends Factory
{
    protected $model = SecurityReport::class;

    public function definition(): array
    {
        return [
            'client_id' => ClientFactory::new(),
            'period' => now()->subMonth()->format('Y-m'),
            'file_path' => null,
            'status' => ReportStatus::Draft,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Sent,
            'sent_at' => now(),
        ]);
    }
}
