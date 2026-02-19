<?php

declare(strict_types=1);

namespace Fabriq\Observability;

use Fabriq\Kernel\Context;

/**
 * Trace context — manages W3C-compatible trace propagation.
 *
 * Propagates trace ID across HTTP → job → consumer → WS push.
 */
final class TraceContext
{
    /**
     * Extract trace context from incoming request headers.
     *
     * Supports W3C Trace Context (traceparent header).
     *
     * @param array<string, string> $headers
     * @return array{trace_id: string, span_id: string, parent_span_id: ?string}
     */
    public static function extract(array $headers): array
    {
        $traceparent = $headers['traceparent'] ?? null;

        if ($traceparent !== null) {
            // Format: 00-{trace_id}-{parent_id}-{flags}
            $parts = explode('-', $traceparent);
            if (count($parts) === 4) {
                return [
                    'trace_id' => $parts[1],
                    'span_id' => bin2hex(random_bytes(8)),
                    'parent_span_id' => $parts[2],
                ];
            }
        }

        // Generate new trace context
        return [
            'trace_id' => bin2hex(random_bytes(16)),
            'span_id' => bin2hex(random_bytes(8)),
            'parent_span_id' => null,
        ];
    }

    /**
     * Generate a traceparent header value for outgoing requests.
     *
     * @param string $traceId
     * @param string $spanId
     * @return string W3C traceparent header
     */
    public static function inject(string $traceId, string $spanId): string
    {
        return "00-{$traceId}-{$spanId}-01";
    }

    /**
     * Create trace headers for propagation to downstream services.
     *
     * @return array<string, string>
     */
    public static function propagationHeaders(): array
    {
        $correlationId = Context::correlationId() ?? bin2hex(random_bytes(16));
        $requestId = Context::requestId() ?? bin2hex(random_bytes(8));

        return [
            'traceparent' => self::inject($correlationId, $requestId),
            'X-Correlation-ID' => $correlationId,
            'X-Request-ID' => $requestId,
        ];
    }
}
