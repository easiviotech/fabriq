<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console;

/**
 * Stores and resolves CLI commands by name.
 */
final class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function register(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    public function resolve(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * @return array<string, Command>
     */
    public function all(): array
    {
        ksort($this->commands);
        return $this->commands;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }
}
