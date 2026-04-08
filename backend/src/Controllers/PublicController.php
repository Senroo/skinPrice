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
}
