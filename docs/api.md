# Contrat d'API v1

Tous les endpoints renvoient du JSON. La devise pivot est `EUR`.

## Endpoints priorite MVP

### `GET /api/dashboard/overview`

Retourne les KPIs principaux du jour.

```json
{
  "date": "2026-04-08",
  "items_tracked": 1820,
  "items_in_range": 614,
  "opportunities_count": 27,
  "watchlist_moving_count": 4,
  "top_signals": [
    {
      "item_id": 42,
      "name": "AK-47 | Redline (Field-Tested)",
      "current_price": 17.40,
      "change_24h": 4.8,
      "volume_ratio": 1.9,
      "score": 78,
      "tags": ["volume_anomaly", "watchlist"],
      "image_url": "https://..."
    }
  ],
  "job_status": {
    "last_sync_at": "2026-04-08T06:35:00+02:00",
    "health": "warning"
  }
}
```

### `GET /api/items`

Liste paginee filtrable.

Parametres :

- `q`
- `price_min`
- `price_max`
- `weapon`
- `rarity`
- `stattrak`
- `souvenir`
- `volume_min`
- `score_min`
- `page`
- `per_page`

```json
{
  "data": [
    {
      "id": 42,
      "market_hash_name": "AK-47 | Redline (Field-Tested)",
      "name": "AK-47 | Redline (Field-Tested)",
      "weapon": "AK-47",
      "rarity": "Classified",
      "category": "Rifle",
      "stattrak": false,
      "souvenir": false,
      "image_url": "https://...",
      "snapshot": {
        "date": "2026-04-08",
        "current_price": 17.40,
        "change_vs_yesterday_pct": 4.8,
        "change_vs_7d_pct": -3.1,
        "sales_24h_volume": 48,
        "volume_ratio_24h_7d": 1.9,
        "interest_score": 78,
        "tags": ["volume_anomaly"]
      }
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 614
  }
}
```

### `GET /api/items/{id}`

Retour detail d'un item.

```json
{
  "id": 42,
  "market_hash_name": "AK-47 | Redline (Field-Tested)",
  "name": "AK-47 | Redline (Field-Tested)",
  "weapon": "AK-47",
  "rarity": "Classified",
  "category": "Rifle",
  "stattrak": false,
  "souvenir": false,
  "image_url": "https://...",
  "latest_snapshot": {
    "snapshot_date": "2026-04-08",
    "current_price": 17.40,
    "sales_24h_avg": 17.05,
    "sales_7d_avg": 17.95,
    "sales_30d_avg": 18.60,
    "sales_24h_volume": 48,
    "sales_7d_volume": 177,
    "sales_30d_volume": 641,
    "change_vs_yesterday_pct": 4.8,
    "change_vs_7d_pct": -3.1,
    "change_vs_30d_pct": -6.5,
    "volume_ratio_24h_7d": 1.9,
    "interest_score": 78,
    "tags": ["volume_anomaly", "watchlist"]
  },
  "history": [
    {
      "snapshot_date": "2026-04-07",
      "current_price": 16.60
    },
    {
      "snapshot_date": "2026-04-08",
      "current_price": 17.40
    }
  ],
  "recent_listing_signals": [
    {
      "observed_at": "2026-04-08T06:00:00+02:00",
      "listing_price": 16.95,
      "float_value": 0.19,
      "has_stickers": true,
      "signal_score": 82
    }
  ]
}
```

### `GET /api/reports/today`

Retourne le rapport courant avec les cartes.

### `GET /api/reports/history`

Retourne les anciens rapports.

### `GET /api/watchlist`

Retourne les items surveilles et leurs alertes.

## Endpoints admin

### `GET /api/admin/health`

```json
{
  "status": "warning",
  "last_sync_at": "2026-04-08T06:35:00+02:00",
  "market_sync_cooldown_remaining": 180,
  "market_sync_available": false,
  "sources": {
    "bymykel": "ok",
    "skinport": "ok",
    "csfloat": "degraded"
  },
  "jobs": {
    "sync-catalog": "success",
    "sync-market": "success",
    "sync-history": "queued",
    "generate-report": "pending"
  }
}
```

### `GET /api/admin/jobs`

Liste des executions recentes de jobs.

### `POST /api/admin/jobs/sync-catalog`

Declenche un job de sync catalogue.

### `POST /api/admin/jobs/sync-market`

Declenche un job de sync marche.

### `POST /api/admin/jobs/sync-csfloat`

Declenche un job d'enrichissement listings CSFloat sur les items prioritaires.

### `POST /api/admin/jobs/generate-report`

Declenche la generation du rapport du jour.

## Ordre d'implementation recommande

1. `GET /api/dashboard/overview`
2. `GET /api/items`
3. `GET /api/items/{id}`
4. `GET /api/reports/today`
5. `GET /api/admin/health`
6. `GET /api/admin/jobs`
7. `POST /api/admin/jobs/sync-catalog`
8. `POST /api/admin/jobs/sync-market`
9. `POST /api/admin/jobs/sync-csfloat`
10. `POST /api/admin/jobs/generate-report`
