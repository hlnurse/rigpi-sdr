#!/usr/bin/env python3
"""
elmer_workability.py
ELMER Workability Index — data fetcher and scoring engine.
Fetches solar conditions, PSKReporter, ARRL DX bulletin, cluster spots,
and computes a 0-100 workability score for a target callsign/entity.

Usage (from sdr_web_server_minimal.py):
    from elmer_workability import WorkabilityEngine
    engine = WorkabilityEngine(my_call="W6HN", my_grid="EN90bt")
    result = engine.score(target_call="3B9G", band="20m")
"""

import math
import time
import json
import re
import threading
import urllib.request
import urllib.parse
from datetime import datetime, timezone

# ── Grid square to lat/lon ────────────────────────────────────────────────────
def grid_to_latlon(grid):
    """Convert Maidenhead grid locator to (lat, lon) center."""
    g = grid.upper()
    if len(g) < 4:
        return None, None
    lon = (ord(g[0]) - ord('A')) * 20 - 180
    lat = (ord(g[1]) - ord('A')) * 10 - 90
    lon += (int(g[2])) * 2
    lat += (int(g[3])) * 1
    if len(g) >= 6:
        lon += (ord(g[4]) - ord('A')) * 5 / 60
        lat += (ord(g[5]) - ord('A')) * 2.5 / 60
    lon += 1.0   # center of square
    lat += 0.5
    return lat, lon

# ── Great circle bearing and distance ────────────────────────────────────────
def bearing_distance(lat1, lon1, lat2, lon2):
    """Return (short_path_bearing_deg, distance_km, long_path_bearing_deg)."""
    R = 6371
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat/2)**2 + math.cos(lat1)*math.cos(lat2)*math.sin(dlon/2)**2
    c = 2*math.asin(math.sqrt(a))
    dist = R * c
    # Bearing
    x = math.sin(dlon)*math.cos(lat2)
    y = math.cos(lat1)*math.sin(lat2) - math.sin(lat1)*math.cos(lat2)*math.cos(dlon)
    bearing = (math.degrees(math.atan2(x, y)) + 360) % 360
    long_path = (bearing + 180) % 360
    return round(bearing, 1), round(dist, 0), round(long_path, 1)

# ── Grayline check ────────────────────────────────────────────────────────────
def grayline_score(lat, lon):
    """
    Return 0.0-1.0 score based on proximity to terminator.
    1.0 = on terminator (best), 0.5 = twilight, 0.0 = deep day/night.
    """
    now = datetime.now(timezone.utc)
    doy = now.timetuple().tm_yday
    # Solar declination
    decl = math.radians(23.45 * math.sin(math.radians(360/365 * (doy - 81))))
    # Hour angle
    utc_h = now.hour + now.minute/60
    solar_noon_lon = -15 * (utc_h - 12)
    ha = math.radians((lon - solar_noon_lon) % 360)
    if ha > math.pi: ha -= 2*math.pi
    # Sun elevation
    lat_r = math.radians(lat)
    elev = math.degrees(math.asin(
        math.sin(lat_r)*math.sin(decl) +
        math.cos(lat_r)*math.cos(decl)*math.cos(ha)
    ))
    # Score: best near -6 to +6 degrees (twilight zone)
    if -6 <= elev <= 6:
        return 1.0
    elif -12 <= elev <= 12:
        return 0.7
    elif elev < -18:
        return 0.4  # night — decent
    else:
        return 0.2  # full daylight — not ideal

# ── NOAA Solar data ───────────────────────────────────────────────────────────
def fetch_solar_data():
    """Fetch SFI and K-index from NOAA SWPC. Returns dict."""
    result = {'sfi': None, 'k_index': None, 'ok': False}
    try:
        # Solar flux
        url = 'https://services.swpc.noaa.gov/json/solar-cycle/observed-solar-cycle-indices.json'
        req = urllib.request.Request(url, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req, timeout=8) as r:
            data = json.loads(r.read())
        if data:
            result['sfi'] = data[-1].get('f10.7', None)

        # K-index (recent)
        url2 = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json'
        req2 = urllib.request.Request(url2, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req2, timeout=8) as r2:
            kdata = json.loads(r2.read())
        if kdata and len(kdata) > 1:
            # Last entry [time, kp]
            result['k_index'] = float(kdata[-1][1])

        result['ok'] = True
    except Exception as e:
        result['error'] = str(e)
    return result

def solar_score(sfi, k_index):
    """Score solar conditions 0.0-1.0."""
    score = 0.5
    if sfi is not None:
        if sfi >= 150:   score += 0.25
        elif sfi >= 120: score += 0.15
        elif sfi >= 100: score += 0.05
        elif sfi < 70:   score -= 0.2
    if k_index is not None:
        if k_index <= 1:   score += 0.25
        elif k_index <= 2: score += 0.15
        elif k_index <= 3: score += 0.0
        elif k_index <= 4: score -= 0.15
        else:              score -= 0.3
    return max(0.0, min(1.0, score))

