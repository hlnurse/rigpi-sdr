/*
 * RigPi Open S-Meter 1.1.0
 * Clean-room SVG replacement for the RigPi meter widget.
 * Copyright (c) 2026 RigPi. MIT License.
 */
(function (window, document) {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var STYLE_ID = 'rigpi-smeter-style';
  var THEMES = [
    { face1:'#ff9200', face2:'#ff9200', ink:'#171717', accent:'#df2739', bezel1:'#747b82', bezel2:'#25292d', glass:'#ffffff' },
    { face1:'#28313b', face2:'#0b1015', ink:'#f2f4f5', accent:'#ff4b55', bezel1:'#69717a', bezel2:'#111417', glass:'#ffffff' },
    { face1:'#d9e6c2', face2:'#9eb786', ink:'#172017', accent:'#b51e2d', bezel1:'#69736a', bezel2:'#202520', glass:'#ffffff' },
    { face1:'#ffffff', face2:'#d8d8d8', ink:'#000000', accent:'#d0001b', bezel1:'#3f3f3f', bezel2:'#050505', glass:'#ffffff' },
    { face1:'#d9efb6', face2:'#88b85f', ink:'#10220f', accent:'#bb1e2d', bezel1:'#596657', bezel2:'#172017', glass:'#ffffff' }
  ];

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var METER_SCALE = 1.02;
var METER_X_STRETCH = 1.27;
var METER_PIVOT_Y = 272;

function polar(radius, angle) {
    var radians = angle * Math.PI / 180;
    var scaledRadius = radius * METER_SCALE;
    return { x:350 + scaledRadius * METER_X_STRETCH * Math.cos(radians), y:METER_PIVOT_Y + scaledRadius * Math.sin(radians) };
  }

  function arc(radius, start, end) {
    var a = polar(radius, start), b = polar(radius, end);
    var scaledRadius = radius * METER_SCALE;
  var scaledRadiusX = scaledRadius * METER_X_STRETCH;
    return 'M ' + a.x.toFixed(2) + ' ' + a.y.toFixed(2) +
      ' A ' + scaledRadiusX + ' ' + scaledRadius + ' 0 0 1 ' + b.x.toFixed(2) + ' ' + b.y.toFixed(2);
  }

  function lineAt(radius1, radius2, angle, cssClass) {
    var a = polar(radius1, angle), b = polar(radius2, angle);
    return '<line class="' + cssClass + '" x1="' + a.x.toFixed(2) + '" y1="' + a.y.toFixed(2) +
      '" x2="' + b.x.toFixed(2) + '" y2="' + b.y.toFixed(2) + '"/>';
  }

  function textAt(radius, angle, text, cssClass, yOffset) {
    var p = polar(radius, angle);
    p.y += Number(yOffset) || 0;
    return '<text class="' + cssClass + '" x="' + p.x.toFixed(2) + '" y="' + p.y.toFixed(2) +
      '" text-anchor="middle" dominant-baseline="middle">' + esc(text) + '</text>';
  }

  function scaleRow(id, label, radius, labels, positions, labelRadius, labelAngle) {
    var out = '<g id="sm-scale-' + id + '" class="sm-secondary-scale" clip-path="url(#smFaceClip)">';
    out += '<path class="sm-scale-line" d="' + arc(radius, -138, -42) + '"/>';
    out += textAt(labelRadius || radius + 2, labelAngle || -145, label, 'sm-row-label');
    for (var i=0; i<labels.length; i++) {
      var angle = -138 + 96 * positions[i];
      out += lineAt(radius - 5, radius + 5, angle, 'sm-row-tick');
      out += textAt(radius - 14, angle, labels[i], 'sm-row-value');
    }
    return out + '</g>';
  }

  function installStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent =
      '.rigpi-smeter{display:block;width:100%;height:100%;max-width:100%;max-height:100%;overflow:hidden}' +
      '.rigpi-smeter text{font-family:Arial,Helvetica,sans-serif;fill:var(--sm-ink)}' +
      '.rigpi-smeter .sm-major{stroke:var(--sm-ink);stroke-width:3.8;stroke-linecap:round}' +
      '.rigpi-smeter .sm-minor{stroke:var(--sm-ink);stroke-width:1.5;stroke-linecap:round}' +
      '.rigpi-smeter .sm-scale-line{fill:none;stroke:var(--sm-ink);stroke-width:2.2;opacity:1}' +
      '.rigpi-smeter .sm-row-tick{stroke:var(--sm-ink);stroke-width:2}' +
      '.rigpi-smeter .sm-s-label{font-size:22px;font-weight:700}' +
      '.rigpi-smeter .sm-s-plus{font-size:20px;font-weight:800;fill:var(--sm-accent)}' +
      '.rigpi-smeter .sm-plus60{font-size:18px}' +
      '.rigpi-smeter .sm-row-label{font-size:15px;font-weight:900}' +
      '.rigpi-smeter .sm-row-value{font-size:13px;font-weight:700}' +
      '.rigpi-smeter .sm-secondary-scale{opacity:.94;transition:opacity .16s}' +
      '.rigpi-smeter .sm-secondary-scale.sm-active{opacity:1}' +
      '.rigpi-smeter .sm-function{font-size:20px;font-weight:800;letter-spacing:.35px}' +
      '.rigpi-smeter .sm-needle-shadow{stroke:#000;stroke-width:5;opacity:.25;stroke-linecap:round}' +
      '.rigpi-smeter .sm-needle{stroke:#e11d2e;stroke-width:2.6;stroke-linecap:round}' +
      '.rigpi-smeter .sm-hub{fill:url(#smHub);stroke:#181818;stroke-width:1.5}';
    document.head.appendChild(style);
  }

  function MeterWidget(hostId, options) {
    this.host = document.getElementById(hostId);
    if (!this.host) throw new Error('RigPi S-meter host not found: ' + hostId);
    this.options = options || {};
    this.theme = Math.max(0, Math.min(THEMES.length - 1, Number(this.options.theme) || 0));
    this.value = 0;
    this.displayValue = 0;
    this.targetValue = 0;
    this.label = 'S-Meter';
    this.duration = 320;
    installStyle();
    this._render();
  }

  MeterWidget.prototype._render = function () {
    var t = THEMES[this.theme];
    var ticks = '', i, angle;
    for (i=0; i<=50; i++) {
      angle = -138 + (96 * i / 50);
      ticks += lineAt(i % 5 === 0 ? 214 : 222, 235, angle, i % 5 === 0 ? 'sm-major' : 'sm-minor');
    }

    var sLabels = [
      [-138,'S','sm-s-label'],[-126,'1','sm-s-label'],[-114,'3','sm-s-label'],[-102,'5','sm-s-label'],
      [-90,'7','sm-s-label'],[-78,'9','sm-s-label'],[-66,'+20','sm-s-plus'],[-54,'+40','sm-s-plus'],[-42,'+60 dB','sm-s-plus sm-plus60',207,11]
    ].map(function(v){ return textAt(v[3] || 195,v[0],v[1],v[2],v[4]); }).join('');

    var html = '' +
      '<svg class="rigpi-smeter" viewBox="80 0 540 260" preserveAspectRatio="xMidYMid meet" role="img" aria-label="RigPi signal and transmit meter" ' +
        'style="--sm-ink:' + t.ink + ';--sm-accent:' + t.accent + '">' +
        '<defs>' +
          '<linearGradient id="smBezel" x1="0" y1="0" x2="0" y2="1"><stop stop-color="' + t.bezel1 + '"/><stop offset="1" stop-color="' + t.bezel2 + '"/></linearGradient>' +
          '<linearGradient id="smFace" x1="0" y1="0" x2="0" y2="1"><stop stop-color="' + t.face1 + '"/><stop offset="1" stop-color="' + t.face2 + '"/></linearGradient>' +
          '<linearGradient id="smDepth" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fff" stop-opacity=".12"/><stop offset=".48" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".10"/></linearGradient>' +
          '<radialGradient id="smHub"><stop stop-color="#f4f4f4"/><stop offset=".45" stop-color="#9da2a7"/><stop offset="1" stop-color="#30343a"/></radialGradient>' +
          '<linearGradient id="smGlass" x1="0" y1="0" x2="1" y2="1"><stop stop-color="' + t.glass + '" stop-opacity=".24"/><stop offset=".48" stop-color="' + t.glass + '" stop-opacity=".03"/><stop offset="1" stop-color="' + t.glass + '" stop-opacity="0"/></linearGradient>' +
          '<clipPath id="smFaceClip"><rect x="97" y="15" width="506" height="230" rx="16"/></clipPath>' +
          '<filter id="smShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-opacity=".45"/></filter>' +
          '<filter id="smSoft"><feGaussianBlur stdDeviation="4"/></filter>' +
        '</defs>' +
        '<rect x="83" y="3" width="534" height="254" rx="24" fill="url(#smBezel)" filter="url(#smShadow)"/>' +
        '<rect x="97" y="15" width="506" height="230" rx="16" fill="url(#smFace)" stroke="#e8ebed" stroke-width="2"/>' +
        '<rect x="99" y="17" width="502" height="226" rx="14" fill="url(#smDepth)" pointer-events="none"/>' +
        '<rect x="101" y="19" width="498" height="222" rx="13" fill="none" stroke="#fff" stroke-opacity=".20" stroke-width="2" pointer-events="none"/>' +
        '<path d="' + arc(235,-138,-42) + '" fill="none" stroke="var(--sm-ink)" stroke-width="2.3"/>' +
        '<path d="' + arc(237,-66,-42) + '" fill="none" stroke="var(--sm-accent)" stroke-width="5" stroke-linecap="round"/>' +
        ticks + sLabels +
        scaleRow('power','PO',175,['0','5','10','25','50','100','150','200','250W'],[0,.12,.22,.36,.5,.64,.76,.88,1]) +
        scaleRow('swr','SWR',138,['1','1.5','2','3','∞'],[0,.28,.5,.72,1]) +
        scaleRow('current','Id',103,['0','5','10','15A'],[0,.34,.67,1]) +
        scaleRow('comp','ALC / COMP',70,['0','10','20','dB'],[0,.42,.78,1],100,-158) +
        '<rect id="sm-label-bg" x="445" y="198" width="140" height="24" rx="12" fill="transparent"/>' +
        '<text id="sm-function" class="sm-function" x="575" y="211" text-anchor="end" dominant-baseline="middle">S-Meter</text>' +
        '<g clip-path="url(#smFaceClip)">' +
          '<g id="sm-needle-group" transform="translate(350 0) scale(1.27 1) translate(-350 0) rotate(-48 350 272)">' +
            '<line class="sm-needle-shadow" x1="350" y1="272" x2="350" y2="29"/>' +
            '<line class="sm-needle" x1="350" y1="272" x2="350" y2="29"/>' +
          '</g>' +
          '<circle class="sm-hub" cx="350" cy="272" r="13"/>' +
          '<circle cx="350" cy="272" r="4" fill="#222"/>' +
        '</g>' +
        '<path d="M105 21 H595 Q555 41 500 57 Q330 8 105 178 Z" fill="url(#smGlass)" pointer-events="none"/>' +
        '<path d="M135 48 Q270 12 430 35" fill="none" stroke="#fff" stroke-opacity=".15" stroke-width="13" stroke-linecap="round" filter="url(#smSoft)" clip-path="url(#smFaceClip)" pointer-events="none"/>' +
        '<path d="M125 229 Q350 245 575 229" fill="none" stroke="#000" stroke-opacity=".10" stroke-width="8" filter="url(#smSoft)" clip-path="url(#smFaceClip)" pointer-events="none"/>' +
      '</svg>';

    this.host.style.overflow = 'hidden';
    this.host.style.width = 'min(340px, calc(100vw - 24px))';
    this.host.style.height = '180px';
    this.host.style.position = 'relative';
    this.host.style.top = '-10px';
    var call = document.getElementById('mcall');
    if (call) {
      call.style.marginTop = '-15px';
      call.style.setProperty('background', 'transparent', 'important');
      call.style.setProperty('background-color', 'transparent', 'important');
      call.style.boxShadow = 'none';
    }
    this.host.innerHTML = html;
    this.svg = this.host.querySelector('svg');
    this.needle = this.host.querySelector('#sm-needle-group');
    this.functionText = this.host.querySelector('#sm-function');
    this._setLabel(this.label);
    this._applyValue(this.value, false);
  };

  MeterWidget.prototype._paintValue = function (value) {
    var angle = -138 + (96 * value / 255);
    var rotation = angle + 90;
    if (this.needle) {
      this.needle.style.transition = 'none';
      this.needle.setAttribute('transform', 'translate(350 0) scale(1.27 1) translate(-350 0) rotate(' + rotation.toFixed(2) + ' 350 272)');
    }
  };

  MeterWidget.prototype._applyValue = function (raw, animate) {
    var value = Math.max(0, Math.min(255, Number(raw) || 0));
    this.value = value;
    this.targetValue = value;
    if (animate === false) {
      if (this._animationFrame) window.cancelAnimationFrame(this._animationFrame);
      this._animationFrame = 0;
      this.displayValue = value;
      this._paintValue(value);
      return;
    }
    if (this._animationFrame) return;
    var self = this;
    this._lastFrameTime = window.performance.now();
    function step(now) {
      var elapsed = Math.min(64, Math.max(1, now - self._lastFrameTime));
      var delta = self.targetValue - self.displayValue;
      var tau = delta >= 0 ? self.duration : self.duration * 1.35;
      self._lastFrameTime = now;
      self.displayValue += delta * (1 - Math.exp(-elapsed / tau));
      self._paintValue(self.displayValue);
      if (Math.abs(self.targetValue - self.displayValue) < .2) {
        self.displayValue = self.targetValue;
        self._paintValue(self.displayValue);
        self._animationFrame = 0;
      } else {
        self._animationFrame = window.requestAnimationFrame(step);
      }
    }
    this._animationFrame = window.requestAnimationFrame(step);
  };

  MeterWidget.prototype._setLabel = function (text) {
    this.label = String(text || 'S-Meter');
    if (this.functionText) {
      while (this.functionText.firstChild) this.functionText.removeChild(this.functionText.firstChild);
      var labelLines = this.label.length > 13 && /power/i.test(this.label) ?
        [this.label.replace(/\s+\S+$/, ''), this.label.match(/\S+$/)[0]] : [this.label];
      this.functionText.setAttribute('y', labelLines.length > 1 ? '200' : '211');
      this.functionText.style.fontSize = labelLines.length > 1 ? '15px' : '';
      for (var lineIndex=0; lineIndex<labelLines.length; lineIndex++) {
        var line = document.createElementNS(NS, 'tspan');
        line.setAttribute('x', '575');
        line.setAttribute('dy', lineIndex === 0 ? '0' : '18');
        line.textContent = labelLines[lineIndex];
        this.functionText.appendChild(line);
      }
    }
    if (!this.svg) return;
    var key = this.label.toLowerCase();
    var map = key.indexOf('swr') >= 0 ? 'swr' :
      (key.indexOf('power') >= 0 || key.indexOf('pwr') >= 0 || key.indexOf('po') === 0) ? 'power' :
      (key.indexOf('current') >= 0 || key === 'id' || key.indexOf('amp') >= 0) ? 'current' :
      (key.indexOf('alc') >= 0 || key.indexOf('comp') >= 0) ? 'comp' : '';
    var groups = this.svg.querySelectorAll('.sm-secondary-scale');
    for (var i=0; i<groups.length; i++) groups[i].classList.toggle('sm-active', groups[i].id === 'sm-scale-' + map);
  };

  MeterWidget.prototype._setTheme = function (index) {
    var oldValue = this.value, oldLabel = this.label;
    this.theme = Math.max(0, Math.min(THEMES.length - 1, Number(index) || 0));
    this._render();
    this._setLabel(oldLabel);
    this._applyValue(oldValue, false);
  };

  MeterWidget.prototype.getByName = function (name) {
    var self = this;
    if (name === 'Slider1') return {
      setValue:function(value, animate){ self._applyValue(value, animate !== false); },
      configureAnimation:function(options){ if (options && options.duration) self.duration = options.duration; },
      setNeedRepaint:function(){}, refreshElement:function(){}
    };
    if (name === 'MtrFn') return {
      setText:function(value){ self._setLabel(value); }, setNeedRepaint:function(){}, refreshElement:function(){}
    };
    if (/background|theme/i.test(name || '')) return {
      setVisible:function(visible){ if (visible) self._setTheme(Number(String(name).match(/\d+/)) || 0); },
      setNeedRepaint:function(){}, refreshElement:function(){}
    };
    return { setValue:function(){}, setText:function(){}, setVisible:function(){}, configureAnimation:function(){}, setNeedRepaint:function(){}, refreshElement:function(){} };
  };

  MeterWidget.prototype.refreshElement = function () {};
  MeterWidget.prototype.setNeedRepaint = function () {};

  window.RigPiSMeter = { Widget:MeterWidget, version:'1.7.8' };
})(window, document);
