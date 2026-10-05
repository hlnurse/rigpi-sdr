<?php
// index_4.php - Four-panel SDR dashboard
session_start();
$dRoot = "/var/www/html";
require_once $dRoot . "/programs/GetUserFieldFunc.php";
require_once $dRoot . "/classes/Membership.php";

// Check authentication
if (!isset($_SESSION["myUsername"]) || empty($_SESSION["myUsername"])) {
    header("Location: /login.php?redirect=/index_4.php");
    exit();
}

// Check admin access
$membership = new Membership();
$membership->confirm_Member($_SESSION["myUsername"]);
$tUserName = (string) $_SESSION["myUsername"];
$tCall = (string) ($_SESSION["myCall"] ?? "");
$accessLevel = getUserField($_SESSION["myUsername"], "Access_Level");
if ($accessLevel != 1) {
    header("Location: /index.php");
    exit();
}

// Get hostname for SDR URLs
$hostname = gethostname();
$baseUrl = "http://" . $hostname . ".local";

// Get all active users and their SDR assignments
require_once $dRoot . "/classes/MysqliDb.php";
$db = new MysqliDb("localhost", "ham", "7388", "station");
$db->where("Access_Level", 9, "<");
$db->orderBy("uID", "asc");
$users = $db->get("Users", 4, ["uID", "Username", "MyCall", "Access_Level"]);

// Build SDR panels — one per user
$panels = [];
foreach ($users as $u) {
    $uID = $u["uID"];
    $sdrPath = $uID == 1 ? "/sdr" : "/sdr" . $uID;
    $sdrUrl =
        $sdrPath .
        "?user=" .
        urlencode($u["Username"]) .
        "&radio=" .
        $uID .
        "&call=" .
        urlencode($u["MyCall"] ?: $u["Username"]);
    $panels[] = [
        "url" => $sdrUrl,
        "label" => $u["MyCall"] ?: $u["Username"],
        "username" => $u["Username"],
        "uid" => $uID,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>RigPi SDR Dashboard</title>
	<link rel="shortcut icon" href="/favicon.ico">
	<link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
	<link href="/awe/css/all.css" rel="stylesheet">
	<link href="/awe/css/fontawesome.css" rel="stylesheet">
	<link href="/awe/css/solid.css" rel="stylesheet">
	<script src="/Bootstrap/jquery.min.js"></script>
	<?php require $dRoot . "/includes/styles.php"; ?>
	<style>
		html {
			height: 100%;
		}
		body {
			background: #1a1a1a;
			margin: 0 !important;
			padding: 0;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			overflow: hidden;
			height: 100vh;
			display: flex;
			flex-direction: column;
		}
		body > nav { flex: 0 0 auto; font-family: var(--font-family-sans-serif); }
		#header {
				background: #2C5D88;
				color: #fff;
				padding: 6px 16px;
				display: flex;
				align-items: center;
				gap: 16px;
				height: 36px;
				flex-shrink: 0;
				overflow-x: auto;
				overflow-y: hidden;
				-webkit-overflow-scrolling: touch;
				white-space: nowrap;
		}
		#header h1 {
				font-size: 14pt;
				margin: 0;
				font-weight: 600;
				white-space: nowrap;
				flex-shrink: 0;
		}
		#header .actions {
				display: flex;
				gap: 8px;
				align-items: center;
				flex-shrink: 0;
		}
		#header a {
			color: #adf;
			font-size: 11pt;
			text-decoration: none;
		}
		#grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			grid-template-rows: 1fr 1fr;
			gap: 4px;
			padding: 4px;
			flex: 1 1 auto;
			min-height: 0;
			box-sizing: border-box;
			background: #111;
		}
		#sdrDashboardStatus { flex: 0 0 auto; }
		#sdrDashboardStatus .footer { position: static !important; }
		@media (max-width: 600px) {
			#grid {
				grid-template-columns: 1fr;
				grid-template-rows: repeat(4, 1fr);
				height: auto;
			}
			.sdr-panel {
				height: 50vw;
				min-height: 180px;
			}
		}
		.sdr-panel {
			position: relative;
			background: #000;
			border: 1px solid #2C5D88;
			border-radius: 4px;
			overflow: hidden;
		}
		.sdr-label {
                        position: absolute;
                        bottom: 6px;
                        right: 8px;
			background: rgba(44,93,136,0.8);
			color: #fff;
			font-size: 10px;
			padding: 2px 6px;
			border-radius: 3px;
			z-index: 10;
			pointer-events: none;
		}
		.sdr-panel iframe {
			width: 100%;
			height: 100%;
			border: none;
			display: block;
		}
		.sdr-panel-empty {
			display: flex;
			align-items: center;
			justify-content: center;
			color: #444;
			font-size: 12pt;
		}
	</style>
