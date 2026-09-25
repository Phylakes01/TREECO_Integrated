import pandas as pd
from sklearn.model_selection import train_test_split
from sklearn.ensemble import RandomForestRegressor
import joblib

# 1. Load your dataset with the correct filename
df = pd.read_csv('crop_yield_data.csv')

# 2. Define features (X) and target (y) using the EXACT column names from your CSV
X = df[['N', 'P', 'K', 'pH', 'Moisture', 'Temp']]
y = df['Yield_kg']

# 3. Split into training and test sets
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)

# 4. Train the model
model = RandomForestRegressor(n_estimators=100, random_state=42)
model.fit(X_train, y_train)

# 5. Save the trained model
joblib.dump(model, 'treeco_rf_model.pkl')
print("Model successfully trained and saved as treeco_rf_model.pkl!")