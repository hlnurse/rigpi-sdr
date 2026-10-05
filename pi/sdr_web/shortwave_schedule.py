#!/usr/bin/env python3
"""Runtime lookup for RigPi's local ILGRadio and EiBi shortwave schedules."""

import csv
import datetime as dt
import json
from pathlib import Path
import re
import threading


DEFAULT_CACHE = Path("/home/pi/Elmer/cache/eibi/schedules.csv")
DEFAULT_METADATA = Path("/home/pi/Elmer/cache/eibi/metadata.json")
DEFAULT_ILG_CACHE = Path("/home/pi/Elmer/cache/ilgradio/schedules.csv")
DEFAULT_ILG_METADATA = Path("/home/pi/Elmer/cache/ilgradio/metadata.json")
WEEKDAYS = {"Mo": 0, "Tu": 1, "We": 2, "Th": 3, "Fr": 4, "Sa": 5, "Su": 6}
UNCERTAIN_MARKERS = ("irr", "tent", "test", "alt")


def _hhmm(value):
    if value == "2400":
        return 24 * 60
    hour, minute = int(value[:2]), int(value[2:])
    if hour > 23 or minute > 59:
        raise ValueError(value)
    return hour * 60 + minute


def _operating_weekdays(expression):
    text = str(expression or "").strip()
    if not text:
        return None
    if text == "MF" or text.startswith("MF-"):
        return {0, 1, 2, 3, 4}
    numeric = re.fullmatch(r"[1-7]+", text)
    if numeric:
        return {int(char) - 1 for char in text}
    result = set()
    for left, right in re.findall(r"(Mo|Tu|We|Th|Fr|Sa|Su)(?:-(Mo|Tu|We|Th|Fr|Sa|Su))?", text):
        first = WEEKDAYS[left]
        if not right:
            result.add(first)
            continue
        last = WEEKDAYS[right]
        day = first
        while True:
            result.add(day)
            if day == last:
                break
            day = (day + 1) % 7
    return result or None


def _date_is_active(start_text, stop_text, when):
    def date_value(text):
        match = re.match(r"^(\d{2})(\d{2})(\d{2})?$", str(text or ""))
        if not match:
            return None
        day, month = map(int, match.groups()[:2])
        year = int(match.group(3)) + 2000 if match.group(3) else 2000
        try:
            return dt.date(year, month, day)
        except ValueError:
            return None
    start = date_value(start_text)
    stop = date_value(stop_text)
    if not start and not stop:
        return True
    full_year = any(len(str(value or "")) == 6 for value in (start_text, stop_text))
    current = when.date() if full_year else dt.date(2000, when.month, when.day)
    if start and stop:
        return start <= current <= stop if start <= stop else current >= start or current <= stop
    return current >= start if start else current <= stop


def is_active(record, when):
    if when.tzinfo is None:
        when = when.replace(tzinfo=dt.timezone.utc)
    when = when.astimezone(dt.timezone.utc)
    try:
        start_text, stop_text = record["time_utc"].split("-", 1)
        start, stop = _hhmm(start_text), _hhmm(stop_text)
    except (KeyError, ValueError):
        return False
    minute = when.hour * 60 + when.minute
    schedule_day = when.weekday()
    if start <= stop:
        in_time = start <= minute < stop or (start == 0 and stop == 1440)
    else:
        in_time = minute >= start or minute < stop
        if minute < stop:
            schedule_day = (schedule_day - 1) % 7
    if not in_time or not _date_is_active(record.get("start_date"), record.get("stop_date"), when):
        return False
    weekdays = _operating_weekdays(record.get("days"))
    return weekdays is None or schedule_day in weekdays


