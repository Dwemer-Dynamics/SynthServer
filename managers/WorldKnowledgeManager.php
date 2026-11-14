<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;
use Synth\Core\SettingsRepository;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once dirname(__DIR__).'/core/dbmanager/repositories/SettingsRepository.php';
require_once dirname(__DIR__).'/lib/settings.php';
require_once dirname(__DIR__).'/lib/worldknowledge_runtime.php';
require_once dirname(__DIR__).'/lib/worldknowledge_forced_context.php';
require_once dirname(__DIR__).'/lib/worldknowledge_vocabulary.php';

// World Knowledge for one native conversation turn: the shared effective catalogue, deterministic retriever,
// access tiers and article renderer, driven only by explicit inputs (server configuration, stored general settings,
// the admitted NPC and profile rows, and this request's bound snapshot). It binds no ordinary per-turn identity or
// settings globals; shared helpers it calls may still read process globals such as the legacy claimed-request flag.
// Deliberate native limits: no extractor/LLM fallback, no stored current-topic signal and no forced location/actor
// articles, so retrieval never adds a provider call. Quest participant tiers use the view the native turn already read
// with its explicit claimed owner; without one they fall back to the legacy reader, which stays unavailable without a
// claimed legacy request, so only reviewed public quest text can then be granted.
final class WorldKnowledgeManager
{
    // Runtime names this feature reads, layered Global -> Profile -> NPC like the ordinary pipeline.
    private const SETTINGS = ['WORLDKNOWLEDGE_INFINIUM', 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK', 'WORLDKNOWLEDGE_CUSTOM',
        'WORLDKNOWLEDGE_AMOUNT', 'WORLDKNOWLEDGE_RESULT_LIMIT', 'RACE_WORLDKNOWLEDGE', 'FACTION_WORLDKNOWLEDGE',
        'LOCATION_WORLDKNOWLEDGE', 'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS', 'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM',
        'WORLDKNOWLEDGE', 'SYNTH_NAME'];
    public const ARTICLE_BYTES = 4000;

    public function __construct(private $db, private array $config, private SettingsRepository $settings) {}

