#!/usr/bin/env python3
"""Regression check for the FT8 sticky-header paint seam."""

from pathlib import Path


root = Path(__file__).resolve().parents[1]
html = (root / "index.html").read_text()

assert 'id="ft8TableScroller"' in html
assert "#ft8TableScroller" in html
assert "isolation: isolate" in html
assert "#ft8Table thead" in html
assert "position: sticky" in html
assert "box-shadow: 0 -3px 0 #0d1f0d" in html
assert "#ft8Table thead th" in html
assert "#ft8StatusBar" in html
assert "z-index: 4" in html

print("FT8 panel-layering regression test passed")
