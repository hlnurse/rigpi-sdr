<?php
session_start();
$_savedUN = $_SESSION["myUsername"] ?? ""; // save before reset
session_reset();
$dRoot = "/var/www/html";
require_once $dRoot . "/programs/GetUserFieldFunc.php";
require_once $dRoot . "/programs/GetSettingsFunc.php";
require_once $dRoot . "/classes/Membership.php";
$membership = new Membership();

// Load check
$loadAvg = sys_getloadavg();
$highLoad = $loadAvg[0] >= 3.0; //adjust 3.0 to desired limit
$blockLogin = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST["username"] ?? "";
    $username = strtolower($username);
    $isStatusPost = !empty($_POST["status"]);
    // Validate username format (skip on logout/reboot/shutdown POSTs)
    if (!$isStatusPost) {
        if (!preg_match('/^[a-z0-9]{1,20}$/', $username)) {
            $errorMessage = "Invalid username or password!";
            $username = "";
        } else {
            // Check username exists in DB
            $dbUID = getUserField($username, "uID");
            if (empty($dbUID)) {
                $errorMessage = "Invalid username or password!";
                $username = "";
            }
        }
    }
    $password = trim($_POST["password"]) ?? "";
    $password1 = strlen($password) > 0 ? md5($password) : "";
    $_SESSION["myUsername"] = $username;
    if (!empty($username)) {
        $_SESSION["myRadio"] = getUserField($username, "SelectedRadio");
        $_SESSION["myRadioName"] = GetField(
            $_SESSION["myRadio"],
            "RadioName",
            "MySettings"
        );
    }
    $instance = "1234" . ($_SESSION["myRadio"] ?? "");
    $_SESSION["myInstance"] = $instance;

    $tMyUN = $username ?: $_savedUN;
    $validUsername = strtolower($username);
    if (strlen((string) ($password ?? "")) > 0) {
        $validPassword = getUserField($username, "Password");
    } else {
        $validPassword = getUserField($username, "Password");
        $password1 = "";
    }
    if (!empty($_POST["status"]) && trim($tMyUN) !== "") {
        $un = $tMyUN;
        $stat = $_POST["status"];
        if ($stat == "loggedout") {
            $_POST["username"] = "";
            session_destroy();
            $membership->log_User_Out($un);
        }
        if ($stat == "reboot") {
            $membership->Reboot_User($un);
            include "./programs/reboot.php";
        }
        if ($stat == "shutdown") {
            $membership->PowerDown_User_Out($un);
        }
    } elseif (strlen((string) ($username ?? "")) > 0) {
        $isAdmin = getUserField($username, "uID") == 1;
        if ($highLoad && !$isAdmin) {
            $blockLogin = true;
        } else {
            // Validate and resolve callsign
            $callsignEntry = strtoupper(trim($_POST["callsign"] ?? ""));
            if (empty($callsignEntry)) {
                $callsignEntry = strtoupper(getUserField($username, "MyCall"));
            } else {
                if (
                    !preg_match(
                        '/^[A-Z0-9]{1,3}[0-9][A-Z]{1,4}(\/[A-Z0-9]+)?$/',
                        $callsignEntry
                    )
                ) {
                    $errorMessage =
                        "Invalid callsign format: " .
                        htmlspecialchars($callsignEntry);
                    $blockLogin = true;
                }
            }
            // Final safety net — never pass null to check_user
            if (empty($callsignEntry)) {
                $callsignEntry = strtoupper($username);
            }
            // Only pass callsignEntry through as a guest-override if it
            // actually differs from the account's own registered callsign —
            // otherwise notify_visitor() incorrectly labels normal logins
            // (e.g. admin logging in as themselves) as "guest/<username>".
            $dbCallForLogin = strtoupper(getUserField($username, "MyCall"));
            $callOverrideForLogin =
                $callsignEntry && $callsignEntry !== $dbCallForLogin
                    ? $callsignEntry
                    : "";
            if (!$blockLogin) {
                $response1 = $membership->check_user(
                    $username,
                    $password1,
                    $callOverrideForLogin
                );
                if (!isset($response1)) {
                    $userOK = "0";
                    $last = filemtime("/var/www/html/my/rc_start.txt");
                    $elapsed = time() - $last;
                    if ($elapsed < 10) {
                        while ($elapsed < 10) {
                            sleep(10);
                            $last = filemtime("/var/www/html/my/rc_start.txt");
                            $elapsed = time() - $last;
                        }
                    }
                } else {
                    if ($response1 == "NG") {
                        $userOK = "1";
                    }
                }
                // Check if credentials are correct
                if (
                    $tMyUN === $validUsername &&
                    $password1 === $validPassword
                ) {
                    $_SESSION["myCall"] = $callsignEntry;
                    $dbCall = strtoupper(getUserField($username, "MyCall"));
                    $_SESSION["isGuest"] =
                        $callsignEntry && $callsignEntry !== $dbCall
                            ? "1"
                            : "0";
                    $_SESSION["myPort"] = 4534;
                    if (
                        strlen((string) ($_SESSION["myRadioName"] ?? "")) == 0
                    ) {
                        $errorMessage = "Please try again.";
                    }
                } else {
                    $errorMessage = "Invalid username or password!";
                }
            }
        }
    }
}
?>
<script src="/Bootstrap/jquery.min.js"></script>
<script>
$(document).ready(function() {
    $("#username").focus();
    $.post('/programs/checkReser.php', function(response){
        var tID = document.getElementById('reservations');
        if(tID) tID.innerHTML = response;
    });
    $.post('/programs/version.php', function(response){
        $("#version").html("RigPi&trade; Station Server, v " + response);
    });
});
</script>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RigPi SDR Login</title>
    <link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: #060d06;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            font-family: 'Share Tech Mono', monospace; overflow-x: hidden;
        }
        #wf-canvas {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0.30; z-index: 0;
        }
        body::after {
            content: ''; position: fixed; inset: 0;
            background: radial-gradient(ellipse at center, transparent 30%, #060d06 100%);
            z-index: 1; pointer-events: none;
        }
        .login-wrap { position: relative; z-index: 2; width: 100%; max-width: 360px; padding: 1rem; }
        .login-card {
            background: rgba(8,20,8,0.88); border: 0.5px solid #1e3e1e; border-radius: 12px;
            padding: 2rem 2rem 1.5rem;
            box-shadow: 0 0 40px rgba(93,202,165,0.08), 0 8px 32px rgba(0,0,0,0.7);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        }
        .login-header { text-align: center; margin-bottom: 1.5rem; }
        .login-header img {
            width: 36px; height: 36px; margin-bottom: 6px;
            filter: drop-shadow(0 0 8px rgba(93,202,165,0.5));
        }
        .login-title {
            display: block; font-size: 22px; letter-spacing: 6px; color: #5dcaa5;
            text-transform: uppercase; text-shadow: 0 0 16px rgba(93,202,165,0.4); margin: 2px 0 0;
        }
        .login-sub { display: block; font-size: 11px; letter-spacing: 3px; color: #b0d8c8; text-transform: uppercase; margin-top: 2px; }
        .form-label { font-size: 13px; letter-spacing: 2px; color: #d0f0e0; text-transform: uppercase; margin-bottom: 3px; display: block; }
        .form-control {
            background: rgba(0,0,0,0.5) !important; border: 0.5px solid #1e3e1e !important;
            border-radius: 4px !important; color: #9FE1CB !important;
            font-family: 'Share Tech Mono', monospace !important; font-size: 13px !important;
            padding: 6px 10px !important; height: 34px !important;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus {
            outline: none !important; border-color: #3a7a5a !important;
            box-shadow: 0 0 0 2px rgba(93,202,165,0.12) !important;
            background: rgba(0,0,0,0.7) !important;
        }
        .form-control::placeholder { color: #7ab8a0 !important; }
        .form-control[name="callsign"] { text-transform: uppercase; }
        .form-group { margin-bottom: 12px; }
        .btn-login {
            width: 100%; margin-top: 6px; padding: 8px;
            background: linear-gradient(135deg, #0d2a1a, #1a4a2a);
            border: 0.5px solid #2a6a4a; border-radius: 20px; color: #5dcaa5;
            font-family: 'Share Tech Mono', monospace; font-size: 13px;
            letter-spacing: 3px; text-transform: uppercase; cursor: pointer; transition: all .2s;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #1a4a2a, #2a6a4a);
            box-shadow: 0 0 16px rgba(93,202,165,0.2); color: #9FE1CB;
        }
        .alert-danger {
            background: rgba(80,10,10,0.7) !important; border: 0.5px solid #6a1a1a !important;
            color: #e18080 !important; border-radius: 4px !important;
            font-size: 12px !important; padding: 8px 12px !important; margin-bottom: 12px;
        }
        .alert-info {
            background: rgba(0,20,30,0.7) !important; border: 0.5px solid #1a3a4a !important;
            color: #7ab8d8 !important; border-radius: 4px !important;
            font-size: 11px !important; padding: 6px 10px !important; margin-bottom: 12px;
        }
        .alert-info strong { color: #9FE1CB; }
        .login-footer { text-align: center; margin-top: 14px; }
        .login-footer a { color: #9FE1CB; font-size: 12px; letter-spacing: 1px; text-decoration: none; transition: color .2s; }
        .login-footer a:hover { color: #5dcaa5; }
        .below-card { position: relative; z-index: 2; text-align: center; margin-top: 20px; color: #b0d8c8; font-size: 12px; letter-spacing: 2px; }
        .below-card a { color: #9FE1CB; text-decoration: none; }
        .below-card a:hover { color: #5dcaa5; }
        #reservations { color: #a0c8b8; font-size: 12px; margin-bottom: 4px; }
        #version { color: #90b8a8; font-size: 12px; }

        .pw-wrap { position: relative; }
        .pw-wrap .form-control { padding-right: 34px !important; }
        .pw-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 0;
            color: #4a7a5a; font-size: 15px; line-height: 1;
            transition: color .2s;
        }
        .pw-toggle:hover { color: #5dcaa5; }
        .noselect { user-select: none; -webkit-user-select: none; cursor: default; }
    </style>
</head>
<body>
<canvas id="wf-canvas"></canvas>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-header noselect">
            <a href="https://www.rigpi.net" target="_blank" rel="noopener noreferrer">
                <img src="/Images/RigPiW.png" alt="RigPi">
            </a>
            <span class="login-title">RigPi SDR</span>
            <span class="login-sub">Station Server &mdash; W6HN</span>
        </div>
        <?php if (!empty($errorMessage)): ?>
            <div class="alert-danger"><?= htmlspecialchars(
                $errorMessage
            ) ?></div>
        <?php endif; ?>
        <?php
        $activeRows = $membership->getActiveUsers();
        if (!empty($activeRows)): ?>
            <div class="alert-info">
                <strong>Active sessions:</strong><br>
                <?php foreach ($activeRows as $ar): ?>
                &bull; <?= htmlspecialchars(
                    $ar["MyCall"] ?: $ar["Username"]
                ) ?> (<?= htmlspecialchars($ar["Username"]) ?>)<br>
                <?php endforeach; ?>
            </div>
        <?php endif;
        ?>
        <?php if (!empty($blockLogin) && empty($errorMessage)): ?>
            <div class="alert-danger" style="text-align:center;">
                <strong>RigPi is too busy for additional connections.</strong><br>
                Please try again later.<br>
                <span style="color:#5a3a3a;font-size:10px;">Load: <?= round(
                    $loadAvg[0],
                    2
                ) ?></span>
            </div>
        <?php else: ?>
            <form method="POST" action="/login.php">
                <div class="form-group">
                    <label class="form-label noselect" for="username">Username</label>
                    <input type="text" name="username" id="username" class="form-control" placeholder="username" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label class="form-label noselect" for="password">Password</label>
                    <div class="pw-wrap"><input type="password" name="password" id="password" class="form-control" placeholder="password if assigned" autocomplete="current-password"><button type="button" class="pw-toggle" tabindex="-1" aria-label="Show password"><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg></button></div>
                </div>
                <div class="form-group">
                    <label class="form-label noselect" for="callsign">Callsign <span style="color:#80b8a0;">(optional)</span></label>
                    <input type="text" name="callsign" id="callsign" class="form-control" placeholder="YOUR CALL" autocomplete="off">
                </div>
                <button type="submit" class="btn-login">&#x2192; Login</button>
            </form>
            <div class="login-footer">
                <a href="/requestAccount.php">Request an Account</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="below-card noselect">
    <div id="reservations"></div>
    <div id="version"></div>
    <div style="margin-top:8px;">RigPi crafted by Howard Nurse, W6HN</div>
    <div style="margin-top:4px;"><a href="https://www.rigpi.net">https://www.rigpi.net</a></div>
    <div style="margin-top:4px;font-style:italic;font-size:9px;color:#3a6a4a;">RigPi is a Trademark of Howard Nurse, W6HN</div>
</div>
<script src="./Bootstrap/popper.min.js"></script>
<script src="./Bootstrap/bootstrap.min.js"></script>
<script>
(function() {
    var canvas = document.getElementById('wf-canvas');
    var ctx = canvas.getContext('2d');
    var W, H, pixels;
    var COLORS = [[0,0,0],[0,8,4],[0,20,10],[0,45,25],[10,90,55],[30,150,100],[93,202,165],[180,235,215],[240,255,250]];
    function resize() { W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; pixels = new Uint8ClampedArray(W*H*4); }
    function lerp(a,b,t){ return a+(b-a)*t; }
    function colorAt(v) {
        var n=COLORS.length-1, idx=Math.min(v,1)*n, lo=Math.floor(idx), hi=Math.min(lo+1,n), t=idx-lo;
        var c0=COLORS[lo], c1=COLORS[hi];
        return [lerp(c0[0],c1[0],t)|0, lerp(c0[1],c1[1],t)|0, lerp(c0[2],c1[2],t)|0];
    }
    function makeSig() {
        var type = Math.random();
        if (type < 0.3) return { fc:Math.random(), bw:0.0008+Math.random()*0.002, amp:0.7+Math.random()*0.3, drift:(Math.random()-.5)*0.00005, flicker:Math.random()*Math.PI*2, type:'cw' };
        if (type < 0.6) return { fc:Math.random(), bw:0.004+Math.random()*0.008, amp:0.5+Math.random()*0.4, drift:(Math.random()-.5)*0.00003, flicker:Math.random()*Math.PI*2, type:'ssb' };
        return { fc:Math.random(), bw:0.012+Math.random()*0.02, amp:0.55+Math.random()*0.35, drift:(Math.random()-.5)*0.00002, flicker:Math.random()*Math.PI*2, type:'am' };
    }
    var signals = []; for(var i=0;i<12;i++) signals.push(makeSig());
    function sigValue(sig, f, ts) {
        var dist=Math.abs(f-sig.fc); if(dist>.5)dist=1-dist;
        var flk=0.88+0.12*Math.sin(ts*.002+sig.flicker);
        if(sig.type==='cw') return sig.amp*Math.exp(-dist*dist/(2*sig.bw*sig.bw))*flk;
        if(sig.type==='ssb') { var s=(f-sig.fc)>0?1.2:0.6; return sig.amp*Math.exp(-dist*dist/(2*(sig.bw*s)*(sig.bw*s)))*flk; }
        var carrier=Math.exp(-dist*dist/(2*0.001*0.001));
        var sb1=Math.abs(dist-sig.bw*0.5), sb2=Math.abs(dist-sig.bw*0.7);
        var sideband=0.5*(Math.exp(-sb1*sb1/(2*0.003*0.003))+Math.exp(-sb2*sb2/(2*0.003*0.003)));
        var body=Math.exp(-dist*dist/(2*(sig.bw*0.4)*(sig.bw*0.4)));
        return sig.amp*Math.max(carrier*0.9,body*0.5,sideband*0.6)*flk;
    }
    function tick(ts) {
        pixels.copyWithin(W*4, 0, W*(H-1)*4);
        for(var s=0;s<signals.length;s++){signals[s].fc+=signals[s].drift;if(signals[s].fc<0)signals[s].fc+=1;if(signals[s].fc>1)signals[s].fc-=1;}
        for(var x=0;x<W;x++){
            var f=x/W, v=0.02+Math.random()*0.04;
            for(var s=0;s<signals.length;s++) v=Math.max(v,sigValue(signals[s],f,ts));
            v=Math.min(1,v); var c=colorAt(v); var px=x*4;
            pixels[px]=c[0]; pixels[px+1]=c[1]; pixels[px+2]=c[2]; pixels[px+3]=255;
        }
        var id=ctx.createImageData(W,H); id.data.set(pixels); ctx.putImageData(id,0,0);
        requestAnimationFrame(tick);
    }
    window.addEventListener('resize', resize);
    resize();
    requestAnimationFrame(tick);
})();
</script>

<script>
document.querySelectorAll('.pw-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var inp = this.previousElementSibling;
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        this.innerHTML = show ? `<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'/><line x1='1' y1='1' x2='23' y2='23'/></svg>` : `<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/></svg>`;
    });
});
</script>
</body>
</html>
