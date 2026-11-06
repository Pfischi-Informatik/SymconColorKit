# Groessen und Anzeigen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Elemente bekommen im Formular eine Groesse von 1x1 bis 4x3, Kacheln optional Rundanzeige oder Balken, Seite `-` sendet nur.

**Architecture:** Das Modul platziert die Elemente per First-Fit im 4x3-Raster je Seite, verwirft was nicht passt und
schreibt `s`, `v`, `m` nur bei Abweichung ins Layout-JSON. Die Firmware platziert nach derselben Regel, rechnet Pixelgroessen
aus der Belegung und baut `lv_arc`/`lv_bar` in die Kachel. Kein neues Topic, keine neue Property.

**Tech Stack:** PHP 8 (IP-Symcon 7.0 `IPSModule`), ESP32 Arduino mit PlatformIO, LVGL 8.3, ArduinoJson 7, PubSubClient.

**Spec:** `docs/superpowers/specs/2026-11-06-groessen-anzeigen-design.md`

## Global Constraints

- Modul-Repo `~/Documents/Claude/SymconColorKit`, Firmware-Repo `~/Documents/Linde-Mig-Kontakte/colorkit-dashboard` (Git, privat).
- `include/config.h` der Firmware enthaelt echte Zugangsdaten: nie lesen, ausgeben, kopieren oder committen.
- Keine neue Property (kein Dienst-Neustart). Neue Spalten werden zur Laufzeit mit Standardwerten ergaenzt.
- Groessen: `1x1`, `2x1`, `2x2`, `4x1`, `4x2`, `4x3`. Raster 4 Spalten x 3 Zeilen, hoechstens 5 Seiten.
- Layout-JSON hoechstens 4096 Byte je Seite (bestehende Pruefung bleibt).
- Felder `s`, `v`, `m` nur senden, wenn sie vom Standard abweichen (`1x1`, Zahl).
- Strings in PHP und JSON mit ae/oe/ue, Captions deutsch. Commits als Dominik Pfister <dominikpfister@hotmail.com>.
- `library.json` build 10.
- Wertschrift: 28 px bei Hoehe 1, 48 px ab Hoehe 2 (`font_de_48`, Fallback `lv_font_montserrat_48`).

## Review Focus

1. Alte Zeilen ohne `Size`/`Display`/`Max`: Layout-JSON ist identisch zu vorher (keine Felder `s`/`v`/`m`). Test in Task 1.
2. `Max` leer, nicht numerisch oder <= 0: Standardwert je Key, sonst 100. Test in Task 1; Firmware begrenzt zusaetzlich.
3. Ungueltige Groesse (`3x1`, `abc`) wird `1x1`, Schreibweise `2X2` und Leerzeichen werden akzeptiert. Test in Task 1.
4. Seite ` - ` (mit Leerzeichen) ist versteckt, der Wert wird trotzdem gesendet. Test in Task 1.
5. Ein `4x3` auf einer Seite, auf der schon etwas steht, passt nicht und wird uebersprungen; ein spaeteres 1x1 wird trotzdem gesetzt. Test in Task 1.

---

### Task 1: Modul – Groesse, Darstellung, Seite `-`, Platzierung

**Files:**
- Modify: `ColorKitDashboard/module.php` (Konstanten bei Zeile 37-60, `layouts()` ab Zeile 182, `addItem()` ab Zeile 212)
- Modify: `ColorKitDashboard/form.json` (Tiles-Spalten nach `Colors`, Buttons-Spalten nach `ScriptID`)
- Modify: `README.md` (Abschnitt Konfiguration), `library.json` (build 10)
- Test: `test/live.php` (vor dem abschliessenden `echo "OK\n";`)

**Interfaces:**
- Consumes: bestehende `rows()`, `tileGroups()`, `pageNames()`, Test-Helfer `layouts($m)` und `rx()` in `test/live.php`.
- Produces: Layout-Elemente mit optionalen Feldern `s` (string, z.B. `"2x2"`), `v` (`"arc"`|`"bar"`), `m` (int|float). Task 2 liest genau diese Felder.

- [ ] **Step 1: Failing tests schreiben**

In `test/live.php` direkt vor der letzten Zeile `echo "OK\n";` einfuegen:

