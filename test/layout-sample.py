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
elif mode == "sizes":
    pages = ["Start", "Größe", "Voll"]
    layouts = [
        [{"t": "tile", "k": "s_soc", "l": "Akku", "u": "%", "d": 0, "v": "arc", "m": 100, "s": "2x2"},
         {"t": "tile", "k": "s_pv", "l": "PV", "u": "W", "d": 0, "v": "bar", "m": 10000, "s": "2x1"},
         {"t": "tile", "k": "s_klein", "l": "Rund 1x1", "u": "%", "d": 0, "v": "arc", "m": 100},
         {"t": "tile", "k": "s_neg", "l": "Negativ", "u": "W", "d": 0, "v": "bar", "m": 1000},
         {"t": "dim", "k": "s_dim", "l": "Dimmer breit", "s": "2x1"},
         {"t": "btn", "k": "s_btn", "l": "Button fest", "s": "2x1", "x": 2, "y": 2}],
        [{"t": "tile", "k": "s_pv", "l": "PV gross", "u": "W", "d": 0, "s": "4x2"},
         {"t": "btn", "k": "s_btn", "l": "Button 4x1", "s": "4x1"}],
        [{"t": "tile", "k": "s_soc", "l": "Ganze Seite", "u": "%", "d": 0, "v": "arc", "m": 100, "s": "4x3"},
         {"t": "tile", "k": "s_pv", "l": "darf nicht erscheinen"}],
    ]
    states = {"s_soc": "68", "s_pv": "3200", "s_klein": "130", "s_neg": "-50", "s_dim": "40", "s_btn": "1"}
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
for k, v in (states if mode in ("sizes", "flow", "flow2") else {}).items():
    c.publish(f"colorkit/state/{k}", v).wait_for_publish()
for _ in range(90 if mode in ("flow", "flow2") else 0):
    time.sleep(1)
    for k, v in states.items():
        c.publish(f"colorkit/state/{k}", v)
time.sleep(1)
c.disconnect()
