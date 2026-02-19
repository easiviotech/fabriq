<?php

declare(strict_types=1);

namespace Fabriq\Observability;

/**
 * Prometheus-compatible metrics collector.
 *
 * Thread-safe within a single worker using PHP arrays.
 * Exposes metrics in Prometheus text exposition format.
 */
final class MetricsCollector
{
    /** @var array<string, float> counter values */
    private array $counters = [];

    /** @var array<string, float> gauge values */
    private array $gauges = [];

    /** @var array<string, list<float>> histogram observations */
    private array $histograms = [];

    /** @var array<string, string> metric help text */
    private array $help = [];

    // ── Registration ──────────────────────────────────────────────────

    public function registerCounter(string $name, string $help = ''): void
    {
        $this->counters[$name] = 0.0;
        $this->help[$name] = $help;
    }

    public function registerGauge(string $name, string $help = ''): void
    {
        $this->gauges[$name] = 0.0;
        $this->help[$name] = $help;
    }

    public function registerHistogram(string $name, string $help = ''): void
    {
        $this->histograms[$name] = [];
        $this->help[$name] = $help;
    }

    // ── Counter ───────────────────────────────────────────────────────

    public function increment(string $name, float $value = 1.0): void
    {
        if (isset($this->counters[$name])) {
            $this->counters[$name] += $value;
        }
    }

    // ── Gauge ─────────────────────────────────────────────────────────

    public function set(string $name, float $value): void
    {
        if (array_key_exists($name, $this->gauges)) {
            $this->gauges[$name] = $value;
        }
    }

    public function add(string $name, float $value): void
    {
        if (isset($this->gauges[$name])) {
            $this->gauges[$name] += $value;
        }
    }

    // ── Histogram ─────────────────────────────────────────────────────

    public function observe(string $name, float $value): void
    {
        if (isset($this->histograms[$name])) {
            $this->histograms[$name][] = $value;
        }
    }

    // ── Export ─────────────────────────────────────────────────────────

    /**
     * Render all metrics in Prometheus text exposition format.
     */
    public function render(): string
    {
        $lines = [];

        foreach ($this->counters as $name => $value) {
            if (isset($this->help[$name]) && $this->help[$name] !== '') {
                $lines[] = "# HELP {$name} {$this->help[$name]}";
            }
            $lines[] = "# TYPE {$name} counter";
            $lines[] = "{$name} {$value}";
        }

        foreach ($this->gauges as $name => $value) {
            if (isset($this->help[$name]) && $this->help[$name] !== '') {
                $lines[] = "# HELP {$name} {$this->help[$name]}";
            }
            $lines[] = "# TYPE {$name} gauge";
            $lines[] = "{$name} {$value}";
        }

        foreach ($this->histograms as $name => $observations) {
            if (isset($this->help[$name]) && $this->help[$name] !== '') {
                $lines[] = "# HELP {$name} {$this->help[$name]}";
            }
            $lines[] = "# TYPE {$name} summary";

            $count = count($observations);
            $sum = array_sum($observations);
            $lines[] = "{$name}_count {$count}";
            $lines[] = "{$name}_sum {$sum}";

            if ($count > 0) {
                sort($observations);
                $lines[] = "{$name}{quantile=\"0.5\"} " . $this->percentile($observations, 0.5);
                $lines[] = "{$name}{quantile=\"0.9\"} " . $this->percentile($observations, 0.9);
                $lines[] = "{$name}{quantile=\"0.99\"} " . $this->percentile($observations, 0.99);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Get all metrics as arrays (for JSON endpoints).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->counters as $name => $value) {
            $result[$name] = ['type' => 'counter', 'value' => $value];
        }

        foreach ($this->gauges as $name => $value) {
            $result[$name] = ['type' => 'gauge', 'value' => $value];
        }

        foreach ($this->histograms as $name => $observations) {
            $result[$name] = [
                'type' => 'histogram',
                'count' => count($observations),
                'sum' => array_sum($observations),
            ];
        }

        return $result;
    }

    private function percentile(array $sorted, float $p): float
    {
        $index = (int)ceil(count($sorted) * $p) - 1;
        return $sorted[max(0, $index)];
    }
}
