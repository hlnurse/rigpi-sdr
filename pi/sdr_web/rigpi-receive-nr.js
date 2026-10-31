/* Local listening NR. No microphone, transmitter, decoder or RF state access. */
(function () {
'use strict';
class ReceiveNRDSP {
  constructor(rate=48000, strength=2) {
    this.rate=rate; this.n=2048; this.hop=512; this.strength=strength;
    this.input=new Float64Array(this.n); this.output=new Float64Array(this.n*2);
    this.re=new Float64Array(this.n); this.im=new Float64Array(this.n);
    this.window=Float64Array.from({length:this.n},(_,i)=>Math.sin(Math.PI*i/this.n));
    this.noise=new Float64Array(this.n/2+1); this.power=new Float64Array(this.noise.length);
    this.gains=new Float64Array(this.noise.length).fill(1); this.target=new Float64Array(this.noise.length);
    this.inPos=0;this.outPos=0;this.count=0;this.frames=0;
  }
  fft(inverse=false) {
    const n=this.n,r=this.re,m=this.im;
    for(let i=1,j=0;i<n;i++) {let bit=n>>1;for(;j&bit;bit>>=1)j^=bit;j^=bit;if(i<j){[r[i],r[j]]=[r[j],r[i]];[m[i],m[j]]=[m[j],m[i]];}}
    for(let len=2;len<=n;len<<=1){const a=(inverse?2:-2)*Math.PI/len,wr0=Math.cos(a),wi0=Math.sin(a);
      for(let i=0;i<n;i+=len){let wr=1,wi=0;for(let j=0;j<len/2;j++){const u=i+j,v=u+len/2,tr=r[v]*wr-m[v]*wi,ti=r[v]*wi+m[v]*wr;r[v]=r[u]-tr;m[v]=m[u]-ti;r[u]+=tr;m[u]+=ti;const t=wr*wr0-wi*wi0;wi=wr*wi0+wi*wr0;wr=t;}}}
    if(inverse)for(let i=0;i<n;i++){r[i]/=n;m[i]/=n;}
  }
  frame() {
    const n=this.n,bins=n/2+1;
    for(let i=0;i<n;i++){this.re[i]=this.input[(this.inPos+i)%n]*this.window[i];this.im[i]=0;}
    this.fft();
    for(let k=0;k<bins;k++)this.power[k]=this.re[k]**2+this.im[k]**2;
    if(this.frames===3){const sorted=Array.from(this.power).sort((a,b)=>a-b);this.noise.fill(Math.max(1e-14,sorted[Math.floor(sorted.length/2)]/0.693));}
    const level=Math.max(1,Math.min(5,Number(this.strength)||2));
    const floor=10**(-(4+level*2.4)/20), over=1.0+level*0.45;
    for(let k=0;k<bins;k++){
      const p=this.power[k],old=this.noise[k]||1e-14;
      // Track downward changes promptly, upward changes only in noise-like bins.
      const alpha=p<old?0.035:p<old*5?0.025:0.00015;
      this.noise[k]=Math.max(1e-14,old+alpha*(p-old));
      const ratio=this.noise[k]/Math.max(p,1e-14);
      this.target[k]=Math.max(floor,Math.min(1,1-over*ratio));
    }
    for(let k=0;k<bins;k++){
      let g=0,weight=0;for(let d=-2;d<=2;d++){const j=k+d;if(j>=0&&j<bins){const w=3-Math.abs(d);g+=this.target[j]*w;weight+=w;}}
      g=0.7*this.target[k]+0.3*g/weight;const a=g>this.gains[k]?0.65:0.16;this.gains[k]+=a*(g-this.gains[k]);
      if(this.frames<4)g=1;else g=this.gains[k];
      this.re[k]*=g;this.im[k]*=g;if(k>0&&k<n/2){this.re[n-k]*=g;this.im[n-k]*=g;}
    }
    this.fft(true);
    for(let i=0;i<n;i++)this.output[(this.outPos+i)%this.output.length]+=this.re[i]*this.window[i]*0.5;
    this.frames++;
  }
  process(input,output) {
    for(let i=0;i<output.length;i++){
      output[i]=this.output[this.outPos];this.output[this.outPos]=0;this.outPos=(this.outPos+1)%this.output.length;
      this.input[this.inPos]=Number.isFinite(input[i])?input[i]:0;this.inPos=(this.inPos+1)%this.n;
      if(++this.count===this.hop){this.count=0;this.frame();}
    }
  }
}
if(typeof AudioWorkletProcessor!=='undefined'){
  class ReceiveNRWorklet extends AudioWorkletProcessor {
    constructor(){super();this.active=true;this.level=2;this.dsp=new ReceiveNRDSP(sampleRate,this.level);this.port.onmessage=event=>{const d=event.data||{};if(d.strength!==undefined)this.level=d.strength;if(d.active!==undefined)this.active=!!d.active;if(d.reset)this.dsp=new ReceiveNRDSP(sampleRate,this.level);this.dsp.strength=this.level;};}
    process(inputs,outputs){const output=outputs[0]?.[0],input=inputs[0]?.[0];if(!output)return true;if(!input||!this.active)output.fill(0);else this.dsp.process(input,output);return true;}
  }
  registerProcessor('rigpi-receive-nr',ReceiveNRWorklet);return;
}
if(typeof module!=='undefined'&&module.exports){module.exports={ReceiveNRDSP};return;}
const scriptURL=document.currentScript.src;
const settings={enabled:false,strength:2};
let storageKey='',current=null,lastReceiver='',errorText='';
const modules=new WeakMap();
function save(){try{localStorage.setItem(storageKey,JSON.stringify(settings));}catch(_){}}
function ui(){
  const check=document.getElementById('rxNrEnabled'),slider=document.getElementById('rxNrStrength'),label=document.getElementById('rxNrValue'),status=document.getElementById('rxNrStatus');
  if(!check)return;check.checked=settings.enabled;slider.disabled=!settings.enabled;slider.value=settings.strength;slider.style.setProperty('--fill',((settings.strength-1)/4*100)+'%');label.textContent=settings.strength+' / 5';
  status.textContent=errorText||(!settings.enabled?'Off':!current||current.ctx.state==='closed'?'Start received audio to use NR':current.loading?'Preparing noise reduction…':'On · received audio only');
}
function fade(route,wet,warm=false){
  const now=route.ctx.currentTime,start=now+(warm?route.dspDelay:0);
  for(const [param,target] of [[route.dry.gain,wet?0:1],[route.wet.gain,wet?1:0]]){param.cancelScheduledValues(now);param.setValueAtTime(param.value,now);if(warm)param.setValueAtTime(param.value,start);param.linearRampToValueAtTime(target,start+0.035);}
}
function send(route,data){if(route.node?.port)route.node.port.postMessage(data);else if(route.node?.configure)route.node.configure(data);}
async function prepare(route){
  if(route.node||route.loading)return;route.loading=true;ui();
  try{
    let node;
    if(route.ctx.audioWorklet&&typeof AudioWorkletNode!=='undefined'){
      if(!modules.has(route.ctx))modules.set(route.ctx,route.ctx.audioWorklet.addModule(scriptURL));
      await modules.get(route.ctx);
      node=new AudioWorkletNode(route.ctx,'rigpi-receive-nr',{numberOfInputs:1,numberOfOutputs:1,outputChannelCount:[1],channelCount:1,channelCountMode:'explicit'});
    }else{
      node=route.ctx.createScriptProcessor(1024,1,1);let active=true,level=settings.strength,dsp=new ReceiveNRDSP(route.ctx.sampleRate,level);
      node.configure=d=>{if(d.active!==undefined)active=d.active;if(d.strength!==undefined)level=d.strength;if(d.reset)dsp=new ReceiveNRDSP(route.ctx.sampleRate,level);dsp.strength=level;};
      node.onaudioprocess=e=>{const output=e.outputBuffer.getChannelData(0);if(active)dsp.process(e.inputBuffer.getChannelData(0),output);else output.fill(0);};
      route.dspDelay+=1024/route.ctx.sampleRate;
    }
    if(route.ctx.state==='closed'){node.disconnect();return;}
    route.node=node;route.source.connect(node);node.connect(route.wet);
    node.onprocessorerror=()=>{fade(route,false);try{route.source.disconnect(node);node.disconnect();}catch(_){}route.node=null;errorText='Noise reduction stopped. Switch NR off and on to retry.';ui();};
    send(route,{active:settings.enabled,strength:settings.strength,reset:true});fade(route,settings.enabled,true);
  }catch(error){fade(route,false);errorText='Noise reduction is unavailable in this browser.';console.warn('[RX NR]',error);}
  finally{route.loading=false;ui();}
}
function configure(reset=false){
  errorText='';const route=current;if(!route||route.ctx.state==='closed'){ui();return;}
  if(settings.enabled&&!route.node){prepare(route);return;}
  if(settings.enabled){send(route,{active:true,strength:settings.strength,reset});fade(route,true,reset);}
  else{fade(route,false);const token=++route.offToken;setTimeout(()=>{if(route.offToken===token&&!settings.enabled)send(route,{active:false});},100);}
  ui();
}
window.RigPiReceiveNR={
  outputFor(ctx,source){
    if(current&&current.ctx===ctx&&current.source===source)return current.output;
    if(current){try{current.source.disconnect(current.dry);if(current.node){current.source.disconnect(current.node);current.node.disconnect();}current.dry.disconnect();current.wet.disconnect();current.output.disconnect();}catch(_){}}
    const route={ctx,source,dry:ctx.createGain(),wet:ctx.createGain(),output:ctx.createGain(),node:null,loading:false,offToken:0,dspDelay:2048/ctx.sampleRate+0.015};
    route.dry.gain.value=1;route.wet.gain.value=0;source.connect(route.dry);route.dry.connect(route.output);route.wet.connect(route.output);current=route;
    configure();return route.output;
  },
  syncReceiver(state){
    const signature=JSON.stringify([state.device_driver,state.device_serial,state.mode,Math.round(Number(state.center_freq)||0),state.hw_gain]);
    if(lastReceiver&&signature!==lastReceiver&&settings.enabled)configure(true);lastReceiver=signature;
  },
  status(){return {enabled:settings.enabled,strength:settings.strength,ready:!!current?.node,loading:!!current?.loading,error:errorText};}
};
function init(){
  storageKey='rigpi_receive_nr_'+(window._sdrUser||new URLSearchParams(location.search).get('user')||'listener');
  try{const saved=JSON.parse(localStorage.getItem(storageKey)||'{}');settings.enabled=saved.enabled===true;settings.strength=Math.max(1,Math.min(5,Math.round(Number(saved.strength)||2)));}catch(_){}
  document.getElementById('rxNrEnabled')?.addEventListener('change',event=>{settings.enabled=event.target.checked;save();configure(true);});
  document.getElementById('rxNrStrength')?.addEventListener('input',event=>{settings.strength=Math.max(1,Math.min(5,Math.round(Number(event.target.value)||2)));save();configure();});
  ui();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
