<?php

declare(strict_types=1);

namespace Fabriq\Observability;

use Fabriq\Kernel\Context;

/**
 * Structured JSON logger — writes to STDERR.
 *
 * Automatically includes context fields (tenant_id, correlation_id,
 * actor_id, request_id) from the current coroutine's Context.
 *
 * Log levels: debug, info, warning, error
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    private int $minLevel;

    public function __construct(string $minLevel = 'info')
    {
        $this->minLevel = self::LEVELS[$minLevel] ?? 1;
    }

    public function debug(string $message, array $extra = []): void
    {
        $this->log('debug', $message, $extra);
    }

    public function info(string $message, array $extra = []): void
    {
        $this->log('info', $message, $extra);
    }

    public function warning(string $message, array $extra = []): void
    {
        $this->log('warning', $message, $extra);
    }

    public function error(string $message, array $extra = []): void
    {
        $this->log('error', $message, $extra);
    }

    /**
     * Log a structured message.
     *
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $extra  Additional fields
     */
    public function log(string $level, string $message, array $extra = []): void
    {
        $levelValue = self::LEVELS[$level] ?? 1;
        if ($levelValue < $this->minLevel) {
            return;
        }

        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'tenant_id' => Context::tenantId(),
            'correlation_id' => Context::correlationId(),
            'actor_id' => Context::actorId(),
            'request_id' => Context::requestId(),
        ];

        if (!empty($extra)) {
            $entry['extra'] = $extra;
        }

        // Remove null context values for cleaner output
        $entry = array_filter($entry, fn($v) => $v !== null);

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Write to STDERR (non-blocking in Swoole)
        fwrite(STDERR, $json . "\n");
    }

    /**
     * Log an exception with full context.
     */
    public function exception(\Throwable $e, string $message = '', array $extra = []): void
    {
        $this->error($message ?: $e->getMessage(), array_merge($extra, [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
        ]));
    }
}

