#!/usr/bin/env python3
"""Validation for small, station-supplied live data packets."""

import re


CALLSIGN_RE=re.compile(
    r'^(?:[A-Z0-9]{1,3}/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:/[A-Z0-9]{1,4})?$')
TEXT_LIMITS={
    'call':24,'name':120,'city':120,'state':80,'country':120,'grid':16,
    'entity':120,'dxcc':16,'cq_zone':8,'itu_zone':8,'wpx_prefix':16,
    'license_class':40,'distance_miles':24,'distance_km':24,'bearing_degrees':24,
    'address_line':180,'postal_code':24,'county':120,'biography':6000,
    'image_url':500,'email':254,'website':500,'aliases':120,'previous_call':24,
    'nickname':120,'formatted_name':160,'license_effective':24,'license_expires':24,'iota':20,
    'qsl_manager':120,'lotw':16,'eqsl':16,'paper_qsl':16,
    'time_zone':80,'gmt_offset':16,'daylight_saving':16,'club':200,
    'provider':80,'retrieved_at':40,'reference':300,
}


def normalize_live_context(value):
    """Return a canonical live-context object or raise ValueError."""
    if value in (None,{}):
        return None
    if not isinstance(value,dict) or set(value)-{'callbook','country_profile','fcc_search','shortwave_search','weather','rig_control','logbook_history','exam_question'}:
        raise ValueError('Live connector data has an invalid format.')
    result={}
    if 'callbook' in value:
        result.update(_normalize_callbook(value['callbook']))
    if 'country_profile' in value:
        result.update(_normalize_country_profile(value['country_profile']))
    if 'fcc_search' in value:
        result.update(_normalize_fcc_search(value['fcc_search']))
    if 'shortwave_search' in value:
        result.update(_normalize_shortwave_search(value['shortwave_search']))
    if 'weather' in value:
        result.update(_normalize_weather(value['weather']))
    if 'rig_control' in value:
        result.update(_normalize_rig_control(value['rig_control']))
    if 'logbook_history' in value:
        result.update(_normalize_logbook_history(value['logbook_history']))
    if 'exam_question' in value:
        result.update(_normalize_exam_question(value['exam_question']))
    if not result:
        raise ValueError('Live connector data has an invalid format.')
    return result


def _normalize_exam_question(raw):
    allowed={'schema_version','license_class','element','pool_title','effective_from','effective_to',
             'errata_date','provider','source_url','notice','question_id','group','topic','question',
             'choices','correct_letter','selected_letter','result','fcc_reference','figure_label'}
    if not isinstance(raw,dict) or set(raw)!=allowed:
        raise ValueError('Live exam question has an invalid format.')
    if raw.get('schema_version')!=1:
        raise ValueError('Live exam question has an invalid schema version.')
    license_class=str(raw.get('license_class','')).strip().lower()
    class_fields={'technician':('T',2),'general':('G',3),'extra':('E',4)}
    if license_class not in class_fields or raw.get('element')!=class_fields[license_class][1]:
        raise ValueError('Live exam question has an invalid license class.')
    question_id=str(raw.get('question_id','')).strip().upper()
    if not re.fullmatch(class_fields[license_class][0]+r'\d[A-Z]\d{2}',question_id):
        raise ValueError('Live exam question has an invalid question identifier.')
    choices=raw.get('choices')
    if not isinstance(choices,list) or len(choices)!=4 or any(not isinstance(item,str) or not item.strip() or len(item)>500 for item in choices):
        raise ValueError('Live exam question has invalid answer choices.')
    correct=str(raw.get('correct_letter','')).strip().upper()
    selected=str(raw.get('selected_letter','')).strip().upper()
    result_value=str(raw.get('result','')).strip().lower()
    if correct not in 'ABCD' or selected not in 'ABCD' or result_value not in ('correct','incorrect') or (selected==correct)!=(result_value=='correct'):
        raise ValueError('Live exam question has an invalid answer result.')
    limits={'pool_title':160,'effective_from':10,'effective_to':10,'errata_date':10,'notice':400,
            'group':8,'topic':500,'question':1000,'fcc_reference':100,'figure_label':40}
    clean={}
    for field,limit in limits.items():
        value=str(raw.get(field,'')).strip()
        if len(value)>limit or (field not in ('fcc_reference','figure_label') and not value):
            raise ValueError(f'Live exam question field {field} is invalid.')
        clean[field]=value
    if raw.get('provider')!='NCVEC Question Pool Committee':
        raise ValueError('Live exam question provider is invalid.')
    source_url=str(raw.get('source_url','')).strip()
    if not re.fullmatch(r'https://(?:www\.)?ncvec\.org/downloads/[A-Za-z0-9%._+\-]+\.docx',source_url):
        raise ValueError('Live exam question reference is invalid.')
    return {'exam_question':{**clean,'schema_version':1,'license_class':license_class,
            'element':class_fields[license_class][1],'provider':'NCVEC Question Pool Committee',
            'source_url':source_url,'question_id':question_id,'choices':[item.strip() for item in choices],
            'correct_letter':correct,'selected_letter':selected,'result':result_value}}


