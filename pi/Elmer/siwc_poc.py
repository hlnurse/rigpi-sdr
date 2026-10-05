#!/usr/bin/env python3
"""Isolated Elmer SIWC CLI. Import only a locally validated Elmer OAuth record.
No API-key fallback, web endpoint, radio actions, or token logging.
"""
import argparse
import contextlib
import datetime as dt
import fcntl
import json
import math
import os
from pathlib import Path
import sqlite3
import stat
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parent
STATE = Path('/home/pi/.config/rigpi-elmer-siwc')
RESOURCE = 'https://api.openai.com/v1'
TOKEN_URL = 'https://auth.openai.com/api/accounts/oauth/token'
REQUIRED = {'chatgpt.tokens.use.direct', 'resource.invoke', 'offline_access'}
TERMINAL = {'invalid_grant', 'invalid_refresh_token', 'token_expired',
            'refresh_token_expired', 'refresh_token_invalidated', 'refresh_token_reused'}

class SIWCError(Exception):
    pass

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

def timestamp(value):
    if isinstance(value, str):
        value = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
        if value.tzinfo is None:
            raise SIWCError('Credential timestamp needs a timezone.')
        value = value.timestamp()
    value = float(value)
    if not math.isfinite(value) or value <= 0:
        raise SIWCError('Invalid credential timestamp.')
    return value

def outside_web(path):
    resolved = Path(path).resolve()
    if resolved == Path('/var/www') or Path('/var/www') in resolved.parents:
        raise SIWCError('Credential storage must be outside the web root.')
    return resolved

def read_private(path):
    outside_web(path)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd) as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise SIWCError('Credential file must be owner-only and owned by the current user.')
        raw = handle.read(131073)
        if len(raw) > 131072:
            raise SIWCError('Credential file is too large.')
        return json.loads(raw)

