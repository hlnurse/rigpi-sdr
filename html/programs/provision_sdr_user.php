<?php
/**
 * provision_sdr_user.php
 * Called by SetUsers.php when a user's Access_Level changes from 9 to 2.
 * Runs the shell provisioning script as root via sudo.
 *
 * Place in /var/www/html/programs/
 */

function provision_sdr_user($uID, $username)
{
    $uID = intval($uID);
    $username = preg_replace("/[^a-z0-9_]/", "", strtolower($username));

    if ($uID < 3) {
        return;
    } // Never provision admin or system accounts

    $script = "/home/pi/sdr_web/provision_sdr_user.sh";
    $logFile = "/var/log/rigpi-provision.log";

    if (!file_exists($script)) {
        error_log("[PROVISION] Script not found: $script", 3, $logFile);
        return;
    }

    $cmd =
        escapeshellcmd("sudo $script") .
        " " .
        escapeshellarg((string) $uID) .
        " " .
        escapeshellarg($username) .
        " >> $logFile 2>&1 &";
    exec($cmd);
    error_log(
        "[PROVISION] Triggered for uID=$uID user=$username\n",
        3,
        $logFile
    );
}
?>
