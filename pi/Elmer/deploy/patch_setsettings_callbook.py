#!/usr/bin/env python3
"""Refresh the signed-in user's RigPi callbook whenever DX Call changes."""

import argparse
from pathlib import Path


SESSION_HOOK='''$elmerDxSessionId = "";
$elmerDxCall = "";
$elmerRefreshCallbook = false;
if ($tField === "DX" && $tTable === "MySettings") {
    $candidate = strtoupper(trim((string) $tData));
    if (preg_match('/^(?:[A-Z0-9]{1,3}\\/)?[A-Z0-9]{1,3}\\d[A-Z]{1,4}(?:\\/[A-Z0-9]{1,4})?$/', $candidate)) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION["myUsername"])) {
            $elmerDxSessionId = session_id();
            $elmerDxCall = $candidate;
        }
        session_write_close();
    }
}
'''

CHANGE_HOOK='''if ($elmerDxCall !== "") {
    $db->where("Radio", $tRadio);
    $existingDxRow = $db->getOne("MySettings");
    $existingDx = strtoupper(trim((string) ($existingDxRow["DX"] ?? "")));
    $elmerRefreshCallbook = $existingDx !== $elmerDxCall;
}
'''

REFRESH_HOOK='''if ($elmerRefreshCallbook && $elmerDxSessionId !== "") {
    $payload = json_encode([
        "call" => $elmerDxCall,
        "include" => ["biography", "details"],
        "provider" => "auto",
    ]);
    $context = stream_context_create(["http" => [
        "method" => "POST",
        "timeout" => 20,
        "ignore_errors" => true,
        "header" => "Content-Type: application/json\\r\\n" .
            "Cookie: PHPSESSID=" . $elmerDxSessionId . "\\r\\n",
        "content" => $payload,
    ]]);
    $result = @file_get_contents(
        "http://127.0.0.1/programs/ElmerCallbook.php",
        false,
        $context
    );
    if (!is_string($result)) {
        error_log("SetSettings: background callbook refresh failed for " . $elmerDxCall);
    }
}
'''


def replace_once(text, old, new):
    count=text.count(old)
    if count != 1:
        raise RuntimeError(f'Expected one patch anchor, found {count}: {old[:70]}')
    return text.replace(old,new,1)


def patch(path):
    target=Path(path)
    text=target.read_text()
    updated=text
    if '$elmerDxSessionId = "";' not in updated:
        updated=replace_once(updated, '}\nini_set("error_reporting", E_ALL);', '}\n'+SESSION_HOOK+'ini_set("error_reporting", E_ALL);')
    if '$existingDxRow = $db->getOne("MySettings");' not in updated:
        updated=replace_once(updated, ');\nif ($tRadio != 0) {', ');\n'+CHANGE_HOOK+'if ($tRadio != 0) {')
    if 'background callbook refresh failed' not in updated:
        updated=replace_once(updated, '//echo "field: " . $tField', REFRESH_HOOK+'//echo "field: " . $tField')
    if updated == text:
        return False
    target.write_text(updated)
    return True


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--target',default='/var/www/html/programs/SetSettings.php')
    args=parser.parse_args()
    print('Updated DX Call callbook refresh.' if patch(args.target) else 'DX Call callbook refresh is already current.')


if __name__=='__main__':
    main()