```php
// ===== Groessen und Anzeigen =====
function page0(ColorKitDashboard $m): array
{
    $s = array_column(layouts($m), 1, 0);
    $m->sent = [];
    return json_decode($s['colorkit/layout/0'], true);
}
$g = new ColorKitDashboard();
$g->Create();
$g->active = true;
$vars[950] = [68, VARIABLETYPE_INTEGER, 0, 1, ''];
$g->props['Pages'] = json_encode([['Name' => 'Start']]);
$g->props['Buttons'] = '[]';

// Alte Zeilen: keine neuen Felder, battery_soc ist Rundanzeige mit Max 100
$g->props['Tiles'] = json_encode([
    ['Key' => 'pv_power', 'VariableID' => 950, 'Factor' => 1],
    ['Key' => 'battery_soc', 'VariableID' => 950, 'Factor' => 1],
]);
$g->PublishAll();
$l = page0($g);
assert($l[0] == ['t' => 'tile', 'k' => 'pv_power', 'l' => 'PV', 'u' => 'W', 'd' => 0]);
assert($l[1] == ['t' => 'tile', 'k' => 'battery_soc', 'l' => 'Akku', 'u' => '%', 'd' => 0, 'v' => 'arc', 'm' => 100]);

// Groesse, Balken, Max-Regeln, Schreibweise
$g->props['Tiles'] = json_encode([
    ['Key' => 'a', 'VariableID' => 950, 'Factor' => 1, 'Size' => ' 2X2 ', 'Display' => 'Balken', 'Max' => '10000'],
    ['Key' => 'b', 'VariableID' => 950, 'Factor' => 1, 'Size' => '3x1', 'Display' => 'Rundanzeige', 'Max' => 'abc'],
    ['Key' => 'c', 'VariableID' => 950, 'Factor' => 1, 'Display' => 'Rundanzeige', 'Max' => '-5'],
    ['Key' => 'd', 'VariableID' => 950, 'Factor' => 1, 'Display' => 'Balken', 'Max' => '0.5'],
    ['Key' => 'e', 'VariableID' => 950, 'Factor' => 1, 'Display' => 'Zahl', 'Max' => '50'],
]);
$g->PublishAll();
$l = page0($g);
assert($l[0] == ['t' => 'tile', 'k' => 'a', 'l' => 'a', 'u' => '', 'd' => -1, 'v' => 'bar', 'm' => 10000, 's' => '2x2']);
assert(!isset($l[1]['s']) && $l[1]['m'] === 100);
assert($l[2]['m'] === 100);
assert($l[3]['m'] === 0.5);
assert(!isset($l[4]['v']) && !isset($l[4]['m']));

// Seite " - ": nicht im Layout, Wert wird gesendet
$g->props['Tiles'] = json_encode([
    ['Key' => 'versteckt', 'VariableID' => 950, 'Factor' => 1, 'Page' => ' - '],
    ['Key' => 'sichtbar', 'VariableID' => 950, 'Factor' => 1],
]);
$g->PublishAll();
$s = array_column(layouts($g), 1, 0);
$g->sent = [];
$l = json_decode($s['colorkit/layout/0'], true);
assert(count($l) === 1 && $l[0]['k'] === 'sichtbar');
assert($s['colorkit/state/versteckt'] === '68');

// Platzierung: 2x2 + 1x1 passen, 4x3 nicht mehr, folgendes 1x1 schon, Buttons mit Groesse
$g->props['Tiles'] = json_encode([
    ['Key' => 'gross', 'VariableID' => 950, 'Factor' => 1, 'Size' => '2x2'],
    ['Key' => 'voll', 'VariableID' => 950, 'Factor' => 1, 'Size' => '4x3'],
    ['Key' => 'klein', 'VariableID' => 950, 'Factor' => 1],
]);
$g->props['Buttons'] = json_encode([
    ['Key' => 'breit', 'VariableID' => 0, 'ScriptID' => 0, 'Size' => '4x1'],
    ['Key' => 'zuviel', 'VariableID' => 0, 'ScriptID' => 0, 'Size' => '4x1'],
]);
$g->PublishAll();
$l = page0($g);
assert(array_column($l, 'k') === ['gross', 'klein', 'breit']);
assert($l[2]['s'] === '4x1');

// 4x3 allein fuellt die Seite, danach passt nichts mehr
$g->props['Tiles'] = json_encode([
    ['Key' => 'voll', 'VariableID' => 950, 'Factor' => 1, 'Size' => '4x3'],
    ['Key' => 'klein', 'VariableID' => 950, 'Factor' => 1],
]);
$g->props['Buttons'] = '[]';
$g->PublishAll();
$l = page0($g);
assert(array_column($l, 'k') === ['voll']);
```

