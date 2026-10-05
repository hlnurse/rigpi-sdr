#!/usr/bin/env python3
import sys
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))

from answer_knowledge import packet_input
from deploy.patch_elmer_php_cloud import patch
from live_connectors import normalize_live_context
from multilingual_planner import normalize_plan


context=normalize_live_context({'callbook':{
    'call':'sm5vfe','name':'Joe Example','city':'Uppsala','country':'Sweden',
    'grid':'JO89','provider':'QRZ XML','retrieved_at':'2026-08-15T12:00:00Z',
    'reference':'https://www.qrz.com/db/SM5VFE',
    'address_line':'Example Road 1','postal_code':'12345',
    'biography':'Joe enjoys portable CW operation.',
    'image_url':'https://cdn-xml.qrz.com/s/sm5vfe/sm5vfe.jpg?p=abc123',
    'email':'joe@example.net','website':'https://example.net/joe',
    'qsl_manager':'SM5ABC','lotw':'yes','eqsl':'no','paper_qsl':'unknown',
    'aliases':'SM5OLD','previous_call':'SM5OLD','nickname':'Joe',
    'formatted_name':'Joe “DX” Example','license_effective':'2020-01-01',
    'license_expires':'2030-01-01','iota':'EU-001','time_zone':'Europe/Stockholm',
    'gmt_offset':'+02:00','daylight_saving':'yes',
}})
assert context['callbook']['call']=='SM5VFE'
assert 'Joe Example' in packet_input({'question':'Who is SM5VFE?','evidence':[],
                                      'live_context':context})

hamqth=normalize_live_context({'callbook':{
    'call':'OK2CQR','name':'Petr Hlozek','provider':'HamQTH XML',
    'reference':'https://www.hamqth.com/OK2CQR',
    'image_url':'https://www.hamqth.com/userfiles/ok2cqr/header.jpg',
}})
assert hamqth['callbook']['provider']=='HamQTH XML'
assert hamqth['callbook']['reference'].endswith('/OK2CQR')

fcc=normalize_live_context({'fcc_search':{
    'provider':'RigPi onboard FCC database','retrieved_at':'2026-08-15T17:00:00Z',
    'postal_code_filter':'44691','radius_miles':1,'center':'signed-in RigPi account',
    'radius_applied':False,
    'distance_basis':'FCC mailing ZIP-code centroids; distances are approximate, not street-level',
    'notice':'The ZIP filter was applied, but the person-level radius was not.',
    'geocoder':'U.S. Census Public_AR_Current','geocode_attempted':2,
    'geocoded_matches':2,'geocode_unmatched':0,'geocode_deferred':0,
    'total_matches':2,'returned':2,'results':[
        {'call':'W6HN','name':'Howard Nurse','city':'Wooster','state':'OH',
         'postal_code':'44691','license_class':'Amateur Extra','former_call':'W1ABC',
         'distance_miles':0.8},
        {'call':'W8ABC','name':'Test Operator','city':'Wooster','state':'OH',
         'postal_code':'44691','distance_miles':0.8},
    ]}})
assert fcc['fcc_search']['results'][0]['call']=='W6HN'
assert fcc['fcc_search']['results'][0]['license_class']=='Amateur Extra'
assert fcc['fcc_search']['radius_applied'] is False
assert 'ZIP-code centroids' in packet_input({'question':'Nearby hams?','evidence':[],
                                             'live_context':fcc})

shortwave=normalize_live_context({'shortwave_search':{
    'provider':'ILGRadio with EiBi fallback','reference':'https://www.ilgradio.com/',
    'retrieved_at':'2026-08-18T14:00:00+00:00','database_date':'2026-08-17',
    'language_filter':'Spanish','station_filter':'','active_now':True,
    'cache_records':15000,'total_matches':2,'returned':2,
    'notice':'Schedule entries indicate planned broadcasts; reception is not guaranteed.',
    'results':[
        {'source':'ILGRadio','frequency_hz':13720000,'station':'Radio Example',
         'language':'Spanish','time_utc':'1200-1500','days':'SuMoTuWeThFrSa',
         'country':'USA','target':'Americas','transmitter':'Greenville','status':'C',
         'monitored':'2608','mode':'AM','power_kw':'250.0'},
        {'source':'EiBi','frequency_hz':15550000,'station':'Fallback Radio',
         'language':'Spanish','time_utc':'1300-1400','days':'Mo-Fr','mode':'AM'},
    ]}})
