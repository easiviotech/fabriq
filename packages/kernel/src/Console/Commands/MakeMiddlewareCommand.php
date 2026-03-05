<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\GeneratorCommand;

final class MakeMiddlewareCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return 'make:middleware';
    }

    public function getDescription(): string
    {
        return 'Create a new middleware class';
    }

    protected function getStub(): string
    {
        return 'middleware.stub';
    }

    protected function getTargetDirectory(): string
    {
        return 'app/Http/Middleware';
    }

    protected function getNameSuffix(): string
    {
        return 'Middleware';
    }

    protected function getNamespace(): string
    {
        return 'App\\Http\\Middleware';
    }
}
