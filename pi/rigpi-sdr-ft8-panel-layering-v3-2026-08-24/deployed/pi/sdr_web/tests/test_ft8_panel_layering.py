#!/usr/bin/env python3
"""Regression check for the FT8 sticky-header paint seam."""

from pathlib import Path


root = Path(__file__).resolve().parents[1]
html = (root / "index.html").read_text()

assert 'id="ft8TableScroller"' in html
scroller_pos = html.index('id="ft8TableScroller"')
table_pos = html.index('id="ft8Table"', scroller_pos)
assert table_pos - scroller_pos < 500
assert html.count('id="ft8TableScroller"') == 1
assert "#ft8TableScroller" in html
assert "isolation: isolate" in html
assert 'id="ft8ScrollSeal"' in html
assert "#ft8ScrollSeal" in html
assert "height: 6px" in html
assert "min-height: 6px" in html
assert "#ft8Table thead th" in html
assert "position: sticky" in html
assert "top: 6px" in html
assert "box-shadow: 0 -2px 0 #0d1f0d" in html
assert "#ft8StatusBar" in html
assert "z-index: 4" in html

print("FT8 panel-layering regression test passed")
