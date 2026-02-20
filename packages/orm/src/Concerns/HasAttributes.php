<?php

declare(strict_types=1);

namespace Fabriq\Orm\Concerns;

use RuntimeException;

/**
 * Attribute management for Eloquent-style models.
 *
 * Handles:
 *  - Attribute get/set via magic __get/__set
 *  - Mass assignment protection via $fillable / $guarded
 *  - Dirty tracking (which attributes have changed since hydration)
 *  - Attribute casting ($casts)
 *  - Original attribute snapshot for change detection
 */
trait HasAttributes
{
    /** @var array<string, mixed> Current attribute values */
    protected array $attributes = [];

    /** @var array<string, mixed> Original values from DB (for dirty tracking) */
    protected array $original = [];

    /** @var array<string, mixed> Changed attributes since last sync */
    protected array $dirty = [];

    // ── Mass Assignment ─────────────────────────────────────────────

    /**
     * Fill attributes from array, respecting $fillable / $guarded.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Force fill attributes ignoring $fillable / $guarded.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    /**
     * Check if an attribute is fillable.
     */
    protected function isFillable(string $key): bool
    {
        $fillable = property_exists($this, 'fillable') ? $this->fillable : [];
        $guarded = property_exists($this, 'guarded') ? $this->guarded : ['*'];

        // If fillable is explicitly defined, only those are allowed
        if (!empty($fillable)) {
            return in_array($key, $fillable, true);
        }

        // If guarded has '*', nothing is fillable by default
        if (in_array('*', $guarded, true)) {
            return false;
        }

        // Otherwise, anything not in $guarded is fillable
        return !in_array($key, $guarded, true);
    }

    // ── Attribute Get/Set ───────────────────────────────────────────

    /**
     * Get an attribute value, applying casts.
     */
    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;
        return $this->castGet($key, $value);
    }

    /**
     * Set an attribute value, tracking dirtiness.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $value = $this->castSet($key, $value);
        $this->attributes[$key] = $value;

        // Track dirty state
        $originalValue = $this->original[$key] ?? null;
        if ($value !== $originalValue) {
            $this->dirty[$key] = $value;
        } else {
            unset($this->dirty[$key]);
        }
    }

    /**
     * Magic getter.
     */
    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    /**
     * Magic setter.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Magic isset.
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    // ── Dirty Tracking ──────────────────────────────────────────────

    /**
     * Get attributes that have changed since hydration.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        return $this->dirty;
    }

    /**
     * Check if the model has unsaved changes.
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return array_key_exists($key, $this->dirty);
        }
        return !empty($this->dirty);
    }

    /**
     * Check if the model is clean (no unsaved changes).
     */
    public function isClean(?string $key = null): bool
    {
        return !$this->isDirty($key);
    }

    /**
     * Get the original value of an attribute.
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->original[$key] ?? null;
        }
        return $this->original;
    }

    /**
     * Sync original attributes with current (after a save).
     */
    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
        $this->dirty = [];
    }

    // ── Hydration ───────────────────────────────────────────────────

    /**
     * Set raw attributes from a database row (no dirty tracking).
     *
     * @param array<string, mixed> $attributes
     */
    public function setRawAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
        $this->original = $attributes;
        $this->dirty = [];
    }

    /**
     * Get all current attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    // ── Casting ─────────────────────────────────────────────────────

    /**
     * Cast a value when getting from attribute store.
     */
    protected function castGet(string $key, mixed $value): mixed
    {
        $casts = property_exists($this, 'casts') ? $this->casts : [];

        if (!isset($casts[$key]) || $value === null) {
            return $value;
        }

        return match ($casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
            'datetime' => $value,
            default => $value,
        };
    }

    /**
     * Cast a value when setting to attribute store.
     */
    protected function castSet(string $key, mixed $value): mixed
    {
        $casts = property_exists($this, 'casts') ? $this->casts : [];

        if (!isset($casts[$key]) || $value === null) {
            return $value;
        }

        return match ($casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
            default => $value,
        };
    }

    // ── Serialization ───────────────────────────────────────────────

    /**
     * Convert model to array (with cast values).
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->castGet($key, $value);
        }
        return $result;
    }
}

