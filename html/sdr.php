<?php
session_start();
$dRoot = "/var/www/html";
require_once $dRoot . "/classes/Membership.php";

$tUserName = $_SESSION["myUsername"] ?? "";
$tCall = $_SESSION["myCall"] ?? "";
$membership = new Membership();
$membership->confirm_Member($tUserName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
	<title><?php echo htmlspecialchars($tCall, ENT_QUOTES, "UTF-8"); ?> RigPi ELMER SDR</title>
	<link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
	<link href="/awe/css/all.css" rel="stylesheet">
	<link href="/awe/css/fontawesome.css" rel="stylesheet">
	<link href="/awe/css/solid.css" rel="stylesheet">
	<script src="/Bootstrap/jquery.min.js"></script>
	<?php require $dRoot . "/includes/styles.php"; ?>
	<style>
		html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
		body { display: flex; flex-direction: column; background: #111; }
		body > nav { flex: 0 0 auto; }
		#elmerSdrWindow { flex: 1 1 auto; min-height: 0; width: 100%; }
		#elmerSdrFrame { display: block; width: 100%; height: 100%; border: 0; }
		#elmerSdrStatus { flex: 0 0 auto; }
		#elmerSdrStatus .footer { position: static !important; }
	</style>
</head>
<body>
	<?php require $dRoot . "/includes/header.php"; ?>
	<main id="elmerSdrWindow">
		<iframe
			id="elmerSdrFrame"
			src="<?php echo htmlspecialchars($elmerSdrFrameURL, ENT_QUOTES, "UTF-8"); ?>"
			allow="autoplay; microphone"
			title="RigPi ELMER SDR"></iframe>
	</main>
	<div id="elmerSdrStatus" class="status">
		<?php require $dRoot . "/includes/footer.php"; ?>
	</div>
	<script src="/Bootstrap/popper.min.js"></script>
	<script src="/Bootstrap/bootstrap.min.js"></script>
	<script>
	(function () {
		const radio = <?php echo (int) $elmerSdrRadio; ?>;
		const username = <?php echo json_encode((string) $tUserName); ?>;
		const call = <?php echo json_encode((string) $tCall); ?>;

		function frequencyLabel(value) {
			const hz = Number(value);
			if (!Number.isFinite(hz) || hz <= 0) return '';
			if (hz < 1000000) return (hz / 1000).toFixed(3) + ' kHz';
			const parts = (hz / 1000000).toFixed(6).split('.');
			return parts[0] + '.' + parts[1].slice(0, 3) + '.' + parts[1].slice(3) + ' MHz';
		}
		function modeLabel(value) {
			if (value === 'PKTUSB') return 'USB-D';
			if (value === 'PKTLSB') return 'LSB-D';
			return value || '';
		}
		function setText(id, text) {
			const node = document.getElementById(id);
			if (node) node.textContent = text;
		}
		function updateClock() {
			const now = new Date();
			setText('fPanel5', String(now.getUTCHours()).padStart(2, '0') + ':' +
				String(now.getUTCMinutes()).padStart(2, '0') + 'z');
		}
		async function updateStatus() {
			try {
				const body = new URLSearchParams({radio: String(radio), un: username, myCall: call});
				const response = await fetch('/programs/GetInterfaceIn.php', {
					method: 'POST', credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
					body: body.toString()
				});
				if (!response.ok) throw new Error('status unavailable');
				const values = (await response.text()).split('`');
				const main = frequencyLabel(values[0]);
				const panel1 = document.getElementById('fPanel1');
				setText('fPanel4', 'User: ' + call + ' (' + (window.tIsGuest ? 'guest/' : '') + username + ')');
				if (!main) {
					setText('fPanel1', 'No Radio');
					setText('fPanel2', '');
					setText('fPanel3', '');
					if (panel1) panel1.style.backgroundColor = 'red';
					return;
				}
				setText('fPanel1', 'Main: ' + main);
				setText('fPanel2', Number(values[1]) === 1 ? 'Sub: ' + frequencyLabel(values[2]) : '');
				setText('fPanel3', 'Mode: ' + modeLabel(values[3]) + ' - BW: ' + (values[17] || ''));
				if (panel1) panel1.style.backgroundColor = 'black';
			} catch (_error) {
				setText('fPanel1', 'Status unavailable');
			}
		}

		updateClock();
		updateStatus();
		setInterval(updateClock, 10000);
		setInterval(updateStatus, 1000);
	})();
	</script>
</body>
</html>
