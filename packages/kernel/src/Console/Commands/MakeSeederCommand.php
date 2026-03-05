<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\Command;

final class MakeSeederCommand extends Command
{
    public function getName(): string
    {
        return 'make:seeder';
    }

    public function getDescription(): string
    {
        return 'Create a new database seeder class';
    }

    public function handle(array $args, array $options): int
    {
        $name = $args[0] ?? '';
        if ($name === '') {
            $this->error('Usage: php bin/fabriq make:seeder <Name>');
            return 1;
        }

        if (!str_ends_with($name, 'Seeder')) {
            $name .= 'Seeder';
        }

        $stubPath = dirname(__DIR__, 3) . '/stubs/seeder.stub';
        if (!is_file($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return 1;
        }

        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            $this->error('Could not read stub file.');
            return 1;
        }

        $content = str_replace('{{ class }}', $name, $stub);

        $targetDir = $this->basePath('database/seeders');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $name . '.php';

        if (is_file($targetFile)) {
            $this->warn("File already exists: {$targetFile}");
            return 1;
        }

        file_put_contents($targetFile, $content);
        $this->info("Created: {$targetFile}");

        return 0;
    }
}
