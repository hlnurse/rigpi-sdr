<?php
// The master owns navigation and audio; Tuner remains a protected child page.
session_start();
if (!isset($_SESSION["myUsername"])) {
    header("Location: /login.php");
    exit();
}
session_write_close();
$shellTunerUrl = '/tuner.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
header('Cache-Control: no-store');
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe') {
    header('Location: ' . $shellTunerUrl, true, 307);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="shell-starting">
<head>
<script>
// Compatibility for old redirects in browsers without Fetch Metadata headers.
if (window !== window.top) {
    window.location.replace(<?php echo json_encode($shellTunerUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
}
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RigPi</title>

<style>

#rigpiShellLoading {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 10000;
    place-content: center;
    justify-items: center;
    gap: 14px;
    color: #e3f2fd;
    background: #444;
    font: 400 18px/1.4 -apple-system, system-ui, "Segoe UI", sans-serif;
}
.shell-starting #rigpiShellLoading { display: grid; }
#rigpiShellProgress {
    width: min(280px, 70vw);
    height: 10px;
    overflow: hidden;
    border-radius: 6px;
    background: #292929;
    border: 1px solid #707070;
}
#rigpiShellProgressFill {
    display: block;
    width: 0;
    height: 100%;
    border-radius: inherit;
    background: #70bfff;
    transition: width 180ms ease-out;
}
@media (prefers-reduced-motion: reduce) {
    #rigpiShellProgressFill { transition: none; }
}

html, body { background-color: #444; }
.shell-starting #rigpiContent, .shell-starting #rigpiShellNav { visibility: hidden; }

html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
}

body {
    display: flex;
    flex-direction: column;
}

#rigpiShellNav {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 0;
    padding: 8px 16px;
    overflow-x: auto;
    background: #e3f2fd;
    color: #212529;
    font: 400 16px/24px -apple-system, system-ui, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
}

#rigpiShellNav .shell-logo {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    min-height: 40px;
    margin-right: 16px;
}
#rigpiShellNav .shell-logo img { display: block; }
#rigpiShellNav .brand { text-decoration: none; }
#rigpiShellNav .shell-logo:focus-visible,
#rigpiShellNav .brand:focus-visible {
    outline: 2px solid #005fcc;
    outline-offset: 2px;
}

#rigpiShellNav .brand {
    flex-shrink: 0;
    margin-right: 16px;
    padding: 5px 0;
    color: #f00;
    font-size: 20px;
    line-height: 30px;
    font-weight: 400;
}

#rigpiShellNav button,
#rigpiShellNav summary {
    box-sizing: border-box;
    flex-shrink: 0;
    min-height: 40px;
    padding: 8px;
    border: 0;
    border-radius: 4px;
    background: transparent;
    color: rgba(0, 0, 0, 0.5);
    font: inherit;
    text-transform: uppercase;
    cursor: pointer;
}

#rigpiShellNav button:hover,
#rigpiShellNav summary:hover,
#rigpiShellNav details[open] > summary {
    color: rgba(0, 0, 0, 0.7);
}

#rigpiShellNav button[aria-current="page"],
#rigpiShellNav details.has-current > summary {
    background: #39f;
    color: rgba(0, 0, 0, 0.9);
    border-radius: 0;
}

#rigpiShellNav button:focus-visible,
#rigpiShellNav summary:focus-visible,
#rigpiShellNav .shell-menu a:focus-visible {
    outline: 2px solid #005fcc;
    outline-offset: -2px;
}

#rigpiShellNav details { flex-shrink: 0; }
#rigpiShellNav details[hidden] { display: none; }
#rigpiShellNav summary { list-style: none; }
#rigpiShellNav summary::-webkit-details-marker { display: none; }
#rigpiShellNav summary::after {
    content: "";
    display: inline-block;
    margin-left: 0.255em;
    vertical-align: 0.255em;
    border-top: 0.3em solid;
    border-right: 0.3em solid transparent;
    border-left: 0.3em solid transparent;
}

#rigpiShellNav .shell-menu {
    position: fixed;
    top: 56px;
    left: 16px;
    z-index: 1000;
    box-sizing: border-box;
    width: min(280px, calc(100vw - 20px));
    max-height: calc(100dvh - 72px);
    overflow-y: auto;
    padding: 8px 0;
    border: 1px solid rgba(0, 0, 0, 0.15);
    border-radius: 4px;
    background: #fff;
    color: #212529;
    box-shadow: none;
}

#rigpiShellNav .shell-menu button,
#rigpiShellNav .shell-menu a {
    display: block;
    box-sizing: border-box;
    width: 100%;
    min-height: 32px;
    padding: 4px 24px;
    color: #212529;
    text-align: left;
    text-transform: none;
    text-decoration: none;
    border-radius: 0;
    font: inherit;
}

#rigpiShellNav .shell-menu button:hover,
#rigpiShellNav .shell-menu a:hover {
    background: #f8f9fa;
    color: #16181b;
}

#rigpiShellNav .shell-menu button[aria-current="page"] {
    background: #007bff;
    color: #fff;
}

#rigpiContent {
    display: block;
    flex: 1 1 0;
    width: 100%;
    height: 0;
    min-height: 0;
    border: none;
}

#rigpiShellControls {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 8px;
    margin-left: auto;
    padding-left: 16px;
}
#rigpiShellControls[hidden] { display: none; }
#rigpiShellSearch { display: flex; align-items: center; gap: 8px; margin: 0; }
#rigpiShellSearchText {
    box-sizing: border-box;
    width: 100px;
    height: 38px;
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: #fff;
    color: #495057;
    font: inherit;
    text-transform: uppercase;
}
#rigpiShellSearchText:focus {
    outline: 0;
    border-color: #80bdff;
    box-shadow: 0 0 0 3px #007bff40;
}
#rigpiShellControls button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 6px 12px;
    color: #212529;
}
#rigpiShellControls svg { width: 20px; height: 20px; }
#rigpiShellControls .shell-search-button {
    border: 1px solid #28a745;
    color: #28a745;
}
#rigpiShellControls .shell-search-button:hover { background: #28a745; color: #fff; }
#rigpiShellControls #rigpiShellAudio {
    padding: 6px 8px;
    width: 38px;
    flex: 0 0 38px;
    border: 1px solid rgba(0, 0, 0, 0.25);
    border-radius: 4px;
}
#rigpiShellControls #rigpiShellAudio .shell-audio-pause { display: none; }
#rigpiShellControls #rigpiShellAudio[aria-pressed="true"] { color: #218838; border-color: #218838; }
#rigpiShellControls #rigpiShellAudio[aria-pressed="true"] .shell-audio-play { display: none; }
#rigpiShellControls #rigpiShellAudio[aria-pressed="true"] .shell-audio-pause { display: block; }
#rigpiShellControls #rigpiShellAudio:focus-visible { outline: 2px solid #39f; outline-offset: 2px; }
#rigpiShellAudioSettings {
    box-sizing: border-box;
    flex: 1;
    min-height: 0;
    padding: 0 15px 24px;
    overflow: auto;
    background: #444;
    color: white;
    font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
#rigpiShellAudioSettings[hidden] { display: none; }
body.shell-audio-settings #rigpiContent { display: none; }
#rigpiShellAudioSettings h1 {
    margin: 0 0 24px;
    padding: 12px 0 14px;
    border-bottom: 1px solid white;
    text-align: center;
    font-size: 20px;
    font-weight: 400;
}
#rigpiShellAudioSettings .audio-settings-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px 30px;
}
#rigpiShellAudioSettings .audio-setting-row {
    display: flex;
    min-width: 0;
    min-height: 38px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    overflow: hidden;
}
#rigpiShellAudioSettings .audio-setting-label {
    display: flex;
    align-items: center;
    flex: 0 0 108px;
    box-sizing: border-box;
    margin: 0;
    padding: 6px 12px;
    background: #888;
}
#rigpiShellAudioSettings .audio-setting-value {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
    padding: 6px 12px;
    color: #495057;
    background: #e9ecef;
    overflow-wrap: anywhere;
}
#rigpiShellAudioSettings input { min-width: 0; width: 100%; accent-color: #007bff; }
#rigpiShellReceiveVolumeValue { flex: 0 0 5ch; min-width: 5ch; white-space: nowrap; overflow-wrap: normal; text-align: right; }
#rigpiShellAudioSettings .audio-settings-note { margin: 15px 0 0; color: #ddd; font-size: 14px; }
@media (max-width: 1000px) {
    #rigpiShellAudioSettings .audio-settings-grid { grid-template-columns: 1fr; }
}

#rigpiShellAudioFooter { display: none; }
body.shell-audio-settings #rigpiShellAudioFooter {
    display: grid;
    grid-template-columns: 3fr 2fr 3fr 3fr 1fr;
    flex: 0 0 auto;
    padding: 0 15px;
    background: black;
    color: white;
    font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
#rigpiShellAudioFooter > div { min-width: 0; overflow-wrap: anywhere; }
@media (max-width: 991px) {
    body.shell-audio-settings #rigpiShellAudioFooter { grid-template-columns: 1fr; text-align: center; }
    #rigpiShellAudioFooter > div:not(:first-child) { display: none; }
}

#rigpiShellAudioSettings select { flex: 1; min-width: 0; width: 100%; border: 0; padding: 6px 12px; color: #495057; background: #fff; font: inherit; }
#rigpiShellAudioSettings select:disabled { background: #e9ecef; }
#rigpiShellAudioSettings .audio-setting-label { flex-basis: 120px; }
#rigpiShellAudioSettings .audio-pan { padding: 0; gap: 0; background: #444; }
#rigpiShellAudioSettings button { padding: 6px 16px; border: 1px solid #aaa; border-radius: 4px; color: white; background: #444; font: inherit; cursor: pointer; }
#rigpiShellAudioSettings .audio-pan button { flex: 1; border: 0; border-radius: 0; }
#rigpiShellAudioSettings button[aria-pressed="true"] { background: #39f; color: #111; }
#rigpiShellAudioSettings .audio-meter-panel { margin-top: 24px; border-top: 1px solid #aaa; padding-top: 16px; }
#rigpiShellAudioSettings .audio-meter-heading { display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
#rigpiShellAudioSettings h2 { margin: 0; font-size: 20px; font-weight: 400; }
#rigpiShellAudioSettings .audio-meter-row { display: grid; grid-template-columns: 100px minmax(100px,1fr) 110px; align-items: center; gap: 15px; margin: 12px 0; }
#rigpiShellAudioSettings meter { width: 100%; height: 24px; }
#rigpiShellAudioSettings .audio-meter-scale { display: flex; justify-content: space-between; margin: 0 125px 0 115px; color: #ddd; font-size: 13px; }
#rigpiShellAudioScope { display: block; width: 100%; height: 180px; background: #111; }
#rigpiShellAudioScope[hidden], #rigpiShellAudioLevels[hidden] { display: none; }
#rigpiShellAudioMessage { min-height: 21px; }

#rigpiShellAudioSettings .audio-action-row { grid-column: 1 / -1; }
#rigpiShellAudioSettings .audio-action-row .audio-setting-value { min-width: 0; flex: 1; }
#rigpiShellAudioSettings .audio-action-row button { flex: 0 0 auto; white-space: nowrap; }
#rigpiShellAudioSettings .audio-action-row [role="status"] { min-width: 0; overflow-wrap: anywhere; }

#rigpiShellAudioSettings .audio-action-row.audio-playback-row { grid-column: span 2; }
#shellRecordPlayer { height: 38px; }
@media (max-width: 1000px) {
    #rigpiShellAudioSettings .audio-action-row.audio-playback-row { grid-column: 1 / -1; }
}
</style>
</head>

<body>

<div id="rigpiShellLoading" role="status" aria-live="polite">
    <div id="rigpiShellProgress" role="progressbar" aria-label="RigPi startup progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="rigpiShellProgressFill"></span></div>
    <span>Loading RigPi…</span>
</div>


<nav id="rigpiShellNav" aria-label="RigPi main navigation">
    <a class="shell-logo" href="https://www.rigpi.com" target="_blank" rel="noopener noreferrer" title="Go to https://www.rigpi.com">
        <img src="/Images/RigPiW.png" width="30" height="30" alt="RigPi">
    </a>
    <a class="brand" href="https://www.rigpi.com" target="_blank" rel="noopener noreferrer" title="Go to https://www.rigpi.com">RigPi</a>
    <button type="button" data-page="/tuner.php" aria-controls="rigpiContent"><u>T</u>uner</button>
    <button type="button" data-page="/keyer.php" aria-controls="rigpiContent"><u>K</u>eyer</button>
    <button type="button" data-page="/log.php" aria-controls="rigpiContent"><u>L</u>og</button>
    <button type="button" data-page="/spots.php" aria-controls="rigpiContent"><u>S</u>pots</button>
    <button type="button" data-page="/calendar_main.php" aria-controls="rigpiContent"><u>C</u>alendar</button>
    <button type="button" data-page="/sdr.php" aria-controls="rigpiContent">El<u>m</u>er</button>
    <button type="button" data-page="/elmer.php" aria-controls="rigpiContent"><u>A</u>sk Elmer</button>
    <button type="button" data-page="/index_4.php" aria-controls="rigpiContent">SD<u>R</u>4</button>
    <button type="button" data-page="/web.php" aria-controls="rigpiContent"><u>W</u>eb</button>
    <details id="rigpiShellSettings" hidden>
        <summary>S<u>e</u>ttings</summary>
        <div class="shell-menu" aria-label="Settings pages"></div>
    </details>
    <details id="rigpiShellHelp" hidden>
        <summary><u>H</u>elp</summary>
        <div class="shell-menu" aria-label="Help resources"></div>
    </details>
    <div id="rigpiShellControls" hidden>
        <button id="rigpiShellAudio" type="button" title="Start receive audio" aria-label="Start receive audio" aria-pressed="false">
        <svg class="shell-audio-play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 4v16l14-8z"/></svg>
        <svg class="shell-audio-pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>
    </button>
    <form id="rigpiShellSearch" role="search" aria-label="Callsign lookup">
            <input id="rigpiShellSearchText" type="text" placeholder="Search for Call" aria-label="Search for Call" autocomplete="off">
            <button type="submit" class="shell-search-button" title="Show call info" aria-label="Show call info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><circle cx="10" cy="10" r="6"></circle><path d="m15 15 6 6"></path></svg>
            </button>
        </form>
        <button id="rigpiShellLogout" type="button" title="Log Out from RigPi" aria-label="Log Out from RigPi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M10 3H4v18h6M9 12h12m-5-5 5 5-5 5"></path></svg>
        </button>
    </div>
