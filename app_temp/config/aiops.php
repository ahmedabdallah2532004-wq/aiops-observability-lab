<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Response Policies
    |--------------------------------------------------------------------------
    |
    | Define automated responses for each incident type.
    |
    */
    'policies' => [
        'LATENCY_SPIKE' => [
            'action' => 'restart_service',
            'description' => 'Restarting the affected service to clear potential resource bottlenecks.',
        ],
        'ERROR_STORM' => [
            'action' => 'send_alert',
            'description' => 'High error rate detected. Sending critical alerts to the SRE team.',
        ],
        'TRAFFIC_SURGE' => [
            'action' => 'scale_service',
            'description' => 'Unusual traffic spike. Scaling up resources to maintain availability.',
        ],
        'SERVICE_DEGRADATION' => [
            'action' => 'escalate',
            'description' => 'Multiple symptoms detected. Escalating to senior engineers.',
        ],
        'LOCALIZED_ENDPOINT_FAILURE' => [
            'action' => 'traffic_throttling',
            'description' => 'Throttling traffic to the affected endpoint to prevent cascading failure.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation Logic
    |--------------------------------------------------------------------------
    |
    | Configuration for automated escalation.
    |
    */
    'escalation' => [
        'enabled' => true,
        'threshold' => 3, // Escalate if the same incident type occurs 3 times in the window.
        'window_minutes' => 10,
        'critical_severity' => 'CRITICAL_ALERT',
    ],

    'storage_path' => storage_path('aiops/responses.json'),
];
