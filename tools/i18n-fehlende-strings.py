#!/usr/bin/env python3
"""Findet Gettext-Strings (Domain blitz-donner-forms), die in den
.po-Dateien fehlen. Vor jedem Release laufen lassen:
    python3 tools/i18n-fehlende-strings.py
Exit-Code 1, wenn Strings fehlen."""
import glob
import re
import sys

SQ = r"'((?:[^'\\]|\\.)*)'"


def unescape(s):
    return s.replace("\\'", "'").replace('\\"', '"').replace('\\n', '\n')


strings = set()
for f in glob.glob('includes/*.php') + ['blitz-donner-forms.php', 'uninstall.php']:
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r"(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*" + SQ + r"\s*,\s*'blitz-donner-forms'", src, re.S):
        strings.add(unescape(m.group(1)))
    for m in re.finditer(r"_n\(\s*" + SQ + r"\s*,\s*" + SQ + r"\s*,", src, re.S):
        strings.add(unescape(m.group(1)))
        strings.add(unescape(m.group(2)))
    for m in re.finditer(r'__\(\s*((?:"(?:[^"\\]|\\.)*"\s*\.?\s*)+),\s*\'blitz-donner-forms\'', src, re.S):
        strings.add(unescape(''.join(re.findall(r'"((?:[^"\\]|\\.)*)"', m.group(1)))))
for f in glob.glob('assets/*.js'):
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r"__\(\s*" + SQ + r"\s*,\s*'blitz-donner-forms'\s*\)", src, re.S):
        strings.add(unescape(m.group(1)))

fehlt_gesamt = 0
for po in sorted(glob.glob('languages/*.po')):
    src = open(po, encoding='utf-8').read()
    have = set()
    for m in re.finditer(r'msgid(?:_plural)?\s+((?:"(?:[^"\\]|\\.)*"\s*)+)', src):
        s = ''.join(re.findall(r'"((?:[^"\\]|\\.)*)"', m.group(1)))
        have.add(s.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\'))
    fehlt = sorted(strings - have)
    fehlt_gesamt += len(fehlt)
    print(f'{po}: {len(fehlt)} fehlend')
    for s in fehlt:
        print('  -', s[:100].replace('\n', '\\n'))
sys.exit(1 if fehlt_gesamt else 0)
