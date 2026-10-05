#!/usr/bin/env python3
"""Patch the RigPi Ask Elmer page for paired cloud request IDs and feedback."""

import argparse
import os
import shutil
import tempfile
from pathlib import Path


CLOUD_REPLACEMENTS=(
    ('maxlength="1000" required placeholder=',
     'maxlength="600" required placeholder='),
    ('To help improve Elmer, this RigPi stores your rating and comments together with your RigPi username, question, Elmer’s complete answer, model and submission time. During this test, the information remains on this RigPi and is not sent to rigpi.net.',
     'To help improve Elmer, your rating and comments are sent securely to rigpi.net together with your question, Elmer’s complete answer, submission time and this station’s private identifier. Your RigPi username is not sent.'),
    ("let lastQuestion='',lastAnswer='',selectedRating=0;",
     "let lastQuestion='',lastAnswer='',lastRequestId='',selectedRating=0;"),
    ("selectedRating=0;ratingBox.querySelectorAll('button')",
     "selectedRating=0;lastRequestId='';ratingBox.querySelectorAll('button')"),
    ("if(!response.ok)throw new Error('Unable to ask Elmer');const reader=",
     "if(!response.ok){const data=await response.json().catch(()=>({}));throw new Error(data.error||'Unable to ask Elmer')}const reader="),
    ("if(kind==='done'){statusBox.textContent='';",
     "if(kind==='done'){lastRequestId=data.request_id||'';statusBox.textContent='';"),
    ("body:JSON.stringify({username:rigPiUsername,rating:selectedRating,",
     "body:JSON.stringify({request_id:lastRequestId,rating:selectedRating,"),
)

BRAND_REPLACEMENTS=(
    ('  <style>\n    .elmer-wrap',
     '  <style>\n    .elmer-brand{display:flex;align-items:center;gap:12px;margin-bottom:18px}.elmer-brand img{width:48px;height:46px;object-fit:contain}.elmer-brand-rigpi{color:#657780;font-size:.82rem;font-weight:800;letter-spacing:.08em;line-height:1;text-transform:uppercase}.elmer-brand h1{font-size:2rem;line-height:1.05;margin:3px 0 0}.elmer-result-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px}#elmerCopy{display:none}#elmerCopy.visible{display:inline-block}#elmerAnswer figure{margin:18px 0;text-align:center}#elmerAnswer figure img{display:block;max-width:100%;height:auto;margin:auto;border-radius:8px;box-shadow:0 3px 14px #0002}#elmerAnswer figcaption{color:#64717a;font-size:13px;margin-top:6px}.elmer-footer{text-align:center;color:#87949b;font-size:.88rem;margin:22px 0 0}.elmer-footer a{color:#75c7f0;font-weight:700}\n    .elmer-wrap'),
    ('    <h1>Ask Elmer</h1>',
     '    <div class="elmer-brand"><img src="/images/AskElmerRigPiW.png" alt="RigPi logo"><div><div class="elmer-brand-rigpi">RigPi</div><h1>Ask Elmer</h1></div></div>'),
    ('    <div id="elmerStatus"></div><div id="elmerAnswer"></div><div id="elmerMetrics" class="elmer-metrics"></div>',
     '    <div class="elmer-result-toolbar"><div id="elmerStatus"></div><button class="btn btn-outline-secondary btn-sm" id="elmerCopy" type="button">Copy</button></div><div id="elmerAnswer"></div><div id="elmerMetrics" class="elmer-metrics"></div>'),
    ('</main>\n<dialog id="elmerDialog">',
     '</main>\n<footer class="elmer-footer">Learn more about RigPi at <a href="https://rigpi.net" target="_blank" rel="noopener">rigpi.net</a>.</footer>\n<dialog id="elmerDialog">'),
    ("metrics=document.querySelector('#elmerMetrics'),feedback=",
     "metrics=document.querySelector('#elmerMetrics'),copyButton=document.querySelector('#elmerCopy'),feedback="),
    ('function renderMarkdown(text){',
     "function safeImageUrl(raw){try{const u=new URL(raw,location.origin),host=u.hostname.toLowerCase(),local=u.origin===location.origin&&/^\\/(?:Help|images)\\//.test(u.pathname),approved=host==='rigpi.net'||host.endsWith('.rigpi.net')||host==='groups.io'||host.endsWith('.groups.io')||host==='raw.githubusercontent.com'||host==='cdn-bio.qrz.com'||host==='cdn-xml.qrz.com'||host==='files.qrz.com'||host==='static.qrz.com'||host==='hamqth.com'||host==='www.hamqth.com';return (local||(u.protocol==='https:'&&approved))?u.href:''}catch{return ''}}\nfunction renderMarkdown(text){"),
    ("let match;if((match=line.match(/^(#{1,3})\\s+(.+)$/))){",
     "let match;if((match=line.match(/^!\\[([^\\]]*)\\]\\(([^)]+)\\)$/))){closeList();const src=safeImageUrl(match[2]);output.push(src?`<figure><img src=\"${escapeHtml(src)}\" alt=\"${escapeHtml(match[1])}\" loading=\"lazy\" referrerpolicy=\"no-referrer\"><figcaption>${escapeHtml(match[1])}</figcaption></figure>`:`<p>${inlineMarkdown(line)}</p>`)}else if((match=line.match(/^(#{1,3})\\s+(.+)$/))){"),
    ("selectedRating=0;lastRequestId='';ratingBox.querySelectorAll('button')",
     "selectedRating=0;lastRequestId='';copyButton.classList.remove('visible');copyButton.textContent='Copy';ratingBox.querySelectorAll('button')"),
    ("answer.innerHTML=renderMarkdown(complete);metrics.textContent=`First words: ${data.timing.first_text_seconds}s · Complete: ${data.timing.total_seconds}s · Tokens: ${data.usage.total_tokens||'—'}`;",
     "answer.innerHTML=renderMarkdown(complete);copyButton.classList.add('visible');const searched=Number(data.records_searched||0).toLocaleString(),used=Number(data.evidence_used||0).toLocaleString();metrics.textContent=`Searched ${searched} knowledge records · Used ${used} references · First words: ${data.timing.first_text_seconds}s · Complete: ${data.timing.total_seconds}s · Tokens: ${data.usage.total_tokens||'—'}`;"),
    ("for(let rating=1;rating<=10;rating++){",
     "const asDataUrl=blob=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(blob)});\nasync function richAnswerHtml(){const copy=answer.cloneNode(true);await Promise.all([...copy.querySelectorAll('img')].map(async img=>{try{const response=await fetch(img.src,{credentials:'omit'});if(!response.ok)throw new Error('image unavailable');img.src=await asDataUrl(await response.blob())}catch{}}));return `<div style=\"font:17px/1.58 system-ui,-apple-system,sans-serif;color:#202830\">${copy.innerHTML}</div>`}\nasync function copyCompleteAnswer(){if(navigator.clipboard?.write&&window.ClipboardItem){const html=richAnswerHtml().then(value=>new Blob([value],{type:'text/html'}));const plain=Promise.resolve(new Blob([lastAnswer],{type:'text/plain'}));await navigator.clipboard.write([new ClipboardItem({'text/html':html,'text/plain':plain})]);return}await navigator.clipboard.writeText(lastAnswer)}\nfunction copyRenderedAnswer(){const copy=document.createElement('div');copy.contentEditable='true';copy.setAttribute('aria-hidden','true');copy.style.cssText='position:fixed;left:-10000px;top:0;width:760px;background:#fff;color:#202830;padding:20px';copy.innerHTML=answer.innerHTML;document.body.appendChild(copy);const selection=getSelection(),saved=[];for(let i=0;i<selection.rangeCount;i++)saved.push(selection.getRangeAt(i).cloneRange());const range=document.createRange();range.selectNodeContents(copy);selection.removeAllRanges();selection.addRange(range);const copied=document.execCommand('copy');selection.removeAllRanges();saved.forEach(item=>selection.addRange(item));copy.remove();if(!copied)throw new Error('rich copy unavailable')}\ncopyButton.addEventListener('click',async()=>{try{copyRenderedAnswer()}catch{try{await copyCompleteAnswer()}catch{const field=document.createElement('textarea');field.value=lastAnswer;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove()}}copyButton.textContent='Copied';setTimeout(()=>copyButton.textContent='Copy',1600)});\nfor(let rating=1;rating<=10;rating++){"),
)

RICH_COPY_REPLACEMENTS=(
    ("copyButton.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(lastAnswer)}catch{const field=document.createElement('textarea');field.value=lastAnswer;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove()}copyButton.textContent='Copied';setTimeout(()=>copyButton.textContent='Copy',1600)});",
     "const asDataUrl=blob=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(blob)});\nasync function richAnswerHtml(){const copy=answer.cloneNode(true);await Promise.all([...copy.querySelectorAll('img')].map(async img=>{try{const response=await fetch(img.src,{credentials:'omit'});if(!response.ok)throw new Error('image unavailable');img.src=await asDataUrl(await response.blob())}catch{}}));return `<div style=\"font:17px/1.58 system-ui,-apple-system,sans-serif;color:#202830\">${copy.innerHTML}</div>`}\nasync function copyCompleteAnswer(){if(navigator.clipboard?.write&&window.ClipboardItem){const html=richAnswerHtml().then(value=>new Blob([value],{type:'text/html'}));const plain=Promise.resolve(new Blob([lastAnswer],{type:'text/plain'}));await navigator.clipboard.write([new ClipboardItem({'text/html':html,'text/plain':plain})]);return}await navigator.clipboard.writeText(lastAnswer)}\nfunction copyRenderedAnswer(){const copy=document.createElement('div');copy.contentEditable='true';copy.setAttribute('aria-hidden','true');copy.style.cssText='position:fixed;left:-10000px;top:0;width:760px;background:#fff;color:#202830;padding:20px';copy.innerHTML=answer.innerHTML;document.body.appendChild(copy);const selection=getSelection(),saved=[];for(let i=0;i<selection.rangeCount;i++)saved.push(selection.getRangeAt(i).cloneRange());const range=document.createRange();range.selectNodeContents(copy);selection.removeAllRanges();selection.addRange(range);const copied=document.execCommand('copy');selection.removeAllRanges();saved.forEach(item=>selection.addRange(item));copy.remove();if(!copied)throw new Error('rich copy unavailable')}\ncopyButton.addEventListener('click',async()=>{try{copyRenderedAnswer()}catch{try{await copyCompleteAnswer()}catch{const field=document.createElement('textarea');field.value=lastAnswer;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove()}}copyButton.textContent='Copied';setTimeout(()=>copyButton.textContent='Copy',1600)});"),
)

RENDERED_COPY_REPLACEMENTS=(
    ("copyButton.addEventListener('click',async()=>{try{await copyCompleteAnswer()}catch{const field=document.createElement('textarea');field.value=lastAnswer;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove()}copyButton.textContent='Copied';setTimeout(()=>copyButton.textContent='Copy',1600)});",
     "function copyRenderedAnswer(){const copy=document.createElement('div');copy.contentEditable='true';copy.setAttribute('aria-hidden','true');copy.style.cssText='position:fixed;left:-10000px;top:0;width:760px;background:#fff;color:#202830;padding:20px';copy.innerHTML=answer.innerHTML;document.body.appendChild(copy);const selection=getSelection(),saved=[];for(let i=0;i<selection.rangeCount;i++)saved.push(selection.getRangeAt(i).cloneRange());const range=document.createRange();range.selectNodeContents(copy);selection.removeAllRanges();selection.addRange(range);const copied=document.execCommand('copy');selection.removeAllRanges();saved.forEach(item=>selection.addRange(item));copy.remove();if(!copied)throw new Error('rich copy unavailable')}\ncopyButton.addEventListener('click',async()=>{try{copyRenderedAnswer()}catch{try{await copyCompleteAnswer()}catch{const field=document.createElement('textarea');field.value=lastAnswer;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove()}}copyButton.textContent='Copied';setTimeout(()=>copyButton.textContent='Copy',1600)});"),
)

DISPLAYED_COPY_REPLACEMENTS=(
    ("function copyRenderedAnswer(){const copy=document.createElement('div');copy.contentEditable='true';copy.setAttribute('aria-hidden','true');copy.style.cssText='position:fixed;left:-10000px;top:0;width:760px;background:#fff;color:#202830;padding:20px';copy.innerHTML=answer.innerHTML;document.body.appendChild(copy);const selection=getSelection(),saved=[];for(let i=0;i<selection.rangeCount;i++)saved.push(selection.getRangeAt(i).cloneRange());const range=document.createRange();range.selectNodeContents(copy);selection.removeAllRanges();selection.addRange(range);const copied=document.execCommand('copy');selection.removeAllRanges();saved.forEach(item=>selection.addRange(item));copy.remove();if(!copied)throw new Error('rich copy unavailable')}",
     "function copyRenderedAnswer(){const selection=getSelection(),saved=[];for(let i=0;i<selection.rangeCount;i++)saved.push(selection.getRangeAt(i).cloneRange());const range=document.createRange();range.selectNodeContents(answer);selection.removeAllRanges();selection.addRange(range);const copied=document.execCommand('copy');selection.removeAllRanges();saved.forEach(item=>selection.addRange(item));if(!copied)throw new Error('rich copy unavailable')}"),
)

BLUE_HEADER_REPLACEMENTS=(
    ('.elmer-brand{display:flex;align-items:center;gap:12px;margin-bottom:18px}',
     '.elmer-brand{background:#176b99;color:#fff;display:flex;align-items:center;gap:12px;margin:-22px -22px 20px;padding:16px 22px;border-radius:10px 10px 0 0}'),
    ('.elmer-brand-rigpi{color:#657780;',
     '.elmer-brand-rigpi{color:#fff;opacity:.82;'),
)

STATUS_LINK_REPLACEMENTS=(
    ('<button class="btn btn-primary" id="elmerAsk" type="submit">Ask Elmer</button> <small',
     '<button class="btn btn-primary" id="elmerAsk" type="submit">Ask Elmer</button> <a class="btn btn-outline-secondary ml-2" href="/elmer-stats.php">Elmer Status</a> <small'),
)

