# Energiefluss Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Layout-Element `flow` zeigt PV, Netz, Haus, Akku, Wallbox und WP mit Linien und wandernden Punkten in Flussrichtung.

**Architecture:** Das Modul erzeugt aus einer Kachelzeile mit Darstellung `Energiefluss` ein Element `{"t":"flow","n":[...]}`
mit den vorhandenen Knoten-Keys. Die Firmware baut Linien, Punkte und Knoten in eine Flaeche und aktualisiert sie aus den
bestehenden `state/<key>`-Werten.

**Tech Stack:** PHP 8 (Symcon 7.0 `IPSModule`), ESP32 Arduino/PlatformIO, LVGL 8.3 (`lv_line`, `lv_anim`), ArduinoJson 7.

**Spec:** `docs/superpowers/specs/2026-11-06-energiefluss-design.md`

## Global Constraints

- `include/config.h` der Firmware nie lesen, ausgeben, kopieren oder committen.
- Keine neue Property, keine neue Formularspalte. Darstellung bekommt die Option `Energiefluss`.
- Knoten-Keys fest: `pv_power`, `grid_power`, `house_power`, `battery_power`, `wallbox_power`, `hp_power`; Ladestand `battery_soc`.
- Vorzeichen: Netz + = Bezug, Akku + = Laden, Verbraucher + = Verbrauch.
- Strings in PHP/JSON mit ae/oe/ue, deutsche Captions. `library.json` build 11.
- Arbeit auf Branch `fluss` in beiden Repos, Commits als Dominik Pfister.

## Review Focus

1. Fluss-Zeile ohne Variable (VariableID 0): kein Fehler, kein `state/energy_flow`, Element erscheint. Test in Task 1.
2. Knoten-Key nur auf Seite `-` vorhanden: Knoten erscheint im Fluss. Test in Task 1.
3. Kein `house_power` vorhanden: Haus-Knoten trotzdem in der Mitte (Firmware), Modul sendet ihn nicht. Test in Task 1 (Modul) und Task 3 (Geraet).
4. Wert nicht numerisch oder Vorzeichenwechsel: Text wird angezeigt, Punkt aendert Richtung bzw. stoppt. Task 3 am Geraet.
5. Element kleiner als 4x2: Hinweis statt Fluss, kein Absturz. Task 3 am Geraet.

---

### Task 1: Modul – Fluss-Element

**Files:**
- Modify: `ColorKitDashboard/module.php` (Konstanten, `layouts()`), `ColorKitDashboard/form.json` (Option Darstellung)
- Modify: `README.md`, `library.json`
- Test: `test/live.php`

**Interfaces:**
- Produces: Layout-Element `{"t":"flow","k":<key>,"l":<titel>,"n":[[key,titel,einheit,stellen],...]}` plus `s`/`x`/`y` wie andere Elemente.

- [ ] **Step 1: Failing test**

In `test/live.php` vor dem letzten `echo "OK\n";`:

```php
// ===== Energiefluss =====
$f = new ColorKitDashboard();
$f->Create();
$f->active = true;
$vars[960] = [-800, VARIABLETYPE_INTEGER, 0, 1, ''];
$f->props['Pages'] = json_encode([['Name' => 'Start']]);
$f->props['Buttons'] = '[]';
$f->props['Tiles'] = json_encode([
    ['Key' => 'pv_power', 'VariableID' => 960, 'Factor' => 1],
    ['Key' => 'grid_power', 'VariableID' => 960, 'Factor' => 1, 'Page' => '-', 'Title' => 'Netz EVU'],
    ['Key' => 'battery_power', 'VariableID' => 960, 'Factor' => 1, 'Page' => '-', 'Unit' => 'kW', 'Decimals' => '2'],
    ['Key' => 'energy_flow', 'VariableID' => 0, 'Factor' => 1, 'Size' => '4x2'],
    ['Key' => 'zweiter', 'VariableID' => 0, 'Factor' => 1, 'Display' => 'Energiefluss', 'Size' => '4x1'],
]);
$f->PublishAll();
$s = array_column(layouts($f), 1, 0);
$f->sent = [];
$l = json_decode($s['colorkit/layout/0'], true);
assert(count($l) === 2);                      // pv_power 1x1 und energy_flow 4x2; zweiter 4x1 passt nicht mehr
assert($l[1] == ['t' => 'flow', 'k' => 'energy_flow', 'l' => 'Energiefluss', 's' => '4x2', 'x' => 0, 'y' => 1,
    'n' => [['pv_power', 'PV', 'W', 0], ['grid_power', 'Netz EVU', 'W', 0], ['battery_power', 'Akku', 'kW', 2]]]);
assert(!isset($s['colorkit/state/energy_flow']));
assert($s['colorkit/state/grid_power'] === '-800');

$f->props['Tiles'] = json_encode([
    ['Key' => 'zweiter', 'VariableID' => 0, 'Factor' => 1, 'Display' => 'Energiefluss'],
]);
$f->PublishAll();
$l = json_decode(array_column(layouts($f), 1, 0)['colorkit/layout/0'], true);
$f->sent = [];
assert($l[0]['t'] === 'flow' && $l[0]['n'] === [] && $l[0]['l'] === 'zweiter');
```

