<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RadarService
{
    private const OPPORTUNITY_THRESHOLD = 60;
    private const MARKET_SYNC_COOLDOWN_SECONDS = 330;
    private const CSFLOAT_SYNC_COOLDOWN_SECONDS = 180;
    private const CSFLOAT_TARGETS_PER_RUN = 4;
    private const CSFLOAT_REQUEST_DELAY_US = 1200000;

    private string $storageDir;
    private string $snapshotsDir;
    private string $catalogFile;
    private string $marketFile;
    private string $marketBackupFile;
    private string $csfloatSignalsFile;
    private string $reportsFile;
    private string $jobsFile;
    private string $watchlistFile;
    private string $stateFile;

    public function __construct()
    {
        ini_set('memory_limit', '1024M');

        $this->storageDir = $this->detectStoragePath();
        $this->snapshotsDir = $this->storageDir . '/snapshots';
        $this->catalogFile = $this->storageDir . '/catalog.json';
        $this->marketFile = $this->storageDir . '/latest_market.json';
        $this->marketBackupFile = $this->storageDir . '/latest_market_backup.json';
        $this->csfloatSignalsFile = $this->storageDir . '/csfloat_signals.json';
        $this->reportsFile = $this->storageDir . '/reports.json';
        $this->jobsFile = $this->storageDir . '/jobs.json';
        $this->watchlistFile = $this->storageDir . '/watchlist.json';
        $this->stateFile = $this->storageDir . '/radar_state.json';

        $this->ensureDirectories();
        $this->ensureWatchlist();
    }

    private function detectStoragePath(): string
    {
        $configured = $this->env('RADAR_STORAGE_PATH');
        if ($configured !== null) {
            return rtrim($configured, '/\\');
        }

        if (DIRECTORY_SEPARATOR === '/' && is_dir('/data')) {
            return '/data/radar';
        }

        return dirname(__DIR__, 2) . '/storage';
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
                'item_page' => $item['item_page'] ?? null,
                'market_page' => $item['market_page'] ?? null,
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
            return $this->normalizeReport($report);
        }

        $market = $this->readMarketData();
        $items = $market['items'] ?? [];

        return $this->normalizeReport([
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
        ]);
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

        $catalogIndex = [];
        foreach (($this->readJson($this->catalogFile, ['items' => []])['items'] ?? []) as $item) {
            $catalogIndex[$item['market_hash_name']] = $item;
        }

        $data = [];
        foreach ($this->readJson($this->watchlistFile, []) as $entry) {
            $marketItem = $marketIndex[$entry['market_hash_name']] ?? null;
            $catalogItem = $catalogIndex[$entry['market_hash_name']] ?? null;
            $data[] = [
                'id' => $marketItem['id'] ?? $this->idFromName((string) $entry['market_hash_name']),
                'name' => $entry['market_hash_name'],
                'price' => $marketItem['current_price'] ?? null,
                'note' => $entry['note'],
                'image_url' => $marketItem['image_url'] ?? $catalogItem['image_url'] ?? null,
                'item_page' => $marketItem['item_page'] ?? null,
                'market_page' => $marketItem['market_page'] ?? null,
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
        $csfloatCooldownRemaining = $this->csfloatSyncCooldownRemaining();
        $csfloat = $this->readJson($this->csfloatSignalsFile, []);
        $csfloatSignals = $csfloat['signals'] ?? [];
        $csfloatConfigured = $this->csfloatIsConfigured();
        $csfloatSyncedAt = is_array($csfloat) ? ($csfloat['synced_at'] ?? null) : null;
        $openRouterConfigured = $this->openRouterIsConfigured();

        return [
            'status' => $this->deriveGlobalHealth($latestJobs),
            'last_sync_at' => $market['synced_at'] ?? $catalog['synced_at'] ?? null,
            'storage_path' => $this->storageDir,
            'state_file_path' => $this->stateFile,
            'state_file_exists' => is_file($this->stateFile),
            'market_sync_cooldown_remaining' => $marketCooldownRemaining,
            'market_sync_available' => $marketCooldownRemaining === 0,
            'csfloat_sync_cooldown_remaining' => $csfloatCooldownRemaining,
            'csfloat_sync_available' => $csfloatCooldownRemaining === 0,
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
                [
                    'name' => 'OpenRouter',
                    'status' => $openRouterConfigured ? 'ready' : 'unknown',
                    'note' => $openRouterConfigured
                        ? sprintf('analyse IA active via %s avec web search', $this->openRouterModel())
                        : 'OPENROUTER_API_KEY absente, rapport texte local uniquement',
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
        if ($jobName === 'sync-csfloat') {
            $this->assertCsfloatSyncCooldown();
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
        $report = $this->normalizeReport($report);

        try {
            $aiAnalysis = $this->generateAiBestDeals($report);
            if ($aiAnalysis !== null) {
                $report['ai_best_deals_title'] = $aiAnalysis['title'];
                $report['ai_best_deals_text'] = $aiAnalysis['text'];
                $report['ai_best_deals_cards'] = $aiAnalysis['cards'];
                $report['ai_best_deals_sources'] = $aiAnalysis['sources'];
                $report['ai_model'] = $aiAnalysis['model'];
                $report['ai_generated_at'] = $aiAnalysis['generated_at'];
                $report['ai_best_deals_error'] = null;
            }
        } catch (\Throwable $exception) {
            $report['ai_best_deals_error'] = $exception->getMessage();
        }

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
        $rateLimited = false;
        $processedTargets = 0;
        foreach ($targets as $index => $target) {
            $url = 'https://csfloat.com/api/v1/listings?limit=5&sort_by=lowest_price&market_hash_name=' . rawurlencode($target['market_hash_name']);
            $headers = [];
            $apiKey = $this->env('CSFLOAT_API_KEY');
            if ($apiKey !== null) {
                $headers[] = 'Authorization: ' . $apiKey;
            }

            try {
                $payload = $this->fetchJsonWithCurl($url, $headers, 45);
            } catch (RuntimeException $exception) {
                if ($this->isRateLimitError($exception->getMessage())) {
                    $rateLimited = true;
                    break;
                }

                throw $exception;
            }

            $processedTargets++;
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

            if ($index < count($targets) - 1) {
                usleep(self::CSFLOAT_REQUEST_DELAY_US);
            }
        }

        if ($signals !== []) {
            $data = [
                'synced_at' => date(DATE_ATOM),
                'targets' => array_map(static fn (array $target): string => $target['market_hash_name'], $targets),
                'signals' => $signals,
                'truncated_due_to_rate_limit' => $rateLimited,
            ];

            $this->writeJson($this->csfloatSignalsFile, $data);
        }

        if ($processedTargets === 0 && $rateLimited) {
            throw new RuntimeException(sprintf(
                'CSFloat rate limited the sync before any target could be processed. Wait %d seconds before retrying.',
                self::CSFLOAT_SYNC_COOLDOWN_SECONDS
            ));
        }

        return [
            'synced_at' => date(DATE_ATOM),
            'items_processed' => count($signals),
            'targets' => $processedTargets,
            'rate_limited' => $rateLimited,
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

    private function postJsonWithCurl(string $url, array $payload, array $headers = [], int $timeout = 60): array
    {
        $handle = curl_init($url);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Unable to encode JSON payload for ' . $url);
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge([
                'User-Agent: CS2 Market Daily Radar',
                'Accept: application/json',
                'Content-Type: application/json',
            ], $headers),
        ]);

        $response = curl_exec($handle);
        if ($response === false) {
            throw new RuntimeException('Curl request failed: ' . curl_error($handle));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($statusCode >= 400) {
            $snippet = trim(substr(preg_replace('/\s+/', ' ', (string) $response) ?? '', 0, 220));
            throw new RuntimeException(sprintf(
                'Unexpected HTTP status %d for %s%s',
                $statusCode,
                $url,
                $snippet !== '' ? ' - ' . $snippet : ''
            ));
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

    private function assertCsfloatSyncCooldown(): void
    {
        $remaining = $this->csfloatSyncCooldownRemaining();
        if ($remaining <= 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'sync-csfloat is on cooldown for %d seconds to respect the CSFloat rate limit.',
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

    private function csfloatSyncCooldownRemaining(): int
    {
        $jobs = $this->readJson($this->jobsFile, []);
        foreach ($jobs as $job) {
            if (($job['job_name'] ?? null) !== 'sync-csfloat') {
                continue;
            }

            $startedAt = strtotime((string) ($job['started_at'] ?? ''));
            if ($startedAt === false) {
                return 0;
            }

            $elapsed = time() - $startedAt;
            if ($elapsed < self::CSFLOAT_SYNC_COOLDOWN_SECONDS) {
                return self::CSFLOAT_SYNC_COOLDOWN_SECONDS - $elapsed;
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

        if ($path !== $this->stateFile) {
            $this->refreshStateFile();
        }
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

    private function refreshStateFile(): void
    {
        $state = $this->buildCanonicalState();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($this->stateFile, $json);
    }

    private function buildCanonicalState(): array
    {
        $catalog = $this->readJson($this->catalogFile, ['items' => []]);
        $market = $this->readJson($this->marketFile, ['items' => []]);
        $marketBackup = $this->readJson($this->marketBackupFile, ['items' => []]);
        $reports = $this->readJson($this->reportsFile, []);
        $latestReport = $reports[0] ?? [];
        $jobs = $this->readJson($this->jobsFile, []);
        $watchlist = $this->readJson($this->watchlistFile, []);
        $csfloat = $this->readJson($this->csfloatSignalsFile, ['signals' => []]);

        return [
            'generated_at' => date(DATE_ATOM),
            'storage_path' => $this->storageDir,
            'latest_report_context' => [
                'date' => $latestReport['date'] ?? null,
                'summary_text' => $latestReport['summary_text'] ?? null,
                'ai_best_deals_title' => $latestReport['ai_best_deals_title'] ?? null,
                'ai_best_deals_text' => $latestReport['ai_best_deals_text'] ?? null,
                'top_opportunities' => array_slice($latestReport['top_opportunities'] ?? [], 0, 8),
                'top_gainers' => array_slice($latestReport['top_gainers'] ?? [], 0, 8),
                'top_losers' => array_slice($latestReport['top_losers'] ?? [], 0, 8),
                'top_volume' => array_slice($latestReport['top_volume'] ?? [], 0, 8),
                'watchlist_moves' => array_slice($latestReport['watchlist_moves'] ?? [], 0, 8),
            ],
            'stats' => [
                'catalog_items' => count($catalog['items'] ?? []),
                'market_items' => count($market['items'] ?? []),
                'market_backup_items' => count($marketBackup['items'] ?? []),
                'reports_count' => count($reports),
                'watchlist_count' => count($watchlist),
                'csfloat_signals_count' => count($csfloat['signals'] ?? []),
            ],
            'catalog' => $catalog,
            'market' => $market,
            'market_backup' => $marketBackup,
            'reports' => $reports,
            'watchlist' => $watchlist,
            'jobs' => $jobs,
            'csfloat' => $csfloat,
        ];
    }

    private function readCanonicalState(): array
    {
        return $this->readJson($this->stateFile, []);
    }

    private function aiPayloadFromCanonicalState(array $report): array
    {
        $state = $this->readCanonicalState();
        if ($state === []) {
            $state = $this->buildCanonicalState();
        }

        $state['latest_report_context'] = [
            'date' => $report['date'] ?? null,
            'summary_text' => $report['summary_text'] ?? null,
            'top_opportunities' => array_slice($report['top_opportunities'] ?? [], 0, 8),
            'top_gainers' => array_slice($report['top_gainers'] ?? [], 0, 8),
            'top_losers' => array_slice($report['top_losers'] ?? [], 0, 8),
            'top_volume' => array_slice($report['top_volume'] ?? [], 0, 8),
            'watchlist_moves' => array_slice($report['watchlist_moves'] ?? [], 0, 8),
        ];

        return [
            'generated_at' => $state['generated_at'] ?? date(DATE_ATOM),
            'storage_path' => $state['storage_path'] ?? $this->storageDir,
            'stats' => $state['stats'] ?? [],
            'latest_report_context' => $state['latest_report_context'] ?? [],
            'market_sample' => array_slice($state['market']['items'] ?? [], 0, 50),
            'watchlist' => array_slice($state['watchlist'] ?? [], 0, 20),
            'recent_jobs' => array_slice($state['jobs'] ?? [], 0, 10),
            'csfloat_signals_sample' => array_slice($state['csfloat']['signals'] ?? [], 0, 20),
        ];
    }

    private function normalizeReport(array $report): array
    {
        $report['ai_best_deals_title'] ??= null;
        $report['ai_best_deals_text'] ??= null;
        $report['ai_best_deals_cards'] ??= [];
        $report['ai_best_deals_sources'] ??= [];
        $report['ai_model'] ??= null;
        $report['ai_generated_at'] ??= null;
        $report['ai_best_deals_error'] ??= null;
        $report['top_opportunities'] = $this->enrichRowsWithCatalogImages($report['top_opportunities'] ?? []);
        $report['top_gainers'] = $this->enrichRowsWithCatalogImages($report['top_gainers'] ?? []);
        $report['top_losers'] = $this->enrichRowsWithCatalogImages($report['top_losers'] ?? []);
        $report['top_volume'] = $this->enrichRowsWithCatalogImages($report['top_volume'] ?? []);

        return $report;
    }

    private function enrichRowsWithCatalogImages(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $catalogIndex = [];
        foreach (($this->readJson($this->catalogFile, ['items' => []])['items'] ?? []) as $item) {
            $catalogIndex[$item['market_hash_name']] = [
                'id' => $item['id'] ?? null,
                'image_url' => $item['image_url'] ?? null,
            ];
            $catalogIndex[$item['name'] ?? $item['market_hash_name']] = [
                'id' => $item['id'] ?? null,
                'image_url' => $item['image_url'] ?? null,
            ];
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $catalogMeta = $catalogIndex[$name] ?? null;
            if (is_array($catalogMeta)) {
                $row['id'] ??= $catalogMeta['id'] ?? null;
                $row['image_url'] ??= $catalogMeta['image_url'] ?? null;
            }
        }
        unset($row);

        return $rows;
    }

    private function generateAiBestDeals(array $report): ?array
    {
        if (!$this->openRouterIsConfigured()) {
            return null;
        }

        $payload = [
            'model' => $this->openRouterModel(),
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Tu es un analyste marche CS2 prudent. Tu recois le JSON du rapport du jour, tu peux utiliser la recherche web OpenRouter pour verifier le contexte public recent, puis tu rediges une synthese concise en francais. N invente aucune donnee. Reponds en JSON strict avec les cles title, text, best_deals, source_urls. Chaque best_deals doit contenir name, verdict et rationale.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'Lis ce JSON canonique stocke par l application. Base ton analyse sur latest_report_context, les echantillons marche, la watchlist et les signaux CSFloat. Utilise la recherche web seulement pour confirmer le contexte public recent ou signaler un risque de liquidite. Si tu n as pas assez d elements, dis-le clairement.',
                        'canonical_state' => $this->aiPayloadFromCanonicalState($report),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ],
            'tools' => [
                [
                    'type' => 'openrouter:web_search',
                    'parameters' => [
                        'max_results' => 5,
                        'max_total_results' => 10,
                        'search_context_size' => 'medium',
                    ],
                ],
            ],
        ];

        $response = $this->postJsonWithCurl(
            'https://openrouter.ai/api/v1/chat/completions',
            $payload,
            [
                'Authorization: Bearer ' . $this->env('OPENROUTER_API_KEY'),
                'HTTP-Referer: https://cs2-market-daily-radar.local',
                'X-Title: CS2 Market Daily Radar',
            ],
            90
        );

        $content = $this->extractAssistantContent($response);
        $decoded = $this->decodeJsonObject($content);
        if ($decoded === null) {
            return [
                'title' => 'Meilleures affaires du jour',
                'text' => trim($content),
                'cards' => [],
                'sources' => $this->extractLinksFromText($content),
                'model' => (string) ($response['model'] ?? $this->openRouterModel()),
                'generated_at' => date(DATE_ATOM),
            ];
        }

        return [
            'title' => (string) ($decoded['title'] ?? 'Meilleures affaires du jour'),
            'text' => trim((string) ($decoded['text'] ?? '')),
            'cards' => $this->normalizeAiCards($decoded['best_deals'] ?? []),
            'sources' => (($sources = $this->normalizeAiSources($decoded['source_urls'] ?? [])) !== [] ? $sources : $this->extractLinksFromText($content)),
            'model' => (string) ($response['model'] ?? $this->openRouterModel()),
            'generated_at' => date(DATE_ATOM),
        ];
    }

    private function extractAssistantContent(array $response): string
    {
        $message = $response['choices'][0]['message']['content'] ?? '';
        if (is_string($message)) {
            return $message;
        }

        if (is_array($message)) {
            $parts = [];
            foreach ($message as $chunk) {
                if (is_string($chunk)) {
                    $parts[] = $chunk;
                    continue;
                }

                if (is_array($chunk) && isset($chunk['text']) && is_string($chunk['text'])) {
                    $parts[] = $chunk['text'];
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }

    private function decodeJsonObject(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/si', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeAiCards(mixed $cards): array
    {
        if (!is_array($cards)) {
            return [];
        }

        $normalized = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }

            $name = trim((string) ($card['name'] ?? ''));
            $verdict = trim((string) ($card['verdict'] ?? ''));
            $rationale = trim((string) ($card['rationale'] ?? ''));
            if ($name === '' && $verdict === '' && $rationale === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'verdict' => $verdict,
                'rationale' => $rationale,
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    private function normalizeAiSources(mixed $sources): array
    {
        if (!is_array($sources)) {
            return [];
        }

        $normalized = [];
        foreach ($sources as $source) {
            if (!is_string($source)) {
                continue;
            }

            $url = trim($source);
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $normalized[] = $url;
        }

        return array_values(array_unique($normalized));
    }

    private function extractLinksFromText(string $text): array
    {
        if (preg_match_all('/https?:\/\/[^\s)<>"\'`]+/i', $text, $matches) === false) {
            return [];
        }

        return array_values(array_unique(array_filter($matches[0], static fn (string $url): bool => (bool) filter_var($url, FILTER_VALIDATE_URL))));
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
        foreach (array_slice($market['items'] ?? [], 0, self::CSFLOAT_TARGETS_PER_RUN) as $item) {
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

        return array_slice(array_values($targets), 0, self::CSFLOAT_TARGETS_PER_RUN);
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

    private function openRouterIsConfigured(): bool
    {
        return $this->env('OPENROUTER_API_KEY') !== null;
    }

    private function openRouterModel(): string
    {
        return $this->env('OPENROUTER_MODEL') ?? 'google/gemma-4-26b-a4b-it';
    }

    private function isRateLimitError(string $message): bool
    {
        return str_contains($message, 'HTTP status 429')
            || str_contains($message, 'rate limited')
            || str_contains($message, 'Too Many Requests');
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
            'id' => $item['id'],
            'name' => $item['name'],
            'weapon' => $item['weapon'],
            'price' => $item['current_price'],
            'change_24h' => $item['change_vs_yesterday_pct'],
            'change_7d' => $item['change_vs_7d_pct'],
            'volume_24h' => $item['sales_24h_volume'],
            'score' => $item['interest_score'],
            'reason' => $this->buildShortReason($item),
            'image_url' => $item['image_url'],
            'item_page' => $item['item_page'] ?? null,
            'market_page' => $item['market_page'] ?? null,
        ];
    }

    private function mapCompactRow(array $item): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'change_24h' => $item['change_vs_yesterday_pct'],
            'price' => $item['current_price'],
            'image_url' => $item['image_url'] ?? null,
            'item_page' => $item['item_page'] ?? null,
            'market_page' => $item['market_page'] ?? null,
        ];
    }

    private function mapVolumeRow(array $item): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'volume_ratio' => $item['volume_ratio_24h_7d'],
            'volume_24h' => $item['sales_24h_volume'],
            'image_url' => $item['image_url'] ?? null,
            'item_page' => $item['item_page'] ?? null,
            'market_page' => $item['market_page'] ?? null,
        ];
    }

    private function mapWatchlistRow(array $item): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'status' => $this->buildShortReason($item),
            'image_url' => $item['image_url'] ?? null,
            'item_page' => $item['item_page'] ?? null,
            'market_page' => $item['market_page'] ?? null,
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
