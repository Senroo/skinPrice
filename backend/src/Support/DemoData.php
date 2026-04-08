<?php

declare(strict_types=1);

namespace App\Support;

final class DemoData
{
    public static function overview(): array
    {
        return [
            'date' => '2026-04-08',
            'items_tracked' => 1820,
            'items_in_range' => 614,
            'opportunities_count' => 27,
            'watchlist_moving_count' => 4,
            'top_signals' => [
                [
                    'item_id' => 42,
                    'weapon' => 'AK-47',
                    'name' => 'AK-47 | Redline (Field-Tested)',
                    'current_price' => 17.40,
                    'change_24h' => 4.8,
                    'volume_ratio' => 1.9,
                    'score' => 78,
                    'tags' => ['volume x1.9', 'watchlist'],
                ],
                [
                    'item_id' => 91,
                    'weapon' => 'AWP',
                    'name' => 'AWP | Asiimov (Battle-Scarred)',
                    'current_price' => 71.20,
                    'change_24h' => 3.6,
                    'volume_ratio' => 1.4,
                    'score' => 74,
                    'tags' => ['drop recupere', 'liquide'],
                ],
                [
                    'item_id' => 115,
                    'weapon' => 'M4A1-S',
                    'name' => 'M4A1-S | Printstream (Field-Tested)',
                    'current_price' => 146.50,
                    'change_24h' => -2.9,
                    'volume_ratio' => 1.1,
                    'score' => 72,
                    'tags' => ['sous moyenne 7j', 'volume stable'],
                ],
            ],
            'opportunity_series' => [
                ['day' => 'Mer', 'value' => 12],
                ['day' => 'Jeu', 'value' => 15],
                ['day' => 'Ven', 'value' => 19],
                ['day' => 'Sam', 'value' => 24],
                ['day' => 'Dim', 'value' => 21],
                ['day' => 'Lun', 'value' => 18],
                ['day' => 'Mar', 'value' => 27],
            ],
            'job_status' => [
                'last_sync_at' => '2026-04-08T06:35:00+02:00',
                'health' => 'warning',
            ],
        ];
    }

    public static function items(): array
    {
        return [
            [
                'id' => 42,
                'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
                'name' => 'AK-47 | Redline (Field-Tested)',
                'weapon' => 'AK-47',
                'rarity' => 'Classified',
                'category' => 'Rifle',
                'stattrak' => false,
                'souvenir' => false,
                'image_url' => null,
                'snapshot' => [
                    'date' => '2026-04-08',
                    'current_price' => 17.40,
                    'change_vs_yesterday_pct' => 4.8,
                    'change_vs_7d_pct' => -3.1,
                    'sales_24h_volume' => 48,
                    'volume_ratio_24h_7d' => 1.9,
                    'interest_score' => 78,
                    'tags' => ['volume_anomaly', 'watchlist'],
                ],
            ],
            [
                'id' => 91,
                'market_hash_name' => 'AWP | Asiimov (Battle-Scarred)',
                'name' => 'AWP | Asiimov (Battle-Scarred)',
                'weapon' => 'AWP',
                'rarity' => 'Covert',
                'category' => 'Sniper Rifle',
                'stattrak' => false,
                'souvenir' => false,
                'image_url' => null,
                'snapshot' => [
                    'date' => '2026-04-08',
                    'current_price' => 71.20,
                    'change_vs_yesterday_pct' => 3.6,
                    'change_vs_7d_pct' => -2.2,
                    'sales_24h_volume' => 22,
                    'volume_ratio_24h_7d' => 1.4,
                    'interest_score' => 74,
                    'tags' => ['drop', 'stable'],
                ],
            ],
            [
                'id' => 115,
                'market_hash_name' => 'M4A1-S | Printstream (Field-Tested)',
                'name' => 'M4A1-S | Printstream (Field-Tested)',
                'weapon' => 'M4A1-S',
                'rarity' => 'Covert',
                'category' => 'Rifle',
                'stattrak' => false,
                'souvenir' => false,
                'image_url' => null,
                'snapshot' => [
                    'date' => '2026-04-08',
                    'current_price' => 146.50,
                    'change_vs_yesterday_pct' => -2.9,
                    'change_vs_7d_pct' => -4.6,
                    'sales_24h_volume' => 18,
                    'volume_ratio_24h_7d' => 1.1,
                    'interest_score' => 72,
                    'tags' => ['drop'],
                ],
            ],
        ];
    }

