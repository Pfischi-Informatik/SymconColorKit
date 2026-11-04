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