# ── PSKReporter ───────────────────────────────────────────────────────────────
def fetch_pskreporter(target_call, my_grid, band_mhz=None):
    """
    Check if target_call has been heard by stations near my_grid.
    Returns dict with heard_count, nearest_reporter, distance_km.
    """
    result = {'heard_count': 0, 'nearest_km': None, 'reporters': [], 'ok': False}
    try:
        params = {
            'senderCallsign': target_call,
            'flowStartSeconds': '-3600',  # last hour
            'modify': 'grid',
            'lastsequence': '0',
        }
        if band_mhz:
            params['freeText'] = ''
        url = 'https://retrieve.pskreporter.info/query?' + urllib.parse.urlencode(params)
        req = urllib.request.Request(url, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req, timeout=10) as r:
            xml = r.read().decode('utf-8', errors='ignore')

        # Parse reception reports
        my_lat, my_lon = grid_to_latlon(my_grid)
        reports = re.findall(
            r'receiverCallsign="([^"]+)"[^>]*locator="([^"]+)"[^>]*frequency="(\d+)"',
            xml
        )
        nearest = None
        for call, loc, freq in reports:
            rlat, rlon = grid_to_latlon(loc[:6])
            if rlat is None or my_lat is None:
                continue
            _, dist, _ = bearing_distance(my_lat, my_lon, rlat, rlon)
            result['reporters'].append({
                'call': call, 'grid': loc[:6],
                'freq_mhz': round(int(freq)/1e6, 4),
                'dist_km': dist
            })
            if nearest is None or dist < nearest:
                nearest = dist

        result['heard_count'] = len(result['reporters'])
        result['nearest_km'] = nearest
        result['ok'] = True
    except Exception as e:
        result['error'] = str(e)
    return result

def pskreporter_score(heard_count, nearest_km, my_dist_km):
    """Score PSKReporter data 0.0-1.0."""
    if heard_count == 0:
        return 0.0
    # Bonus for reporters close to us
    proximity_bonus = 0.0
    if nearest_km is not None and my_dist_km is not None:
        ratio = nearest_km / max(my_dist_km, 1)
        if ratio < 0.3:    proximity_bonus = 0.4
        elif ratio < 0.6:  proximity_bonus = 0.25
        elif ratio < 1.0:  proximity_bonus = 0.1
    base = min(0.6, heard_count * 0.1)
    return min(1.0, base + proximity_bonus)

# ── ARRL DX Bulletin ─────────────────────────────────────────────────────────
def fetch_arrl_bulletin():
    """Fetch latest ARRL DX bulletin and return text."""
    result = {'text': '', 'date': '', 'ok': False}
    try:
        # Get bulletin list
        url = 'http://www.arrl.org/w1aw-bulletins-archive-dx'
        req = urllib.request.Request(url, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req, timeout=10) as r:
            html = r.read().decode('utf-8', errors='ignore')

        # Find latest bulletin link
        m = re.search(r"href='(/w1awbulletinsdxissue\?[^']+)'", html)
        if not m:
            result['error'] = 'No bulletin links found'
            return result

        burl = 'http://www.arrl.org' + m.group(1)
        req2 = urllib.request.Request(burl, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req2, timeout=10) as r2:
            bhtml = r2.read().decode('utf-8', errors='ignore')

        # Extract text
        bm = re.search(r'ZCZC AE\d+(.*?)NNNN', bhtml, re.DOTALL)
        if bm:
            text = re.sub(r'<[^>]+>', ' ', bm.group(1))
            text = re.sub(r'&nbsp;', ' ', text)
            text = re.sub(r'&amp;', '&', text)
            text = re.sub(r'\s+', ' ', text).strip()
            result['text'] = text
            result['ok'] = True

            # Extract date
            dm = re.search(r'([A-Z][a-z]+ \d+, \d{4})', text)
            if dm:
                result['date'] = dm.group(1)
    except Exception as e:
        result['error'] = str(e)
    return result

def check_bulletin_for_call(bulletin_text, target_call):
    """Check if target callsign is mentioned in bulletin."""
    prefix = target_call.split('/')[0] if '/' in target_call else target_call
    # Check for exact call or portable variant
    patterns = [prefix, target_call]
    for p in patterns:
        if re.search(r'\b' + re.escape(p) + r'\b', bulletin_text, re.IGNORECASE):
            # Extract the sentence mentioning it
            idx = bulletin_text.upper().find(p.upper())
            # Find start of entry — look back up to 200 chars for last ". "
            start = max(0, idx-200)
            chunk = bulletin_text[start:idx]
            period = chunk.rfind('. ')
            start = (start + period + 2) if period >= 0 else max(0, idx-20)
            snippet = bulletin_text[start:start+350]
            # Trim to end of this entry — stop before next DXCC header
            import re as _re
            _nm = _re.search(r"[.] [A-Z][A-Z ]+, [A-Z0-9]{2,4}[.]", snippet[30:])
            if _nm: snippet = snippet[:30 + _nm.start() + 1]
            return True, snippet.strip()
    return False, ''

# ── Band MUF estimation ───────────────────────────────────────────────────────
BAND_MHZ = {
    '160m': 1.8, '80m': 3.5, '60m': 5.3, '40m': 7.0,
    '30m': 10.1, '20m': 14.0, '17m': 18.0, '15m': 21.0,
    '12m': 24.9, '10m': 28.0, '6m': 50.0,
    '2m': 144.0, '1.25m': 222.0, '70cm': 432.0, '23cm': 1296.0
}

