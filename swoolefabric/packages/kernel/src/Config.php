<?php

declare(strict_types=1);

namespace SwooleFabric\Kernel;

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
