<?php

declare(strict_types=1);

namespace Fabriq\Benchmarks;

/**
 * HTTP benchmark runner using Swoole coroutine HTTP client.
 *
 * Measures:
 *   - Requests per second
 *   - Latency percentiles (p50, p95, p99)
 *   - Memory usage per worker
 */
final class HttpBenchmark
{
    private string $host;
    private int $port;

    /** @var list<float> Latency samples in milliseconds */
    private array $latencies = [];

    private int $successCount = 0;
    private int $errorCount = 0;
    private float $totalDuration = 0;

    public function __construct(string $host = '127.0.0.1', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Run the benchmark.
     *
     * @param string $path    URL path to request
     * @param int $requests   Total number of requests
     * @param int $concurrency Number of concurrent coroutines
     * @return array<string, mixed> Benchmark results
     */
    public function run(string $path, int $requests, int $concurrency): array
    {
        $this->latencies = [];
        $this->successCount = 0;
        $this->errorCount = 0;

        $perWorker = (int) ceil($requests / $concurrency);
        $startTime = microtime(true);

        \Swoole\Coroutine\run(function () use ($path, $concurrency, $perWorker) {
            $wg = new \Swoole\Coroutine\WaitGroup();

            for ($i = 0; $i < $concurrency; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($path, $perWorker, $wg) {
                    try {
                        for ($j = 0; $j < $perWorker; $j++) {
                            $this->sendRequest($path);
                        }
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();
        });

        $this->totalDuration = microtime(true) - $startTime;

        return $this->results();
    }

    private function sendRequest(string $path): void
    {
        $client = new \Swoole\Coroutine\Http\Client($this->host, $this->port);
        $client->set(['timeout' => 10]);

        $start = microtime(true);
        $client->get($path);
        $latency = (microtime(true) - $start) * 1000;

        if ($client->statusCode > 0) {
            $this->successCount++;
            $this->latencies[] = $latency;
        } else {
            $this->errorCount++;
        }

        $client->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        $total = $this->successCount + $this->errorCount;
        $rps = $this->totalDuration > 0 ? $total / $this->totalDuration : 0;

        sort($this->latencies);
        $count = count($this->latencies);

        return [
            'total_requests' => $total,
            'success' => $this->successCount,
            'errors' => $this->errorCount,
            'duration_seconds' => round($this->totalDuration, 3),
            'requests_per_second' => round($rps, 1),
            'latency_ms' => [
                'p50' => $count > 0 ? round($this->percentile($this->latencies, 0.50), 2) : 0,
                'p95' => $count > 0 ? round($this->percentile($this->latencies, 0.95), 2) : 0,
                'p99' => $count > 0 ? round($this->percentile($this->latencies, 0.99), 2) : 0,
                'min' => $count > 0 ? round(min($this->latencies), 2) : 0,
                'max' => $count > 0 ? round(max($this->latencies), 2) : 0,
                'avg' => $count > 0 ? round(array_sum($this->latencies) / $count, 2) : 0,
            ],
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }

    /**
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $index = (int) ceil(count($sorted) * $p) - 1;
        return $sorted[max(0, $index)];
    }
}
