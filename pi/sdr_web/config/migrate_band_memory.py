#!/usr/bin/env python3
"""
Run once on the Pi to clean up band_memory in admin.json:
  python3 migrate_band_memory.py

Handles all legacy formats and rewrites the file in place.
"""
import json, os, sys, shutil

SETTINGS_PATH = "/home/pi/sdr_web/config/users/admin/admin.json"  # adjust if needed

BAND_FREQ_RANGES = {
    "160": (1800000,    2000000),
    "80":  (3500000,    4000000),
    "60":  (5330000,    5410000),
    "40":  (7000000,    7300000),
    "30":  (10100000,   10150000),
    "20":  (14000000,   14350000),
    "17":  (18068000,   18168000),
    "15":  (21000000,   21450000),
    "12":  (24890000,   24990000),
    "10":  (28000000,   29700000),
    "6":   (50000000,   54000000),
    "70":  (70000000,   71000000),
    "2":   (144000000,  148000000),
    "1.25":(222000000,  225000000),
    "70cm":(420000000,  450000000),
    "23":  (1240000000, 1300000000),
}

OLD_TO_NEW = {
    "160m":"160","80m":"80","60m":"60","40m":"40","30m":"30",
    "20m":"20","17m":"17","15m":"15","12m":"12","10m":"10",
    "6m":"6","2m":"2","70cm":"70cm","23cm":"23",
}

def slot_to_list(s):
    if s is None:
        return None
    if isinstance(s, (list, tuple)) and len(s) >= 3:
        return [int(s[0]), str(s[1]), int(s[2])]
    if isinstance(s, dict) and "freq" in s:
        return [int(s["freq"]), str(s.get("mode","USB")), int(s.get("bw", 2700))]
    return None

def sanitize(bm):
    # Rename old-style keys
    for old_key, new_key in OLD_TO_NEW.items():
        if old_key in bm and new_key not in bm:
            bm[new_key] = bm.pop(old_key)
            print(f"  renamed {old_key} -> {new_key}")

    to_delete = []
    for band, raw in list(bm.items()):
        if band == "other":
            to_delete.append(band)
            continue

        freq_range = BAND_FREQ_RANGES.get(band)

        if isinstance(raw, list):
            slots_raw = raw
        elif isinstance(raw, dict):
            extracted = {}
            base = {}
            for k, v in raw.items():
                if k.isdigit():
                    extracted[int(k)] = v
                else:
                    base[k] = v
            if extracted:
                slots_raw = [extracted.get(i) for i in range(3)]
            elif "freq" in base:
                slots_raw = [base, None, None]
            else:
                slots_raw = [None, None, None]
        else:
            to_delete.append(band)
            continue

        slots_raw = (list(slots_raw) + [None, None, None])[:3]
        clean = []
        for s in slots_raw:
            entry = slot_to_list(s)
            if entry is None:
                clean.append(None)
                continue
            f = entry[0]
            if freq_range and not (freq_range[0] <= f < freq_range[1]):
                print(f"  {band}: nulling slot with freq {f} (out of range {freq_range})")
                clean.append(None)
            else:
                clean.append(entry)
        bm[band] = clean
        print(f"  {band}: {clean}")

    for k in to_delete:
        del bm[k]
        print(f"  deleted band '{k}'")

    return bm

# ── main ──────────────────────────────────────────────────────────────────────
if not os.path.exists(SETTINGS_PATH):
    print(f"ERROR: file not found: {SETTINGS_PATH}")
    sys.exit(1)

# Backup
backup = SETTINGS_PATH + ".bak"
shutil.copy2(SETTINGS_PATH, backup)
print(f"Backed up to {backup}")

with open(SETTINGS_PATH) as f:
    data = json.load(f)

bm = data.get("band_memory", {})
print(f"\nBefore: {list(bm.keys())}")
print("\nSanitizing...")
data["band_memory"] = sanitize(bm)
print(f"\nAfter: {list(data['band_memory'].keys())}")

# Uncomment to wipe ALL slots to None (start completely fresh):
# for band in data["band_memory"]:
#     data["band_memory"][band] = [None, None, None]
# print("All slots wiped to None")

with open(SETTINGS_PATH, "w") as f:
    json.dump(data, f, indent=2)

print(f"\nWritten to {SETTINGS_PATH}")
print("Done. Restart sdr_web_server.py")
