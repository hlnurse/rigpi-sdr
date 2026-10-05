<?php
/**
 * RigPi Factory Reset & Backup Management
 * Admin only — double confirmation required.
 */
session_start();
$dRoot = "/var/www/html";
require_once $dRoot . "/classes/Membership.php";
require_once $dRoot . "/classes/MysqliDb.php";
require $dRoot . "/programs/sqldata.php";

$tUserName = $_SESSION["myUsername"] ?? "";
$tCall = $_SESSION["myCall"] ?? "";
$level = $_SESSION["level"] ?? 9;

if ($level > 1) {
    header("Location: /index.php");
    exit();
}

$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);

$BACKUP_BASE = "/home/pi";
$message = "";
$done = false;
$action = $_POST["action"] ?? ($_GET["action"] ?? "");

// ── Handle backup actions ──────────────────────────────────────────────────
if ($action === "delete_backup") {
    $file = basename($_POST["file"] ?? "");
    $path = "$BACKUP_BASE/$file";
    if (
        $file &&
        strpos($file, "factory_reset_backup_") === 0 &&
        file_exists($path)
    ) {
        exec("sudo rm " . escapeshellarg($path));
        $message = "Deleted: $file";
    }
}

if ($action === "download_backup") {
    $file = basename($_GET["file"] ?? "");
    $path = "$BACKUP_BASE/$file";
    if (
        $file &&
        strpos($file, "factory_reset_backup_") === 0 &&
        file_exists($path)
    ) {
        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=\"$file\"");
        header("Content-Length: " . filesize($path));
        readfile($path);
        exit();
    }
}

if ($action === "restore_backup") {
    $file = basename($_POST["file"] ?? "");
    $path = "$BACKUP_BASE/$file";
    if (
        $file &&
        strpos($file, "factory_reset_backup_") === 0 &&
        file_exists($path)
    ) {
        $restore_script = "/home/pi/sdr_web/restore_backup.sh";
        $output = [];
        exec(
            "sudo $restore_script " . escapeshellarg($path) . " 2>&1",
            $output,
            $rc
        );
        $done = $rc === 0;
        $message = implode("\n", $output);
        if (!$done) {
            $message = "Restore failed (exit $rc):\n" . $message;
        }
    }
}

// ── Handle factory reset ──────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "factory_reset") {
    $confirm1 = $_POST["confirm1"] ?? "";
    $confirm2 = $_POST["confirm2"] ?? "";
    if ($confirm1 !== "RESET" || $confirm2 !== "YES") {
        $message = "Confirmation text incorrect. Reset cancelled.";
    } else {
        $script = "/var/www/html/programs/factory_reset.sh";
        if (!file_exists($script)) {
            $message = "Reset script not found: $script";
        } else {
            $output = [];
            exec("sudo $script 2>&1", $output, $rc);
            $done = $rc === 0;
            $message = implode("\n", $output);
            if (!$done) {
                $message = "Reset failed (exit $rc):\n" . $message;
            }
        }
    }
}

// ── Get backup list ────────────────────────────────────────────────────────
$backups = [];
foreach (glob("$BACKUP_BASE/factory_reset_backup_*.zip") as $f) {
    $backups[] = [
        "file" => basename($f),
        "size" => round(filesize($f) / 1024 / 1024, 1) . " MB",
        "date" => date("Y-m-d H:i", filemtime($f)),
    ];
}
usort($backups, fn($a, $b) => strcmp($b["file"], $a["file"]));

// newest first
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title><?php echo htmlspecialchars($tCall); ?> RigPi - Factory Reset</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
	<link rel="shortcut icon" href="/favicon.ico">
	<link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
	<script src="/Bootstrap/jquery.min.js"></script>
	<link href="/awe/css/all.css" rel="stylesheet">
	<link href="/awe/css/fontawesome.css" rel="stylesheet">
	<link href="/awe/css/solid.css" rel="stylesheet">
	<?php require $dRoot . "/includes/styles.php"; ?>
