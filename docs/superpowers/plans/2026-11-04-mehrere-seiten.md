# Mehrere Seiten Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das Color Kit zeigt bis zu 5 Seiten, die vollstaendig im Symcon-Formular gepflegt werden.

**Architecture:** Das Modul erzeugt aus den Formular-Listen je Seite eine JSON-Beschreibung und sendet sie retained per MQTT. Die Firmware baut daraus mit dem LVGL-Tabview die Seiten und ordnet `state/<key>` den Kacheln zu. Die Topics `state`, `cmd`, `status` und `diag` bleiben unveraendert.

**Tech Stack:** PHP 8 / IP-Symcon ab 7.0, ESP32 Arduino 2.0.x (PlatformIO), LVGL 8.3, PubSubClient 2.8, ArduinoJson 7.

**Spec:** `docs/superpowers/specs/2026-11-04-mehrere-seiten-design.md`

**Orte:** Modul in `~/Documents/Claude/SymconColorKit` (Git). Firmware in `~/Documents/Linde-Mig-Kontakte/colorkit-dashboard` (kein Git; `include/config.h` enthaelt WLAN-Zugangsdaten und darf nie ins Repo).

## Global Constraints

- Hoechstens 5 Seiten, 12 Eintraege je Seite, 4096 Byte je Layout.
- IP-Symcon ab 7.0, `declare(strict_types=1)`, Klasse bleibt `IPSModule`, Nutzlast Latin-1-nach-UTF-8 (nicht hex).
- In PHP- und JSON-Strings Umlaute als ae/oe/ue. Die Display-Schrift hat keine Umlaute, `°` ist vorhanden.
- Die gespeicherten Properties werden bei der Migration nicht umgeschrieben.
- Nichts an Touch-Lesen, WLAN-Absicherung und `colorkit/diag` aendern, ausser `DEBUG_TOUCH 0`.
- Zugangsdaten nie in Repo-Dateien oder Ausgaben.
- Commits im Modul-Repo enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Bestehende Instanz, Zeilen ohne neue Spalten: Alles erscheint mit den Standardwerten auf "Start". Test in Task 1.
2. Im Formular bearbeitete Standardzeile mit leerem Titel, leerer Einheit oder leeren Farben: Es gelten die Standardwerte. Test in Task 1.
3. Seite umbenannt oder geloescht: Die Zeile landet auf der ersten Seite, ihr Wert wird weiter gesendet. Test in Task 1.
4. Seite verkleinert oder entfernt: Das alte retained Layout verschwindet. Test in Task 1, Sichtpruefung in Task 3.
5. Display verbindet sich neu, ohne Aenderung: kein Neuaufbau, aktive Seite bleibt. Pruefung in Task 3.

---

### Task 1: Modul erzeugt und sendet Seiten

**Files:**
- Modify: `ColorKitDashboard/module.php`
- Modify: `ColorKitDashboard/form.json`
- Modify: `README.md`, `library.json`
- Test: `test/live.php`

**Interfaces:**
- Produces: Topic `<base>/pages` = JSON-Array von Namen. Topic `<base>/layout/<n>`, n = 0..4, = JSON-Array von `{"t":"tile","k":..,"l":..,"u":..,"d":..,"c":[[text,"RRGGBB"],..]}` (`c` nur wenn Regeln vorhanden) und `{"t":"btn","k":..,"l":..}`, oder leeres Payload.

- [ ] **Step 1: Failing Test schreiben**

In `test/live.php` direkt vor `echo "OK\n";` einfuegen:

