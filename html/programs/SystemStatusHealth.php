<?php
// Pure health rules. No commands, network access or corrective actions.
function rigpiAssessHealth(array $m): array {
    $checks=[];
    $add=static function($key,$level,$label,$message,$section,$row,$hold=0)use(&$checks){$checks[]=compact('key','level','label','message','section','row','hold');};
    $t=$m['temperature']??null;
    if(!is_numeric($t))$add('temperature','unknown','Temperature','Temperature reading unavailable. Check the sensor reading before judging cooling.','System health','CPU temperature');
    else{$level=$t>=85?'red':($t>=75?'yellow':'green');$add('temperature',$level,'Temperature',number_format($t,1).'°C. '.($t>=80?'The Pi is very hot. Check cooling, airflow and workload.':($t>=75?'Check airflow and fan operation if this stays high.':'Temperature is below the attention threshold.')),'System health','CPU temperature',$t>=75&&$t<80?30:0);}
    $rpm=$m['fanRpm']??null;$drive=$m['fanDrive']??null;
    if(!is_numeric($rpm))$add('fan','unknown','Cooling fan','Fan speed is unavailable; this does not establish a fan failure.','System health','Cooling fan');
    elseif($rpm==0&&((is_numeric($t)&&$t>=75)||(is_numeric($drive)&&$drive>0)))$add('fan','yellow','Cooling fan','Fan reports 0 RPM while cooling is requested or the Pi is hot. Check the fan cable and airflow.','System health','Cooling fan',30);
    else $add('fan','green','Cooling fan',$rpm>0?'Fan reports '.number_format($rpm).' RPM.':'Fan is stopped with no high-temperature or drive indication; temperature-controlled fans may stop normally.','System health','Cooling fan');
    $flags=$m['powerFlags']??null;
    if(!is_int($flags))$add('power','unknown','Power and throttling','Fresh firmware power readings are unavailable.','Power and throttling','Firmware reading');
    else{
        $supply=($m['pi5']??false)?'For Pi 5, use the official 27 W USB-C supply or a compatible supply providing 5 V at 5 A.':'Use a power supply rated for this Raspberry Pi model.';
        if($flags&1)$add('power','red','Supply voltage','Low voltage is detected now. Check the supply, cable and USB device load. '.$supply,'Power and throttling','Undervoltage');
        elseif($flags&(1<<16))$add('power','yellow','Supply voltage','Low voltage occurred earlier since boot; it is not active now. Check for recurrence, then check the supply and cable. '.$supply,'Power and throttling','Undervoltage');
        else $add('power','green','Supply voltage','No undervoltage is reported now or since boot. This does not identify the supply rating.','Power and throttling','Undervoltage');
        if($flags&14)$add('throttling','yellow','CPU limits','Firmware reports an active CPU frequency or temperature limit. Check cooling, power and workload.','Power and throttling',($flags&4)?'CPU throttling':(($flags&8)?'Soft temperature limit':'CPU frequency capped'));
        elseif($flags&(14<<16))$add('throttling','yellow','CPU limits','A CPU limit occurred earlier since boot; it is not active now. Review temperature, power and workload if it recurs.','Power and throttling','CPU throttling');
        else $add('throttling','green','CPU limits','No CPU limiting is reported now or since boot.','Power and throttling','CPU throttling');
    }
    foreach(['cpu'=>['CPU use','System health','CPU use',85,97,30],'memory'=>['Memory use','System health','Memory',90,97,30],'disk'=>['Root disk','System health','Root disk',90,97,0]] as $key=>$r){
        $v=$m[$key]??null;if(!is_numeric($v)){$add($key,'unknown',$r[0],'Reading unavailable.',$r[1],$r[2]);continue;}
        $level=$v>=$r[4]?'red':($v>=$r[3]?'yellow':'green');
        $advice=$key==='cpu'?'Review Highest CPU consumers; reduce unnecessary processing if overload persists.':($key==='memory'?'Review running programs; close unused applications and check for increasing memory use.':'Review recordings and other large files; back up needed files before freeing space.');
        $add($key,$level,$r[0],number_format($v,1).'% used. '.($level==='green'?'Below the attention threshold.':$advice),$r[1],$r[2],$level==='green'?0:$r[5]);
    }
    $load=$m['loadPerCore']??null;
    if(is_numeric($load))$add('load',$load>=1?'yellow':'green','System load',number_format($load,2).' per core over the last minute. '.($load>=1?'The Pi may have CPU or I/O contention. Review the busiest processes and storage activity.':'Below one runnable or waiting task per core.'),'System health','Load (1 / 5 / 15 min)');
    foreach($m['services']??[] as $service){
        $state=$service['state'];$label=$service['label'];$expected=$service['expected'];$section=$service['section'];$row=$service['row'];$hold=0;
        if($state==='active'){$level='green';$message='Service is running. This does not verify attached hardware or external connectivity.';}
        elseif($state==='failed'){$level='red';$message='Service has failed. Review its service log and configuration, then restart the affected service. Reboot only if needed.';}
        elseif($state==='inactive'&&$expected){$level='yellow';$hold=20;$message=(strpos($service['unit'],'sdr_web@')===0?'Service is stopped and is assigned to an account marked active. If this is intentional, no action is needed. Otherwise check its settings and log, then start the service.':'Required service is stopped. Check its settings and service log, then start the affected service.');}
        else{$level='unknown';$message=$state==='inactive'?'Service is stopped and may be intentionally unused. No fault is inferred.':'Service state is '.$state.'; verify its settings before taking action.';}
        $add('service-'.$service['unit'],$level,$label,$message,$section,$row,$hold);
    }
    return $checks;
}
