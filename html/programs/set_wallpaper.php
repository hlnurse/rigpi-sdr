<?php
session_start();
// Admin only
require_once '/var/www/html/programs/sqldata.php';
$isAdmin = false;
if (isset($_SESSION['myUsername'])) {
    $db = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    $u = $db->real_escape_string($_SESSION['myUsername']);
    $r = $db->query("SELECT Access_Level FROM Users WHERE Username='$u' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) $isAdmin = ($row['Access_Level'] == 1);
    $db->close();
}
if (!$isAdmin) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$image = $_POST['image'] ?? '';
$wallpaper_dir = '/usr/share/rpd-wallpaper/';

// Validate — must be in wallpaper dir, no path traversal
$basename = basename($image);
$full_path = $wallpaper_dir . $basename;
if (!file_exists($full_path) || !preg_match('/\.(jpg|jpeg|png)$/i', $basename)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid image']);
    exit;
}

// Update all pcmanfm config files
$configs = glob('/home/pi/.config/pcmanfm/*/desktop-items*.conf');
$updated = 0;
foreach ($configs as $cfg) {
    $content = file_get_contents($cfg);
    $new = preg_replace('/^wallpaper=.*$/m', 'wallpaper=' . $full_path, $content);
    if ($new !== $content) {
        file_put_contents($cfg, $new);
        $updated++;
    }
}

echo json_encode(['ok'=>true,'image'=>$basename,'configs'=>$updated]);
?>
