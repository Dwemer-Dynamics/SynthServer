#!/usr/bin/env python3
"""Audit actor coverage and prepare evidence-backed drafts; never installs content."""
import argparse
import csv
import datetime
import hashlib
import http.client
import io
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import urllib.robotparser
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
# Official installed load order; later plugins win overrides.
PLUGINS = ['Fallout4.esm', 'DLCRobot.esm', 'DLCworkshop01.esm', 'DLCCoast.esm',
           'DLCworkshop02.esm', 'DLCworkshop03.esm', 'DLCNukaWorld.esm']
FIELDS = ['core', 'npc_static_bio', 'worldknowledge_tags', 'personality', 'occupation',
          'appearance', 'skills', 'speechstyle', 'goals']
AUTHORED = set(FIELDS) - {'skills', 'worldknowledge_tags'}
HOSTS = {'fallout.wiki', 'fallout.fandom.com'}
AGENT = 'SYNTH-BiographyAudit/1.0 (+https://github.com/Dwemer-Dynamics/SynthServer)'
RIGHTS = ('Creative Commons Attribution-ShareAlike License 4.0',
          'Creative Commons Attribution-Share Alike License 3.0 (Unported)',
          'Creative Commons Attribution-ShareAlike 3.0 Unported')
# Timeouts, resets, HTTP errors and truncated bodies stop the host rather than the batch.
NETWORK = (OSError, http.client.HTTPException)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def obj(value, what):
    if not isinstance(value, dict):
        raise ValueError('Malformed ' + what)
    return value


def items(value, what):
    if not isinstance(value, list):
        raise ValueError('Malformed ' + what + ' list')
    return [obj(item, what) for item in value]


def read(path):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('Duplicate JSON key: ' + key)
            result[key] = value
        return result
    if path.stat().st_size > 64 * 1024 * 1024:
        raise ValueError('Oversized input')
    return json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=unique)


