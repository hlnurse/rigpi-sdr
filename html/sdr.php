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
	<?php require $dRoot . "/includes/modal.txt"; ?>
	<?php
	$setup = (string) ($_GET["setup"] ?? "");
        if (in_array($setup, ["hermes", "radio", "sdrconnect"], true)) {
		$elmerSdrFrameURL .= "&setup=" . rawurlencode($setup);
	}
	?>
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

        const dxField = document.getElementById('searchText');

        function persistDxCall(dx) {
                if (dxField) dxField.value = dx;
                const body = new URLSearchParams({field: 'DX', radio: String(radio), data: dx, table: 'MySettings'});
                fetch('/programs/SetSettings.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                }).catch(function (error) { console.warn('Unable to save DX call:', error); });
        }

        window.addEventListener('message', function (event) {
                const frame = document.getElementById('elmerSdrFrame');
                if (!frame || event.source !== frame.contentWindow) return;
                const message = event.data;
                if (!message || message.type !== 'elmrTune') return;
                const dx = String(message.dx || '').trim().toUpperCase();
                if (!/^[A-Z0-9/]{1,20}$/.test(dx)) return;
                persistDxCall(dx);
        });

        if (dxField) {
                const body = new URLSearchParams({radio: String(radio), field: 'DX', table: 'MySettings'});
                fetch('/programs/GetSetting.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                }).then(function (response) {
                        if (!response.ok) throw new Error('DX call unavailable');
                        return response.text();
                }).then(function (dx) {
                        dxField.value = dx.trim().toUpperCase();
                }).catch(function (error) { console.warn('Unable to load DX call:', error); });
        }

        document.getElementById('logoutButton')?.addEventListener('click', function () {
                const body = new URLSearchParams({status: 'loggedout'});
                fetch('/login.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                }).finally(function () { window.location.replace('/login.php'); });
        });


	function runCallsignLookup() {
		const dx = String(dxField?.value || '').trim().toUpperCase();
		if (!dx || dx.includes('*')) return;
		dxField.value = dx;
		$.post('/programs/GetUserField.php', {un: username, field: 'uID'}, function (user) {
			$.post('/programs/GetCallbook.php', {call: dx, what: 'QRZData', user: user, un: username}, function (response) {
				$('.modal-body').html(response);
				$.post('/programs/GetCallbook.php', {call: dx, what: 'QRZpix', user: user, un: username}, function (picture) {
					const parts = picture.split('|');
					const height = Number(parts[1] || 0);
					const width = Number(parts[2] || 0);
					const image = $('.modal-pix');
					if (height > 0) {
						if (parts[3] === 'flag') {
							image.addClass('flag-pix').css({width: 'auto', 'max-width': '150px', margin: '10px auto', display: 'block'});
						} else {
							const scale = width > 0 ? width / 400 : 1;
							image.removeClass('flag-pix').attr({height: height / scale, width: width / scale}).css({display: '', margin: ''});
						}
						image.attr('src', parts[0]);
					} else {
						image.attr({height: 0, width: 0, src: 'about:blank'});
					}
					$('.modal-title').html(dx);
					$('#myModal').modal({show: true});
				});
			});
			persistDxCall(dx);
		});
	}

	document.getElementById('searchButton')?.addEventListener('click', function (event) {
		event.preventDefault();
		runCallsignLookup();
	});
	dxField?.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			runCallsignLookup();
		}
	});

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
