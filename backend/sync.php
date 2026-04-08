<?php

declare(strict_types=1);

use App\Services\RadarService;

require __DIR__ . '/bootstrap.php';

$jobName = $argv[1] ?? null;
if (!is_string($jobName) || $jobName === '') {
    fwrite(STDERR, "Usage: php sync.php <sync-catalog|sync-market|sync-csfloat|generate-report>\n");
    exit(1);
}

$service = new RadarService();

try {
    $result = $service->trigger($jobName);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($result['status'] === 'success' ? 0 : 1);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