</head>
<body class="body-black-scroll">
<div class="container-fluid" style="max-width:800px; margin:0 auto; padding:30px 15px;">

	<div class="card" style="background:#1a1a2e; border:1px solid #c0392b; padding:30px; margin-bottom:20px;">
		<div style="text-align:center; font-size:2em; margin-bottom:10px;">
			<i class="fas fa-exclamation-triangle" style="color:#c0392b;"></i>
		</div>
		<h4 class="text-white text-center">Factory Reset</h4>
		<hr style="border-color:#c0392b;">

		<?php if ($done): ?>
		<div class="alert alert-success">
			<strong><?php echo $action === "restore_backup"
       ? "Restore"
       : "Reset"; ?> Complete.</strong>
			<?php echo $action === "restore_backup"
       ? "Backup restored successfully."
       : "System restored to factory defaults. Backup created."; ?>
			<br><br>
			<a href="/index.php" style="color:#fff; background:#1565c0; padding:6px 14px; border-radius:4px; text-decoration:none; margin-right:8px;">
				<i class="fas fa-home"></i> Return to RigPi
			</a>
			<a href="/login.php" style="color:#000; background:#ffc107; padding:6px 14px; border-radius:4px; text-decoration:none;">
				<i class="fas fa-sign-out-alt"></i> Logout
			</a>
		</div>
		<pre style="background:#0a0a1a; color:#aaa; font-size:10px; padding:10px; max-height:300px; overflow-y:auto; margin-top:15px;"><?php echo htmlspecialchars(
      $message
  ); ?></pre>

		<?php elseif ($message && $action !== "delete_backup"): ?>
		<div class="alert alert-danger"><?php echo nl2br(
      htmlspecialchars($message)
  ); ?></div>
		<a href="/programs/factory_reset.php" class="btn btn-secondary">Try Again</a>
		<a href="/system.php" class="btn btn-outline-light" style="margin-left:10px;">Cancel</a>

		<?php else: ?>
		<?php if ($message): ?>
		<div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
		<?php endif; ?>

		<p class="text-white" style="margin-bottom:5px;">This will:</p>
		<ul style="color:#ccc; font-size:0.9em; margin-bottom:15px;">
			<li>Create a backup zip of changed files and database</li>
			<li>Remove all .bak, copy, and temporary files</li>
			<li>Reset the station database using factory defaults</li>
			<li>Clear all logs, /tmp, and Downloads</li>
			<li>Clear SDR user settings</li>
			<li>Fix file ownership</li>
		</ul>
		<div class="alert alert-danger" style="font-size:0.9em;">
			<strong>Warning:</strong> All user accounts, log data, and settings will be reset to factory defaults.
		</div>

		<form method="POST" action="/programs/factory_reset.php">
			<input type="hidden" name="action" value="factory_reset">
			<div class="form-group" style="margin-top:15px;">
				<label class="text-white">Type <strong>RESET</strong> to confirm:</label>
				<input type="text" name="confirm1" class="form-control" placeholder="Type RESET" autocomplete="off" required>
			</div>
			<div class="form-group" style="margin-top:10px;">
				<label class="text-white">Type <strong>YES</strong> to proceed:</label>
				<input type="text" name="confirm2" class="form-control" placeholder="Type YES" autocomplete="off" required>
			</div>
			<div style="margin-top:20px; text-align:center;">
				<button type="submit" class="btn btn-danger btn-lg">
					<i class="fas fa-exclamation-triangle"></i> Perform Factory Reset
				</button>
				&nbsp;&nbsp;
				<a href="/system.php" class="btn btn-secondary btn-lg">Cancel</a>
			</div>
		</form>
		<?php endif; ?>
	</div>

	<!-- Backup Management -->
	<div class="card" style="background:#16213e; border:1px solid #2a3a5a; padding:20px;">
		<h5 class="text-white"><i class="fas fa-archive" style="color:#38bdf8;"></i> Backup Management</h5>
		<hr style="border-color:#2a3a5a;">

		<?php if (empty($backups)): ?>
		<p style="color:#666; font-size:0.9em;">No backups found.</p>
		<?php else: ?>
		<table class="table table-sm" style="font-size:0.85em;">
			<thead><tr style="color:#38bdf8;">
				<th>File</th><th>Date</th><th>Size</th><th>Actions</th>
			</tr></thead>
			<tbody>
			<?php foreach ($backups as $b): ?>
			<tr style="color:#222;">
				<td><?php echo htmlspecialchars($b["file"]); ?></td>
				<td><?php echo $b["date"]; ?></td>
				<td><?php echo $b["size"]; ?></td>
				<td style="white-space:nowrap;">
					<!-- Download -->
					<a href="/programs/factory_reset.php?action=download_backup&file=<?php echo urlencode(
         $b["file"]
     ); ?>"
					   style="color:#38bdf8; margin-right:8px;" title="Download">
						<i class="fas fa-download"></i>
					</a>
					<!-- Restore -->
					<form method="POST" action="/programs/factory_reset.php" style="display:inline;"
						  onsubmit="return confirm('Restore from <?php echo htmlspecialchars(
            $b["file"]
        ); ?>? This will overwrite current files.')">
						<input type="hidden" name="action" value="restore_backup">
						<input type="hidden" name="file" value="<?php echo htmlspecialchars(
          $b["file"]
      ); ?>">
						<button type="submit" style="background:none; border:none; color:#f0a500; cursor:pointer; padding:0; margin-right:8px;" title="Restore">
							<i class="fas fa-undo"></i>
						</button>
					</form>
					<!-- Delete -->
					<form method="POST" action="/programs/factory_reset.php" style="display:inline;"
						  onsubmit="return confirm('Delete <?php echo htmlspecialchars(
            $b["file"]
        ); ?>?')">
						<input type="hidden" name="action" value="delete_backup">
						<input type="hidden" name="file" value="<?php echo htmlspecialchars(
          $b["file"]
      ); ?>">
						<button type="submit" style="background:none; border:none; color:#e74c3c; cursor:pointer; padding:0;" title="Delete">
							<i class="fas fa-trash"></i>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>

</div>
<script src="/Bootstrap/popper.min.js"></script>
<script src="/Bootstrap/bootstrap.min.js"></script>
</body>
</html>
