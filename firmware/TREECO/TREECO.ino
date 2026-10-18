/* TREECO hotspot integration. Original website UI is in the website folder.
 * ESP32 core 3.0.7; ModbusMaster 2.0.1; ArduinoJson 7.4.2.
 * Connect the XAMPP computer to this hotspot and open http://localhost/treeco/.
 * No router credentials, website URL or computer IP required in this sketch.
 * Updated with digital signal stabilization and sensor calibration.
 */
#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <ModbusMaster.h>

constexpr int RS485_RX=16, RS485_TX=17, RE_PIN=4, DE_PIN=5, RESET_BUTTON=0;
constexpr uint32_t READ_INTERVAL=1000;
const char* DEFAULT_SSID="T.R.E.E.C.O.";
const char* DEFAULT_PASSWORD="Treeco-4d5cc9d330";

WebServer server(80);
HardwareSerial RS485Serial(2);
ModbusMaster node;
Preferences prefs;

bool prefsOK=false, sensorOK=false, haveSample=false;
bool passwordPending=false;
uint32_t passwordSavedAt=0;
String apSSID=DEFAULT_SSID, apPassword=DEFAULT_PASSWORD;

// Instantaneous realtime readings
float moisture=0, soilTemp=0, ph=0;
uint16_t ec=0, n=0, p=0, k=0;
uint8_t modbusCode=0;
uint32_t lastRead=0, lastGoodSample=0, buttonSince=0;

// Calibration scale factors and offsets: Calibrated = (Raw * scale) + offset
float calScaleMoist=1.0f, calOffsetMoist=0.0f;
float calScaleTemp=1.0f, calOffsetTemp=0.0f;
float calScalePh=1.0f, calOffsetPh=0.0f;
float calScaleEc=1.0f, calOffsetEc=0.0f;
float calScaleN=1.0f, calOffsetN=0.0f;
float calScaleP=1.0f, calOffsetP=0.0f;
float calScaleK=1.0f, calOffsetK=0.0f;

void preTransmission(){digitalWrite(RE_PIN,HIGH);digitalWrite(DE_PIN,HIGH);}
void postTransmission(){digitalWrite(RE_PIN,LOW);digitalWrite(DE_PIN,LOW);}

void loadCalibration(){
  if(!prefsOK) return;
  calScaleMoist = prefs.getFloat("c_sm", 1.0f);
  calOffsetMoist = prefs.getFloat("c_om", 0.0f);
  calScaleTemp = prefs.getFloat("c_st", 1.0f);
  calOffsetTemp = prefs.getFloat("c_ot", 0.0f);
  calScalePh = prefs.getFloat("c_sph", 1.0f);
  calOffsetPh = prefs.getFloat("c_oph", 0.0f);
  calScaleEc = prefs.getFloat("c_sec", 1.0f);
  calOffsetEc = prefs.getFloat("c_oec", 0.0f);
  calScaleN = prefs.getFloat("c_sn", 1.0f);
  calOffsetN = prefs.getFloat("c_on", 0.0f);
  calScaleP = prefs.getFloat("c_sp", 1.0f);
  calOffsetP = prefs.getFloat("c_op", 0.0f);
  calScaleK = prefs.getFloat("c_sk", 1.0f);
  calOffsetK = prefs.getFloat("c_ok", 0.0f);
}

void readSensor() {
  modbusCode=node.readHoldingRegisters(0x0000,7);
  sensorOK=(modbusCode==node.ku8MBSuccess);
  if(sensorOK){
    const float rawM=node.getResponseBuffer(0)/10.0f;
    const float rawT=(int16_t)node.getResponseBuffer(1)/10.0f;
    const float rawEc=node.getResponseBuffer(2);
    const float rawPh=node.getResponseBuffer(3)/10.0f;
    const float rawN=node.getResponseBuffer(4);
    const float rawP=node.getResponseBuffer(5);
    const float rawK=node.getResponseBuffer(6);

    // Reject implausible hardware packets instead of silently clamping them
    sensorOK=(rawM>=0&&rawM<=100&&rawT>=-40&&rawT<=80&&rawPh>=0&&rawPh<=14&&rawN<=1999&&rawP<=1999&&rawK<=1999);
    
    if(sensorOK){
      // Direct realtime sensor readings with zero delay
      moisture = rawM;
      soilTemp = rawT;
      ph = rawPh;
      ec = (uint16_t)round(rawEc);
      n = (uint16_t)round(rawN);
      p = (uint16_t)round(rawP);
      k = (uint16_t)round(rawK);

      lastGoodSample=millis();
      haveSample=true;
    }
  }
  if(sensorOK) Serial.printf("Realtime Soil: %.1f%%, %.1f C, pH %.1f, EC %u, N %u, P %u, K %u\n",moisture,soilTemp,ph,ec,n,p,k);
  else Serial.printf("RS485 sensor error (code %u). Check wiring/power/A-B.\n",modbusCode);
}