- [ ] **Step 2: Tests laufen lassen, sie muessen scheitern**

Run: `php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: FAIL mit `AssertionError` an der ersten neuen Assertion (battery_soc ohne `v`).

- [ ] **Step 3: Konstanten in `module.php` ersetzen**

`private const MAX_ITEMS = 12;` ersetzen durch:

```php
    private const COLS = 4;
    private const ROWS = 3;
    // Groesse => [Breite, Hoehe] in Plaetzen
    private const SIZES = ['1x1' => [1, 1], '2x1' => [2, 1], '2x2' => [2, 2], '4x1' => [4, 1], '4x2' => [4, 2], '4x3' => [4, 3]];
    private const DISPLAYS = ['Rundanzeige' => 'arc', 'Balken' => 'bar'];
    // Key => [Darstellung, Maximum]
    private const DISPLAY_DEFAULTS = ['battery_soc' => ['Rundanzeige', 100]];
```

- [ ] **Step 4: `layouts()` und `addItem()` ersetzen**

Die ganze Methode `layouts()` und die ganze Methode `addItem()` ersetzen durch:

```php
    private function layouts(array $pages): array
    {
        $layouts = array_fill(0, count($pages), []);
        $grids = array_fill(0, count($pages), array_fill(0, self::COLS * self::ROWS, false));
        foreach ($this->tileGroups() as $key => $group) {
            $key = (string) $key;
            $row = $group[0];
            $default = self::TILE_DEFAULTS[$key] ?? [$key, '', -1, ''];
            $decimals = (string) ($row['Decimals'] ?? '');
            $item = [
                't' => 'tile',
                'k' => $key,
                'l' => ($row['Title'] ?? '') ?: $default[0],
                'u' => ($row['Unit'] ?? '') ?: $default[1],
                'd' => $decimals === '' ? $default[2] : (int) $decimals,
            ];
            $colors = $this->parseColors(($row['Colors'] ?? '') ?: $default[3]);
            if (count($colors) > 0) {
                $item['c'] = $colors;
            }
            $displayDefault = self::DISPLAY_DEFAULTS[$key] ?? ['Zahl', 100];
            $display = ((string) ($row['Display'] ?? '')) ?: $displayDefault[0];
            if (isset(self::DISPLAYS[$display])) {
                $max = trim((string) ($row['Max'] ?? ''));
                $item['v'] = self::DISPLAYS[$display];
                $item['m'] = is_numeric($max) && $max + 0 > 0 ? $max + 0 : $displayDefault[1];
            }
            $this->addItem($layouts, $grids, $pages, $row, $item);
        }
        foreach ($this->rows('Buttons') as $row) {
            if ($row['Key'] !== '') {
                $label = ($row['Label'] ?? '') ?: (self::BUTTON_LABELS[$row['Key']] ?? $row['Key']);
                $type = ($row['Type'] ?? '') === 'Dimmer' ? 'dim' : 'btn';
                $this->addItem($layouts, $grids, $pages, $row, ['t' => $type, 'k' => $row['Key'], 'l' => $label]);
            }
        }
        return $layouts;
    }

    private function addItem(array &$layouts, array &$grids, array $pages, array $row, array $item): void
    {
        $name = trim((string) ($row['Page'] ?? 'Start'));
        if ($name === '-') {
            return;                     // nur senden, nicht anzeigen
        }
        $index = array_search($name, $pages, true);
        if ($index === false) {
            $index = 0;
            if ($name !== '') {
                $this->SendDebug('Seiten', $item['k'] . ': Seite ' . $name . ' gibt es nicht, nehme ' . $pages[0], 0);
            }
        }
        $size = strtolower(str_replace(' ', '', (string) ($row['Size'] ?? '')));
        if ($size === '') {
            $size = '1x1';
        } elseif (!isset(self::SIZES[$size])) {
            $this->SendDebug('Seiten', $item['k'] . ': Groesse ' . $size . ' unbekannt, nehme 1x1', 0);
            $size = '1x1';
        }
        [$w, $h] = self::SIZES[$size];
        if (!self::place($grids[$index], $w, $h)) {
            $this->SendDebug('Seiten', $item['k'] . ': kein Platz mehr auf Seite ' . $pages[$index], 0);
            return;
        }
        if ($size !== '1x1') {
            $item['s'] = $size;
        }
        $layouts[$index][] = $item;
    }

    // Erster freier Platz zeilenweise von oben links, an dem das ganze Element passt (wie die Firmware)
    private static function place(array &$grid, int $w, int $h): bool
    {
        for ($y = 0; $y + $h <= self::ROWS; $y++) {
            for ($x = 0; $x + $w <= self::COLS; $x++) {
                $free = true;
                for ($dy = 0; $dy < $h && $free; $dy++) {
                    for ($dx = 0; $dx < $w && $free; $dx++) {
                        $free = !$grid[($y + $dy) * self::COLS + $x + $dx];
                    }
                }
                if ($free) {
                    for ($dy = 0; $dy < $h; $dy++) {
                        for ($dx = 0; $dx < $w; $dx++) {
                            $grid[($y + $dy) * self::COLS + $x + $dx] = true;
                        }
                    }
                    return true;
                }
            }
        }
        return false;
    }