assert shortwave['shortwave_search']['results'][0]['frequency_hz']==13720000
assert shortwave['shortwave_search']['language_filter']=='Spanish'
assert 'Radio Example' in packet_input({'question':'Spanish broadcasts?','evidence':[],
                                         'live_context':shortwave})

rig=normalize_live_context({'rig_control':{
    'status':'complete','action':'tune_wwv_10mhz','radio':1,
    'requested_frequency_hz':10000000,'verified_frequency_hz':10000000,
    'requested_mode':'AM','verified_mode':'AM',
    'message':'RigPi tuned and verified WWV.','performed_at':'2026-08-15T22:00:00Z',
}})
assert rig['rig_control']['verified_frequency_hz']==10000000
assert 'tune_wwv_10mhz' in packet_input({'question':'Tune WWV','evidence':[],
                                         'live_context':rig})

for bad in (
    {'callbook':{'call':'10MHZ','provider':'QRZ XML'}},
    {'callbook':{'call':'W6HN','password':'secret'}},
    {'callbook':{'call':'W6HN','reference':'https://evil.example/W6HN'}},
    {'callbook':{'call':'W6HN','image_url':'https://evil.example/W6HN.jpg'}},
    {'callbook':{'call':'W6HN','email':'not-an-email'}},
    {'callbook':{'call':'W6HN','website':'javascript:alert(1)'}},
    {'fcc_search':{'results':[{'call':'W6HN','distance_miles':-1}]}},
    {'fcc_search':{'results':[{'call':'not a call','distance_miles':1}]}},
    {'fcc_search':{'results':[],'secret':'nope'}},
    {'fcc_search':{'results':[],'radius_applied':'no'}},
    {'shortwave_search':{'active_now':True,'total_matches':1,'returned':1,
                         'cache_records':1,'results':[{'frequency_hz':13720000}]}},
    {'shortwave_search':{'active_now':True,'total_matches':1,'returned':1,
                         'cache_records':1,'reference':'https://evil.example/',
                         'results':[{'frequency_hz':13720000,'station':'Radio'}]}},
    {'rig_control':{'status':'complete','action':'arbitrary','radio':1,
                    'requested_frequency_hz':10000000,'requested_mode':'AM'}},
    {'rig_control':{'status':'complete','action':'tune_wwv_10mhz','radio':1,
                    'requested_frequency_hz':14074000,'requested_mode':'AM'}},
):
    try:
        normalize_live_context(bad)
        raise AssertionError('invalid live connector data was accepted')
    except ValueError:
        pass