def _normalize_logbook_history(raw):
    allowed={'call','contacts','worked_before','latest','log_scope','provider','retrieved_at','notice'}
    if not isinstance(raw,dict) or set(raw)-allowed:
        raise ValueError('Live logbook history has an invalid format.')
    call=str(raw.get('call','')).strip().upper()
    if not CALLSIGN_RE.fullmatch(call):
        raise ValueError('Live logbook history contains an invalid callsign.')
    contacts=raw.get('contacts')
    if isinstance(contacts,bool) or not isinstance(contacts,int) or not 0<=contacts<=1000000:
        raise ValueError('Live logbook history contains an invalid contact count.')
    worked=raw.get('worked_before')
    if not isinstance(worked,bool) or worked != (contacts > 0):
        raise ValueError('Live logbook history contains an invalid worked status.')
    result={'call':call,'contacts':contacts,'worked_before':worked}
    for field,limit in {'log_scope':80,'provider':80,'retrieved_at':40,'notice':240}.items():
        text=str(raw.get(field,'')).strip()
        if len(text)>limit: raise ValueError(f'Live logbook field {field} is too long.')
        if text: result[field]=text
    if result.get('provider')!='RigPi local logbook':
        raise ValueError('Live logbook provider is invalid.')
    latest=raw.get('latest',{})
    if not isinstance(latest,dict) or set(latest)-{'date','date_iso','band','mode'}:
        raise ValueError('Live logbook latest contact has an invalid format.')
    clean={}
    for field,limit in {'date':16,'date_iso':16,'band':12,'mode':16}.items():
        text=str(latest.get(field,'')).strip()
        if len(text)>limit: raise ValueError(f'Live logbook field {field} is too long.')
        if text: clean[field]=text
    if contacts == 0 and clean:
        raise ValueError('Live logbook history contains an unexpected latest contact.')
    if clean.get('date_iso') and not re.fullmatch(r'\d{4}-\d{2}-\d{2}',clean['date_iso']):
        raise ValueError('Live logbook history contains an invalid date.')
    result['latest']=clean
    return {'logbook_history':result}


WX_TEXT_LIMITS={'provider':40,'retrieved_at':40,'reference':100,'attribution':120,
                'location':200,'location_basis':120,'target':12,'call':24,'timezone':80,
                'data_kind':24,'historical_date':16,'temperature_unit':8,
                'wind_speed_unit':12,'precipitation_unit':8,'visibility_unit':8,
                'pressure_unit':8,'cape_unit':8}
