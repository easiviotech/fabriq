<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Fabriq\Observability\MetricsCollector;

final class MetricsCollectorTest extends TestCase
{
    public function testRegisterCounter(): void
    {
        $m = new MetricsCollector();
        $m->registerCounter('requests_total', 'Total requests');

        $arr = $m->toArray();
        $this->assertSame(0.0, $arr['requests_total']['value']);
        $this->assertSame('counter', $arr['requests_total']['type']);
    }

    public function testIncrementCounter(): void
    {
        $m = new MetricsCollector();
        $m->registerCounter('hits');

        $m->increment('hits');
        $this->assertSame(1.0, $m->toArray()['hits']['value']);

        $m->increment('hits', 5.0);
        $this->assertSame(6.0, $m->toArray()['hits']['value']);
    }

    public function testIgnoresUnregisteredCounter(): void
    {
        $m = new MetricsCollector();
        $m->increment('ghost');

        $this->assertArrayNotHasKey('ghost', $m->toArray());
    }

    public function testRegisterGauge(): void
    {
        $m = new MetricsCollector();
        $m->registerGauge('connections', 'Active connections');

        $arr = $m->toArray();
        $this->assertSame(0.0, $arr['connections']['value']);
        $this->assertSame('gauge', $arr['connections']['type']);
    }

    public function testSetGauge(): void
    {
        $m = new MetricsCollector();
        $m->registerGauge('temp');

        $m->set('temp', 42.5);
        $this->assertSame(42.5, $m->toArray()['temp']['value']);
    }

    public function testAddGauge(): void
    {
        $m = new MetricsCollector();
        $m->registerGauge('mem');

        $m->add('mem', 10.0);
        $m->add('mem', 5.0);
        $this->assertSame(15.0, $m->toArray()['mem']['value']);
    }

    public function testRegisterHistogram(): void
    {
        $m = new MetricsCollector();
        $m->registerHistogram('latency', 'Request latency');

        $arr = $m->toArray();
        $this->assertSame('histogram', $arr['latency']['type']);
        $this->assertSame(0, $arr['latency']['count']);
        $this->assertEquals(0, $arr['latency']['sum']);
    }

    public function testObserveHistogram(): void
    {
        $m = new MetricsCollector();
        $m->registerHistogram('duration');

        $m->observe('duration', 0.1);
        $m->observe('duration', 0.5);
        $m->observe('duration', 1.2);

        $arr = $m->toArray();
        $this->assertSame(3, $arr['duration']['count']);
        $this->assertEqualsWithDelta(1.8, $arr['duration']['sum'], 0.0001);
    }

    public function testRender(): void
    {
        $m = new MetricsCollector();
        $m->registerCounter('http_requests', 'Total HTTP requests');
        $m->increment('http_requests', 10);
        $m->registerGauge('active_conns', 'Open connections');
        $m->set('active_conns', 3);

        $output = $m->render();

        $this->assertStringContainsString('# HELP http_requests Total HTTP requests', $output);
        $this->assertStringContainsString('# TYPE http_requests counter', $output);
        $this->assertStringContainsString('http_requests 10', $output);
        $this->assertStringContainsString('# TYPE active_conns gauge', $output);
        $this->assertStringContainsString('active_conns 3', $output);
    }

    public function testToArray(): void
    {
        $m = new MetricsCollector();
        $m->registerCounter('c');
        $m->registerGauge('g');
        $m->registerHistogram('h');

        $arr = $m->toArray();

        $this->assertSame(['type' => 'counter', 'value' => 0.0], $arr['c']);
        $this->assertSame(['type' => 'gauge', 'value' => 0.0], $arr['g']);
        $this->assertSame('histogram', $arr['h']['type']);
        $this->assertSame(0, $arr['h']['count']);
        $this->assertEquals(0, $arr['h']['sum']);
    }
}
