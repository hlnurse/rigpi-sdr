<?php
/**
 * @author Howard Nurse W6HN
 *
 * This routine gets QRZ info for specified call.
 *
 * It must live in the programs folder
 */
ini_set("error_reporting", E_ALL);
ini_set("display_errors", 1);
function getCallbookFunc($call, $getWhat, $user)
{
    //$call = "HC3RJ";
    //$getWhat = "QRZData";
    //$user = "1";
    $call = strtoupper($call);
    $dRoot = "/var/www/html";
    require $dRoot . "/programs/sqldata.php";
    require_once $dRoot . "/classes/MysqliDb.php";
    $db = new MysqliDb(
        "localhost",
        $sql_radio_username,
        $sql_radio_password,
        $sql_radio_database
    );
    $db->where("User", $user);
    $rowBook = $db->getOne("Callbook");
    if (!$rowBook) {
        return;
    }

    if ($rowBook["Callsign"] != $call) {
        //		echo $call . " ". $rowBook['Callsign'] . " " . $getWhat;
        $db->where("uID", $user);
        $row = $db->getOne("Users");
        $key = $row["qrzKey"];
        $pwd = $row["qrzPWD"];
        $qrzUser = $row["qrzUser"];
        $result = "";
        $qrzOK = true;
        clearCallbookRow($user, $db);
        //		echo $getWhat."\n";
        if ($getWhat == "QRZData") {
            if (connection_status() == CONNECTION_NORMAL) {
                if (
                    !($sock = @fsockopen(
                        getHostByName("qrz.com"),
                        80,
                        $T,
                        $U,
                        1
                    ))
                ) {
                    $qrzOK = false;
                } else {
                    fclose($sock);
                }
            } else {
                $qrzOK = false;
            }
            if (strlen((string) ($call ?? "")) > 0) {
                if ($qrzOK == true) {
                    // No credentials — skip QRZ entirely
                    if (
                        strlen((string) ($qrzUser ?? "")) == 0 ||
                        strlen((string) ($pwd ?? "")) == 0
                    ) {
                        $qrzOK = false;
                        $result = "NO_CREDS";
                    } else {
                        if (strlen((string) ($key ?? "")) == 0) {
                            $qKey = getKey($qrzUser, $pwd, $db, $user);
                            $db->where("uID", $user);
                            $db->update("Users", ["qrzKey" => $qKey]);
                        }
                        $result = getQRZData($key, $call, $user, $qrzUser, $db);
                        //        echo "$call";

                        //print_r($result);
                        if (
                            $result == "Invalid session key" ||
                            $result == "Username / password required" ||
                            $result == "Session Timeout"
                        ) {
                            $qKey = getKey($qrzUser, $pwd, $db, $user);
                            $db->where("uID", $user);
                            $db->update("Users", ["qrzKey" => $qKey]);
                            $result = getQRZData(
                                $qKey,
                                $call,
                                $user,
                                $qrzUser,
                                $db
                            );
                            //          echo "key: " . $qKey;
                            //            echo $qrzUser;
                            if (
                                $result == "Invalid session key" ||
                                $result == "Username / password required" ||
                                $result == "Session Timeout"
                            ) {
                                // Try HamQTH before FCC
                                $hqthResult = getHamQTHData($call, $user, $db);
                                if ($hqthResult != "OK") {
                                    getFCCData($call, $user, $db);
                                }
                                $result = $hqthResult;
                            }
                        }
                    } // end else (has credentials)
                } else {
                    $result = "NO";
                }
                if ($result == "OK") {
                    fillGeo($user, $call, $db, "QRZ");
                    getISize($user, $db);
                } else {
                    // Try HamQTH before falling back to FCC
                    $hqthResult = getHamQTHData($call, $user, $db);
                    if ($hqthResult != "OK") {
                        getFCCData($call, $user, $db);
                    }
                }
            } else {
                return 0;
            }
            fillMyUserDefaults($user, $db);
            fillRadio($user, $db);
            fillDXDistance($user, $db);
        } elseif ($getWhat == "QRZdxcc") {
            $result = getQRZDXCC($key, $call, $user, $qrzUser, $db);
            if (
                $result == "Invalid session key" ||
                $result == "Username / password required" ||
                $result == "Session Timeout"
            ) {
                $qKey = getKey($qrzUser, $pwd, $db, $user);
                $result = getQRZDXCC($qKey, $call, $user, $qrzUser, $db);
            }
        } elseif ($getWhat == "QRZbio") {
            $result = getBio($key, $call, $user, $qrzUser, $db);
            if ($result == "Invalid session key") {
                $qKey = getKey($qrzUser, $pwd, $db, $user);
                $result = getBio($qKey, $call, $user, $qrzUser, $db);
            }
        } elseif ($getWhat == "FCCData") {
            //			echo "FCC data!!!!!!!!!";
            $result = getFCCData($call, $user, $db);
            //			echo $result."\n";
            fillMyUserDefaults($user, $db);
            fillRadio($user, $db);
            fillDXDistance($user, $db);
        }
        //		echo $getWhat;
    }
    return 0;
}

