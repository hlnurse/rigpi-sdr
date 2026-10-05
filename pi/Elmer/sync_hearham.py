#!/usr/bin/env python3
"""Refresh RigPi Elmer's compact HearHam repeater cache."""

import argparse
import csv
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile
import urllib.request


SOURCE_URL = 'https://hearham.com/api/repeaters/v1'
CALL_RE = re.compile(r'^(?=.*[A-Z])(?=.*\d)[A-Z0-9]{2,12}(?:[-/][A-Z0-9]{1,8})*$')
FIELDS = (
    'id', 'callsign', 'latitude', 'longitude', 'city', 'group', 'internet_node',
    'mode', 'encode', 'decode', 'frequency_hz', 'offset_hz', 'power', 'restriction',
)


def normalized_mode(value):
    text = ' '.join(str(value or '').strip().upper().split())
    aliases = {
        'DSTAR': 'D-STAR', 'D-STAR': 'D-STAR',
        'FUSION': 'YSF', 'SYSTEM FUSION': 'YSF',
        'ANALOG': 'FM', 'FM (ANALOG)': 'FM',
    }
    return aliases.get(text, text)[:24]


def finite_coordinate(value, low, high):
    try:
        number = float(value)
    except (TypeError, ValueError, OverflowError):
        return None
    if not low <= number <= high:
        return None
    return number


def exact_int(value):
    if isinstance(value, bool):
        return 0
    try:
        return int(float(value))
    except (TypeError, ValueError, OverflowError):
        return 0


def normalize_records(payload):
    if not isinstance(payload, list):
        raise ValueError('HearHam returned an invalid repeater list.')
    records = []
    seen = set()
    stats = {'downloaded': len(payload), 'inactive': 0, 'invalid': 0, 'duplicates': 0}
    for raw in payload:
        if not isinstance(raw, dict) or exact_int(raw.get('operational')) != 1:
            stats['inactive'] += 1
            continue
        call = str(raw.get('callsign') or '').strip().upper()[:24]
        lat = finite_coordinate(raw.get('latitude'), -90, 90)
        lon = finite_coordinate(raw.get('longitude'), -180, 180)
        frequency = exact_int(raw.get('frequency'))
        mode = normalized_mode(raw.get('mode'))
        if (not CALL_RE.fullmatch(call) or lat is None or lon is None or
                (lat == 0 and lon == 0) or frequency < 1000000):
            stats['invalid'] += 1
            continue
        offset = exact_int(raw.get('offset'))
        key = (call, frequency, offset, round(lat, 6), round(lon, 6), mode)
        if key in seen:
            stats['duplicates'] += 1
            continue
        seen.add(key)
        records.append({
            'id': str(raw.get('id') or '')[:40],
            'callsign': call,
            'latitude': f'{lat:.7f}',
            'longitude': f'{lon:.7f}',
            'city': ' '.join(str(raw.get('city') or '').strip().split())[:140],
            'group': ' '.join(str(raw.get('group') or '').strip().split())[:80],
            'internet_node': str(raw.get('internet_node') or '').strip()[:40],
            'mode': mode,
            'encode': str(raw.get('encode') or '').strip()[:32],
            'decode': str(raw.get('decode') or '').strip()[:32],
            'frequency_hz': str(frequency),
            'offset_hz': str(offset),
            'power': str(raw.get('power') or '').strip()[:32],
            'restriction': ' '.join(str(raw.get('restriction') or '').strip().split())[:160],
        })
    records.sort(key=lambda row: (row['callsign'], int(row['frequency_hz']), row['mode']))
    stats['cached'] = len(records)
    return records, stats


def read_source(source):
    if source:
        raw = Path(source).read_bytes()
    else:
        request = urllib.request.Request(SOURCE_URL, headers={
            'User-Agent': 'RigPi-Elmer/0.19 (https://rigpi.net)',
            'Accept': 'application/json',
        })
        with urllib.request.urlopen(request, timeout=90) as response:
            raw = response.read(32 * 1024 * 1024 + 1)
        if len(raw) > 32 * 1024 * 1024:
            raise ValueError('HearHam response exceeded the safety limit.')
    return raw, json.loads(raw.decode('utf-8'))


def atomic_write_csv(path, records):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=path.name + '.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='') as handle:
            writer = csv.DictWriter(handle, fieldnames=FIELDS, extrasaction='ignore')
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
    fd, temp_name = tempfile.mkstemp(prefix=path.name + '.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(value, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, 0o644)
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', help='Read an already downloaded HearHam JSON file')
    parser.add_argument('--output', default='/home/pi/Elmer/cache/hearham/repeaters.csv')
    parser.add_argument('--metadata', default='/home/pi/Elmer/cache/hearham/metadata.json')
    args = parser.parse_args()
    raw, payload = read_source(args.source)
    records, stats = normalize_records(payload)
    if len(records) < 1000:
        raise SystemExit('Refusing to replace the HearHam cache with fewer than 1,000 usable records.')
    downloaded_at = dt.datetime.now(dt.timezone.utc).isoformat()
    metadata = {
        'source': 'HearHam', 'source_url': SOURCE_URL, 'downloaded_at': downloaded_at,
        'individual_verification_dates_available': False,
        'sha256': hashlib.sha256(raw).hexdigest(), **stats,
    }
    atomic_write_csv(Path(args.output), records)
    atomic_write_json(Path(args.metadata), metadata)
    print(f"HearHam cache: {stats['cached']} operational repeaters ({stats['duplicates']} duplicates removed)")


if __name__ == '__main__':
    main()
