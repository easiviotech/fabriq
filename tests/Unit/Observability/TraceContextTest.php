<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Fabriq\Observability\TraceContext;

final class TraceContextTest extends TestCase
{
    public function testExtractWithTraceparent(): void
    {
        $traceId = bin2hex(random_bytes(16));
        $parentId = bin2hex(random_bytes(8));
        $headers = ['traceparent' => "00-{$traceId}-{$parentId}-01"];

        $ctx = TraceContext::extract($headers);

        $this->assertSame($traceId, $ctx['trace_id']);
        $this->assertSame($parentId, $ctx['parent_span_id']);
        $this->assertNotEmpty($ctx['span_id']);
        $this->assertSame(16, strlen($ctx['span_id'])); // 8 bytes hex-encoded
    }

    public function testExtractWithoutTraceparent(): void
    {
        $ctx = TraceContext::extract([]);

        $this->assertSame(32, strlen($ctx['trace_id'])); // 16 bytes hex-encoded
        $this->assertSame(16, strlen($ctx['span_id']));
        $this->assertNull($ctx['parent_span_id']);
    }

    public function testExtractWithInvalidTraceparent(): void
    {
        $ctx = TraceContext::extract(['traceparent' => 'invalid-header']);

        $this->assertSame(32, strlen($ctx['trace_id']));
        $this->assertSame(16, strlen($ctx['span_id']));
        $this->assertNull($ctx['parent_span_id']);
    }

    public function testInject(): void
    {
        $traceId = 'abcdef1234567890abcdef1234567890';
        $spanId = '1234567890abcdef';

        $header = TraceContext::inject($traceId, $spanId);

        $this->assertSame("00-{$traceId}-{$spanId}-01", $header);
    }
}