source=Path('/Users/howardlnurse/Desktop/Elmer/deploy/elmer.php')
with tempfile.TemporaryDirectory() as folder:
    target=Path(folder)/'elmer.php'
    target.write_text(source.read_text(encoding='utf-8'),encoding='utf-8')
    assert patch(target)
    page=target.read_text(encoding='utf-8')
    assert 'function liveContextFor(text)' in page
    assert '/programs/ElmerCallbook.php' in page
    assert '/programs/ElmerFCC.php' in page
    assert 'function requestedFccSearch(text)' in page
    assert "return {call,center:'user',limit:1}" in page
    assert "if(call&&/\\b(?:map|mapped|show|locate|location)\\b/i.test(text))" in page
    assert '|location|map|mapped|locate|' in page
    assert '|photograph|photographs|picture|pictures|' in page
    assert '|photographie|photographies|adresse|biographie|indicatif|' in page
    assert 'function requestedCallbookProvider(text)' in page
    assert "if(/\\bqrz\\b/i.test(text))return 'auto'" in page
    assert 'provider:requestedCallbookProvider(text)' in page
    assert '/includes/leaflet.css' in page and '/js/leaflet.js' in page
    assert 'id="elmerMapSection"' in page and 'id="elmerMap"' in page
    assert 'function renderElmerMap(data)' in page
    assert 'lastFccMap=data.map||null;delete data.map' in page
    assert 'renderElmerMap(lastFccMap)' in page
    assert 'function queryPlanFor(text)' in page
    assert 'function liveContextFromPlan(plan,text)' in page
    assert '/programs/ElmerShortwave.php' in page
    assert 'context.shortwave_search=data' in page
    assert 'query_plan:queryPlan' in page
    assert 'function rigControlFromPlan(plan)' in page
    assert '/programs/ElmerRigControl.php' in page
    assert "phase:'prepare'" in page and "phase:'execute'" in page
    assert "phase:'cancel'" in page and "phase:'history'" in page
    assert "X-Elmer-Action':'rig-control'" in page
    assert 'id="elmerActivityButton"' in page
    assert 'id="elmerActivityDialog"' in page
    assert 'function loadElmerActivity()' in page
    assert 'live_context:liveContext' in page
    assert 'requestedCallbookDetails' in page
    assert "include.push('email')" in page
    assert "include.push('website')" in page
    assert "include.push('qsl')" in page
    assert "include.push('details')" in page
    assert '|expiration|' in page
    assert "host==='cdn-bio.qrz.com'" in page
    assert "host==='cdn-xml.qrz.com'" in page
    assert "host==='www.hamqth.com'" in page
    assert 'referrerpolicy="no-referrer"' in page
    assert 'id="elmerVoice"' in page
    assert 'id="elmerVoiceAsk"' in page and 'id="elmerWake"' in page
    assert 'id="elmerSpeak"' in page and 'id="elmerStopSpeech"' in page
    assert '/css/elmer_voice.css?v=20260815-4' in page
    assert '/js/elmer_voice.js?v=20260819-2' in page
    assert not patch(target)

voice_js=(ROOT/'web'/'elmer_voice.js').read_text(encoding='utf-8')
assert 'SpeechRecognition' in voice_js and 'webkitSpeechRecognition' in voice_js
assert 'hola|oye|hallo|hej' in voice_js and '你好|嗨|こんにちは' in voice_js
assert 'Starting the microphone' in voice_js
assert "getUserMedia({audio: true})" in voice_js
assert "window.isSecureContext" in voice_js
assert "id = 'elmerVoiceCheck'" in voice_js
assert "Copy report" in voice_js
assert "Ready for Ask by voice and Hey Elmer" in voice_js
assert "Français (France)" in voice_js and "Français (Canada)" in voice_js
assert "Español" in voice_js and "Deutsch" in voice_js and "Svenska" in voice_js
assert "['ru-RU', 'Русский']" in voice_js and "['uk-UA', 'Українська']" in voice_js
assert "['pl-PL', 'Polski']" in voice_js and "['cs-CZ', 'Čeština']" in voice_js
assert "['nb-NO', 'Norsk bokmål']" in voice_js
assert "['da-DK', 'Dansk']" in voice_js and "['fi-FI', 'Suomi']" in voice_js
assert 'привет|эй|здравствуй|привіт|гей|слухай' in voice_js
assert 'cześć|halo|ahoj|moi' in voice_js
assert "日本語" in voice_js and "中文（简体）" in voice_js
assert "elmer-query-plan" in voice_js
assert "localStorage.getItem('elmerVoiceLanguage')" in voice_js
assert "form.requestSubmit()" in voice_js
assert "speechSynthesis.speak" in voice_js
assert "localStorage.getItem('elmerSpeakAnswers')" in voice_js