```php
// ===== Seiten =====
function layouts(ColorKitDashboard $m): array
{
    $out = [];
    foreach ($m->sent as $s) {
        $out[] = [$s['Topic'], mb_convert_encoding($s['Payload'], 'ISO-8859-1', 'UTF-8')];
    }
    return $out;
}

$p = new ColorKitDashboard();
$p->Create();

// Alte Zeilen ohne neue Spalten: Standardwerte auf "Start", Akku als Kachel
$p->props['Tiles'] = json_encode([
    ['Key' => 'hp_flow', 'VariableID' => 1, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
    ['Key' => 'hp_mode', 'VariableID' => 3, 'Factor' => 1, 'Formatted' => true, 'Operation' => 'Summe'],
    ['Key' => 'battery_soc', 'VariableID' => 1, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
    ['Key' => 'eigener_key', 'VariableID' => 1, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
]);
$p->props['Buttons'] = json_encode([['Key' => 'dhw_boost', 'VariableID' => 10, 'ScriptID' => 0]]);
$p->PublishAll();
$sent = layouts($p);
$p->sent = [];
assert($sent[0] === ['colorkit/pages', '["Start"]']);
assert($sent[1][0] === 'colorkit/layout/0');
assert($sent[1][1] === '[{"t":"tile","k":"hp_flow","l":"WP Vorlauf","u":"°C","d":1},'
    . '{"t":"tile","k":"hp_mode","l":"WP Modus","u":"","d":-1,"c":[["Standby","EF6C00"],["Aus","C62828"],["Ein","2E7D32"]]},'
    . '{"t":"tile","k":"battery_soc","l":"Akku","u":"%","d":0},'
    . '{"t":"tile","k":"eigener_key","l":"eigener_key","u":"","d":-1},'
    . '{"t":"btn","k":"dhw_boost","l":"WW Boost"}]');
// Nicht genutzte Seiten werden geleert, Werte kommen nach den Layouts
foreach ([1, 2, 3, 4] as $i) {
    assert($sent[1 + $i] === ["colorkit/layout/$i", '']);
}
assert(str_contains($sent[6][0], '/state/'));

// Seiten, Zeile mit eigenen Werten, zweite Zeile desselben Keys aendert nichts, Farben
$p->props['Pages'] = json_encode([['Name' => 'Start'], ['Name' => 'Energie'], ['Name' => ' '], ['Name' => 'Energie']]);
$p->props['Tiles'] = json_encode([
    ['Key' => 'wb', 'VariableID' => 1, 'Factor' => 1, 'Page' => 'Energie', 'Title' => 'Wallbox', 'Unit' => 'kW', 'Decimals' => 2,
        'Colors' => 'Laedt=gruen; Fehler=ff0000;kaputt;x=lila;=rot'],
    ['Key' => 'wb', 'VariableID' => 2, 'Factor' => 1, 'Page' => 'Start', 'Title' => 'Falsch', 'Unit' => 'X', 'Decimals' => 0, 'Colors' => ''],
    // Bearbeitete Standardzeile mit leeren Feldern: Standardwerte
    ['Key' => 'pv_power', 'VariableID' => 1, 'Factor' => 1, 'Page' => '', 'Title' => '', 'Unit' => '', 'Decimals' => 0, 'Colors' => ''],
    // Geloeschte Seite: erste Seite, Wert wird weiter gesendet
    ['Key' => 'weg', 'VariableID' => 1, 'Factor' => 1, 'Page' => 'Gibtsnicht'],
]);
$p->props['Buttons'] = json_encode([['Key' => 'licht', 'VariableID' => 0, 'ScriptID' => 0, 'Page' => 'Energie', 'Label' => 'Licht']]);
$p->PublishAll();
$sent = layouts($p);
$p->sent = [];
assert($sent[0][1] === '["Start","Energie"]');
assert($sent[1][1] === '[{"t":"tile","k":"pv_power","l":"PV","u":"W","d":0},{"t":"tile","k":"weg","l":"weg","u":"","d":-1}]');
assert($sent[2][1] === '[{"t":"tile","k":"wb","l":"Wallbox","u":"kW","d":2,"c":[["Laedt","2E7D32"],["Fehler","FF0000"]]},{"t":"btn","k":"licht","l":"Licht"}]');
assert($sent[3] === ['colorkit/layout/2', '']);
assert(in_array('colorkit/state/weg', array_column($sent, 0), true));

// Grenzen: 5 Seiten, 12 Eintraege, Werte der ueberzaehligen Zeilen laufen weiter
$p->props['Pages'] = json_encode(array_map(fn ($i) => ['Name' => "S$i"], range(1, 7)));
$p->props['Tiles'] = json_encode(array_map(fn ($i) => ['Key' => "k$i", 'VariableID' => 1, 'Factor' => 1, 'Page' => 'S1'], range(1, 14)));
$p->props['Buttons'] = '[]';
$p->PublishAll();
$sent = layouts($p);
$p->sent = [];
assert(count(json_decode($sent[0][1])) === 5);
assert(count(json_decode($sent[1][1])) === 12);
assert(in_array('colorkit/state/k14', array_column($sent, 0), true));
assert(!in_array('colorkit/layout/5', array_column($sent, 0), true));

// Zu grosses Layout wird nicht gesendet, die anderen schon
$p->props['Pages'] = json_encode([['Name' => 'A'], ['Name' => 'B']]);
$p->props['Tiles'] = json_encode([
    ['Key' => 'gross', 'VariableID' => 1, 'Factor' => 1, 'Page' => 'A', 'Title' => str_repeat('x', 4100)],
    ['Key' => 'klein', 'VariableID' => 1, 'Factor' => 1, 'Page' => 'B'],
]);
$p->PublishAll();
$topics = array_column(layouts($p), 0);
$p->sent = [];
assert(!in_array('colorkit/layout/0', $topics, true));
assert(in_array('colorkit/layout/1', $topics, true));

// Rein numerischer Key loest keinen Typfehler aus
$p->props['Pages'] = '[]';
$p->props['Tiles'] = json_encode([['Key' => '123', 'VariableID' => 1, 'Factor' => 1]]);
$p->PublishAll();
$p->MessageSink(0, 1, VM_UPDATE, []);
$p->sent = [];
// Leere Seitenliste: "Start"
$p->PublishAll();
assert(layouts($p)[0][1] === '["Start"]');
$p->sent = [];

// Ohne aktiven Parent wird auch kein Layout gesendet
$p->active = false;
$p->PublishAll();
assert($p->sent === []);
```

- [ ] **Step 2: Test laufen lassen, muss fehlschlagen**

