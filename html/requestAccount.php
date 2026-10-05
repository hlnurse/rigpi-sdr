<?php
/*
 * RigPi - Account Request Page
 * Creates account with Access_Level=9 (pending) and notifies admin via Pushover.
 * No session required — accessible from login page.
 */

$dRoot = "/var/www/html";
require_once $dRoot . "/classes/MysqliDb.php";
require $dRoot . "/programs/sqldata.php";

$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);

// Get admin info for title and Pushover
$db->where("uID", 1);
$adminRow = $db->getOne("Users");
$adminCall = $adminRow["MyCall"] ?? "RigPi";

$message = "";
$messageType = "";
$submitted = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["requestAccount"])) {
    $call = strtoupper(trim($_POST["MyCall"] ?? ""));
    $username = strtolower(trim($_POST["Username"] ?? ""));
    $fname = trim($_POST["FirstName"] ?? "");
    $lname = trim($_POST["LastName"] ?? "");
    $email = trim($_POST["My_Email"] ?? "");
    $password = trim($_POST["Password"] ?? "");
    $phone = trim($_POST["My_Phone"] ?? "");
    $qth = trim($_POST["QTH"] ?? "");
    $city = trim($_POST["MyCity"] ?? "");
    $state = trim($_POST["MyState"] ?? "");
    $country = trim($_POST["MyCountry"] ?? "");
    $zip = trim($_POST["MyZIP"] ?? "");
    $grid = trim($_POST["My_Grid"] ?? "");
    $lat = trim($_POST["My_Latitude"] ?? "");
    $lon = trim($_POST["My_Longitude"] ?? "");

    // Validation
    $errors = [];
    if (!$call) {
        $errors[] = "Callsign is required.";
    }
    if (!$username) {
        $errors[] = "Username is required.";
    }
    if (strpos($username, " ") !== false) {
        $errors[] = "Username must not contain spaces.";
    }
    if (!$email) {
        $errors[] = "Email address is required.";
    }
    if (!$password) {
        $errors[] = "Password is required.";
    }
    if (!$fname) {
        $errors[] = "First name is required.";
    }
    if (!$lname) {
        $errors[] = "Last name is required.";
    }

    if (!$errors) {
        $db->where("Username", $username);
        if ($db->getOne("Users")) {
            $errors[] = "Username '$username' is already taken.";
        }
    }

    if ($errors) {
        $message = implode("<br>", $errors);
        $messageType = "danger";
    } else {
        // Get next available uID
        $maxRow = $db->getValue("Users", "MAX(uID)");
        $newUID = intval($maxRow) + 1;

        // Insert user with Access_Level=9 (pending)
        $data = [
            "uID" => $newUID,
            "SelectedRadio" => $newUID,
            "MyCall" => $call,
            "Username" => $username,
            "Access_Level" => 9,
            "FirstName" => $fname,
            "LastName" => $lname,
            "Password" => md5($password),
            "My_Email" => $email,
            "My_Phone" => $phone,
            "QTH" => $qth,
            "MyCity" => $city,
            "MyState" => $state,
            "MyCountry" => $country,
            "MyZIP" => $zip,
            "My_Grid" => $grid,
            "My_Latitude" => $lat,
            "My_Longitude" => $lon,
            "Mobile_Lat" => "",
            "Mobile_Lon" => "",
            "Mobile_Grid" => "",
            "MyContinent" => "",
            "MyCounty" => "",
            "qrzUser" => "",
            "qrzPWD" => "",
            "WSJTXPort" => 2333,
            "PushoverToken" => "",
            "PushoverUser" => "",
            "PushoverNotify" => "none",
            "PushoverDelay" => 60,
            "SDRHost" => "http://" . gethostname() . ".local/sdr" . $newUID,
            "SDRHostRemote" => "",
            "SDRPort" => 8001,
            "SDRPosition" => "none",
            "SDRPage" => "none",
            "CurrentIP" => "0",
            "rigctldPort" => 4532,
            "rigDoPID" => 0,
            "LastVisit" => 0,
            "BandEnable" => "1,1,1,1,1,1,1,1,1,1,1,1,1,1,1",
            "ModeEnable" => "1,1,1,1,1,1,1,1,1,1,1,1,1,1,1",
            "BusyBlock" => 0,
            "Active" => 0,
            "Theme" => "0",
            "LogFldigi" => 0,
            "LogWSJTX" => 0,
            "DeadMan" => 0,
            "Inactivity" => 0,
        ];

        if ($db->insert("Users", $data)) {
            // Send Pushover to admin
            $tsZ = gmdate("Y-m-d H:i") . "Z";
            $pushMsg = "Account Request at $tsZ\n";
            $pushMsg .= "Call: $call | User: $username\n";
            $pushMsg .= "Name: $fname $lname\n";
            $pushMsg .= "Email: $email\n";
            if ($phone) {
                $pushMsg .= "Phone: $phone\n";
            }
            if ($grid) {
                $pushMsg .= "Grid: $grid\n";
            }
            if ($city || $state) {
                $pushMsg .= "QTH: $city, $state\n";
            }
            $pushMsg .= "Set Access Level to 2 to approve.";

            $token = trim($adminRow["PushoverToken"] ?? "");
            $userKey = trim($adminRow["PushoverUser"] ?? "");
            $pushSent = false;

            if ($token && $userKey) {
                $ch = curl_init("https://api.pushover.net/1/messages.json");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt(
                    $ch,
                    CURLOPT_POSTFIELDS,
                    http_build_query([
                        "token" => $token,
                        "user" => $userKey,
                        "title" => "RigPi Account Request: $call",
                        "message" => $pushMsg,
                    ])
                );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $pushSent = $httpCode === 200;
            }

            $submitted = true;
            $message = "Your account request has been submitted. The administrator will review it and contact you at $email when approved.";
            $messageType = "success";
        } else {
            $message = "Username '$username' is already taken. Please choose another.";
            $messageType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title><?php echo htmlspecialchars(
        $adminCall
    ); ?> RigPi - Request Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="stylesheet" href="/Bootstrap/bootstrap.min.css">
    <script src="/Bootstrap/jquery.min.js"></script>
    <script defer src="/awe/js/all.js"></script>
    <link href="/awe/css/all.css" rel="stylesheet">
    <link href="/awe/css/fontawesome.css" rel="stylesheet">
    <link href="/awe/css/solid.css" rel="stylesheet">
    <?php require $dRoot . "/includes/styles.php"; ?>
</head>
<body class="body-black-scroll">
<div class="container-fluid">
    <div class="row" style="margin-bottom:10px; margin-top:20px;">
        <div class="col-12 col-md-4 btn-padding">
            <?php if (!$submitted): ?>
            <button class="btn btn-color" type="button" onclick="window.location='/login.php'">
                <i class="fas fa-ban fa-lg"></i>
            </button>
            <?php endif; ?>
        </div>
        <div class="col-6 col-md-4 text-center">
            <div class="label label-success text-white pageLabel" style="margin-top:10px;">
                <?php echo htmlspecialchars(
                    $adminCall
                ); ?> RigPi - Request Account
            </div>
        </div>
        <div class="col-6 col-md-4"></div>
    </div>

    <?php if ($message): ?>
    <div class="row">
        <div class="col-12">
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo $message; ?>
                <?php if ($submitted): ?>
                <br><br><a href="/login.php" class="btn btn-sm btn-outline-light">Return to Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$submitted): ?>
    <form id="requestForm" method="POST" action="/requestAccount.php">
    <input type="hidden" name="requestAccount" value="1">
    <hr>
    <div class="row"><div class="col-12 text-center" style="margin-bottom:8px;">
        <span class="text-white" style="font-size:0.85em;">* Required fields</span>
    </div></div>

    <div class="row">
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">* Call</span></div>
                <input type="text" class="form-control text-uppercase" name="MyCall"
                    value="<?php echo htmlspecialchars(
                        $_POST["MyCall"] ?? ""
                    ); ?>" placeholder="Your callsign">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">* Username</span></div>
                <input type="text" class="form-control" name="Username"
                    value="<?php echo htmlspecialchars(
                        $_POST["Username"] ?? ""
                    ); ?>" placeholder="Unique login username, no spaces">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">* Password</span></div>
                <input type="password" class="form-control" name="Password" placeholder="Desired password">
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">* First</span></div>
                <input type="text" class="form-control" name="FirstName"
                    value="<?php echo htmlspecialchars(
                        $_POST["FirstName"] ?? ""
                    ); ?>" placeholder="First name">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">* Last</span></div>
                <input type="text" class="form-control" name="LastName"
                    value="<?php echo htmlspecialchars(
                        $_POST["LastName"] ?? ""
                    ); ?>" placeholder="Last name">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">* Email</span></div>
                <input type="email" class="form-control" name="My_Email"
                    value="<?php echo htmlspecialchars(
                        $_POST["My_Email"] ?? ""
                    ); ?>" placeholder="Your email address">
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">Phone</span></div>
                <input type="text" class="form-control" name="My_Phone"
                    value="<?php echo htmlspecialchars(
                        $_POST["My_Phone"] ?? ""
                    ); ?>" placeholder="Phone number">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">Grid Sq</span></div>
                <input type="text" class="form-control" name="My_Grid"
                    value="<?php echo htmlspecialchars(
                        $_POST["My_Grid"] ?? ""
                    ); ?>" placeholder="Maidenhead gridsquare">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">Street</span></div>
                <input type="text" class="form-control" name="QTH"
                    value="<?php echo htmlspecialchars(
                        $_POST["QTH"] ?? ""
                    ); ?>" placeholder="Street address">
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">City</span></div>
                <input type="text" class="form-control" name="MyCity"
                    value="<?php echo htmlspecialchars(
                        $_POST["MyCity"] ?? ""
                    ); ?>" placeholder="City">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">State</span></div>
                <input type="text" class="form-control" name="MyState"
                    value="<?php echo htmlspecialchars(
                        $_POST["MyState"] ?? ""
                    ); ?>" placeholder="State">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">Country</span></div>
                <input type="text" class="form-control" name="MyCountry"
                    value="<?php echo htmlspecialchars(
                        $_POST["MyCountry"] ?? ""
                    ); ?>" placeholder="Country">
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">ZIP</span></div>
                <input type="text" class="form-control" name="MyZIP"
                    value="<?php echo htmlspecialchars(
                        $_POST["MyZIP"] ?? ""
                    ); ?>" placeholder="ZIP / Postal code">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">Latitude</span></div>
                <input type="text" class="form-control" name="My_Latitude"
                    value="<?php echo htmlspecialchars(
                        $_POST["My_Latitude"] ?? ""
                    ); ?>" placeholder="Latitude">
            </div>
        </div>
        <div class="col-md-4 text-spacer">
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">Longitude</span></div>
                <input type="text" class="form-control" name="My_Longitude"
                    value="<?php echo htmlspecialchars(
                        $_POST["My_Longitude"] ?? ""
                    ); ?>" placeholder="Longitude">
            </div>
        </div>
    </div>

    <div class="row" style="margin-top:20px; margin-bottom:40px;">
        <div class="col-12 text-center">
            <button type="submit" name="requestAccount" value="1" class="btn btn-success btn-lg">
                <i class="fas fa-paper-plane"></i> Send Account Request
            </button>
            &nbsp;&nbsp;
            <button type="button" class="btn btn-secondary btn-lg" onclick="window.location='/login.php'">
                <i class="fas fa-ban"></i> Cancel
            </button>
        </div>
    </div>
    </form>
    <?php endif; ?>
</div>
<?php require $dRoot . "/includes/footer.php"; ?>
<script src="/Bootstrap/popper.min.js"></script>
<link rel="stylesheet" href="/Bootstrap/jquery-ui.css">
<script src="/Bootstrap/jquery-ui.js"></script>
<script src="/Bootstrap/bootstrap.min.js"></script>
</body>
</html>
