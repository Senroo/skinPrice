<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AdminController;
use App\Controllers\PublicController;
use App\Support\JsonResponse;

final class Application
{
    private ?PublicController $publicController;
    private ?AdminController $adminController;

    public function __construct(
        ?PublicController $publicController = null,
        ?AdminController $adminController = null,
    ) {
        $this->publicController = $publicController;
        $this->adminController = $adminController;
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
                JsonResponse::send($this->publicController()->overview());
                return;
            }

            if ($method === 'GET' && $path === '/api/items') {
                JsonResponse::send($this->publicController()->items());
                return;
            }

            if ($method === 'GET' && preg_match('#^/api/items/(?P<id>\d+)$#', $path, $matches) === 1) {
                JsonResponse::send($this->publicController()->item((int) $matches['id']));
                return;
            }

            if ($method === 'GET' && $path === '/api/reports/today') {
                JsonResponse::send($this->publicController()->reportToday());
                return;
            }

            if ($method === 'GET' && $path === '/api/reports/history') {
                JsonResponse::send($this->publicController()->reportHistory());
                return;
            }

            if ($method === 'GET' && $path === '/api/watchlist') {
                JsonResponse::send($this->publicController()->watchlist());
                return;
            }

            if ($method === 'GET' && $path === '/api/positions') {
                JsonResponse::send($this->publicController()->positions());
                return;
            }

            if ($method === 'GET' && $path === '/api/profiles') {
                JsonResponse::send($this->publicController()->profiles());
                return;
            }

            if ($method === 'GET' && preg_match('#^/api/profiles/(?P<id>[^/]+)$#', $path, $matches) === 1) {
                JsonResponse::send($this->publicController()->profile((string) $matches['id']));
                return;
            }

            if ($method === 'POST' && $path === '/api/profiles') {
                JsonResponse::send($this->publicController()->saveProfile($this->readJsonBody()));
                return;
            }

            if ($method === 'DELETE' && preg_match('#^/api/profiles/(?P<id>[^/]+)$#', $path, $matches) === 1) {
                JsonResponse::send($this->publicController()->deleteProfile((string) $matches['id']));
                return;
            }

            if ($method === 'POST' && preg_match('#^/api/profiles/(?P<id>[^/]+)/sync-inventory$#', $path, $matches) === 1) {
                JsonResponse::send($this->publicController()->syncProfileInventory((string) $matches['id']));
                return;
            }

            if ($method === 'POST' && $path === '/api/positions') {
                JsonResponse::send($this->publicController()->savePosition($this->readJsonBody()));
                return;
            }

            if ($method === 'DELETE' && preg_match('#^/api/positions/(?P<id>[^/]+)$#', $path, $matches) === 1) {
                JsonResponse::send($this->publicController()->deletePosition((string) $matches['id']));
                return;
            }

            if ($method === 'POST' && $path === '/api/assistant/skin-advice') {
                JsonResponse::send($this->publicController()->skinAdvice($this->readJsonBody()));
                return;
            }

            if ($method === 'GET' && $path === '/api/admin/health') {
                JsonResponse::send($this->adminController()->health());
                return;
            }

            if ($method === 'GET' && $path === '/api/admin/jobs') {
                JsonResponse::send($this->adminController()->jobs());
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/sync-catalog') {
                JsonResponse::send($this->adminController()->trigger('sync-catalog'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/sync-market') {
                JsonResponse::send($this->adminController()->trigger('sync-market'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/sync-csfloat') {
                JsonResponse::send($this->adminController()->trigger('sync-csfloat'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/generate-report') {
                JsonResponse::send($this->adminController()->trigger('generate-report'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/send-discord-report') {
                JsonResponse::send($this->adminController()->trigger('send-discord-report'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/jobs/refresh-all') {
                JsonResponse::send($this->adminController()->trigger('refresh-all'));
                return;
            }

            if ($method === 'POST' && $path === '/api/admin/openrouter-test') {
                JsonResponse::send($this->adminController()->openRouterTest());
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

    private function publicController(): PublicController
    {
        return $this->publicController ??= new PublicController();
    }

    private function adminController(): AdminController
    {
        return $this->adminController ??= new AdminController();
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
