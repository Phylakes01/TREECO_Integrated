import pandas as pd
import numpy as np

# Set seed for reproducible, high-precision agronomic research dataset
np.random.seed(42)
n_samples = 2500

# Generating diverse soil parameters covering real-world field conditions
n_vals = np.random.uniform(30, 240, n_samples)
p_vals = np.random.uniform(10, 95, n_samples)
k_vals = np.random.uniform(40, 320, n_samples)
ph_vals = np.random.uniform(4.8, 8.2, n_samples)
moisture_vals = np.random.uniform(25, 95, n_samples)
temp_vals = np.random.uniform(18, 38, n_samples)

# Agronomic response curves based on Mitscherlich-Baule law of diminishing returns:
# 1. Nutrient response (plateaus as nutrients reach luxury consumption)
n_resp = 80.0 * (1 - np.exp(-0.015 * n_vals))
p_resp = 50.0 * (1 - np.exp(-0.045 * p_vals))
k_resp = 45.0 * (1 - np.exp(-0.012 * k_vals))

# 2. Soil pH response factor (optimal between 6.0 and 7.0)
ph_factor = np.exp(-0.5 * ((ph_vals - 6.5) / 0.8) ** 2)

# 3. Soil moisture response factor (optimal between 55% and 75%)
moisture_factor = np.exp(-0.5 * ((moisture_vals - 65.0) / 16.0) ** 2)

# 4. Temperature response factor (optimal between 25°C and 28°C)
temp_factor = np.exp(-0.5 * ((temp_vals - 26.5) / 5.5) ** 2)

# Baseline yield potential (kg per target plot / standard benchmark)
base_yield = (n_resp + p_resp + k_resp) * (0.45 + 0.55 * ph_factor) * (0.50 + 0.50 * moisture_factor) * (0.60 + 0.40 * temp_factor)

# Add small realistic environmental variance (standard deviation 2.5 kg)
field_noise = np.random.normal(0, 2.5, n_samples)
yield_kg = np.clip(base_yield + field_noise, 40.0, 260.0).round(1)

df = pd.DataFrame({
    'N': n_vals.round(1),
    'P': p_vals.round(1),
    'K': k_vals.round(1),
    'pH': ph_vals.round(1),
    'Moisture': moisture_vals.round(1),
    'Temp': temp_vals.round(1),
    'Yield_kg': yield_kg
})

df.to_csv('crop_yield_data.csv', index=False)
print(f"Successfully generated {n_samples} high-quality research agronomic rows in crop_yield_data.csv!")
print(f"Yield Range: {df['Yield_kg'].min()} kg - {df['Yield_kg'].max()} kg (Mean: {df['Yield_kg'].mean():.1f} kg)")