#!/usr/bin/env python3
"""Import a locally licensed ILGRadio dBASE file for RigPi shortwave scanning."""

import argparse
import csv
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import struct
import tempfile


FIELDS = (
    "frequency_hz", "time_utc", "days", "country_code", "station",
    "language_code", "language_name", "target_code", "target_name",
    "transmitter", "persistence", "start_date", "stop_date", "source",
    "status", "monitored", "mode", "modulation_type", "location",
    "power_kw", "azimuth", "antenna", "remarks", "callsign",
    "center_frequency_hz", "longitude", "latitude",
)

REQUIRED_FIELDS = {
    "FREQ", "STATION", "UTC", "DAYS", "LANGUAGE", "TARGET", "LOCATION",
    "POWER", "AZI", "ANTENNA", "REMARKS", "MOD", "MODTYP", "ADM",
    "COUNTRY", "LONGI", "LATI", "STATUS", "YEAR", "CALL", "FDATE",
    "TDATE", "CFFREQ",
}


def read_dbf(path):
    """Yield decoded records from the dBASE III/FoxBase file."""
    path = Path(path)
    with path.open("rb") as handle:
        header = handle.read(32)
        if len(header) != 32 or header[0] not in {0x03, 0x83, 0x8B, 0xF5}:
            raise ValueError("The source is not a supported dBASE III/FoxBase file.")
        record_count = struct.unpack("<I", header[4:8])[0]
        header_size = struct.unpack("<H", header[8:10])[0]
        record_size = struct.unpack("<H", header[10:12])[0]
        descriptors = []
        offset = 1
        while True:
            descriptor = handle.read(32)
            if not descriptor:
                raise ValueError("The dBASE field list is incomplete.")
            if descriptor[0] == 0x0D:
                break
            name = descriptor[:11].split(b"\0", 1)[0].decode("ascii", "strict")
            length = descriptor[16]
            descriptors.append((name, offset, length))
            offset += length
        names = {item[0] for item in descriptors}
        missing = REQUIRED_FIELDS - names
        if missing:
            raise ValueError("The ILGRadio source is missing fields: " + ", ".join(sorted(missing)))
        if offset != record_size:
            raise ValueError("The dBASE record layout is inconsistent.")
        handle.seek(header_size)
        for _ in range(record_count):
            raw = handle.read(record_size)
            if len(raw) != record_size:
                raise ValueError("The ILGRadio source ended before its declared record count.")
            if raw[:1] == b"*":
                continue
            yield {
                name: raw[start:start + length].decode("cp850", "replace").strip()
                for name, start, length in descriptors
            }


def _date(value):
    value = str(value or "").strip()
    return value if re.fullmatch(r"\d{6}", value) else ""


def _time(value):
    value = str(value or "").strip().replace(":", "-").replace("=", "-")
    return value if re.fullmatch(r"\d{4}-\d{4}", value) else ""


def _days(value):
    text = str(value or "")[:7]
    names = ("Su", "Mo", "Tu", "We", "Th", "Fr", "Sa")
    return "".join(names[index - 1] for index, char in enumerate(text, 1) if char == str(index))


