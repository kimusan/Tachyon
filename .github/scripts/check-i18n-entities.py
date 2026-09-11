#!/usr/bin/env python3
"""Fail if a locale string carries an HTML entity.

Translator.js sets innerHTML only for keys written as data-i18n="[html]KEY".
Every other key is assigned to textContent, so "&amp;" is shown to the user
rather than decoded to "&". Weblate's safe-html autofix introduces exactly
that, and it reached eight languages before anyone noticed.
"""
import glob
import json
import re
import sys

ENTITY = re.compile(r'&(amp|lt|gt|quot|apos|#\d+);')


def strings(node, prefix=''):
    for key, value in node.items():
        if isinstance(value, dict):
            yield from strings(value, prefix + key + '/')
        elif isinstance(value, str):
            yield prefix + key, value


def main():
    bad = []
    for path in sorted(glob.glob('tachyon/v/0.0.0/app/localization/*/*.json')):
        with open(path, encoding='utf-8') as handle:
            data = json.load(handle)
        for key, value in strings(data):
            if ENTITY.search(value):
                bad.append((path, key, value))

    for path, key, value in bad:
        print(f'{path}: {key} = {value!r}')

    if bad:
        print(f'\n{len(bad)} string(s) contain HTML entities.')
        print('These are rendered with textContent and will display the entity '
              'literally. Turn off the safe-html autofix in Weblate.')
        return 1

    print('no HTML entities in locale strings')
    return 0


if __name__ == '__main__':
    sys.exit(main())
