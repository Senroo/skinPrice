<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AdminController;
use App\Controllers\PublicController;
use App\Support\JsonResponse;

final class Application
{
    public function __construct(
        private readonly PublicController $publicController = new PublicController(),
        private readonly AdminController $adminController = new AdminController(),
    ) {
    }

    public function handle(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($method === 'OPTIONS') {
            JsonResponse::send(['ok' => true]);
            return;
        }

        try {
            if ($method === 'GET' && $path === '/health') {
                JsonResponse::send(['status' => 'ok']);
                return;
            }

            if ($method === 'GET' && $path === '/api/dashboard/overview') {
                JsonResponse::send($this->publicController->overview());
                return;
            }

            if ($method === 'GET' && $path === '/api/items') {
                JsonResponse::send($this->publicController->items());
                return;
            }

            if ($method === 'GET' && preg_match('#^/api/items/(?P<id>\d+)$#', $path, $matches) === 1) {
                JsonResponse::send($this->publicController->item((int) $matches['id']));
                return;
            }

            if ($method === 'GET' && $path === '/api/reports/today') {
                JsonResponse::send($this->publicController->reportToday());
                return;
            }

            if ($method === 'GET' && $path === '/api/reports/history') {
                JsonResponse::send($this->publicController->reportHistory());
                return;
            }

            if ($method === 'GET' && $path === '/api/watchlist') {
                JsonResponse::send($this->publicController->watchlist());
                return;
            }

            if ($method === 'GET' && $path === '/api/admin/health') {
                JsonResponse::send($this->adminController->health());
                return;
            }

            if ($method === 'GET' && $path === '/api/admin/jobs') {
                JsonResponse::send($this->adminController->jobs());
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/sync-catalog') {
                JsonResponse::send($this->adminController->trigger('sync-catalog'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/sync-market') {
                JsonResponse::send($this->adminController->trigger('sync-market'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/sync-csfloat') {
                JsonResponse::send($this->adminController->trigger('sync-csfloat'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/generate-report') {
                JsonResponse::send($this->adminController->trigger('generate-report'));
                return;
            }

            JsonResponse::send([
                'message' => 'Route not found',
                'method' => $method,
                'path' => $path,
            ], 404);
        } catch (\Throwable $throwable) {
            JsonResponse::send([
                'message' => $throwable->getMessage(),
                'type' => $throwable::class,
            ], 500);
        }
    }
}