CALLSIGN_V2="function liveCallsign(text){if(!/\\b(?:who|name|where|tell|about|photo|picture|image|pix|address|mail|postal|qsl|bio|biography|qrz|callbook|lookup|grid|qth|bearing|distance|country|state|location)\\b/i.test(text))return '';const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]);return tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token)&&!/^\\d+(?:HZ|KHZ|MHZ|GHZ)$/.test(token))||''}"
CALLSIGN_V3="function liveCallsign(text){if(!/\\b(?:who|name|where|tell|about|photo|picture|image|pix|address|mail|postal|qsl|bio|biography|email|contact|website|webpage|url|lotw|eqsl|iota|alias|license|timezone|time\\s+zone|qrz|callbook|lookup|grid|qth|bearing|distance|country|state|location)\\b/i.test(text))return '';const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]);return tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token)&&!/^\\d+(?:HZ|KHZ|MHZ|GHZ)$/.test(token))||''}"
CALLSIGN_V4=CALLSIGN_V3.replace(
    '|location)\\b',
    '|location|expire|expires|expired|expiration|expiry|effective|renew|renewal|valid|validity)\\b')
CALLSIGN_V5=CALLSIGN_V4.replace(
    '|location|expire',
    '|location|map|mapped|locate|expire')
CALLSIGN_V6=CALLSIGN_V5.replace(
    '|photo|picture|image|pix|',
    '|photo|photos|photograph|photographs|picture|pictures|image|images|pix|')
CALLSIGN_V7=CALLSIGN_V6.replace(
    '|photo|photos|',
    '|photo|photos|photographie|photographies|adresse|biographie|indicatif|')
STANDALONE_CALL_PREFIX="function liveCallsign(text){const standalone=String(text||'').trim().toUpperCase().replace(/[?.!,;:]+$/,'');if(/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(standalone)&&!/^\\d+(?:HZ|KHZ|MHZ|GHZ)$/.test(standalone))return standalone;if(!"
CALLSIGN_CURRENT=CALLSIGN_V7.replace("function liveCallsign(text){if(!",STANDALONE_CALL_PREFIX).replace(
    '|location|map|mapped|locate|expire',
    '|location|map|mapped|locate|rig|radio|radios|transceiver|equipment|station|shack|antenna|amplifier|amp|expire')
DETAILS_V2="function requestedCallbookDetails(text){const include=[];if(/\\b(?:address|mailing|postal|qsl(?:\\s+card)?)\\b/i.test(text))include.push('address');if(/\\b(?:bio|biography|about|tell\\s+me\\s+about)\\b/i.test(text))include.push('biography');if(/\\b(?:photo|picture|image|pix|look\\s+like)\\b/i.test(text))include.push('image');return include}"
DETAILS_V3="function requestedCallbookDetails(text){const include=[];if(/\\b(?:address|mailing|postal|qsl(?:\\s+card)?)\\b/i.test(text))include.push('address');if(/\\b(?:bio|biography|about|tell\\s+me\\s+about)\\b/i.test(text))include.push('biography');if(/\\b(?:photo|picture|image|pix|look\\s+like)\\b/i.test(text))include.push('image');if(/\\b(?:email|e-mail|contact)\\b/i.test(text))include.push('email');if(/\\b(?:website|webpage|web\\s+page|url)\\b/i.test(text))include.push('website');if(/\\b(?:qsl|lotw|eqsl|paper\\s+qsl|qsl\\s+manager)\\b/i.test(text))include.push('qsl');if(/\\b(?:alias|previous\\s+call|iota|license|class|timezone|time\\s+zone|gmt|utc\\s+offset|daylight\\s+saving)\\b/i.test(text))include.push('details');return [...new Set(include)]}"
DETAILS_V4=DETAILS_V3.replace(
    '|daylight\\s+saving)\\b',
    '|daylight\\s+saving|expire|expires|expired|expiration|expiry|effective|renew|renewal|valid|validity)\\b')
DETAILS_V5=DETAILS_V4.replace(
    '(?:photo|picture|image|pix|',
    '(?:photo|photos|photograph|photographs|picture|pictures|image|images|pix|')
DETAILS_V6=DETAILS_V5.replace(
    '(?:address|mailing|postal|',
    '(?:address|adresse|mailing|postal|').replace(
    '(?:bio|biography|about|',
    '(?:bio|biography|biographie|about|').replace(
    '(?:photo|photos|',
    '(?:photo|photos|photographie|photographies|')
DETAILS_CURRENT=DETAILS_V6.replace(
    '(?:bio|biography|biographie|about|tell\\s+me\\s+about)',
    '(?:bio|biography|biographie|about|tell\\s+me\\s+about|rig|radio|radios|transceiver|equipment|station|shack|antenna|amplifier|amp)')
LIVE_CONTEXT_JS_V1="async function liveContextFor(text){const call=liveCallsign(text);if(!call)return null;statusBox.textContent=`Elmer is checking the station callbook for ${call}…`;const response=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call,include:requestedCallbookDetails(text)})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||`RigPi could not look up ${call}.`);return {callbook:data}}"
FCC_REQUEST_JS_V1="function requestedFccSearch(text){if(!/\\b(?:hams?|amateurs?|operators?|licensees?|callsigns?|fcc)\\b/i.test(text))return null;const zip=(text.match(/\\b(\\d{5})\\b/)||[])[1]||'',nearMe=/\\b(?:near|around|close\\s+to|witt?hin(?:\\s+\\S+){0,4}\\s+(?:of|on)|from)\\s+me\\b/i.test(text),match=text.match(/\\b(?:witt?hin|inside|radius(?:\\s+of)?)?\\s*(a|one|\\d+(?:\\.\\d+)?)\\s*(miles?|mi|kilometers?|kilometres?|km)\\b/i);let radius=null;if(match){radius=/^(?:a|one)$/i.test(match[1])?1:Number(match[1]);if(/^k/i.test(match[2]))radius/=1.609344}if(!zip&&!nearMe)return null;if(!zip&&radius===null)return null;return {postal_code:zip,center:nearMe?'user':'postal',radius_miles:radius,limit:25}}"
FCC_REQUEST_JS_V2="function requestedFccSearch(text){if(!/\\b(?:hams?|amateurs?|operators?|licensees?|callsigns?|fcc)\\b/i.test(text))return null;const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]),call=tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token))||'';if(call&&/\\bfcc\\b/i.test(text)&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};const zip=(text.match(/\\b(\\d{5})\\b/)||[])[1]||'',nearMe=/\\b(?:near|around|close\\s+to|witt?hin(?:\\s+\\S+){0,4}\\s+(?:of|on)|from)\\s+me\\b/i.test(text),match=text.match(/\\b(?:witt?hin|inside|radius(?:\\s+of)?)?\\s*(a|one|\\d+(?:\\.\\d+)?)\\s*(miles?|mi|kilometers?|kilometres?|km)\\b/i);let radius=null;if(match){radius=/^(?:a|one)$/i.test(match[1])?1:Number(match[1]);if(/^k/i.test(match[2]))radius/=1.609344}if(!zip&&!nearMe)return null;if(!zip&&radius===null)return null;return {postal_code:zip,center:nearMe?'user':'postal',radius_miles:radius,limit:25}}"
FCC_REQUEST_JS="function requestedFccSearch(text){const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]),call=tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token))||'';if(call&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};if(!/\\b(?:hams?|amateurs?|operators?|licensees?|callsigns?|fcc)\\b/i.test(text))return null;const zip=(text.match(/\\b(\\d{5})\\b/)||[])[1]||'',nearMe=/\\b(?:near|around|close\\s+to|witt?hin(?:\\s+\\S+){0,4}\\s+(?:of|on)|from)\\s+me\\b/i.test(text),match=text.match(/\\b(?:witt?hin|inside|radius(?:\\s+of)?)?\\s*(a|one|\\d+(?:\\.\\d+)?)\\s*(miles?|mi|kilometers?|kilometres?|km)\\b/i);let radius=null;if(match){radius=/^(?:a|one)$/i.test(match[1])?1:Number(match[1]);if(/^k/i.test(match[2]))radius/=1.609344}if(!zip&&!nearMe)return null;if(!zip&&radius===null)return null;return {postal_code:zip,center:nearMe?'user':'postal',radius_miles:radius,limit:25}}"
LIVE_CONTEXT_JS_V2=FCC_REQUEST_JS_V1+"\nasync function liveContextFor(text){const context={},call=liveCallsign(text),fcc=requestedFccSearch(text);if(call){statusBox.textContent=`Elmer is checking the station callbook for ${call}…`;const response=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call,include:requestedCallbookDetails(text)})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||`RigPi could not look up ${call}.`);context.callbook=data}if(fcc){statusBox.textContent='Elmer is searching the station FCC database…';const response=await fetch('/programs/ElmerFCC.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(fcc)});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not search the FCC database.');context.fcc_search=data}return Object.keys(context).length?context:null}"
CALLBOOK_PROVIDER_JS_V1="function requestedCallbookProvider(text){return /\\bfcc(?:\\s+(?:record|data|database|information|info|lookup|search))?\\b/i.test(text)?'fcc':'auto'}"
CALLBOOK_PROVIDER_JS="function requestedCallbookProvider(text){if(/\\bqrz\\b/i.test(text))return 'auto';return /\\bfcc(?:\\s+(?:record|data|database|information|info|lookup|search))?\\b/i.test(text)?'fcc':'auto'}"
LIVE_CONTEXT_JS_V3=FCC_REQUEST_JS_V1+'\n'+CALLBOOK_PROVIDER_JS_V1+"\nasync function liveContextFor(text){const context={},call=liveCallsign(text),fcc=requestedFccSearch(text);if(call){statusBox.textContent=`Elmer is checking the station callbook for ${call}…`;const response=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call,include:requestedCallbookDetails(text),provider:requestedCallbookProvider(text)})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||`RigPi could not look up ${call}.`);context.callbook=data}if(fcc){statusBox.textContent='Elmer is searching the station FCC database…';const response=await fetch('/programs/ElmerFCC.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(fcc)});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not search the FCC database.');context.fcc_search=data}return Object.keys(context).length?context:null}"
LIVE_CONTEXT_JS_V4=LIVE_CONTEXT_JS_V3.replace(
    'context.fcc_search=data',
    'lastFccMap=data.map||null;delete data.map;context.fcc_search=data')
LIVE_CONTEXT_JS_V5=(FCC_REQUEST_JS_V2+'\n'+CALLBOOK_PROVIDER_JS+LIVE_CONTEXT_JS_V3.split(CALLBOOK_PROVIDER_JS_V1,1)[1]).replace(
    'context.fcc_search=data',
    'lastFccMap=data.map||null;delete data.map;context.fcc_search=data')
FCC_REQUEST_JS_CLUBS=FCC_REQUEST_JS.replace(
    'hams?|amateurs?|operators?|licensees?|callsigns?|fcc)',
    'hams?|amateurs?|operators?|licensees?|callsigns?|fcc|clubs?|groups?|associations?)').replace(
    "radius_miles:radius,limit:25}}",
    "radius_miles:radius,limit:25,clubs_only:/\\b(?:clubs?|groups?|associations?)\\b/i.test(text)}}")
FCC_REQUEST_JS_LOCAL_CLUBS="function requestedFccSearch(text){const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]),call=tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token))||'',directoryIntent=/\\b(?:hams?|amateurs?|operators?|licensees?|callsigns?|clubs?|groups?|associations?)\\b/i.test(text);if(call&&!directoryIntent&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};const clubsOnly=/\\b(?:clubs?|groups?|associations?)\\b/i.test(text);if(!directoryIntent)return null;const zip=(text.match(/\\b(\\d{5})\\b/)||[])[1]||'',localIntent=/\\b(?:local|nearby|near\\s+me|in\\s+my\\s+area|around\\s+here|close\\s+to\\s+me)\\b/i.test(text),nearMe=localIntent||/\\b(?:near|around|close\\s+to|witt?hin(?:\\s+\\S+){0,4}\\s+(?:of|on)|from)\\s+me\\b/i.test(text),match=text.match(/\\b(?:witt?hin|inside|radius(?:\\s+of)?)?\\s*(a|one|\\d+(?:\\.\\d+)?)\\s*(miles?|mi|kilometers?|kilometres?|km)\\b/i);let radius=null;if(match){radius=/^(?:a|one)$/i.test(match[1])?1:Number(match[1]);if(/^k/i.test(match[2]))radius/=1.609344}if(localIntent&&radius===null)radius=clubsOnly?25:10;if(!zip&&!nearMe)return null;if(!zip&&radius===null)return null;return {postal_code:zip,center:nearMe?'user':'postal',radius_miles:radius,limit:25,clubs_only:clubsOnly}}"
FCC_REQUEST_JS_LOCAL_CLUBS=FCC_REQUEST_JS_LOCAL_CLUBS.replace(
    "||'';if(call&&",
    "||'',callCenter=call&&new RegExp('(?:near|nearby|around|close\\\\s+to|(?:within|inside)(?:\\\\s+\\\\S+){0,5}\\\\s+of)\\\\s+'+call+'\\\\b','i').test(text);if(call&&").replace(
    "if(localIntent&&radius===null)radius=clubsOnly?25:10;",
    "if(callCenter&&radius===null)radius=10;if(localIntent&&radius===null)radius=clubsOnly?25:10;").replace(
    "if(!zip&&!nearMe)return null;",
    "if(!zip&&!nearMe&&!callCenter)return null;").replace(
    "center:nearMe?'user':'postal',radius_miles:radius",
    "center:callCenter?'call':nearMe?'user':'postal',center_call:callCenter?call:'',radius_miles:radius")
LIVE_CONTEXT_JS=(FCC_REQUEST_JS_LOCAL_CLUBS+'\n'+CALLBOOK_PROVIDER_JS+LIVE_CONTEXT_JS_V3.split(CALLBOOK_PROVIDER_JS_V1,1)[1]).replace(
    'context.fcc_search=data',
    'lastFccMap=data.map||null;delete data.map;context.fcc_search=data')