function clearCallbookRow($user, $db)
{
    $tArray = [
        "Callsign" => "",
        "Aliases" => "",
        "DXCC" => "0",
        "His_Grid" => "",
        "His_Name" => "",
        "His_QTH" => "",
        "His_Street" => "",
        "His_City" => "",
        "Note" => "",
        "His_State" => "",
        "His_QTH" => "",
        "His_Latitude" => "",
        "His_Longitude" => "",
        "His_Distance_Mi" => "",
        "His_Distance_KM" => "",
        "His_Email" => "",
        "His_URL" => "",
        "His_County" => "",
        "His_Section" => "",
        "His_Country" => "",
        "His_Continent" => "",
        "His_Entity" => "",
        "Abbreviation" => "",
        "His_FIPS" => "",
        "His_Zip" => "",
        "IOTA" => "",
        "CQZone" => "",
        "ITUZone" => "",
        "WPX_Prefix" => "",
        "Ten_Ten" => "",
        "VE_Province" => "",
        "His_Power" => "",
        "Beam_Heading" => "0",
        "LoTW" => "0",
        "eQSL" => "0",
        "mQSL" => "0",
        "ClubLogQSL" => "0",
        "QRZQSL" => "0",
        "Club" => "",
        "My_Section" => "",
        "My_Contest_Class" => "",
        "Contest_ID" => "",
        "LicenseClass" => "",
        "ImageH" => "",
        "ImageW" => "",
        "ImageURL" => "",
        "TimeZone" => "",
        "DST" => "",
        "GMTOffset" => "",
        "QSLMgr" => "",
        "Transmitter" => "",
        "Receiver" => "",
        "Amplifier" => "",
        "TX_Power" => "",
        "RX_Antenna" => "",
        "Sat_Name" => "",
        "Sat_Mode" => "",
        "Propagation_Mode" => "",
        "His_Bio" => "",
    ];
    $db->where("User", $user);
    $row = $db->update("Callbook", $tArray);
    //  return "OK";
}

