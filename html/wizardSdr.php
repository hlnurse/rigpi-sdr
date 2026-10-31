<?php
session_start();
header("Cache-Control: no-store");
$dRoot = "/var/www/html";
$tUserName = (string) ($_SESSION["myUsername"] ?? "");
$tCall = (string) ($_SESSION["myCall"] ?? "");
$standalone = isset($_GET["standalone"]) && $_GET["standalone"] === "1";
if ($tUserName === "") {
    header("Location: /login.php");
    exit;
}

require_once $dRoot . "/classes/Membership.php";
$membership = new Membership();
$membership->confirm_Member($tUserName);
require_once $dRoot . "/classes/MysqliDb.php";
require $dRoot . "/programs/sqldata.php";
$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$db->where("Username", $tUserName);
$userRow = $db->getOne("Users");
$userID = max(1, (int) ($userRow["uID"] ?? 1));
$radioID = max(1, (int) ($userRow["SelectedRadio"] ?? $userID));
require_once $dRoot . "/programs/SdrInstanceFunc.php";
$instanceID = sdrInstanceForUser($userRow ?: []);
$savedReceiver = json_decode((string) @file_get_contents("/home/pi/sdr_web/config/instance_" . (8000 + $instanceID) . ".json"), true);
$savedNoSdr = is_array($savedReceiver) && ($savedReceiver["device_driver"] ?? "") === "fake";
$availabilityAccounts = sdrInstanceOccupants($db);
$availabilityInstances = sdrInstancesRunning();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($tCall); ?> <?php echo $standalone ? "SDR Devices" : "Express SDR Setup"; ?></title>
    <link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
    <script src="/Bootstrap/jquery.min.js"></script>
    <script defer src="/awe/js/all.js"></script>
    <link href="/awe/css/all.css" rel="stylesheet">
    <link href="/awe/css/fontawesome.css" rel="stylesheet">
    <link href="/awe/css/solid.css" rel="stylesheet">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <?php require $dRoot . "/includes/styles.php"; ?>
    <style>
        .sdr-wizard { margin: 0; }
        .sdr-wizard-card { max-width: 920px; margin: 0 auto 60px; padding: 0 15px 18px; color: #fff; }
        .sdr-device { display: block; border: 2px solid #c8d2d8; border-radius: 7px; padding: 14px 16px; margin-bottom: 10px; background: #fff; color: #23313a; cursor: pointer; }
        .sdr-device:hover { border-color: #36a9c4; }
        .sdr-device.selected { border-color: #159dba; background: #eaf8fb; box-shadow: 0 0 0 2px rgba(21,157,186,.12); }
        .sdr-device input { margin-right: 10px; }
        .sdr-choice-heading { font-size: 20px; }
        .sdr-device-title { font-size: 1.05rem; font-weight: 600; }
        .sdr-device-detail { color: #5e6d75; margin: 4px 0 0 27px; overflow-wrap: anywhere; }
        .sdr-badge { display: inline-block; border-radius: 10px; padding: 2px 8px; margin-left: 8px; font-size: .75rem; font-weight: 600; background: #dce7eb; }
        .sdr-badge.active { background: #c9efd7; color: #1d6937; }
        .wizard-status { min-height: 26px; margin: 12px 0; color: #fff; }
    </style>
</head>
<body class="body-black-scroll">
<?php require $dRoot . "/includes/header.php"; ?>
<?php require $dRoot . "/includes/modal.txt"; ?>
<iframe id="elmerSdrFrame" hidden aria-hidden="true" tabindex="-1" title="Receive audio connection" src="about:blank"></iframe>
<script id="sdrDevicesHeaderConfig" type="application/json"><?php echo json_encode([
    "user" => strtolower($tUserName), "userId" => $userID, "radio" => $radioID,
    "call" => $tCall, "base" => "/sdr" . $instanceID
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<div class="container-fluid sdr-wizard">
    <div class="row" style="margin-bottom:10px;">
        <div class="col-12 col-lg-4 btn-padding"></div>
        <div class="col-12 col-lg-4 text-center">
            <span class="label label-success text-white pageLabel" style="margin-top:10px;"><?php echo $standalone ? "SDR Devices" : "Express SDR Setup"; ?></span>
            <?php if (!$standalone) { ?><br><span class="text-white-small">Step 3 of 3</span><?php } ?>
        </div>
        <div class="col-12 col-lg-4 btn-padding">
            <?php if ($standalone) { ?>
            <a class="btn btn-color" href="/tuner.php">&lt; BACK</a>
            <?php } else { ?>
            <form method="post" action="/wizardSettings.php" style="display:inline; margin:0;">
                <input type="hidden" name="c" value="<?php echo htmlspecialchars($tCall, ENT_QUOTES); ?>">
                <input type="hidden" name="x" value="<?php echo htmlspecialchars($tUserName, ENT_QUOTES); ?>">
                <button class="btn btn-color" type="submit">&lt; BACK</button>
            </form>
            <?php } ?>
            <button id="finish" class="btn btn-color" type="button" disabled>FINISH</button>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-12 col-lg-2 text-spacer"></div>
        <div class="col-12 col-lg-8 text-spacer text-white">
            <div class="row">
                <div class="col text-center">
                    RigPi scans USB receivers and the local network for supported SDR devices.<br>
                    Select one device to use first. You can change devices later in the SDR panel.
                </div>
            </div>
            <div class="row">
                <div class="col-12 text-spacer text-white-small">
                    <h4 class="sdr-choice-heading">Select the SDR to use</h4>
                    <div class="row align-items-center">
                        <div class="col-12 col-sm-9">
                            <div id="scanStatus" class="wizard-status" role="status">Scanning for SDR devices…</div>
                        </div>
                        <div class="col-12 col-sm-3 text-sm-right">
                            <button id="rescan" class="btn btn-color" type="button">RESCAN</button>
                        </div>
                    </div>
                    <div id="deviceList" aria-live="polite"></div>
                    <div id="noDevices" class="alert alert-secondary" style="display:none;">
                        No supported SDR devices were found. Check USB or network connections and try Rescan, or choose No SDR.
                    </div>
                    <div id="sdrplayInfo" class="alert alert-info" style="display:none;" role="status"></div>
                    <div id="selectionError" class="alert alert-danger" style="display:none;" role="alert"></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-2 text-spacer"></div>
    </div>
    <hr>
<?php require $dRoot . "/includes/footer.php"; ?>
<script id="sdrAvailabilityConfig" type="application/json"><?php echo json_encode([
    "base" => "/sdr" . $instanceID, "accounts" => (object)$availabilityAccounts,
    "instances" => $availabilityInstances
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="/js/sdr_device_availability.js?v=20261031-1"></script>
<script>
(function () {
    "use strict";
    const base = <?php echo json_encode("/sdr" . $instanceID); ?>;
    const savedNoSdr = <?php echo json_encode($savedNoSdr); ?>;
    const user = <?php echo json_encode(strtolower($tUserName)); ?>;
    const radio = <?php echo json_encode($radioID); ?>;
    const call = <?php echo json_encode($tCall); ?>;
    const list = document.getElementById("deviceList");
    const scanStatus = document.getElementById("scanStatus");
    const noDevices = document.getElementById("noDevices");
    const sdrplayInfo = document.getElementById("sdrplayInfo");
    const errorBox = document.getElementById("selectionError");
    const finish = document.getElementById("finish");
    let devices = [];
    let selectedIndex = -1;
    let liveState = {};

    function formatFooterFrequency(value) {
        const hz = Number(value);
        if (!Number.isFinite(hz) || hz <= 0) return "";
        if (hz >= 1000000) {
            return (hz / 1000000).toFixed(6).replace(/0+$/, "").replace(/\.$/, "") + " MHz";
        }
        return (hz / 1000).toFixed(3).replace(/0+$/, "").replace(/\.$/, "") + " kHz";
    }

    async function updateFooter() {
        const panel1 = document.getElementById("fPanel1");
        const panel2 = document.getElementById("fPanel2");
        const panel3 = document.getElementById("fPanel3");
        const panel4 = document.getElementById("fPanel4");
        const panel5 = document.getElementById("fPanel5");
        const now = new Date();
        panel5.textContent = String(now.getUTCHours()).padStart(2, "0") + ":" + String(now.getUTCMinutes()).padStart(2, "0") + "z";
        panel4.textContent = "User: " + call + " (" + (typeof tIsGuest !== "undefined" && tIsGuest ? "guest/" : "") + user + ")";
        try {
            const body = new URLSearchParams({radio: String(radio), un: user, myCall: call});
            const response = await fetch("/programs/GetInterfaceIn.php", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
                body: body.toString(),
                cache: "no-store"
            });
            if (!response.ok) throw new Error("Footer radio status failed.");
            const fields = (await response.text()).split("\x60");
            const main = formatFooterFrequency(fields[0]);
            const sub = formatFooterFrequency(fields[2]);
            let mode = fields[3] || "";
            if (mode === "PKTUSB") mode = "USB-D";
            if (mode === "PKTLSB") mode = "LSB-D";
            if (!main) {
                panel1.innerHTML = "&nbsp;No Radio";
                panel1.style.backgroundColor = "red";
                panel2.textContent = "";
                panel3.textContent = "";
            } else {
                panel1.textContent = "Main: " + main;
                panel1.style.backgroundColor = "black";
                panel2.textContent = String(fields[1]) === "1" && sub ? "Sub: " + sub : "";
                panel3.textContent = "Mode: " + mode + (fields[17] ? " - BW: " + fields[17] : "");
            }
        } catch (error) {
            panel1.innerHTML = "&nbsp;No Radio";
            panel1.style.backgroundColor = "red";
            panel2.textContent = "";
            panel3.textContent = "";
        }
    }

    function capability(driver) {
        return driver === "hermeslite" || driver === "hackrf" ? "Receive and transmit" : "Receive only";
    }

    function detail(device) {
        if (device.driver === "none") return "Finish setup without starting an SDR.";
        const parts = [capability(device.driver)];
        if (device.driver === "sdrconnect") parts.push("via SDRConnect Headless");
        if (device.serial) parts.push(device.driver === "hermeslite" ? "IP " + device.serial : device.driver === "sdrconnect" ? "Receiver " + device.serial : "ID " + device.serial);
        parts.push(SdrDeviceAvailability.status(device));
        return parts.join(" · ");
    }

    function render() {
        list.innerHTML = "";
        noDevices.style.display = devices.some(function (device) { return device.driver !== "none"; }) ? "none" : "block";
        finish.disabled = selectedIndex < 0;
        devices.forEach(function (device, index) {
            const label = document.createElement("label");
            label.className = "sdr-device" + (index === selectedIndex ? " selected" : "");
            const input = document.createElement("input");
            input.type = "radio";
            input.name = "sdrDevice";
            input.checked = index === selectedIndex;
            input.addEventListener("change", function () {
                selectedIndex = index;
                render();
            });
            const title = document.createElement("span");
            title.className = "sdr-device-title";
            title.textContent = device.label || device.driver || "SDR";
            label.appendChild(input);
            label.appendChild(title);
            if (device.current) {
                const badge = document.createElement("span");
                badge.className = "sdr-badge active";
                badge.textContent = "Currently selected";
                label.appendChild(badge);
            }
            const meta = document.createElement("div");
            meta.className = "sdr-device-detail";
            meta.textContent = detail(device);
            label.appendChild(meta);
            list.appendChild(label);
        });
    }

    function showSdrplayStatus(status, receiverCount) {
        sdrplayInfo.style.display = "block";
        sdrplayInfo.className = "alert alert-info";
        if (!status) {
            sdrplayInfo.className = "alert alert-warning";
            sdrplayInfo.textContent = "SDRplay status could not be checked. You can try Rescan.";
        } else if (!status.installed) {
            sdrplayInfo.innerHTML = "Using an SDRplay receiver? First download and install <a href=\"https://www.sdrplay.com/sdrconnect/\" target=\"_blank\" rel=\"noopener\">SDRConnect for Linux ARM64</a>, which includes SDRConnect Headless. Then return here and select Rescan.";
        } else if (!status.running) {
            sdrplayInfo.className = "alert alert-warning";
            sdrplayInfo.textContent = "SDRConnect is installed but Headless is not running. Start SDRConnect Headless, then select Rescan.";
        } else if (!receiverCount) {
            sdrplayInfo.textContent = "SDRConnect Headless is running, but no SDRplay receiver was found. Connect or power on the receiver, then select Rescan.";
        } else {
            sdrplayInfo.style.display = "none";
        }
    }

    async function scan() {
        errorBox.style.display = "none";
        sdrplayInfo.style.display = "none";
        scanStatus.textContent = "Scanning for SDR devices…";
        finish.disabled = true;
        try {
            const stamp = "&_=" + Date.now();
            const results = await Promise.all([
                fetch(base + "/api/state?user=" + encodeURIComponent(user) + "&radio=" + radio + stamp, {cache: "no-store"}),
                fetch(base + "/api/sdrconn/devices?probe=1" + stamp, {cache: "no-store"}).catch(function () { return null; }),
                fetch(base + "/api/sdrconn/status?" + stamp.slice(1), {cache: "no-store"}).catch(function () { return null; })
            ]);
            if (!results[0].ok) throw new Error("The SDR service did not respond.");
            liveState = await results[0].json();
            const sdrplay = results[1] && results[1].ok ? await results[1].json() : null;
            const sdrconnectState = results[2] && results[2].ok ? await results[2].json() : {};
            devices = (liveState.available_devices || [])
                .filter(function (device) {
                    return device && device.driver && !["fake", "sdrplay", "sdrconnect"].includes(device.driver);
                })
                .map(function (device) {
                    device.current = device.driver === liveState.device_driver && (device.serial || "") === (liveState.device_serial || "");
                    return device;
                });
            const rspDevices = sdrplay && Array.isArray(sdrplay.devices)
                ? sdrplay.devices.filter(function (name) { return name && !/^IQ File$/i.test(name); })
                : [];
            rspDevices.forEach(function (name) {
                devices.push({
                    driver: "sdrconnect", serial: name, label: "SDRplay " + name,
                    current: Boolean(sdrconnectState.connected) && (!sdrconnectState.device || sdrconnectState.device === name)
                });
            });
            const sdrCount = devices.length;
            showSdrplayStatus(sdrplay, rspDevices.length);
            const noSdrCurrent = liveState.device_driver === "fake" &&
                !liveState.owrx_active && !liveState.kiwi_ok && !liveState.use_kiwi &&
                !liveState.sdrconn_active && !sdrconnectState.connected;
            devices.push({driver: "none", serial: "", label: "No SDR", current: noSdrCurrent});
            selectedIndex = devices.findIndex(function (device) { return device.current; });
            if (selectedIndex < 0 && sdrCount === 1) selectedIndex = 0;
            scanStatus.textContent = sdrCount
                ? sdrCount + (sdrCount === 1 ? " SDR device found." : " SDR devices found.")
                : "No SDR devices found.";
            await SdrDeviceAvailability.refresh();
            render();
        } catch (error) {
            devices = [{driver: "none", serial: "", label: "No SDR", current: savedNoSdr}];
            selectedIndex = savedNoSdr ? 0 : -1;
            scanStatus.textContent = "Unable to scan for SDR devices.";
            errorBox.textContent = error.message || String(error);
            errorBox.style.display = "block";
            render();
        }
    }

    function openSdr(device) {
        if (device && device.driver === "none") {
            window.location.href = "/tuner.php";
            return;
        }
        const setup = device && device.driver === "hermeslite" ? "hermes" : device && device.driver === "sdrconnect" ? "sdrconnect" : "radio";
        window.location.href = "/sdr.php?setup=" + setup;
    }

    document.getElementById("rescan").addEventListener("click", scan);
    finish.addEventListener("click", async function () {
        if (selectedIndex < 0) return;
        const device = devices[selectedIndex];
        errorBox.style.display = "none";
        finish.disabled = true;
        scanStatus.textContent = "Starting " + (device.label || device.driver) + "…";
        if (device.current) {
            openSdr(device);
            return;
        }
        try {
            let response;
            if (device.driver === "sdrconnect") {
                response = await fetch(base + "/api/sdrconn/connect?user=" + encodeURIComponent(user), {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify({
                        freq_hz: Math.round(Number(liveState.center_freq)) || 14074000,
                        mode: liveState.mode || "USB",
                        device: device.serial || null,
                        sample_rate: 2000000
                    })
                });
            } else {
                response = await fetch(base + "/api/device", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify({driver: device.driver === "none" ? "fake" : device.driver, serial: device.serial || "", user: user})
                });
            }
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || "The SDR could not be selected.");
            openSdr(device);
        } catch (error) {
            errorBox.textContent = error.message || String(error);
            errorBox.style.display = "block";
            scanStatus.textContent = "Select an SDR to use.";
            finish.disabled = false;
        }
    });

    updateFooter();
    setInterval(updateFooter, 1000);
    scan();
    let availabilityRefresh = false;
    const availabilityTimer = setInterval(async function () {
        if (document.hidden || availabilityRefresh || finish.disabled) return;
        availabilityRefresh = true;
        const before = devices.map(detail).join("|");
        try {
            await SdrDeviceAvailability.refresh();
            if (devices.map(detail).join("|") !== before) render();
        } finally { availabilityRefresh = false; }
    }, 15000);
    window.addEventListener("pagehide", function () { clearInterval(availabilityTimer); }, {once:true});
}());
</script>
<script src="/Bootstrap/popper.min.js"></script>
<script src="/Bootstrap/bootstrap.min.js"></script>
<script defer src="/js/sdr_devices_header.js?v=20261031-header1"></script>
</body>
</html>
