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
  const status = panel.querySelector('#elmerControlStatus');
  const result = panel.querySelector('#elmerControlResult');
  const history = panel.querySelector('#elmerControlHistory');
  const historyList = panel.querySelector('#elmerControlHistoryList');
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const canSpeak = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  let recognition = null;
  let recognitionMode = '';
  let wakeEnabled = false;
  let awaitingCommand = false;
  let restartTimer = 0;
  let shortwaveScanActive = false;
  let shortwaveScanPaused = false;
  const commandHistoryKey = 'elmerControlCommandHistory';
  let commandHistory = [];
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

  const setStatus = text => { status.textContent = text || ''; };
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

  function speakResult(text) {
    if (!canSpeak || !speak.classList.contains('active') || !text) return;
    speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = localStorage.getItem('elmerVoiceLanguage') || navigator.language || 'en-US';
    utterance.onend = scheduleWake;
    utterance.onerror = scheduleWake;
    stopRecognition();
    speechSynthesis.speak(utterance);
  }

  function setCollapsed(collapsed) {
    panel.classList.toggle('collapsed', collapsed);
    document.body.classList.toggle('elmer-control-open', !collapsed);
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.title = collapsed ? 'Open Elmer Control' : 'Close Elmer Control';
    toggle.setAttribute('aria-label', toggle.title);
    toggle.innerHTML = collapsed ? '<i class="fas fa-chevron-left"></i>' : '<i class="fas fa-chevron-right"></i>';
    localStorage.setItem('elmerControlCollapsed', collapsed ? '1' : '0');
  }

  function positionPanel() {
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
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
      .replace(/\b(change|set)(\s+the)?\s+mood\s+to\b/ig, '$1$2 mode to');
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
      /^(?:tune|set|change|switch|select|go)(?:\s+(?:radio|receiver)\s*([1-4]))?(?:\s+to)?\s+(?:the\s+)?(160|80|60|40|30|20|17|15|12|10|6|2)\s*(?:m|meters?|metres?)(?:\s+band)?(?:\s+(?:to|in|on|for|using|at))?\s+(USB|LSB|SSB|CW|CWR|FT\s*-?\s*8|FT\s*-?\s*4)\s*$/i
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
    const stationFrequencyMatch = clean.match(
      /^(?:what(?:'s|\s+is)\s+|find\s+|show(?:\s+me)?\s+)?(.{2,60}?)\s+(?:frequency|freq)$/i
    );
    const tuneStationMatch = clean.match(
      /^(?:tune|go)(?:\s+me)?(?:\s+back)?\s+to\s+([a-z][a-z0-9 .&'’-]{1,59})$/i
    );
    const stationQuery = String(stationFrequencyMatch?.[1] || tuneStationMatch?.[1] || '').trim();
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
    if (!window.confirm(`Allow this receive-only HackRF change${description ? ` to ${description}` : ''}?`)) {
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
    if (!data.owner_approved && !window.confirm(data.confirmation || 'Allow this receive-only radio change?')) {
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
      speakResult('The radio change was cancelled.');
      return;
    }
    if (outcome.action === 'receiver.split_status') {
      command.value = '';
      if (!outcome.split_on) {
        showResult(`<strong>Split is off.</strong> Radio ${escapeHtml(outcome.radio)} is receiving on <strong>${escapeHtml((Number(outcome.frequency_hz) / 1000000).toFixed(6))} MHz</strong>.`, 'success');
        speakResult(`Split is off on radio ${outcome.radio}.`);
      } else {
        const rx = (Number(outcome.frequency_hz) / 1000000).toFixed(6);
        const tx = (Number(outcome.split_frequency_hz) / 1000000).toFixed(6);
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
        const rx = (Number(outcome.receive_frequency_hz) / 1000000).toFixed(6);
        const tx = (Number(outcome.verified_split_frequency_hz) / 1000000).toFixed(6);
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
      speakResult(`Changed and verified. Radio ${outcome.radio} ${labels[levelName] || levelName} is ${percent} percent.`);
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
        const mhz = (Number(outcome.verified_frequency_hz) / 1000000).toFixed(6);
        const mode = String(outcome.verified_mode || '');
        const width = Number(outcome.verified_bandwidth_hz || 0);
        const inKeyer = synchronizeKeyerCw(true, outcome.cw_text);
        showResult(`<strong>Compound macros completed safely.</strong> Radio ${escapeHtml(outcome.radio)} was verified at <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>${width > 0 ? `, <strong>${escapeHtml(width.toLocaleString())} Hz bandwidth</strong>` : ''}. Then <strong>${escapeHtml(outcome.cw_text)}</strong> was staged with Hold on. Elmer did not transmit.${inKeyer ? '<br>Review the CW to be Sent box and release Hold manually when ready.' : ''}`, 'success');
        speakResult('Compound macros completed. The receiver was verified and CW text was staged with hold on.');
      } else {
        const mhz = (Number(outcome.verified_frequency_hz) / 1000000).toFixed(6);
        const mode = String(outcome.verified_mode || '');
        const width = Number(outcome.verified_bandwidth_hz || 0);
        showResult(`<strong>Macro completed and verified.</strong> <strong>${escapeHtml(macroName)}</strong> set Radio ${escapeHtml(outcome.radio)} to <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>${width > 0 ? `, <strong>${escapeHtml(width.toLocaleString())} Hz bandwidth</strong>` : ''}.`, 'success');
        speakResult(`Macro ${macroName} completed and was verified.`);
      }
      return;
    }
    const mhz = (Number(outcome.verified_frequency_hz) / 1000000).toFixed(6);
    const mode = String(outcome.verified_mode || outcome.requested_mode || '');
    const bandwidth = Number(outcome.verified_bandwidth_hz || outcome.requested_bandwidth_hz || 0);
    const bandwidthText = bandwidth > 0 ? `, <strong>${escapeHtml(bandwidth.toLocaleString())} Hz bandwidth</strong>` : '';
    command.value = '';
    showResult(`<strong>Changed and verified.</strong> Radio ${escapeHtml(outcome.radio)} is at <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>${bandwidthText}.`, 'success');
    speakResult(`Changed and verified. Radio ${outcome.radio} is at ${mhz} megahertz ${mode}${bandwidth > 0 ? `, ${bandwidth} hertz bandwidth` : ''}.`);
  }

  function unsupportedGuidance(capability) {
    if (capability === 'power') {
      return 'Elmer Control did not change transmitter power. Use the <strong>Power Out</strong> slider in the main RigPi <strong>Tuner</strong> window, below the macro buttons.';
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

  function runLocalSdrAction(action) {
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
      if (!window.confirm(`Find active frequencies for ${station} and tune the strongest one?`)) {
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
    if (!window.confirm('Scan broadcasts scheduled now and stop on the first detected signal?')) {
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
    try {
      const plan = deterministicControlPlan(clean) || await queryPlan(clean);
      const action = plan.rig_control || {};
      if (action.action === 'receiver.history') {
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
        runLocalSdrAction(action);
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
    if (!wakeEnabled || run.disabled || (canSpeak && speechSynthesis.speaking)) return;
    restartTimer = window.setTimeout(() => startRecognition('wake'), 500);
  }

  function submitTranscript(text, needsReview = false) {
    const clean = String(text || '').trim().replace(/[.?!]+$/, '');
    if (!clean) return;
    command.value = clean;
    stopRecognition();
    // Speech recognition can return a perfectly grammatical but incorrect
    // frequency. Never let a numeric/tune transcript directly change a radio;
    // require the operator to inspect the displayed text and select Run.
    const numericRadioCommand = /^\s*(?:tune|set|change)\b/i.test(clean)
      || /\b(?:frequency|megahertz|mhz|kilohertz|khz|decimal|point|\d)\b/i.test(clean);
    const cwBufferCommand = /\b(?:cw|morse)\b.*\bbuffer\b|\bbuffer\b.*\b(?:cw|morse)\b/i.test(clean);
    if (needsReview || numericRadioCommand || cwBufferCommand) {
      const reason = needsReview
        ? 'The voice transcript was uncertain.'
        : cwBufferCommand
          ? 'Spoken CW buffer text always requires visual review.'
          : 'Spoken frequencies always require visual review.';
      showResult(`<strong>Please check what I heard:</strong> “${escapeHtml(clean)}”<br>${reason} No radio command was sent. Correct it if necessary, then select <strong>Run</strong>.`, 'warning');
      setStatus('Voice frequency awaits operator review. No station change was made.');
      command.focus();
      return;
    }
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

  mic.addEventListener('click', () => {
    if (recognitionMode === 'ask') { stopRecognition(); setStatus('Voice command cancelled.'); scheduleWake(); }
    else startRecognition('ask');
  });
  wake.addEventListener('click', () => {
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
    localStorage.setItem('elmerControlSpeak', active ? '1' : '0');
    if (!active && canSpeak) speechSynthesis.cancel();
  });
  speak.classList.toggle('active', canSpeak && localStorage.getItem('elmerControlSpeak') === '1');
  speak.disabled = !canSpeak;
  if (!Recognition) { mic.disabled = true; wake.disabled = true; }

  const transferred = localStorage.getItem('elmerPendingControlQuestion');
  if (transferred) {
    localStorage.removeItem('elmerPendingControlQuestion');
    setCollapsed(false);
    command.value = transferred;
    showResult('This command came from Ask Elmer. Review it, then select <strong>Run</strong>.', 'warning');
    command.focus();
  }
})();