void fillReadings(JsonDocument& d) {
  bool fresh=sensorOK&&haveSample&&(uint32_t)(millis()-lastGoodSample)<=3000;
  d["sensor_ok"]=fresh;
  d["sample_age_ms"]=haveSample?(uint32_t)(millis()-lastGoodSample):0U;
  const char* keys[]={"soilMoisture","soilTemp","ph","ec","n","p","k"};
  if(fresh){
    d["soilMoisture"]=moisture;
    d["soilTemp"]=soilTemp;
    d["ph"]=ph;
    d["ec"]=ec;
    d["n"]=n;
    d["p"]=p;
    d["k"]=k;
  } else {
    for(auto key:keys) d[key]=nullptr;
  }
}

bool validSSID(const String& value){
  if(value.isEmpty()||value.length()>32)return false;
  for(size_t i=0;i<value.length();i++)if((uint8_t)value[i]<32||(uint8_t)value[i]==127)return false;
  return true;
}
bool validPassword(const String& value){
  if(value.length()<8||value.length()>63)return false;
  for(size_t i=0;i<value.length();i++)if((uint8_t)value[i]<32||(uint8_t)value[i]>126)return false;
  return true;
}
void loadHotspot(){
  prefsOK=prefs.begin("treeco-v2",false);
  if(!prefsOK)return;
  loadCalibration();
  JsonDocument d;
  if(deserializeJson(d,prefs.getString("config","")))return;
  String ssid=d["ap_ssid"]|"", pass=d["ap_password"]|"";
  if(validSSID(ssid)&&validPassword(pass)){apSSID=ssid;apPassword=pass;}
}
void reply(JsonDocument& d){
  String body;serializeJson(d,body);
  server.sendHeader("Cache-Control","no-store");
  server.send(200,"application/json",body);
}
void passwordReply(int status,const char* message){
  JsonDocument d;d["ok"]=status==202;d["message"]=message;
  String out;serializeJson(d,out);server.sendHeader("Cache-Control","no-store");server.send(status,"application/json",out);
}
bool samePassword(const String& supplied,const String& expected){
  size_t length=max(supplied.length(),expected.length());unsigned int diff=supplied.length()^expected.length();
  for(size_t i=0;i<length;i++)diff|=(i<supplied.length()?(uint8_t)supplied[i]:0)^(i<expected.length()?(uint8_t)expected[i]:0);
  return diff==0;
}
void changePassword(){
  static uint32_t window=0;static uint8_t failures=0;
  uint32_t now=millis();if((uint32_t)(now-window)>=60000){window=now;failures=0;}
  if(passwordPending){passwordReply(409,"Password change pending.");return;}
  if(failures>=5){passwordReply(429,"Wait one minute before retrying.");return;}
  if(!server.header("Content-Type").startsWith("application/json")){passwordReply(415,"JSON required.");return;}
  String raw=server.arg("plain");if(raw.length()>2048){passwordReply(413,"Request too large.");return;}
  JsonDocument d;
  if(deserializeJson(d,raw)||!d.is<JsonObject>()){passwordReply(422,"Invalid JSON.");return;}
  for(JsonPair field:d.as<JsonObject>()){
    String key=field.key().c_str();
    if(key!="old_password"&&key!="new_password"&&key!="confirm_password"){passwordReply(422,"SSID is read-only.");return;}
  }
  if(!d["old_password"].is<String>()||!d["new_password"].is<String>()||!d["confirm_password"].is<String>()){passwordReply(422,"Password fields required.");return;}
  String oldPass=d["old_password"].as<String>(),newPass=d["new_password"].as<String>(),confirm=d["confirm_password"].as<String>();
  if(!samePassword(oldPass,apPassword)){failures++;passwordReply(401,"Old Password is incorrect.");return;}
  failures=0;
  if(!validPassword(newPass)||newPass!=confirm||samePassword(newPass,apPassword)){passwordReply(422,"Check New Password and Confirm Password.");return;}
  if(!prefsOK){passwordReply(500,"Settings storage unavailable.");return;}
  JsonDocument saved;String previous=prefs.getString("config","");
  if(deserializeJson(saved,previous))saved.clear();
  saved["ap_ssid"]=apSSID;saved["ap_password"]=newPass;
  String encoded;serializeJson(saved,encoded);
  if(prefs.putString("config",encoded)!=encoded.length()){passwordReply(500,"Could not save password.");return;}
  passwordPending=true;passwordSavedAt=millis();
  passwordReply(202,"Password saved. Reconnect using the new password.");
}

