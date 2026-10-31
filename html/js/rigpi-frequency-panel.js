/* RigPi frequency panel. Copyright (c) 2026 Howard Nurse, W6HN. MIT License.
 * Independent DOM/SVG control; no PerfectWidgets code or model is loaded.
 * Theme colors match the supplied MIT-licensed RigPi Open S-Meter.
 */
(function(global){
 'use strict';
 const THEMES=[
  {face1:'#ff9200',face2:'#ff9200',ink:'#171717',accent:'#df2739',bezel1:'#747b82',bezel2:'#25292d'},
  {face1:'#28313b',face2:'#0b1015',ink:'#f2f4f5',accent:'#ff4b55',bezel1:'#69717a',bezel2:'#111417'},
  {face1:'#d9e6c2',face2:'#9eb786',ink:'#172017',accent:'#b51e2d',bezel1:'#69736a',bezel2:'#202520'},
  {face1:'#ffffff',face2:'#d8d8d8',ink:'#000000',accent:'#d0001b',bezel1:'#3f3f3f',bezel2:'#050505'},
  {face1:'#d9efb6',face2:'#88b85f',ink:'#10220f',accent:'#bb1e2d',bezel1:'#596657',bezel2:'#172017'}
 ];
 const THEME_NAMES=['Orange','Night','LCD','HighCon','Green'];
 const SUFFIXES=['1000','100','10','1','.1','.01','.001','x1','x01','x001'];
 const LINES=['m4','m3','m2','m1','k3','k2','k1','h3','h2','h1'];
 const SEGMENTS={0:'abcdef',1:'bc',2:'abdeg',3:'abcdg',4:'bcfg',5:'acdfg',6:'acdefg',7:'abc',8:'abcdefg',9:'abcdfg'};
 const POINTS=['5,1 29,1 25,5 9,5','30,2 30,25 26,22 26,6','30,29 30,52 26,48 26,32','5,53 29,53 25,49 9,49','4,29 8,32 8,48 4,52','4,2 8,6 8,22 4,25','5,27 9,24 25,24 29,27 25,30 9,30'];
 class Element {
  constructor(widget,name,node=null,options={}){this.widget=widget;this.name=name;this.node=node;this.visible=options.visible!==false;this.active=true;this.text=options.text||'';this.value=0;this.handlers=[];this.changes=[];this.pressed=false;}
  addOnClickHandler(fn){if(!this.handlers.includes(fn))this.handlers.push(fn);}
  addValueChangedHandler(fn){if(!this.changes.includes(fn))this.changes.push(fn);}
  getText(){return this.text;}
  setText(text){this.text=String(text==null?'':text);this.widget.paint(this);}
  getValue(){return this.value;}
  setValue(value,notify){if(!Number.isFinite(Number(value)))return;this.value=Math.max(0,Math.min(100,Number(value)));this.widget.paint(this);if(notify)for(const fn of this.changes)fn(this,{});}
  setVisible(value){this.visible=!!value;this.widget.paint(this);}
  getVisible(){return this.visible;}
  setActive(value){this.active=!!value;}
  getActive(){return this.active;}
  getPressed(){return this.pressed;}
  setNeedRepaint(){}
  refreshElement(){this.widget.paint(this);}
  configureAnimation(){}
  fire(event){if(!this.active||!this.visible||this.widget.disposed)return;for(const fn of this.handlers)fn(this,event);}
 }
 class Widget {
  constructor(id,options={}){
   this.root=typeof id==='string'?document.getElementById(id):id;
   if(!this.root)throw new Error('RigPi frequency panel container not found.');
   this.options=options;this.elements={};this.bindings=[];this.disposed=false;this.theme=0;
   this.mount();this.setTheme(options.theme);this.listen(global,'resize',()=>this.responsive());this.responsive();
  }
  getByName(name){if(!this.elements[name])throw new Error('Unknown RigPi frequency panel element: '+name);return this.elements[name];}
  add(name,node=null,options={}){return this.elements[name]=new Element(this,name,node,options);}
  listen(node,type,fn,options){node.addEventListener(type,fn,options);this.bindings.push(()=>node.removeEventListener(type,fn,options));}
  canTune(row){return !this.disposed&&(!this.options.canTune||this.options.canTune(row));}
  button(name,text,label,parent){
   const node=document.createElement('button');node.type='button';node.textContent=text;node.setAttribute('aria-label',label);node.title=label;parent.appendChild(node);
   const element=this.add(name,node);
   // Pointer tuning keeps the page's spacebar PTT focus; Tab still reaches
   // each native button, where Space/Enter activate only that control.
   this.listen(node,'pointerdown',event=>{if(event.button===0)event.preventDefault();});
   for(const type of ['keydown','keyup'])this.listen(node,type,event=>{if(event.key===' '||event.key==='Enter')event.stopPropagation();});
   this.listen(node,'click',event=>{event.preventDefault();if(this.canTune(node.dataset.row)&&(!node.closest('.rp-number-keys')||this.getByName('GroupDigits').active))element.fire(event);});
   return node;
  }
  mount(){
   this.root.classList.add('rigpi-frequency-host');
   this.root.innerHTML='<div class="rp-panel-bezel"><section class="rp-panel-face" aria-label="Radio frequency panel"><header class="rp-panel-labels"><b>MAIN</b><span data-field="Band2"></span><span class="rp-clock"><span data-field="LocalTime"></span> UTC</span></header><div class="rp-frequency" data-row="Main"></div><header class="rp-panel-labels rp-sub-labels"><b>SUB</b><span data-field="Band1"></span><span class="rp-mode"><span data-field="Mode"></span> <span data-field="TR"></span></span></header><div class="rp-frequency" data-row="Sub"></div><div class="rp-panel-footer"><div class="rp-number-keys" role="group" aria-label="Enter a frequency digit"></div><div class="rp-mobile-tools"><div class="rp-keypad"></div><div class="rp-linear-meter" role="meter" aria-label="Signal level" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="rp-meter-scale"></div><span class="rp-meter-function"></span><div class="rp-meter-track"><div class="rp-meter-fill"></div></div></div></div></div></section></div>';
   this.face=this.root.querySelector('.rp-panel-face');this.meter=this.root.querySelector('.rp-linear-meter');
   for(const name of ['Band2','Band1','LocalTime','Mode','TR'])this.add(name,this.root.querySelector(`[data-field="${name}"]`));
   this.add('GroupDigits',this.root.querySelector('.rp-number-keys'));
   this.add('GroupNUM',this.root.querySelector('.rp-mobile-tools'),{visible:false});
   for(const name of ['NUMrect','Guide1','Label1','BarBorder'])this.add(name);
   this.add('Slider1');this.add('LinearLevel1',this.meter);
   for(const name of THEME_NAMES)this.add(name,null,{visible:false});
   for(const row of ['Main','Sub']){
    const host=this.root.querySelector(`[data-row="${row}"]`);const prefix=row==='Sub'?'s':'';
    this.add(row,host,{visible:row==='Main',text:'0000.000.000'});
    if(row==='Sub')this.add('SubInactive',host,{text:'0000.000.000'});
    for(let n=0;n<10;n++){
     if(n===4||n===7){const dot=document.createElement('span');dot.className='rp-frequency-dot';dot.textContent='.';host.appendChild(dot);}
     const cell=document.createElement('div');cell.className='rp-digit';cell.dataset.position=String(n);host.appendChild(cell);
     cell.innerHTML='<svg viewBox="0 0 34 56" aria-hidden="true" focusable="false">'+POINTS.map((p,i)=>`<polygon data-segment="${'abcdefg'[i]}" points="${p}"/>`).join('')+'</svg>';
     const step=10**(9-n);
     for(const direction of ['up','dn']){
      const button=this.button(prefix+direction+SUFFIXES[n],'',`${row==='Sub'?'Sub':'Main'} ${direction==='up'?'increase':'decrease'} ${step.toLocaleString('en-US')} Hz`,cell);
      button.className='rp-digit-hit rp-digit-'+direction;button.dataset.row=row;
     }
     for(const direction of ['u','d']){
      const line=document.createElement('i');line.className='rp-digit-selection rp-digit-selection-'+direction;cell.appendChild(line);
      this.add(prefix+'l'+LINES[n][0]+direction+LINES[n].slice(1),line,{visible:false});line.hidden=true;
     }
    }
    this.paint(this.getByName(row));
   }
   const keys=this.root.querySelector('.rp-number-keys');
   for(const digit of [1,2,3,4,5,6,7,8,9,0])this.button(digit===0?'Freq10':'Freq'+digit,String(digit),'Enter digit '+digit,keys);
   this.button('NUMBut','KEYPAD','Open frequency keypad',this.root.querySelector('.rp-keypad'));
   const scale=this.root.querySelector('.rp-meter-scale');
   for(const [position,label] of [[0,'S'],[6,'1'],[18,'3'],[30,'5'],[42,'7'],[54,'9'],[64,'+10'],[84,'+30'],[100,'+60']]){
    const span=document.createElement('span');span.textContent=label;span.style.left=position+'%';if(position>54)span.className='rp-meter-plus';scale.appendChild(span);
   }
   this.setMeterLabel('S-Meter');this.paint(this.getByName('LinearLevel1'));
  }
  responsive(){
   const small=global.innerWidth<835;
   this.root.classList.toggle('rp-small-screen',small);
   // Follow the app's existing group switches; on resize, restore mobile tools
   // even when the initial page registered desktop controls only.
   this.getByName('GroupNUM').visible=small||!!(this.options.mobileTools&&this.options.mobileTools());
   this.getByName('GroupDigits').visible=!this.getByName('GroupNUM').visible;
   this.getByName('GroupDigits').active=this.getByName('GroupDigits').visible;
   this.paint(this.getByName('GroupNUM'));this.paint(this.getByName('GroupDigits'));
  }
  setTheme(raw){
   const index=Number(raw);this.theme=Number.isInteger(index)&&index>=0&&index<THEMES.length?index:0;
   for(const [name,value] of Object.entries(THEMES[this.theme]))this.root.style.setProperty('--rp-'+name,value);
   for(const name of THEME_NAMES)if(this.elements[name])this.elements[name].visible=THEME_NAMES[this.theme]===name;
  }
  setMeterLabel(label){
   this.meterLabel=String(label||'S-Meter');this.meter.setAttribute('aria-label',this.meterLabel);
   const tx=!/^s[- ]?meter$/i.test(this.meterLabel);this.root.classList.toggle('rp-tx-meter',tx);
   this.meter.title=this.meterLabel;this.root.querySelector('.rp-meter-function').textContent=this.meterLabel;
  }
  paint(element){
   const {name,node}=element;
   if(THEME_NAMES.includes(name)){if(element.visible)this.setTheme(THEME_NAMES.indexOf(name));return;}
   if(['Main','Sub','SubInactive'].includes(name)){
    const row=name==='Main'?'Main':'Sub';const host=this.getByName(row).node;
    const active=row==='Main'||this.getByName('Sub').visible;
    const display=active?this.getByName(row):this.getByName('SubInactive');
    const raw=display.text.replaceAll('.','').padStart(10,' ').slice(-10);
    const cells=host.querySelectorAll('.rp-digit');
    cells.forEach((cell,i)=>{for(const segment of cell.querySelectorAll('polygon'))segment.classList.toggle('rp-lit',(SEGMENTS[raw[i]]||'').includes(segment.dataset.segment));});
    host.classList.toggle('rp-frequency-inactive',!active);host.setAttribute('aria-label',`${row==='Main'?'Main':'Sub'} frequency ${display.text.trim()} Hz${active?'':', inactive'}`);
    return;
   }
   if(name==='LinearLevel1'||name==='Slider1'){
    this.root.querySelector('.rp-meter-fill').style.width=element.value+'%';this.meter.setAttribute('aria-valuenow',String(element.value));return;
   }
   if(node){node.hidden=!element.visible;if(['Band1','Band2','Mode','TR','LocalTime'].includes(name))node.textContent=element.text;}
  }
  setNeedRepaint(){}
  refreshElement(){}
  dispose(){this.disposed=true;for(const off of this.bindings)off();this.bindings=[];this.root.replaceChildren();}
 }
 global.RigPiFrequencyPanel={Widget,version:'1.0.0'};
})(window);
