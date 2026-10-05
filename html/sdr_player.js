(async function () {
  const BTN_CONNECT = document.getElementById("btnConnect");
  const BTN_DISCONNECT = document.getElementById("btnDisconnect");
  const VOL = document.getElementById("vol");
  const BUF = document.getElementById("buf");
  const STATUS = document.getElementById("status");
  const STATS = document.getElementById("stats");

  const WS_PORT = 8765;
  const WS_URL = `ws://${window.location.hostname}:${WS_PORT}`;

  let audioCtx = null;
  let playerNode = null;
  let ws = null;
  let packetsReceived = 0;
  let lastBufferSeconds = 0;

  function logStatus(msg) {
    STATUS.textContent = msg;
    console.log("[UI]", msg);
  }

  function updateStats() {
    STATS.textContent = `pkts=${packetsReceived}  buffer≈${lastBufferSeconds.toFixed(2)}s`;
  }

  async function initAudio() {
    if (audioCtx) return;

    audioCtx = new (window.AudioContext || window.webkitAudioContext)({
      sampleRate: 48000,
    });

    await audioCtx.audioWorklet.addModule("sdr-player-worklet.js");
    playerNode = new AudioWorkletNode(audioCtx, "sdr-player");

    playerNode.port.onmessage = (event) => {
      const msg = event.data;
      if (!msg || !msg.type) return;
      if (msg.type === "bufferStatus") {
        lastBufferSeconds = msg.seconds;
        updateStats();
      }
    };

    playerNode.connect(audioCtx.destination);
    logStatus("AudioWorklet initialized.");
  }

  async function connectWs() {
    if (ws && ws.readyState === WebSocket.OPEN) return;

    await initAudio();
    await audioCtx.resume();

    return new Promise((resolve, reject) => {
      const sock = new WebSocket(WS_URL);
      sock.binaryType = "arraybuffer";

      sock.onopen = () => {
        ws = sock;
        packetsReceived = 0;
        logStatus(`Connected to ${WS_URL}`);
        BTN_CONNECT.disabled = true;
        BTN_DISCONNECT.disabled = false;
        resolve();
      };

      sock.onerror = (err) => {
        console.error("WebSocket error:", err);
        logStatus("WebSocket error. See console.");
        reject(err);
      };

      sock.onclose = () => {
        logStatus("WebSocket closed.");
        BTN_CONNECT.disabled = false;
        BTN_DISCONNECT.disabled = true;
        ws = null;
      };

      sock.onmessage = (event) => {
        if (!(event.data instanceof ArrayBuffer)) {
          // ignore any text messages
          return;
        }
        const buf = event.data;
        // 2048 float32 samples = 8192 bytes
        const samples = new Float32Array(buf);
        packetsReceived++;

        // Send to AudioWorklet
        if (playerNode) {
          playerNode.port.postMessage({
            type: "push",
            samples,
          });
        }
      };
    });
  }

  function disconnectWs() {
    if (ws) {
      ws.close();
      ws = null;
    }
  }

  // UI handlers
  BTN_CONNECT.addEventListener("click", async () => {
    try {
      await connectWs();
    } catch (e) {
      console.error(e);
    }
  });

  BTN_DISCONNECT.addEventListener("click", () => {
    disconnectWs();
  });

  VOL.addEventListener("input", () => {
    const val = parseFloat(VOL.value);
    if (playerNode) {
      playerNode.port.postMessage({ type: "setVolume", value: val });
    }
  });

  BUF.addEventListener("input", () => {
    // For now just show it; we can wire this into logic later
    const ms = parseInt(BUF.value, 10);
    STATUS.textContent = `Buffer target: ${ms} ms (not yet enforced)`;
  });

  // Initial state
  BTN_CONNECT.disabled = false;
  BTN_DISCONNECT.disabled = true;
  logStatus("Idle. Click CONNECT to start.");
})();
