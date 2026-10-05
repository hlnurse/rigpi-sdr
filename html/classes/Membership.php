<?php
/**
 * @author Howard Nurse, W6HN
 *
 * Membership class for RigPi — handles login, logout, user session management,
 * access level enforcement, and system control operations (shutdown, reboot).
 *
 * This file must reside in the /classes folder.
 */

/*
 * RigPi
 * Copyright (c) 2025 Howard Nurse, W6HN
 *
 * Licensed under the MIT license — see LICENSE file.
 */

// Include user authentication class
require_once "/var/www/html/classes/User.php";

class Membership
{
    /**
     * Authenticate user against database and initialize session
     *
     * @param string $un  Username
     * @param string $pwd Password
     * @return string "NG" on failure (stops), or redirects on success
     */
    function check_user($un, $pwd, $callOverride = "")
    {
        $user = new User();

        // Reject if username not supplied
        if (!isset($un)) {
            return "NG";
        }

        $tUserName = $un;
        $ensure_credentials = true;
        $pass = "";

        // Capture password if provided
        if (strlen((string) ($pwd ?? "")) > 0) {
            $pass = $pwd;
        }

        // Initialize database connection
        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";

        $db = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );

        // Check for user in database.
        // Look up the account that is actually logging in ($un) — NOT a
        // hard-coded "admin" row. The owner account (uID=1) can be renamed
        // through the setup wizard, so "admin" may not exist; hard-coding it
        // caused check_user to bail with "NG" for every login once uID=1 was
        // renamed, which is why only the original admin name ever worked.
        $db->where("Username", $un);
        $row = $db->getOne("Users");
        $level = "1";

        if ($row) {
            $level = $row["Access_Level"];
        } else {
            return "NG";
        }

        // Wait for system startup (if rc_start.txt was modified <10 sec ago)
        $last = filemtime("/var/www/html/my/rc_start.txt");
        $elapsed = time() - $last;
        if ($elapsed < 10) {
            while ($elapsed < 10) {
                sleep(10);
                $last = filemtime("/var/www/html/my/rc_start.txt");
                $elapsed = time() - $last;
            }
        }