```

- [ ] **Step 5: Tests laufen lassen**

Run: `php -l ColorKitDashboard/module.php && php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: `No syntax errors detected` und `OK`. Die bestehenden Tests (14 Kacheln -> 12 auf Seite S1) muessen weiter gruen sein.

- [ ] **Step 6: Formular, README, Build**

In `ColorKitDashboard/form.json` die Tiles-Zeile

```json
                        { "name": "Colors", "caption": "Farben", "width": "220px", "add": "", "edit": { "type": "ValidationTextBox" } }
```

ersetzen durch:

```json
                        { "name": "Colors", "caption": "Farben", "width": "220px", "add": "", "edit": { "type": "ValidationTextBox" } },
                        {
                            "name": "Size", "caption": "Groesse", "width": "90px", "add": "1x1",
                            "edit": { "type": "Select", "options": [
                                { "caption": "1x1", "value": "1x1" }, { "caption": "2x1", "value": "2x1" }, { "caption": "2x2", "value": "2x2" },
                                { "caption": "4x1", "value": "4x1" }, { "caption": "4x2", "value": "4x2" }, { "caption": "4x3 (ganze Seite)", "value": "4x3" } ] }
                        },
                        {
                            "name": "Display", "caption": "Darstellung", "width": "130px", "add": "",
                            "edit": { "type": "Select", "options": [
                                { "caption": "Standard", "value": "" }, { "caption": "Zahl", "value": "Zahl" },
                                { "caption": "Rundanzeige", "value": "Rundanzeige" }, { "caption": "Balken", "value": "Balken" } ] }
                        },
                        { "name": "Max", "caption": "Maximum", "width": "90px", "add": "", "edit": { "type": "ValidationTextBox", "validate": "^(\\d+(\\.\\d+)?)?$" } }
```

Die Buttons-Zeile

```json
                        { "name": "ScriptID", "caption": "Skript", "width": "300px", "add": 0, "edit": { "type": "SelectScript" } }
```

ersetzen durch:

```json
                        { "name": "ScriptID", "caption": "Skript", "width": "300px", "add": 0, "edit": { "type": "SelectScript" } },
                        {
                            "name": "Size", "caption": "Groesse", "width": "90px", "add": "1x1",
                            "edit": { "type": "Select", "options": [
                                { "caption": "1x1", "value": "1x1" }, { "caption": "2x1", "value": "2x1" }, { "caption": "2x2", "value": "2x2" },
                                { "caption": "4x1", "value": "4x1" }, { "caption": "4x2", "value": "4x2" }, { "caption": "4x3 (ganze Seite)", "value": "4x3" } ] }
                        }
```

In `README.md` die Zeile, die mit `- **Seiten**:` beginnt, ersetzen durch:

```markdown
- **Seiten**: Bis zu 5 Seiten mit je 4 x 3 Plaetzen. Die Elemente kommen in Reihenfolge der Listen (zuerst Kacheln, dann
  Buttons) auf den ersten freien Platz, an dem sie ganz passen. Was nicht mehr passt, fehlt (Debug-Meldung). Die
  Reihenfolge aenderst du per Drag & Drop in den Listen. Seite `-` zeigt nichts an, der Wert wird trotzdem gesendet.
- **Groesse**: `1x1` bis `4x3` (Breite x Hoehe in Plaetzen, `4x3` = ganze Seite). Ab Hoehe 2 ist der Wert groesser.
- **Darstellung**: `Zahl`, `Rundanzeige` oder `Balken` mit **Maximum** (leer = 100). Standard: `battery_soc` als
  Rundanzeige mit Maximum 100. Werte unter 0 oder ueber dem Maximum werden am Rand angezeigt.
```