function getKey($qrzUser, $pwd, $db, $user)
{
    $key = "";
    $url = "https://xmldata.qrz.com/xml/current";
    $data = [
        "username" => $qrzUser,
        "password" => $pwd,
        "agent" => "RigPi_1.0",
    ];
    $options = [
        "http" => [
            "header" => "Content-type: application/x-www-form-urlencoded\r\n",
            "method" => "POST",
            "content" => http_build_query($data),
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === false) {
    }

    $xml = simplexml_load_string($result);
    if (strlen((string) ($xml->Session->Error ?? ""))) {
        $db->where("User", $user);
        $tArray = ["qrzError" => "Key: " . (string) $xml->Session->Error];
        print_r($tArray);
        $row = $db->update("Callbook", $tArray);
        $key = "";
    } else {
        $db->where("User", $user);
        $tArray = ["qrzError" => "Key: OK"];
        $row = $db->update("Callbook", $tArray);
        $key = (string) $xml->Session->Key;
    }

    return $key;
}

function getQRZData($key, $call, $user, $qrzUser, $db)
{
    $url = "https://xmldata.qrz.com/xml/current";
    $data = ["s" => $key, "callsign" => $call];
    $options = [
        "http" => [
            "header" => "Content-type: application/x-www-form-urlencoded\r\n",
            "method" => "POST",
            "content" => http_build_query($data),
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    $xml = simplexml_load_string($result);
    $tArray = "";
    $hisName =
        removeAccents($xml->Callsign->fname) .
        " " .
        removeAccents($xml->Callsign->name);
    $hisName1 = ucwords(strtolower($hisName));
    $wpx = getWPX($call);
    if (strlen((string) ($xml->Session->Error ?? ""))) {
        $db->where("User", $user);
        $tArray = ["qrzError" => (string) $xml->Session->Error];
        $row = $db->update("Callbook", $tArray);
        return (string) $xml->Session->Error;
    }

    if (strlen((string) ($xml->Callsign->call ?? "")) > 0) {
        $tArray = [
            "Callsign" => (string) $xml->Callsign->call,
            "Aliases" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->aliases
            ),
            "DXCC" => (string) $xml->Callsign->dxcc,
            "His_Grid" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->grid
            ),
            "His_Name" => preg_replace('/[^\x20-\x7E]/', "", $hisName1),
            "His_State" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->state
            ),
            "His_Street" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->addr1
            ),
            "His_City" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->addr2
            ),
            "His_QTH" =>
                preg_replace(
                    '/[^\x20-\x7E]/',
                    "",
                    (string) $xml->Callsign->addr1
                ) .
                ", " .
                preg_replace(
                    '/[^\x20-\x7E]/',
                    "",
                    (string) $xml->Callsign->addr2
                ) .
                ", " .
                preg_replace(
                    '/[^\x20-\x7E]/',
                    "",
                    (string) $xml->Callsign->state
                ) .
                ", " .
                preg_replace(
                    '/[^\x20-\x7E]/',
                    "",
                    (string) $xml->Callsign->land
                ) .
                " " .
                preg_replace(
                    '/[^\x20-\x7E]/',
                    "",
                    (string) $xml->Callsign->zip
                ),
            "Note" => "QRZ",
            "His_Email" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->email
            ),
            "His_URL" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->url
            ),
            "His_County" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->county
            ),
            "His_Country" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->land
            ),
            "His_FIPS" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->fips
            ),
            "His_Continent" => "",
            "His_Zip" => preg_replace(
                '/[^\x20-\x7E]/',
                "",
                (string) $xml->Callsign->zip
            ),
            "His_Latitude" => (string) $xml->Callsign->lat,
            "His_Longitude" => (string) $xml->Callsign->lon,
            "IOTA" => (string) $xml->Callsign->iota,
            "CQZone" => (string) $xml->Callsign->cqzone,
            "ITUZone" => (string) $xml->Callsign->ituzone,
            "WPX_Prefix" => $wpx,
            "Ten_Ten" => "",
            "VE_Province" => "",
            "LoTW" => (string) $xml->Callsign->lotw,
            "eQSL" => (string) $xml->Callsign->eqsl,
            "mQSL" => (string) $xml->Callsign->mqsl,
            "ClubLogQSL" => 0,
            "QRZQSL" => 0,
            "LicenseClass" => (string) $xml->Callsign->class,
            "ImageURL" => (string) $xml->Callsign->image,
            "TimeZone" => (string) $xml->Callsign->TimeZone,
            "DST" => (string) $xml->Callsign->DST,
            "GMTOffset" => (string) $xml->Callsign->GMTOffset,
            "QSLMgr" => (string) $xml->Callsign->qslmgr,
            "qrzError" => "OK",
        ];
        $db->where("User", $user);
        $row = $db->update("Callbook", $tArray);
        //   echo $db->getLastError();
        getBio($key, $call, $user, $qrzUser, $db);
        $dxcc = (string) $xml->Callsign->dxcc;
        fillEntity($dxcc, $user, $db);
        return "OK";
    } else {
        fillDXCC($call, $user, $db);
    }
}
function removeAccents($str)
{
    $a = [
        "Ãƒâ‚¬",
        "Ãƒ?",
        "Ãƒâ€š",
        "ÃƒÆ’",
        "Ãƒâ€ž",
        "Ãƒâ€¦",
        "Ãƒâ€ ",
        "Ãƒâ€¡",
        "ÃƒË†",
        "Ãƒâ€°",
        "ÃƒÅ ",
        "Ãƒâ€¹",
        "ÃƒÅ’",
        "Ãƒ?",
        "ÃƒÅ½",
        "Ãƒ?",
        "Ãƒ?",
        "Ãƒâ€˜",
        "Ãƒâ€™",
        "Ãƒâ€œ",
        "Ãƒâ€?",
        "Ãƒâ€¢",
        "Ãƒâ€“",
        "ÃƒËœ",
        "Ãƒâ„¢",
        "ÃƒÅ¡",
        "Ãƒâ€º",
        "ÃƒÅ“",
        "Ãƒ?",
        "ÃƒÅ¸",
        "ÃƒÂ ",
        "ÃƒÂ¡",
        "ÃƒÂ¢",
        "ÃƒÂ£",
        "ÃƒÂ¤",
        "ÃƒÂ¥",
        "ÃƒÂ¦",
        "ÃƒÂ§",
        "ÃƒÂ¨",
        "ÃƒÂ©",
        "ÃƒÂª",
        "ÃƒÂ«",
        "ÃƒÂ¬",
        "ÃƒÂ­",
        "ÃƒÂ®",
        "ÃƒÂ¯",
        "ÃƒÂ±",
        "ÃƒÂ²",
        "ÃƒÂ³",
        "ÃƒÂ´",
        "ÃƒÂµ",
        "ÃƒÂ¶",
        "ÃƒÂ¸",
        "ÃƒÂ¹",
        "ÃƒÂº",
        "ÃƒÂ»",
        "ÃƒÂ¼",
        "ÃƒÂ½",
        "ÃƒÂ¿",
        "Ã„â‚¬",
        "Ã„?",
        "Ã„â€š",
        "Ã„Æ’",
        "Ã„â€ž",
        "Ã„â€¦",
        "Ã„â€ ",
        "Ã„â€¡",
        "Ã„Ë†",
        "Ã„â€°",
        "Ã„Å ",
        "Ã„â€¹",
        "Ã„Å’",
        "Ã„?",
        "Ã„Å½",
        "Ã„?",
        "Ã„?",
        "Ã„â€˜",
        "Ã„â€™",
        "Ã„â€œ",
        "Ã„â€?",
        "Ã„â€¢",
        "Ã„â€“",
        "Ã„â€”",
        "Ã„Ëœ",
        "Ã„â„¢",
        "Ã„Å¡",
        "Ã„â€º",
        "Ã„Å“",
        "Ã„?",
        "Ã„Å¾",
        "Ã„Å¸",
        "Ã„Â ",
        "Ã„Â¡",
        "Ã„Â¢",
        "Ã„Â£",
        "Ã„Â¤",
        "Ã„Â¥",
        "Ã„Â¦",
        "Ã„Â§",
        "Ã„Â¨",
        "Ã„Â©",
        "Ã„Âª",
        "Ã„Â«",
        "Ã„Â¬",
        "Ã„Â­",
        "Ã„Â®",
        "Ã„Â¯",
        "Ã„Â°",
        "Ã„Â±",
        "Ã„Â²",
        "Ã„Â³",
        "Ã„Â´",
        "Ã„Âµ",
        "Ã„Â¶",
        "Ã„Â·",
        "Ã„Â¹",
        "Ã„Âº",
        "Ã„Â»",
        "Ã„Â¼",
        "Ã„Â½",
        "Ã„Â¾",
        "Ã„Â¿",
        "Ã…â‚¬",
        "Ã…?",
        "Ã…â€š",
        "Ã…Æ’",
        "Ã…â€ž",
        "Ã…â€¦",
        "Ã…â€ ",
        "Ã…â€¡",
        "Ã…Ë†",
        "Ã…â€°",
        "Ã…Å’",
        "Ã…?",
        "Ã…Å½",
        "Ã…?",
        "Ã…?",
        "Ã…â€˜",
        "Ã…â€™",
        "Ã…â€œ",
        "Ã…â€?",
        "Ã…â€¢",
        "Ã…â€“",
        "Ã…â€”",
        "Ã…Ëœ",
        "Ã…â„¢",
        "Ã…Å¡",
        "Ã…â€º",
        "Ã…Å“",
        "Ã…?",
        "Ã…Å¾",
        "Ã…Å¸",
        "Ã…Â ",
        "Ã…Â¡",
        "Ã…Â¢",
        "Ã…Â£",
        "Ã…Â¤",
        "Ã…Â¥",
        "Ã…Â¦",
        "Ã…Â§",
        "Ã…Â¨",
        "Ã…Â©",
        "Ã…Âª",
        "Ã…Â«",
        "Ã…Â¬",
        "Ã…Â­",
        "Ã…Â®",
        "Ã…Â¯",
        "Ã…Â°",
        "Ã…Â±",
        "Ã…Â²",
        "Ã…Â³",
        "Ã…Â´",
        "Ã…Âµ",
        "Ã…Â¶",
        "Ã…Â·",
        "Ã…Â¸",
        "Ã…Â¹",
        "Ã…Âº",
        "Ã…Â»",
        "Ã…Â¼",
        "Ã…Â½",
        "Ã…Â¾",
        "Ã…Â¿",
        "Ã†â€™",
        "Ã†Â ",
        "Ã†Â¡",
        "Ã†Â¯",
        "Ã†Â°",
        "Ã‡?",
        "Ã‡Å½",
        "Ã‡?",
        "Ã‡?",
        "Ã‡â€˜",
        "Ã‡â€™",
        "Ã‡â€œ",
        "Ã‡â€?",
        "Ã‡â€¢",
        "Ã‡â€“",
        "Ã‡â€”",
        "Ã‡Ëœ",
        "Ã‡â„¢",
        "Ã‡Å¡",
        "Ã‡â€º",
        "Ã‡Å“",
        "Ã‡Âº",
        "Ã‡Â»",
        "Ã‡Â¼",
        "Ã‡Â½",
        "Ã‡Â¾",
        "Ã‡Â¿",
    ];
    $b = [
        "A",
        "A",
        "A",
        "A",
        "A",
        "A",
        "AE",
        "C",
        "E",
        "E",
        "E",
        "E",
        "I",
        "I",
        "I",
        "I",
        "D",
        "N",
        "O",
        "O",
        "O",
        "O",
        "O",
        "O",
        "U",
        "U",
        "U",
        "U",
        "Y",
        "s",
        "a",
        "a",
        "a",
        "a",
        "a",
        "a",
        "ae",
        "c",
        "e",
        "e",
        "e",
        "e",
        "i",
        "i",
        "i",
        "i",
        "n",
        "o",
        "o",
        "o",
        "o",
        "o",
        "o",
        "u",
        "u",
        "u",
        "u",
        "y",
        "y",
        "A",
        "a",
        "A",
        "a",
        "A",
        "a",
        "C",
        "c",
        "C",
        "c",
        "C",
        "c",
        "C",
        "c",
        "D",
        "d",
        "D",
        "d",
        "E",
        "e",
        "E",
        "e",
        "E",
        "e",
        "E",
        "e",
        "E",
        "e",
        "G",
        "g",
        "G",
        "g",
        "G",
        "g",
        "G",
        "g",
        "H",
        "h",
        "H",
        "h",
        "I",
        "i",
        "I",
        "i",
        "I",
        "i",
        "I",
        "i",
        "I",
        "i",
        "IJ",
        "ij",
        "J",
        "j",
        "K",
        "k",
        "L",
        "l",
        "L",
        "l",
        "L",
        "l",
        "L",
        "l",
        "l",
        "l",
        "N",
        "n",
        "N",
        "n",
        "N",
        "n",
        "n",
        "O",
        "o",
        "O",
        "o",
        "O",
        "o",
        "OE",
        "oe",
        "R",
        "r",
        "R",
        "r",
        "R",
        "r",
        "S",
        "s",
        "S",
        "s",
        "S",
        "s",
        "S",
        "s",
        "T",
        "t",
        "T",
        "t",
        "T",
        "t",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "W",
        "w",
        "Y",
        "y",
        "Y",
        "Z",
        "z",
        "Z",
        "z",
        "Z",
        "z",
        "s",
        "f",
        "O",
        "o",
        "U",
        "u",
        "A",
        "a",
        "I",
        "i",
        "O",
        "o",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "U",
        "u",
        "A",
        "a",
        "AE",
        "ae",
        "O",
        "o",
    ];
    return str_replace($a, $b, $str ?? "");
}
function toASCII($str)
{
    return strtr(
        utf8_decode($str),
        utf8_decode(
            "xÃ…Â Ã…â€™Ã…Â½Ã…Â¡Ã…â€œÃ…Â¾Ã…Â¸Ã‚Â¥Ã‚ÂµÃƒâ‚¬Ãƒ?Ãƒâ€šÃƒÆ’Ãƒâ€žÃƒâ€¦Ãƒâ€ Ã„â€ Ãƒâ€¡ÃƒË†Ãƒâ€°ÃƒÅ Ãƒâ€¹ÃƒÅ’Ãƒ?ÃƒÅ½Ãƒ?Ãƒ?Ãƒâ€˜Ãƒâ€™Ãƒâ€œÃƒâ€?Ãƒâ€¢Ãƒâ€“ÃƒËœÃƒâ„¢ÃƒÅ¡Ãƒâ€ºÃƒÅ“Ãƒ?ÃƒÅ¸ÃƒÂ ÃƒÂ¡ÃƒÂ¢ÃƒÂ£ÃƒÂ¤ÃƒÂ¥ÃƒÂ¦Ã„â€¡ÃƒÂ§ÃƒÂ¨ÃƒÂ©ÃƒÂªÃƒÂ«ÃƒÂ¬ÃƒÂ­ÃƒÂ®ÃƒÂ¯ÃƒÂ°ÃƒÂ±ÃƒÂ²ÃƒÂ³ÃƒÂ´ÃƒÂµÃƒÂ¶ÃƒÂ¸ÃƒÂ¹ÃƒÂºÃƒÂ»ÃƒÂ¼ÃƒÂ½ÃƒÂ¿"
        ),
        "xSOZ!ozYYuAAAAAAACCEEEEIIIIDNOOOOOOUUUUYsaaaaaaacceeeeiiiionoooooouuuuyy"
    );
}