WX_CURRENT_TEXT={'time':32,'condition':80}
WX_CURRENT_NUMERIC={'weather_code':(0,99),'temperature':(-150,150),
                    'apparent_temperature':(-150,150),'relative_humidity':(0,100),
                    'precipitation':(0,10000),'wind_speed':(0,1000),
                    'wind_direction':(0,360),'wind_gusts':(0,1000),'is_day':(0,1),
                    'pressure_msl':(800,1200),'cloud_cover':(0,100),'visibility':(0,1000000)}
WX_DAILY_TEXT={'date':16,'condition':80,'sunrise':32,'sunset':32}
WX_DAILY_NUMERIC={'weather_code':(0,99),'temperature_max':(-150,150),
                  'temperature_min':(-150,150),'precipitation_probability':(0,100),
                  'precipitation':(0,10000),'wind_speed_max':(0,1000),'wind_gusts_max':(0,1000)}
WX_HOURLY_TEXT={'time':32,'condition':80}
WX_HOURLY_NUMERIC={'weather_code':(0,99),'temperature':(-150,150),
                   'apparent_temperature':(-150,150),'precipitation_probability':(0,100),
                   'precipitation':(0,10000),'pressure_msl':(800,1200),'cloud_cover':(0,100),
                   'visibility':(0,1000000),'wind_speed':(0,1000),'wind_direction':(0,360),
                   'wind_gusts':(0,1000),'cape':(0,100000)}
WX_SUMMARY_TEXT={'period_start':32,'period_end':32,'notice':300}
WX_SUMMARY_NUMERIC={'temperature_min':(-150,150),'temperature_max':(-150,150),
                    'precipitation_total':(0,10000),'precipitation_probability_max':(0,100),
                    'wind_speed_max':(0,1000),'wind_gusts_max':(0,1000),
                    'visibility_min':(0,1000000),'pressure_min':(800,1200),
                    'pressure_max':(800,1200),'cape_max':(0,100000)}


def _weather_number(value,field,bounds):
    if value is None: return None
    if isinstance(value,bool) or not isinstance(value,(int,float)):
        raise ValueError(f'Live weather field {field} has an invalid value.')
    number=float(value)
    if not bounds[0]<=number<=bounds[1]:
        raise ValueError(f'Live weather field {field} has an invalid value.')
    return int(number) if number.is_integer() else number