In `library.json` `"build": 9` durch `"build": 10` ersetzen.

Run: `python3 -c "import json;[json.load(open(f)) for f in ('library.json','ColorKitDashboard/form.json')];print('ok')" && php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: `ok` und `OK`.

- [ ] **Step 7: Commit (nicht pushen)**

```bash
git add ColorKitDashboard/module.php ColorKitDashboard/form.json README.md library.json test/live.php
git commit -m "Groesse, Rundanzeige, Balken und Seite - fuer Elemente, build 10"
```

---

### Task 2: Firmware – Platzierung, Groessen, Rundanzeige, Balken, 48-px-Schrift

**Files:**
- Modify: `tools/gen_font_de.py:8` (METRICS), `include/lv_conf.h:28-31` (Schriften)
- Create (erzeugt): `src/font_de_48.c`
- Modify: `src/pages.cpp` (Konstanten Zeile 8-12, `Item`, `applyValue`, `createDimmer`, `createTile`, `createButton`, `buildPage`)

**Interfaces:**
- Consumes: Layout-Felder `s`, `v`, `m` aus Task 1.
- Produces: keine neuen Schnittstellen; `publishCmd(key, val)` bleibt.

Arbeitsverzeichnis: `~/Documents/Linde-Mig-Kontakte/colorkit-dashboard`. `include/config.h` nicht oeffnen.

- [ ] **Step 1: 48-px-Schrift**

In `tools/gen_font_de.py` die Zeile

```python
METRICS = {14: (16, 3, -1), 28: (30, 5, -2)}  # wie lv_font_montserrat_<px>: Zeilenhoehe, Grundlinie, Unterstrich
```

ersetzen durch

```python
METRICS = {14: (16, 3, -1), 28: (30, 5, -2), 48: (52, 9, -4)}  # wie lv_font_montserrat_<px>: Zeilenhoehe, Grundlinie, Unterstrich
```

In `include/lv_conf.h` nach `#define LV_FONT_MONTSERRAT_28 1` die Zeile `#define LV_FONT_MONTSERRAT_48 1` einfuegen und
`LV_FONT_CUSTOM_DECLARE` ersetzen durch:

```c
#define LV_FONT_CUSTOM_DECLARE LV_FONT_DECLARE(font_de_14) LV_FONT_DECLARE(font_de_28) LV_FONT_DECLARE(font_de_48)
```

Run:

```bash
~/.zw-venv/bin/python tools/gen_font_de.py .pio/libdeps/ota/lvgl/scripts/built_in_font/Montserrat-Medium.ttf src
ls src/font_de_48.c
```

Expected: Datei existiert.

- [ ] **Step 2: Konstanten und `Item` in `src/pages.cpp`**

`static const int MAX_PAGES = 5, MAX_ITEMS = 12, MAX_VALUES = 64;` ersetzen durch
`static const int MAX_PAGES = 5, MAX_VALUES = 64;`
und `static const int COLS = 4, GAP = 6, TOP = 36, BAR_H = 40;` ersetzen durch
`static const int COLS = 4, ROWS = 3, GAP = 6, TOP = 36, BAR_H = 40;`

In `struct Item` nach `lv_obj_t* slider = nullptr;         // Dimmer` einfuegen:

```cpp
  lv_obj_t* gauge = nullptr;          // Rundanzeige oder Balken, Bereich 0..1000
  bool isArc = false;
  float max = 100;
  int lastGauge = -1;
```

- [ ] **Step 3: Anzeige in `applyValue`**

Direkt vor `static void applyValue(Item* it, const char* val) {` einfuegen:

```cpp
static void setGauge(Item* it, const char* val) {
  if (!it->gauge) return;
  char* end;
  float f = strtof(val, &end);
  if (end == val) return;
  int p = constrain((int)lroundf(f / it->max * 1000), 0, 1000);
  if (p == it->lastGauge) return;
  it->lastGauge = p;
  if (it->isArc) lv_arc_set_value(it->gauge, p);
  else           lv_bar_set_value(it->gauge, p, LV_ANIM_OFF);
}
```

