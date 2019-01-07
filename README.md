### IP-Symcon Modul für den RCT-Power Inverter / Wechselrichter

PHP-Modul zur Einbindung eines [RCT-Power](http://www.rct-power.com) Inverter/Wechselrichter in IPSymcon. 

Nutzung auf eigene Gefahr ohne Gewähr. Das Modul kann jederzeit überarbeitet werden, so daß Anpassungen für eine weitere Nutzung notwendig sein können. Bei der Weiterentwicklung wird möglichst auf Kompatibilität geachtet. 

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang) 
2. [Systemanforderungen](#2-systemanforderungen)
3. [Installation](#3-installation)
4. [Module](#4-module)

## 1. Funktionsumfang

Das Modul ist dazu gedacht einen [RCT-Power](http://www.rct-power.com) Inverter/Wechselrichter einer Photovoltaikanlage in [IP-Symcon](www.ip-symcon.de) einzubinden. 

Es sollen Zustandsdaten (gelieferte Energie, Momentanverbrauch, Ladezustand des Akkus, etc.) zur Auswertung und weiterverwendung in IP-Symcon zur Verfügung gestellt werden. Programmierung oder Steuerung des Inverters/Wechselrichters sind __nicht__ Bestandteil des Moduls.


## 4. Module
Derzeit bietet das GIT nur das Modul "go-eCharger" für die direkte Steuerung eines einzelnen go-eChargers. 

### 4.1. go-eCharger

Das Modul "go-eCharger" dient als Schnittstelle zu einem lokal installierten go-eCharger. Es liefert die Daten des go-eChargers als Statusvariablen und bietet einen Zugriff auf Funktionen des go-eChargers. Der go-eCharger muss dabei lokal über eine IP-Adresse erreichbar sein (siehe Installation).