control_js=(ROOT/'web'/'elmer_control.js').read_text(encoding='utf-8')
assert 'привет|эй|здравствуй|привіт|гей|слухай' in control_js
assert 'радио|радіо|ригпи|рігпі' in control_js
assert 'radio|rádio' in control_js

answer_source=(ROOT/'answer_knowledge.py').read_text(encoding='utf-8')
assert "Answer in the language used by the user's question" in answer_source

callbook_source=(ROOT/'deploy'/'ElmerCallbook.php').read_text(encoding='utf-8')
assert "getUserField($username, 'hamqthUser')" in callbook_source
assert "$isHamqth ? 'HamQTH XML'" in callbook_source
assert "https://www.hamqth.com/xml_bio.php" in callbook_source
assert "https://www.hamqth.com/" in callbook_source

plan=normalize_plan({
    'language_code':'sv','language_name':'Swedish','response_locale':'sv-SE',
    'english_search_query':'Show a photograph and biography of SM5VFE',
    'callbook':{'enabled':True,'call':'sm5vfe','provider':'auto','include':['image','biography']},
    'fcc_search':{'enabled':False,'call':'','postal_code':'','center':'user','radius_miles':-1,'limit':25},
},'Visa SM5VFE')
assert plan['response_locale']=='sv-SE'
assert plan['callbook']['call']=='SM5VFE'
assert plan['callbook']['include']==['image','biography']
assert plan['shortwave_search']['enabled'] is False

sw_plan=normalize_plan({
    'language_code':'es','language_name':'Spanish','response_locale':'es-ES',
    'english_search_query':'Show shortwave stations broadcasting in Russian now',
    'callbook':{'enabled':False,'call':'','provider':'auto','include':[]},
    'fcc_search':{'enabled':False,'call':'','postal_code':'','center':'user','radius_miles':-1,'limit':25},
    'shortwave_search':{'enabled':True,'language':'Russian','station':'','active_now':True,'limit':50},
    'rig_control':{'enabled':False,'action':'none','requested_capability':'none','radio':0,
                   'frequency_hz':0,'mode':'','bandwidth_hz':0},
},'Emisoras en ruso')
assert sw_plan['shortwave_search']=={
    'enabled':True,'language':'Russian','station':'','active_now':True,'limit':50}

php=(ROOT/'deploy'/'ElmerCallbook.php').read_text(encoding='utf-8')
assert "$_SESSION['myUsername']" in php
assert "getUserField($username, 'qrzPWD')" in php
assert "getCallbookFunc($call, $requestedProvider, $user)" in php
assert "$provider === 'fcc'" in php
assert "in_array('address', $include, true)" in php
assert "in_array('biography', $include, true)" in php
assert "in_array('image', $include, true)" in php
assert "in_array('email', $include, true)" in php
assert "in_array('website', $include, true)" in php
assert "in_array('qsl', $include, true)" in php
assert "in_array('details', $include, true)" in php
assert "'callsign' => $lookupCall" in php
assert "'license_expires'" in php and "'previous_call'" in php
assert "getBio($key, $call, $user, $qrzUser, $db)" in php
assert "getKey($qrzUser, $qrzPassword, $db, $user)" in php
assert "preg_replace('#^http://#i', 'https://', $image)" in php
assert "(?:cdn-bio|cdn-xml|files|static)\\.qrz\\.com" in php
assert "$result['email']" in php and "FILTER_VALIDATE_EMAIL" in php
assert 'qrzPWD' not in php.split("getUserField($username, 'qrzPWD')",1)[1]