        // Authenticate user credentials
        //$ensure_credentials = true;
        $ensure_credentials = $user->validate_user($un, $pass, $db);
        // Get client IP address (handling proxy headers if present)
        if (!empty($_SERVER["REMOTE_ADDR"])) {
            $ip = $_SERVER["REMOTE_ADDR"];
        } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }

        // If validated
        if ($ensure_credentials == true) {
            // Reload user info (access level, callsign)
            $db->where("Username", $un);
            $row = $db->getOne("Users");
            $level = "1";
            if ($row) {
                $level = $row["Access_Level"];
            }
            $call = $row["MyCall"];

            // Update last visit and mark as active
            $db->where("Username", $un);
            $data = [
                "LastVisit" => time(),
                "Active" => "1",
            ];
            $db->update("Users", $data);

            // Log user session in LoggedIn table
            $data = [
                "Callsign" => $call,
                "Username" => $un,
                "CurrentIP" => $ip,
                "TimeOn" => time(),
            ];
            $db->insert("LoggedIn", $data);

            // Notify owner of any login
            $this->notify_visitor("login", $ip, $un, $callOverride);

            // Set session variables
            $_SESSION["level"] = $level;
            $_SESSION["firstUse"] = 1;
            $_SESSION["myCall"] = $call;
            $_SESSION["myUsername"] = $un;
            // myPort must be set here, before the redirects below. With the
            // exit() calls in the routing block, execution no longer returns to
            // login.php to set it, and confirm_Member() on the landing page
            // requires myPort or it bounces the user back to login.
            if (!isset($_SESSION["myPort"])) {
                $_SESSION["myPort"] = 4534;
            }

            // Log success to access log
            error_log(
                "<W>" .
                    date("Y-m-d H:i:s") .
                    " " .
                    $ip .
                    " OK username: " .
                    $un .
                    "\r\n",
                3,
                "/var/log/rigpi-access.log"
            );

            // Redirect based on user type.
            // MyCall == "ADMIN" is the first-run sentinel: an account that has
            // not yet been configured through the setup wizard. Such an account
            // is sent to wizardUser.php to be set up. Once the owner sets a real
            // callsign there, the account routes to index.php like any other.
            // exit() after each redirect so execution cannot continue past the
            // header() and corrupt session state (the original omitted this).
            if (trim($call) == "ADMIN") {
                header("Location: /wizardUser.php");
                exit();
            } elseif ($level == 9) {
                header("Location: /pendingAccount.php");
                exit();
            } elseif ($level > 9) {
                header("Location: /ptt_only.php");
                exit();
            } else {
                header("Location: /index.php");
                exit();
            }
        } else {
            // Log failed login
            error_log(
                "<W>" .
                    date("Y-m-d H:i:s") .
                    " " .
                    $ip .
                    " Invalid login as " .
                    $un .
                    " from " .
                    $ip .
                    "\r\n",
                3,
                "/var/log/rigpi-access.log"
            );
            return "NG";
        }
    }

    /**
     * Log user out, deactivating sessions and system state
     *
     * @param string $un Username to log out (defaults to admin)
     */
    function log_User_Out($un)
    {
        if ($un == "") {
            $un = "admin";
        }
        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";

        $db = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );

        // Turn off radio interfaces
        $data = ["MainIn" => "OFF", "SubIn" => "OFF"];
        $db->update("RadioInterface", $data);

        // Mark user as inactive
        $db->where("Username", $un);
        $data = ["Active" => "0"];
        $db->update("Users", $data);

        // Notify owner of any logout
        $sessionCall = isset($_SESSION["myCall"]) ? $_SESSION["myCall"] : "";
        $dbCall = "";
        if ($un) {
            require_once "/var/www/html/programs/sqldata.php";
            require_once "/var/www/html/classes/MysqliDb.php";
            $db2 = new MysqliDb(
                "localhost",
                $sql_radio_username,
                $sql_radio_password,
                $sql_radio_database
            );
            $db2->where("Username", $un);
            $uRow = $db2->getOne("Users");
            $dbCall = strtoupper($uRow["MyCall"] ?? "");
        }
        $callOverride =
            $sessionCall && $sessionCall !== $dbCall ? $sessionCall : "";
        $this->notify_visitor("logout", "", $un, $callOverride);

        // Reset session
        session_reset();
    }

    /**
     * Log user out and shut down system power
     *
     * @param string $un Username
     */
    function PowerDown_User_Out($un)
    {
        require "/var/www/html/programs/shutdownFunc.php";
        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";

        $db = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );

        // Disable radio interface
        $data = ["MainIn" => "OFF"];
        $db->update("RadioInterface", $data);

        // Mark user inactive
        $db->where("Username", $un);
        $data = ["Active" => "0"];
        $db->update("Users", $data);

        // Remove login record
        $db->where("Username", $un);
        $db->delete("LoggedIn");

        // Initiate power down procedure
        CloseDownPower($un);
    }

    /**
     * Log user out and reboot system
     *
     * @param string $un Username
     */
    function Reboot_User($un)
    {
        require "/var/www/html/programs/rebootFunc.php";
        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";

        $db = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );

        // Disable radio interface
        $data = ["MainIn" => "OFF"];
        $db->update("RadioInterface", $data);

        // Mark user inactive
        $db->where("Username", $un);
        $data = ["Active" => "0"];
        $db->update("Users", $data);

        // Remove login record
        $db->where("Username", $un);
        $db->delete("LoggedIn");

        // Reboot server
        RebootServer($un);
    }

    /**
     * Confirm session validity (modern)
     * Redirects to login if session missing or invalid
     */
    function confirm_Member($userName)
    {
        if (
            !isset($_SESSION["myPort"]) ||
            !isset($_SESSION["myRadio"]) ||
            !isset($_SESSION["myRadioName"])
        ) {
            header("Location: /login.php");
            exit();
        }
        if (isset($_SESSION["myCall"]) && $_SESSION["myCall"] == "NG") {
            header("Location: /login.php");
            exit();
        }
    }

    /**
     * Legacy session validation with IP check
     * @param string $userName
     * @return bool True if session valid, else false
     */
    function confirm_MemberOLD($userName)
    {
        $_SESSION["myUsername"] = $userName;
        $un = $_SESSION["myUsername"];

        // Determine client IP
        if (!empty($_SERVER["REMOTE_ADDR"])) {
            $ip = $_SERVER["REMOTE_ADDR"];
        } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }

        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";
        require_once "/var/www/html/programs/GetUserFieldFunc.php";

        $db = new \MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );

        $db->where("Username", $un);
        $row2 = $db->getOne("Users");

        $db->where("CurrentIP", $ip);
        $db->where("Username", $un);
        $row1 = $db->getOne("LoggedIn");

        if ($db->count > 0) {
            // Optional inactivity check (commented)
            $db->where("Username", $un);
            $data = ["TimeOn" => time()];
            $db->update("LoggedIn", $data);
            echo "\n";
        } else {
            print_r($_SESSION);
            echo "\n";
            // header("Location: /login.php");
            return false;
        }
        return true;
    }

    /**
     * Send Pushover notification when visitor logs in or out
     *
     * @param string $event  "login" or "logout"
     * @param string $ip     Visitor IP address
     */
    function getActiveUsers()
    {
        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";
        $db = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );
        $db->where("Active", "1");
        return $db->get("Users", null, ["Username", "MyCall"]) ?: [];
    }

    function notify_visitor($event, $ip = "", $un = "", $callOverride = "")
    {
        require_once "/var/www/html/classes/MysqliDb.php";
        require "/var/www/html/programs/sqldata.php";

        $db = new MysqliDb(
            "localhost",
            $sql_radio_username,
            $sql_radio_password,
            $sql_radio_database
        );

        // Read settings from account uID=1 (admin)
        $db->where("uID", 1);
        $row = $db->getOne("Users");
        if (!$row) {
            return;
        }

        $notify = $row["PushoverNotify"] ?? "none";
        if ($notify === "none") {
            return;
        }
        if ($notify === "login" && $event !== "login") {
            return;
        }
        if ($notify === "logout" && $event !== "logout") {
            return;
        }

        $token = trim($row["PushoverToken"] ?? "");
        $user = trim($row["PushoverUser"] ?? "");
        $delay = intval($row["PushoverDelay"] ?? 60);
        $lastSent = intval($row["PushoverLast"] ?? 0);

        if (!$token || !$user) {
            return;
        }

        // Enforce delay between messages
        if (time() - $lastSent < $delay) {
            return;
        }

        // Build message
        $ts = date("Y-m-d H:i:s");
        // Look up callsign for this user
        $call = "";
        if ($callOverride) {
            $call = $callOverride . " (guest/" . $un . ")";
        } elseif ($un) {
            $db->where("Username", $un);
            $uRow = $db->getOne("Users");
            $call = $uRow["MyCall"] ?? "";
        }
        $who = $callOverride
            ? $call
            : ($call
                ? $call . " (" . $un . ")"
                : ($un ?:
                "unknown"));
        $tsZ = gmdate("Y-m-d H:i") . "Z";
        $verb = $event === "login" ? "in" : "out";
        $msg = $who . " logged " . $verb . " at " . $tsZ;
        if ($ip) {
            $msg .= " from " . $ip;
        }

        // Count active users
        $db->where("Active", "1");
        $activeUsers = $db->getValue("Users", "count(*)");
        $msg .= ". Active users: " . (int) $activeUsers;

        // Send via Pushover REST API
        $ch = curl_init("https://api.pushover.net/1/messages.json");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            http_build_query([
                "token" => $token,
                "user" => $user,
                "title" => "RigPi Visitor Alert",
                "message" => $msg,
            ])
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Update last sent timestamp regardless of success
        $db->where("uID", 1);
        $db->update("Users", ["PushoverLast" => time()]);

        if ($httpCode === 200) {
            error_log(
                "[RigPi] Pushover sent: visitor $event
",
                3,
                "/var/log/rigpi-access.log"
            );
        } else {
            error_log(
                "[RigPi] Pushover failed ($httpCode): $response
",
                3,
                "/var/log/rigpi-access.log"
            );
        }
    }
}
?>