Run: `php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: FAIL (`AssertionError`, kein `flow`-Element).

- [ ] **Step 2: Konstanten**

In `module.php` in `TILE_DEFAULTS` nach der Zeile `'battery_soc'   => ['Akku', '%', 0, ''],` einfuegen:

```php
        'battery_power' => ['Akku', 'W', 0, ''],
        'hp_power'      => ['WP', 'W', 0, ''],
        'energy_flow'   => ['Energiefluss', '', -1, ''],
```

`DISPLAY_DEFAULTS` ersetzen durch:

```php
    private const DISPLAY_DEFAULTS = ['battery_soc' => ['Rundanzeige', 100], 'energy_flow' => ['Energiefluss', 100]];
    // Feste Knoten des Energieflusses, in dieser Reihenfolge
    private const FLOW_KEYS = ['pv_power', 'grid_power', 'house_power', 'battery_power', 'wallbox_power', 'hp_power'];
```

- [ ] **Step 3: `layouts()` und Hilfsfunktion**

In `layouts()` den Block

```php
            $default = self::TILE_DEFAULTS[$key] ?? [$key, '', -1, ''];
            $decimals = (string) ($row['Decimals'] ?? '');
            $item = [
                't' => 'tile',
                'k' => $key,
                'l' => ($row['Title'] ?? '') ?: $default[0],
                'u' => ($row['Unit'] ?? '') ?: $default[1],
                'd' => $decimals === '' ? $default[2] : (int) $decimals,
            ];
```

ersetzen durch

```php
            $default = self::TILE_DEFAULTS[$key] ?? [$key, '', -1, ''];
            [$title, $unit, $digits] = $this->meta($key, $row);
            $item = ['t' => 'tile', 'k' => $key, 'l' => $title, 'u' => $unit, 'd' => $digits];
```

und den Block

```php
            $displayDefault = self::DISPLAY_DEFAULTS[$key] ?? ['Zahl', 100];
            $display = ((string) ($row['Display'] ?? '')) ?: $displayDefault[0];
            if (isset(self::DISPLAYS[$display])) {
```

ersetzen durch

```php
            $displayDefault = self::DISPLAY_DEFAULTS[$key] ?? ['Zahl', 100];
            $display = ((string) ($row['Display'] ?? '')) ?: $displayDefault[0];
            if ($display === 'Energiefluss') {
                $item = ['t' => 'flow', 'k' => $key, 'l' => $title, 'n' => $this->flowNodes()];
            } elseif (isset(self::DISPLAYS[$display])) {
```

Direkt vor `// Zeile pruefen und mit Groesse und Wunschposition vormerken` einfuegen:

```php
    // Titel, Einheit, Stellen einer Kachelzeile mit Standardwerten je Key
    private function meta(string $key, array $row): array
    {
        $default = self::TILE_DEFAULTS[$key] ?? [$key, '', -1, ''];
        $decimals = (string) ($row['Decimals'] ?? '');
        return [
            ($row['Title'] ?? '') ?: $default[0],
            ($row['Unit'] ?? '') ?: $default[1],
            $decimals === '' ? $default[2] : (int) $decimals,
        ];
    }

    // Knoten fuer den Energiefluss: jeder feste Key, der in der Kachelliste steht (auch auf Seite "-")
    private function flowNodes(): array
    {
        $groups = $this->tileGroups();
        $nodes = [];
        foreach (self::FLOW_KEYS as $key) {
            if (isset($groups[$key])) {
                $nodes[] = array_merge([$key], $this->meta($key, $groups[$key][0]));
            }
        }
        return $nodes;
    }
```

- [ ] **Step 4: Test laufen lassen**

Run: `php -l ColorKitDashboard/module.php && php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: keine Syntaxfehler, `OK`.

- [ ] **Step 5: Formular, README, Build**

In `form.json` in der Darstellung-Auswahl `{ "caption": "Balken", "value": "Balken" } ] }` ersetzen durch
`{ "caption": "Balken", "value": "Balken" }, { "caption": "Energiefluss", "value": "Energiefluss" } ] }`.

In `README.md` nach dem Punkt `- **Darstellung**: ...` (bis zu seinem Zeilenende `...am Rand angezeigt.`) einfuegen:

```markdown
- **Energiefluss**: Kachelzeile mit Key `energy_flow` (keine Variable noetig) und Groesse `4x3` oder `4x2`. Knoten
  erscheinen fuer `pv_power`, `grid_power` (+ = Bezug, - = Einspeisung), `house_power`, `battery_power` (+ = Laden,
  - = Entladen), `wallbox_power` und `hp_power`, sobald der Key in der Liste steht. Werte, die nur der Fluss braucht,
  auf Seite `-` legen. Falsches Vorzeichen mit Faktor `-1` drehen. Der Akku-Knoten zeigt `battery_soc` mit an.
