<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\GeneratorCommand;

final class MakeProviderCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return 'make:provider';
    }

    public function getDescription(): string
    {
        return 'Create a new service provider class';
    }

    protected function getStub(): string
    {
        return 'provider.stub';
    }

    protected function getTargetDirectory(): string
    {
        return 'app/Providers';
    }

    protected function getNameSuffix(): string
    {
        return 'ServiceProvider';
    }

    protected function getNamespace(): string
    {
        return 'App\\Providers';
    }
}
