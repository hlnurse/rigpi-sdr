#!/usr/bin/env python3
import sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from build_knowledge import Parser

sample='''<!doctype html><html><head><title>Cloudflare Tunnel</title>
<style>.hidden { color: red; }</style><script>window.secret = "not knowledge";</script></head>
<body><h1>Cloudflare Tunnel</h1><p>Use the current RigPi tunnel settings.</p>
<noscript>JavaScript warning is not help content.</noscript><a href="next.html">Next</a></body></html>'''
parser=Parser(); parser.feed(sample)
text=' '.join(parser.text)
assert 'Cloudflare Tunnel' in text
assert 'current RigPi tunnel settings' in text
assert 'window.secret' not in text and '.hidden' not in text and 'JavaScript warning' not in text
assert parser.links==['next.html']
assert ' '.join(parser.title)=='Cloudflare Tunnel'
elmer_page=(ROOT/'deploy'/'elmer.php').read_text()
assert "'/Help/RigPi.html?'" in elmer_page
assert "data.source_id==='official-help'" in elmer_page
print('Help HTML extraction test passed')