fcc_php=(ROOT/'deploy'/'ElmerFCC.php').read_text(encoding='utf-8')
assert "$_SESSION['myUsername']" in fcc_php
assert 'fcc_amateur.hd' in fcc_php and 'fcc_amateur.en' in fcc_php
assert 'fcc_amateur.am' in fcc_php and 'former_call' in fcc_php
assert 'hd.status=' in fcc_php and "\\'A\\'" in fcc_php
assert 'distance_basis' in fcc_php and 'interpolated' in fcc_php
assert "$call = strtoupper" in fcc_php
assert "$needsGeocoding = $radius !== null || $call !== ''" in fcc_php
assert "hd.callsign=? LIMIT 1" in fcc_php
assert "'map'" in fcc_php and "'center_latitude'" in fcc_php
assert "$clubsOnly = !empty($request['clubs_only'])" in fcc_php
assert "UPPER(en.full_name) LIKE '%CLUB%'" in fcc_php
assert "UPPER(en.full_name) LIKE '%GROUP%'" in fcc_php
assert "UPPER(en.full_name) LIKE '%ASSOCIATION%'" in fcc_php
assert "Results are limited to active FCC license names" in fcc_php
assert "elseif ($postal !== '' && $radius === null)" in fcc_php
assert '$zipCentroidMatches' in fcc_php
assert 'club records without usable addresses use mailing ZIP centroids' in fcc_php
assert "' AND ' . $distanceSql . '<=?" in fcc_php
assert "'HAVING ' . $distanceSql" not in fcc_php
assert 'if ($needsGeocoding && $candidates && !$clubsOnly)' in fcc_php
assert 'zip_latitude' in fcc_php and "bind_param('dddddddd'" in fcc_php

shortwave_php=(ROOT/'deploy'/'ElmerShortwave.php').read_text(encoding='utf-8')
assert "$_SESSION['myUsername']" in shortwave_php
assert '/home/pi/Elmer/cache/ilgradio/schedules.csv' in shortwave_php
assert '/home/pi/Elmer/cache/eibi/schedules.csv' in shortwave_php
assert 'elmerSwActive' in shortwave_php and 'elmerSwDayActive' in shortwave_php
assert 'ILGRadio with EiBi fallback' in shortwave_php
assert "$offset = min(1000" in fcc_php
assert "$_SESSION['elmer_fcc_club_cache']" in fcc_php
assert "'expires_at' => time() + 300" in fcc_php
assert 'array_slice($evaluated, $offset, $limit)' in fcc_php

rig_php=(ROOT/'deploy'/'ElmerRigControl.php').read_text(encoding='utf-8')
assert "Access_Level" in rig_php and "!== 1" in rig_php
assert "HTTP_X_ELMER_ACTION" in rig_php and "same-origin" in rig_php
assert "10000000" in rig_php and "['AM', '-1']" in rig_php
assert "PTTIn" in rig_php and "PTTOut" in rig_php
assert "elmer_rig_confirmation" in rig_php and "time() + 120" in rig_php
assert "abs($verifiedFrequency - 10000000)" in rig_php
assert "ElmerRigActions.php" in rig_php
assert "elmerCreateActionHistory" in rig_php
assert "elmerFinishActionHistory" in rig_php
assert "phase === 'history'" in rig_php and "phase === 'cancel'" in rig_php

actions_php=(ROOT/'deploy'/'ElmerRigActions.php').read_text(encoding='utf-8')
assert "'read_only'" in actions_php
assert "'receive_control'" in actions_php
assert "'station_change'" in actions_php
assert "'transmit_capable'" in actions_php
assert "'tune_wwv_10mhz'" in actions_php
assert "'may_transmit' => true" in actions_php
assert "'access_levels' => []" in actions_php
assert "ElmerActionHistory" in actions_php
assert "pending_confirmation" in actions_php
assert "['complete', 'failed', 'cancelled']" in actions_php
assert "'radius_applied'" in fcc_php and '$radiusApplied' in fcc_php
assert "'address_line'" not in fcc_php

geocoder_php=(ROOT/'deploy'/'ElmerFCCGeocode.php').read_text(encoding='utf-8')
assert 'ElmerFCCGeocode' in geocoder_php
assert 'geocoding.geo.census.gov/geocoder/locations/addressbatch' in geocoder_php
assert 'Public_AR_Current' in geocoder_php
assert 'address_hash' in geocoder_php

print('Elmer live connector tests passed')