```

`library.json`: `"build": 10` -> `"build": 11`.

Run: `python3 -c "import json;[json.load(open(f)) for f in ('library.json','ColorKitDashboard/form.json')];print('ok')" && php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: `ok`, `OK`.

- [ ] **Step 6: Commit**

```bash
git add ColorKitDashboard/module.php ColorKitDashboard/form.json README.md library.json test/live.php
git commit -m "Energiefluss als Darstellung, build 11"
```

---

### Task 2: Firmware – Fluss-Element

**Files:**
- Modify: `src/pages.cpp`

**Interfaces:**
- Consumes: `{"t":"flow","n":[[key,titel,einheit,stellen],...]}` aus Task 1, `s`/`x`/`y` wie bisher.

- [ ] **Step 1: Strukturen**

In `src/pages.cpp` direkt vor `struct Item {` einfuegen:

```cpp
struct FlowNode {
  std::string key, label, unit;
  int dec = 0, cx = 0, cy = 0, dir = 0;   // dir: +1 zum Haus, -1 vom Haus weg, 0 kein Fluss
  lv_point_t pts[2];                      // Linie Knoten -> Haus, muss leben solange die Linie lebt
  lv_obj_t *line = nullptr, *dot = nullptr, *title = nullptr, *value = nullptr;
};
struct FlowData {
  std::vector<FlowNode*> nodes;
  std::string soc;
  ~FlowData() { for (FlowNode* n : nodes) delete n; }
};
```

In `struct Item` nach `int lastGauge = -1;` einfuegen:

```cpp
  FlowData* flow = nullptr;
  ~Item() { delete flow; }
```

- [ ] **Step 2: Fluss-Funktionen**

Direkt vor `static bool parseSize(` einfuegen:

