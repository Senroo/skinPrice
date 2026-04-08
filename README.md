# CS2 Market Daily Radar

Mini-app pour collecter les donnees marche CS2, historiser les snapshots journaliers, detecter des signaux utiles et produire un rapport quotidien illustre.

## Objectif MVP

Le MVP privilegie une architecture simple et robuste :

- `ByMykel` pour le catalogue et les images
- `Skinport` pour les prix et historiques agreges
- `CSFloat` pour l'enrichissement fin de certains listings
- `MySQL` comme memoire produit
- `PHP 8.3` pour l'API et les jobs
- `Vue 3` pour les dashboards

## Structure du projet

```text
skin/
|-- backend/
|   |-- public/
|   |   `-- index.php
|   |-- src/
|   |   |-- Application.php
|   |   |-- Controllers/
|   |   |   |-- AdminController.php
|   |   |   `-- PublicController.php
|   |   `-- Support/
|   |       `-- JsonResponse.php
|   `-- bootstrap.php
|-- database/
|   `-- schema.sql
|-- docs/
|   |-- api.md
|   `-- architecture.md
|-- frontend/
|   |-- package.json
|   |-- vite.config.js
|   `-- src/
|       |-- App.vue
|       |-- main.js
|       |-- router.js
|       |-- styles.css
|       |-- services/
|       |   `-- api.js
|       `-- pages/
|           |-- AdminPage.vue
|           |-- DashboardPage.vue
|           |-- HistoryPage.vue
|           |-- ItemDetailPage.vue
|           `-- ReportTodayPage.vue
`-- README.md
```

## Priorite de dev

### Phase 1

1. Integrer `ByMykel` et remplir `items`
2. Integrer `Skinport /v1/items` et remplir `market_snapshots`
3. Filtrer la tranche `5.00 EUR -> 800.00 EUR`
4. Exposer `GET /api/dashboard/overview`, `GET /api/items`, `GET /api/reports/today`
5. Afficher dashboard, rapport du jour et historique simple

### Phase 2

1. Integrer `Skinport /v1/sales/history`
2. Calculer score, variations et tags
3. Generer un rapport du jour complet avec resume IA
4. Ajouter watchlist et detail item

### Phase 3

1. Enrichir via `CSFloat`
2. Ajouter dashboard admin complet
3. Ajouter alertes Discord ou email
4. Ajouter rapport hebdo

## Endpoints a coder en premier

Le contrat detaille est dans [docs/api.md](./docs/api.md). Les premiers endpoints utiles sont :

- `GET /api/dashboard/overview`
- `GET /api/items`
- `GET /api/items/{id}`
- `GET /api/reports/today`
- `GET /api/reports/history`
- `GET /api/admin/health`
- `GET /api/admin/jobs`
- `POST /api/admin/jobs/sync-catalog`
- `POST /api/admin/jobs/sync-market`
- `POST /api/admin/jobs/sync-csfloat`
- `POST /api/admin/jobs/generate-report`

## Execution locale

### Backend

Le backend est un squelette PHP sans framework pour demarrer vite.

```powershell
cd backend
php -S 127.0.0.1:8080 -t public router.php
```

### Frontend

Le frontend est prevu pour `Vite + Vue 3`.

```powershell
cd frontend
npm install
npm run dev
```

### Demo immediate

Pour voir la version fonctionnelle sans installer Node :

```powershell
cd backend
php -S 127.0.0.1:8080 -t public router.php
```

Puis ouvrir `http://127.0.0.1:8080/demo/`.

## Variables d'environnement

Copier `.env.example` si tu veux preparer un environnement de deploy :

- `CSFLOAT_API_KEY` : recommande, evite les refus HTTP 403 sur l'enrichissement listings CSFloat
- `SKINPORT_BROWSER_PATH` : optionnel, chemin d'un navigateur Chromium si le runtime n'arrive pas a decoder Brotli via curl
- `PORT` : utile pour Railway

## Jobs live disponibles

- `php backend/sync.php sync-catalog`
- `php backend/sync.php sync-market`
- `php backend/sync.php sync-csfloat`
- `php backend/sync.php generate-report`

## Deploiement Railway via Git

Le repo contient deja [railway.toml](./railway.toml) avec :

- serveur PHP builtin expose sur `0.0.0.0:$PORT`
- healthcheck HTTP sur `/health`
- restart policy simple

Parcours recommande :

1. pousser le repo sur GitHub
2. creer un projet Railway depuis ce repo
3. renseigner les variables `CSFLOAT_API_KEY` et eventuellement `SKINPORT_BROWSER_PATH`
4. verifier que `https://ton-app/health` retourne `{"status":"ok"}`

Note importante :

- le stockage actuel est en JSON local dans `backend/storage`
- sur Railway ce stockage n'est pas durable apres redeploiement
- pour une vraie prod il faut passer rapidement a MySQL Railway pour les snapshots, rapports et signaux

## Prochaine etape recommandee

La meilleure suite pour avancer vite est :

1. implementer les connecteurs `ByMykel` et `Skinport`
2. brancher MySQL
3. remplacer les reponses mockees de l'API
4. brancher le dashboard Vue sur ces vraies donnees
