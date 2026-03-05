<?php

declare(strict_types=1);

use Fabriq\Kernel\Application;

$app = new Application(
    basePath: dirname(__DIR__),
);

$app->registerConfiguredProviders();
$app->boot();

return $app;
