<?php

declare(strict_types=1);

/**
 * ColorKit Dashboard - IP-Symcon Modul
 * ------------------------------------
 * Verbindet Symcon ueber den integrierten MQTT Server mit einem ThingPulse
 * Color Kit Grande (ESP32, LVGL-Firmware). Topics:
 *
 *   <Basis>/state/<key>  Symcon -> Display: Kachelwerte, Button-Zustaende (retained)
 *   <Basis>/cmd/<key>    Display -> Symcon: Button getippt
 *   <Basis>/status       Display -> Symcon: online | offline (retained, LWT)
 */
class ColorKitDashboard extends IPSModule
{
    private const MQTT_SERVER = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    private const DEFAULT_TILES = [
        ['Key' => 'pv_power', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'house_power', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'grid_power', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'wallbox_power', 'VariableID' => 0, 'Factor' => 0.001, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'hp_mode', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => true, 'Operation' => 'Summe'],
        ['Key' => 'hp_flow', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'room_temp', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'outdoor_temp', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
        ['Key' => 'battery_soc', 'VariableID' => 0, 'Factor' => 1, 'Formatted' => false, 'Operation' => 'Summe'],
    ];

    private const DEFAULT_BUTTONS = [
        ['Key' => 'wallbox_pv', 'VariableID' => 0, 'ScriptID' => 0],
        ['Key' => 'wallbox_fast', 'VariableID' => 0, 'ScriptID' => 0],
        ['Key' => 'dhw_boost', 'VariableID' => 0, 'ScriptID' => 0],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent(self::MQTT_SERVER);

        $this->RegisterPropertyString('BaseTopic', 'colorkit');
        $this->RegisterPropertyInteger('RepublishInterval', 300);
        $this->RegisterPropertyString('Tiles', json_encode(self::DEFAULT_TILES));
        $this->RegisterPropertyString('Buttons', json_encode(self::DEFAULT_BUTTONS));

        $this->RegisterAttributeString('RegisteredVariables', '[]');

        $this->RegisterTimer('Republish', 0, 'CKD_PublishAll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Ohne "/" filtern: im JSON kann "/" als "\/" escaped sein
        $parts = array_map('preg_quote', explode('/', $this->baseTopic()));
        $this->SetReceiveDataFilter('.*' . implode('.*', $parts) . '.*');

        foreach (json_decode($this->ReadAttributeString('RegisteredVariables'), true) as $id) {
            if (IPS_ObjectExists($id)) {
                $this->UnregisterMessage($id, VM_UPDATE);
            }
        }

        $ids = array_merge(array_column($this->rows('Tiles'), 'VariableID'), array_column($this->rows('Buttons'), 'VariableID'));
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0 && IPS_VariableExists($id))));
        foreach ($ids as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->WriteAttributeString('RegisteredVariables', json_encode($ids));

        $this->SetTimerInterval('Republish', max(0, $this->ReadPropertyInteger('RepublishInterval')) * 1000);

        if ($this->HasActiveParent()) {
            $this->PublishAll();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        foreach ($this->tileGroups() as $key => $group) {
            if (in_array($SenderID, array_column($group, 'VariableID'), true)) {
                $this->publishTile($key, $group, false);
            }
        }
        foreach ($this->rows('Buttons') as $button) {
            if ($button['VariableID'] === $SenderID) {
                $this->publishButton($button, false);
            }
        }
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        $topic = (string) ($data['Topic'] ?? '');
        // IPSModule: Nutzlast Byte fuer Byte als Latin-1 nach UTF-8 kodiert (hex nur bei IPSModuleStrict)
        $payload = mb_convert_encoding((string) ($data['Payload'] ?? ''), 'ISO-8859-1', 'UTF-8');
        $this->SendDebug('RX', $topic . ' = ' . $payload, 0);

        $base = $this->baseTopic();
        if ($topic === $base . '/status') {
            if ($payload === 'online') {
                $this->PublishAll();
            }
        } elseif (str_starts_with($topic, $base . '/cmd/')) {
            $this->pressButton(substr($topic, strlen($base . '/cmd/')), $payload);
        }
        return '';
    }

    public function PublishAll(): void
    {
        foreach ($this->tileGroups() as $key => $group) {
            $this->publishTile($key, $group, true);
        }
        foreach ($this->rows('Buttons') as $button) {
            $this->publishButton($button, true);
        }
    }

    // Zeilen mit gleichem Key bilden eine Kachel
    private function tileGroups(): array
    {
        $groups = [];
        foreach ($this->rows('Tiles') as $tile) {
            if ($tile['Key'] !== '') {
                $groups[$tile['Key']][] = $tile;
            }
        }
        return $groups;
    }

    private function publishTile(string $key, array $group, bool $force): void
    {
        $operation = $group[0]['Operation'] ?? 'Summe'; // gilt aus der ersten Zeile
        $tiles = array_values(array_filter($group, fn ($tile) => IPS_VariableExists($tile['VariableID'])));
        if (count($tiles) === 0) {
            return;
        }
        if (count($tiles) > 1) {
            $this->publishCombined($key, $tiles, $operation, $force);
            return;
        }

        $tile = $tiles[0];
        if ($tile['Formatted'] ?? false) {
            $text = GetValueFormatted($tile['VariableID']);
        } else {
            $value = GetValue($tile['VariableID']);
            if (is_bool($value)) {
                $text = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $text = $this->number($value * ($tile['Factor'] ?? 1));
            } else {
                $text = (string) $value;
            }
        }
        $this->publish($key, $text, $force);
    }

    private function publishCombined(string $key, array $tiles, string $operation, bool $force): void
    {
        $values = [];
        foreach ($tiles as $tile) {
            $value = GetValue($tile['VariableID']);
            if (is_bool($value) || is_numeric($value)) {
                $values[] = (float) $value * ($tile['Factor'] ?? 1);
            }
        }
        if (count($values) === 0) {
            $this->SendDebug('TX', 'Keine Zahlenwerte fuer ' . $key, 0);
            return;
        }
        $result = match ($operation) {
            'Differenz'  => 2 * $values[0] - array_sum($values), // erste Zeile minus alle weiteren
            'Mittelwert' => array_sum($values) / count($values),
            'Minimum'    => min($values),
            'Maximum'    => max($values),
            default      => array_sum($values),
        };
        $this->publish($key, $this->number($result), $force);
    }

    private function number(float|int $value): string
    {
        return (string) (round($value, 4) + 0.0); // + 0.0: kein "-0" auf dem Display
    }

    private function publishButton(array $button, bool $force): void
    {
        if ($button['Key'] !== '' && IPS_VariableExists($button['VariableID'])) {
            $this->publish($button['Key'], GetValue($button['VariableID']) ? '1' : '0', $force);
        }
    }

    // $force = false: unveraenderte Werte nicht senden, das entlastet die Funkstrecke
    private function publish(string $key, string $payload, bool $force): void
    {
        if (!$force && $this->GetBuffer('tx_' . $key) === $payload) {
            return;
        }
        $topic = $this->baseTopic() . '/state/' . $key;
        if (!$this->HasActiveParent()) {
            $this->SendDebug('TX', 'Kein aktiver Parent, verworfen: ' . $topic . ' = ' . $payload, 0);
            return;
        }
        $this->SetBuffer('tx_' . $key, $payload);
        $this->SendDebug('TX', $topic . ' = ' . $payload, 0);
        $this->SendDataToParent(json_encode([
            'DataID'           => self::TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => true,
            'Topic'            => $topic,
            'Payload'          => mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1'),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function pressButton(string $key, string $payload): void
    {
        foreach ($this->rows('Buttons') as $button) {
            if ($button['Key'] !== $key) {
                continue;
            }
            if (IPS_VariableExists($button['VariableID'])) {
                $this->toggle($button['VariableID']);
            }
            if (IPS_ScriptExists($button['ScriptID'])) {
                IPS_RunScriptEx($button['ScriptID'], ['KEY' => $key, 'VALUE' => $payload, 'SENDER' => 'ColorKit']);
            }
            return;
        }
        $this->SendDebug('RX', 'Kein Button mit Key ' . $key, 0);
    }

    private function toggle(int $id): void
    {
        $variable = IPS_GetVariable($id);
        if ($variable['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            $this->SendDebug('RX', 'Variable ' . $id . ' ist nicht Bool, nicht umgeschaltet', 0);
            return;
        }
        // Eigene Aktion: Skript-ID > 1 hat Vorrang, 0 = Standardaktion, 1 = Standardaktion deaktiviert
        $custom = $variable['VariableCustomAction'];
        if ($custom > 1 || ($custom === 0 && $variable['VariableAction'] > 0)) {
            RequestAction($id, !GetValue($id));
        } else {
            SetValue($id, !GetValue($id));
        }
    }

    private function rows(string $property): array
    {
        return json_decode($this->ReadPropertyString($property), true) ?: [];
    }

    private function baseTopic(): string
    {
        return trim($this->ReadPropertyString('BaseTopic'), '/');
    }
}
