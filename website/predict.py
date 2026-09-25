import os
import sys
import joblib
import pandas as pd
import warnings
warnings.filterwarnings('ignore')

try:
    # Get absolute directory of this script file to resolve [Errno 2]
    script_dir = os.path.dirname(os.path.abspath(__file__))
    model_path = os.path.join(script_dir, 'treeco_rf_model.pkl')
    
    # Load the trained model using absolute path
    model = joblib.load(model_path)
    
    # Parse incoming arguments from PHP
    input_data = pd.DataFrame([{
        'N': float(sys.argv[1]),
        'P': float(sys.argv[2]),
        'K': float(sys.argv[3]),
        'pH': float(sys.argv[4]),
        'Moisture': float(sys.argv[5]),
        'Temp': float(sys.argv[6])
    }])
    
    # Predict and return numerical output
    prediction = model.predict(input_data)[0]
    print(round(prediction, 2))
    
except Exception as e:
    print("Error:", e)