def _normalize_weather(raw):
    allowed=set(WX_TEXT_LIMITS)|{'current','daily','hourly','operating_summary'}
    if not isinstance(raw,dict) or set(raw)-allowed:
        raise ValueError('Live weather data has an invalid format.')
    result={}
    for field,limit in WX_TEXT_LIMITS.items():
        value=raw.get(field)
        if value is None: continue
        if isinstance(value,(dict,list,bool)):
            raise ValueError(f'Live weather field {field} has an invalid value.')
        text=str(value).strip()
        if len(text)>limit:
            raise ValueError(f'Live weather field {field} is too long.')
        if text: result[field]=text
    if (result.get('provider')!='Open-Meteo' or result.get('reference') not in
            ('https://open-meteo.com/','https://open-meteo.com/en/docs/historical-weather-api')):
        raise ValueError('Live weather provider is invalid.')
    call=result.get('call','').upper()
    if call and (not CALLSIGN_RE.fullmatch(call) or re.fullmatch(r'\d+(?:HZ|KHZ|MHZ|GHZ)',call)):
        raise ValueError('Live weather callsign is invalid.')
    if call: result['call']=call
    if result.get('target') not in ('user','call','place'):
        raise ValueError('Live weather target is invalid.')
    kind=result.get('data_kind','forecast')
    if kind not in ('forecast','historical_reanalysis'):
        raise ValueError('Live weather data kind is invalid.')
    if kind=='historical_reanalysis' and not re.fullmatch(r'\d{4}-\d{2}-\d{2}',result.get('historical_date','')):
        raise ValueError('Live historical weather date is invalid.')
    current=raw.get('current')
    if current is not None:
        if not isinstance(current,dict) or set(current)-(set(WX_CURRENT_TEXT)|set(WX_CURRENT_NUMERIC)):
            raise ValueError('Live current weather has an invalid format.')
        clean_current={}
        for field,limit in WX_CURRENT_TEXT.items():
            text=str(current.get(field,'')).strip()
            if len(text)>limit: raise ValueError(f'Live weather field {field} is too long.')
            if text: clean_current[field]=text
        for field,bounds in WX_CURRENT_NUMERIC.items():
            number=_weather_number(current.get(field),field,bounds)
            if number is not None: clean_current[field]=number
        if not clean_current.get('condition'):
            raise ValueError('Live current weather has no condition.')
        result['current']=clean_current
    daily=raw.get('daily')
    if daily is not None:
        if not isinstance(daily,list) or not 1<=len(daily)<=7:
            raise ValueError('Live weather forecast has an invalid format.')
        clean_daily=[]
        for row in daily:
            if not isinstance(row,dict) or set(row)-(set(WX_DAILY_TEXT)|set(WX_DAILY_NUMERIC)):
                raise ValueError('A live daily forecast has an invalid format.')
            item={}
            for field,limit in WX_DAILY_TEXT.items():
                text=str(row.get(field,'')).strip()
                if len(text)>limit: raise ValueError(f'Live weather field {field} is too long.')
                if text: item[field]=text
            for field,bounds in WX_DAILY_NUMERIC.items():
                number=_weather_number(row.get(field),field,bounds)
                if number is not None: item[field]=number
            if not item.get('date') or not item.get('condition'):
                raise ValueError('A live daily forecast is incomplete.')
            clean_daily.append(item)
        result['daily']=clean_daily
    hourly=raw.get('hourly')
    if hourly is not None:
        if not isinstance(hourly,list) or not 1<=len(hourly)<=48:
            raise ValueError('Live hourly weather has an invalid format.')
        clean_hourly=[]
        for row in hourly:
            if not isinstance(row,dict) or set(row)-(set(WX_HOURLY_TEXT)|set(WX_HOURLY_NUMERIC)):
                raise ValueError('A live hourly weather row has an invalid format.')
            item={}
            for field,limit in WX_HOURLY_TEXT.items():
                text=str(row.get(field,'')).strip()
                if len(text)>limit: raise ValueError(f'Live weather field {field} is too long.')
                if text: item[field]=text
            for field,bounds in WX_HOURLY_NUMERIC.items():
                number=_weather_number(row.get(field),field,bounds)
                if number is not None: item[field]=number
            if not item.get('time') or not item.get('condition'):
                raise ValueError('A live hourly weather row is incomplete.')
            clean_hourly.append(item)
        result['hourly']=clean_hourly
    summary=raw.get('operating_summary')
    if summary is not None:
        if not isinstance(summary,dict) or set(summary)-(set(WX_SUMMARY_TEXT)|set(WX_SUMMARY_NUMERIC)|{'thunderstorm_code_present'}):
            raise ValueError('Live operating-weather summary has an invalid format.')
        clean_summary={}
        for field,limit in WX_SUMMARY_TEXT.items():
            text=str(summary.get(field,'')).strip()
            if len(text)>limit: raise ValueError(f'Live weather field {field} is too long.')
            if text: clean_summary[field]=text
        for field,bounds in WX_SUMMARY_NUMERIC.items():
            number=_weather_number(summary.get(field),field,bounds)
            if number is not None: clean_summary[field]=number
        thunder=summary.get('thunderstorm_code_present')
        if not isinstance(thunder,bool): raise ValueError('Live thunderstorm indicator is invalid.')
        clean_summary['thunderstorm_code_present']=thunder
        result['operating_summary']=clean_summary
    if kind=='forecast' and ('current' not in result or 'daily' not in result):
        raise ValueError('Live weather forecast is incomplete.')
    if kind=='historical_reanalysis' and ('hourly' not in result or 'operating_summary' not in result):
        raise ValueError('Live historical weather is incomplete.')
    return {'weather':result}


