<?php

declare(strict_types=1);

namespace Fabriq\Http\Frontend;

use Fabriq\Kernel\Config;
use Fabriq\Tenancy\TenantContext;
use RuntimeException;

/**
 * Clones a tenant's frontend Git repository, runs the build command,
 * and deploys the output to the tenant's public directory.
 *
 * Uses Swoole\Coroutine\System::exec() for non-blocking process execution
 * when running inside a coroutine, with a configurable timeout.
 *
 * Deployment is atomic: the old directory is renamed, the new one is moved
 * in, and then the old one is deleted — users never see a half-built state.
 */
final class FrontendBuilder
{
    private string $workspace;
    private string $publicRoot;
    private string $defaultCommand;
    private string $defaultOutput;
    private int $timeout;

    /** @var array<string, BuildResult> In-memory status cache (per-worker) */
    private array $statuses = [];

    public function __construct(
        private readonly Config $config,
        string $basePath,
    ) {
        $docRoot = (string) $config->get('static.document_root', 'public');
        $this->publicRoot = $basePath . DIRECTORY_SEPARATOR . $docRoot;

        $workspace = (string) $config->get('static.build.workspace', 'storage/builds');
        $this->workspace = $basePath . DIRECTORY_SEPARATOR . $workspace;

        $this->defaultCommand = (string) $config->get('static.build.default_command', 'npm install && npm run build');
        $this->defaultOutput = (string) $config->get('static.build.default_output', 'dist');
        $this->timeout = (int) $config->get('static.build.timeout', 300);
    }

    /**
     * Run the full build pipeline for a tenant.
     */
    public function build(TenantContext $tenant): BuildResult
    {
        $startTime = microtime(true);
        $slug = $tenant->slug;

        $frontendConfig = $tenant->config['frontend'] ?? [];
        $repository = $frontendConfig['repository'] ?? null;

        if (!is_string($repository) || $repository === '') {
            return BuildResult::failed($slug, 'No frontend.repository configured for this tenant', 0);
        }

        $branch = (string) ($frontendConfig['branch'] ?? 'main');
        $buildCommand = (string) ($frontendConfig['build_command'] ?? $this->defaultCommand);
        $outputDir = (string) ($frontendConfig['output_dir'] ?? $this->defaultOutput);

        $this->setStatus($slug, new BuildResult(
            tenantSlug: $slug,
            status: 'building',
            commitHash: '',
            durationMs: 0,
            log: 'Build started...',
            timestamp: date('c'),
        ));

        $repoDir = $this->workspace . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'repo';
        $log = '';

        try {
            $this->ensureDirectory($this->workspace . DIRECTORY_SEPARATOR . $slug);
            $log .= $this->cloneOrPull($repository, $branch, $repoDir);
            $commitHash = $this->getCommitHash($repoDir);
            $log .= $this->runBuild($buildCommand, $repoDir);

            $buildOutputDir = $repoDir . DIRECTORY_SEPARATOR . $outputDir;
            if (!is_dir($buildOutputDir)) {
                throw new RuntimeException(
                    "Build output directory '{$outputDir}' not found. "
                    . "Check the build command output or set 'output_dir' in tenant frontend config."
                );
            }

            $indexFile = (string) $this->config->get('static.index', 'index.html');
            if (!is_file($buildOutputDir . DIRECTORY_SEPARATOR . $indexFile)) {
                $log .= "[warn] Index file '{$indexFile}' not found in build output\n";
            }

            $this->deploy($slug, $buildOutputDir);
            $log .= "[deploy] Deployed to public/{$slug}/\n";

            $durationMs = (microtime(true) - $startTime) * 1000;
            $result = new BuildResult(
                tenantSlug: $slug,
                status: 'success',
                commitHash: $commitHash,
                durationMs: $durationMs,
                log: $this->truncateLog($log),
                timestamp: date('c'),
            );

            $this->setStatus($slug, $result);
            return $result;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $log .= "[error] " . $e->getMessage() . "\n";

            $result = BuildResult::failed($slug, $this->truncateLog($log), $durationMs);
            $this->setStatus($slug, $result);
            return $result;
        }
    }

