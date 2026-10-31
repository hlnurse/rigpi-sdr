/* Mount the existing Elmer Control once after each authenticated page loads. */
(() => {
  'use strict';
  const mount = async () => {
    const template = document.getElementById('elmerControlSharedTemplate');
    if (!template) return;
    if (document.getElementById('elmerControl')) { template.remove(); return; }
    const fragment = template.content;
    const script = fragment.querySelector('script[src]');
    const source = script?.getAttribute('src');
    if (!source || !fragment.querySelector('#elmerControl')) { template.remove(); return; }
    script.remove();
    if (!(Number(window.tMyRadio) > 0)) window.tMyRadio = Number(template.dataset.radio) || 1;
    if (!window.tMyCall) window.tMyCall = template.dataset.call || '';
    if (!window.tUserName) window.tUserName = template.dataset.user || '';
    const styles = ['/css/elmer_control.css?v=20260903-1', '/css/elmer_control_shared.css?v=20261029-1'];
    await Promise.all(styles.map(href => new Promise(resolve => {
      if (Array.from(document.querySelectorAll('link[rel="stylesheet"]')).some(link => link.getAttribute('href') === href)) { resolve(); return; }
      const link = document.createElement('link');
      link.rel = 'stylesheet'; link.href = href;
      link.onload = link.onerror = resolve;
      document.head.appendChild(link);
    })));
    if (document.getElementById('elmerControl')) { template.remove(); return; }
    document.body.classList.add('elmer-control-shared');
    document.body.appendChild(fragment);
    template.remove();
    const control = document.createElement('script');
    control.src = source;
    document.body.appendChild(control);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true});
  else mount();
})();