In `applyValue` nach der Zeile
`  if (strcmp(lv_label_get_text(it->value), text) != 0) lv_label_set_text(it->value, text);`
einfuegen:

```cpp
  setGauge(it, val);
```

- [ ] **Step 4: Erzeuger mit Groesse**

`createDimmer`: Signatur `static void createDimmer(lv_obj_t* tab, Item* it, int x, int y, JsonObject o)` ersetzen durch
`static void createDimmer(lv_obj_t* tab, Item* it, int x, int y, int W, int H, JsonObject o)`, darin
`lv_obj_set_size(t, TW, TH);` durch `lv_obj_set_size(t, W, H);` und
`lv_obj_set_size(it->slider, TW - 28, 10);` durch `lv_obj_set_size(it->slider, W - 28, 10);`.

`createButton`: Signatur ebenso um `int W, int H` nach `int y` erweitern, darin `lv_obj_set_size(b, TW, TH);` durch
`lv_obj_set_size(b, W, H);` und `lv_obj_set_width(l, TW - 16);` durch `lv_obj_set_width(l, W - 16);`.

Die ganze Funktion `createTile` ersetzen durch:

```cpp
static void createTile(lv_obj_t* tab, Item* it, int x, int y, int W, int H, JsonObject o) {
  lv_obj_t* t = lv_obj_create(tab);
  it->box = t;
  lv_obj_set_size(t, W, H);
  lv_obj_set_pos(t, x, y);
  lv_obj_set_style_bg_color(t, lv_color_hex(TILE_BG), 0);
  lv_obj_set_style_border_width(t, 0, 0);
  lv_obj_set_style_radius(t, 10, 0);
  lv_obj_set_style_pad_all(t, 6, 0);
  lv_obj_clear_flag(t, LV_OBJ_FLAG_SCROLLABLE);

  it->title = lv_label_create(t);
  lv_obj_set_style_text_color(it->title, lv_color_hex(TITLE_FG), 0);
  lv_label_set_text(it->title, o["l"] | "");
  lv_obj_align(it->title, LV_ALIGN_TOP_LEFT, 0, 0);

  const char* unit = o["u"] | "";
  if (unit[0]) {
    lv_obj_t* u = lv_label_create(t);
    lv_obj_set_style_text_color(u, lv_color_hex(TITLE_FG), 0);
    lv_label_set_text(u, unit);
    lv_obj_align(u, LV_ALIGN_TOP_RIGHT, 0, 0);
  }

  bool big = H > TH;
  const char* view = o["v"] | "";
  it->max = o["m"] | 100.0f;
  if (!(it->max > 0)) it->max = 100;

  it->value = lv_label_create(t);
  lv_obj_set_style_text_color(it->value, lv_color_hex(0xE6EDF3), 0);
  lv_label_set_long_mode(it->value, LV_LABEL_LONG_CLIP);
  lv_label_set_text(it->value, "-");

  if (strcmp(view, "arc") == 0) {
    int d = min(W, H - 20) - 12;      // oben Platz fuer den Titel
    int line = big ? 12 : 6;
    it->gauge = lv_arc_create(t);
    it->isArc = true;
    lv_obj_set_size(it->gauge, d, d);
    lv_obj_align(it->gauge, LV_ALIGN_BOTTOM_MID, 0, 0);
    lv_arc_set_range(it->gauge, 0, 1000);
    lv_arc_set_value(it->gauge, 0);
    lv_obj_remove_style(it->gauge, NULL, LV_PART_KNOB);
    lv_obj_clear_flag(it->gauge, LV_OBJ_FLAG_CLICKABLE);   // Wischen ueber die Anzeige wechselt weiter die Seite
    lv_obj_set_style_arc_width(it->gauge, line, LV_PART_MAIN);
    lv_obj_set_style_arc_width(it->gauge, line, LV_PART_INDICATOR);
    lv_obj_set_style_text_font(it->value, big ? &font_de_28 : &font_de_14, 0);
    lv_obj_set_width(it->value, d - 2 * line);
    lv_obj_set_style_text_align(it->value, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_align_to(it->value, it->gauge, LV_ALIGN_CENTER, 0, 0);
  } else {
    lv_obj_set_style_text_font(it->value, big ? &font_de_48 : &font_de_28, 0);
    lv_obj_set_width(it->value, W - 12);
    if (strcmp(view, "bar") == 0) {
      it->gauge = lv_bar_create(t);
      lv_obj_set_size(it->gauge, W - 12, 8);
      lv_obj_align(it->gauge, LV_ALIGN_BOTTOM_MID, 0, 0);
      lv_bar_set_range(it->gauge, 0, 1000);
      lv_obj_align(it->value, LV_ALIGN_BOTTOM_LEFT, 0, -14);
    } else {
      lv_obj_align(it->value, LV_ALIGN_BOTTOM_LEFT, 0, 0);
    }
  }

  it->decimals = o["d"] | -1;
  for (JsonArray rule : o["c"].as<JsonArray>()) {
    const char* color = rule[1] | "";
    it->colors.push_back({rule[0] | "", (uint32_t)strtoul(color, nullptr, 16)});
  }
}
```