function getISize($user, $db)
{
    $db->where("User", $user);
    $rowBook = $db->getOne("Callbook");
    $uri = $rowBook["ImageURL"];
    if (strlen((string) ($uri ?? "")) > 0) {
        list($width, $height) = getimagesize($uri);

        $tArray = ["ImageH" => $height, "ImageW" => $width];
        $db->where("User", $user);
        $rowBook = $db->update("Callbook", $tArray);
    } else {
        $tArray = ["ImageH" => 0, "ImageW" => 0];
        $db->where("User", $user);
        $row = $db->update("Callbook", $tArray);
    }
}

function fillGeo($user, $call, $db, $which)
{
    $dRoot = "/var/www/html";
    require_once $dRoot . "/programs/GetCountyFromZIPFunc.php";
    $db->where("User", $user);
    $rowBook = $db->getOne("Callbook");
    if (
        strlen((string) ($rowBook["His_Zip"] ?? "")) > 0 &&
        ($rowBook["DXCC"] == 291 ||
            $rowBook["DXCC"] == 6 ||
            $rowBook["DXCC"] == 110 ||
            $rowBook["DXCC"] == 202)
    ) {
        $countyInfo = getCountyInfo($rowBook["His_Zip"]);

        if (strlen((string) ($countyInfo ?? "")) > 15) {
            $aCountyInfo = explode("|", $countyInfo);
            if ($which == "FCC") {
                $tArray = [
                    "His_County" => $aCountyInfo[0],
                    "His_Latitude" => $aCountyInfo[1],
                    "His_Longitude" => $aCountyInfo[2],
                    "His_Grid" => $aCountyInfo[3],
                    "His_FIPS" => $aCountyInfo[4],
                    "His_Entity" => $aCountyInfo[5],
                    "His_Continent" => $aCountyInfo[6],
                    "CQZone" => $aCountyInfo[7],
                    "ITUZone" => $aCountyInfo[8],
                    "DXCC" => $aCountyInfo[9],
                    "His_Section" => $aCountyInfo[10],
                    //				'WPX_Prefix'=>$wpx
                ];
            } else {
                $tArray = [
                    "His_County" => $aCountyInfo[0],
                    "His_Grid" => $aCountyInfo[3],
                    "His_FIPS" => $aCountyInfo[4],
                    "His_Entity" => $aCountyInfo[5],
                    "His_Continent" => $aCountyInfo[6],
                    "CQZone" => $aCountyInfo[7],
                    "ITUZone" => $aCountyInfo[8],
                    "DXCC" => $aCountyInfo[9],
                    "His_Section" => $aCountyInfo[10],
                ];
            }
            $db->where("User", $user);
            $row = $db->update("Callbook", $tArray);
        }
    }
    return "OK";
}

