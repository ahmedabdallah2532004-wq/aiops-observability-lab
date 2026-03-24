import dataset_builder
import ml_detector
import plots_generator
import os

def run_pipeline():
    print("=== AIOps ML Pipeline Started ===")
    
    print("\nStep 1: Building Dataset...")
    dataset_builder.build_dataset()
    
    print("\nStep 2: Training Model and Predicting Anomalies...")
    ml_detector.train_and_predict()
    
    print("\nStep 3: Generating Visualizations...")
    plots_generator.generate_plots()
    
    print("\n=== Pipeline Completed Successfully ===")
    print("Deliverables generated:")
    print("- aiops_dataset.csv")
    print("- anomaly_predictions.csv")
    print("- latency_anomalies.png")
    print("- error_rate_anomalies.png")

if __name__ == "__main__":
    run_pipeline()
