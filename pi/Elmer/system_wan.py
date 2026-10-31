"""On-demand public IPv4 lookup from the RigPi station."""
import ipaddress
import re
from datetime import datetime, timezone
from urllib.request import Request, urlopen
from urllib.error import URLError

PROVIDER = 'https://api.ipify.org'
SOURCE = 'RigPi System window WAN lookup (ipify)'
NOTICE = 'Public IPv4 observed from this RigPi outbound connection; it may be shared by NAT or a VPN and does not establish inbound reachability.'

def public_ipv4(value):
    if not isinstance(value, str):
        return False
    try:
        address = ipaddress.IPv4Address(value)
        return address.is_global and not address.is_multicast
    except ValueError:
        return False

def requested_wan_status(question):
    text = str(question).split('UI OUTPUT REQUIREMENT:', 1)[0].strip()
    wan = re.search(r'\b(?:wan|public|external|internet)\b', text, re.I)
    address = re.search(r'\b(?:ip(?:v4)?|address)\b', text, re.I)
    station = re.search(r'\b(?:rigpi|raspberry|pi)\b', text, re.I)
    owner = re.search(r'\b(?:my|our|this)\b', text, re.I)
    other = re.search(r'\b(?:camera|phone|computer|laptop|browser|cloud\s+server)\b', text, re.I)
    shorthand = re.fullmatch(r'(?:wan|public|external|internet)\s+ip(?:v4)?[?.!\s]*', text, re.I)
    return bool(wan and address and (station or owner and not other or shorthand))

def collect_wan_status(opener=urlopen):
    result = {'schema_version':1,'source':SOURCE,'provider':PROVIDER,
              'retrieved_at':datetime.now(timezone.utc).isoformat(),
              'status':'unavailable','address':'','notice':NOTICE}
    try:
        request = Request(PROVIDER, headers={'User-Agent':'RigPi-Elmer/1.0'})
        with opener(request, timeout=4) as response:
            address = response.read(65).decode('ascii').strip()
        if public_ipv4(address):
            result.update(status='ok', address=address)
    except (OSError, URLError, ValueError, UnicodeError):
        pass
    return result

def normalize_wan_status(raw):
    fields = {'schema_version','source','provider','retrieved_at','status','address','notice'}
    if not isinstance(raw,dict) or set(raw)!=fields or type(raw['schema_version']) is not int or raw['schema_version']!=1:
        raise ValueError('Live WAN status has an invalid format.')
    if raw['source']!=SOURCE or raw['provider']!=PROVIDER or raw['notice']!=NOTICE or raw['status'] not in ('ok','unavailable'):
        raise ValueError('Live WAN status metadata is invalid.')
    stamp = raw['retrieved_at']
    try:
        if not isinstance(stamp,str) or len(stamp)>40:
            raise ValueError()
        age = (datetime.now(timezone.utc)-datetime.fromisoformat(stamp.replace('Z','+00:00'))).total_seconds()
        if not -60<=age<=300:
            raise ValueError()
    except (TypeError,ValueError):
        raise ValueError('Live WAN status timestamp is invalid or stale.') from None
    if raw['status']=='ok':
        if not public_ipv4(raw['address']):
            raise ValueError('Live WAN IPv4 address is invalid.')
    elif raw['address']!='':
        raise ValueError('Unavailable WAN status cannot contain an address.')
    return dict(raw)
