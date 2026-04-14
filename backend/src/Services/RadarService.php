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
    private string $positionsFile;
    private string $profilesFile;
    private string $inventoryFile;
    private string $stateFile;
    private bool $buildingCanonicalState = false;

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
        $this->positionsFile = $this->storageDir . '/positions.json';
        $this->profilesFile = $this->storageDir . '/profiles.json';
        $this->inventoryFile = $this->storageDir . '/profile_inventory.json';
        $this->stateFile = $this->storageDir . '/radar_state.json';

        $this->ensureDirectories();
        $this->ensureWatchlist();
        $this->ensurePositions();
        $this->ensureProfiles();
        $this->ensureProfileInventory();
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
        $positions = $this->positions();
        $openPositions = $positions['data'] ?? [];
        $positionsMeta = $positions['meta'] ?? [];

        return [
            'date' => $market['date'] ?? ($report['date'] ?? date('Y-m-d')),
            'items_tracked' => count($catalog['items'] ?? []),
            'items_in_range' => $report['items_in_range'] ?? count($items),
            'opportunities_count' => max((int) ($report['opportunities_count'] ?? 0), count(array_filter($items, fn (array $item): bool => $this->isOpportunity($item)))),
            'watchlist_moving_count' => isset($report['watchlist_moves']) ? count($report['watchlist_moves']) : count($this->watchlistMoves($items)),
            'positions_open_count' => $positionsMeta['total'] ?? count($openPositions),
            'positions_ready_to_sell_count' => $positionsMeta['ready_to_sell'] ?? 0,
            'top_positions' => array_slice($openPositions, 0, 4),
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
                'positions' => array_values(array_filter(
                    $this->positions()['data'] ?? [],
                    static fn (array $position): bool => (int) ($position['item_id'] ?? 0) === (int) $item['id']
                )),
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

    public function skinAdvice(array $payload): array
    {
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            throw new RuntimeException('Envoie un skin ou une question pour lancer l analyse IA.');
        }

        $report = $this->reportToday();
        $matchedItems = $this->findAdviceItems($message);
        $observedPrice = $this->extractObservedPriceFromMessage($message);

        if ($this->openRouterIsConfigured()) {
            try {
                return $this->generateSkinAdvice($message, $report, $matchedItems, $observedPrice);
            } catch (\Throwable $exception) {
                return $this->fallbackSkinAdvice($message, $matchedItems, $observedPrice, $exception->getMessage());
            }
        }

        return $this->fallbackSkinAdvice($message, $matchedItems, $observedPrice, 'OPENROUTER_API_KEY absente');
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
        foreach ($this->readWatchlistEntries() as $entry) {
            if (($entry['is_active'] ?? true) !== true) {
                continue;
            }

            $marketItem = $marketIndex[$entry['market_hash_name']] ?? null;
            $catalogItem = $catalogIndex[$entry['market_hash_name']] ?? null;
            $data[] = [
                'id' => $marketItem['id'] ?? $this->idFromName((string) $entry['market_hash_name']),
                'name' => $entry['market_hash_name'],
                'price' => $marketItem['current_price'] ?? null,
                'note' => $entry['note'],
                'managed_by' => $entry['managed_by'] ?? 'system',
                'last_ai_action' => $entry['last_ai_action'] ?? null,
                'last_ai_reason' => $entry['last_ai_reason'] ?? null,
                'image_url' => $marketItem['image_url'] ?? $catalogItem['image_url'] ?? null,
                'item_page' => $marketItem['item_page'] ?? null,
                'market_page' => $marketItem['market_page'] ?? null,
                'is_active' => (bool) ($entry['is_active'] ?? true),
            ];
        }

        return ['data' => $data];
    }

    public function positions(): array
    {
        $marketIndex = [];
        foreach (($this->readMarketData()['items'] ?? []) as $item) {
            $marketIndex[(int) ($item['id'] ?? 0)] = $item;
        }

        $profileNames = [];
        foreach ($this->readProfileEntries() as $profile) {
            $profileNames[(string) $profile['profile_id']] = (string) $profile['name'];
        }

        $positions = array_map(
            fn (array $entry): array => $this->enrichPositionEntry($entry, $marketIndex, $profileNames),
            $this->readPositionEntries()
        );

        usort($positions, static function (array $left, array $right): int {
            $priorityCompare = (int) ($right['sell_priority'] ?? 0) <=> (int) ($left['sell_priority'] ?? 0);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            $pnlCompare = ((float) ($right['pnl_pct'] ?? -INF)) <=> ((float) ($left['pnl_pct'] ?? -INF));
            if ($pnlCompare !== 0) {
                return $pnlCompare;
            }

            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        return [
            'data' => $positions,
            'meta' => [
                'total' => count($positions),
                'ready_to_sell' => count(array_filter($positions, static fn (array $position): bool => ($position['sell_signal'] ?? '') === 'sell_now')),
                'watch_to_sell' => count(array_filter($positions, static fn (array $position): bool => ($position['sell_signal'] ?? '') === 'watch_sell')),
                'hold' => count(array_filter($positions, static fn (array $position): bool => ($position['sell_signal'] ?? '') === 'hold')),
            ],
        ];
    }

    public function profiles(): array
    {
        $profiles = $this->readProfileEntries();
        if (!$this->buildingCanonicalState) {
            foreach ($profiles as $profile) {
                $hasSteamProfile = is_string($profile['steam_profile_url'] ?? null) && trim((string) $profile['steam_profile_url']) !== '';
                $neverSynced = empty($profile['inventory_synced_at']);
                if (!$hasSteamProfile || !$neverSynced) {
                    continue;
                }

                try {
                    $this->syncProfileInventory((string) $profile['profile_id']);
                } catch (\Throwable $exception) {
                    $entries = $this->readProfileEntries();
                    foreach ($entries as &$candidate) {
                        if (($candidate['profile_id'] ?? null) !== ($profile['profile_id'] ?? null)) {
                            continue;
                        }

                        $candidate['inventory_error'] = $exception->getMessage();
                        $candidate['updated_at'] = date(DATE_ATOM);
                    }
                    unset($candidate);
                    $this->writeProfileEntries($entries);
                }
            }

            $profiles = $this->readProfileEntries();
        }
        $positions = $this->positions()['data'] ?? [];
        $inventoryHoldings = $this->profileInventory()['data'] ?? [];
        $profilesData = [];

        foreach ($profiles as $profile) {
            $profilePositions = array_values(array_filter(
                $positions,
                static fn (array $position): bool => ($position['profile_id'] ?? null) === ($profile['profile_id'] ?? null)
            ));
            $profileInventory = array_values(array_filter(
                $inventoryHoldings,
                static fn (array $holding): bool => ($holding['profile_id'] ?? null) === ($profile['profile_id'] ?? null)
            ));
            $profilesData[] = $this->buildProfilePortfolio($profile, $profilePositions, $profileInventory);
        }

        usort($profilesData, static function (array $left, array $right): int {
            $urgentCompare = (int) ($right['ready_to_sell_count'] ?? 0) <=> (int) ($left['ready_to_sell_count'] ?? 0);
            if ($urgentCompare !== 0) {
                return $urgentCompare;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return [
            'data' => $profilesData,
            'meta' => [
                'total' => count($profilesData),
                'positions_count' => count($positions),
                'inventory_items_count' => count($inventoryHoldings),
                'profiles_ready_to_review' => count(array_filter($profilesData, static fn (array $profile): bool => ($profile['ready_to_sell_count'] ?? 0) > 0)),
            ],
        ];
    }

    public function profile(string $profileId): array
    {
        $profile = $this->findProfileById($profileId);
        if ($profile === null) {
            throw new RuntimeException('Profil introuvable.');
        }

        if (
            !$this->buildingCanonicalState
            && is_string($profile['steam_profile_url'] ?? null)
            && trim((string) $profile['steam_profile_url']) !== ''
            && empty($profile['inventory_synced_at'])
        ) {
            try {
                $this->syncProfileInventory($profileId);
            } catch (\Throwable $exception) {
                $entries = $this->readProfileEntries();
                foreach ($entries as &$candidate) {
                    if (($candidate['profile_id'] ?? null) !== $profileId) {
                        continue;
                    }

                    $candidate['inventory_error'] = $exception->getMessage();
                    $candidate['updated_at'] = date(DATE_ATOM);
                }
                unset($candidate);
                $this->writeProfileEntries($entries);
            }

            $profile = $this->findProfileById($profileId) ?? $profile;
        }

        $positions = array_values(array_filter(
            $this->positions()['data'] ?? [],
            static fn (array $position): bool => ($position['profile_id'] ?? null) === $profileId
        ));
        $inventory = $this->profileInventory($profileId)['data'] ?? [];
        $detail = $this->buildProfilePortfolio($profile, $positions, $inventory);
        $analysisRows = $positions !== [] ? $positions : $inventory;

        $detail['positions'] = $positions;
        $detail['inventory_items'] = $inventory;
        $detail['analysis_rows'] = $analysisRows;
        $detail['analysis_total'] = count($analysisRows);
        $detail['ready_to_sell_items'] = array_values(array_filter(
            $analysisRows,
            static fn (array $row): bool => ($row['sell_signal'] ?? '') === 'sell_now'
        ));
        $detail['watch_items'] = array_values(array_filter(
            $analysisRows,
            static fn (array $row): bool => ($row['sell_signal'] ?? '') === 'watch_sell'
        ));
        $detail['keep_items'] = array_values(array_filter(
            $analysisRows,
            static fn (array $row): bool => ($row['sell_signal'] ?? '') === 'hold'
        ));

        return $detail;
    }

    public function profileInventory(?string $profileId = null): array
    {
        $marketIndex = [];
        foreach (($this->readMarketData()['items'] ?? []) as $item) {
            $marketIndex[(string) ($item['market_hash_name'] ?? '')] = $item;
        }

        $holdings = array_map(
            fn (array $entry): array => $this->enrichInventoryHolding($entry, $marketIndex),
            $this->readProfileInventoryEntries()
        );

        if ($profileId !== null) {
            $holdings = array_values(array_filter(
                $holdings,
                static fn (array $holding): bool => ($holding['profile_id'] ?? null) === $profileId
            ));
        }

        usort($holdings, static function (array $left, array $right): int {
            $priorityCompare = (int) ($right['sell_priority'] ?? 0) <=> (int) ($left['sell_priority'] ?? 0);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return [
            'data' => $holdings,
            'meta' => [
                'total' => count($holdings),
                'ready_to_sell' => count(array_filter($holdings, static fn (array $holding): bool => ($holding['sell_signal'] ?? '') === 'sell_now')),
                'watch_to_sell' => count(array_filter($holdings, static fn (array $holding): bool => ($holding['sell_signal'] ?? '') === 'watch_sell')),
                'keep' => count(array_filter($holdings, static fn (array $holding): bool => ($holding['sell_signal'] ?? '') === 'hold')),
            ],
        ];
    }

    public function syncProfileInventory(string $profileId): array
    {
        $profile = $this->findProfileById($profileId);
        if ($profile === null) {
            throw new RuntimeException('Profil introuvable.');
        }

        $steamProfileUrl = $profile['steam_profile_url'] ?? null;
        if (!is_string($steamProfileUrl) || trim($steamProfileUrl) === '') {
            throw new RuntimeException('Ajoute un profil Steam public avant de sync l inventaire.');
        }

        $steamId64 = $this->resolveSteamId64FromProfileUrl($steamProfileUrl);
        $inventory = $this->fetchSteamInventory($steamId64);
        $items = $this->mapSteamInventoryItems($inventory, $profileId, $profile);

        $entries = $this->readProfileInventoryEntries();
        $entries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['profile_id'] ?? null) !== $profileId
        ));
        array_unshift($entries, ...$items);
        $this->writeProfileInventoryEntries($entries);

        $profiles = $this->readProfileEntries();
        foreach ($profiles as &$candidate) {
            if (($candidate['profile_id'] ?? null) !== $profileId) {
                continue;
            }

            $candidate['steam_id64'] = $steamId64;
            $candidate['inventory_synced_at'] = date(DATE_ATOM);
            $candidate['inventory_error'] = null;
            $candidate['updated_at'] = date(DATE_ATOM);
        }
        unset($candidate);
        $this->writeProfileEntries($profiles);

        return [
            'status' => 'success',
            'message' => sprintf('Inventaire Steam synchronise: %d item%s importe%s.', count($items), count($items) > 1 ? 's' : '', count($items) > 1 ? 's' : ''),
            'profile_id' => $profileId,
            'steam_id64' => $steamId64,
            'items_imported' => count($items),
        ];
    }

    public function saveProfile(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Le nom du profil est obligatoire.');
        }

        $strategy = trim((string) ($payload['strategy'] ?? 'balanced'));
        if ($strategy === '') {
            $strategy = 'balanced';
        }

        $steamProfileUrl = $this->normalizeSteamProfileUrl($payload['steam_profile_url'] ?? null);
        $discordWebhookUrl = $this->normalizeOptionalUrl($payload['discord_webhook_url'] ?? null);
        $note = trim((string) ($payload['note'] ?? ''));
        $profileId = trim((string) ($payload['profile_id'] ?? ''));

        $entries = $this->readProfileEntries();
        $index = null;
        foreach ($entries as $entryIndex => $entry) {
            if ($profileId !== '' && ($entry['profile_id'] ?? null) === $profileId) {
                $index = $entryIndex;
                break;
            }
        }

        $now = date(DATE_ATOM);
        $existing = $index !== null ? $entries[$index] : null;
        $entry = [
            'profile_id' => $existing['profile_id'] ?? uniqid('prof_', false),
            'name' => $name,
            'strategy' => $strategy,
            'steam_profile_url' => $steamProfileUrl,
            'discord_webhook_url' => $discordWebhookUrl,
            'note' => $note !== '' ? $note : null,
            'is_active' => true,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];

        if ($index !== null) {
            $entries[$index] = $entry;
        } else {
            array_unshift($entries, $entry);
        }

        $this->writeProfileEntries($entries);

        if ($steamProfileUrl !== null) {
            try {
                $this->syncProfileInventory($entry['profile_id']);
            } catch (\Throwable $exception) {
                $entries = $this->readProfileEntries();
                foreach ($entries as &$candidate) {
                    if (($candidate['profile_id'] ?? null) !== $entry['profile_id']) {
                        continue;
                    }

                    $candidate['inventory_error'] = $exception->getMessage();
                    $candidate['updated_at'] = date(DATE_ATOM);
                }
                unset($candidate);
                $this->writeProfileEntries($entries);
            }
        }

        $profile = null;
        foreach ($this->profiles()['data'] as $candidate) {
            if (($candidate['profile_id'] ?? null) === $entry['profile_id']) {
                $profile = $candidate;
                break;
            }
        }

        return [
            'status' => 'success',
            'message' => 'Profil portefeuille enregistre.',
            'profile' => $profile,
        ];
    }

    public function deleteProfile(string $profileId): array
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            throw new RuntimeException('Profil introuvable.');
        }

        $entries = $this->readProfileEntries();
        $filtered = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['profile_id'] ?? '') !== $profileId
        ));

        if (count($filtered) === count($entries)) {
            throw new RuntimeException('Profil introuvable.');
        }

        $this->writeProfileEntries($filtered);

        $positions = $this->readPositionEntries();
        foreach ($positions as &$position) {
            if (($position['profile_id'] ?? null) === $profileId) {
                $position['profile_id'] = null;
            }
        }
        unset($position);
        $this->writePositionEntries($positions);

        $inventory = array_values(array_filter(
            $this->readProfileInventoryEntries(),
            static fn (array $entry): bool => ($entry['profile_id'] ?? null) !== $profileId
        ));
        $this->writeProfileInventoryEntries($inventory);

        return [
            'status' => 'success',
            'message' => 'Profil supprime. Les positions restent en portefeuille libre.',
            'profile_id' => $profileId,
        ];
    }

    public function savePosition(array $payload): array
    {
        $itemId = (int) ($payload['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new RuntimeException('Choisis un item avant d enregistrer une position.');
        }

        $marketItem = $this->findMarketItemById($itemId);
        if ($marketItem === null) {
            throw new RuntimeException('Item introuvable dans le marche live.');
        }

        $buyPrice = $this->parsePositiveFloat($payload['buy_price_eur'] ?? null, 'Le prix d achat doit etre superieur a 0.');
        $buyDate = $this->normalizeDateInput((string) ($payload['buy_date'] ?? date('Y-m-d')));
        $buyFloat = $this->parseOptionalFloat($payload['buy_float'] ?? null);
        if ($buyFloat !== null && ($buyFloat < 0 || $buyFloat > 1)) {
            throw new RuntimeException('La float doit etre comprise entre 0.0000 et 1.0000.');
        }

        $targetPrice = $this->parseOptionalPositiveFloat($payload['target_price_eur'] ?? null);
        $takeProfit = $this->parseOptionalPositiveFloat($payload['take_profit_pct'] ?? null);
        $stopLoss = $this->parseOptionalPositiveFloat($payload['stop_loss_pct'] ?? null);
        $profileId = $this->normalizeOptionalProfileId($payload['profile_id'] ?? null);
        $note = trim((string) ($payload['note'] ?? ''));
        $noteLength = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
        if ($noteLength > 240) {
            $note = function_exists('mb_substr') ? mb_substr($note, 0, 240) : substr($note, 0, 240);
        }

        $positionId = trim((string) ($payload['position_id'] ?? ''));
        $entries = $this->readPositionEntries();
        $index = null;
        foreach ($entries as $entryIndex => $entry) {
            if (($entry['position_id'] ?? null) === $positionId && $positionId !== '') {
                $index = $entryIndex;
                break;
            }
        }

        $now = date(DATE_ATOM);
        $existing = $index !== null ? $entries[$index] : null;
        $entry = [
            'position_id' => $existing['position_id'] ?? uniqid('pos_', false),
            'item_id' => $itemId,
            'market_hash_name' => (string) ($marketItem['market_hash_name'] ?? $marketItem['name'] ?? ''),
            'buy_price_eur' => $buyPrice,
            'buy_date' => $buyDate,
            'buy_float' => $buyFloat,
            'target_price_eur' => $targetPrice,
            'take_profit_pct' => $takeProfit,
            'stop_loss_pct' => $stopLoss,
            'profile_id' => $profileId,
            'note' => $note !== '' ? $note : null,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];

        if ($index !== null) {
            $entries[$index] = $entry;
        } else {
            array_unshift($entries, $entry);
        }

        $this->writePositionEntries($entries);

        $position = null;
        foreach ($this->positions()['data'] as $candidate) {
            if (($candidate['position_id'] ?? null) === $entry['position_id']) {
                $position = $candidate;
                break;
            }
        }

        return [
            'status' => 'success',
            'message' => 'Position enregistree. Le signal de vente est calcule a partir du marche live, pas d une usure de float.',
            'position' => $position,
        ];
    }

    public function deletePosition(string $positionId): array
    {
        $positionId = trim($positionId);
        if ($positionId === '') {
            throw new RuntimeException('Position introuvable.');
        }

        $entries = $this->readPositionEntries();
        $filtered = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['position_id'] ?? '') !== $positionId
        ));

        if (count($filtered) === count($entries)) {
            throw new RuntimeException('Position introuvable.');
        }

        $this->writePositionEntries($filtered);

        return [
            'status' => 'success',
            'message' => 'Position supprimee.',
            'position_id' => $positionId,
        ];
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
        $discordConfigured = $this->discordWebhookIsConfigured();

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
                [
                    'name' => 'Discord',
                    'status' => $discordConfigured ? 'ready' : 'unknown',
                    'note' => $discordConfigured
                        ? 'webhook configure pour envoi du rapport du jour'
                        : 'DISCORD_WEBHOOK_URL absente, export Discord desactive',
                ],
            ],
            'jobs' => [
                'sync-catalog' => $latestJobs['sync-catalog'] ?? 'pending',
                'sync-market' => $latestJobs['sync-market'] ?? 'pending',
                'generate-report' => $latestJobs['generate-report'] ?? 'pending',
                'sync-csfloat' => $latestJobs['sync-csfloat'] ?? 'pending',
                'send-discord-report' => $latestJobs['send-discord-report'] ?? 'pending',
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
            'send-discord-report' => $this->runJob($jobName, fn (): array => $this->performDiscordReport()),
            default => throw new RuntimeException('Unknown job.'),
        };
    }

    public function openRouterTest(): array
    {
        if (!$this->openRouterIsConfigured()) {
            return [
                'status' => 'error',
                'error' => 'OPENROUTER_API_KEY absente.',
            ];
        }

        $payload = [
            'model' => $this->openRouterModel(),
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => 'Reply with a short OK.'],
                ['role' => 'user', 'content' => 'Ping'],
            ],
        ];

        $startedAt = microtime(true);
        try {
            $response = $this->postJsonWithCurl(
                'https://openrouter.ai/api/v1/chat/completions',
                $payload,
                [
                    'Authorization: Bearer ' . $this->env('OPENROUTER_API_KEY'),
                    'HTTP-Referer: https://cs2-market-daily-radar.local',
                    'X-Title: CS2 Market Daily Radar OpenRouter Test',
                ],
                $this->openRouterTestTimeoutSeconds()
            );

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $excerpt = trim($this->extractAssistantContent($response));
            if (strlen($excerpt) > 120) {
                $excerpt = substr($excerpt, 0, 120) . '...';
            }

            return [
                'status' => 'ok',
                'model' => (string) ($response['model'] ?? $this->openRouterModel()),
                'latency_ms' => $latencyMs,
                'response_excerpt' => $excerpt,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'model' => $this->openRouterModel(),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response_excerpt' => null,
                'error' => $this->normalizeAiErrorMessage($exception->getMessage()),
            ];
        }
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
        foreach ($this->readWatchlistEntries() as $entry) {
            if (($entry['is_active'] ?? true) !== true) {
                continue;
            }

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
                $report['ai_risk_cards'] = $aiAnalysis['risks'];
                $report['ai_false_signal_cards'] = $aiAnalysis['false_signals'];
                $report['ai_stable_watch_cards'] = $aiAnalysis['stable_watch'];
                $report['ai_watchlist_actions'] = $this->applyAiWatchlistActions($aiAnalysis['watchlist_actions']);
                $report['ai_best_deals_sources'] = $aiAnalysis['sources'];
                $report['ai_model'] = $aiAnalysis['model'];
                $report['ai_generated_at'] = $aiAnalysis['generated_at'];
                $report['ai_best_deals_error'] = null;
            }
        } catch (\Throwable $exception) {
            $report['ai_best_deals_error'] = $this->normalizeAiErrorMessage($exception->getMessage());
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

    private function performDiscordReport(): array
    {
        if (!$this->discordWebhookIsConfigured()) {
            throw new RuntimeException('DISCORD_WEBHOOK_URL absente. Configure le webhook Discord avant l envoi.');
        }

        $report = $this->reportToday();
        $overview = $this->overview();
        $watchlist = $this->watchlist()['data'] ?? [];
        $marketIndex = [];
        foreach (($this->readMarketData()['items'] ?? []) as $item) {
            $marketIndex[$item['market_hash_name']] = $item;
            $marketIndex[$item['name'] ?? $item['market_hash_name']] = $item;
        }

        $embeds = [$this->buildDiscordSummaryEmbed($report, $overview, $watchlist)];

        foreach (array_slice($report['top_opportunities'] ?? [], 0, 4) as $card) {
            $marketItem = $marketIndex[(string) ($card['name'] ?? '')] ?? null;
            $embeds[] = $this->buildDiscordItemEmbed(
                is_array($marketItem) ? array_merge($marketItem, $card) : $card,
                'Top opportunité',
                $card['reason'] ?? 'Signal du jour',
                0xB54B2E
            );
        }

        foreach (array_slice($watchlist, 0, 4) as $watchItem) {
            $marketItem = $marketIndex[(string) ($watchItem['name'] ?? '')] ?? null;
            $embeds[] = $this->buildDiscordItemEmbed(
                is_array($marketItem) ? array_merge($marketItem, $watchItem) : $watchItem,
                'Watchlist',
                $watchItem['last_ai_reason'] ?? $watchItem['note'] ?? 'Item suivi de près',
                0xD28C49
            );
        }

        $payload = [
            'username' => 'CS2 Daily Radar',
            'avatar_url' => 'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/icons/logo.png',
            'content' => sprintf('Radar CS2 du %s', (string) ($report['date'] ?? date('Y-m-d'))),
            'allowed_mentions' => ['parse' => []],
            'embeds' => array_slice($embeds, 0, 10),
        ];

        $webhookUrl = $this->discordWebhookUrl();
        $separator = str_contains($webhookUrl, '?') ? '&' : '?';
        $response = $this->postJsonWithCurl($webhookUrl . $separator . 'wait=true', $payload, [], 60);

        return [
            'sent_at' => date(DATE_ATOM),
            'embeds_sent' => count($payload['embeds']),
            'discord_message_id' => $response['id'] ?? null,
            'report_date' => $report['date'] ?? date('Y-m-d'),
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
            $this->seedWatchlistEntry('AK-47 | Redline (Field-Tested)', 'suivi de momentum'),
            $this->seedWatchlistEntry('USP-S | Cortex (Minimal Wear)', 'surveillance cassure'),
            $this->seedWatchlistEntry('AWP | Asiimov (Battle-Scarred)', 'item liquide'),
            $this->seedWatchlistEntry('Desert Eagle | Printstream (Field-Tested)', 'sous moyenne 7 jours'),
        ]);
    }

    private function ensurePositions(): void
    {
        if (is_file($this->positionsFile)) {
            return;
        }

        $this->writeJson($this->positionsFile, []);
    }

    private function ensureProfiles(): void
    {
        if (is_file($this->profilesFile)) {
            return;
        }

        $now = date(DATE_ATOM);
        $this->writeJson($this->profilesFile, [
            [
                'profile_id' => 'prof_demo_main',
                'name' => 'Portefeuille principal',
                'strategy' => 'balanced',
                'steam_profile_url' => null,
                'discord_webhook_url' => null,
                'note' => 'Profil demo pour suivre les meilleures sorties.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function ensureProfileInventory(): void
    {
        if (is_file($this->inventoryFile)) {
            return;
        }

        $this->writeJson($this->inventoryFile, []);
    }

    private function seedWatchlistEntry(string $marketHashName, string $note): array
    {
        $now = date(DATE_ATOM);

        return [
            'market_hash_name' => $marketHashName,
            'note' => $note,
            'managed_by' => 'system',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'last_ai_action' => null,
            'last_ai_reason' => null,
        ];
    }

    private function readWatchlistEntries(): array
    {
        $entries = $this->readJson($this->watchlistFile, []);
        $normalized = [];

        foreach ($entries as $entry) {
            $candidate = $this->normalizeWatchlistEntry($entry);
            if ($candidate === null) {
                continue;
            }

            $normalized[$candidate['market_hash_name']] = $candidate;
        }

        return array_values($normalized);
    }

    private function normalizeWatchlistEntry(mixed $entry): ?array
    {
        if (is_string($entry)) {
            $name = trim($entry);
            if ($name === '') {
                return null;
            }

            $now = date(DATE_ATOM);

            return [
                'market_hash_name' => $name,
                'note' => 'surveillance radar',
                'managed_by' => 'system',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
                'last_ai_action' => null,
                'last_ai_reason' => null,
            ];
        }

        if (!is_array($entry)) {
            return null;
        }

        $name = trim((string) ($entry['market_hash_name'] ?? $entry['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $now = date(DATE_ATOM);
        $managedBy = trim((string) ($entry['managed_by'] ?? 'system'));
        if ($managedBy === '') {
            $managedBy = 'system';
        }

        return [
            'market_hash_name' => $name,
            'note' => trim((string) ($entry['note'] ?? 'surveillance radar')) ?: 'surveillance radar',
            'managed_by' => $managedBy,
            'is_active' => isset($entry['is_active']) ? (bool) $entry['is_active'] : true,
            'created_at' => (string) ($entry['created_at'] ?? $now),
            'updated_at' => (string) ($entry['updated_at'] ?? $entry['created_at'] ?? $now),
            'last_ai_action' => isset($entry['last_ai_action']) ? (string) $entry['last_ai_action'] : null,
            'last_ai_reason' => isset($entry['last_ai_reason']) ? trim((string) $entry['last_ai_reason']) : null,
        ];
    }

    private function writeWatchlistEntries(array $entries): void
    {
        $this->writeJson($this->watchlistFile, array_values($entries));
    }

    private function readPositionEntries(): array
    {
        $entries = $this->readJson($this->positionsFile, []);
        $normalized = [];

        foreach ($entries as $entry) {
            try {
                $candidate = $this->normalizePositionEntry($entry);
            } catch (\Throwable) {
                $candidate = null;
            }

            if ($candidate === null) {
                continue;
            }

            $normalized[$candidate['position_id']] = $candidate;
        }

        return array_values($normalized);
    }

    private function normalizePositionEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $itemId = (int) ($entry['item_id'] ?? 0);
        $marketHashName = trim((string) ($entry['market_hash_name'] ?? $entry['name'] ?? ''));
        if ($itemId <= 0 || $marketHashName === '') {
            return null;
        }

        $buyPrice = isset($entry['buy_price_eur']) ? (float) $entry['buy_price_eur'] : null;
        if ($buyPrice === null || $buyPrice <= 0) {
            return null;
        }

        $now = date(DATE_ATOM);

        return [
            'position_id' => trim((string) ($entry['position_id'] ?? uniqid('pos_', false))),
            'item_id' => $itemId,
            'market_hash_name' => $marketHashName,
            'buy_price_eur' => round($buyPrice, 2),
            'buy_date' => $this->normalizeDateInput((string) ($entry['buy_date'] ?? date('Y-m-d'))),
            'buy_float' => $this->parseOptionalFloat($entry['buy_float'] ?? null),
            'target_price_eur' => $this->parseOptionalPositiveFloat($entry['target_price_eur'] ?? null),
            'take_profit_pct' => $this->parseOptionalPositiveFloat($entry['take_profit_pct'] ?? null),
            'stop_loss_pct' => $this->parseOptionalPositiveFloat($entry['stop_loss_pct'] ?? null),
            'profile_id' => $this->normalizeOptionalProfileId($entry['profile_id'] ?? null),
            'note' => ($note = trim((string) ($entry['note'] ?? ''))) !== '' ? $note : null,
            'created_at' => (string) ($entry['created_at'] ?? $now),
            'updated_at' => (string) ($entry['updated_at'] ?? $entry['created_at'] ?? $now),
        ];
    }

    private function writePositionEntries(array $entries): void
    {
        $this->writeJson($this->positionsFile, array_values($entries));
    }

    private function readProfileEntries(): array
    {
        $entries = $this->readJson($this->profilesFile, []);
        $normalized = [];

        foreach ($entries as $entry) {
            try {
                $candidate = $this->normalizeProfileEntry($entry);
            } catch (\Throwable) {
                $candidate = null;
            }

            if ($candidate === null) {
                continue;
            }

            $normalized[$candidate['profile_id']] = $candidate;
        }

        return array_values($normalized);
    }

    private function readProfileInventoryEntries(): array
    {
        $entries = $this->readJson($this->inventoryFile, []);
        $normalized = [];

        foreach ($entries as $entry) {
            $candidate = $this->normalizeProfileInventoryEntry($entry);
            if ($candidate === null) {
                continue;
            }

            $normalized[$candidate['profile_id'] . ':' . $candidate['asset_id']] = $candidate;
        }

        return array_values($normalized);
    }

    private function normalizeProfileEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $name = trim((string) ($entry['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $now = date(DATE_ATOM);

        return [
            'profile_id' => trim((string) ($entry['profile_id'] ?? uniqid('prof_', false))),
            'name' => $name,
            'strategy' => trim((string) ($entry['strategy'] ?? 'balanced')) ?: 'balanced',
            'steam_profile_url' => $this->normalizeSteamProfileUrl($entry['steam_profile_url'] ?? null, false),
            'steam_id64' => isset($entry['steam_id64']) ? trim((string) $entry['steam_id64']) : null,
            'discord_webhook_url' => $this->normalizeOptionalUrl($entry['discord_webhook_url'] ?? null),
            'note' => ($note = trim((string) ($entry['note'] ?? ''))) !== '' ? $note : null,
            'is_active' => isset($entry['is_active']) ? (bool) $entry['is_active'] : true,
            'inventory_synced_at' => isset($entry['inventory_synced_at']) ? (string) $entry['inventory_synced_at'] : null,
            'inventory_error' => isset($entry['inventory_error']) ? trim((string) $entry['inventory_error']) : null,
            'created_at' => (string) ($entry['created_at'] ?? $now),
            'updated_at' => (string) ($entry['updated_at'] ?? $entry['created_at'] ?? $now),
        ];
    }

    private function writeProfileEntries(array $entries): void
    {
        $this->writeJson($this->profilesFile, array_values($entries));
    }

    private function normalizeProfileInventoryEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $profileId = trim((string) ($entry['profile_id'] ?? ''));
        $assetId = trim((string) ($entry['asset_id'] ?? ''));
        $marketHashName = trim((string) ($entry['market_hash_name'] ?? ''));
        if ($profileId === '' || $assetId === '' || $marketHashName === '') {
            return null;
        }

        return [
            'profile_id' => $profileId,
            'asset_id' => $assetId,
            'class_id' => trim((string) ($entry['class_id'] ?? '')),
            'instance_id' => trim((string) ($entry['instance_id'] ?? '')),
            'market_hash_name' => $marketHashName,
            'name' => trim((string) ($entry['name'] ?? $marketHashName)) ?: $marketHashName,
            'amount' => max(1, (int) ($entry['amount'] ?? 1)),
            'tradable' => isset($entry['tradable']) ? (bool) $entry['tradable'] : true,
            'marketable' => isset($entry['marketable']) ? (bool) $entry['marketable'] : true,
            'commodity' => isset($entry['commodity']) ? (bool) $entry['commodity'] : false,
            'image_url' => isset($entry['image_url']) ? trim((string) $entry['image_url']) : null,
            'item_page' => isset($entry['item_page']) ? trim((string) $entry['item_page']) : null,
            'imported_at' => isset($entry['imported_at']) ? (string) $entry['imported_at'] : date(DATE_ATOM),
        ];
    }

    private function writeProfileInventoryEntries(array $entries): void
    {
        $this->writeJson($this->inventoryFile, array_values($entries));
    }

    private function enrichPositionEntry(array $entry, array $marketIndex, array $profileNames = []): array
    {
        $item = $marketIndex[(int) ($entry['item_id'] ?? 0)] ?? null;
        $currentPrice = isset($item['current_price']) ? (float) $item['current_price'] : null;
        $buyPrice = (float) ($entry['buy_price_eur'] ?? 0.0);
        $pnlValue = ($currentPrice !== null && $buyPrice > 0)
            ? round($currentPrice - $buyPrice, 2)
            : null;
        $pnlPct = ($currentPrice !== null && $buyPrice > 0)
            ? round((($currentPrice - $buyPrice) / $buyPrice) * 100, 2)
            : null;
        $targetPrice = isset($entry['target_price_eur']) ? (float) $entry['target_price_eur'] : null;
        $targetDelta = ($currentPrice !== null && $targetPrice !== null && $targetPrice > 0)
            ? round((($currentPrice - $targetPrice) / $targetPrice) * 100, 2)
            : null;
        $floatValue = isset($entry['buy_float']) && is_numeric($entry['buy_float']) ? (float) $entry['buy_float'] : null;
        $floatWear = $this->wearLabelFromFloat($floatValue);
        $signal = $this->buildSellSignal($entry, $item, $pnlPct, $currentPrice);

        return [
            'position_id' => $entry['position_id'],
            'id' => $item['id'] ?? (int) $entry['item_id'],
            'item_id' => (int) $entry['item_id'],
            'market_hash_name' => $entry['market_hash_name'],
            'name' => $item['name'] ?? $entry['market_hash_name'],
            'amount' => 1,
            'weapon' => $item['weapon'] ?? null,
            'rarity' => $item['rarity'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'item_page' => $item['item_page'] ?? null,
            'market_page' => $item['market_page'] ?? null,
            'buy_price_eur' => $buyPrice,
            'cost_basis_total_eur' => $buyPrice,
            'buy_date' => $entry['buy_date'],
            'days_held' => $this->daysSince($entry['buy_date']),
            'buy_float' => $floatValue,
            'buy_float_wear' => $floatWear,
            'profile_id' => $entry['profile_id'] ?? null,
            'profile_name' => isset($entry['profile_id']) ? ($profileNames[(string) $entry['profile_id']] ?? 'Profil supprime') : null,
            'float_note' => $floatValue !== null
                ? sprintf('Float %.4f (%s) fixe: la float ne bouge pas avec les games dans CS2.', $floatValue, $floatWear ?? 'wear inconnu')
                : 'La float est fixe dans CS2. On suit donc surtout prix, liquidite et objectif de sortie.',
            'target_price_eur' => $targetPrice,
            'take_profit_pct' => isset($entry['take_profit_pct']) ? (float) $entry['take_profit_pct'] : null,
            'stop_loss_pct' => isset($entry['stop_loss_pct']) ? (float) $entry['stop_loss_pct'] : null,
            'target_gap_pct' => $targetDelta,
            'current_price_eur' => $currentPrice,
            'current_total_value_eur' => $currentPrice,
            'pnl_eur' => $pnlValue,
            'pnl_pct' => $pnlPct,
            'interest_score' => $item['interest_score'] ?? null,
            'change_vs_yesterday_pct' => $item['change_vs_yesterday_pct'] ?? null,
            'change_vs_7d_pct' => $item['change_vs_7d_pct'] ?? null,
            'sales_24h_volume' => $item['sales_24h_volume'] ?? null,
            'volume_ratio_24h_7d' => $item['volume_ratio_24h_7d'] ?? null,
            'note' => $entry['note'] ?? null,
            'sell_signal' => $signal['key'],
            'sell_label' => $signal['label'],
            'sell_priority' => $signal['priority'],
            'sell_reasons' => $signal['reasons'],
            'primary_reason' => $signal['reasons'][0] ?? 'Pas de fenetre de vente prioritaire pour l instant.',
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['updated_at'],
        ];
    }

    private function buildSellSignal(array $entry, ?array $item, ?float $pnlPct, ?float $currentPrice): array
    {
        $change24 = isset($item['change_vs_yesterday_pct']) ? (float) $item['change_vs_yesterday_pct'] : null;
        $change7 = isset($item['change_vs_7d_pct']) ? (float) $item['change_vs_7d_pct'] : null;
        $volume24 = (int) ($item['sales_24h_volume'] ?? 0);
        $volumeRatio = isset($item['volume_ratio_24h_7d']) ? (float) $item['volume_ratio_24h_7d'] : null;
        $score = (int) ($item['interest_score'] ?? 0);
        $targetPrice = isset($entry['target_price_eur']) ? (float) $entry['target_price_eur'] : null;
        $takeProfit = isset($entry['take_profit_pct']) ? (float) $entry['take_profit_pct'] : null;
        $stopLoss = isset($entry['stop_loss_pct']) ? (float) $entry['stop_loss_pct'] : null;
        $floatValue = isset($entry['buy_float']) && is_numeric($entry['buy_float']) ? (float) $entry['buy_float'] : null;
        $reasons = [];
        $key = 'hold';
        $label = 'Garder';
        $priority = 35;

        if ($currentPrice === null || $currentPrice <= 0) {
            return [
                'key' => 'watch_sell',
                'label' => 'Surveiller',
                'priority' => 55,
                'reasons' => ['Prix live indisponible: impossible de valider un point de sortie fiable.'],
            ];
        }

        if ($targetPrice !== null && $currentPrice >= $targetPrice) {
            $key = 'sell_now';
            $label = 'Vendre';
            $priority = 100;
            $reasons[] = 'Objectif de prix atteint sur le marche live.';
        } elseif ($stopLoss !== null && $pnlPct !== null && $pnlPct <= -abs($stopLoss)) {
            $key = 'sell_now';
            $label = 'Couper';
            $priority = 92;
            $reasons[] = 'Stop loss touche: le risque de poursuite baissiere augmente.';
        } elseif ($takeProfit !== null && $pnlPct !== null && $pnlPct >= $takeProfit && (($change24 ?? 0.0) <= -3.0 || ($volumeRatio !== null && $volumeRatio < 0.9))) {
            $key = 'sell_now';
            $label = 'Vendre';
            $priority = 88;
            $reasons[] = 'Take profit atteint avec un momentum qui commence a ralentir.';
        } elseif ($pnlPct !== null && $pnlPct >= max(8.0, ($takeProfit ?? 12.0) * 0.65) && ($change24 ?? 0.0) < 0.0) {
            $key = 'watch_sell';
            $label = 'Surveiller';
            $priority = 72;
            $reasons[] = 'La position est verte mais le 24h se retourne: fenetre de vente a surveiller.';
        } elseif ($volume24 <= 1 && abs($change24 ?? 0.0) >= 12.0) {
            $key = 'watch_sell';
            $label = 'Surveiller';
            $priority = 66;
            $reasons[] = 'Variation brutale sur faible volume: attention au faux signal de sortie.';
        } elseif ($score < 45 && $pnlPct !== null && $pnlPct > 0) {
            $key = 'watch_sell';
            $label = 'Surveiller';
            $priority = 61;
            $reasons[] = 'Le marche reste peu propre pour conserver longtemps cette plus-value.';
        } else {
            $reasons[] = 'Pas de signal de vente prioritaire: la position peut continuer a respirer.';
        }

        if ($targetPrice !== null && $currentPrice < $targetPrice) {
            $reasons[] = sprintf('Encore %.2f EUR avant l objectif de sortie.', $targetPrice - $currentPrice);
        }

        if ($change7 !== null && $change7 < -8.0) {
            $reasons[] = 'Le prix reste sous sa moyenne 7 jours: attention au rebond encore fragile.';
        } elseif ($change7 !== null && $change7 > 10.0 && $pnlPct !== null && $pnlPct > 0) {
            $reasons[] = 'Le skin cote deja au-dessus de sa moyenne 7 jours: penser a securiser si la liquidite se tasse.';
        }

        if ($floatValue !== null) {
            $reasons[] = sprintf('Float %.4f conservee telle quelle: aucun risque de passage automatique vers un wear inferieur.', $floatValue);
        }

        return [
            'key' => $key,
            'label' => $label,
            'priority' => $priority,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function buildProfilePortfolio(array $profile, array $positions, array $inventoryHoldings = []): array
    {
        $currentValue = 0.0;
        $costBasis = 0.0;
        $ready = 0;
        $watch = 0;
        $keep = 0;
        $units = 0;
        $analysisRows = $positions !== [] ? $positions : $inventoryHoldings;
        $usesImportedInventory = $positions === [] && $inventoryHoldings !== [];

        foreach ($analysisRows as $position) {
            $currentValue += (float) ($position['current_total_value_eur'] ?? $position['current_price_eur'] ?? 0.0);
            $costBasis += (float) ($position['cost_basis_total_eur'] ?? $position['buy_price_eur'] ?? 0.0);
            $units += max(1, (int) ($position['amount'] ?? 1));

            $signal = (string) ($position['sell_signal'] ?? 'hold');
            if ($signal === 'sell_now') {
                $ready++;
            } elseif ($signal === 'watch_sell') {
                $watch++;
            } else {
                $keep++;
            }
        }

        $pnlValue = round($currentValue - $costBasis, 2);
        $pnlPct = $costBasis > 0 ? round((($currentValue - $costBasis) / $costBasis) * 100, 2) : null;
        $urgent = array_slice(array_values(array_filter(
            $analysisRows,
            static fn (array $position): bool => ($position['sell_signal'] ?? '') === 'sell_now'
        )), 0, 3);
        $watchItems = array_slice(array_values(array_filter(
            $analysisRows,
            static fn (array $position): bool => ($position['sell_signal'] ?? '') === 'watch_sell'
        )), 0, 3);
        $advice = $this->buildProfileActionAdvice($ready, $watch, $keep, count($analysisRows), $usesImportedInventory);

        return [
            'profile_id' => $profile['profile_id'],
            'name' => $profile['name'],
            'strategy' => $profile['strategy'],
            'steam_profile_url' => $profile['steam_profile_url'],
            'steam_id64' => $profile['steam_id64'] ?? null,
            'discord_webhook_url' => $profile['discord_webhook_url'],
            'note' => $profile['note'],
            'inventory_synced_at' => $profile['inventory_synced_at'] ?? null,
            'inventory_error' => $profile['inventory_error'] ?? null,
            'positions_count' => $usesImportedInventory ? count($inventoryHoldings) : count($positions),
            'manual_positions_count' => count($positions),
            'inventory_items_count' => count($inventoryHoldings),
            'units_count' => $units,
            'analysis_mode' => $usesImportedInventory ? 'inventory' : 'positions',
            'ready_to_sell_count' => $ready,
            'watch_count' => $watch,
            'keep_count' => $keep,
            'portfolio_value_eur' => round($currentValue, 2),
            'cost_basis_eur' => round($costBasis, 2),
            'pnl_eur' => $pnlValue,
            'pnl_pct' => $pnlPct,
            'summary' => $this->buildProfileSummaryText($profile['name'], count($positions), count($inventoryHoldings), $ready, $watch, $keep, $pnlPct, $usesImportedInventory, $profile['inventory_error'] ?? null),
            'advice_title' => $advice['title'],
            'advice_text' => $advice['text'],
            'urgent_sales' => $urgent,
            'watch_candidates' => $watchItems,
            'positions' => array_slice($positions, 0, 12),
            'inventory_items' => array_slice($inventoryHoldings, 0, 12),
            'created_at' => $profile['created_at'],
            'updated_at' => $profile['updated_at'],
        ];
    }

    private function buildProfileSummaryText(string $name, int $positionsCount, int $inventoryCount, int $ready, int $watch, int $keep, ?float $pnlPct, bool $usesImportedInventory, ?string $inventoryError = null): string
    {
        if ($inventoryError !== null && $positionsCount === 0 && $inventoryCount === 0) {
            return sprintf('%s: import inventaire impossible pour le moment. %s', $name, $inventoryError);
        }

        if ($positionsCount === 0 && $inventoryCount === 0) {
            return sprintf('%s n a encore aucun item importe. Ajoute un profil Steam public ou saisis des positions manuelles.', $name);
        }

        if ($usesImportedInventory) {
            return sprintf(
                '%s suit %d item%s importe%s depuis Steam. %d vente%s potentielle%s, %d item%s a surveiller et %d a conserver. Les PnL restent a 0 tant que tu n ajoutes pas de prix d entree.',
                $name,
                $inventoryCount,
                $inventoryCount > 1 ? 's' : '',
                $inventoryCount > 1 ? 's' : '',
                $ready,
                $ready > 1 ? 's' : '',
                $ready > 1 ? 's' : '',
                $watch,
                $watch > 1 ? 's' : '',
                $keep
            );
        }

        return sprintf(
            '%s suit %d position%s. %d vente%s prioritaire%s, %d position%s a surveiller et %d a conserver. Performance latente: %s.',
            $name,
            $positionsCount,
            $positionsCount > 1 ? 's' : '',
            $ready,
            $ready > 1 ? 's' : '',
            $ready > 1 ? 's' : '',
            $watch,
            $watch > 1 ? 's' : '',
            $keep,
            $pnlPct !== null ? sprintf('%+.1f%%', $pnlPct) : 'n/a'
        );
    }

    private function buildProfileActionAdvice(int $ready, int $watch, int $keep, int $analysisCount, bool $usesImportedInventory): array
    {
        if ($analysisCount === 0) {
            return [
                'title' => 'Aucune lecture portefeuille',
                'text' => 'Importe un inventaire Steam ou ajoute une position manuelle pour recevoir une lecture keep / watch / sell.',
            ];
        }

        if ($ready > 0) {
            return [
                'title' => 'Ventes prioritaires detectees',
                'text' => sprintf('%d item%s ressort%s en vente prioritaire sur le dernier refresh marche. %d item%s rest%s a surveiller.', $ready, $ready > 1 ? 's' : '', $ready > 1 ? 'ent' : '', $watch, $watch > 1 ? 's' : '', $watch > 1 ? 'ent' : ''),
            ];
        }

        if ($watch > 0) {
            return [
                'title' => 'Surveillance active',
                'text' => sprintf('Aucune vente immediate, mais %d item%s demand%s une surveillance rapprochee apres chaque refresh marche.', $watch, $watch > 1 ? 's' : '', $watch > 1 ? 'ent' : ''),
            ];
        }

        return [
            'title' => $usesImportedInventory ? 'Portefeuille a conserver' : 'Positions stables',
            'text' => sprintf('Le dernier refresh marche ne remonte pas de vente urgente. %d item%s sembl%s plutot a conserver pour le moment.', $keep, $keep > 1 ? 's' : '', $keep > 1 ? 'ent' : ''),
        ];
    }

    private function enrichInventoryHolding(array $entry, array $marketIndex): array
    {
        $marketItem = $marketIndex[$entry['market_hash_name']] ?? null;
        $signal = $this->buildInventorySellSignal($marketItem, $entry);
        $currentPrice = isset($marketItem['current_price']) ? (float) $marketItem['current_price'] : null;
        $amount = max(1, (int) ($entry['amount'] ?? 1));
        $totalValue = $currentPrice !== null ? round($currentPrice * $amount, 2) : null;

        return [
            'profile_id' => $entry['profile_id'],
            'asset_id' => $entry['asset_id'],
            'class_id' => $entry['class_id'],
            'instance_id' => $entry['instance_id'],
            'id' => $marketItem['id'] ?? $this->idFromName($entry['market_hash_name']),
            'market_hash_name' => $entry['market_hash_name'],
            'name' => $entry['name'],
            'amount' => $amount,
            'tradable' => $entry['tradable'],
            'marketable' => $entry['marketable'],
            'commodity' => $entry['commodity'],
            'image_url' => $marketItem['image_url'] ?? $entry['image_url'],
            'item_page' => $marketItem['item_page'] ?? $entry['item_page'],
            'market_page' => $marketItem['market_page'] ?? null,
            'current_price_eur' => $currentPrice,
            'current_total_value_eur' => $totalValue,
            'buy_price_eur' => 0.0,
            'cost_basis_total_eur' => 0.0,
            'interest_score' => $marketItem['interest_score'] ?? null,
            'change_vs_yesterday_pct' => $marketItem['change_vs_yesterday_pct'] ?? null,
            'change_vs_7d_pct' => $marketItem['change_vs_7d_pct'] ?? null,
            'sales_24h_volume' => $marketItem['sales_24h_volume'] ?? null,
            'volume_ratio_24h_7d' => $marketItem['volume_ratio_24h_7d'] ?? null,
            'sell_signal' => $signal['key'],
            'sell_label' => $signal['label'],
            'sell_priority' => $signal['priority'],
            'sell_reasons' => $signal['reasons'],
            'primary_reason' => $signal['reasons'][0] ?? 'Item importe depuis Steam.',
            'imported_at' => $entry['imported_at'],
        ];
    }

    private function buildInventorySellSignal(?array $marketItem, array $entry): array
    {
        if ($marketItem === null) {
            return [
                'key' => 'watch_sell',
                'label' => 'Surveiller',
                'priority' => 52,
                'reasons' => ['Item importe mais absent du radar marche actuel: impossible de produire un signal fort.'],
            ];
        }

        $change24 = isset($marketItem['change_vs_yesterday_pct']) ? (float) $marketItem['change_vs_yesterday_pct'] : null;
        $change7 = isset($marketItem['change_vs_7d_pct']) ? (float) $marketItem['change_vs_7d_pct'] : null;
        $score = (int) ($marketItem['interest_score'] ?? 0);
        $volume24 = (int) ($marketItem['sales_24h_volume'] ?? 0);
        $volumeRatio = isset($marketItem['volume_ratio_24h_7d']) ? (float) $marketItem['volume_ratio_24h_7d'] : null;
        $reasons = [];

        if (($change7 ?? 0.0) >= 12.0 && ($change24 ?? 0.0) <= 0.0) {
            $reasons[] = 'Le skin reste bien au-dessus de sa moyenne 7 jours mais le 24h se tasse: fenetre de vente a etudier.';
            return ['key' => 'sell_now', 'label' => 'Vendre', 'priority' => 86, 'reasons' => $reasons];
        }

        if (($change24 ?? 0.0) >= 10.0 && $volume24 <= 1) {
            $reasons[] = 'Pic violent sur tres faible volume: possible faux signal, attention a la sortie.';
            return ['key' => 'watch_sell', 'label' => 'Surveiller', 'priority' => 74, 'reasons' => $reasons];
        }

        if ($score >= 60 && ($change7 ?? 0.0) < 0 && ($volumeRatio ?? 0.0) >= 1.2) {
            $reasons[] = 'Le marche reste actif et l item conserve un potentiel de rebond: pas de vente immediate.';
            return ['key' => 'hold', 'label' => 'Garder', 'priority' => 38, 'reasons' => $reasons];
        }

        if ($score < 45 || (($volumeRatio ?? 1.0) < 0.8 && $volume24 <= 1)) {
            $reasons[] = 'Liquidite faible ou signal marche mediocre: surveiller une vente opportuniste.';
            return ['key' => 'watch_sell', 'label' => 'Surveiller', 'priority' => 62, 'reasons' => $reasons];
        }

        $reasons[] = 'Pas de signal de sortie dominant sur cet item importe.';
        return ['key' => 'hold', 'label' => 'Garder', 'priority' => 34, 'reasons' => $reasons];
    }

    private function wearLabelFromFloat(?float $float): ?string
    {
        if ($float === null || $float < 0 || $float > 1) {
            return null;
        }

        return match (true) {
            $float < 0.07 => 'Factory New',
            $float < 0.15 => 'Minimal Wear',
            $float < 0.38 => 'Field-Tested',
            $float < 0.45 => 'Well-Worn',
            default => 'Battle-Scarred',
        };
    }

    private function daysSince(string $date): int
    {
        try {
            $start = new \DateTimeImmutable($date);
            $end = new \DateTimeImmutable('today');
        } catch (\Throwable) {
            return 0;
        }

        return (int) $start->diff($end)->format('%a');
    }

    private function normalizeDateInput(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new RuntimeException('La date d achat est invalide.');
        }

        return date('Y-m-d', $timestamp);
    }

    private function parsePositiveFloat(mixed $value, string $message): float
    {
        $parsed = $this->parseOptionalFloat($value);
        if ($parsed === null || $parsed <= 0) {
            throw new RuntimeException($message);
        }

        return round($parsed, 2);
    }

    private function parseOptionalPositiveFloat(mixed $value): ?float
    {
        $parsed = $this->parseOptionalFloat($value);
        if ($parsed === null) {
            return null;
        }

        if ($parsed <= 0) {
            throw new RuntimeException('Les seuils de vente doivent etre superieurs a 0.');
        }

        return round($parsed, 2);
    }

    private function parseOptionalFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(str_replace(',', '.', (string) $value));
        if ($normalized === '') {
            return null;
        }

        if (!is_numeric($normalized)) {
            throw new RuntimeException('Une valeur numerique attendue est invalide.');
        }

        return (float) $normalized;
    }

    private function normalizeOptionalProfileId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $profileId = trim((string) $value);
        if ($profileId === '') {
            return null;
        }

        foreach ($this->readProfileEntries() as $profile) {
            if (($profile['profile_id'] ?? null) === $profileId) {
                return $profileId;
            }
        }

        throw new RuntimeException('Le profil selectionne est introuvable.');
    }

    private function normalizeOptionalUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Une URL de profil est invalide.');
        }

        return $url;
    }

    private function normalizeSteamProfileUrl(mixed $value, bool $strict = true): ?string
    {
        $url = $this->normalizeOptionalUrl($value);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = strtolower((string) ($parts['path'] ?? ''));

        $isSteamCommunity = str_contains($host, 'steamcommunity.com');
        $isTradeUrl = $isSteamCommunity && str_starts_with($path, '/tradeoffer/');
        if ($isTradeUrl) {
            if ($strict) {
                throw new RuntimeException('La trade URL Steam ne peut pas servir de lien inventaire. Mets plutot un profil Steam public ou une URL d inventaire public.');
            }

            return null;
        }

        if (!$isSteamCommunity) {
            if ($strict) {
                throw new RuntimeException('Le lien inventaire doit pointer vers steamcommunity.com.');
            }

            return null;
        }

        $looksLikeProfile = str_starts_with($path, '/id/')
            || str_starts_with($path, '/profiles/')
            || str_starts_with($path, '/inventory/');

        if (!$looksLikeProfile) {
            if ($strict) {
                throw new RuntimeException('Utilise une URL de profil Steam public ou d inventaire public, pas une URL d echange.');
            }

            return null;
        }

        return $url;
    }

    private function findProfileById(string $profileId): ?array
    {
        foreach ($this->readProfileEntries() as $profile) {
            if (($profile['profile_id'] ?? null) === $profileId) {
                return $profile;
            }
        }

        return null;
    }

    private function resolveSteamId64FromProfileUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        if (($segments[0] ?? null) === 'profiles' && isset($segments[1]) && preg_match('/^\d{17}$/', $segments[1]) === 1) {
            return $segments[1];
        }

        if (($segments[0] ?? null) === 'inventory' && isset($segments[1]) && preg_match('/^\d{17}$/', $segments[1]) === 1) {
            return $segments[1];
        }

        $xmlUrl = rtrim($url, '/') . '/?xml=1';
        $xml = $this->fetchRawUrl($xmlUrl, ['Accept: application/xml, text/xml']);
        if (preg_match('/<steamID64><!\[CDATA\[(\d{17})\]\]><\/steamID64>/', $xml, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/<steamID64>(\d{17})<\/steamID64>/', $xml, $matches) === 1) {
            return $matches[1];
        }

        throw new RuntimeException('Impossible de resoudre le SteamID64 depuis ce profil. Verifie que le profil est public.');
    }

    private function fetchSteamInventory(string $steamId64): array
    {
        $startAssetId = null;
        $assets = [];
        $descriptions = [];

        do {
            $url = sprintf(
                'https://steamcommunity.com/inventory/%s/730/2?l=french&count=2000%s',
                rawurlencode($steamId64),
                $startAssetId !== null ? '&start_assetid=' . rawurlencode($startAssetId) : ''
            );

            $payload = $this->fetchJsonWithCurl($url, [
                'Accept: application/json, text/plain, */*',
            ], 60);

            $success = $payload['success'] ?? false;
            $isSuccess = $success === true || $success === 1 || $success === '1';
            if (!$isSuccess) {
                $error = trim((string) ($payload['Error'] ?? $payload['error'] ?? ''));
                throw new RuntimeException(
                    $error !== ''
                        ? 'Steam inventory error: ' . $error
                        : 'Steam a refuse l acces a l inventaire. Le profil doit etre public.'
                );
            }

            $assets = array_merge($assets, $payload['assets'] ?? []);
            $descriptions = array_merge($descriptions, $payload['descriptions'] ?? []);
            $startAssetId = isset($payload['more_items']) && $payload['more_items']
                ? (string) ($payload['last_assetid'] ?? '')
                : null;
        } while ($startAssetId !== null && $startAssetId !== '');

        return [
            'assets' => $assets,
            'descriptions' => $descriptions,
        ];
    }

    private function mapSteamInventoryItems(array $inventory, string $profileId, array $profile): array
    {
        $descriptions = [];
        foreach (($inventory['descriptions'] ?? []) as $description) {
            $key = (string) ($description['classid'] ?? '') . '_' . (string) ($description['instanceid'] ?? '');
            if ($key === '_') {
                continue;
            }
            $descriptions[$key] = $description;
        }

        $items = [];
        foreach (($inventory['assets'] ?? []) as $asset) {
            $key = (string) ($asset['classid'] ?? '') . '_' . (string) ($asset['instanceid'] ?? '');
            $description = $descriptions[$key] ?? null;
            if (!is_array($description)) {
                continue;
            }

            $marketHashName = trim((string) ($description['market_hash_name'] ?? ''));
            if ($marketHashName === '') {
                continue;
            }

            $items[] = [
                'profile_id' => $profileId,
                'asset_id' => trim((string) ($asset['assetid'] ?? '')),
                'class_id' => trim((string) ($asset['classid'] ?? '')),
                'instance_id' => trim((string) ($asset['instanceid'] ?? '')),
                'market_hash_name' => $marketHashName,
                'name' => trim((string) ($description['name'] ?? $marketHashName)) ?: $marketHashName,
                'amount' => max(1, (int) ($asset['amount'] ?? 1)),
                'tradable' => isset($description['tradable']) ? ((int) $description['tradable'] === 1) : true,
                'marketable' => isset($description['marketable']) ? ((int) $description['marketable'] === 1) : true,
                'commodity' => isset($description['commodity']) ? ((int) $description['commodity'] === 1) : false,
                'image_url' => $this->steamImageUrl((string) ($description['icon_url'] ?? '')),
                'item_page' => $profile['steam_profile_url'] ?? null,
                'imported_at' => date(DATE_ATOM),
            ];
        }

        return $items;
    }

    private function steamImageUrl(string $iconPath): ?string
    {
        $iconPath = trim($iconPath);
        if ($iconPath === '') {
            return null;
        }

        return 'https://community.akamai.steamstatic.com/economy/image/' . ltrim($iconPath, '/');
    }

    private function fetchRawUrl(string $url, array $headers = [], int $timeout = 60): string
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(12, max(3, $timeout)),
            CURLOPT_HTTPHEADER => array_merge([
                'User-Agent: CS2 Market Daily Radar',
            ], $headers),
        ]);

        $response = curl_exec($handle);
        if ($response === false) {
            throw new RuntimeException('Curl request failed: ' . curl_error($handle));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('Unexpected HTTP status %d for %s', $statusCode, $url));
        }

        return (string) $response;
    }

    private function findMarketItemById(int $itemId): ?array
    {
        foreach (($this->readMarketData()['items'] ?? []) as $item) {
            if ((int) ($item['id'] ?? 0) === $itemId) {
                return $item;
            }
        }

        return null;
    }

    private function fetchJsonWithCurl(string $url, array $headers = [], int $timeout = 60): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(12, max(3, $timeout)),
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
            CURLOPT_CONNECTTIMEOUT => min(12, max(3, $timeout)),
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

        if ($path !== $this->stateFile && !$this->buildingCanonicalState) {
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
        $previous = $this->buildingCanonicalState;
        $this->buildingCanonicalState = true;

        try {
            $state = $this->buildCanonicalState();
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            file_put_contents($this->stateFile, $json);
        } finally {
            $this->buildingCanonicalState = $previous;
        }
    }

    private function buildCanonicalState(): array
    {
        $catalog = $this->readJson($this->catalogFile, ['items' => []]);
        $market = $this->readJson($this->marketFile, ['items' => []]);
        $marketBackup = $this->readJson($this->marketBackupFile, ['items' => []]);
        $reports = $this->readJson($this->reportsFile, []);
        $latestReport = $reports[0] ?? [];
        $jobs = $this->readJson($this->jobsFile, []);
        $watchlist = array_values(array_filter(
            $this->readWatchlistEntries(),
            static fn (array $entry): bool => ($entry['is_active'] ?? true) === true
        ));
        $csfloat = $this->readJson($this->csfloatSignalsFile, ['signals' => []]);
        $positions = $this->positions();
        $positionRows = $positions['data'] ?? [];
        $positionsMeta = $positions['meta'] ?? [];
        $profiles = $this->profiles();
        $profileRows = $profiles['data'] ?? [];
        $inventory = $this->profileInventory();
        $inventoryRows = $inventory['data'] ?? [];

        return [
            'generated_at' => date(DATE_ATOM),
            'storage_path' => $this->storageDir,
            'latest_report_context' => [
                'date' => $latestReport['date'] ?? null,
                'summary_text' => $latestReport['summary_text'] ?? null,
                'ai_best_deals_title' => $latestReport['ai_best_deals_title'] ?? null,
                'ai_best_deals_text' => $latestReport['ai_best_deals_text'] ?? null,
                'ai_watchlist_actions' => array_slice($latestReport['ai_watchlist_actions'] ?? [], 0, 8),
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
                'positions_count' => $positionsMeta['total'] ?? count($positionRows),
                'positions_ready_to_sell_count' => $positionsMeta['ready_to_sell'] ?? 0,
                'profiles_count' => count($profileRows),
                'inventory_items_count' => count($inventoryRows),
            ],
            'catalog' => $catalog,
            'market' => $market,
            'market_backup' => $marketBackup,
            'reports' => $reports,
            'watchlist' => $watchlist,
            'positions' => array_slice($positionRows, 0, 40),
            'profiles' => array_slice($profileRows, 0, 20),
            'inventory' => array_slice($inventoryRows, 0, 80),
            'jobs' => $jobs,
            'csfloat' => $csfloat,
        ];
    }

    private function advicePayloadFromCanonicalState(string $message, array $report, array $matchedItems, ?float $observedPrice): array
    {
        $state = $this->readCanonicalState();
        if ($state === []) {
            $state = $this->buildCanonicalState();
        }

        $matchedSignals = [];
        foreach ($matchedItems as $item) {
            $name = (string) ($item['name'] ?? $item['market_hash_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $matchedSignals[$name] = array_slice($this->getRecentListingSignals($name), 0, 3);
        }

        return [
            'user_message' => $message,
            'observed_price_eur' => $observedPrice,
            'latest_report_context' => [
                'date' => $report['date'] ?? null,
                'summary_text' => $report['summary_text'] ?? null,
                'ai_best_deals_text' => $report['ai_best_deals_text'] ?? null,
                'top_opportunities' => array_slice($report['top_opportunities'] ?? [], 0, 5),
                'watchlist_moves' => array_slice($report['watchlist_moves'] ?? [], 0, 5),
            ],
            'matched_items' => $matchedItems,
            'matched_listing_signals' => $matchedSignals,
            'watchlist' => array_slice($state['watchlist'] ?? [], 0, 15),
            'positions' => array_slice($state['positions'] ?? [], 0, 15),
            'profiles' => array_slice($state['profiles'] ?? [], 0, 10),
            'inventory' => array_slice($state['inventory'] ?? [], 0, 20),
            'recent_jobs' => array_slice($state['jobs'] ?? [], 0, 8),
            'stats' => $state['stats'] ?? [],
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
            'ai_watchlist_actions' => array_slice($report['ai_watchlist_actions'] ?? [], 0, 8),
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
            'positions' => array_slice($state['positions'] ?? [], 0, 20),
            'profiles' => array_slice($state['profiles'] ?? [], 0, 10),
            'inventory' => array_slice($state['inventory'] ?? [], 0, 40),
            'recent_jobs' => array_slice($state['jobs'] ?? [], 0, 10),
            'csfloat_signals_sample' => array_slice($state['csfloat']['signals'] ?? [], 0, 20),
        ];
    }

    private function normalizeReport(array $report): array
    {
        $report['ai_best_deals_title'] ??= null;
        $report['ai_best_deals_text'] ??= null;
        $report['ai_best_deals_cards'] ??= [];
        $report['ai_risk_cards'] ??= [];
        $report['ai_false_signal_cards'] ??= [];
        $report['ai_stable_watch_cards'] ??= [];
        $report['ai_watchlist_actions'] ??= [];
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
                    'content' => 'Tu es un analyste marche CS2 prudent, oriente investisseur debutant. Tu recois le JSON du rapport du jour, tu peux utiliser la recherche web OpenRouter pour verifier le contexte public recent, puis tu rediges une synthese concise en francais. N invente aucune donnee. Reponds en JSON strict avec les cles title, text, best_deals, risks, false_signals, stable_watch, watchlist_actions, source_urls. Chaque entree best_deals, risks, false_signals et stable_watch doit contenir name, verdict et rationale. Chaque entree watchlist_actions doit contenir action, name, rationale et optionnellement note. Les actions autorisees sont add, remove ou keep. Mets en avant les meilleures affaires, les risques de liquidite ou de momentum, les faux signaux possibles, les items stables a garder sous surveillance et les ajustements pertinents de watchlist.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'Lis ce JSON canonique stocke par l application. Base ton analyse sur latest_report_context, les echantillons marche, la watchlist et les signaux CSFloat. Utilise la recherche web seulement pour confirmer le contexte public recent ou signaler un risque de liquidite. Rends une lecture investisseur avec 5 angles: meilleures affaires, risques, faux signaux, items stables a surveiller et evolution utile de la watchlist. Pour watchlist_actions, ne propose add que pour des items presents dans le contexte du jour et remove seulement quand un item ne justifie plus une surveillance active. Si tu n as pas assez d elements, renvoie simplement une liste vide.',
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
            $this->openRouterTimeoutSeconds()
        );

        $content = $this->extractAssistantContent($response);
        $decoded = $this->decodeJsonObject($content);
        if ($decoded === null) {
            return [
                'title' => 'Meilleures affaires du jour',
                'text' => trim($content),
                'cards' => [],
                'risks' => [],
                'false_signals' => [],
                'stable_watch' => [],
                'watchlist_actions' => [],
                'sources' => $this->extractLinksFromText($content),
                'model' => (string) ($response['model'] ?? $this->openRouterModel()),
                'generated_at' => date(DATE_ATOM),
            ];
        }

        return [
            'title' => (string) ($decoded['title'] ?? 'Meilleures affaires du jour'),
            'text' => trim((string) ($decoded['text'] ?? '')),
            'cards' => $this->normalizeAiCards($decoded['best_deals'] ?? []),
            'risks' => $this->normalizeAiCards($decoded['risks'] ?? []),
            'false_signals' => $this->normalizeAiCards($decoded['false_signals'] ?? []),
            'stable_watch' => $this->normalizeAiCards($decoded['stable_watch'] ?? []),
            'watchlist_actions' => $this->normalizeAiWatchlistActions($decoded['watchlist_actions'] ?? []),
            'sources' => (($sources = $this->normalizeAiSources($decoded['source_urls'] ?? [])) !== [] ? $sources : $this->extractLinksFromText($content)),
            'model' => (string) ($response['model'] ?? $this->openRouterModel()),
            'generated_at' => date(DATE_ATOM),
        ];
    }

    private function generateSkinAdvice(string $message, array $report, array $matchedItems, ?float $observedPrice): array
    {
        $payload = [
            'model' => $this->openRouterModel(),
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Tu es un analyste CS2 orienté achat. Tu reçois la question utilisateur, un extrait des JSON persistés de l application et des items locaux matchés. Tu peux utiliser la recherche web OpenRouter pour confirmer le contexte public récent. Réponds en JSON strict avec les clés title, verdict, confidence, summary, action, positives, risks, matched_item_name, source_urls. verdict doit valoir good_deal, fair_price, avoid ou uncertain. positives et risks sont des tableaux de phrases courtes. N invente aucune donnée.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'Dis si le skin mentionné semble être une bonne affaire maintenant. Utilise les données locales en priorité, puis la recherche web seulement pour confirmer le contexte de liquidité ou de momentum. Si le message contient un prix observé, compare-le au prix marché local. Réponse claire, prudente et exploitable pour décider achat / attente / éviter.',
                        'context' => $this->advicePayloadFromCanonicalState($message, $report, $matchedItems, $observedPrice),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ],
            'tools' => [
                [
                    'type' => 'openrouter:web_search',
                    'parameters' => [
                        'max_results' => 4,
                        'max_total_results' => 8,
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
                'X-Title: CS2 Market Daily Radar Skin Advisor',
            ],
            $this->openRouterTimeoutSeconds()
        );

        $content = $this->extractAssistantContent($response);
        $decoded = $this->decodeJsonObject($content);
        if ($decoded === null) {
            return $this->fallbackSkinAdvice($message, $matchedItems, $observedPrice, 'Réponse IA non structurée');
        }

        return $this->normalizeSkinAdviceResponse(
            $decoded,
            $matchedItems,
            $observedPrice,
            (($sources = $this->normalizeAiSources($decoded['source_urls'] ?? [])) !== [] ? $sources : $this->extractLinksFromText($content)),
            (string) ($response['model'] ?? $this->openRouterModel())
        );
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

    private function normalizeAiWatchlistActions(mixed $actions): array
    {
        if (!is_array($actions)) {
            return [];
        }

        $normalized = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = strtolower(trim((string) ($action['action'] ?? '')));
            $name = trim((string) ($action['name'] ?? ''));
            $rationale = trim((string) ($action['rationale'] ?? ''));
            $note = trim((string) ($action['note'] ?? $rationale));
            if ($name === '' || !in_array($type, ['add', 'remove', 'keep'], true)) {
                continue;
            }

            $normalized[] = [
                'action' => $type,
                'name' => $name,
                'rationale' => $rationale,
                'note' => $note !== '' ? $note : 'ajustement IA',
            ];
        }

        return array_slice($normalized, 0, 6);
    }

    private function applyAiWatchlistActions(array $actions): array
    {
        if ($actions === []) {
            return [];
        }

        $catalogIndex = [];
        foreach (($this->readJson($this->catalogFile, ['items' => []])['items'] ?? []) as $item) {
            $name = trim((string) ($item['market_hash_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $catalogIndex[$name] = $item;
        }

        $entries = [];
        foreach ($this->readWatchlistEntries() as $entry) {
            $entries[$entry['market_hash_name']] = $entry;
        }

        $applied = [];
        $now = date(DATE_ATOM);
        $addCount = 0;
        $removeCount = 0;

        foreach ($actions as $action) {
            $type = $action['action'] ?? null;
            $name = trim((string) ($action['name'] ?? ''));
            $reason = trim((string) ($action['rationale'] ?? ''));
            $note = trim((string) ($action['note'] ?? ''));
            if (!is_string($type) || $name === '') {
                continue;
            }

            if ($type === 'add') {
                if ($addCount >= 3 || !isset($catalogIndex[$name])) {
                    continue;
                }

                if (isset($entries[$name])) {
                    $entries[$name]['is_active'] = true;
                    $entries[$name]['note'] = $note !== '' ? $note : ($entries[$name]['note'] ?? 'surveillance radar');
                    $entries[$name]['updated_at'] = $now;
                    $entries[$name]['last_ai_action'] = 'kept';
                    $entries[$name]['last_ai_reason'] = $reason !== '' ? $reason : 'item conserve par l analyse IA';
                    $applied[] = [
                        'action' => 'keep',
                        'name' => $name,
                        'rationale' => $entries[$name]['last_ai_reason'],
                        'note' => $entries[$name]['note'],
                    ];
                    continue;
                }

                $entries[$name] = [
                    'market_hash_name' => $name,
                    'note' => $note !== '' ? $note : 'ajout IA sur signal du jour',
                    'managed_by' => 'ai',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_ai_action' => 'added',
                    'last_ai_reason' => $reason !== '' ? $reason : 'item ajoute par l analyse IA',
                ];
                $applied[] = [
                    'action' => 'add',
                    'name' => $name,
                    'rationale' => $entries[$name]['last_ai_reason'],
                    'note' => $entries[$name]['note'],
                ];
                $addCount++;
                continue;
            }

            if ($type === 'remove') {
                if ($removeCount >= 3 || !isset($entries[$name])) {
                    continue;
                }

                $managedBy = (string) ($entries[$name]['managed_by'] ?? 'system');
                if ($managedBy === 'manual') {
                    continue;
                }

                $entries[$name]['is_active'] = false;
                $entries[$name]['updated_at'] = $now;
                $entries[$name]['last_ai_action'] = 'removed';
                $entries[$name]['last_ai_reason'] = $reason !== '' ? $reason : 'item retire par l analyse IA';
                $applied[] = [
                    'action' => 'remove',
                    'name' => $name,
                    'rationale' => $entries[$name]['last_ai_reason'],
                    'note' => $entries[$name]['note'] ?? 'surveillance radar',
                ];
                $removeCount++;
                continue;
            }

            if ($type === 'keep' && isset($entries[$name])) {
                $entries[$name]['is_active'] = true;
                if ($note !== '') {
                    $entries[$name]['note'] = $note;
                }
                $entries[$name]['updated_at'] = $now;
                $entries[$name]['last_ai_action'] = 'kept';
                $entries[$name]['last_ai_reason'] = $reason !== '' ? $reason : 'item conserve par l analyse IA';
                $applied[] = [
                    'action' => 'keep',
                    'name' => $name,
                    'rationale' => $entries[$name]['last_ai_reason'],
                    'note' => $entries[$name]['note'],
                ];
            }
        }

        if ($applied !== []) {
            $this->writeWatchlistEntries(array_values($entries));
        }

        return $applied;
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

    private function normalizeSkinAdviceResponse(array $decoded, array $matchedItems, ?float $observedPrice, array $sources, string $model): array
    {
        $matchedItemName = trim((string) ($decoded['matched_item_name'] ?? ''));
        $matchedItem = null;
        foreach ($matchedItems as $item) {
            $name = (string) ($item['name'] ?? $item['market_hash_name'] ?? '');
            if ($matchedItemName !== '' && strcasecmp($name, $matchedItemName) === 0) {
                $matchedItem = $item;
                break;
            }
        }
        $matchedItem ??= $matchedItems[0] ?? null;

        $positives = array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            is_array($decoded['positives'] ?? null) ? $decoded['positives'] : []
        )));
        $risks = array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            is_array($decoded['risks'] ?? null) ? $decoded['risks'] : []
        )));

        return [
            'title' => trim((string) ($decoded['title'] ?? 'Avis IA sur ce skin')) ?: 'Avis IA sur ce skin',
            'verdict' => $this->normalizeAdviceVerdict((string) ($decoded['verdict'] ?? 'uncertain')),
            'confidence' => max(0, min(100, (int) ($decoded['confidence'] ?? 55))),
            'summary' => trim((string) ($decoded['summary'] ?? '')) ?: 'Pas assez de données pour trancher proprement.',
            'action' => trim((string) ($decoded['action'] ?? 'Surveille encore un peu le marché avant décision.')),
            'positives' => array_slice($positives, 0, 4),
            'risks' => array_slice($risks, 0, 4),
            'matched_item' => $matchedItem,
            'observed_price_eur' => $observedPrice,
            'sources' => $sources,
            'model' => $model,
            'generated_at' => date(DATE_ATOM),
        ];
    }

    private function fallbackSkinAdvice(string $message, array $matchedItems, ?float $observedPrice, ?string $error = null): array
    {
        $error = $error !== null ? $this->normalizeAiErrorMessage($error) : null;
        $matchedItem = $matchedItems[0] ?? null;
        if ($matchedItem === null) {
            return [
                'title' => 'Avis IA indisponible',
                'verdict' => 'uncertain',
                'confidence' => 20,
                'summary' => 'Je n ai pas trouve de skin local qui corresponde clairement a ta demande.',
                'action' => 'Donne le nom exact du skin ou colle aussi son prix observe.',
                'positives' => [],
                'risks' => [$error ?? 'Aucun match local clair dans les données.'],
                'matched_item' => null,
                'observed_price_eur' => $observedPrice,
                'sources' => [],
                'model' => null,
                'generated_at' => date(DATE_ATOM),
            ];
        }

        $marketPrice = $this->floatOrNull($matchedItem['current_price'] ?? null);
        $change7d = $this->floatOrNull($matchedItem['change_vs_7d_pct'] ?? $matchedItem['change_7d'] ?? null);
        $change24h = $this->floatOrNull($matchedItem['change_vs_yesterday_pct'] ?? $matchedItem['change_24h'] ?? null);
        $volumeRatio = $this->floatOrNull($matchedItem['volume_ratio_24h_7d'] ?? null);
        $score = (int) ($matchedItem['interest_score'] ?? $matchedItem['score'] ?? 0);

        $verdict = 'fair_price';
        $summary = 'Le prix paraît globalement en ligne avec le marché local.';
        $positives = [];
        $risks = [];
        $action = 'Compare encore avec les volumes et évite de chase un mouvement trop violent.';

        if ($observedPrice !== null && $marketPrice !== null && $observedPrice < $marketPrice * 0.95) {
            $verdict = 'good_deal';
            $summary = 'Le prix observé est en dessous du marché local actuel, ce qui ressemble à une vraie opportunité.';
            $positives[] = sprintf('Prix observé %.2f EUR sous le marché local %.2f EUR.', $observedPrice, $marketPrice);
            $action = 'Vérifie l état exact du skin et exécute vite si le listing est propre.';
        } elseif ($observedPrice !== null && $marketPrice !== null && $observedPrice > $marketPrice * 1.05) {
            $verdict = 'avoid';
            $summary = 'Le prix observé est au-dessus du marché local actuel, ce qui réduit fortement l intérêt de l achat.';
            $risks[] = sprintf('Prix observé %.2f EUR au-dessus du marché local %.2f EUR.', $observedPrice, $marketPrice);
            $action = 'Passe ton tour ou négocie un meilleur prix.';
        }

        if ($change7d !== null && $change7d <= -8.0) {
            $positives[] = sprintf('Le skin reste %.1f%% sous sa moyenne récente 7 jours.', $change7d);
        }

        if ($change24h !== null && abs($change24h) >= 12.0) {
            $risks[] = sprintf('Le momentum 24h est très marqué (%.1f%%), donc le signal peut être instable.', $change24h);
        }

        if ($volumeRatio !== null && $volumeRatio >= 1.5) {
            $positives[] = sprintf('Le volume 24h accélère x%.1f par rapport au rythme 7 jours.', $volumeRatio);
        } else {
            $risks[] = 'La liquidité n accélère pas franchement, donc la sortie peut être moins fluide.';
        }

        if ($score < self::OPPORTUNITY_THRESHOLD) {
            $risks[] = sprintf('Le score radar reste modéré (%d/100).', $score);
        }

        return [
            'title' => 'Avis IA local sur ce skin',
            'verdict' => $verdict,
            'confidence' => $verdict === 'good_deal' ? 72 : ($verdict === 'avoid' ? 76 : 58),
            'summary' => $summary,
            'action' => $action,
            'positives' => array_slice(array_values(array_unique($positives)), 0, 4),
            'risks' => array_slice(array_values(array_unique(array_filter(array_merge($risks, $error ? [$error] : [])))), 0, 4),
            'matched_item' => $matchedItem,
            'observed_price_eur' => $observedPrice,
            'sources' => [],
            'model' => $this->openRouterIsConfigured() ? $this->openRouterModel() : null,
            'generated_at' => date(DATE_ATOM),
        ];
    }

    private function findAdviceItems(string $message, int $limit = 5): array
    {
        $market = $this->readMarketData()['items'] ?? [];
        if ($market === []) {
            return [];
        }

        $lower = static fn (string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $length = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        $query = $lower($message);
        $tokens = preg_split('/[^[:alnum:]\p{L}\p{N}\-\|]+/u', $query) ?: [];
        $tokens = array_values(array_filter(array_unique($tokens), static fn (string $token): bool => $length($token) >= 3));
        $scored = [];

        foreach ($market as $item) {
            $name = $lower((string) ($item['market_hash_name'] ?? $item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $score = 0;
            if (str_contains($query, $name) || str_contains($name, $query)) {
                $score += 120;
            }

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 18;
                }
            }

            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'score' => $score,
                'item' => $this->mapAdviceItem($item),
            ];
        }

        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice(array_values(array_map(static fn (array $entry): array => $entry['item'], $scored)), 0, $limit);
    }

    private function mapAdviceItem(array $item): array
    {
        return [
            'id' => $item['id'] ?? $this->idFromName((string) ($item['market_hash_name'] ?? $item['name'] ?? '')),
            'name' => $item['name'] ?? $item['market_hash_name'] ?? null,
            'market_hash_name' => $item['market_hash_name'] ?? $item['name'] ?? null,
            'weapon' => $item['weapon'] ?? null,
            'rarity' => $item['rarity'] ?? null,
            'current_price' => $item['current_price'] ?? null,
            'change_vs_yesterday_pct' => $item['change_vs_yesterday_pct'] ?? null,
            'change_vs_7d_pct' => $item['change_vs_7d_pct'] ?? null,
            'sales_24h_volume' => $item['sales_24h_volume'] ?? null,
            'volume_ratio_24h_7d' => $item['volume_ratio_24h_7d'] ?? null,
            'interest_score' => $item['interest_score'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'item_page' => $item['item_page'] ?? null,
            'market_page' => $item['market_page'] ?? null,
            'tags' => $item['tags'] ?? [],
        ];
    }

    private function extractObservedPriceFromMessage(string $message): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur|euro)/iu', $message, $matches) !== 1) {
            return null;
        }

        return $this->floatOrNull(str_replace(',', '.', $matches[1]));
    }

    private function normalizeAdviceVerdict(string $verdict): string
    {
        $value = strtolower(trim($verdict));
        return in_array($value, ['good_deal', 'fair_price', 'avoid', 'uncertain'], true) ? $value : 'uncertain';
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

        foreach ($this->readWatchlistEntries() as $entry) {
            if (($entry['is_active'] ?? true) !== true) {
                continue;
            }

            $name = (string) ($entry['market_hash_name'] ?? '');
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

    private function openRouterTimeoutSeconds(): int
    {
        $configured = $this->env('OPENROUTER_TIMEOUT_SECONDS');
        if ($configured !== null && is_numeric($configured)) {
            return max(10, min(60, (int) $configured));
        }

        return 35;
    }

    private function openRouterTestTimeoutSeconds(): int
    {
        $configured = $this->env('OPENROUTER_TEST_TIMEOUT_SECONDS');
        if ($configured !== null && is_numeric($configured)) {
            return max(5, min(30, (int) $configured));
        }

        return 15;
    }

    private function normalizeAiErrorMessage(string $message): string
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return 'Analyse IA temporairement indisponible.';
        }

        $lower = strtolower($normalized);
        if (str_contains($lower, 'operation timed out') || str_contains($lower, 'timed out')) {
            return 'Analyse IA web indisponible pour le moment: OpenRouter n a pas repondu a temps. Analyse locale affichee.';
        }

        if (str_contains($lower, 'could not resolve host') || str_contains($lower, 'failed to connect')) {
            return 'Analyse IA web indisponible pour le moment: connexion reseau impossible vers OpenRouter. Analyse locale affichee.';
        }

        if (str_contains($lower, 'http 429') || str_contains($lower, 'rate limit')) {
            return 'Analyse IA web indisponible pour le moment: limite OpenRouter atteinte. Reessaie dans un instant.';
        }

        return $normalized;
    }

    private function discordWebhookIsConfigured(): bool
    {
        return $this->env('DISCORD_WEBHOOK_URL') !== null;
    }

    private function discordWebhookUrl(): string
    {
        $url = $this->env('DISCORD_WEBHOOK_URL');
        if ($url === null) {
            throw new RuntimeException('DISCORD_WEBHOOK_URL absente.');
        }

        return $url;
    }

    private function buildDiscordSummaryEmbed(array $report, array $overview, array $watchlist): array
    {
        $topGainers = array_slice($report['top_gainers'] ?? [], 0, 3);
        $topLosers = array_slice($report['top_losers'] ?? [], 0, 3);
        $watchlistMoves = array_slice($report['watchlist_moves'] ?? [], 0, 4);

        return [
            'title' => sprintf('CS2 Market Daily Radar • %s', (string) ($report['date'] ?? date('Y-m-d'))),
            'description' => $this->truncateForDiscord((string) ($report['ai_best_deals_text'] ?? $report['summary_text'] ?? 'Rapport du jour genere.')),
            'color' => 0xB54B2E,
            'fields' => [
                $this->discordField('Items suivis', (string) ($overview['items_tracked'] ?? 0), true),
                $this->discordField('Dans la tranche', (string) ($report['items_in_range'] ?? 0), true),
                $this->discordField('Opportunités', (string) ($report['opportunities_count'] ?? 0), true),
                $this->discordField('Watchlist active', (string) count($watchlist), true),
                $this->discordField('Courbe 7j', $this->discordSparkline($overview['opportunity_series'] ?? []), false),
                $this->discordField('Top hausses', $this->discordLeaderboard($topGainers, 'change_24h'), false),
                $this->discordField('Top baisses', $this->discordLeaderboard($topLosers, 'change_24h'), false),
                $this->discordField('Watchlist en mouvement', $this->discordWatchlistSummary($watchlistMoves), false),
            ],
            'footer' => [
                'text' => 'ByMykel • Skinport • CSFloat • OpenRouter',
            ],
            'timestamp' => date(DATE_ATOM),
        ];
    }

    private function buildDiscordItemEmbed(array $item, string $section, string $subtitle, int $color): array
    {
        $name = (string) ($item['name'] ?? $item['market_hash_name'] ?? 'CS2 item');
        $reason = trim($subtitle) !== '' ? trim($subtitle) : ($item['reason'] ?? 'Signal du jour');
        $fields = [
            $this->discordField('Prix', $this->formatDiscordPrice($item['price'] ?? $item['current_price'] ?? null), true),
            $this->discordField('24h', $this->formatDiscordPercent($item['change_24h'] ?? $item['change_vs_yesterday_pct'] ?? null), true),
            $this->discordField('7j', $this->formatDiscordPercent($item['change_7d'] ?? $item['change_vs_7d_pct'] ?? null), true),
            $this->discordField('Volume 24h', (string) ($item['volume_24h'] ?? $item['sales_24h_volume'] ?? 0), true),
            $this->discordField('Score', isset($item['score']) || isset($item['interest_score']) ? (string) ($item['score'] ?? $item['interest_score']) . '/100' : '-', true),
            $this->discordField('Tag', (string) ($section !== '' ? $section : 'Radar'), true),
        ];

        if (isset($item['note']) && trim((string) $item['note']) !== '') {
            $fields[] = $this->discordField('Note', $this->truncateForDiscord((string) $item['note'], 160), false);
        }

        $embed = [
            'title' => $this->truncateForDiscord($name, 250),
            'url' => $this->itemUrl($item),
            'description' => $this->truncateForDiscord($reason, 350),
            'color' => $color,
            'thumbnail' => isset($item['image_url']) && is_string($item['image_url']) && $item['image_url'] !== ''
                ? ['url' => $item['image_url']]
                : null,
            'fields' => array_values(array_filter($fields)),
            'footer' => [
                'text' => $section,
            ],
        ];

        return array_filter($embed, static fn (mixed $value): bool => $value !== null);
    }

    private function discordField(string $name, string $value, bool $inline): array
    {
        $cleanValue = trim($value) !== '' ? trim($value) : '-';

        return [
            'name' => $name,
            'value' => $this->truncateForDiscord($cleanValue, 1000),
            'inline' => $inline,
        ];
    }

    private function discordSparkline(array $series): string
    {
        if ($series === []) {
            return 'Pas assez d historique.';
        }

        $levels = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $values = array_map(static fn (array $point): int => (int) ($point['value'] ?? 0), $series);
        $max = max(1, ...$values);
        $parts = [];

        foreach ($series as $index => $point) {
            $value = $values[$index];
            $levelIndex = (int) round(($value / $max) * (count($levels) - 1));
            $parts[] = sprintf('%s %s (%d)', (string) ($point['day'] ?? ''), $levels[$levelIndex], $value);
        }

        return implode("\n", $parts);
    }

    private function discordLeaderboard(array $rows, string $metric): string
    {
        if ($rows === []) {
            return 'Aucun signal.';
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '• %s — %s',
                (string) ($row['name'] ?? 'item'),
                $metric === 'change_24h'
                    ? $this->formatDiscordPercent($row[$metric] ?? null)
                    : (string) ($row[$metric] ?? '-')
            );
        }

        return $this->truncateForDiscord(implode("\n", $lines), 1000);
    }

    private function discordWatchlistSummary(array $rows): string
    {
        if ($rows === []) {
            return 'Aucun mouvement notable.';
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = sprintf('• %s — %s', (string) ($row['name'] ?? 'item'), (string) ($row['status'] ?? 'surveillance'));
        }

        return $this->truncateForDiscord(implode("\n", $lines), 1000);
    }

    private function formatDiscordPrice(mixed $value): string
    {
        $price = $this->floatOrNull($value);
        return $price === null ? '-' : number_format($price, 2, ',', ' ') . ' EUR';
    }

    private function formatDiscordPercent(mixed $value): string
    {
        $percent = $this->floatOrNull($value);
        if ($percent === null) {
            return '-';
        }

        return sprintf('%s%s%%', $percent > 0 ? '+' : '', str_replace('.', ',', number_format($percent, 1, '.', '')));
    }

    private function truncateForDiscord(string $text, int $limit = 400): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[^\S\n]+/', ' ', $line)),
            explode("\n", $normalized)
        );
        $trimmed = trim(implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== ''))));
        if ($trimmed === '') {
            return '-';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
        if ($length <= $limit) {
            return $trimmed;
        }

        $slice = function_exists('mb_substr')
            ? mb_substr($trimmed, 0, max(1, $limit - 1))
            : substr($trimmed, 0, max(1, $limit - 1));

        return rtrim((string) $slice) . '…';
    }

    private function itemUrl(array $item): ?string
    {
        $url = $item['market_page'] ?? $item['item_page'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
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
