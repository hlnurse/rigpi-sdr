<?php
/**
 * @author Howard Nurse, W6HN
 *
 * SDR instance helpers.
 *
 * An "instance" is a running sdr_web server on port 8000+N, reachable through
 * nginx at /sdrN/.  Instances are infrastructure: they are created when the
 * image is built, not by a form POST.  They are SHARED -- several accounts may
 * use the same instance, and sdr_web multiplexes them.  Assignment is not
 * exclusive; only concurrent *use* is limited, and that is checked at login.
 *
 * Four values live on a Users row and are frequently confused.  They are
 * independent:
 *
 *   Access_Level  - permission tier (1 admin .. 10 ptt-only, 9 = pending)
 *   instance N    - which /sdrN this account uses.  Chosen by the admin.
 *   SDRHost/Port  - DERIVED from N.  Never typed by hand, never a form field.
 *   SelectedRadio - which MySettings rig row.  Unrelated to N and to uID.
 *
 * It must live in the programs folder.
 */

define("SDR_PORT_BASE", 8000);
define("SDR_PROBE_MIN", 1);
define("SDR_PROBE_MAX", 10);

/**
 * Port for instance N.
 */
function sdrPortFor($n)
{
    return SDR_PORT_BASE + (int) $n;
}

/**
 * Canonical LAN URL for instance N, e.g. http://rigpi5.local/sdr2/
 *
 * NOTE the scheme.  A schemeless value ("rigpi5.local/sdr2") is treated by the
 * browser as a *relative path*, so the iframe loads the main page inside
 * itself, which loads it again, ad infinitum.  Always include the scheme.
 */
function sdrUrlFor($n, $host = null)
{
    $host = $host ?: gethostname();
    return "http://" . $host . ".local/sdr" . (int) $n . "/";
}

/**
 * Is this a usable SDR host URL?  Requires a scheme and a /sdrN path.
 * Both are needed: a pathless URL (http://rigpi5.local) falls through nginx's
 * `location /` to index.php and recurses exactly like a schemeless one.
 */
function sdrHostValid($url)
{
    return (bool) preg_match(
        '#^https?://[A-Za-z0-9\.\-]+(:\d+)?/sdr\d+/?$#',
        trim((string) $url)
    );
}

/**
 * Extract the instance number from a stored SDRHost, or 0 if unparseable.
 */
function sdrInstanceOf($url)
{
    if (preg_match('#/sdr(\d+)/?$#', trim((string) $url), $m)) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * Is an sdr_web server listening on this instance's port?
 * A short-timeout TCP connect to loopback; ~10 of these costs a few ms.
 */
function sdrInstanceExists($n)
{
    $sock = @fsockopen("127.0.0.1", sdrPortFor($n), $errno, $errstr, 0.15);
    if ($sock) {
        fclose($sock);
        return true;
    }
    return false;
}

/**
 * All instances currently running, as a list of ints.
 */
function sdrInstancesRunning()
{
    $out = [];
    for ($n = SDR_PROBE_MIN; $n <= SDR_PROBE_MAX; $n++) {
        if (sdrInstanceExists($n)) {
            $out[] = $n;
        }
    }
    return $out;
}

/**
 * Map of instance N => list of usernames assigned to it.
 *
 * Instances are SHARED, not exclusive: sdr_web multiplexes several accounts on
 * one instance (each client slices its own view from the full FFT).  Two
 * accounts on /sdr2 is the intended design, not a collision.  Limits on
 * concurrent *use* are enforced at login, not at assignment.
 */
function sdrInstanceOccupants($db)
{
    $occ = [];
    $rows = $db->get("Users", null, ["uID", "Username", "SDRHost"]);
    foreach ($rows ?: [] as $r) {
        $n = sdrInstanceOf($r["SDRHost"] ?? "");
        if ($n > 0) {
            $occ[$n][] = $r["Username"];
        }
    }
    return $occ;
}

/**
 * Instances offerable in the admin dropdown.
 *
 * Every running instance is selectable.  Occupants are returned for display
 * only ("sdr2 - guest1, w6hn"), never to disable a choice.
 *
 * Returns [ n => ['port'=>int, 'url'=>string, 'occupants'=>string[]], ... ]
 */
function sdrInstanceOptions($db)
{
    $occ = sdrInstanceOccupants($db);
    $opts = [];
    foreach (sdrInstancesRunning() as $n) {
        $opts[$n] = [
            "port" => sdrPortFor($n),
            "url" => sdrUrlFor($n),
            "occupants" => $occ[$n] ?? [],
        ];
    }
    return $opts;
}
?>
