#!/usr/bin/env python3
"""Offline Fallout 4 BA2 shortlist plus optional LazyVoiceFinder transcript join.

No game/provider calls. Generated recordings and transcripts belong outside Git.
Archives are supplied in winning order (last wins); loose roots override archives.
"""
import argparse
import array
import concurrent.futures
import csv
import hashlib
import io
import json
import math
from pathlib import Path
import re
import struct
import subprocess
import sys
import time
import wave
import zlib

LIMIT = 8 * 1024 * 1024
FIELDS = ['voice_id', 'plugin', 'source', 'archive', 'language', 'duration_seconds',
          'speech_seconds', 'clipped_fraction', 'source_sha256', 'sample_sha256',
          'sample_path', 'reference_text', 'rank', 'quality_flags', 'selected', 'review_status']


def voice_path(value):
    parts = value.replace('\\', '/').lower().split('/')
    if len(parts) != 5 or parts[:2] != ['sound', 'voice']:
        return None
    if not re.fullmatch(r'[a-z0-9_+-]{1,128}', parts[3]) or '..' in parts:
        return None
    return '/'.join(parts) if Path(parts[-1]).suffix == '.fuz' else None


def archive_index(path):
    """Accept the tested GNRL layout only; never silently misread a newer BA2."""
    size = path.stat().st_size
    with path.open('rb') as handle:
        magic, version, kind, count, names = struct.unpack('<4sI4sIQ', handle.read(24))
        if magic != b'BTDX' or version not in (1, 7, 8) or kind != b'GNRL' or count > 1000000:
            raise ValueError(f'Unsupported BA2 header: {path.name} ({version}, {kind!r})')
        if names < 24 + count * 36 or names >= size:
            raise ValueError('Invalid BA2 name table')
        records = [struct.unpack('<I4sIIQIII', handle.read(36)) for _ in range(count)]
        handle.seek(names)
        for record in records:
            length, = struct.unpack('<H', handle.read(2))
            name = voice_path(handle.read(length).decode('cp1252'))
            if not name:
                continue
            offset, packed, unpacked = record[4:7]
            if not 12 <= unpacked <= LIMIT or offset + (packed or unpacked) > size:
                raise ValueError(f'Invalid BA2 record: {name}')
            yield name, (path, offset, packed, unpacked)


def read_asset(entry):
    path, offset, packed, unpacked = entry
    with path.open('rb') as handle:
        handle.seek(offset)
        raw = handle.read(packed or unpacked)
    if packed:
        decoder = zlib.decompressobj()
        raw = decoder.decompress(raw, LIMIT + 1)
        if not decoder.eof or decoder.unconsumed_tail:
            raise ValueError('Invalid or oversized compressed voice')
    if len(raw) != unpacked:
        raise ValueError('Voice size mismatch')
    return raw


def xwma(raw):
    if len(raw) < 24 or raw[:4] != b'FUZE':
        raise ValueError('Invalid FUZ')
    version, lip = struct.unpack_from('<II', raw, 4)
    if version != 1 or lip > len(raw) - 24:
        raise ValueError('Invalid FUZ lip block')
    audio = raw[12 + lip:]
    if audio[:4] != b'RIFF' or audio[8:12] != b'XWMA':
        raise ValueError('Expected XWMA')
    return audio


def packet_duration(audio):
    """XWMA dpds records cumulative decoded PCM bytes, not compressed size."""
    offset, channels, rate, decoded = 12, 0, 0, 0
    while offset + 8 <= len(audio):
        tag, length = struct.unpack_from('<4sI', audio, offset)
        offset += 8
        if offset + length > len(audio):
            raise ValueError('Invalid RIFF chunk')
        if tag == b'fmt ' and length >= 16:
            _, channels, rate = struct.unpack_from('<HHI', audio, offset)
        if tag == b'dpds' and length >= 4 and length % 4 == 0:
            decoded, = struct.unpack_from('<I', audio, offset + length - 4)
        offset += length + length % 2
    if not decoded or not channels or not rate:
        raise ValueError('Missing XWMA decoded packet sizes')
    return decoded / (channels * rate * 2)