SW_TEXT_LIMITS={
    'provider':80,'retrieved_at':40,'database_date':20,'language_filter':40,
    'station_filter':80,'notice':240,'reference':300,
}
SW_RESULT_LIMITS={
    'source':20,'station':160,'language':80,'time_utc':9,'days':24,
    'country':40,'target':80,'transmitter':100,'status':12,'monitored':8,
    'mode':12,'power_kw':20,
}


def _normalize_shortwave_search(raw):
    allowed=set(SW_TEXT_LIMITS)|{'active_now','total_matches','returned','cache_records','results'}
    if not isinstance(raw,dict) or set(raw)-allowed:
        raise ValueError('Live shortwave search data has an invalid format.')
    result={}
    for field,limit in SW_TEXT_LIMITS.items():
        item=raw.get(field)
        if item is None: continue
        if isinstance(item,(dict,list,bool)):
            raise ValueError(f'Live shortwave search field {field} has an invalid value.')
        text=str(item).strip()
        if len(text)>limit:
            raise ValueError(f'Live shortwave search field {field} is too long.')
        if text: result[field]=text
    reference=result.get('reference','')
    if reference and reference not in ('https://www.ilgradio.com/','https://www.eibispace.de/'):
        raise ValueError('Live shortwave search reference is invalid.')
    active=raw.get('active_now')
    if not isinstance(active,bool):
        raise ValueError('Live shortwave active-now value is invalid.')
    result['active_now']=active
    for field,maximum in (('total_matches',100000),('returned',50),('cache_records',1000000)):
        item=raw.get(field)
        if isinstance(item,bool) or not isinstance(item,int) or item<0 or item>maximum:
            raise ValueError(f'Live shortwave search field {field} has an invalid value.')
        result[field]=item
    rows=raw.get('results')
    if not isinstance(rows,list) or len(rows)>50:
        raise ValueError('Live shortwave search results have an invalid format.')
    clean=[]
    for row in rows:
        if not isinstance(row,dict) or set(row)-(set(SW_RESULT_LIMITS)|{'frequency_hz'}):
            raise ValueError('A live shortwave search result has an invalid format.')
        frequency=row.get('frequency_hz')
        if isinstance(frequency,bool) or not isinstance(frequency,int) or not 100000<=frequency<=30000000:
            raise ValueError('A live shortwave result contains an invalid frequency.')
        item={'frequency_hz':frequency}
        for field,limit in SW_RESULT_LIMITS.items():
            text=str(row.get(field,'')).strip()
            if len(text)>limit:
                raise ValueError(f'Live shortwave result field {field} is too long.')
            if text: item[field]=text
        if not item.get('station'):
            raise ValueError('A live shortwave result has no station name.')
        clean.append(item)
    result['results']=clean
    result['returned']=len(clean)
    return {'shortwave_search':result}


