<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console;

use Fabriq\Kernel\Application;

/**
 * Console kernel — parses argv, resolves commands, runs them.
 */
final class Kernel
{
    private CommandRegistry $registry;
    private ?Application $app;

    public function __construct(CommandRegistry $registry, ?Application $app = null)
    {
        $this->registry = $registry;
        $this->app = $app;
    }

    public function registry(): CommandRegistry
    {
        return $this->registry;
    }

    /**
     * Run the console kernel with the given argv.
     *
     * @param list<string> $argv  Raw CLI arguments (including script name at index 0)
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'help';

        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            $this->printHelp();
            return 0;
        }

        $command = $this->registry->resolve($commandName);

        if ($command === null) {
            echo "\033[31mUnknown command: {$commandName}\033[0m\n\n";
            $this->printHelp();
            return 1;
        }

        if ($this->app) {
            $command->setApplication($this->app);
        }

        [$args, $options] = $this->parseArguments(array_slice($argv, 2));

        return $command->handle($args, $options);
    }

    private function printHelp(): void
    {
        echo "╔══════════════════════════════════════╗\n";
        echo "║              Fabriq CLI              ║\n";
        echo "╚══════════════════════════════════════╝\n\n";
        echo "Usage:\n";
        echo "  php bin/fabriq <command> [arguments] [options]\n\n";
        echo "Available commands:\n";

        $commands = $this->registry->all();
        $maxLen = 0;
        foreach ($commands as $name => $_) {
            $maxLen = max($maxLen, strlen($name));
        }

        foreach ($commands as $name => $command) {
            $padded = str_pad($name, $maxLen + 2);
            echo "  \033[32m{$padded}\033[0m{$command->getDescription()}\n";
        }

        echo "\n";
    }

    /**
     * Parse arguments and options from raw argv slice.
     *
     * @param list<string> $raw
     * @return array{list<string>, array<string, string>}
     */
    private function parseArguments(array $raw): array
    {
        $args = [];
        $options = [];

        foreach ($raw as $token) {
            if (str_starts_with($token, '--')) {
                $stripped = substr($token, 2);
                if (str_contains($stripped, '=')) {
                    [$key, $value] = explode('=', $stripped, 2);
                    $options[$key] = $value;
                } else {
                    $options[$stripped] = 'true';
                }
            } elseif (str_starts_with($token, '-')) {
                $options[substr($token, 1)] = 'true';
            } else {
                $args[] = $token;
            }
        }

        return [$args, $options];
    }
}
