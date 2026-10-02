<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Domains\Invoicing\Exceptions\InvalidTerminSplit;
use App\Domains\Invoicing\Services\InvoiceTerminSplitter;
use Illuminate\Contracts\Console\Kernel;

$splitter = new InvoiceTerminSplitter;

$today = now()->toDateString();

/** Helper: build term rows. */
function terms(array $pcts, string $today, int $dayOffset = 7): array
{
    $rows = [];
    $i = 0;
    foreach ($pcts as $p) {
        $rows[] = [
            'percent' => $p,
            'due_date' => now()->addDays($dayOffset * ($i + 1))->toDateString(),
        ];
        $i++;
    }

    return $rows;
}

$failures = 0;
$checked = 0;

// ---- 1. Exact-sum invariant across many totals & splits ----
$totals = [1000, 3333, 99999, 100000, 100001, 1234567, 999999999, 7, 13, 100003];
$splits = [
    [30, 30, 40], [50, 50], [33.33, 33.33, 33.34], [10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
    [1, 99], [99, 1], [25, 25, 25, 25], [60, 40], [12.5, 12.5, 75], [3, 3, 3, 3, 88],
    [16.67, 16.67, 16.66, 16.67, 16.67, 16.66],
];

foreach ($totals as $total) {
    foreach ($splits as $split) {
        try {
            $out = $splitter->allocate($total, terms($split, $today), $today);
        } catch (InvalidTerminSplit $e) {
            echo "SKIP total=$total split=".implode('/', $split).' -> '.$e->getMessage()."\n";

            continue;
        }
        $checked++;
        $sum = array_sum(array_column($out, 'amount'));

        // Invariant A: jumlah nominal == nilai kontrak (tepat, tanpa sisa).
        if ($sum !== $total) {
            echo "FAIL SUM total=$total split=".implode('/', $split)." got=$sum\n";
            $failures++;

            continue;
        }

        // Invariant B: semua >= 0
        foreach ($out as $i => $s) {
            if ($s['amount'] < 0) {
                echo "FAIL NEG total=$total split=".implode('/', $split)." i=$i amt={$s['amount']}\n";
                $failures++;
            }
        }
    }
}
echo "Exact-sum: checked=$checked failures=$failures\n";

// ---- 2. Determinism: same input -> same output ----
$a = $splitter->allocate(100001, terms([33.33, 33.33, 33.34], $today), $today);
$b = $splitter->allocate(100001, terms([33.33, 33.33, 33.34], $today), $today);
$det = array_column($a, 'amount') === array_column($b, 'amount');
echo 'Determinism: '.($det ? 'OK' : 'FAIL').' amounts='.implode(',', array_column($a, 'amount'))."\n";
if (! $det) {
    $failures++;
}

// ---- 3. Specific case from the task: 30/30/40 ----
$out = $splitter->allocate(1000000, terms([30, 30, 40], $today), $today);
echo '30/30/40 of 1,000,000 => '.implode(' | ', array_map(fn ($s) => $s['amount'], $out))."\n";

// ---- 4. Validation rejections ----
$cases = [
    'sum 90%' => [30, 30, 30],
    'sum 110%' => [40, 40, 30],
    'zero pct' => [0, 100],
    'negative pct' => [-10, 110],
    'over 100 pct' => [101, -1],
    'single term' => [100],
    'empty' => [],
];

foreach ($cases as $label => $split) {
    try {
        $splitter->allocate(1000000, terms($split, $today), $today);
        echo "FAIL ACCEPTED: $label\n";
        $failures++;
    } catch (InvalidTerminSplit $e) {
        echo "REJECT $label: ".$e->getMessage()."\n";
    }
}

// ---- 5. Due date validation ----
// issue_date = 10 hari lalu; termin 2 jatuh tempo 5 hari LALU (sebelum terbit kontrak) -> tolak
try {
    $splitter->allocate(1000000, [
        ['percent' => 50, 'due_date' => now()->subDays(20)->toDateString()],
        ['percent' => 50, 'due_date' => now()->subDays(5)->toDateString()],
    ], now()->subDays(10)->toDateString());
    echo "FAIL ACCEPTED: due before issue\n";
    $failures++;
} catch (InvalidTerminSplit $e) {
    echo 'REJECT due<issue: '.$e->getMessage()."\n";
}

// issue_date = 10 hari lalu; semua termin jatuh tempo di masa depan -> sah
try {
    $ok = $splitter->allocate(1000000, [
        ['percent' => 50, 'due_date' => now()->addDays(5)->toDateString()],
        ['percent' => 50, 'due_date' => now()->addDays(10)->toDateString()],
    ], now()->subDays(10)->toDateString());
    echo 'ACCEPT future due w/ past issue: '.implode(',', array_column($ok, 'amount'))."\n";
} catch (InvalidTerminSplit $e) {
    echo 'FAIL REJECTED valid future due: '.$e->getMessage()."\n";
    $failures++;
}

try {
    $splitter->allocate(1000000, [
        ['percent' => 50, 'due_date' => null],
        ['percent' => 50, 'due_date' => now()->addDays(5)->toDateString()],
    ], $today);
    echo "FAIL ACCEPTED: blank due date\n";
    $failures++;
} catch (InvalidTerminSplit $e) {
    echo 'REJECT blank due: '.$e->getMessage()."\n";
}

// ---- 6. Too many terms ----
try {
    $splitter->allocate(1000000, terms(array_fill(0, 25, 4), $today), $today);
    echo "FAIL ACCEPTED: 25 terms\n";
    $failures++;
} catch (InvalidTerminSplit $e) {
    echo 'REJECT 25 terms: '.$e->getMessage()."\n";
}

echo $failures === 0 ? "\n== ALLOCATION MATH OK ==\n" : "\n== $failures FAILURES ==\n";
exit($failures === 0 ? 0 : 1);
