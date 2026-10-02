<?php

namespace Database\Factories;

use App\Domains\Projects\Enums\ReportPeriod;
use App\Domains\Projects\Models\AchievementReport;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class AchievementReportFactory extends Factory
{
    protected $model = AchievementReport::class;

    public function definition(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        return [
            'project_id' => Project::factory(),
            'period_type' => ReportPeriod::Month,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'summary' => fake()->paragraph(),
            'narrative' => fake()->optional()->paragraph(),
            'generated_at' => now(),
            'created_by' => null,
        ];
    }
}
