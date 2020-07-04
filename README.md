### IP-Symcon Modul für den RCT-Power Inverter / Wechselrichter

<img src="./imgs/RCT%20Power%20Logo.png">

PHP-Modul zur Einbindung eines [RCT-Power](http://www.rct-power.com) Inverter/Wechselrichter in IPSymcon. 

Nutzung auf eigene Gefahr ohne Gewähr. Das Modul kann jederzeit überarbeitet werden, so daß Anpassungen für eine weitere Nutzung notwendig sein können. Bei der Weiterentwicklung wird möglichst auf Kompatibilität geachtet. 

**Bei der Installation einer neuen Version sollte man in die [Versionshistorie](#5-versionshistorie) schauen, ob man evtl. manuell tätig werden muss!**

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang) 
2. [Systemanforderungen](#2-systemanforderungen)
3. [Installation](#3-installation)
4. [Module](#4-module)
4. [Versionshistorie](#5-versionshistorie)

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
  <img width="800" src="./imgs/RCT%20Gateway%20Konfiguration.png">
</p>

Dem RCT Power Inverter Modul muss diese als übergeordnete Instanz zugewiesen werden

<p align="center">
  <img width="800" src="./imgs/RCT%20Modul%20Instanzkonfiguration.png">
</p>

Weitere Einstellungen:


**PV Paneldaten - Eingang A**

Hier kann die Anzahl der Panel sowie deren jeweilige (je Panel) Nennleistung für den Eingang A eingestellt werden. Diese Werte dienen der Berechnung der %-Ausnutzung der Panel am Eingang A.

**PV Paneldaten - Eingang B**

Hier kann die Anzahl der Panel sowie deren jeweilige (je Panel) Nennleistung für den Eingang B eingestellt werden. Diese Werte dienen der Berechnung der %-Ausnutzung der Panel am Eingang A.

**RCT Wechselrichter Einstellungen**

Hier kann die untere Entladeschwelle einer angeschlossenen Batterie (RCT Power Storage) eingestellt werden. Die verfügbare Restkapazität der Batterie bezieht sich auf die verfügbare Batterie-Kapazität zwischen der im Wechselrichter eingestellten unteren sowie der oberen Ladeschwelle (die obere Schwelle sowie die Brutto-Kapazität wird automatisch ermittelt).

**Automatische Updates**

Für das regelmäßige aktualisieren der Daten müssen die automatischen Updates aktiv und ein Update-Inverval eingestellt sein. Die Untergrenze des Update-Intervalls liegt bei 15 Sekunden (früher 10).
Anmerkung: Der Werte wurde mit Version 1.0 erhöht, da bei mehreren Wechselrichtern mehr Zeit notwendig ist, damit alle ihre Daten nacheinander abholen können. 

Sollen die Updates nicht automatisch erfolgen, können die Daten mittels des Befehls RCTPowerInverter_UpdateData() über ein Skript angefordert werden. Auch hier sollte man darauf achten, das man nicht zu schnell hintereinander Daten anfordert!

**Tools**

Die Verwendung der Tools sollte eigentlich nur im Problemfall notwendig und deshalb alle Tools im Normalfall deaktiviert sein.

* **Debuginformationen ausgeben**. Über diesen Schalter können Debug-Meldungen im Modul aktiviert werden. So kann man grob mitverfolgen, was das Modul aktuell gerade macht.
* **Fehlerhafte Antwort-Sequence ignorieren**. Das Modul arbeitet nach dem Prinzip "Frage X Adressen an und werde die Antworten aus". Dabei erwartet es die Anworten in genau der Sequenz, in der sie angefragt wurden. Sollte es hier zu Probleme kommen (siehe Debug-Meldungen im Modul), kann man versuchen, diese Sequenz-Überprüfung zu deaktivieren.
* **Auf fremde Abfragen reagieren**. Im Normalfall ignoriert das Modul Antworten auf der Schnittstelle, die von anderen Anbindungen wie z.B. der RCT Android/iOS App angefordert wurden. Durch diesen Schalter kann man versuchen, auch diese Antworten auswerten zu lassen (experimentell).


## 4. Module
Derzeit bietet das GIT nur das Modul "RCT_POWER_INVERTER" für die direkte Anbindung eines einzelnen RCT-Power Inverter/Wechselrichter. 

### 4.1. RCT_POWER_INVERTER

Das Modul "RCT_POWER_INVERTER" dient als Schnittstelle zu einem lokal installierten RCT-Power Inverter/Wechselrichter. Es liefert die Daten des Inverter/Wechselrichter als Statusvariablen. Der RCT-Power Inverter/Wechselrichter muss dabei lokal über eine IP-Adresse erreichbar (siehe Installation) und das Update Interval entsprechend eingestellt sein.

### Wichtiger Hinweis
Das Modul reagiert auf Nachrichten vom Wechselrichter über die geöffnete TCP Schnittstelle. Das Update Interval dient faktisch nur der regelmäßigen Anforderung der Daten. Wenn die TCP-Schnittstelle geöffnet ist reagiert das Modul auch auf Nachrichten, die ggf. parallel über die RCT Android/iOS App vom Wechselrichter angefordert wurden oder zwischen mehreren installierten Wechselrichtern hin- und hergeschickt werden. 

**Während die Quer-Kommunikation der Wechselrichter untereinander möglichst ignoriert wird, kann ein paralleles Pollen über die Android oder IOS App ggf. die Kommunikation und den Datenempfang dieses Moduls stören. Aus diesem Grund sollte man möglichst auf einen parallele Nutzung der RCT Android/iOS App verzichten!**

## 5. Versionshistorie

### Version 1.0
Überarbeitete Version mit folgenden Anpassungen:
* Komplett überarbeitet Kommunikation mit dem RCT Wechselrichter. 
Es zeigten sich Probleme bei Installationen mit mehreren Wechselrichtern. Die WR kommunizieren untereinander, so dass es zu Fehl-Zuordnungen der Daten kam. Man konnte nicht Unterscheiden, welche Daten zu welchem Wechselrichter gehörten.
Die Kommunikation wurde mittels Semaphoren sequenziert. Zudem wird nicht jede Antwort auf der Schnittstelle sofort ausgewertet, sondern die Antworten werden gesammelt um dann mit den angeforderten Daten verglichen und ausgewertet zu werden.
* optimiertes Layout und Anpassungen der Konfigurationsparameter in der Instanzkonfiguration

### Version 0.1
Erste Version des Moduls. Funktioniert stabil mit *einem* Wechselrichter und liesst die Daten über die TCP/IP Schnittstelle aus
