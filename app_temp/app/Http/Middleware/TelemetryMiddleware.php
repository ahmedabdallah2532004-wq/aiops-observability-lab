<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelemetryMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        
        $correlationId = $request->header('X-Request-Id', (string) Str::uuid());
        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('request_start_time', $startTime);

        $response = $next($request);

        $latency = round((microtime(true) - $startTime) * 1000, 2);
        
        if (method_exists($response, 'header')) {
            $response->header('X-Request-Id', $correlationId);
        }

        $errorCategory = $request->attributes->get('error_category', null);
        
        if ($latency > 4000 && !$errorCategory && $response->getStatusCode() < 400) {
            $errorCategory = \App\Exceptions\Handler::categorizeError(null, $latency);
            $request->attributes->set('error_category', $errorCategory);
        }
        
        $severity = ($response->getStatusCode() >= 400 || $errorCategory) ? 'error' : 'info';
        
        $telemetry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'method' => $request->method(),
            'correlation_id' => $correlationId,
            'latency_ms' => $latency,
            'client_ip' => $request->ip() ?? null,
            'user_agent' => $request->userAgent() ?? null,
            'query' => $request->getQueryString() ?? null,
            'payload_size_bytes' => (isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : strlen($request->getContent())),
            'response_size_bytes' => method_exists($response, 'getContent') ? strlen($response->getContent() ?? '') : 0,
            'route_name' => $request->route() ? $request->route()->getName() : $request->path(),
            'status_code' => $response->getStatusCode(),
            'error_category' => $errorCategory,
            'build_version' => env('BUILD_VERSION', '1.0.0'),
            'host' => gethostname(),
            'severity' => $severity,
        ];
        
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $logLine = json_encode($telemetry) . PHP_EOL;
        file_put_contents($logDir . '/logs.json', $logLine, FILE_APPEND | LOCK_EX);
        file_put_contents($logDir . '/aiops.log', $logLine, FILE_APPEND | LOCK_EX);

        return $response;
    }
}
