<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/compact_context_history.php';

final class CompactNpcContextHistoryTest extends TestCase
{
    public function testCurrentNativeInputIsSeparateFromCompactAndExpandedHistory(): void
    {
        require_once __DIR__.'/../../lib/prompt_composition.php';
        require_once __DIR__.'/../../lib/synth_equipment_prompt.php';
        $utterance = 'RANGROO: hey';
        $head = [['role'=>'system','content'=>'Roleplay as Nick Valentine.']];
        $prior = [['role'=>'user','content'=>$utterance]];
        $cue = [['role'=>'user','content'=>"Write Nick's next dialogue line."]];
        $equipment = '<current_observed_equipment unavailable="true">Current gear is unknown.</current_observed_equipment>';
        foreach (['input_text', 'input_audio'] as $wireType) {
            foreach (['inputtext', 'inputtext_s', 'narrator_inputtext'] as $requestType) {
                $live = synthCurrentPlayerInputMessages(['protocol_version'=>2,'type'=>$wireType], [$requestType,0,0,$utterance]);
                $this->assertSame([['role'=>'user','content'=>$utterance]], $live);
                foreach ([[], $prior] as $history) {
                    foreach ([false, true] as $compact) {
                        $messages = $compact
                            ? synthAppendCompactHistoryToPrompt($head, synthFormatCompactNpcContextHistory($history, 'Nick Valentine'), true)
                            : array_merge($head, $history);
                        $messages = synth_append_equipment_observer_context(
                            array_merge($messages, $cue),
                            ['protocol_version'=>2,'type'=>$wireType],
                            $equipment,
                            $live
                        );
                        $this->assertSame($live[0], end($messages));
                        $this->assertSame('system', $messages[count($messages)-2]['role']);
                        $this->assertStringContainsString($equipment, $messages[count($messages)-2]['content']);
                        // The previous identical utterance must remain; only the current input is appended.
                        if (!$compact && $history !== []) $this->assertSame($prior[0], $messages[1]);
                    }
                }
            }
        }
        foreach (['trigger', 'action_result', 'context', 'cancel', 'halt'] as $type) {
            $this->assertSame([], synthCurrentPlayerInputMessages(['protocol_version'=>2,'type'=>$type], ['inputtext',0,0,$utterance]));
        }
        $this->assertSame([], synthCurrentPlayerInputMessages(['protocol_version'=>1,'type'=>'input_text'], ['inputtext',0,0,$utterance]));
        $this->assertSame([], synthCurrentPlayerInputMessages(['protocol_version'=>2,'type'=>'input_text'], ['inputtext',0,0,'  ']));
        require_once __DIR__.'/../../lib/request.php';
        $normalized = ['inputtext',0,0,json_encode(['player'=>'RANGROO','npc'=>'Nick Valentine','text'=>'Where is Diamond City?'])];
        synth_adapt_json_input_payload_for_pipeline($normalized);
        $this->assertSame([['role'=>'user','content'=>'RANGROO: Where is Diamond City? (Talking to Nick Valentine)']],
            synthCurrentPlayerInputMessages(['protocol_version'=>2,'type'=>'input_text'], $normalized));
        $source = file_get_contents(__DIR__.'/../../main_synth_pipeline.php');
        $append = '$contextData = synth_append_equipment_observer_context(';
        $this->assertSame(1, substr_count($source, $append));
        $this->assertLessThan(strpos($source, $append), strpos($source, '$head = synthAppendCompactHistoryToPrompt'));
        $this->assertStringContainsString('if ($currentPlayerInput === [] && !empty($GLOBALS["DIRECT_NARRATOR_DIALOGUE"])', $source);
    }

