#!/usr/bin/env python3
from flask import Flask, Response, render_template_string
import socket, json, time, threading
import numpy as np

# -------- CONFIG --------
SCOPE_HOST = "localhost"    # where rigctld scope stream runs
SCOPE_PORT = 4532
DISPLAY_BINS = 500          # initial display bins; UI slider can resample client-side
CENTER_FREQ_HZ = 7100000
SPAN_HZ = 500000
REFRESH_MS = 1000
WATERFALL_HEIGHT = 100      # number of waterfall rows kept on server
PEAK_DECAY = 0.95
RECONNECT_DELAY = 2.0       # seconds between reconnect attempts if the socket drops
# ------------------------

app = Flask(__name__)
fft_data = [0]*DISPLAY_BINS
peak_data = [0]*DISPLAY_BINS
max_val = 1
waterfall = [[0]*DISPLAY_BINS for _ in range(WATERFALL_HEIGHT)]
lock = threading.Lock()

def resample(arr, n_bins):
    if len(arr) == n_bins:
        return arr
    if len(arr) == 0:
        return [0]*n_bins
    # linear interpolation to n_bins
    xp = np.arange(len(arr))
    xnew = np.linspace(0, len(arr)-1, n_bins)
    return list(np.interp(xnew, xp, arr))

# ---------- Scope TCP Reader (rigctld :20001) ----------
def read_scope_socket():
    """
    Connects to rigctld scope stream and parses Icom scope frames:
    FE FE E0 94 27 [payload bytes...] FD
    Payload here is treated as a vector of 0..255 magnitudes.
    """
    global fft_data, peak_data, max_val, waterfall
    while True:
        try:
            with socket.create_connection((SCOPE_HOST, SCOPE_PORT), timeout=5) as s:
                s.settimeout(1.0)
                packet = bytearray()
                while True:
                    try:
                        chunk = s.recv(4096)
                        if not chunk:
                            raise ConnectionError("scope stream closed")
                        for b in chunk:
                            packet.append(b)
                            if b == 0xFD and len(packet) > 6:
                                # look for header
                                if packet[:5] == b"\xFE\xFE\xE0\x94\x27":
                                    # payload (magnitudes) are between header and 0xFD
                                    payload = list(packet[5:-1])
                                    # resample to our DISPLAY_BINS server-side
                                    fft_resampled = resample(payload, DISPLAY_BINS)
                                    with lock:
                                        fft_data[:] = fft_resampled
                                        # rolling max for manual scaling fallback
                                        if fft_resampled:
                                            max_val = max(max_val*0.95, max(fft_resampled))
                                        # peak hold update
                                        for i, val in enumerate(fft_data):
                                            peak_data[i] = max(peak_data[i]*PEAK_DECAY, val)
                                        # update waterfall (oldest at index 0)
                                        waterfall.pop(0)
                                        waterfall.append(list(fft_data))
                                packet.clear()
                    except socket.timeout:
                        # just loop; keep reading
                        continue
        except Exception as e:
            # Could not connect or lost connection — retry
            time.sleep(RECONNECT_DELAY)

# Launch reader thread
threading.Thread(target=read_scope_socket, daemon=True).start()

# ---------- SSE Stream ----------
@app.route("/stream")
def stream():
    def generate():
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
<title>Rigctld Scope Viewer</title>
<style>
body { background:black; color:white; margin:0; overflow:hidden; }
canvas { display:block; }
.controls { position:absolute; top:5px; left:5px; background:rgba(0,0,0,0.5); padding:5px; font-family: monospace;}
.controls input[type="range"] { width:180px; }
button { margin-top:5px; }
</style>
</head>
<body>
<div class="controls">
    <div><strong>Source:</strong> rigctld scope @ {{ host }}:{{ port }}</div>
    <label>Span (Hz): <input type="range" id="spanSlider" min="1000" max="5000000" value="{{ span }}" step="1000"></label><br>
    <label>Center Freq (MHz): <input type="number" id="centerFreq" value="{{ center_freq/1e6 }}" step="0.001"></label><br>
    <button id="resetPeak">Reset Peak Hold</button><br>
    <label><input type="checkbox" id="autoScale" checked> Auto-Scale</label><br>
    <label><input type="checkbox" id="avgToggle"> Enable Bin Averaging</label><br>
    <label>Averaging Factor: <input type="range" id="avgFactor" min="0.01" max="0.99" step="0.01" value="0.5"></label> <span id="avgValue">0.5</span><br>
    <label>Bins: <input type="range" id="binsSlider" min="50" max="1000" value="{{ bins }}" step="1"></label>
    <span id="binsValue">{{ bins }}</span><br>
</div>
<canvas id="spectrum"></canvas>
<script>
const canvas = document.getElementById("spectrum");
const ctx = canvas.getContext("2d");
let width, height;
function resize(){ width=window.innerWidth; height=window.innerHeight; canvas.width=width; canvas.height=height; }
window.addEventListener("resize", resize); resize();

let cursorX = null;
canvas.addEventListener("mousemove", e => { cursorX = e.clientX; });
canvas.addEventListener("mouseleave", () => { cursorX = null; });

const spanSlider = document.getElementById("spanSlider");
let span = parseFloat(spanSlider.value);
spanSlider.addEventListener("input", ()=>{ span = parseFloat(spanSlider.value); });

const centerFreqInput = document.getElementById("centerFreq");
let centerFreq = parseFloat(centerFreqInput.value)*1e6;
centerFreqInput.addEventListener("change", ()=>{ centerFreq = parseFloat(centerFreqInput.value)*1e6; });