LOCAL_CLUB_QTH_REPLACEMENTS=(
    ("nearMe=/\\b(?:near|around|close\\s+to|witt?hin(?:\\s+\\S+){0,4}\\s+(?:of|on)|from)\\s+me\\b/i.test(text),match=",
     "localIntent=/\\b(?:local|nearby|near\\s+me|in\\s+my\\s+area|around\\s+here|close\\s+to\\s+me)\\b/i.test(text),nearMe=localIntent||/\\b(?:near|around|close\\s+to|witt?hin(?:\\s+\\S+){0,4}\\s+(?:of|on)|from)\\s+me\\b/i.test(text),match="),
    ("if(!zip&&!nearMe)return null;if(!zip&&radius===null)return null;",
     "const clubsOnly=/\\b(?:clubs?|groups?|associations?)\\b/i.test(text);if(localIntent&&radius===null)radius=clubsOnly?25:10;if(!zip&&!nearMe)return null;if(!zip&&radius===null)return null;"),
    ("return {postal_code:zip,center:nearMe?'user':'postal',radius_miles:radius,limit:25,clubs_only:/\\b(?:clubs?|groups?|associations?)\\b/i.test(text),license_class_code:licenseClassCode}}",
     "return {postal_code:zip,center:nearMe?'user':'postal',radius_miles:radius,limit:25,clubs_only:clubsOnly,license_class_code:licenseClassCode}}"),
)
LOCAL_HAMS_QTH_REPLACEMENTS=(
    ("if(clubsOnly&&localIntent&&radius===null)radius=25;",
     "if(localIntent&&radius===null)radius=clubsOnly?25:10;"),
)
LOCAL_FCC_PRECEDENCE_REPLACEMENTS=(
    ("detectedFcc=requestedFccSearch(text)",
     "detectedFcc=requestedFccSearch(String(plan.english_search_query||text))"),
    ("const source=fcc.enabled?fcc:detectedFcc,request=",
     "const source=detectedFcc?{...fcc,...detectedFcc}:fcc,request="),
)
CALLBOOK_EQUIPMENT_REPLACEMENTS=(
    ("|location|map|mapped|locate|expire",
     "|location|map|mapped|locate|rig|radio|radios|transceiver|equipment|station|shack|antenna|amplifier|amp|expire"),
    ("(?:bio|biography|biographie|about|tell\\s+me\\s+about)\\b/i.test(text))include.push('biography');",
     "(?:bio|biography|biographie|about|tell\\s+me\\s+about|rig|radio|radios|transceiver|equipment|station|shack|antenna|amplifier|amp)\\b/i.test(text))include.push('biography');"),
    ("include:Array.isArray(resolvedBook.include)?[...new Set(resolvedBook.include)]:[]",
     "include:[...new Set([...(Array.isArray(resolvedBook.include)?resolvedBook.include:[]),...requestedCallbookDetails(String(plan.english_search_query||text))])]")
)
CALL_CENTER_FCC_REPLACEMENTS=(
    ("if(call&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};if(!/\\b(?:hams?",
     "if(call&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};const callCenter=call&&new RegExp('(?:near|nearby|around|close\\\\s+to|(?:within|inside)(?:\\\\s+\\\\S+){0,5}\\\\s+of)\\\\s+'+call+'\\\\b','i').test(text);if(!/\\b(?:hams?"),
    ("if(localIntent&&radius===null)radius=clubsOnly?25:10;",
     "if(callCenter&&radius===null)radius=10;if(localIntent&&radius===null)radius=clubsOnly?25:10;"),
    ("if(!zip&&!nearMe)return null;",
     "if(!zip&&!nearMe&&!callCenter)return null;"),
    ("center:nearMe?'user':'postal',radius_miles:radius",
     "center:callCenter?'call':nearMe?'user':'postal',center_call:callCenter?call:'',radius_miles:radius"),
    ("center:source.center==='postal'?'postal':source.center==='city'?'city':'user'",
     "center:source.center==='postal'?'postal':source.center==='city'?'city':source.center==='call'?'call':'user'"),
    ("if(source.center_state)request.center_state=source.center_state;",
     "if(source.center_state)request.center_state=source.center_state;if(source.center_call)request.center_call=source.center_call;"),
)
CALL_CENTER_FILTER_REPLACEMENTS=(
    ("if(source.call)request.call=source.call;",
     "if(source.call&&source.center!=='call')request.call=source.call;"),
)
SHOW_CALL_DIRECTORY_REPLACEMENTS=(
    ("if(call&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};",
     "if(call&&!/\\b(?:hams?|amateurs?|operators?|licensees?|callsigns?|clubs?|groups?|associations?)\\b/i.test(text)&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};"),
)
CALL_CENTER_QRZ_FALLBACK_JS="""if(source.center==='call'&&source.center_call){const centerResponse=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call:source.center_call,include:['details'],provider:'auto'})});const centerData=await centerResponse.json().catch(()=>({}));if(centerResponse.ok){const latitude=Number(centerData._center_latitude),longitude=Number(centerData._center_longitude);if(Number.isFinite(latitude)&&Number.isFinite(longitude)){request.center_latitude=latitude;request.center_longitude=longitude;request.center_basis=String(centerData._center_basis||'callbook coordinates')}}}"""
CALL_CENTER_QRZ_FALLBACK_REPLACEMENTS=(
    ("context.callbook=data}if(fcc){",
     "delete data._center_latitude;delete data._center_longitude;delete data._center_basis;context.callbook=data}if(fcc){"),
    ("context.callbook=data}if(fcc.enabled||detectedFcc){",
     "delete data._center_latitude;delete data._center_longitude;delete data._center_basis;context.callbook=data}if(fcc.enabled||detectedFcc){"),
    ("if(source.center_call)request.center_call=source.center_call;",
     "if(source.center_call)request.center_call=source.center_call;"+CALL_CENTER_QRZ_FALLBACK_JS),
)

MULTILINGUAL_PLAN_JS="""async function queryPlanFor(text){const response=await fetch('/elmer-api/plan',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:text})});const plan=await response.json().catch(()=>({}));if(!response.ok)throw new Error(plan.error||'Elmer could not plan this question.');window.dispatchEvent(new CustomEvent('elmer-query-plan',{detail:plan}));return plan}
async function liveContextFromPlan(plan,text){if(!plan||typeof plan!=='object')return liveContextFor(text);const context={},book=plan.callbook||{},fcc=plan.fcc_search||{};if(book.enabled&&book.call){statusBox.textContent=`Elmer is checking the station callbook for ${book.call}…`;const response=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call:book.call,include:Array.isArray(book.include)?book.include:[],provider:book.provider==='fcc'?'fcc':'auto'})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||`RigPi could not look up ${book.call}.`);context.callbook=data}if(fcc.enabled){statusBox.textContent='Elmer is searching the station FCC database…';const request={center:fcc.center==='postal'?'postal':'user',limit:Number(fcc.limit)||25};if(fcc.call)request.call=fcc.call;if(fcc.postal_code)request.postal_code=fcc.postal_code;if(Number(fcc.radius_miles)>=0)request.radius_miles=Number(fcc.radius_miles);const response=await fetch('/programs/ElmerFCC.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(request)});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not search the FCC database.');lastFccMap=data.map||null;delete data.map;context.fcc_search=data}return Object.keys(context).length?context:null}"""
MULTILINGUAL_PLAN_JS=MULTILINGUAL_PLAN_JS.replace(
    "const context={},book=plan.callbook||{},fcc=plan.fcc_search||{};",
    "const context={},book=plan.callbook||{},fcc=plan.fcc_search||{},detectedFcc=requestedFccSearch(text);").replace(
    "if(fcc.enabled){statusBox.textContent='Elmer is searching the station FCC database…';const request={center:fcc.center==='postal'?'postal':'user',limit:Number(fcc.limit)||25};if(fcc.call)request.call=fcc.call;if(fcc.postal_code)request.postal_code=fcc.postal_code;if(Number(fcc.radius_miles)>=0)request.radius_miles=Number(fcc.radius_miles);",
    "if(fcc.enabled||detectedFcc){statusBox.textContent='Elmer is searching the station FCC database…';const source=detectedFcc?{...fcc,...detectedFcc}:fcc,request={center:source.center==='postal'?'postal':'user',limit:Number(source.limit)||25};if(source.call)request.call=source.call;if(source.postal_code)request.postal_code=source.postal_code;if(Number(source.radius_miles)>=0)request.radius_miles=Number(source.radius_miles);if(detectedFcc?.clubs_only)request.clubs_only=true;")
MULTILINGUAL_PLAN_JS_WITHOUT_SHORTWAVE=MULTILINGUAL_PLAN_JS
MULTILINGUAL_PLAN_JS=MULTILINGUAL_PLAN_JS.replace(
    "const context={},book=plan.callbook||{},fcc=plan.fcc_search||{},detectedFcc=requestedFccSearch(text);",
    "const context={},book=plan.callbook||{},fcc=plan.fcc_search||{},shortwave=plan.shortwave_search||{},detectedFcc=requestedFccSearch(text);").replace(
    "return Object.keys(context).length?context:null}",
    "if(shortwave.enabled){statusBox.textContent='Elmer is searching the local shortwave schedule…';const response=await fetch('/programs/ElmerShortwave.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({language:String(shortwave.language||''),station:String(shortwave.station||''),active_now:shortwave.active_now!==false,limit:Number(shortwave.limit)||50})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not search the shortwave schedule.');window.elmerShortwaveRows=Array.isArray(data.results)?data.results:[];context.shortwave_search=data}return Object.keys(context).length?context:null}")
MULTILINGUAL_PLAN_JS=MULTILINGUAL_PLAN_JS.replace(
    "detectedFcc=requestedFccSearch(text);if(book.enabled&&book.call)",
    "detectedFcc=requestedFccSearch(text),detectedCall=liveCallsign(text),resolvedBook=book.enabled&&book.call?book:(detectedCall?{enabled:true,call:detectedCall,provider:'auto',include:requestedCallbookDetails(text).concat('details')}:book);if(resolvedBook.enabled&&resolvedBook.call)").replace(
    '${book.call}','${resolvedBook.call}').replace(
    'call:book.call','call:resolvedBook.call').replace(
    'Array.isArray(book.include)?book.include:[]','Array.isArray(resolvedBook.include)?[...new Set(resolvedBook.include)]:[]').replace(
    "book.provider==='fcc'","resolvedBook.provider==='fcc'")
WEATHER_FETCH_JS_OLD="""if(weather.enabled){statusBox.textContent='Elmer is checking current weather and the forecast…';const response=await fetch('/programs/ElmerWeather.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({target:String(weather.target||'user'),call:String(weather.call||''),location:String(weather.location||''),forecast_days:Number(weather.forecast_days)||3})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not retrieve the weather.');context.weather=data}"""
WEATHER_FETCH_JS="""if(weather.enabled){statusBox.textContent=weather.mode==='historical'?'Elmer is retrieving historical weather…':'Elmer is checking operating weather and the forecast…';const response=await fetch('/programs/ElmerWeather.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({target:String(weather.target||'user'),call:String(weather.call||''),location:String(weather.location||''),mode:String(weather.mode||'forecast'),forecast_days:Number(weather.forecast_days)||3,hourly_hours:Number(weather.hourly_hours)||0,antenna_safety:weather.antenna_safety===true,historical_date:String(weather.historical_date||'')})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not retrieve the weather.');context.weather=data}"""
MULTILINGUAL_PLAN_JS=MULTILINGUAL_PLAN_JS.replace(
    "shortwave=plan.shortwave_search||{},detectedFcc=",
    "shortwave=plan.shortwave_search||{},weather=plan.weather_search||{},detectedFcc=").replace(
    "context.shortwave_search=data}return Object.keys(context).length?context:null}",
    "context.shortwave_search=data}"+WEATHER_FETCH_JS+"return Object.keys(context).length?context:null}")
LOGBOOK_REQUEST_JS=r"""function requestedLogbookSearch(text){const value=String(text||'');if(!/\b(?:have\s+i\s+worked|worked\s+(?:him|her|them|it|this\s+station)?\s*before|when\s+did\s+i\s+work|last\s+(?:worked|qso|contact)|how\s+many\s+(?:contacts?|qsos?)|(?:contacts?|qsos?)\s+with|contact\s+history|logbook)\b/i.test(value))return null;const tokens=(value.toUpperCase().match(/[A-Z0-9]+(?:\/[A-Z0-9]+)?/g)||[]),call=tokens.find(token=>/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/.test(token)&&!/^\d+(?:HZ|KHZ|MHZ|GHZ)$/.test(token))||'';return call?{call}:null}"""
LOGBOOK_FETCH_JS="""const logbookRequest=requestedLogbookSearch(String(plan.english_search_query||text));if(logbookRequest){statusBox.textContent=`Elmer is checking your RigPi logbook for ${logbookRequest.call}…`;const response=await fetch('/programs/ElmerLogbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Elmer-Action':'logbook-history'},body:JSON.stringify(logbookRequest)});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not search your logbook.');context.logbook_history=data}"""
MULTILINGUAL_PLAN_JS=LOGBOOK_REQUEST_JS+'\n'+MULTILINGUAL_PLAN_JS.replace(
    'return Object.keys(context).length?context:null}',
    LOGBOOK_FETCH_JS+'return Object.keys(context).length?context:null}')
MULTILINGUAL_PLAN_JS=MULTILINGUAL_PLAN_JS.replace(
    ":book);if(resolvedBook.enabled&&resolvedBook.call)",
    ":book),logbookRequest=requestedLogbookSearch(String(plan.english_search_query||text));if(!logbookRequest&&resolvedBook.enabled&&resolvedBook.call)").replace(
    "const logbookRequest=requestedLogbookSearch(String(plan.english_search_query||text));if(logbookRequest)",
    "if(logbookRequest)")
MULTILINGUAL_PLAN_JS=MULTILINGUAL_PLAN_JS.replace(
    "detectedFcc=requestedFccSearch(text)",
    "detectedFcc=requestedFccSearch(String(plan.english_search_query||text))").replace(
    "center:source.center==='postal'?'postal':'user'",
    "center:source.center==='postal'?'postal':source.center==='call'?'call':'user'").replace(
    "if(source.postal_code)request.postal_code=source.postal_code;",
    "if(source.postal_code)request.postal_code=source.postal_code;if(source.center_call)request.center_call=source.center_call;").replace(
    "if(source.call)request.call=source.call;",
    "if(source.call&&source.center!=='call')request.call=source.call;").replace(
    "context.callbook=data}",
    "delete data._center_latitude;delete data._center_longitude;delete data._center_basis;context.callbook=data}").replace(
    "if(source.center_call)request.center_call=source.center_call;",
    "if(source.center_call)request.center_call=source.center_call;"+CALL_CENTER_QRZ_FALLBACK_JS)
