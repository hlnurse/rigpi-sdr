#!/usr/bin/env python3
from flask import Flask, Response, render_template_string
import serial, json, time, threading
import numpy as np

# -------- CONFIG --------
SERIAL_PORT = "/dev/ttyUSB0"
BAUD = 115200
DISPLAY_BINS = 500           # initial number of bins
CENTER_FREQ_HZ = 7100000
SPAN_HZ = 500000
REFRESH_MS = 1000
WATERFALL_HEIGHT = 100
PEAK_DECAY = 0.95
# ------------------------

app = Flask(__name__)
fft_data = [0]*DISPLAY_BINS
peak_data = [0]*DISPLAY_BINS
max_val = 1
waterfall = [[0]*DISPLAY_BINS for _ in range(WATERFALL_HEIGHT)]
lock = threading.Lock()

# ---------- Serial Reading Thread ----------
def read_serial():
    global fft_data, peak_data, max_val, waterfall
    ser = serial.Serial(SERIAL_PORT, BAUD, timeout=1)
    ser.write(b"\xFE\xFE\x94\xE0\x27\x00\xFD")  # start spectrum stream
    time.sleep(0.1)
    packet = bytearray()
    try:
        while True:
            while ser.in_waiting:
                b = ser.read(1)
                packet.append(b[0])
                if b[0] == 0xFD and len(packet) > 6:
                    if packet[0:5] == b"\xFE\xFE\xE0\x94\x27":
                        fft = list(packet[5:-1])
                        # Resample to DISPLAY_BINS
                        if len(fft) != DISPLAY_BINS:
                            fft_resampled = list(np.interp(
                                np.linspace(0, len(fft)-1, DISPLAY_BINS),
                                np.arange(len(fft)),
                                fft
                            ))
                        else:
                            fft_resampled = fft
                        with lock:
                            fft_data[:] = fft_resampled
                            max_val = max(max_val*0.95, max(fft_resampled) if fft_resampled else 1)
                            for i, val in enumerate(fft_data):
                                peak_data[i] = max(peak_data[i]*PEAK_DECAY, val)
                            waterfall.pop(0)
                            waterfall.append(list(fft_data))
                    packet.clear()
            time.sleep(0.01)
    finally:
        ser.write(b"\xFE\xFE\x94\xE0\x27\x00\xFD")  # stop spectrum stream
        ser.close()

threading.Thread(target=read_serial, daemon=True).start()

# ---------- SSE Stream ----------
@app.route("/stream")
def stream():
    def generate():
        global fft_data, peak_data, waterfall, max_val
        while True:
            with lock:
                payload = {
                    "fft": fft_data,
                    "peak": peak_data,
                    "waterfall": waterfall,
                    "max_val": max_val,
                    "center_freq": CENTER_FREQ_HZ,
                    "span": SPAN_HZ
                }
            yield f"data:{json.dumps(payload)}\n\n"
            time.sleep(REFRESH_MS/1000)
    return Response(generate(), mimetype="text/event-stream")

# ---------- Peak Hold Reset ----------
@app.route("/reset_peak")
def reset_peak():
    global peak_data
    with lock:
        peak_data = [0]*len(peak_data)
    return "OK"