Run: `php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: FAIL, Exception bei `ReadPropertyString`/Undefined `Pages` oder Assertion `$sent[0] === ['colorkit/pages', '["Start"]']`.

- [ ] **Step 3: Modul umsetzen**

In `ColorKitDashboard/module.php` nach `DEFAULT_BUTTONS` einfuegen:

```php
    private const MAX_PAGES = 5;
    private const MAX_ITEMS = 12;
    private const MAX_LAYOUT_BYTES = 4096;

    // Key => [Titel, Einheit, Nachkommastellen, Farbregeln]
    private const TILE_DEFAULTS = [
        'pv_power'      => ['PV', 'W', 0, ''],
        'house_power'   => ['Haus', 'W', 0, ''],
        'grid_power'    => ['Netz', 'W', 0, ''],
        'wallbox_power' => ['Wallbox', 'kW', 2, ''],
        'hp_mode'       => ['WP Modus', '', -1, 'Standby=orange;Aus=rot;Ein=gruen'],
        'hp_flow'       => ['WP Vorlauf', '°C', 1, ''],
        'room_temp'     => ['Raum', '°C', 1, ''],
        'outdoor_temp'  => ['Aussen', '°C', 1, ''],
        'battery_soc'   => ['Akku', '%', 0, ''],
    ];

    private const BUTTON_LABELS = ['wallbox_pv' => 'Wallbox PV', 'wallbox_fast' => 'Sofortladen', 'dhw_boost' => 'WW Boost'];

    private const COLOR_NAMES = ['rot' => 'C62828', 'orange' => 'EF6C00', 'gruen' => '2E7D32', 'blau' => '1565C0', 'grau' => '546E7A'];
```

In `Create()` nach der Property `Buttons`:

```php
        $this->RegisterPropertyString('Pages', json_encode([['Name' => 'Start']]));
