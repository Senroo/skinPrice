# Architecture cible

## Principe

La base MySQL porte toute la memoire produit :

- catalogue normalise
- snapshots journaliers
- signaux listings
- rapports quotidiens
- watchlist
- traces d'execution des jobs

L'IA n'intervient qu'au niveau redactionnel pour transformer un JSON propre du jour en resume lisible.

## Sources de donnees

### 1. ByMykel

Role :

- source de catalogue stable
- source d'images pour les cartes et le detail item

Donnees stockees :

- `market_hash_name`
- `name`
- `weapon`
- `rarity`
- `category`
- `stattrak`
- `souvenir`
- `image_url`

### 2. Skinport

Role :

- prix agreges
- volumes
- historiques 24h, 7j, 30j, 90j

Donnees stockees :

- `current_price`
- `min_price`
- `max_price`
- `mean_price`
- `median_price`
- `quantity`
- `sales_*`

### 3. CSFloat

Role :

- enrichissement ponctuel des items retenus
- signaux fins sur float, stickers et prix listing

Donnees stockees :

- `listing_price`
- `float_value`
- `seller_score`
- `has_stickers`
- `raw_payload_json`

## Jobs quotidiens

### Job 1 - `sync-catalog`

- source : ByMykel
- frequence : 1 fois par jour
- effet : upsert dans `items`

### Job 2 - `sync-market`

- source : Skinport `/v1/items`
- frequence : 1 fois par jour
- effet : creation ou mise a jour du snapshot du jour dans `market_snapshots`
- contrainte : respecter le cache 5 minutes et la limite de 8 requetes sur 5 minutes

### Job 3 - `sync-history`

- source : Skinport `/v1/sales/history`
- frequence : 1 fois par jour apres le sync marche
- effet : enrichissement des agregats 24h, 7j, 30j, 90j

### Job 4 - `enrich-listings`

- source : CSFloat
- frequence : 1 fois par jour sur les items prioritaires
- effet : alimentation de `listing_signals` et enrichissement du score

### Job 5 - `generate-report`

- source : base locale
- frequence : 1 fois par jour apres les syncs
- effet :
  - comparaison avec J-1
  - calcul des tops et opportunites
  - construction d'un JSON propre
  - generation d'un resume IA
  - insertion dans `daily_reports`

## Services backend a prevoir

- `CatalogSyncService`
- `MarketSyncService`
- `HistorySyncService`
- `ListingEnrichmentService`
- `ScoringService`
- `ReportGeneratorService`
- `ImageResolverService`
- `DashboardMetricsService`
- `JobRunService`

## Score d'interet propose

Base simple et ajustable :

- `+40` si `current_price` est significativement sous `sales_7d_avg`
- `+25` si `sales_24h_volume` est superieur au volume moyen recent
- `+15` si `float_value` est interessant
- `+10` si l'item est en `watchlist`
- `-20` si la liquidite est trop faible
- `-15` si les donnees sont trop pauvres

Tags possibles :

- `drop`
- `spike`
- `volume_anomaly`
- `watchlist`
- `stable`

## Recommandation implementation

Ordre de dev le plus rentable :

1. persistance MySQL
2. connecteurs externes
3. jobs cron
4. endpoints de lecture
5. dashboard produit
6. dashboard admin
7. resume IA