def transcripts(path):
    if not path:
        return {}
    with path.open(encoding='utf-8-sig', newline='') as handle:
        reader = csv.DictReader(handle)
        if not {'Plugin', 'File Name', 'Voice Type'} <= set(reader.fieldnames or []):
            raise ValueError('Expected LazyVoiceFinder Plugin, File Name, Voice Type columns')
        column = next((c for c in reader.fieldnames if c in ('Dialogue 1 - English', 'Dialogue 1 - en', 'Dialogue')), None)
        if not column:
            raise ValueError('Expected an English dialogue column')
        result = {}
        for row in reader:
            key = voice_path(f"sound/voice/{row['Plugin']}/{row['Voice Type']}/{row['File Name']}")
            if key:
                text = row[column].strip()
                if key in result and result[key] != text:
                    raise ValueError(f'Conflicting transcript: {key}')
                result[key] = text
        if not result:
            raise ValueError('No Fallout 4 FUZ records in CSV')
        return result


def analyze(item, output, ffmpeg):
    name, entry, duration, text = item
    raw = read_asset(entry)
    digest = hashlib.sha256(raw).hexdigest()
    # Decode the full original. A timeout and 180-second input bound cap resource use.
    result = subprocess.run([ffmpeg, '-nostdin', '-v', 'error', '-f', 'xwma', '-i', 'pipe:0',
                             '-t', '181', '-ar', '22050', '-ac', '1', '-f', 's16le', 'pipe:1'],
                            input=xwma(raw), capture_output=True, timeout=30, check=True)
    pcm = array.array('h', result.stdout)
    if sys.byteorder != 'little':
        pcm.byteswap()
    actual = len(pcm) / 22050
    if not 3 <= actual <= 180:
        raise ValueError('Decoded duration outside 3-180 seconds')
    # Silence-energy estimate, not a speech recognizer; listening approval remains required.
    speech = sum(len(pcm[i:i+441]) for i in range(0, len(pcm), 441)
                 if math.sqrt(sum(v*v for v in pcm[i:i+441]) / len(pcm[i:i+441])) > 184) / 22050
    clipped = sum(abs(v) >= 32760 for v in pcm) / len(pcm)
    flags = []
    if speech / actual < .65:
        flags.append('low_speech_energy')
    if clipped > .001:
        flags.append('clipping')
    if re.search(r'combat|attack|death|breath|radio|shout|pain', name, re.I):
        flags.append('delivery_review')
    if not text:
        flags.append('missing_transcript')
    if abs(actual - duration) > .25:
        flags.append('packet_duration_mismatch')
    parts = name.split('/')
    sample = output / 'candidates' / parts[3] / f'{digest}.wav'
    sample.parent.mkdir(parents=True, exist_ok=True)
    buffer = io.BytesIO()
    with wave.open(buffer, 'wb') as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(22050)
        wav.writeframes(result.stdout)
    wav_bytes = buffer.getvalue()
    if not sample.exists() or sample.read_bytes() != wav_bytes:
        stage = sample.with_suffix('.tmp')
        stage.write_bytes(wav_bytes)
        stage.replace(sample)
    return dict(zip(FIELDS, [parts[3], parts[2], name, str(entry[0]), 'en-US',
                round(actual, 4), round(speech, 4), round(clipped, 6), digest,
                hashlib.sha256(wav_bytes).hexdigest(), str(sample.relative_to(output)), text,
                '', ';'.join(flags), '0', 'pending_listening']))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, action='append', default=[])
    parser.add_argument('--loose-root', type=Path, action='append', default=[])
    parser.add_argument('--lazy-csv', type=Path)
    parser.add_argument('--join-existing', action='store_true', help='Join transcripts without rescanning audio')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--voice', action='append', default=[])
    parser.add_argument('--top', type=int, default=5)
    parser.add_argument('--ffmpeg', default='ffmpeg')
    args = parser.parse_args()
    if not 1 <= args.top <= 20:
        parser.error('--top must be 1-20')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    catalog = output / 'fallout4_voice_catalog.csv'
    if args.join_existing:
        if not args.lazy_csv or not catalog.is_file():
            parser.error('--join-existing requires --lazy-csv and an existing output catalog')
        text_map = transcripts(args.lazy_csv)
        with catalog.open(encoding='utf-8-sig', newline='') as handle:
            reader = csv.DictReader(handle)
            if reader.fieldnames != FIELDS:
                parser.error('Unexpected catalog columns')
            rows = list(reader)
        matched = 0
        for row in rows:
            text = text_map.get(row['source'], '')
            if not text:
                continue
            matched += 1
            if row['reference_text'] != text:
                row.update(reference_text=text, selected='0', review_status='pending_listening')
            row['quality_flags'] = ';'.join(f for f in row['quality_flags'].split(';') if f != 'missing_transcript')
        if not matched:
            parser.error('No matching catalog records; catalog unchanged')
        backup = catalog.with_name(f'fallout4_voice_catalog.{time.time_ns()}.csv.bak')
        backup.write_bytes(catalog.read_bytes())
        stage = catalog.with_suffix('.tmp')
        with stage.open('w', encoding='utf-8-sig', newline='') as handle:
            writer = csv.DictWriter(handle, fieldnames=FIELDS)
            writer.writeheader()
            writer.writerows(rows)
        stage.replace(catalog)
        print(f'Joined {matched} transcripts; original catalog saved to {backup}')
        return
    if not args.archive:
        parser.error('At least one --archive is required for a new catalog')
    if catalog.exists():
        parser.error('Output catalog already exists; use a fresh directory to preserve review decisions')
    text_map = transcripts(args.lazy_csv)
    index, groups, failures = {}, {}, []
    for archive in args.archive:
        index.update(archive_index(archive.resolve()))
    for root in args.loose_root:
        for file in sorted((root / 'Sound' / 'Voice').rglob('*')):
            if not file.is_file():
                continue
            name = voice_path(str(file.relative_to(root)))
            if name and file.stat().st_size <= LIMIT:
                index[name] = (file.resolve(), 0, 0, file.stat().st_size)
    print(f'Indexed {len(index)} winning voice files', flush=True)
    for number, (name, entry) in enumerate(sorted(index.items()), 1):
        voice = name.split('/')[3]
        if args.voice and voice not in args.voice:
            continue
        try:
            duration = packet_duration(xwma(read_asset(entry)))
            if 3 <= duration <= 180:
                groups.setdefault(voice, []).append((name, entry, duration, text_map.get(name, '')))
        except (ValueError, struct.error, zlib.error) as error:
            failures.append({'source': name, 'error': str(error)})
        if number % 10000 == 0:
            print(f'Measured {number}/{len(index)} records', flush=True)
    # Prefer ordinary dialogue; retain a separate delivery-review shortlist when all are flagged.
    jobs = []
    for values in groups.values():
        values.sort(key=lambda r: (bool(re.search(r'combat|attack|death|breath|radio|shout|pain', r[0])), -r[2], r[0]))
        jobs.extend(values[:args.top])
    rows = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        futures = {pool.submit(analyze, job, output, args.ffmpeg): job[0] for job in jobs}
        for future in concurrent.futures.as_completed(futures):
            try:
                rows.append(future.result())
            except (ValueError, subprocess.SubprocessError) as error:
                failures.append({'source': futures[future], 'error': type(error).__name__})
            if len(rows) % 100 == 0:
                print(f'Decoded {len(rows)}/{len(jobs)} shortlist recordings', flush=True)
    rows.sort(key=lambda r: (r['voice_id'], bool(r['quality_flags'].replace('missing_transcript', '').strip(';')),
                             -r['speech_seconds'], -r['duration_seconds'], r['source']))
    previous, rank = None, 0
    for row in rows:
        rank = rank + 1 if row['voice_id'] == previous else 1
        row['rank'], previous = rank, row['voice_id']
    stage = catalog.with_suffix('.tmp')
    with stage.open('w', encoding='utf-8-sig', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(rows)
    stage.replace(catalog)
    report = {'indexed': len(index), 'ranked_candidates': len(rows), 'voice_types': len({r['voice_id'] for r in rows}),
              'transcripts_matched': sum(bool(r['reference_text']) for r in rows),
              'lazy_csv': str(args.lazy_csv or ''), 'failures': failures,
              'scope': 'explicit archives in order plus explicit loose roots; no implicit MO2 load-order resolution',
              'ranking': 'decoded packet duration shortlist; decoded energy-based speech estimate; listening approval required'}
    (output / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps({k: v for k, v in report.items() if k != 'failures'}), flush=True)
    print(f'Failures: {len(failures)}; catalog: {catalog}', flush=True)


if __name__ == '__main__':
    main()
