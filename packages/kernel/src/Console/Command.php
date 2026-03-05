<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console;

use Fabriq\Kernel\Application;

/**
 * Base class for all CLI commands.
 *
 * Subclasses define a name (e.g. 'make:controller'), a description,
 * and implement handle() with the command's logic.
 */
abstract class Command
{
    protected ?Application $app = null;

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    /**
     * Execute the command.
     *
     * @param list<string> $args  Positional arguments (after the command name)
     * @param array<string, string> $options  Named options (--key=value)
     * @return int Exit code (0 = success)
     */
    abstract public function handle(array $args, array $options): int;

    public function setApplication(Application $app): void
    {
        $this->app = $app;
    }

    // ── Output Helpers ──────────────────────────────────────────────

    protected function line(string $text): void
    {
        echo $text . "\n";
    }

    protected function info(string $text): void
    {
        echo "\033[32m{$text}\033[0m\n";
    }

    protected function warn(string $text): void
    {
        echo "\033[33m{$text}\033[0m\n";
    }

    protected function error(string $text): void
    {
        echo "\033[31m{$text}\033[0m\n";
    }

    /**
     * Get the base path of the application.
     */
    protected function basePath(string $path = ''): string
    {
        if ($this->app) {
            return $this->app->basePath($path);
        }

        return getcwd() . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}
