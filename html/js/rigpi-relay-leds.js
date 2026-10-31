/* RigPi relay LEDs. Copyright (c) 2026 Howard Nurse, W6HN. MIT License.
 * Independent DOM/CSS control. Existing Tuner callbacks own relay commands.
 */
(function(global){
 'use strict';
 class Element{
  constructor(widget,name,node=null){this.widget=widget;this.name=name;this.node=node;this.visible=true;this.active=true;this.pressed=false;this.handlers=[];}
  addOnClickHandler(fn){if(!this.handlers.includes(fn))this.handlers.push(fn);}
  setVisible(value){this.visible=!!value;this.widget.paint(this);}
  getVisible(){return this.visible;}
  setActive(value){this.active=!!value;if(this.name.endsWith('PB'))this.node.disabled=!this.active;}
  getPressed(){return this.pressed;}
  setNeedRepaint(){}
  refreshElement(){this.widget.paint(this);}
  fire(event){if(this.active&&this.visible&&!this.widget.disposed)for(const fn of this.handlers)fn(this,event);}
 }
 class Widget{
  constructor(id,options={}){
   this.root=typeof id==='string'?document.getElementById(id):id;
   if(!this.root)throw new Error('RigPi relay LED container not found.');
   this.options=options;this.elements={};this.bindings=[];this.disposed=false;
   this.root.classList.add('rigpi-relay-host');this.root.innerHTML='<div class="rigpi-relay-row" role="group" aria-label="Relay switches"></div>';
   const row=this.root.firstElementChild;
   for(let n=8;n>=1;n--){
    const button=document.createElement('button');button.type='button';button.className='rigpi-relay-button';button.dataset.relay=String(n);
    button.innerHTML=`<span class="rigpi-relay-number" aria-hidden="true">${n}</span><span class="rigpi-relay-lamp" aria-hidden="true"></span>`;row.appendChild(button);
    const pb=this.add('R'+n+'PB',button);this.add('R'+n+'O',button);
    this.listen(button,'pointerdown',e=>{if(e.button===0){e.preventDefault();pb.pressed=true;}});
    for(const type of ['pointerup','pointercancel','lostpointercapture','blur'])this.listen(button,type,()=>{pb.pressed=false;});
    for(const type of ['keydown','keyup'])this.listen(button,type,e=>{if(e.key===' '||e.key==='Enter')e.stopPropagation();});
    this.listen(button,'click',event=>{event.preventDefault();if(!this.options.canToggle||this.options.canToggle())pb.fire(event);});
    this.paint(this.getByName('R'+n+'O'));
   }
   const label=document.createElement('span');label.className='rigpi-relay-caption';label.textContent='SW';label.setAttribute('aria-hidden','true');row.appendChild(label);
   this.listen(global,'blur',()=>this.release());this.listen(global,'pagehide',()=>this.release());
  }
  add(name,node){return this.elements[name]=new Element(this,name,node);}
  getByName(name){const element=this.elements[String(name).trim()];if(!element)throw new Error('Unknown RigPi relay LED element: '+name);return element;}
  listen(node,type,fn){node.addEventListener(type,fn);this.bindings.push(()=>node.removeEventListener(type,fn));}
  paint(element){
   if(this.disposed)return;
   if(element.name.endsWith('PB')){element.node.hidden=!element.visible;return;}
   // The old white/gray overlay is visible for OFF, hidden for red/ON.
   const button=element.node,on=!element.visible,n=button.dataset.relay;
   button.classList.toggle('rigpi-relay-on',on);button.setAttribute('aria-pressed',String(on));
   button.setAttribute('aria-label','Relay '+n+', '+(on?'on':'off'));button.title='Relay '+n+': '+(on?'on':'off');
  }
  release(){for(const element of Object.values(this.elements))element.pressed=false;}
  setNeedRepaint(){}
  refreshElement(){}
  dispose(){this.release();this.disposed=true;for(const off of this.bindings)off();this.bindings=[];this.root.replaceChildren();}
 }
 global.RigPiRelayLEDs={Widget,version:'1.0.0'};
})(window);