def _normalize_rig_control(raw):
    allowed={'status','action','radio','requested_frequency_hz','verified_frequency_hz',
             'requested_mode','verified_mode','requested_bandwidth_hz',
             'verified_bandwidth_hz','previous_state','message','performed_at'}
    if not isinstance(raw,dict) or set(raw)-allowed:
        raise ValueError('Live RigPi control data has an invalid format.')
    status=str(raw.get('status','')).strip().lower()
    if status not in ('complete','cancelled'):
        raise ValueError('Live RigPi control status is invalid.')
    action=str(raw.get('action','')).strip()
    if action not in ('tune_wwv_10mhz','receiver.tune','receiver.restore'):
        raise ValueError('Live RigPi control action is invalid.')
    radio=raw.get('radio')
    if isinstance(radio,bool) or not isinstance(radio,int) or radio<1 or radio>4:
        raise ValueError('Live RigPi control radio is invalid.')
    requested=raw.get('requested_frequency_hz')
    if (isinstance(requested,bool) or not isinstance(requested,int)
            or requested<1000 or requested>6000000000):
        raise ValueError('Live RigPi control frequency is invalid.')
    verified=raw.get('verified_frequency_hz')
    if verified is not None and (isinstance(verified,bool) or not isinstance(verified,int)
                                 or verified<1000000 or verified>6000000000):
        raise ValueError('Live RigPi control verification is invalid.')
    requested_mode=str(raw.get('requested_mode','')).strip().upper()
    verified_mode=str(raw.get('verified_mode','')).strip().upper()
    modes={'AM','AMS','CW','CWR','DSB','ECSSLSB','ECSSUSB','FAX','FM','LSB',
           'PKTFM','PKTLSB','PKTUSB','RTTY','RTTYR','SAH','SAL','SAM','USB','WFM'}
    if requested_mode not in modes or (verified_mode and verified_mode not in modes):
        raise ValueError('Live RigPi control mode is invalid.')
    if action=='tune_wwv_10mhz' and (requested!=10000000 or requested_mode!='AM'):
        raise ValueError('Live RigPi WWV control data is invalid.')
    requested_width=_normalize_optional_bandwidth(raw.get('requested_bandwidth_hz'))
    verified_width=_normalize_optional_bandwidth(raw.get('verified_bandwidth_hz'))
    previous=_normalize_receiver_state(raw.get('previous_state'))
    message=str(raw.get('message','')).strip()
    performed_at=str(raw.get('performed_at','')).strip()
    if len(message)>240 or len(performed_at)>40:
        raise ValueError('Live RigPi control data is too long.')
    result={'status':status,'action':action,'radio':radio,
            'requested_frequency_hz':requested,'requested_mode':requested_mode}
    if verified is not None: result['verified_frequency_hz']=verified
    if verified_mode: result['verified_mode']=verified_mode
    if requested_width is not None: result['requested_bandwidth_hz']=requested_width
    if verified_width is not None: result['verified_bandwidth_hz']=verified_width
    if previous is not None: result['previous_state']=previous
    if message: result['message']=message
    if performed_at: result['performed_at']=performed_at
    return {'rig_control':result}


def _normalize_optional_bandwidth(value):
    if value is None:
        return None
    if isinstance(value,bool) or not isinstance(value,int) or value<50 or value>1000000:
        raise ValueError('Live RigPi control bandwidth is invalid.')
    return value


def _normalize_receiver_state(value):
    if value is None:
        return None
    if not isinstance(value,dict) or set(value)-{'frequency_hz','mode','bandwidth_hz'}:
        raise ValueError('Live RigPi previous receiver state is invalid.')
    frequency=value.get('frequency_hz')
    mode=str(value.get('mode','')).strip().upper()
    if (isinstance(frequency,bool) or not isinstance(frequency,int)
            or frequency<1000 or frequency>6000000000
            or not re.fullmatch(r'[A-Z][A-Z0-9-]{0,11}',mode)):
        raise ValueError('Live RigPi previous receiver state is invalid.')
    bandwidth=_normalize_optional_bandwidth(value.get('bandwidth_hz'))
    result={'frequency_hz':frequency,'mode':mode}
    if bandwidth is not None: result['bandwidth_hz']=bandwidth
    return result


