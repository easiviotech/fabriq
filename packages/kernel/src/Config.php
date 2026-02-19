<?php

declare(strict_types=1);

namespace Fabriq\Kernel;

/**
 * Immutable, array-based configuration.
 *
 * Loaded once at boot. Retrieve values via dot-notation keys.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Load config from a PHP file that returns an array.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        /** @var array<string, mixed> $data */
        $data = require $path;

        return new self(is_array($data) ? $data : []);
    }

    /**
     * Load config from a directory of PHP files.
     *
     * Each file becomes a top-level key. For example:
     *   config/database.php  →  $config->get('database.platform.host')
     *   config/server.php    →  $config->get('server.host')
     *
     * @param string $directory Path to config directory
     */
    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory)) {
            return new self();
        }

        $items = [];
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return new self();
        }

        foreach ($files as $file) {
            $key = basename($file, '.php');

            /** @var mixed $data */
            $data = require $file;

            if (is_array($data)) {
                $items[$key] = $data;
            }
        }

        return new self($items);
    }

    /**
     * Get a value using dot-notation.
     *
     * @example $config->get('server.host', '0.0.0.0')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Return the entire config array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Return a new Config scoped to a sub-key.
     */
    public function section(string $key): self
    {
        $value = $this->get($key, []);
        return new self(is_array($value) ? $value : []);
    }
}
