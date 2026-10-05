class SdrPlayerProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    this.queue = [];
    this.readIndex = 0;
    this.volume = 1.0;
    this.sampleRate = sampleRate; // AudioWorklet global
    this.totalQueuedSamples = 0;

    this.port.onmessage = (event) => {
      const msg = event.data;
      if (!msg || !msg.type) return;

      switch (msg.type) {
        case "push":
          // msg.samples is a Float32Array (copied via structured clone)
          if (msg.samples && msg.samples.length) {
            this.queue.push(msg.samples);
            this.totalQueuedSamples += msg.samples.length;
          }
          break;

        case "setVolume":
          this.volume = msg.value ?? 1.0;
          break;
      }
    };
  }

  process(inputs, outputs, parameters) {
    const output = outputs[0][0]; // mono channel
    const outLen = output.length;
    let i = 0;

    while (i < outLen) {
      if (this.queue.length === 0) {
        // Underflow: no data, output silence
        for (; i < outLen; i++) {
          output[i] = 0;
        }
        break;
      }

      const current = this.queue[0];
      const remaining = current.length - this.readIndex;
      const needed = outLen - i;
      const n = remaining < needed ? remaining : needed;

      for (let k = 0; k < n; k++) {
        output[i + k] = current[this.readIndex + k] * this.volume;
      }

      i += n;
      this.readIndex += n;
      this.totalQueuedSamples -= n;

      if (this.readIndex >= current.length) {
        this.queue.shift();
        this.readIndex = 0;
      }
    }

    // Report buffer time every ~0.25 seconds
    if (!this._counter) this._counter = 0;
    this._counter++;
    if (this._counter >= 10) {
      this._counter = 0;
      const seconds = this.totalQueuedSamples / this.sampleRate;
      this.port.postMessage({
        type: "bufferStatus",
        seconds,
      });
    }

    return true;
  }
}

registerProcessor("sdr-player", SdrPlayerProcessor);
