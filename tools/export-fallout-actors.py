#!/usr/bin/env python3
"""Read official masters into a private, attributable actor inventory. Never modifies game files."""
import argparse
import hashlib
import json
import struct
import zlib
from pathlib import Path

# Official installed load order; later plugins win overrides.
PLUGINS = ['Fallout4.esm', 'DLCRobot.esm', 'DLCworkshop01.esm', 'DLCCoast.esm',
           'DLCworkshop02.esm', 'DLCworkshop03.esm', 'DLCNukaWorld.esm']


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def fields(data):
    pos = 0
    extended = None
    while pos < len(data):
        if pos + 6 > len(data):
            raise ValueError('Truncated subrecord')
        sig, size = struct.unpack_from('<4sH', data, pos)
        pos += 6
        if sig == b'XXXX':
            if size != 4 or pos + 4 > len(data):
                raise ValueError('Invalid extended subrecord')
            extended = struct.unpack_from('<I', data, pos)[0]
            pos += 4
            continue
        size = extended if extended is not None else size
        extended = None
        if pos + size > len(data):
            raise ValueError('Subrecord exceeds record')
        yield sig, data[pos:pos + size]
        pos += size
    if extended is not None:
        raise ValueError('Dangling extended subrecord')


def strings(root, stem):
    loose = root / 'Strings' / (stem + '_en.STRINGS')
    sources = []
    if loose.is_file():
        data = loose.read_bytes()
        sources.append({'file': loose.name, 'sha256': digest(loose)})
    else:
        archive = root / (stem + (' - Interface.ba2' if stem == 'Fallout4' else ' - Main.ba2'))
        if not archive.is_file():
            return {}, sources, 'English strings archive unavailable'
        with archive.open('rb') as stream:
            magic, version, kind, count, names = struct.unpack('<4sI4sIQ', stream.read(24))
            if magic != b'BTDX' or kind != b'GNRL' or version not in (1, 8):
                return {}, sources, 'Unsupported strings archive format/version %d' % version
            stream.seek(names)
            index = None
            for i in range(count):
                size = struct.unpack('<H', stream.read(2))[0]
                name = stream.read(size).decode('utf-8')
                if name.replace('\\', '/').lower() == 'strings/' + stem.lower() + '_en.strings':
                    if index is not None:
                        raise ValueError('Duplicate strings archive entry')
                    index, entry = i, name
            if index is None:
                return {}, sources, 'English strings entry missing from archive'
            stream.seek(24 + index * 36)
            _, _, _, _, offset, packed, size, marker = struct.unpack('<4s4sIIQIII', stream.read(36))
            if marker != 0xBAADF00D or size > 32 * 1024 * 1024:
                raise ValueError('Unsupported archive entry')
            stream.seek(offset)
            data = stream.read(packed or size)
            if packed:
                data = zlib.decompress(data)
            if len(data) != size:
                raise ValueError('Truncated strings entry')
        sources.append({'file': archive.name, 'sha256': digest(archive), 'entry': entry,
                        'entry_sha256': hashlib.sha256(data).hexdigest()})
    count, size = struct.unpack_from('<II', data)
    start = 8 + count * 8
    if start + size != len(data):
        raise ValueError('Invalid strings table')
    result = {}
    for i in range(count):
        key, offset = struct.unpack_from('<II', data, 8 + i * 8)
        if key in result or offset >= size:
            raise ValueError('Invalid string identity')
        end = data.find(b'\0', start + offset)
        if end < 0:
            raise ValueError('Unterminated string entry')
        text = data[start + offset:end]
        try:
            result[key] = text.decode('utf-8')
        except UnicodeDecodeError:
            result[key] = text.decode('cp1252')
    return result, sources, None


