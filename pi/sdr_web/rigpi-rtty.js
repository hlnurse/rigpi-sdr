/* RigPi receive-only RTTY UI. Copyright 2026 Howard Nurse. MIT License. */
(function(global){
 'use strict';
 const panel=document.getElementById('tab_rtty');
 if(!panel)return;
 const el=id=>document.getElementById(id);
 let last=null,paused=false,latestText='',inflight=false,changing=0,stopped=false;
 const base=()=>global._sdrBase||'';
 const settings={baud:'rttyBaud',shift:'rttyShift',center:'rttyCenter',reverse:'rttyReverse',afc:'rttyAFC',squelch:'rttySquelch'};
 async function json(url,options){
  const abort=new AbortController();const timer=setTimeout(()=>abort.abort(),4000);
  try{const response=await fetch(base()+url,{...options,signal:abort.signal});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'RTTY request failed');return data;}finally{clearTimeout(timer);}
 }
 function message(text){el('rttyStatus').textContent=text;}
 function textPaint(){if(paused)return;const log=el('rttyText');const atBottom=log.scrollTop+log.clientHeight>=log.scrollHeight-25;log.textContent=latestText;if(atBottom)log.scrollTop=log.scrollHeight;}
 function paint(data){
  last=data;
  if(typeof data.text==='string')latestText=data.text;
  textPaint();
  el('rttyCount').textContent=(data.received||0)+' characters';
  el('rttyEnable').textContent=data.config.enabled?'Stop RTTY':'Start RTTY';
  for(const [key,id] of Object.entries(settings)){
   const field=el(id);if(document.activeElement===field||changing)continue;
   if(field.type==='checkbox')field.checked=!!data.config[key];else field.value=key==='squelch'?Math.round(data.config[key]*100):String(data.config[key]);
  }
  let state='Decoder off';
  if(data.config.enabled)state=data.error?'Decoder error: '+data.error:!data.audio_live?'Waiting for SDR audio':data.signal?'Receiving RTTY':'Listening — tune a RTTY signal';
  if(data.audio_live&&data.mode&&!/^(USB|LSB|PKTUSB|PKTLSB|RTTY)$/i.test(data.mode))state+=' · use USB or LSB';
  message(state);
  el('rttyOffset').textContent=data.signal?`${data.offset_hz>=0?'+':''}${data.offset_hz.toFixed(1)} Hz\n${Math.round(data.quality*100)}% contrast`:'No signal\n— contrast';
  drawScope(data.scope||[]);
  drawAudio(data);
 }
 async function command(change){
  changing++;el('rttyEnable').disabled=true;
  try{const data=await json('/api/rtty/control',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(change)});paint(data);return true;}catch(error){message(error.name==='AbortError'?'RTTY request timed out':error.message);}finally{changing--;el('rttyEnable').disabled=false;}
 }
 async function poll(){
  if(stopped||inflight||changing||document.hidden||!panel.classList.contains('active'))return;
  inflight=true;
  try{paint(await json('/api/rtty/status'));}catch(error){message(error.name==='AbortError'?'RTTY status timed out':error.message);}finally{inflight=false;}
 }
 function scopeBackdrop(ctx,w,h){
  ctx.fillStyle='#020704';ctx.fillRect(0,0,w,h);ctx.strokeStyle='#183d25';ctx.lineWidth=1;
  ctx.beginPath();ctx.moveTo(0,h/2);ctx.lineTo(w,h/2);ctx.moveTo(w/2,0);ctx.lineTo(w/2,h);ctx.stroke();
  ctx.strokeStyle='#12351e';ctx.beginPath();ctx.ellipse(w/2,h/2,w*.43,h*.43,0,0,2*Math.PI);ctx.stroke();
  ctx.fillStyle='#6baa7c';ctx.font='11px monospace';ctx.fillText('MARK',8,16);ctx.fillText('SPACE',w-46,h-9);
 }
 function drawScope(points){
  const c=el('rttyScope'),ctx=c.getContext('2d');if(!ctx)return;
  if(!points.length){scopeBackdrop(ctx,c.width,c.height);return;}
  // Phosphor persistence comes from earlier received samples, not simulated motion.
  ctx.fillStyle='rgba(2,7,4,.25)';ctx.fillRect(0,0,c.width,c.height);
  ctx.strokeStyle='#183d25';ctx.beginPath();ctx.moveTo(0,c.height/2);ctx.lineTo(c.width,c.height/2);ctx.moveTo(c.width/2,0);ctx.lineTo(c.width/2,c.height);ctx.stroke();
  for(const [x,y,tone] of points){if(!Number.isFinite(x)||!Number.isFinite(y))continue;ctx.fillStyle=tone===0?'#78ff8b':'#b0ffb2';ctx.fillRect(c.width/2+x*c.height*.43,c.height/2-y*c.height*.43,1.7,1.7);}
 }
 function drawAudio(data){
  const c=el('rttyAudioSpectrum'),ctx=c.getContext('2d');if(!ctx)return;
  const w=c.width,h=c.height;ctx.fillStyle='#020704';ctx.fillRect(0,0,w,h);
  ctx.strokeStyle='#205232';ctx.beginPath();for(let hz=0;hz<=3000;hz+=500){let x=hz/3000*w;ctx.moveTo(x,0);ctx.lineTo(x,h-18);}ctx.stroke();
  const bins=data.spectrum||[];ctx.strokeStyle='#71d48a';ctx.lineWidth=1.5;ctx.beginPath();bins.forEach((db,i)=>{const x=i/(bins.length-1)*w,y=5+(-db/70)*(h-25);if(i)ctx.lineTo(x,y);else ctx.moveTo(x,y);});ctx.stroke();
  const center=Number(data.config.center)+Number(data.afc_offset_hz||0),half=Number(data.config.shift)/2;
  for(const [hz,label,color] of [[center+half,data.config.reverse?'S':'M','#86ff9f'],[center-half,data.config.reverse?'M':'S','#e6d776']]){const x=hz/3000*w;ctx.strokeStyle=color;ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,h-18);ctx.stroke();ctx.fillStyle=color;ctx.font='11px monospace';ctx.fillText(label+' '+Math.round(hz),Math.min(x+4,w-65),label==='M'?16:32);}
  ctx.fillStyle='#8aa893';ctx.font='11px monospace';for(let hz=0;hz<=3000;hz+=500)ctx.fillText(hz===3000?'3 kHz':String(hz),Math.min(hz/3000*w+3,w-37),h-4);
 }
 el('rttyEnable').addEventListener('click',()=>command({enabled:!(last&&last.config.enabled)}));
 for(const [key,id] of Object.entries(settings))el(id).addEventListener('change',event=>{const f=event.target;const value=f.type==='checkbox'?f.checked:key==='squelch'?Number(f.value)/100:Number(f.value);command({[key]:value});});
 el('rttyPause').addEventListener('click',()=>{paused=!paused;el('rttyPause').textContent=paused?'Resume display':'Pause display';el('rttyPause').setAttribute('aria-pressed',String(paused));textPaint();});
 el('rttyClear').addEventListener('click',()=>command({clear:true}).then(ok=>{if(ok)el('rttyText').textContent='';}));
 el('rttySave').addEventListener('click',()=>{const text=el('rttyText').textContent;const url=URL.createObjectURL(new Blob([text],{type:'text/plain;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='RigPi-RTTY-'+new Date().toISOString().replace(/[:.]/g,'-')+'.txt';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
 el('rttyAudioSpectrum').addEventListener('click',event=>{const r=event.currentTarget.getBoundingClientRect();const half=Number(last?.config.shift||170)/2;const center=Math.round((event.clientX-r.left)/r.width*3000/5)*5;command({center:Math.max(Math.max(300,100+half),Math.min(Math.min(2300,2700-half),center))});});
 el('rttyAudioSpectrum').addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight'].includes(event.key))return;event.preventDefault();const half=Number(last?.config.shift||170)/2;const center=Number(last?.config.center||2210)+(event.key==='ArrowRight'?5:-5);command({center:Math.max(Math.max(300,100+half),Math.min(Math.min(2300,2700-half),center))});});
 scopeBackdrop(el('rttyScope').getContext('2d'),320,210);
 drawAudio({config:{center:2210,shift:170},spectrum:[]});
 const observer=new MutationObserver(()=>{if(!panel.classList.contains('active'))document.querySelectorAll('.rtty-wf-marker').forEach(e=>e.style.display='none');poll();});observer.observe(panel,{attributes:true,attributeFilter:['class']});
 const timer=setInterval(poll,300);
 document.addEventListener('visibilitychange',poll);
 global.addEventListener('pagehide',()=>{stopped=true;clearInterval(timer);observer.disconnect();},{once:true});
 global.RigPiRTTY={updateWaterfallMarkers(canvas,displayCenter,span,dial,mode){
  const parent=canvas.parentElement;if(!parent)return;
  if(getComputedStyle(parent).position==='static')parent.style.position='relative';
  const r=canvas.getBoundingClientRect(),base=parent.getBoundingClientRect();
  const enabled=last?.config.enabled&&panel.classList.contains('active')&&!global._mapModeActive;
  const center=Number(last?.config.center||2210)+Number(last?.afc_offset_hz||0),half=Number(last?.config.shift||170)/2,sign=/LSB/i.test(String(mode))?-1:1;
  for(const [offset,label,color] of [[center+half,last?.config.reverse?'S':'M','#85ff9d'],[center-half,last?.config.reverse?'M':'S','#e6d776']]){
   let line=document.getElementById('rttyWF'+label);if(!line){line=document.createElement('div');line.id='rttyWF'+label;line.className='rtty-wf-marker';line.setAttribute('aria-hidden','true');line.style.cssText='display:none;position:absolute;width:1px;pointer-events:none;z-index:5;background:'+color;parent.appendChild(line);}
   const ratio=(dial+sign*offset-displayCenter)/span+.5;const visible=enabled&&span>0&&Number.isFinite(ratio)&&ratio>=0&&ratio<=1&&r.width>0;
   line.style.display=visible?'block':'none';if(!visible)continue;
   line.style.left=(r.left-base.left-parent.clientLeft+parent.scrollLeft+Math.min(r.width-1,ratio*r.width))+'px';
   line.style.top=(r.top-base.top-parent.clientTop+parent.scrollTop)+'px';line.style.height=r.height+'px';
  }
 },drawRFMarkers(ctx,w,h,displayCenter,span,dial,mode){
  if(!last?.config.enabled||!panel.classList.contains('active')||!Number.isFinite(dial)||!Number.isFinite(span)||span<=0)return;
  const c=Number(last.config.center)+Number(last.afc_offset_hz||0),half=Number(last.config.shift)/2,sign=/LSB/i.test(String(mode))?-1:1;
  ctx.save();ctx.setLineDash([3,3]);ctx.lineWidth=1;ctx.font='10px monospace';
  for(const [offset,name,color] of [[c+half,last.config.reverse?'RTTY S':'RTTY M','#85ff9d'],[c-half,last.config.reverse?'RTTY M':'RTTY S','#e6d776']]){const x=((dial+sign*offset-displayCenter)/span+.5)*w;if(x<0||x>w)continue;ctx.strokeStyle=color;ctx.fillStyle=color;ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,h);ctx.stroke();ctx.fillText(name,Math.min(x+3,w-48),name.endsWith('M')?38:50);}
  ctx.restore();
 }};
 poll();
})(window);
