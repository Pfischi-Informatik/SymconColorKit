# Mehrere Seiten auf dem Color Kit

Stand: 2026-11-04, Konzept vom Nutzer freigegeben.

## Ziel

Das Display zeigt bis zu 5 Seiten. Seiten, Kacheln und Buttons werden im Symcon-Formular gepflegt. Eine neue Kachel braucht
kein Flashen mehr. Gewuenschte Inhalte: Start, Energie, Heizung, Licht/Schalten, Wetter/Sonstiges.

Fertig ist es, wenn:

1. Eine im Formular angelegte Kachel nach dem Speichern ohne Flashen auf der gewaehlten Seite erscheint.
2. Alle Reiter per Tippen und Wischen erreichbar sind.
3. Die bestehende Instanz nach dem Update ohne Nacharbeit dieselben Inhalte auf der Seite "Start" zeigt.
4. Die Buttons auf jeder Seite `cmd/<key>` senden und ihren Zustand anzeigen.
5. Nach einem Neustart des Displays oder des MQTT Servers alle Seiten und Werte von selbst wieder erscheinen.

## Nicht enthalten

Echte Untermenues, Kurven und Tagesdiagramme, Wetter-Symbole, freie Platzierung im Raster. Alles spaeter ergaenzbar.

## Display

```
Kopfzeile (Uhr, Akku, WLAN/MQTT)    bleibt auf allen Seiten sichtbar
Raster 4 x 3                        12 Plaetze je Seite, Kacheln und Buttons gemischt
Reiterleiste unten, 40 px           Tippen oder Wischen wechselt die Seite
```

- Plaetze werden in Reihenfolge der Formular-Listen gefuellt: zuerst alle Kacheln der Seite, dann alle Buttons.
- Kachelhoehe etwa 76 px statt bisher 96 px.
- Bevor die erste Seitenliste ankommt, zeigt das Display "Warte auf Symcon". Die Kopfzeile laeuft dabei normal.

## Formular

Neue Property `Pages` (Liste, Spalte Name, hoechstens 5). Vorbelegung: `Start`.

`Tiles` bekommt zusaetzlich:

| Spalte | Bedeutung | Fehlt in gespeicherter Zeile |
|---|---|---|
| Page | Seitenname (Auswahl als Textfeld) | `Start` |
| Title | Titel auf der Kachel | Standardwert je Key, sonst der Key |
| Unit | Einheit | Standardwert je Key, sonst leer |
| Decimals | Nachkommastellen, -1 = Text unveraendert | Standardwert je Key, sonst -1 |
| Colors | Farbregeln, z.B. `Standby=orange;Aus=rot;Ein=gruen` | Standardwert je Key, sonst leer |

`Buttons` bekommt zusaetzlich Page und Label (fehlt: `Start` bzw. Standardwert je Key, sonst der Key).

Standardwerte je Key entsprechen der heutigen Firmware-Tabelle:

| Key | Title | Unit | Decimals | Colors |
|---|---|---|---|---|
| `pv_power` | PV | W | 0 | |
| `house_power` | Haus | W | 0 | |
| `grid_power` | Netz | W | 0 | |
| `wallbox_power` | Wallbox | kW | 2 | |
| `hp_mode` | WP Modus | | -1 | `Standby=orange;Aus=rot;Ein=gruen` |
| `hp_flow` | WP Vorlauf | °C | 1 | |
| `room_temp` | Raum | °C | 1 | |
| `outdoor_temp` | Aussen | °C | 1 | |

Buttons: `wallbox_pv` = Wallbox PV, `wallbox_fast` = Sofortladen, `dhw_boost` = WW Boost.

Regeln:

- Bei mehreren Zeilen mit gleichem Key legt die erste Zeile Seite, Titel, Einheit, Nachkommastellen und Farben fest. Die
  weiteren Zeilen liefern nur Werte, wie heute bei der Berechnung.
- `battery_soc` erscheint immer in der Kopfzeile, nie im Raster.
- Farbregeln: `Text=Farbe`, getrennt durch `;`. Farbe ist `rot`, `orange`, `gruen`, `blau`, `grau` oder 6 Hex-Stellen.
  Der Vergleich mit dem Kachelwert ignoriert Gross- und Kleinschreibung. Ungueltige Regeln werden uebersprungen und im
  Debug gemeldet.
