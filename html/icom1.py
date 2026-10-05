import serial, time

ser = serial.Serial("/dev/ttyUSB0", 115200, timeout=1)
ser.write(b"\xFE\xFE\x94\xE0\x27\x00\xFD")  # enable scope
time.sleep(0.1)

count = 0
packet = bytearray()

while count < 5:  # just show first 5 packets
    b = ser.read(1)
    if not b:
        continue
    packet.append(b[0])
    if b[0] == 0xFD and len(packet) > 6:
        if packet[0:5] == b"\xFE\xFE\xE0\x94\x27":
            fft = packet[5:-1]
            print(list(fft))  # print numeric values
            count += 1
        packet.clear()

ser.write(b"\xFE\xFE\x94\xE0\x27\x00\xFD")  # disable scope
ser.close()
