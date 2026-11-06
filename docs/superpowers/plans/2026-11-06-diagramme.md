# Diagramme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Linien (heute) und Saeulen (7/14 Tage, 12 Monate) aus dem Symcon-Archiv auf dem Color Kit, plus Wallbox ohne
Nachkommastellen.

**Architecture:** Das Modul liest das Archiv, rechnet Reihen und sendet sie retained als JSON auf `chart/<key>`; das Layout
bekommt ein Element `chart`. Die Firmware merkt sich die Chart-Nachrichten und zeichnet sie mit `lv_chart`.

**Tech Stack:** PHP 8 (Symcon 7.0), ESP32 Arduino, LVGL 8.3, ArduinoJson 7, PubSubClient.

**Spec:** `docs/superpowers/specs/2026-11-06-diagramme-design.md`

## Global Constraints

- Neue Property `Charts` (Dienst-Neustart noetig), Timer `Charts` 300 s, nur aktiv mit Diagrammen.
- Archiv-Stufen: 5 = 5 Minuten, 1 = Tag, 3 = Monat. Zaehler (`AC_GetAggregationType` = 1): Avg = Zuwachs.
- Topic `<Basis>/chart/<key>` retained, hoechstens 4096 Byte. Layout-Element `{"t":"chart","k","l"}`, Standardgroesse 4x3.
- Farben: gelb FFB300, blau 1565C0, rot C62828, gruen 2E7D32, orange EF6C00, lila 6A1B9A, grau 546E7A; Folge in dieser
  Reihenfolge ohne grau.
- Stellen 0 bis 3, leer = 1. `wallbox_power` Standard 0 Stellen.
- Firmware: `include/config.h` nie lesen oder ausgeben.

## Review Focus

1. Tag mit Sommer-/Winterzeitwechsel: 15-Minuten-Raster ab Mitternacht verschiebt sich um eine Stunde (ponytail: bewusst).
2. Grosse Monatswerte (z. B. 1500 kWh) und negative Werte (Einspeisung): Y-Achse und int16-Grenze von lv_chart.
3. Archiv fehlt, Variable nicht archiviert oder geloescht: kein Absturz, Debug, leere Reihe.
4. Mehr als 4 Reihen oder 14 Tage x 4 Reihen: Nachricht unter 4096 Byte, Firmware-Puffer reicht.
5. Diagramm kommt vor oder nach dem Layout an: beide Reihenfolgen zeigen die Daten.

---

### Task 1: Modul — Wallbox-Stellen, Liste Diagramme, Archivdaten, Topic

**Files:** `ColorKitDashboard/module.php`, `ColorKitDashboard/form.json`, `test/live.php`, `README.md`, `library.json` (build 12)

**Produces:** Layout-Element `{"t":"chart","k","l","s"?, "x","y"}`; Topic `chart/<key>` mit
`{"t":"line"|"bar","u","d","x"?,"s":[{"n","c","v"}]}`; `public function PublishCharts(): void`.

- [ ] Tests zuerst in `test/live.php` (Archiv-Stubs `IPS_GetInstanceListByModuleID`, `AC_GetLoggingStatus`,
  `AC_GetAggregationType`, `AC_GetAggregatedValues`, `IPS_GetName`; Timer je Name):
  - wallbox_power ohne Stellen -> `"d":0`
  - Heute, Zaehler: drei 5-Min-Werte 0,1/0,2/0,3 kWh im ersten Viertel -> erster Punkt 2,4 kW; Liste endet beim
    aktuellen Viertel; Luecken `null`
  - Heute, Standardvariable: Mittel je Viertel
  - 7 Tage: Beschriftung Wochentage bis heute, Zaehler-Zuwachs je Tag, fehlender Tag `null`
  - 14 Tage: Beschriftung `TT.MM`
  - 12 Monate: Beschriftung `Jan`.., Standardvariable Mittel x Dauer/3600
  - zwei Zeilen gleicher Key = zwei Reihen; Farbe Name, Hex, Folge; Reihenname leer = Variablenname; Faktor; Stellen
  - kein Archiv / nicht archiviert -> Reihe mit `"v":[]`; nicht existierende Variable -> Reihe fehlt
  - Layout: chart 4x3 bei leer/1x1, Titel leer = Key; Seite `-` nicht im Layout, Daten gesendet
  - Timer `Charts` 300000 mit Diagrammen, 0 ohne
- [ ] Laufen lassen, FAIL
- [ ] Umsetzen; `php -l` und Test: OK
- [ ] Commit

### Task 2: Firmware — chart-Element

**Files:** `src/pages.cpp`, `src/pages.h`, `src/main.cpp`, `README.md`; `test/layout-sample.py` (Modul-Repo) Modus `chart`

- [ ] `chart/#` abonnieren, `pagesOnChart(key, payload, len)`; Nachrichten je Key merken (hoechstens 8)
- [ ] `createChart`: Rahmen, Titel + Einheit, Legende, `lv_chart` (Linie 96 Punkte bzw. Saeulen je `x`), Y-Skala rund
  (1/2/5 x 10^n), Werte x 10^d skaliert, Achsenbeschriftung ueber `LV_EVENT_DRAW_PART_BEGIN`
- [ ] Bauen (`pio run -e ota`), Flash/RAM notieren, per OTA flashen, `layout-sample.py ... chart` einspielen, Foto vom Nutzer
- [ ] Commit

### Task 3: Review und Abschluss

- [ ] Abschlussreview (frischer Reviewer), eine Fixrunde, README, Push beider Repos