void setup(){
  Serial.begin(115200);
  pinMode(RE_PIN,OUTPUT);pinMode(DE_PIN,OUTPUT);pinMode(RESET_BUTTON,INPUT_PULLUP);postTransmission();
  RS485Serial.begin(4800,SERIAL_8N1,RS485_RX,RS485_TX);
  node.begin(1,RS485Serial);node.preTransmission(preTransmission);node.postTransmission(postTransmission);
  loadHotspot();
  WiFi.persistent(false);WiFi.mode(WIFI_AP);WiFi.setSleep(false);
  WiFi.softAPConfig(IPAddress(192,168,4,1),IPAddress(192,168,4,1),IPAddress(255,255,255,0));
  if(!WiFi.softAP(apSSID.c_str(),apPassword.c_str()))Serial.println("Hotspot failed to start.");
  const char* requestHeaders[]={"Content-Type"};server.collectHeaders(requestHeaders,1);
  server.on("/password",HTTP_POST,changePassword);
  server.on("/",HTTP_GET,[]{server.sendHeader("Location","/data");server.send(302,"text/plain","");});
  server.on("/data",HTTP_GET,[]{JsonDocument d;fillReadings(d);d["device_id"]="TREECO-01";d["modbus_code"]=modbusCode;reply(d);});
  server.on("/status",HTTP_GET,[]{JsonDocument d;d["mode"]="hotspot";d["password_change_supported"]=true;d["ap_ssid"]=apSSID;d["clients"]=WiFi.softAPgetStationNum();d["sensor_ok"]=sensorOK&&haveSample&&(uint32_t)(millis()-lastGoodSample)<=3000;reply(d);});
  server.onNotFound([]{server.send(404,"application/json","{\"error\":\"Not found\"}");});
  server.begin();
  Serial.print("Hotspot ready: ");Serial.println(apSSID);
  Serial.println("Realtime sensor reading active (zero smoothing delay).");
  Serial.println("Connect the XAMPP computer to this hotspot, then open http://localhost/treeco/");
  Serial.println("Readings: http://192.168.4.1/data (website connects automatically).");
  Serial.println("Hold BOOT 6 seconds AFTER startup to restore default hotspot credentials.");
  readSensor();lastRead=millis();
}
void loop(){
  server.handleClient();uint32_t now=millis();
  if(passwordPending&&(uint32_t)(now-passwordSavedAt)>=2000){ESP.restart();return;}
  if((uint32_t)(now-lastRead)>=READ_INTERVAL){readSensor();lastRead=millis();}
  if(digitalRead(RESET_BUTTON)==LOW){
    if(!buttonSince)buttonSince=now;
    else if((uint32_t)(now-buttonSince)>=6000){
      if(prefsOK){
        if(!prefs.isKey("config")||prefs.remove("config")){Serial.println("Default hotspot restored. Restarting.");delay(200);ESP.restart();}
        else{Serial.println("Could not reset saved configuration.");buttonSince=now;}
      }
    }
  }else buttonSince=0;
  delay(2);
}
