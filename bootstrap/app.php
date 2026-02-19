<?php

declare(strict_types=1);

/**
 * Application Bootstrap
 *
 * This file creates the Fabriq Application instance and registers
 * all service providers listed in config/app.php.
 *
 * The single entry point that wires everything together before the server starts.
 *
 * @return \Fabriq\Kernel\Application
 */

use Fabriq\Kernel\Application;

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The base path is the project root directory. The Application constructor
| automatically loads all config/*.php files and registers core services
| (Logger, MetricsCollector, DbManager, etc.).
|
*/

$app = new Application(
    basePath: dirname(__DIR__),
);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| All providers listed in config/app.php 'providers' array are registered
| here. Each provider's register() method is called to bind services,
| and then boot() is called on all providers after registration.
|
*/

$app->registerConfiguredProviders();
$app->boot();

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| The CLI entry point (bin/fabriq) calls $app->run() to start the server.
|
*/

return $app;

