<?php
declare(strict_types=1);

// Single public entry point. All domain logic and templates remain outside public/.
require dirname(__DIR__) . '/src/Http/bootstrap.php';
require dirname(__DIR__) . '/views/layout.php';
