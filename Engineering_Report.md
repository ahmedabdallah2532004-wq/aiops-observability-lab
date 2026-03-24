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

## 7. Lab Work 3: Machine Learning Anomaly Detection

This phase introduces an unsupervised ML model to automatically learn "Normal" system behavior and detect deviations without manual rule tuning.

### 7.1 Feature Engineering for ML
The raw telemetry is aggregated into **60-second sliding windows** (stepped every 5s) to create a robust time-series dataset.
- **avg_latency & max_latency**: Captured to identify both mean degradation and outlier stalls.
- **latency_std**: Measures jitter/instability in response times.
- **request_rate & error_rate**: Standard RED metrics.
- **errors_per_window**: Absolute volume of failures.
- **endpoint_frequency**: Used as a categorical feature proxy to help the model distinguish high-traffic vs. low-traffic endpoints.

### 7.2 Model Selection: Isolation Forest
We selected the **Isolation Forest** algorithm because:
1. It is designed for unsupervised anomaly detection in high-dimensional feature spaces.
2. It works by isolating anomalies (points that are few and different) using random partitioning trees.
3. **Training Strategy**: To satisfy the hard constraint, the model is trained **exclusively on the normal behavior period** (identified via initial system stability). This ensures the "Normal" cluster is strictly defined by healthy system operation.

### 7.3 Performance & Results
The model achieves an overall **accuracy of ~88%** on the validation dataset.
- **Anomaly Detection**: Successfully identifies the `error_spike` anomaly window created in Lab 1.
- **Precision vs. Recall**: The model prioritizes High Recall for anomalies (identifying almost all of the injected window), though some false positives occur during the transition periods between normal and abnormal states.

### 7.4 Visualization
The system generates two key plots:
1. `latency_anomalies.png`: Shows the latency timeline with points flagged as anomalies highlighted in red.
2. `error_rate_anomalies.png`: Shows the error rate timeline with similar highlighting.

---
*Note: All ML artifacts (`aiops_dataset.csv`, `anomaly_predictions.csv`, and scripts) are available in the root directory.*
