# Diagramme (Energiedashboard Stufe 3)

Stand: 2026-11-06. Entwurf im Chat freigegeben (Stufe 3: Liste "Diagramme", Heute/Tage/Monate aus archivierten
kWh-Zaehlern, einmaliger Dienst-Neustart). Nutzer laesst weiterbauen, Nachkommastellen je Diagramm gewuenscht.

## Ziel

Linien fuer heute (Leistung in kW, 15-Minuten-Punkte) und Saeulen fuer 7 Tage, 14 Tage oder 12 Monate (Energie in kWh),
mit mehreren Reihen, aus dem Symcon-Archiv. Platzierung wie alle Elemente, Standardgroesse ganze Seite.

Fertig ist es, wenn:

1. Eine Diagrammzeile mit archiviertem kWh-Zaehler nach dem Speichern ohne Flashen als Diagramm erscheint.
2. Mehrere Zeilen mit gleichem Key als Reihen mit eigener Farbe und Legende erscheinen.
3. `Heute` den Tagesverlauf bis jetzt zeigt, `7 Tage`/`14 Tage`/`12 Monate` je Zeitraum eine Saeulengruppe mit Beschriftung.
4. Die Werte alle 5 Minuten und beim Neustart von Display oder MQTT Server aktualisiert werden.
5. Ohne Archiv oder ohne Archivierung der Variable nichts abstuerzt (Debug-Eintrag, leere Reihe).

## Nicht enthalten

Autarkie/Eigenverbrauch in %, Zoom, Antippen fuer Werte, eigene Zeitraeume, Wochen/Jahre. Spaeter ergaenzbar.

## Formular

Neue Property `Charts` (Liste "Diagramme"), deshalb einmal den Symcon-Dienst neu starten. Spalten:

| Spalte | Bedeutung | Leer |
|---|---|---|
| Key | Name des Diagramms; gleiche Keys = mehrere Reihen | Zeile ignoriert |
| Page, Size, Column, Row | wie bei Kacheln, aus der ersten Zeile | Start, 4x3 (auch bei 1x1), automatisch |
| Type | `Heute`, `7 Tage`, `14 Tage`, `12 Monate`, aus der ersten Zeile | `Heute` |
| Title | Ueberschrift, aus der ersten Zeile | Key |
| Unit | Einheit, aus der ersten Zeile | `kW` bei Heute, sonst `kWh` |
| Decimals | Nachkommastellen der Werte (0 bis 3), aus der ersten Zeile | 1 |
| Series | Name der Reihe in der Legende | Variablenname |
| Color | `gelb`, `blau`, `rot`, `gruen`, `orange`, `lila`, `grau` oder 6 Hex-Stellen | Farbfolge |
| VariableID | archivierte Variable | Reihe fehlt |
| Factor | Faktor auf jeden Wert | 1 |

## Nachkommastellen der Kacheln

Die Kachelliste hat die Spalte Stellen schon; sie gilt auch fuer den Knoten im Energiefluss. Neuer Standard fuer
`wallbox_power`: 0 Stellen statt 2 (Wunsch des Nutzers). Bestehende Zeilen mit leerer Spalte zeigen danach keine
Nachkommastellen mehr, eine eingetragene Zahl gilt weiter.

## Daten

Archiv: erste Instanz von Archive Control. Pro Reihe `AC_GetAggregationType` und `AC_GetAggregatedValues`:

| Typ | Stufe | Zeitraum | Zaehler-Variable (Avg = Zuwachs) | Standard-Variable (Avg = Mittelwert) |
|---|---|---|---|---|
| Heute | 5 Minuten | heute 0 Uhr bis jetzt | Summe je 15 Min x 4 (kWh -> kW) | Mittel je 15 Min |
| 7/14 Tage | Tag | letzte N Tage inkl. heute | Zuwachs je Tag | Mittel x Stunden des Zeitraums |
| 12 Monate | Monat | letzte 12 Monate inkl. aktuellem | Zuwachs je Monat | Mittel x Stunden des Zeitraums |

Danach mal Faktor, gerundet auf die Stellen. Fehlende Zeitraeume sind `null`.

## MQTT

Layout-Element `{"t":"chart","k":"pv_tag","l":"PV heute","s":"4x3","x":0,"y":0}`.

Neues Topic `colorkit/chart/<key>` (retained), hoechstens 4096 Byte, sonst nicht gesendet (Debug):

```json
{"t":"bar","u":"kWh","d":1,"x":["Sa","So","Mo","Di","Mi","Do","Fr"],
 "s":[{"n":"PV","c":"FFB300","v":[12.3,8.1,null,4,5,6,7.5]}]}
```

`t`: `line` (96 Punkte ab 0 Uhr, nur bis jetzt gefuellt) oder `bar`; `x` nur bei `bar`. Gesendet bei `ApplyChanges`,
`online`, im Republish-Timer und zusaetzlich alle 5 Minuten (eigener Timer, nur wenn Diagramme konfiguriert sind).

Beschriftung: 7 Tage Wochentag (`Mo`), 14 Tage Datum (`06.11`), Monate (`Jan`).

## Firmware

- Abonniert `chart/#`, merkt sich je Key die letzte Nachricht und wendet sie auf passende Diagramme an (auch spaeter
  gebaute).
- `lv_chart` in einer Kachel: Titel mit Einheit oben links, farbige Legende oben rechts, Y-Achse mit 5 Beschriftungen,
  X-Achse 0/6/12/18/24 Uhr bzw. die Beschriftungen aus `x`.
- Y-Bereich von 0 (oder dem negativen Minimum) bis zur naechsten runden Zahl (1/2/5 x 10^n). Werte intern skaliert,
  damit kWh-Monatswerte nicht ueberlaufen.
- Neuzeichnung nur bei geaenderter Nachricht. Wischen ueber das Diagramm wechselt die Seite.

## Test

- `test/live.php` mit Archiv-Stubs: Heute Zaehler und Standard, 7 Tage mit Wochentagen, 12 Monate mit Monatsnamen,
  Farben (Name, Hex, Folge), Stellen, fehlendes Archiv, nicht archivierte Variable, Layout-Element mit 4x3-Standard.
- Geraet: `test/layout-sample.py ... chart` mit Beispieldaten, Sichtpruefung durch den Nutzer.

## Risiken

- Symcon braucht fuer 5-Minuten-Werte etwas Rechenzeit; bei wenigen Reihen alle 5 Minuten unkritisch.
- Archive-Control-GUID `{43192F0B-135B-4CE7-A0A7-1475603F3060}` stammt aus der Erfahrung, nicht aus der Doku; wird beim
  Nutzer geprueft (Debug "kein Archiv").
