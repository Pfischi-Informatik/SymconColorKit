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
- **Kacheln**: Key, Seite, Titel, Einheit, Stellen, Farben, Variable, Faktor, Formatiert. Formatiert sendet den Text aus dem Variablenprofil.
  Sonst: Bool als `1`/`0`, Zahl als `Wert * Faktor` (auf 4 Stellen gerundet), Text unveraendert.
- **Mehrere Variablen pro Kachel** (z.B. zwei Wallboxen): denselben Key in mehreren Zeilen eintragen. Jede Zeile hat ihren
  eigenen Faktor, das Ergebnis wird danach berechnet. Die Spalte **Berechnung** gilt aus der ersten Zeile des Keys:
  Summe, Differenz (erste Zeile minus alle weiteren), Mittelwert, Minimum, Maximum. Formatiert wird dabei ignoriert,
  Variablen ohne Zahlenwert werden uebersprungen.
- **Buttons**: Key, Seite, Beschriftung, Variable, Skript. Eine Bool-Variable wird beim Tippen umgeschaltet (per `RequestAction`, wenn sie eine
  Aktion hat, sonst `SetValue`). Ein Skript wird danach mit `$_IPS['KEY']`, `$_IPS['VALUE']` und `$_IPS['SENDER']` = `ColorKit` gestartet.

- **Dimmer**: In der Button-Liste Typ `Dimmer` waehlen. Das Display zeigt einen Regler von 0 bis 100 % und sendet den Wert beim Loslassen. Er wird auf den Bereich des Variablenprofils umgerechnet (ohne Profil 0 bis 100). Bool-Variablen: 0 % = aus, sonst an.
- **Seiten**: Bis zu 5 Seiten mit je 4 x 3 Plaetzen. Die Elemente kommen in Reihenfolge der Listen (zuerst Kacheln, dann
  Buttons) auf den ersten freien Platz, an dem sie ganz passen. Was nicht mehr passt, fehlt (Debug-Meldung). Die
  Reihenfolge aenderst du per Drag & Drop in den Listen. Seite `-` zeigt nichts an, der Wert wird trotzdem gesendet.
- **Groesse**: `1x1` bis `4x3` (Breite x Hoehe in Plaetzen, `4x3` = ganze Seite). Ab Hoehe 2 ist der Wert groesser.
- **Spalte / Zeile**: linke obere Ecke (Spalte 1-4, Zeile 1-3). Solche Elemente werden zuerst gesetzt, alle anderen
  fuellen die freien Plaetze. Ist die Position belegt oder ragt das Element ueber den Rand, wird es automatisch platziert.
- **Darstellung**: `Zahl`, `Rundanzeige` oder `Balken` mit **Maximum** (leer = 100). Standard: `battery_soc` als
  Rundanzeige mit Maximum 100. Werte unter 0 oder ueber dem Maximum werden am Rand angezeigt.
- **Energiefluss**: Kachelzeile mit Key `energy_flow` (keine Variable noetig) und Groesse `4x3` oder `4x2`. Knoten
  erscheinen fuer `pv_power`, `grid_power` (+ = Bezug, - = Einspeisung), `house_power`, `battery_power` (+ = Laden,
  - = Entladen), `wallbox_power` und `hp_power`, sobald der Key in der Liste steht. Werte, die nur der Fluss braucht,
  auf Seite `-` legen. Falsches Vorzeichen mit Faktor `-1` drehen. Der Akku-Knoten zeigt `battery_soc` mit an.
  Titel, Einheit und Farben stellen Sie je Zeile ein, leer bedeutet Standardwert. Farben: `Text=Farbe;Text=Farbe` mit
  rot, orange, gruen, blau, grau oder 6 Hex-Stellen. `battery_soc` ist eine normale Kachel.
- Nach dem Update des Moduls den Symcon-Dienst neu starten, damit die neue Eigenschaft `Pages` angelegt wird.

Die vorbelegten Keys passen zur Firmware; nur die Variablen muessen noch zugeordnet werden.

**Umlaute:** Die Firmware zeigt Ä Ö Ü ä ö ü ß in Titeln, Beschriftungen, Seitennamen und Profiltexten. Andere Sonderzeichen fehlen in der Schrift.
Seitennamen hoechstens etwa 10 Zeichen, sonst passen 5 Reiter nicht in die Leiste.

## Funktionen

```php
CKD_PublishAll(int $InstanzID); // alle Werte jetzt senden, auch als Button im Formular
```

RX/TX stehen im Debug-Fenster der Instanz.

## Test ohne Symcon

```bash
php -d zend.assertions=1 -d assert.exception=1 test/live.php
```
