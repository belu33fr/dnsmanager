#!/usr/bin/env python3
"""Outils de traduction de DNSManage (sans dépendance, sans msgfmt).

  python3 tools/locales.py check     # liste les chaînes du code absentes des .po
  python3 tools/locales.py compile   # recompile locales/*.po -> locales/*.mo

Les chaînes sources sont en français (domaine 'dnsmanager'). Le français n'a pas
besoin d'être traduit ; l'anglais (en_GB.po) doit contenir chaque chaîne.
"""
import array, glob, os, re, struct, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOC = os.path.join(ROOT, 'locales')


def _unq(q, s):
    s = s.replace("\\" + q, q)
    return s.replace("\\\\", "\\")


_SIMPLE = re.compile(r"""__s?\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])dnsmanager\3\s*\)""", re.S)
_PLURAL = re.compile(r"""_n\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])((?:\\.|(?!\3).)*)\3\s*,[^,]*,\s*(['"])dnsmanager\5\s*\)""", re.S)


def code_msgids():
    found = {}
    files = []
    for pat in ('**/*.php', '**/*.twig'):
        files += glob.glob(os.path.join(ROOT, pat), recursive=True)
    for f in files:
        rel = os.path.relpath(f, ROOT)
        if rel.startswith('.git') or rel.startswith('tools'):
            continue
        t = open(f, encoding='utf-8').read()
        for m in _SIMPLE.finditer(t):
            found.setdefault(_unq(m.group(1), m.group(2)), set()).add(rel)
        for m in _PLURAL.finditer(t):
            found.setdefault(_unq(m.group(1), m.group(2)), set()).add(rel)
    return found


def _unescape(s):
    out, i = [], 0
    while i < len(s):
        c = s[i]
        if c == '\\' and i + 1 < len(s):
            n = s[i + 1]
            out.append({'n': '\n', 't': '\t', '"': '"', '\\': '\\'}.get(n, '\\' + n))
            i += 2
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def parse_po(path):
    entries, cur, field, fuzzy_next = [], None, None, False

    def flush():
        nonlocal cur
        if cur is not None:
            entries.append(cur)
        cur = None

    for raw in open(path, encoding='utf-8').read().split('\n'):
        line = raw.strip()
        if line.startswith('#,') and 'fuzzy' in line:
            fuzzy_next = True
            continue
        if not line or line.startswith('#'):
            continue
        m = re.match(r'^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$', line)
        if m:
            kind, idx, val = m.group(1), m.group(2), _unescape(m.group(3))
            if kind in ('msgid', 'msgctxt') and (cur is None or (kind == 'msgid' and cur.get('strs')) or (kind == 'msgctxt' and cur.get('strs'))):
                flush()
                cur = {'ctxt': None, 'id': '', 'plural': None, 'strs': {}, 'fuzzy': fuzzy_next}
                fuzzy_next = False
            if kind == 'msgctxt':
                cur['ctxt'] = val; field = ('ctxt',)
            elif kind == 'msgid':
                cur['id'] = val; field = ('id',)
            elif kind == 'msgid_plural':
                cur['plural'] = val; field = ('plural',)
            else:
                n = int(idx) if idx is not None else 0
                cur['strs'][n] = val; field = ('str', n)
            continue
        m = re.match(r'^"(.*)"$', line)
        if m and cur is not None and field:
            val = _unescape(m.group(1))
            if field[0] == 'str':
                cur['strs'][field[1]] += val
            else:
                cur[field[0]] += val
    flush()
    return entries


def compile_mo(po_path, mo_path):
    messages = {}
    for e in parse_po(po_path):
        if e['fuzzy'] and e['id'] != '':
            continue
        strs = [e['strs'][k] for k in sorted(e['strs'])]
        if e['id'] != '' and not any(strs):
            continue
        key = (e['ctxt'] + '\x04' if e['ctxt'] is not None else '') + e['id']
        if e['plural'] is not None:
            key += '\0' + e['plural']
        messages[key.encode('utf-8')] = '\0'.join(strs).encode('utf-8')
    keys = sorted(messages)
    offsets, ids, strs_b = [], b'', b''
    for k in keys:
        offsets.append((len(ids), len(k), len(strs_b), len(messages[k])))
        ids += k + b'\0'
        strs_b += messages[k] + b'\0'
    keystart = 7 * 4 + 16 * len(keys)
    valuestart = keystart + len(ids)
    ko, vo = [], []
    for o1, l1, o2, l2 in offsets:
        ko += [l1, o1 + keystart]
        vo += [l2, o2 + valuestart]
    out = struct.pack('Iiiiiii', 0x950412de, 0, len(keys), 7 * 4, 7 * 4 + len(keys) * 8, 0, 0)
    out += array.array('i', ko + vo).tobytes() + ids + strs_b
    open(mo_path, 'wb').write(out)
    return len(keys)


def cmd_check():
    found = code_msgids()
    have = {e['id'] for e in parse_po(os.path.join(LOC, 'en_GB.po'))}
    miss = sorted(k for k in found if k and k not in have)
    print('%d chaîne(s) du code absente(s) de locales/en_GB.po' % len(miss))
    for k in miss:
        print('  -', k.replace('\n', '\\n'), ' [' + ', '.join(sorted(found[k])) + ']')
    return 1 if miss else 0


def cmd_compile():
    for po in sorted(glob.glob(os.path.join(LOC, '*.po'))):
        mo = po[:-3] + '.mo'
        print('%s -> %d entrées' % (os.path.basename(mo), compile_mo(po, mo)))
    return 0


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    sys.exit({'check': cmd_check, 'compile': cmd_compile}.get(cmd, lambda: print(__doc__) or 2)())