def export(root):
    plugins = []
    rows = []
    for plugin in PLUGINS:
        path = root / plugin
        if not path.is_file():
            plugins.append({'plugin': plugin, 'complete': False, 'reason': 'master unavailable'})
            continue
        data = path.read_bytes()
        if len(data) < 24 or data[:4] != b'TES4':
            raise ValueError('Missing TES4 header: ' + plugin)
        labels, source, strings_error = strings(root, path.stem)
        masters = []
        localized = False
        pos = 0
        ends = [len(data)]
        counts = {'NPC_': 0, 'ACHR': 0}
        unresolved = 0
        header_seen = False
        while pos < len(data):
            while len(ends) > 1 and pos == ends[-1]:
                ends.pop()
            if pos + 24 > ends[-1]:
                raise ValueError('Truncated record header: ' + plugin)
            sig, size, flags, form = struct.unpack_from('<4sIII', data, pos)
            if sig == b'GRUP':
                if size < 24 or pos + size > ends[-1]:
                    raise ValueError('Invalid group boundary')
                ends.append(pos + size)
                pos += 24
                continue
            end = pos + 24 + size
            if end > ends[-1]:
                raise ValueError('Invalid record boundary')
            body = data[pos + 24:end]
            pos = end
            if sig not in (b'TES4', b'NPC_', b'ACHR'):
                continue
            if flags & 0x40000:
                if len(body) < 4:
                    raise ValueError('Invalid compressed record')
                expected = struct.unpack_from('<I', body)[0]
                body = zlib.decompress(body[4:])
                if len(body) != expected:
                    raise ValueError('Invalid compressed record')
            parts = list(fields(body))
            if sig == b'TES4':
                if header_seen or pos != 24 + size:
                    raise ValueError('Duplicate or misplaced TES4 header')
                header_seen = True
                if not parts or parts[0][0] != b'HEDR' or len(parts[0][1]) != 12:
                    raise ValueError('Invalid TES4 metadata')
                masters = [v.rstrip(b'\0').decode('utf-8') for k, v in parts if k == b'MAST']
                if len(set(masters)) != len(masters) or any(m not in PLUGINS or m == plugin for m in masters):
                    raise ValueError('Unsupported master dependency')
                localized = bool(flags & 0x80)
                continue
            counts[sig.decode()] += 1
            owners = masters + [plugin]
            # File form prefixes index this file's masters, not the installed load order.
            def identity(value):
                slot = value >> 24
                if slot >= len(owners) or not value & 0xFFFFFF:
                    raise ValueError('Invalid master-local form identity')
                return owners[slot], '%08X' % (value & 0xFFFFFF)
            owner, local = identity(form)
            row = {'plugin': owner, 'local_id': local, 'kind': 'base' if sig == b'NPC_' else 'reference',
                   'export_plugin': plugin, 'deleted': bool(flags & 0x20)}
            values = dict(parts)
            row['editor_id'] = values.get(b'EDID', b'').rstrip(b'\0').decode('utf-8')
            full = values.get(b'FULL', b'')
            if localized and full:
                if len(full) != 4:
                    raise ValueError('Invalid localized FULL')
                row['display_name'] = labels.get(struct.unpack('<I', full)[0], '')
                row['name_unresolved'] = not bool(row['display_name'])
                unresolved += row['name_unresolved']
            else:
                row['display_name'] = full.rstrip(b'\0').decode('utf-8')
                row['name_unresolved'] = False
            if sig == b'NPC_':
                acbs = values.get(b'ACBS', b'')
                row['unique'] = bool(struct.unpack_from('<I', acbs)[0] & 0x20) if len(acbs) >= 4 else None
            elif b'NAME' in values:
                if len(values[b'NAME']) != 4:
                    raise ValueError('Invalid reference base')
                base_plugin, base_id = identity(struct.unpack('<I', values[b'NAME'])[0])
                row['base'] = {'plugin': base_plugin, 'local_id': base_id}
            rows.append(row)
        if not header_seen:
            raise ValueError('Missing master header')
        # Record structure can be complete while localized names are not.
        names = 'Strings unavailable: ' + strings_error if localized and strings_error else (
            '%d localized names unresolved' % unresolved if unresolved else None)
        plugins.append({'plugin': plugin, 'complete': True, 'sha256': digest(path),
                        'name_resolution_complete': names is None, 'name_resolution_reason': names,
                        'strings_sources': source, 'localized': localized, 'counts': counts,
                        'classification_complete': False,
                        'reason': 'Structural export; unique flag is not proof of a named living roleplay actor. Quest aliases and cut content require review.'})
    return {'schema': 'synth.actor-inventory.v1', 'plugins': plugins, 'actors': rows}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    checkout = Path(__file__).resolve().parents[1]
    if output.is_relative_to(checkout) or output.is_relative_to(args.data.resolve()):
        parser.error('Output must be outside checkout and game data')
    try:
        result = export(args.data)
    except (ValueError, KeyError, IndexError, OSError, struct.error, zlib.error) as error:
        raise SystemExit('Exporter refused input: %s: %s' % (type(error).__name__, error))
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'plugins': len(result['plugins']), 'records': len(result['actors'])}))
