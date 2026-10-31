/* RigPi tuning knob. Copyright (c) 2026 Howard Nurse, W6HN. MIT License.
 * Independent DOM/SVG implementation; no PerfectWidgets code or model data.
 */
(function (global) {
    'use strict';
    let nextId=0;
    class Element {
        constructor(widget,name,visible=true){this.widget=widget;this.name=name;this.visible=visible;this.pressed=false;this.handlers=[];}
        addOnClickHandler(handler){this.handlers.push(handler);}
        getPressed(){return this.pressed && (!['PlusButton','MinusButton'].includes(this.name)||this.widget.canTune());}
        setVisible(visible){this.visible=!!visible;this.widget.paint();}
        getVisible(){return this.visible;}
        fire(event){for(const handler of this.handlers)handler(this,event);}
    }
    class Slider extends Element {
        constructor(widget){super(widget,'Slider1');this.value=0;this.changeHandlers=[];}
        getValue(){return this.value;}
        setValue(value,notify=false){if(!Number.isFinite(Number(value)))return;this.value=Number(value);this.widget.paint();if(notify)this.emit();}
        addValueChangedHandler(handler){this.changeHandlers.push(handler);}
        emit(){for(const handler of this.changeHandlers)handler(this,{});}
    }
    class Widget {
        constructor(id,options={}){
            this.root=typeof id==='string'?document.getElementById(id):id;
            if(!this.root)throw new Error('RigPi knob container was not found.');
            this.options=options;this.disposed=false;this.drag=null;this.remainder=0;this.wheelRemainder=0;this.pttHold=null;this.bindings=[];
            this.elements={};
            for(const name of ['PlusButton','MinusButton','Lock','LockOn','PTT','PTTOn'])this.elements[name]=new Element(this,name,!['LockOn','PTTOn'].includes(name));
            this.elements.Slider1=new Slider(this);
            this.mount();this.bind();this.paint();
        }
        getByName(name){if(!this.elements[name])throw new Error('Unknown RigPi knob element: '+name);return this.elements[name];}
        canTune(){return !this.disposed&&!this.elements.LockOn.visible&&(!this.options.canTune||this.options.canTune());}
        listen(target,type,handler,options){target.addEventListener(type,handler,options);this.bindings.push(()=>target.removeEventListener(type,handler,options));}
        mount(){
            const id='rigpi-knob-'+(++nextId),ticks=Array.from({length:48},(_,n)=>`<path d="M120 10v5" transform="rotate(${n*7.5} 120 120)"/>`).join('');
            this.root.classList.add('rigpi-knob-host');
            this.root.innerHTML=`<div class="rigpi-knob-shell">
                <button type="button" class="rigpi-knob-step rigpi-knob-plus" aria-label="Tune up one step" title="Tune up; hold to repeat"><span>+</span></button>
                <button type="button" class="rigpi-knob-dial" role="spinbutton" aria-label="Tuning dial" aria-valuenow="0" aria-valuetext="0 tuning steps" title="Drag to rotate, scroll to tune, or use arrow keys">
                    <svg viewBox="0 0 240 240" aria-hidden="true" focusable="false">
                        <defs><radialGradient id="${id}-face" cx="35%" cy="25%"><stop offset="0" stop-color="#414141"/><stop offset=".45" stop-color="#202020"/><stop offset="1" stop-color="#090909"/></radialGradient><radialGradient id="${id}-grip"><stop stop-color="#555"/><stop offset="1" stop-color="#161616"/></radialGradient></defs>
                        <g class="rigpi-knob-rotor"><circle cx="120" cy="120" r="117" fill="#111"/><g stroke="#777" stroke-width="3">${ticks}</g>
                        <circle cx="120" cy="120" r="105" fill="url(#${id}-face)" stroke="#9c9c9c" stroke-width="1"/>
                        <g class="rigpi-knob-marker"><circle cx="120" cy="49" r="20" fill="url(#${id}-grip)" stroke="#ccc" stroke-width="2"/></g></g>
                    </svg>
                </button>
                <button type="button" class="rigpi-knob-step rigpi-knob-minus" aria-label="Tune down one step" title="Tune down; hold to repeat"><span>−</span></button>
                <button type="button" class="rigpi-knob-lock" aria-label="Lock tuning" aria-pressed="false" title="Lock tuning"><svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path class="rigpi-lock-shackle" d="M10 15V9a6 6 0 0 1 12 0" fill="none" stroke="#eee" stroke-width="3" stroke-linecap="round"/><rect x="6" y="14" width="20" height="15" rx="3" fill="#d9bd6c"/><circle cx="16" cy="20" r="2" fill="#171717"/><path d="M16 21v4" stroke="#171717" stroke-width="2"/></svg></button>
                <button type="button" class="rigpi-knob-ptt" aria-label="Push to talk" aria-pressed="false" title="PTT follows your Momentary/Latch setting">PTT</button>
            </div>`;
            this.dial=this.root.querySelector('.rigpi-knob-dial');this.marker=this.root.querySelector('.rigpi-knob-rotor');
            this.plus=this.root.querySelector('.rigpi-knob-plus');this.minus=this.root.querySelector('.rigpi-knob-minus');
            this.lock=this.root.querySelector('.rigpi-knob-lock');this.ptt=this.root.querySelector('.rigpi-knob-ptt');
        }
        step(count){
            count=Math.trunc(count);if(!count||!this.canTune())return;
            // The existing Tuner callback treats jumps larger than five as a
            // wrapped old dial. Emit one step at a time without value wrapping.
            for(let n=0;n<Math.min(Math.abs(count),24);n++){
                if(!this.canTune())break;
                this.elements.Slider1.value+=Math.sign(count)*10;this.elements.Slider1.emit();
            }
            this.paint();
        }
        paint(){
            if(!this.dial)return;
            const value=this.elements.Slider1.value,locked=this.elements.LockOn.visible,tx=this.elements.PTTOn.visible;
            this.marker.setAttribute('transform',`rotate(${-45+value*.6+(this.drag?this.remainder:0)} 120 120)`);
            this.dial.setAttribute('aria-valuenow',String(value/10));this.dial.setAttribute('aria-valuetext',`${value/10} tuning steps${locked?', locked':''}`);
            this.dial.setAttribute('aria-disabled',String(locked));
            this.root.classList.toggle('rigpi-knob-locked',locked);this.root.classList.toggle('rigpi-knob-transmitting',tx);
            this.lock.setAttribute('aria-pressed',String(locked));this.lock.setAttribute('aria-label',locked?'Unlock tuning':'Lock tuning');this.lock.title=locked?'Unlock tuning':'Lock tuning';this.lock.querySelector('.rigpi-lock-shackle').setAttribute('d',locked?'M10 15V9a6 6 0 0 1 12 0v6':'M10 15V9a6 6 0 0 1 12 0');
            this.ptt.setAttribute('aria-pressed',String(tx));this.ptt.setAttribute('aria-label',tx?'Transmit active; release or click to receive':'Push to talk');
        }
        angle(event){const box=this.dial.getBoundingClientRect();return Math.atan2(event.clientY-box.top-box.height/2,event.clientX-box.left-box.width/2)*180/Math.PI;}
        capture(target,id){try{target.setPointerCapture(id);}catch(error){}}
        releaseCapture(target,id){try{if(target.hasPointerCapture(id))target.releasePointerCapture(id);}catch(error){}}
        bindButton(target,name){
            const element=this.elements[name];let pointer=null,key=null,ignoreClickUntil=0;
            const down=event=>{if(element.pressed||!this.canTune())return; element.pressed=true;element.fire(event);};
            const up=()=>{const pressed=element.pressed;element.pressed=false;pointer=null;key=null;if(pressed&&this.options.onReleaseTune)this.options.onReleaseTune();};
            this.listen(target,'pointerdown',event=>{if(event.button!==0||pointer!==null)return;event.preventDefault();target.focus({preventScroll:true});pointer=event.pointerId;this.capture(target,pointer);down(event);});
            this.listen(target,'pointerup',event=>{if(event.pointerId!==pointer)return;const id=pointer;up();this.releaseCapture(target,id);});
            for(const type of ['pointercancel','lostpointercapture'])this.listen(target,type,up);
            this.listen(target,'keydown',event=>{if(![' ','Enter'].includes(event.key))return;event.preventDefault();if(event.repeat||key)return;key=event.key;ignoreClickUntil=Date.now()+500;down(event);});
            this.listen(target,'keyup',event=>{if(event.key!==key)return;event.preventDefault();ignoreClickUntil=Date.now()+500;up();});
            this.listen(target,'blur',up);
            this.listen(target,'click',event=>{event.preventDefault();if(event.detail===0&&Date.now()>ignoreClickUntil){down(event);up();}});
        }
        startPTT(event,token){
            if(this.pttHold)return;
            const momentary=!!(this.options.isMomentaryPTT&&this.options.isMomentaryPTT());
            this.pttHold={token,momentary};this.elements.PTT.pressed=true;this.elements.PTTOn.pressed=true;
            this.elements.PTT.fire(event);
        }
        stopPTT(){
            const hold=this.pttHold;if(!hold)return;
            this.pttHold=null;this.elements.PTT.pressed=false;this.elements.PTTOn.pressed=false;
            if(hold.momentary&&this.options.onReleasePTT)this.options.onReleasePTT();
        }
        cancelInteraction(){this.drag=null;this.remainder=0;const tuning=this.elements.PlusButton.pressed||this.elements.MinusButton.pressed;this.elements.PlusButton.pressed=false;this.elements.MinusButton.pressed=false;if(tuning&&this.options.onReleaseTune)this.options.onReleaseTune();this.stopPTT();}
        bind(){
            this.bindButton(this.plus,'PlusButton');this.bindButton(this.minus,'MinusButton');
            this.listen(this.lock,'click',event=>{this.elements.Lock.fire(event);if(this.elements.LockOn.visible){this.drag=null;this.elements.PlusButton.pressed=false;this.elements.MinusButton.pressed=false;}});
            this.listen(this.dial,'pointerdown',event=>{
                if(event.button!==0||this.drag||!this.canTune())return;event.preventDefault();this.dial.focus({preventScroll:true});
                this.drag={id:event.pointerId,angle:this.angle(event)};this.remainder=0;this.capture(this.dial,event.pointerId);
            });
            this.listen(this.dial,'pointermove',event=>{
                if(!this.drag||event.pointerId!==this.drag.id)return;event.preventDefault();
                const angle=this.angle(event);let delta=angle-this.drag.angle;if(delta>180)delta-=360;if(delta<-180)delta+=360;this.drag.angle=angle;
                this.remainder+=delta;const count=Math.trunc(this.remainder/6);if(count){this.remainder-=count*6;this.step(count);}this.paint();
            });
            const endDrag=event=>{if(!this.drag||event.pointerId!==this.drag.id)return;const id=this.drag.id;this.drag=null;this.remainder=0;this.releaseCapture(this.dial,id);this.paint();};
            for(const type of ['pointerup','pointercancel','lostpointercapture'])this.listen(this.dial,type,endDrag);
            this.listen(this.dial,'wheel',event=>{
                if(event.ctrlKey||event.metaKey)return;event.preventDefault();event.stopPropagation();if(!this.canTune()){this.wheelRemainder=0;return;}
                const scale=event.deltaMode===1?1/3:event.deltaMode===2?1:1/100;
                this.wheelRemainder-=event.deltaY*scale;const count=Math.trunc(this.wheelRemainder);if(count){this.wheelRemainder-=count;this.step(count);}
            },{passive:false});
            this.listen(this.dial,'keydown',event=>{const directions={ArrowUp:1,ArrowRight:1,ArrowDown:-1,ArrowLeft:-1};if(!directions[event.key])return;event.preventDefault();event.stopPropagation();this.step(directions[event.key]);});
            let ignorePttClickUntil=0;
            this.listen(this.ptt,'pointerdown',event=>{if(event.button!==0||this.pttHold)return;event.preventDefault();this.ptt.focus({preventScroll:true});this.capture(this.ptt,event.pointerId);this.startPTT(event,event.pointerId);});
            this.listen(this.ptt,'pointerup',event=>{if(this.pttHold?.token!==event.pointerId)return;this.stopPTT();this.releaseCapture(this.ptt,event.pointerId);});
            for(const type of ['pointercancel','lostpointercapture'])this.listen(this.ptt,type,()=>this.stopPTT());
            this.listen(this.ptt,'keydown',event=>{if(![' ','Enter'].includes(event.key))return;event.preventDefault();event.stopPropagation();if(event.repeat||this.pttHold)return;ignorePttClickUntil=Date.now()+500;this.startPTT(event,event.key);});
            this.listen(this.ptt,'keyup',event=>{if(this.pttHold?.token!==event.key)return;event.preventDefault();event.stopPropagation();ignorePttClickUntil=Date.now()+500;this.stopPTT();});
            this.listen(this.ptt,'blur',()=>this.stopPTT());
            this.listen(this.ptt,'click',event=>{event.preventDefault();if(event.detail===0&&Date.now()>ignorePttClickUntil){this.startPTT(event,'assistive');this.stopPTT();}});
            this.listen(global,'blur',()=>this.cancelInteraction());this.listen(global,'pagehide',()=>this.cancelInteraction());
            this.listen(document,'visibilitychange',()=>{if(document.hidden)this.cancelInteraction();});
        }
        dispose(){this.cancelInteraction();this.disposed=true;for(const unbind of this.bindings)unbind();this.bindings=[];this.root.replaceChildren();}
    }
    global.RigPiKnob={Widget};
})(window);
