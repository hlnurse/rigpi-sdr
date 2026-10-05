<?php
/**
 * update_clusters.php
 * Fetches current DX cluster list from dxcluster.info and updates the
 * station.Clusters table.  Admin only.
 *
 * Usage: POST or GET with ?action=update
 * Returns JSON: {"ok":true,"added":N,"matched":N,"total":N,"errors":[...]}
 */

header('Content-Type: application/json');

// ── Auth check ────────────────────────────────────────────────────────────────
session_start();
require_once '/var/www/html/programs/sqldata.php';
$isLocal = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
$isLoggedIn = isset($_SESSION['myUsername']) && !empty($_SESSION['myUsername']);
$isAdmin = false;
if ($isLoggedIn) {
    $authDb = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);
    if (!$authDb->connect_errno) {
        $uname = $authDb->real_escape_string($_SESSION['myUsername']);
        $res = $authDb->query("SELECT Access_Level FROM Users WHERE Username='$uname' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) { $isAdmin = ($row['Access_Level'] == 1); }
        $authDb->close();
    }
}
if (!$isAdmin && !$isLocal) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── DB connection ─────────────────────────────────────────────────────────────
// Use RigPi's existing DB credentials
require_once '/var/www/html/programs/sqldata.php';
$mysqli = new mysqli('localhost', $sql_radio_username, $sql_radio_password, $sql_radio_database);

if ($mysqli->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'DB connect failed: ' . $mysqli->connect_error]);
    exit;
}

// ── Fetch cluster list ────────────────────────────────────────────────────────
$url = 'https://www.dxcluster.info/telnet/index.php';
$ctx = stream_context_create([
    'http' => [
        'timeout' => 20,
        'header'  => "User-Agent: RigPi-ClusterUpdate/1.0\r\n"
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false
    ]
]);

$html = @file_get_contents($url, false, $ctx);
if ($html === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch ' . $url]);
    exit;
}

// ── Parse HTML ────────────────────────────────────────────────────────────────
$clusters = [];
$errors   = [];

// Match table rows with cluster data
// Pattern: <td><a id="CALL" href="telnet://HOST:PORT">...</a><br>HOST:PORT</td>
//          <td>LOCATION<br>GRID</td>
$rowPattern = '/<tr[^>]*>\s*<td><a\s+id="([^"]+)"\s+href="telnet:\/\/([^"]+)"[^>]*>/i';
preg_match_all($rowPattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

foreach ($matches as $m) {
    $nodeCall = trim($m[1][0]);
    $hostPort = trim($m[2][0]);

    // Parse host and port
    if (strpos($hostPort, ':') !== false) {
        $parts = explode(':', $hostPort, 2);
        $host  = trim($parts[0]);
        $port  = trim($parts[1]);
    } else {
        $host = trim($hostPort);
        $port = '7300';  // default DX cluster port
    }

    // Get offset in HTML to find the next <td> (location/grid)
    $offset   = $m[0][1] + strlen($m[0][0]);
    $snippet  = substr($html, $offset, 300);

    // Extract location and grid from next <td>
    $location = '';
    $grid     = '';
    if (preg_match('/<\/td>\s*<td>([^<]*)<br[^>]*>([^<]*)<\/td>/i', $snippet, $locm)) {
        $location = trim(strip_tags($locm[1]));
        $grid     = trim(strip_tags($locm[2]));
    }

    if ($nodeCall && $host) {
        $clusters[] = [
            'NodeCall' => substr($nodeCall, 0, 58),
            'IP'       => substr($host,     0, 30),
            'Port'     => substr($port,     0, 10),
            'Location' => substr($location, 0, 44),
            'Grid'     => substr($grid,     0, 10),
            'Notes'    => ''
        ];
    }
}

if (empty($clusters)) {
    echo json_encode(['ok' => false, 'error' => 'No clusters parsed from page', 'html_len' => strlen($html)]);
    exit;
}

// ── Update DB ─────────────────────────────────────────────────────────────────
$added   = 0;
$updated = 0;

// Prepare statements
$checkStmt = $mysqli->prepare("SELECT ID FROM Clusters WHERE NodeCall = ?");
$updateStmt = $mysqli->prepare(
    "UPDATE Clusters SET IP=?, Port=?, Location=?, Grid=? WHERE NodeCall=?"
);
$insertStmt = $mysqli->prepare(
    "INSERT INTO Clusters (NodeCall, IP, Port, Location, Grid, Notes) VALUES (?,?,?,?,?,'')"
);

foreach ($clusters as $c) {
    $checkStmt->bind_param('s', $c['NodeCall']);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        // Update existing
        $updateStmt->bind_param(
            'sssss',
            $c['IP'], $c['Port'], $c['Location'], $c['Grid'], $c['NodeCall']
        );
        if ($updateStmt->execute()) {
            $updated++;
        } else {
            $errors[] = "Update failed for {$c['NodeCall']}: " . $updateStmt->error;
        }
    } else {
        // Insert new
        $insertStmt->bind_param(
            'sssss',
            $c['NodeCall'], $c['IP'], $c['Port'], $c['Location'], $c['Grid']
        );
        if ($insertStmt->execute()) {
            $added++;
        } else {
            $errors[] = "Insert failed for {$c['NodeCall']}: " . $insertStmt->error;
        }
    }
    $checkStmt->free_result();
}

$checkStmt->close();
$updateStmt->close();
$insertStmt->close();

// Get final count
$result = $mysqli->query("SELECT COUNT(*) as cnt FROM Clusters");
$row    = $result->fetch_assoc();
$total  = $row['cnt'];

$mysqli->close();

echo json_encode([
    'ok'      => true,
    'added'   => $added,
    'matched' => $updated,
    'total'   => $total,
    'errors'  => $errors,
    'parsed'  => count($clusters)
]);
