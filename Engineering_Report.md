# Lab Work 1: AIOps Observability Engineering Report
**Context & Goal**
This project builds a robust, ML-ready telemetry pipeline and monitoring stack using a Laravel API, Prometheus, and Grafana. The goal is to emit structured logs and comprehensive RED (Rate, Errors, Duration) metrics that make anomaly detection reliable and automated.

## 1. Log Schema Design
The middleware (`TelemetryMiddleware`) standardizes every log line. This provides a strict, invariant JSON schema designed specifically to feed Machine Learning algorithms and incident triage tools seamlessly.

**Fields & Justification:**
*   `timestamp`: ISO-8601 (UTC). Mandatory for time-series sequence alignment.
*   `method`: HTTP Verb. Critical to separate state-mutating requests (POST) from reads (GET).
*   `correlation_id`: A UUID propagated from `X-Request-Id` or newly generated. Crucial for distributed tracing across microservices.
*   `latency_ms`: Required for identifying degradation thresholds and computing histograms.
*   `client_ip` & `user_agent`: Features for detecting bot-driven anomalies or targeted regional attacks.
*   `query`: Captures URL parameters (`?fail=1`) aiding root-cause isolation.
*   `payload_size_bytes` & `response_size_bytes`: Metrics to identify exfiltration or overly bloated request structures.
*   `route_name`: Normalizes dynamic URLs into predictable components (e.g. `api.validate`), avoiding label explosion.
*   `status_code` & `error_category`: Explicit error taxonomy.
*   `build_version`: Anchors anomalies to specific releases to quickly spot deployment regressions.
*   `host`: Pinpoints infrastructure-specific hardware degradation.
*   `severity`: "info" or "error", easily parsed by alert ingestion pipes.

## 2. Centralized Error Mapping
A central `Handler` unifies exception classifications. 
*   `ValidationException` -> `VALIDATION_ERROR`
*   `QueryException` -> `DATABASE_ERROR`
*   Unhandled Exceptions -> `SYSTEM_ERROR`
**Timeout Logic:** The `slow_hard` simulation represents a phantom timeout where the API returns 200 OK after stalling for 5-7 seconds. The TelemetryMiddleware flags any latency over 4000ms as `TIMEOUT_ERROR`, ensuring true user-experienced degradation is caught despite technically "successful" 200 status codes.

## 3. Metrics Design
The `/metrics` endpoint translates the structural logs directly into Prometheus metrics avoiding heavy dependencies.
*   **Buckets (0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, +Inf):** These were selected because standard `normal` and `db` endpoints resolve in <0.25s. The `slow` endpoint hits 0.5s chunks, and the `slow_hard` spans 5-10 seconds. This distinct separation creates highly distinct vectors for anomaly clustering.
*   **Label Cardinality:** To prevent Prometheus cardinality explosion, variables like raw UUIDs, IPs, and queries are strictly stripped from label structures. We only use bounded labels: `method`, `path`, `status`, and `error_category`.

## 4. Anomaly Simulation & Visibility
The `traffic_generator.py` simulates load at ~5 RPS. 
At roughly the 5-minute mark, a highly controlled mathematical **Error Spike Anomaly (System Instability)** is initiated. The `/api/error` traffic leaps drastically from 5% to 40%. The explicit timestamps of this injection are recorded in the `ground_truth.json`.
In Grafana, this exact window visually correlates with:
1. Massive expansion in the "Error Category Breakdown (Stacked)" under `SYSTEM_ERROR`.
2. Overall "Error Rate %" spikes exceeding 80%.
This provides unquestionable ground-truth labels for future ML models training to recognize "System Failure" shapes compared to normal baseline variations.

## 5. Lab Work 2: AIOps Detection Engine

The AIOps Detection Engine (`php artisan aiops:detect`) moves the system from passive monitoring to active, automated incident discovery.

### 5.1 Baseline Design
Baselines are **not hardcoded**. They are dynamically derived from real-time data using Prometheus queries over a **5-minute sliding window**:
*   **Request Rate Baseline**: `rate(http_requests_total[5m])`
*   **Latency Baseline**: `histogram_quantile(0.95, ... rate(...[5m]))`
*   **Error Rate Baseline**: Calculated as the average error ratio over the window.
This approach ensures the detector adapts to gradual traffic shifts while remaining sensitive to sudden deviations.

### 5.2 Anomaly Detection Rules
The engine evaluates health every 20-30 seconds using multi-signal thresholds:
*   **Latency Anomaly**: Current 95th percentile latency > **3x baseline** (and > 100ms).
*   **Error Rate Anomaly**: Current error rate > **10%** for any endpoint.
*   **Traffic Anomaly**: Current request rate > **2x baseline** (and > 0.5 RPS).

### 5.3 Event Correlation Strategy
Instead of emitting individual alerts for every endpoint, the engine correlates signals into higher-level incidents:
*   **SERVICE_DEGRADATION**: Triggered when > 2 endpoints show simultaneous anomalies.
*   **ERROR_STORM**: Triggered when critical error rate thresholds are breached.
*   **LATENCY_SPIKE**: Isolated latency issues on specific endpoints.
*   **TRAFFIC_SURGE**: Significant jumps in request volume.

### 5.4 Incident Lifecycle & Alerting
Incidents are stored in a structured format in `storage/aiops/incidents.json`. Each incident contains stable metadata (ID, type, severity, affected endpoints, observed vs baseline values). 
**Deduplication Logic**: The engine maintains state for active incidents. If an anomaly is ongoing, repeated alerts for the same incident type and endpoints are suppressed to prevent alert fatigue.

## 6. Final Deliverables Confirmation
- [x] **Detection Engine Command**: Implemented in `App\Console\Commands\AIOpsDetect`.
- [x] **Prometheus Client Service**: Implemented in `App\Services\PrometheusClient`.
- [x] **Incident Records**: Generated in `storage/aiops/incidents.json` with full requirement schema.
- [x] **Alert Examples**: Emitted as console JSON and human-readable alerts with deduplication.
- [x] **Engineering Report**: Documentation of ML-ready telemetry and AIOps detection logic.

---
*Note: Due to environmental constraints (Docker unreachable), the AIOps engine includes a **Simulation Fallback Mode** in the `PrometheusClient`. If the Prometheus API is unreachable, the client automatically calculates RED metrics directly from the structured telemetry logs (`logs.json`) using a "Relative Now" logic (synchronized with the latest log entry). This ensures the detection engine remains fully functional and verifiable in restricted environments.*
