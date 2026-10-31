/* Read-only device availability; receiver use is distinct from account assignment. */
(function () {
'use strict';
const config = JSON.parse(document.getElementById('sdrAvailabilityConfig').textContent);
let records = [], complete = false;
async function read(url) {
 const controller = new AbortController(); const timer=setTimeout(()=>controller.abort(),2500);
 try { const r=await fetch(url,{cache:'no-store',signal:controller.signal}); if(!r.ok)throw Error('status'); return await r.json(); }
 finally {clearTimeout(timer);}
}
function owner(record) {
 const instance=record.port-8000, name=String(record.in_use_by||'').trim();
 if(name)return name+' (SDR '+instance+')';
 const assigned=(config.accounts[String(instance)]||[]).join(', ');
 return 'SDR '+instance+(assigned?' — assigned to '+assigned:' — operator unknown');
}
function status(device) {
 if(device.driver==='none')return '';
 const matching=records.filter(r=>device.driver==='sdrconnect'
   ? r.state.sdrconn_active && r.remote && r.remote.connected && (!r.remote.device || r.remote.device===device.serial)
   : r.state.device_open && r.state.device_driver===device.driver && (!r.state.device_serial || !device.serial || r.state.device_serial===device.serial));
 if(matching.length)return 'In use by '+matching.map(owner).join('; ');
 return complete?'Available':'Availability unknown — status check incomplete';
}
async function refresh() {
 let registry; try { registry=await read(config.base+'/api/instances'); if(!Array.isArray(registry))throw Error('inventory'); }
 catch(e){complete=false;records=[];return;}
 const ports=[...new Set(config.instances.map(n=>8000+n).concat(registry.map(r=>Number(r.port))))].filter(p=>p>=8001&&p<=8010);
 const checked=await Promise.all(ports.map(async port=>{
  const entry=registry.find(r=>Number(r.port)===port)||{port};
  try {
   const state=await read('/sdr'+(port-8000)+'/api/system/status');
   if(state.schema_version!==1 || Number(state.instance_port)!==port)throw Error('invalid status');
   const remote=state.sdrconn_active?await read('/sdr'+(port-8000)+'/api/sdrconn/status'):null;
   return {...entry,port,state,remote};
  }catch(e){return null;}
 }));
 records=checked.filter(Boolean);complete=ports.length>0&&records.length===ports.length;
}
window.SdrDeviceAvailability={refresh,status};
})();