function fillRadio($user, $db)
{
    $dRoot = "/var/www/html";
    require_once $dRoot . "/programs/GetInterfaceFunc.php";
    require_once $dRoot . "/programs/GetSettingsFunc.php";
    $db->where("uID", $user);
    $rowUser = $db->getOne("Users");
    if (!$rowUser) {
        return;
    }
    $tMyRadio = $rowUser["SelectedRadio"];
    $db->where("User", $user);
    $rowBook = $db->getOne("Callbook");
    $pOut = getRadioData($tMyRadio, "powerOut", "RadioInterface");
    $model = GetField($tMyRadio, "Model", "MySettings");
    $name = GetField($tMyRadio, "RadioName", "MySettings");
    $newRow = [];
    if (strlen((string) ($rowBook["Transmitter"] ?? "")) == 0) {
        if (strlen((string) ($name ?? "")) == 0) {
            $newRow["Transmitter"] = $model;
            $newRow["Receiver"] = $model;
        } else {
            $newRow["Transmitter"] = $name;
            $newRow["Receiver"] = $name;
        }
    }
    if (strlen((string) ($rowBook["TX_Power"] ?? "")) == 0) {
        $newRow["TX_Power"] = $pOut;
    }
    if (count($newRow) > 0) {
        $db->where("User", $user);
        $row = $db->update("Callbook", $newRow);
    }
    return "OK";
}

