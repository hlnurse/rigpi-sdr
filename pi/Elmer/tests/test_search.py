#!/usr/bin/env python3
import sqlite3, subprocess, sys, tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory() as td:
    db_path=Path(td)/'knowledge.db'
    db=sqlite3.connect(db_path)
    db.execute("CREATE TABLE documents(stable_id TEXT PRIMARY KEY,authority INTEGER)")
    db.execute("CREATE VIRTUAL TABLE chunks USING fts5(stable_id UNINDEXED,title,path UNINDEXED,body,source_id UNINDEXED,source_type UNINDEXED,author UNINDEXED,thread_id UNINDEXED,source_url UNINDEXED,local_ref UNINDEXED)")
    db.executemany('INSERT INTO chunks VALUES(?,?,?,?,?,?,?,?,?,?)',[
        ('DOC-1','First page','first.html','rigctld appears in a weaker matching chunk','official-help','help','','','',''),
        ('DOC-1','First page','first.html','rigctld rigctld is the strongest matching chunk','official-help','help','','','',''),
        ('GROUP-1','Re: Shared thread','thread:Shared thread','a weaker rigctld forum message','groups-messages','groups_io_message','Alice','THREAD-1','','elmer://source/groups/thread/THREAD-1/message/GROUP-1'),
        ('GROUP-2','Re: Shared thread','thread:Shared thread','rigctld rigctld in the best forum message','groups-messages','groups_io_message','Bob','THREAD-1','https://groups.io/g/RigPi/message/42','elmer://source/groups/thread/THREAD-1/message/GROUP-2'),
        ('GROUP-3','Other thread','thread:Other thread','another rigctld discussion','groups-messages','groups_io_message','Carol','THREAD-2','','elmer://source/groups/thread/THREAD-2/message/GROUP-3'),
        ('DOC-2','Needlephrase title','title-only.html','body text unrelated to the query','official-help','help','','','',''),
        ('DOC-3','IC-7300 model','model.html','The IC-7300 is supported.','official-help','help','','','',''),
        ('GH-ISSUE-1','Issue #1: Shared GitHub issue','issue:1','rigctld issue report','hamlib-github','github_issue','Reporter','GH-THREAD-1','https://github.com/Hamlib/Hamlib/issues/1','elmer://source/hamlib-github/document/GH-ISSUE-1'),
        ('GH-COMMENT-1','Issue #1 comment: Shared GitHub issue','issue:1#comment','rigctld rigctld maintainer response','hamlib-github','github_issue_comment','Maintainer','GH-THREAD-1','https://github.com/Hamlib/Hamlib/issues/1#comment','elmer://source/hamlib-github/document/GH-COMMENT-1'),
    ])
    db.executemany('INSERT INTO documents VALUES(?,?)',[
        ('DOC-1',100),('DOC-2',100),('DOC-3',100),('GROUP-1',60),('GROUP-2',60),('GROUP-3',60),('GH-ISSUE-1',90),('GH-COMMENT-1',90)
    ])
    db.commit(); db.close()
    result=subprocess.run([sys.executable,str(ROOT/'search_knowledge.py'),'rigctld','--db',str(db_path),'-n','10'],check=True,capture_output=True,text=True)
    assert result.stdout.count('DOC-1  First page')==1
    assert result.stdout.count('Shared thread')==2  # heading plus best-message subject
    assert 'GROUP-1' not in result.stdout
    assert result.stdout.count('GROUP-2  Shared thread')==1
    assert result.stdout.count('GROUP-3  Other thread')==1
    assert 'strongest matching chunk' in result.stdout
    assert '[Official Help]' in result.stdout
    assert result.stdout.count('[Groups.io]')==2
    assert result.stdout.count('Shared GitHub issue')==1
    assert 'External: https://groups.io/g/RigPi/message/42' in result.stdout
    assert result.stdout.count('Local: elmer://source/groups/')==2
    limited=subprocess.run([sys.executable,str(ROOT/'search_knowledge.py'),'rigctld','--db',str(db_path),'-n','1'],check=True,capture_output=True,text=True)
    assert limited.stdout.count('DOC-')==1
    title_match=subprocess.run([sys.executable,str(ROOT/'search_knowledge.py'),'needlephrase','--db',str(db_path)],check=True,capture_output=True,text=True)
    assert '[Needlephrase] title' in title_match.stdout
    assert 'body text unrelated' not in title_match.stdout
    model_match=subprocess.run([sys.executable,str(ROOT/'search_knowledge.py'),'IC-7300','--db',str(db_path)],check=True,capture_output=True,text=True)
    assert 'DOC-3  IC-7300 model' in model_match.stdout
    filtered=subprocess.run([sys.executable,str(ROOT/'search_knowledge.py'),'rigctld','--db',str(db_path),'--source','hamlib-github'],check=True,capture_output=True,text=True)
    assert 'Shared GitHub issue' in filtered.stdout
    assert '[Official Help]' not in filtered.stdout and '[Groups.io]' not in filtered.stdout

print('Search grouping test passed')
