#!/usr/bin/env python3
"""Add the permission-aware Elmer Control panel to the RigPi Tuner."""

import argparse
import os
import shutil
import tempfile
from pathlib import Path


STYLE = '<link rel="stylesheet" href="/css/elmer_control.css?v=20260823-1">'
OLD_STYLES = (
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-1">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-2">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-3">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-4">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-5">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-6">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-7">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260816-8">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260818-8">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260818-9">',
    '<link rel="stylesheet" href="/css/elmer_control.css?v=20260818-10">',
)
INCLUDE = '<?php require $dRoot . "/includes/elmer_control_panel.php"; ?>'
DOCUMENT_GUARD = "\tif (window.elmerControlKeyboardActive || $(e.target).closest('#elmerControl').length || document.querySelector('#elmerControl')?.contains(document.activeElement)) return true;"
INPUT_GUARD = "\t\tif (window.elmerControlKeyboardActive || $(event.target).closest('#elmerControl').length || document.querySelector('#elmerControl')?.contains(document.activeElement)) return true;"
OLD_DOCUMENT_GUARD = "\tif ($(e.target).closest('#elmerControl').length) return true;"
OLD_INPUT_GUARD = "\t\tif ($(event.target).closest('#elmerControl').length) return true;"


def patch(path):
    path = Path(path)
    original = path.read_text(encoding='utf-8')
    updated = original
    updated = updated.replace(OLD_DOCUMENT_GUARD, DOCUMENT_GUARD)
    updated = updated.replace(OLD_INPUT_GUARD, INPUT_GUARD)
    for old_style in OLD_STYLES:
        if old_style in updated:
            updated = updated.replace(old_style, STYLE, 1)
    if STYLE not in updated:
        marker = '<link rel="apple-touch-icon" href="/favicon.ico">'
        if updated.count(marker) != 1:
            raise RuntimeError('Unable to locate the Tuner stylesheet insertion point.')
        updated = updated.replace(marker, marker + '\n\t\t\t' + STYLE, 1)
    if INCLUDE not in updated:
        marker = '<?php require $dRoot . "/includes/macroButtons.php"; ?>'
        if updated.count(marker) != 1:
            raise RuntimeError('Unable to locate the Tuner macro panel.')
        close = marker + '\n\t\t   </div>'
        if updated.count(close) != 1:
            raise RuntimeError('Unable to locate the end of the Tuner macro panel.')
        updated = updated.replace(close, close + '\n\t\t\t' + INCLUDE, 1)
    document_marker = '$(document).keydown(function(e){\n'
    guarded_document_marker = document_marker + DOCUMENT_GUARD + '\n'
    if guarded_document_marker not in updated:
        updated = updated.replace(document_marker, guarded_document_marker)
    keyup_marker = '\t$(document).keyup(function(e)\n\t{\n'
    guarded_keyup_marker = keyup_marker + DOCUMENT_GUARD + '\n'
    if guarded_keyup_marker not in updated:
        updated = updated.replace(keyup_marker, guarded_keyup_marker, 1)
    input_marker = '\t$("input").bind("keydown", function(event)\n\t{\n'
    guarded_input_marker = input_marker + INPUT_GUARD + '\n'
    if guarded_input_marker not in updated:
        updated = updated.replace(input_marker, guarded_input_marker, 1)
    if updated == original:
        return False
    backup = path.with_name(path.name + '.pre-elmer-control')
    if not backup.exists():
        shutil.copy2(path, backup)
    fd, temp_name = tempfile.mkstemp(prefix='.' + path.name + '.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            fd = -1
            handle.write(updated)
            handle.flush()
            os.fsync(handle.fileno())
        shutil.copymode(path, temp_name)
        os.replace(temp_name, path)
    finally:
        if fd >= 0:
            os.close(fd)
        try:
            os.unlink(temp_name)
        except FileNotFoundError:
            pass
    return True


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--target', default='/var/www/html/index.php')
    args = parser.parse_args()
    changed = patch(args.target)
    print('Updated RigPi Tuner with Elmer Control.' if changed else
          'RigPi Tuner Elmer Control integration is already current.')


if __name__ == '__main__':
    main()
