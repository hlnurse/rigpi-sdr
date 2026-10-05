/*
 * RigPi SDR — Frequency Intelligence Bar (FIB)
 * Project B24P/AR, Milestone 3-4
 *
 * Priority-based label layout is now active. Background paint order remains
 * independent from intelligence order. Labels progressively shorten, then
 * disappear, while hit regions remain available for every visible object.
 */
(function () {
  "use strict";

  const finiteNumber = (value, fallback = 0) => {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
  };

  class FIBJsonProvider {
    constructor(url, fallbackObjects = [], options = {}) {
      this.url = String(url || "");
      this.fallbackObjects = Array.isArray(fallbackObjects) ? fallbackObjects : [];
      this.id = String(options.id || "json-provider");
      this.metadata = null;
      this.objects = [];
      this.loaded = false;
      this.loading = null;
      this.error = null;
      this.usedFallback = false;
    }

    async load() {
      if (this.loaded) return this.objects;
      if (this.loading) return this.loading;
      this.loading = this.loadDocument()
        .then((document) => this.acceptDocument(document, false))
        .catch((error) => {
          this.error = error;
          if (!this.fallbackObjects.length) {
            this.loaded = false;
            console.error("FIB provider:", error);
            return [];
          }
          console.warn("FIB provider: external JSON unavailable; using embedded factory data.", error);
          return this.acceptDocument({
            schemaVersion: 1,
            id: `${this.id}-fallback`,
            name: "Embedded FIB factory data",
            objects: this.fallbackObjects,
          }, true);
        })
        .finally(() => { this.loading = null; });
      return this.loading;
    }

    async loadDocument() {
      if (!this.url) throw new Error("FIB provider URL is empty");
      const response = await fetch(this.url, { cache: "no-cache" });
      if (!response.ok) throw new Error(`FIB data request failed: ${response.status} ${response.statusText}`);
      return response.json();
    }

    acceptDocument(document, usedFallback) {
      if (!document || !Array.isArray(document.objects)) throw new Error("FIB data must contain an objects array");
      this.metadata = {
        schemaVersion: finiteNumber(document.schemaVersion, 1),
        id: String(document.id || ""), name: String(document.name || ""),
        region: String(document.region || ""), author: String(document.author || ""),
        revision: String(document.revision || ""),
      };
      this.objects = document.objects
        .filter((object) => object && object.enabled !== false)
        .map((object, index) => this.normalize(object, index))
        .filter((object) => object !== null);
      this.loaded = true;
      this.error = usedFallback ? this.error : null;
      this.usedFallback = usedFallback;
      return this.objects;
    }

    normalize(rawObject, index) {
      const start = finiteNumber(rawObject.start ?? rawObject.lo, NaN);
      const end = finiteNumber(rawObject.end ?? rawObject.hi ?? start, NaN);
      if (!Number.isFinite(start) || !Number.isFinite(end)) {
        console.warn("FIB provider: ignoring object with invalid frequency range", rawObject);
        return null;
      }
      const rangeStart = Math.min(start, end);
      const rangeEnd = Math.max(start, end);
      const shortLabel = String(rawObject.shortLabel ?? rawObject.label ?? "");
      const longLabel = String(rawObject.longLabel ?? shortLabel);
      const rawActions = Array.isArray(rawObject.actions) ? rawObject.actions : [];
      const legacyAction = rawObject.action && typeof rawObject.action === "object" ? rawObject.action : {};
      const actionSources = rawActions.length ? rawActions : [legacyAction];
      const normalizedActions = actionSources.map((rawAction, actionIndex) => {
        const fallbackFrequency = finiteNumber(
          rawAction.frequency ?? rawAction.freq ?? rawObject.defaultTune,
          rangeStart + (rangeEnd - rangeStart) / 2,
        );
        return Object.freeze({
          label: String(rawAction.label ?? rawAction.name ?? (actionIndex === 0 ? "Tune" : `Action ${actionIndex + 1}`)),
          frequency: Math.max(0, fallbackFrequency),
          mode: String(rawAction.mode ?? "").trim().toUpperCase(),
          bandwidth: Math.max(0, Math.round(finiteNumber(rawAction.bandwidth ?? rawAction.bw, 0))),
          default: rawAction.default === true,
        });
      });
      let defaultActionIndex = normalizedActions.findIndex((candidate) => candidate.default);
      if (defaultActionIndex < 0) defaultActionIndex = 0;
      const actions = Object.freeze(normalizedActions.map((candidate, actionIndex) => Object.freeze({
        ...candidate, default: actionIndex === defaultActionIndex,
      })));
      const action = actions[defaultActionIndex];
      return Object.freeze({
        id: String(rawObject.id ?? `${this.id}-${index}`),
        enabled: rawObject.enabled !== false,
        type: String(rawObject.type ?? "service"),
        source: String(rawObject.source ?? this.id), providerId: this.id,
        start: rangeStart, end: rangeEnd, lo: rangeStart, hi: rangeEnd,
        widthHz: rangeEnd - rangeStart,
        shortLabel, longLabel, label: shortLabel,
        tooltip: String(rawObject.tooltip ?? longLabel),
        segments: Object.freeze((Array.isArray(rawObject.segments) ? rawObject.segments : [])
          .map((segment) => {
            const segmentStart = finiteNumber(segment.start ?? segment.lo, NaN);
            const segmentEnd = finiteNumber(segment.end ?? segment.hi, NaN);
            if (!Number.isFinite(segmentStart) || !Number.isFinite(segmentEnd)) return null;
            return Object.freeze({
              label: String(segment.label ?? segment.name ?? "Activity"),
              start: Math.min(segmentStart, segmentEnd),
              end: Math.max(segmentStart, segmentEnd),
            });
          })
          .filter(Boolean)),
        note: String(rawObject.note ?? ""),
        color: String(rawObject.color ?? "rgba(255,255,255,0.15)"),
        priority: finiteNumber(rawObject.priority, 50),
        zoomLevel: Math.max(0, Math.trunc(finiteNumber(rawObject.zoomLevel, 0))),
        minSpanHz: Math.max(0, finiteNumber(rawObject.minSpanHz, 0)),
        maxSpanHz: Math.max(0, finiteNumber(rawObject.maxSpanHz, 0)),
        actions,
        action,
        defaultTune: action.frequency,
        order: finiteNumber(rawObject.order, index), raw: rawObject,
      });
    }
    getObjects() { return this.objects; }
  }

  class FIBEngine {
    constructor({ provider, providers, onDataReady } = {}) {
      const supplied = Array.isArray(providers) ? providers : provider ? [provider] : [];
      this.providers = supplied.filter(Boolean);
      this.objects = [];
      this.visibleObjects = [];
      this.intelligenceObjects = [];
      this.hitRegions = [];
      this.labelLayouts = [];
      this.viewport = null;
      this.onDataReady = typeof onDataReady === "function" ? onDataReady : null;
      this.ready = false;
      this.loadError = null;
      this.load();
    }

    async load() {
      try {
        const groups = await Promise.all(this.providers.map((provider) => provider.load()));
        this.objects = groups.flat().filter(Boolean);
        this.ready = true;
        this.loadError = null;
        this.rebuildVisibleObjects();
        if (this.onDataReady) this.onDataReady(this);
      } catch (error) {
        this.ready = false; this.loadError = error; console.error("FIB engine:", error);
      }
    }

    clear() {
      this.visibleObjects = []; this.intelligenceObjects = [];
      this.hitRegions = []; this.labelLayouts = []; this.viewport = null;
    }

    update(freqsDisplay, centerFrequency) {
      if (!Array.isArray(freqsDisplay) || freqsDisplay.length < 2) { this.clear(); return; }
      const first = finiteNumber(freqsDisplay[0], NaN);
      const last = finiteNumber(freqsDisplay[freqsDisplay.length - 1], NaN);
      const f0 = Math.min(first, last), f1 = Math.max(first, last);
      if (!Number.isFinite(f0) || !Number.isFinite(f1) || f1 <= f0) { this.clear(); return; }
      const spanHz = f1 - f0;
      this.viewport = { f0, f1, centerFrequency: finiteNumber(centerFrequency, f0 + spanHz / 2), spanHz, zoomLevel: this.getZoomLevel(spanHz) };
      this.rebuildVisibleObjects();
    }

    getZoomLevel(spanHz) {
      if (spanHz <= 100000) return 3;
      if (spanHz <= 500000) return 2;
      if (spanHz <= 2000000) return 1;
      return 0;
    }

    isZoomEligible(object) {
      if (!this.viewport) return false;
      const { spanHz, zoomLevel } = this.viewport;
      if (object.zoomLevel > zoomLevel) return false;
      if (object.minSpanHz > 0 && spanHz < object.minSpanHz) return false;
      if (object.maxSpanHz > 0 && spanHz > object.maxSpanHz) return false;
      return true;
    }

    rebuildVisibleObjects() {
      if (!this.viewport) { this.visibleObjects = []; this.intelligenceObjects = []; this.hitRegions = []; return; }
      const { f0, f1, spanHz } = this.viewport;
      const intersecting = this.objects.filter((o) => o.enabled)
        .filter((o) => o.end >= f0 && o.start <= f1)
        .filter((o) => this.isZoomEligible(o));
      this.visibleObjects = [...intersecting].sort((a,b) => a.start !== b.start ? a.start-b.start : a.order-b.order);
      this.intelligenceObjects = [...intersecting].sort((a,b) => {
        if (a.priority !== b.priority) return b.priority-a.priority;
        if (a.widthHz !== b.widthHz) return a.widthHz-b.widthHz;
        return a.order-b.order;
      });
      this.hitRegions = this.intelligenceObjects.map((object) => ({
        object,
        startRatio: Math.max(0, (object.start-f0)/spanHz),
        endRatio: Math.min(1, (object.end-f0)/spanHz),
      }));
    }

    getObjectAtRatio(ratio, containerWidth = 0, paddingPixels = 0) {
      const x = Math.max(0, Math.min(1, finiteNumber(ratio, 0)));
      const width = Math.max(0, finiteNumber(containerWidth, 0));
      const padRatio = width > 0 ? Math.max(0, finiteNumber(paddingPixels, 0)) / width : 0;
      return this.hitRegions.find((r) =>
        x >= Math.max(0, r.startRatio - padRatio) &&
        x <= Math.min(1, r.endRatio + padRatio)
      )?.object || null;
    }

    frequencyAtRatio(ratio) {
      if (!this.viewport) return NaN;
      const x = Math.max(0, Math.min(1, finiteNumber(ratio, 0)));
      return this.viewport.f0 + x * this.viewport.spanHz;
    }

    compactLabel(object) {
      if (object.type === "ham") return object.shortLabel.replace(/\s+/g, "").replace(/m$/, "");
      if (/^WWV\s+/i.test(object.shortLabel)) return object.shortLabel.replace(/^WWV\s+/i, "");
      if (/^SW\s+/i.test(object.shortLabel)) return object.shortLabel.replace(/^SW\s+/i, "").replace(/\s+/g, "");
      if (/Broadcast$/i.test(object.shortLabel)) return object.shortLabel.replace(/\s*Broadcast$/i, "");
      return object.shortLabel.replace(/\s+/g, "");
    }

    buildLabelLayouts(containerWidth) {
      this.labelLayouts = [];
      if (!this.viewport || containerWidth < 20) return this.labelLayouts;
      const measureCanvas = this._measureCanvas || (this._measureCanvas = document.createElement("canvas"));
      const ctx = measureCanvas.getContext("2d");
      ctx.font = "700 12px system-ui, -apple-system, sans-serif";
      const accepted = [];
      const gap = 4;
      for (const object of this.intelligenceObjects) {
        const region = this.hitRegions.find((r) => r.object === object);
        if (!region) continue;
        const leftBound = region.startRatio * containerWidth;
        const rightBound = region.endRatio * containerWidth;
        const center = (leftBound + rightBound) / 2;
        const choices = [...new Set([object.longLabel, object.shortLabel, this.compactLabel(object)].filter(Boolean))];
        let chosen = null;
        for (const text of choices) {
          const width = Math.ceil(ctx.measureText(text).width) + 10;
          if (width > containerWidth - 4) continue;
          let left = center - width/2;
          let right = center + width/2;
          if (left < 2) { right += 2-left; left = 2; }
          if (right > containerWidth-2) { left -= right-(containerWidth-2); right = containerWidth-2; }
          const collides = accepted.some((a) => right + gap > a.left && left - gap < a.right);
          if (!collides) { chosen = { object, text, left, right, center:(left+right)/2, width }; break; }
        }
        if (chosen) { accepted.push(chosen); this.labelLayouts.push(chosen); }
      }
      this.labelLayouts.sort((a,b) => a.center-b.center);
      return this.labelLayouts;
    }

    draw({ bar, ticks, label, formatFrequency }) {
      if (!bar || !ticks || !label) return;
      if (!this.viewport) {
        ticks.innerHTML = ""; label.innerHTML = "";
        bar.style.background = "rgba(255,255,255,0.06)"; return;
      }
      const { f0, f1, centerFrequency, spanHz } = this.viewport;
      const baseOut = "rgba(255,0,0,0.80)";
      const stops = [`${baseOut} 0%`];
      for (const band of this.visibleObjects) {
        const left = Math.max(0, Math.min(1, (band.start-f0)/spanHz))*100;
        const right = Math.max(0, Math.min(1, (band.end-f0)/spanHz))*100;
        stops.push(`${baseOut} ${left}%`, `${band.color} ${left}%`, `${band.color} ${right}%`, `${baseOut} ${right}%`);
      }
      stops.push(`${baseOut} 100%`);
      bar.style.background = `linear-gradient(90deg, ${stops.join(",")})`;
      label.innerHTML = "";
      for (const layout of this.buildLabelLayouts(bar.clientWidth)) {
        const node = document.createElement("div");
        node.className = `fib-object-label ${layout.object.type}`;
        node.style.left = `${layout.center}px`;
        node.textContent = layout.text;
        node.dataset.fibId = layout.object.id;
        label.appendChild(node);
      }
      ticks.innerHTML = "";
      const makeTick = (frequency, text, major=false, labelPosition="mid", className="") => {
        const t=(frequency-f0)/spanHz;
        const x=Math.max(0,Math.min(1,t))*(ticks.clientWidth-1);
        const tick=document.createElement("div");
        tick.className=`tick${major?" major":""}${className?` ${className}`:""}`;
        tick.style.left=`${x}px`; ticks.appendChild(tick);
        if (text) {
          const tickLabel=document.createElement("div");
          tickLabel.className=`tick-label${labelPosition==="left"?" left":labelPosition==="right"?" right":""}`;
          tickLabel.style.left=`${x}px`; tickLabel.textContent=text; ticks.appendChild(tickLabel);
        }
      };
      for (let k=1;k<=2;k+=1) { makeTick(f0+(k/5)*spanHz,"",false); makeTick(f0+(1-k/5)*spanHz,"",false); }
      const formatter=typeof formatFrequency==="function"?formatFrequency:(f)=>String(f);
      makeTick(f0,formatter(f0),true,"left");
      makeTick(centerFrequency,formatter(centerFrequency),true,"mid");
      makeTick(f1,formatter(f1),true,"right");
      for (const band of this.visibleObjects) {
        if (band.start>=f0 && band.start<=f1) makeTick(band.start,"",true,"mid","band-edge");
        if (band.end>=f0 && band.end<=f1) makeTick(band.end,"",true,"mid","band-edge");
      }
    }
  }

  window.FIBJsonProvider = FIBJsonProvider;
  window.FIBEngine = FIBEngine;
})();