</nav>

<main id="rigpiShellAudioSettings" aria-labelledby="rigpiShellAudioSettingsTitle" hidden>
    <h1 id="rigpiShellAudioSettingsTitle" tabindex="-1">Audio Settings</h1>
    <div class="audio-settings-grid">
        <div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellAudioSource">Rx source</label><select id="rigpiShellAudioSource"><option value="sdr">SDR</option><option value="radio">Radio USB audio</option></select></div>
        <div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellRadioInput">Radio input</label><select id="rigpiShellRadioInput" disabled><option value="">Select radio input</option></select></div>
        <div class="audio-setting-row"><span class="audio-setting-label">Status</span><span id="rigpiShellAudioState" class="audio-setting-value" role="status">Paused</span></div>
        <div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellAudioOutput">Speakers</label><select id="rigpiShellAudioOutput"><option value="">System default</option></select></div>
        <div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellReceiveVolume">Rx volume</label><div class="audio-setting-value"><input id="rigpiShellReceiveVolume" type="range" min="0" max="200" step="1" value="50" aria-describedby="rigpiShellAudioVolumeNote"><output id="rigpiShellReceiveVolumeValue" for="rigpiShellReceiveVolume">50%</output></div></div>
        <div class="audio-setting-row"><span class="audio-setting-label">Balance</span><div class="audio-setting-value audio-pan" role="group" aria-label="Receive balance"><button type="button" data-shell-pan="-1" aria-pressed="false">Left</button><button type="button" data-shell-pan="0" aria-pressed="true">Center</button><button type="button" data-shell-pan="1" aria-pressed="false">Right</button></div></div>
    </div>
    <p id="rigpiShellAudioVolumeNote" class="audio-settings-note">Receive settings apply immediately and are saved in this browser.</p>
    <p id="rigpiShellAudioOutputNote" class="audio-settings-note"></p>
    <p id="rigpiShellAudioMessage" class="audio-settings-note" role="status"></p>
    <section class="audio-meter-panel" aria-labelledby="rigpiShellAudioMeterTitle">
        <div class="audio-meter-heading"><h2 id="rigpiShellAudioMeterTitle">Receive audio level</h2><div role="group" aria-label="Receive meter view"><button type="button" id="rigpiShellAudioVuButton" aria-pressed="true">Level meters</button> <button type="button" id="rigpiShellAudioScopeButton" aria-pressed="false">Spectrum</button></div></div>
        <div id="rigpiShellAudioLevels">
            <div class="audio-meter-row"><label for="rigpiShellAudioRms">RMS</label><meter id="rigpiShellAudioRms" min="-60" max="0" low="-18" high="-6" optimum="-24" value="-60"></meter><output id="rigpiShellAudioRmsValue">— dBFS</output></div>
            <div class="audio-meter-row"><label for="rigpiShellAudioPeak">Peak</label><meter id="rigpiShellAudioPeak" min="-60" max="0" low="-18" high="-6" optimum="-24" value="-60"></meter><output id="rigpiShellAudioPeakValue">— dBFS</output></div>
            <div class="audio-meter-scale" aria-hidden="true"><span>-60</span><span>-40</span><span>-20</span><span>0 dBFS</span></div>
        </div>
        <canvas id="rigpiShellAudioScope" width="1200" height="180" aria-label="Receive audio spectrum from 0 to 12 kHz" hidden></canvas>
        <p class="audio-settings-note">Levels are measured before the volume control.</p>
    </section>

    <section id="rigpiShellRecordingPanel" class="audio-meter-panel" aria-labelledby="rigpiShellRecordingTitle">
<div class="audio-meter-heading"><h2 id="rigpiShellRecordingTitle">Recording</h2></div>
<div class="audio-settings-grid">
<div class="audio-setting-row"><span class="audio-setting-label">Rx audio</span><div class="audio-setting-value" style="flex-wrap:wrap"><button type="button" id="shellRecordStart">● Record</button><button type="button" id="shellRecordPause" disabled>Pause</button><button type="button" id="shellRecordStop" disabled>Stop</button><output id="shellRecordTimer" style="white-space:nowrap;font-variant-numeric:tabular-nums">00:00</output></div></div>
<div class="audio-setting-row"><span class="audio-setting-label">Status</span><div class="audio-setting-value" style="flex-wrap:wrap;overflow-wrap:anywhere"><span id="shellRecordStatus" role="status">Ready</span> <button type="button" id="shellRecordRetry" hidden>Retry save</button> <a id="shellRecordLocal" hidden>Download unsaved recording</a></div></div>
<div class="audio-setting-row"><label class="audio-setting-label" for="shellRecordFiles">Saved</label><select id="shellRecordFiles"><option value="">No recordings</option></select></div>
<div class="audio-setting-row audio-action-row audio-playback-row"><span class="audio-setting-label">Playback</span><div class="audio-setting-value" style="flex-wrap:wrap"><audio id="shellRecordPlayer" controls preload="metadata" style="width:100%;max-width:340px" aria-label="Recording playback"></audio><button type="button" id="shellRecordDownload" hidden onclick="downloadShellRecording()">Download</button><button type="button" id="shellRecordRefresh">Refresh list</button></div></div>
</div>
<p class="audio-settings-note">Records the selected receive source, independently of speaker volume. Stop saves the recording on RigPi. Recording continues between shell pages; stopping receive audio also stops and saves the recording. Each recording stops automatically after 60 minutes.</p>
</section>
<section id="rigpiShellLoopbackPanel" class="audio-meter-panel" aria-labelledby="rigpiShellLoopbackTitle">
<div class="audio-meter-heading"><h2 id="rigpiShellLoopbackTitle">Digital-mode loopback</h2></div>
<div class="audio-settings-grid">
<div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellLoopbackDevice">Loop out</label><select id="rigpiShellLoopbackDevice"><option value="">Loading loopback devices</option></select></div>
<div class="audio-setting-row"><span class="audio-setting-label">Rx loopback</span><div class="audio-setting-value"><button type="button" id="rigpiShellLoopbackToggle" aria-pressed="false" disabled>Start loopback</button><span id="rigpiShellLoopbackStatus" role="status">Checking status</span></div></div>
</div>
<p class="audio-settings-note">Feeds SDR receive audio to WSJT-X and similar programs on this RigPi. Select the paired Loopback input in your digital-mode program. For Radio USB audio, select the radio USB input directly in that program.</p>
</section>
<section id="rigpiShellMicrophonePanel" class="audio-meter-panel" aria-labelledby="rigpiShellMicrophoneTitle">
        <div class="audio-meter-heading"><h2 id="rigpiShellMicrophoneTitle">Transmit microphone</h2></div>
        <div class="audio-settings-grid">
            <div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellMicrophoneInput">Microphone</label><select id="rigpiShellMicrophoneInput"><option value="">System default</option></select></div>
            <div class="audio-setting-row audio-action-row"><span class="audio-setting-label">Mic check</span><div class="audio-setting-value"><button type="button" id="rigpiShellMicrophoneCheck" aria-pressed="false">Check microphone</button><span id="rigpiShellMicrophoneStatus" role="status">Microphone off</span></div></div>
        </div>
        <div class="audio-meter-row"><label for="rigpiShellMicrophoneRms">RMS</label><meter id="rigpiShellMicrophoneRms" min="-60" max="0" low="-18" high="-6" optimum="-24" value="-60"></meter><output id="rigpiShellMicrophoneRmsValue">— dBFS</output></div>
        <div class="audio-meter-row"><label for="rigpiShellMicrophonePeak">Peak</label><meter id="rigpiShellMicrophonePeak" min="-60" max="0" low="-18" high="-6" optimum="-24" value="-60"></meter><output id="rigpiShellMicrophonePeakValue">— dBFS</output></div>
        <p class="audio-settings-note">Mic check measures your microphone locally. The radio remains in receive.</p>
        <div class="audio-settings-grid">
            <div class="audio-setting-row"><label class="audio-setting-label" for="rigpiShellTransmitDevice">Radio output</label><select id="rigpiShellTransmitDevice"><option value="">Select radio output</option></select></div>
            <div class="audio-setting-row audio-action-row"><span class="audio-setting-label">Tx audio</span><div class="audio-setting-value"><button type="button" id="rigpiShellTransmitConnect" aria-pressed="false">Connect TX audio</button><span id="rigpiShellTransmitStatus" role="status">Disconnected</span></div></div>
        </div>
        <p class="audio-settings-note">Connect TX audio, then use PTT on the Tuner. Microphone audio is muted when PTT is released.</p>

    </section>
</main>
<footer id="rigpiShellAudioFooter" aria-label="RigPi status"><div></div><div></div><div></div><div></div><div></div></footer>



<iframe
    id="rigpiContent"
    src="<?php echo htmlspecialchars($shellTunerUrl, ENT_QUOTES, 'UTF-8'); ?>"
    title="RigPi"
    allow="microphone; autoplay">
</iframe>


<script>
window.rigpiShell = {
    started: Date.now(),
    version: "test-1",
    currentUrl: null,
    lastLoaded: null,
    loadCount: 0
};

// Reserve a persistent browser context; no audio engine is loaded yet.
window.rigpiShell.audioHost = {
    status: "inactive",
    created: Date.now(),
    lastLoaded: null,
    loadCount: 0
};

const rigpiAudioHost = document.createElement("iframe");
rigpiAudioHost.id = "rigpiAudioHost";
rigpiAudioHost.title = "RigPi audio host (inactive)";
rigpiAudioHost.hidden = true;
rigpiAudioHost.tabIndex = -1;
rigpiAudioHost.allow = "microphone; autoplay";
rigpiAudioHost.src = "about:blank";
rigpiAudioHost.addEventListener("load", () => {
    const host = window.rigpiShell.audioHost;
    host.lastLoaded = new Date().toISOString();
    host.loadCount += 1;
    console.log("RigPi shell audio host loaded:", host.status, host.loadCount, host.lastLoaded);
});
// Keep this frame in the master document when rigpiContent navigates.
document.body.appendChild(rigpiAudioHost);

// Receive-only prototype: no microphone capture or transmit connection.
const shellAudioButton = document.getElementById("rigpiShellAudio");
let shellAudioConfig = null;
let shellRx = null;
let shellRxGeneration = 0;
Object.assign(window.rigpiShell.audioHost, { packets: 0, samples: 0, error: null });

let shellReceiveVolume = 50;
try {
    const saved = localStorage.getItem("rigpi_shell_receive_volume");
    if (saved !== null && saved.trim() !== "" && Number.isFinite(Number(saved))) {
        shellReceiveVolume = Math.max(0, Math.min(200, Number(saved)));
    }
} catch (_) { /* Volume still works when browser storage is unavailable. */ }


function syncShellAudioFooter() {
    if (document.getElementById("rigpiShellAudioSettings").hidden) return;
    const frame = document.getElementById("rigpiContent");
    try {
        const cells = document.getElementById("rigpiShellAudioFooter").children;
        for (let i = 0; i < cells.length; i++) {
            const source = frame.contentDocument.getElementById("fPanel" + (i + 1));
            const text = source ? source.textContent : "";
            if (cells[i].textContent !== text) cells[i].textContent = text;
            if (source) {
                const style = frame.contentWindow.getComputedStyle(source);
                cells[i].style.color = style.color;
                cells[i].style.backgroundColor = style.backgroundColor;
                cells[i].style.font = style.font;
                cells[i].style.textAlign = style.textAlign;
            }
        }
    } catch (_) {}
}
setInterval(syncShellAudioFooter, 1000);