```cpp
// ===== Energiefluss =====
static const struct { const char* key; float fx, fy; } FLOW_POS[] = {
  {"pv_power", .5f, .17f}, {"grid_power", .13f, .5f}, {"house_power", .5f, .5f},
  {"wallbox_power", .87f, .5f}, {"battery_power", .27f, .83f}, {"hp_power", .73f, .83f},
};
static const uint32_t LINE_IDLE = 0x3A4552;

static void dotMove(void* var, int32_t v) {
  lv_obj_t* d = (lv_obj_t*)var;
  FlowNode* nd = (FlowNode*)lv_obj_get_user_data(d);
  lv_point_t a = nd->pts[0], b = nd->pts[1];
  if (nd->dir < 0) { lv_point_t t = a; a = b; b = t; }
  lv_obj_set_pos(d, a.x + (b.x - a.x) * v / 1000 - 5, a.y + (b.y - a.y) * v / 1000 - 5);
}

static void flowTitle(FlowData* f, FlowNode* nd) {
  std::string t = nd->label;
  if (nd->key == "grid_power" && nd->dir) t = nd->dir > 0 ? "Bezug" : "Einspeisung";
  if (nd->key == "battery_power") {
    if (nd->dir) t = nd->dir < 0 ? "Laden" : "Entladen";
    if (!f->soc.empty()) {
      char s[16];
      snprintf(s, sizeof(s), " %.0f %%", strtof(f->soc.c_str(), nullptr));
      t += s;
    }
  }
  if (strcmp(lv_label_get_text(nd->title), t.c_str()) != 0) lv_label_set_text(nd->title, t.c_str());
}

static uint32_t flowColor(const FlowNode* nd) {
  if (nd->key == "pv_power")      return 0xFFB300;
  if (nd->key == "grid_power")    return nd->dir > 0 ? 0xC62828 : 0x2E7D32;
  if (nd->key == "battery_power") return nd->dir < 0 ? 0x2E7D32 : 0xEF6C00;
  return 0x1E88E5;
}

static void flowSet(Item* it, const char* key, const char* val) {
  FlowData* f = it->flow;
  if (strcmp(key, "battery_soc") == 0) {
    f->soc = val;
    for (FlowNode* nd : f->nodes) if (nd->key == "battery_power") flowTitle(f, nd);
    return;
  }
  FlowNode* nd = nullptr;
  for (FlowNode* n : f->nodes) if (n->key == key) nd = n;
  if (!nd) return;

  char out[32];
  char* end;
  float v = strtof(val, &end);
  int dir = 0;
  if (end == val) {
    snprintf(out, sizeof(out), "%s", val);
  } else {
    int dec = constrain(nd->dec, 0, 3);
    float r = roundf(v * powf(10, dec)) / powf(10, dec);
    snprintf(out, sizeof(out), "%.*f %s", dec, fabsf(r), nd->unit.c_str());
    int sign = r > 0 ? 1 : (r < 0 ? -1 : 0);
    if (nd->key == "pv_power" || nd->key == "grid_power") dir = sign;
    else if (nd->key != "house_power") dir = -sign;      // Akku laden und Verbraucher: vom Haus weg
  }
  if (strcmp(lv_label_get_text(nd->value), out) != 0) lv_label_set_text(nd->value, out);
  if (!nd->line || dir == nd->dir) { flowTitle(f, nd); return; }

  nd->dir = dir;
  flowTitle(f, nd);
  lv_anim_del(nd->dot, dotMove);
  lv_obj_set_style_line_color(nd->line, lv_color_hex(dir ? flowColor(nd) : LINE_IDLE), 0);
  if (!dir) { lv_obj_add_flag(nd->dot, LV_OBJ_FLAG_HIDDEN); return; }
  lv_obj_set_style_bg_color(nd->dot, lv_color_hex(flowColor(nd)), 0);
  lv_obj_clear_flag(nd->dot, LV_OBJ_FLAG_HIDDEN);
  lv_anim_t a;
  lv_anim_init(&a);
  lv_anim_set_var(&a, nd->dot);
  lv_anim_set_exec_cb(&a, dotMove);
  lv_anim_set_values(&a, 0, 1000);
  lv_anim_set_time(&a, 1600);
  lv_anim_set_repeat_count(&a, LV_ANIM_REPEAT_INFINITE);
  lv_anim_start(&a);
}

static void createFlow(lv_obj_t* tab, Item* it, int x, int y, int W, int H, JsonObject o) {
  lv_obj_t* t = lv_obj_create(tab);
  it->box = t;
  lv_obj_set_size(t, W, H);
  lv_obj_set_pos(t, x, y);
  lv_obj_set_style_bg_color(t, lv_color_hex(TILE_BG), 0);
  lv_obj_set_style_border_width(t, 0, 0);
  lv_obj_set_style_radius(t, 10, 0);
  lv_obj_set_style_pad_all(t, 0, 0);
  lv_obj_clear_flag(t, LV_OBJ_FLAG_SCROLLABLE);
  if (W < 300 || H < 140) {
    lv_obj_t* l = lv_label_create(t);
    lv_obj_set_style_text_color(l, lv_color_hex(TITLE_FG), 0);
    lv_label_set_text(l, "Energiefluss: mindestens 4x2");
    lv_obj_center(l);
    return;
  }

  FlowData* f = new FlowData();
  it->flow = f;
  int hx = 0, hy = 0;
  for (const auto& p : FLOW_POS) {
    const char* label = strcmp(p.key, "house_power") == 0 ? "Haus" : nullptr;
    const char* unit = "W";
    int dec = 0;
    for (JsonArray n : o["n"].as<JsonArray>()) {
      if (strcmp(n[0] | "", p.key) == 0) { label = n[1] | p.key; unit = n[2] | ""; dec = n[3] | 0; }
    }
    if (!label) continue;                              // Key fehlt im Modul: kein Knoten (ausser Haus)
    FlowNode* nd = new FlowNode();
    nd->key = p.key; nd->label = label; nd->unit = unit; nd->dec = dec;
    nd->cx = (int)(p.fx * W); nd->cy = (int)(p.fy * H);
    if (nd->key == "house_power") { hx = nd->cx; hy = nd->cy; }
    f->nodes.push_back(nd);
  }
  for (FlowNode* nd : f->nodes) {                      // Linien und Punkte zuerst, damit die Knoten darueber liegen
    if (nd->key == "house_power") continue;
    nd->pts[0] = {(lv_coord_t)nd->cx, (lv_coord_t)nd->cy};
    nd->pts[1] = {(lv_coord_t)hx, (lv_coord_t)hy};
    nd->line = lv_line_create(t);
    lv_line_set_points(nd->line, nd->pts, 2);
    lv_obj_set_style_line_width(nd->line, 3, 0);
    lv_obj_set_style_line_rounded(nd->line, true, 0);
    lv_obj_set_style_line_color(nd->line, lv_color_hex(LINE_IDLE), 0);
    nd->dot = lv_obj_create(t);
    lv_obj_set_size(nd->dot, 10, 10);
    lv_obj_set_style_radius(nd->dot, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_border_width(nd->dot, 0, 0);
    lv_obj_clear_flag(nd->dot, (lv_obj_flag_t)(LV_OBJ_FLAG_CLICKABLE | LV_OBJ_FLAG_SCROLLABLE));
    lv_obj_add_flag(nd->dot, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_user_data(nd->dot, nd);
  }
  for (FlowNode* nd : f->nodes) {
    lv_obj_t* b = lv_obj_create(t);
    lv_obj_set_size(b, 100, 48);
    lv_obj_set_pos(b, nd->cx - 50, nd->cy - 24);
    lv_obj_set_style_bg_color(b, lv_color_hex(0x2D3743), 0);
    lv_obj_set_style_border_width(b, 0, 0);
    lv_obj_set_style_radius(b, 8, 0);
    lv_obj_set_style_pad_all(b, 3, 0);
    lv_obj_clear_flag(b, (lv_obj_flag_t)(LV_OBJ_FLAG_CLICKABLE | LV_OBJ_FLAG_SCROLLABLE));
    nd->title = lv_label_create(b);
    lv_obj_set_style_text_color(nd->title, lv_color_hex(TITLE_FG), 0);
    lv_label_set_text(nd->title, nd->label.c_str());
    lv_obj_align(nd->title, LV_ALIGN_TOP_MID, 0, 0);
    nd->value = lv_label_create(b);
    lv_obj_set_style_text_font(nd->value, &lv_font_montserrat_20, 0);
    lv_obj_set_style_text_color(nd->value, lv_color_hex(0xE6EDF3), 0);
    lv_label_set_text(nd->value, "-");
    lv_obj_align(nd->value, LV_ALIGN_BOTTOM_MID, 0, 0);
  }
  for (FlowNode* nd : f->nodes) {
    auto last = lastValues.find(nd->key);
    if (last != lastValues.end()) flowSet(it, nd->key.c_str(), last->second.c_str());
  }
  auto soc = lastValues.find("battery_soc");
  if (soc != lastValues.end()) flowSet(it, "battery_soc", soc->second.c_str());
}
```

