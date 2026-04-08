<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RadarService
{
    private const OPPORTUNITY_THRESHOLD = 60;
    private const MARKET_SYNC_COOLDOWN_SECONDS = 330;

    private string $storageDir;
    private string $snapshotsDir;
    private string $catalogFile;
    private string $marketFile;
    private string $marketBackupFile;
    private string $csfloatSignalsFile;
    private string $reportsFile;
    private string $jobsFile;
    private string $watchlistFile;

    public function __construct()
    {
        ini_set('memory_limit', '1024M');

        $this->storageDir = dirname(__DIR__, 2) . '/storage';
        $this->snapshotsDir = $this->storageDir . '/snapshots';
        $this->catalogFile = $this->storageDir . '/catalog.json';
        $this->marketFile = $this->storageDir . '/latest_market.json';
        $this->marketBackupFile = $this->storageDir . '/latest_market_backup.json';
        $this->csfloatSignalsFile = $this->storageDir . '/csfloat_signals.json';
        $this->reportsFile = $this->storageDir . '/reports.json';
        $this->jobsFile = $this->storageDir . '/jobs.json';
        $this->watchlistFile = $this->storageDir . '/watchlist.json';

        $this->ensureDirectories();
        $this->ensureWatchlist();
    }

    public function overview(): array
    {
        $catalog = $this->readJson($this->catalogFile, ['items' => []]);
        $market = $this->readMarketData();
        $report = $this->getLatestReport();
        $items = $market['items'] ?? [];

        return [
            'date' => $market['date'] ?? ($report['date'] ?? date('Y-m-d')),
            'items_tracked' => count($catalog['items'] ?? []),
            'items_in_range' => $report['items_in_range'] ?? count($items),
            'opportunities_count' => max((int) ($report['opportunities_count'] ?? 0), count(array_filter($items, fn (array $item): bool => $this->isOpportunity($item)))),
            'watchlist_moving_count' => isset($report['watchlist_moves']) ? count($report['watchlist_moves']) : count($this->watchlistMoves($items)),
            'top_signals' => array_slice($items, 0, 5),
            'opportunity_series' => $this->buildOpportunitySeries(),
            'job_status' => [
                'last_sync_at' => $market['synced_at'] ?? null,
                'health' => $this->health()['status'],
            ],
        ];
    }

    public function items(array $filters = []): array
    {
        $market = $this->readMarketData();
        $items = $market['items'] ?? [];
        $filtered = array_values(array_filter($items, function (array $item) use ($filters): bool {
            $query = trim((string) ($filters['q'] ?? ''));
            if ($query !== '' && stripos((string) $item['name'], $query) === false) {
                return false;
            }

            $priceMin = isset($filters['price_min']) ? (float) $filters['price_min'] : 0.0;
            if (($item['current_price'] ?? 0.0) < $priceMin) {
                return false;
            }

            $priceMax = isset($filters['price_max']) ? (float) $filters['price_max'] : INF;
            if (($item['current_price'] ?? 0.0) > $priceMax) {
                return false;
            }

            $volumeMin = isset($filters['volume_min']) ? (int) $filters['volume_min'] : 0;
            if (($item['sales_24h_volume'] ?? 0) < $volumeMin) {
                return false;
            }

            $scoreMin = isset($filters['score_min']) ? (int) $filters['score_min'] : 0;
            if (($item['interest_score'] ?? 0) < $scoreMin) {
                return false;
            }

            foreach (['weapon', 'rarity'] as $field) {
                $value = trim((string) ($filters[$field] ?? ''));
                if ($value !== '' && strcasecmp((string) ($item[$field] ?? ''), $value) !== 0) {
                    return false;
                }
            }

            foreach (['stattrak', 'souvenir'] as $field) {
                if (!array_key_exists($field, $filters) || $filters[$field] === '') {
                    continue;
                }

                if ((bool) $item[$field] !== (bool) (int) $filters[$field]) {
                    return false;
                }
            }

            return true;
        }));

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_slice($filtered, $offset, $perPage),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => count($filtered),
            ],
        ];
    }

    public function item(int $id): array
    {
        $market = $this->readMarketData();
        foreach ($market['items'] ?? [] as $item) {
            if ((int) $item['id'] !== $id) {
                continue;
            }

            return [
                'id' => $item['id'],
                'market_hash_name' => $item['market_hash_name'],
                'name' => $item['name'],
                'weapon' => $item['weapon'],
                'rarity' => $item['rarity'],
                'category' => $item['category'],
                'stattrak' => $item['stattrak'],
                'souvenir' => $item['souvenir'],
                'image_url' => $item['image_url'],
                'latest_snapshot' => [
                    'snapshot_date' => $market['date'] ?? date('Y-m-d'),
                    'current_price' => $item['current_price'],
                    'sales_24h_avg' => $item['sales_24h_avg'],
                    'sales_7d_avg' => $item['sales_7d_avg'],
                    'sales_30d_avg' => $item['sales_30d_avg'],
                    'sales_24h_volume' => $item['sales_24h_volume'],
                    'sales_7d_volume' => $item['sales_7d_volume'],
                    'sales_30d_volume' => $item['sales_30d_volume'],
                    'change_vs_yesterday_pct' => $item['change_vs_yesterday_pct'],
                    'change_vs_7d_pct' => $item['change_vs_7d_pct'],
                    'change_vs_30d_pct' => $item['change_vs_30d_pct'],
                    'volume_ratio_24h_7d' => $item['volume_ratio_24h_7d'],
                    'interest_score' => $item['interest_score'],
                    'tags' => $item['tags'],
                ],
                'history' => $this->buildItemHistory((string) $item['market_hash_name']),
                'recent_listing_signals' => $this->getRecentListingSignals((string) $item['market_hash_name']),
                'explanation' => $this->buildItemExplanation($item),
            ];
        }

        throw new RuntimeException('Item not found.');
    }

    public function reportToday(): array
    {
        $report = $this->getLatestReport();
        if ($report !== []) {
            return $report;
        }

        $market = $this->readMarketData();
        $items = $market['items'] ?? [];

        return [
            'date' => $market['date'] ?? date('Y-m-d'),
            'items_scanned' => count($this->readJson($this->catalogFile, ['items' => []])['items'] ?? []),
            'items_in_range' => count($items),
            'opportunities_count' => count(array_filter($items, fn (array $item): bool => $this->isOpportunity($item))),
            'summary_text' => $items === []
                ? 'Aucun rapport genere pour le moment. Lance un sync catalogue, un sync marche puis la generation du rapport.'
                : $this->buildSummaryText($items),
            'top_opportunities' => array_map([$this, 'mapReportCard'], array_slice($items, 0, 5)),
            'top_gainers' => [],
            'top_losers' => [],
            'top_volume' => [],
            'watchlist_moves' => array_map([$this, 'mapWatchlistRow'], array_slice($this->watchlistMoves($items), 0, 5)),
        ];
    }

    public function reportHistory(): array
    {
        return [
            'data' => $this->readJson($this->reportsFile, []),
            'meta' => [
                'total' => count($this->readJson($this->reportsFile, [])),
            ],
        ];
    }

    public function watchlist(): array
    {
        $marketIndex = [];
        foreach (($this->readMarketData()['items'] ?? []) as $item) {
            $marketIndex[$item['market_hash_name']] = $item;
        }

        $data = [];
        foreach ($this->readJson($this->watchlistFile, []) as $entry) {
            $marketItem = $marketIndex[$entry['market_hash_name']] ?? null;
            $data[] = [
                'name' => $entry['market_hash_name'],
                'price' => $marketItem['current_price'] ?? null,
                'note' => $entry['note'],
                'is_active' => true,
            ];
        }

        return ['data' => $data];
    }

    public function health(): array
    {
        $jobs = $this->readJson($this->jobsFile, []);
        $latestJobs = [];
        foreach ($jobs as $job) {
            $latestJobs[$job['job_name']] ??= $job['status'];
        }

        $catalog = $this->readJson($this->catalogFile, []);
        $market = $this->readMarketData();
        $marketCooldownRemaining = $this->marketSyncCooldownRemaining();
        $csfloat = $this->readJson($this->csfloatSignalsFile, []);
        $csfloatSignals = $csfloat['signals'] ?? [];
        $csfloatConfigured = $this->csfloatIsConfigured();
        $csfloatSyncedAt = is_array($csfloat) ? ($csfloat['synced_at'] ?? null) : null;

        return [
            'status' => $this->deriveGlobalHealth($latestJobs),
            'last_sync_at' => $market['synced_at'] ?? $catalog['synced_at'] ?? null,
            'market_sync_cooldown_remaining' => $marketCooldownRemaining,
            'market_sync_available' => $marketCooldownRemaining === 0,
            'sources' => [
                ['name' => 'ByMykel', 'status' => isset($catalog['synced_at']) ? 'ok' : 'unknown', 'note' => isset($catalog['synced_at']) ? 'catalogue et images synchronises' : 'catalogue non synchronise'],
                ['name' => 'Skinport', 'status' => isset($market['synced_at']) ? 'ok' : 'unknown', 'note' => isset($market['synced_at']) ? 'prix et historique synchronises' : 'market non synchronise'],
                [
                    'name' => 'CSFloat',
                    'status' => $csfloatSyncedAt !== null ? 'ok' : ($csfloatConfigured ? 'ready' : 'public'),
                    'note' => $csfloatSyncedAt !== null
                        ? sprintf('signaux listings sync a %s (%d entrees)', $csfloatSyncedAt, count($csfloatSignals))
                        : ($csfloatConfigured ? 'pret pour enrichissement via API key' : 'mode public sans API key, certains endpoints peuvent refuser 403'),
                ],
            ],
            'jobs' => [
                'sync-catalog' => $latestJobs['sync-catalog'] ?? 'pending',
                'sync-market' => $latestJobs['sync-market'] ?? 'pending',
                'generate-report' => $latestJobs['generate-report'] ?? 'pending',
                'sync-csfloat' => $latestJobs['sync-csfloat'] ?? 'pending',
            ],
        ];
    }

    public function jobs(): array
    {
        return [
            'data' => $this->readJson($this->jobsFile, []),
        ];
    }

    public function trigger(string $jobName): array
    {
        if ($jobName === 'sync-market') {
            $this->assertMarketSyncCooldown();
        }

        return match ($jobName) {
            'sync-catalog' => $this->runJob($jobName, fn (): array => $this->performCatalogSync()),
            'sync-market' => $this->runJob($jobName, fn (): array => $this->performMarketSync()),
            'sync-csfloat' => $this->runJob($jobName, fn (): array => $this->performCsfloatSync()),
            'generate-report' => $this->runJob($jobName, fn (): array => $this->performReportGeneration()),
            default => throw new RuntimeException('Unknown job.'),
        };
    }

    private function performCatalogSync(): array
    {
        $payload = $this->fetchJsonWithCurl('https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/skins_not_grouped.json');
        $items = [];

        foreach ($payload as $item) {
            $marketHashName = (string) ($item['market_hash_name'] ?? '');
            if ($marketHashName === '') {
                continue;
            }

            $items[$marketHashName] = [
                'id' => $this->idFromName($marketHashName),
                'market_hash_name' => $marketHashName,
                'name' => $item['name'] ?? $marketHashName,
                'weapon' => $item['weapon']['name'] ?? null,
                'rarity' => $item['rarity']['name'] ?? null,
                'category' => $item['category']['name'] ?? null,
                'exterior' => $item['wear']['name'] ?? null,
                'stattrak' => (bool) ($item['stattrak'] ?? false),
                'souvenir' => (bool) ($item['souvenir'] ?? false),
                'image_url' => $item['image'] ?? null,
            ];
        }

        ksort($items);

        $data = [
            'synced_at' => date(DATE_ATOM),
            'items' => array_values($items),
        ];

        $this->writeJson($this->catalogFile, $data);

        return [
            'synced_at' => $data['synced_at'],
            'items_processed' => count($data['items']),
        ];
    }

    private function performMarketSync(): array
    {
        if (!is_file($this->catalogFile)) {
            $this->performCatalogSync();
        }

        $catalog = $this->readJson($this->catalogFile, ['items' => []]);
        $catalogIndex = [];
        foreach ($catalog['items'] ?? [] as $item) {
            $catalogIndex[$item['market_hash_name']] = $item;
        }

        $marketPayload = $this->fetchSkinportJson('https://api.skinport.com/v1/items?app_id=730&currency=EUR&tradable=0');
        $historyPayload = $this->fetchSkinportJson('https://api.skinport.com/v1/sales/history?app_id=730&currency=EUR');
        $historyIndex = [];
        foreach ($historyPayload as $historyItem) {
            $historyIndex[$historyItem['market_hash_name']] = $historyItem;
        }

        $previousSnapshot = $this->loadPreviousSnapshot(date('Y-m-d'));
        $previousIndex = [];
        foreach ($previousSnapshot['items'] ?? [] as $item) {
            $previousIndex[$item['market_hash_name']] = $item;
        }

        $watchlist = [];
        foreach ($this->readJson($this->watchlistFile, []) as $entry) {
            $watchlist[$entry['market_hash_name']] = true;
        }

        $items = [];
        foreach ($marketPayload as $marketItem) {
            $marketHashName = (string) ($marketItem['market_hash_name'] ?? '');
            if ($marketHashName === '' || !isset($catalogIndex[$marketHashName])) {
                continue;
            }

            $currentPrice = $this->pickCurrentPrice($marketItem);
            if ($currentPrice === null || $currentPrice < 5.0 || $currentPrice > 800.0) {
                continue;
            }

            $historyItem = $historyIndex[$marketHashName] ?? null;
            $previousItem = $previousIndex[$marketHashName] ?? null;
            $catalogItem = $catalogIndex[$marketHashName];
            $merged = [
                'id' => $catalogItem['id'],
                'market_hash_name' => $marketHashName,
                'name' => $catalogItem['name'],
                'weapon' => $catalogItem['weapon'],
                'rarity' => $catalogItem['rarity'],
                'category' => $catalogItem['category'],
                'exterior' => $catalogItem['exterior'],
                'stattrak' => $catalogItem['stattrak'],
                'souvenir' => $catalogItem['souvenir'],
                'image_url' => $catalogItem['image_url'],
                'current_price' => $currentPrice,
                'min_price' => $this->floatOrNull($marketItem['min_price'] ?? null),
                'max_price' => $this->floatOrNull($marketItem['max_price'] ?? null),
                'mean_price' => $this->floatOrNull($marketItem['mean_price'] ?? null),
                'median_price' => $this->floatOrNull($marketItem['median_price'] ?? null),
                'quantity' => (int) ($marketItem['quantity'] ?? 0),
                'sales_24h_avg' => $this->floatOrNull($historyItem['last_24_hours']['avg'] ?? null),
                'sales_24h_volume' => (int) ($historyItem['last_24_hours']['volume'] ?? 0),
                'sales_7d_avg' => $this->floatOrNull($historyItem['last_7_days']['avg'] ?? null),
                'sales_7d_volume' => (int) ($historyItem['last_7_days']['volume'] ?? 0),
                'sales_30d_avg' => $this->floatOrNull($historyItem['last_30_days']['avg'] ?? null),
                'sales_30d_volume' => (int) ($historyItem['last_30_days']['volume'] ?? 0),
                'sales_90d_avg' => $this->floatOrNull($historyItem['last_90_days']['avg'] ?? null),
                'sales_90d_volume' => (int) ($historyItem['last_90_days']['volume'] ?? 0),
                'previous_price' => $this->floatOrNull($previousItem['current_price'] ?? null),
                'change_vs_yesterday_pct' => null,
                'change_vs_7d_pct' => null,
                'change_vs_30d_pct' => null,
                'volume_ratio_24h_7d' => null,
                'item_page' => $marketItem['item_page'] ?? null,
                'market_page' => $marketItem['market_page'] ?? null,
                'is_watchlist' => isset($watchlist[$marketHashName]),
            ];

            if (isset($items[$marketHashName])) {
                $items[$marketHashName] = $this->mergeMarketRows($items[$marketHashName], $merged);
                continue;
            }

            $items[$marketHashName] = $merged;
        }

        $items = array_map(function (array $item): array {
            $item['change_vs_yesterday_pct'] = $this->percentageChange($item['current_price'], $item['previous_price'] ?? $item['sales_24h_avg'] ?? null);
            $item['change_vs_7d_pct'] = $this->percentageChange($item['current_price'], $item['sales_7d_avg'] ?? null);
            $item['change_vs_30d_pct'] = $this->percentageChange($item['current_price'], $item['sales_30d_avg'] ?? null);
            $item['volume_ratio_24h_7d'] = $this->volumeRatio(
                (int) ($item['sales_24h_volume'] ?? 0),
                (int) ($item['sales_7d_volume'] ?? 0)
            );
            [$score, $tags] = $this->scoreItem($item);
            $item['interest_score'] = $score;
            $item['tags'] = $tags;
            unset($item['previous_price']);

            return $item;
        }, array_values($items));

        usort($items, function (array $left, array $right): int {
            return [$right['interest_score'], $right['sales_24h_volume'], $right['current_price']]
                <=> [$left['interest_score'], $left['sales_24h_volume'], $left['current_price']];
        });

        if ($items === []) {
            throw new RuntimeException('No exploitable Skinport items matched the catalog and price filter.');
        }

        $data = [
            'date' => date('Y-m-d'),
            'synced_at' => date(DATE_ATOM),
            'items' => $items,
        ];

        $this->writeJson($this->marketFile, $data);
        $this->writeJson($this->marketBackupFile, $data);
        $this->writeJson($this->snapshotsDir . '/' . $data['date'] . '.json', $data);

        return [
            'synced_at' => $data['synced_at'],
            'items_processed' => count($items),
        ];
    }

    private function performReportGeneration(): array
    {
        $market = $this->readMarketData();
        $items = $market['items'] ?? [];
        if ($items === []) {
            throw new RuntimeException('No market data available. Run sync-market first.');
        }

        $gainers = $items;
        usort($gainers, fn (array $a, array $b): int => ($b['change_vs_yesterday_pct'] ?? -INF) <=> ($a['change_vs_yesterday_pct'] ?? -INF));

        $losers = $items;
        usort($losers, fn (array $a, array $b): int => ($a['change_vs_yesterday_pct'] ?? INF) <=> ($b['change_vs_yesterday_pct'] ?? INF));

        $volumes = $items;
        usort($volumes, fn (array $a, array $b): int => ($b['volume_ratio_24h_7d'] ?? -INF) <=> ($a['volume_ratio_24h_7d'] ?? -INF));

        $watchlistMoves = $this->watchlistMoves($items);
        $topOpportunities = array_slice($items, 0, 8);
        $report = [
            'date' => $market['date'] ?? date('Y-m-d'),
            'items_scanned' => count($this->readJson($this->catalogFile, ['items' => []])['items'] ?? []),
            'items_in_range' => count($items),
            'opportunities_count' => count(array_filter($items, fn (array $item): bool => $this->isOpportunity($item))),
            'summary_text' => $this->buildSummaryText($items),
            'top_opportunities' => array_map([$this, 'mapReportCard'], array_slice($topOpportunities, 0, 5)),
            'top_gainers' => array_map([$this, 'mapCompactRow'], array_slice($gainers, 0, 5)),
            'top_losers' => array_map([$this, 'mapCompactRow'], array_slice($losers, 0, 5)),
            'top_volume' => array_map([$this, 'mapVolumeRow'], array_slice($volumes, 0, 5)),
            'watchlist_moves' => array_map([$this, 'mapWatchlistRow'], array_slice($watchlistMoves, 0, 5)),
        ];

        $reports = array_values(array_filter(
            $this->readJson($this->reportsFile, []),
            static fn (array $entry): bool => ($entry['date'] ?? null) !== $report['date']
        ));
        array_unshift($reports, $report);
        $this->writeJson($this->reportsFile, array_slice($reports, 0, 30));

        return $report;
    }

    private function performCsfloatSync(): array
    {
        $targets = $this->csfloatTargets();
        if ($targets === []) {
            throw new RuntimeException('No eligible items available for CSFloat enrichment.');
        }

        $signals = [];
        foreach ($targets as $target) {
            $url = 'https://csfloat.com/api/v1/listings?limit=5&sort_by=lowest_price&market_hash_name=' . rawurlencode($target['market_hash_name']);
            $headers = [];
            $apiKey = $this->env('CSFLOAT_API_KEY');
            if ($apiKey !== null) {
                $headers[] = 'Authorization: ' . $apiKey;
            }

            $payload = $this->fetchJsonWithCurl($url, $headers, 45);
            $listings = isset($payload['data']) && is_array($payload['data'])
                ? $payload['data']
                : (array_is_list($payload) ? $payload : []);

            foreach ($listings as $listing) {
                if (!is_array($listing)) {
                    continue;
                }

                $signal = $this->mapCsfloatListing($listing, $target);
                if ($signal !== null) {
                    $signals[] = $signal;
                }
            }
        }

        $data = [
            'synced_at' => date(DATE_ATOM),
            'targets' => array_map(static fn (array $target): string => $target['market_hash_name'], $targets),
            'signals' => $signals,
        ];

        $this->writeJson($this->csfloatSignalsFile, $data);

        return [
            'synced_at' => $data['synced_at'],
            'items_processed' => count($signals),
            'targets' => count($targets),
        ];
    }

    private function runJob(string $jobName, callable $callback): array
    {
        $startedAt = date(DATE_ATOM);

        try {
            $result = $callback();
            $job = [
                'job_name' => $jobName,
                'status' => 'success',
                'started_at' => $startedAt,
                'ended_at' => date(DATE_ATOM),
                'items_processed' => (int) ($result['items_processed'] ?? 0),
                'error_count' => 0,
                'log_excerpt' => json_encode($result, JSON_UNESCAPED_SLASHES),
                'result' => $result,
            ];
        } catch (\Throwable $throwable) {
            $job = [
                'job_name' => $jobName,
                'status' => 'error',
                'started_at' => $startedAt,
                'ended_at' => date(DATE_ATOM),
                'items_processed' => 0,
                'error_count' => 1,
                'log_excerpt' => $throwable->getMessage(),
                'result' => [
                    'message' => $throwable->getMessage(),
                ],
            ];
        }

        $jobs = $this->readJson($this->jobsFile, []);
        array_unshift($jobs, array_diff_key($job, ['result' => true]));
        $this->writeJson($this->jobsFile, array_slice($jobs, 0, 50));

        return $job;
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->storageDir, $this->snapshotsDir] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
            }
        }
    }

    private function ensureWatchlist(): void
    {
        if (is_file($this->watchlistFile)) {
            return;
        }

        $this->writeJson($this->watchlistFile, [
            ['market_hash_name' => 'AK-47 | Redline (Field-Tested)', 'note' => 'suivi de momentum'],
            ['market_hash_name' => 'USP-S | Cortex (Minimal Wear)', 'note' => 'surveillance cassure'],
            ['market_hash_name' => 'AWP | Asiimov (Battle-Scarred)', 'note' => 'item liquide'],
            ['market_hash_name' => 'Desert Eagle | Printstream (Field-Tested)', 'note' => 'sous moyenne 7 jours'],
        ]);
    }

    private function fetchJsonWithCurl(string $url, array $headers = [], int $timeout = 60): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge([
                'User-Agent: CS2 Market Daily Radar',
                'Accept: application/json',
            ], $headers),
        ]);

        $response = curl_exec($handle);
        if ($response === false) {
            throw new RuntimeException('Curl request failed: ' . curl_error($handle));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($statusCode >= 400) {
            if (str_contains($url, 'csfloat.com') && $statusCode === 403) {
                throw new RuntimeException('CSFloat rejected the request with HTTP 403. Add CSFLOAT_API_KEY in the environment to enable listings enrichment.');
            }

            throw new RuntimeException(sprintf('Unexpected HTTP status %d for %s', $statusCode, $url));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unable to decode JSON payload from ' . $url);
        }

        return $decoded;
    }

    private function fetchSkinportJson(string $url): array
    {
        try {
            return $this->fetchSkinportJsonWithCurl($url);
        } catch (\Throwable) {
            // Fall back to a browser bridge when Brotli decoding is not available in curl.
        }

        return $this->fetchSkinportJsonWithBrowser($url);
    }

    private function fetchSkinportJsonWithBrowser(string $url): array
    {
        $browserPath = $this->detectBrowserPath();
        $outputPath = $this->storageDir . '/skinport_response.html';
        if (is_file($outputPath)) {
            unlink($outputPath);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $scriptPath = $this->storageDir . '/fetch_skinport.ps1';
            $script = <<<'PS1'
param(
    [Parameter(Mandatory = $true)][string] $BrowserPath,
    [Parameter(Mandatory = $true)][string] $Url,
    [Parameter(Mandatory = $true)][string] $OutputPath
)

& $BrowserPath --headless=new --disable-gpu --dump-dom $Url | Out-File -FilePath $OutputPath -Encoding utf8
PS1;
            file_put_contents($scriptPath, $script);

            $command = sprintf(
                'powershell -NoProfile -ExecutionPolicy Bypass -File %s -BrowserPath %s -Url %s -OutputPath %s',
                escapeshellarg($scriptPath),
                escapeshellarg($browserPath),
                escapeshellarg($url),
                escapeshellarg($outputPath),
            );
        } else {
            $command = sprintf(
                '%s --headless=new --disable-gpu --dump-dom %s > %s 2>/dev/null',
                escapeshellarg($browserPath),
                escapeshellarg($url),
                escapeshellarg($outputPath),
            );
        }

        shell_exec($command);
        $html = is_file($outputPath) ? file_get_contents($outputPath) : false;
        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException('Unable to fetch Skinport payload through browser bridge.');
        }

        $json = $this->extractJsonPayload($html);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Unable to decode Skinport JSON payload.');
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $firstError = $decoded['errors'][0]['message'] ?? 'Unknown Skinport error.';
            throw new RuntimeException('Skinport API error: ' . $firstError);
        }

        if (!array_is_list($decoded)) {
            throw new RuntimeException('Unexpected Skinport response shape.');
        }

        return $decoded;
    }

    private function fetchSkinportJsonWithCurl(string $url): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: CS2 Market Daily Radar',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle);
            throw new RuntimeException('Skinport curl request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('Unexpected HTTP status %d for %s', $statusCode, $url));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unable to decode Skinport JSON payload from curl.');
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $firstError = $decoded['errors'][0]['message'] ?? 'Unknown Skinport error.';
            throw new RuntimeException('Skinport API error: ' . $firstError);
        }

        if (!array_is_list($decoded)) {
            throw new RuntimeException('Unexpected Skinport response shape.');
        }

        return $decoded;
    }

    private function detectBrowserPath(): string
    {
        $configured = $this->env('SKINPORT_BROWSER_PATH');
        if ($configured !== null && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/microsoft-edge',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No compatible browser found. Install Chrome or Edge to fetch Skinport data.');
    }

    private function extractJsonPayload(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            return $trimmed;
        }

        $preStart = stripos($html, '<pre');
        if ($preStart === false) {
            throw new RuntimeException('Skinport browser response does not contain JSON payload.');
        }

        $contentStart = strpos($html, '>', $preStart);
        if ($contentStart === false) {
            throw new RuntimeException('Skinport browser response does not contain JSON payload.');
        }

        $contentStart++;
        $preEnd = stripos($html, '</pre>', $contentStart);
        if ($preEnd === false) {
            throw new RuntimeException('Skinport browser response is incomplete.');
        }

        $json = trim(html_entity_decode(substr($html, $contentStart, $preEnd - $contentStart), ENT_QUOTES | ENT_HTML5));
        if ($json === '') {
            throw new RuntimeException('Skinport browser response does not contain JSON payload.');
        }

        return $json;
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return $default;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function readMarketData(): array
    {
        $market = $this->readJson($this->marketFile, ['items' => []]);
        if (($market['items'] ?? []) !== []) {
            return $market;
        }

        $backup = $this->readJson($this->marketBackupFile, ['items' => []]);
        if (($backup['items'] ?? []) !== []) {
            return $backup;
        }

        return $this->marketDataFromReport();
    }

    private function assertMarketSyncCooldown(): void
    {
        $remaining = $this->marketSyncCooldownRemaining();
        if ($remaining <= 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'sync-market is on cooldown for %d seconds to respect the Skinport rate limit.',
            $remaining
        ));
    }

    private function marketSyncCooldownRemaining(): int
    {
        $jobs = $this->readJson($this->jobsFile, []);
        foreach ($jobs as $job) {
            if (($job['job_name'] ?? null) !== 'sync-market') {
                continue;
            }

            $startedAt = strtotime((string) ($job['started_at'] ?? ''));
            if ($startedAt === false) {
                return 0;
            }

            $elapsed = time() - $startedAt;
            if ($elapsed < self::MARKET_SYNC_COOLDOWN_SECONDS) {
                return self::MARKET_SYNC_COOLDOWN_SECONDS - $elapsed;
            }

            return 0;
        }

        return 0;
    }

    private function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode JSON payload.');
        }

        file_put_contents($path, $json);
    }

    private function buildOpportunitySeries(): array
    {
        $reports = $this->readJson($this->reportsFile, []);
        $reports = array_reverse(array_slice($reports, 0, 7));

        return array_map(static function (array $report): array {
            return [
                'day' => substr((string) ($report['date'] ?? ''), 5),
                'value' => (int) ($report['opportunities_count'] ?? 0),
            ];
        }, $reports);
    }

    private function getLatestReport(): array
    {
        $reports = $this->readJson($this->reportsFile, []);
        return $reports[0] ?? [];
    }

    private function getRecentListingSignals(string $marketHashName): array
    {
        $signals = $this->readJson($this->csfloatSignalsFile, ['signals' => []])['signals'] ?? [];
        $filtered = array_values(array_filter($signals, static function (array $signal) use ($marketHashName): bool {
            return ($signal['market_hash_name'] ?? null) === $marketHashName;
        }));

        usort($filtered, static function (array $left, array $right): int {
            return [$right['signal_score'] ?? 0, $left['listing_price'] ?? INF]
                <=> [$left['signal_score'] ?? 0, $right['listing_price'] ?? INF];
        });

        return array_slice($filtered, 0, 5);
    }

    private function marketDataFromReport(): array
    {
        $report = $this->getLatestReport();
        if ($report === []) {
            return ['items' => []];
        }

        $catalog = $this->readJson($this->catalogFile, ['items' => []]);
        $catalogIndex = [];
        foreach ($catalog['items'] ?? [] as $item) {
            $catalogIndex[$item['market_hash_name']] = $item;
        }

        $cards = $report['top_opportunities'] ?? [];
        $items = [];
        foreach ($cards as $card) {
            $name = (string) ($card['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $catalogItem = $catalogIndex[$name] ?? [];
            $currentPrice = $this->floatOrNull($card['price'] ?? null);
            $change24h = $this->floatOrNull($card['change_24h'] ?? null);
            $change7d = $this->floatOrNull($card['change_7d'] ?? null);

            $candidate = [
                'id' => $this->idFromName($name),
                'market_hash_name' => $name,
                'name' => $name,
                'weapon' => $catalogItem['weapon'] ?? ($card['weapon'] ?? null),
                'rarity' => $catalogItem['rarity'] ?? null,
                'category' => $catalogItem['category'] ?? null,
                'exterior' => $catalogItem['exterior'] ?? null,
                'stattrak' => (bool) ($catalogItem['stattrak'] ?? false),
                'souvenir' => (bool) ($catalogItem['souvenir'] ?? false),
                'image_url' => $card['image_url'] ?? ($catalogItem['image_url'] ?? null),
                'current_price' => $currentPrice,
                'min_price' => $currentPrice,
                'max_price' => $currentPrice,
                'mean_price' => $currentPrice,
                'median_price' => $currentPrice,
                'quantity' => 1,
                'sales_24h_avg' => $this->referencePriceFromChange($currentPrice, $change24h),
                'sales_24h_volume' => (int) ($card['volume_24h'] ?? 0),
                'sales_7d_avg' => $this->referencePriceFromChange($currentPrice, $change7d),
                'sales_7d_volume' => 0,
                'sales_30d_avg' => null,
                'sales_30d_volume' => 0,
                'sales_90d_avg' => null,
                'sales_90d_volume' => 0,
                'change_vs_yesterday_pct' => $change24h,
                'change_vs_7d_pct' => $change7d,
                'change_vs_30d_pct' => null,
                'volume_ratio_24h_7d' => null,
                'item_page' => null,
                'market_page' => null,
                'is_watchlist' => false,
                'interest_score' => (int) ($card['score'] ?? 0),
                'tags' => $this->tagsFromReason((string) ($card['reason'] ?? '')),
            ];

            if (isset($items[$name])) {
                $items[$name] = $this->mergeFallbackRows($items[$name], $candidate);
                continue;
            }

            $items[$name] = $candidate;
        }

        return [
            'date' => $report['date'] ?? date('Y-m-d'),
            'synced_at' => null,
            'items' => array_values($items),
        ];
    }

    private function loadPreviousSnapshot(string $currentDate): array
    {
        $files = glob($this->snapshotsDir . '/*.json') ?: [];
        rsort($files);

        foreach ($files as $file) {
            if (basename($file, '.json') >= $currentDate) {
                continue;
            }

            return $this->readJson($file, []);
        }

        return [];
    }

    private function buildItemHistory(string $marketHashName): array
    {
        $files = glob($this->snapshotsDir . '/*.json') ?: [];
        sort($files);
        $history = [];

        foreach ($files as $file) {
            $snapshot = $this->readJson($file, []);
            foreach ($snapshot['items'] ?? [] as $item) {
                if (($item['market_hash_name'] ?? null) !== $marketHashName) {
                    continue;
                }

                $history[] = [
                    'snapshot_date' => $snapshot['date'] ?? basename($file, '.json'),
                    'current_price' => $item['current_price'],
                    'volume' => $item['sales_24h_volume'] ?? 0,
                ];
            }
        }

        return array_slice($history, -14);
    }

    private function buildItemExplanation(array $item): string
    {
        $reasons = [];

        if (($item['change_vs_7d_pct'] ?? 0.0) < -5.0) {
            $reasons[] = 'prix sous la moyenne 7 jours';
        }

        if (($item['volume_ratio_24h_7d'] ?? 0.0) > 1.5) {
            $reasons[] = 'volume 24h au-dessus du rythme moyen';
        }

        if (($item['is_watchlist'] ?? false) === true) {
            $reasons[] = 'item present dans la watchlist';
        }

        $signals = $this->getRecentListingSignals((string) ($item['market_hash_name'] ?? ''));
        $bestSignal = $signals[0] ?? null;
        if (($bestSignal['float_value'] ?? 1.0) <= 0.08) {
            $reasons[] = 'listing CSFloat avec float interessant';
        }

        if (($bestSignal['has_stickers'] ?? false) === true) {
            $reasons[] = 'listing CSFloat avec stickers a verifier';
        }

        if ($reasons === []) {
            $reasons[] = 'signal remonte surtout par combinaison liquidite et tendance recente';
        }

        return ucfirst(implode(', ', $reasons)) . '.';
    }

    private function watchlistMoves(array $items): array
    {
        return array_values(array_filter($items, static function (array $item): bool {
            return ($item['is_watchlist'] ?? false) === true;
        }));
    }

    private function deriveGlobalHealth(array $latestJobs): string
    {
        if (in_array('error', $latestJobs, true)) {
            return 'warning';
        }

        if ($latestJobs === []) {
            return 'pending';
        }

        return 'ok';
    }

    private function pickCurrentPrice(array $marketItem): ?float
    {
        foreach (['suggested_price', 'median_price', 'mean_price', 'min_price'] as $field) {
            $value = $this->floatOrNull($marketItem[$field] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function percentageChange(?float $current, mixed $reference): ?float
    {
        $referenceValue = $this->floatOrNull($reference);
        if ($current === null || $referenceValue === null || $referenceValue <= 0.0) {
            return null;
        }

        return round((($current - $referenceValue) / $referenceValue) * 100, 2);
    }

    private function volumeRatio(int $volume24h, int $volume7d): ?float
    {
        if ($volume24h <= 0 || $volume7d <= 0) {
            return null;
        }

        $averagePerDay = $volume7d / 7;
        if ($averagePerDay <= 0) {
            return null;
        }

        return round($volume24h / $averagePerDay, 2);
    }

    private function referencePriceFromChange(?float $currentPrice, ?float $changePct): ?float
    {
        if ($currentPrice === null || $changePct === null || $changePct <= -100.0) {
            return null;
        }

        return round($currentPrice / (1 + ($changePct / 100)), 2);
    }

    private function csfloatTargets(): array
    {
        $targets = [];
        $market = $this->readMarketData();
        foreach (array_slice($market['items'] ?? [], 0, 8) as $item) {
            $targets[$item['market_hash_name']] = [
                'id' => $item['id'],
                'market_hash_name' => $item['market_hash_name'],
                'current_price' => $item['current_price'] ?? null,
            ];
        }

        foreach ($this->readJson($this->watchlistFile, []) as $entry) {
            $name = (string) ($entry['market_hash_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $targets[$name] ??= [
                'id' => $this->idFromName($name),
                'market_hash_name' => $name,
                'current_price' => null,
            ];
        }

        if ($targets === []) {
            $report = $this->getLatestReport();
            foreach (($report['top_opportunities'] ?? []) as $card) {
                $name = (string) ($card['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $targets[$name] ??= [
                    'id' => $this->idFromName($name),
                    'market_hash_name' => $name,
                    'current_price' => $this->floatOrNull($card['price'] ?? null),
                ];
            }
        }

        return array_slice(array_values($targets), 0, 12);
    }

    private function mapCsfloatListing(array $listing, array $target): ?array
    {
        $item = $listing['item'] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $marketHashName = (string) ($item['market_hash_name'] ?? $target['market_hash_name']);
        $listingPriceCents = is_numeric($listing['price'] ?? null) ? (int) $listing['price'] : null;
        $listingPrice = $listingPriceCents !== null ? round($listingPriceCents / 100, 2) : null;
        $floatValue = $this->floatOrNull($item['float_value'] ?? null);
        $stickers = is_array($item['stickers'] ?? null) ? $item['stickers'] : [];
        $sellerStats = $listing['seller']['statistics'] ?? [];
        $sellerScore = $this->sellerScore($sellerStats);

        return [
            'id' => (string) ($listing['id'] ?? ''),
            'item_id' => $target['id'],
            'market_hash_name' => $marketHashName,
            'observed_at' => date(DATE_ATOM),
            'listing_price' => $listingPrice,
            'listing_price_cents' => $listingPriceCents,
            'price_currency' => 'USD_CENTS',
            'float_value' => $floatValue,
            'seller_score' => $sellerScore,
            'has_stickers' => $stickers !== [],
            'sticker_count' => count($stickers),
            'signal_score' => $this->csfloatSignalScore($listingPrice, $target['current_price'] ?? null, $floatValue, $stickers, $sellerScore),
            'icon_url' => $this->normalizeCsfloatIconUrl($item['icon_url'] ?? null),
            'listing_url' => isset($listing['id']) ? 'https://csfloat.com/item/' . $listing['id'] : null,
            'raw_payload_json' => [
                'id' => $listing['id'] ?? null,
                'type' => $listing['type'] ?? null,
                'price' => $listing['price'] ?? null,
                'state' => $listing['state'] ?? null,
                'watchers' => $listing['watchers'] ?? null,
                'seller' => [
                    'steam_id' => $listing['seller']['steam_id'] ?? null,
                    'username' => $listing['seller']['username'] ?? null,
                    'statistics' => $sellerStats,
                ],
                'item' => [
                    'market_hash_name' => $marketHashName,
                    'float_value' => $item['float_value'] ?? null,
                    'stickers' => $stickers,
                    'inspect_link' => $item['inspect_link'] ?? null,
                ],
            ],
        ];
    }

    private function sellerScore(array $statistics): int
    {
        $verified = (int) ($statistics['total_verified_trades'] ?? 0);
        $total = (int) ($statistics['total_trades'] ?? 0);
        $failed = (int) ($statistics['total_failed_trades'] ?? 0);
        $medianTradeTime = (int) ($statistics['median_trade_time'] ?? 0);

        $score = min(60, $verified * 2) + min(25, $total);
        $score -= min(20, $failed * 10);

        if ($medianTradeTime > 0 && $medianTradeTime <= 240) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }

    private function csfloatSignalScore(?float $listingPrice, ?float $marketPrice, ?float $floatValue, array $stickers, int $sellerScore): int
    {
        $score = 0;

        if ($listingPrice !== null && $marketPrice !== null && $listingPrice <= $marketPrice * 0.95) {
            $score += 35;
        }

        if ($floatValue !== null && $floatValue <= 0.08) {
            $score += 20;
        }

        if ($stickers !== []) {
            $score += 12;
        }

        if ($sellerScore >= 60) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }

    private function normalizeCsfloatIconUrl(mixed $iconUrl): ?string
    {
        if (!is_string($iconUrl) || $iconUrl === '') {
            return null;
        }

        if (str_starts_with($iconUrl, 'http://') || str_starts_with($iconUrl, 'https://')) {
            return $iconUrl;
        }

        return 'https://community.akamai.steamstatic.com/economy/image/' . ltrim($iconUrl, '/');
    }

    private function csfloatIsConfigured(): bool
    {
        return $this->env('CSFLOAT_API_KEY') !== null;
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function mergeMarketRows(array $existing, array $candidate): array
    {
        $preferCandidate = ($candidate['current_price'] ?? INF) < ($existing['current_price'] ?? INF);

        $existing['current_price'] = min((float) $existing['current_price'], (float) $candidate['current_price']);
        $existing['min_price'] = $this->minNullable($existing['min_price'] ?? null, $candidate['min_price'] ?? null);
        $existing['max_price'] = $this->maxNullable($existing['max_price'] ?? null, $candidate['max_price'] ?? null);
        $existing['mean_price'] = $this->averageNullable($existing['mean_price'] ?? null, $candidate['mean_price'] ?? null);
        $existing['median_price'] = $this->averageNullable($existing['median_price'] ?? null, $candidate['median_price'] ?? null);
        $existing['quantity'] = (int) ($existing['quantity'] ?? 0) + (int) ($candidate['quantity'] ?? 0);

        if ($preferCandidate) {
            $existing['item_page'] = $candidate['item_page'] ?? $existing['item_page'];
            $existing['market_page'] = $candidate['market_page'] ?? $existing['market_page'];
        }

        return $existing;
    }

    private function mergeFallbackRows(array $existing, array $candidate): array
    {
        if (($candidate['current_price'] ?? INF) < ($existing['current_price'] ?? INF)) {
            $existing['current_price'] = $candidate['current_price'];
            $existing['min_price'] = $candidate['min_price'];
            $existing['max_price'] = $candidate['max_price'];
            $existing['mean_price'] = $candidate['mean_price'];
            $existing['median_price'] = $candidate['median_price'];
            $existing['sales_24h_avg'] = $candidate['sales_24h_avg'];
            $existing['sales_7d_avg'] = $candidate['sales_7d_avg'];
            $existing['change_vs_yesterday_pct'] = $candidate['change_vs_yesterday_pct'];
            $existing['change_vs_7d_pct'] = $candidate['change_vs_7d_pct'];
            $existing['image_url'] = $candidate['image_url'] ?? $existing['image_url'];
        }

        $existing['sales_24h_volume'] = max((int) ($existing['sales_24h_volume'] ?? 0), (int) ($candidate['sales_24h_volume'] ?? 0));
        $existing['interest_score'] = max((int) ($existing['interest_score'] ?? 0), (int) ($candidate['interest_score'] ?? 0));
        $existing['tags'] = array_values(array_unique(array_merge($existing['tags'] ?? [], $candidate['tags'] ?? [])));

        return $existing;
    }

    private function scoreItem(array $item): array
    {
        $score = 0;
        $tags = [];

        if (($item['change_vs_7d_pct'] ?? 0.0) <= -8.0) {
            $score += 40;
            $tags[] = 'drop';
        }

        if (($item['volume_ratio_24h_7d'] ?? 0.0) >= 1.5) {
            $score += 25;
            $tags[] = 'volume_anomaly';
        }

        if (($item['change_vs_yesterday_pct'] ?? 0.0) >= 5.0) {
            $score += 12;
            $tags[] = 'spike';
        }

        if (($item['is_watchlist'] ?? false) === true) {
            $score += 10;
            $tags[] = 'watchlist';
        }

        if (($item['sales_24h_volume'] ?? 0) < 2) {
            $score -= 20;
        }

        $spread = null;
        if (($item['min_price'] ?? null) !== null && ($item['max_price'] ?? null) !== null && $item['min_price'] > 0) {
            $spread = ($item['max_price'] - $item['min_price']) / $item['min_price'];
        }

        if ($spread !== null && $spread > 0.35) {
            $score -= 15;
        }

        if ($tags === []) {
            $tags[] = 'stable';
        }

        return [max(0, min(100, $score)), array_values(array_unique($tags))];
    }

    private function buildSummaryText(array $items): string
    {
        $highScoreCount = count(array_filter($items, fn (array $item): bool => $this->isOpportunity($item)));
        $watchlistMoves = count($this->watchlistMoves($items));
        $volumeAnomalies = count(array_filter($items, static fn (array $item): bool => in_array('volume_anomaly', $item['tags'] ?? [], true)));

        return sprintf(
            'Le radar live suit %d items dans la tranche cible. %d opportunites fortes ressortent aujourd hui, avec %d mouvements de watchlist et %d signaux de volume anormal.',
            count($items),
            $highScoreCount,
            $watchlistMoves,
            $volumeAnomalies,
        );
    }

    private function mapReportCard(array $item): array
    {
        return [
            'name' => $item['name'],
            'weapon' => $item['weapon'],
            'price' => $item['current_price'],
            'change_24h' => $item['change_vs_yesterday_pct'],
            'change_7d' => $item['change_vs_7d_pct'],
            'volume_24h' => $item['sales_24h_volume'],
            'score' => $item['interest_score'],
            'reason' => $this->buildShortReason($item),
            'image_url' => $item['image_url'],
        ];
    }

    private function mapCompactRow(array $item): array
    {
        return [
            'name' => $item['name'],
            'change_24h' => $item['change_vs_yesterday_pct'],
            'price' => $item['current_price'],
        ];
    }

    private function mapVolumeRow(array $item): array
    {
        return [
            'name' => $item['name'],
            'volume_ratio' => $item['volume_ratio_24h_7d'],
            'volume_24h' => $item['sales_24h_volume'],
        ];
    }

    private function mapWatchlistRow(array $item): array
    {
        return [
            'name' => $item['name'],
            'status' => $this->buildShortReason($item),
        ];
    }

    private function buildShortReason(array $item): string
    {
        if (($item['volume_ratio_24h_7d'] ?? 0.0) >= 1.8) {
            return 'volume x' . number_format((float) $item['volume_ratio_24h_7d'], 1, '.', '') . ' vs moyenne 7j';
        }

        if (($item['change_vs_7d_pct'] ?? 0.0) <= -8.0) {
            return 'prix sous moyenne 7j';
        }

        if (($item['is_watchlist'] ?? false) === true) {
            return 'watchlist + acceleration';
        }

        return 'signal stable';
    }

    private function tagsFromReason(string $reason): array
    {
        $tags = [];
        $normalized = strtolower($reason);

        if (str_contains($normalized, 'volume')) {
            $tags[] = 'volume_anomaly';
        }

        if (str_contains($normalized, 'prix sous')) {
            $tags[] = 'drop';
        }

        if (str_contains($normalized, 'watchlist')) {
            $tags[] = 'watchlist';
        }

        return $tags === [] ? ['stable'] : $tags;
    }

    private function isOpportunity(array $item): bool
    {
        return ($item['interest_score'] ?? 0) >= self::OPPORTUNITY_THRESHOLD;
    }

    private function minNullable(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return min($left, $right);
    }

    private function maxNullable(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    private function averageNullable(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return round(($left + $right) / 2, 2);
    }

    private function idFromName(string $marketHashName): int
    {
        return abs((int) sprintf('%u', crc32($marketHashName)));
    }
}
