#!/usr/bin/env python3
import sys, serial, time, os

# -------- CONFIG --------
SERIAL_PORT = "/dev/ttyUSB0"  # change if needed
BAUD = 115200
BINS_PER_LINE = 100  # width of displayed spectrum
WATERFALL_HEIGHT = 30
# ------------------------

# Open serial connection
ser = serial.Serial(SERIAL_PORT, BAUD, timeout=1)

# Enable scope output
ser.write(b"\xFE\xFE\x94\xE0\x27\x00\xFD")
time.sleep(0.1)

# Prepare waterfall buffer
waterfall = [" " * BINS_PER_LINE] * WATERFALL_HEIGHT

def draw_waterfall():
    os.system("clear")
    for row in waterfall:
        print(row)

try:
    packet = bytearray()
    while True:
        b = ser.read(1)
        if not b:
            continue
        packet.append(b[0])

        # Look for end of packet (0xFD)
        if b[0] == 0xFD and len(packet) > 6:
            if packet[0:5] == b"\xFE\xFE\xE0\x94\x27":
                fft = packet[5:-1]
                # Map FFT values to ASCII brightness
                bins = []
                for val in fft[:BINS_PER_LINE]:
                    if val > 200:
                        bins.append("#")
                    elif val > 150:
                        bins.append("*")
                    elif val > 100:
                        bins.append("+")
                    elif val > 50:
                        bins.append(".")
                    else:
                        bins.append(" ")
                spectrum_line = "".join(bins)

                # Update waterfall buffer
                waterfall.pop(0)
                waterfall.append(spectrum_line)

                draw_waterfall()
            packet.clear()

except KeyboardInterrupt:
    pass
finally:
    # Disable scope output
    ser.write(b"\xFE\xFE\x94\xE0\x27\x00\xFD")
    ser.close()
