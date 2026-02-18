<?php

declare(strict_types=1);

namespace SwooleFabric\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use SwooleFabric\Kernel\Config;

final class ConfigTest extends TestCase
{
    public function testGetReturnsValueByDotNotation(): void
    {
        $config = new Config([
            'server' => [
                'host' => '0.0.0.0',
                'port' => 8000,
            ],
        ]);

        $this->assertSame('0.0.0.0', $config->get('server.host'));
        $this->assertSame(8000, $config->get('server.port'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $config = new Config([]);

        $this->assertSame('default_val', $config->get('missing.key', 'default_val'));
        $this->assertNull($config->get('missing.key'));
    }

    public function testSectionReturnsSubConfig(): void
    {
        $config = new Config([
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
            ],
        ]);

        $section = $config->section('database');
        $this->assertSame('localhost', $section->get('host'));
        $this->assertSame(3306, $section->get('port'));
    }

    public function testAllReturnsEntireArray(): void
    {
        $items = ['a' => 1, 'b' => ['c' => 2]];
        $config = new Config($items);

        $this->assertSame($items, $config->all());
    }

    public function testFromFileMissingFileReturnsEmpty(): void
    {
        $config = Config::fromFile('/non/existent/file.php');
        $this->assertEmpty($config->all());
    }
}
