icurl -s http://localhost:8002/api/state | python3 -c "
import sys, json
d = json.load(sys.stdin)
print('driver:     ', d['device_driver'])
print('sample_rate:', d['sample_rate'])
print('center_freq:', d['center_freq'])
print('hw_agc:     ', d.get('hw_agc'))
print('hw_gain:    ', d.get('hw_gain'))
"