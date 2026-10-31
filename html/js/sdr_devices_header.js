(() => {
  'use strict';
  const configElement = document.getElementById('sdrDevicesHeaderConfig');
  if (!configElement) return;
  const config = JSON.parse(configElement.textContent);
  const dxField = document.getElementById('searchText');
  const lookupButton = document.getElementById('searchButton');
  const logoutButton = document.getElementById('logoutButton');
  const bridge = document.getElementById('elmerSdrFrame');
  const controller = new AbortController();
  let lookupSequence = 0, receiverState = {}, audioInfo = {};

  async function post(path, values) {
    const response = await fetch(path, {method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams(values).toString(), signal: controller.signal});
    if (!response.ok) throw new Error('RigPi request failed.');
    return response.text();
  }
  if (dxField) {
    const initial = dxField.value;
    post('/programs/GetSetting.php', {radio: config.radio, field: 'DX', table: 'MySettings'})
      .then(dx => { if (dxField.value === initial) dxField.value = dx.trim().toUpperCase(); })
      .catch(() => {});
  }
  logoutButton?.addEventListener('click', event => {
    event.preventDefault();
    post('/login.php', {status: 'loggedout'}).catch(() => {}).finally(() => window.location.replace('/login.php'));
  });
  function lookup() {
    const dx = String(dxField?.value || '').trim().toUpperCase();
    if (!dx || dx.includes('*')) return;
    dxField.value = dx;
    const sequence = ++lookupSequence;
    const modal = $('#myModal');
    const body = modal.find('.modal-body');
    const image = modal.find('.modal-pix');
    const failed = () => {
      if (sequence !== lookupSequence) return;
      body.text('Callsign lookup is unavailable. Please try again.');
      image.attr({height: 0, width: 0, src: 'about:blank'});
      modal.find('.modal-title').text(dx); modal.modal('show');
    };
    post('/programs/SetSettings.php', {field: 'DX', radio: config.radio, data: dx, table: 'MySettings'}).catch(() => {});
    $.post('/programs/GetCallbook.php', {call: dx, what: 'QRZData', user: config.userId, un: config.user}, response => {
      if (sequence !== lookupSequence) return;
      body.html(response); modal.find('.modal-title').text(dx);
      image.attr({height: 0, width: 0, src: 'about:blank'});
      modal.modal('show');
      $.post('/programs/GetCallbook.php', {call: dx, what: 'QRZpix', user: config.userId, un: config.user}, picture => {
        if (sequence !== lookupSequence) return;
        const parts = String(picture).split('|');
        const height = Number(parts[1] || 0), width = Number(parts[2] || 0);
        if (height <= 0) return;
        if (parts[3] === 'flag') image.addClass('flag-pix').css({width:'auto','max-width':'150px',margin:'10px auto',display:'block'});
        else {
          const scale = width > 0 ? width / 400 : 1;
          image.removeClass('flag-pix').attr({height:height/scale,width:width/scale}).css({display:'',margin:''});
        }
        image.attr('src', parts[0]);
      }).fail(() => {});
    }).fail(failed);
  }
  lookupButton?.addEventListener('click', event => { event.preventDefault(); lookup(); });
  dxField?.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); lookup(); }
  });
  dxField?.closest('form')?.addEventListener('submit', event => event.preventDefault());

  function installAudioBridge() {
    const owner = bridge?.contentWindow;
    const doc = bridge?.contentDocument;
    if (!owner || !doc?.body) return;
    owner.spectrumWsUrl = () => (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + config.base +
      '/ws/spectrum?user=' + encodeURIComponent(config.user) + '&call=' + encodeURIComponent(config.call);
    owner.currentRxAudioConfig = () => {
      const source = audioInfo.rx_source || localStorage.getItem('sdr_rx_audio_source') || 'sdr';
      const raw = audioInfo.rx_device == null ? localStorage.getItem('sdr_rx_radio_device') : String(audioInfo.rx_device);
      const device = raw == null || raw === '' ? null : Number(raw);
      return {source: source === 'radio' ? 'radio' : 'sdr', device: Number.isFinite(device) ? device : null};
    };
    owner._sdrLastState = receiverState;
    const radioSelect = doc.createElement('select'); radioSelect.id = 'rxRadioDevice';
    for (const input of audioInfo.sources || []) {
      const option = doc.createElement('option'); option.value = String(input.index); option.textContent = input.name;
      radioSelect.appendChild(option);
    }
    const deviceSelect = doc.createElement('select'); deviceSelect.title = 'Select SDR hardware device';
    const device = doc.createElement('option'); device.selected = true;
    device.textContent = receiverState.sdrconn_active ? receiverState.sdrconn_device || 'SDRplay' :
      (receiverState.device_driver === 'fake' ? 'No SDR' : [receiverState.device_driver || 'SDR', receiverState.device_serial || ''].join(' ').trim());
    deviceSelect.appendChild(device);
    doc.body.replaceChildren(radioSelect, deviceSelect);
  }
  bridge?.addEventListener('load', installAudioBridge);
  installAudioBridge();
  Promise.allSettled([
    fetch(config.base + '/api/state?user=' + encodeURIComponent(config.user) + '&radio=' + config.radio,
      {cache:'no-store', signal:controller.signal}).then(response => response.ok ? response.json() : {}),
    fetch(config.base + '/api/audio/devices', {cache:'no-store', signal:controller.signal}).then(response => response.ok ? response.json() : {})
  ]).then(results => {
    if (controller.signal.aborted) return;
    receiverState = results[0].status === 'fulfilled' ? results[0].value : {};
    audioInfo = results[1].status === 'fulfilled' ? results[1].value : {};
    installAudioBridge();
  });
  window.addEventListener('pagehide', () => { lookupSequence += 1; controller.abort(); }, {once:true});
})();
