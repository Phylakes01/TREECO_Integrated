# Validation — latest complete package

- Includes the latest index.html, dashboard_api.php, prediction_api.php, device_api.php and TREECO.ino, plus all required files from the integrated website.
- 22 software checks passed: PHP API tests with SQLite PDO and a simulated ESP32 HTTP endpoint, plus browser checks on desktop/mobile with external requests blocked.
- Actual original model inference succeeded. Missing Python dependencies/model, Python executable fallback and prediction timeout were tested.
- Password form tested for read-only SSID, mismatched confirmation, wrong old password, reconnect message and clearing fields. HTTP device responses were simulated; no physical password change was tested.
- Original table markup and CSS retained.
- Firmware compiled with ESP32 core 3.0.7, ArduinoJson 7.4.2, ModbusMaster 2.0.1; ESP32 Dev Module target.
- Actual ESP32 hardware, Wi-Fi reassociation, NVS password persistence and production MySQL were not physically tested here.

## Compiler output

```
Sketch uses 959957 bytes (73%) of program storage space. Maximum is 1310720 bytes.
Global variables use 45808 bytes (13%) of dynamic memory, leaving 281872 bytes for local variables. Maximum is 327680 bytes.
```

## Passed checks

- PHP syntax passes
- original live dashboard still works
- actual model returns numeric yield
- prediction cache retains result
- offline sensor cannot produce fresh prediction
- fallback detects installed Python
- missing model has actionable error
- missing dependency has actionable error
- incompatible model has actionable error
- hung prediction is stopped
- SSID read and CSRF issued without dashboard login
- CSRF missing is rejected
- SSID modification is rejected
- confirm mismatch is rejected
- short password is rejected
- incorrect old password is rejected by device
- password change acknowledged with reconnect instruction
- SSID stays unchanged
- password is never returned
- old firmware upgrade requirement reported
- browser yield and Manage Device workflows
- browser submitted password without changing SSID
