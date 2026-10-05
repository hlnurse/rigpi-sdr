<?php
session_start();
$dRoot = '/var/www/html';
if (empty($_SESSION['myUsername'])) {
    header('Location: /login.php');
    exit;
}
require_once $dRoot . '/programs/GetUserFieldFunc.php';
$tUserName = $_SESSION['myUsername'];
$tCall = $_SESSION['myCall'] ?? '';
$tFirstName = trim((string) getUserField($tUserName, 'FirstName'));
$tGreetingName = $tFirstName !== '' ? $tFirstName : ($tCall !== '' ? strtoupper($tCall) : $tUserName);
$level = (int) getUserField($tUserName, 'Access_Level');
if ($level !== 1) {
    http_response_code(403);
    echo 'Administrator access is required.';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($tCall); ?> RigPi Ask Elmer</title>
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="/Bootstrap/jquery-ui.css">
  <script src="/Bootstrap/jquery.min.js"></script>
  <script defer src="/awe/js/all.js"></script>
  <link href="/awe/css/all.css" rel="stylesheet">
  <link href="/awe/css/fontawesome.css" rel="stylesheet">
  <link href="/awe/css/solid.css" rel="stylesheet">
  <?php require $dRoot . '/includes/styles.php'; ?>
  <style>
    .elmer-wrap{max-width:980px;margin:24px auto;padding:0 16px 45px}.elmer-card{background:#f8f9fa;color:#202830;border-radius:10px;padding:22px;box-shadow:0 4px 18px #0007}.elmer-greeting{display:block;color:#28566f;font-size:1.18rem;margin:.15em 0 .8em}.elmer-result{display:none;margin-top:18px}.elmer-result.visible{display:block}#elmerQuestion{min-height:100px;font-size:18px}#elmerStatus{color:#176b99;font-weight:700;min-height:28px}#elmerAnswer{overflow-wrap:anywhere;font-size:17px;line-height:1.58}#elmerAnswer.streaming{white-space:pre-wrap}#elmerAnswer h2{color:#183b54;font-size:1.45rem;text-align:left;margin:1.25em 0 .45em;border-bottom:1px solid #ccd7dc;padding-bottom:.2em}#elmerAnswer h3{color:#28566f;font-size:1.18rem;margin:1.1em 0 .35em}#elmerAnswer p{margin:.65em 0}#elmerAnswer ul,#elmerAnswer ol{padding-left:1.7em;margin:.55em 0}#elmerAnswer li{margin:.38em 0}#elmerAnswer strong{color:#102f45}#elmerAnswer code{background:#e4eaed;color:#17212b;border-radius:4px;padding:.08em .3em;font-size:.92em}#elmerAnswer a{color:#075f91;text-decoration:underline;text-decoration-thickness:2px}.elmer-metrics{border-top:1px solid #ccd4d8;margin-top:18px;padding-top:9px;color:#64717a;font-size:13px}.elmer-feedback{display:none;border-top:1px solid #ccd4d8;margin-top:18px;padding-top:15px}.elmer-feedback.visible{display:block}.elmer-feedback-note{background:#e9f2f6;border-left:4px solid #176b99;border-radius:4px;color:#344b58;font-size:.9rem;margin:.75em 0;padding:.65em .8em}.elmer-rating{display:flex;flex-wrap:wrap;gap:6px;margin:.6em 0}.elmer-rating button{min-width:38px}.elmer-rating button.selected{background:#176b99;color:#fff;border-color:#176b99}.elmer-why{display:none;margin-top:12px}.elmer-why.visible{display:block}.elmer-feedback-status{color:#176b99;font-weight:700;margin-top:9px}dialog{width:min(780px,92vw);max-height:82vh;border:0;border-radius:10px;padding:0;box-shadow:0 18px 65px #0008}dialog::backdrop{background:#000b}.elmer-dialog-head{position:sticky;top:0;display:flex;justify-content:space-between;align-items:center;background:#343a40;color:#fff;padding:12px 18px}#elmerReferenceBody{padding:20px;white-space:pre-wrap;font:14px/1.5 monospace}
  </style>
</head>
<body class="body-black-scroll" id="AskElmer">
<?php require $dRoot . '/includes/header.php'; ?>
<?php require $dRoot . '/includes/modal.txt'; ?>
<?php require $dRoot . '/includes/modalCancelOnly.txt'; ?>
<main class="elmer-wrap">
  <section class="elmer-card">
    <h1>Ask Elmer</h1>
    <form id="elmerForm">
      <label for="elmerQuestion" id="elmerGreeting" class="elmer-greeting"></label>
      <textarea class="form-control" id="elmerQuestion" maxlength="1000" required placeholder="How do I configure an IC-7300 for remote audio and CAT control?"></textarea>
      <div class="mt-3"><button class="btn btn-primary" id="elmerAsk" type="submit">Ask Elmer</button> <small class="text-muted ml-2">Enter asks · Shift+Enter starts a new line</small></div>
    </form>
  </section>
  <section id="elmerResult" class="elmer-card elmer-result" aria-live="polite">
    <div id="elmerStatus"></div><div id="elmerAnswer"></div><div id="elmerMetrics" class="elmer-metrics"></div>
    <form id="elmerFeedback" class="elmer-feedback">
      <strong>How helpful was this answer?</strong> <span class="text-muted">1 = not helpful · 10 = exactly right</span>
      <p class="elmer-feedback-note"><strong>About your feedback:</strong> To help improve Elmer, this RigPi stores your rating and comments together with your RigPi username, question, Elmer’s complete answer, model and submission time. During this test, the information remains on this RigPi and is not sent to rigpi.net. Please do not include passwords, API keys or other sensitive information.</p>
      <div id="elmerRating" class="elmer-rating" role="group" aria-label="Rate this answer from 1 to 10"></div>
      <div id="elmerWhy" class="elmer-why">
        <label for="elmerComment"><strong>Why did you choose that rating?</strong></label>
        <textarea id="elmerComment" class="form-control" maxlength="2000" rows="3" placeholder="What helped, or what should Elmer improve?"></textarea>
        <button type="submit" class="btn btn-primary mt-2">Send feedback</button>
      </div>
      <div id="elmerFeedbackStatus" class="elmer-feedback-status" aria-live="polite"></div>
    </form>
  </section>
</main>
<dialog id="elmerDialog"><div class="elmer-dialog-head"><strong>Elmer reference</strong><button class="btn btn-sm btn-light" id="elmerClose">Close</button></div><div id="elmerReferenceBody"></div></dialog>
<script>
const form=document.querySelector('#elmerForm'),question=document.querySelector('#elmerQuestion'),button=document.querySelector('#elmerAsk'),result=document.querySelector('#elmerResult'),statusBox=document.querySelector('#elmerStatus'),answer=document.querySelector('#elmerAnswer'),metrics=document.querySelector('#elmerMetrics'),feedback=document.querySelector('#elmerFeedback'),ratingBox=document.querySelector('#elmerRating'),whyBox=document.querySelector('#elmerWhy'),comment=document.querySelector('#elmerComment'),feedbackStatus=document.querySelector('#elmerFeedbackStatus'),dialog=document.querySelector('#elmerDialog'),referenceBody=document.querySelector('#elmerReferenceBody');
const rigPiUsername=<?php echo json_encode($tUserName); ?>,elmerName=<?php echo json_encode($tGreetingName); ?>;let rigPiRadio=1,rigPiUser='';
let lastQuestion='',lastAnswer='',selectedRating=0;
function updateElmerGreeting(){const hour=new Date().getHours(),period=hour<12?'morning':hour<17?'afternoon':'evening',salutation=hour<12?'GM':hour<17?'GA':'GE';document.querySelector('#elmerGreeting').textContent=`${salutation} ${elmerName}, what's on your mind this ${period}?`}
updateElmerGreeting();
const escapeHtml=s=>s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function inlineMarkdown(text){return escapeHtml(text).replace(/(https:\/\/[^\s<]+|elmer:\/\/[^\s<]+)/g,raw=>{let url=raw,tail='';while(/[.,;)]$/.test(url)){tail=url.slice(-1)+tail;url=url.slice(0,-1)}return url.startsWith('elmer://')?`<a href="#" data-ref="${url}">${url}</a>${tail}`:`<a href="${url}" target="_blank" rel="noopener">${url}</a>${tail}`}).replace(/`([^`]+)`/g,'<code>$1</code>').replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>')}
function renderMarkdown(text){const output=[];let list='';const closeList=()=>{if(list){output.push(`</${list}>`);list=''}};for(const raw of text.split(/\r?\n/)){const line=raw.trim();if(!line){closeList();continue}let match;if((match=line.match(/^(#{1,3})\s+(.+)$/))){closeList();const level=match[1].length>=3?3:2;output.push(`<h${level}>${inlineMarkdown(match[2])}</h${level}>`)}else if((match=line.match(/^[-*]\s+(.+)$/))){if(list!=='ul'){closeList();list='ul';output.push('<ul>')}output.push(`<li>${inlineMarkdown(match[1])}</li>`)}else if((match=line.match(/^\d+[.)]\s+(.+)$/))){if(list!=='ol'){closeList();list='ol';output.push('<ol>')}output.push(`<li>${inlineMarkdown(match[1])}</li>`)}else{closeList();output.push(`<p>${inlineMarkdown(line)}</p>`)}}closeList();return output.join('')}
function parseEvents(buffer,onEvent){const blocks=buffer.split('\n\n'),remainder=blocks.pop();for(const block of blocks){let kind='message',data='';for(const line of block.split('\n')){if(line.startsWith('event:'))kind=line.slice(6).trim();if(line.startsWith('data:'))data+=line.slice(5).trim()}if(data)onEvent(kind,JSON.parse(data))}return remainder}
form.addEventListener('submit',async event=>{event.preventDefault();const q=question.value.trim();if(!q)return;button.disabled=true;result.classList.add('visible');statusBox.textContent='Connecting to Elmer…';answer.classList.add('streaming');answer.textContent='';metrics.textContent='';feedback.classList.remove('visible');whyBox.classList.remove('visible');feedbackStatus.textContent='';comment.value='';selectedRating=0;ratingBox.querySelectorAll('button').forEach(b=>b.classList.remove('selected'));let complete='',buffer='';try{const response=await fetch('/elmer-api/answer',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q})});if(response.status===401||response.status===403){location.href='/login.php';return}if(!response.ok)throw new Error('Unable to ask Elmer');const reader=response.body.getReader(),decoder=new TextDecoder();while(true){const {value,done}=await reader.read();buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});buffer=parseEvents(buffer,(kind,data)=>{if(kind==='status')statusBox.textContent=data.text;if(kind==='delta'){statusBox.textContent='Elmer is answering…';complete+=data.text;answer.textContent=complete}if(kind==='done'){statusBox.textContent='';answer.classList.remove('streaming');answer.innerHTML=renderMarkdown(complete);metrics.textContent=`First words: ${data.timing.first_text_seconds}s · Complete: ${data.timing.total_seconds}s · Tokens: ${data.usage.total_tokens||'—'}`;lastQuestion=q;lastAnswer=complete;feedback.classList.add('visible')}if(kind==='error')throw new Error(data.message)});if(done)break}}catch(error){statusBox.textContent='Elmer could not complete that answer.';answer.textContent=error.message}finally{button.disabled=false;question.focus()}});
for(let rating=1;rating<=10;rating++){const choice=document.createElement('button');choice.type='button';choice.className='btn btn-outline-secondary btn-sm';choice.textContent=rating;choice.setAttribute('aria-label',`Rate ${rating} out of 10`);choice.addEventListener('click',()=>{selectedRating=rating;ratingBox.querySelectorAll('button').forEach(b=>b.classList.toggle('selected',b===choice));whyBox.classList.add('visible');comment.focus()});ratingBox.appendChild(choice)}
feedback.addEventListener('submit',async event=>{event.preventDefault();if(!selectedRating)return;feedbackStatus.textContent='Saving your feedback…';try{const response=await fetch('/elmer-api/feedback',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:rigPiUsername,rating:selectedRating,comment:comment.value.trim(),question:lastQuestion,answer:lastAnswer})});if(response.status===401||response.status===403){location.href='/login.php';return}const data=await response.json();if(!response.ok)throw new Error(data.error||'Unable to save feedback');feedbackStatus.textContent=`73 ${elmerName}, your feedback will help improve RigPi.`;whyBox.classList.remove('visible');ratingBox.querySelectorAll('button').forEach(b=>b.disabled=true)}catch(error){feedbackStatus.textContent=error.message}});
question.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit()}});
answer.addEventListener('click',async event=>{const link=event.target.closest('[data-ref]');if(!link)return;event.preventDefault();referenceBody.textContent='Opening reference…';dialog.showModal();try{const response=await fetch('/elmer-api/reference?ref='+encodeURIComponent(link.dataset.ref));if(response.status===401||response.status===403){location.href='/login.php';return}const data=await response.json();if(!response.ok)throw new Error(data.error);if(data.kind==='document'&&data.source_id==='official-help'){window.open('/Help/RigPi.html?'+encodeURIComponent(data.document.path),'_blank','noopener');dialog.close();return}referenceBody.textContent=data.kind==='document'?`${data.document.title}\n${data.document.path}\n\n${data.document.body}`:data.messages.map(m=>`${m.selected?'SELECTED':'CONTEXT'} — ${m.subject}\n${m.author||'(unknown)'} · ${m.published_at||''}\n\n${m.body}`).join('\n\n──────────\n\n')}catch(error){referenceBody.textContent=error.message}});
document.querySelector('#elmerClose').addEventListener('click',()=>dialog.close());
document.querySelector('#logoutButton')?.addEventListener('click',()=>{const f=document.createElement('form');f.method='POST';f.action='/login.php';f.innerHTML=`<input type="hidden" name="status" value="loggedout"><input type="hidden" name="username" value="<?php echo htmlspecialchars($tUserName, ENT_QUOTES); ?>">`;document.body.appendChild(f);f.submit()});
$.post('/programs/GetSelectedRadio.php',{un:rigPiUsername},response=>{rigPiRadio=response;$.post('/programs/GetSetting.php',{radio:rigPiRadio,field:'DX',table:'MySettings'},value=>$('#searchText').val(value))});
function runCallsignLookup(){const dx=$('#searchText').val().trim().toUpperCase();if(!dx||dx.includes('*'))return;$('#searchText').val(dx);$.post('/programs/GetUserField.php',{un:rigPiUsername,field:'uID'},user=>{rigPiUser=user;$.post('/programs/GetCallbook.php',{call:dx,what:'QRZData',user:rigPiUser,un:rigPiUsername},response=>{$('.modal-body').html(response);$.post('/programs/GetCallbook.php',{call:dx,what:'QRZpix',user:rigPiUser,un:rigPiUsername},picture=>{const parts=picture.split('|'),height=Number(parts[1]||0),width=Number(parts[2]||0),image=$('.modal-pix');if(height>0){if(parts[3]==='flag'){image.addClass('flag-pix').css({width:'auto','max-width':'150px',margin:'10px auto',display:'block'})}else{const scale=width/400;image.removeClass('flag-pix').attr({height:height/scale,width:width/scale}).css({display:'',margin:''})}image.attr('src',parts[0])}else{image.attr({height:0,width:0,src:'about:blank'})}$('.modal-title').html(dx);$('#myModal').modal({show:true})})});$.post('/programs/SetSettings.php',{field:'DX',radio:rigPiRadio,data:dx,table:'MySettings'})})}
$('#searchButton').on('click',event=>{event.preventDefault();runCallsignLookup()});
$('#searchText').on('keydown',event=>{if(event.key==='Enter'){event.preventDefault();runCallsignLookup()}});
question.focus();
</script>
<script src="/Bootstrap/popper.min.js"></script><script src="/Bootstrap/bootstrap.min.js"></script><script src="/js/nav-active.js"></script>
</body></html>
