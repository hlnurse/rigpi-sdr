"""Bounded, fresh snapshots read from the existing RigPi System Status endpoint."""
import json
from datetime import datetime, timezone

SOURCE = 'RigPi System Status'

def normalize_system_status(raw):
    fields = {'schema_version','source','retrieved_at','sections','health'}
    if not isinstance(raw,dict) or set(raw)!=fields or type(raw['schema_version']) is not int or raw['schema_version']!=1 or raw['source']!=SOURCE:
        raise ValueError('Live System Status has an invalid format.')
    if len(json.dumps(raw,ensure_ascii=False))>120000:
        raise ValueError('Live System Status is too large.')
    stamp=raw['retrieved_at']
    try:
        if not isinstance(stamp,str) or len(stamp)>40:raise ValueError()
        age=(datetime.now(timezone.utc)-datetime.fromisoformat(stamp.replace('Z','+00:00'))).total_seconds()
        if not -60<=age<=300:raise ValueError()
    except (TypeError,ValueError):
        raise ValueError('Live System Status is invalid or stale.') from None
    def text(value,limit):
        if not isinstance(value,str) or len(value)>limit:raise ValueError('Live System Status text is invalid.')
        return value
    sections=raw['sections']
    if not isinstance(sections,list) or not 1<=len(sections)<=32:raise ValueError('Live System Status sections are invalid.')
    for section in sections:
        if not isinstance(section,dict) or set(section)!={'title','note','entries'}:raise ValueError('Live System Status section is invalid.')
        text(section['title'],120);text(section['note'],2000)
        entries=section['entries']
        if not isinstance(entries,list) or len(entries)>128:raise ValueError('Live System Status entries are invalid.')
        for entry in entries:
            if not isinstance(entry,dict) or set(entry)!={'title','rows'}:raise ValueError('Live System Status entry is invalid.')
            text(entry['title'],160)
            rows=entry['rows']
            if not isinstance(rows,list) or len(rows)>80:raise ValueError('Live System Status rows are invalid.')
            for row in rows:
                if not isinstance(row,list) or len(row)!=2:raise ValueError('Live System Status row is invalid.')
                text(row[0],200);text(row[1],3000)
    health=raw['health']
    if not isinstance(health,list) or len(health)>80:raise ValueError('Live System Status health checks are invalid.')
    for check in health:
        if not isinstance(check,dict) or set(check)!={'key','level','label','message','section','row','hold'}:raise ValueError('Live System Status health check is invalid.')
        if check['level'] not in ('green','yellow','red','unknown'):raise ValueError('Live System Status health level is invalid.')
        for name,limit in [('key',160),('label',160),('message',2000),('section',120),('row',200)]:text(check[name],limit)
        if type(check['hold']) is not int or not 0<=check['hold']<=600:raise ValueError('Live System Status observation time is invalid.')
    return json.loads(json.dumps(raw,ensure_ascii=False))
