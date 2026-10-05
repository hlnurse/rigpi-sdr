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
                <button id="elmerControlSpeak" class="btn btn-outline-primary btn-sm" type="button" title="Read control results aloud" aria-label="Toggle spoken control results" aria-pressed="false"><i class="fas fa-volume-up"></i></button>
            </div>
        </form>
        <div id="elmerControlStatus" class="elmer-control-status" role="status" aria-live="polite"></div>
        <div id="elmerControlResult" class="elmer-control-result" aria-live="polite"></div>
        <details id="elmerControlHistory" class="elmer-control-history"><summary>History</summary><div id="elmerControlHistoryList" class="elmer-control-history-list"></div></details>
        <p class="elmer-control-note">Commands require confirmation and are verified by RigPi. Spoken results use an AI-generated voice. With spoken results on, Elmer reads each proposal and accepts a separate PTT response of Approve or Cancel. Radio Power On also connects the selected radio; Power Off is blocked while PTT is active. A guest sharing another account's radio must receive administrator approval for split changes. CW text is staged on Hold for operator review. Log or Log contact prepares the Log Editor, using DX Call when no callsign is spoken; only the operator can save the contact. Elmer Control cannot transmit or release Hold.</p>
    </div>
</section>
<script src="/js/elmer_control.js?v=20260907-marin6"></script>
<?php endif; ?>
