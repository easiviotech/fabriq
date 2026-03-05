<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\GeneratorCommand;

final class MakeControllerCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return 'make:controller';
    }

    public function getDescription(): string
    {
        return 'Create a new controller class';
    }

    protected function getStub(): string
    {
        return 'controller.stub';
    }

    protected function getTargetDirectory(): string
    {
        return 'app/Http/Controllers';
    }

    protected function getNameSuffix(): string
    {
        return 'Controller';
    }

    protected function getNamespace(): string
    {
        return 'App\\Http\\Controllers';
    }
}
