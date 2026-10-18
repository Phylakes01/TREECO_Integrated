T.R.E.E.C.O. - Model Validation & Confusion Matrix Images
===========================================================
Generated on: 2026-10-18 04:21:25

FILES GENERATED:
1. confusion_matrix.png
   - 3x3 Heatmap of Actual vs Predicted yield categories
   - Accuracy: 85.60% | F1-Score: 85.52%
   - Demonstrates zero (0) extreme errors between Low and High tiers.

2. f1_score_and_metrics.png
   - Grouped bar chart comparing Precision, Recall, and F1-Score
   - Per-class evaluation (Low, Medium, High Yield) and Weighted Average.

3. feature_importance.png
   - Horizontal bar chart of soil parameter weights (pH, Moisture, Temp, N, P, K).

4. model_validation_dashboard.png
   - Composite 4-panel executive summary for thesis documentation and presentations.

HOW TO REGENERATE:
Run in command line:
python generate_validation_images.py
