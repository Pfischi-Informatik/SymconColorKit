# Energiefluss (Energiedashboard Stufe 2)

Stand: 2026-11-06. Entwurf im Chat freigegeben ("Ja, passt", drei Stufen), Nutzer laesst weiterbauen und prueft spaeter.
Baut auf Stufe 1 auf (Groessen, Positionen, Seite `-`).

## Ziel

Ein Element zeigt live, wohin der Strom fliesst: PV, Netz, Akku, Wallbox und Waermepumpe als Knoten um das Haus, mit
Linien und einem wandernden Punkt in Flussrichtung.

Fertig ist es, wenn:

1. Eine Kachelzeile mit Key `energy_flow` und Groesse `4x3` ohne Flashen eine Seite mit dem Energiefluss fuellt.
2. Jeder vorhandene Key aus der Liste unten als Knoten mit Wert und Einheit erscheint, fehlende Keys keinen Knoten haben.
3. Der Punkt bei Netzbezug vom Netz zum Haus laeuft, bei Einspeisung umgekehrt, beim Akku entsprechend Laden/Entladen.
4. Werte von Zeilen mit Seite `-` den Fluss speisen, ohne selbst als Kachel zu erscheinen.

## Nicht enthalten

Direkte Linien PV -> Netz oder PV -> Akku (alles laeuft sternfoermig ueber das Haus), Liniendicke nach Leistung,
Tagesenergie. Verlauf und Saeulen sind Stufe 3.

## Formular

Keine neue Property, keine neue Spalte. In der Spalte Darstellung gibt es zusaetzlich `Energiefluss`.

- Key `energy_flow` hat als Standard Darstellung `Energiefluss` und Titel `Energiefluss`. Eine Variable braucht die Zeile
  nicht.
- Jede andere Kachelzeile mit Darstellung `Energiefluss` erzeugt ebenfalls ein Fluss-Element.
- Empfohlene Groesse `4x3`, mindestens `4x2`. Kleiner zeigt die Firmware einen Hinweis statt des Flusses.

Knoten und ihre Keys (feste Zuordnung):

| Knoten | Key | Vorzeichen |
|---|---|---|
| PV | `pv_power` | positiv = Erzeugung |
| Netz | `grid_power` | positiv = Bezug, negativ = Einspeisung |
| Haus (Mitte) | `house_power` | Verbrauch |
| Akku | `battery_power` | positiv = Laden, negativ = Entladen |
| Wallbox | `wallbox_power` | Verbrauch |
| WP | `hp_power` | Verbrauch |

Neue Standardwerte: `battery_power` = Akku, W, 0; `hp_power` = WP, W, 0. Passt ein Vorzeichen nicht, dreht der Nutzer es
mit Faktor `-1`. Der Akku-Knoten zeigt zusaetzlich `battery_soc` in %, wenn vorhanden.

Ein Knoten erscheint, wenn sein Key irgendwo in der Kachelliste steht, auch auf Seite `-`. Titel, Einheit und Stellen
kommen aus dieser Zeile bzw. den Standardwerten. Das Haus erscheint immer.

## MQTT

Neuer Elementtyp in `layout/<n>`:

```json
{"t":"flow","k":"energy_flow","l":"Energiefluss","s":"4x3","x":0,"y":0,
 "n":[["pv_power","PV","W",0],["grid_power","Netz","W",0],["house_power","Haus","W",0],["battery_power","Akku","W",0]]}
```

`n`: Knoten als `[key, titel, einheit, stellen]`, nur vorhandene Keys. Die Werte kommen wie bisher ueber `state/<key>`.

## Firmware

- Feste Anordnung relativ zur Elementgroesse: PV oben Mitte, Netz links, Haus Mitte, Wallbox rechts, Akku unten links,
  WP unten rechts. Knoten 100 x 48 px mit Titel und Wert (20 px).
- Linie vom Knoten zum Haus, grau ohne Fluss. Mit Fluss eingefaerbt und ein Punkt laeuft in Flussrichtung (1,6 s, endlos).
  Farben: PV gelb, Netzbezug rot, Einspeisung gruen, Akku laden gruen, entladen orange, Verbraucher blau.
- Kein Fluss, wenn der auf die Stellen gerundete Wert 0 ist. Angezeigt wird der Betrag mit Einheit.
- Titel wechselt beim Netz auf `Bezug`/`Einspeisung`, beim Akku auf `Laden`/`Entladen`, plus Ladestand.
- Neuzeichnung nur bei Aenderung; die Punkte invalidieren nur ihre kleine Flaeche.

## Test

- `test/live.php`: Fluss-Element mit Knoten nur fuer vorhandene Keys (auch Seite `-`), Titel/Einheit/Stellen aus Zeile und
  Standard, Zeile ohne Variable, Darstellung `Energiefluss` auf anderem Key, Groesse und Position wie andere Elemente.
- Geraet: `test/layout-sample.py ... flow` mit Beispielwerten, danach umgedrehte Vorzeichen. Sichtpruefung durch den Nutzer.

## Risiken

- Die laufenden Punkte erzeugen staendige kleine Neuzeichnungen. Auf dem langsamen Panel ist das bei 5 Punkten von je
  10 x 10 px unkritisch, wird am Geraet beobachtet (Touch-Reaktion).
