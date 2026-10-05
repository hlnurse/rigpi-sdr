<?php
require_once __DIR__."/RigPiCWEcho.php";
require_once "/var/www/html/programs/GetSettingsFunc.php";
require_once "/var/www/html/programs/GetInterfaceFunc.php";
require_once "/var/www/html/programs/SetSettingsFunc.php";
require_once "/var/www/html/programs/GetInterfaceOut.php";
require_once "/var/www/html/classes/PhpSerialClass.php";
require_once "/var/www/html/classes/TCPServer.class.php";
require_once "/var/www/html/classes/MysqliDb.php";
require_once "/var/www/html/programs/sqldata.php";

class CWPortServer
{
    private $cDB;
    private static $user;
    private static $winkey;
    private static $winkeyOK = true;
    public $cwSpeed = 0x20 . ";";
    private static $winkey_WPM_Speed;
    private static $cwBuffer;
    protected $config;
    public $cw_socket;
    public $cw_TCP_Client;
    public $cw_TCP_Socket;
    public $keyerPort;
    public $busy = 0;

    public $max_read = 1024;

    public function doLog($test, $what)
    {
        if ($test == 1) {
            error_log(
                date("Y-m-d H:i:s", time()) . " " . $what . PHP_EOL,
                3,
                "test.log"
            );
        }
    }

