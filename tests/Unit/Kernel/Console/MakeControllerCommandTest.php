<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Kernel\Console;

use PHPUnit\Framework\TestCase;
use Fabriq\Kernel\Console\Commands\MakeControllerCommand;

final class MakeControllerCommandTest extends TestCase
{
    private string $tmpDir;
    private string $originalDir;

    protected function setUp(): void
    {
        $this->originalDir = getcwd() ?: sys_get_temp_dir();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fabriq-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->tmpDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers', 0755, true);
        chdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDir);
        $this->removeDirectory($this->tmpDir);
    }

    public function testGeneratesControllerFile(): void
    {
        $cmd = new MakeControllerCommand();

        ob_start();
        $exit = $cmd->handle(['Home'], []);
        ob_end_clean();

        $this->assertSame(0, $exit);

        $expected = $this->tmpDir . DIRECTORY_SEPARATOR
            . 'app' . DIRECTORY_SEPARATOR
            . 'Http' . DIRECTORY_SEPARATOR
            . 'Controllers' . DIRECTORY_SEPARATOR
            . 'HomeController.php';

        $this->assertFileExists($expected);

        $content = file_get_contents($expected);
        $this->assertStringContainsString('namespace App\\Http\\Controllers', $content);
        $this->assertStringContainsString('class HomeController', $content);
    }

    public function testDoesNotOverwriteExistingFile(): void
    {
        $existing = $this->tmpDir . DIRECTORY_SEPARATOR
            . 'app' . DIRECTORY_SEPARATOR
            . 'Http' . DIRECTORY_SEPARATOR
            . 'Controllers' . DIRECTORY_SEPARATOR
            . 'HomeController.php';

        file_put_contents($existing, '<?php // existing');

        $cmd = new MakeControllerCommand();

        ob_start();
        $exit = $cmd->handle(['Home'], []);
        ob_end_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('existing', file_get_contents($existing));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