def atomic_write(path, record):
    fd, temporary = tempfile.mkstemp(prefix='.siwc-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as handle:
            os.fchmod(handle.fileno(), 0o600)
            json.dump(record, handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

class Store:
    def __init__(self, directory=STATE):
        self.directory = Path(directory)
        outside_web(self.directory)
        self.directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = self.directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise SIWCError('SIWC directory must be owned by the current user with mode 0700.')
        self.credentials = self.directory / 'credentials.json'
        self.host = self.directory / 'host.json'

    @contextlib.contextmanager
    def locked(self):
        fd = os.open(self.directory / 'session.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'a') as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
                raise SIWCError('Unsafe session lock permissions.')
            fcntl.flock(handle, fcntl.LOCK_EX)
            yield

    def host_id(self):
        if not self.host.exists():
            atomic_write(self.host, {'ext_agent_host_id': 'urn:uuid:' + str(uuid.uuid4())})
        value = read_private(self.host)['ext_agent_host_id']
        if not isinstance(value, str) or not value.startswith(('urn:uuid:', 'urn:ietf:params:oauth:jwk-thumbprint:', 'did:key:')):
            raise SIWCError('Invalid persisted host ID.')
        return value

    def validate(self, record):
        if not isinstance(record, dict):
            raise SIWCError('Expected one protected registration record.')
        for key in ('client_id', 'subject', 'access_token', 'refresh_token'):
            if not isinstance(record.get(key), str) or not record[key] or any(c.isspace() for c in record[key]):
                raise SIWCError('Required credential fields are missing or invalid.')
        if record['client_id'] == 'dynamic_agent_client' or record.get('issuer') != 'https://auth.openai.com':
            raise SIWCError('Expected issued client ID and validated OpenAI identity.')
        if record.get('token_type', '').lower() != 'bearer':
            raise SIWCError('Expected bearer credentials.')
        scopes = record.get('scopes', record.get('scope', '').split())
        if not isinstance(scopes, list) or not all(isinstance(s, str) for s in scopes) or not REQUIRED.issubset(scopes):
            raise SIWCError('ChatGPT plan usage and renewable-session scopes are required.')
        record['scopes'] = scopes
        saved = timestamp(record['saved_at'])
        lifetime = float(record['expires_in'])
        if not math.isfinite(lifetime) or lifetime <= 0 or saved > time.time() + 300:
            raise SIWCError('Invalid token expiry metadata.')
        record['expires_at'] = saved + lifetime
        if record.get('earliest_refresh_at') is not None:
            timestamp(record['earliest_refresh_at'])
        return record

    def import_file(self, source):
        with self.locked():
            host = self.host_id()
            record = self.validate(read_private(source))
            if self.credentials.exists():
                old = read_private(self.credentials)
                if old.get('access_token') or old.get('refresh_token'):
                    raise SIWCError('Active credentials already exist; do not re-import a rotating session.')
                if (old.get('client_id'), old.get('subject')) != (record['client_id'], record['subject']):
                    raise SIWCError('Reauthorization must retain this registration identity.')
            record['ext_agent_host_id'] = host
            atomic_write(self.credentials, record)

    def access_token(self, opener=OPENER.open):
        with self.locked():
            record = self.validate(read_private(self.credentials))
            if record.get('ext_agent_host_id') != self.host_id():
                raise SIWCError('Credential host ID differs from the persisted Pi host ID.')
            now = time.time()
            if now < record['expires_at'] - 60:
                return record['access_token']
            earliest = record.get('earliest_refresh_at')
            if earliest is not None and now < timestamp(earliest):
                if now < record['expires_at']:
                    return record['access_token']
                raise SIWCError('Refresh is not permitted yet; retry later.')
            form = urllib.parse.urlencode({'grant_type': 'refresh_token',
                'client_id': record['client_id'], 'refresh_token': record['refresh_token'],
                'resource': RESOURCE}).encode()
            request = urllib.request.Request(TOKEN_URL, data=form,
                headers={'Content-Type': 'application/x-www-form-urlencoded'}, method='POST')
            try:
                with opener(request, timeout=30) as response:
                    if response.status != 200:
                        raise SIWCError('Unexpected refresh status; credentials preserved.')
                    new = json.loads(response.read(131072))
            except urllib.error.HTTPError as exc:
                try:
                    body = json.loads(exc.read(16384))
                    code = body.get('error')
                    if isinstance(code, dict):
                        code = code.get('code')
                except Exception:
                    code = None
                if isinstance(code, str) and code in TERMINAL:
                    for field in ('access_token', 'refresh_token', 'id_token'):
                        record.pop(field, None)
                    atomic_write(self.credentials, record)
                    raise SIWCError('Session ended. Reauthorize locally with the saved client ID.') from None
                raise SIWCError('Token refresh failed (HTTP %d); credentials preserved.' % exc.code) from None
            except SIWCError:
                raise
            except Exception:
                raise SIWCError('Token refresh interrupted; credentials preserved. Do not retry concurrently.') from None
            updated = dict(record)
            for field in ('access_token', 'refresh_token', 'expires_in'):
                if field not in new:
                    raise SIWCError('Incomplete refresh response; reauthorization may be required.')
                updated[field] = new[field]
            updated['saved_at'] = time.time()
            updated.pop('earliest_refresh_at', None)
            for field in ('id_token', 'token_type', 'earliest_refresh_at'):
                if field in new:
                    updated[field] = new[field]
            if 'scope' in new:
                updated['scopes'] = new['scope'].split()
            atomic_write(self.credentials, self.validate(updated))
            return updated['access_token']

def api_request(store, path, payload=None, opener=OPENER.open):
    token = store.access_token()
    request = urllib.request.Request(RESOURCE + path,
        data=None if payload is None else json.dumps(payload).encode(),
        headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json',
                 'Accept': 'text/event-stream' if payload else 'application/json',
                 'User-Agent': 'RigPi-Elmer-SIWC-PoC'})
    try:
        return opener(request, timeout=90)
    except urllib.error.HTTPError as exc:
        raise SIWCError('OpenAI request failed (HTTP %d). No API-key fallback was attempted.' % exc.code) from None
    except Exception:
        raise SIWCError('OpenAI request could not be completed.') from None

def consume_stream(response):
    data, parts = [], []
    completed = False
    for raw in response:
        if len(raw) > 1048576:
            raise SIWCError('Stream event is too large.')
        line = raw.decode('utf-8').rstrip('\r\n')
        if line.startswith('data:'):
            data.append(line[5:].lstrip())
        elif not line and data:
            value = '\n'.join(data)
            data = []
            if value == '[DONE]':
                break
            event = json.loads(value)
            kind = event.get('type')
            if kind == 'response.output_text.delta':
                parts.append(event.get('delta', ''))
            elif kind in ('response.failed', 'response.incomplete', 'error'):
                raise SIWCError('Inference failed or was incomplete; no successful answer.')
            elif kind == 'response.completed':
                completed = True
                break
    if not completed:
        raise SIWCError('Stream ended without response.completed.')
    return ''.join(parts)

def answer(store, question, model, db_path, opener=OPENER.open):
    from answer_knowledge import INSTRUCTIONS, response_input
    from question_knowledge import make_packet
    with sqlite3.connect(Path(db_path).resolve().as_uri() + '?mode=ro', uri=True) as db:
        packet = make_packet(db, question, 6, None, 2, 1800)
    if not packet.get('evidence'):
        raise SIWCError('Elmer found no evidence for this question.')
    payload = {'model': model, 'instructions': INSTRUCTIONS,
               'input': response_input(packet), 'store': False, 'stream': True}
    with api_request(store, '/responses', payload, opener) as response:
        return consume_stream(response)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('init')
    sub.add_parser('status')
    imp = sub.add_parser('import')
    imp.add_argument('file', help='0600 file transferred over SSH; never paste tokens')
    sub.add_parser('models')
    ask = sub.add_parser('ask')
    ask.add_argument('question')
    ask.add_argument('--model', required=True, help='Slug from the SIWC models command')
    ask.add_argument('--db', default=str(ROOT / 'output/knowledge.db'))
    args = parser.parse_args()
    try:
        store = Store()
        if args.command == 'init':
            with store.locked():
                store.host_id()
            print('Pi host identity initialized; existing identity preserved.')
        elif args.command == 'status':
            with store.locked():
                host_present = store.host.exists()
                present = store.credentials.exists()
                usable = False
                if present:
                    try:
                        record = store.validate(read_private(store.credentials))
                        usable = record.get('ext_agent_host_id') == store.host_id()
                    except (SIWCError, KeyError, ValueError, TypeError):
                        pass
            print('Host identity: ' + ('present' if host_present else 'not initialized'))
            print('Credential record: ' + ('present' if present else 'not imported'))
            print('Locally valid record: ' + ('yes (network access unverified)' if usable else 'no'))
        elif args.command == 'import':
            store.import_file(args.file)
            print('Credentials imported. Pi host identity preserved. Pi now owns refreshes.')
        elif args.command == 'models':
            with api_request(store, '/models') as response:
                catalog = json.loads(response.read(1048576))
            print(json.dumps([{'slug': m['slug'], 'display_name': m.get('display_name', m['slug'])}
                              for m in catalog['models'] if m.get('visibility') == 'list'], indent=2))
        else:
            print(answer(store, args.question, args.model, args.db))
        return 0
    except SIWCError as exc:
        print(str(exc), file=sys.stderr)
    except Exception:
        print('SIWC operation failed. Check protected file format, ownership, and permissions. No tokens logged.', file=sys.stderr)
    return 1

if __name__ == '__main__':
    sys.exit(main())

