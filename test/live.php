<?php

declare(strict_types=1);

/**
 * Test ohne Symcon: spielt MQTT-Nachrichten ein und prueft Publishes und Aktionen.
 *
 *   php -d zend.assertions=1 -d assert.exception=1 test/live.php
 */

const VARIABLETYPE_BOOLEAN = 0;
const VM_UPDATE = 10603;

$vars = [];    // id => [value, type, action, custom, formatted]
$scripts = [];
$requested = [];
$ran = [];

function IPS_ObjectExists($id) { global $vars; return isset($vars[$id]); }
function IPS_VariableExists($id) { global $vars; return isset($vars[$id]); }
function IPS_ScriptExists($id) { return $id === 500; }
function IPS_GetVariable($id) { global $vars; return ['VariableType' => $vars[$id][1], 'VariableAction' => $vars[$id][2], 'VariableCustomAction' => $vars[$id][3]]; }
function GetValue($id) { global $vars; return $vars[$id][0]; }
function GetValueFormatted($id) { global $vars; return $vars[$id][4]; }
function SetValue($id, $v) { global $vars; $vars[$id][0] = $v; }
function RequestAction($id, $v) { global $requested; $requested[] = [$id, $v]; }
function IPS_RunScriptEx($id, $p) { global $ran; $ran[] = [$id, $p]; }

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
    protected function SetTimerInterval($n, $i) { $this->interval = $i; }
    protected function SetReceiveDataFilter($f) { $this->filter = $f; }
    protected function RegisterMessage($id, $m) { $this->messages[$id] = true; }
    protected function UnregisterMessage($id, $m) { unset($this->messages[$id]); }
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
assert(count(json_decode($m->props['Tiles'], true)) === 8 && count(json_decode($m->props['Buttons'], true)) === 3);

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

// Rundung auf -0 wird zu 0
$vars[2][0] = -0.00001;
$m->MessageSink(0, 2, VM_UPDATE, []);
assert(state($m) === ['colorkit/state/b' => '0']);

// Mehrere Variablen pro Key, Faktor je Zeile, Berechnung aus der ersten Zeile
$saved = [$m->props['Tiles'], $vars];
$vars[1][0] = 1000; $vars[2][0] = 500.0; $vars[4][0] = true;
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

echo "OK\n";
