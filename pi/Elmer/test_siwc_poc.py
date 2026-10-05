import concurrent.futures
import io
import json
from pathlib import Path
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import siwc_poc as s

class Response(io.BytesIO):
    status = 200

def record():
    return dict(client_id='oaiapp_test', subject='test-subject', issuer='https://auth.openai.com',
                access_token='dummy-access', refresh_token='dummy-refresh', id_token='dummy-id',
                token_type='Bearer', scopes=list(s.REQUIRED), saved_at=time.time()-4000,
                expires_in=3600, ext_agent_host_id='urn:uuid:laptop')

class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.store = s.Store(self.base / 'state')
        self.source = self.base / 'import.json'
        s.atomic_write(self.source, record())
        self.store.import_file(self.source)

    def test_import_identity_and_permissions(self):
        data = s.read_private(self.store.credentials)
        self.assertNotEqual(data['ext_agent_host_id'], 'urn:uuid:laptop')
        self.assertEqual(data['ext_agent_host_id'], self.store.host_id())
        self.assertEqual(data['id_token'], 'dummy-id')
        self.assertEqual(self.store.directory.stat().st_mode & 0o777, 0o700)
        self.assertEqual(self.store.credentials.stat().st_mode & 0o777, 0o600)
        self.assertEqual(s.Store(self.store.directory).host_id(), data['ext_agent_host_id'])
        with self.assertRaises(s.SIWCError):
            self.store.import_file(self.source)

    def test_unsafe_permissions_and_symlink(self):
        self.source.chmod(0o644)
        with self.assertRaises(s.SIWCError):
            s.read_private(self.source)
        link = self.base / 'link'
        link.symlink_to(self.source)
        with self.assertRaises(OSError):
            s.read_private(link)
        with self.assertRaises(s.SIWCError):
            s.Store('/var/www/html/siwc-test')

    def test_grant_validation(self):
        for field, value in [('client_id', 'dynamic_agent_client'), ('scopes', []),
                             ('expires_in', -1), ('issuer', 'invalid')]:
            data = record()
            data[field] = value
            with self.assertRaises(s.SIWCError):
                self.store.validate(data)

    def test_serialized_rotation(self):
        calls = []
        def refresh(request, timeout):
            form = urllib.parse.parse_qs(request.data.decode())
            self.assertEqual(request.full_url, s.TOKEN_URL)
            self.assertEqual(form['client_id'], ['oaiapp_test'])
            self.assertEqual(form['resource'], [s.RESOURCE])
            self.assertNotIn('scope', form)
            calls.append(1)
            time.sleep(0.03)
            return Response(json.dumps(dict(access_token='new-access', refresh_token='new-refresh',
                                             expires_in=3600)).encode())
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            result = list(pool.map(lambda _: self.store.access_token(refresh), range(2)))
        self.assertEqual(result, ['new-access', 'new-access'])
        self.assertEqual(len(calls), 1)
        data = s.read_private(self.store.credentials)
        self.assertEqual(data['refresh_token'], 'new-refresh')
        self.assertEqual(data['id_token'], 'dummy-id')
        self.assertEqual(data['ext_agent_host_id'], self.store.host_id())

    def test_terminal_refresh_clears_only_tokens(self):
        def fail(request, timeout):
            raise urllib.error.HTTPError(request.full_url, 400, 'bad', {},
                                         io.BytesIO(b'{"error":"invalid_grant"}'))
        with self.assertRaises(s.SIWCError):
            self.store.access_token(fail)
        data = s.read_private(self.store.credentials)
        self.assertNotIn('access_token', data)
        self.assertNotIn('refresh_token', data)
        self.assertNotIn('id_token', data)
        self.assertEqual(data['client_id'], 'oaiapp_test')
        self.assertEqual(data['ext_agent_host_id'], self.store.host_id())

    def test_transient_refresh_preserves_credentials(self):
        before = self.store.credentials.read_bytes()
        def fail(request, timeout):
            raise urllib.error.HTTPError(request.full_url, 503, 'temporary', {}, io.BytesIO(b'{}'))
        with self.assertRaises(s.SIWCError):
            self.store.access_token(fail)
        self.assertEqual(before, self.store.credentials.read_bytes())

    def test_stream_requires_completion(self):
        delta = b'data: {"type":"response.output_text.delta","delta":"hello"}\n\n'
        done = b'data: {"type":"response.completed"}\n\n'
        self.assertEqual(s.consume_stream(Response(delta + done)), 'hello')
        for ending in [b'', b'data: [DONE]\n\n',
                       b'data: {"type":"response.failed"}\n\n',
                       b'data: {"type":"response.incomplete"}\n\n']:
            with self.assertRaises(s.SIWCError):
                s.consume_stream(Response(delta + ending))

    def test_real_elmer_packet_with_mock_inference(self):
        data = record()
        data['saved_at'] = time.time()
        data['ext_agent_host_id'] = self.store.host_id()
        s.atomic_write(self.store.credentials, data)
        def infer(request, timeout):
            self.assertEqual(request.full_url, s.RESOURCE + '/responses')
            payload = json.loads(request.data)
            self.assertIs(payload['store'], False)
            self.assertIs(payload['stream'], True)
            self.assertTrue(payload['input'])
            self.assertTrue(payload['instructions'])
            return Response(b'data: {"type":"response.output_text.delta","delta":"test answer"}\n\n'
                            b'data: {"type":"response.completed"}\n\n')
        result = s.answer(self.store, 'What is RigPi?', 'test-model',
                          s.ROOT / 'output/knowledge.db', infer)
        self.assertEqual(result, 'test answer')

if __name__ == '__main__':
    unittest.main()

