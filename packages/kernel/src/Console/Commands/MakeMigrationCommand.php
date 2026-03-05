<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\Command;

final class MakeMigrationCommand extends Command
{
    public function getName(): string
    {
        return 'make:migration';
    }

    public function getDescription(): string
    {
        return 'Create a new database migration';
    }

    public function handle(array $args, array $options): int
    {
        $name = $args[0] ?? '';
        if ($name === '') {
            $this->error('Usage: php bin/fabriq make:migration <name>');
            return 1;
        }

        $timestamp = date('Y_m_d_His');
        $snakeName = $this->toSnakeCase($name);
        $className = $this->toClassName($name);

        $stubPath = dirname(__DIR__, 3) . '/stubs/migration.stub';
        if (!is_file($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return 1;
        }

        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            $this->error("Could not read stub file.");
            return 1;
        }

        $content = str_replace('{{ class }}', $className, $stub);

        $targetDir = $this->basePath('database/migrations');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = "{$timestamp}_{$snakeName}.php";
        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($targetFile, $content);
        $this->info("Created: {$targetFile}");

        return 0;
    }

    private function toSnakeCase(string $name): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)) ?? $name);
    }

    private function toClassName(string $name): string
    {
        $parts = preg_split('/[_\-\s]+/', $name);
        if ($parts === false) {
            return ucfirst($name);
        }
        return implode('', array_map('ucfirst', $parts));
    }
}
