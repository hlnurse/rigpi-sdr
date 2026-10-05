#!/usr/bin/env python3
"""Regression check that FT8 titles are outside the vertical scroller."""

from pathlib import Path


root = Path(__file__).resolve().parents[1]
html = (root / "index.html").read_text()

viewport_pos = html.index('id="ft8TableViewport"')
header_pos = html.index('id="ft8HeaderScroller"', viewport_pos)
header_table_pos = html.index('id="ft8HeaderTable"', header_pos)
scroller_pos = html.index('id="ft8TableScroller"', header_table_pos)
table_pos = html.index('id="ft8Table"', scroller_pos)
body_pos = html.index('id="ft8TableBody"', table_pos)
assert viewport_pos < header_pos < header_table_pos < scroller_pos < table_pos < body_pos
assert html.count('id="ft8TableScroller"') == 1
assert html.count('id="ft8HeaderScroller"') == 1
assert html.count('id="ft8HeaderTable"') == 1
assert html.count('<col style="width:52px"><col style="width:32px">') == 2
assert "#ft8TableViewport" in html
assert "#ft8HeaderScroller" in html
assert "#ft8HeaderTable," in html
assert "table-layout: fixed" in html
assert "width: 460px" in html
assert "box-sizing: border-box" in html
assert "text-overflow: ellipsis" in html
assert 'header.scrollLeft = event.currentTarget.scrollLeft' in html
assert '#ft8Table thead th' not in html
assert 'id="ft8ScrollSeal"' not in html

print("FT8 panel-layering regression test passed")