Hinweis: `lastValues` ist oberhalb deklariert (`static std::map<std::string, std::string> lastValues;`), `TILE_BG`
und `TITLE_FG` ebenso.

- [ ] **Step 3: Einbinden in `buildPage` und `pagesOnState`**

In `buildPage` die Zeile

```cpp
    else if (strcmp(o["t"] | "", "dim") == 0) createDimmer(tabs[n], it, x, y, W, H, o);
```

ersetzen durch

```cpp
    else if (strcmp(o["t"] | "", "dim") == 0) createDimmer(tabs[n], it, x, y, W, H, o);
    else if (strcmp(o["t"] | "", "flow") == 0) createFlow(tabs[n], it, x, y, W, H, o);
```

In `pagesOnState` die Zeile

```cpp
      if (it->key == key) applyValue(it, val);
```

ersetzen durch

```cpp
      if (it->flow) flowSet(it, key, val);
      else if (it->key == key) applyValue(it, val);
```

In `buildPage` die Zeile

```cpp
    if (last != lastValues.end()) applyValue(it, last->second.c_str());
```

ersetzen durch

```cpp
    if (last != lastValues.end() && !it->flow) applyValue(it, last->second.c_str());
```

Am Anfang von `applyValue` (erste Zeile im Rumpf) einfuegen, damit ein Fluss-Hinweis (zu klein, ohne Labels) mit
eigenem Wert nicht abstuerzt:

