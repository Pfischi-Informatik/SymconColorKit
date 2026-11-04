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
