#!/usr/bin/env python3
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
voice = (ROOT / 'web' / 'elmer_voice.js').read_text(encoding='utf-8')
control = (ROOT / 'web' / 'elmer_control.js').read_text(encoding='utf-8')
panel = (ROOT / 'deploy' / 'elmer_control_panel.php').read_text(encoding='utf-8')
patcher = (ROOT / 'deploy' / 'patch_elmer_php_cloud.py').read_text(encoding='utf-8')

assert "['ru-RU', 'Русский']" in voice
assert "['uk-UA', 'Українська']" in voice
assert "['pl-PL', 'Polski']" in voice
assert "['cs-CZ', 'Čeština']" in voice
assert "['nb-NO', 'Norsk bokmål']" in voice
assert "['da-DK', 'Dansk']" in voice
assert "['fi-FI', 'Suomi']" in voice
assert 'привет|эй|здравствуй|привіт|гей|слухай' in voice
assert '(?:elmer|элмер|ельмер|елмер)' in voice

assert 'привет|эй|здравствуй|привіт|гей|слухай' in control
assert 'радио|радіо|ригпи|рігпі' in control
assert '|элмер|ельмер|елмер)' in control

assert 'cześć|halo|ahoj|moi' in voice
assert 'cześć|halo|ahoj|moi' in control

assert '/js/elmer_control.js?v=20260819-2' in panel
assert '/js/elmer_voice.js?v=20260819-2' in patcher

print('European voice language checks passed.')
