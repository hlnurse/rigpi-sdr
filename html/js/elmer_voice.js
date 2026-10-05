(() => {
  'use strict';

  const form = document.querySelector('#elmerForm');
  const question = document.querySelector('#elmerQuestion');
  const askButton = document.querySelector('#elmerAsk');
  const answer = document.querySelector('#elmerAnswer');
  const answerStatus = document.querySelector('#elmerStatus');
  const voiceButton = document.querySelector('#elmerVoiceAsk');
  const voiceOrb = document.querySelector('#elmerAskVoiceOrb');
  const wakeButton = document.querySelector('#elmerWake');
  const speakToggle = document.querySelector('#elmerSpeak');
  const stopButton = document.querySelector('#elmerStopSpeech');
  const voiceStatus = document.querySelector('#elmerVoiceStatus');
  const voicePanel = document.querySelector('#elmerVoice');
  if (!form || !question || !askButton || !answer || !voiceButton ||
      !speakToggle || !stopButton || !voiceStatus || !voicePanel) return;

  const canRecord = Boolean(window.isSecureContext && navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
  const canBrowserSpeak = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  const canSpeak = typeof window.Audio === 'function' || canBrowserSpeak;
  let recorder = null;
  let microphoneStream = null;
  let recordingChunks = [];
  let recordingStarted = 0;
  let recordingTimer = 0;
  let recordingStarting = false;
  let recordingCancelRequested = false;
  let discardRecording = false;
  let transcriptionPending = false;
  let lastSpokenAnswer = '';
  let compatibilityText = '';
  let plannedLocale = '';
  const speechAudio = typeof window.Audio === 'function' ? new Audio() : null;
  let speechAudioUrl = '';
  let speechRequest = null;
  let speechSequence = 0;
  let voiceOrbSpeaking = false;
  let generatedSpeechVoice = 'marin';

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

  if (wakeButton) wakeButton.hidden = true;

  const languageLabel = document.createElement('label');
  languageLabel.className = 'elmer-voice-language';
  languageLabel.textContent = 'Voice language ';
  const languageSelect = document.createElement('select');
  languageSelect.className = 'form-control form-control-sm';
  languageSelect.id = 'elmerVoiceLanguage';
  for (const [value, label] of [
    ['', 'Automatic'], ['en-US', 'English (US)'], ['en-GB', 'English (UK)'],
    ['fr-FR', 'Français (France)'], ['fr-CA', 'Français (Canada)'],
    ['es-ES', 'Español'], ['de-DE', 'Deutsch'], ['sv-SE', 'Svenska'],
    ['ru-RU', 'Русский'], ['uk-UA', 'Українська'],
    ['pl-PL', 'Polski'], ['cs-CZ', 'Čeština'], ['nb-NO', 'Norsk bokmål'],
    ['da-DK', 'Dansk'], ['fi-FI', 'Suomi'], ['it-IT', 'Italiano'],
    ['nl-NL', 'Nederlands'], ['pt-BR', 'Português (Brasil)'],
    ['pt-PT', 'Português (Portugal)'], ['ja-JP', '日本語'],
    ['zh-CN', '中文（简体）'], ['zh-TW', '中文（繁體）'], ['ko-KR', '한국어']]) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    languageSelect.appendChild(option);
  }
  const savedLanguage = localStorage.getItem('elmerVoiceLanguage') || '';
  if ([...languageSelect.options].some(option => option.value === savedLanguage)) languageSelect.value = savedLanguage;
  languageLabel.appendChild(languageSelect);
  voicePanel.insertBefore(languageLabel, voiceStatus);

  const checkButton = document.createElement('button');
  checkButton.className = 'btn btn-outline-secondary btn-sm';
  checkButton.id = 'elmerVoiceCheck';
  checkButton.type = 'button';
  checkButton.textContent = 'Check voice compatibility';
  voicePanel.insertBefore(checkButton, voiceStatus);

  const report = document.createElement('section');
  report.className = 'elmer-voice-report';
  report.id = 'elmerVoiceReport';
  report.hidden = true;
  report.setAttribute('aria-live', 'polite');
  voicePanel.insertAdjacentElement('afterend', report);

  function refreshVoiceOrb() {
    if (!voiceOrb) return;
    const voiceMessage = String(voiceStatus.textContent || '').toLowerCase();
    const answerMessage = String(answerStatus?.textContent || '').trim();
    let state = 'ready';
    if (voiceOrbSpeaking) state = 'speaking';
    else if (recordingStarting || recorder || microphoneStream || /listening|microphone|recording/.test(voiceMessage)) state = 'listening';
    else if (transcriptionPending || answer.classList.contains('streaming') || answerMessage || /transcrib|checking|reading/.test(voiceMessage)) state = 'thinking';
    voiceOrb.dataset.state = state;
    voiceOrb.title = state === 'listening' ? 'Ask Elmer is listening'
      : state === 'thinking' ? 'Ask Elmer is working'
      : state === 'speaking' ? 'Ask Elmer is speaking'
      : 'Ask Elmer voice is ready';
  }

  const setStatus = text => {
    voiceStatus.textContent = text;
    refreshVoiceOrb();
  };
  const voiceLanguage = () => languageSelect.value || plannedLocale || navigator.language || 'en-US';
  // An empty selector value means true automatic detection. Do not replace it
  // with the browser locale when sending audio to the transcription service.
  const transcriptionLanguage = () => languageSelect.value ? languageSelect.value.split('-')[0] : '';

  function setVoiceButton(active, label) {
    voiceButton.classList.toggle('listening', active);
    voiceButton.setAttribute('aria-pressed', active ? 'true' : 'false');
    const caption = voiceButton.querySelector('span');
    if (caption) caption.textContent = label;
    else voiceButton.textContent = label;
    refreshVoiceOrb();
  }

  window.addEventListener('elmer-query-plan', event => {
    const locale = String(event.detail?.response_locale || '').trim();
    if (locale) plannedLocale = locale;
  });

  languageSelect.addEventListener('change', () => {
    localStorage.setItem('elmerVoiceLanguage', languageSelect.value);
    setStatus(languageSelect.value
      ? `Voice language: ${languageSelect.options[languageSelect.selectedIndex].textContent}.`
      : 'Voice language: Automatic detection.');
  });

  function browserAndDevice() {
    const ua = navigator.userAgent;
    let browser = 'Other browser';
    if (/Edg\//.test(ua)) browser = 'Microsoft Edge';
    else if (/CriOS\//.test(ua)) browser = 'Chrome on iOS/iPadOS';
    else if (/FxiOS\//.test(ua)) browser = 'Firefox on iOS/iPadOS';
    else if (/Chrome\//.test(ua)) browser = 'Google Chrome';
    else if (/Firefox\//.test(ua)) browser = 'Mozilla Firefox';
    else if (/Safari\//.test(ua)) browser = 'Safari';
    let device = navigator.userAgentData?.platform || navigator.platform || 'Unknown platform';
    if (/iPad/.test(ua) || (device === 'MacIntel' && navigator.maxTouchPoints > 1)) device = 'iPadOS';
    else if (/iPhone/.test(ua)) device = 'iPhone';
    else if (/Android/.test(ua)) device = 'Android';
    else if (/Windows/.test(ua)) device = 'Windows';
    else if (/Mac/.test(ua)) device = 'macOS';
    return {browser, device};
  }

  function renderCompatibility(rows, conclusion, conclusionState) {
    report.replaceChildren();
    const head = document.createElement('div');
    head.className = 'elmer-voice-report-head';
    const title = document.createElement('strong');
    title.textContent = 'Ask Elmer voice compatibility';
    const copy = document.createElement('button');
    copy.className = 'btn btn-outline-secondary btn-sm';
    copy.type = 'button';
    copy.textContent = 'Copy report';
    head.append(title, copy);
    const list = document.createElement('dl');
    for (const row of rows) {
      const term = document.createElement('dt');
      const description = document.createElement('dd');
      term.textContent = row.label;
      description.textContent = row.value;
      description.className = `elmer-voice-state-${row.state}`;
      list.append(term, description);
    }
    const summary = document.createElement('p');
    summary.className = `elmer-voice-conclusion elmer-voice-state-${conclusionState}`;
    summary.textContent = conclusion;
    report.append(head, list, summary);
    report.hidden = false;
    compatibilityText = [title.textContent, ...rows.map(row => `${row.label}: ${row.value}`), `Conclusion: ${conclusion}`].join('\n');
    copy.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(compatibilityText);
      } catch (_) {
        const helper = document.createElement('textarea');
        helper.value = compatibilityText;
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        helper.remove();
      }
      copy.textContent = 'Copied';
    });
  }

  async function runCompatibilityCheck() {
    checkButton.disabled = true;
    checkButton.textContent = 'Checking…';
    setStatus('Checking microphone access and voice capabilities…');
    const secure = window.isSecureContext;
    let microphone = 'Not available';
    let microphoneState = 'unavailable';
    if (!secure) microphone = 'Requires HTTPS';
    else if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) microphone = 'Microphone recording is not supported';
    else {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({audio: true, video: false});
        stream.getTracks().forEach(track => track.stop());
        microphone = 'Permission granted; microphone opened successfully';
        microphoneState = 'ready';
      } catch (error) {
        if (error.name === 'NotAllowedError' || error.name === 'SecurityError') microphone = 'Permission blocked or denied';
        else if (error.name === 'NotFoundError') microphone = 'No microphone was found';
        else if (error.name === 'NotReadableError') microphone = 'Microphone is busy or unavailable';
        else microphone = `Microphone test failed (${error.name || 'unknown error'})`;
      }
    }
    const {browser, device} = browserAndDevice();
    const rows = [
      {label: 'Connection', value: secure ? 'Secure HTTPS context' : 'Not secure; HTTPS is required', state: secure ? 'ready' : 'unavailable'},
      {label: 'Browser', value: browser, state: 'ready'},
      {label: 'Device', value: device, state: 'ready'},
      {label: 'Microphone', value: microphone, state: microphoneState},
      {label: 'Voice recognition', value: canRecord ? 'ChatGPT Transcribe recording is supported' : 'Microphone recording is unavailable', state: canRecord ? 'ready' : 'unavailable'},
      {label: 'Spoken answers', value: canSpeak ? 'Speech synthesis is available' : 'Speech synthesis is unavailable', state: canSpeak ? 'ready' : 'unavailable'},
      {label: 'Voice language', value: languageSelect.value
        ? `${languageSelect.options[languageSelect.selectedIndex].textContent} (${voiceLanguage()})`
        : 'Automatic detection', state: 'ready'}
    ];
    const ready = canRecord && microphoneState === 'ready';
    const conclusion = ready
      ? 'Ready. Tap Elmer PTT, ask a question, then tap again to transcribe and submit it.'
      : 'Voice input is unavailable here; typed questions and spoken answers may still be used.';
    renderCompatibility(rows, conclusion, ready ? 'ready' : 'unavailable');
    checkButton.disabled = false;
    checkButton.textContent = 'Check again';
    setStatus(conclusion);
  }

  function preferredRecordingType() {
    const choices = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'];
    return choices.find(type => !MediaRecorder.isTypeSupported || MediaRecorder.isTypeSupported(type)) || '';
  }

  function releaseMicrophone() {
    window.clearTimeout(recordingTimer);
    recordingTimer = 0;
    if (microphoneStream) microphoneStream.getTracks().forEach(track => track.stop());
    microphoneStream = null;
  }

  function blobAsBase64(blob) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('Unable to read the microphone recording.'));
      reader.onload = () => resolve(String(reader.result || '').split(',', 2)[1] || '');
      reader.readAsDataURL(blob);
    });
  }

  function submitTranscript(text) {
    const cleaned = String(text || '').trim().replace(/[.?!]+$/, '');
    if (!cleaned) return;
    question.value = cleaned;
    question.dispatchEvent(new Event('input', {bubbles: true}));
    setStatus(`You asked: “${cleaned}”`);
    form.requestSubmit();
  }

  async function transcribeAudio(blob, durationSeconds) {
    transcriptionPending = true;
    voiceButton.disabled = true;
    setStatus('Transcribing your question…');
    try {
      if (!blob || blob.size < 256) throw new Error('I did not receive enough audio. Please try again.');
      if (blob.size > 1500000) throw new Error('That recording was too long. Please ask a shorter question.');
      const response = await fetch('/elmer-api/transcription', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          audio_base64: await blobAsBase64(blob),
          media_type: blob.type || 'audio/webm',
          language: transcriptionLanguage(),
          duration_seconds: Math.max(0.2, Math.min(21, Number(durationSeconds) || 0.2)),
          session_id: `ask_voice_${Date.now()}_${crypto.randomUUID ? crypto.randomUUID().replaceAll('-', '') : Math.random().toString(36).slice(2)}`
        })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Voice transcription is unavailable.');
      const transcript = String(data.text || '').trim();
      if (!transcript) throw new Error('I did not hear a question. Please try again.');
      submitTranscript(transcript);
    } catch (error) {
      setStatus(error.message || 'Voice transcription failed.');
    } finally {
      transcriptionPending = false;
      voiceButton.disabled = !canRecord;
      setVoiceButton(false, 'Elmer PTT');
    }
  }

  function stopRecording() {
    if (!recorder || recorder.state === 'inactive') return;
    setStatus('Finishing the recording…');
    try { recorder.stop(); } catch (_) { releaseMicrophone(); }
  }

  function cancelRecording() {
    recordingCancelRequested = true;
    discardRecording = true;
    if (recorder && recorder.state !== 'inactive') {
      try { recorder.stop(); } catch (_) { releaseMicrophone(); }
    } else {
      releaseMicrophone();
      recorder = null;
      setVoiceButton(false, 'Elmer PTT');
    }
  }

  async function startRecording() {
    if (!canRecord || transcriptionPending || recordingStarting || recorder || microphoneStream) return;
    recordingStarting = true;
    recordingCancelRequested = false;
    discardRecording = false;
    if (canSpeak) speechSynthesis.cancel();
    try {
      const stream = await navigator.mediaDevices.getUserMedia({audio: true, video: false});
      if (recordingCancelRequested) {
        stream.getTracks().forEach(track => track.stop());
        setVoiceButton(false, 'Elmer PTT');
        setStatus('Voice question cancelled.');
        return;
      }
      microphoneStream = stream;
      recordingChunks = [];
      const type = preferredRecordingType();
      const activeRecorder = type ? new MediaRecorder(stream, {mimeType: type}) : new MediaRecorder(stream);
      recorder = activeRecorder;
      activeRecorder.ondataavailable = event => { if (event.data?.size) recordingChunks.push(event.data); };
      activeRecorder.onerror = () => {
        releaseMicrophone();
        recorder = null;
        setVoiceButton(false, 'Elmer PTT');
        setStatus('The microphone recording stopped unexpectedly.');
      };
      activeRecorder.onstop = () => {
        const durationSeconds = recordingStarted ? (performance.now() - recordingStarted) / 1000 : 0.2;
        const blob = new Blob(recordingChunks, {type: activeRecorder.mimeType || type || 'audio/webm'});
        const shouldDiscard = discardRecording;
        recordingChunks = [];
        recorder = null;
        releaseMicrophone();
        setVoiceButton(false, 'Elmer PTT');
        if (!shouldDiscard) transcribeAudio(blob, durationSeconds);
      };
      activeRecorder.start(250);
      recordingStarted = performance.now();
      setVoiceButton(true, 'Stop & ask');
      setStatus('Listening… tap Elmer PTT again when finished.');
      recordingTimer = window.setTimeout(stopRecording, 20000);
    } catch (error) {
      releaseMicrophone();
      recorder = null;
      setVoiceButton(false, 'Elmer PTT');
      setStatus(error?.name === 'NotAllowedError'
        ? 'Microphone access was not allowed.'
        : `Could not open the microphone${error?.message ? `: ${error.message}` : '.'}`);
    } finally {
      recordingStarting = false;
      recordingCancelRequested = false;
    }
  }

  function answerForSpeech() {
    const clone = answer.cloneNode(true);
    for (const child of [...clone.children]) {
      if (/^(?:references|références|referencias|quellen|referenser|riferimenti|referências|参考文献|参考资料|参考資料|참고문헌)$/i.test(child.textContent.trim())) {
        let node = child;
        while (node) {
          const next = node.nextSibling;
          node.remove();
          node = next;
        }
        break;
      }
    }
    clone.querySelectorAll('figure,script,style').forEach(node => node.remove());
    return clone.textContent.replace(/https?:\/\/\S+/g, '').replace(/\[(?:Live\s+FCC\s+|Live\s+)?\d+\]/gi, '').replace(/\s+/g, ' ').trim();
  }

  function finishSpeech() {
    voiceOrbSpeaking = false;
    stopButton.hidden = true;
    if (voiceStatus.textContent === 'Elmer is reading the answer aloud.') setStatus('');
    else refreshVoiceOrb();
  }

  function stopAnswerSpeech() {
    speechSequence += 1;
    askSpeechSources.forEach(source => { try { source.stop(); } catch (_) {} });
    askSpeechSources.clear();
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
    if (canBrowserSpeak) speechSynthesis.cancel();
    voiceOrbSpeaking = false;
    refreshVoiceOrb();
  }

  function speechChunks(text, maximum = 620) {
    const sentences = String(text || '').match(/[^.!?]+(?:[.!?]+|$)/g) || [String(text || '')];
    const chunks = [];
    // Generate a short opening first; prefetch continues with full-sized chunks.
    const limit = () => chunks.length ? maximum : Math.min(180, maximum);
    let current = '';
    const append = part => {
      const candidate = current ? `${current} ${part}` : part;
      if (candidate.length <= limit()) { current = candidate; return; }
      if (current) chunks.push(current);
      current = '';
      const words = part.split(/\s+/);
      for (const word of words) {
        const next = current ? `${current} ${word}` : word;
        if (next.length > limit() && current) { chunks.push(current); current = word; }
        else current = next;
      }
    };
    sentences.map(sentence => sentence.trim()).filter(Boolean).forEach(append);
    if (current) chunks.push(current);
    return chunks;
  }

  function browserSpeechFallback(text, sequence) {
    if (!canBrowserSpeak || sequence !== speechSequence || !speakToggle.checked || !text) {
      finishSpeech();
      return;
    }
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = voiceLanguage();
    const voices = speechSynthesis.getVoices();
    const wanted = utterance.lang.toLowerCase();
    utterance.voice = voices.find(voice => voice.lang.toLowerCase() === wanted) ||
      voices.find(voice => voice.lang.toLowerCase().startsWith(wanted.split('-')[0])) || null;
    utterance.rate = 1;
    utterance.onend = () => { if (sequence === speechSequence) finishSpeech(); };
    utterance.onerror = () => { if (sequence === speechSequence) finishSpeech(); };
    voiceOrbSpeaking = true;
    refreshVoiceOrb();
    speechSynthesis.speak(utterance);
  }

  let askSpeechContext = null;
  const askSpeechSources = new Set();
  function unlockAskSpeech() {
    try {
      const Context = window.AudioContext || window.webkitAudioContext;
      if (!Context) return;
      if (!askSpeechContext) askSpeechContext = new Context();
      askSpeechContext.resume().catch(() => {});
    } catch (_) {}
  }
  form.addEventListener('pointerdown', unlockAskSpeech, {passive: true});
  form.addEventListener('keydown', unlockAskSpeech);
  voiceButton.addEventListener('click', unlockAskSpeech);
  speakToggle.addEventListener('change', unlockAskSpeech);

  async function streamAskOpening(text, sequence, controller) {
    const context = askSpeechContext;
    if (!context || context.state !== 'running') throw new Error('Audio needs a user gesture.');
    const response = await fetch('/elmer-api/speech', {
      method: 'POST', credentials: 'same-origin', signal: controller.signal,
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(speechPayload({text, stream: true}))
    });
    if (!response.ok || !response.body || !(response.headers.get('Content-Type') || '').includes('audio/pcm'))
      throw new Error('Streaming speech is unavailable.');
    const reader = response.body.getReader();
    let carry = new Uint8Array(0), nextStart = context.currentTime + 0.05;
    let received = 0;
    const active = () => sequence === speechSequence && !controller.signal.aborted && speakToggle.checked;
    try {
      while (true) {
        const {value, done} = await reader.read();
        if (!active()) return;
        if (done) break;
        const bytes = new Uint8Array(carry.length + value.length);
        bytes.set(carry); bytes.set(value, carry.length);
        const usable = bytes.length - bytes.length % 2;
        carry = bytes.slice(usable);
        if (!usable) continue;
        const buffer = context.createBuffer(1, usable / 2, 24000);
        const channel = buffer.getChannelData(0);
        const view = new DataView(bytes.buffer);
        for (let i = 0; i < channel.length; i++) channel[i] = view.getInt16(i * 2, true) / 32768;
        const source = context.createBufferSource();
        source.buffer = buffer; source.connect(context.destination);
        askSpeechSources.add(source);
        source.onended = () => { askSpeechSources.delete(source); source.disconnect(); };
        nextStart = Math.max(nextStart, context.currentTime + 0.05);
        source.start(nextStart); nextStart += buffer.duration;
        received += usable;
        voiceOrbSpeaking = true; refreshVoiceOrb();
      }
      if (!received || carry.length) throw new Error('Incomplete speech stream.');
      while (active() && context.currentTime < nextStart) await new Promise(resolve => setTimeout(resolve, 40));
    } catch (error) {
      // Do not repeat words already played if the connection fails mid-sentence.
      error.openingStarted = received > 16800;
      throw error;
    } finally {
      try { await reader.cancel(); } catch (_) {}
    }
  }

  function speakAnswer(text) {
    if (!canSpeak || !speakToggle.checked || !text) return;
    stopAnswerSpeech();
    const sequence = ++speechSequence;
    const chunks = speechChunks(text);
    const loaded = new Map();
    const controller = new AbortController();
    speechRequest = controller;
    const loadChunk = index => {
      if (index >= chunks.length) return null;
      if (!loaded.has(index)) loaded.set(index, fetch('/elmer-api/speech', {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(speechPayload({text: chunks[index], lead_silence_ms: index === 0 ? 350 : 100}))
      }).then(response => {
        if (!response.ok) throw new Error('Generated speech is unavailable.');
        return response.blob();
      }));
      return loaded.get(index);
    };
    const playChunk = async index => {
      if (sequence !== speechSequence || !speakToggle.checked) return;
      if (index >= chunks.length) { speechRequest = null; finishSpeech(); return; }
      try {
        if (index === 0 && askSpeechContext?.state === 'running') {
          try {
            await streamAskOpening(chunks[0], sequence, controller);
            if (sequence === speechSequence && speakToggle.checked) playChunk(1);
            return;
          } catch (error) {
            if (controller.signal.aborted || sequence !== speechSequence) return;
            askSpeechSources.forEach(source => { try { source.stop(); } catch (_) {} });
            askSpeechSources.clear();
            if (error.openingStarted) {
              stopAnswerSpeech(); finishSpeech();
              setStatus('Speech was interrupted. Please ask again.');
              return;
            }
          }
        }
        const blob = await loadChunk(index);
        if (sequence !== speechSequence || !speakToggle.checked) return;
        loadChunk(index + 1)?.catch(() => {});
        if (!speechAudio) throw new Error('Audio playback is unavailable.');
        if (speechAudioUrl) URL.revokeObjectURL(speechAudioUrl);
        speechAudioUrl = URL.createObjectURL(blob);
        speechAudio.src = speechAudioUrl;
        speechAudio.preload = 'auto';
        speechAudio.playsInline = true;
        speechAudio.onended = () => playChunk(index + 1);
        speechAudio.onerror = () => browserSpeechFallback(chunks.slice(index).join(' '), sequence);
        voiceOrbSpeaking = true;
        refreshVoiceOrb();
        await speechAudio.play();
      } catch (error) {
        if (error?.name !== 'AbortError' && sequence === speechSequence) {
          browserSpeechFallback(chunks.slice(index).join(' '), sequence);
        }
      }
    };
    stopButton.hidden = false;
    setStatus('Elmer is reading the answer aloud.');
    if (askSpeechContext?.state !== 'running') loadChunk(0)?.catch(() => {});
    loadChunk(1)?.catch(() => {});
    playChunk(0);
  }

  voiceButton.addEventListener('click', event => {
    event.preventDefault();
    if (recordingStarting) {
      recordingCancelRequested = true;
      setStatus('Stopping the microphone…');
      return;
    }
    if (recorder && recorder.state !== 'inactive') stopRecording();
    else startRecording();
  });

  const controlSpeakSaved = localStorage.getItem('elmerControlSpeak');
  const speakSaved = controlSpeakSaved === null ? localStorage.getItem('elmerSpeakAnswers') : controlSpeakSaved;
  speakToggle.checked = canSpeak && speakSaved === '1';
  speakToggle.disabled = !canSpeak;
  speakToggle.addEventListener('change', () => {
    const enabled = speakToggle.checked ? '1' : '0';
    localStorage.setItem('elmerSpeakAnswers', enabled);
    localStorage.setItem('elmerControlSpeak', enabled);
    if (!speakToggle.checked && canSpeak) {
      stopAnswerSpeech();
      finishSpeech();
    }
  });

  stopButton.addEventListener('click', () => {
    if (canSpeak) stopAnswerSpeech();
    finishSpeech();
    setStatus('Spoken answer stopped.');
  });

  checkButton.addEventListener('click', runCompatibilityCheck);
  form.addEventListener('submit', () => {
    if (recorder || recordingStarting || microphoneStream) cancelRecording();
    if (canSpeak) stopAnswerSpeech();
    stopButton.hidden = true;
  });

  new MutationObserver(() => {
    refreshVoiceOrb();
    if (answer.classList.contains('streaming') || answerStatus.textContent.trim()) return;
    const text = answerForSpeech();
    if (!text || text === lastSpokenAnswer) return;
    lastSpokenAnswer = text;
    speakAnswer(text);
  }).observe(answer, {attributes: true, childList: true, subtree: true});

  if (answerStatus) new MutationObserver(refreshVoiceOrb).observe(answerStatus, {childList: true, subtree: true});

  voiceButton.disabled = !canRecord;
  if (!canRecord) setStatus('Elmer PTT requires HTTPS and a browser that supports microphone recording. Spoken answers may still be used.');
})();