    /**
     * Get the last known build status for a tenant.
     */
    public function status(string $tenantSlug): ?BuildResult
    {
        return $this->statuses[$tenantSlug] ?? null;
    }

    private function setStatus(string $slug, BuildResult $result): void
    {
        $this->statuses[$slug] = $result;
    }

    private function cloneOrPull(string $repository, string $branch, string $repoDir): string
    {
        $log = '';

        if (is_dir($repoDir . DIRECTORY_SEPARATOR . '.git')) {
            $log .= "[git] Fetching updates...\n";
            $log .= $this->exec("git -C {$this->escape($repoDir)} fetch origin");
            $log .= $this->exec("git -C {$this->escape($repoDir)} checkout {$this->escape($branch)}");
            $log .= $this->exec("git -C {$this->escape($repoDir)} reset --hard origin/{$this->escape($branch)}");
        } else {
            $log .= "[git] Cloning {$repository} (branch: {$branch})...\n";
            $this->ensureDirectory(dirname($repoDir));
            $log .= $this->exec(
                "git clone --depth 1 --branch {$this->escape($branch)} {$this->escape($repository)} {$this->escape($repoDir)}"
            );
        }

        return $log;
    }

    private function getCommitHash(string $repoDir): string
    {
        $result = $this->execRaw("git -C {$this->escape($repoDir)} rev-parse --short HEAD");
        return trim($result);
    }

    private function runBuild(string $command, string $workingDir): string
    {
        $log = "[build] Running: {$command}\n";
        $log .= $this->exec("cd {$this->escape($workingDir)} && {$command}");
        return $log;
    }

    /**
     * Atomic deployment: rename old dir, move new dir in, delete old.
     */
    private function deploy(string $slug, string $buildOutputDir): void
    {
        $targetDir = $this->publicRoot . DIRECTORY_SEPARATOR . $slug;
        $oldDir = $targetDir . '.old';

        // Clean up any leftover .old directory
        if (is_dir($oldDir)) {
            $this->exec("rm -rf {$this->escape($oldDir)}");
        }

        // Move current to .old (if exists)
        if (is_dir($targetDir)) {
            rename($targetDir, $oldDir);
        }

        // Copy build output to target
        $this->ensureDirectory(dirname($targetDir));
        $this->copyDirectory($buildOutputDir, $targetDir);

        // Remove old directory
        if (is_dir($oldDir)) {
            $this->exec("rm -rf {$this->escape($oldDir)}");
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    private function exec(string $command): string
    {
        if (class_exists(\Swoole\Coroutine\System::class) && \Swoole\Coroutine::getCid() >= 0) {
            $result = \Swoole\Coroutine\System::exec($command, $this->timeout);
            if ($result === false) {
                throw new RuntimeException("Command failed or timed out: {$command}");
            }
            $output = $result['output'] ?? '';
            $code = $result['code'] ?? -1;
        } else {
            $output = '';
            $code = 0;
            exec($command . ' 2>&1', $lines, $code);
            $output = implode("\n", $lines);
        }

        if ($code !== 0) {
            throw new RuntimeException(
                "Command exited with code {$code}: {$command}\nOutput: {$output}"
            );
        }

        return $output . "\n";
    }

    /**
     * Execute a command and return raw output without throwing on error.
     */
    private function execRaw(string $command): string
    {
        if (class_exists(\Swoole\Coroutine\System::class) && \Swoole\Coroutine::getCid() >= 0) {
            $result = \Swoole\Coroutine\System::exec($command, $this->timeout);
            return ($result !== false) ? ($result['output'] ?? '') : '';
        }

        $output = [];
        exec($command . ' 2>&1', $output);
        return implode("\n", $output);
    }

    private function escape(string $arg): string
    {
        return escapeshellarg($arg);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function truncateLog(string $log, int $maxLength = 65536): string
    {
        if (strlen($log) <= $maxLength) {
            return $log;
        }
        return substr($log, 0, $maxLength) . "\n... [truncated]";
    }
}
