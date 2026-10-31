<?php
// Read-only collector. Direct requests never return system information.
if (!function_exists('rigpiSystemStatus')) {
function rigpiStatusReceiverTuning($s,$port){
 $none=['frequency'=>'—','center'=>'—','basis'=>'Unavailable'];
 if(($s['schema_version']??null)!==1||($s['instance_port']??null)!==(int)$port)return $none;
 $remote=!empty($s['owrx_active'])||!empty($s['sdrconn_active'])||!empty($s['use_kiwi']);
 $local=!empty($s['device_open'])&&!in_array($s['device_driver']??'',['','fake'],true);
 if(!$remote&&!$local){$none['basis']=($s['device_driver']??'')==='fake'?'No SDR selected':'No receiver connected';return $none;}
 $format=static function($v){return is_numeric($v)&&is_finite((float)$v)&&(float)$v>0?number_format((float)$v/1000000,6).' MHz':'Unavailable';};
 return ['frequency'=>$format($s['frequency_hz']??null),'center'=>$format($s['spectrum_center_hz']??null),'basis'=>(string)($s['frequency_basis']??'Tuning unavailable')];
}
function rigpiStatusRead($path){$s=@file_get_contents($path);return $s===false?null:trim($s);}
function rigpiStatusApis($ports){$multi=curl_multi_init();$handles=[];$results=[];foreach($ports as $port){foreach(['/api/system/status','/api/audio/state','/api/audio/loopback'] as $path){$key=$port.$path;$c=curl_init('http://127.0.0.1:'.$key);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>250,CURLOPT_TIMEOUT_MS=>800,CURLOPT_PROXY=>'']);$handles[$key]=$c;curl_multi_add_handle($multi,$c);}}do{$code=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,0.1);}while($active&&$code===CURLM_OK);foreach($handles as $key=>$c){$results[$key]=curl_getinfo($c,CURLINFO_HTTP_CODE)===200?json_decode(curl_multi_getcontent($c),true):null;curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $results;}
function rigpiStatusProcesses(){
 $result=[];
 foreach(glob('/proc/[0-9]*/stat')?:[] as $path){$raw=@file_get_contents($path);if($raw===false)continue;$left=strpos($raw,'(');$right=strrpos($raw,')');if($left===false||$right===false)continue;$pid=(int)substr($raw,0,$left);$fields=preg_split('/\s+/',trim(substr($raw,$right+1)));if(count($fields)<22)continue;$result[$pid]=['name'=>substr($raw,$left+1,$right-$left-1),'ticks'=>(float)$fields[11]+(float)$fields[12],'start'=>$fields[19],'rss'=>(int)$fields[21]];
 }return $result;
}
function rigpiSystemStatus($db,$viewer=[]){
 if(!function_exists('rigpiSdrMasterStatus'))require_once __DIR__.'/SdrMasterControl.php';
 $master=rigpiSdrMasterStatus();
 $na='Unavailable';$sections=[];$time=gmdate('Y-m-d H:i:s').' UTC';
 $sections[]=['title'=>'SDR master control','note'=>'All SDRs Off is an administrator setting for all five receiver services. It survives restarts and cannot be changed by an account receiver selection. Saved receiver selections are preserved.','entries'=>[['rows'=>[['Master setting',$master['off']?'All SDRs Off':'Enabled'],['Receiver services running',(string)$master['running'].' / 5'],['Change in progress',$master['pending']?'Yes':'No'],['Last changed',$master['changed_at']?:'No master change recorded'],['Changed by',$master['changed_by']?:'Not recorded']]]]];
 $version=$na;$versionSource=rigpiStatusRead('/var/www/html/programs/version.php');
 if(preg_match('/\$vers\s*=\s*["\x27]([^"\x27]+)["\x27]/',$versionSource??'',$match))$version=$match[1];
 $model=trim(str_replace(chr(0),'',rigpiStatusRead('/proc/device-tree/model')??''));
 $os=$na;if(preg_match('/^PRETTY_NAME=["\x27]?(.*?)["\x27]?$/m',rigpiStatusRead('/etc/os-release')??'',$match))$os=$match[1];
 $files=[['Master shell · index.php','/var/www/html/index.php'],['Tuner · tuner.php','/var/www/html/tuner.php'],['Keyer · keyer.php','/var/www/html/keyer.php'],['Menu · includes/header.php','/var/www/html/includes/header.php'],['System Status · systemStatus.php','/var/www/html/systemStatus.php'],['System Status data','/var/www/html/programs/SystemStatusData.php'],['SDR server','/home/pi/sdr_web/sdr_web_server_minimal.py'],['SDR engine','/home/pi/sdr_web/spectrum_engine.py']];
 $fileRows=[];$latest=0;$latestName='';foreach($files as $file){$modified=@filemtime($file[1]);$fileRows[]=[$file[0],$modified?gmdate('Y-m-d H:i:s',$modified).' UTC':$na];if($modified>$latest){$latest=$modified;$latestName=$file[0];}}
 $rows=[['RigPi installed version',$version],['Hardware',$model?:$na],['Operating system',$os],['Kernel',php_uname('r')],['Most recent listed file',$latest?gmdate('Y-m-d H:i:s',$latest).' UTC · '.$latestName:$na]];
 $sections[]=['title'=>'Installed version and files','note'=>'Version comes from the installed RigPi version file. File dates show last modification, including local changes. Master shell includes the new Audio Settings.','entries'=>[['rows'=>$rows],['title'=>'Key file dates','rows'=>$fileRows]]];
 $cpu=function(){ $v=preg_split('/\s+/',rigpiStatusRead('/proc/stat')??'');return array_map('floatval',array_slice($v,1,8));};
    $serviceCpuSample=static function(){
        $units=['nginx.service','php8.4-fpm.service','mariadb.service','cloudflared.service','elmer-web.service','rigpi-duckdns-update.service'];
        foreach(range(8001,8005) as $p)$units[]='sdr_web@'.$p.'.service';
        $result=[];
        foreach($units as $unit){
            $path='/sys/fs/cgroup/system.slice/'.(strpos($unit,'sdr_web@')===0?'system-sdr_web.slice/':'').$unit;
            clearstatcache(true,$path);$inode=@fileinode($path);$text=rigpiStatusRead($path.'/cpu.stat');
            if($inode!==false&&preg_match('/^usage_usec\s+(\d+)/m',$text??'',$m))$result[$unit]=['usage'=>(float)$m[1],'inode'=>$inode,'time'=>hrtime(true)];
        }
        return $result;
    };
    $serviceCpuA=$serviceCpuSample();
$procA=rigpiStatusProcesses();$a=$cpu();usleep(500000);$b=$cpu();$procB=rigpiStatusProcesses();$serviceCpuB=$serviceCpuSample();$delta=array_sum($b)-array_sum($a);$usage=$delta>0?round(100*(1-(($b[3]+$b[4])-($a[3]+$a[4]))/$delta),1).'%':$na;
 $mem=rigpiStatusRead('/proc/meminfo');preg_match('/MemTotal:\s+(\d+)/',$mem??'',$mt);preg_match('/MemAvailable:\s+(\d+)/',$mem??'',$ma);$memory=isset($mt[1],$ma[1])?round(($mt[1]-$ma[1])/1024).' / '.round($mt[1]/1024).' MB used':$na;
 preg_match('/SwapTotal:\s+(\d+)/',$mem??'',$st);preg_match('/SwapFree:\s+(\d+)/',$mem??'',$sf);$swap=isset($st[1],$sf[1])?((int)$st[1]===0?'Disabled (0 MB configured)':round(($st[1]-$sf[1])/1024).' / '.round($st[1]/1024).' MB used'):$na;
 $up=rigpiStatusRead('/proc/uptime');$seconds=$up!==null?(int)explode(' ',$up)[0]:null;$uptime=$seconds===null?$na:floor($seconds/86400).'d '.floor(($seconds%86400)/3600).'h '.floor(($seconds%3600)/60).'m';
 $temp=rigpiStatusRead('/sys/class/thermal/thermal_zone0/temp');$total=@disk_total_space('/');$free=@disk_free_space('/');$load=sys_getloadavg();$cores=preg_match_all('/^processor\s*:/m',rigpiStatusRead('/proc/cpuinfo')??'');
 $sections[]=['title'=>'System health','entries'=>[['rows'=>[['Host',gethostname()?:$na],['Uptime',$uptime],['CPU use',$usage],['Load (1 / 5 / 15 min)',implode(' / ',array_map(function($v){return number_format($v,2);},$load?:[])).' · '.$cores.' cores'],['CPU temperature',is_numeric($temp)?round($temp/1000,1).' °C':$na],['Memory',$memory],['Swap',$swap],['Root disk',$total&&$free?round(($total-$free)/1073741824,1).' / '.round($total/1073741824,1).' GB used':$na]]]]];
 $fanRows=[];
 foreach(glob('/sys/class/hwmon/hwmon*')?:[] as $sensor){if(rigpiStatusRead($sensor.'/name')!=='pwmfan')continue;$rpm=rigpiStatusRead($sensor.'/fan1_input');$pwm=rigpiStatusRead($sensor.'/pwm1');$fanRows[]=['Cooling fan',is_numeric($rpm)?((float)$rpm>0?'Running · '.number_format((float)$rpm).' RPM':'Stopped · 0 RPM'):'Speed unavailable'];$fanRows[]=['Fan drive',is_numeric($pwm)?round(100*(float)$pwm/255).'%':$na];break;}
 foreach(glob('/sys/class/thermal/cooling_device*')?:[] as $cooling){if(rigpiStatusRead($cooling.'/type')!=='pwm-fan')continue;$state=rigpiStatusRead($cooling.'/cur_state');$maximum=rigpiStatusRead($cooling.'/max_state');$fanRows[]=['Fan cooling level',is_numeric($state)&&is_numeric($maximum)?$state.' / '.$maximum:$na];break;}
 if(!$fanRows)$fanRows[]=['Cooling fan','No fan sensor available'];
 array_splice($sections[count($sections)-1]['entries'][0]['rows'],5,0,$fanRows);
 $power=json_decode(rigpiStatusRead('/run/rigpi-health-status/status.json')??'',true);
 $powerFresh=is_array($power)&&isset($power['time'])&&time()-(int)$power['time']<=30&&time()>=(int)$power['time']&&isset($power['flags'])&&is_int($power['flags']);
 $powerRows=[];
 foreach([['Undervoltage',0,16],['CPU frequency capped',1,17],['CPU throttling',2,18],['Soft temperature limit',3,19]] as $flag){$current=$powerFresh?(($power['flags']&(1<<$flag[1]))?'Active':'Normal'):$na;$history=$powerFresh?(($power['flags']&(1<<$flag[2]))?'Recorded since boot':'None since boot'):$na;$powerRows[]=[$flag[0],$current.' · '.$history];}
 $powerRows[]=['Firmware reading',$powerFresh?'0x'.dechex($power['flags']).' · '.gmdate('H:i:s',$power['time']).' UTC':'Unavailable or older than 30 seconds'];
 $sections[]=['title'=>'Power and throttling','note'=>'Current conditions and firmware event history since boot. The reading refreshes every 10 seconds.','entries'=>[['rows'=>$powerRows]]];
 $processes=[];if($delta>0){foreach($procB as $pid=>$process){if(!isset($procA[$pid])||$procA[$pid]['start']!==$process['start'])continue;$ticks=max(0,$process['ticks']-$procA[$pid]['ticks']);$processes[]=['pid'=>$pid,'name'=>$process['name'],'cpu'=>min(100,100*$ticks/$delta)];}}
 usort($processes,function($x,$y){return $y['cpu']<=>$x['cpu'];});$processRows=[];foreach(array_slice($processes,0,5) as $process){$processRows[]=[$process['name'].' · PID '.$process['pid'],number_format($process['cpu'],1).'%'];}
 $sections[]=['title'=>'Highest CPU consumers','note'=>'Sampled over 0.5 seconds. Percentages use total CPU capacity across all cores, matching CPU use above.','entries'=>[['rows'=>$processRows?:[['Processes',$na]]]]];
 $networkRows=[];
 $interfaces=json_decode((string)@shell_exec('/usr/sbin/ip -j address show 2>/dev/null'),true);
 foreach(is_array($interfaces)?$interfaces:[] as $interface){$name=$interface['ifname']??'';if($name==='lo')continue;$addresses=[];foreach(($interface['addr_info']??[]) as $address){$ip=$address['local']??'';if(filter_var($ip,FILTER_VALIDATE_IP))$addresses[]=$ip.(($address['scope']??'')==='link'?' (link-local)':'');} $label=$name==='eth0'?'Ethernet (eth0)':($name==='wlan0'?'Wi-Fi (wlan0)':($name==='docker0'?'Container network (docker0)':$name));$networkRows[]=[$label,$addresses?implode(' · ',$addresses):'No IP address'];}
 $agent=substr((string)($viewer['agent']??''),0,512);$browser=$na;
 foreach([['Microsoft Edge','/(?:Edg|EdgA|EdgiOS)\/([0-9.]+)/'],['Opera','/(?:OPR|OPiOS)\/([0-9.]+)/'],['Firefox','/(?:Firefox|FxiOS)\/([0-9.]+)/'],['Google Chrome','/(?:Chrome|CriOS)\/([0-9.]+)/'],['Safari','/Version\/([0-9.]+).*Safari\//']] as $candidate){if(preg_match($candidate[1],$agent,$match)){$browser=$candidate[0].' '.$match[1];break;}}
 $device=$na;$location='Unable to determine';
 if(preg_match('/iPhone|iPad|iPod/i',$agent)){$device=preg_match('/iPad/i',$agent)?'iPad':'iPhone / iOS';$location='External device';}
 elseif(stripos($agent,'Android')!==false){$device='Android';$location='External device';}
 elseif(stripos($agent,'Macintosh')!==false){$device='Mac';$location='External device';}
 elseif(stripos($agent,'Windows')!==false){$device='Windows PC';$location='External device';}
 elseif(stripos($agent,'Linux')!==false){$device=preg_match('/aarch64|armv[0-9]|arm64/i',$agent)?'Linux ARM (may be Raspberry Pi)':'Linux';$location='Cannot confirm from browser identification';}
 $networkRows[]=['This browser',$browser];$networkRows[]=['Browser device',$device];$networkRows[]=['Browser location',$location];
 $peer=filter_var($viewer['peer']??'',FILTER_VALIDATE_IP)?$viewer['peer']:'';
 $reported=filter_var($viewer['reported']??'',FILTER_VALIDATE_IP)?$viewer['reported']:'';
 if($peer)$networkRows[]=['This browser · connection IP',$peer];
 if($reported)$networkRows[]=['This browser · reported client IP',$reported.' (proxy header)'];
 $sections[]=['title'=>'Network IP addresses','note'=>'Connection IP is the address seen by the web server. A proxy may report a separate client IP. Browser and device identification are reported by the current browser.','entries'=>[['rows'=>$networkRows?:[['IP addresses',$na]]]]];
 $users=$db->rawQuery('SELECT Username,Access_Level,MyCall,CurrentIP,SelectedRadio,SDRPort,LastVisit FROM Users WHERE Active=1 ORDER BY uID');if(!is_array($users))throw new Exception('Accounts unavailable');$entries=[];$ports=[8001];$assigned=[];
 foreach($users as $u){$port=(int)$u['SDRPort'];if($port>=8000&&$port<=8099)$ports[]=$port;$assigned[]=(int)$u['SelectedRadio'];$visit=(int)$u['LastVisit'];$ip=trim((string)$u['CurrentIP']);$ipSource='Saved IP';if(!filter_var($ip,FILTER_VALIDATE_IP)){$ip='';if($u['Username']===($viewer['username']??'')){$ip=$reported?:$peer;$ipSource=$reported?'Reported client IP (proxy)':'Current connection IP';}}$local=filter_var($ip,FILTER_VALIDATE_IP)&&!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);$entries[]=['title'=>$u['Username'].($u['MyCall']?' · '.$u['MyCall']:''),'rows'=>[['Access level','Level '.(string)$u['Access_Level']],[$ipSource,$ip?:'Not recorded'],['Connection',$ip?($local?'Local / private address':'Remote address'):$na],['Radio',(string)$u['SelectedRadio']],['Receiver port',(string)$port],['Last visit',$visit?gmdate('Y-m-d H:i:s',$visit).' UTC'.(time()-$visit>86400?' · Older than 24 hours':''):$na]]];}
 $sections[]=['title'=>'Accounts marked active','note'=>'Saved account flags can remain after a browser closes. Last visit is not a live connection heartbeat.','entries'=>$entries?:[['rows'=>[['Accounts','None marked active']]]]];
 $radios=$db->rawQuery('SELECT m.Radio,m.RadioName,m.Model,m.Port,r.MainIn,r.ModeIn,r.PTTIn FROM MySettings m LEFT JOIN RadioInterface r ON m.Radio=r.Radio ORDER BY m.Radio');if(!is_array($radios))throw new Exception('Radios unavailable');$entries=[];
 foreach($radios as $r){if(strtolower((string)$r['Model'])==='dummy'&&!in_array((int)$r['Radio'],$assigned))continue;$freq=is_numeric($r['MainIn'])&&(float)$r['MainIn']>0;$port=(string)$r['Port'];$entries[]=['title'=>'Radio '.$r['Radio'].' · '.$r['RadioName'],'rows'=>[['Model',$r['Model']],['CAT data',$freq?'Available':'Disconnected / no frequency'],['Frequency',$freq?number_format((float)$r['MainIn']/1000000,6).' MHz':'—'],['Mode',$r['ModeIn']?:'—'],['PTT',(string)$r['PTTIn']==='1'?'Keyed':'Released'],['Serial device',$port&&$port!=='None'?$port.' · '.(file_exists($port)?'Detected':'Not detected'):'None']]];}
 $sections[]=['title'=>'Radios','entries'=>$entries?:[['rows'=>[['Radios','None configured']]]]];
 $ports=array_slice(array_unique($ports),0,8);$apis=rigpiStatusApis($ports);$entries=[];foreach($ports as $port){if($master['off']&&($master['services']['sdr_web@'.$port.'.service']['state']??'')==='inactive'){$entries[]=['title'=>'Receiver '.$port,'rows'=>[['Engine','Stopped by All SDRs Off'],['Source','Master Off'],['Device','Closed'],['Frequency','—'],['Sample rate','—'],['TX audio','Stopped'],['RX loopback','Stopped']]];continue;}$s=$apis[$port.'/api/system/status']??null;if(!is_array($s)){$entries[]=['title'=>'Receiver '.$port,'rows'=>[['Engine','Unavailable']]];continue;}$driver=$s['device_driver']??'';$serial=$s['device_serial']??'';$device=$driver?:'None';foreach(($s['available_devices']??[]) as $d){if(($d['driver']??'')===$driver&&($d['serial']??'')===$serial)$device=$d['label']??$device;}
 $remote=!empty($s['owrx_active'])||!empty($s['sdrconn_active'])||!empty($s['use_kiwi']);$tuning=rigpiStatusReceiverTuning($s,$port);$audio=($apis[$port.'/api/audio/state']??null);$loop=($apis[$port.'/api/audio/loopback']??null);$loopname='';foreach(($loop['devices']??[]) as $d){if(($d['index']??null)===($loop['device']??null))$loopname=$d['name']??'';}
 $entries[]=['title'=>'Receiver '.$port,'rows'=>[['Engine','Available'],['Source',$remote?'Remote receiver':($driver==='fake'?'No SDR':$device)],['Device',!empty($s['device_open'])?'Open':($remote?'Remote connection':'Idle')],['Frequency',$tuning['frequency']],['Frequency source',$tuning['basis']],['Spectrum center',$tuning['center']],['Sample rate',isset($s['sample_rate'])?number_format($s['sample_rate']/1000).' kHz':$na],['TX audio',$audio===null?$na:(!empty($audio['running'])?'Running':'Idle')],['RX loopback',$loop===null?$na:(!empty($loop['running'])?'Running'.($loopname?' · '.$loopname:''):'Stopped')]]];}
 $sections[]=['title'=>'Receivers and audio','note'=>'Frequency is this receiver instance’s tuning, not a saved user display setting. Spectrum center is listed separately. A receiver with No SDR or no active connection has no live frequency. Engine state is separate from the browser audio play/pause button.','entries'=>$entries];
 $usb=[];foreach(array_slice(glob('/sys/bus/usb/devices/*/product')?:[],0,24) as $path){$name=rigpiStatusRead($path);if($name&&!preg_match('/hub|host controller/i',$name))$usb[]=['USB',$name];}$alsa=rigpiStatusRead('/proc/asound/cards');$audioRows=[];foreach(explode("
",$alsa??'') as $line){if(preg_match('/^\s*\d+\s+\[([^]]+)\]\s*:\s*(.+)$/',$line,$m))$audioRows[]=['ALSA card',trim($m[1]).' · '.trim($m[2])];}
 $sections[]=['title'=>'Detected devices','entries'=>[['rows'=>array_merge($usb,$audioRows)?:[['Devices',$na]]]]];
 
    // Local process checks only: no pairing, credential validation or notifications.
    $serviceStates=[];
    $serviceOutput=@shell_exec('/usr/bin/timeout 2 /usr/bin/systemctl show nginx.service php8.4-fpm.service mariadb.service elmer-web.service cloudflared.service rigpi-duckdns-update.service rigpi-duckdns-update.timer sdr_web@8001.service sdr_web@8002.service sdr_web@8003.service sdr_web@8004.service sdr_web@8005.service --property=Id --property=ActiveState --property=LoadState 2>/dev/null');
    foreach(preg_split('/\n\s*\n/',trim($serviceOutput??'')) as $block){$values=[];foreach(explode("\n",$block) as $line){$pair=explode('=',$line,2);if(count($pair)===2)$values[$pair[0]]=$pair[1];}if(isset($values['Id']))$serviceStates[$values['Id']]=$values;}
    $serviceCpu=static function($unit)use($serviceCpuA,$serviceCpuB,$cores,$serviceStates){
        $a=$serviceCpuA[$unit]??null;$b=$serviceCpuB[$unit]??null;
        if($a&&$b&&$a['inode']===$b['inode']&&$b['usage']>=$a['usage']&&$b['time']>$a['time']&&$cores>0){$pct=100*($b['usage']-$a['usage'])/(($b['time']-$a['time'])/1000*$cores);return number_format(min(100,max(0,$pct)),1).'%';}
        return in_array($serviceStates[$unit]['ActiveState']??'',['inactive','failed'],true)?'0.0%':'Unavailable';
    };
    $serviceLabel=static function($unit)use($serviceStates,$serviceCpu){$s=$serviceStates[$unit]??[];if(($s['LoadState']??'')==='not-found')return 'Not installed';$v=$s['ActiveState']??'';$label=['active'=>'Running','inactive'=>'Stopped','failed'=>'Failed','activating'=>'Starting','deactivating'=>'Stopping'][$v]??'Unavailable';return $label.(substr($unit,-6)==='.timer'?'':' · CPU '.$serviceCpu($unit));};
    $account=[];
    if(!empty($viewer['username'])){
        $db->where('Username',$viewer['username']);
        $account=$db->getOne('Users',"uID, (LENGTH(TRIM(COALESCE(qrzUser,'')))>0) AS qrz_user, (LENGTH(TRIM(COALESCE(qrzPWD,'')))>0) AS qrz_pass, (LENGTH(TRIM(COALESCE(hamqthUser,'')))>0) AS ham_user, (LENGTH(TRIM(COALESCE(hamqthPWD,'')))>0) AS ham_pass, (LENGTH(TRIM(COALESCE(PushoverToken,'')))>0) AS push_token, (LENGTH(TRIM(COALESCE(PushoverUser,'')))>0) AS push_user, PushoverNotify")?:[];
    }
    $db->where('uID',1);$pushAdmin=$db->getOne('Users',"(LENGTH(TRIM(COALESCE(PushoverToken,'')))>0) AS configured")?:[];
    $configured=static function($user,$pass){return $user&&$pass?'Configured · not tested':($user||$pass?'Incomplete credentials':'Not configured');};
    $pushToken=(int)($account['uID']??0)===1?!empty($account['push_token']):!empty($pushAdmin['configured']);
    $pushMode=(string)($account['PushoverNotify']??'none');
    $pushMode=in_array($pushMode,['none','all','available','unavailable','on','off','1','0'],true)?$pushMode:'Configured';
    $sections[]=['title'=>'Connections and services','note'=>'Account settings apply to '.($viewer['username']??'the current user').'. Configured means credentials are saved; remote access is not tested. Local service state does not confirm the remote service is reachable.','entries'=>[
        ['title'=>'Elmer','rows'=>[['Local service',$serviceLabel('elmer-web.service')],['Account connection','Not checked · see Elmer settings']]],
        ['title'=>'QRZ','rows'=>[['Credentials',$configured(!empty($account['qrz_user']),!empty($account['qrz_pass']))]]],
        ['title'=>'Cloudflare','rows'=>[['Tunnel service',$serviceLabel('cloudflared.service')]]],
        ['title'=>'DuckDNS','rows'=>[['IP update timer',$serviceLabel('rigpi-duckdns-update.timer')],['IP updater',$serviceLabel('rigpi-duckdns-update.service')],['DNS and HTTPS','Not tested']]],
        ['title'=>'HamQTH','rows'=>[['Credentials',$configured(!empty($account['ham_user']),!empty($account['ham_pass']))]]],
        ['title'=>'Pushover','rows'=>[['Credentials',$configured($pushToken,!empty($account['push_user']))],['Notifications',$pushMode==='none'?'Disabled':$pushMode]]]
    ]];


    $hardware=$db->rawQuery('SELECT m.Radio,m.RadioName,m.Keyer,m.KeyerPort,m.Rotor,m.RotorManufacturer,m.RotorModel,m.RotorPort,r.MainIn,r.RotorAzIn,r.RotorElIn,k.WKSpeed FROM MySettings m LEFT JOIN RadioInterface r ON r.Radio=m.Radio LEFT JOIN Keyer k ON k.Radio=m.Radio ORDER BY m.Radio');
    $keyers=[];$rotors=[];
    $deviceStatus=static function($port){$port=trim((string)$port);if($port===''||strcasecmp($port,'none')===0)return 'Not configured';if(strpos($port,'/dev/')===0)return $port.' · '.(file_exists($port)?'Detected':'Not detected');return $port.' · connection not checked';};
    foreach($hardware?:[] as $h){
        $id=(int)$h['Radio'];$title='Radio '.$id.' · '.$h['RadioName'];
        $method=trim((string)$h['Keyer']);$hasKeyer=$method!==''&&strcasecmp($method,'none')!==0;
        if($hasKeyer||in_array($id,$assigned,true)){
            $rows=[['Keyer',$hasKeyer?$method:'None configured']];
            if($hasKeyer){
                if(stripos($method,'CAT')!==false){$rows[]=['Keying connection','Not tested'];}
                else{$rows[]=['Port',$deviceStatus($h['KeyerPort'])];}
                if(is_numeric($h['WKSpeed'])&&(float)$h['WKSpeed']>0)$rows[]=['Saved speed',(string)$h['WKSpeed'].' WPM'];
            }
            $keyers[]=['title'=>$title,'rows'=>$rows];
        }
        $model=trim((string)$h['RotorModel']);
        if($model!==''&&!in_array(strtolower($model),['none','dummy'],true)){
            $rows=[['Model',trim($h['RotorManufacturer'].' '.$model)],['Port',$deviceStatus($h['RotorPort'])]];
            foreach(['RotorAzIn'=>'Last reported azimuth','RotorElIn'=>'Last reported elevation'] as $field=>$label){$value=$h[$field];$valid=is_numeric($value)&&(float)$value>=0&&(float)$value<=($field==='RotorAzIn'?360:180);$rows[]=[$label,$valid?number_format((float)$value,1).'°':'Unavailable'];}
            $rotors[]=['title'=>$title,'rows'=>$rows];
        }
    }
    $sections[]=['title'=>'Keyers','note'=>'Configured keying method and device availability. Keying is not tested; saved speed is a setting.','entries'=>$keyers?:[['rows'=>[['Keyers','None configured']]]]];
    $dummyRotors=count(array_filter($hardware?:[],static function($h){return strtolower(trim((string)$h['RotorModel']))==='dummy';}));
    $sections[]=['title'=>'Rotors','note'=>'Configured models and ports. Saved positions can be stale; device detection does not confirm a working rotor connection.','entries'=>array_merge($dummyRotors?[['rows'=>[['Dummy rotors','Hamlib Dummy · '.$dummyRotors.' slots (simulated)']]]]:[],$rotors)?:[['rows'=>[['Rotors','None configured']]]]];


    $sdrAccounts=$db->rawQuery('SELECT Username,Access_Level,Active,SDRPort,SDRPosition,SDRPage FROM Users ORDER BY uID');
    $sdrEntries=[];
    foreach($sdrAccounts?:[] as $u){
        $position=strtolower(trim((string)$u['SDRPosition']));$page=strtolower(trim((string)$u['SDRPage']));
        $positionLabel=['none'=>'None','top'=>'Top','bottom'=>'Bottom'][$position]??'Unknown';
        $pageLabel=['none'=>'None','tuner'=>'Tuner','keyer'=>'Keyer','both'=>'Tuner and Keyer'][$page]??'Unknown';
        $hidden=$position==='none'||$page==='none';
        $sdrEntries[]=['title'=>$u['Username'],'rows'=>[['Access level','Level '.(string)$u['Access_Level']],['Account flag',(string)$u['Active']==='1'?'Marked active':'Inactive'],['Receiver port',(string)$u['SDRPort']],['SDR position',$positionLabel],['SDR pages',$pageLabel],['Display setting',$hidden?'Hidden':'Enabled on selected pages']]];
    }
    $sections[]=['title'=>'SDR by account','note'=>'Saved display settings for every account. None hides the embedded SDR. Display settings are separate from receiver engine state and do not show which page a browser currently has open.','entries'=>$sdrEntries?:[['rows'=>[['Accounts','None']]]]];

$sdrServiceRows=[];foreach(range(8001,8005) as $servicePort){$sdrServiceRows[]=['SDR service '.$servicePort,$serviceLabel('sdr_web@'.$servicePort.'.service')];}
    $sections[]=['title'=>'Services','note'=>'CPU is percent of total Pi capacity, sampled over about half a second and including service workers. Service state is separate from receiver hardware and browser audio. A running service can have No SDR selected.','entries'=>[['rows'=>array_merge([['Web server','Responding · CPU '.$serviceCpu('nginx.service')],['PHP',$serviceLabel('php8.4-fpm.service')],['Database','Responding · CPU '.$serviceCpu('mariadb.service')]],$sdrServiceRows,[['Status refresh','10 seconds'],['Report time',$time]])]]];
 
    require_once __DIR__.'/SystemStatusHealth.php';
    $healthFanRpm=null;$healthFanDrive=null;
    foreach(glob('/sys/class/hwmon/hwmon*')?:[] as $sensor){if(rigpiStatusRead($sensor.'/name')!=='pwmfan')continue;$v=rigpiStatusRead($sensor.'/fan1_input');$d=rigpiStatusRead($sensor.'/pwm1');$healthFanRpm=is_numeric($v)?(float)$v:null;$healthFanDrive=is_numeric($d)?(float)$d:null;break;}
    $healthServices=[];$activeSdrPorts=array_map(static function($u){return (int)$u['SDRPort'];},$users);
    foreach(['nginx.service'=>'Web server','php8.4-fpm.service'=>'PHP','mariadb.service'=>'Database','cloudflared.service'=>'Tunnel service','elmer-web.service'=>'Local service','rigpi-duckdns-update.service'=>'IP updater'] as $unit=>$label){$healthServices[]=['unit'=>$unit,'label'=>['cloudflared.service'=>'Cloudflare','elmer-web.service'=>'Elmer','rigpi-duckdns-update.service'=>'DuckDNS updater'][$unit]??$label,'state'=>$serviceStates[$unit]['ActiveState']??'unavailable','expected'=>in_array($unit,['nginx.service','php8.4-fpm.service','mariadb.service'],true),'section'=>in_array($unit,['nginx.service','php8.4-fpm.service','mariadb.service'],true)?'Services':'Connections and services','row'=>$label];}
    foreach(range(8001,8005) as $p){$unit='sdr_web@'.$p.'.service';$healthServices[]=['unit'=>$unit,'label'=>'SDR service '.$p,'state'=>$serviceStates[$unit]['ActiveState']??'unavailable','expected'=>!$master['off']&&in_array($p,$activeSdrPorts,true),'section'=>'Services','row'=>'SDR service '.$p];}
    $health=rigpiAssessHealth(['temperature'=>is_numeric($temp)?(float)$temp/1000:null,'fanRpm'=>$healthFanRpm,'fanDrive'=>$healthFanDrive,'powerFlags'=>$powerFresh?$power['flags']:null,'pi5'=>strpos(rigpiStatusRead('/proc/device-tree/model')??'','Raspberry Pi 5')!==false,'cpu'=>$delta>0?(float)$usage:null,'memory'=>isset($mt[1],$ma[1])&&(float)$mt[1]>0?100*(1-(float)$ma[1]/(float)$mt[1]):null,'disk'=>$total>0&&$free!==false?100*(1-$free/$total):null,'loadPerCore'=>$cores>0&&isset($load[0])?$load[0]/$cores:null,'services'=>$healthServices]);
$health=rigpiSdrMasterHealth($health,$master,$serviceStates);
return ['time'=>$time,'sections'=>$sections,'health'=>$health,'sdr_master'=>$master];
}
}
