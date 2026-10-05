#!/usr/bin/env python3
"""
build_owrx_list.py - Fetch all ReceiverBook pages and extract OWRX servers with band data.
Run on Pi: python3 /home/pi/sdr_web/build_owrx_list.py
"""
import json, re, time, urllib.request, sys

OUTPUT = "/home/pi/sdr_web/openwebrx_servers.json"
BASE_URL = "https://www.receiverbook.de/"
HEADERS = {"User-Agent": "ELMER-SDR/1.0 (W6HN ham radio tool)"}
RECEIVERBOOK_JSON = "/home/pi/sdr_web/receiverbook.json"

# HF bands in priority order (lowest freq = most useful for ham HF)
HF_PRIORITY = ['2190m','630m','160m','80m','60m','40m','30m','20m','17m',
                '15m','12m','10m','6m','4m','2m','70cm','23cm','13cm','3cm','ADSB']

def fetch(url):
    req = urllib.request.Request(url, headers=HEADERS)
    return urllib.request.urlopen(req, timeout=15).read().decode('utf-8', errors='replace')

def parse_page(html):
    """Extract {url: [bands]} from one page — bands from title attr of bandtag span."""
    url_bands = {}
    blocks = re.split(r'(?=<div><a href="http)', html)
    for block in blocks:
        m = re.match(r'<div><a href="(https?://[^"]+)"', block)
        if not m:
            continue
        url = m.group(1).rstrip('/')
        title_m = re.search(r'class="[^"]*bandtag[^"]*"[^>]*title="(.*?)"(?:\s*>|\s*/>)',
                            block, re.DOTALL)
        if title_m:
            bands = re.findall(r'<li>\s*(\S+)\s*</li>', title_m.group(1))
            if bands:
                url_bands[url] = bands
    return url_bands

def get_page_count(html):
    pages = re.findall(r'href="[^"]*[?&]page=(\d+)"', html)
    return max((int(p) for p in pages), default=1) if pages else 1

def primary_band(bands):
    """Pick best primary band — prefer HF bands, use priority order."""
    # First try to find an HF band (10m-160m range)
    hf = [b for b in bands if re.match(r'^\d+m$', b) and int(b[:-1]) >= 10]
    if hf:
        # Return the one lowest in HF_PRIORITY list
        for p in HF_PRIORITY:
            if p in bands:
                return p
    # Fall back to first in priority list
    for p in HF_PRIORITY:
        if p in bands:
            return p
    return bands[0] if bands else None

LABEL_BANDS = [
    (r'\b160\s*m\b','160m'),(r'\b80\s*m\b','80m'),(r'\b60\s*m\b','60m'),
    (r'\b40\s*m\b','40m'),(r'\b30\s*m\b','30m'),(r'\b20\s*m\b','20m'),
    (r'\b17\s*m\b','17m'),(r'\b15\s*m\b','15m'),(r'\b12\s*m\b','12m'),
    (r'\b10\s*m\b','10m'),(r'\b6\s*m\b','6m'),(r'\b2\s*m\b','2m'),
    (r'\b70\s*cm\b','70cm'),(r'\badsb\b','ADSB'),
    (r'\bhf\b','HF'),(r'\bvhf\b','VHF'),(r'\buhf\b','UHF'),
]

def label_to_band(label):
    label_l = label.lower()
    for pat, band in LABEL_BANDS:
        if re.search(pat, label_l, re.IGNORECASE):
            return band
    return None

# Step 1: Scrape all pages for band data
print("Fetching page 1...")
html1 = fetch(BASE_URL)
n_pages = get_page_count(html1)
print(f"Found {n_pages} pages")

url_bands = parse_page(html1)
print(f"  Page 1: {len(url_bands)} receivers with bands")

for page in range(2, n_pages + 1):
    try:
        print(f"  Page {page}/{n_pages}...", end='\r', flush=True)
        html = fetch(f"{BASE_URL}?page={page}")
        url_bands.update(parse_page(html))
        time.sleep(0.3)
    except Exception as e:
        print(f"\n  Page {page} failed: {e}")

print(f"\nTotal receivers with band data from HTML: {len(url_bands)}")

# Step 2: Build entries from receiverbook.json
with open(RECEIVERBOOK_JSON) as f:
    rb = json.load(f)

entries = []
for site in rb:
    loc = site.get('location', {})
    coords = loc.get('coordinates', [0,0])
    lon, lat = coords[0], coords[1]
    for r in site.get('receivers', []):
        if r.get('type') not in ('OpenWebRX', 'OpenWebRX+'):
            continue
        url = r.get('url','').rstrip('/')
        label = re.sub(r'<[^>]+>','', r.get('label','')).strip()
        bands = url_bands.get(url) or url_bands.get(url+'/')
        band = primary_band(bands) if bands else label_to_band(label)
        entry = {'name': label[:80], 'url': r.get('url',''), 'type':'owrx', 'lat':lat, 'lon':lon}
        if bands:
            entry['bands'] = bands
            entry['band'] = band
        elif band:
            entry['band'] = band
        entries.append(entry)

with_bands = sum(1 for e in entries if 'band' in e)
with_list  = sum(1 for e in entries if 'bands' in e)
print(f"Results: {len(entries)} total, {with_bands} with band ({with_list} with full list)")

bc = {}
for e in entries:
    b = e.get('band','none'); bc[b] = bc.get(b,0)+1
for b,c in sorted(bc.items(), key=lambda x:-x[1])[:15]:
    print(f"  {b}: {c}")

with open(OUTPUT,'w') as f:
    json.dump(entries, f, indent=2)
print(f"Saved to {OUTPUT}")