LOGBOOK_CONTEXT_REPLACEMENTS=(
    ('async function queryPlanFor(text)',LOGBOOK_REQUEST_JS+'\nasync function queryPlanFor(text)'),
    ('context.weather=data}return Object.keys(context).length?context:null}',
     'context.weather=data}'+LOGBOOK_FETCH_JS+'return Object.keys(context).length?context:null}'),
)
LOGBOOK_ONLY_CONTEXT_REPLACEMENTS=(
    (':book);if(resolvedBook.enabled&&resolvedBook.call)',
     ':book),logbookRequest=requestedLogbookSearch(String(plan.english_search_query||text));if(!logbookRequest&&resolvedBook.enabled&&resolvedBook.call)'),
    ('const logbookRequest=requestedLogbookSearch(String(plan.english_search_query||text));if(logbookRequest)',
     'if(logbookRequest)'),
)
SHORTWAVE_PLAN_UPGRADE_REPLACEMENTS=((MULTILINGUAL_PLAN_JS_WITHOUT_SHORTWAVE,MULTILINGUAL_PLAN_JS),)
SHORTWAVE_CURRENT_UPGRADE_REPLACEMENTS=(
    ("const context={},book=plan.callbook||{},fcc=plan.fcc_search||{},detectedFcc=requestedFccSearch(text);",
     "const context={},book=plan.callbook||{},fcc=plan.fcc_search||{},shortwave=plan.shortwave_search||{},detectedFcc=requestedFccSearch(text);"),
    ("}return Object.keys(context).length?context:null}\nasync function rigControlFromPlan(plan)",
     "}if(shortwave.enabled){statusBox.textContent='Elmer is searching the local shortwave schedule…';const response=await fetch('/programs/ElmerShortwave.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({language:String(shortwave.language||''),station:String(shortwave.station||''),active_now:shortwave.active_now!==false,limit:Number(shortwave.limit)||50})});const data=await response.json().catch(()=>({}));if(response.status===401||response.status===403){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not search the shortwave schedule.');window.elmerShortwaveRows=Array.isArray(data.results)?data.results:[];context.shortwave_search=data}return Object.keys(context).length?context:null}\nasync function rigControlFromPlan(plan)"),
)

SHORTWAVE_CLICK_JS=r"""function shortwaveFrequencyFromLabel(label){const text=String(label||'').replaceAll(',',''),match=text.match(/(\d+(?:\.\d+)?)\s*(kHz|MHz)/i);if(!match)return 0;return Math.round(Number(match[1])*(match[2].toLowerCase()==='mhz'?1000000:1000))}
function tuneShortwaveFromElement(element){const frequency=Number(element?.dataset.shortwaveFrequency),mode=String(element?.dataset.shortwaveMode||'AM').toUpperCase(),station=String(element?.dataset.shortwaveStation||'shortwave station');if(!Number.isFinite(frequency)||frequency<100000||frequency>30000000)return;const mhz=(frequency/1000000).toFixed(6);localStorage.setItem('elmerPendingControlQuestion',`Tune to ${mhz} MHz ${mode}`);statusBox.textContent=`Opening Elmer Control to tune ${station} on ${(frequency/1000).toLocaleString()} kHz…`;location.href='/?elmer-control=1#elmerControl'}
function linkShortwaveFrequencies(){const rows=Array.isArray(window.elmerShortwaveRows)?window.elmerShortwaveRows:[];if(!rows.length)return;const pattern=/\b(?:\d{1,2}(?:,\d{3})|\d{4,5})(?:\.\d+)?\s*kHz\b|\b\d{1,2}(?:\.\d{1,6})?\s*MHz\b/gi,walker=document.createTreeWalker(answer,NodeFilter.SHOW_TEXT),nodes=[];while(walker.nextNode()){const node=walker.currentNode;if(node.parentElement?.closest('a,button,code'))continue;pattern.lastIndex=0;if(pattern.test(node.data))nodes.push(node)}for(const node of nodes){pattern.lastIndex=0;const fragment=document.createDocumentFragment();let offset=0,match;while((match=pattern.exec(node.data))){fragment.append(document.createTextNode(node.data.slice(offset,match.index)));const frequency=shortwaveFrequencyFromLabel(match[0]),row=rows.find(item=>Math.abs(Number(item.frequency_hz)-frequency)<=50);if(row){const button=document.createElement('button');button.type='button';button.className='elmer-repeater-tune elmer-shortwave-tune';button.dataset.shortwaveFrequency=String(row.frequency_hz);button.dataset.shortwaveMode=String(row.mode||'AM');button.dataset.shortwaveStation=String(row.station||'shortwave station');button.title=`Tune ${row.station||'this broadcast'} through Elmer Control`;button.textContent=match[0];fragment.append(button)}else fragment.append(document.createTextNode(match[0]));offset=pattern.lastIndex}fragment.append(document.createTextNode(node.data.slice(offset)));node.replaceWith(fragment)}}
answer.addEventListener('click',event=>{const tune=event.target.closest('.elmer-shortwave-tune');if(tune){event.preventDefault();tuneShortwaveFromElement(tune)}})"""
SHORTWAVE_CLICK_REPLACEMENTS=(
    ('async function rigControlFromPlan(plan)', SHORTWAVE_CLICK_JS+'\nasync function rigControlFromPlan(plan)'),
    ('linkFccCallsigns();copyButton.classList.add',
     'linkFccCallsigns();linkShortwaveFrequencies();copyButton.classList.add'),
)
SHORTWAVE_ROW_CACHE_REPLACEMENTS=(
    ('context.shortwave_search=data}return Object.keys(context)',
     'window.elmerShortwaveRows=Array.isArray(data.results)?data.results:[];context.shortwave_search=data}return Object.keys(context)'),
)

DX_CALL_LINK_JS="""let pendingDxLookup=Promise.resolve();
function callsignFromQrzLink(link){try{const url=new URL(link.href,location.href);if(!/(^|\\.)qrz\\.com$/i.test(url.hostname))return '';const match=url.pathname.match(/^\\/db\\/([^/]+)/i),call=match?decodeURIComponent(match[1]).toUpperCase().trim():'';return /^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(call)?call:''}catch{return ''}}
function primeDxCall(call){document.querySelector('#searchText')?.setAttribute('value',call);if(window.jQuery)jQuery('#searchText').val(call);const body=new URLSearchParams({field:'DX',radio:String(rigPiRadio||1),data:call,table:'MySettings'});pendingDxLookup=fetch('/programs/SetSettings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(response=>{if(!response.ok)throw new Error('RigPi could not update DX Call.');return response.text()}).catch(error=>{console.warn('DX Call callbook refresh failed:',error);throw error})}
answer.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(!link)return;const call=callsignFromQrzLink(link);if(call)primeDxCall(call)})
fccList?.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(!link)return;const call=callsignFromQrzLink(link);if(call)primeDxCall(call)})"""
DX_CALL_LINK_REPLACEMENTS=(
    ('function linkFccCallsigns(){',DX_CALL_LINK_JS+'\nfunction linkFccCallsigns(){'),
    ('async function loadQsoIdeas(){qsoIdeasButton.disabled=true;try{const response=',
     'async function loadQsoIdeas(){qsoIdeasButton.disabled=true;try{await pendingDxLookup;const response='),
)
DX_CALL_BACKGROUND_REPLACEMENTS=(
    ('function callsignFromQrzLink(link){','let pendingDxLookup=Promise.resolve();\nfunction callsignFromQrzLink(link){'),
    ("const body=new URLSearchParams({field:'DX',radio:String(rigPiRadio||1),data:call,table:'MySettings'});fetch('/programs/SetSettings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),keepalive:true}).catch(error=>console.warn('DX Call could not be primed:',error))",
     "const body=new URLSearchParams({field:'DX',radio:String(rigPiRadio||1),data:call,table:'MySettings'}),settingsRequest=fetch('/programs/SetSettings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),keepalive:true}),callbookRequest=fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call,include:['biography','details'],provider:'auto'})});pendingDxLookup=Promise.allSettled([settingsRequest,callbookRequest]).then(()=>undefined)"),
    ('async function loadQsoIdeas(){qsoIdeasButton.disabled=true;try{const response=',
     'async function loadQsoIdeas(){qsoIdeasButton.disabled=true;try{await pendingDxLookup;const response='),
)
DX_CALL_CENTRAL_REFRESH_REPLACEMENTS=((
    "const body=new URLSearchParams({field:'DX',radio:String(rigPiRadio||1),data:call,table:'MySettings'}),settingsRequest=fetch('/programs/SetSettings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),keepalive:true}),callbookRequest=fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call,include:['biography','details'],provider:'auto'})});pendingDxLookup=Promise.allSettled([settingsRequest,callbookRequest]).then(()=>undefined)",
    "const body=new URLSearchParams({field:'DX',radio:String(rigPiRadio||1),data:call,table:'MySettings'});pendingDxLookup=fetch('/programs/SetSettings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(response=>{if(!response.ok)throw new Error('RigPi could not update DX Call.');return response.text()}).catch(error=>{console.warn('DX Call callbook refresh failed:',error);throw error})"
),)
FCC_DIRECTORY_DX_REPLACEMENTS=((
    "answer.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(!link)return;const call=callsignFromQrzLink(link);if(call)primeDxCall(call)})",
    "answer.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(!link)return;const call=callsignFromQrzLink(link);if(call)primeDxCall(call)})\nfccList?.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(!link)return;const call=callsignFromQrzLink(link);if(call)primeDxCall(call)})"
),)

RIG_CONTROL_JS_V1="""async function rigControlFromPlan(plan){const action=plan&&plan.rig_control;if(!action||!action.enabled)return null;statusBox.textContent='Elmer is preparing a receive-only radio change…';const headers={'Content-Type':'application/json','X-Elmer-Action':'rig-control'};let response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'prepare',action:action.action})});let data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not prepare the radio change.');if(!window.confirm(data.confirmation||'Allow this receive-only radio change?'))return {status:'cancelled',action:'tune_wwv_10mhz',radio:Number(data.radio)||1,requested_frequency_hz:10000000,requested_mode:'AM',message:'The user cancelled the requested radio change.'};statusBox.textContent='RigPi is tuning and verifying the selected radio…';response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'execute',confirmation_id:data.confirmation_id})});data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'RigPi could not complete the radio change.');return data}"""
RIG_CONTROL_JS_V2=RIG_CONTROL_JS_V1.replace(
    "if(!window.confirm(data.confirmation||'Allow this receive-only radio change?'))return {status:'cancelled',action:'tune_wwv_10mhz',radio:Number(data.radio)||1,requested_frequency_hz:10000000,requested_mode:'AM',message:'The user cancelled the requested radio change.'};statusBox.textContent",
    "if(!window.confirm(data.confirmation||'Allow this receive-only radio change?')){response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'cancel',confirmation_id:data.confirmation_id})});data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'RigPi could not cancel the proposed action.');return data}statusBox.textContent")
RIG_CONTROL_JS_V4="""async function rigControlFromPlan(plan){const action=plan&&plan.rig_control;if(!action||!action.enabled||action.action==='none')return null;if(action.action==='receiver.history'){document.querySelector('#elmerActivityButton')?.click();return null}statusBox.textContent='Elmer is preparing a receive-only radio change…';const headers={'Content-Type':'application/json','X-Elmer-Action':'rig-control'},prepare={phase:'prepare',action:action.action,radio:Number(action.radio)>0?Number(action.radio):null,frequency_hz:Number(action.frequency_hz)>0?Number(action.frequency_hz):null,mode:action.mode||null,bandwidth_hz:Number(action.bandwidth_hz)>0?Number(action.bandwidth_hz):null};let response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify(prepare)});let data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'RigPi could not prepare the radio change.');if(!window.confirm(data.confirmation||'Allow this receive-only radio change?')){response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'cancel',confirmation_id:data.confirmation_id})});data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'RigPi could not cancel the proposed action.');return data}statusBox.textContent='RigPi is changing and verifying the selected receiver…';response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'execute',confirmation_id:data.confirmation_id})});data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'RigPi could not complete the radio change.');return data}"""
RIG_CONTROL_JS=RIG_CONTROL_JS_V4.replace(
    "let data=await response.json().catch(()=>({}));if(response.status===401)",
    "let data=await response.json().catch(()=>({}));const controlNotice=document.querySelector('#elmerControlNotice');if(response.ok)controlNotice?.classList.remove('visible');else if(/not connected/i.test(data.error||''))controlNotice?.classList.add('visible');if(response.status===401)",1)
HELP_ROUTING_JS="""async function rigControlFromPlan(plan){const action=plan&&plan.rig_control;if(!action||!action.enabled||action.action==='none')return null;localStorage.setItem('elmerPendingControlQuestion',question.value.trim());statusBox.textContent='Opening Elmer Control in the main Tuner…';location.href='/?elmer-control=1#elmerControl';return {routed:true}}"""
RIG_CONTROL_JS_V3=RIG_CONTROL_JS.replace(
    'radio:Number(action.radio)>0?Number(action.radio):null,frequency_hz:Number(action.frequency_hz)>0?Number(action.frequency_hz):null,mode:action.mode||null,bandwidth_hz:Number(action.bandwidth_hz)>0?Number(action.bandwidth_hz):null',
    'radio:action.radio??null,frequency_hz:action.frequency_hz??null,mode:action.mode??null,bandwidth_hz:action.bandwidth_hz??null')

