#!/usr/bin/env python3
import io
import json
import sys
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from elmer_web import AnswerSink,PAGE,event_bytes,initialize_feedback_db,load_api_key,store_feedback
from deploy.install_rigpi_integration import patch_header,patch_nginx

class Handler:
    def __init__(self): self.events=[]; self.wfile=io.BytesIO()
    def send_event(self,event,payload): self.events.append((event,payload))

handler=Handler(); sink=AnswerSink(handler)
sink.write('Hello Joe'); sink.flush()
assert handler.events==[('delta',{'text':'Hello Joe'})]
encoded=event_bytes('done',{'timing':{'first_text_seconds':2.94}}).decode()
assert encoded.startswith('event: done\ndata: ')
assert json.loads(encoded.split('data: ',1)[1])['timing']['first_text_seconds']==2.94
page=PAGE.read_text()
assert 'Ask Elmer' in page and '/api/answer' in page and 'aria-live' in page
assert 'OPENAI_API_KEY' not in page
assert load_api_key({'OPENAI_API_KEY':' direct-key '})=='direct-key'
with tempfile.TemporaryDirectory() as td:
    folder=Path(td)
    feedback_db=folder/'feedback.db'
    initialize_feedback_db(feedback_db)
    feedback_id=store_feedback(feedback_db,'joe',8,'Useful citations','Question?','Answer.','test-model')
    assert feedback_id==1
    import sqlite3
    with sqlite3.connect(feedback_db) as db:
        assert db.execute('SELECT username,rating,comment FROM feedback').fetchone()==('joe',8,'Useful citations')
    (folder/'openai_api_key').write_text(' credential-key\n')
    assert load_api_key({'CREDENTIALS_DIRECTORY':td})=='credential-key'
    header=folder/'header.php'
    header.write_text('<li><a class="dropdown-item" href="/help.php" target="_blank">RigPi Help</a></li>\n')
    assert patch_header(header) is True and 'Ask Elmer' in header.read_text()
    assert patch_header(header) is False
    alternate=folder/'header_cal.php'; alternate.write_text('<nav>Calendar menu</nav>\n')
    assert patch_header(alternate,required=False) is False
    nginx=folder/'myserver'
    nginx.write_text('server {\n    index index.php index.html;\n}\n')
    assert patch_nginx(nginx) is True and 'elmer.conf' in nginx.read_text()
    assert patch_nginx(nginx) is False
rigpi_page=(ROOT/'deploy'/'elmer.php').read_text()
assert '<body class="body-black-scroll"' in rigpi_page
assert "GetCallbook.php" in rigpi_page and "runCallsignLookup" in rigpi_page
assert "includes/modal.txt" in rigpi_page
assert "renderMarkdown" in rigpi_page and "<strong>$1</strong>" in rigpi_page
assert "getUserField($tUserName, 'FirstName')" in rigpi_page
assert 'id="elmerGreeting"' in rigpi_page
assert "what's on your mind this ${period}?" in rigpi_page
assert 'id="elmerFeedback"' in rigpi_page and '/elmer-api/feedback' in rigpi_page
assert 'Why did you choose that rating?' in rigpi_page
assert 'During this test, the information remains on this RigPi' in rigpi_page
assert 'Please do not include passwords, API keys or other sensitive information.' in rigpi_page
assert '73 ${elmerName}, your feedback will help improve RigPi.' in rigpi_page
assert "answer.classList.remove('streaming')" in rigpi_page
print('Elmer web-interface test passed')
