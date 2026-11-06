<?php

declare(strict_types=1);

/**
 * Test ohne Symcon: spielt MQTT-Nachrichten ein und prueft Publishes und Aktionen.
 *
 *   php -d zend.assertions=1 -d assert.exception=1 test/live.php
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VM_UPDATE = 10603;

$vars = [];    // id => [value, type, action, custom, formatted]
$scripts = [];
$requested = [];
$ran = [];

function IPS_ObjectExists($id) { global $vars; return isset($vars[$id]); }
function IPS_VariableExists($id) { global $vars; return isset($vars[$id]); }
function IPS_ScriptExists($id) { return $id === 500; }
function IPS_GetVariable($id) { global $vars; return ['VariableType' => $vars[$id][1], 'VariableAction' => $vars[$id][2], 'VariableCustomAction' => $vars[$id][3], 'VariableProfile' => $vars[$id][5] ?? '', 'VariableCustomProfile' => '']; }
function IPS_VariableProfileExists($n) { return $n === 'Hell1' || $n === 'Hell255'; }
function IPS_GetVariableProfile($n) { return ['MinValue' => 0.0, 'MaxValue' => $n === 'Hell1' ? 1.0 : 255.0]; }
function GetValue($id) { global $vars; return $vars[$id][0]; }
function GetValueFormatted($id) { global $vars; return $vars[$id][4]; }
function SetValue($id, $v) { global $vars; $vars[$id][0] = $v; }
function RequestAction($id, $v) { global $requested; $requested[] = [$id, $v]; }
function IPS_RunScriptEx($id, $p) { global $ran; $ran[] = [$id, $p]; }
function IPS_GetName($id) { return "Var$id"; }

// Archiv: $archiveIds leer = kein Archiv; $archive[var] = [Typ, Stufe => [[TimeStamp, Avg, Duration], ...]]
$archiveIds = [77];
$archive = [];
function IPS_GetInstanceListByModuleID($g) { global $archiveIds; assert($g === '{43192F0B-135B-4CE7-A0A7-1475603F3060}'); return $archiveIds; }
function AC_GetLoggingStatus($a, $v) { global $archive; return isset($archive[$v]); }
function AC_GetAggregationType($a, $v) { global $archive; return $archive[$v][0]; }
function AC_GetAggregatedValues($a, $v, $level, $start, $end, $limit)
{
    global $archive;
    assert($a === 77 && $limit === 0);
    $out = [];
    foreach ($archive[$v][$level] ?? [] as [$ts, $avg, $dur]) {
        if ($ts >= $start && $ts <= $end) {
            $out[] = ['TimeStamp' => $ts, 'Avg' => $avg, 'Duration' => $dur, 'Min' => 0, 'Max' => 0];
        }
    }
    return array_reverse($out);       // neueste zuerst wie Symcon
}

class IPSModule
{
    public $props = [], $attrs = [], $sent = [], $messages = [], $filter = '', $interval = -1, $active = true;
    public function Create(): void {}
    public function ApplyChanges(): void {}
    protected function ConnectParent($g) {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadAttributeString($n) { return $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) {}
    public $timers = [];
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; if ($n === 'Republish') { $this->interval = $i; } }
    protected function SetReceiveDataFilter($f) { $this->filter = $f; }
    protected function RegisterMessage($id, $m) { $this->messages[$id] = true; }
    protected function UnregisterMessage($id, $m) { unset($this->messages[$id]); }
    public $buffers = [];
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function HasActiveParent() { return $this->active; }
    protected function SendDebug($t, $m, $f) {}
    protected function SendDataToParent($json) { $this->sent[] = json_decode($json, true); return ''; }
}

require __DIR__ . '/../ColorKitDashboard/module.php';

function rx(ColorKitDashboard $m, string $topic, string $payload): void
{
    $m->ReceiveData(json_encode(['Topic' => $topic, 'Payload' => mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1')]));
}
function state(ColorKitDashboard $m): array
{
    $out = [];
    foreach ($m->sent as $s) {
        assert($s['Retain'] === true && $s['PacketType'] === 3);
        $out[$s['Topic']] = mb_convert_encoding($s['Payload'], 'ISO-8859-1', 'UTF-8');
    }
    $m->sent = [];
    return $out;
}

$vars = [
    1 => [3420, 1, 0, 0, ''],           // Zahl mit Faktor
    2 => [-0.01, 2, 0, 0, ''],          // rundet auf -0
    3 => [2, 1, 0, 0, '21,5 °C'],       // formatiert, Nicht-ASCII
    4 => [true, 0, 0, 0, ''],           // Bool-Kachel
    5 => ['Text', 3, 0, 0, ''],         // Text
    10 => [false, 0, 0, 0, ''],         // Button ohne Aktion -> SetValue
    11 => [false, 0, 99, 0, ''],        // Standardaktion -> RequestAction
    12 => [true, 0, 0, 77777, ''],      // Custom Action -> RequestAction
    13 => [false, 0, 99, 1, ''],        // Standardaktion deaktiviert -> SetValue
    14 => [5, 1, 0, 0, ''],             // keine Bool -> nicht umschalten
];

$m = new ColorKitDashboard();
$m->Create();
assert(count(json_decode($m->props['Tiles'], true)) === 9 && count(json_decode($m->props['Buttons'], true)) === 3);

$m->props['Tiles'] = json_encode([
    ['Key' => 'a', 'VariableID' => 1, 'Factor' => 0.001, 'Formatted' => false],
    ['Key' => 'b', 'VariableID' => 2, 'Factor' => 0.001, 'Formatted' => false],
    ['Key' => 'c', 'VariableID' => 3, 'Factor' => 1, 'Formatted' => true],
    ['Key' => 'd', 'VariableID' => 4, 'Factor' => 1, 'Formatted' => false],
    ['Key' => 'e', 'VariableID' => 5, 'Factor' => 1, 'Formatted' => false],
    ['Key' => 'leer', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false],
]);
$m->props['Buttons'] = json_encode([
    ['Key' => 'noaction', 'VariableID' => 10, 'ScriptID' => 500],
    ['Key' => 'action', 'VariableID' => 11, 'ScriptID' => 0],
    ['Key' => 'custom', 'VariableID' => 12, 'ScriptID' => 0],
    ['Key' => 'disabled', 'VariableID' => 13, 'ScriptID' => 0],
    ['Key' => 'int', 'VariableID' => 14, 'ScriptID' => 0],
    ['Key' => 'script', 'VariableID' => 0, 'ScriptID' => 500],
]);

// ApplyChanges: Registrierung, Timer, Filter, PublishAll
$m->ApplyChanges();
assert(array_keys($m->messages) === [1, 2, 3, 4, 5, 10, 11, 12, 13, 14]);
assert($m->interval === 300000);
assert($m->filter === '.*colorkit.*');
$s = state($m);
assert($s['colorkit/state/a'] === '3.42');
assert($s['colorkit/state/b'] === '-0.01' || $s['colorkit/state/b'] === '0');
assert($s['colorkit/state/c'] === '21,5 °C');
assert($s['colorkit/state/d'] === '1');
assert($s['colorkit/state/e'] === 'Text');
assert($s['colorkit/state/noaction'] === '0' && $s['colorkit/state/custom'] === '1');
assert(!isset($s['colorkit/state/leer']) && !isset($s['colorkit/state/script']));

// Klartext auf der Leitung, nicht hex (825 W * 0.001)
$vars[1][0] = 825;
$m->MessageSink(0, 1, VM_UPDATE, []);
assert($m->sent[0]['Payload'] === '0.825');
state($m);

// Unveraenderter Wert wird bei VM_UPDATE nicht erneut gesendet, PublishAll sendet immer
$m->MessageSink(0, 1, VM_UPDATE, []);
assert(state($m) === []);
$m->PublishAll();
assert(isset(state($m)['colorkit/state/a']));

// Rundung auf -0 wird zu 0
$vars[2][0] = -0.00001;
$m->buffers = [];
$m->MessageSink(0, 2, VM_UPDATE, []);
assert(state($m) === ['colorkit/state/b' => '0']);

// Mehrere Variablen pro Key, Faktor je Zeile, Berechnung aus der ersten Zeile
$saved = [$m->props['Tiles'], $vars];
$vars[1][0] = 1000; $vars[2][0] = 500.0; $vars[4][0] = true;
$m->buffers = [];
$combo = function (string $op) use ($m): void {
    $m->props['Tiles'] = json_encode([
        ['Key' => 'wb', 'VariableID' => 1, 'Factor' => 0.001, 'Formatted' => true, 'Operation' => $op],
        ['Key' => 'wb', 'VariableID' => 2, 'Factor' => 0.001, 'Formatted' => false, 'Operation' => 'Maximum'],
        ['Key' => 'wb', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
    ]);
    $m->MessageSink(0, 2, VM_UPDATE, []);
};
foreach (['Summe' => '1.5', 'Differenz' => '0.5', 'Mittelwert' => '0.75', 'Minimum' => '0.5', 'Maximum' => '1'] as $op => $expected) {
    $combo($op);
    assert(state($m) === ['colorkit/state/wb' => $expected], $op);
}
// Differenz mit drei Variablen, Bool zaehlt als 0/1, Text wird uebersprungen, ohne Operation = Summe
$m->props['Tiles'] = json_encode([
    ['Key' => 'x', 'VariableID' => 1, 'Factor' => 1, 'Operation' => 'Differenz'],
    ['Key' => 'x', 'VariableID' => 2, 'Factor' => 1],
    ['Key' => 'x', 'VariableID' => 4, 'Factor' => 1],
    ['Key' => 'x', 'VariableID' => 5, 'Factor' => 1],
    ['Key' => 'y', 'VariableID' => 1, 'Factor' => 1],
    ['Key' => 'y', 'VariableID' => 2, 'Factor' => 1],
]);
$m->MessageSink(0, 1, VM_UPDATE, []);
assert(state($m) === ['colorkit/state/x' => '499', 'colorkit/state/y' => '1500']);
// Nur Text: nichts senden
$m->buffers = [];
$m->props['Tiles'] = json_encode([['Key' => 't', 'VariableID' => 5, 'Factor' => 1], ['Key' => 't', 'VariableID' => 5, 'Factor' => 1]]);
$m->MessageSink(0, 5, VM_UPDATE, []);
assert(state($m) === []);
[$m->props['Tiles'], $vars] = $saved;

// Abgeloeste Variable wird beim naechsten ApplyChanges entfernt
$m->props['Tiles'] = '[]';
$m->props['Buttons'] = json_encode([['Key' => 'noaction', 'VariableID' => 10, 'ScriptID' => 500]] + json_decode($m->props['Buttons'], true));
$m->ApplyChanges();
assert(!isset($m->messages[1]));
assert(isset($m->messages[10]));
state($m);

// Button-Update publiziert nur diesen Zustand
$vars[10][0] = true;
$m->MessageSink(0, 10, VM_UPDATE, []);
assert(state($m) === ['colorkit/state/noaction' => '1']);
$vars[10][0] = false;

// status online -> alles senden, offline nicht
rx($m, 'colorkit/status', 'offline');
assert(state($m) === []);
rx($m, 'colorkit/status', 'online');
assert(isset(state($m)['colorkit/state/noaction']));

// Tastendruck: SetValue + Skript
rx($m, 'colorkit/cmd/noaction', '1');
assert($vars[10][0] === true);
assert($ran === [[500, ['KEY' => 'noaction', 'VALUE' => '1', 'SENDER' => 'ColorKit']]]);

// RequestAction bei Standardaktion und Custom Action, SetValue wenn deaktiviert, Int bleibt
rx($m, 'colorkit/cmd/action', '1');
rx($m, 'colorkit/cmd/custom', '1');
rx($m, 'colorkit/cmd/disabled', '1');
rx($m, 'colorkit/cmd/int', '1');
assert($requested === [[11, true], [12, false]]);
assert($vars[13][0] === true && $vars[14][0] === 5);

// Unbekannter Key und fremdes Basis-Topic tun nichts
$ran = [];
rx($m, 'colorkit/cmd/gibtsnicht', '1');
rx($m, 'colorkit2/cmd/script', '1');
rx($m, 'colorkit/state/script', '1');
assert($ran === []);

// Ohne aktiven Parent wird nichts gesendet
$m->active = false;
$m->PublishAll();
assert($m->sent === []);

// Timer aus, Basis-Topic mit Ebene
$m->active = true;
$m->props['RepublishInterval'] = 0;
$m->props['BaseTopic'] = '/home/colorkit/';
$m->ApplyChanges();
assert($m->interval === 0);
assert($m->filter === '.*home.*colorkit.*');
assert(isset(state($m)['home/colorkit/state/noaction']));

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
assert($sent[1][1] === '[{"t":"tile","k":"hp_flow","l":"WP Vorlauf","u":"°C","d":1,"x":0,"y":0},'
    . '{"t":"tile","k":"hp_mode","l":"WP Modus","u":"","d":-1,"c":[["Standby","EF6C00"],["Aus","C62828"],["Ein","2E7D32"]],"x":1,"y":0},'
    . '{"t":"tile","k":"battery_soc","l":"Akku","u":"%","d":0,"v":"arc","m":100,"x":2,"y":0},'
    . '{"t":"tile","k":"eigener_key","l":"eigener_key","u":"","d":-1,"x":3,"y":0},'
    . '{"t":"btn","k":"dhw_boost","l":"WW Boost","x":0,"y":1}]');
// Nicht genutzte Seiten werden geleert, Werte kommen nach den Layouts
foreach ([1, 2, 3, 4] as $i) {
    assert($sent[1 + $i] === ["colorkit/layout/$i", '']);
}
assert(str_contains($sent[6][0], '/state/'));

// Seiten, Zeile mit eigenen Werten, zweite Zeile desselben Keys aendert nichts, Farben
$p->props['Pages'] = json_encode([['Name' => 'Start'], ['Name' => 'Energie'], ['Name' => ' '], ['Name' => 'Energie']]);
$p->props['Tiles'] = json_encode([
    ['Key' => 'wb', 'VariableID' => 1, 'Factor' => 1, 'Page' => 'Energie', 'Title' => 'Wallbox', 'Unit' => 'kW', 'Decimals' => 2,
        'Colors' => 'Laedt=gruen; Fehler=ff0000;kaputt;x=pink;=rot'],
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
assert($sent[1][1] === '[{"t":"tile","k":"pv_power","l":"PV","u":"W","d":0,"x":0,"y":0},{"t":"tile","k":"weg","l":"weg","u":"","d":-1,"x":1,"y":0}]');
assert($sent[2][1] === '[{"t":"tile","k":"wb","l":"Wallbox","u":"kW","d":2,"c":[["Laedt","2E7D32"],["Fehler","FF0000"]],"x":0,"y":0},{"t":"btn","k":"licht","l":"Licht","x":1,"y":0}]');
assert($sent[3] === ['colorkit/layout/2', '']);
assert(in_array('colorkit/state/weg', array_column($sent, 0), true));

// Decimals leer = Standardwert, 0 = null Stellen; Seitenname mit Leerzeichen wird getrimmt
$p->props['Pages'] = json_encode([['Name' => 'Start'], ['Name' => 'Energie']]);
$p->props['Tiles'] = json_encode([
    ['Key' => 'wallbox_power', 'VariableID' => 1, 'Factor' => 1, 'Page' => 'Energie ', 'Decimals' => ''],
    ['Key' => 'pv_power', 'VariableID' => 1, 'Factor' => 1, 'Decimals' => 0],
]);
$p->props['Buttons'] = '[]';
$p->PublishAll();
$sent = layouts($p);
$p->sent = [];
assert(str_contains($sent[2][1], '"k":"wallbox_power","l":"Wallbox","u":"kW","d":0'), $sent[2][1]);
assert(str_contains($sent[1][1], '"k":"pv_power","l":"PV","u":"W","d":0'), $sent[1][1]);

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

// Dimmer: Layout, Skalierung ueber das Profil, Bool, Standardaktion, Grenzen
$d = new ColorKitDashboard();
$d->Create();
$d->active = true;
$vars[901] = [0.5, VARIABLETYPE_FLOAT, 0, 1, '', 'Hell1'];
$vars[902] = [255, VARIABLETYPE_INTEGER, 7, 0, '', 'Hell255'];
$vars[903] = [40, VARIABLETYPE_INTEGER, 0, 1, ''];
$vars[904] = [true, VARIABLETYPE_BOOLEAN, 0, 1, ''];
$d->props['Tiles'] = '[]';
$d->props['Pages'] = json_encode([['Name' => 'Start']]);
$d->props['Buttons'] = json_encode([
    ['Key' => 'f', 'VariableID' => 901, 'ScriptID' => 0, 'Type' => 'Dimmer', 'Label' => 'LED Büro'],
    ['Key' => 'i', 'VariableID' => 902, 'ScriptID' => 0, 'Type' => 'Dimmer'],
    ['Key' => 'n', 'VariableID' => 903, 'ScriptID' => 0, 'Type' => 'Dimmer'],
    ['Key' => 'b', 'VariableID' => 904, 'ScriptID' => 0, 'Type' => 'Dimmer'],
    ['Key' => 'alt', 'VariableID' => 904, 'ScriptID' => 0],
]);
$d->PublishAll();
$s = array_column(layouts($d), 1, 0);
$l = json_decode($s['colorkit/layout/0'], true);
assert($l[0] === ['t' => 'dim', 'k' => 'f', 'l' => 'LED Büro', 'x' => 0, 'y' => 0]);
assert($l[4]['t'] === 'btn');
assert($s['colorkit/state/f'] === '50' && $s['colorkit/state/i'] === '100' && $s['colorkit/state/n'] === '40' && $s['colorkit/state/b'] === '100');
assert($s['colorkit/state/alt'] === '1');
rx($d, 'colorkit/cmd/f', '25');
assert($vars[901][0] === 0.25);
$requested = [];
rx($d, 'colorkit/cmd/i', '20');
assert($requested === [[902, 51]]);
rx($d, 'colorkit/cmd/n', '999');
assert($vars[903][0] === 100);
rx($d, 'colorkit/cmd/b', '0');
assert($vars[904][0] === false);

// ===== Groessen, Anzeigen, Positionen =====
function page0(ColorKitDashboard $m): array
{
    $s = array_column(layouts($m), 1, 0);
    $m->sent = [];
    return json_decode($s['colorkit/layout/0'], true);
}
function slots(array $l): array
{
    return array_combine(array_column($l, 'k'), array_map(fn ($i) => [$i['x'], $i['y']], $l));
}
$g = new ColorKitDashboard();
$g->Create();
$g->active = true;
$vars[950] = [68, VARIABLETYPE_INTEGER, 0, 1, ''];
$g->props['Pages'] = json_encode([['Name' => 'Start']]);
$g->props['Buttons'] = '[]';

// Alte Zeilen: keine neuen Felder ausser x/y, battery_soc ist Rundanzeige mit Max 100
$g->props['Tiles'] = json_encode([
    ['Key' => 'pv_power', 'VariableID' => 950, 'Factor' => 1],
    ['Key' => 'battery_soc', 'VariableID' => 950, 'Factor' => 1],
]);
$g->PublishAll();
$l = page0($g);
assert($l[0] == ['t' => 'tile', 'k' => 'pv_power', 'l' => 'PV', 'u' => 'W', 'd' => 0, 'x' => 0, 'y' => 0]);
assert($l[1] == ['t' => 'tile', 'k' => 'battery_soc', 'l' => 'Akku', 'u' => '%', 'd' => 0, 'v' => 'arc', 'm' => 100, 'x' => 1, 'y' => 0]);

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
assert($l[0] == ['t' => 'tile', 'k' => 'a', 'l' => 'a', 'u' => '', 'd' => -1, 'v' => 'bar', 'm' => 10000, 's' => '2x2', 'x' => 0, 'y' => 0]);
assert(!isset($l[1]['s']) && $l[1]['m'] === 100);
assert($l[2]['m'] === 100);
assert($l[3]['m'] === 0.5);
assert(!isset($l[4]['v']) && !isset($l[4]['m']));
assert(slots($l) === ['a' => [0, 0], 'b' => [2, 0], 'c' => [3, 0], 'd' => [2, 1], 'e' => [3, 1]]);

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
assert(slots($l) === ['gross' => [0, 0], 'klein' => [2, 0], 'breit' => [0, 2]]);
assert($l[2]['s'] === '4x1');

// 4x3 allein fuellt die Seite, danach passt nichts mehr
$g->props['Tiles'] = json_encode([
    ['Key' => 'voll', 'VariableID' => 950, 'Factor' => 1, 'Size' => '4x3'],
    ['Key' => 'klein', 'VariableID' => 950, 'Factor' => 1],
]);
$g->props['Buttons'] = '[]';
$g->PublishAll();
assert(array_column(page0($g), 'k') === ['voll']);

// Feste Positionen: zuerst gesetzt, belegt/Rand/halb angegeben -> automatisch
$g->props['Tiles'] = json_encode([
    ['Key' => 'auto1', 'VariableID' => 950, 'Factor' => 1],
    ['Key' => 'fest', 'VariableID' => 950, 'Factor' => 1, 'Column' => '1', 'Row' => '1'],
    ['Key' => 'fest2', 'VariableID' => 950, 'Factor' => 1, 'Column' => 1, 'Row' => 1],
    ['Key' => 'rand', 'VariableID' => 950, 'Factor' => 1, 'Size' => '2x2', 'Column' => '4', 'Row' => '1'],
    ['Key' => 'halb', 'VariableID' => 950, 'Factor' => 1, 'Column' => '2'],
]);
$g->props['Buttons'] = json_encode([
    ['Key' => 'unten', 'VariableID' => 0, 'ScriptID' => 0, 'Size' => '2x1', 'Column' => '3', 'Row' => '3'],
]);
$g->PublishAll();
$l = page0($g);
assert(slots($l) === ['auto1' => [1, 0], 'fest' => [0, 0], 'fest2' => [2, 0], 'rand' => [0, 1], 'halb' => [3, 0], 'unten' => [2, 2]]);

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

// Energiefluss ohne Groesse oder mit 1x1 (Formular-Standard) wird 4x3
$f->props['Tiles'] = json_encode([['Key' => 'energy_flow', 'VariableID' => 0, 'Factor' => 1, 'Size' => '1x1']]);
$f->PublishAll();
$l = json_decode(array_column(layouts($f), 1, 0)['colorkit/layout/0'], true);
$f->sent = [];
assert($l[0]['s'] === '4x3');

// ===== Diagramme =====
$c = new ColorKitDashboard();
$c->Create();
$c->active = true;
assert($c->props['Charts'] === '[]');
$c->props['Buttons'] = '[]';
$c->props['Tiles'] = '[]';
$c->ApplyChanges();
assert($c->timers['Charts'] === 0);
$c->sent = [];
function charts(ColorKitDashboard $m): array
{
    $out = [];
    foreach ($m->sent as $s) {
        if (str_contains($s['Topic'], '/chart/')) {
            $out[substr($s['Topic'], strlen('colorkit/chart/'))] = json_decode(mb_convert_encoding($s['Payload'], 'ISO-8859-1', 'UTF-8'), true);
        }
    }
    return $out;
}
$day = mktime(0, 0, 0);
$y = (int) date('Y');
$mo = (int) date('n');
$d = (int) date('j');
$vars[970] = [0.0, VARIABLETYPE_FLOAT, 0, 0, ''];   // Zaehler kWh
$vars[971] = [0.0, VARIABLETYPE_FLOAT, 0, 0, ''];   // Leistung kW
$vars[972] = [0.0, VARIABLETYPE_FLOAT, 0, 0, ''];   // nicht archiviert
$archive[970] = [1, 5 => [[$day, 0.1, 300], [$day + 300, 0.2, 300], [$day + 600, 0.3, 300], [$day + 900, 0.25, 300], [$day - 300, 9, 300]],
    1 => [[mktime(0, 0, 0, $mo, $d - 2), 7, 86400], [$day, 5, 3600]]];
$archive[971] = [0, 5 => [[$day, 2, 300], [$day + 300, 4, 300]],
    3 => [[mktime(0, 0, 0, $mo, 1), 2, 36000], [mktime(0, 0, 0, $mo - 11, 1), 1, 7200]]];
$c->props['Charts'] = json_encode([
    ['Key' => 'heute', 'Page' => 'Start', 'Type' => 'Heute', 'Title' => 'PV heute', 'Size' => '1x1', 'Decimals' => '', 'Series' => 'PV', 'Color' => 'gelb', 'VariableID' => 970, 'Factor' => 1],
    ['Key' => 'heute', 'Page' => 'Start', 'Type' => '', 'Title' => 'falsch', 'Size' => '', 'Decimals' => '3', 'Series' => '', 'Color' => '', 'VariableID' => 971, 'Factor' => 2],
    ['Key' => 'heute', 'Series' => 'ohne', 'VariableID' => 972, 'Factor' => 1],
    ['Key' => 'heute', 'Series' => 'weg', 'VariableID' => 99999, 'Factor' => 1],
    ['Key' => 'woche', 'Page' => '-', 'Type' => '7 Tage', 'Decimals' => '0', 'Color' => 'aa00BB', 'VariableID' => 970, 'Factor' => 1],
    ['Key' => 'zwei', 'Page' => '-', 'Type' => '14 Tage', 'Unit' => 'Wh', 'VariableID' => 970, 'Factor' => 1000],
    ['Key' => 'jahr', 'Page' => '-', 'Type' => '12 Monate', 'Color' => 'kaputt', 'VariableID' => 971, 'Factor' => 1],
    ['Key' => '', 'VariableID' => 970, 'Factor' => 1],
]);
$c->ApplyChanges();
assert($c->timers['Charts'] === 300000);
$l = json_decode(array_column(layouts($c), 1, 0)['colorkit/layout/0'], true);
assert($l == [['t' => 'chart', 'k' => 'heute', 'l' => 'PV heute', 's' => '4x3', 'x' => 0, 'y' => 0]]);
$ch = charts($c);
$c->sent = [];
assert(array_keys($ch) === ['heute', 'woche', 'zwei', 'jahr']);

$h = $ch['heute'];
assert($h['t'] === 'line' && $h['u'] === 'kW' && $h['d'] === 1 && !isset($h['x']));
assert(array_column($h['s'], 'n') === ['PV', 'Var971', 'ohne']);
assert(array_column($h['s'], 'c') === ['FFB300', '1565C0', 'C62828']);
$pv = $h['s'][0]['v'];
assert(count($pv) === intdiv(time() - $day, 900) + 1);
assert($pv[0] === 2.4 && $pv[1] === 3.0 && ($pv[2] ?? null) === null, json_encode($pv));
assert($h['s'][1]['v'][0] === 6.0);           // Mittel 3 kW x Faktor 2, Stellen der ersten Zeile
assert($h['s'][2]['v'] === []);

$w = $ch['woche'];
$names = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
assert($w['t'] === 'bar' && $w['u'] === 'kWh' && $w['d'] === 0);
assert(count($w['x']) === 7 && $w['x'][6] === $names[date('w')] && $w['x'][0] === $names[date('w', mktime(0, 0, 0, $mo, $d - 6))]);
assert($w['s'][0]['c'] === 'AA00BB' && $w['s'][0]['n'] === 'Var970');
assert($w['s'][0]['v'] === [null, null, null, null, 7.0, null, 5.0], json_encode($w['s'][0]['v']));

$z = $ch['zwei'];
assert($z['u'] === 'Wh' && count($z['x']) === 14 && $z['x'][13] === date('d.m') && $z['x'][0] === date('d.m', mktime(0, 0, 0, $mo, $d - 13)));
assert($z['s'][0]['v'][13] === 5000.0);

$j = $ch['jahr'];
$months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
assert(count($j['x']) === 12 && $j['x'][11] === $months[$mo - 1] && $j['x'][0] === $months[(int) date('n', mktime(0, 0, 0, $mo - 11, 1)) - 1]);
assert($j['s'][0]['c'] === 'FFB300');         // ungueltige Farbe: Farbfolge
assert($j['s'][0]['v'][11] === 20.0 && $j['s'][0]['v'][0] === 2.0 && $j['s'][0]['v'][5] === null);

// Entfernter Key: retained Nachricht wird geloescht
$c->props['Charts'] = json_encode([['Key' => 'woche', 'Type' => '7 Tage', 'VariableID' => 970, 'Factor' => 1]]);
$c->ApplyChanges();
$t = array_column(array_map(fn ($x) => [$x['Topic'], $x['Payload']], $c->sent), 1, 0);
$c->sent = [];
assert($t['colorkit/chart/heute'] === '' && $t['colorkit/chart/jahr'] === '' && $t['colorkit/chart/woche'] !== '');
$c->PublishCharts();
$t = array_column(array_map(fn ($x) => [$x['Topic'], $x['Payload']], $c->sent), 1, 0);
$c->sent = [];
assert(!isset($t['colorkit/chart/heute']));
$c->props['Charts'] = json_encode([
    ['Key' => 'heute', 'Type' => 'Heute', 'VariableID' => 970, 'Factor' => 1],
    ['Key' => 'jahr', 'Type' => '12 Monate', 'VariableID' => 971, 'Factor' => 1],
]);

// Ohne Archiv: leere Reihen, kein Absturz
$archiveIds = [];
$c->PublishCharts();
$ch = charts($c);
$c->sent = [];
assert($ch['heute']['s'][0]['v'] === [] && $ch['jahr']['s'][0]['v'] === []);
$archiveIds = [77];

echo "OK\n";