</head>
<body>
	<?php require $dRoot . "/includes/header.php"; ?>
	<?php
	$dashboardUserId = (int) getUserField($tUserName, "uID");
	$dashboardRadio = (int) getUserField($tUserName, "SelectedRadio");
	if ($dashboardRadio < 1) {
		$dashboardRadio = $dashboardUserId > 0 ? $dashboardUserId : 1;
	}
	$db->where("Radio", $dashboardRadio);
	$dashboardSettings = $db->getOne("MySettings", ["DX"]);
	$dashboardDx = strtoupper(trim((string) ($dashboardSettings["DX"] ?? "")));
 ?>

<div id="grid">
<?php foreach ($panels as $p): ?>
	<div class="sdr-panel">
		<div class="sdr-label"><?php echo htmlspecialchars(
      $p["label"]
  ); ?> &mdash; SDR <?php echo $p["uid"]; ?></div>
		<iframe src="<?php echo htmlspecialchars($p["url"]); ?>"
				title="SDR <?php echo $p["uid"]; ?>"
				allow="autoplay; microphone"
				loading="lazy">
		</iframe>
	</div>
<?php endforeach; ?>
<?php for ($i = count($panels); $i < 4; $i++): ?>
	<div class="sdr-panel sdr-panel-empty">
		<span>No SDR <?php echo $i + 1; ?></span>
	</div>
<?php endfor; ?>
</div>

<div id="sdrDashboardStatus" class="status">
	<?php require $dRoot . "/includes/footer.php"; ?>
</div>

<?php require $dRoot . "/includes/modal.txt"; ?>

<script src="/Bootstrap/popper.min.js"></script>
<script src="/Bootstrap/bootstrap.min.js"></script>
<script src="/js/nav-active.js"></script>
<script>
(function () {
	const labels = <?php echo json_encode(array_map(
     static fn($panel) => (string) $panel["label"],
     $panels
 )); ?>;
	const username = <?php echo json_encode($tUserName); ?>;
	const call = <?php echo json_encode($tCall); ?>;
	const userId = <?php echo json_encode($dashboardUserId); ?>;
	const dashboardRadio = <?php echo json_encode($dashboardRadio); ?>;
	const searchText = document.getElementById('searchText');
	const searchButton = document.getElementById('searchButton');
	const searchForm = searchText ? searchText.closest('form') : null;
	function setText(id, value) {
		const node = document.getElementById(id);
		if (node) node.textContent = value;
	}
	function updateFooter() {
		const now = new Date();
		setText('fPanel1', 'SDR Dashboard');
		setText('fPanel2', labels[0] ? 'SDR 1: ' + labels[0] : 'SDR 1: Unassigned');
		setText('fPanel3', labels[1] ? 'SDR 2: ' + labels[1] : 'SDR 2: Unassigned');
		setText('fPanel4', 'User: ' + call + ' (' + username + ')');
		setText('fPanel5', String(now.getUTCHours()).padStart(2, '0') + ':' +
			String(now.getUTCMinutes()).padStart(2, '0') + 'z');
	}
	function showCallbook(dx) {
		$.post('/programs/GetCallbook.php', {
			call: dx, what: 'QRZData', user: userId, un: username
		}, function (response) {
			$('.modal-body').html(response);
			$('.modal-title').html(dx);
			$.post('/programs/GetCallbook.php', {
				call: dx, what: 'QRZpix', user: userId, un: username
			}, function (pixResponse) {
				const pix = String(pixResponse || '').split('|');
				const image = $('.modal-pix');
				const height = Number(pix[1]) || 0;
				const width = Number(pix[2]) || 0;
				if (height > 0 && width > 0) {
					const ratio = width / 400;
					image.attr('src', pix[0]).attr('height', (height / ratio) + 'px').attr('width', '400px');
				} else {
					image.attr('width', '0px').attr('src', 'about:blank');
				}
				$('#myModal').modal({show: true});
			});
		});
	}
	function lookupDx(event) {
		if (event) event.preventDefault();
		const dx = String(searchText ? searchText.value : '').trim().toUpperCase();
		if (!dx || dx.includes('*') || dx.includes('=')) return false;
		searchText.value = dx;
		$.post('/programs/SetSettings.php', {field: 'waitReset', radio: dashboardRadio, data: 1, table: 'RadioInterface'});
		$.post('/programs/SetSettings.php', {field: 'DX', radio: dashboardRadio, data: dx, table: 'MySettings'});
		showCallbook(dx);
		return false;
	}

	if (searchText) searchText.value = <?php echo json_encode($dashboardDx); ?>;
	if (searchButton) searchButton.onclick = lookupDx;
	if (searchForm) searchForm.onsubmit = lookupDx;
	updateFooter();
	setInterval(updateFooter, 10000);
})();
</script>

</body>
</html>