    public function __construct(
        $bind_ip,
        $port,
        $kport,
        $ktype,
        $radio,
        $user,
        $trans,
        $utest
    ) {
        //    $radio=2;
        $this->doLog(
            $utest,
            " line: " .
                $port .
                " " .
                $kport .
                " " .
                $radio .
                " " .
                $user .
                PHP_EOL
        );
        ($this->cDB = new MysqliDB(DB_SERVER, DB_USER, DB_PWD, DB_NAME)) or
            die("There was a problem connecting to the database.");
        if ($ktype == "rpk1" || $ktype == "rpk2") {
            $ktype = "rpk";
        }
        $this->config["keyerType"] = $ktype;
        $keyerPort = GetField($radio, "KeyerPort", "MySettings");
        echo "Keyer port: $keyerPort \n";
        $kptt = GetField($radio, "WKPTT", "Keyer");
        $func = GetField($radio, "WKFunction", "Keyer");
        $wkRemoteIP = GetField($radio, "WKRemoteIP", "Keyer");
        $wkRemotePort = GetField($radio, "WKRemotePort", "Keyer");
        // An explicit network keyer port overrides the launcher's serial default.
        $savedKeyerPort = trim((string) GetField($radio, "KeyerPort", "MySettings"));
        if ($ktype !== "cat" && preg_match('/^([^:]+):([0-9]+)$/', $savedKeyerPort, $target)) {
            $kport = $target[1];
            $wkRemoteIP = $target[1];
            $wkRemotePort = (int) $target[2];
            $port = $wkRemotePort;
        }

        if ($wkRemoteIP == "") {
            if (strstr((string) ($kport ?? ""), ".")) {
                $wkRemoteIP = $kport;
                $wkRemotePort = $port;
            }
        }
        // "Normal" CW via CAT always targets the selected radio's local
        // rigctld instance. Values retained from an earlier Remote Keyer
        // configuration must not redirect locally prepared CW off-station.
        if ($ktype == "cat") {
            $wkRemoteIP = "127.0.0.1";
            $wkRemotePort = 4530 + 2 * (int) $radio;
            $port = $wkRemotePort;
        }
        echo "remote: $wkRemoteIP $wkRemotePort \n";
        // Preserve the operator's Hold state across worker restarts. Clearing
        // Hold here could transmit text that Elmer deliberately staged safely.
        SetInterface($radio, "CWInWKCk", 0);
        $this->config["pttOn"] = $kptt;
        $this->config["keyerfunc"] = $func;
        set_time_limit(0);
        $this->config["wkRemote"] = $wkRemoteIP;
        $this->config["full"] = "0";
        $this->config["ip"] = $bind_ip;
        $this->config["radio"] = $radio;
        $this->config["port"] = $port;
        $this->config["tcpport"] = 30000 + $radio;
        if ($ktype == "rpk1" || $ktype == "rpk2") {
            $ktype = "rpk";
        }
        $this->config["keyerType"] = $ktype;
        $this->config["user"] = $user;
        $this->config["keyerPort"] = $kport;
        $this->config["portTrans"] = $trans;
        $this->config["test"] = $utest;
        $this->config["CWSockOK"] = 1; //////
        if ($this->config["portTrans"] == 0) {
            if ($ktype == "rpk" && strstr((string) ($kport ?? ""), "/dev/")) {
                $ktype = "rpk";
                //        $kport1 = "ttyS0";
                system("stty -F " . $keyerPort . " -echo");
                system("stty -F " . $keyerPort . " raw");
                self::$winkey = new PhpSerial($radio);
                $this->setWinkeyDevice($kport);
                $this->setWinkeyBaud(1200);
                $this->setWinkeyParity("none");
                $this->setWinkeyBits(8);
                $this->setWinkeyStop(1);
                $this->setWinkeyFlow("none");
                $this->setWinkeyOpen();
                usleep(100000);
                $this->InitWinKeyer(7);
            } elseif ($ktype == "cat") {
                //				$this->debug('CAT used');
            } elseif ($ktype == "wkr" && str_starts_with((string)$kport, "/dev/")) {
                system("stty -F " . $keyerPort . " -echo");
                system("stty -F " . $keyerPort . " raw");
                self::$winkey = new PhpSerial($radio);
                echo "new wkr phpserial created for radio $radio on port $keyerPort \n";
                usleep(1000000);
                $this->setWinkeyDevice($keyerPort);
                echo "phps set winkeyer to: " . $keyerPort . "\n";
                $this->setWinkeyBaud(1200);
                $this->setWinkeyParity("none");
                $this->setWinkeyBits(8);
                $this->setWinkeyStop(1);
                $this->setWinkeyFlow("none");
                $this->setWinkeyOpen();
                $this->InitWinKeyer(7);
                //        }
            }
        }
        if ($wkRemoteIP == "" || $wkRemotePort == "") {
            $this->config["CWSockOK"] = 0;
        } else {
            $this->config["CWSockOK"] = 1;
        }
        $this->config["CWSockOK"] = isset($target[2]) ? 1 : 0;

        echo "cw socket ok: " . $this->config["CWSockOK"] . " \n";
        if ($this->config["CWSockOK"] == 1) {
            $this->cw_TCP_Client = socket_create(AF_INET, SOCK_STREAM, 0);
            echo "CW socket created";
            if (!$this->cw_TCP_Client) {
                $this->config["CWSockOK"] = 0;
                echo "TCP Create ERROR: " . socket_last_error();
            } else {
                $this->config["CWSockOK"] = 1;
                echo "CW Create TCP SOCKET is OK!\n";
            }
            echo "kport: " . $kport . " tcpport: " . $this->config["tcpport"],
                "\n";
            if ($kport !== "None") {
                if ($this->config["CWSockOK"] == 1) {
                    //use connect and not bind or there will be a conflict with TCPDo.
                    //            $wkRemoteIP='172.16.0.47';
                    //            $wkRemotePort=30001;
                    $this->config["CWSockOK"] = (int) socket_connect(
                        $this->cw_TCP_Client,
                        $wkRemoteIP, // $kport,
                        $wkRemotePort
                    ); //        $this->config["tcpport"]
                    if (
                        $this->cw_TCP_Client === false ||
                        $this->config["CWSockOK"] == 0
                    ) {
                        $this->config["CWSockOK"] = 0;
                        echo "TCP CW Client Connect ERROR: " .
                            socket_last_error();
                    } else {
                        $this->config["CWSockOK"] = 1;
                        socket_write($this->cw_TCP_Client,"CE1;");
echo "TCP CW Client SOCKET is connected.!\n";
                    }
                }
            }
        }
    }

