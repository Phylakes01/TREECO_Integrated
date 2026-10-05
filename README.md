# TREECO — Complete updated package

**ESP32 ang hotspot.** Ikonekta rito ang computer na nagpapatakbo ng original PHP/MySQL website. Pagbukas ng dashboard, automatic na kukunin at ise-save ang sensor readings. Walang router credentials, computer IP, website URL, o device key na kailangang ilagay sa firmware.

## Setup

1. Sa XAMPP, start **Apache** at **MySQL**.
2. Kung existing na ang website, i-backup muna ang folder. Panatilihin ang sarili mong database credentials sa `config.php` at Python path sa `device_config.php` kung binago mo na ang mga iyon. Copy lahat ng laman ng `website` folder sa `C:\xampp\htdocs\treeco\`. Isama ang `assets` at `.htaccess`. Gamitin ang buong package na ito para magkatugma ang PHP files.
3. Import `website/database.sql` sa `http://localhost/phpmyadmin`. Walang binuburang existing readings. Retained ang original `sensor_logs` table/columns; may maliit na additional table para sa connection state. Hindi nag-iinsert ng sample data ang bagong SQL.
4. Check `config.php`: default database `treeco_db`, user `root`, blank MySQL password. Palitan lang kung iba ang local MySQL credentials.
5. Install Python dependencies habang may internet pa:

```bat
cd C:\xampp\htdocs\treeco
python -m pip install -r requirements.txt
python predict.py 110 45 180 6.5 65.5 28.2
```

Dapat numerical result ang prediction test. Kung hindi makita ng Apache ang Python, set ang absolute executable path sa `TREECO_PYTHON` sa `device_config.php`, halimbawa `C:/Python312/python.exe`, at restart Apache. Kung incompatible ang original model sa installed scikit-learn, run `python train_model.py` mula sa folder na ito gamit ang existing CSV. Hindi kailangang i-run ang `datasets.py`.

6. Sa Arduino IDE, install **esp32 by Espressif Systems**, **ModbusMaster 2.0.1**, at **ArduinoJson 7.4.2**. Buksan `firmware/TREECO/TREECO.ino`, piliin ang tamang board/COM port, at Upload. Compile target: classic **ESP32 Dev Module**, ESP32 core **3.0.7**.
7. Connect ang Wi-Fi ng computer sa **T.R.E.E.C.O.** Default password: **Treeco-4d5cc9d330**. Galing ito sa firmware na ipinadala mo. Kung may saved hotspot name/password mula sa v2 firmware, iyon ang mananatiling gamit. Para bumalik sa defaults, hold **BOOT nang 6 seconds matapos mag-start** ang board, then reconnect.
8. Normal ang **No Internet** sa hotspot. Manatiling connected. Sa parehong computer, buksan **http://localhost/treeco/**.

Wala nang manual IP entry. Naka-set na sa backend ang fixed ESP32 sensor endpoint. Para sa diagnostic check lamang, puwedeng buksan `http://192.168.4.1/data`.

## Ano ang retained

- Original layout, colors, at tables; may requested Manage Device tab sa ilalim ng Sensor History.
- Original Soil Health, Seasonal Guide, chart, at Sensor History. Updated ang Python detection at error reporting para sa Yield Prediction.
- Original Python scripts, CSV, at trained model.
- Sensor conversion at validation mula sa latest uploaded `TREECO.ino`: RS485 RX16, TX17, RE4, DE5; 4800 baud/8N1; Modbus slave 1; holding registers 0–6; moisture/temp/pH ÷10; signed temperature.
- Saved ESP32 hotspot name/password from the supplied v2 firmware, if present.

Kasama ang local CSS, icons, at charts para gumana ang design kahit walang internet. Walang dashboard login. Hotspot-only ang ESP32.

## Manage Device at Yield Prediction

Sa ilalim ng Sensor History, buksan ang **Manage Device**. Read-only ang SSID. Ilagay ang **Old Password**, **New Password**, at **Confirm Password**, saka click **Change Password**. Dapat 8–63 printable ASCII characters ang passwords. Ire-restart ng ESP32 ang sarili matapos ma-save; reconnect sa parehong SSID gamit ang bagong password, saka refresh. Kailangan munang i-upload ang kasamang updated firmware para gumana ito. Naka-save ang password kahit patayin ang board.

Automatic na nagre-refresh ang Yield Prediction kada humigit-kumulang 30 segundo habang may live sensor readings. Hiwalay na ito sa sensor polling para hindi maantala ang readings kapag may Python error. May malinaw na status kapag missing ang Python, packages, model, o incompatible ang model. Kailangan pa rin ang Python at model dependencies sa XAMPP computer.

## Paano dumadaloy ang readings

Nagbabasa ang ESP32 kada 1 segundo. Habang bukas at aktibong nagre-refresh ang website, ang PHP backend ay kumukuha ng readings sa ESP32 kada humigit-kumulang 2 segundo, nagse-save sa MySQL, at ibinabalik sa existing dashboard.

Ang **computer na nagpapatakbo ng XAMPP** ang kailangang naka-connect sa hotspot. Ang PHP/MySQL website ay nasa computer; hindi ini-install ang PHP o MySQL sa ESP32. Ang `localhost` ay gumagana sa computer na iyon. Ang phone-only connection sa hotspot ay hindi awtomatikong magho-host ng existing PHP website.

Kapag sarado o suspended ang dashboard tab, hihinto ang polling/logging; walang background collector. Kapag may sensor error o nawala ang hotspot connection, lilitaw ang existing offline indicator at walang fake zero o stale sample na ise-save. Maaaring manatili ang last displayed values sa original UI habang may offline banner. Automatic ang recovery kapag bumalik ang valid sensor response.

## Kung hindi lumabas ang readings

- **Walang hotspot:** check ESP32 power, board/port, upload, at Serial Monitor sa 115200 baud.
- **Hotspot connected pero website offline:** siguraduhing XAMPP computer mismo ang naka-connect, running ang Apache/MySQL, at imported ang database.sql. Dapat enabled ang PHP `allow_url_fopen`.
- **`sensor_ok:false`:** check RS485 A/B, sensor power, common ground, transceiver logic level, pins, slave ID, at baud. Hindi babaguhin ng software ang maling wiring.
- **Hindi mabuksan ang localhost:** check Apache at `htdocs/treeco` location; gamitin ang configured Apache port kung hindi 80.
- **Prediction em dash:** check Python executable, dependencies/model, at PHP `proc_open` setting. Ang original model ay estimate; walang bagong field validation na ginawa.
- **Lumang files ang nakikita:** copy buong website folder mula sa package at Ctrl+F5.

Tingnan ang VALIDATION.md para sa actual software checks at hardware-test limits.
