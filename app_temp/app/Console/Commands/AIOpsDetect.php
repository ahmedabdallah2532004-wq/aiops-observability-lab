<?php

namespace App\Console\Commands;

use App\Services\PrometheusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AIOpsDetect extends Command
{
    protected $signature = 'aiops:detect';
    protected $description = 'AIOps Detection Engine for system health monitoring';

    protected PrometheusClient $prometheus;
    protected string $incidentPath;
    protected array $activeIncidents = [];
    protected array $baselines = [];

    public function __construct(PrometheusClient $prometheus)
    {
        parent::__construct();
        $this->prometheus = $prometheus;
        $this->incidentPath = storage_path('aiops/incidents.json');
    }

    public function handle()
    {
        $this->info("AIOps Detection Engine started...");
        File::ensureDirectoryExists(storage_path('aiops'));

        // Load existing status to keep track of active incidents (for deduplication)
        $this->loadActiveIncidents();

        while (true) {
            $this->info("[" . now() . "] Evaluating system health...");

            try {
                $this->evaluate();
            } catch (\Exception $e) {
                $this->error("Error during evaluation: " . $e->getMessage());
            }

            $sleepTime = rand(20, 30);
            $this->info("Sleeping for {$sleepTime} seconds...");
            sleep($sleepTime);
        }
    }

    protected function evaluate()
    {
        // 1. Fetch current metrics
        $currentRates = $this->prometheus->getRequestRates();
        $errorRates = $this->prometheus->getErrorRates();
        $latencies = $this->prometheus->getLatencyPercentiles();
        
        // 2. Compute/Fetch Baselines (Average over last 5m)
        $baselineRates = $this->fetchBaselines('rate(http_requests_total[5m])');
        $baselineLatencies = $this->fetchBaselines('histogram_quantile(0.95, sum by (le, path) (rate(http_request_duration_seconds_bucket[5m])))');
        // Error rate baseline is usually 0, but let's fetch it too
        $baselineErrors = $this->fetchBaselines('sum by (path) (rate(http_requests_total{status!~"2.."}[5m])) / sum by (path) (rate(http_requests_total[5m]))');

        $this->info("Current Metrics Trace:");
        foreach ($currentRates as $path => $rate) {
            $this->line(" - $path: Rate=".round($rate,2).", Error=".round(($errorRates[$path] ?? 0)*100, 2)."%, Latency=".round(($latencies[$path] ?? 0), 3)."s");
        }

        // 3. Detect Abnormal Signals
        $signals = [];
        $endpoints = array_unique(array_merge(array_keys($currentRates), array_keys($errorRates), array_keys($latencies)));

        foreach ($endpoints as $path) {
            if (str_contains($path, 'metrics')) continue;

            $currRate = $currentRates[$path] ?? 0;
            $currErr = $errorRates[$path] ?? 0;
            $currLat = $latencies[$path] ?? 0;

            $baseRate = $baselineRates[$path] ?? 0.01; // Avoid div by zero
            $baseLat = $baselineLatencies[$path] ?? 0.05;
            $baseErr = $baselineErrors[$path] ?? 0;

            // Traffic Anomaly: > 2x baseline
            if ($currRate > 2 * $baseRate && $currRate > 0.5) { // 0.5 req/s threshold to avoid noise
                $signals[] = [
                    'endpoint' => $path,
                    'type' => 'TRAFFIC_SURGE',
                    'observed' => $currRate,
                    'baseline' => $baseRate,
                    'severity' => 'MEDIUM'
                ];
            }

            // Latency Anomaly: > 3x baseline
            if ($currLat > 3 * $baseLat && $currLat > 0.1) {
                $signals[] = [
                    'endpoint' => $path,
                    'type' => 'LATENCY_SPIKE',
                    'observed' => $currLat,
                    'baseline' => $baseLat,
                    'severity' => 'HIGH'
                ];
            }

            // Error Rate Anomaly: > 10%
            if ($currErr > 0.10) {
                $signals[] = [
                    'endpoint' => $path,
                    'type' => 'ERROR_STORM',
                    'observed' => $currErr,
                    'baseline' => $baseErr,
                    'severity' => 'CRITICAL'
                ];
            }
        }

        // 4. Correlate Signals
        if (!empty($signals)) {
            $this->correlateAndGenerateIncidents($signals);
        } else {
            $this->info("No anomalies detected.");
        }
    }

    protected function correlateAndGenerateIncidents(array $signals)
    {
        $endpoints = array_unique(array_column($signals, 'endpoint'));
        $types = array_unique(array_column($signals, 'type'));
        
        $incidentType = 'LOCALIZED_ENDPOINT_FAILURE';
        $severity = 'LOW';

        if (count($endpoints) > 2) {
            $incidentType = 'SERVICE_DEGRADATION';
            $severity = 'HIGH';
        }

        if (in_array('ERROR_STORM', $types)) {
            $incidentType = 'ERROR_STORM';
            $severity = 'CRITICAL';
        } elseif (in_array('LATENCY_SPIKE', $types) && count($endpoints) > 1) {
            $incidentType = 'LATENCY_SPIKE';
            $severity = 'HIGH';
        } elseif (in_array('TRAFFIC_SURGE', $types)) {
            $incidentType = 'TRAFFIC_SURGE';
            $severity = 'MEDIUM';
        }

        $incidentId = Str::uuid()->toString();
        
        // Simple deduplication: if an active incident of the same type and endpoints exists, don't re-alert
        // In a real system, we'd check if the situation is ongoing or "resolved".
        $signature = $incidentType . '|' . implode(',', $endpoints);
        if (isset($this->activeIncidents[$signature])) {
            $this->comment("Ongoing incident detected: {$incidentType} on " . implode(', ', $endpoints) . ". Alert suppressed.");
            return;
        }

        $baselineValues = [];
        $observedValues = [];
        foreach ($signals as $signal) {
            $baselineValues[$signal['endpoint']] = $signal['baseline'];
            $observedValues[$signal['endpoint']] = $signal['observed'];
        }

        $incident = [
            'incident_id' => $incidentId,
            'incident_type' => $incidentType,
            'severity' => $severity,
            'status' => 'OPEN',
            'detected_at' => now()->toIso8601String(),
            'affected_service' => config('app.name', 'Laravel'),
            'affected_endpoints' => $endpoints,
            'triggering_signals' => $signals,
            'baseline_values' => $baselineValues,
            'observed_values' => $observedValues,
            'summary' => "Detected {$incidentType} affecting " . count($endpoints) . " endpoints."
        ];

        $this->saveIncident($incident);
        $this->activeIncidents[$signature] = $incidentId;
        
        $this->emitAlert($incident);
    }

    protected function fetchBaselines(string $query): array
    {
        // Simple wrapper for Prometheus query to format it for baselines
        $results = $this->prometheus->query($query);
        $formatted = [];
        foreach ($results as $res) {
            $path = $res['metric']['path'] ?? 'unknown';
            $val = (float) ($res['value'][1] ?? 0);
            $formatted[$path] = $val;
        }
        return $formatted;
    }

    protected function saveIncident(array $incident)
    {
        $existing = [];
        if (File::exists($this->incidentPath)) {
            $content = File::get($this->incidentPath);
            $existing = json_decode($content, true) ?: [];
        }
        
        $existing[] = $incident;
        File::put($this->incidentPath, json_encode($existing, JSON_PRETTY_PRINT));
    }

    protected function loadActiveIncidents()
    {
        // For simplicity, we just keep them in memory for the run.
        // In a persistent system, we'd read the status from a file/DB.
        $this->activeIncidents = [];
    }

    protected function emitAlert(array $incident)
    {
        $this->warn("\n!!! ALERT: INCIDENT DETECTED !!!");
        $this->warn("ID: {$incident['incident_id']}");
        $this->warn("Type: {$incident['incident_type']}");
        $this->warn("Severity: {$incident['severity']}");
        $this->warn("Summary: {$incident['summary']}");
        $this->warn("Endpoints: " . implode(', ', $incident['affected_endpoints']));

        // JSON alert to console as required
        $this->line(json_encode([
            'alert' => 'new_incident',
            'data' => $incident
        ]));
    }
}