def _normalize_callbook(raw):
    if not isinstance(raw,dict):
        raise ValueError('Live callbook data has an invalid format.')
    if set(raw)-set(TEXT_LIMITS):
        raise ValueError('Live callbook data contains unsupported fields.')
    result={}
    for field,limit in TEXT_LIMITS.items():
        item=raw.get(field)
        if item is None:
            continue
        if isinstance(item,(dict,list,bool)):
            raise ValueError(f'Live callbook field {field} has an invalid value.')
        text=str(item).strip()
        if len(text)>limit:
            raise ValueError(f'Live callbook field {field} is too long.')
        if text:
            result[field]=text
    call=result.get('call','').upper()
    if (not call or not CALLSIGN_RE.fullmatch(call)
            or re.fullmatch(r'\d+(?:HZ|KHZ|MHZ|GHZ)',call)):
        raise ValueError('Live callbook data contains an invalid callsign.')
    result['call']=call
    reference=result.get('reference','')
    if reference and not (re.fullmatch(r'https://www\.qrz\.com/db/[A-Z0-9/]+',reference)
                          or re.fullmatch(r'https://www\.hamqth\.com/[A-Z0-9/]+',reference)):
        raise ValueError('Live callbook data contains an invalid reference.')
    image_url=result.get('image_url','')
    if image_url and not re.fullmatch(
            r"https://(?:(?:cdn-bio|cdn-xml|files|static)\.qrz\.com|(?:www\.)?hamqth\.com)/[A-Za-z0-9._~!$&'()*+,;=:@%/?-]+",image_url):
        raise ValueError('Live callbook data contains an invalid image reference.')
    email=result.get('email','')
    if email and not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',email):
        raise ValueError('Live callbook data contains an invalid email address.')
    website=result.get('website','')
    if website and not re.fullmatch(r'https?://[^\s<>]+',website):
        raise ValueError('Live callbook data contains an invalid website reference.')
    return {'callbook':result}


def _normalize_country_profile(raw):
    text_limits={
        'country':120,'official_name':180,'slug':100,'flag':16,'iso2':2,'iso3':3,
        'region':80,'subregion':100,'capital':120,'introduction':1800,
        'location':1200,'climate':1200,'terrain':1200,'languages':800,
        'government_type':500,'data_updated_at':40,'retrieved_at':40,
        'provider':80,'reference':300,'notice':400,'station_marker_basis':80,
        'capital_distance_basis':240,
    }
    allowed={'schema_version','population','area_km2','capital_distance_km','capital_distance_miles','station_marker_available'}|set(text_limits)
    if not isinstance(raw,dict) or set(raw)-allowed or raw.get('schema_version')!=1:
        raise ValueError('Live country profile has an invalid format.')
    result={'schema_version':1}
    for field,limit in text_limits.items():
        value=raw.get(field)
        if value is None: continue
        if isinstance(value,(dict,list,bool)):
            raise ValueError(f'Live country field {field} has an invalid value.')
        text=str(value).strip()
        if len(text)>limit:
            raise ValueError(f'Live country field {field} is too long.')
        if text: result[field]=text
    if not result.get('country') or result.get('provider')!='WorldFactbook.io':
        raise ValueError('Live country profile has invalid identity fields.')
    if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*',result.get('slug','')):
        raise ValueError('Live country profile has an invalid slug.')
    if result.get('iso2') and not re.fullmatch(r'[A-Z]{2}',result['iso2']):
        raise ValueError('Live country profile has an invalid ISO code.')
    if result.get('iso3') and not re.fullmatch(r'[A-Z]{3}',result['iso3']):
        raise ValueError('Live country profile has an invalid ISO code.')
    if not re.fullmatch(r'https://worldfactbook\.io/countries/[a-z0-9-]+/',result.get('reference','')):
        raise ValueError('Live country profile has an invalid reference.')
    population=raw.get('population')
    if population is not None:
        if isinstance(population,bool) or not isinstance(population,int) or not 0<=population<=20000000000:
            raise ValueError('Live country population is invalid.')
        result['population']=population
    area=raw.get('area_km2')
    if area is not None:
        if isinstance(area,bool) or not isinstance(area,(int,float)) or not 0<=area<=200000000:
            raise ValueError('Live country area is invalid.')
        result['area_km2']=float(area)
    if 'station_marker_available' in raw:
        if raw.get('station_marker_available') is not True:
            raise ValueError('Live country station marker is invalid.')
        result['station_marker_available']=True
    for field,maximum in (('capital_distance_km',25000),('capital_distance_miles',16000)):
        value=raw.get(field)
        if value is not None:
            if isinstance(value,bool) or not isinstance(value,(int,float)) or not 0<=value<=maximum:
                raise ValueError(f'Live country field {field} is invalid.')
            result[field]=float(value)
    return {'country_profile':result}


