# Groessen und Anzeigen (Energiedashboard Stufe 1)

Stand: 2026-11-06, Entwurf vom Nutzer freigegeben. Stufe 2 (Energiefluss) und Stufe 3 (Verlauf und Saeulen) bauen
darauf auf und bekommen eigene Spezifikationen.

## Ziel

Jedes Element bekommt im Formular eine Groesse von 1x1 bis zur ganzen Seite. Kacheln koennen ihren Wert zusaetzlich als
Rundanzeige oder Balken zeigen. Werte koennen gesendet werden, ohne dass eine Kachel erscheint.

Fertig ist es, wenn:

1. Eine Kachel mit Groesse `2x2` nach dem Speichern ohne Flashen vier Plaetze belegt und den Wert groesser zeigt.
2. `battery_soc` als Rundanzeige mit Maximum 100 den Fuellstand als Bogen zeigt, eine Kachel als Balken entsprechend.
3. Eine Zeile mit Seite `-` keinen Platz belegt, ihr Wert aber weiter unter `state/<key>` gesendet wird.
4. Die bestehende Instanz nach dem Update ohne Nacharbeit genauso aussieht wie vorher.
5. Ein Element, das auf der Seite keinen Platz mehr findet, nicht erscheint und im Debug gemeldet wird.
6. Ein Element mit Spalte und Zeile genau dort erscheint, die anderen fuellen die freien Plaetze.

## Nicht enthalten

Energiefluss, Diagramme, Minimum fuer Anzeigen, Groessen fuer die Reiterleiste.

## Formular

Keine neue Property, daher kein Neustart des Symcon-Dienstes.

`Tiles` bekommt zusaetzlich:

| Spalte | Bedeutung | Fehlt in gespeicherter Zeile |
|---|---|---|
| Size | `1x1`, `2x1`, `2x2`, `4x1`, `4x2`, `4x3` (Breite x Hoehe in Plaetzen) | `1x1` |
| Display | `Zahl`, `Rundanzeige`, `Balken` | Standardwert je Key, sonst `Zahl` |
| Max | Maximum fuer Rundanzeige und Balken, leer = Standardwert je Key, sonst 100 | wie leer |
| Column | Spalte 1 bis 4 der linken oberen Ecke, leer = automatisch | leer |
| Row | Zeile 1 bis 3 der linken oberen Ecke, leer = automatisch | leer |

`Buttons` bekommt zusaetzlich `Size`, `Column` und `Row` (gilt fuer Button und Dimmer).

Standardwerte: `battery_soc` Rundanzeige, Max 100. Alle anderen Keys `Zahl`.

Regeln:

- Seite `-`: Die Zeile erscheint auf keiner Seite, der Wert wird weiter gesendet. Fuer Stufe 2 gedacht.
- Bei mehreren Kachel-Zeilen mit gleichem Key gelten Size, Display, Max, Column und Row aus der ersten Zeile.
- Nur Spalte oder nur Zeile gesetzt, oder ein Wert ausserhalb: automatisch platzieren, Debug-Eintrag.
- Ungueltige Size wird zu `1x1`, Debug-Eintrag.

## Platzierung

Raster 4 Spalten x 3 Zeilen je Seite. Zwei Durchgaenge, jeweils in Reihenfolge der Listen (erst Kacheln, dann Buttons):

1. Elemente mit Spalte und Zeile kommen genau dorthin. Ist der Bereich belegt oder ragt er ueber den Rand, wird das
   Element im zweiten Durchgang automatisch platziert, Debug-Eintrag.
2. Alle uebrigen kommen auf den ersten freien Platz, an dem das ganze Element passt, zeilenweise von oben links. Passt
   ein Element nicht mehr, wird es uebersprungen und das naechste versucht, Debug-Eintrag.

Das Modul rechnet die Platzierung und sendet die Position jedes Elements mit. Die Firmware setzt die Elemente an diese
Positionen; fehlt die Position (alte Modulversion, Testwerkzeug), platziert sie nach derselben Regel selbst. Die Grenze
von 12 Eintraegen je Seite entfaellt zugunsten der Belegung.

## MQTT

`layout/<n>` bekommt immer `x`/`y` (Spalte/Zeile ab 0) und optionale Felder, nur wenn sie vom Standard abweichen:

```json
{"t":"tile","k":"battery_soc","l":"Akku","u":"%","d":0,"s":"2x2","v":"arc","m":100,"x":0,"y":0}
{"t":"tile","k":"pv_power","l":"PV","u":"W","d":0,"v":"bar","m":10000,"x":2,"y":0}
{"t":"dim","k":"light_dim","l":"Buero","s":"2x1","x":2,"y":2}
```

- `s`: Groesse, fehlt = `1x1`
- `v`: `arc` oder `bar`, fehlt = Zahl
- `m`: Maximum, nur bei `arc`/`bar`
- `x`, `y`: linke obere Ecke, Spalte 0 bis 3, Zeile 0 bis 2

## Firmware

- Elementgroesse: `w * TW + (w - 1) * GAP` bzw. fuer die Hoehe entsprechend.
- Wertschrift: 28 px bei Hoehe 1, 48 px ab Hoehe 2. `font_de_48` mit Fallback auf `lv_font_montserrat_48` wird mit
  `tools/gen_font_de.py` erzeugt.
- Rundanzeige: `lv_arc` (0 bis Max, Werte ausserhalb begrenzt), Wert in der Mitte, Titel oben.
- Balken: `lv_bar` unter dem Wert, 0 bis Max.
- Buttons und Dimmer werden auf die Groesse gestreckt, der Dimmer-Regler wird breiter.
- Eine Neuzeichnung nur bei geaendertem Wert, wie bisher.

## Migration

Fehlende Spalten werden zur Laufzeit ergaenzt, gespeicherte Properties bleiben unveraendert. Alte Firmware ignoriert
`s`, `v`, `m`, `x` und `y` und zeigt alles als 1x1. Reihenfolge fuer den Nutzer: Firmware flashen, dann Modul aktualisieren.

## Test

- `test/live.php`: feste Positionen (belegt, Rand, nur Spalte), Platzierung (2x2 neben 1x1, 4x3 fuellt die Seite, Ueberlauf wird uebersprungen und das naechste
  passende Element gesetzt), Seite `-`, Standardwerte bei alten Zeilen, Felder `s`/`v`/`m` nur bei Abweichung, `x`/`y` immer.
- Geraet: Layouts per `test/layout-sample.py` einspielen, Sichtpruefung des Nutzers, Heap nach vollen Seiten.

## Risiken

- 48-px-Schrift kostet Flash (geschaetzt 100 bis 150 kB bei 58,9 % Belegung). Wird beim Bauen gemessen.
- Rundanzeige auf 1x1 (112 x 73 px) ist klein. Empfohlen ist `2x2`, 1x1 bleibt erlaubt.
