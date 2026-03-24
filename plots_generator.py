import pandas as pd
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
import os

DATASET_FILE = "aiops_dataset.csv"
PREDICTIONS_FILE = "anomaly_predictions.csv"

def generate_plots():
    if not os.path.exists(DATASET_FILE) or not os.path.exists(PREDICTIONS_FILE):
        print("Error: Missing data files.")
        return

    df = pd.read_csv(DATASET_FILE)
    df['timestamp'] = pd.to_datetime(df['timestamp'])
    
    preds = pd.read_csv(PREDICTIONS_FILE)
    # Merge is_anomaly back to dataset
    df['is_anomaly'] = preds['is_anomaly']
    
    # We'll plot the AGGREGATE system behavior (mean of endpoints) for clarity
    system_df = df.groupby('timestamp').agg({
        'avg_latency': 'mean',
        'error_rate': 'mean',
        'is_anomaly': 'max' # If any endpoint is anomaly, mark window as anomaly
    }).reset_index()

    # 1. Latency Timeline
    plt.figure(figsize=(12, 6))
    plt.plot(system_df['timestamp'], system_df['avg_latency'], label='Avg Latency (ms)', color='blue')
    
    # Highlight anomalies
    anomalies = system_df[system_df['is_anomaly'] == 1]
    plt.scatter(anomalies['timestamp'], anomalies['avg_latency'], color='red', label='Anomaly Detected', zorder=5)
    
    plt.title('System Latency Timeline with ML Anomalies')
    plt.xlabel('Timestamp')
    plt.ylabel('Latency (ms)')
    plt.legend()
    plt.grid(True)
    plt.savefig('latency_anomalies.png')
    print("Saved latency_anomalies.png")

    # 2. Error Rate Timeline
    plt.figure(figsize=(12, 6))
    plt.plot(system_df['timestamp'], system_df['error_rate'], label='Error Rate (%)', color='green')
    
    # Highlight anomalies
    plt.scatter(anomalies['timestamp'], anomalies['error_rate'], color='red', label='Anomaly Detected', zorder=5)
    
    plt.title('System Error Rate Timeline with ML Anomalies')
    plt.xlabel('Timestamp')
    plt.ylabel('Error Rate')
    plt.legend()
    plt.grid(True)
    plt.savefig('error_rate_anomalies.png')
    print("Saved error_rate_anomalies.png")

if __name__ == "__main__":
    generate_plots()
