(() => {
  'use strict';

  const panel = document.querySelector('#elmerControl');
  if (!panel) return;
  const toggle = panel.querySelector('#elmerControlToggle');
  const form = panel.querySelector('#elmerControlForm');
  const command = panel.querySelector('#elmerControlCommand');
  const run = panel.querySelector('#elmerControlRun');
  const mic = panel.querySelector('#elmerControlMic');
  const wake = panel.querySelector('#elmerControlWake');
  const speak = panel.querySelector('#elmerControlSpeak');
  const voiceOrb = panel.querySelector('#elmerVoiceOrb');
  const status = panel.querySelector('#elmerControlStatus');
  const result = panel.querySelector('#elmerControlResult');
  const history = panel.querySelector('#elmerControlHistory');
  const historyList = panel.querySelector('#elmerControlHistoryList');
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const canBrowserSpeak = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  const canSpeak = typeof window.Audio === 'function' || canBrowserSpeak;
  let recognition = null;
  let recognitionMode = '';
  let commandRecorder = null;
  let commandStream = null;
  let commandStreamPromise = null;
  let commandStreamWarmTimer = 0;
  let commandStartedOnPointerDown = false;
  let commandChunks = [];
  let commandRecordingTimer = 0;
  let commandRecordingStarted = 0;
  let commandRecordingStarting = false;
  let commandRecordingCancelRequested = false;
  let transcriptionPending = false;
  let pendingVoiceConfirmation = null;
  let commandLanguageHint = '';
  let wakeEnabled = false;
  let awaitingCommand = false;
  let restartTimer = 0;
  let speechStartTimer = 0;
  let speechSequence = 0;
  let voiceOrbSpeaking = false;
  const speechAudio = typeof window.Audio === 'function' ? new Audio() : null;
  const speechCueAudio = typeof window.Audio === 'function' ? new Audio('/Recordings/beep.mp3') : null;
  if (speechCueAudio) {
    speechCueAudio.preload = 'auto';
    speechCueAudio.playsInline = true;
  }
  let speechAudioUrl = '';
  let speechRequest = null;
  let speechContext = null;
  let speechStreamEndTimer = 0;
  const speechSources = new Set();
  const prefetchedSpeech = new Map();
  let generatedSpeechVoice = 'marin';
  const cancellationAcknowledgement = 'Okay. Cancelled. Nothing was changed.';
  let lastCancellationAcknowledgement = 0;
  let shortwaveScanActive = false;
  let shortwaveScanPaused = false;
  const commandHistoryKey = 'elmerControlCommandHistory';
  let commandHistory = [];

  fetch('/programs/ElmerPreferences.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'preferences'},
    body: JSON.stringify({action: 'status'})
  }).then(response => response.ok ? response.json() : {})
    .then(data => { generatedSpeechVoice = String(data.voice || 'marin').trim().toLowerCase() || 'marin'; })
    .catch(() => {});

  function speechPayload(payload) {
    return generatedSpeechVoice ? {...payload, voice: generatedSpeechVoice} : payload;
  }
  try {
    const saved = JSON.parse(localStorage.getItem(commandHistoryKey) || '[]');
    if (Array.isArray(saved)) commandHistory = saved.filter(value => typeof value === 'string' && value.trim()).slice(-50);
  } catch (_) {}
  let commandHistoryIndex = commandHistory.length;
  let commandHistoryDraft = '';

  // Once the operator interacts with this drawer, its keyboard remains
  // isolated until the operator deliberately clicks elsewhere in the Tuner.
  // This also covers key-up events delivered after a native confirm dialog.
  window.elmerControlKeyboardActive = false;
  panel.addEventListener('pointerdown', () => { window.elmerControlKeyboardActive = true; });
  panel.addEventListener('focusin', () => { window.elmerControlKeyboardActive = true; });
  document.addEventListener('pointerdown', event => {
    if (!panel.contains(event.target)) window.elmerControlKeyboardActive = false;
  }, true);
  ['keydown', 'keypress', 'keyup'].forEach(type => {
    panel.addEventListener(type, event => event.stopPropagation());
  });

  function refreshVoiceOrb(text = status.textContent) {
    if (!voiceOrb) return;
    const message = String(text || '').toLowerCase();
    let state = 'ready';
    if (voiceOrbSpeaking) state = 'speaking';
    else if (/listening|microphone|recording/.test(message)) state = 'listening';
    else if (/transcrib|interpret|prepar|check|chang|verif|connect|disconnect|power|tuning|scan|approval received|reading the proposed/.test(message)) state = 'thinking';
    voiceOrb.dataset.state = state;
    voiceOrb.title = state === 'listening' ? 'Elmer is listening'
      : state === 'thinking' ? 'Elmer is working'
      : state === 'speaking' ? 'Elmer is speaking'
      : 'Elmer voice is ready';
  }

  const setStatus = text => {
    status.textContent = text || '';
    refreshVoiceOrb(text);
  };
  const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[character]);

  function rememberCommand(value) {
    const text = String(value || '').trim();
    if (!text) return;
    if (commandHistory[commandHistory.length - 1] !== text) commandHistory.push(text);
    commandHistory = commandHistory.slice(-50);
    commandHistoryIndex = commandHistory.length;
    commandHistoryDraft = '';
    try { localStorage.setItem(commandHistoryKey, JSON.stringify(commandHistory)); } catch (_) {}
  }

  command.addEventListener('keydown', event => {
    if ((event.key !== 'ArrowUp' && event.key !== 'ArrowDown') || event.altKey || event.ctrlKey || event.metaKey) return;
    if (!commandHistory.length) return;
    event.preventDefault();
    event.stopPropagation();
    if (event.key === 'ArrowUp') {
      if (commandHistoryIndex === commandHistory.length) commandHistoryDraft = command.value;
      commandHistoryIndex = Math.max(0, commandHistoryIndex - 1);
      command.value = commandHistory[commandHistoryIndex] || '';
    } else if (commandHistoryIndex < commandHistory.length - 1) {
      commandHistoryIndex += 1;
      command.value = commandHistory[commandHistoryIndex] || '';
    } else {
      commandHistoryIndex = commandHistory.length;
      command.value = commandHistoryDraft;
    }
    command.setSelectionRange(command.value.length, command.value.length);
  });
  command.addEventListener('input', () => {
    commandHistoryIndex = commandHistory.length;
    commandHistoryDraft = command.value;
  });

  function normalizeVoiceText(value) {
    return String(value || '')
      .replace(/^\s*(?:till|tell)\s+tony\b/i, 'Tune to twenty')
      .replace(/^\s*(?:tim|team|tin)\s+(?:tutti|tooty|toti|tuddy)\b/i, 'Tune to twenty')
      .replace(/^\s*(?:a\s+)?(?:reply|rely)\s+to(?:\s+the)?\b/i, 'Tune to')
      .replace(/^\s*(?:two|too|to)\s+to\b/i, 'Tune to')
      .replace(/^\s*tune\s+(?:two|too)\b/i, 'Tune to')
      .replace(/\b(change|set)(\s+the)?\s+mood\s+to\b/ig, '$1$2 mode to')
      .replace(/\bww[ev]\b/ig, 'WWV')
      .replace(/\bmegahurts\b/ig, 'megahertz')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function radioTranscriptScore(value) {
    const text = normalizeVoiceText(value).toLowerCase();
    let score = 0;
    if (/^tune to\b/.test(text)) score += 20;
    if (/^(?:restore|show history|history)\b/.test(text)) score += 20;
    if (/\b(?:radio|frequency|megahertz|mhz|restore|history)\b/.test(text)) score += 8;
    if (/\b(?:zero|oh|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|\d)\b/.test(text)) score += 5;
    if (/\b(?:till|tell|tony|tim|team|tin|tutti|tooty|toti|tuddy|hammer)\b/i.test(String(value))) score -= 12;
    if (/\b(?:[a-z]\d+|zero?to|zeroto)\b/i.test(text)) score -= 12;
    return score;
  }

  function bestRecognitionAlternative(result) {
    const candidates = [];
    for (let index = 0; index < result.length; index += 1) {
      const raw = result[index].transcript.trim();
      candidates.push({raw, text: normalizeVoiceText(raw), score: radioTranscriptScore(raw)});
    }
    candidates.sort((left, right) => right.score - left.score);
    const best = candidates[0] || {raw: '', text: '', score: 0};
    best.needsReview = best.score < 15 || /\b(?:till|tell|tony|tim|team|tin|tutti|tooty|toti|tuddy|hammer|[a-z]\d+|zero?to|zeroto)\b/i.test(best.raw);
    return best;
  }

  function showResult(message, kind = '') {
    result.className = `elmer-control-result visible ${kind}`.trim();
    result.innerHTML = message;
  }

  function synchronizeKeyerCw(hold, text = '', speed = null) {
    const input = document.getElementById('cwi');
    const label = document.getElementById('myHold');
    const button = document.getElementById('holdButton');
    if (!input || !button) return false;
    window.holdCW = Boolean(hold);
    if (hold && text) input.value = String(text);
    if (label) label.textContent = hold ? 'HOLD\u00a0' : '';
    button.classList.toggle('btn-danger', Boolean(hold));
    button.classList.toggle('btn-color', !hold);
    button.classList.toggle('btn-primary', !hold);
    const speedNumber = Number(speed);
    if (Number.isFinite(speedNumber) && speedNumber >= 5 && speedNumber <= 60) {
      window.tSpeed = String(speedNumber);
      window.sliderSpeedRef = speedNumber;
      const output = document.getElementById('myKeyerSpeedVal');
      if (output) output.textContent = `${speedNumber} WPM`;
      if (window.jQuery && jQuery('#sliderSpeed').hasClass('ui-slider')) {
        jQuery('#sliderSpeed').slider('value', speedNumber);
      }
    }
    return true;
  }

  async function synchronizeKeyerFromServer() {
    if (!document.getElementById('cwi')) return;
    try {
      const response = await fetch('/programs/ElmerRigControl.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'},
        body: JSON.stringify({phase: 'cw_status'})
      });
      const data = await response.json().catch(() => ({}));
      if (response.ok) synchronizeKeyerCw(Boolean(data.cw_hold), data.cw_hold ? data.cw_text : '', data.cw_speed_wpm);
    } catch (_) {}
  }

  function ensureSpeechContext() {
    if (speechContext) return speechContext;
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return null;
    try { speechContext = new AudioContext({sampleRate: 24000}); } catch (_) { speechContext = new AudioContext(); }
    return speechContext;
  }

  function unlockSpeechPlayback() {
    const context = ensureSpeechContext();
    if (!context) return;
    context.resume().catch(() => {});
    try {
      const source = context.createBufferSource();
      source.buffer = context.createBuffer(1, 1, context.sampleRate);
      source.connect(context.destination);
      source.start();
    } catch (_) {}
  }

  function stopStreamedSpeech() {
    window.clearTimeout(speechStreamEndTimer);
    speechStreamEndTimer = 0;
    speechSources.forEach(source => { try { source.stop(); } catch (_) {} });
    speechSources.clear();
  }

  function stopSpokenPlayback() {
    if (speechRequest) speechRequest.abort();
    speechRequest = null;
    if (speechAudio) {
      speechAudio.onended = null;
      speechAudio.onerror = null;
      speechAudio.pause();
      speechAudio.removeAttribute('src');
      speechAudio.load();
    }
    if (speechAudioUrl) URL.revokeObjectURL(speechAudioUrl);
    speechAudioUrl = '';
    voiceOrbSpeaking = false;
    if (speechCueAudio) {
      speechCueAudio.onended = null;
      speechCueAudio.onerror = null;
      speechCueAudio.pause();
      try { speechCueAudio.currentTime = 0; } catch (_) {}
    }
    stopStreamedSpeech();
    if (canBrowserSpeak) speechSynthesis.cancel();
    refreshVoiceOrb();
  }

  function playSpeechCue(sequence, onFinished) {
    const finish = () => {
      if (sequence === speechSequence && typeof onFinished === 'function') onFinished();
    };
    if (!speechCueAudio) {
      finish();
      return;
    }
    voiceOrbSpeaking = true;
    refreshVoiceOrb();
    speechCueAudio.onended = finish;
    speechCueAudio.onerror = finish;
    try { speechCueAudio.currentTime = 0; } catch (_) {}
    speechCueAudio.play().catch(finish);
  }

  function prefetchSpeech(text) {
    if (!speechAudio || !text || prefetchedSpeech.has(text)) return;
    const entry = {blob: null, failed: false};
    prefetchedSpeech.set(text, entry);
    fetch('/elmer-api/speech', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(speechPayload({text, lead_silence_ms: 350}))
    }).then(response => {
      if (!response.ok) throw new Error('Unable to preload speech.');
      return response.blob();
    }).then(blob => { entry.blob = blob; }).catch(() => { entry.failed = true; });
  }

  function playPrefetchedSpeech(text) {
    const entry = prefetchedSpeech.get(text);
    if (!speechAudio || !entry?.blob || !speak.classList.contains('active')) return false;
    prefetchedSpeech.delete(text);
    window.clearTimeout(speechStartTimer);
    speechSequence += 1;
    const sequence = speechSequence;
    stopSpokenPlayback();
    speechAudioUrl = URL.createObjectURL(entry.blob);
    speechAudio.src = speechAudioUrl;
    speechAudio.preload = 'auto';
    speechAudio.playsInline = true;
    speechAudio.onended = () => {
      if (speechAudioUrl) URL.revokeObjectURL(speechAudioUrl);
      speechAudioUrl = '';
      voiceOrbSpeaking = false;
      refreshVoiceOrb();
      scheduleWake();
    };
    playSpeechCue(sequence, () => {
      if (sequence !== speechSequence || !speak.classList.contains('active')) return;
      speechAudio.play().catch(() => {
        voiceOrbSpeaking = false;
        refreshVoiceOrb();
        scheduleWake();
      });
    });
    return true;
  }

  function speakPreparedResult(text) {
    const preparedText = `Okay. ${text}`;
    if (!playPrefetchedSpeech(preparedText)) speakResult(text);
  }

  async function streamGeneratedSpeech(text, sequence, finish) {
    const context = ensureSpeechContext();
    if (!context || !window.ReadableStream) throw new Error('Streaming audio is unavailable.');
    await context.resume();
    const controller = new AbortController();
    speechRequest = controller;
    const response = await fetch('/elmer-api/speech', {
      method: 'POST', credentials: 'same-origin', signal: controller.signal,
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(speechPayload({text: `Okay. ${text}`, stream: true}))
    });
    if (!response.ok || !response.body) throw new Error('Generated speech stream is unavailable.');
    const reader = response.body.getReader();
    let carry = new Uint8Array(0);
    let nextStart = context.currentTime + 0.04;
    let scheduledSamples = 0;
    let reservoir = [];
    let reservoirBytes = 0;
    const scheduleBytes = bytes => {
      if (!bytes.length) return;
      const samples = bytes.length / 2;
      const buffer = context.createBuffer(1, samples, 24000);
      const channel = buffer.getChannelData(0);
      const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
      for (let index = 0; index < samples; index += 1) channel[index] = view.getInt16(index * 2, true) / 32768;
      const source = context.createBufferSource();
      source.buffer = buffer;
      source.connect(context.destination);
      speechSources.add(source);
      source.onended = () => speechSources.delete(source);
      nextStart = Math.max(nextStart, context.currentTime + 0.025);
      source.start(nextStart);
      nextStart += samples / 24000;
      scheduledSamples += samples;
    };
    try {
      while (true) {
        const {value, done} = await reader.read();
        if (sequence !== speechSequence) { try { await reader.cancel(); } catch (_) {} return; }
        if (done) break;
        let bytes = value || new Uint8Array(0);
        if (carry.length) {
          const joined = new Uint8Array(carry.length + bytes.length);
          joined.set(carry); joined.set(bytes, carry.length); bytes = joined;
        }
        const usable = bytes.length - (bytes.length % 2);
        carry = usable < bytes.length ? bytes.slice(usable) : new Uint8Array(0);
        if (!usable) continue;
        const audio = bytes.slice(0, usable);
        if (!scheduledSamples) {
          reservoir.push(audio);
          reservoirBytes += audio.length;
          // Keep about 1.2 seconds in hand before starting. The first 350 ms
          // is silence, leaving enough voiced audio to ride out Safari/network jitter.
          if (reservoirBytes < 57600) continue;
          const combined = new Uint8Array(reservoirBytes);
          let offset = 0;
          reservoir.forEach(part => { combined.set(part, offset); offset += part.length; });
          reservoir = [];
          reservoirBytes = 0;
          scheduleBytes(combined);
        } else scheduleBytes(audio);
      }
      if (!scheduledSamples && reservoirBytes) {
        const combined = new Uint8Array(reservoirBytes);
        let offset = 0;
        reservoir.forEach(part => { combined.set(part, offset); offset += part.length; });
        scheduleBytes(combined);
      }
    } catch (error) {
      error.elmerSpeechStarted = scheduledSamples > 0;
      error.elmerSpeechRemainingMs = Math.max(0, Math.ceil((nextStart - context.currentTime) * 1000) + 30);
      throw error;
    }
    const remaining = Math.max(0, nextStart - context.currentTime);
    speechStreamEndTimer = window.setTimeout(finish, Math.ceil(remaining * 1000) + 30);
  }

  function browserSpeechFallback(text, sequence, finish) {
    if (!canBrowserSpeak || sequence !== speechSequence || !speak.classList.contains('active')) {
      finish();
      return;
    }
    const utterance = new SpeechSynthesisUtterance(`Okay. ${text}`);
    utterance.lang = localStorage.getItem('elmerVoiceLanguage') || navigator.language || 'en-US';
    utterance.onend = finish;
    utterance.onerror = finish;
    speechSynthesis.resume();
    speechSynthesis.speak(utterance);
  }

  function speakResult(text, onFinished = null, initialDelayMs = 0) {
    if (!canSpeak || !speak.classList.contains('active') || !text
        || commandRecordingStarting || commandRecorder || commandStream || transcriptionPending) return false;
    window.clearTimeout(speechStartTimer);
    speechStartTimer = 0;
    speechSequence += 1;
    const sequence = speechSequence;
    stopSpokenPlayback();
    stopRecognition();
    speechStartTimer = window.setTimeout(() => {
      speechStartTimer = 0;
      if (sequence !== speechSequence || !speak.classList.contains('active')) return;
      let finished = false;
      const finish = () => {
        if (finished || sequence !== speechSequence) return;
        finished = true;
        voiceOrbSpeaking = false;
        refreshVoiceOrb();
        speechRequest = null;
        if (speechAudioUrl) URL.revokeObjectURL(speechAudioUrl);
        speechAudioUrl = '';
        if (typeof onFinished === 'function') onFinished();
        scheduleWake();
      };
      if (!speechAudio) {
        browserSpeechFallback(text, sequence, finish);
        return;
      }
      const controller = new AbortController();
      speechRequest = controller;
      fetch('/elmer-api/speech', {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(speechPayload({text: `Okay. ${text}`, lead_silence_ms: 350}))
      }).then(response => {
        if (!response.ok) throw new Error('Generated speech is unavailable.');
        return response.blob();
      }).then(blob => {
        if (sequence !== speechSequence || !speak.classList.contains('active')) return;
        speechAudioUrl = URL.createObjectURL(blob);
        speechAudio.src = speechAudioUrl;
        speechAudio.preload = 'auto';
        speechAudio.playsInline = true;
        speechAudio.onended = finish;
        speechAudio.onerror = () => browserSpeechFallback(text, sequence, finish);
        playSpeechCue(sequence, () => {
          if (sequence !== speechSequence || !speak.classList.contains('active')) return;
          speechAudio.play().catch(() => browserSpeechFallback(text, sequence, finish));
        });
      }).catch(error => {
        if (error?.name !== 'AbortError' && sequence === speechSequence) {
          playSpeechCue(sequence, () => browserSpeechFallback(text, sequence, finish));
        }
      });
    }, Math.max(60, Number(initialDelayMs) || 0));
    return true;
  }

  function settleOperatorConfirmation(approved, reason = '') {
    const pending = pendingVoiceConfirmation;
    if (!pending) return false;
    pendingVoiceConfirmation = null;
    window.clearTimeout(pending.timer);
    discardPrimedCommandMicrophone();
    result.querySelectorAll('[data-elmer-confirmation]').forEach(button => { button.disabled = true; });
    if (reason === 'expired') setStatus('Confirmation expired. Nothing was changed.');
    else {
      setStatus(approved ? 'Approval received. Carrying out the action…' : 'Action cancelled. Nothing was changed.');
      if (!approved) {
        lastCancellationAcknowledgement = Date.now();
        if (!playPrefetchedSpeech(cancellationAcknowledgement)) speakResult('Cancelled. Nothing was changed.');
      }
    }
    pending.resolve(Boolean(approved));
    return true;
  }

  function handleVoiceConfirmation(text) {
    if (!pendingVoiceConfirmation) return false;
    const answer = String(text || '').trim().toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '');
    if (/^(?:approve|approved|confirm|confirmed|yes|do it|go ahead|proceed|move|aprobar|apruebo|aprobado|confirmar|confirmado|si|see|c|t|si por favor|adelante|hazlo|procede|godkann|godkant|ja|kor|fortsatt)$/.test(answer)) {
      settleOperatorConfirmation(true, 'voice');
    } else if (/^(?:cancel|cancelled|canceled|no|stop|reject|denied?|do not|don't|cancelar|cancelado|detener|detente|avbryt|avbruten|nej|stopp)$/.test(answer)) {
      settleOperatorConfirmation(false, 'voice');
    } else {
      setStatus(`Heard “${text}.” Please say only Approve or Cancel.`);
      speakResult('Please say approve or cancel.');
    }
    return true;
  }

  function requestOperatorConfirmation(prompt, expectedSuccessSpeech = '') {
    if (pendingVoiceConfirmation) settleOperatorConfirmation(false, 'replaced');
    const displayPrompt = trimFrequencyZeros(String(prompt || 'Allow this station action?').replace(/\s+/g, ' ').trim());
    const spokenPrompt = commandForSpeech(displayPrompt);
    showResult(`<strong>Confirm station action.</strong><br>${escapeHtml(displayPrompt)}<div class="elmer-shared-approval-actions"><button type="button" data-elmer-confirmation="approve">Approve</button><button type="button" data-elmer-confirmation="cancel">Cancel</button></div><small>Or press Elmer PTT and say “Approve” or “Cancel.”</small>`, 'warning');
    setStatus(speak.classList.contains('active') ? 'Reading the proposed action…' : 'Waiting for approval · expires in 20 seconds');
    let pending;
    const promise = new Promise(resolve => {
      pending = {resolve, timer: 0};
      pendingVoiceConfirmation = pending;
    });
    const approveButton = result.querySelector('[data-elmer-confirmation="approve"]');
    approveButton?.addEventListener('click', () => settleOperatorConfirmation(true, 'button'));
    result.querySelector('[data-elmer-confirmation="cancel"]')?.addEventListener('click', () => settleOperatorConfirmation(false, 'button'));
    approveButton?.focus({preventScroll: true});
    const startApprovalTimer = () => {
      if (pendingVoiceConfirmation !== pending || pending.timer) return;
      primeCommandMicrophone();
      setStatus('Waiting for approval · expires in 20 seconds');
      pending.timer = window.setTimeout(() => settleOperatorConfirmation(false, 'expired'), 20000);
    };
    // A proposal follows microphone transcription immediately. Give Safari's
    // audio route time to switch fully back to playback, as it naturally does
    // while an approved action is being executed and verified.
    if (!speakResult(`${spokenPrompt}. Press Elmer PTT and say approve or cancel.`, startApprovalTimer)) {
      startApprovalTimer();
    }
    window.setTimeout(() => {
      if (pendingVoiceConfirmation !== pending || !speak.classList.contains('active')) return;
      prefetchSpeech(cancellationAcknowledgement);
      if (expectedSuccessSpeech) prefetchSpeech(`Okay. ${expectedSuccessSpeech}`);
    }, 700);
    return promise;
  }

  function frequencyForSpeech(frequencyHz) {
    const fixed = (Number(frequencyHz) / 1000000).toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
    const parts = fixed.split('.');
    return parts.length === 1 ? parts[0] : `${parts[0]} point ${parts[1].split('').join(' ')}`;
  }

  function frequencyForDisplay(frequencyHz) {
    return (Number(frequencyHz) / 1000000).toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
  }

  function trimFrequencyZeros(text) {
    return String(text || '').replace(/\b([0-9]+)\.([0-9]+)(?=\s*MHz\b)/gi, (_match, whole, fraction) => {
      const significant = fraction.replace(/0+$/, '');
      return significant ? `${whole}.${significant}` : whole;
    });
  }

  function commandForSpeech(text) {
    return String(text || '')
      .replace(/\b([0-9]+)\.([0-9]+)\b/g, (_match, whole, fraction) => {
        const significant = fraction.replace(/0+$/, '');
        return significant ? `${whole} point ${significant.split('').join(' ')}` : whole;
      })
      .replace(/\bMHz\b/gi, 'megahertz')
      .replace(/\bkHz\b/gi, 'kilohertz')
      .replace(/\bHz\b/gi, 'hertz')
      .replace(/\bFT\s*-?\s*8\b/gi, 'F T eight')
      .replace(/\bFT\s*-?\s*4\b/gi, 'F T four')
      .replace(/\s+/g, ' ')
      .trim();
  }

  // Keep the full SDR toolbar clear of the Elmer Control drawer.
  function syncSdrToolbar() {
    const frame = document.getElementById('elmerSdrFrame');
    if (!frame || !frame.contentWindow) return;
    const rect = frame.getBoundingClientRect();
    const drawer = panel.getBoundingClientRect();
    const overlaps = drawer.top < rect.top + 45 && drawer.bottom > rect.top;
    const inset = !panel.classList.contains('collapsed') && overlaps
      ? Math.max(0, rect.right - (window.innerWidth - panel.offsetWidth)) : 0;
    let origin;
    try { origin = new URL(frame.src, location.href).origin; } catch (_) { return; }
    frame.contentWindow.postMessage({type:'rigpiElmerToolbarInset', inset:inset}, origin);
  }
  window.addEventListener('message', (event) => {
    const frame = document.getElementById('elmerSdrFrame');
    if (frame && event.source === frame.contentWindow && event.data?.type === 'rigpiElmerToolbarReady') syncSdrToolbar();
  });
  window.addEventListener('resize', () => requestAnimationFrame(syncSdrToolbar));
  function setCollapsed(collapsed) {
    panel.classList.toggle('collapsed', collapsed);
    document.body.classList.toggle('elmer-control-open', !collapsed);
    requestAnimationFrame(syncSdrToolbar);
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.title = collapsed ? 'Open Elmer Control' : 'Close Elmer Control';
    toggle.setAttribute('aria-label', toggle.title);
    toggle.innerHTML = collapsed ? '<i class="fas fa-chevron-left"></i>' : '<i class="fas fa-chevron-right"></i>';
    localStorage.setItem('elmerControlCollapsed', collapsed ? '1' : '0');
  }

  function positionPanel() {
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    // A landscape phone has room beside the SDR. Keep the Elmer drawer there
    // instead of moving it below the SDR and out of the short viewport.
    const landscapeSideDrawer = viewportWidth > viewportHeight && viewportWidth >= 640;
    const nav = document.querySelector('nav.navbar');
    const headerBottom = nav ? Math.max(0, nav.getBoundingClientRect().bottom) : 0;
    let top = Math.max(0, headerBottom);
    let bottom = 0;
    const footer = document.querySelector('.footer');
    if (footer && getComputedStyle(footer).display !== 'none') {
      const footerRect = footer.getBoundingClientRect();
      if (footerRect.bottom > 0 && footerRect.top < viewportHeight) {
        bottom = Math.max(0, viewportHeight - Math.max(0, footerRect.top));
      }
    }
    const sdr = document.querySelector('#sdrPanel');
    if (sdr && getComputedStyle(sdr).display !== 'none') {
      const rect = sdr.getBoundingClientRect();
      const visibleTop = Math.max(top, rect.top);
      const visibleBottom = Math.min(viewportHeight, rect.bottom);
      if (visibleBottom > visibleTop) {
        if (rect.top <= top + 12) top = Math.max(top, visibleBottom);
        else bottom = Math.max(bottom, viewportHeight - visibleTop);
      }
    }
    // Preserve a usable drawer if the SDR occupies nearly the whole viewport.
    if (viewportHeight - top - bottom < 180) {
      if (bottom > 0) bottom = Math.max(0, viewportHeight - top - 180);
      else top = Math.max(headerBottom, viewportHeight - 180);
    }
    panel.style.setProperty('--elmer-control-top', `${Math.round(top)}px`);
    panel.style.setProperty('--elmer-control-bottom', `${Math.round(bottom)}px`);
  }

  toggle.addEventListener('click', () => setCollapsed(!panel.classList.contains('collapsed')));
  setCollapsed(localStorage.getItem('elmerControlCollapsed') === '1');
  positionPanel();
  window.addEventListener('resize', positionPanel, {passive: true});
  window.addEventListener('scroll', positionPanel, {passive: true});
  let footerResizeObserver = null;
  function connectFooterObserver() {
    positionPanel();
    if ('ResizeObserver' in window && !footerResizeObserver) {
      const footer = document.querySelector('.footer');
      if (footer) {
        footerResizeObserver = new ResizeObserver(positionPanel);
        footerResizeObserver.observe(footer);
      }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', connectFooterObserver, {once: true});
  } else {
    connectFooterObserver();
  }
  window.addEventListener('load', connectFooterObserver, {once: true});

  async function queryPlan(text) {
    const response = await fetch('/elmer-api/plan', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'}, body: JSON.stringify({question: text})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Elmer could not interpret that command.');
    return data;
  }

  function parsePercentValue(value) {
    const clean = String(value || '').trim().toLowerCase().replace(/-/g, ' ');
    if (/^[0-9]+(?:\.[0-9]+)?$/.test(clean)) return Math.round(Number(clean));
    const small = {zero: 0, one: 1, two: 2, three: 3, four: 4, five: 5,
      six: 6, seven: 7, eight: 8, nine: 9, ten: 10, eleven: 11,
      twelve: 12, thirteen: 13, fourteen: 14, fifteen: 15, sixteen: 16,
      seventeen: 17, eighteen: 18, nineteen: 19};
    const tens = {twenty: 20, thirty: 30, forty: 40, fifty: 50,
      sixty: 60, seventy: 70, eighty: 80, ninety: 90};
    if (Object.hasOwn(small, clean)) return small[clean];
    if (clean === 'one hundred' || clean === 'a hundred' || clean === 'hundred') return 100;
    const words = clean.split(/\s+/);
    if (words.length === 1 && Object.hasOwn(tens, words[0])) return tens[words[0]];
    if (words.length === 2 && Object.hasOwn(tens, words[0])
        && Object.hasOwn(small, words[1]) && small[words[1]] < 10) {
      return tens[words[0]] + small[words[1]];
    }
    return NaN;
  }

  function deterministicControlPlan(text) {
    const clean = String(text || '').trim().replace(/[.?!]+$/, '')
      .replace(/([0-9])\s*%/g, '$1 %')
      .replace(/\bF\s*[.-]?\s*T\s*[.-]?\s*([48])\b/ig, 'FT$1')
      .replace(/\b(one\s+hundred(?:\s+and)?\s+sixty|eighty|sixty|forty|thirty|twenty|seventeen|fifteen|twelve|ten|six|two)\s+(?=m(?:eters?|etres?)?\b)/ig,
        word => ({'one hundred sixty': '160', 'one hundred and sixty': '160', eighty: '80', sixty: '60', forty: '40', thirty: '30', twenty: '20', seventeen: '17', fifteen: '15', twelve: '12', ten: '10', six: '6', two: '2'}[word.toLowerCase().replace(/\s+/g, ' ').trim()] || word))
      .replace(/\b(change|set)(\s+the)?\s+mood\s+to\b/ig, '$1$2 mode to');
    const bandFirstActivityTune = clean.match(/^(?:(?:please\s+)?(?:tune|set|switch|select|go)(?:\s+(?:radio|receiver)\s*([1-4]))?(?:\s+to)?\s+|to\s+)?(?:the\s+)?(160|80|60|40|30|20|17|15|12|10|6|2)\s*(?:m|meters?|metres?)(?:\s+band)?\s*(ft\s*-?\s*8|ft\s*-?\s*4)$/i);
    if (bandFirstActivityTune) {
      return {rig_control: {
        enabled: true, action: 'receiver.activity_tune', radio: Number(bandFirstActivityTune[1]) || 0,
        activity_query: bandFirstActivityTune[3].replace(/[\s-]/g, '').toUpperCase(),
        band_query: `${bandFirstActivityTune[2]}m`, frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    const activityTune = clean.match(/^tune(?:\s+(?:radio|receiver)\s*([1-4]))?\s+to\s+(ft\s*-?\s*8|ft\s*-?\s*4)\s+(?:on|for|in)\s+(?:the\s+)?(160|80|60|40|30|20|17|15|12|10|6|2)\s*(?:m|meters?|metres?)?(?:\s+band)?$/i);
    if (activityTune) {
      return {rig_control: {
        enabled: true, action: 'receiver.activity_tune', radio: Number(activityTune[1]) || 0,
        activity_query: activityTune[2].replace(/[\s-]/g, '').toUpperCase(),
        band_query: `${activityTune[3]}m`, frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    if (/^swap\s+(?:the\s+)?(?:vfo'?s|vfo\s*a\s+(?:and|with)\s+(?:vfo\s*)?b)$/i.test(clean)) {
      return {rig_control: {enabled: true, action: 'receiver.vfo_swap', radio: 0}};
    }
    if (/^copy\s+(?:vfo\s*)?a\s+to\s+(?:vfo\s*)?b$/i.test(clean)) {
      return {rig_control: {enabled: true, action: 'receiver.vfo_a_to_b', radio: 0}};
    }
    if (/^copy\s+(?:vfo\s*)?a\s+to\s+(?:the\s+)?memory$/i.test(clean)) {
      return {rig_control: {enabled: true, action: 'receiver.vfo_a_to_memory', radio: 0}};
    }
    if (/^copy\s+(?:the\s+)?memory\s+to\s+(?:vfo\s*)?a$/i.test(clean)) {
      return {rig_control: {enabled: true, action: 'receiver.memory_to_vfo_a', radio: 0}};
    }
    const relativeTune = clean.match(/^(?:tune|move|step)(?:\s+(?:the\s+)?(?:main(?:\s+vfo)?|vfo|radio|receiver))?\s+(up|down)\s+([0-9]+(?:\.[0-9]+)?)\s*(mhz|megahertz|khz|kilohertz|hz|hertz)?(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (relativeTune) {
      const currentFrequency = Number(String(window.tMain || '').replace(/\D/g, ''));
      let offset = Number(relativeTune[2]);
      const unit = String(relativeTune[3] || 'khz').toLowerCase();
      offset *= unit.startsWith('m') ? 1000000 : unit.startsWith('h') ? 1 : 1000;
      if (relativeTune[1].toLowerCase() === 'down') offset *= -1;
      offset = Math.round(offset);
      const targetFrequency = currentFrequency + offset;
      if (Number.isFinite(currentFrequency) && currentFrequency >= 1000
          && offset !== 0 && Math.abs(offset) <= 10000000
          && targetFrequency >= 1000 && targetFrequency <= 6000000000) {
        return {rig_control: {
          enabled: true, action: 'receiver.tune', radio: Number(relativeTune[4]) || 0,
          frequency_hz: targetFrequency, frequency_offset_hz: offset,
          mode: '', bandwidth_hz: 0
        }};
      }
    }
    const directTune = clean.match(/^(?:tune|set|change)(?:\s+(?:radio|receiver)\s*([1-4]))?(?:\s+(?:the\s+)?(?:frequency|freq))?\s+(?:to|at)\s+([0-9]+(?:\.[0-9]+)?)\s*(mhz|megahertz|khz|kilohertz|hz|hertz)?(?:\s+(?:in|on|using|to)?\s*(USB|LSB|AM|FM|CW|CWR|PKTUSB|DATA\s*USB|FT\s*-?\s*8|FT\s*-?\s*4))?$/i);
    if (directTune) {
      const writtenNumber = String(directTune[2]);
      const unit = String(directTune[3] || '').toLowerCase();
      // A spoken decimal such as 14.074 is conventionally MHz.  Leave a
      // unitless integer for the planner because 7074 could mean kHz or Hz.
      if (unit || writtenNumber.includes('.')) {
        let frequency = Number(writtenNumber);
        frequency *= unit.startsWith('k') ? 1000 : unit.startsWith('h') ? 1 : 1000000;
        frequency = Math.round(frequency);
        let mode = String(directTune[4] || '').toUpperCase().replace(/[\s-]/g, '');
        if (mode === 'FT8' || mode === 'FT4' || mode === 'DATAUSB') mode = 'PKTUSB';
        if (frequency >= 1000 && frequency <= 6000000000) {
          return {rig_control: {
            enabled: true, action: 'receiver.tune', radio: Number(directTune[1]) || 0,
            frequency_hz: frequency, mode, bandwidth_hz: 0
          }};
        }
      }
    }
    const radioConnection = clean.match(/^(?:(?:please|would\s+you|can\s+you)\s+)?(connect|disconnect)\s+(?:the\s+)?(?:radio|rig)(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (radioConnection) {
      return {rig_control: {
        enabled: true, action: radioConnection[1].toLowerCase() === 'connect' ? 'station.connect' : 'station.disconnect',
        radio: Number(radioConnection[2]) || 0, frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    const radioPower = clean.match(/^(?:(?:please|would\s+you|can\s+you)\s+)?(?:(?:turn|switch|power)\s+(?:the\s+)?(?:radio|rig)\s+(on|off)|(?:turn|switch)\s+(on|off)\s+(?:the\s+)?(?:radio|rig)|(?:radio|rig)\s+power\s+(on|off))(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (radioPower) {
      const state = String(radioPower[1] || radioPower[2] || radioPower[3] || '').toLowerCase();
      return {rig_control: {
        enabled: true, action: state === 'on' ? 'station.power_on' : 'station.power_off',
        radio: Number(radioPower[4]) || 0, frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    const logContact = clean.match(/^(?:(?:please|would\s+you|can\s+you)\s+)?(?:log(?:\s+(?:this|the|a))?(?:\s+(?:contact|qso))?|record(?:\s+(?:this|the|a))?\s+(?:contact|qso))(?:\s+with)?(?:\s+([a-z0-9/]{3,15}))?$/i);
    if (logContact) {
      return {rig_control: {
        enabled: true, action: 'logbook.prepare_contact', radio: 0,
        callsign: String(logContact[1] || '').toUpperCase(),
        frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    const splitStatus = clean.match(/^(?:(?:show|read|check|display|what(?:'s|\s+is))\s+)?(?:the\s+)?split(?:\s+(?:status|frequency|freq))?(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (splitStatus) {
      return {rig_control: {
        enabled: true, action: 'receiver.split_status', radio: Number(splitStatus[1]) || 0,
        frequency_hz: 0, mode: '', bandwidth_hz: 0,
        split_frequency_hz: 0, split_offset_hz: 0
      }};
    }
    const splitOff = clean.match(/^(?:(?:turn|set|switch)\s+)?split\s+(?:off|disable|disabled)(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (splitOff) {
      return {rig_control: {
        enabled: true, action: 'receiver.split_off', radio: Number(splitOff[1]) || 0,
        frequency_hz: 0, mode: '', bandwidth_hz: 0,
        split_frequency_hz: 0, split_offset_hz: 0
      }};
    }
    const splitAbsolute = clean.match(/^(?:set|enable|turn\s+on)?\s*split(?:\s+(?:tx|transmit))?(?:\s+(?:frequency|freq))?\s+(?:to|at)\s+([0-9]+(?:\.[0-9]+)?)\s*(mhz|megahertz|khz|kilohertz|hz|hertz)(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (splitAbsolute) {
      let frequency = Number(splitAbsolute[1]);
      const unit = splitAbsolute[2].toLowerCase();
      frequency *= unit.startsWith('m') ? 1000000 : unit.startsWith('k') ? 1000 : 1;
      frequency = Math.round(frequency);
      if (frequency >= 1000 && frequency <= 6000000000) {
        return {rig_control: {
          enabled: true, action: 'receiver.split_set', radio: Number(splitAbsolute[3]) || 0,
          frequency_hz: 0, mode: '', bandwidth_hz: 0,
          split_frequency_hz: frequency, split_offset_hz: 0
        }};
      }
    }
    const splitOffset = clean.match(/^(?:set|enable|turn\s+on)?\s*split\s+(?:(up|down)\s*|([+-])\s*)([0-9]+(?:\.[0-9]+)?)\s*(mhz|megahertz|khz|kilohertz|hz|hertz)?(?:\s+(?:on|for)\s+(?:radio|receiver)\s*([1-4]))?$/i);
    if (splitOffset) {
      let offset = Number(splitOffset[3]);
      const unit = String(splitOffset[4] || 'khz').toLowerCase();
      offset *= unit.startsWith('m') ? 1000000 : unit.startsWith('h') ? 1 : 1000;
      if (String(splitOffset[1] || '').toLowerCase() === 'down' || splitOffset[2] === '-') offset *= -1;
      offset = Math.round(offset);
      if (offset !== 0 && Math.abs(offset) <= 10000000) {
        return {rig_control: {
          enabled: true, action: 'receiver.split_set', radio: Number(splitOffset[5]) || 0,
          frequency_hz: 0, mode: '', bandwidth_hz: 0,
          split_frequency_hz: 0, split_offset_hz: offset
        }};
      }
    }
    const bandModeMatch = clean.match(
      /^(?:tune|set|change|switch|select|go)(?:\s+(?:radio|receiver)\s*([1-4]))?(?:\s+to)?\s+(?:the\s+)?(160|80|60|40|30|20|17|15|12|10|6|2)\s*(?:m|meters?|metres?)(?:\s+band)?(?:\s+(?:to|in|on|for|using|at))?\s*(USB|LSB|SSB|CW|CWR|FT\s*-?\s*8|FT\s*-?\s*4)\s*$/i
    );
    if (bandModeMatch) {
      // These are the same common-activity defaults shown by the SDR smart
      // band bar.  They are operating starting points, not band-plan limits.
      const profiles = {
        160: {voice: 1900000, sideband: 'LSB', cw: 1825000, ft8: 1840000},
        80:  {voice: 3800000, sideband: 'LSB', cw: 3525000, ft8: 3573000},
        60:  {voice: 5332500, sideband: 'USB', ft8: 5357000},
        40:  {voice: 7200000, sideband: 'LSB', cw: 7025000, ft8: 7074000, ft4: 7047500},
        30:  {cw: 10110000, ft8: 10136000, ft4: 10140000},
        20:  {voice: 14250000, sideband: 'USB', cw: 14025000, ft8: 14074000, ft4: 14080000},
        17:  {voice: 18130000, sideband: 'USB', cw: 18080000, ft8: 18100000, ft4: 18104000},
        15:  {voice: 21300000, sideband: 'USB', cw: 21025000, ft8: 21074000, ft4: 21140000},
        12:  {voice: 24950000, sideband: 'USB', cw: 24900000, ft8: 24915000, ft4: 24919000},
        10:  {voice: 28400000, sideband: 'USB', cw: 28025000, ft8: 28074000, ft4: 28180000},
        6:   {voice: 50125000, sideband: 'USB', cw: 50090000, ft8: 50313000, ft4: 50318000},
        2:   {voice: 144200000, sideband: 'USB', cw: 144100000, ft8: 144174000}
      };
      const radio = Number(bandModeMatch[1]) || 0;
      const profile = profiles[Number(bandModeMatch[2])];
      const requested = bandModeMatch[3].toUpperCase().replace(/[\s-]/g, '');
      const kind = requested === 'FT8' ? 'ft8' : requested === 'FT4' ? 'ft4'
        : requested === 'CW' || requested === 'CWR' ? 'cw' : 'voice';
      const frequency = Number(profile?.[kind]) || 0;
      if (frequency) {
        const mode = kind === 'ft8' || kind === 'ft4' ? 'PKTUSB'
          : requested === 'SSB' ? profile.sideband : requested;
        const bandwidth = kind === 'cw' ? 500 : kind === 'voice' ? 2700 : 3000;
        return {rig_control: {
          enabled: true, action: 'receiver.tune', radio,
          frequency_hz: frequency, mode, bandwidth_hz: bandwidth
        }};
      }
    }
    if (/^(?:stop|cancel)(?:\s+the)?(?:\s+(?:shortwave|sw|broadcast))?\s+scan$/i.test(clean)) {
      return {rig_control: {
        enabled: true, action: 'sdr.scan_stop', radio: 0,
        frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    if (/^(?:resume|continue)(?:\s+the)?(?:\s+(?:shortwave|sw|broadcast))?\s+scan$/i.test(clean)) {
      return {rig_control: {
        enabled: true, action: 'sdr.scan_resume', radio: 0,
        frequency_hz: 0, mode: 'AM', bandwidth_hz: 6000
      }};
    }
    const languageBroadcastMatch = clean.match(
      /^(?:find|scan|search)(?:\s+(?:for|the))?\s+(?:active\s+)?(?:frequencies\s+for\s+)?(?:(?:shortwave|sw)\s+)?(?:broadcast(?:s|\s+stations)?\s+in|broadcasting\s+in)\s+([a-z][a-z -]{1,30}?)(?:\s+and\s+(?:tune|select)(?:\s+the)?\s+strongest(?:\s+(?:one|signal))?)?$/i
    );
    if (languageBroadcastMatch) {
      const language = String(languageBroadcastMatch[1] || '').trim();
      return {rig_control: {
        enabled: true, action: 'sdr.station_lookup', radio: 0,
        frequency_hz: 0, mode: 'AM', bandwidth_hz: 6000,
        station_query: language
      }};
    }
    const scanStationMatch = clean.match(
      /^(?:scan|search)(?:\s+for)?\s+(.{2,60}?)\s+(?:frequencies|frequency|freqs?)$/i
    );
    const stationFrequencyMatch = clean.match(
      /^(?:what(?:'s|\s+is)\s+|find\s+|show(?:\s+me)?\s+)?(.{2,60}?)\s+(?:frequency|freq)$/i
    );
    const tuneStationMatch = clean.match(
      /^(?:tune|go)(?:\s+me)?(?:\s+back)?\s+to\s+([a-z][a-z0-9 .&'’-]{1,59})$/i
    );
    const stationQuery = String(scanStationMatch?.[1] || stationFrequencyMatch?.[1] || tuneStationMatch?.[1] || '').trim();
    if (stationQuery
        && !/^(?:the\s+)?(?:radio\s+)?(?:frequency|freq)$/i.test(stationQuery)
        && !/\b(?:mhz|khz|hz|megahertz|kilohertz|hertz)\b/i.test(stationQuery)) {
      return {rig_control: {
        enabled: true, action: 'sdr.station_lookup', radio: 0,
        frequency_hz: 0, mode: 'AM', bandwidth_hz: 6000,
        station_query: stationQuery
      }};
    }
    if (/^(?:scan|search)(?:\s+(?:for|the))?\s+(?:(?:shortwave|sw)\s+)?broadcast(?:s|\s+stations)?(?:\s+and\s+stop(?:\s+(?:on|when))?(?:\s+(?:a\s+)?signal(?:\s+is\s+detected)?)?)?$/i.test(clean)) {
      return {rig_control: {
        enabled: true, action: 'sdr.scan_shortwave', radio: 0,
        frequency_hz: 0, mode: 'AM', bandwidth_hz: 6000
      }};
    }
    const macroMatch = clean.match(
      /^(?:run|execute|use|send)(?:\s+the)?\s+macro\s+(.+)$/i
    );
    if (macroMatch) {
      const parts = macroMatch[1].split(
        /\s*(?:,\s*|\s+)(?:and\s+)?then\s+(?:the\s+)?macro\s+/i
      );
      if (parts.length < 1 || parts.length > 4) return null;
      const sequence = [];
      for (const part of parts) {
        const item = part.trim().match(/^(.+?)(?:\s+(?:from|in)\s+(?:macro\s+)?bank\s+([1-4]))?\s*$/i);
        if (!item || !item[1].trim()) return null;
        sequence.push({selector: item[1].trim(), bank: item[2] ? Number(item[2]) : 0});
      }
      return {rig_control: {
        enabled: true, action: 'macro.run', radio: 0,
        frequency_hz: 0, mode: '', bandwidth_hz: 0,
        macro_selector: sequence[0].selector,
        macro_bank: sequence[0].bank,
        macro_sequence: sequence,
        macro_context: document.body.id === 'keyer' ? 'keyer' : 'tuner'
      }};
    }
    const cwSpeedMatch = clean.match(
      /^(?:change|set)(?:\s+the)?\s+(?:cw|keyer|morse)(?:\s+keyer)?\s+speed(?:\s+to)?\s+([0-9]+)\s*(?:wpm|words?\s+per\s+minute)?\s*$/i
    );
    if (cwSpeedMatch) {
      const speed = Number(cwSpeedMatch[1]);
      if (Number.isInteger(speed) && speed >= 5 && speed <= 60) {
        return {rig_control: {
          enabled: true, action: 'keyer.speed', radio: 0,
          frequency_hz: 0, mode: '', bandwidth_hz: 0,
          cw_speed_wpm: speed
        }};
      }
    }
    const cwTextMatch = clean.match(
      /^(?:put|place|stage|load)(?:\s+the\s+text)?\s+(.+?)\s+(?:in|into)\s+(?:the\s+)?(?:cw|morse)(?:\s+outgoing)?\s+buffer\s*$/i
    );
    if (cwTextMatch) {
      const cwText = cwTextMatch[1].trim();
      if (cwText && cwText.length <= 240) {
        return {rig_control: {
          enabled: true, action: 'keyer.stage_text', radio: 0,
          frequency_hz: 0, mode: '', bandwidth_hz: 0,
          cw_text: cwText
        }};
      }
    }
    if (/^(?:(?:set|adjust|calibrate)(?:\s+the)?\s+rf\s+gain\s+(?:to\s+)?(?:the\s+)?noise\s+floor|set(?:\s+the)?\s+rf\s+gain\s+(?:so|until)\s+(?:the\s+)?noise\s+(?:just\s+)?moves?\s+(?:the\s+)?s[ -]?meter)$/i.test(clean)) {
      return {rig_control: {
        enabled: true, action: 'receiver.auto_rf_noise', radio: 0,
        frequency_hz: 0, mode: '', bandwidth_hz: 0
      }};
    }
    const compoundMode = clean.match(
      /\bmode\s+(?:to\s+)?(AM|AMS|CW|CWR|DSB|FAX|FM|LSB|PKTFM|PKTLSB|PKTUSB|RTTY|RTTYR|SAM|USB|WFM)\b/i
    );
    const compoundBandwidth = clean.match(
      /\b(?:bw|band\s*width|pass\s*band(?:\s*width)?)\s*(?:to\s+)?([0-9]+(?:\.[0-9]+)?)\s*(hz|hertz|khz|kilohertz)?\b/i
    );
    if (compoundMode && compoundBandwidth && /^(?:change|set)\b/i.test(clean)) {
      let bandwidth = Number(compoundBandwidth[1]);
      if (/^(?:khz|kilohertz)$/i.test(compoundBandwidth[2] || '')) bandwidth *= 1000;
      bandwidth = Math.round(bandwidth);
      if (Number.isFinite(bandwidth) && bandwidth >= 50 && bandwidth <= 1000000) {
        return {rig_control: {
          enabled: true, action: 'receiver.tune', radio: 0,
          frequency_hz: 0, mode: compoundMode[1].toUpperCase(), bandwidth_hz: bandwidth
        }};
      }
    }
    const match = clean.match(
      /^(?:change|set)(?:\s+the)?\s+mode\s+to\s+(AM|AMS|CW|CWR|DSB|FAX|FM|LSB|PKTFM|PKTLSB|PKTUSB|RTTY|RTTYR|SAM|USB|WFM)\s*$/i
    );
    if (match) {
      return {rig_control: {
        enabled: true, action: 'receiver.tune', radio: 0,
        frequency_hz: 0, mode: match[1].toUpperCase(), bandwidth_hz: 0
      }};
    }
    const micMuteMatch = clean.match(/^(?:mute|turn off)(?:\s+the)?\s+(?:mic|microphone|tx audio|transmit audio)\s*$/i);
    const micLevelMatch = clean.match(
      /^(?:change|set)(?:\s+the)?\s+(?:mic|microphone|mic(?:rophone)?\s+level|tx audio(?:\s+level)?|transmit audio(?:\s+level)?)\s+(?:to\s+)?(.+?)\s*(?:%|percent)?\s*$/i
    );
    if (micMuteMatch || micLevelMatch) {
      const percentText = micMuteMatch ? '0' : micLevelMatch[1].replace(/\s*(?:%|percent)\s*$/i, '');
      const percent = parsePercentValue(percentText);
      if (Number.isFinite(percent) && percent >= 0 && percent <= 100) {
        return {rig_control: {
          enabled: true, action: 'station.mic_level', radio: 0,
          frequency_hz: 0, mode: '', bandwidth_hz: 0,
          level_percent: percent
        }};
      }
    }
    const levelMatch = clean.match(
      /^(?:change|set)(?:\s+the)?\s+(volume|audio(?:\s+gain)?|af(?:\s+gain)?|rf(?:\s+gain)?|squelch|sql)\s+(?:to\s+)?(.+?)\s*(?:%|percent)?\s*$/i
    );
    if (levelMatch) {
      const spokenName = levelMatch[1].toLowerCase().replace(/\s+/g, ' ');
      const levelName = /^(?:volume|audio|audio gain|af|af gain)$/.test(spokenName)
        ? 'AF' : /^(?:rf|rf gain)$/.test(spokenName) ? 'RF' : 'SQL';
      const percentText = levelMatch[2].replace(/\s*(?:%|percent)\s*$/i, '');
      const percent = parsePercentValue(percentText);
      if (Number.isFinite(percent) && percent >= 0 && percent <= 100) {
        return {rig_control: {
          enabled: true, action: 'receiver.level', radio: 0,
          frequency_hz: 0, mode: '', bandwidth_hz: 0,
          level_name: levelName, level_percent: percent
        }};
      }
    }
    const bandwidthMatch = clean.match(
      /^(?:change|set)?\s*(?:radio\s+([1-4])\s+)?(?:the\s+)?(?:bw|band\s*width|pass\s*band(?:\s*width)?)(?:\s+to)?\s+([0-9]+(?:\.[0-9]+)?)\s*(hz|hertz|khz|kilohertz)?\s*$/i
    );
    if (!bandwidthMatch) return null;
    const radio = Number(bandwidthMatch[1]) || 0;
    let bandwidth = Number(bandwidthMatch[2]);
    if (/^(?:khz|kilohertz)$/i.test(bandwidthMatch[3] || '')) bandwidth *= 1000;
    bandwidth = Math.round(bandwidth);
    if (!Number.isFinite(bandwidth) || bandwidth < 50 || bandwidth > 1000000) return null;
    return {rig_control: {
      enabled: true, action: 'receiver.tune', radio,
      frequency_hz: 0, mode: '', bandwidth_hz: bandwidth
    }};
  }

  function requestEmbeddedSdr(payload, timeoutMs = 2500) {
    const frame = document.getElementById('sdrIframe');
    if (!frame || !frame.contentWindow || !String(frame.getAttribute('src') || '').trim()) {
      return Promise.resolve(null);
    }
    const requestId = `elmer-sdr-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    let targetOrigin = location.origin;
    try { targetOrigin = new URL(frame.src || location.href, location.href).origin; } catch (_) {}
    return new Promise((resolve, reject) => {
      const timer = window.setTimeout(() => {
        window.removeEventListener('message', receive);
        reject(new Error('The SDR did not respond to Elmer Control.'));
      }, timeoutMs);
      function receive(event) {
        if (event.source !== frame.contentWindow || !event.data ||
            event.data.type !== 'rigpi-elmer-sdr-result' ||
            event.data.request_id !== requestId) return;
        window.clearTimeout(timer);
        window.removeEventListener('message', receive);
        resolve(event.data);
      }
      window.addEventListener('message', receive);
      frame.contentWindow.postMessage({
        type: 'rigpi-elmer-sdr-control', request_id: requestId, ...payload
      }, targetOrigin);
    });
  }

  async function maybeControlHackrf(action) {
    if (!action || action.action !== 'receiver.tune') return null;
    let probe;
    try { probe = await requestEmbeddedSdr({action: 'probe'}, 1200); }
    catch (_) { return null; }
    if (!probe || String(probe.device_driver || '').toLowerCase() !== 'hackrf') return null;

    const requestedFrequency = Number(action.frequency_hz) > 0
      ? Number(action.frequency_hz) : Number(probe.frequency_hz);
    const requestedMode = action.mode || probe.mode || '';
    const requestedBandwidth = Number(action.bandwidth_hz) > 0
      ? Number(action.bandwidth_hz) : Number(probe.bandwidth_hz);
    const mhz = requestedFrequency > 0 ? (requestedFrequency / 1000000).toFixed(6) : '';
    const description = [mhz ? `${mhz} MHz` : '', requestedMode,
      requestedBandwidth > 0 ? `${Math.round(requestedBandwidth).toLocaleString()} Hz` : '']
      .filter(Boolean).join(', ');
    if (!(await requestOperatorConfirmation(`Allow this receive-only HackRF change${description ? ` to ${description}` : ''}?`))) {
      return {status: 'cancelled', action: 'receiver.tune', message: 'The user cancelled the requested HackRF receive change.'};
    }
    setStatus('Tuning and verifying the HackRF receiver…');
    const result = await requestEmbeddedSdr({
      action: 'receiver.tune',
      frequency_hz: requestedFrequency,
      mode: requestedMode,
      bandwidth_hz: requestedBandwidth,
    }, 4000);
    if (!result || !result.ok) throw new Error(result?.error || 'The HackRF receive change failed.');
    return result;
  }

  async function selectedRadioResponsive() {
    const response = await fetch('/programs/ElmerRigControl.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'},
      body: JSON.stringify({phase: 'connection_status', radio: Number(window.tMyRadio) || 1})
    });
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) {
      location.href = '/login.php';
      throw new Error('Please sign in to RigPi.');
    }
    if (!response.ok) throw new Error(data.error || 'RigPi could not check the live radio connection.');
    return data.responsive === true;
  }

  function normalizedContactCall(value) {
    const call = String(value || '').trim().toUpperCase().replace(/\s+/g, '');
    return /^(?=.{3,15}$)(?=.*[A-Z])(?=.*\d)[A-Z0-9]+(?:\/[A-Z0-9]+)*$/.test(call) ? call : '';
  }

  async function prepareLogContact(action) {
    const selectedRadio = Number(window.tMyRadio) || 1;
    const requestedRadio = Number(action.radio) || selectedRadio;
    if (requestedRadio !== selectedRadio) {
      throw new Error(`Select Radio ${requestedRadio} in RigPi before preparing its log entry.`);
    }
    const visibleCall = document.getElementById('searchText')?.value || window.tDX || '';
    const callsign = normalizedContactCall(action.callsign) || normalizedContactCall(visibleCall);
    if (!callsign) {
      throw new Error('Include the callsign, for example: “Log contact W1AW.”');
    }

    const frequency = Number(String(window.tMain || '').replace(/\D/g, ''));
    const mode = String(window.tMode || '').trim().toUpperCase();
    const station = Number.isFinite(frequency) && frequency >= 1000
      ? ` at ${frequencyForDisplay(frequency)} MHz${mode ? ` ${mode}` : ''}` : '';
    const approved = await requestOperatorConfirmation(
      `Prepare a new log entry for ${callsign}${station}? RigPi will fill the contact, time, frequency, mode, and band. Nothing will be saved until you click Save in Log Editor.`
    );
    if (!approved) {
      showResult('<strong>Log entry cancelled.</strong> Nothing was opened or saved.', 'warning');
      setStatus('Nothing was changed.');
      return false;
    }

    setStatus(`Preparing a log entry for ${callsign}…`);
    const settingHeaders = {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'};
    const writeResponse = await fetch('/programs/SetSettings.php', {
      method: 'POST', credentials: 'same-origin', headers: settingHeaders,
      body: new URLSearchParams({field: 'DX', radio: String(selectedRadio), data: callsign, table: 'MySettings'})
    });
    if (!writeResponse.ok) throw new Error(`RigPi could not place ${callsign} in the Log Editor.`);

    let logStyle = 'General';
    try {
      const styleResponse = await fetch('/programs/GetSetting.php', {
        method: 'POST', credentials: 'same-origin', headers: settingHeaders,
        body: new URLSearchParams({radio: String(selectedRadio), field: 'LogStyle', table: 'MySettings'})
      });
      const savedStyle = styleResponse.ok ? String(await styleResponse.text()).trim() : '';
      if (savedStyle) logStyle = savedStyle;
    } catch (_) {}

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/logEditor.php';
    form.target = '_self';
    const values = {
      c: String(window.tMyCall || ''), x: String(window.tUserName || ''),
      id: '0', radio: String(selectedRadio), what: 'edit', style: logStyle,
      elmer_main_hz: Number.isFinite(frequency) && frequency >= 1000 ? String(frequency) : '',
      elmer_mode: mode,
      elmer_bandwidth: String(window.tBW || window.tBandwidth || '').replace(/\D/g, '')
    };
    for (const [name, value] of Object.entries(values)) {
      const input = document.createElement('input');
      input.type = 'hidden'; input.name = name; input.value = value;
      form.appendChild(input);
    }
    form.hidden = true;
    document.body.appendChild(form);
    form.submit();
    return true;
  }

  async function runLocalRadioPowerAction(action) {
    const powerOn = action.action === 'station.power_on';
    const selectedRadio = Number(window.tMyRadio) || 1;
    const requestedRadio = Number(action.radio) || selectedRadio;
    if (requestedRadio !== selectedRadio) {
      throw new Error(`Select Radio ${requestedRadio} in RigPi before asking Elmer to change its power.`);
    }
    if (String(window.tRadioModel || '').toUpperCase() === 'NET RIGCTL') {
      throw new Error("Shared radio access (Net rigctl) cannot control radio power.");
    }
    if (!powerOn && (Number(window.tPTT) === 1 || Number(window.tPTTIsOn) === 1)) {
      throw new Error('RigPi will not power off the radio while PTT is active. Return to receive and try again.');
    }
    const alive = await selectedRadioResponsive();
    if (alive === powerOn) {
      return {status: 'complete', action: action.action, radio: selectedRadio,
        powered_on: powerOn, already_in_state: true};
    }
    const radioName = String(window.tRadioModel || `Radio ${selectedRadio}`);
    const confirmation = powerOn
      ? `Power on and connect ${radioName} on Radio ${selectedRadio}?`
      : `Power off and disconnect ${radioName} on Radio ${selectedRadio}?\n\nRigPi will first clear split and release its PTT controls.`;
    if (!(await requestOperatorConfirmation(confirmation))) {
      return {status: 'cancelled', action: action.action, radio: selectedRadio};
    }
    setStatus(powerOn ? 'Powering on and connecting the selected radio…' : 'Powering off and disconnecting the selected radio…');
    if (powerOn) {
      // Power-on is also the recovery path for a disconnected radio. Send it
      // straight to RigPi's established power-on/connect endpoint so the
      // normal disconnected-command guard cannot reject it.
      const response = await fetch('/programs/connectRadio.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'X-Elmer-Action': 'rig-control'}
      });
      if (!response.ok) throw new Error('RigPi could not start the radio power-on and connection sequence.');
    } else {
      if (typeof window.elmerRadioPowerCommand !== 'function') {
        throw new Error('RigPi radio power control is unavailable in this window.');
      }
      window.elmerRadioPowerCommand('*PS0;');
    }
    for (let attempt = 0; attempt < 60; attempt += 1) {
      await new Promise(resolve => setTimeout(resolve, 500));
      try {
        if ((await selectedRadioResponsive()) === powerOn) {
          return {status: 'complete', action: action.action, radio: selectedRadio,
            powered_on: powerOn, already_in_state: false};
        }
      } catch (_) {}
    }
    throw new Error(powerOn
      ? 'RigPi did not confirm that the radio powered on and connected.'
      : 'RigPi did not confirm that the radio powered off and disconnected.');
  }

  async function tuneActivityOnBand(action) {
    setStatus(`Checking RigPi's ${action.activity_query} activity directory for ${action.band_query}…`);
    let data = {}, directoryError = '';
    try {
      const response = await fetch('/programs/ElmerFrequency.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({query_mode: 'activity_on_band', activity_query: action.activity_query, band_query: action.band_query})
      });
      data = await response.json().catch(() => ({}));
      if (!response.ok) directoryError = data.error || 'RigPi could not search its activity directory.';
    } catch (error) {
      directoryError = String(error?.message || error);
    }
    let primary = data.primary_activity || (Array.isArray(data.activity_matches) ? data.activity_matches[0] : null);
    // Keep the common FT8/FT4 smart-bar destinations available even when a
    // restored station database has not yet recreated the optional activity
    // directory. These are customary dial frequencies, not regulatory limits;
    // the receiver action still performs the separate FCC-license warning.
    if (!primary) {
      const common = {
        FT8: {'160m':1840000, '80m':3573000, '60m':5357000, '40m':7074000,
          '30m':10136000, '20m':14074000, '17m':18100000, '15m':21074000,
          '12m':24915000, '10m':28074000, '6m':50313000, '2m':144174000},
        FT4: {'40m':7047500, '30m':10140000, '20m':14080000, '17m':18104000,
          '15m':21140000, '12m':24919000, '10m':28180000, '6m':50318000}
      };
      const fallback = Number(common[String(action.activity_query || '').toUpperCase()]?.[String(action.band_query || '')]);
      if (Number.isFinite(fallback) && fallback >= 1000) {
        primary = {dial_hz: fallback, mode: 'PKTUSB'};
      }
    }
    const frequency = Number(primary?.dial_hz || primary?.start_hz);
    if (!Number.isFinite(frequency) || frequency < 1000) {
      throw new Error(directoryError || `RigPi has no ${action.activity_query} dial frequency listed for ${action.band_query}.`);
    }
    // BandActivity describes the customary sideband generically, but FT8/FT4
    // need Hamlib's data-mode name so radios such as the IC-7300 can verify the
    // same mode they actually select instead of being rolled back as "USB".
    const activity = String(action.activity_query || '').toUpperCase();
    const listedMode = String(primary?.mode || 'PKTUSB').toUpperCase();
    const mode = /^(?:FT8|FT4)$/.test(activity) && listedMode === 'USB'
      ? 'PKTUSB' : listedMode;
    return controlAction({enabled: true, action: 'receiver.tune', radio: Number(action.radio) || 0,
      frequency_hz: Math.round(frequency), mode, bandwidth_hz: 3000});
  }

  async function readInterfaceField(field) {
    const body = new URLSearchParams({radio: String(Number(window.tMyRadio) || 1), field});
    const response = await fetch('/programs/GetInterface.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body
    });
    if (!response.ok) throw new Error(`RigPi could not read ${field}.`);
    return String(await response.text()).trim();
  }

  async function readRadioSetting(field) {
    const body = new URLSearchParams({
      radio: String(Number(window.tMyRadio) || 1), field, table: 'RadioInterface'
    });
    const response = await fetch('/programs/GetSetting.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body
    });
    if (!response.ok) throw new Error(`RigPi could not read ${field}.`);
    return String(await response.text()).trim();
  }

  async function writeRadioSetting(field, value) {
    const body = new URLSearchParams({
      radio: String(Number(window.tMyRadio) || 1), field,
      data: String(value), table: 'RadioInterface'
    });
    const response = await fetch('/programs/SetSettings.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body
    });
    if (!response.ok) throw new Error(`RigPi could not change ${field}.`);
  }

  function storedMicLevelToPercent(value) {
    const stored = Math.round(Number(value));
    if (!Number.isFinite(stored) || stored <= 0) return 0;
    if (stored >= 100) return 100;
    return stored - 1;
  }

  function micPercentToStoredLevel(percent) {
    const requested = Math.max(0, Math.min(100, Math.round(Number(percent))));
    return requested >= 100 ? 100 : requested + 1;
  }

  async function runLocalMicLevelAction(action) {
    const selectedRadio = Number(window.tMyRadio) || 1;
    const requestedRadio = Number(action.radio) || selectedRadio;
    const requestedPercent = Math.round(Number(action.level_percent));
    if (requestedRadio !== selectedRadio) {
      throw new Error(`Select Radio ${requestedRadio} in RigPi before changing its microphone level.`);
    }
    if (!Number.isFinite(requestedPercent) || requestedPercent < 0 || requestedPercent > 100) {
      throw new Error('The microphone level must be between 0 and 100 percent.');
    }
    if (Number(window.tPTT) === 1 || Number(window.tPTTIsOn) === 1) {
      throw new Error('RigPi will not change the microphone level while PTT is active. Return to receive and try again.');
    }
    const currentPercent = storedMicLevelToPercent(await readRadioSetting('MicLvl'));
    if (currentPercent === requestedPercent) {
      return {status: 'complete', action: action.action, radio: selectedRadio,
        verified_level_percent: currentPercent, already_in_state: true};
    }
    const radioName = String(window.tRadioModel || `Radio ${selectedRadio}`);
    if (!(await requestOperatorConfirmation(
      `Change ${radioName} microphone level from ${currentPercent}% to ${requestedPercent}%?\n\nThis changes transmit audio level only. It will not key the radio.`
    ))) {
      return {status: 'cancelled', action: action.action, radio: selectedRadio};
    }
    setStatus('Changing the microphone level…');
    const storedTarget = micPercentToStoredLevel(requestedPercent);
    await writeRadioSetting('MicLvl', storedTarget);
    for (let attempt = 0; attempt < 10; attempt += 1) {
      await new Promise(resolve => setTimeout(resolve, 100));
      const verifiedPercent = storedMicLevelToPercent(await readRadioSetting('MicLvl'));
      if (verifiedPercent === requestedPercent) {
        const slider = window.jQuery?.('#sliderMic');
        if (slider?.length && typeof slider.slider === 'function') slider.slider('value', storedTarget);
        const label = document.getElementById('myMicVal');
        if (label) label.textContent = String(verifiedPercent);
        return {status: 'complete', action: action.action, radio: selectedRadio,
          verified_level_percent: verifiedPercent, already_in_state: false};
      }
    }
    throw new Error('RigPi did not verify the requested microphone level.');
  }

  function sameStoredFrequency(left, right) {
    const a = Number(String(left || '').replace(/\D/g, ''));
    const b = Number(String(right || '').replace(/\D/g, ''));
    return Number.isFinite(a) && a >= 1000 && a === b;
  }

  async function runLocalVfoAction(action) {
    const names = {
      'receiver.vfo_swap': ['swap VFO A and B', 'swap'],
      'receiver.vfo_a_to_b': ['copy VFO A to B', 'a_to_b'],
      'receiver.vfo_a_to_memory': ['copy VFO A to memory', 'a_to_memory'],
      'receiver.memory_to_vfo_a': ['copy memory to VFO A', 'memory_to_a']
    };
    const entry = names[action.action];
    if (!entry) throw new Error('That VFO action is not supported.');
    if (!(await selectedRadioResponsive())) throw new Error('Connect the radio before changing VFO or memory values.');
    if (Number(window.tPTT) === 1 || Number(window.tPTTIsOn) === 1) {
      throw new Error('RigPi will not change VFO or memory values while PTT is active.');
    }
    const mainBefore = await readInterfaceField('MainIn');
    const subBefore = await readInterfaceField('SubIn');
    const memoryBefore = await readInterfaceField('M');
    if (action.action === 'receiver.memory_to_vfo_a' && !sameStoredFrequency(memoryBefore, memoryBefore)) {
      throw new Error('The VFO memory does not contain a valid frequency.');
    }
    if (!(await requestOperatorConfirmation(`${entry[0][0].toUpperCase()}${entry[0].slice(1)} on Radio ${Number(window.tMyRadio) || 1}?

Split will be turned on if needed.`))) {
      return {status: 'cancelled', action: action.action, radio: Number(window.tMyRadio) || 1};
    }
    if (typeof window.elmerVfoCommand !== 'function') throw new Error('RigPi VFO controls are unavailable in this window.');
    setStatus(`${entry[0][0].toUpperCase()}${entry[0].slice(1)}…`);
    window.elmerVfoCommand(entry[1]);
    for (let attempt = 0; attempt < 40; attempt += 1) {
      await new Promise(resolve => setTimeout(resolve, 250));
      const main = await readInterfaceField('MainIn');
      const sub = await readInterfaceField('SubIn');
      const memory = await readInterfaceField('M');
      const verifiedValue = action.action === 'receiver.vfo_swap'
        ? sameStoredFrequency(main, subBefore) && sameStoredFrequency(sub, mainBefore)
        : action.action === 'receiver.vfo_a_to_b'
          ? sameStoredFrequency(sub, mainBefore)
          : action.action === 'receiver.vfo_a_to_memory'
            ? sameStoredFrequency(memory, mainBefore)
            : sameStoredFrequency(main, memoryBefore);
      const verified = Number(window.tSplitOn) === 1 && verifiedValue;
      if (verified) return {status: 'complete', action: action.action,
        radio: Number(window.tMyRadio) || 1, verified_frequency_hz: Number(String(main).replace(/\D/g, ''))};
    }
    throw new Error(`RigPi did not verify that it completed ${entry[0]}.`);
  }

  async function runLocalRadioConnectionAction(action) {
    const connect = action.action === 'station.connect';
    const selectedRadio = Number(window.tMyRadio) || 1;
    const requestedRadio = Number(action.radio) || selectedRadio;
    if (requestedRadio !== selectedRadio) {
      throw new Error(`Select Radio ${requestedRadio} in RigPi before asking Elmer to ${connect ? 'connect' : 'disconnect'} it.`);
    }
    if (!connect && (Number(window.tPTT) === 1 || Number(window.tPTTIsOn) === 1)) {
      throw new Error('RigPi will not disconnect the radio while PTT is active. Return to receive and try again.');
    }
    const responsive = await selectedRadioResponsive();
    if (responsive === connect) {
      return {status: 'complete', action: action.action, radio: selectedRadio,
        connected: connect, already_in_state: true};
    }
    const radioName = String(window.tRadioModel || `Radio ${selectedRadio}`);
    if (!(await requestOperatorConfirmation(`${connect ? 'Connect' : 'Disconnect'} ${radioName} on Radio ${selectedRadio}?`))) {
      return {status: 'cancelled', action: action.action, radio: selectedRadio};
    }
    if (typeof window.elmerRadioConnectionCommand !== 'function') {
      throw new Error('RigPi radio connection control is unavailable in this window.');
    }
    setStatus(connect ? 'Connecting the selected radio…' : 'Disconnecting the selected radio…');
    window.elmerRadioConnectionCommand(connect ? 'connect' : 'disconnect');
    let consecutive = 0;
    for (let attempt = 0; attempt < 60; attempt += 1) {
      await new Promise(resolve => setTimeout(resolve, 500));
      if ((await selectedRadioResponsive()) === connect) {
        consecutive += 1;
        if (consecutive >= 3) {
          return {status: 'complete', action: action.action, radio: selectedRadio,
            connected: connect, already_in_state: false};
        }
      } else {
        consecutive = 0;
      }
    }
    throw new Error(connect
      ? 'RigPi did not confirm a live CAT connection to the radio.'
      : 'RigPi did not confirm that the radio disconnected.');
  }

  function expectedControlSuccessSpeech(action) {
    const radio = Number(action.radio) || Number(window.tMyRadio) || 1;
    if (action.action === 'receiver.tune' && Number(action.frequency_hz) > 0 && action.mode) {
      const bandwidth = Number(action.bandwidth_hz) || 0;
      return `Radio ${radio} is now tuned to ${frequencyForSpeech(action.frequency_hz)} megahertz, ${action.mode}${bandwidth > 0 ? `, with ${bandwidth} hertz bandwidth` : ''}. The change is verified.`;
    }
    if ((action.action === 'receiver.level' || action.action === 'receiver.auto_rf_noise')
        && Number.isFinite(Number(action.level_percent))) {
      const labels = {AF: 'volume', RF: 'RF gain', SQL: 'squelch'};
      const levelName = String(action.level_name || '');
      return `Changed and verified. Radio ${radio} ${labels[levelName] || levelName} is ${Number(action.level_percent)} percent.`;
    }
    return '';
  }

  async function controlAction(action) {
    const hackrfResult = await maybeControlHackrf(action);
    if (hackrfResult) return hackrfResult;
    const headers = {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'};
    const prepare = {
      phase: 'prepare', action: action.action,
      radio: Number(action.radio) > 0 ? Number(action.radio) : null,
      frequency_hz: Number(action.frequency_hz) > 0 ? Number(action.frequency_hz) : null,
      mode: action.mode || null,
      bandwidth_hz: Number(action.bandwidth_hz) > 0 ? Number(action.bandwidth_hz) : null,
      split_frequency_hz: Number(action.split_frequency_hz) > 0 ? Number(action.split_frequency_hz) : null,
      split_offset_hz: Number(action.split_offset_hz) !== 0 ? Number(action.split_offset_hz) : null,
      level_name: action.level_name || null,
      level_percent: Number.isFinite(Number(action.level_percent)) ? Number(action.level_percent) : null,
      cw_speed_wpm: Number.isFinite(Number(action.cw_speed_wpm)) ? Number(action.cw_speed_wpm) : null,
      cw_text: typeof action.cw_text === 'string' ? action.cw_text : null,
      macro_selector: typeof action.macro_selector === 'string' ? action.macro_selector : null,
      macro_bank: Number(action.macro_bank) >= 1 ? Number(action.macro_bank) : null,
      macro_sequence: Array.isArray(action.macro_sequence) ? action.macro_sequence : null,
      macro_context: action.macro_context === 'keyer' ? 'keyer' : 'tuner',
      shared_approval_id: typeof action.shared_approval_id === 'string' ? action.shared_approval_id : null
    };
    setStatus('Preparing a safe station change…');
    let response = await fetch('/programs/ElmerRigControl.php', {
      method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(prepare)
    });
    let data = await response.json().catch(() => ({}));
    if (response.status === 401) { location.href = '/login.php'; throw new Error('Please sign in to RigPi.'); }
    if (response.status === 202 && data.approval_required && data.approval_id) {
      const approvalId = String(data.approval_id);
      setStatus('Waiting for administrator approval of this shared-radio split change…');
      showResult('<strong>Approval requested.</strong> The administrator has been notified. This split command will continue automatically if approved within two minutes.', 'warning');
      for (let attempt = 0; attempt < 60; attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, 2000));
        const statusResponse = await fetch('/programs/ElmerRigControl.php', {
          method: 'POST', credentials: 'same-origin', headers,
          body: JSON.stringify({phase: 'shared_status', approval_id: approvalId})
        });
        const statusData = await statusResponse.json().catch(() => ({}));
        if (!statusResponse.ok) throw new Error(statusData.error || 'RigPi could not check the shared-radio approval.');
        if (statusData.status === 'approved') {
          return controlAction({...action, shared_approval_id: approvalId});
        }
        if (statusData.status === 'denied') throw new Error('The administrator declined this shared-radio split change.');
        if (statusData.status === 'expired') throw new Error('The shared-radio approval request expired. Please try again.');
      }
      throw new Error('The shared-radio approval request expired. Please try again.');
    }
    if (!response.ok) throw new Error(data.error || 'RigPi could not prepare the radio change.');
    if (data.status === 'complete' && data.already_in_state) return data;
    if (!data.owner_approved && !(await requestOperatorConfirmation(
      data.confirmation || 'Allow this receive-only radio change?', expectedControlSuccessSpeech(action)
    ))) {
      response = await fetch('/programs/ElmerRigControl.php', {
        method: 'POST', credentials: 'same-origin', headers,
        body: JSON.stringify({phase: 'cancel', confirmation_id: data.confirmation_id})
      });
      data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'RigPi could not cancel the proposed action.');
      return data;
    }
    setStatus('Changing and verifying the selected station setting…');
    response = await fetch('/programs/ElmerRigControl.php', {
      method: 'POST', credentials: 'same-origin', headers,
      body: JSON.stringify({phase: 'execute', confirmation_id: data.confirmation_id})
    });
    data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'RigPi could not complete the radio change.');
    return data;
  }

  const sharedApprovalToasts = new Set();

  async function decideSharedApproval(approvalId, decision, toast) {
    const headers = {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'};
    const response = await fetch('/programs/ElmerRigControl.php', {
      method: 'POST', credentials: 'same-origin', headers,
      body: JSON.stringify({phase: 'shared_decide', approval_id: approvalId, decision})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'RigPi could not record that decision.');
    toast.classList.add(decision === 'approved' ? 'approved' : 'denied');
    toast.querySelector('.elmer-shared-approval-actions').replaceChildren(
      document.createTextNode(decision === 'approved' ? 'Approved' : 'Denied')
    );
    setTimeout(() => toast.remove(), 1800);
  }

  function showSharedApprovalToast(item) {
    const approvalId = String(item.approval_id || '');
    if (!approvalId || sharedApprovalToasts.has(approvalId)) return;
    sharedApprovalToasts.add(approvalId);
    const toast = document.createElement('aside');
    toast.className = 'elmer-shared-approval-toast';
    toast.setAttribute('role', 'alertdialog');
    toast.setAttribute('aria-label', 'Shared radio approval');
    toast.innerHTML = `<strong>Shared-radio request</strong><br>${escapeHtml(item.requester)} requests: <strong>${escapeHtml(item.action_label)}</strong> on Radio ${escapeHtml(item.radio)}.<div class="elmer-shared-approval-actions"><button type="button" data-decision="approved">Approve</button><button type="button" data-decision="denied">Deny</button></div>`;
    toast.querySelectorAll('button').forEach(button => button.addEventListener('click', async () => {
      toast.querySelectorAll('button').forEach(itemButton => { itemButton.disabled = true; });
      try {
        await decideSharedApproval(approvalId, button.dataset.decision, toast);
      } catch (error) {
        toast.querySelector('.elmer-shared-approval-actions').textContent = String(error.message || 'Approval failed.');
        setTimeout(() => toast.remove(), 3000);
      }
    }));
    document.body.appendChild(toast);
  }

  async function pollSharedApprovals() {
    try {
      const response = await fetch('/programs/ElmerRigControl.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'},
        body: JSON.stringify({phase: 'shared_pending'})
      });
      if (!response.ok) return;
      const data = await response.json().catch(() => ({}));
      (Array.isArray(data.approvals) ? data.approvals : []).forEach(showSharedApprovalToast);
    } catch (_) {}
  }

  setTimeout(pollSharedApprovals, 1200);
  setInterval(pollSharedApprovals, 4000);

  function renderOutcome(outcome) {
    if (outcome.status === 'cancelled') {
      showResult('The radio change was cancelled. Nothing was changed.', 'warning');
      if (Date.now() - lastCancellationAcknowledgement > 10000) speakResult('The radio change was cancelled.');
      return;
    }
    if (/^receiver\.(?:vfo_swap|vfo_a_to_b|vfo_a_to_memory|memory_to_vfo_a)$/.test(outcome.action)) {
      const labels = {
        'receiver.vfo_swap': 'VFO A and B were swapped',
        'receiver.vfo_a_to_b': 'VFO A was copied to B',
        'receiver.vfo_a_to_memory': 'VFO A was copied to memory',
        'receiver.memory_to_vfo_a': 'Memory was copied to VFO A'
      };
      command.value = '';
      showResult(`<strong>VFO command verified.</strong> ${escapeHtml(labels[outcome.action])}.`, 'success');
      speakResult(labels[outcome.action]);
      return;
    }
    if (outcome.action === 'station.connect' || outcome.action === 'station.disconnect') {
      const connected = outcome.action === 'station.connect';
      command.value = '';
      const unchanged = outcome.already_in_state ? ' already was' : ' is now';
      showResult(`<strong>Radio connection verified.</strong> Radio ${escapeHtml(outcome.radio)}${unchanged} <strong>${connected ? 'connected and responding to CAT' : 'disconnected'}</strong>.`, 'success');
      speakResult(`Radio ${outcome.radio} is ${connected ? 'connected' : 'disconnected'}.`);
      return;
    }
    if (outcome.action === 'station.power_on' || outcome.action === 'station.power_off') {
      const on = outcome.action === 'station.power_on';
      command.value = '';
      const unchanged = outcome.already_in_state ? ' already was' : ' is now';
      showResult(`<strong>Radio power verified.</strong> Radio ${escapeHtml(outcome.radio)}${unchanged} <strong>${on ? 'on and connected' : 'off and disconnected'}</strong>.`, 'success');
      speakResult(`Radio ${outcome.radio} is ${on ? 'on and connected' : 'off and disconnected'}.`);
      return;
    }
    if (outcome.action === 'station.mic_level') {
      command.value = '';
      const percent = Number(outcome.verified_level_percent);
      const unchanged = outcome.already_in_state ? ' already was' : ' is now';
      showResult(`<strong>Microphone level verified.</strong> Radio ${escapeHtml(outcome.radio)}${unchanged} <strong>${escapeHtml(percent)}%</strong>.`, 'success');
      speakResult(`Microphone level verified. Radio ${outcome.radio}${unchanged} ${percent} percent.`);
      return;
    }
    if (outcome.action === 'receiver.split_status') {
      command.value = '';
      if (!outcome.split_on) {
        showResult(`<strong>Split is off.</strong> Radio ${escapeHtml(outcome.radio)} is receiving on <strong>${escapeHtml(frequencyForDisplay(outcome.frequency_hz))} MHz</strong>.`, 'success');
        speakResult(`Split is off on radio ${outcome.radio}.`);
      } else {
        const rx = frequencyForDisplay(outcome.frequency_hz);
        const tx = frequencyForDisplay(outcome.split_frequency_hz);
        const offset = Number(outcome.split_frequency_hz) - Number(outcome.frequency_hz);
        const signed = `${offset >= 0 ? '+' : '−'}${(Math.abs(offset) / 1000).toFixed(1)}`;
        showResult(`<strong>Split is on.</strong> Radio ${escapeHtml(outcome.radio)} RX is <strong>${escapeHtml(rx)} MHz</strong>; TX is <strong>${escapeHtml(tx)} MHz</strong> (${escapeHtml(signed)} kHz).`, 'success');
        speakResult(`Split is on. Receive ${rx} megahertz, transmit ${tx} megahertz.`);
      }
      return;
    }
    if (outcome.action === 'receiver.split_set' || outcome.action === 'receiver.split_off') {
      command.value = '';
      if (!outcome.split_on) {
        showResult(`<strong>Split turned off and verified.</strong> Radio ${escapeHtml(outcome.radio)} remains in receive.`, 'success');
        speakResult(`Split turned off and verified on radio ${outcome.radio}.`);
      } else {
        const rx = frequencyForDisplay(outcome.receive_frequency_hz);
        const tx = frequencyForDisplay(outcome.verified_split_frequency_hz);
        const offset = Number(outcome.split_offset_hz);
        const signed = `${offset >= 0 ? '+' : '−'}${(Math.abs(offset) / 1000).toFixed(1)}`;
        const readback = outcome.split_frequency_readback
          ? 'The radio verified the TX frequency.'
          : 'Hamlib confirmed split on and accepted the TX-frequency command; this radio does not return split-frequency readback.';
        showResult(`<strong>Split enabled.</strong> Radio ${escapeHtml(outcome.radio)} RX is <strong>${escapeHtml(rx)} MHz</strong>; TX is <strong>${escapeHtml(tx)} MHz</strong> (${escapeHtml(signed)} kHz). ${escapeHtml(readback)} Elmer did not transmit.`, 'success');
        speakResult(`Split enabled. Receive ${rx} megahertz, transmit ${tx} megahertz.`);
      }
      return;
    }
    if (outcome.action === 'receiver.level' || outcome.action === 'receiver.auto_rf_noise') {
      const labels = {AF: 'volume', RF: 'RF gain', SQL: 'squelch'};
      const levelName = String(outcome.level_name || '');
      const percent = Number(outcome.verified_level_percent);
      command.value = '';
      const strength = Number.isFinite(Number(outcome.verified_strength_db))
        ? ` The stable S-meter reading is <strong>${escapeHtml(outcome.verified_strength_db)} dB relative to S9</strong>.` : '';
      showResult(`<strong>Changed and verified.</strong> Radio ${escapeHtml(outcome.radio)} ${escapeHtml(labels[levelName] || levelName)} is <strong>${escapeHtml(percent)}%</strong>.${strength}`, 'success');
      speakPreparedResult(`Changed and verified. Radio ${outcome.radio} ${labels[levelName] || levelName} is ${percent} percent.`);
      return;
    }
    if (outcome.action === 'keyer.speed') {
      command.value = '';
      synchronizeKeyerFromServer();
      showResult(`<strong>Changed and verified.</strong> Radio ${escapeHtml(outcome.radio)} CW speed is <strong>${escapeHtml(outcome.verified_cw_speed_wpm)} WPM</strong>.`, 'success');
      speakResult(`Changed and verified. Radio ${outcome.radio} CW speed is ${outcome.verified_cw_speed_wpm} words per minute.`);
      return;
    }
    if (outcome.action === 'keyer.stage_text') {
      command.value = '';
      const inKeyer = synchronizeKeyerCw(true, outcome.cw_text);
      const reviewControl = inKeyer
        ? '<br><strong>Review the CW to be Sent box, then use the red HOLD button manually when ready.</strong>'
        : '<br><button id="elmerOpenCwKeyer" class="btn btn-outline-primary btn-sm mt-2" type="button">Open Keyer and review</button>';
      showResult(`<strong>Text staged safely.</strong> Radio ${escapeHtml(outcome.radio)} CW is <strong>on Hold</strong>. The outgoing buffer contains <strong>${escapeHtml(outcome.cw_text)}</strong>. Elmer did not transmit.${reviewControl}`, 'success');
      const openKeyer = result.querySelector('#elmerOpenCwKeyer');
      if (openKeyer) openKeyer.addEventListener('click', () => {
        const keyerWindow = window.open('/keyer.php', '_blank');
        if (!keyerWindow) {
          setStatus('Allow pop-up windows for RigPi, then open the Keyer from the RigPi menu. CW remains on Hold.');
          return;
        }
        keyerWindow.addEventListener('load', () => {
          try {
            // The server-side Hold flag is already on. This synchronizes only
            // the legacy Keyer page's browser-local lamp and visible textarea.
            keyerWindow.holdCW = true;
            const input = keyerWindow.document.getElementById('cwi');
            const label = keyerWindow.document.getElementById('myHold');
            const button = keyerWindow.document.getElementById('holdButton');
            if (input) input.value = String(outcome.cw_text || '');
            if (label) label.textContent = 'HOLD\u00a0';
            if (button) {
              button.classList.remove('btn-color', 'btn-primary');
              button.classList.add('btn-danger');
            }
            if (input) input.focus();
          } catch (_) {
            setStatus('The Keyer opened. CW remains on Hold; review it before manually releasing Hold.');
          }
        }, {once: true});
      });
      speakResult(`Text staged safely. Radio ${outcome.radio} CW is on hold. Review it in the RigPi Keyer and release hold manually when ready.`);
      return;
    }
    if (outcome.action === 'macro.run') {
      command.value = '';
      const macroName = String(outcome.macro_label || `Macro ${outcome.macro_slot || ''}`);
      if (outcome.macro_kind === 'cw_stage') {
        const inKeyer = synchronizeKeyerCw(true, outcome.cw_text);
        showResult(`<strong>Macro staged safely.</strong> <strong>${escapeHtml(macroName)}</strong> placed <strong>${escapeHtml(outcome.cw_text)}</strong> in Radio ${escapeHtml(outcome.radio)}'s CW buffer with Hold on. Elmer did not transmit.${inKeyer ? '<br>Review the CW to be Sent box and release Hold manually when ready.' : ''}`, 'success');
        speakResult(`Macro ${macroName} was staged safely with CW hold on.`);
      } else if (outcome.macro_kind === 'receiver_then_cw') {
        const mhz = frequencyForDisplay(outcome.verified_frequency_hz);
        const mode = String(outcome.verified_mode || '');
        const width = Number(outcome.verified_bandwidth_hz || 0);
        const inKeyer = synchronizeKeyerCw(true, outcome.cw_text);
        showResult(`<strong>Compound macros completed safely.</strong> Radio ${escapeHtml(outcome.radio)} was verified at <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>${width > 0 ? `, <strong>${escapeHtml(width.toLocaleString())} Hz bandwidth</strong>` : ''}. Then <strong>${escapeHtml(outcome.cw_text)}</strong> was staged with Hold on. Elmer did not transmit.${inKeyer ? '<br>Review the CW to be Sent box and release Hold manually when ready.' : ''}`, 'success');
        speakResult('Compound macros completed. The receiver was verified and CW text was staged with hold on.');
      } else {
        const mhz = frequencyForDisplay(outcome.verified_frequency_hz);
        const mode = String(outcome.verified_mode || '');
        const width = Number(outcome.verified_bandwidth_hz || 0);
        showResult(`<strong>Macro completed and verified.</strong> <strong>${escapeHtml(macroName)}</strong> set Radio ${escapeHtml(outcome.radio)} to <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>${width > 0 ? `, <strong>${escapeHtml(width.toLocaleString())} Hz bandwidth</strong>` : ''}.`, 'success');
        speakResult(`Macro ${macroName} completed and was verified.`);
      }
      return;
    }
    const mhz = frequencyForDisplay(outcome.verified_frequency_hz);
    const mode = String(outcome.verified_mode || outcome.requested_mode || '');
    const bandwidth = Number(outcome.verified_bandwidth_hz || outcome.requested_bandwidth_hz || 0);
    const bandwidthText = bandwidth > 0 ? `, <strong>${escapeHtml(bandwidth.toLocaleString())} Hz bandwidth</strong>` : '';
    command.value = '';
    showResult(`<strong>Changed and verified.</strong> Radio ${escapeHtml(outcome.radio)} is at <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>${bandwidthText}.`, 'success');
    speakPreparedResult(`Radio ${outcome.radio} is now tuned to ${frequencyForSpeech(outcome.verified_frequency_hz)} megahertz, ${mode}${bandwidth > 0 ? `, with ${bandwidth} hertz bandwidth` : ''}. The change is verified.`);
  }

  function unsupportedGuidance(capability) {
    if (capability === 'power') {
      return 'Elmer Control did not change transmitter output power. To change RF output, use the <strong>Power Out</strong> slider in the main RigPi <strong>Tuner</strong> window. Radio Power On/Off commands are separate.';
    }
    return `That ${escapeHtml(capability === 'none' ? 'station' : capability)} control is not yet available through Elmer Control. <a href="/elmer.php">Ask Elmer in Help</a> for instructions.`;
  }

  function sdrFrame() {
    const frame = document.getElementById('sdrIframe');
    if (!frame || !frame.contentWindow || !String(frame.getAttribute('src') || '').trim()) {
      throw new Error('Open the SDR panel before starting a shortwave scan.');
    }
    return frame;
  }

  function postSdrScan(action, details = {}) {
    const frame = sdrFrame();
    let targetOrigin = location.origin;
    try { targetOrigin = new URL(frame.src || location.href, location.href).origin; } catch (_) {}
    frame.contentWindow.postMessage({
      type: 'rigpi-elmer-scan', action,
      kind: action === 'stop' ? '' : 'shortwave',
      ...details
    }, targetOrigin);
  }

  document.addEventListener('keydown', event => {
    if ((!shortwaveScanActive && !shortwaveScanPaused) || (event.key !== ' ' && event.code !== 'Space')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (shortwaveScanActive) {
      shortwaveScanActive = false;
      shortwaveScanPaused = true;
      postSdrScan('stop');
      showResult('<strong>Shortwave scan paused.</strong> The receiver remains on the current frequency. Press Space again to resume.', 'warning');
      setStatus('Scan paused here · Space: resume');
    } else {
      shortwaveScanPaused = false;
      shortwaveScanActive = true;
      postSdrScan('resume');
      showResult('<strong>Shortwave scan resumed.</strong> Elmer is continuing from the paused search.', 'success');
      setStatus('Resuming the SDR scan…');
    }
  }, true);

  async function runLocalSdrAction(action) {
    if (action.action === 'sdr.scan_stop') {
      shortwaveScanActive = false;
      shortwaveScanPaused = true;
      postSdrScan('stop');
      command.value = '';
      showResult('<strong>Stopping the shortwave scan.</strong>', 'success');
      setStatus('Stopping scan…');
      return;
    }
    if (action.action === 'sdr.scan_resume') {
      shortwaveScanActive = true;
      shortwaveScanPaused = false;
      postSdrScan('resume');
      command.value = '';
      showResult('<strong>Shortwave scan resumed.</strong> Elmer is continuing after the last detected signal.', 'success');
      setStatus('Resuming the SDR scan…');
      return;
    }
    if (action.action === 'sdr.station_lookup') {
      const station = String(action.station_query || '').trim();
      if (!(await requestOperatorConfirmation(`Find active frequencies for ${station} and tune the strongest one?`))) {
        showResult('The scheduled-station lookup was cancelled. Nothing was changed.', 'warning');
        setStatus('');
        return;
      }
      shortwaveScanActive = true;
      shortwaveScanPaused = false;
      postSdrScan('tune-station', {station_query: station});
      command.value = '';
      showResult(`<strong>Finding ${escapeHtml(station)}.</strong> Elmer is checking its active scheduled frequencies.`, 'success');
      setStatus(`Checking active frequencies for ${station}…`);
      return;
    }
    if (!(await requestOperatorConfirmation('Scan broadcasts scheduled now and stop on the first detected signal?'))) {
      showResult('The shortwave scan was cancelled. Nothing was changed.', 'warning');
      setStatus('');
      return;
    }
    shortwaveScanActive = true;
    shortwaveScanPaused = false;
    postSdrScan('start');
    command.value = '';
    showResult('<strong>Shortwave scan started.</strong> Elmer is checking broadcasts scheduled right now and will remain tuned to the first detected signal.', 'success');
    setStatus('Waiting for the SDR scan…');
  }

  window.addEventListener('message', event => {
    const frame = document.getElementById('sdrIframe');
    if (!frame || event.source !== frame.contentWindow || !event.data || event.data.type !== 'rigpi-elmer-scan-status') return;
    const update = event.data;
    if (update.status === 'progress' || update.status === 'loading' || update.status === 'started' || update.status === 'station-progress') {
      shortwaveScanActive = true;
      shortwaveScanPaused = false;
      setStatus(`${update.message || 'Scanning scheduled shortwave broadcasts…'} · Space: stop here`);
      return;
    }
    shortwaveScanActive = false;
    if (update.status !== 'stopped') shortwaveScanPaused = false;
    if (update.status === 'station-tuned') {
      const khz = Math.round(Number(update.frequency_hz) / 1000).toLocaleString();
      const alternatives = Array.isArray(update.alternatives_hz)
        ? update.alternatives_hz.map(value => (Number(value) / 1000000).toFixed(3)).join(', ')
        : '';
      const choices = alternatives ? `<br>Active frequencies checked: ${escapeHtml(alternatives)} MHz.` : '';
      showResult(`<strong>Tuned.</strong> ${escapeHtml(update.station || 'Scheduled station')} is selected on <strong>${escapeHtml(khz)} kHz AM</strong>.${choices}`, 'success');
      speakResult(`Tuned to ${update.station || 'the scheduled station'} on ${khz} kilohertz.`);
      setStatus('Scheduled station tuned.');
      return;
    }
    if (update.status === 'station-not-found') {
      const alternatives = Array.isArray(update.alternatives_hz)
        ? update.alternatives_hz.map(value => (Number(value) / 1000000).toFixed(3)).join(', ')
        : '';
      const checked = alternatives ? `<br>Scheduled frequencies checked: ${escapeHtml(alternatives)} MHz.` : '';
      showResult(`<strong>No signal found.</strong> None of the active ${escapeHtml(update.station || 'station')} listings passed ${escapeHtml(update.detector || 'the signal detector')}. The previous frequency was restored.${checked}`, 'warning');
      speakResult(`No ${update.station || 'station'} signal was found. The previous frequency was restored.`);
      setStatus('No scheduled station signal detected.');
      return;
    }
    if (update.status === 'found') {
      const khz = Math.round(Number(update.frequency_hz) / 1000).toLocaleString();
      const snr = Number.isFinite(Number(update.snr_db)) ? ` at <strong>${escapeHtml(update.snr_db)} dB SNR</strong>` : '';
      showResult(`<strong>Signal found.</strong> ${escapeHtml(update.station || 'Scheduled broadcast')} on <strong>${escapeHtml(khz)} kHz AM</strong>${snr}. The receiver remains tuned there; use <strong>Resume scan</strong> to continue.`, 'success');
      speakResult(`Signal found. ${update.station || 'Scheduled broadcast'} on ${khz} kilohertz.`);
      setStatus('Scan stopped on an active broadcast.');
      return;
    }
    if (update.status === 'complete') {
      showResult('<strong>Scan complete.</strong> No scheduled shortwave signal was detected.', 'warning');
      setStatus('No signal detected.');
      return;
    }
    if (update.status === 'stopped') {
      if (shortwaveScanPaused) {
        showResult('<strong>Shortwave scan paused.</strong> The receiver remains on the current frequency. Press Space again to resume.', 'warning');
        setStatus('Scan paused here · Space: resume');
      } else {
        showResult('<strong>Shortwave scan stopped.</strong>', 'warning');
        setStatus('');
      }
      return;
    }
    if (update.status === 'error') {
      showResult(`<strong>Shortwave scan could not continue.</strong> ${escapeHtml(update.message || 'Unknown SDR error.')}`, 'error');
      setStatus(update.message || 'Shortwave scan failed.');
    }
  });

  async function executeCommand(text) {
    const clean = String(text || '').trim();
    if (!clean) return;
    rememberCommand(clean);
    run.disabled = true;
    result.className = 'elmer-control-result';
    setStatus('Interpreting the command…');
    commandLanguageHint = '';
    try {
      const plan = deterministicControlPlan(clean) || await queryPlan(clean);
      const plannedLanguage = String(plan.response_locale || '').trim().toLowerCase().split('-')[0];
      if (/^[a-z]{2,3}$/.test(plannedLanguage)) commandLanguageHint = plannedLanguage;
      const action = plan.rig_control || {};
      if (action.action === 'receiver.activity_tune') {
        const outcome = await tuneActivityOnBand(action);
        renderOutcome(outcome);
        await loadHistory(false);
        setStatus('');
      } else if (/^receiver\.(?:vfo_swap|vfo_a_to_b|vfo_a_to_memory|memory_to_vfo_a)$/.test(action.action)) {
        const outcome = await runLocalVfoAction(action);
        renderOutcome(outcome);
        setStatus('');
      } else if (action.action === 'station.connect' || action.action === 'station.disconnect') {
        const outcome = await runLocalRadioConnectionAction(action);
        renderOutcome(outcome);
        setStatus('');
      } else if (action.action === 'station.power_on' || action.action === 'station.power_off') {
        const outcome = await runLocalRadioPowerAction(action);
        renderOutcome(outcome);
        setStatus('');
      } else if (action.action === 'station.mic_level') {
        const outcome = await runLocalMicLevelAction(action);
        renderOutcome(outcome);
        setStatus('');
      } else if (action.action === 'logbook.prepare_contact') {
        await prepareLogContact(action);
      } else if (action.action === 'receiver.history') {
        history.open = true;
        await loadHistory();
        setStatus('');
      } else if (action.action === 'receiver.split_status') {
        const headers = {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'};
        const response = await fetch('/programs/ElmerRigControl.php', {
          method: 'POST', credentials: 'same-origin', headers,
          body: JSON.stringify({phase: 'split_status', radio: Number(action.radio) > 0 ? Number(action.radio) : null})
        });
        const outcome = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(outcome.error || 'RigPi could not read split status.');
        renderOutcome(outcome);
        setStatus('');
      } else if (action.action === 'sdr.scan_shortwave' || action.action === 'sdr.scan_resume' || action.action === 'sdr.scan_stop' || action.action === 'sdr.station_lookup') {
        await runLocalSdrAction(action);
      } else if (action.enabled) {
        const outcome = await controlAction(action);
        renderOutcome(outcome);
        await loadHistory(false);
        setStatus('');
      } else if (action.action === 'unsupported') {
        showResult(unsupportedGuidance(action.requested_capability || 'other'), 'warning');
        setStatus('No station change was made.');
      } else {
        localStorage.setItem('elmerPendingHelpQuestion', clean);
        showResult('That sounds like a Help question rather than a station command. <a href="/elmer.php">Open it in Ask Elmer</a>.', 'warning');
        setStatus('No station change was made.');
      }
    } catch (error) {
      const message = String(error.message || 'Elmer Control could not complete that command.');
      showResult(`<strong>No change was made.</strong> ${escapeHtml(message)}`, 'error');
      setStatus(message);
    } finally {
      run.disabled = false;
      command.focus();
      scheduleWake();
    }
  }

  async function repeatItem(item) {
    const request = item && item.request || {};
    try {
      const outcome = await controlAction({
        enabled: true, action: 'receiver.tune', radio: Number(item.radio),
        frequency_hz: Number(request.frequency_hz), mode: String(request.mode || ''),
        bandwidth_hz: Number(request.bandwidth_hz) || 0
      });
      renderOutcome(outcome);
      setStatus('');
      await loadHistory(false);
    } catch (error) {
      showResult(escapeHtml(error.message), 'error');
      setStatus('Elmer Control could not repeat that command.');
    }
  }

  async function loadHistory(showLoading = true) {
    if (showLoading) historyList.textContent = 'Loading command history…';
    const response = await fetch('/programs/ElmerRigControl.php', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'rig-control'},
      body: JSON.stringify({phase: 'history', limit: 15})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { historyList.textContent = data.error || 'History is unavailable.'; return; }
    historyList.replaceChildren();
    if (!data.actions || !data.actions.length) { historyList.textContent = 'No Elmer Control commands yet.'; return; }
    for (const item of data.actions) {
      const row = document.createElement('div');
      const title = document.createElement('strong');
      const meta = document.createElement('div');
      row.className = 'elmer-control-history-item';
      title.textContent = item.action_label || item.action_name;
      meta.className = 'elmer-control-history-meta';
      meta.textContent = `${new Date((item.updated_at || item.created_at).replace(' ', 'T') + 'Z').toLocaleString()} · Radio ${item.radio} · ${item.status}`;
      row.append(title, meta);
      const request = item.request || {};
      if (item.status === 'complete' && item.action_class === 'receive_control' && Number(request.frequency_hz) >= 1000) {
        const repeat = document.createElement('button');
        repeat.className = 'btn btn-outline-primary btn-sm';
        repeat.type = 'button';
        repeat.textContent = 'Repeat';
        repeat.addEventListener('click', () => repeatItem(item));
        row.append(repeat);
      }
      historyList.append(row);
    }
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    stopRecognition();
    executeCommand(command.value);
  });
  history.addEventListener('toggle', () => { if (history.open) loadHistory(); });
  synchronizeKeyerFromServer();

  function stopRecognition() {
    window.clearTimeout(restartTimer);
    restartTimer = 0;
    if (!recognition) return;
    const active = recognition;
    recognition = null;
    recognitionMode = '';
    try { active.abort(); } catch (_) {}
    mic.classList.remove('active');
  }

  function scheduleWake() {
    window.clearTimeout(restartTimer);
    if (!wakeEnabled || run.disabled
        || (canBrowserSpeak && speechSynthesis.speaking)
        || (speechAudio && !speechAudio.paused && !speechAudio.ended)) return;
    restartTimer = window.setTimeout(() => startRecognition('wake'), 500);
  }

  function preferredRecordingType() {
    if (!window.MediaRecorder) return '';
    const choices = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'];
    return choices.find(type => !MediaRecorder.isTypeSupported || MediaRecorder.isTypeSupported(type)) || '';
  }

  function releaseCommandStream() {
    window.clearTimeout(commandRecordingTimer);
    commandRecordingTimer = 0;
    if (commandStream) commandStream.getTracks().forEach(track => track.stop());
    commandStream = null;
  }

  function discardPrimedCommandMicrophone() {
    window.clearTimeout(commandStreamWarmTimer);
    commandStreamWarmTimer = 0;
    const pending = commandStreamPromise;
    commandStreamPromise = null;
    pending?.then(stream => stream?.getTracks().forEach(track => track.stop())).catch(() => {});
  }

  function primeCommandMicrophone() {
    if (!navigator.mediaDevices?.getUserMedia || commandStreamPromise || commandRecorder || commandStream) return;
    const pending = navigator.mediaDevices.getUserMedia({audio: true, video: false}).catch(() => null);
    commandStreamPromise = pending;
    window.clearTimeout(commandStreamWarmTimer);
    commandStreamWarmTimer = window.setTimeout(() => {
      if (commandStreamPromise !== pending) return;
      commandStreamPromise = null;
      pending.then(stream => stream?.getTracks().forEach(track => track.stop()));
    }, 25000);
  }

  function blobAsBase64(blob) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('Unable to read the microphone recording.'));
      reader.onload = () => resolve(String(reader.result || '').split(',', 2)[1] || '');
      reader.readAsDataURL(blob);
    });
  }

  async function transcribeCommandAudio(blob, durationSeconds) {
    transcriptionPending = true;
    mic.disabled = true;
    setStatus('Transcribing your command…');
    try {
      if (!blob || blob.size < 256) throw new Error('I did not receive enough audio. Please try again.');
      if (blob.size > 1500000) throw new Error('That recording was too long. Please use a shorter command.');
      const response = await fetch('/elmer-api/transcription', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          audio_base64: await blobAsBase64(blob),
          media_type: blob.type || 'audio/webm',
          // Detect the full command automatically, then retain its language
          // for the very short and otherwise ambiguous approval recording.
          language: pendingVoiceConfirmation ? commandLanguageHint : '',
          transcription_mode: pendingVoiceConfirmation ? 'confirmation' : 'command',
          duration_seconds: Math.max(0.2, Math.min(21, Number(durationSeconds) || 0.2)),
          session_id: `voice_${Date.now()}_${crypto.randomUUID ? crypto.randomUUID().replaceAll('-', '') : Math.random().toString(36).slice(2)}`
        })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Voice transcription is unavailable.');
      const transcript = normalizeVoiceText(data.text || '');
      if (!transcript) throw new Error('I did not hear a command. Please try again.');
      if (pendingVoiceConfirmation) {
        transcriptionPending = false;
        handleVoiceConfirmation(transcript);
        return;
      }
      transcriptionPending = false;
      submitTranscript(transcript, false);
    } catch (error) {
      setStatus(error.message || 'Voice transcription failed.');
    } finally {
      transcriptionPending = false;
      mic.disabled = false;
      scheduleWake();
    }
  }

  function stopCommandRecording() {
    if (!commandRecorder || commandRecorder.state === 'inactive') return;
    setStatus('Finishing the recording…');
    try { commandRecorder.stop(); } catch (_) { releaseCommandStream(); }
  }

  async function startCommandRecording() {
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder || transcriptionPending
        || commandRecordingStarting || commandRecorder || commandStream) return;
    commandRecordingStarting = true;
    commandRecordingCancelRequested = false;
    stopRecognition();
    stopSpokenPlayback();
    try {
      const primed = commandStreamPromise;
      commandStreamPromise = null;
      window.clearTimeout(commandStreamWarmTimer);
      commandStreamWarmTimer = 0;
      const stream = (primed ? await primed : null)
        || await navigator.mediaDevices.getUserMedia({audio: true, video: false});
      if (commandRecordingCancelRequested) {
        stream.getTracks().forEach(track => track.stop());
        mic.classList.remove('active');
        setStatus('Voice command cancelled.');
        return;
      }
      commandStream = stream;
      commandChunks = [];
      const type = preferredRecordingType();
      const recorder = type ? new MediaRecorder(commandStream, {mimeType: type}) : new MediaRecorder(commandStream);
      commandRecorder = recorder;
      recorder.ondataavailable = event => { if (event.data?.size) commandChunks.push(event.data); };
      recorder.onerror = () => {
        releaseCommandStream();
        commandRecorder = null;
        mic.classList.remove('active');
        setStatus('The microphone recording stopped unexpectedly.');
      };
      recorder.onstop = () => {
        const durationSeconds = commandRecordingStarted
          ? (performance.now() - commandRecordingStarted) / 1000 : 0.2;
        const blob = new Blob(commandChunks, {type: recorder.mimeType || type || 'audio/webm'});
        commandChunks = [];
        commandRecorder = null;
        mic.classList.remove('active');
        releaseCommandStream();
        transcribeCommandAudio(blob, durationSeconds);
      };
      recorder.start(250);
      commandRecordingStarted = performance.now();
      mic.classList.add('active');
      setStatus('Listening… tap the microphone again when finished.');
      commandRecordingTimer = window.setTimeout(stopCommandRecording, 20000);
    } catch (error) {
      releaseCommandStream();
      commandRecorder = null;
      mic.classList.remove('active');
      setStatus(error?.name === 'NotAllowedError'
        ? 'Microphone access was not allowed.'
        : `Could not open the microphone${error?.message ? `: ${error.message}` : '.'}`);
      scheduleWake();
    } finally {
      commandRecordingStarting = false;
      commandRecordingCancelRequested = false;
    }
  }

  function submitTranscript(text, needsReview = false) {
    const clean = String(text || '').trim().replace(/[.?!]+$/, '');
    if (!clean) return;
    command.value = clean;
    stopRecognition();
    // Uncertain recognition and spoken CW text still require visual review.
    // Numeric radio commands proceed only to the separate, exact confirmation
    // prompt; they are never executed directly from this first transcript.
    const cwBufferCommand = /\b(?:cw|morse)\b.*\bbuffer\b|\bbuffer\b.*\b(?:cw|morse)\b/i.test(clean);
    if (needsReview || cwBufferCommand) {
      const reason = needsReview
        ? 'The voice transcript was uncertain.'
        : 'Spoken CW buffer text always requires visual review.';
      showResult(`<strong>Please check what I heard:</strong> “${escapeHtml(clean)}”<br>${reason} No radio command was sent. Correct it if necessary, then select <strong>Run</strong>.`, 'warning');
      setStatus('Voice command awaits operator review. No station change was made.');
      command.focus();
      return;
    }
    setStatus('Command heard. Preparing the verified proposal…');
    executeCommand(clean);
  }

  function wakeMatch(text) {
    // Generic browser dictation has no RigPi vocabulary and often substitutes
    // familiar-sounding words. Keep this family deliberately bounded and
    // require a greeting so ordinary conversation cannot wake radio control.
    const greeting = '(?:hey|hay|okay|ok|hé|salut|bonjour|hola|oye|hallo|hej|hei|ciao|olá|oi|cześć|halo|ahoj|moi|привет|эй|здравствуй|привіт|гей|слухай|你好|嗨|こんにちは|ねえ|안녕)';
    const rigPiSound = '(?:rig\\s*(?:pie|pi|bee|by|bay|p)|rigpie|rigby|rigbee|rick(?:\\s*(?:pie|pi|bee|by|bay|p))?|rib\\s*(?:pie|pi)|brie\\s*(?:pie|pi)?|bri|bree|big\\s*(?:pie|pi)|brick\\s*(?:pie|pi)|ruby|rugby|radio|rádio|радио|радіо|ригпи|рігпі)';
    const invocation = `(?:(?:^|\\s)${greeting}\\s*(?:${rigPiSound}|elmer|hammer|элмер|ельмер|елмер)|^a\\s+radio)`;
    return text.match(new RegExp(`${invocation}(?:\\s+|[,:，：]\\s*|$)(.*)$`, 'i'));
  }

  function canonicalWakeDisplay(text) {
    const matched = wakeMatch(String(text || '').trim());
    if (!matched) return String(text || '').trim();
    const commandText = normalizeWakeRemainder(matched[1]);
    return commandText ? `Hey Radio ${commandText}` : 'Hey Radio';
  }

  function wakeFragmentOnly(text) {
    return /^(?:pi|pie|p|by|bee|bay)$/i.test(String(text || '').trim());
  }

  function normalizeWakeRemainder(text) {
    let remainder = String(text || '').trim();
    // Safari can finalize "Rigby" and emit the last syllable as a new result,
    // or leave it at the front of the actual command.
    remainder = remainder.replace(/^(?:pi|pie|p|by|bee|bay)(?:[\s,:，：-]+|$)/i, '');
    return normalizeVoiceText(remainder).trim();
  }

  function startRecognition(mode) {
    if (!Recognition) return;
    stopRecognition();
    const current = new Recognition();
    recognition = current;
    recognitionMode = mode;
    current.lang = localStorage.getItem('elmerVoiceLanguage') || navigator.language || 'en-US';
    current.continuous = mode === 'wake';
    current.interimResults = true;
    current.maxAlternatives = 5;
    // Do not set the experimental SpeechRecognition.phrases property here.
    // Some Chrome recognition services expose it but terminate at runtime
    // with "phrases-not-supported". Transcript normalization below provides
    // a portable fallback across Safari and Chrome.
    current.onstart = () => {
      if (recognition !== current) return;
      if (mode === 'ask') { mic.classList.add('active'); setStatus('Listening for a command…'); }
      else setStatus(awaitingCommand ? 'Listening for your command…' : 'Say “Hey Radio” followed by a command.');
    };
    current.onresult = event => {
      if (recognition !== current) return;
      let finalText = '', interimText = '', finalNeedsReview = false;
      for (let index = event.resultIndex; index < event.results.length; index += 1) {
        const selected = bestRecognitionAlternative(event.results[index]);
        if (event.results[index].isFinal) {
          finalText += `${selected.text} `;
          finalNeedsReview = finalNeedsReview || selected.needsReview;
        } else interimText += `${selected.text} `;
      }
      const heard = (finalText || interimText).trim();
      if (mode === 'ask') {
        if (heard) command.value = heard;
        if (finalText.trim()) submitTranscript(finalText, finalNeedsReview);
        return;
      }
      if (interimText.trim()) {
        if (awaitingCommand && wakeFragmentOnly(interimText))
          setStatus('Heard: “Hey Radio.” I’m listening. What should I do?');
        else setStatus(`Heard: ${canonicalWakeDisplay(interimText)}`);
      }
      const final = finalText.trim();
      if (!final) return;
      const matched = wakeMatch(final);
      if (matched) {
        const remainder = normalizeWakeRemainder(matched[1]);
        if (remainder) { awaitingCommand = false; submitTranscript(remainder, finalNeedsReview); }
        else {
          awaitingCommand = true;
          setStatus('Heard: “Hey Radio.” I’m listening. What should I do?');
        }
      } else if (awaitingCommand) {
        if (wakeFragmentOnly(final)) {
          setStatus('Heard: “Hey Radio.” I’m listening. What should I do?');
          return;
        }
        awaitingCommand = false;
        submitTranscript(final, finalNeedsReview);
      } else setStatus('Waiting for “Hey Radio”…');
    };
    current.onerror = event => {
      if (recognition !== current) return;
      if (!['no-speech', 'aborted'].includes(event.error)) setStatus(`Voice recognition stopped (${event.error}).`);
      if (['not-allowed', 'service-not-allowed'].includes(event.error)) {
        wakeEnabled = false;
        wake.classList.remove('active');
        wake.setAttribute('aria-pressed', 'false');
      }
    };
    current.onend = () => {
      if (recognition === current) { recognition = null; recognitionMode = ''; }
      mic.classList.remove('active');
      if (mode === 'wake') scheduleWake();
    };
    try { current.start(); } catch (_) { recognition = null; recognitionMode = ''; scheduleWake(); }
  }

  mic.addEventListener('pointerdown', () => {
    if (commandRecordingStarting || commandRecorder || commandStream || transcriptionPending) return;
    commandStartedOnPointerDown = true;
    startCommandRecording();
  });
  mic.addEventListener('pointercancel', () => { commandStartedOnPointerDown = false; });
  mic.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    if (commandStartedOnPointerDown) {
      commandStartedOnPointerDown = false;
      return;
    }
    if (commandRecordingStarting) {
      commandRecordingCancelRequested = true;
      setStatus('Stopping the microphone…');
      return;
    }
    if (commandRecorder && commandRecorder.state !== 'inactive') {
      stopCommandRecording();
      return;
    }
    startCommandRecording();
  });
  if (wake) wake.addEventListener('click', () => {
    wakeEnabled = !wakeEnabled;
    awaitingCommand = false;
    wake.classList.toggle('active', wakeEnabled);
    wake.setAttribute('aria-pressed', wakeEnabled ? 'true' : 'false');
    wake.title = wakeEnabled ? 'Turn off Hey Radio listening' : 'Turn on Hey Radio listening';
    if (wakeEnabled) startRecognition('wake');
    else { stopRecognition(); setStatus('Hey Radio listening is off.'); }
  });
  speak.addEventListener('click', () => {
    const active = !speak.classList.contains('active');
    speak.classList.toggle('active', active);
    speak.setAttribute('aria-pressed', active ? 'true' : 'false');
    speak.title = active ? 'Turn off spoken Elmer results' : 'Read Elmer results aloud';
    localStorage.setItem('elmerControlSpeak', active ? '1' : '0');
    if (active) {
      setStatus('Spoken Elmer results are on.');
      const currentResult = String(result.textContent || '').trim();
      speakResult(currentResult || 'Elmer voice is on.');
    } else {
      window.clearTimeout(speechStartTimer);
      speechStartTimer = 0;
      speechSequence += 1;
      stopSpokenPlayback();
      if (pendingVoiceConfirmation && !pendingVoiceConfirmation.timer) {
        setStatus('Waiting for approval · expires in 20 seconds');
        pendingVoiceConfirmation.timer = window.setTimeout(
          () => settleOperatorConfirmation(false, 'expired'), 20000);
      } else if (!pendingVoiceConfirmation) setStatus('Spoken Elmer results are off.');
    }
  });
  speak.classList.toggle('active', canSpeak && localStorage.getItem('elmerControlSpeak') === '1');
  speak.disabled = !canSpeak;
  mic.disabled = !navigator.mediaDevices?.getUserMedia || !window.MediaRecorder;
  if (wake) wake.disabled = !Recognition;

  const transferred = localStorage.getItem('elmerPendingControlQuestion');
  if (transferred) {
    localStorage.removeItem('elmerPendingControlQuestion');
    setCollapsed(false);
    command.value = transferred;
    showResult('This command came from Ask Elmer. Review it, then select <strong>Run</strong>.', 'warning');
    command.focus();
  }
})();
