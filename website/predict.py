import os
import sys
import json
import joblib
import pandas as pd
import numpy as np
import warnings
warnings.filterwarnings('ignore')

try:
    script_dir = os.path.dirname(os.path.abspath(__file__))
    model_path = os.path.join(script_dir, 'treeco_rf_model.pkl')

    if not os.path.isfile(model_path):
        print("Error: Model file treeco_rf_model.pkl not found.")
        sys.exit(1)

    model = joblib.load(model_path)

    json_mode = '--json' in sys.argv
    args = [a for a in sys.argv[1:] if a != '--json']

    if len(args) < 6:
        print("Error: Missing required sensor input parameters (N, P, K, pH, Moisture, Temp).")
        sys.exit(1)

    # Physical clamping to prevent absurd out-of-distribution crashes
    n_val = max(0.0, min(1999.0, float(args[0])))
    p_val = max(0.0, min(1999.0, float(args[1])))
    k_val = max(0.0, min(1999.0, float(args[2])))
    ph_val = max(0.0, min(14.0, float(args[3])))
    moisture_val = max(0.0, min(100.0, float(args[4])))
    temp_val = max(-40.0, min(80.0, float(args[5])))

    input_data = pd.DataFrame([{
        'N': n_val,
        'P': p_val,
        'K': k_val,
        'pH': ph_val,
        'Moisture': moisture_val,
        'Temp': temp_val
    }])

    # 1. Base Realtime Yield Prediction via Random Forest
    prediction = float(model.predict(input_data)[0])
    prediction = max(0.0, round(prediction, 2))

    # 2. AI Model-Based Achievable Yield Potential & Limiting Factor Identification
    # Optimal target values derived from agronomic response curves in training data
    optimal_values = {
        'pH': 6.5,        # Importance: 37.8% (Primary driver)
        'Moisture': 65.0, # Importance: 33.4% (Secondary driver)
        'Temp': 26.5,     # Importance: 14.3%
        'N': 180.0,       # Importance: 8.5%
        'P': 50.0,        # Importance: 3.4%
        'K': 220.0        # Importance: 2.6%
    }

    limits = []
    remediated = {
        'N': n_val, 'P': p_val, 'K': k_val,
        'pH': ph_val, 'Moisture': moisture_val, 'Temp': temp_val
    }

    # Identify limiting factors ordered by Random Forest feature importance
    if ph_val < 6.0:
        limits.append(f"Soil pH ({ph_val:.1f}) is acidic (Optimal: 6.0 - 7.0) — High impact (37.8% AI weight)")
        remediated['pH'] = optimal_values['pH']
    elif ph_val > 7.2:
        limits.append(f"Soil pH ({ph_val:.1f}) is alkaline (Optimal: 6.0 - 7.0) — High impact (37.8% AI weight)")
        remediated['pH'] = optimal_values['pH']

    if moisture_val < 50.0:
        limits.append(f"Soil moisture ({moisture_val:.1f}%) is low (Optimal: 55 - 75%) — High impact (33.4% AI weight)")
        remediated['Moisture'] = optimal_values['Moisture']
    elif moisture_val > 80.0:
        limits.append(f"Soil moisture ({moisture_val:.1f}%) is waterlogged (Optimal: 55 - 75%) — High impact (33.4% AI weight)")
        remediated['Moisture'] = optimal_values['Moisture']

    if temp_val < 20.0 or temp_val > 32.0:
        limits.append(f"Soil temperature ({temp_val:.1f}°C) is outside ideal 24 - 28°C range (14.3% AI weight)")
        remediated['Temp'] = optimal_values['Temp']

    if n_val < 100.0:
        limits.append(f"Nitrogen level ({n_val:.1f} mg/kg) is deficient (Optimal: 150 - 250 mg/kg)")
        remediated['N'] = optimal_values['N']

    if p_val < 30.0:
        limits.append(f"Phosphorus level ({p_val:.1f} mg/kg) is deficient (Optimal: 30 - 60 mg/kg)")
        remediated['P'] = optimal_values['P']

    if k_val < 150.0:
        limits.append(f"Potassium level ({k_val:.1f} mg/kg) is deficient (Optimal: 150 - 300 mg/kg)")
        remediated['K'] = optimal_values['K']

    # 3. Predict Achievable Potential by running the AI model on remediated parameters
    remediated_data = pd.DataFrame([remediated])
    potential = float(model.predict(remediated_data)[0])
    potential = max(prediction, round(potential, 2))

    if json_mode:
        print(json.dumps({
            'prediction_kg': prediction,
            'potential_kg': potential,
            'limiting_factors': limits
        }))
    else:
        # Return numerical output as required by backward-compatible proc_open
        print(prediction)

except Exception as e:
    print("Error:", e)
    sys.exit(1)