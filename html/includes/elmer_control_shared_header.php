<?php
// The main shell has its own navigation; mount one drawer in its content page.
if (empty($_SESSION['myUsername']) || !isset($level) || !in_array((int)$level, [1, 2, 3, 4], true)) return;
$elmerSharedPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (in_array($elmerSharedPage, ['tuner.php', 'keyer.php'], true)) return;
$elmerSharedRadio = (int)($_SESSION['myRadio'] ?? ($row['SelectedRadio'] ?? 1));
if ($elmerSharedRadio < 1) $elmerSharedRadio = 1;
?>
<template id="elmerControlSharedTemplate"
 data-radio="<?php echo $elmerSharedRadio; ?>"
 data-call="<?php echo htmlspecialchars((string)($_SESSION['myCall'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
 data-user="<?php echo htmlspecialchars((string)$_SESSION['myUsername'], ENT_QUOTES, 'UTF-8'); ?>">
<?php require __DIR__ . '/elmer_control_panel.php'; ?>
</template>
<script defer src="/js/elmer_control_shared.js?v=20261029-1"></script>
