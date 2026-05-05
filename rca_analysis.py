import pandas as pd
import json
import matplotlib.pyplot as plt
import seaborn as sns
import numpy as np
from datetime import datetime

# Configuration
DATASET_PATH = 'aiops_dataset.csv'
PREDICTIONS_PATH = 'anomaly_predictions.csv'
ANOMALY_START = '2026-03-24 19:00:10'
ANOMALY_END = '2026-03-24 19:05:45'
REPORT_PATH = 'rca_report.json'
TIMELINE_PLOT_PATH = 'incident_timeline.png'
ERROR_PLOT_PATH = 'error_distribution.png'

def run_rca():
    print("Loading datasets...")
    df = pd.read_csv(DATASET_PATH)
    df['timestamp'] = pd.to_datetime(df['timestamp'])
    
    # 1. Incident Selection
    anomaly_window = df[(df['timestamp'] >= ANOMALY_START) & (df['timestamp'] <= ANOMALY_END)]
    normal_window = df[(df['timestamp'] < ANOMALY_START)]
    
    # 2. Signal Analysis & Endpoint Attribution
    print("Analyzing signals and performing endpoint attribution...")
    endpoints = df['endpoint'].unique()
    attribution_scores = {}
    signal_stats = {}
    
    for ep in endpoints:
        ep_anomaly = anomaly_window[anomaly_window['endpoint'] == ep]
        ep_normal = normal_window[normal_window['endpoint'] == ep]
        
        if ep_anomaly.empty or ep_normal.empty:
            continue
            
        # Calculate Z-Scores for attribution
        latency_mean = ep_normal['avg_latency'].mean()
        latency_std = ep_normal['avg_latency'].std() if ep_normal['avg_latency'].std() > 0 else 1
        latency_z = (ep_anomaly['avg_latency'].mean() - latency_mean) / latency_std
        
        error_mean = ep_normal['errors_per_window'].mean()
        error_std = ep_normal['errors_per_window'].std() if ep_normal['errors_per_window'].std() > 0 else 1
        error_z = (ep_anomaly['errors_per_window'].mean() - error_mean) / error_std
        
        traffic_mean = ep_normal['request_rate'].mean()
        traffic_std = ep_normal['request_rate'].std() if ep_normal['request_rate'].std() > 0 else 1
        traffic_z = (ep_anomaly['request_rate'].mean() - traffic_mean) / traffic_std
        
        # Combined score (weighted: errors and latency carry more weight in AIOps)
        attribution_scores[ep] = (error_z * 0.5) + (latency_z * 0.4) + (traffic_z * 0.1)
        
        signal_stats[ep] = {
            "latency_delta_ms": ep_anomaly['avg_latency'].mean() - latency_mean,
            "error_surge_count": ep_anomaly['errors_per_window'].sum() - (error_mean * len(ep_anomaly)),
            "traffic_increase_pct": ((ep_anomaly['request_rate'].mean() - traffic_mean) / traffic_mean) * 100 if traffic_mean > 0 else 0,
            "latency_z_score": latency_z,
            "error_z_score": error_z
        }

    # 3. Determine Root Cause
    root_cause_endpoint = max(attribution_scores, key=attribution_scores.get)
    primary_signal = "failure surge" if signal_stats[root_cause_endpoint]['error_z_score'] > signal_stats[root_cause_endpoint]['latency_z_score'] else "latency spike"
    
    # 4. Error Category Analysis
    print("Analyzing error categories...")
    error_cats = anomaly_window['error_category'].value_counts().to_dict()
    
    # 5. Incident Timeline Description
    timeline = {
        "normal_state": "System operating within baseline parameters. Latency < 2000ms for /api/slow, Error rate stable for /api/error.",
        "anomaly_start": ANOMALY_START,
        "peak_incident": str(anomaly_window.loc[anomaly_window['errors_per_window'].idxmax(), 'timestamp']) if not anomaly_window.empty else "N/A",
        "recovery": ANOMALY_END
    }
    
    # 6. Generate Report JSON
    report = {
        "incident_id": "INC-20260324-001",
        "root_cause_endpoint": root_cause_endpoint,
        "primary_signal": primary_signal,
        "supporting_evidence": {
            "attribution_score": attribution_scores[root_cause_endpoint],
            "endpoint_stats": signal_stats[root_cause_endpoint],
            "system_error_distribution": error_cats
        },
        "timeline": timeline,
        "confidence_score": min(0.99, 0.7 + (attribution_scores[root_cause_endpoint] / 100)), # Simplified confidence
        "recommended_action": f"Immediate: Scale resources for {root_cause_endpoint}. Long-term: Implement circuit breakers and investigate upstream dependencies."
    }
    
    with open(REPORT_PATH, 'w') as f:
        json.dump(report, f, indent=4)
    print(f"RCA Report saved to {REPORT_PATH}")

    # 7. Visualizations
    print("Generating visualizations...")
    
    # Plot 1: Timeline
    plt.figure(figsize=(12, 6))
    sns.lineplot(data=df, x='timestamp', y='avg_latency', hue='endpoint')
    plt.axvspan(pd.to_datetime(ANOMALY_START), pd.to_datetime(ANOMALY_END), color='red', alpha=0.2, label='Anomaly Window')
    plt.title('Incident Timeline: Latency Signals')
    plt.ylabel('Latency (ms)')
    plt.xlabel('Time')
    plt.xticks(rotation=45)
    plt.legend()
    plt.tight_layout()
    plt.savefig(TIMELINE_PLOT_PATH)
    
    # Plot 2: Error Distribution
    plt.figure(figsize=(10, 6))
    if error_cats:
        plt.pie(error_cats.values(), labels=error_cats.keys(), autopct='%1.1f%%', colors=sns.color_palette('viridis'))
        plt.title('Error Category Distribution during Incident')
    else:
        plt.text(0.5, 0.5, 'No errors detected', ha='center')
    plt.tight_layout()
    plt.savefig(ERROR_PLOT_PATH)
    
    print(f"Visualizations saved: {TIMELINE_PLOT_PATH}, {ERROR_PLOT_PATH}")

if __name__ == "__main__":
    run_rca()
