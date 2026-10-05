#!/usr/bin/env python3
"""Regression checks for RigPi's built-in FT4 decoder guidance."""

import json
import sqlite3
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PI_ROOT = ROOT.parent
sys.path.insert(0, str(ROOT))

from answer_knowledge import INSTRUCTIONS
from build_knowledge import Parser
from question_knowledge import apply_current_guidance, matched_current_guidance


help_path = PI_ROOT / "RigPi" / "Help" / "rigpi-sdr.html"
parser = Parser()
parser.feed(help_path.read_text())
help_text = " ".join(parser.text)
assert "built-in receive decoding for both FT8 and FT4" in help_text
assert "select FT4" in help_text
assert "External WSJT-X software is not required" in help_text

guidance = json.loads((ROOT / "config" / "current_guidance.json").read_text())
rule = next(item for item in guidance["rules"]
            if item["id"] == "rigpi-built-in-ft8-ft4-decoder")
assert rule["path"] == "rigpi-sdr.html"
assert matched_current_guidance("What do FT4 signals look like?", [])[0]["id"] == rule["id"]

database = sqlite3.connect(":memory:")
database.execute("""CREATE TABLE documents (
    stable_id TEXT, title TEXT, path TEXT, source_id TEXT, source_type TEXT,
    author TEXT, source_url TEXT, local_ref TEXT, authority INTEGER,
    published_at TEXT)""")
database.execute("CREATE TABLE chunks (stable_id TEXT, content TEXT)")
database.execute("INSERT INTO documents VALUES (?,?,?,?,?,?,?,?,?,?)", (
    "HELP-0063", "Views > RigPi-SDR", "rigpi-sdr.html", "official-help",
    "official_help", "RigPi", "", "elmer://source/official-help/document/HELP-0063",
    100, "2026-08-24"))
database.execute("INSERT INTO chunks VALUES (?,?)", ("HELP-0063", help_text))
selected, rules = apply_current_guidance(
    database, "How can I confirm an FT4 signal in RigPi?", [], 8)
assert rules and selected
assert selected[0]["source_id"] == "official-help"
assert selected[0]["path"] == "rigpi-sdr.html"
assert "built-in receive decoding" in selected[0]["content"]

assert "built-in receive decoding for both FT8 and FT4" in INSTRUCTIONS
assert "Do not say that external WSJT-X is required" in INSTRUCTIONS

print("RigPi FT4-awareness regression test passed")