    public static function item(int $id): array
    {
        return [
            'id' => $id,
            'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
            'name' => 'AK-47 | Redline (Field-Tested)',
            'weapon' => 'AK-47',
            'rarity' => 'Classified',
            'category' => 'Rifle',
            'stattrak' => false,
            'souvenir' => false,
            'image_url' => null,
            'latest_snapshot' => [
                'snapshot_date' => '2026-04-08',
                'current_price' => 17.40,
                'sales_24h_avg' => 17.05,
                'sales_7d_avg' => 17.95,
                'sales_30d_avg' => 18.60,
                'sales_24h_volume' => 48,
                'sales_7d_volume' => 177,
                'sales_30d_volume' => 641,
                'change_vs_yesterday_pct' => 4.8,
                'change_vs_7d_pct' => -3.1,
                'change_vs_30d_pct' => -6.5,
                'volume_ratio_24h_7d' => 1.9,
                'interest_score' => 78,
                'tags' => ['volume_anomaly', 'watchlist'],
            ],
            'history' => [
                ['snapshot_date' => '2026-04-04', 'current_price' => 18.10, 'volume' => 23],
                ['snapshot_date' => '2026-04-05', 'current_price' => 17.85, 'volume' => 25],
                ['snapshot_date' => '2026-04-06', 'current_price' => 17.60, 'volume' => 31],
                ['snapshot_date' => '2026-04-07', 'current_price' => 16.60, 'volume' => 42],
                ['snapshot_date' => '2026-04-08', 'current_price' => 17.40, 'volume' => 48],
            ],
            'recent_listing_signals' => [
                [
                    'observed_at' => '2026-04-08T06:00:00+02:00',
                    'listing_price' => 16.95,
                    'float_value' => 0.19,
                    'has_stickers' => true,
                    'signal_score' => 82,
                ],
            ],
            'explanation' => 'Prix sous moyenne 7 jours avec acceleration du volume et liquidite correcte.',
        ];
    }

    public static function reportToday(): array
    {
        return [
            'date' => '2026-04-08',
            'items_scanned' => 1820,
            'items_in_range' => 614,
            'opportunities_count' => 27,
            'summary_text' => 'Le marche reste selectif, mais plusieurs skins liquides montrent un reveil propre avec du volume au-dessus de leur moyenne recente.',
            'top_opportunities' => [
                [
                    'name' => 'AK-47 | Redline (Field-Tested)',
                    'weapon' => 'AK-47',
                    'price' => 17.40,
                    'change_24h' => 4.8,
                    'change_7d' => -3.1,
                    'volume_24h' => 48,
                    'score' => 78,
                    'reason' => 'volume x2 vs moyenne 7j',
                ],
                [
                    'name' => 'USP-S | Cortex (Minimal Wear)',
                    'weapon' => 'USP-S',
                    'price' => 11.95,
                    'change_24h' => 6.2,
                    'change_7d' => -1.4,
                    'volume_24h' => 64,
                    'score' => 76,
                    'reason' => 'watchlist + acceleration',
                ],
                [
                    'name' => 'Desert Eagle | Printstream (Field-Tested)',
                    'weapon' => 'Desert Eagle',
                    'price' => 38.10,
                    'change_24h' => 2.7,
                    'change_7d' => -4.6,
                    'volume_24h' => 29,
                    'score' => 73,
                    'reason' => 'prix sous moyenne 7j',
                ],
                [
                    'name' => 'AWP | Asiimov (Battle-Scarred)',
                    'weapon' => 'AWP',
                    'price' => 71.20,
                    'change_24h' => 3.6,
                    'change_7d' => -2.2,
                    'volume_24h' => 22,
                    'score' => 71,
                    'reason' => 'retour de liquidite',
                ],
            ],
            'top_gainers' => [
                ['name' => 'USP-S | Cortex (Minimal Wear)', 'change_24h' => 6.2, 'price' => 11.95],
                ['name' => 'AK-47 | Redline (Field-Tested)', 'change_24h' => 4.8, 'price' => 17.40],
                ['name' => 'AWP | Asiimov (Battle-Scarred)', 'change_24h' => 3.6, 'price' => 71.20],
            ],
            'top_losers' => [
                ['name' => 'M4A1-S | Printstream (Field-Tested)', 'change_24h' => -2.9, 'price' => 146.50],
                ['name' => 'Glock-18 | Vogue (Field-Tested)', 'change_24h' => -2.4, 'price' => 6.70],
                ['name' => 'FAMAS | Commemoration (MW)', 'change_24h' => -2.1, 'price' => 14.20],
            ],
            'top_volume' => [
                ['name' => 'AK-47 | Redline (Field-Tested)', 'volume_ratio' => 1.9, 'volume_24h' => 48],
                ['name' => 'USP-S | Cortex (Minimal Wear)', 'volume_ratio' => 2.2, 'volume_24h' => 64],
                ['name' => 'M4A4 | Neo-Noir (FT)', 'volume_ratio' => 1.8, 'volume_24h' => 34],
            ],
            'watchlist_moves' => [
                ['name' => 'AK-47 | Redline (FT)', 'status' => 'approche du seuil bas'],
                ['name' => 'USP-S | Cortex (MW)', 'status' => 'casse une resistance courte'],
            ],
        ];
    }

