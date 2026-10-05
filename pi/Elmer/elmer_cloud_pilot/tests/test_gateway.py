#!/usr/bin/env python3
import sqlite3
import sys
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))

from elmer_gateway import PAGE,load_secret
from gateway_store import (
    approve_pairing,audit_action,audit_rows,authenticate_station,complete_request,create_pass,exchange_pass,
    initialize,list_pairings,list_passes,list_stations,pairing_status,reject_pairing,
    reserve_anonymous,reserve_reviewer,reserve_station,reviewer_context,revoke_pass,
    revoke_station,start_pairing,station_statistics,store_feedback,update_station_limit,
)


secret=b'x'*48
with tempfile.TemporaryDirectory() as td:
    folder=Path(td)
    state=folder/'gateway.db'
    initialize(state)

    request_id,remaining=reserve_anonymous(state,'person-a',1,2)
    assert remaining==0
    try:
        reserve_anonymous(state,'person-a',1,2)
        raise AssertionError('personal daily quota was not enforced')
    except PermissionError:
        pass
    reserve_anonymous(state,'person-b',1,2)
    try:
        reserve_anonymous(state,'person-c',1,2)
        raise AssertionError('global anonymous quota was not enforced')
    except PermissionError:
        pass

    complete_request(state,request_id,'complete',{'total_tokens':123},{'total_seconds':1.2})
    feedback_id=store_feedback(
        state,request_id,'anonymous','person-a',9,'Useful','Question','Answer')
    assert feedback_id
    try:
        store_feedback(state,request_id,'anonymous','person-a',8,'Again','Question','Answer')
        raise AssertionError('duplicate feedback was accepted')
    except sqlite3.IntegrityError:
        pass
    try:
        store_feedback(state,request_id,'anonymous','person-z',5,'','Question','Answer')
        raise AssertionError('cross-identity feedback was accepted')
    except ValueError:
        pass

    pass_id,token,_expires=create_pass(state,secret,'Test Reviewer',1,2)
    session,_session_expires,name=exchange_pass(state,secret,token,12)
    assert name=='Test Reviewer'
    context=reviewer_context(state,secret,session)
    assert context['pass_id']==pass_id and context['remaining']==2
    first,remaining,actor,_=reserve_reviewer(state,secret,session)
    assert remaining==1
    complete_request(state,first,'complete')
    second,remaining,_actor,_=reserve_reviewer(state,secret,session)
    assert remaining==0
    complete_request(state,second,'complete')
    try:
        reserve_reviewer(state,secret,session)
        raise AssertionError('reviewer allowance was not enforced')
    except PermissionError:
        pass
    assert list_passes(state)[0][4:6]==(2,2)
    assert revoke_pass(state,pass_id)==1
    assert reviewer_context(state,secret,session) is None

    pairing=start_pairing(
        state,secret,'pairing-person','station-12345678','Howard’s RigPi',2,10,10)
    assert len(pairing['code'])==6
    assert pairing_status(
        state,secret,pairing['pairing_id'],pairing['poll_token'])['status']=='pending'
    try:
        pairing_status(state,secret,pairing['pairing_id'],'wrong-token')
        raise AssertionError('incorrect pairing poll token was accepted')
    except PermissionError:
        pass
    approval=approve_pairing(state,secret,pairing['code'],'pilot',2)
    first_claim=pairing_status(
        state,secret,pairing['pairing_id'],pairing['poll_token'])
    credential=first_claim['credential']
    assert first_claim['status']=='paired' and credential.startswith('elp_')
    retry_claim=pairing_status(
        state,secret,pairing['pairing_id'],pairing['poll_token'])
    assert retry_claim['credential']==credential
    station=authenticate_station(state,secret,credential)
    second_claim=pairing_status(
        state,secret,pairing['pairing_id'],pairing['poll_token'])
    assert second_claim['status']=='paired' and 'credential' not in second_claim
    assert station['station_id']=='station-12345678' and station['remaining']==2
    station_request,remaining,station_actor,_station=reserve_station(state,secret,credential)
    assert remaining==1
    complete_request(state,station_request,'complete',
        {'input_tokens':100,'output_tokens':25,'total_tokens':125},
        {'first_text_seconds':1.2,'total_seconds':4.5},records_searched=48910,
        evidence_used=10,model='test-model')
    stats=station_statistics(state,'station-12345678',30)
    assert stats['questions']==1 and stats['tokens']['total']==125
    assert stats['station']['used']==1 and stats['models']==['test-model']
    station_request,remaining,_station_actor,_station=reserve_station(state,secret,credential)
    assert remaining==0
    complete_request(state,station_request,'complete')
    try:
        reserve_station(state,secret,credential)
        raise AssertionError('station monthly allowance was not enforced')
    except PermissionError:
        pass
    assert station_actor
    assert list_pairings(state)[0][5]=='claimed'
    assert list_stations(state)[0][8]==2

    replacement=start_pairing(
        state,secret,'pairing-person','station-12345678','Howard’s RigPi',2,10,10)
    replacement_approval=approve_pairing(state,secret,replacement['code'],'pilot',3)
    assert replacement_approval['credential_id']!=approval['credential_id']
    assert authenticate_station(state,secret,credential) is None
    replacement_claim=pairing_status(
        state,secret,replacement['pairing_id'],replacement['poll_token'])
    replacement_credential=replacement_claim['credential']
    assert authenticate_station(state,secret,replacement_credential)['remaining']==3
    assert revoke_station(state,replacement_approval['credential_id'])==1
    assert authenticate_station(state,secret,replacement_credential) is None
    assert update_station_limit(state,replacement_approval['credential_id'],300)==0
    audit_action(state,'howard','test','station','station-12345678',{'ok':True})
    assert audit_rows(state)[0]['detail']=={'ok':True}

    rejected=start_pairing(
        state,secret,'other-person','station-87654321','Rejected RigPi',1,10,10)
    assert reject_pairing(state,secret,rejected['code'])==1
    assert pairing_status(
        state,secret,rejected['pairing_id'],rejected['poll_token'])['status']=='rejected'
    try:
        start_pairing(state,secret,'other-person','station-00000000','Too Many',1,10,10)
        raise AssertionError('pairing request rate limit was not enforced')
    except PermissionError:
        pass

    expired=start_pairing(
        state,secret,'expiry-person','station-99999999','Expired RigPi',1,10,10)
    with sqlite3.connect(state) as db:
        db.execute("UPDATE pairing_requests SET expires_at='2000-01-01T00:00:00+00:00' WHERE id=?",
                   (expired['pairing_id'],))
    assert pairing_status(
        state,secret,expired['pairing_id'],expired['poll_token'])['status']=='expired'
    try:
        approve_pairing(state,secret,expired['code'])
        raise AssertionError('expired pairing code was approved')
    except PermissionError:
        pass

    secret_file=folder/'secret'
    secret_file.write_bytes(secret+b'\n')
    assert load_secret(secret_file)==secret

    with sqlite3.connect(state) as db:
        assert db.execute('SELECT COUNT(*) FROM feedback').fetchone()[0]==1

page=PAGE.read_text()
assert 'one live question per connection each day' in page
assert 'Never include passwords, API keys, or other secrets.' in page
assert 'voluntarily submit feedback' in page
assert 'renderMarkdown' in page
assert 'RigPiW.png' in page and 'class="brand-name">RigPi' in page
assert 'id="copyAnswer"' in page and 'ClipboardItem' in page
assert "'text/html':html" in page and 'richAnswerHtml' in page
assert 'copyRenderedAnswer' in page and "document.execCommand('copy')" in page
assert 'records_searched' in page and 'evidence_used' in page
assert 'safeImageUrl' in page and 'raw.githubusercontent.com' in page
assert 'https://rigpi.net' in page
assert '/api/feedback' in page
assert 'OPENAI_API_KEY' not in page
print('Elmer gateway tests passed')