    public function testNativeRoleEvidenceSurvivesFormattingUntilVisibilityFiltering(): void
    {
        require_once __DIR__.'/../../lib/data_functions.php';
        require_once __DIR__.'/../../lib/chat_helper_functions.php';
        $keys=['SYNTH_REQUEST_EVENT','SYNTH_NAME','HIDE_NARRATOR_DIALOGUE'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2]];
        $GLOBALS['SYNTH_NAME']='Settler'; $GLOBALS['HIDE_NARRATOR_DIALOGUE']=true;
        $rows=[
            ['role'=>'npc','content'=>'The Narrator: An NPC speaking.','_g'=>100,'_synth_history_speech'=>'npc'],
            ['role'=>'narratorchat','content'=>'The Narrator: Narrator speaking.','_g'=>110,'_synth_history_speech'=>'narrator'],
            ['role'=>'npc','content'=>'The Narrator: Unproven role.','_g'=>120],
            ['role'=>'npc','content'=>'Settler: Private (Talking to The Narrator)','_g'=>130,'_synth_history_speech'=>'npc'],
        ];
        try {
            $formatted=replaceRoles(compactHistoricContext($rows,'Settler'),'Settler',-10);
            $this->assertSame('npc',$formatted[0]['_synth_history_speech']);
            $visible=filterHistoricContextForNarratorVisibility($formatted,'Settler');
            $this->assertSame([['role'=>'user','content'=>'The Narrator: An NPC speaking.']],$visible);
            $narratorView=filterHistoricContextForNarratorVisibility($formatted,'The Narrator');
            $this->assertCount(4,$narratorView);
            foreach ($narratorView as $entry) $this->assertArrayNotHasKey('_synth_history_speech',$entry);
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>1]];
            $this->assertSame([],filterHistoricContextForNarratorVisibility($formatted,'Settler'));
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key]; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testNativeHistoryWindowPreservesUtteranceBoundariesAndSourceFloor(): void
    {
        require_once __DIR__ . '/../../lib/data_functions.php';
        $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['protocol_version' => 2]];
        $rows = [
            ['role'=>'assistant','content'=>'Piper: One (talking to Player)', '_g'=>100],
            ['role'=>'assistant','content'=>'Piper: Two (talking to Nick)', '_g'=>150],
            ['role'=>'assistant','content'=>'{"character":"Piper","message":"Three"}', '_g'=>175],
            ['role'=>'npc','content'=>'Nick: Four (talking to Piper)', '_g'=>200],
            ['role'=>'npc','content'=>'Nick: Five (talking to Player)', '_g'=>250],
            ['role'=>'player','content'=>'Player: Current question.', '_g'=>300],
        ];
        try {
            foreach ([false, true] as $compactInfo) {
                $compacted = compactHistoricContext($rows, 'Piper', $compactInfo);
                $this->assertSame($rows, $compacted);
                $history = replaceRoles($compacted, 'Piper', -5);
                $this->assertCount(5, $history);
                $this->assertSame(150, $GLOBALS['CONTEXT_WINDOW_FLOOR']);
                $this->assertSame(array_column(array_slice($rows,1), 'content'), array_column($history,'content'));
                $this->assertSame(['assistant','assistant','user','user','user'], array_column($history,'role'));
                foreach ($history as $entry) $this->assertArrayNotHasKey('_g',$entry);
                $block = synthFormatCompactNpcContextHistory($history, 'Piper');
                $this->assertStringContainsString('Piper, speaking to Nick: Two', $block);
                $this->assertStringContainsString('Nick, speaking to Piper: Four', $block);
                $this->assertStringContainsString('Nick, speaking to Player: Five', $block);
            }
        } finally {
            if ($previous === null) unset($GLOBALS['SYNTH_REQUEST_EVENT']); else $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
            unset($GLOBALS['CONTEXT_WINDOW_FLOOR'], $GLOBALS['CONTEXT_BUILDING_DATA']);
        }
    }

    public function testShortTermMemoryFloorSurvivesCompactionWithoutLeakingMetadata(): void
    {
        require_once __DIR__ . '/../../lib/data_functions.php';
        $GLOBALS['SYNTH_NAME'] = 'Piper';
        $rows = [
            ['role' => 'player', 'content' => 'Sole Survivor: Earlier.', '_g' => 50],
            ['role' => 'assistant', 'content' => 'Piper: First reply.', '_g' => 100],
            ['role' => 'assistant', 'content' => 'Piper: Second reply.', '_g' => 150],
            ['role' => 'npc', 'content' => 'Nick: First note.', '_g' => 200],
            ['role' => 'npc', 'content' => 'Nick: Second note.', '_g' => 250],
            ['role' => 'player', 'content' => 'Sole Survivor: Current question.', '_g' => 300],
        ];
        foreach ([false, true] as $compactInfo) {
            $compacted = compactHistoricContext($rows, 'Piper', $compactInfo);
            $history = replaceRoles($compacted, 'Piper', -3);
            $this->assertSame(100, $GLOBALS['CONTEXT_WINDOW_FLOOR']);
            $this->assertCount(3, $history);
            foreach ($history as $row) {
                $this->assertArrayNotHasKey('_g', $row);
                $this->assertArrayNotHasKey('gamets', $row);
            }
            $this->assertStringContainsString('Second reply.', $history[0]['content']);
            $this->assertStringContainsString('Second note.', $history[1]['content']);
            $this->assertStringContainsString('Current question.', $history[2]['content']);
        }
        replaceRoles([], 'Piper', -3);
        $this->assertSame(PHP_INT_MAX, $GLOBALS['CONTEXT_WINDOW_FLOOR']);
        replaceRoles([['role' => 'user', 'content' => 'Unknown time']], 'Piper', -3);
        $this->assertSame(0, $GLOBALS['CONTEXT_WINDOW_FLOOR']);
        unset($GLOBALS['CONTEXT_WINDOW_FLOOR'], $GLOBALS['CONTEXT_BUILDING_DATA']);
    }

    public function testCompactPromptInfoDefaultsOnAndCanPreserveOriginalPrompt(): void
    {
        require_once __DIR__ . '/../../lib/prompt_composition.php';
        $schema = json_decode(file_get_contents(__DIR__ . '/../../conf/conf_schema.json'), true);
        $this->assertTrue($schema['PROMPT_HEAD_MARKDOWN_ENABLED']['default']);
        require_once __DIR__ . '/../../lib/settings.php';
        $this->assertFalse(synthSettingsNormalizeScalar('false', $schema['PROMPT_HEAD_MARKDOWN_ENABLED']));
        $this->assertTrue(synthSettingsNormalizeScalar('true', $schema['PROMPT_HEAD_MARKDOWN_ENABLED']));
        $xml = " <character>\r\n<personality>Direct</personality>\r\n</character>\r\n";
        $this->assertSame($xml, synthFormatPromptHeadSection($xml, false));
    }

    public function testCompactPromptInfoKeepsHierarchyFieldsAndHyphenLists(): void
    {
        require_once __DIR__ . '/../../lib/prompt_composition.php';
        $xml = "<world>\n<worldspace>The Commonwealth</worldspace>\n<location>Diamond City</location>\n</world>\n"
            . "<character>\n<personality>Direct</personality>\n<equipment>\n#Current Equipment\n"
            . "• Rifle\n</equipment>\n</character>\n"
            . "<nearby_actors>\n#NEARBY ACTORS/NPC IN THE SCENE\n##Piper (00002F1F)\n</nearby_actors>\n"
            . "<available_actions_list>\nAVAILABLE ACTION: Follow\n</available_actions_list>\n"
            . "<general_instructions>\nUse <inventory> for reference.\n</general_instructions>\n"
            . "<fallout_context>\n<knowledge>\n<catalog contract=\"synth.worldknowledge-catalog.v1\" status=\"grounded\">\n"
            . "<article topic=\"diamond_city\" source=\"location\" access=\"basic\">\n"
            . "<content>Diamond City occupies Fenway Park.</content>\n</article>\n</catalog>\n</knowledge>\n</fallout_context>";
        $formatted = synthFormatPromptHeadSection($xml, true);
        foreach ([
            '# World', '- Worldspace: The Commonwealth', '- Location: Diamond City',
            '# Character', '## Personality', '## Equipment', '- Rifle',
            '# Nearby Actors', '- Piper (00002F1F)', '# Available Actions',
            '- AVAILABLE ACTION: Follow', 'Use `Inventory` for reference.',
            '# Fallout Context', '## Knowledge', '### Catalog',
            '- Contract: synth.worldknowledge-catalog.v1', '- Status: grounded',
            '#### Article', '- Topic: diamond_city', '- Source: location',
            '- Access: basic', '##### Content', 'Diamond City occupies Fenway Park.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $formatted);
        }
        $this->assertStringNotContainsString('<', $formatted);
        $this->assertStringNotContainsString('Current Equipment', $formatted);
        $head = [['role' => 'system', 'content' => $formatted]];
        $history = "# Sole Survivor: Hello.\n# Piper: Hello.";
        $this->assertSame(
            synthAppendCompactHistoryToPrompt($head, $history),
            synthAppendCompactHistoryToPrompt($head, $history, false)
        );
        $this->assertSame(
            rtrim($formatted) . "\n\n# Conversation History\n\n- Sole Survivor: Hello.\n- Piper: Hello.",
            synthAppendCompactHistoryToPrompt($head, $history, true)[0]['content']
        );
        $this->assertSame($head, synthAppendCompactHistoryToPrompt($head, '', true));
    }

    public function testSettingIsEnabledByDefaultAndCanBeDisabled(): void
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../../conf/conf_schema.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['COMPACT_NPC_CONTEXT_HISTORY', 'PROMPT_HEAD_MARKDOWN_ENABLED', 'SHORT_TERM_MEMORY_IN_COMPACT_CHAT'] as $key) {
            $this->assertTrue($schema[$key]['default']);
        }
        $GLOBALS['SYNTH_NAME'] = 'Piper';
        $this->assertTrue(synthCompactNpcContextHistoryEnabled());
        $this->assertTrue(synthShouldCompactNpcContextHistory());
        $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'] = false;
        $this->assertFalse(synthCompactNpcContextHistoryEnabled());
        $this->assertFalse(synthShouldCompactNpcContextHistory());
    }

    public function testNarratorRemainsExcluded(): void
    {
        $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'] = true;
        $this->assertFalse(synthShouldCompactNpcContextHistory('The Narrator'));
        $this->assertTrue(synthShouldCompactNpcContextHistory('Piper'));
    }

    public function testFormatsFalloutConversationAsOnePlaintextBlock(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => '{"speaker":"Piper","listener":"Sole Survivor","action":"Talk","text":"Careful around the market."}'],
            ['role' => 'user', 'content' => '*(Context location: Diamond City background chat) Nick: Drinks are on the house.*'],
            ['role' => 'user', 'content' => 'Sole Survivor: What is out there? (Speaking privately to Piper)'],
            ['role' => 'user', 'content' => 'LOCATION CHANGE to Diamond City Market, worldspace: The Commonwealth, timeline mark: 0 hours ago'],
        ];
        $this->assertSame(implode("\n", [
            '# Piper, speaking to Sole Survivor: Careful around the market.',
            '# Background dialogue at Diamond City: Nick: Drinks are on the house.',
            '# Sole Survivor, speaking to Piper: What is out there?',
            '# The current scene is at Diamond City Market in The Commonwealth.',
        ]), synthFormatCompactNpcContextHistory($history, 'Piper'));
    }

    public function testKeepsActionsAndToolHistoryInPlaintext(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => '{"speaker":"Piper","listener":"Sole Survivor","text":"Lead the way.","action":"Follow","target":"Sole Survivor"}'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'call_1', 'function' => ['name' => 'StopFollowing'],
            ]]],
            ['role' => 'tool', 'content' => 'StopFollowing completed.'],
        ];
        $this->assertSame(implode("\n", [
            '# Piper, speaking to Sole Survivor: Lead the way. [Action: Follow, targeting Sole Survivor]',
            '# Requested action: StopFollowing.',
            '# Tool result: StopFollowing completed.',
        ]), synthFormatCompactNpcContextHistory($history, 'Piper'));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'], $GLOBALS['SYNTH_NAME']);
    }
}
