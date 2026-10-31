/* Tuner shortcut routing across the Tuner, shell and same-origin SDR frames. */
(() => {
  'use strict';
  const bands = {'1':'160','2':'80','3':'60','4':'40','5':'30','6':'20','7':'17','8':'15','9':'12',a:'10',b:'6',c:'2',d:'125',e:'70',g:'23'};
  const modes = {l:'lsb',u:'usb',s:'usbd',w:'cw',r:'cwr',t:'rtty',y:'rttyr',f:'fm',m:'am'};
  const navigation = {t:'tuner',k:'keyer',l:'log',s:'spots',c:'calendar',m:'elmer',a:'askElmer',r:'sdr4',w:'web',e:'settings',h:'help',x:'logout'};
  function actionFor(event) {
    if (event.isComposing || event.metaKey || event.getModifierState?.('AltGraph')) return '';
    const code = event.code || '';
    const letter = /^Key[A-Z]$/.test(code) ? code.slice(3).toLowerCase() : String(event.key || '').toLowerCase();
    const digit = /^(?:Digit|Numpad)[0-9]$/.test(code) ? code.slice(-1) : String(event.key || '');
    if (event.ctrlKey) {
      if (event.altKey || event.shiftKey) return '';
      if (bands[digit] && /^[1-9]$/.test(digit)) return 'button:' + bands[digit] + 'Button';
      if (bands[letter]) return 'button:' + bands[letter] + 'Button';
      return modes[letter] ? 'button:' + modes[letter] + 'Button' : '';
    }
    if (event.altKey) {
      if (event.shiftKey) return letter === 'c' ? 'connect' : '';
      if (/^[1-4]$/.test(digit)) return 'bank:' + digit;
      if (letter === 'd') return 'disconnect';
      if (letter === 'i') return 'split';
      if (letter === 'o') return 'lookup';
      return navigation[letter] ? 'nav:' + navigation[letter] : '';
    }
    if (event.key === '?') return 'shortcuts';
    if (event.key === '+') return 'tune:1';
    if (event.shiftKey) return '';
    if (code === 'BracketLeft' || event.key === '[') return 'band:up';
    if (code === 'BracketRight' || event.key === ']') return 'band:down';
    const keys = {ArrowUp:'tune:1',ArrowDown:'tune:-1',ArrowLeft:'select:1',ArrowRight:'select:-1',Enter:'lookup','/':'focusLookup','-':'tune:-1'};
    if (keys[event.key]) return keys[event.key];
    if (/^F(?:[1-9]|1[0-2])$/.test(event.key)) return 'macro:' + event.key;
    return /^[0-9]$/.test(digit) ? 'digit:' + digit : '';
  }
  function install(options) {
    let controller = null;
    const handle = event => {
      const action = actionFor(event);
      if (!action) return;
      const target = event.target, doc = target?.ownerDocument;
      if (target?.closest?.('#elmerControl') || doc?.activeElement?.closest?.('#elmerControl')) return;
      const editing = element => element?.id !== 'dt' && (element?.isContentEditable || element?.closest?.('input,textarea,select,[contenteditable="true"],[role="textbox"],[role="combobox"],[role="slider"],[role="spinbutton"],.ui-slider-handle'));
      const search = target?.closest?.('#searchText,#rigpiShellSearchText');
      const nativeButton = action === 'lookup' && event.key === 'Enter' && target?.closest?.('button,a[href],summary');
      let nativeSdrArrow = false;
      try { nativeSdrArrow = /^Arrow/.test(event.key) && doc !== options.document && doc !== options.document.defaultView.top.document && Boolean(target?.closest?.('button,[role="button"],[role="tab"]')); } catch (_) {}
      if ((editing(target) || editing(doc?.activeElement) || nativeButton || nativeSdrArrow) && !(action === 'lookup' && search)) {
        // Native and custom editor/widget handlers retain the event.
        return;
      }
      if (doc?.querySelector?.('.modal.show') || options.modalOpen?.()) {
        event.stopImmediatePropagation();
        return;
      }
      if (action.startsWith('digit:') && !options.acceptDigits?.()) return;
      event.preventDefault(); event.stopImmediatePropagation();
      if (event.repeat && !/^(tune|select|digit):/.test(action)) return;
      options.execute(action, event);
    };
    const attach = () => {
      if (controller && !controller.signal.aborted) return;
      controller = new AbortController();
      const documents = new Set(), signal = controller.signal;
      const visit = doc => {
        if (!doc || documents.has(doc)) return;
        documents.add(doc);
        doc.addEventListener('keydown', handle, {capture:true,signal});
        doc.addEventListener('load', event => { if (event.target?.tagName === 'IFRAME') { try { visit(event.target.contentDocument); } catch (_) {} } }, {capture:true,signal});
        for (const frame of doc.querySelectorAll('iframe')) { try { visit(frame.contentDocument); } catch (_) {} }
      };
      try { visit(options.document.defaultView.top.document); } catch (_) {}
      visit(options.document);
    };
    attach();
    options.document.defaultView.addEventListener('pagehide', () => controller?.abort());
    options.document.defaultView.addEventListener('pageshow', attach);
    return {dispose:()=>controller?.abort()};
  }
  window.RigPiTunerShortcuts = {actionFor,install};
})();