# ---------- Main Browser Page ----------
@app.route("/")
def index():
    return render_template_string("""
<!DOCTYPE html>
<html>
<head>
<title>IC-7300 Spectrum Analyzer</title>
<style>
body { background:black; color:white; margin:0; overflow:hidden; }
canvas { display:block; }
.controls { position:absolute; top:5px; left:5px; background:rgba(0,0,0,0.5); padding:5px; font-family: monospace;}
input { width:150px; }
button { margin-top:5px; }
</style>
</head>
<body>
<div class="controls">
    <label>Span (Hz): <input type="range" id="spanSlider" min="1000" max="5000000" value="{{ span }}" step="1000"></label><br>
    <label>Center Freq (MHz): <input type="number" id="centerFreq" value="{{ center_freq/1e6 }}" step="0.001"></label><br>
    <button id="resetPeak">Reset Peak Hold</button><br>
    <label><input type="checkbox" id="autoScale" checked> Auto-Scale</label><br>
    <label><input type="checkbox" id="avgToggle"> Enable Bin Averaging</label><br>
    <label>Averaging Factor: <input type="range" id="avgFactor" min="0.01" max="0.99" step="0.01" value="0.5"></label><span id="avgValue">0.5</span><br>
    <label>Bins: <input type="range" id="binsSlider" min="50" max="1000" value="{{ bins }}" step="1"></label>
    <span id="binsValue">{{ bins }}</span><br>
</div>
<canvas id="spectrum"></canvas>
<script>
const canvas = document.getElementById("spectrum");
const ctx = canvas.getContext("2d");
let width, height;
function resize() { width=window.innerWidth; height=window.innerHeight; canvas.width=width; canvas.height=height; }
window.addEventListener("resize", resize);
resize();

let cursorX = null;
canvas.addEventListener("mousemove", e => { cursorX = e.clientX; });
canvas.addEventListener("mouseleave", e => { cursorX = null; });

const spanSlider = document.getElementById("spanSlider");
let span = parseFloat(spanSlider.value);
spanSlider.addEventListener("input", ()=>{ span=parseFloat(spanSlider.value); });

const centerFreqInput = document.getElementById("centerFreq");
let centerFreq = parseFloat(centerFreqInput.value)*1e6;
centerFreqInput.addEventListener("change", ()=>{ centerFreq=parseFloat(centerFreqInput.value)*1e6; });

const resetPeakBtn = document.getElementById("resetPeak");
resetPeakBtn.addEventListener("click", ()=>{
    fetch("/reset_peak");
    if(latest){
        latest.peak = new Array(latest.peak.length).fill(0); // instant reset
    }
    fftSmoothed = new Array(BINS).fill(0); // clear smoothed data
});

const autoScaleCheckbox = document.getElementById("autoScale");
let autoScale = autoScaleCheckbox.checked;
autoScaleCheckbox.addEventListener("change", ()=>{ autoScale = autoScaleCheckbox.checked; });

const avgToggle = document.getElementById("avgToggle");
let useAveraging = false;
avgToggle.addEventListener("change", ()=>{ useAveraging = avgToggle.checked; });

const avgFactorSlider = document.getElementById("avgFactor");
const avgValueSpan = document.getElementById("avgValue");
let alpha = parseFloat(avgFactorSlider.value);
avgFactorSlider.addEventListener("input", ()=>{
    alpha = parseFloat(avgFactorSlider.value);
    avgValueSpan.textContent = alpha;
});

const binsSlider = document.getElementById("binsSlider");
const binsValue = document.getElementById("binsValue");
let BINS = {{ bins }};
binsSlider.addEventListener("input", ()=>{
    BINS = parseInt(binsSlider.value);
    binsValue.textContent = BINS;
    fftSmoothed = new Array(BINS).fill(0);
});

const evtSource = new EventSource("/stream");
let latest = null;
evtSource.onmessage = function(e) { latest=JSON.parse(e.data); }

let fftSmoothed = new Array(BINS).fill(0);

// Helper to interpolate FFT to desired bins
function interpolateFFT(inputFFT, targetBins){
    let resampled = new Array(targetBins);
    for(let i=0;i<targetBins;i++){
        let idx = i*(inputFFT.length-1)/(targetBins-1);
        let low = Math.floor(idx);
        let high = Math.min(Math.ceil(idx), inputFFT.length-1);
        let weight = idx - low;
        resampled[i] = inputFFT[low]*(1-weight) + inputFFT[high]*weight;
    }
    return resampled;
}

function draw(){
    if(!latest){ requestAnimationFrame(draw); return; }

    let fftResampled = interpolateFFT(latest.fft, BINS);
    let peak = interpolateFFT(latest.peak, BINS);
    let waterfallData = latest.waterfall.map(row => interpolateFFT(row,BINS));

    const spectrumHeight = height*0.3;
    const waterfallTop = spectrumHeight + 20;
    const waterfallHeight = height - waterfallTop;
    const waterfallLineHeight = waterfallHeight / waterfallData.length;

    ctx.clearRect(0,0,width,height);

    const barWidth = Math.max(width / BINS, 1);
    const localMax = autoScale ? Math.max(...fftResampled) : latest.max_val;

    // Apply bin averaging if enabled
    let fftToDraw;
    if(useAveraging){
        for(let i=0;i<BINS;i++){
            fftSmoothed[i] = alpha*fftResampled[i] + (1-alpha)*fftSmoothed[i];
        }
        fftToDraw = fftSmoothed;
    } else {
        fftToDraw = fftResampled;
    }

    // Spectrum bars
    for(let i=0;i<BINS;i++){
        let h = fftToDraw[i]/localMax * spectrumHeight;
        let ratio = fftToDraw[i]/localMax;
        ctx.fillStyle = ratio<0.33?'green':(ratio<0.66?'yellow':'red');
        ctx.fillRect(i*barWidth, spectrumHeight-h, barWidth, h);
    }

    // Peak-hold line
    ctx.strokeStyle="white"; ctx.beginPath();
    for(let i=0;i<BINS;i++){
        let h = peak[i]/localMax*spectrumHeight;
        if(i==0) ctx.moveTo(i*barWidth,spectrumHeight-h);
        else ctx.lineTo(i*barWidth,spectrumHeight-h);
    }
    ctx.stroke();

    // Vertical peak marker
    let peakIndex = fftToDraw.indexOf(Math.max(...fftToDraw));
    let xPeak = peakIndex*barWidth;
    ctx.strokeStyle="white"; ctx.beginPath();
    ctx.moveTo(xPeak,0); ctx.lineTo(xPeak,spectrumHeight); ctx.stroke();
    ctx.fillStyle="white"; ctx.fillText("Peak", xPeak+5, spectrumHeight-5);

    // Mouse frequency cursor
    if(cursorX!==null){
        ctx.strokeStyle="cyan"; ctx.beginPath();
        ctx.moveTo(cursorX,0); ctx.lineTo(cursorX,height); ctx.stroke();
        let bin = Math.floor(cursorX / width * BINS);
        let freq = centerFreq - span/2 + bin*span/BINS;
        ctx.fillStyle="cyan";
        ctx.fillText((freq/1e6).toFixed(6)+" MHz", cursorX+5, 15);
    }

    // Frequency axis
    ctx.fillStyle="white"; ctx.font="12px monospace";
    for(let i=0;i<=10;i++){
        let freq = centerFreq - span/2 + i*span/10;
        ctx.fillText((freq/1e6).toFixed(3)+" MHz", i*width/10, spectrumHeight+15);
    }

    // Waterfall
    for(let y=0; y<waterfallData.length; y++){
        let row = waterfallData[y];
        for(let x=0; x<BINS; x++){
            let ratio = row[x]/localMax;
            ctx.fillStyle = ratio<0.33?"rgb(0,64,0)":(ratio<0.66?"rgb(128,128,0)":"rgb(255,0,0)");
            ctx.fillRect(x*barWidth, waterfallTop + y*waterfallLineHeight, barWidth, waterfallLineHeight);
        }
    }

    requestAnimationFrame(draw);
}
draw();
</script>
</body>
</html>
""", center_freq=CENTER_FREQ_HZ, span=SPAN_HZ, bins=DISPLAY_BINS)

# ---------- Run Flask ----------
if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, threaded=True)