- [ ] **Step 5: Platzierung in `buildPage`**

Direkt vor `static void clearItems(int n) {` einfuegen:

```cpp
static bool parseSize(const char* s, int& w, int& h) {
  w = h = 1;
  if (!s[0]) return true;
  return sscanf(s, "%dx%d", &w, &h) == 2 && w >= 1 && w <= COLS && h >= 1 && h <= ROWS;
}

// Erster freier Platz zeilenweise von oben links, an dem das ganze Element passt (wie das Modul)
static bool place(bool* used, int w, int h, int& cx, int& cy) {
  for (cy = 0; cy + h <= ROWS; cy++) {
    for (cx = 0; cx + w <= COLS; cx++) {
      bool free = true;
      for (int dy = 0; dy < h && free; dy++)
        for (int dx = 0; dx < w && free; dx++) free = !used[(cy + dy) * COLS + cx + dx];
      if (!free) continue;
      for (int dy = 0; dy < h; dy++)
        for (int dx = 0; dx < w; dx++) used[(cy + dy) * COLS + cx + dx] = true;
      return true;
    }
  }
  return false;
}
```

In `buildPage` die Schleife

```cpp
  int i = 0;
  for (JsonObject o : doc.as<JsonArray>()) {
    if (i >= MAX_ITEMS) break;
    Item* it = new Item();
    it->key = o["k"] | "";
    int x = GAP + (i % COLS) * (TW + GAP), y = GAP + (i / COLS) * (TH + GAP);
    if (strcmp(o["t"] | "", "btn") == 0)      createButton(tabs[n], it, x, y, o);
    else if (strcmp(o["t"] | "", "dim") == 0) createDimmer(tabs[n], it, x, y, o);
    else                                 createTile(tabs[n], it, x, y, o);
```

ersetzen durch

```cpp
  bool used[COLS * ROWS] = {};
  int i = 0;
  for (JsonObject o : doc.as<JsonArray>()) {
    int w, h, cx, cy;
    if (!parseSize(o["s"] | "", w, h)) w = h = 1;
    if (!place(used, w, h, cx, cy)) {
      Serial.printf("Seite %d: %s passt nicht, verworfen\n", n, o["k"] | "?");
      continue;
    }
    Item* it = new Item();
    it->key = o["k"] | "";
    int x = GAP + cx * (TW + GAP), y = GAP + cy * (TH + GAP);
    int W = w * TW + (w - 1) * GAP, H = h * TH + (h - 1) * GAP;
    if (strcmp(o["t"] | "", "btn") == 0)      createButton(tabs[n], it, x, y, W, H, o);
    else if (strcmp(o["t"] | "", "dim") == 0) createDimmer(tabs[n], it, x, y, W, H, o);
    else                                      createTile(tabs[n], it, x, y, W, H, o);
```

Der Rest der Schleife (`items[n].push_back(it);`, letzter Wert, `i++;`) bleibt.

- [ ] **Step 6: Bauen**

Run: `uvx --python 3.11 --from platformio pio run -e ota 2>&1 | grep -E "error|warning: unused|RAM:|Flash:|SUCCESS|FAILED"`
Expected: `SUCCESS`, keine Fehler. Flash-Belegung notieren (vorher 58,9 %), muss unter 90 % bleiben.

- [ ] **Step 7: Commit im Firmware-Repo (nicht pushen)**

```bash
git add tools/gen_font_de.py include/lv_conf.h src/font_de_48.c src/pages.cpp
git status --short   # include/config.h darf nicht auftauchen
git commit -m "Groessen, Rundanzeige, Balken und 48-px-Schrift"
```