ACTIVITY_JS_V1="""async function loadElmerActivity(){const body=document.querySelector('#elmerActivityBody'),dialog=document.querySelector('#elmerActivityDialog');body.textContent='Loading station activity…';dialog.showModal();const response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Elmer-Action':'rig-control'},body:JSON.stringify({phase:'history',limit:25})});const data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';return}if(!response.ok){body.textContent=data.error||'RigPi could not load station activity.';return}body.replaceChildren();if(!data.actions||!data.actions.length){body.textContent='No Ask Elmer station actions have been recorded yet.';return}for(const item of data.actions){const card=document.createElement('article'),title=document.createElement('strong'),meta=document.createElement('div'),detail=document.createElement('div');card.className='elmer-activity-item';title.textContent=item.action_label||item.action_name;meta.className='elmer-activity-meta';meta.textContent=`${new Date((item.updated_at||item.created_at).replace(' ','T')+'Z').toLocaleString()} · Radio ${item.radio} · ${String(item.action_class||'').replaceAll('_',' ')} · ${item.status}`;detail.textContent=item.detail||'';card.append(title,meta,detail);body.append(card)}}
document.querySelector('#elmerActivityButton')?.addEventListener('click',loadElmerActivity)"""
ACTIVITY_JS="""async function repeatElmerAction(item){const request=item&&item.request||{},frequency=Number(request.frequency_hz),radio=Number(item&&item.radio);if(item.status!=='complete'||item.action_class!=='receive_control'||!Number.isFinite(frequency)||frequency<1000||radio<1||radio>4)throw new Error('This activity cannot be repeated.');document.querySelector('#elmerActivityDialog')?.close();result.classList.add('visible');answer.textContent='';metrics.textContent='';copyButton.classList.remove('visible');try{const outcome=await rigControlFromPlan({rig_control:{enabled:true,action:'receiver.tune',radio,frequency_hz:frequency,mode:String(request.mode||''),bandwidth_hz:Number(request.bandwidth_hz)||0}});if(!outcome)return;if(outcome.status==='cancelled'){statusBox.textContent='The repeated command was cancelled.';return}const mhz=(Number(outcome.verified_frequency_hz)/1000000).toFixed(6),mode=String(outcome.verified_mode||outcome.requested_mode||'');lastQuestion=`Repeat ${item.action_label||item.action_name}`;lastAnswer=`Repeated and verified Radio ${outcome.radio} at ${mhz} MHz ${mode}.`;answer.innerHTML=`<h2>Command repeated</h2><p><strong>Radio ${escapeHtml(String(outcome.radio))}</strong> was tuned and verified at <strong>${escapeHtml(mhz)} MHz ${escapeHtml(mode)}</strong>.</p>`;statusBox.textContent='';metrics.textContent='Authenticated local action · Confirmed and verified';copyButton.classList.add('visible')}catch(error){statusBox.textContent='Elmer could not repeat that command.';answer.textContent=error.message}}
async function loadElmerActivity(){const body=document.querySelector('#elmerActivityBody'),dialog=document.querySelector('#elmerActivityDialog');body.textContent='Loading station activity…';dialog.showModal();const response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Elmer-Action':'rig-control'},body:JSON.stringify({phase:'history',limit:25})});const data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';return}if(!response.ok){body.textContent=data.error||'RigPi could not load station activity.';return}body.replaceChildren();if(!data.actions||!data.actions.length){body.textContent='No Ask Elmer station actions have been recorded yet.';return}for(const item of data.actions){const card=document.createElement('article'),title=document.createElement('strong'),meta=document.createElement('div'),detail=document.createElement('div');card.className='elmer-activity-item';title.textContent=item.action_label||item.action_name;meta.className='elmer-activity-meta';meta.textContent=`${new Date((item.updated_at||item.created_at).replace(' ','T')+'Z').toLocaleString()} · Radio ${item.radio} · ${String(item.action_class||'').replaceAll('_',' ')} · ${item.status}`;detail.textContent=item.detail||'';card.append(title,meta,detail);const request=item.request||{},frequency=Number(request.frequency_hz);if(item.status==='complete'&&item.action_class==='receive_control'&&Number.isFinite(frequency)&&frequency>=1000){const actions=document.createElement('div'),repeat=document.createElement('button');actions.className='elmer-activity-actions';repeat.className='btn btn-outline-primary btn-sm';repeat.type='button';repeat.textContent='Repeat';repeat.addEventListener('click',()=>repeatElmerAction(item));actions.append(repeat);card.append(actions)}body.append(card)}}
document.querySelector('#elmerActivityButton')?.addEventListener('click',loadElmerActivity)"""
ACTIVITY_JS_V2=ACTIVITY_JS.replace(
    'escapeHtml(String(outcome.radio))','escapeHtml(outcome.radio)')

CALLBOOK_REPLACEMENTS=(
    ("function parseEvents(buffer,onEvent){",
     CALLSIGN_CURRENT+'\n'+DETAILS_CURRENT+'\n'+LIVE_CONTEXT_JS+'\nfunction parseEvents(buffer,onEvent){'),
    ("let complete='',buffer='';try{const response=await fetch('/elmer-api/answer'",
     "let complete='',buffer='';try{const liveContext=await liveContextFor(q);const response=await fetch('/elmer-api/answer'"),
    ("body:JSON.stringify({question:q})",
     "body:JSON.stringify({question:q,live_context:liveContext})"),
)

MULTILINGUAL_PLAN_REPLACEMENTS=(
    ('function parseEvents(buffer,onEvent){', MULTILINGUAL_PLAN_JS+'\nfunction parseEvents(buffer,onEvent){'),
    ("const liveContext=await liveContextFor(q);const response=await fetch('/elmer-api/answer'",
     "const queryPlan=await queryPlanFor(q);const liveContext=await liveContextFromPlan(queryPlan,q);const response=await fetch('/elmer-api/answer'"),
    ('body:JSON.stringify({question:q,live_context:liveContext})',
     'body:JSON.stringify({question:q,query_plan:queryPlan,live_context:liveContext})'),
)

RIG_CONTROL_REPLACEMENTS=(
    ('function parseEvents(buffer,onEvent){', RIG_CONTROL_JS+'\nfunction parseEvents(buffer,onEvent){'),
    ('const queryPlan=await queryPlanFor(q);const liveContext=await liveContextFromPlan(queryPlan,q);',
     'const queryPlan=await queryPlanFor(q);const rigControl=await rigControlFromPlan(queryPlan);let liveContext=await liveContextFromPlan(queryPlan,q);if(rigControl)liveContext={...(liveContext||{}),rig_control:rigControl};'),
)

RIG_CONTROL_UPGRADE_REPLACEMENTS=((RIG_CONTROL_JS_V1,RIG_CONTROL_JS),)
RIG_CONTROL_PARAMETER_V2_UPGRADE_REPLACEMENTS=((RIG_CONTROL_JS_V2,RIG_CONTROL_JS),)
RIG_CONTROL_PARAMETER_UPGRADE_REPLACEMENTS=((RIG_CONTROL_JS_V3,RIG_CONTROL_JS),)
RIG_CONTROL_NOTICE_UPGRADE_REPLACEMENTS=((RIG_CONTROL_JS_V4,RIG_CONTROL_JS),)
ACTIVITY_REPLAY_UPGRADE_REPLACEMENTS=(
    (ACTIVITY_JS_V1,ACTIVITY_JS),
    ('.elmer-activity-item strong{color:#183b54}',
     '.elmer-activity-item strong{color:#183b54}.elmer-activity-actions{margin-top:8px}'),
)
ACTIVITY_LABEL_UPGRADE_REPLACEMENTS=(
    ('id="elmerActivityButton" type="button">Station Activity</button>',
     'id="elmerActivityButton" type="button">History</button>'),
    ('<h2>Station Activity</h2>', '<h2>Command History</h2>'),
)
ACTIVITY_REPEAT_ESCAPE_UPGRADE_REPLACEMENTS=((ACTIVITY_JS_V2,ACTIVITY_JS),)
CONTROL_SPLIT_REPLACEMENTS=(
    (RIG_CONTROL_JS,HELP_ROUTING_JS),
    ('<button class="btn btn-outline-secondary ml-2" id="elmerActivityButton" type="button">History</button>',
     '<a class="btn btn-outline-secondary ml-2" href="/?elmer-control=1#elmerControl">Elmer Control</a>'),
    ('id="elmerWake" type="button" aria-pressed="false">Hey Elmer: Off</button>',
     'id="elmerWake" type="button" aria-pressed="false" hidden>Hey Elmer: Off</button>'),
    ('updateElmerGreeting();',
     "updateElmerGreeting();\nconst pendingHelpQuestion=localStorage.getItem('elmerPendingHelpQuestion');if(pendingHelpQuestion){localStorage.removeItem('elmerPendingHelpQuestion');question.value=pendingHelpQuestion;question.focus()}"),
    ('const rigControl=await rigControlFromPlan(queryPlan);let liveContext=',
     'const rigControl=await rigControlFromPlan(queryPlan);if(rigControl&&rigControl.routed)return;let liveContext='),
)

ACTIVITY_REPLACEMENTS=(
    ('href="/elmer-stats.php">Elmer Status</a>',
     'href="/elmer-stats.php">Elmer Status</a> <button class="btn btn-outline-secondary ml-2" id="elmerActivityButton" type="button">History</button>'),
    ('<dialog id="elmerDialog">',
     '<dialog id="elmerActivityDialog" class="elmer-dialog"><form method="dialog"><div class="elmer-activity-head"><h2>Command History</h2><button class="btn btn-outline-secondary btn-sm" value="close">Close</button></div><p class="elmer-activity-note">Authenticated local actions only. No PTT or transmit commands are available.</p><div id="elmerActivityBody"></div></form></dialog>\n<dialog id="elmerDialog">'),
    ('  <style>\n',
     '  <style>\n    #elmerActivityDialog{width:min(760px,94vw);max-height:82vh;border:0;border-radius:10px;padding:20px;box-shadow:0 12px 40px #0005}#elmerActivityDialog::backdrop{background:#0007}.elmer-activity-head{display:flex;align-items:center;justify-content:space-between;gap:15px}.elmer-activity-head h2{margin:0;color:#183b54}.elmer-activity-note,.elmer-activity-meta{color:#64717a;font-size:.84rem}.elmer-activity-item{border-top:1px solid #d7e0e5;padding:12px 0}.elmer-activity-item strong{color:#183b54}.elmer-activity-actions{margin-top:8px}\n'),
    ('function parseEvents(buffer,onEvent){', ACTIVITY_JS+'\nfunction parseEvents(buffer,onEvent){'),
)

RADIO_NOTICE_REPLACEMENTS=(
    ('  <style>\n',
     '  <style>\n    .elmer-control-note{display:none;background:#fff3cd;border-left:4px solid #b7791f;border-radius:4px;color:#5f4710;font-size:.9rem;margin:14px 0 4px;padding:.65em .8em}.elmer-control-note.visible{display:block}.elmer-control-note strong{color:#513b08}\n'),
    ('    </form>\n    <div id="elmerVoice"',
     '    </form>\n    <p id="elmerControlNotice" class="elmer-control-note" role="status"><strong>Radio Control isn’t connected.</strong> Click RigPi’s <strong>Connect</strong> button, then ask Elmer again.</p>\n    <div id="elmerVoice"'),
)
RADIO_NOTICE_UPGRADE_REPLACEMENTS=(
    ('.elmer-control-note{background:#e9f2f6;border-left:4px solid #176b99;border-radius:4px;color:#344b58;font-size:.9rem;margin:14px 0 4px;padding:.65em .8em}.elmer-control-note strong{color:#183b54}',
     '.elmer-control-note{display:none;background:#fff3cd;border-left:4px solid #b7791f;border-radius:4px;color:#5f4710;font-size:.9rem;margin:14px 0 4px;padding:.65em .8em}.elmer-control-note.visible{display:block}.elmer-control-note strong{color:#513b08}'),
    ('<p class="elmer-control-note"><strong>Radio commands:</strong> Start Radio Control with RigPi’s <strong>Connect</strong> button before asking Elmer to tune or restore a radio.</p>',
     '<p id="elmerControlNotice" class="elmer-control-note" role="status"><strong>Radio Control isn’t connected.</strong> Click RigPi’s <strong>Connect</strong> button, then ask Elmer again.</p>'),
)

CALLBOOK_DETAIL_REPLACEMENTS=(
    ("function liveCallsign(text){if(!/\\b(?:who|name|where|tell|about|qrz|callbook|lookup|grid|qth|bearing|distance|country|state|location)\\b/i.test(text))return '';const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]);return tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token)&&!/^\\d+(?:HZ|KHZ|MHZ|GHZ)$/.test(token))||''}\nasync function liveContextFor(text){const call=liveCallsign(text);if(!call)return null;statusBox.textContent=`Elmer is checking the station callbook for ${call}…`;const response=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call})});",
     "function liveCallsign(text){if(!/\\b(?:who|name|where|tell|about|photo|picture|image|pix|address|mail|postal|qsl|bio|biography|qrz|callbook|lookup|grid|qth|bearing|distance|country|state|location)\\b/i.test(text))return '';const tokens=(text.toUpperCase().match(/[A-Z0-9]+(?:\\/[A-Z0-9]+)?/g)||[]);return tokens.find(token=>/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/.test(token)&&!/^\\d+(?:HZ|KHZ|MHZ|GHZ)$/.test(token))||''}\nfunction requestedCallbookDetails(text){const include=[];if(/\\b(?:address|mailing|postal|qsl(?:\\s+card)?)\\b/i.test(text))include.push('address');if(/\\b(?:bio|biography|about|tell\\s+me\\s+about)\\b/i.test(text))include.push('biography');if(/\\b(?:photo|picture|image|pix|look\\s+like)\\b/i.test(text))include.push('image');return include}\nasync function liveContextFor(text){const call=liveCallsign(text);if(!call)return null;statusBox.textContent=`Elmer is checking the station callbook for ${call}…`;const response=await fetch('/programs/ElmerCallbook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({call,include:requestedCallbookDetails(text)})});"),
)

EXTRA_XML_REPLACEMENTS=((CALLSIGN_V2,CALLSIGN_V4),(DETAILS_V2,DETAILS_V4))
EXPIRATION_ROUTING_REPLACEMENTS=((CALLSIGN_V3,CALLSIGN_V4),(DETAILS_V3,DETAILS_V4))
FCC_CONTEXT_REPLACEMENTS=((LIVE_CONTEXT_JS_V1,LIVE_CONTEXT_JS),)
FCC_PROVIDER_REPLACEMENTS=((LIVE_CONTEXT_JS_V2,LIVE_CONTEXT_JS),)
MAP_CONTEXT_REPLACEMENTS=((LIVE_CONTEXT_JS_V3,LIVE_CONTEXT_JS),)
SINGLE_CALL_MAP_CONTEXT_REPLACEMENTS=((LIVE_CONTEXT_JS_V4,LIVE_CONTEXT_JS),)
IMPLICIT_CALL_MAP_REPLACEMENTS=((LIVE_CONTEXT_JS_V5,LIVE_CONTEXT_JS),)
MAP_CALLSIGN_REPLACEMENTS=((CALLSIGN_V4,CALLSIGN_V5),)
PHOTO_SYNONYM_REPLACEMENTS=((CALLSIGN_V5,CALLSIGN_V7),(DETAILS_V4,DETAILS_V6))
FRENCH_CALLBOOK_REPLACEMENTS=((CALLSIGN_V6,CALLSIGN_V7),(DETAILS_V5,DETAILS_V6))
STANDALONE_CALLSIGN_REPLACEMENTS=(
    ("function liveCallsign(text){if(!",STANDALONE_CALL_PREFIX),
    ("detectedFcc=requestedFccSearch(text);if(book.enabled&&book.call)",
     "detectedFcc=requestedFccSearch(text),detectedCall=liveCallsign(text),resolvedBook=book.enabled&&book.call?book:(detectedCall?{enabled:true,call:detectedCall,provider:'auto',include:requestedCallbookDetails(text).concat('details')}:book);if(resolvedBook.enabled&&resolvedBook.call)"),
    ('for ${book.call}…','for ${resolvedBook.call}…'),
    ('up ${book.call}.','up ${resolvedBook.call}.'),
    ('call:book.call','call:resolvedBook.call'),
    ('Array.isArray(book.include)?book.include:[]','Array.isArray(resolvedBook.include)?[...new Set(resolvedBook.include)]:[]'),
    ("book.provider==='fcc'","resolvedBook.provider==='fcc'"),
)
WEATHER_PLAN_REPLACEMENTS=(
    ("shortwave=plan.shortwave_search||{},detectedFcc=",
     "shortwave=plan.shortwave_search||{},weather=plan.weather_search||{},detectedFcc="),
    ("context.shortwave_search=data}return Object.keys(context).length?context:null}",
     "context.shortwave_search=data}"+WEATHER_FETCH_JS+"return Object.keys(context).length?context:null}"),
)