const resetPeakBtn = document.getElementById("resetPeak");
resetPeakBtn.addEventListener("click", ()=>{
    fetch("/reset_peak");
    if(latest){ latest.peak = new Array(latest.peak.length).fill(0); }
    fftSmoothed = new Array(BINS).fill(0);
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
avgFactorSlider.addEventListener("input", ()=>{ alpha = parseFloat(avgFactorSlider.value); avgValueSpan.textContent = alpha; });

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
evtSource.onmessage = (e) => { latest = JSON.parse(e.data); }

let fftSmoothed = new Array(BINS).fill(0);

// Linear interpolation to target bins
function interpolateFFT(inputFFT, targetBins){
    if(inputFFT.length === targetBins) return inputFFT.slice();
    const resampled = new Array(targetBins);
    const N = inputFFT.length;
    for(let i=0;i<targetBins;i++){
        const idx = i*(N-1)/(targetBins-1);
        const lo = Math.floor(idx), hi = Math.min(Math.ceil(idx), N-1);
        const w = idx - lo;
        resampled[i] = inputFFT[lo]*(1-w) + inputFFT[hi]*w;
    }
    return resampled;
}

function draw(){
    if(!latest){ requestAnimationFrame(draw); return; }

    // Resample server FFT/peak/waterfall to current BINS
    const fftResampled = interpolateFFT(latest.fft, BINS);
    const peak = interpolateFFT(latest.peak, BINS);
    const waterfallData = latest.waterfall.map(row => interpolateFFT(row, BINS));

    const spectrumHeight = height*0.3;
    const waterfallTop = spectrumHeight + 20;
    const waterfallHeight = height - waterfallTop;
    const waterfallLineHeight = waterfallHeight / waterfallData.length;

    ctx.clearRect(0,0,width,height);

    const barWidth = Math.max(width / BINS, 1);
    const localMax = autoScale ? Math.max(...fftResampled) : latest.max_val || 1;

    // Optional averaging (EMA)
    let fftToDraw;
    if(useAveraging){
        for(let i=0;i<BINS;i++){
            fftSmoothed[i] = alpha*fftResampled[i] + (1-alpha)*fftSmoothed[i];
        }
        fftToDraw = fftSmoothed;
    }else{
        fftToDraw = fftResampled;
    }

    // Spectrum bars
    for(let i=0;i<BINS;i++){
        const ratio = fftToDraw[i]/localMax;
        const h = ratio * spectrumHeight;
        ctx.fillStyle = ratio<0.33 ? 'green' : (ratio<0.66 ? 'yellow' : 'red');
        ctx.fillRect(i*barWidth, spectrumHeight-h, barWidth, h);
    }

    // Peak-hold line
    ctx.strokeStyle="white"; ctx.beginPath();
    for(let i=0;i<BINS;i++){
        const h = (peak[i]/localMax) * spectrumHeight;
        if(i===0) ctx.moveTo(i*barWidth, spectrumHeight-h);
        else ctx.lineTo(i*barWidth, spectrumHeight-h);
    }
    ctx.stroke();

    // Peak marker
    let peakIndex = 0, peakVal = -Infinity;
    for(let i=0;i<BINS;i++){ if(fftToDraw[i] > peakVal){ peakVal = fftToDraw[i]; peakIndex = i; } }
    const xPeak = peakIndex*barWidth;
    ctx.strokeStyle="white"; ctx.beginPath(); ctx.moveTo(xPeak,0); ctx.lineTo(xPeak,spectrumHeight); ctx.stroke();
    ctx.fillStyle="white"; ctx.fillText("Peak", xPeak+5, spectrumHeight-5);

    // Mouse frequency cursor
    if(cursorX!==null){
        ctx.strokeStyle="cyan"; ctx.beginPath(); ctx.moveTo(cursorX,0); ctx.lineTo(cursorX,height); ctx.stroke();
        const bin = Math.floor(cursorX / width * BINS);
        const freq = centerFreq - span/2 + bin*span/BINS;
        ctx.fillStyle="cyan"; ctx.fillText((freq/1e6).toFixed(6)+" MHz", cursorX+5, 15);
    }

    // Frequency axis
    ctx.fillStyle="white"; ctx.font="12px monospace";
    for(let i=0;i<=10;i++){
        const freq = centerFreq - span/2 + i*span/10;
        ctx.fillText((freq/1e6).toFixed(3)+" MHz", i*width/10, spectrumHeight+15);
    }

    // Contiguous waterfall (line height equals spacing)
    for(let y=0; y<waterfallData.length; y++){
        const row = waterfallData[y];
        const yTop = waterfallTop + y*waterfallLineHeight;
        for(let x=0; x<BINS; x++){
            const ratio = row[x]/localMax;
            ctx.fillStyle = ratio<0.33 ? "rgb(0,64,0)" : (ratio<0.66 ? "rgb(128,128,0)" : "rgb(255,0,0)");
            ctx.fillRect(x*barWidth, yTop, barWidth, waterfallLineHeight);
        }
    }

    requestAnimationFrame(draw);
}
draw();
</script>
</body>
</html>
""", host=SCOPE_HOST, port=SCOPE_PORT, center_freq=CENTER_FREQ_HZ, span=SPAN_HZ, bins=DISPLAY_BINS)

# ---------- Run Flask ----------
if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, threaded=True)