- Zeile mit unbekannter Seite: Sie erscheint auf der ersten Seite, Debug-Eintrag. Ihr Wert wird weiter gesendet.
- Mehr als 12 Eintraege auf einer Seite oder mehr als 5 Seiten: Der Ueberschuss wird nicht angezeigt, Debug-Eintrag.
  Die Werte werden weiter gesendet.

## MQTT

Bestehend und unveraendert: `state/<key>`, `cmd/<key>`, `status`, `diag`.

Neu, beide retained, Modul an Display:

**`colorkit/pages`**: Seitennamen in Reihenfolge.

```json
["Start","Energie","Heizung"]
```

**`colorkit/layout/<n>`**: Inhalt der Seite n, ab 0.

```json
[
  {"t":"tile","k":"hp_mode","l":"WP Modus","u":"","d":-1,"c":[["Standby","EF6C00"],["Aus","C62828"],["Ein","2E7D32"]]},
  {"t":"tile","k":"pv_power","l":"PV","u":"W","d":0},
  {"t":"btn","k":"wallbox_pv","l":"Wallbox PV"}
]
```

- Das Modul sendet `pages` und alle `layout/<n>` in `ApplyChanges` und bei `status = online`, vor den Werten.
- Fuer nicht genutzte Seiten bis Index 4 sendet es ein leeres retained Payload, damit alte Seiten vom Server verschwinden.
- Eine Beschreibung darf hoechstens 4096 Byte lang sein. Das Modul prueft das und sendet groessere nicht, Debug-Eintrag.

Reihenfolge ist nicht garantiert, weil retained Nachrichten beliebig ankommen. Deshalb gilt in der Firmware:

- Letzter Wert je Key wird zwischengespeichert. Wird eine Seite gebaut, erhaelt sie sofort die bekannten Werte.
- Ein Layout mit Index ausserhalb der Seitenliste wird ignoriert.

## Firmware

- `TILES`, `BUTTONS`, `TILE_COLORS` entfallen aus `config.h`. `BATTERY_KEY` bleibt.
- Neue Bibliothek ArduinoJson, MQTT-Puffer 4096 Byte.
- Reiter und Wischen mit dem LVGL-Tabview. `lv_conf.h` muss Tabview und genug LVGL-Speicher fuer 5 x 12 Eintraege
  freigeben; die Groesse wird im Plan am Geraet gemessen.
- Beim Empfang von `pages` oder `layout/<n>`: Nur bauen, wenn der Inhalt sich geaendert hat, und nur die betroffene Seite.
- Ungueltiges JSON: verwerfen, bisherige Ansicht bleibt, Meldung auf der seriellen Ausgabe.
- Nach dem Umbau: `DEBUG_TOUCH 0`. Touch-Lesen, WLAN und Diagnose bleiben unveraendert.

## Migration

Beim ersten `ApplyChanges` nach dem Update fehlen die neuen Spalten. Das Modul ergaenzt sie beim Lesen zur Laufzeit nach den
Regeln oben und schreibt die gespeicherten Properties nicht um. Zuordnungen bleiben dadurch erhalten. Die Spalten im Formular
zeigen diese Werte erst, wenn die Zeile bearbeitet wird; das Display zeigt sie sofort richtig.

Reihenfolge fuer den Nutzer: zuerst Modul aktualisieren, dann Firmware flashen. Die alte Firmware ignoriert die neuen Topics.

## Test

- `test/live.php`: Layout-JSON je Seite, Standardwerte bei alten Zeilen, Farbregeln einschliesslich ungueltiger, erste Zeile
  gewinnt, `battery_soc` nicht im Raster, Grenzen 5 Seiten / 12 Eintraege / 4096 Byte, leere Payloads, Reihenfolge bei `online`.
- Firmware am Geraet: Layouts per MQTT einspielen und ueber serielle Ausgabe und Sichtpruefung des Nutzers kontrollieren:
  Tippen auf jeden Reiter, Wischen, Buttons, freier Speicher nach 5 vollen Seiten, Neustart des Displays.
- Formular: kann nur der Nutzer in echtem Symcon pruefen.

## Risiken

- Der LVGL-Speicher kann fuer 5 volle Seiten knapp werden. Wird im Plan zuerst gemessen; Ausweg sind PSRAM oder einfachere Kacheln.
- Wischen kann mit dem Scrollen der Reiter kollidieren; das wird am Geraet geprueft. Tippen bleibt der verlaessliche Weg.
- Das schwache WLAN kann das Flashen wie bisher mehrere Versuche kosten. Nach dem Umbau ist Flashen nur noch selten noetig.
