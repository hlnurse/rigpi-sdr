#!/usr/bin/env python3
"""Focused tests for the local ILGRadio importer and EiBi fallback."""

import csv
import datetime as dt
import importlib.util
from pathlib import Path
import tempfile


ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


sync = load("sync_ilgradio", ROOT / "sync_ilgradio.py")
runtime = load("shortwave_schedule_ilg", ROOT.parent / "sdr_web" / "shortwave_schedule.py")

assert sync._time("1200:1300") == "1200-1300"
assert sync._time("1200=1300") == "1200-1300"
assert sync._days("1.345.7") == "SuTuWeThSa"

active = {
    "frequency_hz": "6070000", "time_utc": "2300-0200", "days": "Mo",
    "start_date": "010826", "stop_date": "310826",
}
monday = dt.datetime(2026, 8, 17, 23, 30, tzinfo=dt.timezone.utc)
tuesday = dt.datetime(2026, 8, 18, 1, 0, tzinfo=dt.timezone.utc)
september = dt.datetime(2026, 9, 1, 1, 0, tzinfo=dt.timezone.utc)
assert runtime.is_active(active, monday)
assert runtime.is_active(active, tuesday)
assert not runtime.is_active(active, september)

with tempfile.TemporaryDirectory() as directory:
    directory = Path(directory)
    eibi_path = directory / "eibi.csv"
    ilg_path = directory / "ilg.csv"
    common = {
        "frequency_hz": "6070000", "time_utc": "0000-2400", "days": "1234567",
        "country_code": "D", "language_code": "E", "language_name": "English",
        "target_code": "EU", "target_name": "Europe", "transmitter": "Test",
        "persistence": "0", "start_date": "", "stop_date": "",
    }
    with eibi_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=tuple(common) + ("station",))
        writer.writeheader()
        writer.writerow({**common, "station": "EiBi Station"})
    with ilg_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=sync.FIELDS)
        writer.writeheader()
        writer.writerow({**common, "station": "ILGRadio Station", "source": "ILGRadio", "status": "C"})
    cache = runtime.ScheduleCache(
        eibi_path, directory / "eibi.json", ilg_path, directory / "ilg.json"
    )
    document = cache.fib_document(monday, 6_000_000, 6_100_000)
    assert document["sources"] == ["ILGRadio", "EiBi"]
    assert len(document["objects"]) == 1
    assert document["objects"][0]["shortLabel"] == "ILGRadio Station"
    assert document["objects"][0]["source"] == "ILGRadio"

print("ILGRadio connector tests passed")