    public static function reportHistory(): array
    {
        return [
            ['date' => '2026-04-08', 'opportunities_count' => 27, 'note' => 'Rapport genere a 06:52'],
            ['date' => '2026-04-07', 'opportunities_count' => 22, 'note' => 'Volume en baisse'],
            ['date' => '2026-04-06', 'opportunities_count' => 31, 'note' => 'Pic sur rifles'],
            ['date' => '2026-04-05', 'opportunities_count' => 19, 'note' => 'Marche plus calme'],
        ];
    }

    public static function watchlist(): array
    {
        return [
            ['name' => 'AK-47 | Redline (FT)', 'price' => 17.40, 'note' => 'Alerte sous 17.00 EUR'],
            ['name' => 'USP-S | Cortex (MW)', 'price' => 11.95, 'note' => 'Alerte au-dessus de 12.20 EUR'],
            ['name' => 'AWP | Asiimov (BS)', 'price' => 71.20, 'note' => 'Surveillance liquidite'],
            ['name' => 'Desert Eagle | Printstream (FT)', 'price' => 38.10, 'note' => 'Signal moyen 7j'],
        ];
    }

    public static function health(): array
    {
        return [
            'status' => 'warning',
            'last_sync_at' => '2026-04-08T06:35:00+02:00',
            'sources' => [
                ['name' => 'ByMykel', 'status' => 'ok', 'note' => 'catalogue + images a jour'],
                ['name' => 'Skinport', 'status' => 'ok', 'note' => 'prix et historique recuperes'],
                ['name' => 'CSFloat', 'status' => 'degraded', 'note' => 'timeouts sur une partie des listings'],
            ],
            'jobs' => [
                'sync-catalog' => 'success',
                'sync-market' => 'success',
                'sync-history' => 'success',
                'generate-report' => 'success',
            ],
        ];
    }

    public static function jobs(): array
    {
        return [
            ['job_name' => 'sync-market', 'status' => 'success', 'started_at' => '2026-04-08T06:31:00+02:00', 'ended_at' => '2026-04-08T06:35:00+02:00', 'items_processed' => 614, 'error_count' => 0, 'log_excerpt' => '614 items dans la tranche 5-800 EUR'],
            ['job_name' => 'sync-history', 'status' => 'success', 'started_at' => '2026-04-08T06:38:00+02:00', 'ended_at' => '2026-04-08T06:42:00+02:00', 'items_processed' => 614, 'error_count' => 0, 'log_excerpt' => 'agregats 24h/7j/30j enrichis'],
            ['job_name' => 'enrich-listings', 'status' => 'partial', 'started_at' => '2026-04-08T06:44:00+02:00', 'ended_at' => '2026-04-08T06:48:00+02:00', 'items_processed' => 73, 'error_count' => 9, 'log_excerpt' => 'CSFloat timeouts sur quelques items'],
            ['job_name' => 'generate-report', 'status' => 'success', 'started_at' => '2026-04-08T06:50:00+02:00', 'ended_at' => '2026-04-08T06:52:00+02:00', 'items_processed' => 27, 'error_count' => 0, 'log_excerpt' => 'rapport quotidien publie'],
        ];
    }
}