def band_muf_score(band, sfi, dist_km):
    """
    Rough MUF estimation based on SFI and path distance.
    Returns 0.0-1.0 probability band is usable.
    """
    freq = BAND_MHZ.get(band, 14.0)
    if sfi is None:
        sfi = 100

    # Rough MUF formula (simplified)
    # MUF peaks around 3000km for ionospheric paths
    if dist_km < 500:
        # Near-vertical incidence — lower bands better
        muf_est = 5 + sfi * 0.08
    elif dist_km < 3000:
        muf_est = 10 + sfi * 0.15
    elif dist_km < 8000:
        muf_est = 14 + sfi * 0.18
    else:
        muf_est = 12 + sfi * 0.14

    if freq <= muf_est * 0.85:
        return 1.0   # well below MUF — good
    elif freq <= muf_est:
        return 0.75  # near MUF — ok
    elif freq <= muf_est * 1.1:
        return 0.4   # slightly above MUF — possible
    else:
        return 0.0   # above MUF — unlikely

# ── Local path / LOS scoring ──────────────────────────────────────────────────
VHF_UHF_BANDS = {'2m', '70cm', '6m', '1.25m', '23cm'}

def los_horizon_km(h1_m=10, h2_m=10):
    """Optical LOS horizon distance for two antenna heights (metres)."""
    return 4.12 * (math.sqrt(h1_m) + math.sqrt(h2_m))

def path_type(dist_km, band):
    """
    Classify propagation mode.
    Returns one of: 'los', 'groundwave', 'near_skip', 'skywave'
    """
    band_mhz = BAND_MHZ.get(band, 14.0)
    if band_mhz >= 50:
        return 'los'           # VHF/UHF always LOS regardless of distance
    if dist_km is None:
        return 'skywave'
    if dist_km < 50:
        return 'groundwave'    # HF very short
    if dist_km < 300:
        return 'near_skip'     # HF skip zone
    return 'skywave'

def local_path_score(band, dist_km, ant_height_m=10):
    """
    Score for local/short paths where standard MUF model does not apply.
    Returns (score 0.0-1.0, path_type_str, warning_str).
    """
    # Same station or zero distance — perfect on any band
    if dist_km is not None and dist_km == 0:
        return 1.0, 'local', ''
    band_mhz = BAND_MHZ.get(band, 14.0)
    ptype = path_type(dist_km, band)
    horizon_km = los_horizon_km(ant_height_m, ant_height_m)

    if ptype == 'los':
        if dist_km <= horizon_km:
            return 0.90, 'los', ''
        elif dist_km <= horizon_km * 1.5:
            return 0.60, 'los', f'Path ({dist_km:.0f}km) is near LOS horizon (~{horizon_km:.0f}km) — terrain may block.'
        elif dist_km <= 300:
            return 0.30, 'los', f'Beyond LOS horizon ({dist_km:.0f}km vs ~{horizon_km:.0f}km) — tropo or aircraft scatter only.'
        else:
            return 0.15, 'los', 'Well beyond LOS — troposcatter or Es required.'

    elif ptype == 'groundwave':
        if band_mhz <= 3.5:
            return 0.80, 'groundwave', ''
        elif band_mhz <= 7.0:
            return 0.65, 'groundwave', ''
        elif band_mhz <= 14.0:
            return 0.30, 'groundwave', f'Groundwave on {band} is weak for local contacts — try 40m or 80m.'
        else:
            return 0.10, 'groundwave', f'{band} groundwave is negligible for local contacts — use 40m/80m or VHF/UHF.'

    elif ptype == 'near_skip':
        if band_mhz <= 7.0:
            return 0.40, 'near_skip', f'{dist_km:.0f}km is in the skip zone for {band} — groundwave marginal, skywave cannot reach.'
        else:
            return 0.05, 'near_skip', f'{dist_km:.0f}km is in the skip zone for {band}. Try 40m/80m or VHF/UHF for local contact.'

    return None, 'skywave', ''

# ── Antenna bearing score ─────────────────────────────────────────────────────
def antenna_score(beam_heading, target_bearing, beam_width=60):
    """Score antenna alignment 0.0-1.0. beam_heading=None means omnidirectional."""
    if beam_heading is None:
        return 0.6  # omni — decent but not optimal
    diff = abs(((target_bearing - beam_heading) + 180) % 360 - 180)
    if diff <= beam_width / 2:
        return 1.0
    elif diff <= beam_width:
        return 0.7
    elif diff <= 90:
        return 0.3
    else:
        return 0.0