let shellRxChoice = null;
let shellPan = 0;
let shellSink = "";
let shellMeterView = "level";
try {
    const saved = JSON.parse(localStorage.getItem("rigpi_shell_rx_choice") || "null");
    if (saved && ["sdr", "radio"].includes(saved.source)) shellRxChoice = saved;
    const pan = Number(localStorage.getItem("rigpi_shell_pan") || "0");
    if ([-1, 0, 1].includes(pan)) shellPan = pan;
    shellSink = localStorage.getItem("rigpi_shell_sink") || "";
} catch (_) {}
function shellReceiveChoice(config) {
    return shellRxChoice ? { source: shellRxChoice.source, device: shellRxChoice.device } : { ...config.receive };
}
function refreshShellReceiveControls() {
    const config = shellAudioConfig;
    const choice = config ? shellReceiveChoice(config) : { source: "sdr", device: null };
    const source = document.getElementById("rigpiShellAudioSource");
    source.options[0].textContent = config ? "SDR — " + (config.deviceLabel || "SDR") : "SDR";
    source.value = choice.source;
    source.disabled = !config;
    const input = document.getElementById("rigpiShellRadioInput");
    const devices = config?.radioDevices || [];
    const signature = JSON.stringify(devices);
    if (input.dataset.devices !== signature) {
        input.replaceChildren(new Option("Select radio input", ""));
        for (const item of devices) input.add(new Option(item.label, item.value));
        input.dataset.devices = signature;
    }
    input.value = choice.device === null ? "" : String(choice.device);
    input.disabled = !config;
    document.querySelectorAll('[data-shell-pan]').forEach(button => {
        button.setAttribute("aria-pressed", String(Number(button.dataset.shellPan) === shellPan));
    });
}
function changeShellReceiveSource() {
    if (!shellAudioConfig) return;
    const source = document.getElementById("rigpiShellAudioSource").value;
    const raw = document.getElementById("rigpiShellRadioInput").value;
    const device = raw === "" ? null : Number(raw);
    const message = document.getElementById("rigpiShellAudioMessage");
    if (source === "radio" && device === null) {
        message.textContent = "Select a radio input before choosing Radio USB audio.";
        refreshShellReceiveControls();
        return;
    }
    shellRxChoice = { source, device };
    try { localStorage.setItem("rigpi_shell_rx_choice", JSON.stringify(shellRxChoice)); } catch (_) {}
    message.textContent = "";
    if (shellRx && shellRx.socket?.readyState === 1) {
        const current = shellRx.config.receive;
        if (current.source !== source || (source === "radio" && current.device !== device)) {
            flushShellAudio(shellRx);
            shellRx.config = { ...shellRx.config, receive: { ...shellRxChoice } };
            shellRx.socket.send(JSON.stringify({ cmd: "audio_off" }));
            shellRx.socket.send(JSON.stringify({ cmd: "audio_on", ...shellRxChoice }));
            shellRx.lastPacket = Date.now();
            setShellAudioStatus("waiting");
        }
    }
    refreshShellReceiveControls();
}
document.getElementById("rigpiShellAudioSource").addEventListener("change", changeShellReceiveSource);
document.getElementById("rigpiShellRadioInput").addEventListener("change", changeShellReceiveSource);
document.querySelectorAll('[data-shell-pan]').forEach(button => button.addEventListener("click", () => {
    shellPan = Number(button.dataset.shellPan);
    if (shellRx) shellRx.panner.pan.setTargetAtTime(shellPan, shellRx.context.currentTime, 0.02);
    try { localStorage.setItem("rigpi_shell_pan", String(shellPan)); } catch (_) {}
    refreshShellReceiveControls();
}));
async function refreshShellSpeakers() {
    const select = document.getElementById("rigpiShellAudioOutput");
    const note = document.getElementById("rigpiShellAudioOutputNote");
    const Context = rigpiAudioHost.contentWindow.AudioContext;
    const supported = !!Context?.prototype.setSinkId && !!navigator.mediaDevices?.enumerateDevices;
    select.disabled = !supported;
    if (!supported) {
        note.textContent = "Speaker selection is unavailable on this connection. Audio uses the Mac's selected sound output.";
        return;
    }
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        select.replaceChildren(new Option("System default", ""));
        for (const device of devices.filter(item => item.kind === "audiooutput" && item.deviceId !== "default")) {
            select.add(new Option(device.label || "Speaker " + select.options.length, device.deviceId));
        }
        select.value = shellSink;
        if (select.selectedIndex < 0) select.value = "";
        note.textContent = "";
    } catch (_) { note.textContent = "Unable to list speakers. Audio uses the system default."; }
}
document.getElementById("rigpiShellAudioOutput").addEventListener("change", async event => {
    const selected = event.target.value;
    const message = document.getElementById("rigpiShellAudioMessage");
    event.target.disabled = true;
    try {
        if (shellRx) await shellRx.context.setSinkId(selected);
        shellSink = selected;
        try { localStorage.setItem("rigpi_shell_sink", shellSink); } catch (_) {}
        message.textContent = "";
    } catch (_) {
        event.target.value = shellSink;
        message.textContent = "That speaker could not be selected. The previous output is still in use.";
    } finally { event.target.disabled = false; }
});
function setShellMeterView(view) {
    shellMeterView = view;
    document.getElementById("rigpiShellAudioLevels").hidden = view !== "level";
    document.getElementById("rigpiShellAudioScope").hidden = view !== "spectrum";
    document.getElementById("rigpiShellAudioVuButton").setAttribute("aria-pressed", String(view === "level"));
    document.getElementById("rigpiShellAudioScopeButton").setAttribute("aria-pressed", String(view === "spectrum"));
}
document.getElementById("rigpiShellAudioVuButton").addEventListener("click", () => setShellMeterView("level"));
document.getElementById("rigpiShellAudioScopeButton").addEventListener("click", () => setShellMeterView("spectrum"));
function updateShellReceiveMeters() {
    if (document.getElementById("rigpiShellAudioSettings").hidden) return;
    const session = shellRx;
    const active = session && session.context.state === "running" && !session.muted && window.rigpiShell.audioHost.status === "playing";
    let rms = 0, peak = 0;
    if (active) {
        session.analyser.getFloatTimeDomainData(session.meterSamples);
        for (const value of session.meterSamples) { rms += value * value; peak = Math.max(peak, Math.abs(value)); }
        rms = Math.sqrt(rms / session.meterSamples.length);
    }
    for (const [name, value] of [["Rms", rms], ["Peak", peak]]) {
        const db = value > 0 ? 20 * Math.log10(value) : -Infinity;
        document.getElementById("rigpiShellAudio" + name).value = Math.max(-60, Math.min(0, db));
        document.getElementById("rigpiShellAudio" + name + "Value").value = Number.isFinite(db) ? db.toFixed(1) + " dBFS" : "— dBFS";
    }
    if (shellMeterView !== "spectrum") return;
    const canvas = document.getElementById("rigpiShellAudioScope");
    const ctx = canvas.getContext("2d");
    const width = canvas.width, height = canvas.height;
    ctx.fillStyle = "#111"; ctx.fillRect(0, 0, width, height);
    ctx.strokeStyle = "#444"; ctx.lineWidth = 1;
    for (let i = 1; i <= 4; i++) {
        const x = i * width / 4;
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, height - 24); ctx.stroke();
    }
    ctx.font = "13px sans-serif"; ctx.fillStyle = "#ddd";
    for (let i = 0; i <= 4; i++) ctx.fillText(i * 3 + " kHz", Math.min(width - 50, i * width / 4), height - 6);
    if (!active) return;
    session.analyser.getByteFrequencyData(session.meterBins);
    ctx.strokeStyle = "#44dd99"; ctx.lineWidth = 2;
    const trace = new Path2D();
    const count = Math.min(session.meterBins.length, Math.floor(12000 / (session.context.sampleRate / session.analyser.fftSize)));
    for (let i = 0; i < count; i++) {
        const x = i * width / Math.max(1, count - 1);
        const y = (height - 28) * (1 - session.meterBins[i] / 255);
        if (i === 0) trace.moveTo(x, y); else trace.lineTo(x, y);
    }
    const area = new Path2D(trace);
    area.lineTo(width, height - 28);
    area.lineTo(0, height - 28);
    area.closePath();
    ctx.fillStyle = "rgba(144, 238, 144, 0.30)";
    ctx.fill(area);
    ctx.stroke(trace);
}
setInterval(updateShellReceiveMeters, 100);

function updateShellAudioSettings() {
    const config = (shellRx && shellRx.config) || shellAudioConfig;
    refreshShellReceiveControls();
    const state = window.rigpiShell.audioHost;
    const labels = { inactive: "Paused", starting: "Connecting", waiting: "Waiting for audio", playing: "Playing", error: "Audio unavailable" };
    document.getElementById("rigpiShellAudioState").textContent = state.error || labels[state.status] || state.status;
    document.getElementById("rigpiShellReceiveVolume").value = shellReceiveVolume;
    document.getElementById("rigpiShellReceiveVolumeValue").value = Math.round(shellReceiveVolume) + "%";
}

function openShellAudioSettings() {
    refreshShellRecordings();
    refreshShellLoopback();
    if (window.rigpiShell.transmit?.pttRequested) setShellTransmitGate(false);
    refreshShellTransmitControls();
    refreshShellMicrophones();
    closeShellMenus();
    try {
        const config = readShellAudioConfig();
        if (config) shellAudioConfig = config;
    } catch (_) {}
    updateShellAudioSettings();
    document.getElementById("rigpiShellAudioSettings").hidden = false;
    document.body.classList.add("shell-audio-settings");
    updateShellNavigation();
    syncShellAudioFooter();
    refreshShellSpeakers();
    updateShellReceiveMeters();
    document.getElementById("rigpiShellAudioSettingsTitle").focus();
}

document.getElementById("rigpiShellReceiveVolume").addEventListener("input", (event) => {
    shellReceiveVolume = Number(event.target.value);
    if (shellRx) shellRx.gain.gain.setTargetAtTime(rigpiShell.transmit?.pttRequested ? 0 : shellReceiveVolume / 100, shellRx.context.currentTime, 0.02);
    document.getElementById("rigpiShellReceiveVolumeValue").value = shellReceiveVolume + "%";
    try { localStorage.setItem("rigpi_shell_receive_volume", String(shellReceiveVolume)); } catch (_) {}
});

function setShellAudioStatus(status, error = null) {
    const state = window.rigpiShell.audioHost;
    state.status = status;
    state.error = error;
    const active = status === "starting" || status === "waiting" || status === "playing";
    shellAudioButton.setAttribute("aria-label", active ? "Pause receive audio" : "Start receive audio");
    shellAudioButton.setAttribute("aria-pressed", String(active));
    shellAudioButton.title = error || (active ? "Pause receive audio" : "Start receive audio");
    updateShellAudioSettings();
    console.log("RigPi shell receive audio:", status, error || "");
}

function closeShellSdrReceive(session) {
    clearTimeout(session.rtcDeadline);
    session.rtcAbort?.abort();
    session.rtcActive = false;
    if (session.rtcPeer) { session.rtcPeer.onconnectionstatechange = null; session.rtcPeer.ontrack = null; session.rtcPeer.close(); }
    if (session.rtcElement) { session.rtcElement.pause(); session.rtcElement.srcObject = null; session.rtcElement.remove(); session.rtcElement = null; }
    session.rtcStream?.getTracks().forEach(track => track.stop());
    session.rtcSource?.disconnect();
    session.rtcGate?.disconnect();
    session.rtcPeer = null;
    session.rtcSource = null;
    session.rtcStream = null;
    session.rtcGate = null;
}
async function startShellSdrReceive(session) {
    if (session.config.receive.source !== "sdr" || session.rtcPeer || shellRx !== session) return;
    const owner = document.getElementById("rigpiAudioHost").contentWindow;
    const peer = new owner.RTCPeerConnection({iceServers:[]});
    session.rtcPeer = peer;
    const abort = new AbortController();
    session.rtcAbort = abort;
    const fallback = error => {
        if (session.rtcPeer !== peer) return;
        closeShellSdrReceive(session);
        console.warn("RigPi shell SDR receive fallback:", error);
    };
    const activate = () => {
        if (shellRx !== session || session.rtcPeer !== peer || peer.connectionState !== "connected" || !session.rtcSource || session.rtcActive) return;
        flushShellAudio(session);
        session.rtcActive = true;
        session.rtcGate.gain.value = session.muted ? 0 : 1;
        clearTimeout(session.rtcDeadline);
        console.log("RigPi shell SDR receive WebRTC connected");
    };
    session.rtcDeadline = setTimeout(() => fallback("connection timed out"), 25000);
    peer.ontrack = event => {
        if (shellRx !== session || session.rtcPeer !== peer) { event.track.stop(); return; }
        session.rtcStream = new owner.MediaStream([event.track]);
        // Chrome needs an attached media element to pull and decode remote audio.
        const element = owner.document.createElement("audio");
        element.muted = true;
        element.autoplay = true;
        element.srcObject = session.rtcStream;
        owner.document.body.appendChild(element);
        session.rtcElement = element;
        element.play().catch(error => fallback(error.message));
        session.rtcSource = session.context.createMediaStreamSource(session.rtcStream);
        session.rtcGate = session.context.createGain();
        session.rtcGate.gain.value = 0;
        session.rtcSource.connect(session.rtcGate);
        session.rtcGate.connect(session.analyser);
        event.track.onended = () => fallback("receive track ended");
        activate();
    };
    peer.onconnectionstatechange = () => {
        if (shellRx !== session || session.rtcPeer !== peer) return;
        if (["failed", "closed", "disconnected"].includes(peer.connectionState)) fallback(peer.connectionState);
        else activate();
    };
    try {
        const receiver = peer.addTransceiver("audio", {direction:"recvonly"}).receiver;
        if ("jitterBufferTarget" in receiver) receiver.jitterBufferTarget = 400;
        await peer.setLocalDescription(await peer.createOffer());
        if (peer.iceGatheringState !== "complete") await new Promise((resolve, reject) => {
            const cleanup = () => { peer.removeEventListener("icegatheringstatechange", changed); abort.signal.removeEventListener("abort", cancelled); };
            const changed = () => { if (peer.iceGatheringState === "complete") { cleanup(); resolve(); } };
            const cancelled = () => { cleanup(); reject(new Error("Receive connection cancelled")); };
            peer.addEventListener("icegatheringstatechange", changed);
            abort.signal.addEventListener("abort", cancelled, {once:true});
            if (abort.signal.aborted) cancelled(); else changed();
        });
        if (shellRx !== session || session.rtcPeer !== peer) return;
        const url = new URL(session.config.url);
        url.protocol = location.protocol;
        url.pathname = url.pathname.replace(/\/ws\/spectrum$/, "/api/audio/offer");
        url.search = "";
        if (url.origin !== location.origin || !url.pathname.endsWith("/api/audio/offer")) throw new Error("Invalid receive service");
        const response = await fetch(url, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({sdp:peer.localDescription.sdp,type:"offer"}), signal:abort.signal});
        const answer = await response.json();
        if (!response.ok || !answer.sdp) throw new Error(answer.error || "Receive connection failed");
        if (shellRx !== session || session.rtcPeer !== peer) return;
        await peer.setRemoteDescription(answer);
    } catch (error) { fallback(error.message); }
}

