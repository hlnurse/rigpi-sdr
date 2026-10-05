"""Use installed broadcast station names when the cloud planner misses a lookup."""
import csv
import re
import unicodedata
from pathlib import Path

def _words(value):
    value=unicodedata.normalize('NFKD',str(value)).encode('ascii','ignore').decode().lower()
    value=re.sub(r'\br[.\s]+', 'radio ', value)
    return ' '.join(re.findall(r'[a-z0-9]+',value))

def supplement_shortwave_plan(plan, question, cache=None):
    if not isinstance(plan,dict) or plan.get('shortwave_search',{}).get('enabled'):
        return plan
    text=_words(question)
    if not re.search(r'\b(frequency|frequencies|schedule|shortwave|broadcast|broadcasts)\b',text):
        return plan
    cache=Path(cache) if cache else Path(__file__).parent/'cache'
    matches=[]
    for provider in ('ilgradio','eibi'):
        try:
            with (cache/provider/'schedules.csv').open(encoding='utf-8-sig',newline='') as handle:
                for row in csv.DictReader(handle):
                    station=row.get('station','').strip()
                    name=_words(station)
                    if len(name)>=3 and name not in ('radio','english','test') and (' '+name+' ') in (' '+text+' '):
                        matches.append((len(name),station))
        except (OSError,UnicodeError,csv.Error):
            continue
    if matches:
        station=max(matches)[1]
        plan=dict(plan)
        plan['shortwave_search']={'enabled':True,'station':station,'language':'','active_now':bool(re.search(r'\b(now|currently)\b',text)),'limit':50}
    return plan
