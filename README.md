# ColorKit (IP-Symcon)

Verbindet IP-Symcon mit einem ThingPulse Color Kit Grande (ESP32, 480x320 Touch-Display, eigene LVGL-Firmware):
Symcon-Variablen erscheinen per MQTT auf dem Display, Tipps auf Display-Buttons loesen Aktionen in Symcon aus.
Das Display verbindet sich als MQTT-Client auf den in Symcon integrierten MQTT Server.

## Installation

1. In der Modulverwaltung (Module Control) die Git-URL eintragen: `https://github.com/Pfischi-Informatik/SymconColorKit`
2. Instanz **ColorKitDashboard** anlegen. Symcon verbindet sie mit einem **MQTT Server** (legt bei Bedarf einen an).
3. Am MQTT Server den Port (Standard 1883) pruefen und in der Firmware des Displays eintragen.

Voraussetzung: IP-Symcon ab 7.0.

## Topics

Basis-Topic ist konfigurierbar, Standard `colorkit`.

| Topic | Richtung | Inhalt |
|---|---|---|
| `colorkit/state/<key>` | Symcon -> Display | Kachelwert (Zahl als Text, z.B. `3.42`, oder Text), Button-Zustand `0`/`1`. Retain = true |
| `colorkit/cmd/<key>` | Display -> Symcon | Button getippt, Payload `1` |
| `colorkit/status` | Display -> Symcon | `online`/`offline` (retained, LWT). Bei `online` sendet Symcon sofort alle Werte |
| `colorkit/pages` | Symcon -> Display | Seitennamen als JSON (retained) |
| `colorkit/layout/<n>` | Symcon -> Display | Inhalt der Seite n als JSON (retained), leer = keine Seite |
| `colorkit/diag` | Display -> Symcon | WLAN-Diagnose nach jedem Verbinden (retained), steht im Debug-Fenster unter RX |

## Konfiguration

- **Basis-Topic**: Standard `colorkit`.
- **Alles neu senden alle**: Sekunden, Standard 300, 0 = aus.
- **Kacheln**: Key, Variable, Faktor, Formatiert. Formatiert sendet den Text aus dem Variablenprofil.
  Sonst: Bool als `1`/`0`, Zahl als `Wert * Faktor` (auf 4 Stellen gerundet), Text unveraendert.
- **Mehrere Variablen pro Kachel** (z.B. zwei Wallboxen): denselben Key in mehreren Zeilen eintragen. Jede Zeile hat ihren
  eigenen Faktor, das Ergebnis wird danach berechnet. Die Spalte **Berechnung** gilt aus der ersten Zeile des Keys:
  Summe, Differenz (erste Zeile minus alle weiteren), Mittelwert, Minimum, Maximum. Formatiert wird dabei ignoriert,
  Variablen ohne Zahlenwert werden uebersprungen.
- **Buttons**: Key, Variable, Skript. Eine Bool-Variable wird beim Tippen umgeschaltet (per `RequestAction`, wenn sie eine
  Aktion hat, sonst `SetValue`). Ein Skript wird danach mit `$_IPS['KEY']`, `$_IPS['VALUE']` und `$_IPS['SENDER']` = `ColorKit` gestartet.

- **Seiten**: Bis zu 5 Seiten mit je 12 Plaetzen. Die Plaetze fuellen sich in Reihenfolge der Listen, zuerst Kacheln, dann Buttons.
  Titel, Einheit und Farben stellen Sie je Zeile ein, leer bedeutet Standardwert. Farben: `Text=Farbe;Text=Farbe` mit
  rot, orange, gruen, blau, grau oder 6 Hex-Stellen. `battery_soc` ist eine normale Kachel.
- Nach dem Update des Moduls den Symcon-Dienst neu starten, damit die neue Eigenschaft `Pages` angelegt wird.

Die vorbelegten Keys passen zur Firmware; nur die Variablen muessen noch zugeordnet werden.

**Umlaute:** Die Display-Schrift (LVGL Montserrat) hat keine Umlaute. Fuer formatierte Kacheln daher Profiltexte ohne
Umlaute verwenden, also `Kuehlen` statt `Kühlen`.

## Funktionen

```php
CKD_PublishAll(int $InstanzID); // alle Werte jetzt senden, auch als Button im Formular
```

RX/TX stehen im Debug-Fenster der Instanz.

## Test ohne Symcon

```bash
php -d zend.assertions=1 -d assert.exception=1 test/live.php
```