function getQRZDXCC($key, $call, $user, $qrzUser, $db)
{
    $url = "https://xmldata.qrz.com/xml/current";
    $data = ["s" => $key, "dxcc" => $call];
    $options = [
        "http" => [
            "header" => "Content-type: application/x-www-form-urlencoded\r\n",
            "method" => "POST",
            "content" => http_build_query($data),
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === false) {
    }

    $xml = simplexml_load_string($result);
    if (strlen((string) (string ?? ""))) {
        $db->where("User", $user);
        $tArray = ["qrzError" => "DXCC" . (string) $xml->Session->Error];
        $row = $db->update("Callbook", $tArray);
        return (string) $xml->Session->Error;
    } else {
        $tArray = [
            "DXCC" => (string) $xml->dxcc,
            "His_Continent" => (string) $xml->continent,
            "His_Zip" => (string) $xml->zip,
            "CQZone" => (string) $xml->cqzone,
            "ITUZone" => (string) $xml->ituzone,
        ];
    }
    $db->where("User", $user);
    $row = $db->update("Callbook", $tArray);
    return "OK";
}

function getBio($key, $call, $user, $qrzUser, $db)
{
    $url = "https://xmldata.qrz.com/xml/current";
    $data = ["s" => $key, "html" => $call];
    $options = [
        "http" => [
            "header" => "Content-type: application/x-www-form-urlencoded\r\n",
            "method" => "POST",
            "content" => http_build_query($data),
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === false) {
    }
    $tArray = [
        "His_Bio" => preg_replace('/[^\x20-\x7E\x09\x0A\x0D]/', "", $result),
    ];
    $db->where("User", $user);
    $row = $db->update("Callbook", $tArray);
    return "OK";
}

function getHamQTHData($call, $user, $db)
{
    $db->where("uID", $user);
    $row = $db->getOne("Users");
    $hamqthUser = $row["hamqthUser"] ?? "";
    $hamqthPWD = $row["hamqthPWD"] ?? "";
    if (strlen($hamqthUser) == 0 || strlen($hamqthPWD) == 0) {
        return "NO_CREDS";
    }
    // Step 1: authenticate
    $authUrl =
        "https://www.hamqth.com/xml.php?u=" .
        urlencode($hamqthUser) .
        "&p=" .
        urlencode($hamqthPWD) .
        "&prg=RigPi";
    $authXml = @file_get_contents($authUrl);
    if ($authXml === false) {
        return "FAIL";
    }
    $sessionId = "";
    if (preg_match("/<session_id>([^<]+)<\/session_id>/", $authXml, $m)) {
        $sessionId = trim($m[1]);
    }
    if (strlen($sessionId) == 0) {
        return "AUTH_FAIL";
    }
    // Step 2: lookup
    $lookupUrl =
        "https://www.hamqth.com/xml.php?id=" .
        urlencode($sessionId) .
        "&callsign=" .
        urlencode($call) .
        "&prg=RigPi";
    $xml = @file_get_contents($lookupUrl);
    if ($xml === false) {
        return "FAIL";
    }
    $grid = preg_match("/<grid>([^<]+)<\/grid>/", $xml, $m) ? trim($m[1]) : "";
    $nick = preg_match("/<nick>([^<]+)<\/nick>/", $xml, $m) ? trim($m[1]) : "";
    $name = strlen($nick)
        ? $nick
        : (preg_match("/<adr_name>([^<]+)<\/adr_name>/", $xml, $m)
            ? trim($m[1])
            : "");
    $country = preg_match("/<country>([^<]+)<\/country>/", $xml, $m)
        ? trim($m[1])
        : "";
    $addr1 = preg_match("/<adr_street1>([^<]+)<\/adr_street1>/", $xml, $m)
        ? trim($m[1])
        : "";
    $city = preg_match("/<adr_city>([^<]+)<\/adr_city>/", $xml, $m)
        ? trim($m[1])
        : "";
    $state = preg_match("/<us_state>([^<]+)<\/us_state>/", $xml, $m)
        ? trim($m[1])
        : "";
    $zip = preg_match("/<adr_zip>([^<]+)<\/adr_zip>/", $xml, $m)
        ? trim($m[1])
        : "";
    $email = preg_match("/<email>([^<]+)<\/email>/", $xml, $m)
        ? trim($m[1])
        : "";
    $url = preg_match("/<web>([^<]+)<\/web>/", $xml, $m) ? trim($m[1]) : "";
    $lotw = preg_match("/<lotw>([^<]+)<\/lotw>/", $xml, $m) ? trim($m[1]) : "";
    $image = preg_match("/<picture>([^<]+)<\/picture>/", $xml, $m)
        ? trim($m[1])
        : "";
    if (strlen($name) == 0 && strlen($country) == 0) {
        return "NOT_FOUND";
    }
    $wpx = getWPX($call);
    $tArray = [
        "Callsign" => $call,
        "His_Grid" => $grid,
        "His_Name" => $name,
        "His_Street" => $addr1,
        "His_City" => $city,
        "His_State" => $state,
        "His_QTH" => trim("$addr1, $city, $state $zip"),
        "His_Zip" => $zip,
        "His_Email" => $email,
        "His_URL" => $url,
        "His_Country" => $country,
        "LoTW" => $lotw == "Y" ? "1" : "0",
        "ImageURL" => $image,
        "Note" => "HamQTH",
        "WPX_Prefix" => $wpx,
    ];
    $db->where("User", $user);
    $db->update("Callbook", $tArray);
    fillDXCC($call, $user, $db);
    fillGeo($user, $call, $db, "QRZ");
    fillRadio($user, $db);
    fillDXDistance($user, $db);
    if (strlen($image) > 0) {
        getISize($user, $db);
    }
    // Restore source label after fillDXCC overwrites Note
    $db->where("User", $user);
    $db->update("Callbook", ["Note" => "HamQTH"]);
    return "OK";
}

function getFCCData($call, $user, $db)
{
    $db->addConnection("slave", [
        "host" => "localhost",
        "username" => "ham",
        "password" => "7388",
        "db" => "fcc_amateur",
    ]);
    //  $call='K2EE';
    $Callsign = $call;
    $db->where("callsign", $Callsign);
    $db->where("status", "A");
    $hd = $db->getOne("fcc_amateur.hd");

    if (!is_null($hd)) {
        $db->where("fccid", $hd["fccid"]);
        $am = $db->getOne("fcc_amateur.am");

        $licenseCode = strtoupper(trim($am["class"] ?? ""));

        $classNames = [
            "T" => "Technician",
            "G" => "General",
            "A" => "Advanced",
            "E" => "Amateur Extra",
            "N" => "Novice",
        ];

        $licenseClass = $classNames[$licenseCode] ?? $licenseCode;

        $db->where("fccid", $hd["fccid"]);
        $row = $db->getOne("fcc_amateur.en");
        $address1 = ucwords(strtolower($row["address1"]));
        $city = ucwords(strtolower($row["city"]));
        $state = $row["state"];
        $zip = substr($row["zip"], 0, 5);
        $name =
            ucwords(strtolower($row["first"])) .
            " " .
            ucwords(strtolower($row["middle"])) .
            " " .
            ucwords(strtolower($row["last"]));

        $tArray = [
            "Callsign" => $Callsign,
            "Aliases" => "",
            "DXCC" => 0,
            "His_Grid" => "",
            "His_Name" => $name,
            "Note" => "FCC",
            "His_Street" => $address1,
            "His_City" => $city,
            "His_State" => $state,
            "His_QTH" => $address1 . ", " . $city . ", " . $state . " " . $zip,
            "His_Email" => "",
            "His_URL" => "",
            "His_County" => "",
            "His_Country" => "US",
            "His_Continent" => "NA",
            "His_Zip" => $zip,
            "IOTA" => "",
            "CQZone" => "3, 4, 5",
            "ITUZone" => "4, 5, 6",
            "WPX_Prefix" => getWPX($Callsign),
            "Ten_Ten" => "",
            "VE_Province" => "",
            "LoTW" => "",
            "eQSL" => "",
            "mQSL" => "",
            "ClubLogQSL" => 0,
            "QRZQSL" => 0,
            "LicenseClass" => $licenseClass,
            "ImageURL" => "",
            "TimeZone" => "",
            "DST" => "",
            "GMTOffset" => "",
            "QSLMgr" => "",
            "His_Bio" => "",
        ];
        $db->where("User", $user);
        $row = $db->update("Callbook", $tArray);
        fillDXCC($call, $user, $db);
        fillGeo($user, $call, $db, "FCC");
        fillRadio($user, $db);
        return "OK";
    } else {
        fillDXCC($call, $user, $db);
    }
}

function getWPX($call)
{
    $aCall = explode("/", $call);
    if (count($aCall) > 1) {
        if (strlen((string) ($aCall[0] > strlen($aCall[1] ?? "")))) {
            $call = $aCall[0];
        } else {
            $call = $aCall[1];
        }
    }
    $len = strlen((string) ($call ?? ""));
    $find = $call;
    $callWPX = "";
    if (strlen((string) ($find ?? "")) > 2) {
        for ($i = 0; $i <= $len; $i++) {
            if (ord(substr($find, -1)) > 47 && ord(substr($find, -1)) < 58) {
                $callWPX = $find;
                break;
            }
            $find = substr($find, 0, strlen((string) ($find ?? "")) - 1);
            $callWPX = $callWPX . $find . "|";
        }
    }
    return $callWPX;
}

function fillEntity($dxcc, $user, $db)
{
    $db->where("DXCC", $dxcc);
    $row = $db->getOne("Prefixes");
    if (!is_null($row)) {
        $entity = $row["Country"];
        $tArray = [
            "His_Entity" => $entity,
            "Abbreviation" => $row["Abbreviation"],
        ];
        //	print_r($tArray);
        $db->where("User", $user);
        $row = $db->update("Callbook", $tArray);
    } else {
        $tArray = ["His_Entity" => 0, "Abbreviation" => ""];
        $row = $db->update("Callbook", $tArray);
    }
    return "OK";
}

function fillMyUserDefaults($user, $db)
{
    $db->where("uID", $user);
    $rowUser = $db->getOne("Users");
    $db->where("User", $user);
    $rowBook = $db->getOne("Callbook");
    $newRow = [];
    if ($rowUser == 1) {
        foreach ($rowUser as $key => $value) {
            if (array_key_exists($key, $rowBook)) {
                if (
                    $rowBook[$key] == "" &&
                    strlen((string) ($value ?? "")) > 0
                ) {
                    $newRow[$key] = $value;
                }
            }
        }
    }
    if (count($newRow) > 0) {
        $db->where("User", $user);
        $row = $db->update("Callbook", $newRow);
    }
    return "OK";
}

function fillDXCC($call, $user, $db)
{
    //  $call='VE3KZE';
    $dRoot = "/var/www/html";
    require_once $dRoot . "/programs/GetDXCC.php";
    $response = GetLocationData($call);
    // echo $response . "<p>";
    $aResponse = explode("|", $response);
    //	return $Callsign . "|" . $DXCC . "|" . $country . "|" . $latitude . "|" . $longitude . "|" . $time_offset . "|" . $cont . "|" . $abbr . "|" . $itu . "|" . $cq . "|";
    $DXCC = $aResponse[1];
    //  echo "DXCC $DXCC <p>";
    $tArray = [
        "Callsign" => $call,
        "His_Country" => $aResponse[2],
        "His_Latitude" => $aResponse[3],
        "His_Longitude" => $aResponse[4],
        "His_Continent" => $aResponse[6],
        "Abbreviation" => $aResponse[7],
        "ITUZone" => $aResponse[8],
        "CQZone" => $aResponse[9],
        "Note" => "RigPi, all numbers and map center are approximate",
        "WPX_Prefix" => getWPX($call),
        "DXCC" => $DXCC,
    ];
    //  print_r($tArray);
    $db->where("User", $user);

    $row = $db->update("Callbook", $tArray);
    fillEntity($DXCC, $user, $db);
    //  echo "<p>about to fill $DXCC $user<p>";

    return "OK";
}

function fillDXDistance($user, $db)
{
    $dRoot = "/var/www/html";
    require_once $dRoot . "/programs/GetDistanceFunc.php";
    $db->where("User", $user);
    $rowDist = $db->getOne("Callbook");
    $dxlat = $rowDist["His_Latitude"];
    $dxlon = $rowDist["His_Longitude"];
    $db->where("uID", $user);
    $rowDist = $db->getOne("Users");
    $mylat = $rowDist["My_Latitude"];
    $mylon = $rowDist["My_Longitude"];
    $dist = getDistance($dxlat, $dxlon, $mylat, $mylon);
    $aDist = explode("|", $dist);
    $tArray = [
        "His_Distance_Mi" => $aDist[1],
        "His_Distance_KM" => $aDist[2],
        "Beam_Heading" => $aDist[3],
    ];
    $db->where("User", $user);
    $row = $db->update("Callbook", $tArray);
    return "OK";
}

?>
