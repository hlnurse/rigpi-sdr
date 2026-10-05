<?php
/*
 * RigPi - Account Pending Page
 * Shown when a user with Access_Level=9 (pending approval) logs in.
 */
session_start();
$dRoot = "/var/www/html";
require_once $dRoot . "/classes/MysqliDb.php";
require $dRoot . "/programs/sqldata.php";

$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$db->where("uID", 1);
$adminRow = $db->getOne("Users");
$adminCall = $adminRow["MyCall"] ?? "RigPi";
$adminEmail = $adminRow["My_Email"] ?? "";

$un = $_SESSION["myUsername"] ?? "";
$call = $_SESSION["myCall"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title><?php echo htmlspecialchars(
     $adminCall
 ); ?> RigPi - Account Pending</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
	<link rel="shortcut icon" href="/favicon.ico">
	<link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
	<script src="/Bootstrap/jquery.min.js"></script>
	<?php require $dRoot . "/includes/styles.php"; ?>
</head>
<body class="body-black-scroll">
<div class="container-fluid">
	<div class="row" style="margin-top:60px;">
		<div class="col-md-4"></div>
		<div class="col-md-4 text-center">
			<div class="card" style="background:#1a1a2e; border:1px solid #444; padding:30px;">
				<div style="font-size:3em; margin-bottom:20px;">
					<i class="fas fa-clock" style="color:#f0a500;"></i>
				</div>
				<h4 class="text-white">Account Approval Pending</h4>
				<hr style="border-color:#444;">
				<p class="text-white" style="margin-top:15px;">
					Your account request for <strong><?php echo htmlspecialchars($call); ?></strong>
					(<?php echo htmlspecialchars($un); ?>) is awaiting administrator approval.
				</p>
				<p style="color:#aaa; font-size:0.9em;">
					You will be notified by email when your account has been approved.
					<?php if ($adminEmail): ?>
					<br>You may also contact the administrator at
					<a href="mailto:<?php echo htmlspecialchars(
         $adminEmail
     ); ?>" style="color:#38bdf8;">
						<?php echo htmlspecialchars($adminEmail); ?>
					</a>.
					<?php endif; ?>
				</p>
				<div style="margin-top:20px;">
					<a href="/login.php" class="btn btn-outline-light btn-sm">
						<i class="fas fa-sign-out-alt"></i> Return to Login
					</a>
				</div>
			</div>
		</div>
		<div class="col-md-4"></div>
	</div>
</div>
<script src="/Bootstrap/popper.min.js"></script>
<script src="/Bootstrap/bootstrap.min.js"></script>
</body>
</html>