FCC_TEXT_LIMITS={
    'provider':80,'retrieved_at':40,'postal_code_filter':5,'center':120,
    'distance_basis':220,'notice':300,'geocoder':80,'query_type':40,
    'region_type':40,'region_label':160,'count_basis':320,
}
FCC_RESULT_LIMITS={'call':24,'name':120,'city':120,'state':20,'postal_code':5,
                   'license_class':40,'former_call':24}


def _normalize_fcc_search(raw):
    if not isinstance(raw,dict) or set(raw)-(
            set(FCC_TEXT_LIMITS)|{'radius_miles','radius_applied','total_matches','returned',
                                  'geocode_attempted','geocoded_matches','geocode_unmatched',
                                  'geocode_deferred','active_only','results'}):
        raise ValueError('Live FCC search data has an invalid format.')
    result={}
    for field,limit in FCC_TEXT_LIMITS.items():
        item=raw.get(field)
        if item is None: continue
        if isinstance(item,(dict,list,bool)):
            raise ValueError(f'Live FCC search field {field} has an invalid value.')
        text=str(item).strip()
        if len(text)>limit:
            raise ValueError(f'Live FCC search field {field} is too long.')
        if text: result[field]=text
    for field,maximum in (('radius_miles',250),('total_matches',1000000),('returned',50),
                          ('geocode_attempted',1000),('geocoded_matches',2000),
                          ('geocode_unmatched',2000),('geocode_deferred',2000)):
        item=raw.get(field)
        if item is None: continue
        if isinstance(item,bool) or not isinstance(item,(int,float)) or item<0 or item>maximum:
            raise ValueError(f'Live FCC search field {field} has an invalid value.')
        result[field]=item
    radius_applied=raw.get('radius_applied')
    if radius_applied is not None:
        if not isinstance(radius_applied,bool):
            raise ValueError('Live FCC search field radius_applied has an invalid value.')
        result['radius_applied']=radius_applied
    active_only=raw.get('active_only')
    if active_only is not None:
        if not isinstance(active_only,bool):
            raise ValueError('Live FCC search field active_only has an invalid value.')
        result['active_only']=active_only
    rows=raw.get('results')
    if not isinstance(rows,list) or len(rows)>50:
        raise ValueError('Live FCC search results have an invalid format.')
    clean=[]
    for row in rows:
        if not isinstance(row,dict) or set(row)-(set(FCC_RESULT_LIMITS)|{'distance_miles'}):
            raise ValueError('A live FCC search result has an invalid format.')
        item={}
        for field,limit in FCC_RESULT_LIMITS.items():
            text=str(row.get(field,'')).strip()
            if len(text)>limit: raise ValueError(f'Live FCC result field {field} is too long.')
            if text: item[field]=text
        call=item.get('call','').upper()
        if not call or not CALLSIGN_RE.fullmatch(call):
            raise ValueError('A live FCC search result contains an invalid callsign.')
        item['call']=call
        former=item.get('former_call','')
        if former and not CALLSIGN_RE.fullmatch(former.upper()):
            raise ValueError('A live FCC search result contains an invalid former callsign.')
        if former: item['former_call']=former.upper()
        distance=row.get('distance_miles')
        if isinstance(distance,bool) or not isinstance(distance,(int,float)) or distance<0 or distance>5000:
            raise ValueError('A live FCC search result contains an invalid distance.')
        item['distance_miles']=distance
        clean.append(item)
    result['results']=clean
    result['returned']=len(clean)
    return {'fcc_search':result}
