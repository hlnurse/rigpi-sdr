#!/usr/bin/env python3
"""Refresh RigPi's validated local EiBi shortwave schedule cache."""

import argparse
import csv
import datetime as dt
import hashlib
import html
import json
import os
from pathlib import Path
import re
import ssl
import tempfile
import urllib.error
import urllib.request


SITE_URL = "https://www.eibispace.de/"
HTTP_SITE_URL = "http://www.eibispace.de/"
MAX_DOWNLOAD = 16 * 1024 * 1024
FIELDS = (
    "frequency_hz", "time_utc", "days", "country_code", "station",
    "language_code", "language_name", "target_code", "target_name",
    "transmitter", "persistence", "start_date", "stop_date",
)
CSV_LINK_RE = re.compile(r'href=["\'](dx/sked-[ab]\d\d\.csv)["\']', re.I)
README_LINK = "dx/README.TXT"


COMMON_LANGUAGES = {
    "A": "Arabic", "C": "Chinese", "E": "English", "F": "French",
    "G": "German", "H": "Hungarian", "I": "Italian", "J": "Japanese",
    "K": "Korean", "P": "Portuguese", "R": "Russian", "S": "Spanish",
    "SW": "Swahili", "T": "Turkish", "V": "Vietnamese", "-CW": "Morse",
    "-MX": "Music", "-TS": "Time signal", "-TY": "Digital",
}


def _download(url, allow_insecure_http=False):
    request = urllib.request.Request(url, headers={
        "User-Agent": "RigPi-Elmer/0.19 (https://rigpi.net)",
        "Accept": "text/html,text/plain,text/csv,*/*",
    })
    try:
        with urllib.request.urlopen(request, timeout=90) as response:
            data = response.read(MAX_DOWNLOAD + 1)
    except (urllib.error.URLError, ssl.SSLError):
        if not allow_insecure_http or not url.startswith("https://www.eibispace.de/"):
            raise
        fallback = "http://www.eibispace.de/" + url.split("https://www.eibispace.de/", 1)[1]
        request.full_url = fallback
        with urllib.request.urlopen(request, timeout=90) as response:
            data = response.read(MAX_DOWNLOAD + 1)
    if len(data) > MAX_DOWNLOAD:
        raise ValueError("EiBi response exceeded the safety limit.")
    return data


def discover_current_files(page_bytes):
    page = page_bytes.decode("latin-1", "replace")
    match = CSV_LINK_RE.search(page)
    if not match:
        raise ValueError("EiBi page did not identify a current schedule CSV.")
    relative = html.unescape(match.group(1))
    season = Path(relative).stem.removeprefix("sked-").upper()
    return relative, season


def parse_language_codes(readme_text):
    codes = dict(COMMON_LANGUAGES)
    marker = "I) Language codes."
    start = readme_text.rfind(marker)
    end = readme_text.find("II) Country codes.", start + len(marker))
    if start < 0 or end < 0:
        return codes
    for line in readme_text[start:end].splitlines():
        match = re.match(r"^\s{3}(-?[A-Za-z]{1,3})\s{2,}(.+?)\s*$", line)
        if not match:
            continue
        code, description = match.groups()
        description = re.sub(r"\s*\[[a-z]{3}\]\s*$", "", description).strip()
        description = description.split(":", 1)[0].strip()
        if description:
            codes[code] = description[:80]
    return codes


def normalize_records(csv_bytes, language_codes):
    text = csv_bytes.decode("latin-1", "replace")
    reader = csv.reader(text.splitlines(), delimiter=";")
    next(reader, None)
    records = []
    stats = {"downloaded": 0, "utility": 0, "inactive": 0, "outside_hf": 0, "invalid": 0}
    for row in reader:
        if not row or not any(cell.strip() for cell in row):
            continue
        stats["downloaded"] += 1
        if len(row) != 11:
            stats["invalid"] += 1
            continue
        frequency, times, days, country, station, language, target, transmitter, persistence, start, stop = (
            cell.strip() for cell in row
        )
        try:
            frequency_hz = round(float(frequency) * 1000)
            persistence_number = int(persistence or "0")
        except ValueError:
            stats["invalid"] += 1
            continue
        if persistence_number >= 90:
            stats["utility"] += 1
            continue
        if persistence_number == 8:
            stats["inactive"] += 1
            continue
        if not 2_300_000 <= frequency_hz <= 30_000_000:
            stats["outside_hf"] += 1
            continue
        if not re.fullmatch(r"\d{4}-\d{4}", times) or not station:
            stats["invalid"] += 1
            continue
        language_name = ", ".join(
            language_codes.get(part.strip(), part.strip())
            for part in language.split(",") if part.strip()
        )
        records.append({
            "frequency_hz": str(frequency_hz),
            "time_utc": times,
            "days": days[:24],
            "country_code": country[:4],
            "station": " ".join(station.split())[:160],
            "language_code": language[:8],
            "language_name": language_name[:80],
            "target_code": target[:8],
            "target_name": target[:80],
            "transmitter": transmitter[:16],
            "persistence": str(persistence_number),
            "start_date": start[:16],
            "stop_date": stop[:16],
        })
    records.sort(key=lambda row: (int(row["frequency_hz"]), row["time_utc"], row["station"]))
    stats["cached"] = len(records)
    return records, stats


def atomic_write_csv(path, records):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=FIELDS, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(records)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, 0o644)
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def atomic_write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, 0o644)
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", help="Read an already-downloaded EiBi CSV")
    parser.add_argument("--readme", help="Read an already-downloaded EiBi README")
    parser.add_argument("--season", default="", help="Season label for local input, such as A26")
    parser.add_argument("--output", default="/home/pi/Elmer/cache/eibi/schedules.csv")
    parser.add_argument("--metadata", default="/home/pi/Elmer/cache/eibi/metadata.json")
    args = parser.parse_args()

    insecure_transport = False
    if args.source:
        csv_bytes = Path(args.source).read_bytes()
        readme_bytes = Path(args.readme).read_bytes() if args.readme else b""
        season = args.season.upper() or "LOCAL"
        source_url = str(Path(args.source))
    else:
        try:
            page_bytes = _download(SITE_URL)
        except Exception:
            page_bytes = _download(HTTP_SITE_URL)
            insecure_transport = True
        relative, season = discover_current_files(page_bytes)
        source_url = SITE_URL + relative
        try:
            csv_bytes = _download(source_url)
            readme_bytes = _download(SITE_URL + README_LINK)
        except Exception:
            csv_bytes = _download(HTTP_SITE_URL + relative)
            readme_bytes = _download(HTTP_SITE_URL + README_LINK)
            insecure_transport = True

    readme_text = readme_bytes.decode("latin-1", "replace") if readme_bytes else ""
    records, stats = normalize_records(csv_bytes, parse_language_codes(readme_text))
    if len(records) < 5_000:
        raise SystemExit("Refusing to replace the EiBi cache with fewer than 5,000 usable HF broadcasts.")
    metadata = {
        "source": "EiBi", "source_url": source_url, "season": season,
        "downloaded_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "sha256": hashlib.sha256(csv_bytes).hexdigest(),
        "transport_warning": "Official HTTPS certificate unavailable; downloaded over HTTP" if insecure_transport else "",
        **stats,
    }
    atomic_write_csv(Path(args.output), records)
    atomic_write_json(Path(args.metadata), metadata)
    print(f"EiBi {season}: {stats['cached']} HF broadcast schedules cached")


if __name__ == "__main__":
    main()
