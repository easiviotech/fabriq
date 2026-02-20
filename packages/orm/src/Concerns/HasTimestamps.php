<?php

declare(strict_types=1);

namespace Fabriq\Orm\Concerns;

/**
 * Automatic timestamp management for models.
 *
 * When enabled ($timestamps = true on the model), automatically
 * sets `created_at` on insert and `updated_at` on update.
 *
 * Timestamp format: 'Y-m-d H:i:s' (MySQL DATETIME)
 */
trait HasTimestamps
{
    /**
     * Get the current timestamp string.
     */
    protected function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Touch the created_at timestamp.
     */
    protected function touchCreatedAt(): void
    {
        if ($this->usesTimestamps() && !isset($this->attributes[$this->getCreatedAtColumn()])) {
            $this->setAttribute($this->getCreatedAtColumn(), $this->freshTimestamp());
        }
    }

    /**
     * Touch the updated_at timestamp.
     */
    protected function touchUpdatedAt(): void
    {
        if ($this->usesTimestamps()) {
            $this->setAttribute($this->getUpdatedAtColumn(), $this->freshTimestamp());
        }
    }

    /**
     * Check if this model uses timestamps.
     */
    public function usesTimestamps(): bool
    {
        return property_exists($this, 'timestamps') ? $this->timestamps : true;
    }

    /**
     * Get the created_at column name.
     */
    public function getCreatedAtColumn(): string
    {
        return defined('static::CREATED_AT') ? static::CREATED_AT : 'created_at';
    }

    /**
     * Get the updated_at column name.
     */
    public function getUpdatedAtColumn(): string
    {
        return defined('static::UPDATED_AT') ? static::UPDATED_AT : 'updated_at';
    }
}