function flushShellAudio(session) {
    session.sources.forEach((source) => { try { source.stop(); } catch (_) {} });
    session.sources.clear();
    session.nextTime = 0;
}

function stopShellAudio(status = "inactive", error = null) {
    stopShellRecording();
    shellRxGeneration += 1;
    const session = shellRx;
    shellRx = null;
    if (session) {
        clearTimeout(session.timeout);
        clearInterval(session.watchdog);
        closeShellSdrReceive(session);
        flushShellAudio(session);
        if (session.socket) {
            try {
                if (session.socket.readyState === 1) session.socket.send(JSON.stringify({ cmd: "audio_off" }));
                session.socket.close();
            } catch (_) { /* Local playback still stops if the socket has failed. */ }
        }
        session.context.close().catch(() => {});
    }
    setShellAudioStatus(status, error);
}


let shellExpressPending = null;
let shellExpressIntent = null;
let shellExpressRecoveryUntil = 0;
let shellExpressNextAttempt = 0;
let shellHideSdr = false;
try { shellHideSdr = localStorage.getItem("rigpi_shell_hide_sdr") === "1"; } catch (_) {}
function applyShellSdrVisibility(frame) {
    document.querySelectorAll('#rigpiShellNav button[data-page="/sdr.php"], #rigpiShellNav button[data-page="/index_4.php"]').forEach((button) => {
        button.hidden = shellHideSdr;
        button.disabled = shellHideSdr;
        button.style.display = shellHideSdr ? "none" : "";
    });
    try {
        const page = frame.contentDocument;
        if (!page?.head) return;
        let style = page.getElementById("rigpiShellSdrVisibility");
        if (!style) {
            style = page.createElement("style");
            style.id = "rigpiShellSdrVisibility";
            style.textContent = '#sdrPanel, #tuner #sdrPanel + hr, #keyer #sdrPanel + hr, #elmerSdrWindow, .sdr-panel, .sdr-grid, #sdrDashboardStatus { display: none !important; }';
            page.head.appendChild(style);
        }
        style.disabled = !shellHideSdr;
    } catch (_) {}
}

// Conceal a No SDR navigation until the new document has its shell CSS.
function bindShellSdrNavigation(frame) {
    try {
        const page = frame.contentDocument;
        const owner = frame.contentWindow;
        if (!page?.documentElement || page.documentElement.dataset.shellSdrNavigationBound) return;
        page.documentElement.dataset.shellSdrNavigationBound = "true";
        owner.addEventListener("pagehide", () => {
            if (shellHideSdr || (shellExpressIntent?.noSdr && Date.now() < shellExpressIntent.expires)) {
                frame.style.visibility = "hidden";
                frame.setAttribute("aria-busy", "true");
            }
        });
    } catch (_) {}
}

// Authentication replaces the whole master, so re-login gets a fresh startup gate.
document.getElementById("rigpiContent").addEventListener("load", function () {
    try {
        const destination = this.contentWindow.location;
        if (destination.pathname !== "/login.php") return;
        document.documentElement.style.visibility = "hidden";
        window.location.replace(destination.href);
    } catch (_) {}
});

const shellSdrNavigationFrame = document.getElementById("rigpiContent");
shellSdrNavigationFrame.addEventListener("load", () => {
    try {
        // Finish may carry the selection until the destination loads.
        bindShellExpress(shellSdrNavigationFrame);
        applyShellSdrVisibility(shellSdrNavigationFrame);
        bindShellSdrNavigation(shellSdrNavigationFrame);
    } finally {
        shellSdrNavigationFrame.style.removeProperty("visibility");
        shellSdrNavigationFrame.removeAttribute("aria-busy");
    }
});
bindShellSdrNavigation(shellSdrNavigationFrame);

applyShellSdrVisibility(document.getElementById("rigpiContent"));

function selectShellUsbInput(config) {
    const devices = config.radioDevices || [];
    const physical = devices.filter(item => !/loopback|loop[0-9]|hermes|elmer|pulse|default/i.test(item.label));
    const saved = shellRxChoice?.source === "radio" ? shellRxChoice.device : config.receive.source === "radio" ? config.receive.device : null;
    const previous = physical.find(item => Number(item.value) === saved);
    if (previous) return previous;
    const codecs = physical.filter(item => /usb.*codec|codec.*usb/i.test(item.label));
    if (codecs.length === 1) return codecs[0];
    const usb = physical.filter(item => /usb/i.test(item.label));
    return usb.length === 1 ? usb[0] : null;
}
function completeShellExpressSelection(noSdr) {
    shellHideSdr = noSdr;
    try { localStorage.setItem("rigpi_shell_hide_sdr", noSdr ? "1" : "0"); } catch (_) {}
    shellExpressPending = { noSdr, expires: Date.now() + 20000 };
    shellExpressRecoveryUntil = Date.now() + 45000;
    shellExpressNextAttempt = 0;
    console.log("RigPi shell Express selection:", noSdr ? "No SDR; use radio USB audio" : "SDR; show SDR panels");
}
function addShellRemoteDevice(page) {
    const list = page.getElementById("deviceList");
    if (!list || page.documentElement.dataset.shellRemoteDeviceBound) return;
    page.documentElement.dataset.shellRemoteDeviceBound = "true";
    function addChoice() {
        if (page.getElementById("shellRemoteDevice")) return;
        const label = page.createElement("label");
        label.className = "sdr-device";
        const input = page.createElement("input");
        input.type = "radio";
        input.name = "sdrDevice";
        input.id = "shellRemoteDevice";
        const title = page.createElement("span");
        title.className = "sdr-device-title";
        title.textContent = "Remote (OpenWebRX and KiwiSDR)";
        const detail = page.createElement("div");
        detail.className = "sdr-device-detail";
        detail.textContent = "Choose a remote receiver in the Remote controls.";
        label.append(input, title, detail);
        const noSdr = Array.from(list.children).find(item => item.querySelector(".sdr-device-title")?.textContent.trim() === "No SDR");
        list.insertBefore(label, noSdr || null);
    }
    list.addEventListener("change", () => {
        list.querySelectorAll(".sdr-device").forEach(label => {
            label.classList.toggle("selected", !!label.querySelector("input:checked"));
        });
        if (page.getElementById("shellRemoteDevice")?.checked) {
            page.getElementById("finish").disabled = false;
        }
    });
    const observer = new MutationObserver(addChoice);
    observer.observe(list, {childList: true});
    page.defaultView.addEventListener("pagehide", () => observer.disconnect(), {once: true});
    addChoice();
}

function openShellRemoteControls() {
    shellExpressIntent = null;
    shellHideSdr = false;
    try { localStorage.setItem("rigpi_shell_hide_sdr", "0"); } catch (_) {}
    const frame = document.getElementById("rigpiContent");
    applyShellSdrVisibility(frame);
    window.rigpiShell.navigate("/sdr.php");
    const expires = Date.now() + 20000;
    const timer = setInterval(() => {
        try {
            const page = frame.contentDocument;
            const receiver = page?.getElementById("elmerSdrFrame");
            const controls = receiver?.contentDocument;
            const button = controls?.querySelector('.panel-tab[data-panel="remote"]');
            if (frame.contentWindow.location.pathname === "/sdr.php" &&
                controls?.readyState === "complete" && button) {
                clearInterval(timer);
                button.click();
                console.log("RigPi shell: Remote receiver controls opened");
                return;
            }
        } catch (_) {}
        if (Date.now() > expires) {
            clearInterval(timer);
            console.warn("RigPi shell: open the Remote tab to choose a receiver.");
        }
    }, 250);
}

function bindShellExpress(frame) {
    try {
        const owner = frame.contentWindow;
        const page = frame.contentDocument;
        if (owner.location.pathname !== "/wizardSdr.php") {
            const intent = shellExpressIntent;
            shellExpressIntent = null;
            if (intent && Date.now() < intent.expires && ["/tuner.php", "/sdr.php"].includes(owner.location.pathname)) {
                completeShellExpressSelection(intent.noSdr);
                applyShellSdrVisibility(frame);
            }
            return;
        }
        addShellRemoteDevice(page);
    if (page.documentElement.dataset.shellExpressBound) return;
        page.documentElement.dataset.shellExpressBound = "true";
        page.getElementById("finish")?.addEventListener("click", (event) => {
        if (page.getElementById("shellRemoteDevice")?.checked) {
            event.preventDefault();
            event.stopImmediatePropagation();
            openShellRemoteControls();
            return;
        }
            const selected = page.querySelector('input[name="sdrDevice"]:checked');
            const title = selected?.closest(".sdr-device")?.querySelector(".sdr-device-title")?.textContent?.trim();
            shellExpressIntent = title ? { noSdr: title === "No SDR", expires: Date.now() + 60000 } : null;
        }, true);
        const originalFetch = owner.fetch;
        owner.fetch = async function(...args) {
            let selection = null;
            try {
                const address = new URL(String(args[0]), owner.location.href);
                const options = args[1] || {};
                if (address.origin === location.origin && address.pathname.endsWith("/api/device") && options.method?.toUpperCase() === "POST") {
                    const body = JSON.parse(options.body);
                    selection = body.driver === "fake" ? true : body.driver ? false : null;
                }
            } catch (_) {}
            const response = await originalFetch.apply(owner, args);
            if (selection !== null && response.ok) {
                try {
                    const result = await response.clone().json();
                    if (result.ok) { shellExpressIntent = null; completeShellExpressSelection(selection); }
                } catch (_) {}
            }
            return response;
        };
    } catch (_) {}
}
function finishShellExpressAudio() {
    const pending = shellExpressPending;
    if (!pending) {
        if (Date.now() < shellExpressRecoveryUntil && !shellRx && shellAudioConfig && Date.now() >= shellExpressNextAttempt) {
            shellExpressNextAttempt = Date.now() + 2000;
            startShellAudio();
        }
        return;
    }
    const frame = document.getElementById("rigpiContent");
    let path;
    try { path = frame.contentWindow.location.pathname; } catch (_) { return; }
    if (Date.now() > pending.expires) {
        shellExpressPending = null;
        document.getElementById("rigpiShellAudioMessage").textContent = "Open Tuner, then choose the receive source in Audio Settings.";
        return;
    }
    if (!["/tuner.php", "/sdr.php", "/keyer.php"].includes(path)) return;
    let config;
    try { config = readShellAudioConfig(); } catch (_) { return; }
    if (!config || (pending.noSdr && !(config.radioDevices || []).length)) return;
    shellExpressPending = null;
    shellAudioConfig = config;
    const device = pending.noSdr ? selectShellUsbInput(config) : null;
    if (pending.noSdr && !device) {
        shellExpressRecoveryUntil = 0;
        stopShellAudio("error", "Select the radio USB input in Audio Settings.");
        return;
    }
    shellRxChoice = { source: pending.noSdr ? "radio" : "sdr", device: device ? Number(device.value) : config.receive.device };
    try { localStorage.setItem("rigpi_shell_rx_choice", JSON.stringify(shellRxChoice)); } catch (_) {}
    refreshShellReceiveControls();
    document.getElementById("rigpiShellAudioMessage").textContent = pending.noSdr ? "Express selected radio USB audio automatically." : "Express selected SDR audio.";
    changeShellReceiveSource();
    if (!shellRx) startShellAudio();
    applyShellSdrVisibility(frame);
}
setInterval(finishShellExpressAudio, 250);

function hideShellLegacyAudio() {
    const page = document.getElementById("rigpiContent").contentDocument;
    page?.querySelectorAll("iframe").forEach(frame => {
        try {
            const doc = frame.contentDocument;
            if (!doc?.getElementById("tab_audio") || !doc.head || doc.getElementById("shellLegacyAudioHidden")) return;
            const style = doc.createElement("style");
            style.id = "shellLegacyAudioHidden";
            style.textContent = '.panel-tab[data-panel="audio"], #tab_audio { display: none !important; }';
            doc.head.appendChild(style);
            const tab = doc.querySelector('.panel-tab[data-panel="audio"]');
            if (tab?.classList.contains("active")) {
                doc.querySelector('.panel-tab[data-panel="sdr"]')?.click();
            }
            tab?.addEventListener("click", event => {
                event.preventDefault();
                event.stopImmediatePropagation();
                openShellAudioSettings();
            }, true);
        } catch (_) { /* A receiver frame may still be loading. */ }
    });
}

setInterval(hideShellLegacyAudio, 500);

function readShellAudioConfig() {
    hideShellLegacyAudio();
    const page = document.getElementById("rigpiContent").contentDocument;
    const frame = page && (page.getElementById("sdrIframe") || page.getElementById("elmerSdrFrame"));
    if (!frame) return null;
    const panel = frame.contentWindow;
    if (typeof panel.spectrumWsUrl !== "function" || typeof panel.currentRxAudioConfig !== "function") return null;
    const url = new URL(panel.spectrumWsUrl());
    if (url.hostname !== location.hostname || !["ws:", "wss:"].includes(url.protocol)) return null;
    const receive = panel.currentRxAudioConfig();
    const audioButton = panel.document.getElementById("audioBtn");
    return {
        url: url.href, receive,
        radioDevices: Array.from(panel.document.getElementById("rxRadioDevice")?.options || [])
            .filter(option => option.value !== "" && Number.isFinite(Number(option.value)))
            .map(option => ({ value: option.value, label: option.textContent.trim() })),
        deviceLabel: panel.document.querySelector('select[title="Select SDR hardware device"] option:checked')?.textContent.trim() || "SDR",
        panelPlaying: !!audioButton && audioButton.classList.contains("audio-connected"),
        remote: !!(panel._sdrLastState && panel._sdrLastState.owrx_active)
    };
}

