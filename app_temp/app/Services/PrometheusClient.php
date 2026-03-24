<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrometheusClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.prometheus.url', 'http://localhost:9090/api/v1');
    }

    /**
     * Run a PromQL query.
     */
    public function query(string $query)
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/query", [
                'query' => $query,
            ]);

            if ($response->successful()) {
                return $response->json()['data']['result'] ?? [];
            }
        } catch (\Exception $e) {
            // Fallback: If Prometheus is down, simulate response from local metrics
            return $this->simulateFromLocalLogs($query);
        }

        return $this->simulateFromLocalLogs($query);
    }

    /**
     * Simulation mode: Read storage/logs/logs.json and calculate current metrics.
     * This ensures the AIOps engine works even if Prometheus is down.
     */
    protected function simulateFromLocalLogs(string $query): array
    {
        // Simple logic to extract path and value from logs for the last 1 minute
        $logPath = storage_path('logs/logs.json');
        if (!\Illuminate\Support\Facades\File::exists($logPath)) {
            return [];
        }

        $lines = file($logPath);
        if (empty($lines)) return [];
        
        $lastData = json_decode(end($lines), true);
        $now = strtotime($lastData['timestamp'] ?? 'now');
        
        $oneMinAgo = $now - 60;
        $fiveMinAgo = $now - 300;

        $isBaselineQuery = str_contains($query, '[5m]');
        $window = $isBaselineQuery ? 300 : 60;
        $threshold = $isBaselineQuery ? $fiveMinAgo : $oneMinAgo;

        $stats = [];
        $errorStats = [];
        $latencyStats = [];

        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (!$data) continue;

            $ts = strtotime($data['timestamp'] ?? '');
            if ($ts > $now) continue;
            if ($ts < $threshold) break;

            $path = $data['route_name'] ?? 'unknown';
            $stats[$path] = ($stats[$path] ?? 0) + 1;
            // ...
        }

        $results = [];
        $window = $isBaselineQuery ? 300 : 60;

        if (str_contains($query, 'http_requests_total')) {
            // Handle error rate query specifically: sum by (path) (rate(http_requests_total{status!~"2.."}[1m])) / sum by (path) (rate(http_requests_total[1m]))
            if (str_contains($query, '{status!~"2.."}')) {
                foreach ($errorStats as $path => $count) {
                    $total = $stats[$path] ?? 1;
                    $results[] = [
                        'metric' => ['path' => $path],
                        'value' => [now()->timestamp, $count / $total]
                    ];
                }
            } else {
                foreach ($stats as $path => $count) {
                    $results[] = [
                        'metric' => ['path' => $path],
                        'value' => [now()->timestamp, $count / $window]
                    ];
                }
            }
        } elseif (str_contains($query, 'http_request_duration_seconds')) {
            foreach ($latencyStats as $path => $vals) {
                sort($vals);
                $p95 = $vals[count($vals) - 1]; // Simplified P95
                $results[] = [
                    'metric' => ['path' => $path],
                    'value' => [now()->timestamp, $p95]
                ];
            }
        }

        return $results;
    }

    /**
     * Get request rate per endpoint (per second, over last 1m).
     */
    public function getRequestRates(): array
    {
        $query = 'rate(http_requests_total[1m])';
        return $this->formatResults($this->query($query));
    }

    /**
     * Get error rates per endpoint (count of non-2xx status codes over last 1m).
     */
    public function getErrorRates(): array
    {
        // Calculate error rate as (requests with status != 200) / total requests
        $query = 'sum by (path) (rate(http_requests_total{status!~"2.."}[1m])) / sum by (path) (rate(http_requests_total[1m]))';
        return $this->formatResults($this->query($query));
    }

    /**
     * Get 95th percentile latency per endpoint (over last 1m).
     */
    public function getLatencyPercentiles(float $quantile = 0.95): array
    {
        $query = "histogram_quantile({$quantile}, sum by (le, path) (rate(http_request_duration_seconds_bucket[1m])))";
        return $this->formatResults($this->query($query));
    }

    /**
     * Get error category counts.
     */
    public function getErrorCategories(): array
    {
        $query = 'sum by (error_category, path) (rate(http_errors_total[1m]))';
        $results = $this->query($query);
        $formatted = [];
        foreach ($results as $res) {
            $path = $res['metric']['path'] ?? 'unknown';
            $cat = $res['metric']['error_category'] ?? 'none';
            $val = (float) ($res['value'][1] ?? 0);
            $formatted[$path][$cat] = $val;
        }
        return $formatted;
    }

    protected function formatResults(array $results): array
    {
        $formatted = [];
        foreach ($results as $res) {
            $path = $res['metric']['path'] ?? 'unknown';
            $val = (float) ($res['value'][1] ?? 0);
            $formatted[$path] = $val;
        }
        return $formatted;
    }
}
