CREATE TABLE items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_hash_name VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    weapon VARCHAR(100) NULL,
    rarity VARCHAR(100) NULL,
    category VARCHAR(100) NULL,
    exterior VARCHAR(100) NULL,
    stattrak TINYINT(1) NOT NULL DEFAULT 0,
    souvenir TINYINT(1) NOT NULL DEFAULT 0,
    image_url VARCHAR(512) NULL,
    source_catalog VARCHAR(50) NOT NULL DEFAULT 'bymykel',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_items_market_hash_name (market_hash_name),
    KEY idx_items_name (name),
    KEY idx_items_weapon (weapon),
    KEY idx_items_rarity (rarity),
    KEY idx_items_flags (stattrak, souvenir)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE market_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    snapshot_date DATE NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    current_price DECIMAL(10,2) NULL,
    min_price DECIMAL(10,2) NULL,
    max_price DECIMAL(10,2) NULL,
    mean_price DECIMAL(10,2) NULL,
    median_price DECIMAL(10,2) NULL,
    quantity INT UNSIGNED NULL,
    sales_24h_avg DECIMAL(10,2) NULL,
    sales_24h_volume INT UNSIGNED NULL,
    sales_7d_avg DECIMAL(10,2) NULL,
    sales_7d_volume INT UNSIGNED NULL,
    sales_30d_avg DECIMAL(10,2) NULL,
    sales_30d_volume INT UNSIGNED NULL,
    sales_90d_avg DECIMAL(10,2) NULL,
    sales_90d_volume INT UNSIGNED NULL,
    change_vs_yesterday_pct DECIMAL(8,2) NULL,
    change_vs_7d_pct DECIMAL(8,2) NULL,
    change_vs_30d_pct DECIMAL(8,2) NULL,
    volume_ratio_24h_7d DECIMAL(8,2) NULL,
    interest_score SMALLINT NULL,
    is_in_price_range TINYINT(1) NOT NULL DEFAULT 0,
    tags_json JSON NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'skinport',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_market_snapshots_item
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_market_snapshots_item_date_source (item_id, snapshot_date, source),
    KEY idx_market_snapshots_date (snapshot_date),
    KEY idx_market_snapshots_price_range (is_in_price_range, current_price),
    KEY idx_market_snapshots_score (interest_score),
    KEY idx_market_snapshots_item_date (item_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listing_signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    observed_at DATETIME NOT NULL,
    listing_price DECIMAL(10,2) NOT NULL,
    float_value DECIMAL(8,6) NULL,
    seller_score DECIMAL(6,2) NULL,
    has_stickers TINYINT(1) NOT NULL DEFAULT 0,
    signal_score SMALLINT NULL,
    raw_payload_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_listing_signals_item
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    KEY idx_listing_signals_observed_at (observed_at),
    KEY idx_listing_signals_item_observed_at (item_id, observed_at),
    KEY idx_listing_signals_signal_score (signal_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_date DATE NOT NULL,
    items_scanned INT UNSIGNED NOT NULL DEFAULT 0,
    items_in_range INT UNSIGNED NOT NULL DEFAULT 0,
    opportunities_count INT UNSIGNED NOT NULL DEFAULT 0,
    top_gainers_json JSON NULL,
    top_losers_json JSON NULL,
    top_volume_json JSON NULL,
    top_opportunities_json JSON NULL,
    watchlist_moves_json JSON NULL,
    summary_text TEXT NULL,
    summary_model VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_daily_reports_date (report_date),
    KEY idx_daily_reports_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE watchlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    user_label VARCHAR(120) NULL,
    min_alert_price DECIMAL(10,2) NULL,
    max_alert_price DECIMAL(10,2) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_watchlist_item
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_watchlist_item_label (item_id, user_label),
    KEY idx_watchlist_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    items_processed INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    log_excerpt TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_job_runs_name_started_at (job_name, started_at),
    KEY idx_job_runs_status (status),
    KEY idx_job_runs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