    // The bounded <oghma> block for this turn, or '' when disabled, unavailable or no article applies. Unmatched
    // input selects no topic article but may still return the proactive vocabulary article. $history is this
    // turn's already-read native conversation, used only for the deterministic short follow-up fallback. $quest is
    // this turn's locked quest reader view (ContextManager::questKnowledge), used only for participant access.
    public function context(TurnRequest $request, PlacedCharacter $character, array $history, ?array $quest = null): string
    {
        $started = hrtime(true);
        $npc = $character->npc;
        $profile = $character->profile;
        $input = $request->text();
        $trace = ['algorithm_version' => \SynthWorldKnowledgeRetriever::VERSION, 'status' => 'disabled',
            'request_type' => 'conversation', 'npc_name' => strval($npc['npc_name'] ?? ''), 'input_text' => $input,
            'normalized_input' => \SynthWorldKnowledgeRetriever::normalize($input), 'catalog_id' => '', 'catalog_version' => '',
            'catalog_checksum' => '', 'grounded_matches' => [], 'rejected_candidates' => [], 'tag_decisions' => [],
            'context_tags' => [], 'fallback' => ['eligible' => false, 'attempted' => false, 'native' => 'deterministic_only'],
            'context_fallback' => ['eligible' => false, 'attempted' => false, 'used' => false], 'forced_signals' => [],
            'access_decisions' => [], 'selected_articles' => [], 'settings' => [], 'prompt_hash' => '',
            'retrieval_elapsed_ms' => 0.0, 'elapsed_ms' => 0.0];
        try {
            $inputs = $this->inputs($npc, $profile);
            $trace['settings'] = $effective = \synthWorldKnowledgeEffectiveSettings($inputs, $npc, $profile);
            $values = $effective['values'];
            // Disabled: no catalogue, tag or quest read.
            if (!$values['enabled']) return $this->finish($trace, '', $started);
            $catalog = \synthWorldKnowledgeFetchEffectiveCatalog($this->db);
            if ($catalog === []) { $trace['status'] = 'unavailable'; return $this->finish($trace, '', $started); }
            foreach ($catalog as $row) {
                if (strval($row['source_kind'] ?? '') !== 'factory' || strval($row['catalog_version'] ?? '') === '') continue;
                $trace['catalog_id'] = strval($row['catalog_id'] ?? '');
                $trace['catalog_version'] = strval($row['catalog_version']);
                $result = $this->db->execQuery('SELECT checksum_sha256 FROM synth.worldknowledge_catalogs
                    WHERE catalog_id=$1 AND catalog_version=$2 LIMIT 1', [$trace['catalog_id'], $trace['catalog_version']]);
                $trace['catalog_checksum'] = strval(($result ? pg_fetch_assoc($result) : false)['checksum_sha256'] ?? '');
                break;
            }

            $topicCount = intval($values['topic_count']);
            $retriever = new \SynthWorldKnowledgeRetriever($catalog);
            $retrievalStarted = hrtime(true);
            $retrieval = $retriever->extract($input, [], $topicCount);
            $trace['context_fallback']['eligible'] = $retrieval['topics'] === []
                && \SynthWorldKnowledgeRetriever::shouldUsePreviousExchange($input);
            if ($trace['context_fallback']['eligible']) {
                $previous = self::previousExchange($history, $input);
                if ($previous !== '') {
                    $trace['context_fallback']['attempted'] = true;
                    $previousRetrieval = $retriever->extract($previous, [], 1);
                    if ($previousRetrieval['topics'] !== []) {
                        foreach ($previousRetrieval['matches'] as &$match) $match['context_source'] = 'previous_exchange';
                        unset($match);
                        $retrieval = $previousRetrieval;
                        $trace['context_fallback']['used'] = true;
                    }
                }
            }
            $trace['grounded_matches'] = $retrieval['matches'];
            $trace['rejected_candidates'] = $retrieval['rejected'];
            $trace['tag_decisions'] = $retrieval['tag_decisions'];
            $trace['fallback']['eligible'] = boolval($retrieval['fallback_eligible']);
            $trace['retrieval_elapsed_ms'] = round((hrtime(true) - $retrievalStarted) / 1_000_000, 3);
            $topics = array_slice($retrieval['topics'], 0, intval($values['result_limit']));

            $access = ['npc' => $npc, 'quest_view' => $quest !== null ? \synthQuestAccessViewFromKnowledge($quest)
                : \synthQuestAccessReaderView($this->db, $request->event)];
            $world = $request->context['payload']['world'] ?? null;
            $tags = \synthWorldKnowledgeKnowledgeTags(['db' => $this->db, 'npc' => $npc,
                'tags' => strval($inputs['WORLDKNOWLEDGE'] ?? ''), 'name' => strval($inputs['SYNTH_NAME'] ?? ''),
                'world' => is_array($world) ? $world : []]);
            $trace['context_tags'] = $tags;
            $articles = [];
            $injected = [];
            $vocabulary = \synthWorldKnowledgeVocabularyContext($catalog, $tags, $access);
            if ($vocabulary !== null) {
                $articles[] = $this->article($vocabulary['row'], $vocabulary['decision'], 'vocabulary', $trace);
                $injected['wasteland_vocabulary'] = true;
            }
            $rowsByTopic = [];
            foreach ($catalog as $row) {
                $canonical = trim(strval($row['canonical_topic'] ?? \synthWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? ''))));
                if ($canonical !== '') $rowsByTopic[$canonical] = $row;
            }
            foreach ($topics as $topic) {
                $row = $rowsByTopic[$topic] ?? null;
                $key = \synthWorldKnowledgeNormalizeCanonicalTopic(strval($topic));
                if (!is_array($row) || isset($injected[$key])) continue;
                $injected[$key] = true;
                $decision = \synthWorldKnowledgeAccessDecision($row, $tags, $access);
                $articles[] = $this->article($row, $decision, 'conversation', $trace);
            }
            $trace['status'] = $articles === [] ? 'no_match' : 'grounded';
            if ($articles === []) return $this->finish($trace, '', $started);
            $block = '<oghma contract="oghma-parity-v1" status="grounded">'."\n".implode("\n", $articles)."\n</oghma>";
            $trace['prompt_hash'] = hash('sha256', $block);
            return $this->finish($trace, $block, $started);
        } catch (\SYNTHProtocolCancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable) {
            // Lore is optional context: an unreadable catalogue or setting yields no articles, never a failed turn.
            $trace['status'] = 'unavailable';
            return $this->finish($trace, '', $started);
        }
    }

    // Configuration then stored general settings (Global), then profile metadata, then the NPC's own tags and
    // name followed by its metadata and extended_data overrides. Each override keeps the ordinary pipeline's
    // admission rule, so an explicit false always wins over a lower layer.
    private function inputs(array $npc, array $profile): array
    {
        $inputs = array_intersect_key($this->config, array_flip(self::SETTINGS));
        foreach ($this->settings->values(self::SETTINGS) as $id => $raw) {
            $inputs[$id] = \synthSettingsNormalizeScalar($raw, \synthGetSchemaDefinition($id));
        }
        foreach (self::decode($profile['metadata'] ?? null) as $key => $value) {
            if ($value !== null && $value !== '') self::apply($inputs, strval($key), $value);
        }
        $inputs['WORLDKNOWLEDGE'] = strval($npc['worldknowledge_tags'] ?? '');
        if (isset($npc['npc_name'])) $inputs['SYNTH_NAME'] = strval($npc['npc_name']);
        foreach (['metadata', 'extended_data'] as $field) {
            foreach (self::decode($npc[$field] ?? null) as $key => $value) {
                if (!empty($value) || is_numeric($value) || is_bool($value)) self::apply($inputs, strval($key), $value);
            }
        }
        return $inputs;
    }

    // Same normalization as synthApplyOverrideValueToGlobals for the flat names above, into the explicit map.
    private static function apply(array &$inputs, string $key, mixed $value): void
    {
        $key = trim($key);
        if (!in_array($key, self::SETTINGS, true)) return;
        $definition = \synthGetSchemaDefinition($key);
        if ($definition !== []) $value = \synthSettingsNormalizeScalar(\synthSettingsStringifyValue($value), $definition);
        elseif ($value === 'true') $value = true;
        elseif ($value === 'false') $value = false;
        $inputs[$key] = $value;
    }

    private static function decode(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        return is_array($value) ? $value : [];
    }

    // At most the two most recent distinct earlier lines, oldest first, without talking-to labels.
    private static function previousExchange(array $history, string $input): string
    {
        $current = \SynthWorldKnowledgeRetriever::normalize($input);
        $seen = [];
        $parts = [];
        foreach (array_reverse($history) as $message) {
            $text = preg_replace('/\((?:(?:talking|whispering|shouting)|speaking privately)\s+to\s+[^()]+\)/iu', ' ',
                strval($message['content'] ?? '')) ?? '';
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            $key = \SynthWorldKnowledgeRetriever::normalize($text);
            if ($key === '' || $key === $current || isset($seen[$key])) continue;
            $seen[$key] = true;
            $parts[] = $text;
            if (count($parts) >= 2) break;
        }
        return mb_strcut(implode("\n", array_reverse($parts)), 0, 4096, 'UTF-8');
    }

    private function article(array $row, array $decision, string $source, array &$trace): string
    {
        $decision['source'] = $source;
        $decision['description'] = mb_strcut(strval($decision['description'] ?? ''), 0, self::ARTICLE_BYTES, 'UTF-8');
        $trace['access_decisions'][] = $decision;
        $trace['selected_articles'][] = ['topic' => strval($decision['topic'] ?? ''), 'level' => $decision['level'],
            'source_kind' => $row['source_kind'] ?? '', 'catalog_id' => $row['catalog_id'] ?? null,
            'catalog_version' => $row['catalog_version'] ?? null];
        return \synthWorldKnowledgeRenderArticleXml($row, $decision, $source);
    }

    private function finish(array $trace, string $block, int $started): string
    {
        $trace['elapsed_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
        \synthWorldKnowledgeRecordAudit($this->db, $trace);
        return $block;
    }
}
