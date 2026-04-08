<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RadarService;

final class AdminController
{
    public function __construct(
        private readonly RadarService $radarService = new RadarService(),
    ) {
    }

    public function health(): array
    {
        return $this->radarService->health();
    }

    public function jobs(): array
    {
        return $this->radarService->jobs();
    }

    public function trigger(string $jobName): array
    {
        return $this->radarService->trigger($jobName);
    }
}