    public function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return (float) $usec + (float) $sec;
    }

    public function loop_once()
    {
        $tRadio = $this->config["radio"];
        $dataIn = GetCWOut($tRadio);
        //        $this->doLog(1, "datain: " . $dataIn . "\n");

        //      echo strlen((string)($dataIn ?? "")) . "\n";
        if (strlen((string) ($dataIn ?? "")) > 0) {
            //     echo "first datain: $dataIn\n";
            $data = explode(chr(96), $dataIn);
            $tCW = ""; //$data[0]; //cwout, FROM keyer
            if (strlen((string) ($data[6] ?? "")) > 2) {
                echo "datain: " . $data[6] . "\n";
            }
            $tCWChangeCk = $data[3];
            $tCWInitCk = $data[4];

            $tCWCk = $data[1]; //cwout from keyer ready
            $tCWIn = strtoupper($data[2]); //cwin, TO keyer
            $tCWInWK = strtoupper($data[5]); //cwin, TO keyer
            $tCWInWKCk = $data[6];
            $t = $data[6]; //cwin, TO keyer
            if ($tCWChangeCk == 1) {
                SetInterface($tRadio, "CWChangeCk", "0");
                $cwS = GetField($tRadio, "WKSpeed", "Keyer");
                echo "after changeck: $cwS " . bin2hex($cwS) . "\n";
                if (
                    $this->config["keyerType"] == "rpk" ||
                    $this->config["keyerType"] == "wkr"
                ) {
                    //           $this->setWinkeySpeed(chr($cwS));
                    $this->setWinkeySpeed(chr($cwS));
                    $cwS = "";
                    $tCWIn = "";
                } elseif ($this->config["keyerType"] == "cat") {
                    $this->sendCATMessage(
                        $this->config["port"],
                        "L",
                        "KEYSPD " . $cwS,
                        $this->config["wkRemote"]
                    );
                    $cwS = "";
                    $tCWIn = "";
                }
            }
            $tCWInWKCk = 0;
            if ($tCWInWKCk == 1) {
                SetInterface($this->config["radio"], "CWInWKCk", 0);
                if (
                    $this->config["type"] == "rpk" ||
                    $this->config["type"] == "wkr"
                ) {
                    $this->InitWinKeyer(7);
                } elseif ($this->config["type"] == "cat") {
                    //init goes here
                }
                $cancel = false;
                while ($cancel == false) {
                    if (
                        strstr((string) ($tCWInWK ?? ""), "<") &&
                        strstr((string) ($tCWInWK ?? ""), ">")
                    ) {
                        echo "<> 1 found\n";
                        $tHStart = strpos($tCWInWK, "<");
                        $tHVal = substr($tCWInWK, $tHStart + 1, 2);
                        if ($tHVal == "F1" || $tHVal == "F2") {
                            if ($tHVal == "F1") {
                                $this->InitWinkeyer(11);
                                return true;
                            } elseif ($tHVal == "F2") {
                                $this->InitWinkeyer(7);
                                return true;
                            }
                        }
                        $tIns = chr(hexdec($tHVal));
                        $this->doLog(
                            $utest,
                            "check pinconf $tHVal and $tIns \n"
                        );
                        echo "tIns: $tIns \n";
                        $tHOut =
                            substr($tCWInWK, 0, $tHStart) .
                            $tIns .
                            substr($tCWInWK, strpos($tCWInWK, ">") + 1);
                        $tCWInWK = $tHOut;
                        $cancel = true;
                    } else {
                        $cancel = true;
                    }
                }

                echo "sock OK: " . $this->config["CWSockOK"] . "\n";
                if (
                    strlen((string) ($tCWInWK ?? "")) > 0 &&
                    $this->config["CWSockOK"] == 1
                ) {
                    $this->debug($tCWInWK);
                    $this->debug("php sending to keyer: KY " . $tCWInWK);
                    $this->cw_socket_write_smart(
                        $this->cw_TCP_Client,
                        "KY " . $tCWInWK . ";",
                        true
                    );
                }
            }

            if (strlen((string) ($tCWIn ?? "")) > 0) {
                echo "phps CW Port Server got data to send: " . $tCWIn . "\n";
                // A leading line feed is RigPi's existing Clear/Abort signal.
                // Replace the unsent in-memory queue instead of appending to it.
                // Any text following the line feed becomes the new held queue.
                if (str_starts_with($tCWIn, chr(10))) {
                // Abort immediately, even when held or the device buffer is full.
                if ($this->cw_TCP_Client) {
                    $this->sendWinkeyMessage("!ESC", $this->cw_TCP_Client, "KY ");
                } elseif (!$this->remotePeer && in_array($this->config["keyerType"], ["rpk", "wkr"], true)) {
                    $this->sendWinkeyMessage(chr(10), "", "");
                }
                $tH = substr($tCWIn, 1);
            } else {
                    $tH = $this->getCWBuffer() . $tCWIn;
                }
                //check for < and > to catch hex control characters.  See Winkeyer3 API.
                $cancel = false;
                while ($cancel == false) {
                    if (
                        strstr((string) ($tH ?? ""), "<") &&
                        strstr((string) ($tH ?? ""), ">")
                    ) {
                        echo "<> 2 found!\n";
                        $tHStart = strpos($tH, "<");
                        $tHVal = substr($tH, $tHStart + 1, 2);
                        echo "tHVal: $tHVal \n";
                        if ($tHVal == "F1" || $tHVal == "F2") {
                            if ($tHVal == "F1") {
                                $this->InitWinkeyer(11);
                                return true;
                            } elseif ($tHVal == "F2") {
                                $this->InitWinkeyer(7);
                                return true;
                            }
                        }
                        $tIns = chr(hexdec($tHVal));
                        $tHOut =
                            substr($tH, 0, $tHStart) .
                            $tIns .
                            substr($tH, strpos($tH, ">") + 1);
                        $tH = $tHOut;
                    } else {
                        $cancel = true;
                    }
                }
                // Exclamation marks are valid CW punctuation.  An earlier
                // parser loop intended to remove them, but its mutation was
                // commented out; any queued text containing "!" therefore
                // trapped this worker in an infinite loop.  Preserve the
                // character and continue processing the queue normally.
                $this->setCWBuffer($tH);
            }
            $tCWChangeCk = $data[3];
            $tCWInitCk = $data[4];
            //cw
            $tSend = $this->getCWBuffer();
            if (strlen((string) ($tSend ?? "")) > 0 && $tCWCk == "0") {
                //TO keyer plus $tCWCk is used as Hold
                echo "sending $tSend to " . $this->config["keyerType"] . " \n";
                if ($this->config["keyerType"] == "cat") {
                    // Send CAT CW in bounded chunks and advance the in-memory
                    // queue after every chunk.  The old code cleared messages
                    // shorter than 40 characters, but never removed the first
                    // ten characters of longer messages.  It consequently
                    // resent the same prefix forever.
                    if (strlen((string) ($tSend ?? "")) <= 10) {
                        $this->setCWBuffer("");
                    } else {
                        $this->setCWBuffer(substr($tSend, 10));
                        $tSend = substr($tSend, 0, 10);
                    }
                    $this->doLog(1, "sending CAT\n");
                    $this->sendCATMessage(
                        $this->config["port"],
                        "b",
                        "$tSend",
                        $this->config["wkRemote"]
                    );
                    //        $this->sendCATMessage(4534, "b", $tSend, '172.16.0.12');
                } else {
                    if ($this->config["full"] == "1") {
                        //do nothing
                    } else {
                        if (strlen((string) ($tSend ?? "")) < 40) {
                            $this->setCWBuffer("");
                        } else {
                            $this->setCWBuffer(substr($tSend, 9));
                            $tSend = substr($tSend, 0, 9);

                            $this->setCWBuffer("");
                        }
                        //           $this->cw_TCP_Client
                        //   if ($this->config["keyerType"] == "rpk" ||
                        //     $this->config["keyerType"] == "wkr")
                        //  {
                        $tSend = str_replace(":", "||", $tSend);
                        if ($this->config["CWSockOK"] == 1) {
                            echo "send $tSend to TCP\n";
                            $this->sendWinkeyMessage(
                                $tSend,
                                $this->cw_TCP_Client,
                                "KY "
                            );
                        } else {
                            $this->sendWinkeyMessage($tSend, "", "");
                        }
                        //   }else{
                        //      $this->sendWinkeyMessage($tSend, "", "");
                    }
                    // }
                }
            }
            if ($tCWInitCk == 1 && $this->config["portTrans"] == 0) {
                echo "INITing Keyer\n";
                SetInterface($tRadio, "CWInitCk", "0");
                if (
                    $this->config["keyerType"] == "rpk" ||
                    $this->config["keyerType"] == "wkr"
                ) {
                    $this->InitWinKeyer(7);
                } elseif ($this->config["keyerType"] == "cat") {
                    echo "Remote: " . $this->config["wkRemote"] . "\n";
                    //CAT init, if any, goes here
                }
            }

            if ($tCWChangeCk == 1) {
                $cwS = GetField($tRadio, "WKSpeed", "Keyer");
                SetInterface($tRadio, "CWChangeCk", "0");
                if (
                    $this->config["keyerType"] == "rpk" ||
                    $this->config["keyerType"] == "wkr"
                ) {
                    $tSend = "";
                    $this->setWinkeySpeed(chr($cwS));
                } elseif ($this->config["keyerType"] == "cat") {
                    $tSend = "";
                    $this->doLog(1, "sending CAT2\n");
                    $this->sendCATMessage(
                        $this->config["port"],
                        "L",
                        " KEYSPD " . $cwS,
                        $this->config["wkRemote"]
                    );
                }
            }

            if (
                ($this->config["keyerType"] == "rpk" ||
                    $this->config["keyerType"] == "wkr") &&
                $this->config["portTrans"] == 0
            ) {
                static $receiveFailed = false;
            if (!$receiveFailed && (self::$winkey || $this->cw_TCP_Client || $this->remotePeer)) {
                try {
                    $wk_s = $this->getWinkeyMessage($this->cw_socket, 128);
                    if (strlen((string) ($wk_s ?? "")) > 0) {
                        for (
                            $i = 0;
                            $i < strlen((string) ($wk_s ?? ""));
                            $i++
                        ) {
                            $wk1 = substr($wk_s, $i, 1);
                            if (ord($wk1) < 0x80) {
                                if (strlen((string) ($wk1 ?? "")) > 0) {
                                    SetCWOutAdditive($tRadio, $wk1); RigPiCWEcho::state($tRadio, $wk1);
                                }
                            } elseif ((ord($wk1) & 0xc0) == 0x80) {
                                $wk = ord($wk1) & 0x3f;
                                //               SetKeyerSpeed($tRadio, "WKPot", $wk);
                                SetKeyerSpeed($tRadio, "WKPot", $wk);
                                //                            $this->setWinkeySpeed($wk);
                                $this->cwSpeed = $wk;
                            } elseif ((ord($wk1) & 0xc0) == 0xc0) {
                                //            echo "phps  c0: " . bin2hex($wk1);
                                //buffer 3/4 full
                                if ((ord($wk1) & 0x01) == 0x01) {
                                    $this->config["full"] = "1";
                                } else {
                                    $this->config["full"] = "0";
                                }
                            }
                        }
                    }
                
                } catch (Throwable $error) {
                    $receiveFailed = true;
                    $this->config["full"] = "0";
                    error_log('Keyer feedback disabled; sending retained: '.get_class($error).': '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine());
                }
            }
            }
        }
        //   echo "returning false\n";
        return true;
    }

    public function infinite_loop()
    {
        $test1 = true;
        do {
            usleep(100000);
            $test1 = $this->loop_once();
        } while ($test1);
    }

    public static function debug($text)
    {
        echo "{$text}\r\n";
    }

    public function &__get($name)
    {
        return $this->{$name};
    }
    public static function cw_socket_write_smart(&$sock, $string, $crlf = "")
    {
        if ($crlf) {
            $string = "{$string}{$crlf}";
        }
        return socket_write($sock, $string);

        //   return ($sock, $string);
    }

    public static function TCP_socket_write(&$sock, $string, $crlf = "")
    {
        //    echo "phps cw writing: " . $string . "\n";
        if ($crlf) {
            $string = "{$string}{$crlf}";
        }
        return socket_write($sock, $string);
        //    return socket_write_smart($sock, $string, $crlf);
    }

    public static function TCP_socket_read(&$sock, $count)
    {
        $rT = "";
        $i = 0;
        if ($count > 0) {
            $rT = fread($sock, $count);
        } else {
            do {
                //       echo "i: " . $i . "\n";
                $rT .= fread($sock, 128);
            } while (($i += 128) === strlen((string) ($rT ?? "")));
        }
        //    echo "phps cw reading..." . $rT . "\n";
        return $rT;
        //    return fgets($sock);
        // return socket_write_smart($sock, $string, $crlf);
    }

    //////////// Keyer
    public function setCWSpeed($which)
    {
        $this->cwSpeed = $which;
    }

    public function setKeyerPort($which)
    {
        $this->keyerPort = $which;
    }

    public function getKeyerPort()
    {
        return $this->keyerPort;
    }

    public function set($which)
    {
        $this::$cwBuffer = $which;
    }

    public function setCWBuffer($which)
    {
        $this::$cwBuffer = $which;
    }

    public function getCWBuffer()
    {
        return $this::$cwBuffer;
    }

    public function setWinkeyDevice($which)
    {
        //   echo "set winkey dev: " . $which . "\n";
        //    if ($this::$winkeyOK) {
        if (self::$winkey->deviceSet($which) == false) {
            $this::$winkeyOK = false;
        }
        //    }
    }

    public function setWinkeyBaud($which)
    {
        if ($this::$winkeyOK) {
            self::$winkey->confBaudRate($which);
        }
    }

    public function setWinkeyPTT($which)
    {
        if ($this::$winkeyOK) {
            self::$winkey->confPTT(chr(18), $which);
        }
    }

    public function setWinkeyParity($which)
    {
        if ($this::$winkeyOK) {
            self::$winkey->confParity($which);
        }
    }

    public function setWinkeyBits($which)
    {
        if ($this::$winkeyOK) {
            self::$winkey->confCharacterLength($which);
        }
    }

    public function setWinkeyStop($which)
    {
        if ($this::$winkeyOK) {
            self::$winkey->confStopBits($which);
        }
    }

    public function setWinkeyFlow($which)
    {
        if ($this::$winkeyOK) {
            self::$winkey->confFlowControl($which);
        }
    }

    public function setWinkeyOpen()
    {
        if ($this::$winkeyOK) {
            self::$winkey->deviceOpen();
        }
    }

    public function sendCATMessage($port, $com, $what, $toIP)
    {
        if (strlen((string) ($what ?? "")) > 0 && is_null($what) == false) {
            $payload = "$toIP:$port|$com|$what";
            echo "CAT: php /var/www/html/cwCAT.php " . escapeshellarg($payload);
            // Quote the complete payload as one shell argument.  Apostrophes
            // are valid CW punctuation and must not terminate this command.
            exec(
                escapeshellarg(PHP_BINARY) .
                    " " .
                    escapeshellarg("/var/www/html/cwCAT.php") .
                    " " .
                    escapeshellarg($payload)
            );
        }
    }

    public function sendWinkeyMessage($what, $sock, $K)
    {
        if ($sock && ($K === "KY " || $K === "KS ")) {
            if ($K === "KS ") {
                $what = (string) ord(substr($what, -1));
            }
            $message = $K . $what . ";";
            while ($message !== "") {
                $written = @socket_write($sock, $message);
                if ($written === false || $written === 0) {
                    error_log(date('c').' keyer send failed; not replayed'.PHP_EOL,3,'/tmp/rigpi-remote-keyer.log'); @socket_shutdown($sock); return;
                }
                $message = substr($message, $written);
            }
            return;
        }
        if (self::$winkey) {
            self::$winkey->sendMessage($K . $what, "");
        }
    }

    private $remotePeer=null, $remoteRetryAt=0;
    public function getWinkeyMessage(&$sock, $count)
    {
        if ($this->cw_TCP_Client || $this->remotePeer) {
            static $echoBuffer = '';
            if (!$this->cw_TCP_Client) {
                if (microtime(true) < $this->remoteRetryAt) return '';
                $this->remoteRetryAt = microtime(true) + 3;
                $candidate=socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                socket_set_option($candidate, SOL_SOCKET, SO_SNDTIMEO, ['sec'=>1,'usec'=>0]);
                if (!@socket_connect($candidate,$this->remotePeer[0],$this->remotePeer[1])) { socket_close($candidate); return ''; }
                if (@socket_write($candidate,'CE1;') !== 4) { socket_close($candidate); return ''; }
                $this->cw_TCP_Client=$candidate;
                error_log(date('c').' remote keyer reconnected'.PHP_EOL,3,'/tmp/rigpi-remote-keyer.log');
            }
            if (!$this->remotePeer && @socket_getpeername($this->cw_TCP_Client,$host,$port)) $this->remotePeer=[$host,$port];
            $input='';
            $n=@socket_recv($this->cw_TCP_Client,$input,8192,MSG_DONTWAIT);
            if ($n>0) return RigPiCWEcho::decode($echoBuffer,$input);
            if ($n===0 || ($n===false && !in_array(socket_last_error($this->cw_TCP_Client),[SOCKET_EAGAIN,SOCKET_EWOULDBLOCK,SOCKET_EINTR],true))) {
                socket_close($this->cw_TCP_Client); $this->cw_TCP_Client=null; $echoBuffer='';
                $this->remoteRetryAt=microtime(true)+1;
                error_log(date('c').' remote keyer disconnected; retrying'.PHP_EOL,3,'/tmp/rigpi-remote-keyer.log');
            }
            return '';
        }
        //    $count = 8;
        //    echo "count: " . strlen((string)($count ?? "")) . " " . bin2hex($count) . "\n";
        if ($this::$winkeyOK) {
            if (self::$winkey && str_starts_with((string) ($this->config["port"] ?? ""), "/dev/")) {
                $ts = self::$winkey->readPort($count);
                return $ts;
            }
            return "";
        }
    }

    public function setWinkeySpeed($what)
    {
        echo "Setting speed to $what";
        if ($this::$winkeyOK) {
            if (is_null($what) == false && $this->cw_TCP_Client) {
                $this::$winkey_WPM_Speed = $what;
                $this->sendWinkeyMessage(
                    chr(2) . $what,
                    $this->cw_TCP_Client,
                    "KS "
                );
            } else {
                //        self::$winkey->sendMessage(chr(2) . $what, "");
                $this->sendWinkeyMessage(chr(2) . $what, "", "");
            }
        }
    }

    public function getWinkeySpeed()
    {
        if ($this::$winkeyOK) {
            return $this::$winkey_WPM_Speed;
        }
    }

    public static function setWinKeyPinConf($whichRadio)
    {
        if (!self::$winkey) { return; }
        //    $this->doLog( "start pinconf for radio $whichRadio \n");

        echo "here in conf set\n";
        $winkey_Admin = chr(0x00);
        $winkey_Host_Open = chr(0x02);
        $winkey_Admin_Host_Close = chr(0x03);
        //   $winkey_Sidetone_Frequency = chr(1) . chr(GetField($whichRadio, "WKSidetone", "Keyer")); //800 Hz
        $winkey_Sidetone_Frequency = chr(1) . chr(96); //800 Hz

        $winkey_Pin = GetField($whichRadio, "WKPinConf", "Keyer");
        //   if (hexdec($this->config["pttOn"] & $winkey_Pin) == 1) {
        //    $winkey_Pin = $winkey_Pin & 0xfe;
        //   }
        $winkey_Pin = chr(0x07 & $winkey_Pin); // works!
        $winkey_Pin_Configuration = chr(9) . chr(0); //$winkey_Pin;
        //    self::$winkey->sendMessage($winkey_Host_Open, "");
        //    self::$winkey->sendMessage($winkey_Admin . $winkey_Host_Open, "");
        //    self::$winkey->sendMessage($winkey_Sidetone_Frequency,"");
        self::$winkey->sendMessage($winkey_Pin_Configuration, "");
        //    self::$winkey->sendMessage(chr(0x18) . chr($this->config["pttOn"]), "");
        //    self::$winkey->sendMessage(chr(0x18), "");
    }

    public function InitWinKeyer($which)
    {
        $tRadio = 1;
        //Winkeyer ADMIN constants
        $winkey_Admin = chr(0x00);
        $winkey_WK3_Mode = chr(20); //sets WK3 mode
        $winkey_Reset = chr(0x01);
        $winkey_Host_Open = chr(0x02);
        $winkey_Admin_Host_Close = chr(0x03);
        $winkey_Echo_Test = chr(0x04);
        $winkey_Readback_VCC = chr(0x21);

        //Winkeyer commands
        $winkey_Sidetone_Control = chr(0x01); //+<nn>
        $winkey_Get_Speed_Pot = chr(0x07);
        $winkey_Request_Status = chr(0x15);
        //Winkeyer load defaults
        $tRadio = $this->config["radio"];
        $winkey_Mode_Register =
            chr(14) . chr(GetField($tRadio, "WKMode", "Keyer"));
        $winkey_Speed = chr(2) . chr(GetField($tRadio, "WKSpeed", "Keyer"));
        $winkey_Sidetone_Frequency =
            chr(1) . chr(GetField($tRadio, "WKSidetone", "Keyer")); //800 Hz
        $winkey_Weight = chr(3) . chr(GetField($tRadio, "WKWeight", "Keyer")); //50
        $winkey_Leadin_Time =
            chr(4) .
            chr(GetField($tRadio, "WKLeadin", "Keyer")) .
            chr(GetField($tRadio, "WKTail", "Keyer"));
        $winkey_Minimum_WPM =
            chr(5) .
            chr(GetField($tRadio, "WKMinWPM", "Keyer")) .
            chr(GetField($tRadio, "WKWPMRange", "Keyer")) .
            chr(0); //5 WPM
        $winkey_X2_Mode =
            chr(0) . chr(22) . chr(GetField($tRadio, "WKX2Mode", "Keyer"));
        $winkey_Key_Compensation =
            chr(0x11) . chr(GetField($tRadio, "WKKeyComp", "Keyer"));
        $winkey_Farnsworth_WPM =
            chr(0x0d) . chr(GetField($tRadio, "WKFarnsworth", "Keyer"));
        $winkey_Paddle_Setpoint =
            chr(0x12) . chr(GetField($tRadio, "WKPaddleSet", "Keyer"));
        $winkey_DitDah_Ratio =
            chr(0x17) . chr(GetField($tRadio, "WKDitDahRatio", "Keyer")); //50
        $winkey_Pin = GetField($tRadio, "WKPinConf", "Keyer");
        ($winkey_Pin = $winkey_Pin) and $which;
        if (hexdec($this->config["pttOn"] & $winkey_Pin) == 1) {
            $winkey_Pin = $winkey_Pin & 0xfe;
        }
        $winkey_Pin_Configuration = chr(9) . chr($winkey_Pin);
        $winkey_X1_Mode =
            chr(0) . chr(15) . chr(GetField($tRadio, "WKX1Mode", "Keyer"));
        $this->sendWinkeyMessage($winkey_Admin . $winkey_Host_Open, "", "");
        usleep(100000);
        $this->sendWinkeyMessage($winkey_Admin . $winkey_WK3_Mode, "", "");
        usleep(100000);
        $this->sendWinkeyMessage($winkey_Mode_Register, "", "");
        //    echo "sent: " . bin2hex($winkey_Mode_Register) . "\n";
        //    $this->sendWinkeyMessage($winkey_Speed,$this->cw_TCP_Client, "KS ");
        $this->sendWinkeyMessage($winkey_Sidetone_Frequency, "", "");
        $this->sendWinkeyMessage($winkey_Weight, "", "");
        $this->sendWinkeyMessage($winkey_Leadin_Time, "", "");
        $this->sendWinkeyMessage($winkey_Minimum_WPM, "", "");
        $this->sendWinkeyMessage($winkey_X2_Mode, "", "");
        $this->sendWinkeyMessage($winkey_Key_Compensation, "", "");
        $this->sendWinkeyMessage($winkey_Farnsworth_WPM, "", "");
        $this->sendWinkeyMessage($winkey_Paddle_Setpoint, "", "");
        $this->sendWinkeyMessage($winkey_DitDah_Ratio, "", "");
        $this->sendWinkeyMessage($winkey_Pin_Configuration, "", "");
        $this->sendWinkeyMessage($winkey_X1_Mode, "", "");
        usleep(100000);

        $tInit = $winkey_Get_Speed_Pot . $winkey_Request_Status;
        $this->sendWinkeyMessage($tInit, "", "");
        usleep(100000);
        $this->sendWinkeyMessage(
            chr(0x18) . chr($this->config["pttOn"]),
            "",
            ""
        );
        //    echo "init done\n";
        //    $this->sendWinkeyMessage("E");
    }
}

?>