def normalize_records(source):
    records = []
    stats = {
        "source_records": 0, "separators": 0, "utility": 0, "inactive": 0,
        "outside_hf": 0, "invalid": 0, "confirmed": 0, "unconfirmed": 0,
    }
    for row in read_dbf(source):
        stats["source_records"] += 1
        try:
            frequency_hz = round(float(row["FREQ"]) * 1000)
        except ValueError:
            stats["separators"] += 1
            continue
        if not 2_300_000 <= frequency_hz <= 30_000_000:
            stats["outside_hf"] += 1
            continue
        language = " ".join(row["LANGUAGE"].split())
        if language.upper().startswith("DATA:"):
            stats["utility"] += 1
            continue
        status = row["STATUS"].upper()
        if status not in {"C", "N"}:
            stats["inactive"] += 1
            continue
        times = _time(row["UTC"])
        station = " ".join(row["STATION"].split())
        if not times or not station:
            stats["invalid"] += 1
            continue
        try:
            center_frequency_hz = round(float(row["CFFREQ"]) * 1000) if row["CFFREQ"] else 0
        except ValueError:
            center_frequency_hz = 0
        try:
            power = str(float(row["POWER"])) if row["POWER"] else ""
        except ValueError:
            power = ""
        mode = row["MOD"].upper()
        records.append({
            "frequency_hz": str(frequency_hz),
            "time_utc": times,
            "days": _days(row["DAYS"]),
            "country_code": row["ADM"][:4],
            "station": station[:160],
            "language_code": "",
            "language_name": language[:80],
            "target_code": row["TARGET"][:16],
            "target_name": row["TARGET"][:80],
            "transmitter": row["LOCATION"][:80],
            "persistence": "0" if status == "C" else "1",
            "start_date": _date(row["FDATE"]),
            "stop_date": _date(row["TDATE"]),
            "source": "ILGRadio",
            "status": status,
            "monitored": row["YEAR"][:4],
            "mode": mode[:8],
            "modulation_type": row["MODTYP"][:12],
            "location": row["LOCATION"][:80],
            "power_kw": power,
            "azimuth": row["AZI"][:8],
            "antenna": row["ANTENNA"][:20],
            "remarks": row["REMARKS"][:80],
            "callsign": row["CALL"][:80],
            "center_frequency_hz": str(center_frequency_hz or ""),
            "longitude": row["LONGI"][:16],
            "latitude": row["LATI"][:16],
        })
        stats["confirmed" if status == "C" else "unconfirmed"] += 1
    records.sort(key=lambda item: (int(item["frequency_hz"]), item["time_utc"], item["station"]))
    stats["cached"] = len(records)
    return records, stats


def _atomic_write(path, callback, mode=0o600):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
            callback(handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser(description="Import a licensed local ILGRadio database")
    parser.add_argument("source", help="Path to ILGADATA.DBF or ILGBDATA.DBF")
    parser.add_argument("--output", default="/home/pi/Elmer/cache/ilgradio/schedules.csv")
    parser.add_argument("--metadata", default="/home/pi/Elmer/cache/ilgradio/metadata.json")
    args = parser.parse_args()
    source = Path(args.source)
    records, stats = normalize_records(source)
    if len(records) < 1_000:
        raise SystemExit("Refusing to replace the ILGRadio cache with fewer than 1,000 usable broadcasts.")
    source_bytes = source.read_bytes()
    metadata = {
        "source": "ILGRadio", "edition": source.stem.upper(),
        "source_file": source.name,
        "imported_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "database_date": f"{1900 + source_bytes[1]:04d}-{source_bytes[2]:02d}-{source_bytes[3]:02d}",
        "sha256": hashlib.sha256(source_bytes).hexdigest(),
        "license_notice": "Licensed ILGRadio data; local RigPi use only. Not for redistribution.",
        "excluded_fields": ["NA", "CA", "SA", "EU", "AF", "ME", "AS", "AU", "PA", "GRAPHIC", "G00-G23"],
        **stats,
    }
    _atomic_write(Path(args.output), lambda handle: (
        (lambda writer: (writer.writeheader(), writer.writerows(records)))(
            csv.DictWriter(handle, fieldnames=FIELDS, extrasaction="ignore")
        )
    ))
    _atomic_write(Path(args.metadata), lambda handle: (json.dump(metadata, handle, indent=2, sort_keys=True), handle.write("\n")))
    print(
        f"ILGRadio {metadata['database_date']}: {stats['cached']} active HF broadcast schedules cached "
        f"({stats['confirmed']} confirmed, {stats['unconfirmed']} unconfirmed)"
    )


if __name__ == "__main__":
    main()
