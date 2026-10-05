
#!/usr/bin/env python3
import os
os.environ["SOAPY_SDR_PLUGIN_PATH"] = (
	"/usr/lib/aarch64-linux-gnu/SoapySDR/modules0.8:"
	"/usr/local/lib/SoapySDR/modules0.8:"
	"/usr/local/lib/aarch64-linux-gnu/SoapySDR/modules0.8"
)
import SoapySDR

print("SoapySDR python module file:", SoapySDR.__file__)
print("Lib version:", SoapySDR.SoapySDR_lib_version() if hasattr(SoapySDR, "SoapySDR_lib_version") else "n/a")
print()
print("All enumerated devices (no filter):")
devs = SoapySDR.Device.enumerate(dict(driver="rx888"))
sdr = SoapySDR.Device(devs[0])   # instead of SoapySDR.Device(dict(driver="rx888"))
if not devs:
	print("  <none found>")
for d in devs:
	print(" ", dict(d))
print()
print("Enumerated with driver=rx888 filter:")
devs2 = SoapySDR.Device.enumerate(dict(driver="rx888"))
if not devs2:
	print("  <none found>")
for d in devs2:
	print(" ", dict(d))