class ScheduleCache:
    def __init__(self, path=DEFAULT_CACHE, metadata_path=DEFAULT_METADATA,
                 preferred_path=DEFAULT_ILG_CACHE, preferred_metadata_path=DEFAULT_ILG_METADATA):
        self.path = Path(path)
        self.metadata_path = Path(metadata_path)
        self.preferred_path = Path(preferred_path) if preferred_path else None
        self.preferred_metadata_path = Path(preferred_metadata_path) if preferred_metadata_path else None
        self._signature = None
        self._records = []
        self._metadata = {}
        self._lock = threading.Lock()

    def load(self):
        paths = [(self.path, self.metadata_path, "EiBi")]
        if self.preferred_path:
            paths.insert(0, (self.preferred_path, self.preferred_metadata_path, "ILGRadio"))
        signature = tuple(path.stat().st_mtime_ns if path.exists() else -1 for path, _, _ in paths)
        with self._lock:
            if signature != self._signature:
                records, sources = [], []
                for path, metadata_path, source_name in paths:
                    if not path.exists():
                        continue
                    with path.open(encoding="utf-8", newline="") as handle:
                        source_records = list(csv.DictReader(handle))
                    for record in source_records:
                        record["source"] = record.get("source") or source_name
                    source_metadata = {}
                    try:
                        source_metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
                    except (OSError, ValueError, AttributeError):
                        pass
                    records.extend(source_records)
                    sources.append(source_metadata or {"source": source_name})
                self._records = records
                self._metadata = {"sources": sources}
                self._signature = signature
            return self._records, self._metadata

    def fib_document(self, when=None, low_hz=2_300_000, high_hz=30_000_000):
        when = when or dt.datetime.now(dt.timezone.utc)
        records, metadata = self.load()
        grouped = {}
        for record in records:
            try:
                frequency = int(record["frequency_hz"])
            except (KeyError, ValueError):
                continue
            if frequency < low_hz or frequency > high_hz or not is_active(record, when):
                continue
            grouped.setdefault(frequency, []).append(record)
        objects = []
        for frequency, matches in sorted(grouped.items()):
            ilg_matches = [row for row in matches if row.get("source") == "ILGRadio"]
            if ilg_matches:
                matches = ilg_matches
            matches.sort(key=lambda row: ("alt" in row.get("days", "").lower(), row.get("station", "")))
            primary = matches[0]
            names = []
            details = []
            uncertain = False
            for row in matches[:8]:
                station = row.get("station") or "Unknown station"
                if station not in names:
                    names.append(station)
                language = row.get("language_name") or row.get("language_code") or "language not listed"
                target = row.get("target_name") or row.get("target_code") or "target not listed"
                location = row.get("location") or row.get("transmitter") or ""
                location_note = f" · from {location}" if location else ""
                details.append(f"{station} · {language} · target {target}{location_note} · {row.get('time_utc', '')} UTC")
                uncertain = uncertain or any(marker in row.get("days", "").lower() for marker in UNCERTAIN_MARKERS)
                uncertain = uncertain or row.get("status") == "N"
            more = len(matches) - len(details)
            if more > 0:
                details.append(f"+{more} more schedule entries")
            short = names[0]
            long_label = short if len(names) == 1 else f"{short} +{len(names) - 1}"
            source_name = primary.get("source") or "EiBi"
            note = f"{source_name} schedule; reception is not confirmed. " + " | ".join(details)
            if uncertain:
                note = "Tentative/irregular schedule. " + note
            objects.append({
                "id": f"{source_name.lower()}-{frequency}", "type": "broadcast-schedule", "source": source_name,
                "start": frequency - 2500, "end": frequency + 2500,
                "shortLabel": short[:24], "longLabel": long_label[:52],
                "tooltip": f"{long_label} — scheduled on {frequency / 1000:g} kHz",
                "note": note, "color": "rgba(250,204,21,0.52)", "priority": 96,
                "zoomLevel": 1, "maxSpanHz": 2_000_000,
                "action": {"label": "Tune scheduled broadcast", "frequency": frequency,
                           "mode": primary.get("mode") if primary.get("mode") in {"USB", "LSB"} else "AM",
                           "bandwidth": 6000},
            })
        sources = metadata.get("sources", [])
        source_names = [item.get("source", "") for item in sources if item.get("source")]
        revisions = [item.get("imported_at") or item.get("downloaded_at") or "" for item in sources]
        return {
            "schemaVersion": 1, "id": "shortwave-now", "name": "Shortwave broadcasts scheduled now",
            "revision": "|".join(revisions), "generatedAt": when.isoformat(),
            "sources": source_names, "objects": objects,
        }
