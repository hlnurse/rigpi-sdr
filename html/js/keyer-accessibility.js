/* Keyer accessibility metadata only: no keying or control actions. */
(() => {
  'use strict';
  const speed = document.getElementById('sliderSpeed');
  function updateSpeedLabel() {
    if (!speed) return;
    const handle = speed.querySelector('.ui-slider-handle');
    if (!handle) return;
    handle.setAttribute('role', 'slider');
    handle.setAttribute('aria-label', 'Keyer speed');
    handle.setAttribute('aria-orientation', 'horizontal');
    const text = document.getElementById('myKeyerSpeedVal')?.textContent.replace(/\s+/g, ' ').trim();
    if (text) handle.setAttribute('aria-valuetext', text.replace(/WPM/gi, 'words per minute'));
    if (window.jQuery && window.jQuery(speed).hasClass('ui-slider')) {
      for (const [attribute, key] of [['aria-valuenow', 'value'], ['aria-valuemin', 'min'], ['aria-valuemax', 'max']]) {
        const value = key === 'value' ? window.jQuery(speed).slider('value') : window.jQuery(speed).slider('option', key);
        if (Number.isFinite(Number(value))) handle.setAttribute(attribute, String(value));
      }
    }
    const reported = text?.match(/(\d+(?:\.\d+)?)\s*WPM/i);
    if (reported) handle.setAttribute('aria-valuenow', reported[1]);
  }
  updateSpeedLabel();
  const observer = new MutationObserver(updateSpeedLabel);
  if (speed) observer.observe(speed, {childList:true, subtree:true});
  const label = document.getElementById('myKeyerSpeedVal');
  if (label) observer.observe(label, {childList:true, subtree:true, characterData:true});
  for (const type of ['mouseover', 'focusin']) document.addEventListener(type, event => {
    if (event.target?.closest?.('#containerSpeed')) updateSpeedLabel();
  }, {capture:true, passive:true});
  window.addEventListener('pagehide', () => observer.disconnect(), {once:true});
})();
