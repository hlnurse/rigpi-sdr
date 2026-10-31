/* RigPi master-shell health monitoring. Readings and advice only. */
(function(root){
'use strict';
const PERIOD=300000,CONFIRM=31000;
function assess(checks,states,now){
 const alerts=[];let pending=false;const rank={yellow:1,red:2};
 for(const c of checks){
  if(!c||typeof c.key!=='string')continue;
  if(c.level==='green'){states.delete(c.key);continue;}
  if(!rank[c.level]){const s=states.get(c.key);if(s)s.candidate=null;continue;}
  const s=states.get(c.key)||{notified:0,candidate:null};states.set(c.key,s);
  if(rank[c.level]<=s.notified){s.candidate=null;continue;}
  if(!s.candidate||s.candidate.level!==c.level||now-s.candidate.last>60000)s.candidate={level:c.level,since:now,last:now};
  s.candidate.last=now;
  if(now-s.candidate.since>=Math.max(10000,(Number(c.hold)||0)*1000)){
   s.notified=rank[c.level];s.candidate=null;alerts.push(c);
  }else pending=true;
 }
 return {alerts,pending};
}
if(typeof module==='object'&&module.exports){module.exports={assess,PERIOD,CONFIRM};return;}
const frame=document.getElementById('rigpiContent');if(!frame||!root.rigpiShell||root.rigpiShell.healthMonitor)return;
const states=new Map(),toasts=new Map();let timer=null,busy=false,controller=null,generation=0;
const info={lastCheck:null,nextCheck:null,lastError:null,checking:false,checkNow:()=>poll()};root.rigpiShell.healthMonitor=info;
const style=document.createElement('style');style.textContent=
'#rigpiHealthToasts{position:fixed;right:18px;bottom:42px;width:min(440px,calc(100vw - 36px));max-height:65vh;overflow:auto;z-index:9500;font:15px Arial,sans-serif;pointer-events:none}'+
'.rigpi-health-toast{pointer-events:auto;background:#fff;color:#222;border:2px solid #a66b00;border-left-width:7px;border-radius:8px;padding:14px;margin-top:10px;box-shadow:0 4px 18px #0005}'+
'.rigpi-health-toast[data-level=red]{border-color:#ba2525}.rigpi-health-toast strong{display:block;font-size:17px;margin-right:26px;color:#805500}.rigpi-health-toast[data-level=red] strong{color:#a51f1f}'+
'.rigpi-health-toast p{margin:8px 0;line-height:1.4}.rigpi-health-toast button{background:#fff;color:#174b69;border:1px solid #888;border-radius:4px;padding:6px 10px;cursor:pointer}.rigpi-health-toast .health-dismiss{float:right;padding:2px 7px;color:#444}';document.head.append(style);
const region=document.createElement('div');region.id='rigpiHealthToasts';region.setAttribute('aria-live','polite');region.setAttribute('aria-label','Station Health notifications');document.body.append(region);
function removeToast(key){const t=toasts.get(key);if(t){clearTimeout(t.timeout);t.node.remove();toasts.delete(key);}}
function clear(){states.clear();for(const key of toasts.keys())removeToast(key);}
function show(c){
 removeToast(c.key);const n=document.createElement('section');n.className='rigpi-health-toast';n.dataset.level=c.level;
 const close=document.createElement('button');close.type='button';close.className='health-dismiss';close.textContent='×';close.setAttribute('aria-label','Dismiss health alert for '+c.label);close.onclick=()=>removeToast(c.key);
 const title=document.createElement('strong');title.textContent=(c.level==='red'?'● Problem: ':'● Attention: ')+c.label;
 const message=document.createElement('p');message.textContent=c.message;
 const view=document.createElement('button');view.type='button';view.textContent='View System Status';view.onclick=()=>{frame.src='/systemStatus.php';};
 n.append(close,title,message,view);region.append(n);const t={node:n,timeout:null};toasts.set(c.key,t);
 if(c.level==='yellow'){
  const fade=()=>{if(n.matches(':hover')||n.contains(document.activeElement)){t.timeout=setTimeout(fade,5000);return;}removeToast(c.key);};t.timeout=setTimeout(fade,20000);
 }
}
function eligible(){try{
 const doc=frame.contentDocument;if(!doc||/\/login\.php$/.test(doc.location.pathname))return false;
 return [...doc.querySelectorAll('a[href]')].some(a=>{try{return new URL(a.href).pathname==='/systemStatus.php';}catch(e){return false;}});
}catch(e){return false;}}
function schedule(ms){clearTimeout(timer);info.nextCheck=Date.now()+ms;timer=setTimeout(poll,ms);}
async function poll(){
 if(busy)return;
 if(!eligible()){clear();schedule(PERIOD);return;}
 if(document.hidden){schedule(15000);return;}
 busy=true;info.checking=true;const epoch=generation;controller=new AbortController();const timeout=setTimeout(()=>controller?.abort(),10000);
 try{
  const response=await fetch('/systemStatus.php?data=1',{cache:'no-store',credentials:'same-origin',signal:controller.signal});
  if(response.status===401||response.status===403){clear();info.lastError='Session or administrator access unavailable';schedule(PERIOD);return;}
  if(!response.ok)throw new Error('Health readings unavailable');
  const data=await response.json();if(!Array.isArray(data.health))throw new Error('Invalid health readings');
  if(epoch!==generation||!eligible())return;
  info.lastCheck=Date.now();info.lastError=null;
  const result=assess(data.health,states,Date.now());
  for(const c of data.health)if(c.level!=='yellow'&&c.level!=='red')removeToast(c.key);
  for(const c of result.alerts)show(c);
  schedule(result.pending?CONFIRM:PERIOD);
 }catch(error){
  if(epoch===generation){info.lastError='Health readings unavailable';for(const s of states.values())s.candidate=null;for(const key of toasts.keys())removeToast(key);schedule(PERIOD);}
 }finally{clearTimeout(timeout);controller=null;busy=false;info.checking=false;}
}
frame.addEventListener('load',()=>{if(!eligible()){generation++;controller?.abort();clear();}if(!info.lastCheck||Date.now()-info.lastCheck>=PERIOD)schedule(15000);});
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&(!info.lastCheck||Date.now()>=info.nextCheck))schedule(1000);});
schedule(15000);
})(typeof window!=='undefined'?window:globalThis);
