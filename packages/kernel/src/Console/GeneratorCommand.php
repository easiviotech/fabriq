<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console;

/**
 * Base class for stub-based code generators (make:* commands).
 *
 * Subclasses define the stub file, target directory, and suffix.
 * The generator reads the stub, replaces {{ placeholders }}, and
 * writes the file to the correct location.
 */
abstract class GeneratorCommand extends Command
{
    abstract protected function getStub(): string;

    abstract protected function getTargetDirectory(): string;

    protected function getNameSuffix(): string
    {
        return '';
    }

    protected function getNamespace(): string
    {
        return 'App';
    }

    public function handle(array $args, array $options): int
    {
        $name = $args[0] ?? '';
        if ($name === '') {
            $this->error("Usage: php bin/fabriq {$this->getName()} <Name>");
            return 1;
        }

        $name = str_replace('/', '\\', $name);
        $className = class_basename($name) . $this->getNameSuffix();
        $subNamespace = str_contains($name, '\\')
            ? '\\' . substr($name, 0, (int) strrpos($name, '\\'))
            : '';

        $fullNamespace = $this->getNamespace() . $subNamespace;

        $stubPath = dirname(__DIR__, 2) . '/stubs/' . $this->getStub();
        if (!is_file($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");
            return 1;
        }

        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            $this->error("Could not read stub file.");
            return 1;
        }

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$fullNamespace, $className],
            $stub,
        );

        $targetDir = $this->basePath($this->getTargetDirectory());
        if ($subNamespace !== '') {
            $targetDir .= DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, ltrim($subNamespace, '\\'));
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetFile = $targetDir . DIRECTORY_SEPARATOR . $className . '.php';

        if (is_file($targetFile)) {
            $this->warn("File already exists: {$targetFile}");
            return 1;
        }

        file_put_contents($targetFile, $content);
        $this->info("Created: {$targetFile}");

        return 0;
    }
}

/**
 * Extract the class name from a potentially namespaced string.
 */
function class_basename(string $class): string
{
    $pos = strrpos($class, '\\');
    return $pos !== false ? substr($class, $pos + 1) : $class;
}
