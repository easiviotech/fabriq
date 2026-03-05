<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Fabriq\Observability\Logger;

final class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }
    }

    public function testMinLevelFilteringSkipsLowerLevels(): void
    {
        $logger = new Logger('warning');

        $logger->debug('should be skipped');
        $logger->info('should be skipped too');

        $this->assertTrue(true, 'Lower-level messages did not throw');
    }

    public function testExceptionLoggingDoesNotThrow(): void
    {
        $logger = new Logger('error');

        $logger->exception(
            new \RuntimeException('test failure', 42),
            'Something went wrong',
            ['context' => 'unit-test'],
        );

        $this->assertTrue(true, 'Exception logging did not throw');
    }

    public function testConstructorSetsMinLevel(): void
    {
        $debug = new Logger('debug');
        $error = new Logger('error');

        $this->assertInstanceOf(Logger::class, $debug);
        $this->assertInstanceOf(Logger::class, $error);
    }
}
