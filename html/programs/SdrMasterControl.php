<?php
/** Persistent master control; this file alone performs no action. */
const RIGPI_SDR_MASTER_DIR='/var/lib/rigpi/sdr-master';
function rigpiSdrMasterUnits(){return array_map(static function($p){return 'sdr_web@'.$p.'.service';},range(8001,8005));}
function rigpiSdrMasterRun($command){
 $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))return ['code'=>1,'out'=>''];
 $out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 return ['code'=>proc_close($process),'out'=>$out];
}
function rigpiSdrMasterServices($runner=null){
 $run=$runner??'rigpiSdrMasterRun';$units=rigpiSdrMasterUnits();
 $r=$run(array_merge(['/bin/systemctl','show','--no-pager','--property=Id,ActiveState,SubState'],$units));$states=[];
 foreach(preg_split('/\n\s*\n/',trim($r['out']??'')) as $block){$values=[];foreach(explode("\n",$block) as $line){$v=explode('=',$line,2);if(count($v)===2)$values[$v[0]]=$v[1];}if(in_array($values['Id']??'', $units,true))$states[$values['Id']]=['state'=>$values['ActiveState']??'unknown','detail'=>$values['SubState']??'unknown'];}
 foreach($units as $unit)if(!isset($states[$unit]))$states[$unit]=['state'=>'unknown','detail'=>'unknown'];
 return $states;
}
function rigpiSdrMasterStatus($dir=RIGPI_SDR_MASTER_DIR,$runner=null){
 $off=file_exists($dir.'/all-off');$change=json_decode((string)@file_get_contents($dir.'/last-change.json'),true);if(!is_array($change))$change=[];
 $services=rigpiSdrMasterServices($runner);$pending=false;$running=0;$stopped=0;
 foreach($services as $service){$state=$service['state'];if($state==='active')$running++;if(in_array($state,['inactive','failed'],true))$stopped++;if(in_array($state,['activating','deactivating','reloading'],true))$pending=true;}
 $fresh=isset($change['changed_at'])&&time()-(strtotime($change['changed_at'])?:0)<180;
 if($fresh&&($change['state']??'')===($off?'off':'on')&&($off?$stopped<5:$running<5&&!in_array('failed',array_column($services,'state'),true)))$pending=true;
 return ['off'=>$off,'state'=>$off?'off':'on','pending'=>$pending,'running'=>$running,'stopped'=>$stopped,'services'=>$services,'changed_at'=>(string)($change['changed_at']??''),'changed_by'=>(string)($change['changed_by']??'')];
}
function rigpiSdrMasterWrite($path,$value){
 $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
 if(file_put_contents($temp,json_encode($value,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Unable to save the SDR master setting.');
 chmod($temp,0644);if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Unable to save the SDR master setting.');}
}
function rigpiSdrMasterProbe($port){
 $curl=curl_init('http://127.0.0.1:'.$port.'/api/system/status');
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>300,CURLOPT_TIMEOUT_MS=>1500,CURLOPT_PROXY=>'']);
 $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 return $code===200?json_decode($body,true):null;
}
function rigpiSdrMasterChange($mode,$actor,$dir=RIGPI_SDR_MASTER_DIR,$runner=null,$probe=null){
 if(!in_array($mode,['off','on'],true))throw new InvalidArgumentException('Invalid SDR master setting.');
 if(!is_dir($dir))throw new RuntimeException('SDR master control has not been installed.');
 $lock=fopen($dir.'/control.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another SDR master change is in progress.');
 try{
  $before=rigpiSdrMasterStatus($dir,$runner);
  if($before['state']===$mode)return $before;
  if($before['pending'])throw new RuntimeException('The receiver services are still changing state. Please wait.');
  if($mode==='off'){
   $check=$probe??'rigpiSdrMasterProbe';
   foreach($before['services'] as $unit=>$service){
    if(in_array($service['state'],['inactive','failed'],true))continue;
    if($service['state']!=='active')throw new RuntimeException('A receiver state is unknown. Verify the receiver services before turning all SDRs off.');
    preg_match('/@(\d+)\./',$unit,$match);$port=(int)$match[1];$data=$check($port);
    if(!is_array($data)||($data['schema_version']??null)!==1||($data['instance_port']??null)!==$port||!array_key_exists('transmit_active',$data)||!is_bool($data['transmit_active']))throw new RuntimeException('Unable to confirm transmit status for receiver '.$port.'. No master setting was changed.');
    if($data['transmit_active'])throw new RuntimeException('Receiver '.$port.' is transmitting or starting transmission. Release PTT and stop transmission before turning all SDRs off.');
   }
  }
  $oldFlag=$before['off']?@file_get_contents($dir.'/all-off'):null;$oldChange=@file_get_contents($dir.'/last-change.json');
  $change=['state'=>$mode,'changed_at'=>gmdate('c'),'changed_by'=>substr((string)$actor,0,120)];
  rigpiSdrMasterWrite($dir.'/last-change.json',$change);
  if($mode==='off')rigpiSdrMasterWrite($dir.'/all-off',$change);elseif(!unlink($dir.'/all-off'))throw new RuntimeException('Unable to enable SDR receivers.');
  $run=$runner??'rigpiSdrMasterRun';$command=array_merge(['/usr/bin/sudo','-n','/bin/systemctl','restart'],rigpiSdrMasterUnits(),['--no-block']);
  try{$r=$run($command);}catch(Throwable $error){$r=['code'=>1];}
  if(($r['code']??1)!==0){
   if($before['off'])rigpiSdrMasterWrite($dir.'/all-off',json_decode($oldFlag,true)?:['state'=>'off']);else @unlink($dir.'/all-off');
   if($oldChange!==false)rigpiSdrMasterWrite($dir.'/last-change.json',json_decode($oldChange,true)?:[]);else @unlink($dir.'/last-change.json');
   if($before['off']){try{$run($command);}catch(Throwable $ignored){}}
   throw new RuntimeException('The SDR service request failed. The previous master setting was restored; check the receiver service states.');
  }
  $result=rigpiSdrMasterStatus($dir,$runner);$result['pending']=true;return $result;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function rigpiSdrMasterHealth($checks,$master,$serviceStates){
 if(empty($master['off']))return $checks;
 foreach($checks as &$check){if(strpos($check['key'],'service-sdr_web@')===0){$unit=substr($check['key'],8);if(($serviceStates[$unit]['ActiveState']??'')==='inactive'){$check['level']='green';$check['message']='Stopped by persistent All SDRs Off. Enable SDRs in System Status when receivers are needed.';$check['hold']=0;}}}unset($check);
 return $checks;
}
