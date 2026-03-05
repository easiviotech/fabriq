<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;

final class FrontendStatusCommand extends Command
{
    public function getName(): string
    {
        return 'frontend:status';
    }

    public function getDescription(): string
    {
        return 'Show frontend deployment status';
    }

    public function handle(array $args, array $options): int
    {
        $tenantSlug = $args[0] ?? '';
        if ($tenantSlug === '') {
            $this->error('Usage: php bin/fabriq frontend:status <tenant-slug>');
            return 1;
        }

        $basePath = $this->basePath();
        $config = Config::fromDirectory($basePath . '/config');
        $docRoot = (string) $config->get('static.document_root', 'public');
        $publicDir = $basePath . '/' . $docRoot . '/' . $tenantSlug;

        echo "Tenant:       {$tenantSlug}\n";
        echo "Deploy path:  {$publicDir}\n";

        if (is_dir($publicDir)) {
            $indexFile = (string) $config->get('static.index', 'index.html');
            $hasIndex = is_file($publicDir . '/' . $indexFile);
            echo "Status:       deployed\n";
            echo "Has index:    " . ($hasIndex ? 'yes' : 'no') . "\n";

            $files = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($publicDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $_) {
                $files++;
            }
            echo "Total files:  {$files}\n";
        } else {
            echo "Status:       not deployed (directory does not exist)\n";
        }

        return 0;
    }
}
