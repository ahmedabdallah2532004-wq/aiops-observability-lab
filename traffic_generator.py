import time
import json
import random
import uuid
from datetime import datetime
import os

TOTAL_REQUESTS = 3600
ANOMALY_START_REQ = 1000
ANOMALY_END_REQ = 2500

LOG_FILE = "storage/logs/logs.json"
AIOPS_LOG = "storage/logs/aiops.log"

os.makedirs("storage/logs", exist_ok=True)

def get_base_distribution():
    return [
        ("normal", 70), ("slow", 15), ("slow_hard", 5), ("error", 5),
        ("db", 1.5), ("db_fail", 1.5), ("validate_valid", 1), ("validate_invalid", 1)
    ]

def get_anomaly_distribution():
    return [
        ("normal", 40), ("slow", 10), ("slow_hard", 5), ("error", 40),
        ("db", 1.5), ("db_fail", 1.5), ("validate_valid", 1), ("validate_invalid", 1)
    ]

def pick_endpoint(distribution):
    rand = random.uniform(0, 100)
    cumulative = 0
    for ep, prob in distribution:
        cumulative += prob
        if rand <= cumulative:
            return ep
    return distribution[0][0]

def simulate_request(endpoint, timestamp):
    latency = 0
    status_code = 200
    error_category = None
    route_name = "/api/" + endpoint.split('_')[0] if '_' in endpoint else "/api/" + endpoint
    method = "GET"
    if "validate" in endpoint:
        method = "POST"
        
    if endpoint == "normal":
        latency = random.randint(10, 50)
    elif endpoint == "slow":
        latency = random.randint(100, 500)
    elif endpoint == "slow_hard":
        latency = random.randint(5000, 7000)
        error_category = "TIMEOUT_ERROR"
    elif endpoint == "error":
        latency = random.randint(20, 100)
        status_code = 500
        error_category = "SYSTEM_ERROR"
    elif endpoint == "db":
        latency = random.randint(50, 150)
    elif endpoint == "db_fail":
        latency = random.randint(20, 60)
        status_code = 500
        error_category = "DATABASE_ERROR"
    elif endpoint == "validate_valid":
        latency = random.randint(10, 30)
    elif endpoint == "validate_invalid":
        latency = random.randint(5, 20)
        status_code = 422
        error_category = "VALIDATION_ERROR"
        
    severity = "error" if status_code >= 400 or error_category else "info"
    
    return {
        "timestamp": timestamp,
        "method": method,
        "correlation_id": str(uuid.uuid4()),
        "latency_ms": latency,
        "client_ip": f"192.168.1.{random.randint(1, 254)}",
        "user_agent": "python-requests/2.31.0",
        "query": "fail=1" if "fail" in endpoint else None,
        "payload_size_bytes": random.randint(50, 200) if method == "POST" else 0,
        "response_size_bytes": random.randint(100, 500),
        "route_name": route_name,
        "status_code": status_code,
        "error_category": error_category,
        "build_version": "1.0.0",
        "host": "local-machine",
        "severity": severity
    }

print("Generating logs...")
start_time = datetime.utcnow().timestamp() - 7200
anomaly_start_iso = None
anomaly_end_iso = None

with open(LOG_FILE, "w") as f_json, open(AIOPS_LOG, "w") as f_log:
    for i in range(TOTAL_REQUESTS):
        current_time_sec = start_time + (i * 0.2) # 5 RPS
        ts_iso = datetime.utcfromtimestamp(current_time_sec).isoformat() + "Z"
        
        in_anomaly = ANOMALY_START_REQ <= i <= ANOMALY_END_REQ
        if in_anomaly and anomaly_start_iso is None:
            anomaly_start_iso = ts_iso
        if not in_anomaly and anomaly_start_iso is not None and anomaly_end_iso is None:
            anomaly_end_iso = ts_iso
            
        dist = get_anomaly_distribution() if in_anomaly else get_base_distribution()
        ep = pick_endpoint(dist)
        
        log_entry = simulate_request(ep, ts_iso)
        line = json.dumps(log_entry) + "\n"
        
        f_json.write(line)
        f_log.write(line)

print("Logs generated.")

ground_truth = {
    "anomaly_start_iso": anomaly_start_iso,
    "anomaly_end_iso": anomaly_end_iso,
    "anomaly_type": "error_spike",
    "expected_behavior": "Spike in /api/error to 40% of total traffic, causing increased error rates and system instability."
}

with open("ground_truth.json", "w") as f:
    json.dump(ground_truth, f, indent=2)

print("Wrote ground_truth.json")