# ── DXSummit activity analysis ────────────────────────────────────────────────
def fetch_dxsummit_activity(target_call, limit=100):
    """
    Fetch recent spots from DXSummit and compute activity window.
    Returns dict with peak_hour, active_hours, top_bands, spot_count, window_str.
    """
    result = {'ok': False, 'spot_count': 0, 'top_bands': [], 'peak_hour': None,
              'window_start': None, 'window_end': None, 'window_str': '', 'active_hours': []}
    try:
        from collections import Counter
        url = f"http://www.dxsummit.fi/api/v1/spots?dx_calls={target_call}&limit={limit}"
        req = urllib.request.Request(url, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req, timeout=10) as r:
            spots = json.loads(r.read())

        if not spots:
            result['ok'] = True
            return result

        hours = []
        bands = []
        BAND_MAP = [(2,'160m'),(4,'80m'),(8,'40m'),(11,'30m'),(15,'20m'),
                    (18.5,'17m'),(22,'15m'),(25,'12m'),(50,'10m'),(300,'6m')]
        for s in spots:
            t = s.get('time', '')
            freq = float(s.get('frequency', 0) or 0)
            if t and len(t) >= 13:
                try: hours.append(int(t[11:13]))
                except: pass
            if freq > 0:
                mhz = freq / 1000
                for lim, band in BAND_MAP:
                    if mhz < lim:
                        bands.append(band)
                        break

        hour_counts = Counter(hours)
        band_counts = Counter(bands)

        result['spot_count'] = len(spots)
        result['top_bands'] = [b for b,_ in band_counts.most_common(3)]
        result['ok'] = True

        if not hours:
            return result

        result['peak_hour'] = hour_counts.most_common(1)[0][0]
        active = sorted([h for h,c in hour_counts.items() if c >= 1])
        result['active_hours'] = active

        # Find longest contiguous window
        if active:
            best_start, best_end, cur_start = active[0], active[0], active[0]
            for i in range(1, len(active)):
                if active[i] - active[i-1] <= 2:  # allow 1-hour gap
                    best_end = active[i]
                else:
                    if best_end - cur_start > best_end - best_start:
                        best_start, best_end = cur_start, active[i-1]
                    cur_start = active[i]
            result['window_start'] = best_start
            result['window_end'] = best_end
            result['window_str'] = f"{best_start:02d}00-{best_end:02d}59z"

    except Exception as e:
        result['error'] = str(e)
    return result


# ── QRZ lookup ────────────────────────────────────────────────────────────────
def fetch_qrz_grid(callsign, qrz_user, qrz_pwd):
    """Look up callsign on QRZ XML API, return grid square or None."""
    try:
        import xml.etree.ElementTree as ET
        # Get session key
        login_url = f"https://xmldata.qrz.com/xml/current/?username={qrz_user}&password={qrz_pwd}&agent=ELMER-WI/1.0"
        req = urllib.request.Request(login_url, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req, timeout=8) as r:
            tree = ET.parse(r)
        root = tree.getroot()
        ns = {'q': 'http://xmldata.qrz.com'}
        key_el = root.find('.//q:Key', ns)
        if key_el is None:
            return None, None
        key = key_el.text

        # Look up callsign
        lookup_url = f"https://xmldata.qrz.com/xml/current/?s={key}&callsign={callsign}"
        req2 = urllib.request.Request(lookup_url, headers={'User-Agent': 'ELMER-WI/1.0'})
        with urllib.request.urlopen(req2, timeout=8) as r2:
            tree2 = ET.parse(r2)
        root2 = tree2.getroot()

        grid_el    = root2.find('.//q:grid', ns)
        name_el    = root2.find('.//q:name', ns)
        fname_el   = root2.find('.//q:fname', ns)
        country_el = root2.find('.//q:country', ns)
        lotw_el    = root2.find('.//q:lotw', ns)
        url_el     = root2.find('.//q:url', ns)
        image_el   = root2.find('.//q:image', ns)
        bio_el     = root2.find('.//q:bio', ns)
        addr1_el   = root2.find('.//q:addr1', ns)
        addr2_el   = root2.find('.//q:addr2', ns)
        state_el   = root2.find('.//q:state', ns)
        eqsl_el    = root2.find('.//q:eqsl', ns)
        email_el   = root2.find('.//q:email', ns)
        grid    = grid_el.text[:6] if grid_el is not None and grid_el.text else None
        fname   = fname_el.text if fname_el is not None else None
        lname   = name_el.text if name_el is not None else None
        name    = ((fname or "") + " " + (lname or "")).strip() or lname
        country = country_el.text if country_el is not None else None
        lotw    = lotw_el.text if lotw_el is not None else None
        eqsl    = eqsl_el.text if eqsl_el is not None else None
        email   = email_el.text if email_el is not None else None
        url     = url_el.text if url_el is not None else None
        image   = image_el.text if image_el is not None else None
        addr1   = addr1_el.text if addr1_el is not None else None
        addr2   = addr2_el.text if addr2_el is not None else None
        state   = state_el.text if state_el is not None else None

        return grid, {
            'name': name, 'country': country, 'lotw': lotw, 'eqsl': eqsl,
            'url': url, 'image': image, 'addr1': addr1, 'addr2': addr2,
            'state': state, 'email': email,
        }
    except Exception as e:
        return None, {'error': str(e)}


