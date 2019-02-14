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

## 2. Systemanforderungen
- IP-Symcon ab Version 4.x

## 3. Installation

### Vorbereitung des RCT-Power Inverters / Wechselrichters
Vor der Installation des Moduls in IPSymcon muss der RCT-Power Inverter/Wechselrichter vollständig eingerichtet sein. Da dieses Modul lokal auf den Inverter/Wechselrichter zugreift, muss dieser im lokalen WLAN (nicht dem WLAN des Inverters/Wechselrichters!) oder lokalen LAN mit einer statischen IP erreichbar sein. 

Der Zugriff auf den RCT Power Inverter erfolgt über eine ClientServer TCP Instanz auf die IP des Wechselrichters mit Port 8899.

<p align="center">
  <img width="753" height="571" src="./images/TCP%20Schnittstelle.jpg">
</p>

Dem RCT Power Inverter Modul muss diese als übergeordnete Instanz zugewiesen werden

<p align="center">
  <img width="753" height="571" src="./images/RCT%20Power%20Inverter.jpg">
</p>

Weitere Einstellungen:
- **Grunddaten - Input A und B**

Hier kann die Anzahl der Panel sowie deren jeweilige (je Panel) Nennleistung eingestellt werden. Diese Werte dienen der Berechnung der %-Ausnutzung der Panel/Eingänge.
- **RCT Wechselrichter Einstellungen**

Hier kann die untere Entladeschwelle einer angeschlossenen Batterie (RCT Power Storage) eingestellt werden. Die verfügbare Restkapazität der Batterie bezieht sich auf die verfügbare Batterie-Kapazität zwischen der im Wechselrichter eingestellten unteren sowie der oberen Ladeschwelle (die obere Schwelle sowie die Brutto-Kapazität wird automatisch ermittelt).
- **Update Interval**

Für das regelmäßige aktualisieren der Daten muss ein Update-Inverval eingestellt werden. Dabei steht 0 für keine Updates! Weniger als 10 Sekunden sollte man aus Performance-Gründen nicht wählen, da das Abrufen der Daten ein paar Sekunden dauert.

## 4. Module
Derzeit bietet das GIT nur das Modul "RCT_POWER_INVERTER" für die direkte Anbindung eines einzelnen RCT-Power Inverter/Wechselrichter. 

### 4.1. RCT_POWER_INVERTER

Das Modul "RCT_POWER_INVERTER" dient als Schnittstelle zu einem lokal installierten RCT-Power Inverter/Wechselrichter. Es liefert die Daten des Inverter/Wechselrichter als Statusvariablen. Der RCT-Power Inverter/Wechselrichter muss dabei lokal über eine IP-Adresse erreichbar (siehe Installation) und das Update Interval entsprechend eingestellt sein.
