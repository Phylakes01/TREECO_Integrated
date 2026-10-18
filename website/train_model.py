import json
import os
import time
import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_val_score, KFold
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import (r2_score, mean_absolute_error,
                             root_mean_squared_error, confusion_matrix,
                             classification_report, f1_score,
                             precision_score, recall_score, accuracy_score)
import joblib

script_dir = os.path.dirname(os.path.abspath(__file__))
csv_path = os.path.join(script_dir, 'crop_yield_data.csv')
model_path = os.path.join(script_dir, 'treeco_rf_model.pkl')
metrics_path = os.path.join(script_dir, 'model_metrics.json')

print("1. Loading dataset from:", csv_path)
df = pd.read_csv(csv_path)

# Features and target
feature_cols = ['N', 'P', 'K', 'pH', 'Moisture', 'Temp']
X = df[feature_cols]
y = df['Yield_kg']

print(f"2. Splitting {len(df)} samples into train and test sets (80/20)...")
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.20, random_state=42)

print("3. Training Optimized Random Forest Regressor...")
model = RandomForestRegressor(
    n_estimators=150,
    max_depth=16,
    min_samples_split=3,
    min_samples_leaf=1,
    random_state=42,
    n_jobs=-1
)
model.fit(X_train, y_train)

print("4. Evaluating model performance...")
y_pred_train = model.predict(X_train)
y_pred_test = model.predict(X_test)

r2_train = float(r2_score(y_train, y_pred_train))
r2_test = float(r2_score(y_test, y_pred_test))
mae_test = float(mean_absolute_error(y_test, y_pred_test))
rmse_test = float(root_mean_squared_error(y_test, y_pred_test))

# 5-fold Cross-Validation
cv = KFold(n_splits=5, shuffle=True, random_state=42)
cv_scores = cross_val_score(model, X, y, cv=cv, scoring='r2')
cv_r2_mean = float(np.mean(cv_scores))
cv_r2_std = float(np.std(cv_scores))

# Feature importances
importances = {col: round(float(imp), 4) for col, imp in zip(feature_cols, model.feature_importances_)}

# ============================================================
# 5. CLASSIFICATION-BASED VALIDATION (Confusion Matrix & F1)
# ============================================================
# Bin continuous yield predictions into agronomic categories
# to generate confusion matrix, F1, precision, recall metrics
# ============================================================
yield_bins = [0, 60, 100, 999]
yield_labels = ['Low Yield', 'Medium Yield', 'High Yield']

y_test_class = pd.cut(y_test, bins=yield_bins, labels=yield_labels, include_lowest=True)
y_pred_class = pd.cut(pd.Series(y_pred_test), bins=yield_bins, labels=yield_labels, include_lowest=True)

# Confusion Matrix (3x3: Low, Medium, High)
cm = confusion_matrix(y_test_class, y_pred_class, labels=yield_labels)
cm_list = cm.tolist()

# Per-class metrics
class_report = classification_report(y_test_class, y_pred_class,
                                     labels=yield_labels, output_dict=True,
                                     zero_division=0)

# Overall weighted scores
f1_weighted = float(f1_score(y_test_class, y_pred_class, labels=yield_labels,
                             average='weighted', zero_division=0))
precision_weighted = float(precision_score(y_test_class, y_pred_class,
                                           labels=yield_labels, average='weighted',
                                           zero_division=0))
recall_weighted = float(recall_score(y_test_class, y_pred_class,
                                     labels=yield_labels, average='weighted',
                                     zero_division=0))
accuracy_cls = float(accuracy_score(y_test_class, y_pred_class))

# Per-class detail for JSON
per_class = []
for label in yield_labels:
    info = class_report.get(label, {})
    per_class.append({
        'class': label,
        'precision': round(float(info.get('precision', 0)), 4),
        'recall': round(float(info.get('recall', 0)), 4),
        'f1_score': round(float(info.get('f1-score', 0)), 4),
        'support': int(info.get('support', 0))
    })

print("\n--- REGRESSION PERFORMANCE ---")
print(f"Algorithm: Random Forest Regressor (150 trees)")
print(f"Training R² Score: {r2_train:.4f} ({r2_train*100:.2f}%)")
print(f"Testing R² Score:  {r2_test:.4f} ({r2_test*100:.2f}%)")
print(f"5-Fold CV R²:      {cv_r2_mean:.4f} +/- {cv_r2_std:.4f}")
print(f"Mean Absolute Error (MAE):  {mae_test:.2f} kg")
print(f"Root Mean Squared Error (RMSE): {rmse_test:.2f} kg")
print("Feature Importances:", importances)

print("\n--- CLASSIFICATION VALIDATION ---")
print(f"Yield Categories: {yield_labels}")
print(f"Classification Accuracy: {accuracy_cls:.4f} ({accuracy_cls*100:.2f}%)")
print(f"Weighted F1 Score:       {f1_weighted:.4f}")
print(f"Weighted Precision:      {precision_weighted:.4f}")
print(f"Weighted Recall:         {recall_weighted:.4f}")
print(f"\nConfusion Matrix (rows=actual, cols=predicted):")
print(f"              {'  '.join([f'{l:>12}' for l in yield_labels])}")
for i, label in enumerate(yield_labels):
    row = '  '.join([f'{v:>12}' for v in cm_list[i]])
    print(f"  {label:>12}  {row}")
print(f"\nPer-Class Report:")
for pc in per_class:
    print(f"  {pc['class']:>12}: Precision={pc['precision']:.4f}  Recall={pc['recall']:.4f}  F1={pc['f1_score']:.4f}  Support={pc['support']}")

# Save the trained model
joblib.dump(model, model_path, compress=3)
print(f"\n6. Successfully saved model to {model_path}!")

# Save evaluation metrics to JSON for website dashboard
metrics = {
    'model_name': 'Treeco Random Forest Regressor',
    'trained_at': time.strftime('%Y-%m-%d %H:%M:%S'),
    'sample_count': len(df),
    'r2_score': round(r2_test, 4),
    'r2_percentage': round(r2_test * 100, 2),
    'r2_train': round(r2_train, 4),
    'mae_kg': round(mae_test, 2),
    'rmse_kg': round(rmse_test, 2),
    'cv_r2_mean': round(cv_r2_mean, 4),
    'cv_r2_std': round(cv_r2_std, 4),
    'feature_importances': importances,
    'accuracy_rating': 'Excellent (R² > 0.98)' if r2_test >= 0.98 else 'High Accuracy',
    # Classification-based validation
    'classification': {
        'categories': yield_labels,
        'bin_edges': yield_bins,
        'accuracy': round(accuracy_cls, 4),
        'accuracy_pct': round(accuracy_cls * 100, 2),
        'f1_weighted': round(f1_weighted, 4),
        'precision_weighted': round(precision_weighted, 4),
        'recall_weighted': round(recall_weighted, 4),
        'confusion_matrix': cm_list,
        'per_class': per_class
    }
}

with open(metrics_path, 'w') as f:
    json.dump(metrics, f, indent=4)

print(f"7. Saved metrics report to {metrics_path}!")