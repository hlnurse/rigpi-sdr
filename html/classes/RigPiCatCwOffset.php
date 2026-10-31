<?php
/** Temporary, verified CAT CW TX offset; RX frequency is never changed. */
final class RigPiCatCwOffset {
    private $command, $startWait, $pollWait, $closeWait, $clock;
    private float $minimumUntil=0.0, $idleSince=0.0;
    private bool $completion=false;
    private ?array $saved = null;
    private $waiter = null;
    public function __construct(callable $command, callable $startWait, callable $pollWait, callable $closeWait, ?callable $clock=null) {
        $this->command=$command; $this->startWait=$startWait;
        $this->pollWait=$pollWait; $this->closeWait=$closeWait;
        $this->clock=$clock??static fn()=>hrtime(true)/1e9;
    }
    private function query(string $command): string {
        return trim((string)($this->command)($command."\n"));
    }
    private function number(string $command): int {
        $reply=$this->query($command);
        if(!preg_match('/^[+-]?\d+$/D',$reply))throw new RuntimeException('CW offset readback failed: '.$command);
        return (int)$reply;
    }
    private function set(string $command): void {
        if($this->query($command)!=='RPRT 0')throw new RuntimeException('CW offset command failed: '.$command);
    }
    public function isActive(): bool { return $this->saved!==null; }
    public function send(string $text,int $offset): void {
        if(!in_array($offset,[-700,700],true) || $text==='' || strlen($text)>256 || preg_match('/[^\x20-\x7E]/',$text)) {
            throw new InvalidArgumentException('Invalid offset CW text.');
        }
        $mode=preg_split('/\s+/',$this->query('m'))[0]??'';
        if(($mode==='CW'?700:($mode==='CWR'?-700:0))!==$offset) {
            throw new RuntimeException('Select matching CW or CWR mode before sending offset CW.');
        }
        if($this->saved!==null && $this->saved['temporary']!==$offset) {
            throw new RuntimeException('Finish or cancel the current CW before reversing its offset.');
        }
        if($this->saved!==null && ($this->number('z')!==$offset || $this->number('u XIT')!==1)) {
            throw new RuntimeException('XIT changed during CW; finish or cancel before sending more.');
        }
        if($this->saved===null) {
            $split=preg_split('/\s+/',$this->query('s'))[0]??'';
            if($split!=='0')throw new RuntimeException('Turn Split off before using CW passband offset.');
            $oldOffset=$this->number('z'); $oldEnabled=$this->number('u XIT');
            if(!in_array($oldEnabled,[0,1],true))throw new RuntimeException('XIT state is unavailable.');
            $this->saved=['offset'=>$oldOffset,'enabled'=>$oldEnabled,'temporary'=>$offset];
            try {
                $this->set('Z '.$offset); $this->set('U XIT 1');
                if($this->number('z')!==$offset || $this->number('u XIT')!==1) {
                    throw new RuntimeException('Radio did not confirm the CW transmit offset.');
                }
            } catch(Throwable $error) {
                $this->restore(false);
                throw $error;
            }
        }
        if($this->waiter!==null){($this->closeWait)($this->waiter);$this->waiter=null;}
        try {
            $wpm=$this->number('l KEYSPD');
            if($wpm<5 || $wpm>80)throw new RuntimeException('CW speed readback is unavailable.');
            $duration=self::duration($text,$wpm);
            $this->completion=false; $this->idleSince=0.0;
            $this->minimumUntil=max(($this->clock)(),$this->minimumUntil)+$duration+0.5;
            $wireText=strlen($text)===1?$text.' ':$text;
            $this->set('\\send_morse '.$wireText);
            // Only the completion waiter uses a second connection. CW itself
            // stays on the radio worker's existing rigctld session.
            $this->waiter=($this->startWait)();
            if($this->waiter===null || $this->waiter===false)throw new RuntimeException('CW completion monitor could not start.');
        } catch(Throwable $error) {
            $this->query('\\stop_morse'); $this->restore(true); throw $error;
        }
    }
    public static function duration(string $text,int $wpm): float {
        $morse=['A'=>'.-','B'=>'-...','C'=>'-.-.','D'=>'-..','E'=>'.','F'=>'..-.','G'=>'--.','H'=>'....','I'=>'..','J'=>'.---','K'=>'-.-','L'=>'.-..','M'=>'--','N'=>'-.','O'=>'---','P'=>'.--.','Q'=>'--.-','R'=>'.-.','S'=>'...','T'=>'-','U'=>'..-','V'=>'...-','W'=>'.--','X'=>'-..-','Y'=>'-.--','Z'=>'--..','0'=>'-----','1'=>'.----','2'=>'..---','3'=>'...--','4'=>'....-','5'=>'.....','6'=>'-....','7'=>'--...','8'=>'---..','9'=>'----.','.'=>'.-.-.-',','=>'--..--','?'=>'..--..','/'=>'-..-.','='=>'-...-','+'=>'.-.-.','-'=>'-....-','@'=>'.--.-.'];
        $units=0;
        foreach(str_split(strtoupper($text)) as $char){
            if(ctype_space($char)){$units+=7;continue;}
            $pattern=$morse[$char]??'------';
            $units+=substr_count($pattern,'-')*3+substr_count($pattern,'.')+strlen($pattern)-1+3;
        }
        return $units*1.2/(max(5,min(80,$wpm))*0.985);
    }
    public function tick(): void {
        if($this->saved===null)return;
        if(!$this->completion){
            if($this->waiter===null)return;
            $result=($this->pollWait)($this->waiter);
            if($result===null)return;
            if($result===false){
                $this->query('\\stop_morse'); $this->restore(true);
                throw new RuntimeException('CW completion monitor failed; CW was stopped.');
            }
            ($this->closeWait)($this->waiter); $this->waiter=null;
            $this->completion=true;
        }
        // Some IC-7300/Hamlib versions finish wait_morse while the radio's
        // text buffer is still keying. Never restore XIT on that signal alone.
        $now=($this->clock)();
        if($now<$this->minimumUntil)return;
        if($this->number('t')!==0){$this->idleSince=0.0;return;}
        if($this->idleSince===0.0){$this->idleSince=$now;return;}
        if($now-$this->idleSince<0.6)return;
        $this->restore(true);
    }
    public function cancel(): void { $this->restore(true); }
    private function restore(bool $respectOperator): void {
        if($this->waiter!==null){($this->closeWait)($this->waiter);$this->waiter=null;}
        if($this->saved===null)return;
        $saved=$this->saved;
        try {
            if($respectOperator && ($this->number('z')!==$saved['temporary'] || $this->number('u XIT')!==1))return;
            $this->set('Z '.$saved['offset']);
            $this->set('U XIT '.$saved['enabled']);
            if($this->number('z')!==$saved['offset'] || $this->number('u XIT')!==$saved['enabled']) {
                throw new RuntimeException('Check the radio XIT setting: restore readback failed.');
            }
        } finally { $this->saved=null; $this->minimumUntil=0.0; $this->idleSince=0.0; $this->completion=false; }
    }
}
