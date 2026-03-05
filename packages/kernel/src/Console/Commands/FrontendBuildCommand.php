<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;
use Fabriq\Http\Frontend\FrontendBuilder;
use Fabriq\Storage\DbManager;
use Fabriq\Tenancy\TenantContext;

final class FrontendBuildCommand extends Command
{
    public function getName(): string
    {
        return 'frontend:build';
    }

    public function getDescription(): string
    {
        return 'Build & deploy tenant frontend from Git repo';
    }

    public function handle(array $args, array $options): int
    {
        $tenantSlug = $args[0] ?? '';
        if ($tenantSlug === '') {
            $this->error('Usage: php bin/fabriq frontend:build <tenant-slug>');
            return 1;
        }

        echo "╔══════════════════════════════════════╗\n";
        echo "║              Fabriq                  ║\n";
        echo "║        Frontend Builder              ║\n";
        echo "╚══════════════════════════════════════╝\n\n";

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $basePath = $this->basePath();
        $config = Config::fromDirectory($basePath . '/config');
        $exitCode = 0;

        \Swoole\Coroutine\run(function () use ($config, $basePath, $tenantSlug, &$exitCode) {
            $dbManager = new DbManager();
            $dbManager->boot($config);

            try {
                $pdo = $dbManager->platform();
                $stmt = $pdo->prepare('SELECT * FROM tenants WHERE slug = ? LIMIT 1');
                $stmt->execute([$tenantSlug]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $dbManager->releasePlatform($pdo);

                if (!$row) {
                    echo "\033[31m[error] Tenant '{$tenantSlug}' not found\033[0m\n";
                    $exitCode = 1;
                    return;
                }

                $tenant = TenantContext::fromArray($row);
            } catch (\Throwable $e) {
                echo "\033[31m[error] Failed to look up tenant: {$e->getMessage()}\033[0m\n";
                $exitCode = 1;
                return;
            }

            $builder = new FrontendBuilder($config, $basePath);
            echo "[info] Starting build for tenant '{$tenantSlug}'...\n\n";

            $result = $builder->build($tenant);

            echo "\n";
            echo "Status:      {$result->status}\n";
            echo "Commit:      {$result->commitHash}\n";
            echo "Duration:    " . round($result->durationMs) . "ms\n";
            echo "Timestamp:   {$result->timestamp}\n";

            if ($result->status !== 'success') {
                echo "\n--- Build Log ---\n";
                echo $result->log . "\n";
                $exitCode = 1;
                return;
            }

            echo "\nFrontend deployed to public/{$tenantSlug}/\n";
        });

        return $exitCode;
    }
}
