<?php
// Bounded confirmed-character journal, independent of the browser's Sent CW buffer.
class RigPiCWEcho {
 public static function state($radio,$append='') {
  $p='/tmp/rigpi-cw-echo-'.(int)$radio.'.json';
  $f=@fopen($p,'c+'); if(!$f)return [0,''];
  flock($f,LOCK_EX); $s=json_decode(stream_get_contents($f),true);
  if(!is_array($s))$s=[0,''];
  if($append!==''){$s=[(int)$s[0]+strlen($append),substr($s[1].$append,-4096)];rewind($f);ftruncate($f,0);fwrite($f,json_encode($s));}
  flock($f,LOCK_UN);fclose($f);return $s;
 }
 public static function delta($s,&$cursor){$n=max(0,(int)$s[0]-$cursor);$cursor=(int)$s[0];return $n?substr($s[1],-min($n,strlen($s[1]))):'';}
 public static function decode(&$buffer,$input){
  $buffer.=$input;$out='';
  while(($i=strpos($buffer,';'))!==false){$f=substr($buffer,0,$i);$buffer=substr($buffer,$i+1);if(preg_match('/^CE([0-9a-f]+)$/i',$f)&&strlen(substr($f,2))%2===0)$out.=hex2bin(substr($f,2));}
  if(strlen($buffer)>16384)$buffer='';return $out;
 }
}