function syncShellAudioPage() {
    const url = window.rigpiShell.currentUrl;
    if (!url || new URL(url).pathname === "/login.php") {
        shellAudioConfig = null;
        if (shellRx) stopShellAudio();
        return;
    }
    try {
        const config = readShellAudioConfig();
        if (config) {
            if (shellRx && shellAudioConfig && config.url !== shellAudioConfig.url) stopShellAudio();
            shellAudioConfig = config;
        }
    } catch (_) { /* A nested panel may still be loading. */ }
}

async function startShellAudio() {
    if (shellRx) return;
    let generation = null;
    try {
        const current = readShellAudioConfig();
        if (current) shellAudioConfig = current;
        if (!shellAudioConfig) throw new Error("Open Tuner or Keyer first so the audio source is available.");
        if (shellAudioConfig.panelPlaying) throw new Error("Stop the page's existing audio before starting shell audio.");
        if (shellAudioConfig.remote) throw new Error("This first shell audio test uses local receive audio.");
        const config = { ...shellAudioConfig, receive: shellReceiveChoice(shellAudioConfig) };
        if (config.receive.source === "radio" && config.receive.device === null) throw new Error("Select a radio input in Audio Settings first.");
        const owner = rigpiAudioHost.contentWindow;
        const Context = owner.AudioContext || owner.webkitAudioContext;
        if (!Context) throw new Error("Receive audio is unavailable in this browser.");
        generation = ++shellRxGeneration;
        const context = new Context();
        const gain = context.createGain();
        gain.gain.value = rigpiShell.transmit?.pttRequested ? 0 : shellReceiveVolume / 100;
        const analyser = context.createAnalyser();
        analyser.fftSize = 2048;
        const panner = context.createStereoPanner();
        panner.pan.value = shellPan;
        analyser.connect(gain);
        gain.connect(panner);
        panner.connect(context.destination);
        const session = { context, gain, config, analyser, panner, meterSamples: new Float32Array(analyser.fftSize), meterBins: new Uint8Array(analyser.frequencyBinCount), sources: new Set(), nextTime: 0, socket: null,
            timeout: null, watchdog: null, muted: false, rate: 48000, lastPacket: 0 };
        shellRx = session;
        Object.assign(window.rigpiShell.audioHost, { packets: 0, samples: 0, lastPacket: null });
        setShellAudioStatus("starting");
        await context.resume();
        if (shellSink && typeof context.setSinkId === "function") {
            try { await context.setSinkId(shellSink); }
            catch (_) {
                shellSink = "";
                try { localStorage.removeItem("rigpi_shell_sink"); } catch (_) {}
                document.getElementById("rigpiShellAudioMessage").textContent = "Saved speaker unavailable. Using system default.";
            }
        }
        if (generation !== shellRxGeneration || shellRx !== session) return;
        if (context.state !== "running") throw new Error("Click Start audio again to enable playback.");
        const socket = new owner.WebSocket(config.url);
        session.socket = socket;
        socket.binaryType = "arraybuffer";
        session.timeout = setTimeout(() => {
            if (shellRx === session) stopShellAudio("error", "No receive audio arrived. Check the selected source and try again.");
        }, 10000);
        socket.onopen = () => {
            if (shellRx !== session) return;
            socket.send(JSON.stringify({ cmd: "audio_on", source: config.receive.source, device: config.receive.device }));
            setShellAudioStatus("waiting");
            startShellSdrReceive(session);
        };
        socket.onmessage = (event) => {
            if (shellRx !== session) return;
            if (typeof event.data === "string") {
                let message;
                try { message = JSON.parse(event.data); } catch (_) { return; }
                if (!message || typeof message !== "object") return;
                if (message.type === "sdr_tx_mute") {
                    session.muted = message.muted === true;
                    if (session.rtcGate && session.rtcActive) session.rtcGate.gain.value = session.muted ? 0 : 1;
                    if (session.muted) flushShellAudio(session);
                }
                if (message.type === "audio_reset") flushShellAudio(session);
                if (message.owrx_active === true) {
                    stopShellAudio("error", "Remote receive audio is outside this first shell test.");
                }
                return;
            }
            const data = new DataView(event.data);
            if (data.byteLength < 3 || data.getUint8(0) !== 1 || (data.byteLength - 1) % 2) return;
            const state = window.rigpiShell.audioHost;
            state.packets += 1;
            state.samples += (data.byteLength - 1) / 2;
            state.lastPacket = new Date().toISOString();
            session.lastPacket = Date.now();
            clearTimeout(session.timeout);
            if (state.status !== "playing") setShellAudioStatus("playing");
            if (session.rtcActive || session.muted || context.state !== "running") return;
            const count = (data.byteLength - 1) / 2;
            const buffer = context.createBuffer(1, count, session.rate);
            const samples = buffer.getChannelData(0);
            for (let i = 0; i < count; i += 1) samples[i] = data.getInt16(1 + i * 2, true) / 32768;
            const now = context.currentTime;
            // Absorb packet arrival jitter without repeatedly inserting short gaps.
            if (session.nextTime > now + 0.65) flushShellAudio(session);
            if (session.nextTime < now + 0.005) session.nextTime = now + 0.20;
            const source = context.createBufferSource();
            source.buffer = buffer;
            source.connect(analyser);
            session.sources.add(source);
            source.onended = () => { session.sources.delete(source); source.disconnect(); };
            source.start(session.nextTime);
            session.nextTime += buffer.duration;
        };
        socket.onerror = () => {
            if (shellRx === session) stopShellAudio("error", "Receive audio connection failed. Click Start audio to retry.");
        };
        socket.onclose = () => {
            if (shellRx === session) stopShellAudio("error", "Receive audio disconnected. Click Start audio to retry.");
        };
        session.watchdog = setInterval(() => {
            if (shellRx !== session) return;
            if (session.lastPacket && Date.now() - session.lastPacket > 10000) {
                stopShellAudio("error", "Receive audio stopped arriving. Click Start audio to retry.");
            }
        }, 2000);
    } catch (error) {
        if (generation !== null && generation !== shellRxGeneration) return;
        stopShellAudio("error", error.message);
    }
}

shellAudioButton.addEventListener("click", () => {
    shellExpressPending = null;
    shellExpressRecoveryUntil = 0;
    if (shellRx) stopShellAudio();
    else startShellAudio();
});
window.addEventListener("pagehide", () => stopShellAudio());

window.rigpiShell.navigate = function (path) {
    let destination;

    try {
        if (typeof path !== "string" || !path.trim()) {
            throw new Error("A page URL is required.");
        }
        destination = new URL(path, window.location.href);
        if (destination.origin !== window.location.origin ||
            destination.protocol !== window.location.protocol) {
            throw new Error("Navigation must stay on this RigPi site.");
        }
        if (["/", "/index.php", "/rigpi-shell-test.php"].includes(destination.pathname)) {
            throw new Error("The shell cannot load itself inside the iframe.");
        }
    } catch (error) {
        console.warn("RigPi shell navigation rejected:", error.message);
        return false;
    }

    document.getElementById("rigpiShellAudioSettings").hidden = true;
    document.body.classList.remove("shell-audio-settings");
    updateShellNavigation();
    document.getElementById("rigpiContent").src = destination.href;
    return true;
};

const shellNavButtons = document.querySelectorAll("#rigpiShellNav button[data-page]");

const shellMenuGroups = document.querySelectorAll("#rigpiShellNav details");

function closeShellMenus() {
    shellMenuGroups.forEach((group) => { group.open = false; });
}

shellMenuGroups.forEach((group) => {
    group.addEventListener("toggle", () => {
        if (!group.open) return;
        shellMenuGroups.forEach((other) => {
            if (other !== group) other.open = false;
        });
        const panel = group.querySelector(".shell-menu");
        const rect = group.querySelector("summary").getBoundingClientRect();
        panel.style.left = Math.max(10, Math.min(rect.left, window.innerWidth - panel.offsetWidth - 10)) + "px";
        panel.style.top = rect.bottom + 8 + "px";
    });
});

document.addEventListener("click", (event) => {
    if (!event.target.closest("#rigpiShellNav details")) closeShellMenus();
});

function dismissShellMenus(event) {
    if (event.key !== "Escape") return;
    const openGroup = document.querySelector("#rigpiShellNav details[open]");
    if (openGroup) {
        closeShellMenus();
        openGroup.querySelector("summary").focus();
    }
}
document.addEventListener("keydown", dismissShellMenus);
window.addEventListener("resize", closeShellMenus);

function embeddedHeaderControls() {
    const frame = document.getElementById("rigpiContent");
    const doc = frame.contentDocument;
    return {
        searchText: doc && doc.getElementById("searchText"),
        searchButton: doc && doc.getElementById("searchButton"),
        logoutButton: doc && doc.getElementById("logoutButton")
    };
}

document.getElementById("rigpiShellSearch").addEventListener("submit", (event) => {
    event.preventDefault();
    const controls = embeddedHeaderControls();
    if (controls.searchText && controls.searchButton) {
        controls.searchText.value = document.getElementById("rigpiShellSearchText").value;
        controls.searchButton.click();
    }
});

document.getElementById("rigpiShellLogout").addEventListener("click", () => {
    const controls = embeddedHeaderControls();
    if (controls.logoutButton) controls.logoutButton.click();
});

let shellSearchSyncTimer = null;

document.getElementById("rigpiShellSearchText").addEventListener("input", (event) => {
    try {
        const controls = embeddedHeaderControls();
        if (controls.searchText) controls.searchText.value = event.target.value;
    } catch (error) {
        // The iframe may be changing pages.
    }
});

function hideEmbeddedHeader(frame) {
    clearInterval(shellSearchSyncTimer);
    shellSearchSyncTimer = null;
    const shellControls = document.getElementById("rigpiShellControls");
    shellControls.hidden = true;
    try {
        const doc = frame.contentDocument;
        if (!doc) return;
        const controls = embeddedHeaderControls();
        const header = controls.logoutButton && controls.logoutButton.closest("nav.navbar");
        if (!header) return;
        if (!controls.searchText || !controls.searchButton) return;
        const shellSearchText = document.getElementById("rigpiShellSearchText");
        shellSearchText.value = controls.searchText.value;
        // Legacy pages seed DX asynchronously with .val(), without input events.
        let lastSearchValue = controls.searchText.value;
        shellSearchSyncTimer = setInterval(() => {
            if (!controls.searchText.isConnected) return;
            const value = controls.searchText.value;
            if (value !== lastSearchValue) {
                lastSearchValue = value;
                if (shellSearchText.value !== value) shellSearchText.value = value;
            }
        }, 250);
        shellControls.hidden = false;
        header.setAttribute("data-rigpi-shell-header", "");
        const style = doc.createElement("style");
        style.id = "rigpiShellHeaderStyle";
        style.textContent = 'nav[data-rigpi-shell-header] { display: none !important; }';
        doc.head.appendChild(style);
        frame.contentWindow.dispatchEvent(new Event("resize"));
    } catch (error) {
        shellControls.hidden = true;
        console.warn("RigPi shell header unchanged:", error.message);
    }
}

function syncShellMenus(frame) {
    let doc;
    try {
        doc = frame.contentDocument;
        if (!doc) return;
        if (new URL(window.rigpiShell.currentUrl).pathname === "/login.php") {
            shellMenuGroups.forEach((group) => {
                group.hidden = true;
                group.querySelector(".shell-menu").replaceChildren();
            });
            return;
        }
        [["rigpiShellSettings", "Preview"], ["rigpiShellHelp", "help"]].forEach(([groupId, sourceId]) => {
            const group = document.getElementById(groupId);
            const source = doc.getElementById(sourceId);
            const menu = source && source.parentElement.querySelector("ul.dropdown-menu");
            if (!menu) {
                if (doc.querySelector("nav")) group.hidden = true;
                return;
            }
            const panel = group.querySelector(".shell-menu");
            panel.replaceChildren();
        if (group.id === "rigpiShellSettings") {
            const audioSettings = document.createElement("button");
            audioSettings.type = "button";
            audioSettings.textContent = "Audio Settings";
            audioSettings.dataset.shellAudioSettings = "true";
            audioSettings.setAttribute("aria-controls", "rigpiShellAudioSettings");
            audioSettings.addEventListener("click", openShellAudioSettings);
            panel.appendChild(audioSettings);
        }
            menu.querySelectorAll("a[href], button").forEach((item) => {
                let control;
                if (item.tagName === "A") {
                    const url = new URL(item.getAttribute("href"), doc.baseURI);
                    if (url.origin === window.location.origin && url.protocol === window.location.protocol) {
                        control = document.createElement("button");
                        control.type = "button";
                        control.dataset.page = url.pathname + url.search + url.hash;
                        control.setAttribute("aria-controls", "rigpiContent");
                        control.addEventListener("click", () => {
                            closeShellMenus();
                            window.rigpiShell.navigate(control.dataset.page);
                        });
                    } else if (url.protocol === "https:" || url.protocol === "http:") {
                        control = document.createElement("a");
                        control.href = url.href;
                        control.target = "_blank";
                        control.rel = "noopener noreferrer";
                        control.addEventListener("click", closeShellMenus);
                    } else return;
                } else if (item.textContent.trim() === "Welcome to RigPi") {
                    control = document.createElement("button");
                    control.type = "button";
                    control.addEventListener("click", () => {
                        closeShellMenus();
                        const currentFrame = document.getElementById("rigpiContent").contentWindow;
                        if (typeof currentFrame.showRigPiWelcome === "function") {
                            currentFrame.showRigPiWelcome(true);
                        } else {
                            window.rigpiShell.navigate("/tuner.php?welcome=1");
                        }
                    });
                } else return;
                control.textContent = item.textContent.trim();
                panel.appendChild(control);
            });
            group.hidden = !panel.children.length;
        });
        doc.addEventListener("pointerdown", closeShellMenus);
        doc.addEventListener("keydown", dismissShellMenus);
    } catch (error) {
        console.warn("RigPi shell menus unavailable:", error.message);
        shellMenuGroups.forEach((group) => { group.hidden = true; });
    }
}

