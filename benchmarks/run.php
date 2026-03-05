<?php

declare(strict_types=1);

/**
 * Fabriq HTTP Benchmark Runner
 *
 * Usage:
 *   1. Start the Fabriq server:  php bin/fabriq serve
 *   2. Run benchmarks:           php benchmarks/run.php
 *
 * Options:
 *   --host=127.0.0.1   Server host
 *   --port=8080         Server port
 *   --path=/            Request path
 *   --requests=1000     Total requests
 *   --concurrency=10    Concurrent connections (comma-separated for multiple runs: 10,50,100)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/HttpBenchmark.php';

use Fabriq\Benchmarks\HttpBenchmark;

// Parse CLI options
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}

$host = $options['host'] ?? '127.0.0.1';
$port = (int) ($options['port'] ?? 8080);
$path = $options['path'] ?? '/';
$totalRequests = (int) ($options['requests'] ?? 1000);
$concurrencyLevels = array_map('intval', explode(',', $options['concurrency'] ?? '10,50,100'));

echo "╔══════════════════════════════════════╗\n";
echo "║        Fabriq HTTP Benchmark         ║\n";
echo "╚══════════════════════════════════════╝\n\n";
echo "Target: http://{$host}:{$port}{$path}\n";
echo "Total requests per level: {$totalRequests}\n\n";

$allResults = [];

foreach ($concurrencyLevels as $concurrency) {
    echo "─── Concurrency: {$concurrency} ────────────────────────\n";

    $bench = new HttpBenchmark($host, $port);
    $result = $bench->run($path, $totalRequests, $concurrency);
    $result['concurrency'] = $concurrency;
    $allResults[] = $result;

    echo "  Requests/sec:  {$result['requests_per_second']}\n";
    echo "  Success:       {$result['success']} / {$result['total_requests']}\n";
    echo "  Errors:        {$result['errors']}\n";
    echo "  Duration:      {$result['duration_seconds']}s\n";
    echo "  Latency p50:   {$result['latency_ms']['p50']}ms\n";
    echo "  Latency p95:   {$result['latency_ms']['p95']}ms\n";
    echo "  Latency p99:   {$result['latency_ms']['p99']}ms\n";
    echo "  Min/Avg/Max:   {$result['latency_ms']['min']} / {$result['latency_ms']['avg']} / {$result['latency_ms']['max']}ms\n";
    echo "  Memory peak:   {$result['memory_mb']} MB\n\n";
}

// Save results
$output = [
    'timestamp' => date('c'),
    'target' => "http://{$host}:{$port}{$path}",
    'php_version' => PHP_VERSION,
    'swoole_version' => phpversion('swoole') ?: 'unknown',
    'results' => $allResults,
];

$resultsFile = __DIR__ . '/results.json';
file_put_contents($resultsFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Results saved to {$resultsFile}\n";
