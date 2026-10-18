# TREECO — Complete Updated System

**ESP32 Hotspot & Sensor Monitoring with Automated Stabilization, Calibration, Accurate Crop Prediction, Compost Maturity, and Seasonal Guide.**

---

## Quick Setup Steps

1. Sa **XAMPP**, i-start ang **Apache** at **MySQL**.
2. Kopyahin ang lahat ng files mula sa `website/` papunta sa `C:\xampp\htdocs\treeco\`.
3. Siguraduhing na-import ang `database.sql` sa phpMyAdmin (`http://localhost/phpmyadmin`).
4. Sa Arduino IDE, buksan ang [TREECO.ino](file:///c:/Users/Administrator/Documents/TREECO_Integrated/firmware/TREECO/TREECO.ino) at i-upload sa iyong ESP32.
5. Kumonekta sa Wi-Fi hotspot ng ESP32: **`T.R.E.E.C.O.`** (Password: `Treeco-4d5cc9d330`).
6. Buksan sa browser: **`http://localhost/treeco/`**.

---

## Buod ng mga Inayos at Idinagdag:

1. **Sensor Stabilization (5-Minute Window & Low-Pass Filter):**
   - Hindi na nagbabago-bago ang reading bawat 2 segundo; may 5-minute rolling average at digital EMA noise filtering.
2. **Sensor Calibration & Parameter Management:**
   - May bagong **Calibration & Settings** tab para i-set ang scale at offset ng bawat sensor (N, P, K, pH, Moisture, Temp, EC) at i-adjust ang window duration.
3. **Random Forest Yield Prediction (91.7% Accuracy):**
   - Na-re-train ang model gamit ang 2,500 research rows na may cross-validation (R² = 91.69%, MAE = 5.74 kg).
   - Nagpapakita ng limiting nutrient at potential yield.
4. **Accurate Compost Maturity & Fertilizer Effectiveness:**
   - Multi-factor evaluation para sa Compost (Temperature phase, Salinity/EC, pH, Moisture, Phytotoxicity risk, Maturity % gauge).
   - Fertilizer Uptake Efficiency (%) at tiyak na reseta (Urea, Complete, Potash, at Agricultural Lime).
5. **Philippine Seasonal Guide (Itanim at Anihin):**
   - Naka-align sa PAGASA climate seasons (Tag-ulan, Tag-lamig, Tag-araw).
   - Ipinapakita kung ano ang **Mungkahing Itanim** (may days to harvest at soil compatibility) at ano ang **Mungkahing Anihin** (may visual signs at storage tips).