```cpp
  if (!it->isBtn && !it->value) return;
```

- [ ] **Step 4: Bauen**

Run: `uvx --python 3.11 --from platformio pio run -e ota 2>&1 | grep -E "error|RAM:|Flash:|SUCCESS|FAILED"`
Expected: `SUCCESS`, keine Fehler aus `src/`.

- [ ] **Step 5: Commit**

```bash
git add src/pages.cpp
git commit -m "Energiefluss-Element mit laufenden Punkten"
```

---

### Task 3: Am Geraet pruefen

**Files:**
- Modify: `test/layout-sample.py` (Modul-Repo, Modus `flow`)

- [ ] **Step 1: Modus `flow`**

In `test/layout-sample.py` vor `else:\n    pages, layouts = ["Start"], [[]]` einfuegen:

```python
elif mode in ("flow", "flow2"):
    nodes = [["pv_power", "PV", "W", 0], ["grid_power", "Netz", "W", 0], ["house_power", "Haus", "W", 0],
             ["battery_power", "Akku", "W", 0], ["wallbox_power", "Wallbox", "kW", 1], ["hp_power", "WP", "W", 0]]
    pages = ["Fluss", "Klein"]
    layouts = [[{"t": "flow", "k": "energy_flow", "l": "Energiefluss", "s": "4x3", "x": 0, "y": 0, "n": nodes}],
               [{"t": "flow", "k": "energy_flow", "l": "Energiefluss", "s": "2x2", "x": 0, "y": 0, "n": nodes},
                {"t": "flow", "k": "energy_flow", "l": "Energiefluss", "s": "4x1", "x": 0, "y": 2, "n": nodes[:1]}]]
    states = ({"pv_power": "3200", "grid_power": "-800", "house_power": "1100", "battery_power": "400",
               "battery_soc": "68", "wallbox_power": "1.3", "hp_power": "0"} if mode == "flow" else
              {"pv_power": "0", "grid_power": "1500", "house_power": "1900", "battery_power": "-400",
               "battery_soc": "67", "wallbox_power": "0", "hp_power": "abc"})
```

und die Zeile `for k, v in (states if mode == "sizes" else {}).items():` ersetzen durch
`for k, v in (states if mode in ("sizes", "flow", "flow2") else {}).items():`.

- [ ] **Step 2: Flashen, einspielen**

Firmware per OTA flashen, dann `layout-sample.py <broker> flow`, nach der Sichtpruefung `flow2`. Achtung: ueberschreibt
die echten Layouts und echte `state/`-Werte bis zum naechsten Senden durch Symcon (Button "Alle Werte jetzt senden").

- [ ] **Step 3: Sichtpruefung**

`flow`: PV gelb mit Punkt zum Haus, Netz "Einspeisung 800 W" gruen mit Punkt vom Haus zum Netz, Akku "Laden 68 %" gruen
vom Haus weg, Wallbox "1.3 kW" blau vom Haus weg, WP grau ohne Punkt. Seite Klein: zwei Hinweise "mindestens 4x2".
`flow2`: PV grau, Netz "Bezug" rot zum Haus, Akku "Entladen 67 %" orange zum Haus, Wallbox grau, WP zeigt "abc" grau.
Touch und Wischen reagieren normal.

- [ ] **Step 4: Commit**

```bash
git add test/layout-sample.py
git commit -m "Testlayout fuer den Energiefluss"
```

---

### Task 4: Review und Ausliefern

- [ ] Gesamt-Review beider Diffs, Fixes, dann `fluss` nach `main` in beiden Repos, pushen.
