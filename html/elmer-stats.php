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
if ((int) getUserField($tUserName, 'Access_Level') !== 1) {
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
  <title><?php echo htmlspecialchars($tCall); ?> RigPi Elmer Status</title>
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
  <script src="/Bootstrap/jquery.min.js"></script>
  <?php require $dRoot . '/includes/styles.php'; ?>
  <style>
    .es-wrap{max-width:1080px;margin:24px auto;padding:0 16px 45px}.es-card{background:#f8f9fa;color:#202830;border-radius:10px;padding:22px;box-shadow:0 4px 18px #0007;margin-bottom:18px}.es-brand{background:#176b99;color:#fff;display:flex;align-items:center;gap:12px;margin:-22px -22px 20px;padding:16px 22px;border-radius:10px 10px 0 0}.es-brand img{width:48px;height:46px;object-fit:contain}.es-brand small{display:block;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.82}.es-brand h1{font-size:2rem;margin:2px 0 0}.es-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px}.es-stat{background:#fff;border:1px solid #d8e1e5;border-radius:8px;padding:14px}.es-stat strong{display:block;color:#176b99;font-size:1.65rem}.es-muted{color:#65747c}.es-table{width:100%;border-collapse:collapse}.es-table th,.es-table td{padding:8px;border-bottom:1px solid #d7dfe3;text-align:left}.es-error{color:#a12622;font-weight:700}.es-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.es-actions select{width:auto}
  </style>
</head>
<body class="body-black-scroll" id="ElmerStatus">
<?php require $dRoot . '/includes/header.php'; ?>
<main class="es-wrap">
  <section class="es-card">
    <div class="es-brand"><img src="/images/AskElmerRigPiW.png" alt="RigPi logo"><div><small>RigPi</small><h1>Elmer Status</h1></div></div>
    <div class="es-actions"><label for="period"><strong>Reporting period</strong></label><select id="period" class="form-control"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="365">1 year</option></select><button id="refresh" class="btn btn-primary" type="button">Refresh</button><a class="btn btn-outline-secondary" href="/elmer.php">Ask Elmer</a></div>
    <p id="status" class="es-muted mt-3">Loading this RigPi’s statistics…</p>
  </section>
  <section id="summary" class="es-card" hidden><h2 id="stationName"></h2><p id="stationMeta" class="es-muted"></p><div class="es-grid"><div class="es-stat"><span>Monthly allowance</span><strong id="allowance">—</strong></div><div class="es-stat"><span>Questions in period</span><strong id="questions">—</strong></div><div class="es-stat"><span>Total tokens</span><strong id="tokens">—</strong></div><div class="es-stat"><span>Average rating</span><strong id="rating">—</strong></div><div class="es-stat"><span>First words</span><strong id="firstWords">—</strong></div><div class="es-stat"><span>Complete answer</span><strong id="completeTime">—</strong></div></div></section>
  <section id="details" class="es-card" hidden><h2>Daily activity</h2><table class="es-table"><thead><tr><th>Date</th><th>Questions</th><th>Tokens</th></tr></thead><tbody id="daily"></tbody></table><h2 class="mt-4">Outcomes</h2><div id="outcomes" class="es-grid"></div><p class="es-muted mt-3">This page reports operational totals only. It does not display users’ question or answer text.</p></section>
</main>
<script>
const number=value=>Number(value||0).toLocaleString();
async function loadStats(){const days=document.querySelector('#period').value,status=document.querySelector('#status');status.className='es-muted mt-3';status.textContent='Loading this RigPi’s statistics…';try{const response=await fetch('/elmer-api/station/statistics?days='+encodeURIComponent(days),{credentials:'same-origin'}),data=await response.json();if(!response.ok)throw new Error(data.error||'Unable to load Elmer statistics.');const station=data.station;document.querySelector('#stationName').textContent=station.station_name;document.querySelector('#stationMeta').textContent=`${station.station_id} · ${station.plan} plan · ${station.connected?'Connected':'Disconnected'} · ${data.period_days} day report`;document.querySelector('#allowance').textContent=`${number(station.used)} / ${number(station.monthly_limit)}`;document.querySelector('#questions').textContent=number(data.questions);document.querySelector('#tokens').textContent=number(data.tokens.total);document.querySelector('#rating').textContent=data.feedback.average_rating===null?'—':`${data.feedback.average_rating} / 10`;document.querySelector('#firstWords').textContent=data.timing.average_first_text_seconds===null?'—':`${data.timing.average_first_text_seconds}s`;document.querySelector('#completeTime').textContent=data.timing.average_total_seconds===null?'—':`${data.timing.average_total_seconds}s`;document.querySelector('#daily').innerHTML=data.daily.length?data.daily.map(row=>`<tr><td>${row.day}</td><td>${number(row.questions)}</td><td>${number(row.tokens)}</td></tr>`).join(''):'<tr><td colspan="3">No activity in this period.</td></tr>';document.querySelector('#outcomes').innerHTML=Object.entries(data.statuses).map(([key,value])=>`<div class="es-stat"><span>${key.replaceAll('_',' ')}</span><strong>${number(value)}</strong></div>`).join('')||'<p>No outcomes in this period.</p>';document.querySelector('#summary').hidden=false;document.querySelector('#details').hidden=false;status.textContent=`Updated ${new Date().toLocaleString()}`;}catch(error){status.className='es-error mt-3';status.textContent=error.message}}
document.querySelector('#refresh').addEventListener('click',loadStats);document.querySelector('#period').addEventListener('change',loadStats);loadStats();
</script>
</body></html>
