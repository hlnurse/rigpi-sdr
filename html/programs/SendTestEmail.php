<?php
// SendTestEmail.php - Send a test email to verify address
require "/var/www/html/programs/sqldata.php";

$to = isset($_POST['email']) ? trim($_POST['email']) : '';
$un = isset($_POST['un'])    ? trim($_POST['un'])    : 'unknown';

if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address.";
    exit;
}

$subject = "RigPi Test Email";
$body    = "This is a test email from RigPi for user: " . htmlspecialchars($un) . "\r\n\r\nIf you received this, your email address is configured correctly.";
$headers = "From: RigPi <noreply@rigpi.local>\r\nContent-Type: text/plain; charset=UTF-8";

if (mail($to, $subject, $body, $headers)) {
    echo "Test email sent to <b>" . htmlspecialchars($to) . "</b>. Check your inbox.";
} else {
    echo "mail() failed. Check that sendmail or an SMTP relay is configured on the Pi.<br>Try: <code>sudo apt install ssmtp</code> or configure msmtp.";
}
?>
