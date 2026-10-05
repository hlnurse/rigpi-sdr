<?php if ((int) $level === 1): ?>
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
                <button id="elmerControlMic" class="btn btn-outline-primary btn-sm" type="button" title="Speak one command" aria-label="Speak one command"><i class="fas fa-microphone"></i></button>
                <button id="elmerControlWake" class="btn btn-outline-primary btn-sm" type="button" title="Turn on Hey Radio listening" aria-label="Toggle Hey Radio listening" aria-pressed="false"><i class="fas fa-broadcast-tower"></i> Hey Radio</button>
                <button id="elmerControlSpeak" class="btn btn-outline-primary btn-sm" type="button" title="Read control results aloud" aria-label="Toggle spoken control results" aria-pressed="false"><i class="fas fa-volume-up"></i></button>
            </div>
        </form>
        <div id="elmerControlStatus" class="elmer-control-status" role="status" aria-live="polite"></div>
        <div id="elmerControlResult" class="elmer-control-result" aria-live="polite"></div>
        <details id="elmerControlHistory" class="elmer-control-history"><summary>History</summary><div id="elmerControlHistoryList" class="elmer-control-history-list"></div></details>
        <p class="elmer-control-note">Commands require confirmation and are verified by RigPi. CW text is staged on Hold for operator review. Elmer Control cannot transmit or release Hold.</p>
    </div>
</section>
<script src="/js/elmer_control.js?v=20260820-1"></script>
<?php endif; ?>