ICEBREAKER_JS_V1_BASE="""let qsoIdeas=[],qsoIdeaIndex=-1;
function showQsoIdea(index){if(!qsoIdeas.length)return;qsoIdeaIndex=(index+qsoIdeas.length)%qsoIdeas.length;const item=qsoIdeas[qsoIdeaIndex];icebreakerText.textContent=item.text||'';icebreakerSource.textContent=item.source?`Based on ${String(item.source).slice(0,100)}`:'';icebreakerBox.hidden=false}
async function loadQsoIdeas(){qsoIdeasButton.disabled=true;try{const response=await fetch('/programs/ElmerConversation.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Elmer-Action':'qso-ideas'},body:'{}'}),data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';return}if(!response.ok)throw new Error(data.error||'RigPi could not prepare QSO Ideas.');qsoIdeas=Array.isArray(data.ideas)?data.ideas:[];if(!qsoIdeas.length)throw new Error('No QSO Ideas are available for this station.');qsoIdeasTitle.textContent=`QSO idea for ${String(data.call||'the other station')}`;showQsoIdea(0)}catch(error){statusBox.textContent=error.message}finally{qsoIdeasButton.disabled=false}}
qsoIdeasButton.addEventListener('click',loadQsoIdeas);icebreakerAnotherButton.addEventListener('click',()=>showQsoIdea(qsoIdeaIndex+1));icebreakerCopyButton.addEventListener('click',async()=>{if(qsoIdeaIndex<0)return;try{await navigator.clipboard.writeText(qsoIdeas[qsoIdeaIndex].text||'');icebreakerCopyButton.textContent='Copied';setTimeout(()=>icebreakerCopyButton.textContent='Copy',1200)}catch(error){statusBox.textContent='The QSO idea could not be copied.'}});icebreakerDismiss.addEventListener('click',()=>{icebreakerBox.hidden=true});"""
OLD_COPY_LISTENER="icebreakerCopyButton.addEventListener('click',async()=>{if(qsoIdeaIndex<0)return;try{await navigator.clipboard.writeText(qsoIdeas[qsoIdeaIndex].text||'');icebreakerCopyButton.textContent='Copied';setTimeout(()=>icebreakerCopyButton.textContent='Copy',1200)}catch(error){statusBox.textContent='The QSO idea could not be copied.'}});"
NEW_COPY_LISTENER="async function copyQsoIdea(){if(qsoIdeaIndex<0)return;const text=String(qsoIdeas[qsoIdeaIndex].text||'');let copied=false;if(navigator.clipboard&&window.isSecureContext){try{await navigator.clipboard.writeText(text);copied=true}catch(error){}}if(!copied){const area=document.createElement('textarea');area.value=text;area.setAttribute('readonly','');area.style.position='fixed';area.style.opacity='0';document.body.append(area);area.select();area.setSelectionRange(0,area.value.length);try{copied=document.execCommand('copy')}catch(error){}area.remove()}if(!copied){statusBox.textContent='The browser blocked clipboard access. Select the idea text and copy it manually.';return}icebreakerCopyButton.textContent='Copied';setTimeout(()=>icebreakerCopyButton.textContent='Copy',1200)}\nicebreakerCopyButton.addEventListener('click',copyQsoIdea);"
ICEBREAKER_JS_V1=ICEBREAKER_JS_V1_BASE.replace(OLD_COPY_LISTENER,NEW_COPY_LISTENER)
QSO_KEYER_JS_V1_BASE="""async function stageQsoIdea(){if(qsoIdeaIndex<0)return;icebreakerKeyerButton.disabled=true;const headers={'Content-Type':'application/json','X-Elmer-Action':'rig-control'},cwText=String(qsoIdeas[qsoIdeaIndex].text||'').replace(/[‘’]/g,"'").replace(/[–—]/g,'-');try{let response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'prepare',action:'keyer.stage_text',cw_text:cwText})}),data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';return}if(!response.ok)throw new Error(data.error||'RigPi could not prepare the CW text.');if(!window.confirm(data.confirmation||'Stage this QSO idea in the CW buffer with Hold on?')){await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'cancel',confirmation_id:data.confirmation_id})});return}response=await fetch('/programs/ElmerRigControl.php',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({phase:'execute',confirmation_id:data.confirmation_id})});data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||'RigPi could not stage the CW text.');statusBox.textContent='QSO idea staged in the Keyer with Hold on. Review it before releasing Hold.'}catch(error){statusBox.textContent=error.message}finally{icebreakerKeyerButton.disabled=false}}
icebreakerKeyerButton.addEventListener('click',stageQsoIdea);"""
QSO_KEYER_JS_V1=QSO_KEYER_JS_V1_BASE.replace("statusBox.textContent='QSO idea staged in the Keyer with Hold on. Review it before releasing Hold.'","statusBox.textContent='QSO idea staged in the Keyer with Hold on. Opening the Keyer…';setTimeout(()=>{location.href='/keyer.php'},350)")
QSO_KEYER_JS_BASE=QSO_KEYER_JS_V1_BASE.replace(
    "async function stageQsoIdea()",
    "function cwShorthand(text){return String(text||'').replace(/[‘’]/g,\"'\").replace(/[–—]/g,'-').replace(/\\byou're\\b/gi,'U R').replace(/\\byour\\b/gi,'UR').replace(/\\bhere\\b/gi,'HT').replace(/\\bare\\b/gi,'R').replace(/\\byou\\b/gi,'U')}\nasync function stageQsoIdea()"
).replace(
    "cwText=String(qsoIdeas[qsoIdeaIndex].text||'').replace(/[‘’]/g,\"'\").replace(/[–—]/g,'-')",
    "cwText=cwShorthand(qsoIdeas[qsoIdeaIndex].text||'')"
)
QSO_KEYER_JS=QSO_KEYER_JS_BASE.replace("statusBox.textContent='QSO idea staged in the Keyer with Hold on. Review it before releasing Hold.'","statusBox.textContent='QSO idea staged in the Keyer with Hold on. Opening the Keyer…';setTimeout(()=>{location.href='/keyer.php'},350)")
ICEBREAKER_JS_BEHAVIOR_OLD=ICEBREAKER_JS_V1_BASE+'\n'+QSO_KEYER_JS_V1_BASE
ICEBREAKER_JS=ICEBREAKER_JS_V1+'\n'+QSO_KEYER_JS
ICEBREAKER_JS_PRE_LANGUAGE=ICEBREAKER_JS
QSO_LANGUAGE_JS="""const qsoLanguageChoices=[['en','English CW'],['fr-FR','Français'],['fr-CA','Français (Canada)'],['es-ES','Español'],['de-DE','Deutsch'],['sv-SE','Svenska'],['ru-RU','Русский'],['uk-UA','Українська'],['pl-PL','Polski'],['cs-CZ','Čeština'],['nb-NO','Norsk bokmål'],['da-DK','Dansk'],['fi-FI','Suomi'],['it-IT','Italiano'],['nl-NL','Nederlands'],['pt-BR','Português (Brasil)'],['pt-PT','Português (Portugal)'],['ja-JP','日本語'],['zh-CN','中文（简体）'],['zh-TW','中文（繁體）'],['ko-KR','한국어']];
const qsoLanguageSelect=document.createElement('select'),qsoTranslationCache=new Map();qsoLanguageSelect.id='elmerQsoLanguage';qsoLanguageSelect.className='form-control form-control-sm';qsoLanguageSelect.title='QSO idea language';qsoLanguageSelect.setAttribute('aria-label','QSO idea language');qsoLanguageSelect.style.cssText='display:inline-block;width:auto;margin-left:8px;vertical-align:middle';for(const [value,label] of qsoLanguageChoices){const option=document.createElement('option');option.value=value;option.textContent=label;qsoLanguageSelect.append(option)}qsoLanguageSelect.value=localStorage.getItem('elmerQsoLanguage')||'en';if(!qsoLanguageSelect.value)qsoLanguageSelect.value='en';qsoIdeasButton.insertAdjacentElement('afterend',qsoLanguageSelect);
async function translatedQsoIdeas(items){const locale=qsoLanguageSelect.value||'en',source=items.map(item=>({text:String(item.text||''),source:String(item.source||'')}));if(locale==='en')return source.map(item=>({...item,cw_text:item.text}));const cacheKey=locale+'\\n'+source.map(item=>item.text).join('\\n');if(qsoTranslationCache.has(cacheKey))return qsoTranslationCache.get(cacheKey).map(item=>({...item}));statusBox.textContent='Elmer is translating the QSO ideas…';const response=await fetch('/elmer-api/text-translation',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({target_locale:locale,texts:source.map(item=>item.text)})}),data=await response.json().catch(()=>({}));if(response.status===401){location.href='/login.php';throw new Error('Please sign in to RigPi.')}if(!response.ok)throw new Error(data.error||'Elmer could not translate the QSO ideas.');if(!Array.isArray(data.translations)||!Array.isArray(data.cw_texts)||data.translations.length!==source.length||data.cw_texts.length!==source.length)throw new Error('Elmer returned an incomplete QSO translation.');const language=String(data.language||qsoLanguageSelect.options[qsoLanguageSelect.selectedIndex].textContent),translated=source.map((item,index)=>({text:String(data.translations[index]||''),cw_text:String(data.cw_texts[index]||''),source:item.source?item.source+' · '+language:language}));qsoTranslationCache.set(cacheKey,translated);return translated.map(item=>({...item}))}
qsoLanguageSelect.addEventListener('change',async()=>{localStorage.setItem('elmerQsoLanguage',qsoLanguageSelect.value);if(!qsoIdeasOriginal.length)return;qsoLanguageSelect.disabled=true;try{qsoIdeas=await translatedQsoIdeas(qsoIdeasOriginal);showQsoIdea(qsoIdeaIndex<0?0:qsoIdeaIndex);statusBox.textContent=qsoLanguageSelect.value==='en'?'QSO ideas use international English CW.':'QSO ideas translated; To Keyer will use ASCII transliteration.'}catch(error){statusBox.textContent=error.message}finally{qsoLanguageSelect.disabled=false}});"""
ICEBREAKER_JS=ICEBREAKER_JS.replace(
    'let qsoIdeas=[],qsoIdeaIndex=-1;',
    'let qsoIdeas=[],qsoIdeasOriginal=[],qsoIdeaIndex=-1;'
).replace(
    "qsoIdeas=Array.isArray(data.ideas)?data.ideas:[];if(!qsoIdeas.length)throw new Error('No QSO Ideas are available for this station.');qsoIdeasTitle.textContent=`QSO idea for ${String(data.call||'the other station')}`;showQsoIdea(0)",
    "qsoIdeasOriginal=Array.isArray(data.ideas)?data.ideas:[];if(!qsoIdeasOriginal.length)throw new Error('No QSO Ideas are available for this station.');qsoIdeas=await translatedQsoIdeas(qsoIdeasOriginal);qsoIdeasTitle.textContent=`QSO idea for ${String(data.call||'the other station')}`;showQsoIdea(0)"
).replace(
    'function cwShorthand(text)',
    QSO_LANGUAGE_JS+'\nfunction cwShorthand(text)'
).replace(
    "cwText=cwShorthand(qsoIdeas[qsoIdeaIndex].text||'')",
    "cwText=cwShorthand(qsoIdeas[qsoIdeaIndex].cw_text||qsoIdeas[qsoIdeaIndex].text||'')"
)
ICEBREAKER_REPLACEMENTS=(
    ('  <style>\n',
     '  <style>\n    .elmer-qso-tools{margin:-4px 0 10px}.elmer-icebreaker{background:#e9f2f6;border-left:4px solid #176b99;border-radius:6px;color:#25485b;margin:0 0 12px;padding:10px 12px}.elmer-icebreaker[hidden]{display:none}.elmer-icebreaker-title{display:block;color:#183b54;margin-bottom:5px}.elmer-icebreaker-main{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.elmer-icebreaker-text{font-size:1rem;line-height:1.4}.elmer-icebreaker-actions{display:flex;gap:6px;white-space:nowrap}.elmer-icebreaker-source{color:#64717a;font-size:.75rem;margin-top:5px}\n'),
    ('      <label for="elmerQuestion" id="elmerGreeting" class="elmer-greeting"></label>',
     '      <label for="elmerQuestion" id="elmerGreeting" class="elmer-greeting"></label>\n      <div class="elmer-qso-tools"><button id="elmerQsoIdeas" class="btn btn-outline-secondary btn-sm" type="button">QSO Ideas</button></div>\n      <aside id="elmerIcebreaker" class="elmer-icebreaker" hidden><strong id="elmerQsoIdeasTitle" class="elmer-icebreaker-title">QSO idea</strong><div class="elmer-icebreaker-main"><span id="elmerIcebreakerText" class="elmer-icebreaker-text"></span><span class="elmer-icebreaker-actions"><button id="elmerIcebreakerAnother" class="btn btn-outline-primary btn-sm" type="button">Another</button><button id="elmerIcebreakerCopy" class="btn btn-outline-secondary btn-sm" type="button">Copy</button><button id="elmerIcebreakerDismiss" class="btn btn-outline-secondary btn-sm" type="button" aria-label="Dismiss QSO idea">×</button></span></div><div id="elmerIcebreakerSource" class="elmer-icebreaker-source"></div></aside>'),
    ("setupCheck=document.querySelector('#elmerSetupCheck');",
     "setupCheck=document.querySelector('#elmerSetupCheck'),qsoIdeasButton=document.querySelector('#elmerQsoIdeas'),qsoIdeasTitle=document.querySelector('#elmerQsoIdeasTitle'),icebreakerBox=document.querySelector('#elmerIcebreaker'),icebreakerText=document.querySelector('#elmerIcebreakerText'),icebreakerSource=document.querySelector('#elmerIcebreakerSource'),icebreakerAnotherButton=document.querySelector('#elmerIcebreakerAnother'),icebreakerCopyButton=document.querySelector('#elmerIcebreakerCopy'),icebreakerDismiss=document.querySelector('#elmerIcebreakerDismiss');"),
    ('updateElmerGreeting();\nlet elmerServiceConnected=false;',
     'updateElmerGreeting();\n'+ICEBREAKER_JS+'\nlet elmerServiceConnected=false;'),
)
ICEBREAKER_BASE_REPLACEMENTS=tuple(
    ("referenceBody=document.querySelector('#elmerReferenceBody');",
     "referenceBody=document.querySelector('#elmerReferenceBody'),qsoIdeasButton=document.querySelector('#elmerQsoIdeas'),qsoIdeasTitle=document.querySelector('#elmerQsoIdeasTitle'),icebreakerBox=document.querySelector('#elmerIcebreaker'),icebreakerText=document.querySelector('#elmerIcebreakerText'),icebreakerSource=document.querySelector('#elmerIcebreakerSource'),icebreakerAnotherButton=document.querySelector('#elmerIcebreakerAnother'),icebreakerCopyButton=document.querySelector('#elmerIcebreakerCopy'),icebreakerDismiss=document.querySelector('#elmerIcebreakerDismiss');")
    if old == "setupCheck=document.querySelector('#elmerSetupCheck');" else (old,new)
    if old != 'updateElmerGreeting();\nlet elmerServiceConnected=false;' else
    ('updateElmerGreeting();', 'updateElmerGreeting();\n'+ICEBREAKER_JS)
    for old,new in ICEBREAKER_REPLACEMENTS
)

