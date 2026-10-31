<?php
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
$dRoot='/var/www/html';
header('Cache-Control: no-store');
$isData=isset($_GET['data']);
function statusError($code,$message) { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['error'=>$message]); exit; }
if (empty($_SESSION['myUsername'])) { if($isData) statusError(401,'Please log in again.'); header('Location: /login.php'); exit; }
try {
 require_once "$dRoot/classes/MysqliDb.php"; require "$dRoot/programs/sqldata.php";
 $db=new MysqliDb('localhost',$sql_radio_username,$sql_radio_password,$sql_radio_database);
 $db->where('Username',$_SESSION['myUsername']); $access=$db->getOne('Users','Access_Level,SelectedRadio');
 if (!$access || !in_array((string)$access['Access_Level'],['1','2'],true)) statusError(403,'Administrator access required.');
} catch(Throwable $e) { statusError(503,'System Status is temporarily unavailable.'); }

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(($_POST['action']??'')==='sdr-master'){
        if((string)$access['Access_Level']!=='1')statusError(403,'RigPi administrator access is required.');
        $token=$_POST['token']??null;
        if(!is_string($token)||empty($_SESSION['sdrMasterToken'])||!hash_equals($_SESSION['sdrMasterToken'],$token))statusError(403,'Please reload System Status before changing SDR master control.');
        $site=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));if($site!==''&&$site!=='same-origin')statusError(403,'Use SDR master control from this RigPi.');
        $mode=$_POST['state']??null;if(!is_string($mode)||!in_array($mode,['off','on'],true))statusError(400,'Invalid SDR master setting.');
        $actor=(string)$_SESSION['myUsername'];session_write_close();
        require_once $dRoot.'/programs/SdrMasterControl.php';
        try{$result=rigpiSdrMasterChange($mode,$actor);header('Content-Type: application/json');echo json_encode($result,JSON_THROW_ON_ERROR);}
        catch(Throwable $error){statusError(409,$error->getMessage());}
        exit;
    }
    if(($_POST['action']??'')!=='reboot')statusError(400,'Unknown action.');
    $token=$_POST['token']??null;
    if(!is_string($token)||empty($_SESSION['statusRebootToken'])||!hash_equals($_SESSION['statusRebootToken'],$token))statusError(403,'Please reload System Status before rebooting.');
    unset($_SESSION['statusRebootToken']);
    session_write_close();
    require "$dRoot/programs/reboot.php";
    exit;
}
if(!$isData&&(string)$access['Access_Level']==='1'&&empty($_SESSION['sdrMasterToken']))$_SESSION['sdrMasterToken']=bin2hex(random_bytes(32));
if(!$isData&&empty($_SESSION['statusRebootToken']))$_SESSION['statusRebootToken']=bin2hex(random_bytes(32));
if($isData) {
 $viewer=['username'=>(string)$_SESSION['myUsername'],'peer'=>(string)($_SERVER['REMOTE_ADDR']??''),'reported'=>'','agent'=>(string)($_SERVER['HTTP_USER_AGENT']??'')];
 $candidate=(string)($_SERVER['HTTP_CF_CONNECTING_IP']??'');
 if(!filter_var($candidate,FILTER_VALIDATE_IP))$candidate=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??''))[0]);
 if(filter_var($candidate,FILTER_VALIDATE_IP))$viewer['reported']=$candidate;
 session_write_close();
 require "$dRoot/programs/SystemStatusData.php";
 try { $snapshot=rigpiSystemStatus($db,$viewer); header('Content-Type: application/json'); echo json_encode($snapshot,JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR); }
 catch(Throwable $e) { statusError(503,'Unable to refresh System Status.'); }
 exit;
}
$db->where('Radio',(int)$access['SelectedRadio']); $statusRadio=$db->getOne('MySettings','DX');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>RigPi System Status</title>
<link rel="stylesheet" href="/Bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/awe/css/all.css">
<script src="/Bootstrap/jquery.min.js"></script>
<?php require "$dRoot/includes/styles.php"; ?>
<style>
body{background:#444;color:#fff}.status-wrap{padding:16px 18px 65px;max-width:1500px;margin:auto}.status-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}.status-heading h1{font-size:24px;margin:0}.status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.status-card{background:#363636;border:1px solid #777;border-radius:5px;padding:16px}.status-card h2{font-size:20px;color:#9dd5ff;margin:0 0 12px}.status-card dl{display:grid;grid-template-columns:minmax(110px,1fr) 2fr;gap:6px 15px;margin:0}.status-card dt{font-weight:500;color:#ddd}.status-card dd{margin:0;overflow-wrap:anywhere}.status-card .entry+.entry{border-top:1px solid #666;padding-top:10px;margin-top:10px}.status-card h3{font-size:17px;margin:0 0 6px}.status-note{color:#ddd;font-size:14px;margin:8px 0}.status-error{color:#ffce8b}#statusUpdated{font-variant-numeric:tabular-nums}@media(max-width:750px){.status-grid{grid-template-columns:1fr}.status-wrap{padding:12px 10px 65px}}

#stationHealth{margin:0 0 20px;padding:18px;background:#333;border:1px solid #777;border-radius:7px;scroll-margin-top:20px}#stationHealth h2{font-size:1.4rem;color:#a9d9ff}#stationHealth a{color:#a9d9ff;text-decoration:underline}.health-badge{white-space:nowrap;font-size:.88em;font-weight:600}.health-badge::before{content:'●';display:inline-block;margin-right:.35em}.health-green::before{color:#64d68b}.health-yellow::before{color:#ffd65a}.health-red::before{color:#ff7979}.health-unknown::before{color:#c4c4c4}.health-list{padding-left:1.4em}.health-list li{margin:.65em 0;line-height:1.5}.status-card dt{scroll-margin-top:25px}.status-card dt .health-badge{font-size:.75em}#stationHealth summary{cursor:pointer;color:#a9d9ff}
.health-badge{background:transparent!important;color:inherit!important;border:0!important;padding:0!important}.health-list{list-style:none;padding-left:0}
</style></head><body class="body-black-scroll">
<?php require "$dRoot/includes/header.php"; ?>
<main class="status-wrap"><div class="status-heading"><h1>System Status</h1><div><button id="statusReboot" type="button" class="btn btn-outline-warning btn-sm">Reboot RigPi</button> <button id="statusRefresh" class="btn btn-primary btn-sm">Refresh</button> <button id="statusCopy" class="btn btn-secondary btn-sm" disabled>Copy diagnostic summary</button></div></div>
<p id="rebootMessage" role="status" aria-live="polite" hidden></p><p id="statusUpdated" role="status" aria-live="polite">Loading system status…</p>
<section class="status-card" aria-label="SDR master control" style="margin-bottom:16px">
<h2>SDR master control</h2><p id="sdrMasterState" role="status" aria-live="polite">Checking SDR master setting…</p>
<p class="status-note">All SDRs Off applies to every account and stays set after restarts until an administrator enables SDRs.</p>
<?php if((string)$access['Access_Level']==='1'): ?>
<button id="sdrMasterOff" type="button" class="btn btn-outline-warning btn-sm" disabled>All SDRs Off</button>
<button id="sdrMasterOn" type="button" class="btn btn-primary btn-sm" disabled>Enable SDRs</button>
<?php endif; ?>
<p id="sdrMasterMessage" role="status" aria-live="polite"></p></section>
<section id="stationHealth" aria-label="Station Health"><h2>Station Health</h2><p>Waiting for fresh readings…</p></section><div class="status-grid" id="statusCards"></div>
<p class="status-note">System readings refresh every 10 seconds while this page is visible. Administrator actions require confirmation.</p>
</main>
<?php require "$dRoot/includes/footer.php"; ?>
<script src="/Bootstrap/popper.min.js"></script><script src="/Bootstrap/bootstrap.min.js"></script><script src="/js/nav-active.js"></script>
<script>
document.getElementById('searchText').value=<?php echo json_encode($statusRadio['DX']??'',JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var tUserName=<?php echo json_encode($_SESSION['myUsername'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var tMyRadio=<?php echo (int)$access['SelectedRadio']; ?>,tUser;
$(document).on('click','#logoutButton',function(){const form=document.createElement('form');form.method='POST';form.action='/login.php';for(const [name,value] of Object.entries({status:'loggedout',username:tUserName})){const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);}document.body.append(form);form.submit();});
for(const [id,text] of [['fPanel1','System Status'],['fPanel3','User: '+tUserName],['fPanel5','Read only']]){const panel=document.getElementById(id);if(panel)panel.textContent=text;}
$(document).on('click', '#searchButton', function()
			{
			  var dx=$('#searchText').val().toUpperCase();
			  if (dx.length==0 || ~dx.indexOf('*')){
				return;
			  }
				$.post('/programs/GetUserField.php',{un: tUserName, field: 'uID'}, function(response) {
				  tUser=response;
				  $.post("./programs/GetCallbook.php", {call: dx, what: 'QRZData', user: tUser, un: tUserName},function(response){
					$(".modal-body").html(response);
					$.post("./programs/GetCallbook.php", {call: dx, what: 'QRZpix', user: tUser, un: tUserName},function(response){
					  var aPix=response.split('|');
					  var h=aPix[1];
					  var w=aPix[2];
					  if (h>0){
					  	if (aPix[3]==="flag"){
					  		$(".modal-pix").addClass("flag-pix").css({"width":"auto","max-width":"150px","margin":"10px auto","display":"block"});
					  	}else{
					  		var wP=(aPix[2]/400);
					  		var tW=w/wP;
					  		var tH=h/wP;
					  		$(".modal-pix").attr("height",tH+"px");
					  		$(".modal-pix").attr("width",tW+"px");
					  		$(".modal-pix").css({"display":"","margin":""});
					  	}
					  	$(".modal-pix").attr("src",aPix[0]);
					  }else{
					  	$(".modal-pix").attr("height","0px");
					  	$(".modal-pix").attr("width","0px");
					  	$(".modal-pix").attr("src","about:blank");
					  }
					  $('.modal-title').html(dx);
					  $('#myModal').modal({show:true});
				  });
				});
				$.post("./programs/SetSettings.php", {field: "DX", radio: tMyRadio, data: dx, table: "MySettings"});
			  })

			});

			
$.getScript("/js/modalLoad.js");
(function(){
'use strict';
const cards=document.getElementById('statusCards'),updated=document.getElementById('statusUpdated'),copy=document.getElementById('statusCopy'),refresh=document.getElementById('statusRefresh');
let busy=false,snapshot=null,summary='';
function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;}

const healthHistory=new Map();let healthLastSample=0;
const healthNames={green:'Normal',yellow:'Attention',red:'Problem',unknown:'Unknown'};
const healthRank={green:0,unknown:1,yellow:2,red:3};
function healthTarget(section,row){return 'health-'+(section+'-'+row).toLowerCase().replace(/[^a-z0-9]+/g,'-');}
function settleHealth(checks,now,history){
    return checks.map(check=>{
        const c=Object.assign({},check),signature=c.level;
        let previous=history.get(c.key);
        if(!previous||previous.signature!==signature){previous={signature,since:now};history.set(c.key,previous);}
        if(c.hold>0&&(c.level==='yellow'||c.level==='red')&&now-previous.since<c.hold){c.level='unknown';c.message='Observing this condition for '+c.hold+' seconds before alerting. '+c.message;}
        return c;
    });
}
function healthBadge(level){const badge=el('span',healthNames[level]||'Unknown','health-badge health-'+level);return badge;}
function renderHealth(checks){
    const expanded=!!document.querySelector("#stationHealth details[open]");
    const panel=document.getElementById('stationHealth');panel.replaceChildren();panel.append(el('h2','Station Health'));
    const counts={green:0,yellow:0,red:0,unknown:0};for(const c of checks)counts[c.level]++;
    const level=counts.red?'red':counts.yellow?'yellow':counts.green?'green':'unknown';
    const lead=el('p');lead.append(healthBadge(level),document.createTextNode(' '+(counts.red?counts.red+' problem(s) detected':counts.yellow?counts.yellow+' item(s) need attention':counts.green?'Checked items are normal':'Health assessment unavailable')));panel.append(lead);
    panel.append(el('p',counts.green+' normal · '+counts.yellow+' attention · '+counts.red+' problem · '+counts.unknown+' unknown or observing. Advice only; no automatic changes.','status-note'));
    const list=el('ul',undefined,'health-list');
    const addItem=(c,parent)=>{const item=el('li');item.append(healthBadge(c.level));const target=healthTarget(c.section,c.row);if(document.getElementById(target)){const link=el('a',c.label);link.href='#'+target;item.append(document.createTextNode(' '),link);}else item.append(document.createTextNode(' '+c.label));item.append(document.createTextNode(' — '+c.message));parent.append(item);};
    for(const c of checks.filter(c=>c.level!=='green').sort((a,b)=>healthRank[b.level]-healthRank[a.level]))addItem(c,list);
    if(list.children.length)panel.append(list);
    const normal=checks.filter(c=>c.level==='green');if(normal.length){const detail=el('details');detail.open=expanded;detail.append(el('summary','Show '+normal.length+' normal checks'));const normalList=el('ul',undefined,'health-list');for(const c of normal)addItem(c,normalList);detail.append(normalList);panel.append(detail);}
}
function markHealthStale(){if(!summary.startsWith('STALE SNAPSHOT'))summary='STALE SNAPSHOT — current health unavailable\n'+summary;const panel=document.getElementById('stationHealth');panel.replaceChildren(el('h2','Station Health'),healthBadge('unknown'),el('p','Fresh readings are unavailable. The details below are the last successful snapshot; current health cannot be assessed.'));document.querySelectorAll('.status-card .health-badge').forEach(b=>{b.className='health-badge health-unknown';b.textContent='Unknown · last reading';});healthHistory.clear();healthLastSample=0;}
function draw(data){
renderSdrMaster(data.sdr_master);
const now=performance.now()/1000;if(healthLastSample&&now-healthLastSample>25)healthHistory.clear();healthLastSample=now;
const health=settleHealth(Array.isArray(data.health)?data.health:[],now,healthHistory),healthRows=new Map();
for(const c of health){const key=c.section+'|'+c.row,old=healthRows.get(key);if(!old||healthRank[c.level]>healthRank[old.level])healthRows.set(key,c);}
cards.replaceChildren();let connectionUrl=new URL(window.location.href);try{connectionUrl=new URL(window.top.location.href);}catch(e){}const connectionRows=[['Connection URL',connectionUrl.origin+connectionUrl.pathname],['Connection security',connectionUrl.protocol==='https:'?'HTTPS · encrypted (TLS/SSL)':connectionUrl.protocol==='http:'?'HTTP · not encrypted':'Unable to determine']];const sections=data.sections.map(section=>section.title==='Network IP addresses'?Object.assign({},section,{entries:[{rows:connectionRows}].concat(section.entries)}):section);let lines=['RigPi System Status',data.time,''];for(const section of sections){const card=el('section',undefined,'status-card');card.append(el('h2',section.title));lines.push(section.title);if(section.note){card.append(el('p',section.note,'status-note'));lines.push(section.note);}for(const entry of section.entries){const box=el('div',undefined,'entry');if(entry.title){box.append(el('h3',entry.title));lines.push(entry.title);}const dl=el('dl');for(const row of entry.rows){const dt=el('dt',row[0]),dd=el('dd',row[1]);dt.id=healthTarget(section.title,row[0]);const check=healthRows.get(section.title+'|'+row[0]);if(check){dt.prepend(healthBadge(check.level),document.createTextNode(' '));dt.title=check.message;}dl.append(dt,dd);lines.push(row[0]+': '+row[1]);}box.append(dl);card.append(box);}cards.append(card);lines.push('');}renderHealth(health);lines.push('Station Health');for(const c of health)lines.push(healthNames[c.level]+': '+c.label+' — '+c.message);summary=lines.join('\n');copy.disabled=false;}
async function load(){if(busy||document.hidden||rebootPending)return;busy=true;refresh.disabled=true;const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),7000);try{const response=await fetch('/systemStatus.php?data=1',{cache:'no-store',signal:controller.signal});if(!response.ok)throw new Error(response.status===401?'Please log in again.':response.status===403?'Administrator access required.':'Refresh unavailable.');const data=await response.json();if(!Array.isArray(data.sections))throw new Error('Invalid status response.');snapshot=data;draw(data);updated.textContent='Updated '+data.time;updated.className='status-note';}catch(error){markHealthStale();updated.textContent=(snapshot?'Showing the last successful reading. ':'')+error.message+' Retrying in 10 seconds.';updated.className='status-error';}finally{clearTimeout(timer);busy=false;refresh.disabled=false;}}

let rebootPending=false;
document.getElementById('statusReboot').addEventListener('click',async()=>{
    if(rebootPending||!window.confirm('Reboot RigPi now?\n\nThis disconnects every user and interrupts radio, SDR and audio services.'))return;
    rebootPending=true;
    const button=document.getElementById('statusReboot'),message=document.getElementById('rebootMessage');
    button.disabled=true;button.textContent='Rebooting…';
    message.hidden=false;message.textContent='Reboot requested. Wait for RigPi to restart, then reconnect using the login page.';
    const link=document.createElement('a');link.href='/login.php';link.target='_top';link.textContent=' Open login page';message.appendChild(link);
    try{
        const response=await fetch('/systemStatus.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'reboot',token:<?php echo json_encode($_SESSION['statusRebootToken']??''); ?>})});
        if(!response.ok){let text='Unable to request reboot.';try{text=(await response.json()).error||text;}catch(e){}throw new Error(text);}
    }catch(error){
        if(error instanceof TypeError){message.firstChild.textContent='Connection closed during the reboot request. Wait for RigPi to restart, then reconnect.';}
        else{message.textContent=error.message;button.disabled=false;button.textContent='Reboot RigPi';rebootPending=false;}
    }
});

let sdrMasterBusy=false;
const sdrMasterToken=<?php echo json_encode($_SESSION['sdrMasterToken']??''); ?>;
function renderSdrMaster(state){
 const label=document.getElementById('sdrMasterState'),off=document.getElementById('sdrMasterOff'),on=document.getElementById('sdrMasterOn');
 if(!state||typeof state.off!=='boolean'){label.textContent='SDR master state unavailable.';if(off)off.disabled=true;if(on)on.disabled=true;return;}
 label.textContent=state.off?'All SDRs Off — persistent master setting. '+state.stopped+' of 5 receiver services stopped.':'SDRs enabled — '+state.running+' of 5 receiver services running.';
 if(state.pending)label.textContent+=' Receiver services are changing state…';
 if(off)off.disabled=sdrMasterBusy||state.pending||state.off;
 if(on)on.disabled=sdrMasterBusy||state.pending||!state.off;
}
async function changeSdrMaster(mode){
 if(sdrMasterBusy)return;
 const question=mode==='off'?'Turn off all five SDR receiver services for every account?\n\nThis disconnects SDR sessions. They will stay off after restarts until an administrator enables SDRs. Account receiver selections are preserved.':'Enable all five SDR receiver services?\n\nSaved receiver selections may reopen. This does not request transmission.';
 if(!window.confirm(question))return;
 sdrMasterBusy=true;if(snapshot)renderSdrMaster(snapshot.sdr_master);
 const message=document.getElementById('sdrMasterMessage');message.textContent='Saving SDR master setting…';
 try{
  const response=await fetch('/systemStatus.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'sdr-master',state:mode,token:sdrMasterToken})});
  const data=await response.json();if(!response.ok)throw new Error(data.error||'The SDR master setting could not be changed.');
  if(snapshot)snapshot.sdr_master=data;renderSdrMaster(data);
  message.textContent=mode==='off'?'All SDRs Off is saved. Receiver services are stopping.':'SDRs are enabled. Receiver services are starting.';
 }catch(error){message.textContent=error.message;}
 finally{sdrMasterBusy=false;if(snapshot)renderSdrMaster(snapshot.sdr_master);await load();}
}
const sdrOffButton=document.getElementById('sdrMasterOff'),sdrOnButton=document.getElementById('sdrMasterOn');
if(sdrOffButton)sdrOffButton.addEventListener('click',()=>changeSdrMaster('off'));
if(sdrOnButton)sdrOnButton.addEventListener('click',()=>changeSdrMaster('on'));

refresh.addEventListener('click',load);document.addEventListener('visibilitychange',()=>{if(!document.hidden)load();});setInterval(load,10000);load();
// Build a self-contained, inert snapshot for email and document editors.
function diagnosticHtml(){
 const root=el('div');root.style.cssText='font:14px Arial,sans-serif;line-height:1.45;color:#222;background:#fff;max-width:960px';
 root.append(el('h1','RigPi System Status'),el('p',updated.textContent));
 const colors={green:'#16803c',yellow:'#946200',red:'#c62828',unknown:'#666666'};
 function exportNode(source){
  if(source.nodeType===3)return document.createTextNode(source.textContent);
  if(source.nodeType!==1)return document.createTextNode('');
  if(source.classList.contains('health-badge')){
   const level=Object.keys(colors).find(k=>source.classList.contains('health-'+k))||'unknown';
   const badge=el('strong','● '+source.textContent);badge.style.color=colors[level];return badge;
  }
  if(source.tagName==='DL'){
   const table=el('table');table.setAttribute('cellspacing','0');table.setAttribute('cellpadding','6');table.style.cssText='border-collapse:collapse;width:100%;margin:8px 0 18px';
   for(const dt of source.querySelectorAll(':scope > dt')){
    const row=el('tr'),label=el('th'),value=el('td',dt.nextElementSibling?.textContent||'');
    label.setAttribute('scope','row');label.style.cssText='text-align:left;vertical-align:top;width:36%;border:1px solid #ccc;background:#f3f6f8';
    value.style.cssText='vertical-align:top;border:1px solid #ccc;white-space:pre-wrap;overflow-wrap:anywhere';
    for(const child of dt.childNodes)label.append(exportNode(child));row.append(label,value);table.append(row);
   }return table;
  }
  const tags={H2:'h2',H3:'h3',P:'p',UL:'div',LI:'p',STRONG:'strong',SPAN:'span',SUMMARY:'h3'};
  const out=el(tags[source.tagName]||'div');
  if(source.tagName==='SUMMARY')return el('h3',source.textContent.replace(/^Show /,''));
  if(source.tagName==='A')return document.createTextNode(source.textContent);
  if(source.tagName==='H2')out.style.cssText='font-size:20px;color:#245a78;border-bottom:1px solid #bbb;padding-bottom:5px;margin-top:24px';
  if(source.tagName==='H3'||source.tagName==='SUMMARY')out.style.cssText='font-size:16px;margin:16px 0 6px';
  if(source.tagName==='LI')out.style.cssText='list-style:none;margin:8px 0';
  for(const child of source.childNodes)out.append(exportNode(child));return out;
 }
 root.append(exportNode(document.getElementById('stationHealth')));
 for(const card of cards.children)root.append(exportNode(card));
 return root.outerHTML;
}
function legacyDiagnosticCopy(text,html){
 const field=el('textarea',text);field.style.cssText='position:fixed;left:-9999px';document.body.append(field);
 let rich=false;const handler=event=>{if(event.clipboardData){event.clipboardData.setData('text/plain',text);event.clipboardData.setData('text/html',html);event.preventDefault();rich=true;}};
 document.addEventListener('copy',handler);
 try{field.select();if(!document.execCommand('copy'))throw new Error('Copy unavailable');return rich;}
 finally{document.removeEventListener('copy',handler);field.remove();copy.focus();}
}
copy.addEventListener('click',async()=>{
 const text=summary,html=diagnosticHtml();let rich=false;
 try{
  if(window.isSecureContext&&navigator.clipboard?.write&&typeof ClipboardItem!=='undefined'){
   try{await navigator.clipboard.write([new ClipboardItem({'text/plain':new Blob([text],{type:'text/plain'}),'text/html':new Blob([html],{type:'text/html'})})]);rich=true;}
   catch(error){rich=legacyDiagnosticCopy(text,html);}
  }else{rich=legacyDiagnosticCopy(text,html);}
  copy.textContent=rich?'Copied with formatting':'Copied as text';setTimeout(()=>copy.textContent='Copy diagnostic summary',1800);
 }catch(error){updated.textContent='Unable to copy. Please select and copy the status text.';}
});
})();
</script></body></html>
