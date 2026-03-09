<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class MetricsController extends Controller
{
    public function metrics()
    {
        $logPath = storage_path('logs/logs.json');
        
        $requests = [];
        $errors = [];
        $histograms = [];
        $sums = [];
        $counts = [];
        
        $buckets = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, '+Inf'];

        if (!File::exists($logPath)) {
            return response('', 200)->header('Content-Type', 'text/plain; version=0.0.4');
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!$data || !isset($data['route_name']) || $data['route_name'] === 'unknown') {
                continue;
            }
            
            $method = $data['method'] ?? 'GET';
            $path = $data['route_name'];
            $status = $data['status_code'] ?? 200;
            $latencySec = ($data['latency_ms'] ?? 0) / 1000.0;
            $category = $data['error_category'] ?? null;
            
            $reqKey = "{$method}|{$path}|{$status}";
            $requests[$reqKey] = ($requests[$reqKey] ?? 0) + 1;
            
            if ($category) {
                $errKey = "{$method}|{$path}|{$category}";
                $errors[$errKey] = ($errors[$errKey] ?? 0) + 1;
            }
            
            $histKey = "{$method}|{$path}";
            $sums[$histKey] = ($sums[$histKey] ?? 0) + $latencySec;
            $counts[$histKey] = ($counts[$histKey] ?? 0) + 1;
            
            if (!isset($histograms[$histKey])) {
                $histograms[$histKey] = array_fill_keys(array_map('strval', $buckets), 0);
            }
            
            foreach ($buckets as $le) {
                if ($le === '+Inf' || $latencySec <= floatval($le)) {
                    $histograms[$histKey][strval($le)]++;
                }
            }
        }
        
        $out = "";
        
        $out .= "# HELP http_requests_total Total number of HTTP requests.\n";
        $out .= "# TYPE http_requests_total counter\n";
        foreach ($requests as $k => $v) {
            [$m, $p, $s] = explode('|', $k);
            if (str_contains($p, 'metrics')) continue;
            $out .= "http_requests_total{method=\"{$m}\",path=\"{$p}\",status=\"{$s}\"} {$v}\n";
        }
        
        $out .= "# HELP http_errors_total Total number of HTTP errors by category.\n";
        $out .= "# TYPE http_errors_total counter\n";
        foreach ($errors as $k => $v) {
            [$m, $p, $c] = explode('|', $k);
            if (str_contains($p, 'metrics')) continue;
            $out .= "http_errors_total{method=\"{$m}\",path=\"{$p}\",error_category=\"{$c}\"} {$v}\n";
        }
        
        $out .= "# HELP http_request_duration_seconds Histogram of HTTP request durations.\n";
        $out .= "# TYPE http_request_duration_seconds histogram\n";
        foreach ($histograms as $histKey => $bucks) {
            [$m, $p] = explode('|', $histKey);
            if (str_contains($p, 'metrics')) continue;
            
            foreach ($bucks as $le => $count) {
                $out .= "http_request_duration_seconds_bucket{method=\"{$m}\",path=\"{$p}\",le=\"{$le}\"} {$count}\n";
            }
            $out .= "http_request_duration_seconds_sum{method=\"{$m}\",path=\"{$p}\"} " . $sums[$histKey] . "\n";
            $out .= "http_request_duration_seconds_count{method=\"{$m}\",path=\"{$p}\"} " . $counts[$histKey] . "\n";
        }
        
        return response($out, 200)->header('Content-Type', 'text/plain; version=0.0.4');
    }
}