OLD_ICEBREAKER_UPGRADE_REPLACEMENTS=(
    ('    .elmer-icebreaker{background:#e9f2f6;border-left:4px solid #176b99;border-radius:6px;color:#25485b;margin:0 0 12px;padding:10px 12px}.elmer-icebreaker[hidden]{display:none}.elmer-icebreaker-main{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.elmer-icebreaker-text{font-size:1rem;line-height:1.4}.elmer-icebreaker-actions{display:flex;gap:6px;white-space:nowrap}.elmer-icebreaker-source{color:#64717a;font-size:.75rem;margin-top:5px}',
     '    .elmer-qso-tools{margin:-4px 0 10px}.elmer-icebreaker{background:#e9f2f6;border-left:4px solid #176b99;border-radius:6px;color:#25485b;margin:0 0 12px;padding:10px 12px}.elmer-icebreaker[hidden]{display:none}.elmer-icebreaker-title{display:block;color:#183b54;margin-bottom:5px}.elmer-icebreaker-main{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.elmer-icebreaker-text{font-size:1rem;line-height:1.4}.elmer-icebreaker-actions{display:flex;gap:6px;white-space:nowrap}.elmer-icebreaker-source{color:#64717a;font-size:.75rem;margin-top:5px}'),
    ('      <aside id="elmerIcebreaker" class="elmer-icebreaker" hidden><div class="elmer-icebreaker-main"><span id="elmerIcebreakerText" class="elmer-icebreaker-text"></span><span class="elmer-icebreaker-actions"><button id="elmerIcebreakerReply" class="btn btn-outline-primary btn-sm" type="button">Reply</button><button id="elmerIcebreakerDismiss" class="btn btn-outline-secondary btn-sm" type="button" aria-label="Dismiss conversation starter">×</button></span></div><div id="elmerIcebreakerSource" class="elmer-icebreaker-source"></div></aside>',
     '      <div class="elmer-qso-tools"><button id="elmerQsoIdeas" class="btn btn-outline-secondary btn-sm" type="button">QSO Ideas</button></div>\n      <aside id="elmerIcebreaker" class="elmer-icebreaker" hidden><strong id="elmerQsoIdeasTitle" class="elmer-icebreaker-title">QSO idea</strong><div class="elmer-icebreaker-main"><span id="elmerIcebreakerText" class="elmer-icebreaker-text"></span><span class="elmer-icebreaker-actions"><button id="elmerIcebreakerAnother" class="btn btn-outline-primary btn-sm" type="button">Another</button><button id="elmerIcebreakerCopy" class="btn btn-outline-secondary btn-sm" type="button">Copy</button><button id="elmerIcebreakerDismiss" class="btn btn-outline-secondary btn-sm" type="button" aria-label="Dismiss QSO idea">×</button></span></div><div id="elmerIcebreakerSource" class="elmer-icebreaker-source"></div></aside>'),
    ("setupCheck=document.querySelector('#elmerSetupCheck'),icebreakerBox=document.querySelector('#elmerIcebreaker'),icebreakerText=document.querySelector('#elmerIcebreakerText'),icebreakerSource=document.querySelector('#elmerIcebreakerSource'),icebreakerReplyButton=document.querySelector('#elmerIcebreakerReply'),icebreakerDismiss=document.querySelector('#elmerIcebreakerDismiss');",
     "setupCheck=document.querySelector('#elmerSetupCheck'),qsoIdeasButton=document.querySelector('#elmerQsoIdeas'),qsoIdeasTitle=document.querySelector('#elmerQsoIdeasTitle'),icebreakerBox=document.querySelector('#elmerIcebreaker'),icebreakerText=document.querySelector('#elmerIcebreakerText'),icebreakerSource=document.querySelector('#elmerIcebreakerSource'),icebreakerAnotherButton=document.querySelector('#elmerIcebreakerAnother'),icebreakerCopyButton=document.querySelector('#elmerIcebreakerCopy'),icebreakerDismiss=document.querySelector('#elmerIcebreakerDismiss');"),
    (ICEBREAKER_JS if False else "const icebreakerSessionKey=`elmerConversationStarterShown:${rigPiUsername}`;let currentIcebreaker='',icebreakerReply=false;const defaultQuestionPlaceholder=question.placeholder;\nfunction hideIcebreaker(){icebreakerBox.hidden=true;icebreakerReply=false;question.placeholder=defaultQuestionPlaceholder}\nasync function loadIcebreaker(){if(sessionStorage.getItem(icebreakerSessionKey))return;try{const response=await fetch('/programs/ElmerPreferences.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Elmer-Action':'preferences'},body:JSON.stringify({action:'starter'})}),data=await response.json().catch(()=>({}));if(!response.ok||data.conversation_starters!==true||!data.starter)return;currentIcebreaker=String(data.starter).slice(0,400);icebreakerText.textContent=currentIcebreaker;icebreakerSource.textContent=data.source?`From ${String(data.source).slice(0,80)}`:'';icebreakerBox.hidden=false;sessionStorage.setItem(icebreakerSessionKey,'1')}catch(error){console.warn('Elmer starter unavailable:',error)}}\nicebreakerReplyButton.addEventListener('click',()=>{icebreakerReply=true;question.value='';question.placeholder='Reply to Elmer…';question.focus()});icebreakerDismiss.addEventListener('click',hideIcebreaker);loadIcebreaker();", ICEBREAKER_JS),
    ("event.preventDefault();const q=question.value.trim();if(!q)return;const requestQ=icebreakerReply&&currentIcebreaker?`[Conversation starter shown by RigPi]\\n${currentIcebreaker}\\n[Signed-in user's reply]\\n${q}`:q;button.disabled=true;",
     "event.preventDefault();const q=question.value.trim();if(!q)return;button.disabled=true;"),
    ('body:JSON.stringify({question:requestQ,query_plan:queryPlan,live_context:liveContext})','body:JSON.stringify({question:q,query_plan:queryPlan,live_context:liveContext})'),
    ("lastQuestion=q;lastAnswer=complete;if(icebreakerReply){icebreakerReply=false;currentIcebreaker='';question.placeholder=defaultQuestionPlaceholder}renderElmerMap(lastFccMap);",
     "lastQuestion=q;lastAnswer=complete;renderElmerMap(lastFccMap);")
)
QSO_BUTTON_ROW_REPLACEMENTS=(
    ('      <div class="elmer-qso-tools"><button id="elmerQsoIdeas" class="btn btn-outline-secondary btn-sm" type="button">QSO Ideas</button></div>\n',''),
    ('<a class="btn btn-outline-secondary ml-2" href="/?elmer-control=1#elmerControl">Elmer Control</a>',
     '<a class="btn btn-outline-secondary ml-2" href="/?elmer-control=1#elmerControl">Elmer Control</a> <button id="elmerQsoIdeas" class="btn btn-outline-secondary ml-2" type="button">QSO Ideas</button>'),
)
QSO_KEYER_UI_REPLACEMENTS=(
    ('<button id="elmerIcebreakerCopy" class="btn btn-outline-secondary btn-sm" type="button">Copy</button>',
     '<button id="elmerIcebreakerKeyer" class="btn btn-outline-primary btn-sm" type="button">To Keyer</button><button id="elmerIcebreakerCopy" class="btn btn-outline-secondary btn-sm" type="button">Copy</button>'),
    ("icebreakerAnotherButton=document.querySelector('#elmerIcebreakerAnother'),icebreakerCopyButton=",
     "icebreakerAnotherButton=document.querySelector('#elmerIcebreakerAnother'),icebreakerKeyerButton=document.querySelector('#elmerIcebreakerKeyer'),icebreakerCopyButton="),
)
QSO_KEYER_JS_UPGRADE_REPLACEMENTS=((ICEBREAKER_JS_V1,ICEBREAKER_JS),)
QSO_BEHAVIOR_UPGRADE_REPLACEMENTS=((ICEBREAKER_JS_BEHAVIOR_OLD,ICEBREAKER_JS),)
QSO_CW_TRANSLATION_REPLACEMENTS=((QSO_KEYER_JS_V1,QSO_KEYER_JS),)
QSO_LANGUAGE_REPLACEMENTS=((ICEBREAKER_JS_PRE_LANGUAGE,ICEBREAKER_JS),)
QSO_TRANSLATION_ROUTE_REPLACEMENTS=(("fetch('/api/text-translation'","fetch('/elmer-api/text-translation'"),)

MAP_FUNCTIONS="""function clearElmerMap(){lastFccMap=null;if(elmerMap){elmerMap.remove();elmerMap=null}mapSection.classList.remove('visible');mapCanvas.replaceChildren()}
function renderElmerMap(data){clearElmerMap();if(!data||!Array.isArray(data.points)||!data.points.length||typeof L==='undefined')return;mapSection.classList.add('visible');const radius=Number(data.radius_miles||0);mapSummary.textContent=`${data.points.length} geocoded FCC ${data.points.length===1?'location':'locations'}${radius>0?` · ${radius.toLocaleString()} mile radius`:''}`;elmerMap=L.map(mapCanvas,{scrollWheelZoom:false});L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(elmerMap);const center=[Number(data.center_latitude),Number(data.center_longitude)],bounds=L.latLngBounds([center]);L.circleMarker(center,{radius:8,color:'#075f91',weight:3,fillColor:'#48a9d6',fillOpacity:1}).bindPopup(`<strong>${escapeHtml(data.center_label||'Your station')}</strong>`).addTo(elmerMap);if(radius>0)L.circle(center,{radius:radius*1609.344,color:'#176b99',weight:2,fillColor:'#48a9d6',fillOpacity:.08}).addTo(elmerMap);for(const point of data.points){const lat=Number(point.latitude),lon=Number(point.longitude);if(!Number.isFinite(lat)||!Number.isFinite(lon))continue;const where=[lat,lon],details=[point.name,point.license_class,`${Number(point.distance_miles).toFixed(1)} mi`].filter(Boolean).map(escapeHtml).join('<br>');L.circleMarker(where,{radius:7,color:'#8b1538',weight:2,fillColor:'#d50045',fillOpacity:.88}).bindPopup(`<strong>${escapeHtml(point.call||'FCC licensee')}</strong><br>${details}`).addTo(elmerMap);bounds.extend(where)}elmerMap.fitBounds(bounds.pad(.18),{maxZoom:15});setTimeout(()=>elmerMap?.invalidateSize(),50)}"""

MAP_REPLACEMENTS=(
    ('  <?php require $dRoot . \'/includes/styles.php\'; ?>',
     '  <link rel="stylesheet" href="/includes/leaflet.css">\n  <script src="/js/leaflet.js"></script>\n  <?php require $dRoot . \'/includes/styles.php\'; ?>'),
    ('  <style>\n',
     '  <style>\n    .elmer-map-section{display:none;margin:22px 0 8px}.elmer-map-section.visible{display:block}.elmer-map-title{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:8px}.elmer-map-title h2{color:#183b54;font-size:1.35rem;margin:0}.elmer-map-summary{color:#64717a;font-size:.84rem;text-align:right}#elmerMap{height:430px;width:100%;border:1px solid #b9c8d0;border-radius:8px;background:#eaf3f6}.elmer-map-note{color:#64717a;font-size:.8rem;margin-top:6px}\n'),
    ('<div id="elmerAnswer"></div><div id="elmerMetrics"',
     '<div id="elmerAnswer"></div><section id="elmerMapSection" class="elmer-map-section"><div class="elmer-map-title"><h2>FCC proximity map</h2><span id="elmerMapSummary" class="elmer-map-summary"></span></div><div id="elmerMap" role="img" aria-label="Map of geocoded FCC licensees"></div><div class="elmer-map-note">Locations are interpolated from FCC mailing addresses by the U.S. Census and are not guaranteed rooftop or station locations.</div></section><div id="elmerMetrics"'),
    ("let lastQuestion='',lastAnswer='',lastRequestId='',selectedRating=0;",
     "let lastQuestion='',lastAnswer='',lastRequestId='',selectedRating=0,elmerMap=null,lastFccMap=null;const mapSection=document.querySelector('#elmerMapSection'),mapCanvas=document.querySelector('#elmerMap'),mapSummary=document.querySelector('#elmerMapSummary');"),
    ('function parseEvents(buffer,onEvent){', MAP_FUNCTIONS+'\nfunction parseEvents(buffer,onEvent){'),
    ("selectedRating=0;lastRequestId='';copyButton.classList.remove('visible');",
     "selectedRating=0;lastRequestId='';clearElmerMap();copyButton.classList.remove('visible');"),
    ("lastQuestion=q;lastAnswer=complete;feedback.classList.add('visible')",
     "lastQuestion=q;lastAnswer=complete;renderElmerMap(lastFccMap);feedback.classList.add('visible')"),
)

QRZ_IMAGE_REPLACEMENTS=(
    ("host==='raw.githubusercontent.com';return", "host==='raw.githubusercontent.com'||host==='cdn-bio.qrz.com'||host==='cdn-xml.qrz.com'||host==='files.qrz.com'||host==='static.qrz.com';return"),
    ('loading="lazy"><figcaption>', 'loading="lazy" referrerpolicy="no-referrer"><figcaption>'),
)

HAMQTH_IMAGE_REPLACEMENTS=(
    ("host==='static.qrz.com'", "host==='static.qrz.com'||host==='hamqth.com'||host==='www.hamqth.com'"),
)