const shellShortcutTargets = new Map();
document.querySelectorAll("#rigpiShellNav > button[data-page], #rigpiShellNav > details > summary").forEach((control) => {
    const letter = control.querySelector("u");
    if (!letter) return;
    const key = letter.textContent.trim().toLowerCase();
    shellShortcutTargets.set(key, control);
    control.setAttribute("aria-keyshortcuts", "Alt+" + key.toUpperCase());
    control.title = "Alt+" + key.toUpperCase() + " (Option on Mac)";
});

function handleShellShortcut(event) {
    if (!event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing ||
        event.getModifierState("AltGraph")) return;
    // Option produces special characters on macOS; code identifies the letter key.
    const key = /^Key[A-Z]$/.test(event.code)
        ? event.code.slice(3).toLowerCase() : event.key.toLowerCase();
    const control = shellShortcutTargets.get(key);
    if (!control || control.closest("[hidden]") || control.disabled) return;
    const target = event.target;
    const active = target && target.ownerDocument && target.ownerDocument.activeElement;
    const editing = (element) => element && (element.isContentEditable ||
        element.closest('input, textarea, select, [role="textbox"], [role="combobox"]'));
    if (editing(target) || editing(active)) {
        // Keep character entry, and block the legacy menu shortcut behind the field.
        event.stopImmediatePropagation();
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    if (event.repeat) return;
    control.focus();
    control.click();
}

document.addEventListener("keydown", handleShellShortcut, true);

function updateShellNavigation() {
    const showingAudio = !document.getElementById("rigpiShellAudioSettings").hidden;
    const currentPath = !showingAudio && window.rigpiShell.currentUrl
        ? new URL(window.rigpiShell.currentUrl).pathname
        : null;

    document.querySelectorAll("#rigpiShellNav button[data-page]").forEach((button) => {
        if (new URL(button.dataset.page, window.location.href).pathname === currentPath) {
            button.setAttribute("aria-current", "page");
        } else {
            button.removeAttribute("aria-current");
        }
    });
    const audioItem = document.querySelector('[data-shell-audio-settings]');
    if (audioItem) {
        if (showingAudio) audioItem.setAttribute("aria-current", "page");
        else audioItem.removeAttribute("aria-current");
    }
    shellMenuGroups.forEach((group) => {
        group.classList.toggle("has-current", !!group.querySelector('[aria-current="page"]'));
    });
}

shellNavButtons.forEach((button) => {
    button.addEventListener("click", () => {
        window.rigpiShell.navigate(button.dataset.page);
    });
});

document.getElementById("rigpiContent").addEventListener("load", function () {
    const timestamp = new Date().toISOString();
    window.rigpiShell.lastLoaded = timestamp;
    window.rigpiShell.loadCount += 1;
    window.rigpiShell.currentUrl = null;

    try {
        window.rigpiShell.currentUrl = this.contentWindow.location.href;
        console.log("RigPi iframe loaded:", window.rigpiShell.currentUrl, timestamp);
    } catch (error) {
        console.log("RigPi iframe loaded: URL unavailable", timestamp);
    }
    closeShellMenus();
    syncShellMenus(this);
    hideEmbeddedHeader(this);
    applyShellSdrVisibility(this);
    bindShellExpress(this);
    syncShellAudioPage();
    try {
        this.contentDocument.addEventListener("keydown", handleShellShortcut, true);
    } catch (error) {
        // Only same-origin embedded pages can use shell shortcuts.
    }
    updateShellNavigation();
    shellMenuGroups.forEach((group) => {
        group.classList.toggle("has-current", !!group.querySelector('[aria-current="page"]'));
    });
});

setInterval(() => {
    const seconds = Math.floor(
        (Date.now() - window.rigpiShell.started) / 1000
    );

    console.log("RigPi shell alive:", seconds, "seconds");
}, 5000);
</script>
<script>
// Microphone capture belongs to the persistent audio iframe, independent of page navigation.
let shellMic = null;
let shellMicGeneration = 0;
let shellMicStarting = false;
let shellMicDevice = "";
try { shellMicDevice = localStorage.getItem("rigpi_shell_microphone") || ""; } catch (_) {}
window.rigpiShell.microphone = { status: "off", label: "", rms: -60, peak: -60 };
function setShellMicrophoneStatus(message) {
    document.getElementById("rigpiShellMicrophoneStatus").textContent = message;
    const button = document.getElementById("rigpiShellMicrophoneCheck");
    button.textContent = shellMicStarting ? "Cancel microphone check" : shellMic ? "Stop microphone check" : "Check microphone";
    button.setAttribute("aria-pressed", String(!!shellMic));
    document.getElementById("rigpiShellMicrophoneInput").disabled = shellMicStarting || !!shellMic;
}
async function refreshShellMicrophones() {
    const select = document.getElementById("rigpiShellMicrophoneInput");
    try {
        const devices = await rigpiAudioHost.contentWindow.navigator.mediaDevices?.enumerateDevices();
        const options = [new Option("System default", "")];
        for (const device of devices || []) {
            if (device.kind === "audioinput" && device.deviceId && device.deviceId !== "default") options.push(new Option(device.label || "Microphone " + options.length, device.deviceId));
        }
        if (shellMicDevice && !options.some(o => o.value === shellMicDevice)) options.push(new Option("Selected microphone unavailable", shellMicDevice));
        select.replaceChildren(...options);
        select.value = shellMicDevice;
    } catch (_) { /* Keep the current selection when devices cannot be listed. */ }
}
function stopShellMicrophone(message = "Microphone off") {
    stopShellTransmit();
    ++shellMicGeneration;
    shellMicStarting = false;
    const mic = shellMic;
    shellMic = null;
    if (mic) {
        mic.stream.getTracks().forEach(track => track.stop());
        try { mic.source.disconnect(); mic.analyser.disconnect(); } catch (_) {}
        mic.context.close().catch(() => {});
    }
    Object.assign(rigpiShell.microphone, { status: "off", label: "", rms: -60, peak: -60 });
    for (const name of ["Rms", "Peak"]) {
        document.getElementById("rigpiShellMicrophone" + name).value = -60;
        document.getElementById("rigpiShellMicrophone" + name + "Value").value = "— dBFS";
    }
    setShellMicrophoneStatus(message);
    console.log("RigPi shell microphone:", message);
}
async function startShellMicrophone() {
    if (shellMic || shellMicStarting) return;
    const owner = rigpiAudioHost.contentWindow;
    if (!owner.isSecureContext || !owner.navigator.mediaDevices?.getUserMedia) {
        setShellMicrophoneStatus("Microphone access requires a secure browser connection.");
        return;
    }
    const generation = ++shellMicGeneration;
    shellMicStarting = true;
    rigpiShell.microphone.status = "requesting";
    setShellMicrophoneStatus("Waiting for microphone access…");
    let stream = null, context = null;
    try {
        const audio = { channelCount: 1, echoCancellation: false, noiseSuppression: false, autoGainControl: false };
        if (shellMicDevice) audio.deviceId = { exact: shellMicDevice };
        stream = await owner.navigator.mediaDevices.getUserMedia({ audio, video: false });
        if (generation !== shellMicGeneration) { stream.getTracks().forEach(t => t.stop()); return; }
        const AudioContext = owner.AudioContext || owner.webkitAudioContext;
        context = new AudioContext();
        await context.resume();
        if (generation !== shellMicGeneration) { stream.getTracks().forEach(t => t.stop()); await context.close(); return; }
        const source = context.createMediaStreamSource(stream);
        const analyser = context.createAnalyser();
        analyser.fftSize = 2048;
        source.connect(analyser);
        shellMic = { stream, context, source, analyser, samples: new Float32Array(analyser.fftSize), generation };
        shellMicStarting = false;
        const track = stream.getAudioTracks()[0];
        track.addEventListener("ended", () => { if (shellMic?.generation === generation) stopShellMicrophone("Microphone disconnected"); });
        Object.assign(rigpiShell.microphone, { status: "checking", label: track.label, started: Date.now() });
        setShellMicrophoneStatus("Checking — " + (track.label || "Microphone"));
        refreshShellMicrophones();
        console.log("RigPi shell microphone:", "checking", track.label);
    } catch (error) {
        stream?.getTracks().forEach(t => t.stop());
        if (context && context.state !== "closed") context.close().catch(() => {});
        if (generation !== shellMicGeneration) return;
        shellMicStarting = false;
        rigpiShell.microphone.status = "error";
        const message = error.name === "NotAllowedError" ? "Microphone permission was not granted." : error.name === "NotFoundError" || error.name === "OverconstrainedError" ? "Selected microphone is unavailable. Choose another microphone." : "Microphone could not start: " + error.message;
        setShellMicrophoneStatus(message);
        console.warn("RigPi shell microphone:", error.name, error.message);
    }
}
document.getElementById("rigpiShellMicrophoneCheck").addEventListener("click", () => {
    if (shellMic || shellMicStarting) stopShellMicrophone(); else startShellMicrophone();
});
document.getElementById("rigpiShellMicrophoneInput").addEventListener("change", event => {
    shellMicDevice = event.target.value;
    try { localStorage.setItem("rigpi_shell_microphone", shellMicDevice); } catch (_) {}
});
document.getElementById("rigpiShellLogout").addEventListener("click", () => stopShellMicrophone());
window.addEventListener("pagehide", () => stopShellMicrophone());
setInterval(() => {
    if (!shellMic) return;
    try {
        if (/login|logout/i.test(document.getElementById("rigpiContent").contentWindow.location.pathname)) { stopShellMicrophone(); return; }
    } catch (_) { stopShellMicrophone(); return; }
    const mic = shellMic;
    mic.analyser.getFloatTimeDomainData(mic.samples);
    let sum = 0, peak = 0;
    for (const sample of mic.samples) { sum += sample * sample; peak = Math.max(peak, Math.abs(sample)); }
    const rmsDb = Math.max(-60, Math.min(0, 20 * Math.log10(Math.max(0.000001, Math.sqrt(sum / mic.samples.length)))));
    const peakDb = Math.max(-60, Math.min(0, 20 * Math.log10(Math.max(0.000001, peak))));
    Object.assign(rigpiShell.microphone, { rms: rmsDb, peak: peakDb });
    for (const [name, value] of [["Rms", rmsDb], ["Peak", peakDb]]) {
        document.getElementById("rigpiShellMicrophone" + name).value = value;
        document.getElementById("rigpiShellMicrophone" + name + "Value").value = value.toFixed(1) + " dBFS";
    }
}, 100);
</script>
<script>
let shellTx = null;
let shellTxGeneration = 0;
let shellTxDevice = null;
try { shellTxDevice = localStorage.getItem("rigpi_shell_tx_device"); } catch (_) {}
window.rigpiShell.transmit = { status: "disconnected", muted: true };
function refreshShellTransmitControls() {
    const select = document.getElementById("rigpiShellTransmitDevice");
    if (shellTx) return;
    try {
        const doc = document.getElementById("rigpiContent").contentDocument;
        const source = doc.querySelector("#sdrIframe, #elmerSdrFrame")?.contentDocument.getElementById("txDevice");
        if (!source?.options.length) return;
        const options = [new Option("Select radio output", "")];
        for (const option of source.options) if (/^\d+$/.test(option.value)) options.push(new Option(option.text, option.value));
        if (shellTxDevice === null) shellTxDevice = source.value;
        if (shellTxDevice && !options.some(o => o.value === shellTxDevice)) options.push(new Option("Selected radio output unavailable", shellTxDevice));
        select.replaceChildren(...options);
        select.value = shellTxDevice || "";
    } catch (_) {}
}
function setShellTransmitStatus(status, message, muted = true) {
    rigpiShell.transmit.status = status;
    rigpiShell.transmit.muted = muted;
    const button = document.getElementById("rigpiShellTransmitConnect");
    button.textContent = shellTx ? "Disconnect TX audio" : "Connect TX audio";
    button.setAttribute("aria-pressed", String(!!shellTx));
    document.getElementById("rigpiShellTransmitDevice").disabled = !!shellTx;
    document.getElementById("rigpiShellTransmitStatus").textContent = message;
    console.log("RigPi shell transmit:", status, message);
}
function stopShellTransmit(message = "Disconnected") {
    ++shellTxGeneration;
    const tx = shellTx;
    shellTx = null;
    updateShellPttReceiveMute(false);
    if (tx) {
        clearTimeout(tx.timeout);
        tx.abort.abort();
        tx.gain.gain.value = 0;
        tx.pc.close();
        try { tx.gain.disconnect(); shellMic?.source.disconnect(tx.gain); } catch (_) {}
        tx.destination.stream.getTracks().forEach(track => track.stop());
        fetch(tx.base + "/api/audio/tx_gate", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({source_id: tx.id, active: false}), keepalive: true}).catch(() => {});
    }
    setShellTransmitStatus("disconnected", message);
}
async function connectShellTransmit() {
    if (shellTx) return;
    const selected = document.getElementById("rigpiShellTransmitDevice").value;
    if (!/^\d+$/.test(selected)) { setShellTransmitStatus("error", "Select the radio USB output first."); return; }
    const doc = document.getElementById("rigpiContent").contentDocument;
    const panel = doc.querySelector("#sdrIframe, #elmerSdrFrame")?.contentWindow;
    const base = panel?._sdrBase;
    if (!base || new URL(base, location.href).origin !== location.origin) { setShellTransmitStatus("error", "Open Tuner to read the radio audio connection."); return; }
    const generation = ++shellTxGeneration;
    if (!shellMic) await startShellMicrophone();
    if (!shellMic || generation !== shellTxGeneration) return;
    const owner = rigpiAudioHost.contentWindow;
    const gain = shellMic.context.createGain();
    gain.gain.value = 0;
    const destination = shellMic.context.createMediaStreamDestination();
    shellMic.source.connect(gain);
    gain.connect(destination);
    let iceServers = [];
    try { iceServers = panel.eval("iceServers"); } catch (_) {}
    const pc = new owner.RTCPeerConnection({iceServers});
    const tx = {pc, gain, destination, base, id: "shell-" + Date.now() + "-" + Math.random().toString(36).slice(2), abort: new AbortController(), timeout: null};
    shellTx = tx;
    tx.timeout = setTimeout(() => { if (shellTx === tx) stopShellTransmit("Connection timed out. Connect again to retry."); }, 25000);
    pc.addTransceiver(destination.stream.getAudioTracks()[0], {direction: "sendonly", streams: [destination.stream]});
    pc.onconnectionstatechange = () => {
        if (shellTx !== tx) return;
        if (pc.connectionState === "connected") {
            clearTimeout(tx.timeout);
            setShellTransmitStatus("connected", "Connected — microphone muted");
        } else if (["failed", "closed", "disconnected"].includes(pc.connectionState)) stopShellTransmit("Connection lost. Connect again to retry.");
    };
    setShellTransmitStatus("connecting", "Connecting — microphone muted");
    try {
        await pc.setLocalDescription(await pc.createOffer());
        if (pc.iceGatheringState !== "complete") await new Promise((resolve, reject) => {
            const check = () => { if (pc.iceGatheringState === "complete") { pc.removeEventListener("icegatheringstatechange", check); resolve(); } };
            pc.addEventListener("icegatheringstatechange", check);
            tx.abort.signal.addEventListener("abort", () => { pc.removeEventListener("icegatheringstatechange", check); reject(new Error("Connection cancelled")); }, {once: true});
            check();
        });
        if (shellTx !== tx || generation !== shellTxGeneration) return;
        const response = await fetch(base + "/api/audio/tx_offer", {method: "POST", headers: {"Content-Type": "application/json"}, signal: tx.abort.signal, body: JSON.stringify({sdp: pc.localDescription.sdp, type: pc.localDescription.type, tx_device: Number(selected), source_id: tx.id, mic_source: shellMicDevice, mic_label: rigpiShell.microphone.label})});
        if (!response.ok) throw new Error("Audio service returned " + response.status);
        const answer = await response.json();
        if (shellTx !== tx) return;
        await pc.setRemoteDescription(answer);
        const gate = await fetch(base + "/api/audio/tx_gate", {method: "POST", headers: {"Content-Type": "application/json"}, signal: tx.abort.signal, body: JSON.stringify({source_id: tx.id, active: false})});
        if (!gate.ok) throw new Error("Could not confirm microphone mute");
    } catch (error) {
        if (shellTx === tx) stopShellTransmit("TX audio failed: " + error.message);
    }
}
document.getElementById("rigpiShellTransmitConnect").addEventListener("click", () => {
    if (shellTx) stopShellTransmit(); else connectShellTransmit();
});
document.getElementById("rigpiShellTransmitDevice").addEventListener("change", event => {
    shellTxDevice = event.target.value;
    try { localStorage.setItem("rigpi_shell_tx_device", shellTxDevice); } catch (_) {}
});
</script>
<script>
function updateShellPttReceiveMute(active) {
    rigpiShell.transmit.pttRequested = !!active;
    if (shellRx) {
        shellRx.gain.gain.cancelScheduledValues(shellRx.context.currentTime);
        shellRx.gain.gain.value = active ? 0 : shellReceiveVolume / 100;
    }
}
function setShellTransmitGate(active, owner) {
    const tx = shellTx;
    active = !!active && !document.hidden && owner === document.getElementById("rigpiContent").contentWindow;
    if (!tx) {
        if (active) setShellTransmitStatus("disconnected", "Connect TX audio in Audio Settings first.");
        return Promise.resolve();
    }
    if (active && (tx.pc.connectionState !== "connected" || !shellMic || shellMic.stream.getAudioTracks()[0]?.readyState !== "live")) active = false;
    tx.pttWanted = active;
    const sequence = tx.gateSequence = (tx.gateSequence || 0) + 1;
    updateShellPttReceiveMute(active);
    // Close locally before waiting for the service; a delayed reply cannot reopen a released PTT.
    if (!active) {
        tx.gain.gain.cancelScheduledValues(shellMic?.context.currentTime || 0);
        tx.gain.gain.value = 0;
        if (tx.pc.connectionState === "connected") setShellTransmitStatus("connected", "Connected — microphone muted");
    }
    tx.gateQueue = (tx.gateQueue || Promise.resolve()).then(async () => {
        if (shellTx !== tx) return;
        if (active && (!tx.pttWanted || sequence !== tx.gateSequence)) return;
        const response = await fetch(tx.base + "/api/audio/tx_gate", {
            method: "POST", headers: {"Content-Type": "application/json"},
            signal: AbortSignal.timeout(3000),
            body: JSON.stringify({source_id: tx.id, active})
        });
        const result = await response.json();
        if (!response.ok || !result.ok || (active && result.active_source !== tx.id)) throw new Error("Microphone gate was not confirmed");
        if (shellTx !== tx) return;
        const open = active && tx.pttWanted && sequence === tx.gateSequence && !document.hidden && owner === document.getElementById("rigpiContent").contentWindow && tx.pc.connectionState === "connected";
        if (open) {
            tx.gain.gain.value = 1;
            setShellTransmitStatus("connected", "PTT — microphone live", false);
        }
        console.log("RigPi shell PTT microphone:", open ? "open" : "muted", "sequence", sequence);
    }).catch(error => {
        if (shellTx === tx) stopShellTransmit("Microphone muted: " + error.message);
    });
    return tx.gateQueue;
}
function bindShellKnobPtt(owner) {
    const widget = owner.pknob;
    if (!widget || widget.shellPttLayoutBound) return;
    const elements = [widget.elements?.PTT, widget.elements?.PTTOn];
    if (!elements.every(element => Array.isArray(element?.handlers) && element.handlers.some(handler => handler.name === "knobPttClicked"))) return;
    let lastClick = 0;
    function shellKnobPttClicked() {
        const now = Date.now();
        if (now - lastClick < 500) return;
        lastClick = now;
        const active = !(owner.xmit === true || owner.tKnobPTT === 1);
        owner.setLocalBrowserMicGate(active);
        owner.setPTT(active ? 1 : 0, 0);
        owner.tKnobPTT = active ? 1 : 0;
    }
    // The shell hides the legacy header, so the old top-of-page exclusion is invalid.
    // Keep the widget's pointer, keyboard, momentary release and latch behavior.
    elements.forEach(element => {
        element.handlers = element.handlers.map(handler => handler.name === "knobPttClicked" ? shellKnobPttClicked : handler);
    });
    widget.shellPttLayoutBound = true;
    console.log("RigPi shell PTT layout bound");
}
function bindShellTunerPtt() {
    try {
        const frame = document.getElementById("rigpiContent");
        const owner = frame.contentWindow, page = frame.contentDocument;
        if (owner.location.pathname !== "/tuner.php" || typeof owner.setLocalBrowserMicGate !== "function") return;
        bindShellKnobPtt(owner);
        if (page.documentElement.dataset.shellPttBound) return;
        page.documentElement.dataset.shellPttBound = "true";
        // Bind only the prototype's Tuner document. Existing radio PTT routing stays in the Tuner.
        owner.setLocalBrowserMicGate = function(active, force) {
            active = Boolean(active) && owner.tDisconnected == 0 && owner.tAccessLevel < 4 && !page.hidden;
            if (!force && owner.localBrowserMicGateState === active) return;
            owner.localBrowserMicGateState = active;
            return setShellTransmitGate(active, owner);
        };
        owner.addEventListener("pagehide", () => setShellTransmitGate(false, owner), true);
        page.addEventListener("visibilitychange", () => { if (page.hidden) setShellTransmitGate(false, owner); });
        console.log("RigPi shell Tuner PTT bound");
    } catch (_) {}
}
document.getElementById("rigpiContent").addEventListener("load", () => {
    if (shellTx?.pttWanted) setShellTransmitGate(false);
    bindShellTunerPtt();
});
document.addEventListener("visibilitychange", () => { if (document.hidden) setShellTransmitGate(false); });
setInterval(bindShellTunerPtt, 500);
bindShellTunerPtt();
</script>

