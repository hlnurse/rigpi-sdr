"""Read-only LAN IPv4 context for explicit RigPi address questions."""
import ipaddress
import json
import re
import subprocess
from datetime import datetime, timezone

SOURCE = 'RigPi System Status network interfaces'
NOTICE = 'LAN IPv4 addresses only; browser, public, loopback, and container addresses are excluded.'
PRIVATE = tuple(ipaddress.ip_network(n) for n in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'))

def is_lan_address(value):
    try:
        address = ipaddress.IPv4Address(value)
        return any(address in network for network in PRIVATE)
    except (ValueError, TypeError):
        return False

def requested_network_status(question):
    text = str(question).split('UI OUTPUT REQUIREMENT:', 1)[0]
    if re.search(r'\b(public|external|wan)\b', text, re.I) and not re.search(r'\b(local|lan|internal)\b', text, re.I):
        return False
    address = re.search(r'\b(?:ip(?:v4)?|lan\s+address|local\s+address)\b', text, re.I)
    lookup = re.search(r'\b(?:address(?:es)?|what|where|which|show|find|check|tell|display)\b', text, re.I)
    station = re.search(r'\b(?:rigpi|raspberry|pi)\b', text, re.I)
    owner = re.search(r'\b(?:my|our|this)\b', text, re.I)
    other = re.search(r'\b(?:router|radio|camera|phone|computer|laptop|browser|server)\b', text, re.I)
    return bool(address and lookup and (station or owner and not other))

def collect_network_status(runner=subprocess.run):
    result = {'schema_version': 1, 'source': SOURCE,
              'retrieved_at': datetime.now(timezone.utc).isoformat(),
              'status': 'unavailable', 'interfaces': [], 'notice': NOTICE}
    try:
        response = runner(['/usr/sbin/ip', '-j', '-4', 'address', 'show'],
                          capture_output=True, text=True, timeout=3, check=True)
        interfaces = json.loads(response.stdout)
        if not isinstance(interfaces, list):
            return result
        for interface in interfaces:
            name = interface.get('ifname', '')
            if not re.fullmatch(r'(?:eth|en|wlan|wl)[A-Za-z0-9_.-]{0,24}', name):
                continue
            if interface.get('operstate') != 'UP':
                continue
            addresses = []
            for item in interface.get('addr_info', []):
                address = item.get('local', '')
                if item.get('family') == 'inet' and item.get('scope') == 'global' and is_lan_address(address) and address not in addresses:
                    addresses.append(address)
            if addresses:
                result['interfaces'].append({'name': name, 'addresses': addresses[:8]})
        result['interfaces'] = result['interfaces'][:8]
        result['status'] = 'ok' if result['interfaces'] else 'no_lan_address'
    except (OSError, subprocess.SubprocessError, ValueError, TypeError, AttributeError):
        result["interfaces"]=[]
    return result

def normalize_network_status(raw):
    fields = {'schema_version', 'source', 'retrieved_at', 'status', 'interfaces', 'notice'}
    if not isinstance(raw, dict) or set(raw) != fields or type(raw['schema_version']) is not int or raw['schema_version'] != 1:
        raise ValueError('Live network status has an invalid format.')
    if raw['source'] != SOURCE or raw['notice'] != NOTICE or raw['status'] not in ('ok', 'no_lan_address', 'unavailable'):
        raise ValueError('Live network status metadata is invalid.')
    stamp = raw['retrieved_at']
    try:
        if not isinstance(stamp, str) or len(stamp) > 40:
            raise ValueError()
        moment = datetime.fromisoformat(stamp.replace('Z', '+00:00'))
        age = (datetime.now(timezone.utc) - moment).total_seconds()
        if not -60 <= age <= 300:
            raise ValueError()
    except (ValueError, TypeError):
        raise ValueError('Live network status timestamp is invalid or stale.') from None
    rows = raw['interfaces']
    if not isinstance(rows, list) or len(rows) > 8 or (raw['status'] == 'ok') != bool(rows):
        raise ValueError('Live network interface status is inconsistent.')
    clean = []
    names = set()
    for row in rows:
        if not isinstance(row, dict) or set(row) != {'name', 'addresses'}:
            raise ValueError('Live network interface is invalid.')
        name = row['name']
        if not isinstance(name, str) or not re.fullmatch(r'(?:eth|en|wlan|wl)[A-Za-z0-9_.-]{0,24}', name) or name in names:
            raise ValueError('Live network interface name is invalid.')
        addresses = row['addresses']
        if not isinstance(addresses, list) or not 1 <= len(addresses) <= 8 or any(not isinstance(a, str) or not is_lan_address(a) for a in addresses):
            raise ValueError('Live LAN IPv4 address is invalid.')
        names.add(name)
        clean.append({'name': name, 'addresses': list(dict.fromkeys(addresses))})
    return {**raw, 'interfaces': clean}