VOICE_REPLACEMENTS=(
    ("  <?php require $dRoot . '/includes/styles.php'; ?>",
     "  <link rel=\"stylesheet\" href=\"/css/elmer_voice.css?v=20260815-4\">\n  <?php require $dRoot . '/includes/styles.php'; ?>"),
    ('    </form>\n  </section>\n  <section id="elmerResult"',
     '    </form>\n    <div id="elmerVoice" class="elmer-voice">\n      <button class="btn btn-outline-primary btn-sm" id="elmerVoiceAsk" type="button">Ask by voice</button>\n      <button class="btn btn-outline-primary btn-sm" id="elmerWake" type="button" aria-pressed="false">Hey Elmer: Off</button>\n      <label class="elmer-voice-speak" for="elmerSpeak"><input id="elmerSpeak" type="checkbox"> Read answers aloud</label>\n      <button class="btn btn-outline-secondary btn-sm" id="elmerStopSpeech" type="button" hidden>Stop speaking</button>\n      <div id="elmerVoiceStatus" class="elmer-voice-status" aria-live="polite"></div>\n      <p class="elmer-voice-note">Voice recognition is provided by your browser and may use its online speech service. Ask Elmer does not send microphone audio to rigpi.net.</p>\n    </div>\n  </section>\n  <section id="elmerResult"'),
    ('<script src="/Bootstrap/popper.min.js"></script><script src="/Bootstrap/bootstrap.min.js"></script><script src="/js/nav-active.js"></script>',
     '<script src="/Bootstrap/popper.min.js"></script><script src="/Bootstrap/bootstrap.min.js"></script><script src="/js/nav-active.js"></script><script src="/js/elmer_voice.js?v=20260819-2"></script>'),
)

VOICE_SCRIPT_CURRENT='/js/elmer_voice.js?v=20260819-2"></script>'
VOICE_SCRIPT_OLD=(
    '/js/elmer_voice.js?v=20260815-3"></script>',
    '/js/elmer_voice.js?v=20260815-4"></script>',
    '/js/elmer_voice.js?v=20260819-1"></script>',
)


def patch(path):
    path=Path(path)
    text=path.read_text(encoding='utf-8')
    updated=text
    groups=[]
    if not ('lastRequestId=data.request_id' in updated and
            'Your RigPi username is not sent.' in updated):
        groups.append(CLOUD_REPLACEMENTS)
    if 'class="elmer-brand"' not in updated:
        groups.append(BRAND_REPLACEMENTS)
    if 'function copyCompleteAnswer()' not in updated and 'id="elmerCopy"' in updated:
        groups.append(RICH_COPY_REPLACEMENTS)
    elif 'function copyRenderedAnswer()' not in updated and 'id="elmerCopy"' in updated:
        groups.append(RENDERED_COPY_REPLACEMENTS)
    if 'range.selectNodeContents(answer)' not in updated:
        groups.append(DISPLAYED_COPY_REPLACEMENTS)
    if '.elmer-brand{background:#176b99;' not in updated:
        groups.append(BLUE_HEADER_REPLACEMENTS)
    if 'href="/elmer-stats.php"' not in updated:
        groups.append(STATUS_LINK_REPLACEMENTS)
    if 'function liveContextFor(text)' not in updated:
        groups.append(CALLBOOK_REPLACEMENTS)
    elif 'function requestedCallbookDetails(text)' not in updated:
        groups.append(CALLBOOK_DETAIL_REPLACEMENTS)
    elif "include.push('email')" not in updated:
        groups.append(EXTRA_XML_REPLACEMENTS)
    elif '|expiration|' not in updated:
        groups.append(EXPIRATION_ROUTING_REPLACEMENTS)
    if ('function requestedCallbookDetails(text)' in updated
            and '|rig|radio|radios|transceiver|equipment|station|shack|antenna|amplifier|amp)' not in updated):
        groups.append(CALLBOOK_EQUIPMENT_REPLACEMENTS)
    if 'function liveContextFor(text)' in updated and 'function requestedFccSearch(text)' not in updated:
        groups.append(FCC_CONTEXT_REPLACEMENTS)
    elif 'function requestedFccSearch(text)' in updated and 'function requestedCallbookProvider(text)' not in updated:
        groups.append(FCC_PROVIDER_REPLACEMENTS)
    elif 'function requestedCallbookProvider(text)' in updated and 'delete data.map' not in updated:
        groups.append(MAP_CONTEXT_REPLACEMENTS)
    elif 'delete data.map' in updated and "return {call,center:'user',limit:1}" not in updated:
        groups.append(SINGLE_CALL_MAP_CONTEXT_REPLACEMENTS)
    elif ('delete data.map' in updated and CALLSIGN_V5 not in updated and
          LIVE_CONTEXT_JS_V5 in updated):
        groups.append(IMPLICIT_CALL_MAP_REPLACEMENTS)
    if CALLSIGN_V4 in updated and CALLSIGN_V5 not in updated:
        groups.append(MAP_CALLSIGN_REPLACEMENTS)
    if CALLSIGN_V5 in updated and CALLSIGN_V6 not in updated and DETAILS_V4 in updated:
        groups.append(PHOTO_SYNONYM_REPLACEMENTS)
    if CALLSIGN_V6 in updated and CALLSIGN_V7 not in updated and DETAILS_V5 in updated:
        groups.append(FRENCH_CALLBOOK_REPLACEMENTS)
    if 'id="elmerMapSection"' not in updated:
        groups.append(MAP_REPLACEMENTS)
    if 'class="elmer-brand"' in updated and "host==='files.qrz.com'" not in updated:
        groups.append(QRZ_IMAGE_REPLACEMENTS)
    elif 'class="elmer-brand"' in updated and "host==='cdn-bio.qrz.com'" not in updated:
        groups.append((("host==='files.qrz.com'", "host==='cdn-bio.qrz.com'||host==='files.qrz.com'"),))
    if ('class="elmer-brand"' in updated and "host==='files.qrz.com'" in updated and
            "host==='cdn-xml.qrz.com'" not in updated):
        groups.append((("host==='cdn-bio.qrz.com'", "host==='cdn-bio.qrz.com'||host==='cdn-xml.qrz.com'"),))
    if 'class="elmer-brand"' in updated and "host==='www.hamqth.com'" not in updated:
        groups.append(HAMQTH_IMAGE_REPLACEMENTS)
    if 'function liveCallsign(text)' in updated and 'const standalone=' not in updated:
        groups.append(STANDALONE_CALLSIGN_REPLACEMENTS)
    if 'id="elmerVoice"' not in updated:
        groups.append(VOICE_REPLACEMENTS)
    elif VOICE_SCRIPT_CURRENT not in updated:
        old_voice=next((candidate for candidate in VOICE_SCRIPT_OLD if candidate in updated),None)
        if old_voice is None:
            raise RuntimeError('Unable to locate the Ask Elmer voice script cache tag.')
        groups.append(((old_voice,VOICE_SCRIPT_CURRENT),))
    if 'function queryPlanFor(text)' not in updated:
        groups.append(MULTILINGUAL_PLAN_REPLACEMENTS)
    elif '/programs/ElmerShortwave.php' not in updated:
        groups.append(SHORTWAVE_CURRENT_UPGRADE_REPLACEMENTS if 'lastFccRequest=' in updated
                      else SHORTWAVE_PLAN_UPGRADE_REPLACEMENTS)
    if ('function requestedFccSearch(text)' in updated and 'clubs_only:' in updated
            and 'localIntent=' not in updated):
        groups.append(LOCAL_CLUB_QTH_REPLACEMENTS)
    if ('function requestedFccSearch(text)' in updated
            and 'if(clubsOnly&&localIntent&&radius===null)radius=25;' in updated):
        groups.append(LOCAL_HAMS_QTH_REPLACEMENTS)
    if ('function liveContextFromPlan(plan,text)' in updated
            and ('detectedFcc=requestedFccSearch(text)' in updated
                 or 'const source=fcc.enabled?fcc:detectedFcc,request=' in updated)):
        groups.append(LOCAL_FCC_PRECEDENCE_REPLACEMENTS)
    if ('function requestedFccSearch(text)' in updated and 'callCenter=' not in updated):
        groups.append(CALL_CENTER_FCC_REPLACEMENTS)
    if ('function liveContextFromPlan(plan,text)' in updated
            and 'if(source.call)request.call=source.call;' in updated):
        groups.append(CALL_CENTER_FILTER_REPLACEMENTS)
    if ("if(call&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))return {call,center:'user',limit:1};"
            in updated):
        groups.append(SHOW_CALL_DIRECTORY_REPLACEMENTS)
    if ('function liveContextFromPlan(plan,text)' in updated
            and 'request.center_latitude=latitude' not in updated):
        groups.append(CALL_CENTER_QRZ_FALLBACK_REPLACEMENTS)
    if '/programs/ElmerShortwave.php' in updated and 'function linkShortwaveFrequencies()' not in updated:
        if 'window.elmerShortwaveRows=' not in updated:
            groups.append(SHORTWAVE_ROW_CACHE_REPLACEMENTS)
        groups.append(SHORTWAVE_CLICK_REPLACEMENTS)
    if 'function linkFccCallsigns()' in updated and 'function callsignFromQrzLink(' not in updated:
        groups.append(DX_CALL_LINK_REPLACEMENTS)
    elif 'function callsignFromQrzLink(' in updated and 'pendingDxLookup=' not in updated:
        groups.append(DX_CALL_BACKGROUND_REPLACEMENTS)
    elif 'function callsignFromQrzLink(' in updated and 'callbookRequest=fetch(' in updated:
        groups.append(DX_CALL_CENTRAL_REFRESH_REPLACEMENTS)
    if ('function primeDxCall(call)' in updated
            and "fccList?.addEventListener('click'" not in updated):
        groups.append(FCC_DIRECTORY_DX_REPLACEMENTS)
    if 'function liveContextFromPlan(plan,text)' in updated and '/programs/ElmerWeather.php' not in updated:
        groups.append(WEATHER_PLAN_REPLACEMENTS)
    elif '/programs/ElmerWeather.php' in updated and 'historical_date:String(weather.historical_date' not in updated:
        groups.append(((WEATHER_FETCH_JS_OLD,WEATHER_FETCH_JS),))
    if 'function liveContextFromPlan(plan,text)' in updated and '/programs/ElmerLogbook.php' not in updated:
        groups.append(LOGBOOK_CONTEXT_REPLACEMENTS)
    elif '/programs/ElmerLogbook.php' in updated and 'if(!logbookRequest&&resolvedBook.enabled' not in updated:
        groups.append(LOGBOOK_ONLY_CONTEXT_REPLACEMENTS)
    if 'id="elmerIcebreaker"' not in updated:
        groups.append(ICEBREAKER_REPLACEMENTS if "setupCheck=document.querySelector('#elmerSetupCheck');" in updated
                      else ICEBREAKER_BASE_REPLACEMENTS)
    elif 'id="elmerQsoIdeas"' not in updated:
        groups.append(OLD_ICEBREAKER_UPGRADE_REPLACEMENTS)
    if 'id="elmerIcebreakerKeyer"' not in updated:
        groups.append(QSO_KEYER_UI_REPLACEMENTS)
        if ICEBREAKER_JS_V1 in updated:
            groups.append(QSO_KEYER_JS_UPGRADE_REPLACEMENTS)
    elif 'function copyQsoIdea()' not in updated or "location.href='/keyer.php'" not in updated:
        groups.append(QSO_BEHAVIOR_UPGRADE_REPLACEMENTS)
    if 'function stageQsoIdea()' in updated and 'function cwShorthand(text)' not in updated:
        groups.append(QSO_CW_TRANSLATION_REPLACEMENTS)
    if 'id="elmerQsoIdeas"' in updated and 'elmerQsoLanguage' not in updated:
        groups.append(QSO_LANGUAGE_REPLACEMENTS)
    if "fetch('/api/text-translation'" in updated:
        groups.append(QSO_TRANSLATION_ROUTE_REPLACEMENTS)
    if 'elmerPendingControlQuestion' not in updated:
        if 'function rigControlFromPlan(plan)' not in updated:
            groups.append(RIG_CONTROL_REPLACEMENTS)
        elif "phase:'cancel'" not in updated:
            groups.append(RIG_CONTROL_UPGRADE_REPLACEMENTS)
        elif "prepare={phase:'prepare'" not in updated:
            groups.append(RIG_CONTROL_PARAMETER_V2_UPGRADE_REPLACEMENTS)
        elif 'radio:Number(action.radio)>0' not in updated:
            groups.append(RIG_CONTROL_PARAMETER_UPGRADE_REPLACEMENTS)
        elif "controlNotice?.classList" not in updated:
            groups.append(RIG_CONTROL_NOTICE_UPGRADE_REPLACEMENTS)
    if 'id="elmerActivityDialog"' not in updated:
        groups.append(ACTIVITY_REPLACEMENTS)
    elif 'function repeatElmerAction(item)' not in updated:
        groups.append(ACTIVITY_REPLAY_UPGRADE_REPLACEMENTS)
    elif 'escapeHtml(outcome.radio)' in updated:
        groups.append(ACTIVITY_REPEAT_ESCAPE_UPGRADE_REPLACEMENTS)
    if 'id="elmerActivityButton" type="button">Station Activity</button>' in updated:
        groups.append(ACTIVITY_LABEL_UPGRADE_REPLACEMENTS)
    if 'class="elmer-control-note"' not in updated:
        groups.append(RADIO_NOTICE_REPLACEMENTS)
    elif 'id="elmerControlNotice"' not in updated:
        groups.append(RADIO_NOTICE_UPGRADE_REPLACEMENTS)
    if 'elmerPendingControlQuestion' not in updated:
        groups.append(CONTROL_SPLIT_REPLACEMENTS)
    if '<div class="elmer-qso-tools">' in updated or 'id="elmerQsoIdeas"' not in updated:
        groups.append(QSO_BUTTON_ROW_REPLACEMENTS)
    for replacements in groups:
        for old,new in replacements:
            count=updated.count(old)
            if count!=1:
                raise RuntimeError(f'Expected one match, found {count}: {old[:72]}')
            updated=updated.replace(old,new,1)
    if updated==text:
        return False
    backup=path.with_name(path.name+'.pre-cloud-pairing')
    if not backup.exists():
        shutil.copy2(path,backup)
    fd,temp_name=tempfile.mkstemp(prefix='.'+path.name+'.',dir=path.parent)
    try:
        with os.fdopen(fd,'w',encoding='utf-8') as handle:
            fd=-1
            handle.write(updated)
            handle.flush()
            os.fsync(handle.fileno())
        shutil.copymode(path,temp_name)
        os.replace(temp_name,path)
    finally:
        if fd>=0:
            os.close(fd)
        try:
            os.unlink(temp_name)
        except FileNotFoundError:
            pass
    return True


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--target',default='/var/www/html/elmer.php')
    args=parser.parse_args()
    changed=patch(args.target)
    print('Updated Ask Elmer cloud interface.' if changed else
          'Ask Elmer cloud interface is already current.')


if __name__=='__main__':
    main()