<script>
const shellLoopback = {endpoint: null, running: false, busy: false, known: false};
window.rigpiShell.loopback = shellLoopback;
function renderShellLoopback(message) {
    const select = document.getElementById("rigpiShellLoopbackDevice");
    const button = document.getElementById("rigpiShellLoopbackToggle");
    button.textContent = shellLoopback.running ? "Stop loopback" : "Start loopback";
    button.setAttribute("aria-pressed", String(shellLoopback.running));
    button.disabled = shellLoopback.busy || !shellLoopback.known || (!shellLoopback.running && !select.value);
    select.disabled = shellLoopback.busy || shellLoopback.running;
    document.getElementById("rigpiShellLoopbackStatus").textContent = message || (shellLoopback.running ? "Running — " + (select.selectedOptions[0]?.textContent || "loopback output") : "Stopped");
}
async function refreshShellLoopback() {
    if (shellLoopback.busy) return;
    shellLoopback.busy = true;
    renderShellLoopback();
    try {
        let config;
        try { config = readShellAudioConfig(); } catch (_) {}
        config = config || shellAudioConfig;
        if (config?.url) {
            const url = new URL(config.url);
            url.protocol = location.protocol;
            url.pathname = url.pathname.replace(/\/ws\/spectrum$/, "/api/audio/loopback");
            url.search = "";
            if (url.origin !== location.origin || !url.pathname.endsWith("/api/audio/loopback")) throw new Error("Loopback service unavailable");
            shellLoopback.endpoint = url.href;
        }
        if (!shellLoopback.endpoint) throw new Error("Open the Tuner to find the audio service");
        const response = await fetch(shellLoopback.endpoint, {cache:"no-store", signal:AbortSignal.timeout(5000)});
        if (!response.ok) throw new Error("Loopback status unavailable");
        const data = await response.json();
        if (typeof data.running !== "boolean" || !Array.isArray(data.devices)) throw new Error("Invalid loopback status");
        const select = document.getElementById("rigpiShellLoopbackDevice");
        const saved = select.value || localStorage.getItem("rigpi_shell_loopback_device") || localStorage.getItem("loopbackDevice");
        const devices = data.devices.filter(device => /loopback/i.test(device.name) && Number.isInteger(device.index));
        select.replaceChildren(new Option("Select loopback output", ""));
        devices.forEach(device => select.add(new Option(device.name, String(device.index))));
        const chosen = data.running ? String(data.device) : saved;
        if (chosen && devices.some(device => String(device.index) === chosen)) select.value = chosen;
        else if (!data.running && !saved && devices.length) select.value = String(devices[0].index);
        else if (data.running) select.add(new Option("Active output " + data.device, String(data.device), true, true));
        shellLoopback.running = data.running;
        shellLoopback.known = true;
        shellLoopback.busy = false;
        renderShellLoopback(!devices.length && !data.running ? "No loopback output available on the RigPi" : undefined);
    } catch (error) {
        shellLoopback.busy = false;
        shellLoopback.known = false;
        renderShellLoopback(error.message);
    }
}
document.getElementById("rigpiShellLoopbackDevice").addEventListener("change", event => {
    localStorage.setItem("rigpi_shell_loopback_device", event.target.value);
    renderShellLoopback();
});
document.getElementById("rigpiShellLoopbackToggle").addEventListener("click", async () => {
    if (shellLoopback.busy || !shellLoopback.known) return;
    const select = document.getElementById("rigpiShellLoopbackDevice");
    const action = shellLoopback.running ? "stop" : "start";
    if (action === "start" && !select.value) return;
    shellLoopback.busy = true;
    renderShellLoopback(action === "start" ? "Starting loopback" : "Stopping loopback");
    let requestError = "";
    try {
        const response = await fetch(shellLoopback.endpoint, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action, device:Number(select.value)}), signal:AbortSignal.timeout(5000)});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || "Loopback request failed");
        console.log("RigPi shell loopback:", action);
    } catch (error) {
        console.warn("RigPi shell loopback:", error.message);
        requestError = error.message;
    } finally {
        shellLoopback.busy = false;
        await refreshShellLoopback();
        if (requestError) renderShellLoopback(requestError);
    }
});
setInterval(() => {
    if (!document.getElementById("rigpiShellAudioSettings").hidden) refreshShellLoopback();
}, 5000);
</script>
<script>

