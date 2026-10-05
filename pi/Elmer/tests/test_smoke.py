#!/usr/bin/env python3
import json, sqlite3, subprocess, sys, tempfile, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from build_knowledge import load_cfg

def run(config='config.json',help_zip=None,output_directory=None):
    help_zip=help_zip or ROOT/'sources'/'Help.sample.zip'
    output_directory=Path(output_directory)
    output_directory.mkdir(parents=True,exist_ok=True)
    config_path=Path(config)
    if not config_path.is_absolute(): config_path=ROOT/config_path
    effective=load_cfg(config_path)
    effective.setdefault('paths',{})['output_directory']=str(output_directory)
    effective['paths']['connector_cache_directory']=str(ROOT/'cache'/'connectors')
    connectors=effective.get('connectors',effective.get('sources',[]))
    for connector in connectors:
        connector['enabled']=connector.get('type')=='help_zip'
    test_config=output_directory/'effective-config.json'
    test_config.write_text(json.dumps(effective))
    return subprocess.run([sys.executable,str(ROOT/'build_knowledge.py'),str(help_zip),'--config',str(test_config)],cwd=ROOT,text=True,capture_output=True)

def main():
    with tempfile.TemporaryDirectory() as build_td:
        build_output=Path(build_td)/'output'
        p=run(output_directory=build_output); assert p.returncode==0,p.stderr
        assert 'v0.19' in p.stdout
        r=json.loads((build_output/'documentation-analysis.json').read_text())
        assert r['summary']['topics']==2
        db=sqlite3.connect(build_output/'knowledge.db')
        help_refs=[row[0] for row in db.execute("SELECT local_ref FROM documents WHERE source_type='help'")]
        chunk_refs=[row[0] for row in db.execute("SELECT DISTINCT local_ref FROM chunks WHERE source_type='help'")]
        db.close()
        assert help_refs and all(x.startswith('elmer://source/') and '/document/HELP-' in x for x in help_refs)
        assert set(chunk_refs)==set(help_refs)
        # macOS ZIP metadata must never become documents or images
        dirty=Path(build_td)/'Help.with-macos-metadata.zip'
        with zipfile.ZipFile(ROOT/'sources'/'Help.sample.zip') as src,zipfile.ZipFile(dirty,'w') as dst:
            for item in src.infolist(): dst.writestr(item,src.read(item.filename))
            dst.writestr('__MACOSX/._fake.html','<html><title>Fake metadata</title></html>')
            dst.writestr('images/._fake.png',b'not an image')
            dst.writestr('.DS_Store',b'metadata')
        x=run(help_zip=dirty,output_directory=build_output); assert x.returncode==0,x.stderr
        dirty_report=json.loads((build_output/'documentation-analysis.json').read_text())
        assert dirty_report['summary']['topics']==2
        assert dirty_report['summary']['images']==1
    # modular include override and configurable closing
    with tempfile.TemporaryDirectory() as td:
        q=Path(td)/'neutral.json'
        q.write_text(json.dumps({'include':[str(ROOT/'config'/'project.json'),str(ROOT/'config'/'sources.json'),str(ROOT/'config'/'analysis.json'),str(ROOT/'config'/'report.json')],'project':{'builder_name':'Neutral Builder'},'messages':{'console_closing':'','report_closing':''}}))
        x=run(config=q,output_directory=Path(td)/'output')
        assert x.returncode==0,x.stderr
        assert 'Neutral Builder' in x.stdout and '73 from' not in x.stdout
    # legacy monolithic configuration remains supported
    old=json.loads((ROOT/'config'/'project.json').read_text())
    old.update(json.loads((ROOT/'config'/'sources.json').read_text()))
    old.update(json.loads((ROOT/'config'/'analysis.json').read_text()))
    old.update(json.loads((ROOT/'config'/'report.json').read_text()))
    with tempfile.TemporaryDirectory() as td:
        q=Path(td)/'old.json'; q.write_text(json.dumps(old))
        x=run(config=q,output_directory=Path(td)/'output')
        assert x.returncode==0,x.stderr
    print('v0.19 smoke tests passed')
if __name__=='__main__': main()
