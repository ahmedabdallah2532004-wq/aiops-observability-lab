<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AIOpsRespond extends Command
{
    protected $signature = 'aiops:respond {--once : Run once and exit}';
    protected $description = 'AIOps Automation Engine for incident response';

    protected string $incidentPath;
    protected string $responsePath;
    protected array $policies;

    public function __construct()
    {
        parent::__construct();
        $this->incidentPath = storage_path('aiops/incidents.json');
        $this->responsePath = config('aiops.storage_path', storage_path('aiops/responses.json'));
        $this->policies = config('aiops.policies', []);
    }

    public function handle()
    {
        $this->info("AIOps Automation Engine started...");
        File::ensureDirectoryExists(storage_path('aiops'));

        while (true) {
            $this->info("[" . now() . "] Checking for open incidents...");

            $incidents = $this->loadIncidents();
            $processedCount = 0;

            foreach ($incidents as &$incident) {
                if (($incident['status'] ?? 'OPEN') === 'OPEN') {
                    $this->processIncident($incident, $incidents);
                    $processedCount++;
                }
            }

            if ($processedCount > 0) {
                $this->saveIncidents($incidents);
            } else {
                $this->comment("No new incidents to process.");
            }

            if ($this->option('once')) {
                break;
            }

            $sleepTime = 15;
            $this->info("Waiting {$sleepTime} seconds for next check...");
            sleep($sleepTime);
        }
    }

    protected function processIncident(array &$incident, array $allIncidents)
    {
        $type = $incident['incident_type'];
        $id = $incident['incident_id'];
        $policy = $this->policies[$type] ?? null;

        $this->warn("\nProcessing Incident: {$id} ({$type})");

        if (!$policy) {
            $this->error("No policy defined for incident type: {$type}. Defaulting to escalation.");
            $action = 'incident_escalation';
        } else {
            $action = $policy['action'];
        }

        // Check for escalation need (anomaly persists or high severity)
        if ($this->shouldEscalate($incident, $allIncidents)) {
            $action = 'incident_escalation';
            $this->error("Conditions met for escalation!");
        }

        $this->info("Executing action: {$action}...");
        
        // Simulate execution
        $result = $this->simulateAction($action, $incident);

        // Record response
        $this->logResponse($incident, $action, $result);

        // Update status
        $incident['status'] = $result['success'] ? 'RESPONDED' : 'FAILED';
        if ($action === 'incident_escalation') {
            $incident['status'] = 'ESCALATED';
            $incident['severity'] = config('aiops.escalation.critical_severity', 'CRITICAL_ALERT');
        }
    }

    protected function simulateAction(string $action, array $incident): array
    {
        // Randomly simulate failure (10% chance) except for escalation which always "succeeds" in sending the alert
        $success = ($action === 'incident_escalation') || (rand(1, 100) > 10);
        
        $notes = "Successfully executed {$action} simulation.";
        if (!$success) {
            $notes = "Failed to execute {$action}. Resource locked or timeout.";
        }

        return [
            'success' => $success,
            'notes' => $notes,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected function logResponse(array $incident, string $action, array $result)
    {
        $response = [
            'incident_id' => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'action_taken' => $action,
            'timestamp' => $result['timestamp'],
            'result' => $result['success'] ? 'SUCCESS' : 'FAILURE',
            'notes' => $result['notes'],
        ];

        $existing = [];
        if (File::exists($this->responsePath)) {
            $content = File::get($this->responsePath);
            $existing = json_decode($content, true) ?: [];
        }

        $existing[] = $response;
        File::put($this->responsePath, json_encode($existing, JSON_PRETTY_PRINT));
        
        $this->info("Response logged to storage/aiops/responses.json");
    }

    protected function shouldEscalate(array $incident, array $allIncidents): bool
    {
        // 1. If severity is already CRITICAL
        if ($incident['severity'] === 'CRITICAL') {
            return true;
        }

        // 2. If the same incident type occurred recently multiple times
        $threshold = config('aiops.escalation.threshold', 3);
        $windowMinutes = config('aiops.escalation.window_minutes', 10);
        $now = now();

        $recentCount = 0;
        foreach ($allIncidents as $pastIncident) {
            if ($pastIncident['incident_type'] === $incident['incident_type']) {
                $pastTime = \Illuminate\Support\Carbon::parse($pastIncident['detected_at']);
                if ($now->diffInMinutes($pastTime) <= $windowMinutes) {
                    $recentCount++;
                }
            }
        }

        if ($recentCount >= $threshold) {
            $this->comment("Persistent anomaly detected: {$recentCount} occurrences in {$windowMinutes} mins.");
            return true;
        }

        return false;
    }

    protected function loadIncidents(): array
    {
        if (!File::exists($this->incidentPath)) {
            return [];
        }
        return json_decode(File::get($this->incidentPath), true) ?: [];
    }

    protected function saveIncidents(array $incidents)
    {
        File::put($this->incidentPath, json_encode(array_values($incidents), JSON_PRETTY_PRINT));
    }
}