// Shell-owned receive recording survives navigation of the content iframe.
const shellRecording = {take:null, pending:null, saving:false, starting:false, loading:false, listEndpoint:null, localUrl:null};
rigpiShell.recording = shellRecording;
const recEl = id => document.getElementById("shellRecord" + id);
function shellRecordingEndpoint(config) {
    const current = config || shellRx?.config || shellAudioConfig || readShellAudioConfig();
    if (!current?.url) throw new Error("Open the Tuner to find the audio service");
    const url = new URL(current.url);
    url.protocol = location.protocol;
    url.pathname = url.pathname.replace(/\/ws\/spectrum$/, "/api/audio/recordings");
    url.search = ""; url.hash = "";
    if (url.origin !== location.origin || !url.pathname.endsWith("/api/audio/recordings")) throw new Error("Recording service unavailable");
    return url.href;
}
function renderShellRecording(message) {
    const take = shellRecording.take;
    const active = !!take && !take.stopping;
    recEl("Start").disabled = !!take || shellRecording.starting || shellRecording.saving || !!shellRecording.pending;
    recEl("Pause").disabled = !active;
    recEl("Pause").textContent = take?.recorder.state === "paused" ? "Resume" : "Pause";
    recEl("Stop").disabled = !active;
    recEl("Start").setAttribute("aria-pressed", String(active));
    recEl("Retry").hidden = !shellRecording.pending || shellRecording.saving;
    if (message) recEl("Status").textContent = message;
    if (take) {
        const ms = take.elapsed + (take.recorder.state === "recording" ? performance.now() - take.segment : 0);
        const secs = Math.floor(ms / 1000);
        recEl("Timer").textContent = String(Math.floor(secs / 60)).padStart(2,"0") + ":" + String(secs % 60).padStart(2,"0");
    }
}
function downloadShellRecording() {
    const button = recEl("Download");
    if (button.hidden || !button.dataset.url) return;
    const link = document.createElement("a");
    link.href = button.dataset.url;
    link.download = button.dataset.filename;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function selectShellRecording() {
    const select = recEl("Files"), player = recEl("Player"), link = recEl("Download");
    player.pause();
    if (select.value && shellRecording.listEndpoint) {
        const url = shellRecording.listEndpoint + "/" + encodeURIComponent(select.value);
        player.src = url; link.dataset.url = url; link.dataset.filename = select.value; link.hidden = false;
    } else { player.removeAttribute("src"); player.load(); link.hidden = true; }
}
async function refreshShellRecordings(preferred) {
    if (shellRecording.loading) return;
    shellRecording.loading = true; recEl("Refresh").disabled = true;
    try {
        const endpoint = shellRecordingEndpoint();
        const response = await fetch(endpoint, {cache:"no-store",signal:AbortSignal.timeout(10000)});
        if (!response.ok) throw new Error("Recording list unavailable (" + response.status + ")");
        const data = await response.json();
        if (!Array.isArray(data.files)) throw new Error("Invalid recording list");
        const select = recEl("Files"), old = select.value;
        shellRecording.listEndpoint = endpoint;
        select.replaceChildren();
        for (const file of data.files) {
            const name = typeof file === "string" ? file : file.name;
            if (typeof name !== "string" || !name) continue;
            const option = document.createElement("option"); option.value = name; option.textContent = name; select.append(option);
        }
        if (!select.options.length) select.add(new Option("No recordings", ""));
        const wanted = preferred || old;
        if ([...select.options].some(option => option.value === wanted)) select.value = wanted;
        if (preferred || select.value !== old || !recEl("Player").getAttribute("src")) selectShellRecording();
    } catch (error) { renderShellRecording(error.message); }
    finally { shellRecording.loading = false; recEl("Refresh").disabled = false; }
}
async function saveShellRecording() {
    const pending = shellRecording.pending;
    if (!pending || shellRecording.saving) return;
    shellRecording.saving = true; renderShellRecording("Saving on RigPi…");
    try {
        const form = new FormData(); form.append("file", pending.blob, pending.name);
        const response = await fetch(pending.endpoint, {method:"POST",body:form,signal:AbortSignal.timeout(120000)});
        if (!response.ok) throw new Error("Save failed (" + response.status + ")");
        const data = await response.json();
        if (data.ok === false || typeof data.name !== "string") throw new Error(data.error || "Recording was not saved");
        shellRecording.pending = null;
        recEl("Local").hidden = true;
        if (shellRecording.localUrl) URL.revokeObjectURL(shellRecording.localUrl);
        shellRecording.localUrl = null;
        renderShellRecording("Saved: " + data.name);
        await refreshShellRecordings(data.name);
    } catch (error) {
        renderShellRecording(error.message + ". Retry save or download the unsaved recording before leaving this page.");
    } finally { shellRecording.saving = false; renderShellRecording(); }
}
function stopShellRecording() {
    const take = window.rigpiShell?.recording?.take;
    if (!take || take.stopping) return;
    renderShellRecording();
    if (take.recorder.state === "recording") take.elapsed += performance.now() - take.segment;
    take.stopping = true; clearInterval(take.timer);
    try { take.session.analyser.disconnect(take.destination); } catch (_) {}
    if (take.recorder.state !== "inactive") take.recorder.stop();
    renderShellRecording("Finishing recording…");
}
async function startShellRecording() {
    if (shellRecording.take || shellRecording.starting || shellRecording.saving || shellRecording.pending) return;
    shellRecording.starting = true; renderShellRecording();
    let destination, session;
    try {
        session = shellRx;
        if (!session || session.context.state === "closed" || !session.lastPacket) throw new Error("Start receive audio before recording");
        const owner = rigpiAudioHost.contentWindow;
        if (!owner.MediaRecorder) throw new Error("Recording is unavailable in this browser");
        const endpoint = shellRecordingEndpoint(session.config);
        await session.context.resume();
        if (shellRx !== session) throw new Error("Receive source changed; try recording again");
        destination = session.context.createMediaStreamDestination();
        const mime = ["audio/webm;codecs=opus","audio/webm","audio/ogg;codecs=opus","audio/mp4"].find(type => owner.MediaRecorder.isTypeSupported(type));
        const recorder = new owner.MediaRecorder(destination.stream, mime ? {mimeType:mime} : {});
        const extension = recorder.mimeType.includes("mp4") ? "m4a" : recorder.mimeType.includes("ogg") ? "ogg" : "webm";
        const take = {session,destination,recorder,endpoint,chunks:[],bytes:0,elapsed:0,segment:performance.now(),stopping:false,name:"RigPi_RX_" + new Date().toISOString().replace(/[:.]/g,"-") + "." + extension};
        recorder.ondataavailable = event => {
            if (event.data.size) { take.chunks.push(event.data); take.bytes += event.data.size; }
            if (take.bytes > 128 * 1024 * 1024) stopShellRecording();
        };
        recorder.onerror = event => { renderShellRecording(event.error?.message || "Recording failed"); stopShellRecording(); };
        recorder.onstop = () => {
            clearInterval(take.timer);
            try { session.analyser.disconnect(destination); } catch (_) {}
            destination.stream.getTracks().forEach(track => track.stop());
            if (shellRecording.take !== take) return;
            shellRecording.take = null;
            const blob = new Blob(take.chunks, {type:recorder.mimeType}); take.chunks = [];
            if (!blob.size) { renderShellRecording("No audio was recorded"); return; }
            shellRecording.pending = {blob,name:take.name,endpoint};
            if (shellRecording.localUrl) URL.revokeObjectURL(shellRecording.localUrl);
            shellRecording.localUrl = URL.createObjectURL(blob);
            const link = recEl("Local"); link.href = shellRecording.localUrl; link.download = take.name; link.hidden = false;
            saveShellRecording();
        };
        session.analyser.connect(destination);
        shellRecording.take = take;
        recorder.start(1000);
        recEl("Player").pause();
        take.timer = setInterval(() => {
            renderShellRecording();
            if (take.elapsed + (recorder.state === "recording" ? performance.now() - take.segment : 0) >= 3600000) stopShellRecording();
        }, 250);
        renderShellRecording("Recording receive audio");
    } catch (error) {
        if (destination) { try { session.analyser.disconnect(destination); } catch (_) {} destination.stream.getTracks().forEach(track => track.stop()); }
        if (shellRecording.take?.destination === destination) shellRecording.take = null;
        renderShellRecording(error.message);
    } finally { shellRecording.starting = false; renderShellRecording(); }
}
recEl("Start").addEventListener("click", startShellRecording);
recEl("Stop").addEventListener("click", stopShellRecording);
recEl("Pause").addEventListener("click", () => {
    const take = shellRecording.take;
    if (!take || take.stopping) return;
    if (take.recorder.state === "recording") {
        take.elapsed += performance.now() - take.segment; take.recorder.pause(); renderShellRecording("Recording paused");
    } else if (take.recorder.state === "paused") {
        take.segment = performance.now(); take.recorder.resume(); renderShellRecording("Recording receive audio");
    }
});
recEl("Retry").addEventListener("click", saveShellRecording);
recEl("Refresh").addEventListener("click", () => refreshShellRecordings());
recEl("Files").addEventListener("change", selectShellRecording);
window.addEventListener("beforeunload", event => {
    if (shellRecording.take || shellRecording.pending || shellRecording.saving) { event.preventDefault(); event.returnValue = ""; }
});
renderShellRecording();

</script>

<script>
// Startup includes asynchronous saved settings and the receiver's own iframe.
let shellStartupTimer = null;
let shellStartupSignature = "";
let shellStartupChangedAt = performance.now();
const shellStartupDeadline = performance.now() + 8000;
window.rigpiShell.startup = {phase: "loading", checks: 0};
function setShellStartupProgress(value) {
    const bar = document.getElementById("rigpiShellProgress");
    if (!bar) return;
    const progress = Math.max(Number(bar.getAttribute("aria-valuenow")), value);
    bar.setAttribute("aria-valuenow", String(progress));
    bar.setAttribute("aria-valuetext", Math.round(progress / 20) + " of 5 startup checks complete");
    document.getElementById("rigpiShellProgressFill").style.width = progress + "%";
    window.rigpiShell.startup.progress = progress;
}

function revealShellStartup() {
    if (!document.documentElement.classList.contains("shell-starting")) return;
    clearTimeout(shellStartupTimer);
    const frame = document.getElementById("rigpiContent");
    let ready = false;
    let signature = "";
    let waiting = "page";
    const steps = {page: false, receiver: false, settings: false, fonts: false};
    try {
        const doc = frame.contentDocument;
        const owner = frame.contentWindow;
        if (doc?.body && doc.readyState === "complete" && owner.location.href !== "about:blank") {
            ready = true;
            steps.page = true;
            steps.receiver = true;
            const docs = [doc];
            const receiver = doc.getElementById("sdrIframe");
            if (receiver && !shellHideSdr && owner.getComputedStyle(receiver).display !== "none") {
                const src = receiver.getAttribute("src");
                const rxDoc = receiver.contentDocument;
                if (!src || !rxDoc || rxDoc.readyState !== "complete" ||
                    receiver.contentWindow.location.href !== new URL(src, doc.baseURI).href) {
                    ready = false;
                    waiting = "receiver page";
                    steps.receiver = false;
                } else docs.push(rxDoc);
            }
            steps.settings = !(owner.jQuery?.active > 0);
            steps.fonts = !docs.some(page => page.fonts?.status === "loading");
            if (owner.jQuery?.active > 0) { ready = false; waiting = "saved settings"; }
            if (docs.some(page => page.fonts?.status === "loading")) { ready = false; waiting = "fonts"; }
            hideShellLegacyAudio();
            signature = JSON.stringify(docs.map(page => ({
                height: page.body?.scrollHeight,
                width: page.body?.scrollWidth,
                buttons: Array.from(page.querySelectorAll("button")).map(el => el.textContent.trim()),
                frames: Array.from(page.querySelectorAll("iframe")).map(el => [el.getAttribute("src"), el.offsetWidth, el.offsetHeight]),
                panels: Array.from(page.body?.children || []).map(el => [el.offsetTop, el.offsetWidth, el.offsetHeight])
            })));
        }
    } catch (_) { waiting = "page"; }
    const now = performance.now();
    if (!ready || signature !== shellStartupSignature) {
        shellStartupSignature = signature;
        shellStartupChangedAt = now;
    }
    setShellStartupProgress(Object.values(steps).filter(Boolean).length * 20);
    const timedOut = now >= shellStartupDeadline;
    window.rigpiShell.startup.checks += 1;
    window.rigpiShell.startup.phase = ready ? "settling layout" : waiting;
    if ((!ready || now - shellStartupChangedAt < 400) && !timedOut) {
        shellStartupTimer = setTimeout(revealShellStartup, 80);
        return;
    }
    if (!timedOut) setShellStartupProgress(100);
    // Re-sync the master controls after the asynchronous child settings have arrived.
    hideEmbeddedHeader(frame);
    syncShellMenus(frame);
    applyShellSdrVisibility(frame);
    requestAnimationFrame(() => {
        if (!document.documentElement.classList.contains("shell-starting")) return;
        document.documentElement.classList.remove("shell-starting");
        window.rigpiShell.startupReadyAt = Date.now();
        window.rigpiShell.startup.phase = timedOut ? "timeout" : "ready";
        console.log("RigPi shell ready:", new Date().toISOString(), window.rigpiShell.startup.phase);
    });
}
document.getElementById("rigpiContent").addEventListener("load", revealShellStartup);
window.addEventListener("load", revealShellStartup, {once: true});
revealShellStartup();
</script>

<script src="/rigpi-health-monitor.js?v=1"></script>
</body>
</html>