# ── HamQTH callsign lookup ────────────────────────────────────────────────────
def fetch_hamqth_grid(callsign, username, password):
    """
    Fetch grid and info from HamQTH XML API.
    Returns (grid_string, info_dict) — grid may be None if not found.
    Session-based: authenticate first, then lookup.
    """
    result = {}
    try:
        # Step 1: authenticate
        auth_url = (
            "https://www.hamqth.com/xml.php"
            f"?u={urllib.parse.quote(username)}"
            f"&p={urllib.parse.quote(password)}"
            "&prg=ELMER"
        )
        req = urllib.request.Request(auth_url, headers={"User-Agent": "ELMER-WI/1.0"})
        with urllib.request.urlopen(req, timeout=8) as r:
            xml = r.read().decode("utf-8", errors="ignore")

        session_id = None
        m = re.search(r"<session_id>([^<]+)</session_id>", xml)
        if m:
            session_id = m.group(1).strip()
        if not session_id:
            err = re.search(r"<error>([^<]+)</error>", xml)
            return None, {"error": err.group(1) if err else "Auth failed"}

        # Step 2: lookup
        lookup_url = (
            "https://www.hamqth.com/xml.php"
            f"?id={session_id}"
            f"&callsign={urllib.parse.quote(callsign)}"
            "&prg=ELMER"
        )
        req2 = urllib.request.Request(lookup_url, headers={"User-Agent": "ELMER-WI/1.0"})
        with urllib.request.urlopen(req2, timeout=8) as r2:
            xml2 = r2.read().decode("utf-8", errors="ignore")

        def _get(tag):
            m2 = re.search(rf"<{tag}>([^<]+)</{tag}>", xml2)
            return m2.group(1).strip() if m2 else None

        grid    = _get("grid")
        name    = _get("nick") or _get("adr_name")
        country = _get("country")
        qsl     = _get("qsl_via")
        lotw    = _get("lotw")
        image   = _get("picture")
        email   = _get("email")
        url     = _get("web")
        addr1   = _get("adr_street1")
        addr2   = _get("adr_city")
        state   = _get("us_state")

        result = {
            "name": name, "country": country, "qsl_via": qsl,
            "lotw": lotw, "image": image, "email": email,
            "url": url, "addr1": addr1, "addr2": addr2, "state": state,
            "source": "hamqth"
        }
        return grid, result

    except Exception as e:
        return None, {"error": str(e)}

