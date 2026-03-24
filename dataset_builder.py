import pandas as pd
import json
from datetime import datetime, timezone
import numpy as np
import os

LOG_FILE = "app_temp/storage/logs/logs.json"
GROUND_TRUTH = "ground_truth.json"
OUTPUT_FILE = "aiops_dataset.csv"

def build_dataset():
    if not os.path.exists(LOG_FILE):
        print(f"Error: {LOG_FILE} not found.")
        return

    data = []
    with open(LOG_FILE, 'r') as f:
        for line in f:
            data.append(json.loads(line))
            
    df = pd.DataFrame(data)
    df['timestamp'] = pd.to_datetime(df['timestamp'])
    
    # Load ground truth for labeling (evaluation only)
    with open(GROUND_TRUTH, 'r') as f:
        gt = json.load(f)
    gt_start = pd.to_datetime(gt['anomaly_start_iso'])
    gt_end = pd.to_datetime(gt['anomaly_end_iso'])

    endpoints = df['route_name'].unique()
    start_time = df['timestamp'].min()
    end_time = df['timestamp'].max()
    
    window_size = pd.Timedelta(seconds=60)
    step_size = pd.Timedelta(seconds=5)
    
    dataset_rows = []
    
    print(f"Processing {len(endpoints)} endpoints from {start_time} to {end_time}...")

    for ep in endpoints:
        ep_df = df[df['route_name'] == ep].sort_values('timestamp')
        
        current_time = start_time + window_size
        while current_time <= end_time:
            window_df = ep_df[(ep_df['timestamp'] > current_time - window_size) & (ep_df['timestamp'] <= current_time)]
            
            if not window_df.empty:
                total_reqs = len(window_df)
                errors = len(window_df[window_df['status_code'] >= 400])
                latency = window_df['latency_ms']
                
                # Check if this window overlaps with the ground truth anomaly
                # We'll mark it as anomaly if it's within the window
                is_anomaly = (current_time >= gt_start) and (current_time <= gt_end)
                
                # Get most frequent error category
                err_cats = window_df['error_category'].dropna()
                top_cat = err_cats.mode()[0] if not err_cats.empty else "NONE"

                dataset_rows.append({
                    'timestamp': current_time,
                    'endpoint': ep,
                    'avg_latency': latency.mean(),
                    'max_latency': latency.max(),
                    'latency_std': latency.std() if len(latency) > 1 else 0,
                    'request_rate': total_reqs / 60.0,
                    'error_rate': errors / total_reqs if total_reqs > 0 else 0,
                    'errors_per_window': errors,
                    'error_category': top_cat,
                    'is_ground_truth_anomaly': 1 if is_anomaly else 0
                })
            
            current_time += step_size

    dataset = pd.DataFrame(dataset_rows)
    
    # Calculate endpoint frequency
    ep_counts = df['route_name'].value_counts().to_dict()
    dataset['endpoint_frequency'] = dataset['endpoint'].map(ep_counts)
    
    dataset = dataset.fillna(0)
    dataset.to_csv(OUTPUT_FILE, index=False)
    print(f"Dataset built with {len(dataset)} observations and saved to {OUTPUT_FILE}")

if __name__ == "__main__":
    build_dataset()
