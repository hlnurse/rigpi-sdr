(() => {
  'use strict';

  const form = document.querySelector('#elmerForm');
  const question = document.querySelector('#elmerQuestion');
  const askButton = document.querySelector('#elmerAsk');
  const answer = document.querySelector('#elmerAnswer');
  const answerStatus = document.querySelector('#elmerStatus');
  const voiceButton = document.querySelector('#elmerVoiceAsk');
  const wakeButton = document.querySelector('#elmerWake');
  const speakToggle = document.querySelector('#elmerSpeak');
  const stopButton = document.querySelector('#elmerStopSpeech');
  const voiceStatus = document.querySelector('#elmerVoiceStatus');
  const voicePanel = document.querySelector('#elmerVoice');
  if (!form || !question || !askButton || !answer || !voiceButton || !wakeButton ||
      !speakToggle || !stopButton || !voiceStatus || !voicePanel) return;

  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const canSpeak = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  let recognition = null;
  let recognitionMode = '';
  let wakeEnabled = false;
  let awaitingWakeQuestion = false;
  let restartTimer = 0;
  let lastSpokenAnswer = '';
  let compatibilityText = '';
  let plannedLocale = '';

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
    ['da-DK', 'Dansk'], ['fi-FI', 'Suomi'],
    ['it-IT', 'Italiano'], ['nl-NL', 'Nederlands'], ['pt-BR', 'Português (Brasil)'],
    ['pt-PT', 'Português (Portugal)'], ['ja-JP', '日本語'],
    ['zh-CN', '中文（简体）'], ['zh-TW', '中文（繁體）'], ['ko-KR', '한국어']]) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    languageSelect.appendChild(option);
  }
  const savedLanguage = localStorage.getItem('elmerVoiceLanguage') || '';
  if ([...languageSelect.options].some(option => option.value === savedLanguage)) {
    languageSelect.value = savedLanguage;
  }
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

  const setStatus = text => { voiceStatus.textContent = text; };
  const voiceLanguage = () => languageSelect.value || plannedLocale || navigator.language || 'en-US';

  window.addEventListener('elmer-query-plan', event => {
    const locale = String(event.detail?.response_locale || '').trim();
    if (locale) plannedLocale = locale;
  });

  languageSelect.addEventListener('change', () => {
    localStorage.setItem('elmerVoiceLanguage', languageSelect.value);
    setStatus(`Voice language: ${languageSelect.options[languageSelect.selectedIndex].textContent}.`);
    if (wakeEnabled) startRecognition('wake');
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

  function speechService(browser) {
    if (browser === 'Safari') return 'Browser/Apple speech service';
    if (browser === 'Microsoft Edge') return 'Browser/Microsoft Azure speech service';
    if (browser.includes('Chrome')) return 'Browser/Google speech service';
    return 'Browser dependent';
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
    compatibilityText = [title.textContent, ...rows.map(row => `${row.label}: ${row.value}`),
      `Conclusion: ${conclusion}`].join('\n');
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
    stopRecognition();
    checkButton.disabled = true;
    checkButton.textContent = 'Checking…';
    setStatus('Checking microphone access and voice capabilities…');
    const secure = window.isSecureContext;
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;
    let microphone = 'Not available';
    let microphoneState = 'unavailable';
    if (!secure) {
      microphone = 'Requires HTTPS';
    } else if (!navigator.mediaDevices?.getUserMedia) {
      microphone = 'Microphone capture is not supported';
    } else {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({audio: true});
        stream.getTracks().forEach(track => track.stop());
        microphone = 'Permission granted; microphone opened successfully';
        microphoneState = 'ready';
      } catch (error) {
        if (error.name === 'NotAllowedError' || error.name === 'SecurityError') {
          microphone = 'Permission blocked or denied';
        } else if (error.name === 'NotFoundError') {
          microphone = 'No microphone was found';
        } else if (error.name === 'NotReadableError') {
          microphone = 'Microphone is busy or unavailable';
        } else {
          microphone = `Microphone test failed (${error.name || 'unknown error'})`;
        }
      }
    }
    const {browser, device} = browserAndDevice();
    const recognitionReady = secure && Boolean(Recognition);
    const rows = [
      {label: 'Connection', value: secure ? 'Secure HTTPS context' : 'Not secure; HTTPS is required', state: secure ? 'ready' : 'unavailable'},
      {label: 'Browser', value: browser, state: 'ready'},
      {label: 'Device', value: device, state: 'ready'},
      {label: 'Microphone', value: microphone, state: microphoneState},
      {label: 'Voice recognition', value: recognitionReady ? 'Speech Recognition API is available' : 'Speech Recognition API is unavailable', state: recognitionReady ? 'ready' : 'unavailable'},
      {label: 'Spoken answers', value: canSpeak ? 'Speech synthesis is available' : 'Speech synthesis is unavailable', state: canSpeak ? 'ready' : 'unavailable'},
      {label: 'Page mode', value: standalone ? 'Installed/home-screen mode' : 'Normal browser tab', state: standalone ? 'limited' : 'ready'},
      {label: 'Page visibility', value: document.visibilityState === 'visible' ? 'Visible; wake listening can run' : 'Hidden; wake listening is suspended', state: document.visibilityState === 'visible' ? 'ready' : 'limited'},
      {label: 'Recognition service', value: speechService(browser), state: 'limited'},
      {label: 'Voice language', value: `${languageSelect.options[languageSelect.selectedIndex].textContent} (${voiceLanguage()})`, state: 'ready'},
    ];
    let conclusion = 'Voice input is unavailable here; typed questions and spoken answers may still be used.';
    let conclusionState = 'unavailable';
    if (recognitionReady && microphoneState === 'ready') {
      conclusion = standalone ?
        'Voice is available, but wake listening may be limited in installed/home-screen mode.' :
        'Ready for Ask by voice and Hey Elmer while this page remains visible.';
      conclusionState = standalone ? 'limited' : 'ready';
    } else if (recognitionReady) {
      conclusion = 'Voice recognition is available, but the microphone must be enabled before Hey Elmer can listen.';
      conclusionState = 'limited';
    }
    renderCompatibility(rows, conclusion, conclusionState);
    checkButton.disabled = false;
    checkButton.textContent = 'Check again';
    setStatus(conclusion);
    scheduleWakeRestart();
  }
  const cancelRestart = () => {
    window.clearTimeout(restartTimer);
    restartTimer = 0;
  };

  function stopRecognition() {
    cancelRestart();
    if (!recognition) return;
    const active = recognition;
    recognition = null;
    recognitionMode = '';
    try { active.abort(); } catch (_) {}
    voiceButton.classList.remove('listening');
  }

  function scheduleWakeRestart() {
    cancelRestart();
    if (!wakeEnabled || askButton.disabled || (canSpeak && speechSynthesis.speaking)) return;
    restartTimer = window.setTimeout(() => startRecognition('wake'), 450);
  }

  function submitTranscript(text) {
    const cleaned = text.trim().replace(/[.?!]+$/, '');
    if (!cleaned) return;
    question.value = cleaned;
    question.dispatchEvent(new Event('input', {bubbles: true}));
    setStatus(`You asked: “${cleaned}”`);
    stopRecognition();
    form.requestSubmit();
  }

  function recognitionMessage(error) {
    if (error === 'not-allowed' || error === 'service-not-allowed') {
      return 'Microphone access was blocked. Allow microphone access for RigPi in your browser settings.';
    }
    if (error === 'audio-capture') return 'No working microphone was found.';
    if (error === 'network') return 'The browser’s speech service is unavailable.';
    return `Voice recognition stopped${error ? ` (${error})` : ''}.`;
  }

  function startRecognition(mode) {
    if (!Recognition) return;
    stopRecognition();
    recognitionMode = mode;
    const current = new Recognition();
    recognition = current;
    current.lang = voiceLanguage();
    current.continuous = mode === 'wake';
    current.interimResults = true;
    current.maxAlternatives = 1;

    current.onstart = () => {
      if (recognition !== current) return;
      if (mode === 'ask') {
        voiceButton.classList.add('listening');
        voiceButton.textContent = 'Listening…';
        setStatus('Speak your question.');
      } else {
        setStatus(awaitingWakeQuestion ? 'I’m listening for your question…' : 'Say “Hey Elmer” followed by your question.');
      }
    };

    current.onresult = event => {
      if (recognition !== current) return;
      let finalText = '';
      let interimText = '';
      for (let index = event.resultIndex; index < event.results.length; index += 1) {
        const text = event.results[index][0].transcript.trim();
        if (event.results[index].isFinal) finalText += `${text} `;
        else interimText += `${text} `;
      }
      const heard = (finalText || interimText).trim();
      if (mode === 'ask') {
        if (heard) {
          question.value = heard;
          question.dispatchEvent(new Event('input', {bubbles: true}));
        }
        if (finalText.trim()) submitTranscript(finalText);
        return;
      }
      if (interimText.trim()) setStatus(`Heard: ${interimText.trim()}`);
      const final = finalText.trim();
      if (!final) return;
      const wake = final.match(/(?:^|\s)(?:hey|hé|salut|bonjour|dis|hola|oye|hallo|hej|hei|ciao|olá|oi|cześć|halo|ahoj|moi|привет|эй|здравствуй|привіт|гей|слухай|你好|嗨|こんにちは|ねえ|안녕)\s*(?:elmer|элмер|ельмер|елмер)(?:\s|[,:，：])*(.*)$/i);
      if (wake) {
        const remainder = wake[1].trim();
        if (remainder) {
          awaitingWakeQuestion = false;
          submitTranscript(remainder);
        } else {
          awaitingWakeQuestion = true;
          setStatus('I’m listening. What would you like help with?');
        }
      } else if (awaitingWakeQuestion) {
        awaitingWakeQuestion = false;
        submitTranscript(final);
      } else {
        setStatus('Waiting for “Hey Elmer”…');
      }
    };

    current.onerror = event => {
      if (recognition !== current) return;
      if (event.error !== 'no-speech' && event.error !== 'aborted') {
        setStatus(recognitionMessage(event.error));
      }
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        wakeEnabled = false;
        wakeButton.classList.remove('wake-on');
        wakeButton.textContent = 'Hey Elmer: Off';
      }
    };

    current.onend = () => {
      if (recognition === current) {
        recognition = null;
        recognitionMode = '';
      }
      if (mode === 'ask') {
        voiceButton.classList.remove('listening');
        voiceButton.textContent = 'Ask by voice';
      }
      if (mode === 'wake') scheduleWakeRestart();
    };

    try {
      current.start();
    } catch (_) {
      recognition = null;
      recognitionMode = '';
      scheduleWakeRestart();
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
    return clone.textContent
      .replace(/https?:\/\/\S+/g, '')
      .replace(/\[(?:Live\s+FCC\s+|Live\s+)?\d+\]/gi, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function finishSpeech() {
    stopButton.hidden = true;
    scheduleWakeRestart();
  }

  function speakAnswer(text) {
    if (!canSpeak || !speakToggle.checked || !text) {
      scheduleWakeRestart();
      return;
    }
    stopRecognition();
    speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = voiceLanguage();
    const voices = speechSynthesis.getVoices();
    const wanted = utterance.lang.toLowerCase();
    utterance.voice = voices.find(voice => voice.lang.toLowerCase() === wanted) ||
      voices.find(voice => voice.lang.toLowerCase().startsWith(wanted.split('-')[0])) || null;
    utterance.rate = 1;
    utterance.onend = finishSpeech;
    utterance.onerror = finishSpeech;
    stopButton.hidden = false;
    setStatus('Elmer is reading the answer aloud.');
    speechSynthesis.speak(utterance);
  }

  voiceButton.addEventListener('click', () => {
    if (recognitionMode === 'ask') {
      stopRecognition();
      voiceButton.textContent = 'Ask by voice';
      setStatus('Voice question cancelled.');
      scheduleWakeRestart();
      return;
    }
    startRecognition('ask');
  });

  wakeButton.addEventListener('click', () => {
    wakeEnabled = !wakeEnabled;
    awaitingWakeQuestion = false;
    wakeButton.classList.toggle('wake-on', wakeEnabled);
    wakeButton.textContent = `Hey Elmer: ${wakeEnabled ? 'On' : 'Off'}`;
    wakeButton.setAttribute('aria-pressed', wakeEnabled ? 'true' : 'false');
    if (wakeEnabled) {
      setStatus('Starting the microphone… Say “Hey Elmer” followed by your question.');
      startRecognition('wake');
    }
    else {
      stopRecognition();
      setStatus('Hey Elmer listening is off.');
    }
  });

  speakToggle.checked = canSpeak && localStorage.getItem('elmerSpeakAnswers') === '1';
  speakToggle.disabled = !canSpeak;
  speakToggle.addEventListener('change', () => {
    localStorage.setItem('elmerSpeakAnswers', speakToggle.checked ? '1' : '0');
    if (!speakToggle.checked && canSpeak) {
      speechSynthesis.cancel();
      finishSpeech();
    }
  });

  stopButton.addEventListener('click', () => {
    if (canSpeak) speechSynthesis.cancel();
    finishSpeech();
    setStatus('Spoken answer stopped.');
  });

  checkButton.addEventListener('click', runCompatibilityCheck);

  form.addEventListener('submit', () => {
    stopRecognition();
    if (canSpeak) speechSynthesis.cancel();
    stopButton.hidden = true;
  });

  new MutationObserver(() => {
    if (answer.classList.contains('streaming') || answerStatus.textContent.trim()) return;
    const text = answerForSpeech();
    if (!text || text === lastSpokenAnswer) return;
    lastSpokenAnswer = text;
    speakAnswer(text);
  }).observe(answer, {attributes: true, childList: true, subtree: true});

  new MutationObserver(() => {
    if (!askButton.disabled && wakeEnabled && !recognition &&
        !(canSpeak && speechSynthesis.speaking)) scheduleWakeRestart();
  }).observe(askButton, {attributes: true, attributeFilter: ['disabled']});

  if (!Recognition) {
    voiceButton.disabled = true;
    wakeButton.disabled = true;
    setStatus('Voice recognition is not available in this browser. Spoken answers may still be used.');
  }
})();