---

### Task 3: Am Geraet pruefen (Controller, mit Nutzer)

**Files:**
- Modify: `test/layout-sample.py` (Modul-Repo, neuer Modus `sizes`)

- [ ] **Step 1: Modus `sizes` in `test/layout-sample.py`**

Nach dem Block `elif mode == "small": ...` (vor `else:`) einfuegen:

```python
elif mode == "sizes":
    pages = ["Start", "Größe", "Voll"]
    layouts = [
        [{"t": "tile", "k": "s_soc", "l": "Akku", "u": "%", "d": 0, "v": "arc", "m": 100, "s": "2x2"},
         {"t": "tile", "k": "s_pv", "l": "PV", "u": "W", "d": 0, "v": "bar", "m": 10000, "s": "2x1"},
         {"t": "tile", "k": "s_klein", "l": "Rund 1x1", "u": "%", "d": 0, "v": "arc", "m": 100},
         {"t": "tile", "k": "s_neg", "l": "Negativ", "u": "W", "d": 0, "v": "bar", "m": 1000},
         {"t": "dim", "k": "s_dim", "l": "Dimmer breit", "s": "2x1"},
         {"t": "btn", "k": "s_btn", "l": "Button 2x1", "s": "2x1"}],
        [{"t": "tile", "k": "s_pv", "l": "PV gross", "u": "W", "d": 0, "s": "4x2"},
         {"t": "btn", "k": "s_btn", "l": "Button 4x1", "s": "4x1"}],
        [{"t": "tile", "k": "s_soc", "l": "Ganze Seite", "u": "%", "d": 0, "v": "arc", "m": 100, "s": "4x3"},
         {"t": "tile", "k": "s_pv", "l": "darf nicht erscheinen"}],
    ]
    states = {"s_soc": "68", "s_pv": "3200", "s_klein": "130", "s_neg": "-50", "s_dim": "40", "s_btn": "1"}
```

und nach der Schleife, die die Layouts sendet, vor `time.sleep(1)`:

```python
for k, v in (states if mode == "sizes" else {}).items():
    c.publish(f"colorkit/state/{k}", v).wait_for_publish()
```

- [ ] **Step 2: Flashen und einspielen**

Firmware per OTA flashen (Schleife bei Abbruch), dann mit Zugangsdaten aus der Umgebung:
`uvx --from paho-mqtt python test/layout-sample.py <broker> sizes`.
Achtung: Das ueberschreibt die echten retained Layouts. Danach im Symcon-Formular "Alle Werte jetzt senden" druecken
oder 5 Minuten auf den Timer warten.

- [ ] **Step 3: Sichtpruefung mit dem Nutzer**

Erwartet: Seite Start: Akku-Bogen 2x2 bei 68 %, PV-Balken 2x1 bei etwa einem Drittel, "Rund 1x1" voller Bogen
(130 > 100), "Negativ" leerer Balken, Dimmer und Button je 2x1 in Zeile 3. Seite "Größe" (Reitername mit Umlaut):
PV in 48-px-Schrift ueber 4x2, Button 4x1. Seite Voll: ein Bogen ueber die ganze Seite, "darf nicht erscheinen" fehlt. Wischen ueber Bogen und
Balken wechselt die Seite.

- [ ] **Step 4: Heap**

`colorkit/diag` und serielle Ausgabe `Seite n: ... Heap frei` lesen, wenn erreichbar. Erwartet: kein Neustart, Heap frei > 30000.

- [ ] **Step 5: Commit**

```bash
git add test/layout-sample.py
git commit -m "Testlayout fuer Groessen und Anzeigen"
```

---

### Task 4: Ausliefern

- [ ] **Step 1:** Firmware-Repo pushen: `git push` im Firmware-Repo.
- [ ] **Step 2:** Modul pushen: `git push origin main` im Modul-Repo (Spezifikation, Plan, Task 1, Task 3).
- [ ] **Step 3:** Nutzer aktualisiert das Modul (kein Dienst-Neustart), stellt z.B. `battery_soc` auf `2x2`.
- [ ] **Step 4:** Per MQTT pruefen: `layout/<n>` enthaelt `s`/`v`/`m` wie eingestellt, alte Kacheln unveraendert, keine Neuverbindung des Displays.