# ── Main WorkabilityEngine ────────────────────────────────────────────────────
class WorkabilityEngine:
    """
    Computes ELMER Workability Index for a target station.

    Weights:
        PSKReporter heard nearby   20%
        Band MUF supports path     18%
        Grayline (TX + RX end)     15%
        Solar conditions           15%
        ARRL bulletin mention      12%
        Antenna alignment          10%
        Cluster activity           10%
    """

    WEIGHTS = {
        'pskreporter': 0.20,
        'band_muf':    0.18,
        'grayline':    0.15,
        'solar':       0.15,
        'bulletin':    0.12,
        'antenna':     0.10,
        'cluster':     0.10,
    }

    def __init__(self, my_call='W6HN', my_grid='EN90bt',
                 beam_heading=None, power_w=100):
        self.my_call = my_call
        self.my_grid = my_grid
        self.beam_heading = beam_heading
        self.power_w = power_w
        self.my_lat, self.my_lon = grid_to_latlon(my_grid)

        # Cache solar data (refresh every 15 min)
        self._solar_cache = None
        self._solar_time = 0
        self._bulletin_cache = None
        self._bulletin_time = 0

    def _get_solar(self):
        if time.time() - self._solar_time > 900:
            self._solar_cache = fetch_solar_data()
            self._solar_time = time.time()
        return self._solar_cache or {}

    def _get_bulletin(self):
        if time.time() - self._bulletin_time > 3600:
            self._bulletin_cache = fetch_arrl_bulletin()
            self._bulletin_time = time.time()
        return self._bulletin_cache or {}


    def narrative(self, result):
        """Generate a plain-English assessment from workability result."""
        score   = result.get('score', 0)
        grade   = result.get('grade', '')
        factors = result.get('factors', {})
        details = result.get('details', {})
        band    = result.get('band', '20m')
        dist_km = result.get('distance_km')
        bearing = result.get('bearing')
        lp      = result.get('long_path_bearing')
        solar   = result.get('solar', {})
        target  = result.get('target_call', '')
        sfi     = solar.get('sfi')
        k_idx   = solar.get('k_index')

        parts = []

        # ── Opening: overall assessment ──────────────────────────────────────
        # Short-circuit for same station
        _bdet_narr = details.get('band_muf', {})
        if _bdet_narr.get('path_type') == 'local':
            return f"{target} is at the same location — any band will work locally."

        dist_str = f"{dist_km/1000:.0f} Mm" if dist_km else ""
        if score >= 75:
            parts.append(f"Excellent conditions for working {target} on {band}.")
        elif score >= 55:
            parts.append(f"Good chance of working {target} on {band}.")
        elif score >= 35:
            if dist_km and dist_km > 10000:
                parts.append(f"Fair chance — {target} is a long path ({dist_str}), conditions are marginal.")
            else:
                parts.append(f"Fair conditions for {target} on {band} — worth a try.")
        elif score >= 20:
            parts.append(f"Poor conditions for {target} on {band} at this time.")
        else:
            parts.append(f"Unlikely to work {target} on {band} under current conditions.")

        # ── Local path warning ───────────────────────────────────────────────
        band_det = details.get('band_muf', {})
        local_warn = band_det.get('warning', '')
        local_type = band_det.get('path_type', 'skywave')
        if local_warn:
            parts.append(f"WARNING: {local_warn}")
        elif local_type in ('groundwave', 'los') and dist_km and dist_km < 300:
            band_mhz_local = BAND_MHZ.get(band, 14.0)
            if band_mhz_local >= 50:
                _horizon = los_horizon_km(10, 10)
                if dist_km <= _horizon:
                    parts.append(f"{target} is {dist_km:.0f}km away — within LOS horizon (~{_horizon:.0f}km at 10m antenna height). Clear path likely.")
                else:
                    parts.append(f"{target} is {dist_km:.0f}km away — slightly beyond LOS horizon (~{_horizon:.0f}km). Terrain may affect the path.")
            else:
                parts.append(f"{target} is {dist_km:.0f}km away — groundwave path on {band}.")
        elif local_type == 'near_skip':
            parts.append(f"WARNING: {target} is {dist_km:.0f}km away — likely in the HF skip zone on {band}. Consider VHF/UHF for local contact.")

        # ── Solar conditions ──────────────────────────────────────────────────
        if sfi and k_idx is not None:
            if sfi >= 150 and k_idx <= 2:
                parts.append(f"Solar conditions are excellent (SFI={sfi:.0f}, K={k_idx:.1f}).")
            elif sfi >= 120 and k_idx <= 3:
                parts.append(f"Solar conditions are good (SFI={sfi:.0f}, K={k_idx:.1f}).")
            elif k_idx >= 5:
                parts.append(f"Geomagnetic storm in progress (K={k_idx:.1f}) — expect degraded conditions on polar paths.")
            elif k_idx >= 4:
                parts.append(f"Elevated K-index ({k_idx:.1f}) may degrade higher-latitude paths.")
            elif sfi < 80:
                parts.append(f"Low solar flux (SFI={sfi:.0f}) limits higher band openings.")

        # ── Grayline — skip for local paths ──────────────────────────────────
        _bdet_gl = details.get('band_muf', {})
        _is_local_gl = _bdet_gl.get('path_type', 'skywave') in ('local', 'groundwave', 'near_skip', 'los')
        if not _is_local_gl:
            gl = factors.get('grayline', 0)
            gl_det = details.get('grayline', {})
            my_gl = gl_det.get('my_score', 0)
            dx_gl = gl_det.get('dx_score', 0)
            if gl >= 0.8:
                parts.append("Grayline is favorable at both ends — prime time for this path.")
            elif gl >= 0.6:
                if my_gl > dx_gl:
                    parts.append("Grayline is near your location — good enhancement possible.")
                else:
                    parts.append("Grayline is near the DX end — watch for openings.")
            elif gl < 0.35:
                import datetime
                utc_h = datetime.datetime.utcnow().hour
                if utc_h < 6 or utc_h > 20:
                    parts.append("Grayline not favorable now — try near local sunrise for best path.")
                else:
                    parts.append("Grayline not aligned — conditions may improve near sunrise/sunset.")

        # ── PSKReporter — skip "not open" message for local paths ────────────
        _bdet_psk = details.get('band_muf', {})
        _is_local_psk = _bdet_psk.get('path_type', 'skywave') in ('local', 'groundwave', 'near_skip', 'los')
        psk = details.get('pskreporter', {})
        heard = psk.get('heard_count', 0)
        nearest = psk.get('nearest_km')
        if heard > 5:
            parts.append(f"Strong PSKReporter activity — {heard} stations heard {target} in the last hour.")
        elif heard > 0:
            near_str = f", nearest {nearest:.0f}km from you" if nearest else ""
            parts.append(f"PSKReporter shows {heard} station{'s' if heard>1 else ''} hearing {target} recently{near_str}.")
        elif not _is_local_psk:
            parts.append(f"No PSKReporter reports for {target} in the last hour — band may not be open yet.")

        # ── Band/MUF ─────────────────────────────────────────────────────────
        muf = factors.get('band_muf', 0)
        _bdet3 = details.get('band_muf', {})
        _is_local3 = _bdet3.get('path_type', 'skywave') != 'skywave'
        if not _is_local3 and muf < 0.4:
            parts.append(f"{band} may be above the MUF for this path — try a lower band.")
        elif muf >= 1.0:
            pass  # Don't belabor the obvious

        # ── Bulletin ─────────────────────────────────────────────────────────
        bul = details.get('bulletin', {})
        if bul.get('found'):
            parts.append(f"{target} is mentioned in the current ARRL DX bulletin — confirmed active.")

        # ── Antenna ───────────────────────────────────────────────────────────

        # ── DXSummit activity window ─────────────────────────────────────────────
        dx_act = result.get('dx_activity', {})
        if dx_act.get('ok') and dx_act.get('spot_count', 0) > 0:
            sc = dx_act['spot_count']
            bands_str = '/'.join(dx_act.get('top_bands', [])[:2])
            win = dx_act.get('window_str', '')
            peak = dx_act.get('peak_hour')
            if win and bands_str:
                parts.append(f"{target} is generally active on {bands_str} from {win}" +
                             (f", peak activity around {peak:02d}00z" if peak is not None else "") +
                             f" (based on {sc} recent spots).")

        # ── Rotor suggestion ─────────────────────────────────────────────────────
        ant_det = details.get('antenna', {})
        rotor_az = ant_det.get('beam_heading')
        if rotor_az is not None and bearing is not None:
            diff = abs(((bearing - rotor_az) + 180) % 360 - 180)
            if diff > 5:
                lp_diff = abs(((lp - rotor_az) + 180) % 360 - 180) if lp else 999
                dist_km = result.get('distance_km', 0) or 0
                lp_practical = dist_km > 8000  # LP only useful beyond ~8000km
                if lp and lp_practical and lp_diff < diff:
                    parts.append(f"Rotor is at {rotor_az:.0f}° — rotate to {lp:.0f}° for long path to {target}.")
                else:
                    parts.append(f"Rotor is at {rotor_az:.0f}° — rotate to {bearing:.0f}° for short path to {target}.")

        # ── LoTW status ───────────────────────────────────────────────────────────
        # (passed in via result dict if available)
        lotw = result.get('lotw', {})
        if lotw.get('active'):
            parts.append(f"{target} uses LoTW (last upload {lotw.get('last_upload','')}).")
        elif lotw.get('active') is False:
            parts.append(f"{target} not found in LoTW user list.")

        # ── Closing suggestion ────────────────────────────────────────────────
        _bdet_close = details.get('band_muf', {})
        _is_local_close = _bdet_close.get('path_type', 'skywave') in ('local', 'groundwave', 'near_skip', 'los')
        if not _is_local_close and score < 40 and factors.get('grayline', 0) < 0.4:
            import datetime
            utc_h = datetime.datetime.utcnow().hour
            # Sunrise Ohio ~1100 UTC, sunset ~2300 UTC
            if utc_h < 9:
                wait_h = 11 - utc_h
                parts.append(f"Conditions may improve near your sunrise in about {wait_h} hour{'s' if wait_h!=1 else ''}.")
            elif utc_h > 14 and utc_h < 21:
                wait_h = 23 - utc_h
                parts.append(f"Try again near your sunset in about {wait_h} hour{'s' if wait_h!=1 else ''}.")

        return " ".join(parts)

    def score(self, target_call, target_grid=None, band='20m',
              cluster_spots=0, beam_heading=None):
        """
        Compute workability index.

        Args:
            target_call: DX callsign e.g. "3B9G"
            target_grid: Maidenhead grid of target (if known)
            band: band string e.g. "20m"
            cluster_spots: number of recent cluster spots for this entity
            beam_heading: override antenna heading for this calculation

        Returns dict with:
            score: 0-100 integer
            grade: "Excellent"/"Good"/"Fair"/"Poor"/"Unlikely"
            factors: dict of factor scores and details
            bearing: short path bearing
            distance_km: path distance
            long_path_bearing: long path bearing
            summary: one-line text summary
        """
        factors = {}
        details = {}

        # ── Path geometry ─────────────────────────────────────────────────────
        t_lat, t_lon = grid_to_latlon(target_grid) if target_grid else (None, None)
        if t_lat is None or self.my_lat is None:
            bearing, dist_km, lp_bearing = None, None, None
        else:
            bearing, dist_km, lp_bearing = bearing_distance(
                self.my_lat, self.my_lon, t_lat, t_lon
            )
            # Same grid or effectively zero distance
            if dist_km is not None and dist_km < 1:
                dist_km = 0

        # ── Solar ─────────────────────────────────────────────────────────────
        solar = self._get_solar()
        sfi = solar.get('sfi')
        k_index = solar.get('k_index')
        factors['solar'] = solar_score(sfi, k_index)
        details['solar'] = {
            'sfi': sfi, 'k_index': k_index,
            'label': f"SFI={sfi:.0f} K={k_index:.1f}" if (sfi is not None and k_index is not None) else (f"SFI={sfi:.0f}" if sfi is not None else "No solar data")
        }

        # ── Band MUF / Local path ────────────────────────────────────────────
        _ptype = path_type(dist_km if dist_km is not None else 5000, band)
        _local_score, _local_type, _local_warn = local_path_score(band, dist_km if dist_km is not None else 5000)
        if _local_score is not None:
            factors['band_muf'] = _local_score
            details['band_muf'] = {
                'band': band,
                'dist_km': dist_km,
                'path_type': _local_type,
                'warning': _local_warn,
                'label': f"{band} {_local_type} {dist_km:.0f}km" if dist_km else band
            }
        else:
            factors['band_muf'] = band_muf_score(band, sfi, dist_km or 5000)
            details['band_muf'] = {
                'band': band,
                'dist_km': dist_km,
                'path_type': 'skywave',
                'warning': '',
                'label': f"{band} path {dist_km:.0f}km" if dist_km else band
            }

        # ── Grayline ──────────────────────────────────────────────────────────
        gl_my = grayline_score(self.my_lat, self.my_lon) if self.my_lat else 0.3
        gl_dx = grayline_score(t_lat, t_lon) if t_lat else 0.3
        factors['grayline'] = (gl_my + gl_dx) / 2
        details['grayline'] = {
            'my_score': round(gl_my, 2),
            'dx_score': round(gl_dx, 2),
            'label': f"My end {'✓' if gl_my > 0.5 else '✗'} DX end {'✓' if gl_dx > 0.5 else '✗'}"
        }

        # ── PSKReporter ───────────────────────────────────────────────────────
        psk = fetch_pskreporter(target_call, self.my_grid,
                                BAND_MHZ.get(band))
        factors['pskreporter'] = pskreporter_score(
            psk.get('heard_count', 0),
            psk.get('nearest_km'),
            dist_km
        )
        details['pskreporter'] = {
            'heard_count': psk.get('heard_count', 0),
            'nearest_km': psk.get('nearest_km'),
            'label': f"{psk.get('heard_count',0)} reporters heard {target_call} in last hour"
        }

        # ── ARRL Bulletin ─────────────────────────────────────────────────────
        bulletin = self._get_bulletin()
        in_bulletin, snippet = check_bulletin_for_call(
            bulletin.get('text', ''), target_call
        )
        factors['bulletin'] = 0.8 if in_bulletin else 0.2
        details['bulletin'] = {
            'found': in_bulletin,
            'snippet': snippet[:300] if snippet else '',
            'date': bulletin.get('date', ''),
            'label': f"In ARRL bulletin {'✓' if in_bulletin else '—'}"
        }

        # ── Antenna ───────────────────────────────────────────────────────────
        bh = beam_heading if beam_heading is not None else self.beam_heading
        factors['antenna'] = antenna_score(bh, bearing) if bearing else 0.5
        details['antenna'] = {
            'beam_heading': bh,
            'target_bearing': bearing,
            'long_path': lp_bearing,
            'label': f"Beam {bh}° → Target {bearing}°" if (bh and bearing) else
                     f"Target bearing {bearing}°" if bearing else "Unknown"
        }

        # ── DXSummit activity ────────────────────────────────────────────────────
        dx_activity = fetch_dxsummit_activity(target_call)

        # ── Cluster ───────────────────────────────────────────────────────────
        factors['cluster'] = min(1.0, cluster_spots * 0.15 + (0.1 if cluster_spots > 0 else 0))
        details['cluster'] = {
            'spots': cluster_spots,
            'label': f"{cluster_spots} recent cluster spots"
        }

        # ── Composite score ───────────────────────────────────────────────────
        _bdet_final = details.get('band_muf', {})
        _final_ptype = _bdet_final.get('path_type', 'skywave')
        if _final_ptype == 'local':
            score_100 = 100
        elif _final_ptype in ('los', 'groundwave', 'near_skip'):
            # Local path — propagation factors irrelevant, use neutral 0.5
            factors_local = dict(factors)
            factors_local['grayline']    = 0.5
            factors_local['solar']       = 0.5
            factors_local['bulletin']    = 0.5
            factors_local['pskreporter'] = 0.5
            factors_local['cluster']     = 0.5
            composite = sum(
                factors_local[k] * self.WEIGHTS[k]
                for k in self.WEIGHTS
                if k in factors_local
            )
            score_100 = round(composite * 100)
        else:
            composite = sum(
                factors[k] * self.WEIGHTS[k]
                for k in self.WEIGHTS
                if k in factors
            )
            score_100 = round(composite * 100)

        if score_100 >= 75:   grade = "Excellent"
        elif score_100 >= 55: grade = "Good"
        elif score_100 >= 35: grade = "Fair"
        elif score_100 >= 20: grade = "Poor"
        else:                  grade = "Unlikely"

        # ── Summary ───────────────────────────────────────────────────────────
        parts = []
        if bearing:
            parts.append(f"SP bearing {bearing}°")
        if lp_bearing:
            parts.append(f"LP {lp_bearing}°")
        if dist_km:
            parts.append(f"{dist_km:.0f}km")
        if sfi:
            parts.append(f"SFI={sfi:.0f}")
        if k_index is not None:
            parts.append(f"K={k_index:.1f}")
        summary = f"{grade} ({score_100}%) — " + ", ".join(parts)

        narrative_text = self.narrative({
            'score': score_100, 'grade': grade, 'factors': factors,
            'details': details, 'band': band, 'distance_km': dist_km,
            'bearing': bearing, 'long_path_bearing': lp_bearing,
            'solar': {'sfi': sfi, 'k_index': k_index},
            'target_call': target_call,
            'dx_activity': dx_activity,
        })

        return {
            'score': score_100,
            'narrative': narrative_text,
            'grade': grade,
            'summary': summary,
            'bearing': bearing,
            'long_path_bearing': lp_bearing,
            'distance_km': dist_km,
            'factors': factors,
            'details': details,
            'solar': {'sfi': sfi, 'k_index': k_index},
            'target_call': target_call,
            'band': band,
            'dx_activity': dx_activity,
            'timestamp': datetime.now(timezone.utc).isoformat()
        }