```

`MessageSink`: `$this->publishTile($key, $group, false);` ersetzen durch `$this->publishTile((string) $key, $group, false);`.

`PublishAll` ersetzen:

```php
    public function PublishAll(): void
    {
        $this->publishLayouts();
        foreach ($this->tileGroups() as $key => $group) {
            $this->publishTile((string) $key, $group, true);
        }
        foreach ($this->rows('Buttons') as $button) {
            $this->publishButton($button, true);
        }
    }

    private function publishLayouts(): void
    {
        $pages = $this->pageNames();
        $layouts = $this->layouts($pages);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $this->sendRetained($this->baseTopic() . '/pages', json_encode($pages, $flags));
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $payload = isset($layouts[$i]) ? json_encode($layouts[$i], $flags) : ''; // leer: retained Seite loeschen
            if (strlen($payload) > self::MAX_LAYOUT_BYTES) {
                $this->SendDebug('Seiten', 'Seite ' . $pages[$i] . ' ist zu gross und wird nicht gesendet', 0);
                continue;
            }
            $this->sendRetained($this->baseTopic() . '/layout/' . $i, $payload);
        }
    }

    private function pageNames(): array
    {
        $names = [];
        foreach ($this->rows('Pages') as $row) {
            $name = trim((string) ($row['Name'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        if (count($names) > self::MAX_PAGES) {
            $this->SendDebug('Seiten', 'Mehr als ' . self::MAX_PAGES . ' Seiten, der Rest wird nicht angezeigt', 0);
        }
        return array_slice($names, 0, self::MAX_PAGES) ?: ['Start'];
    }

    private function layouts(array $pages): array
    {
        $layouts = array_fill(0, count($pages), []);
        foreach ($this->tileGroups() as $key => $group) {
            $key = (string) $key;
            $row = $group[0];
            $default = self::TILE_DEFAULTS[$key] ?? [$key, '', -1, ''];
            $item = [
                't' => 'tile',
                'k' => $key,
                'l' => ($row['Title'] ?? '') ?: $default[0],
                'u' => ($row['Unit'] ?? '') ?: $default[1],
                'd' => (int) ($row['Decimals'] ?? $default[2]),
            ];
            $colors = $this->parseColors(($row['Colors'] ?? '') ?: $default[3]);
            if (count($colors) > 0) {
                $item['c'] = $colors;
            }
            $this->addItem($layouts, $pages, $row, $item);
        }
        foreach ($this->rows('Buttons') as $row) {
            if ($row['Key'] !== '') {
                $label = ($row['Label'] ?? '') ?: (self::BUTTON_LABELS[$row['Key']] ?? $row['Key']);
                $this->addItem($layouts, $pages, $row, ['t' => 'btn', 'k' => $row['Key'], 'l' => $label]);
            }
        }
        return $layouts;
    }

    private function addItem(array &$layouts, array $pages, array $row, array $item): void
    {
        $name = $row['Page'] ?? 'Start';
        $index = array_search($name, $pages, true);
        if ($index === false) {
            $index = 0;
            if ($name !== '') {
                $this->SendDebug('Seiten', $item['k'] . ': Seite ' . $name . ' gibt es nicht, nehme ' . $pages[0], 0);
            }
        }
        if (count($layouts[$index]) >= self::MAX_ITEMS) {
            $this->SendDebug('Seiten', $item['k'] . ': Seite ' . $pages[$index] . ' ist voll', 0);
            return;
        }
        $layouts[$index][] = $item;
    }

    private function parseColors(string $rules): array
    {
        $colors = [];
        foreach (explode(';', $rules) as $rule) {
            if (trim($rule) === '') {
                continue;
            }
            $parts = array_map('trim', explode('=', $rule, 2));
            $color = strtolower($parts[1] ?? '');
            $hex = self::COLOR_NAMES[$color] ?? (preg_match('/^[0-9a-f]{6}$/', $color) ? strtoupper($color) : null);
            if ($parts[0] === '' || $hex === null) {
                $this->SendDebug('Seiten', 'Ungueltige Farbregel: ' . $rule, 0);
                continue;
            }
            $colors[] = [$parts[0], $hex];
        }
        return $colors;
    }
```

`publish` ersetzen und `sendRetained` ergaenzen:

```php
    // $force = false: unveraenderte Werte nicht senden, das entlastet die Funkstrecke
    private function publish(string $key, string $payload, bool $force): void
    {
        if (!$force && $this->GetBuffer('tx_' . $key) === $payload) {
            return;
        }
        if ($this->sendRetained($this->baseTopic() . '/state/' . $key, $payload)) {
            $this->SetBuffer('tx_' . $key, $payload);
        }
    }

    private function sendRetained(string $topic, string $payload): bool
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug('TX', 'Kein aktiver Parent, verworfen: ' . $topic . ' = ' . $payload, 0);
            return false;
        }
        $this->SendDebug('TX', $topic . ' = ' . $payload, 0);
        $this->SendDataToParent(json_encode([
            'DataID'           => self::TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => true,
            'Topic'            => $topic,
            'Payload'          => mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1'),
        ], JSON_UNESCAPED_SLASHES));
        return true;
    }
```

In `tileGroups()` bleibt der Rest gleich. `rows()` liefert bei `'[]'` ein leeres Array, das reicht fuer `Pages`.

- [ ] **Step 4: Test laufen lassen, muss bestehen**

Run: `php -l ColorKitDashboard/module.php && php -d zend.assertions=1 -d assert.exception=1 test/live.php`
Expected: `No syntax errors`, dann `OK`.

- [ ] **Step 5: Formular erweitern**

In `ColorKitDashboard/form.json` vor dem ExpansionPanel "Kacheln" einfuegen:

```json
        {
            "type": "ExpansionPanel",
            "caption": "Seiten",
            "items": [
                {
                    "type": "List",
                    "name": "Pages",
                    "caption": "Seiten in Reihenfolge (hoechstens 5)",
                    "rowCount": 5,
                    "add": true,
                    "delete": true,
                    "changeOrder": true,
                    "columns": [
                        { "name": "Name", "caption": "Name", "width": "auto", "add": "", "edit": { "type": "ValidationTextBox" } }
                    ]
                }
            ]
        },
```

In der Liste `Tiles` nach der Spalte `Key` einfuegen:

```json
                        { "name": "Page", "caption": "Seite", "width": "110px", "add": "Start", "edit": { "type": "ValidationTextBox" } },
                        { "name": "Title", "caption": "Titel", "width": "110px", "add": "", "edit": { "type": "ValidationTextBox" } },
                        { "name": "Unit", "caption": "Einheit", "width": "70px", "add": "", "edit": { "type": "ValidationTextBox" } },
                        { "name": "Decimals", "caption": "Stellen", "width": "70px", "add": -1, "edit": { "type": "NumberSpinner", "minimum": -1, "maximum": 6 } },
```

und nach der Spalte `Operation`:

```json
                        ,{ "name": "Colors", "caption": "Farben", "width": "220px", "add": "", "edit": { "type": "ValidationTextBox" } }
```

In der Liste `Buttons` nach der Spalte `Key` einfuegen:

```json
                        { "name": "Page", "caption": "Seite", "width": "110px", "add": "Start", "edit": { "type": "ValidationTextBox" } },
                        { "name": "Label", "caption": "Beschriftung", "width": "150px", "add": "", "edit": { "type": "ValidationTextBox" } },
```

Den Caption-Text der Liste `Tiles` ersetzen durch: `Kacheln (leer = Standardwert. Gleicher Key in mehreren Zeilen = Werte verrechnen, die erste Zeile legt Seite, Titel, Einheit, Stellen, Farben und Berechnung fest)`.

- [ ] **Step 6: JSON pruefen**

Run: `for f in library.json ColorKitDashboard/module.json ColorKitDashboard/form.json; do php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR); echo "ok $argv[1]\n";' "$f"; done`
Expected: dreimal `ok`.

- [ ] **Step 7: README und Version**

In `README.md` in der Topic-Tabelle nach `colorkit/status` ergaenzen:

```markdown
| `colorkit/pages` | Symcon -> Display | Seitennamen als JSON (retained) |
| `colorkit/layout/<n>` | Symcon -> Display | Inhalt der Seite n als JSON (retained), leer = keine Seite |
```

Im Abschnitt Konfiguration ergaenzen und den Satz zu `battery_soc` ("erscheint in der Kopfzeile") entfernen:

```markdown
- **Seiten**: Bis zu 5 Seiten mit je 12 Plaetzen. Die Plaetze fuellen sich in Reihenfolge der Listen, zuerst Kacheln, dann Buttons.
  Titel, Einheit und Farben stellen Sie je Zeile ein, leer bedeutet Standardwert. Farben: `Text=Farbe;Text=Farbe` mit
  rot, orange, gruen, blau, grau oder 6 Hex-Stellen. `battery_soc` ist eine normale Kachel.
- Nach dem Update des Moduls den Symcon-Dienst neu starten, damit die neue Eigenschaft `Pages` angelegt wird.
```

In `library.json` `"build": 5` auf `"build": 6` setzen.

- [ ] **Step 8: Commit (ohne Push)**

```bash
git add ColorKitDashboard README.md library.json test/live.php
git commit -m "Seiten: Layouts aus dem Formular erzeugen und per MQTT senden"
```

---

### Task 2: Firmware baut Seiten aus MQTT

**Files:**
- Create: `src/pages.h`, `src/pages.cpp`
- Modify: `src/main.cpp`, `include/config.h`, `platformio.ini`

**Interfaces:**
- Consumes: Topics und JSON aus Task 1.
- Produces: `pagesInit(lv_obj_t* scr)`, `pagesOnPages(const byte*, unsigned)`, `pagesOnLayout(int, const byte*, unsigned)`, `pagesOnState(const char* key, const char* val)`. `main.cpp` stellt `void publishCmd(const char* key)` bereit.

- [ ] **Step 1: Sicherung anlegen**

Run: `cd ~/Documents/Linde-Mig-Kontakte && zip -qr colorkit-dashboard-vor-seiten.zip colorkit-dashboard -x 'colorkit-dashboard/.pio/*'`
Expected: Datei angelegt, sie bleibt lokal.

- [ ] **Step 2: `src/pages.h` anlegen**

```cpp
#pragma once
#include <Arduino.h>
#include <lvgl.h>

void pagesInit(lv_obj_t* scr);
void pagesOnPages(const byte* payload, unsigned int len);
void pagesOnLayout(int index, const byte* payload, unsigned int len);
void pagesOnState(const char* key, const char* val);

void publishCmd(const char* key);   // in main.cpp
```

- [ ] **Step 3: `src/pages.cpp` anlegen**

```cpp
// Seiten aus MQTT: <base>/pages und <base>/layout/<n>, siehe Spezifikation im Modul-Repo
#include "pages.h"
#include <ArduinoJson.h>
#include <map>
#include <string>
#include <vector>

static const int MAX_PAGES = 5, MAX_ITEMS = 12, MAX_VALUES = 64;
static const int COLS = 4, GAP = 6, TOP = 36, BAR_H = 40;
static const int TW = (480 - (COLS + 1) * GAP) / COLS;                // 112
static const int TH = (320 - TOP - BAR_H - 4 * GAP) / 3;              // 73
static const uint32_t TILE_BG = 0x1B232C, TITLE_FG = 0x8B949E;

struct ColorRule { std::string text; uint32_t color; };
struct Item {
  bool isBtn = false;
  std::string key;
  int decimals = -1;
  std::vector<ColorRule> colors;
  lv_obj_t* box = nullptr;
  lv_obj_t* title = nullptr;
  lv_obj_t* value = nullptr;
};

static lv_obj_t* screen;
static lv_obj_t* waitLbl;
static lv_obj_t* tabview = nullptr;
static lv_obj_t* tabs[MAX_PAGES];
static int pageCount = 0;
static std::string pagesSrc;
static std::string layoutSrc[MAX_PAGES];
static std::vector<Item*> items[MAX_PAGES];
static std::map<std::string, std::string> lastValues;

static void applyValue(Item* it, const char* val) {
  if (it->isBtn) {
    bool on = val[0] == '1' || strcasecmp(val, "true") == 0 || strcasecmp(val, "on") == 0;
    if (on) lv_obj_add_state(it->box, LV_STATE_CHECKED);
    else    lv_obj_clear_state(it->box, LV_STATE_CHECKED);
    return;
  }
  char out[32];
  const char* text = val;
  if (it->decimals >= 0) {
    char* end;
    float f = strtof(val, &end);
    if (end != val) {
      snprintf(out, sizeof(out), "%.*f", min(it->decimals, 6), f);
      text = out;
    }
  }
  lv_label_set_text(it->value, text);

  uint32_t bg = TILE_BG, title = TITLE_FG;
  for (const ColorRule& r : it->colors) {
    if (strcasecmp(r.text.c_str(), val) == 0) {
      bg = r.color;
      title = 0xFFFFFF;       // grau waere auf Farbe schlecht lesbar
    }
  }
  lv_obj_set_style_bg_color(it->box, lv_color_hex(bg), 0);
  lv_obj_set_style_text_color(it->title, lv_color_hex(title), 0);
}

static void btnEvent(lv_event_t* e) {
  publishCmd(((Item*)lv_event_get_user_data(e))->key.c_str());
}

static void createTile(lv_obj_t* tab, Item* it, int x, int y, JsonObject o) {
  lv_obj_t* t = lv_obj_create(tab);
  it->box = t;
  lv_obj_set_size(t, TW, TH);
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

  it->value = lv_label_create(t);
  lv_obj_set_style_text_font(it->value, &lv_font_montserrat_28, 0);
  lv_obj_set_style_text_color(it->value, lv_color_hex(0xE6EDF3), 0);
  lv_label_set_long_mode(it->value, LV_LABEL_LONG_CLIP);
  lv_obj_set_width(it->value, TW - 12);
  lv_label_set_text(it->value, "-");
  lv_obj_align(it->value, LV_ALIGN_BOTTOM_LEFT, 0, 0);

  it->decimals = o["d"] | -1;
  for (JsonArray rule : o["c"].as<JsonArray>()) {
    const char* color = rule[1] | "";
    it->colors.push_back({rule[0] | "", (uint32_t)strtoul(color, nullptr, 16)});
  }
}

static void createButton(lv_obj_t* tab, Item* it, int x, int y, JsonObject o) {
  lv_obj_t* b = lv_btn_create(tab);
  it->box = b;
  it->isBtn = true;
  lv_obj_set_size(b, TW, TH);
  lv_obj_set_pos(b, x, y);
  lv_obj_set_style_radius(b, 10, 0);
  lv_obj_set_style_bg_color(b, lv_color_hex(0x2D3743), 0);
  lv_obj_set_style_bg_color(b, lv_color_hex(0x1F8A5B), LV_STATE_CHECKED);
  lv_obj_add_event_cb(b, btnEvent, LV_EVENT_CLICKED, it);
  lv_obj_t* l = lv_label_create(b);
  lv_label_set_long_mode(l, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(l, TW - 16);
  lv_obj_set_style_text_align(l, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_text(l, o["l"] | "");
  lv_obj_center(l);
}

static void clearItems(int n) {
  for (Item* it : items[n]) delete it;
  items[n].clear();
}

static void buildPage(int n) {
  if (n >= pageCount) return;
  lv_obj_clean(tabs[n]);
  clearItems(n);
  JsonDocument doc;
  if (layoutSrc[n].empty() || deserializeJson(doc, layoutSrc[n])) return;
  int i = 0;
  for (JsonObject o : doc.as<JsonArray>()) {
    if (i >= MAX_ITEMS) break;
    Item* it = new Item();
    it->key = o["k"] | "";
    int x = GAP + (i % COLS) * (TW + GAP), y = GAP + (i / COLS) * (TH + GAP);
    if (strcmp(o["t"] | "", "btn") == 0) createButton(tabs[n], it, x, y, o);
    else                                 createTile(tabs[n], it, x, y, o);
    items[n].push_back(it);
    auto last = lastValues.find(it->key);
    if (last != lastValues.end()) applyValue(it, last->second.c_str());
    i++;
  }
  Serial.printf("Seite %d: %d Eintraege, Heap frei %u, PSRAM frei %u\n", n, i, ESP.getFreeHeap(), ESP.getFreePsram());
}

static void buildTabs(JsonArray names) {
  uint16_t active = tabview ? lv_tabview_get_tab_act(tabview) : 0;
  for (int n = 0; n < MAX_PAGES; n++) clearItems(n);
  if (tabview) lv_obj_del(tabview);
  tabview = nullptr;
  pageCount = 0;

  tabview = lv_tabview_create(screen, LV_DIR_BOTTOM, BAR_H);
  lv_obj_set_pos(tabview, 0, TOP);
  lv_obj_set_size(tabview, 480, 320 - TOP);
  lv_obj_set_style_bg_color(tabview, lv_color_hex(0x0F141A), 0);
  for (JsonVariant name : names) {
    if (pageCount >= MAX_PAGES) break;
    lv_obj_t* tab = lv_tabview_add_tab(tabview, name.as<const char*>() ? name.as<const char*>() : "?");
    lv_obj_set_style_pad_all(tab, 0, 0);
    lv_obj_clear_flag(tab, LV_OBJ_FLAG_SCROLLABLE);
    tabs[pageCount++] = tab;
  }
  for (int n = 0; n < pageCount; n++) buildPage(n);
  if (pageCount > 0) lv_tabview_set_act(tabview, min<uint16_t>(active, pageCount - 1), LV_ANIM_OFF);
  lv_obj_add_flag(waitLbl, LV_OBJ_FLAG_HIDDEN);
}

void pagesInit(lv_obj_t* scr) {
  screen = scr;
  waitLbl = lv_label_create(scr);
  lv_obj_set_style_text_font(waitLbl, &lv_font_montserrat_20, 0);
  lv_obj_set_style_text_color(waitLbl, lv_color_hex(TITLE_FG), 0);
  lv_label_set_text(waitLbl, "Warte auf Symcon");
  lv_obj_center(waitLbl);
}

void pagesOnPages(const byte* payload, unsigned int len) {
  std::string src((const char*)payload, len);
  if (src == pagesSrc) return;        // Neu verbunden ohne Aenderung: aktive Seite bleibt
  JsonDocument doc;
  if (deserializeJson(doc, src) || !doc.is<JsonArray>() || doc.as<JsonArray>().size() == 0) {
    Serial.println("Seitenliste ungueltig, verworfen");
    return;
  }
  pagesSrc = src;
  buildTabs(doc.as<JsonArray>());
}

void pagesOnLayout(int index, const byte* payload, unsigned int len) {
  if (index < 0 || index >= MAX_PAGES) return;
  std::string src((const char*)payload, len);
  if (src == layoutSrc[index]) return;
  if (len > 0) {
    JsonDocument doc;
    if (deserializeJson(doc, src) || !doc.is<JsonArray>()) {
      Serial.printf("Layout %d ungueltig, verworfen\n", index);
      return;
    }
  }
  layoutSrc[index] = src;             // Kommt vor der Seitenliste an: wird beim Aufbau der Reiter genutzt
  buildPage(index);
}

void pagesOnState(const char* key, const char* val) {
  if (lastValues.size() < MAX_VALUES || lastValues.count(key)) lastValues[key] = val;
  for (int n = 0; n < pageCount; n++) {
    for (Item* it : items[n]) {
      if (it->key == key) applyValue(it, val);
    }
  }
}
```

- [ ] **Step 4: `config.h` und `platformio.ini` anpassen**

In `include/config.h` die Abschnitte "Kacheln", "Akku in der Kopfzeile", "Kachelfarben nach Text" und "Buttons" samt `struct`-Definitionen entfernen, `#define DEBUG_TOUCH 0` setzen. In `platformio.ini` unter `lib_deps` ergaenzen:

```ini
  bblanchon/ArduinoJson@^7.0.0
```

- [ ] **Step 5: `main.cpp` anpassen**

Oben ergaenzen: `#include "pages.h"`. Entfernen: `N_TILES`, `N_BTNS`, `tileValue`, `tileBox`, `btnObj`, `batteryLbl`, die alte Funktion `btnEvent`, `colorTile`, in `buildUi` das Akku-Label, das Kachelraster und die Buttonleiste. Am Ende von `buildUi` statt dessen: `pagesInit(scr);`.

`onMqtt` ersetzen:

```cpp
static void onMqtt(char* topic, byte* payload, unsigned int len) {
  const char* base = MQTT_BASE "/";
  size_t bl = strlen(base);
  if (strncmp(topic, base, bl) != 0) return;
  const char* sub = topic + bl;

  if (strcmp(sub, "pages") == 0) {
    pagesOnPages(payload, len);
  } else if (strncmp(sub, "layout/", 7) == 0) {
    pagesOnLayout(atoi(sub + 7), payload, len);
  } else if (strncmp(sub, "state/", 6) == 0) {
    char val[48];
    len = min<unsigned int>(len, sizeof(val) - 1);
    memcpy(val, payload, len);
    val[len] = 0;
    pagesOnState(sub + 6, val);
  }
}

void publishCmd(const char* key) {
  char topic[96];
  snprintf(topic, sizeof(topic), MQTT_BASE "/cmd/%s", key);
  if (mqtt.connected()) mqtt.publish(topic, "1");
}
```

`mqttReconnect`: Nach `mqtt.subscribe(MQTT_BASE "/state/#");` und vor dem Publish von `online` ergaenzen:

```cpp
    mqtt.subscribe(MQTT_BASE "/pages");
    mqtt.subscribe(MQTT_BASE "/layout/#");
```

In `setup`: `mqtt.setBufferSize(512);` ersetzen durch `mqtt.setBufferSize(4200);   // 4096 Byte Layout plus Topic`.

- [ ] **Step 6: Kompilieren**

Run: `cd ~/Documents/Linde-Mig-Kontakte/colorkit-dashboard && uvx --python 3.11 --from platformio pio run -e ota 2>&1 | grep -E 'error|RAM:|Flash:|SUCCESS|FAILED'`
Expected: `SUCCESS`, keine `error`-Zeile, Flash unter 100 %.

---

### Task 3: Firmware am Geraet pruefen

**Files:**
- Create: `test/layout-sample.py`, `test/serial-log.py` (im Modul-Repo)

**Interfaces:**
- Consumes: Firmware aus Task 2, Topics aus Task 1.

- [ ] **Step 1: Testwerkzeuge anlegen**

`test/layout-sample.py`:

```python
"""Spielt Seiten direkt per MQTT ein, ohne Symcon.
Aufruf: uvx --from paho-mqtt python test/layout-sample.py HOST full|empty|small
Zugang aus den Umgebungsvariablen MQTT_USER und MQTT_PASS."""
import json, os, sys, time
import paho.mqtt.client as mqtt

host, mode = sys.argv[1], sys.argv[2]
tile = lambda p, i: {"t": "tile", "k": f"t{p}_{i}", "l": f"Kachel {i}", "u": "W", "d": 0,
                     "c": [["Standby", "EF6C00"], ["Aus", "C62828"], ["Ein", "2E7D32"]]}
if mode == "full":
    pages = [f"Seite {p}" for p in range(5)]
    layouts = [[tile(p, i) for i in range(10)] + [{"t": "btn", "k": f"b{p}_{i}", "l": f"Taste {i}"} for i in range(2)]
               for p in range(5)]
elif mode == "small":
    pages = ["Start", "Energie"]
    layouts = [[tile(0, i) for i in range(3)], [{"t": "btn", "k": "b1_0", "l": "Taste"}]]
else:
    pages, layouts = ["Start"], [[]]

c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="colorkit-layout-test")
c.username_pw_set(os.environ["MQTT_USER"], os.environ["MQTT_PASS"])
c.connect(host, 1883, 30)
c.loop_start()
c.publish("colorkit/pages", json.dumps(pages), retain=True)
for n in range(5):
    c.publish(f"colorkit/layout/{n}", json.dumps(layouts[n]) if n < len(layouts) else "", retain=True).wait_for_publish()
    print(n, "gesendet")
time.sleep(1)
c.disconnect()
```

`test/serial-log.py`:

```python
"""Liest die serielle Ausgabe, ohne das ESP32 zurueckzusetzen.
Aufruf: uvx --from pyserial python test/serial-log.py PORT SEKUNDEN"""
import serial, sys, time

s = serial.Serial()
s.port, s.baudrate, s.timeout, s.dtr, s.rts = sys.argv[1], 115200, 1, False, False
s.open()
end = time.time() + float(sys.argv[2])
while time.time() < end:
    line = s.readline()
    if line:
        print(time.strftime("%H:%M:%S"), line.decode("utf8", "replace").rstrip(), flush=True)
```

- [ ] **Step 2: Serielle Aufzeichnung starten, Firmware flashen**

Run im Hintergrund, `$SCRATCH` ist das Scratchpad der Sitzung: `uvx --from pyserial python test/serial-log.py /dev/cu.usbserial-5C8D0756931 900 > "$SCRATCH/serial.log"`
Flashen, bei Abbruch bis zu 6 Mal wiederholen: `uvx --python 3.11 --from platformio pio run -e ota -t upload --upload-port 10.10.0.168`
Expected: `Result: OK`. Das Display zeigt "Warte auf Symcon", bis Seiten ankommen.

- [ ] **Step 3: Volle Seiten einspielen und Speicher messen**

Run mit gesetzten `MQTT_USER`, `MQTT_PASS` aus `config.h`, ohne sie auszugeben: `uvx --from paho-mqtt python test/layout-sample.py 10.10.0.125 full`
Expected im seriellen Log: fuenf Zeilen `Seite n: 12 Eintraege, Heap frei ...`, kein Neustart. Heap frei mindestens 30000 Byte. Wenn weniger: Spezifikation "Risiken" anwenden, also LVGL-Objekte einsparen oder PSRAM nutzen, und dem Nutzer berichten.

- [ ] **Step 4: Sichtpruefung durch den Nutzer**

Nutzer bitten und Antwort abwarten: Reiter antippen, zwischen Seiten wischen, je einen Button tippen. Parallel `cmd/b*` per MQTT mitlesen.
Expected: Jeder Reiter erreichbar, Tasten senden `cmd/<key>`, das Raster passt ohne Abschneiden auf das Display. Werte mit 5 Stellen und Vorzeichen sind in der Kachel lesbar.

- [ ] **Step 5: Verkleinern und Sonderfaelle**

Run: `uvx --from paho-mqtt python test/layout-sample.py 10.10.0.125 small`
Expected: Nur noch zwei Reiter. Dann Topic `colorkit/layout/0` mit ungueltigem Payload `{kaputt` retained senden: Log `Layout 0 ungueltig, verworfen`, Ansicht bleibt. Danach `small` erneut senden.
WLAN- oder MQTT-Neuverbindung abwarten oder Server neu verbinden: Eine `online`-Nachricht fuehrt zu keiner neuen Zeile `Seite n:` im Log, wenn sich nichts geaendert hat.

- [ ] **Step 6: Neustart des Displays**

Run: Das OTA-Upload aus Step 2 erneut ausfuehren, es startet das Display neu.
Expected: Nach dem Neustart erscheinen Seiten ohne weiteres Zutun aus den retained Nachrichten.

- [ ] **Step 7: Aufraeumen und Commit**

Run: `uvx --from paho-mqtt python test/layout-sample.py 10.10.0.125 empty`

```bash
git add test/layout-sample.py test/serial-log.py
git commit -m "Testwerkzeuge fuer Seiten am Geraet"
```

---

### Task 4: Auslieferung und Pruefung in Symcon

**Files:** keine neuen.

**Interfaces:**
- Consumes: Modul aus Task 1, Firmware aus Task 2.

- [ ] **Step 1: Modul pushen**

Run: `git push origin main`
Expected: `## main...origin/main` ohne `ahead`. Bei Serverfehler spaeter wiederholen.

- [ ] **Step 2: Nutzer aktualisiert Symcon**

Nutzer bitten: Modul im Module Control aktualisieren, Symcon-Dienst neu starten, Instanz oeffnen, Anwenden. Antwort abwarten.
Expected: Formular zeigt Panel "Seiten" und die neuen Spalten, kein Fehler im Log.

- [ ] **Step 3: Migration pruefen**

MQTT mitlesen.
Expected: `colorkit/pages` = `["Start"]`, `layout/0` enthaelt alle bisherigen Kacheln einschliesslich `battery_soc` und die Buttons. Das Display zeigt sie mit Werten, `hp_mode` mit Farbe.

- [ ] **Step 4: Abnahme mit dem Nutzer**

Nutzer legt im Formular die Seiten Energie, Heizung, Licht und Mehr an und verschiebt eine Kachel auf eine andere Seite.
Expected: Alle fuenf Abnahmekriterien der Spezifikation sind erfuellt, Aenderung erscheint nach Anwenden ohne Flashen.

- [ ] **Step 5: Abschluss**

10 Minuten MQTT und Ping aufzeichnen und gegen die letzte Messung (1 % Verlust, 0 WLAN-Verluste) vergleichen. Ergebnis, Offenes und den Hinweis berichten, dass die Firmware nur lokal liegt.
