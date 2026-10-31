<?php if ((int) $level >= 1 && (int) $level <= 4): ?>
<section id="elmerControl" class="elmer-control" aria-label="Elmer Control">
    <div class="elmer-control-header">
        <h2 class="elmer-control-title"><img src="/images/AskElmerRigPiW.png" alt="">Elmer Control</h2>
        <button id="elmerControlToggle" class="elmer-control-toggle" type="button" aria-expanded="true" aria-label="Close Elmer Control" title="Close Elmer Control"><i class="fas fa-chevron-right"></i></button>
    </div>
    <div class="elmer-control-body">
        <form id="elmerControlForm">
            <div class="elmer-control-row">
                <div class="elmer-control-command-wrap">
                    <input id="elmerControlCommand" class="form-control" type="text" maxlength="600" autocomplete="off" placeholder="Tune to 7.074 MHz USB" aria-label="Elmer Control command">
                    <span class="elmer-control-history-cue" aria-hidden="true"><span class="history-triangle-up"></span><span class="history-triangle-down"></span></span>
                </div>
                <button id="elmerControlRun" class="btn btn-primary" type="submit">Run</button>
            </div>
            <div class="elmer-control-tools">
                <div id="elmerVoiceOrb" class="elmer-voice-orb" data-state="ready" aria-hidden="true" title="Elmer voice is ready">
                    <span></span><span></span><span></span><span></span><span></span>
                </div>
                <button id="elmerControlMic" class="btn btn-outline-primary btn-sm" type="button" title="Tap to start or finish a voice command" aria-label="Elmer push-to-talk"><i class="fas fa-microphone"></i><span>Elmer PTT</span></button>
                <button id="elmerControlSpeak" class="btn btn-outline-primary btn-sm" type="button" title="Read control results aloud" aria-label="Spoken Elmer results" aria-pressed="false"><i class="fas fa-volume-up"></i></button>
            </div>
        </form>
        <div id="elmerControlStatus" class="elmer-control-status" role="status" aria-live="polite"></div>
        <div id="elmerControlResult" class="elmer-control-result" aria-live="polite"></div>
        <div id="elmerControlStateAlerts" class="elmer-control-status" role="status" aria-live="polite" aria-atomic="true"></div>
        <details id="elmerControlHistory" class="elmer-control-history"><summary>History</summary><div id="elmerControlHistoryList" class="elmer-control-history-list"></div></details>
        <details id="elmerControlAccessHelp" class="elmer-control-history"><summary>Voice alerts and shortcuts</summary><p>Say &ldquo;State alerts on&rdquo; or &ldquo;State alerts off.&rdquo; Two rising tones mean confirmed transmit; the same two tones in reverse mean confirmed receive. PTT is sampled separately every quarter second while state alerts are on. Spoken state alerts wait while the radio is transmitting. Say &ldquo;What is being announced?&rdquo; to read announcement settings, or &ldquo;Where am I?&rdquo; for the selected radio, main frequency, mode, and focused control.</p><p>Say &ldquo;What is focused?&rdquo; for the selected control and its state, or &ldquo;Explain this control&rdquo; / &ldquo;Read tooltip&rdquo; for its description. Elmer remembers the selected control while you use its command box or PTT. Say &ldquo;Focus announcements on&rdquo; to hear labels and states when hovering over controls or navigating with the keyboard; &ldquo;Focus announcements off&rdquo; stops them. Focus announcements are off by default and saved in this browser. Pause the pointer over a control for about two-thirds of a second to hear it; moving away cancels the announcement.</p><p><kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>M</kbd>: start or finish Elmer PTT. <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>Q</kbd>: silence Elmer. Alt is Option on a Mac. Say &ldquo;Keyboard shortcuts off&rdquo; or &ldquo;Keyboard shortcuts on&rdquo; to change this saved preference. Alerts are opt-in and preferences are saved in this browser.</p></details>
        <p class="elmer-control-note">Station changes require confirmation and are verified by RigPi. Readbacks and speech controls do not change radio settings. Spoken results use an AI-generated voice. With spoken results on, Elmer reads each proposal and accepts a separate PTT response of Approve or Cancel. Radio Power On also connects the selected radio; Power Off is blocked while PTT is active. A guest sharing another account's radio must receive administrator approval for split changes. CW text is staged on Hold for operator review. Log or Log contact prepares the Log Editor, using DX Call when no callsign is spoken; only the operator can save the contact. Say &ldquo;DX call&rdquo; or &ldquo;What is DX call?&rdquo; to hear the current DX Call. Say &ldquo;Always speak DX call&rdquo; to announce changes while this page is open; &ldquo;Stop speaking DX call&rdquo; turns announcements off. Say &ldquo;Speak current frequency,&rdquo; &ldquo;Speak current mode,&rdquo; or &ldquo;Speak current frequency and mode&rdquo; for a one-time readback. &ldquo;Speak both&rdquo; also reads frequency and mode. These preferences are saved in this browser. Say &ldquo;Always speak frequency,&rdquo; &ldquo;Always speak mode,&rdquo; or &ldquo;Always speak frequency and mode&rdquo; to announce settled changes. Use &ldquo;Stop speaking frequency,&rdquo; &ldquo;Stop speaking mode,&rdquo; or &ldquo;Stop speaking frequency and mode&rdquo; to turn them off. Say &ldquo;What can I say?&rdquo; for spoken command help, &ldquo;Repeat that&rdquo; to hear the last spoken result, &ldquo;Station status,&rdquo; &ldquo;Speak signal strength,&rdquo; or &ldquo;Speak volume&rdquo; for live radio readbacks. &ldquo;Stop speaking now&rdquo; mutes Elmer; &ldquo;Voice on&rdquo; resumes speech. Elmer Control cannot transmit or release Hold.</p>
    </div>
</section>
<script src="/js/elmer_control.js?v=20261029-silent-key1"></script>
<?php endif; ?>
