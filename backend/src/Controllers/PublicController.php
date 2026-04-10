<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RadarService;

final class PublicController
{
    public function __construct(
        private readonly RadarService $radarService = new RadarService(),
    ) {
    }

    public function overview(): array
    {
        return $this->radarService->overview();
    }

    public function items(): array
    {
        return $this->radarService->items($_GET);
    }

    public function item(int $id): array
    {
        return $this->radarService->item($id);
    }

    public function reportToday(): array
    {
        return $this->radarService->reportToday();
    }

    public function reportHistory(): array
    {
        return $this->radarService->reportHistory();
    }

    public function watchlist(): array
    {
        return $this->radarService->watchlist();
    }

    public function positions(): array
    {
        return $this->radarService->positions();
    }

    public function profiles(): array
    {
        return $this->radarService->profiles();
    }

    public function saveProfile(array $payload): array
    {
        return $this->radarService->saveProfile($payload);
    }

    public function deleteProfile(string $profileId): array
    {
        return $this->radarService->deleteProfile($profileId);
    }

    public function savePosition(array $payload): array
    {
        return $this->radarService->savePosition($payload);
    }

    public function deletePosition(string $positionId): array
    {
        return $this->radarService->deletePosition($positionId);
    }

    public function skinAdvice(array $payload): array
    {
        return $this->radarService->skinAdvice($payload);
    }
}