def write(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, sort_keys=True, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def key(plugin, kind, form):
    if plugin not in PLUGINS or kind not in ('base', 'reference') or not isinstance(form, str) or not re.fullmatch(r'00[0-9A-F]{6}', form) or form == '00000000':
        raise ValueError('Invalid plugin-local identity')
    return '|'.join((plugin, kind, form))


def rowkey(row):
    return key(row['plugin'], row['kind'], row['local_id'])


def audit(inventory, decisions, catalog):
    pack = read(catalog)
    if pack.get('schema') != 'synth.fallout4-content.v1':
        raise ValueError('Invalid catalog schema')
    bindings = {}
    for npc in pack['npcs']:
        kind = npc.get('mapping_kind', 'base')
        ids = [npc['reference_local_formid']] if kind == 'reference' else [npc['baseid']] + npc.get('base_aliases', [])
        for form in ids:
            identity = key(npc['plugin'], kind, form)
            if identity in bindings:
                raise ValueError('Conflicting catalog identity: ' + identity)
            bindings[identity] = npc['npc_name']
    report = {'schema': 'synth.biography-audit.v1', 'catalog_sha256': sha(catalog.read_bytes()),
              'catalog_biographies': len(pack['npcs']), 'catalog_bindings': len(bindings),
              'plugins': {}, 'actors': [], 'reference_bindings': [], 'unreviewed_references': {}, 'limitations': [
                  'Structural record coverage is separate from reviewed named-cast coverage.',
                  'No display-name binding. Quest-created aliases and unreviewed multi-form actors are withheld.']}
    exports = {}
    effective = {}
    if inventory:
        data = obj(read(inventory), 'inventory')
        if data.get('schema') != 'synth.actor-inventory.v1':
            raise ValueError('Invalid inventory schema')
        for item in items(data.get('plugins'), 'plugin export'):
            plugin = item.get('plugin')
            if plugin not in PLUGINS or plugin in exports or not isinstance(item.get('complete'), bool):
                raise ValueError('Invalid/duplicate plugin export')
            if item['complete'] and (not re.fullmatch('[a-f0-9]{64}', str(item.get('sha256', '')))
                                     or not isinstance(item.get('name_resolution_complete'), bool)):
                raise ValueError('Complete export requires master checksum and name-resolution status')
            exports[plugin] = item
        order = list(exports)
        if order != [plugin for plugin in PLUGINS if plugin in exports]:
            raise ValueError('Plugin exports must follow official load order: ' + ', '.join(PLUGINS))
        per_export = set()
        position = 0
        for row in items(data.get('actors'), 'actor record'):
            identity = rowkey(row)
            source = row.get('export_plugin')
            if source not in exports or (source, identity) in per_export:
                raise ValueError('Duplicate record or unknown export owner')
            per_export.add((source, identity))
            # The winner is the last declared plugin, never an arbitrary interleaving of rows.
            if order.index(source) < position or PLUGINS.index(row['plugin']) > PLUGINS.index(source):
                raise ValueError('Actor records must follow declared plugin order: ' + identity)
            position = order.index(source)
            if (not isinstance(row.get('deleted'), bool) or not isinstance(row.get('display_name'), str)
                    or not isinstance(row.get('editor_id'), str)):
                raise ValueError('Invalid actor record')
            if row['kind'] == 'base' and row.get('unique') is not None and type(row['unique']) is not bool:
                raise ValueError('Invalid unique flag')
            if 'base' in row:
                base = obj(row['base'], 'reference base')
                if row['kind'] != 'reference':
                    raise ValueError('Only references carry a base link')
                key(base.get('plugin'), 'base', base.get('local_id'))
            effective[identity] = row
        report['inventory_sha256'] = sha(inventory.read_bytes())
    reviewed = {}
    if decisions:
        review = obj(read(decisions), 'actor review')
        if review.get('schema') != 'synth.actor-review.v1' or review.get('inventory_sha256') != report.get('inventory_sha256'):
            raise ValueError('Actor review must pin this exact inventory')
        for decision in items(review.get('actors'), 'actor review entry'):
            identity = rowkey(decision)
            if (identity in reviewed or identity not in effective or not isinstance(decision.get('reason'), str)
                    or not decision['reason'] or decision.get('editor_id') != effective[identity].get('editor_id')):
                raise ValueError('Invalid/duplicate actor review identity')
            if decision.get('status') not in ('eligible', 'excluded', 'ambiguous'):
                raise ValueError('Invalid review status')
            reviewed[identity] = decision
        report['review_sha256'] = sha(decisions.read_bytes())
    # Group every eligible decision by proposed character before any form can become missing.
    catalog_keys = {npc['npc_name'] for npc in pack['npcs']}
    proposed = {}
    for identity, decision in reviewed.items():
        if decision['status'] == 'eligible' and 'character_key' in decision:
            character = decision['character_key']
            if not isinstance(character, str) or not re.fullmatch('[a-z0-9_]{1,100}', character) or character in catalog_keys:
                raise ValueError('Invalid/existing candidate character key')
            proposed.setdefault(character, []).append(identity)
    # A base placed by a catalog-bound reference may be shared; it never gains a name-specific binding.
    bound_bases = {}
    for identity, row in effective.items():
        if row['kind'] == 'reference' and identity in bindings and 'base' in row:
            bound_bases.setdefault(key(row['base']['plugin'], 'base', row['base']['local_id']), identity)
    editors = Counter(row['editor_id'].lower() for row in effective.values() if row['editor_id'])
    for plugin in PLUGINS:
        export = exports.get(plugin, {})
        report['plugins'][plugin] = {'record_export_complete': export.get('complete', False),
            'name_resolution_complete': export.get('name_resolution_complete', False),
            'named_classification_complete': False, 'export': export,
            'catalog_bindings': sum(k.startswith(plugin + '|') for k in bindings),
            'covered': 0, 'missing': 0, 'ambiguous': 0, 'excluded': 0}
    for identity, row in sorted(effective.items()):
        if row['kind'] == 'reference':
            if identity in bindings:
                report['reference_bindings'].append({'identity': identity, 'catalog_key': bindings[identity]})
            elif identity not in reviewed:
                # Unreviewed placed instances are not additional character identities.
                report['unreviewed_references'][row['plugin']] = report['unreviewed_references'].get(row['plugin'], 0) + 1
                continue
        decision = reviewed.get(identity, {})
        status, reason = 'ambiguous', 'Named identity/alias/cut-content classification requires review'
        base = key(row['base']['plugin'], 'base', row['base']['local_id']) if 'base' in row else None
        if row['deleted']:
            status, reason = 'excluded', 'Deleted record'
        elif identity in bindings:
            status, reason = 'covered', 'Exact catalog identity or known physical-form alias'
        elif decision.get('status') == 'excluded':
            status, reason = 'excluded', decision['reason']
        elif identity in bound_bases:
            reason = 'Base of catalog-bound reference ' + bound_bases[identity] + '; shared bases gain no name-specific binding'
        elif base in bindings:
            reason = 'Placed reference of catalog-bound base ' + base + ' (' + bindings[base] + ')'
        elif decision.get('status') == 'eligible':
            # A reviewer must explicitly account for aliases before allowing a new biography.
            if len(proposed.get(decision.get('character_key'), [])) > 1:
                # Unknown aliases need a reviewed single canonical binding, never one bio per form.
                reason = 'Multiple reviewed forms share proposed character ' + decision['character_key'] + '; canonical binding unresolved'
            elif decision.get('aliases_checked') is True and row['display_name'] and not row.get('name_unresolved') and decision.get('character_key'):
                status, reason = 'missing', decision['reason']
        elif decision:
            reason = decision['reason']
        report['actors'].append({'identity': identity, 'status': status, 'reason': reason,
            'record': row, 'review': decision, 'catalog_key': bindings.get(identity),
            'editor_id_unique': editors[row['editor_id'].lower()] == 1})
        report['plugins'][row['plugin']][status] += 1
    report['missing_catalog_bindings_in_export'] = sorted(set(bindings) - set(effective))
    report['totals'] = dict(Counter(entry['status'] for entry in report['actors']))
    return report


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Redirect refused: review source endpoint explicitly')


def fetch_bytes(url):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.hostname not in HOSTS or parsed.port is not None or parsed.username or parsed.password or parsed.fragment:
        raise ValueError('Source URL outside permitted HTTPS hosts')
    with urllib.request.build_opener(NoRedirect).open(urllib.request.Request(url, headers={'User-Agent': AGENT}), timeout=25) as response:
        body = response.read(4 * 1024 * 1024 + 1)
        if len(body) > 4 * 1024 * 1024:
            raise ValueError('Oversized source response')
        return body.decode('utf-8')


def validate_source(entry):
    if entry.get('status') != 'available':
        return
    source = entry['source']
    policy = obj(entry.get('policy'), 'cached policy')
    robot = urllib.robotparser.RobotFileParser()
    robot.parse(policy['robots'].splitlines())
    # Robots must permit the exact canonical article and rights requests, not just the API path.
    if (entry.get('url') != article_url(source) or policy.get('rights_url') != rights_url(source['host'])
            or not robot.can_fetch(AGENT, entry['url']) or not robot.can_fetch(AGENT, policy['rights_url'])):
        raise ValueError('Cached policy does not permit API access')
    rights = json.loads(policy['rights_response'])['query']['rightsinfo']
    if rights != policy['rights'] or rights.get('text') not in RIGHTS:
        raise ValueError('Cached reuse terms require review')
    raw = entry['response']
    page = json.loads(raw)['query']['pages'][0]
    revision = page['revisions'][0]
    if (sha(raw.encode()) != entry['response_sha256'] or page['title'] != source['title'] or page.get('missing')
            or revision['revid'] != entry['revision_id'] or revision['slots']['main']['content'] != entry['text']
            or revision.get('timestamp') != entry.get('timestamp') or entry.get('attribution') != attribution(source)
            or entry.get('history_url') != history_url(source)):
        raise ValueError('Cached article/revision/attribution mismatch')
    datetime.datetime.fromisoformat(entry['observed_at'])


def article_url(source):
    query = urllib.parse.urlencode({'action': 'query', 'prop': 'revisions', 'rvprop': 'ids|timestamp|content',
        'rvslots': 'main', 'titles': source['title'], 'format': 'json', 'formatversion': '2', 'maxlag': '5'})
    return 'https://' + source['host'] + '/api.php?' + query


def rights_url(host):
    return 'https://' + host + '/api.php?action=query&meta=siteinfo&siprop=rightsinfo&format=json'


def attribution(source):
    return 'Contributors to ' + source['title'] + ' at ' + source['host']


def history_url(source):
    return 'https://' + source['host'] + '/wiki/' + urllib.parse.quote(source['title']) + '?action=history'


def failure(error):
    return str(error) if isinstance(error, ValueError) else type(error).__name__ + ': ' + str(error)


def research(queue_path, cache, online):
    queue = obj(read(queue_path), 'source queue')
    queued = items(queue.get('sources'), 'source')
    if queue.get('schema') != 'synth.biography-sources.v1' or len(queued) > 1000:
        raise ValueError('Invalid source queue')
    ids = set()
    for source in queued:
        sid, host, title = source.get('id'), source.get('host'), source.get('title')
        if (not isinstance(sid, str) or not re.fullmatch('[a-z0-9_-]{1,100}', sid) or sid in ids or host not in HOSTS
                or not isinstance(title, str) or not 1 <= len(title) <= 256 or set(source) != {'id', 'host', 'title'}):
            raise ValueError('Invalid source identity')
        ids.add(sid)
    result = {}
    policies = {}
    for source in queued:
        sid, host, title = source['id'], source['host'], source['title']
        target = cache / (sid + '.json')
        if target.exists():
            cached = obj(read(target), 'cached source')
            if cached.get('source') != source:
                raise ValueError('Cache/source mismatch')
            validate_source(cached)
            result[sid] = cached
            continue
        if not online:
            result[sid] = {'source': source, 'status': 'unavailable', 'reason': 'No cached response; offline mode'}
            continue
        # Stop this host for the batch on policy/access failure. Never retry around a 403/429.
        if host not in policies:
            fetched = {}
            try:
                robots = fetch_bytes('https://' + host + '/robots.txt')
                fetched['robots'] = robots
                robot = urllib.robotparser.RobotFileParser()
                robot.parse(robots.splitlines())
                if not robot.can_fetch(AGENT, 'https://' + host + '/api.php'):
                    raise ValueError('API disallowed by robots policy')
                delay = max(2, robot.crawl_delay(AGENT) or 0)
                if delay > 60:
                    raise ValueError('Policy delay exceeds bounded batch budget')
                time.sleep(delay)
                rights_request = rights_url(host)
                if not robot.can_fetch(AGENT, rights_request):
                    raise ValueError('Reuse metadata URL disallowed by robots policy')
                rights_raw = fetch_bytes(rights_request)
                fetched.update(rights_url=rights_request, rights_response=rights_raw)
                rights = json.loads(rights_raw)['query']['rightsinfo']
                if rights.get('text') not in RIGHTS:
                    raise ValueError('Unrecognized reuse terms; manual policy review required')
                policies[host] = {'robots': robots, 'rights': rights, 'rights_response': rights_raw,
                                  'rights_url': rights_request, 'delay': delay}
            except (ValueError, KeyError, IndexError, TypeError, AttributeError) + NETWORK as error:
                # Keep the raw policy evidence that caused the denial; never retry around it.
                policies[host] = {'error': failure(error), 'evidence': fetched}
        policy = policies[host]
        entry = {'source': source, 'status': 'unavailable',
                 'observed_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}
        if 'error' in policy:
            entry['reason'] = 'Source policy unavailable: ' + policy['error']
            if policy['evidence']:
                entry['policy_evidence'] = policy['evidence']
        else:
            try:
                time.sleep(policy['delay'])
                url = article_url(source)
                robot = urllib.robotparser.RobotFileParser()
                robot.parse(policy['robots'].splitlines())
                if not robot.can_fetch(AGENT, url):
                    raise ValueError('Article API URL disallowed by robots policy')
                raw = fetch_bytes(url)
                page = json.loads(raw)['query']['pages'][0]
                revision = page['revisions'][0]
                text = revision['slots']['main']['content']
                if page.get('missing') or page['title'] != title or not isinstance(revision['revid'], int) or not text:
                    raise ValueError('Missing/redirected/unrevisioned article')
                entry.update(status='available', url=url, revision_id=revision['revid'], timestamp=revision['timestamp'],
                    text=text, response=raw, response_sha256=sha(raw.encode()), policy=policy,
                    attribution=attribution(source), history_url=history_url(source))
            except (ValueError, KeyError, IndexError, TypeError, AttributeError) + NETWORK as error:
                entry['reason'] = 'Article unavailable: ' + failure(error)
                if isinstance(error, NETWORK):
                    # Includes HTTP 403/429: stop this host for the batch, no retry.
                    policies[host] = {'error': 'host stopped after ' + failure(error), 'evidence': {}}
                    if isinstance(error, urllib.error.HTTPError):
                        entry['retry_after'] = error.headers.get('Retry-After') if error.headers else None
        validate_source(entry)
        write(target, entry)
        result[sid] = entry
    return result


def evidence_ledger(path, report):
    # Every entry is validated before any network access, cache or output write. Absent support stays pending.
    ledger = obj(read(path), 'evidence') if path else {'schema': 'synth.biography-evidence.v1', 'actors': []}
    if ledger.get('schema') != 'synth.biography-evidence.v1':
        raise ValueError('Invalid evidence schema')
    eligible = {entry['identity'] for entry in report['actors'] if entry['status'] == 'missing'}
    seen = set()
    for actor in items(ledger.get('actors'), 'evidence entry'):
        identity = key(actor.get('plugin'), actor.get('kind'), actor.get('local_id'))
        if identity in seen or identity not in eligible:
            raise ValueError('Duplicate or nonmissing evidence identity')
        seen.add(identity)
        quotes = [obj(actor.get('source_binding', {}), 'source binding')]
        for field, authored in obj(actor.get('fields', {}), 'evidence fields').items():
            text = obj(authored, 'authored field').get('text')
            if field not in AUTHORED or not isinstance(text, str) or len(text.encode()) > 4096 or '\0' in text:
                raise ValueError('Unsupported/oversized authored field (skills never accepted)')
            quotes += items(authored.get('support', []), 'field support')
        if any(not isinstance(quote.get('source_id', ''), str) or not isinstance(quote.get('quote', ''), str) for quote in quotes):
            raise ValueError('Malformed evidence quote')
    return ledger


def drafts(report, ledger, sources, limit):
    eligible = {entry['identity']: entry for entry in report['actors'] if entry['status'] == 'missing'}
    candidates, pending, seen = [], [], set()
    for actor in ledger['actors']:
        identity = rowkey(actor)
        if identity in seen or identity not in eligible:
            raise ValueError('Duplicate or nonmissing evidence identity')
        seen.add(identity)
        entry = eligible[identity]
        output = {field: '' for field in FIELDS}
        provenance = {}
        reasons = []
        if actor.get('editor_id') != entry['record']['editor_id'] or actor.get('starting_state_reviewed') is not True:
            reasons.append('Editor identity or starting-state review missing')
        # The source must corroborate a form/editor identifier, independently of its page title.
        binding = actor.get('source_binding', {})
        binding_id = binding.get('source_id')
        binding_source = sources.get(binding_id, {})
        binding_quote = binding.get('quote', '')
        plugin = entry['record']['plugin']
        local = re.escape(entry['record']['local_id'][2:])
        editor = entry['record']['editor_id']
        # Bare/00 IDs prove only Fallout4.esm. An xx prefix cannot say which DLC owns the local ID,
        # so every DLC form (base or reference) needs an explicit Plugin.esm|id qualification.
        qualified = re.escape(plugin) + r'\s*\|\s*'
        if plugin == 'Fallout4.esm':
            form_value = r'(?:' + qualified + r')?(?:0x)?(?:00)?' + local
        else:
            form_value = qualified + r'(?:0x)?(?:00|xx)?' + local
        editor_value = (qualified if plugin != 'Fallout4.esm' else '') + re.escape(editor)
        form_label = r'(?:ref(?:erence)?\s*id|refid)' if entry['record']['kind'] == 'reference' else r'(?:base\s*id|baseid|form\s*id|formid)'
        form_pattern = r'(?i)(?<![a-z0-9_])' + form_label + r'\s*[=:]\s*(?:' + form_value + r')(?![a-z0-9_])'
        editor_pattern = r'(?i)(?<![a-z0-9_])(?:editor\s*id|editorid)\s*[=:]\s*' + editor_value + r'(?![a-z0-9_])'
        # A shared editor ID cannot identify one form; only an exact form ID can.
        corroborated = bool(re.search(form_pattern, binding_quote)
                            or (editor and entry['editor_id_unique'] and re.search(editor_pattern, binding_quote)))
        if (binding_source.get('status') != 'available' or not binding_quote
                or binding_quote not in binding_source.get('text', '')
                or binding.get('revision_id') != binding_source.get('revision_id')
                or not corroborated):
            reasons.append('Source does not corroborate stable form/editor identity')
        for field, authored in actor.get('fields', {}).items():
            if field not in AUTHORED or not isinstance(authored.get('text'), str) or len(authored['text'].encode()) > 4096 or '\0' in authored['text']:
                raise ValueError('Unsupported/oversized authored field (skills never accepted)')
            supports = authored.get('support', [])
            if not supports:
                reasons.append('Missing evidence for ' + field)
            for support in supports:
                # Field prose must come from the same page that proved this actor's identity.
                if support.get('source_id') != binding_id:
                    reasons.append('Evidence for ' + field + ' is not from the identity-bound source')
                source = sources.get(support.get('source_id'), {})
                raw = source.get('response', '')
                quote = support.get('quote', '')
                if (source.get('status') != 'available' or not source.get('policy') or not source.get('revision_id')
                        or sha(raw.encode()) != source.get('response_sha256') or not 12 <= len(quote) <= 1000
                        or len(re.findall(r'\w+', quote)) < 2
                        or quote not in source.get('text', '') or support.get('revision_id') != source['revision_id']):
                    reasons.append('Unavailable, trivial or unmatched revision evidence for ' + field)
            output[field] = authored['text']
            provenance[field] = supports
        if not output['core'] or not output['npc_static_bio']:
            reasons.append('Identity introduction and background required for importer compatibility')
        if reasons:
            pending.append({'identity': identity, 'reasons': sorted(set(reasons))})
            continue
        if limit and len(candidates) >= limit:
            pending.append({'identity': identity, 'reasons': ['Outside pilot limit']})
            continue
        record = entry['record']
        source = binding_source
        candidate = {'plugin': record['plugin'],
            'npc_name': entry['review']['character_key'], 'display_name': record['display_name'],
            'is_nonverbal_creature': False, 'fields': output,
            'source_url': 'https://' + source['source']['host'] + '/wiki/' + urllib.parse.quote(source['source']['title'])}
        if record['kind'] == 'reference':
            candidate.update(mapping_kind='reference', reference_local_formid=record['local_id'])
        else:
            candidate['baseid'] = record['local_id']
        candidates.append({'candidate': candidate, 'field_evidence': provenance,
            'identity_evidence': entry['review'], 'source_binding': binding, 'source_ids': [binding_id]})
    for identity in sorted(set(eligible) - seen):
        pending.append({'identity': identity, 'reasons': ['No reviewed original draft/evidence']})
    return candidates, sorted(pending, key=lambda row: row['identity'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--inventory', type=Path)
    parser.add_argument('--review', type=Path)
    parser.add_argument('--evidence', type=Path)
    parser.add_argument('--sources', type=Path)
    parser.add_argument('--cache', type=Path)
    parser.add_argument('--online', action='store_true')
    parser.add_argument('--pilot', action='store_true', help='At most ten eligible drafts')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    if output.is_relative_to(ROOT) or (args.cache and args.cache.resolve().is_relative_to(ROOT)):
        parser.error('Outputs and cache must stay outside the checkout')
    if args.online and not (args.sources and args.cache):
        parser.error('Online mode requires sources and external cache')
    if bool(args.sources) != bool(args.cache):
        parser.error('Sources and cache must be supplied together')
    if args.review and not args.inventory:
        parser.error('Actor review requires its pinned inventory')
    report = audit(args.inventory, args.review, ROOT / 'data/fallout4_content_pack.json')
    ledger = evidence_ledger(args.evidence, report)
    sources = research(args.sources, args.cache, args.online) if args.sources and args.cache else {}
    candidates, pending = drafts(report, ledger, sources, 10 if args.pilot else 0)
    write(output / 'coverage.json', report)
    write(output / 'drafts.json', {'schema': 'synth.biography-drafts.v1', 'review_required': True,
          'npcs': candidates, 'sources': sources, 'pending': pending,
          'notice': 'Not installed. Review identities, field evidence, starting state and share-alike attribution before catalog inclusion.'})
    header = next(csv.reader((ROOT / 'data/fallout4_named_bios.csv').open(encoding='utf-8', newline='')))
    stream = io.StringIO(newline='')
    writer = csv.DictWriter(stream, fieldnames=header, lineterminator='\n')
    writer.writeheader()
    for row in candidates:
        npc = row['candidate']
        writer.writerow({**dict.fromkeys(header, ''), 'npc_name': npc['npc_name'], **npc['fields']})
    (output / 'drafts.csv').write_text(stream.getvalue(), encoding='utf-8', newline='')
    print(json.dumps({'catalog_biographies': report['catalog_biographies'], 'catalog_bindings': report['catalog_bindings'],
        'coverage': report['totals'], 'drafts': len(candidates), 'pending': len(pending),
        'sources': dict(Counter(source['status'] for source in sources.values()))}, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, IndexError, TypeError, AttributeError, OSError) as error:
        raise SystemExit('Extractor refused input: ' + str(error))
