<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\GeneratorCommand;

final class MakeModelCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return 'make:model';
    }

    public function getDescription(): string
    {
        return 'Create a new model class';
    }

    protected function getStub(): string
    {
        return 'model.stub';
    }

    protected function getTargetDirectory(): string
    {
        return 'app/Models';
    }

    protected function getNamespace(): string
    {
        return 'App\\Models';
    }
}
