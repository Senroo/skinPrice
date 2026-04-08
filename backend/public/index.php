<?php

declare(strict_types=1);

use App\Application;

require dirname(__DIR__) . '/bootstrap.php';

$application = new Application();
$application->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