# ── Quick test ────────────────────────────────────────────────────────────────
if __name__ == '__main__':
    import sys
    call = sys.argv[1] if len(sys.argv) > 1 else '3B9G'
    grid = sys.argv[2] if len(sys.argv) > 2 else 'MH10'
    band = sys.argv[3] if len(sys.argv) > 3 else '20m'

    print(f"ELMER Workability Index for {call} on {band}")
    print("Fetching data...")
    eng = WorkabilityEngine(my_call='W6HN', my_grid='EN90bt', beam_heading=None)
    result = eng.score(call, target_grid=grid, band=band, cluster_spots=3)

    print(f"\n{'='*50}")
    print(f"SCORE: {result['score']}/100 — {result['grade']}")
    print(f"Summary: {result['summary']}")
    print(f"\nFactor breakdown:")
    for k, v in result['factors'].items():
        d = result['details'].get(k, {})
        bar = '█' * int(v*10) + '░' * (10-int(v*10))
        print(f"  {k:15s} {bar} {v:.2f}  {d.get('label','')}")
    print(f"\nNARRATIVE:\n{result.get('narrative','')}")
    print(f"\nPSKReporter: {result['details']['pskreporter']['label']}")
    print(f"Bulletin:    {result['details']['bulletin']['label']}")
    if result['details']['bulletin'].get('snippet'):
        print(f"  → {result['details']['bulletin']['snippet']}")
