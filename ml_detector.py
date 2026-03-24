import pandas as pd
import numpy as np
from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler
import os

INPUT_FILE = "aiops_dataset.csv"
OUTPUT_FILE = "anomaly_predictions.csv"

def train_and_predict():
    if not os.path.exists(INPUT_FILE):
        print(f"Error: {INPUT_FILE} not found.")
        return

    df = pd.DataFrame(pd.read_csv(INPUT_FILE))
    
    # Feature selection for the model
    features = ['avg_latency', 'max_latency', 'latency_std', 'request_rate', 'error_rate', 'errors_per_window', 'endpoint_frequency']
    
    # Preprocessing
    scaler = StandardScaler()
    X = scaler.fit_transform(df[features])
    
    # Hard constraint: Train only on normal behavior
    # In our dataset, we use is_ground_truth_anomaly == 0 for normal
    X_train = scaler.transform(df[df['is_ground_truth_anomaly'] == 0][features])
    
    print(f"Training Isolation Forest on {len(X_train)} normal observations...")
    
    model = IsolationForest(contamination=0.05, random_state=42)
    model.fit(X_train)
    
    # Predict on the entire dataset
    # model.predict returns 1 for normal, -1 for anomaly
    # model.decision_function returns anomaly score (lower is more anomalous)
    
    df['anomaly_score'] = model.decision_function(X) # Higher score = more normal
    # We want anomaly_score to be "A score representing the degree of abnormality"
    # Usually, we invert it so higher is more anomalous for the user
    df['anomaly_score'] = -df['anomaly_score'] 
    
    # model.predict(X) returns -1 for anomaly, 1 for normal
    predictions = model.predict(X)
    df['is_anomaly'] = [1 if p == -1 else 0 for p in predictions]
    
    # Output: timestamp, anomaly_score, is_anomaly
    output_df = df[['timestamp', 'endpoint', 'anomaly_score', 'is_anomaly', 'is_ground_truth_anomaly']]
    
    output_df.to_csv(OUTPUT_FILE, index=False)
    print(f"Predictions saved to {OUTPUT_FILE}")
    
    # Quick Performance Check
    from sklearn.metrics import classification_report
    print("\nClassification Report (vs Ground Truth):")
    print(classification_report(df['is_ground_truth_anomaly'], df['is_anomaly']))

if __name__ == "__main__":
    train_and_predict()
