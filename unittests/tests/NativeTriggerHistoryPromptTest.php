<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/synth_history_trigger_prompt.php';
require_once __DIR__ . '/../../lib/compact_context_history.php';

final class NativeTriggerHistoryPromptTest extends TestCase
{
    private function row(string $kind = 'combat_bark', bool $suffix = false): array
    {
        $payload = ['schema'=>'synth.trigger.v1','kind'=>$kind,'npc'=>'Codsworth',
            'npc_id'=>'0x0001CA7D','npc_plugin'=>'Fallout4.esm','playthrough_id'=>'test-save','game'=>'fo4',
            'actor_states'=>[['inventory'=>array_fill(0, 400, ['display_name'=>'Old Radroach sample', 'count'=>4])]],
            'active_quests'=>[['display_name'=>'A quest retained in immutable evidence']]];
        return ['history_event_id'=>52,'reader_identity_id'=>9,'type'=>$kind === 'combat_bark' ? 'combatbark' : $kind,
            'data'=>json_encode($payload, JSON_THROW_ON_ERROR) . ($suffix ? ' (Codsworth shouts during combat)' : '')];
    }

    public function testLargeCapturedTriggerUsesBoundedPromptProjectionWithoutEditingEvidence(): void
    {
        foreach (['combat_bark','bored','auto_greeting'] as $kind) {
            $row=$this->row($kind); $original=$row;
            $this->assertGreaterThan(15000,strlen($row['data']));
            $projected=synth_history_trigger_prompt_data($row,true);
            $this->assertLessThan(220,strlen($projected));
            $this->assertStringContainsString('Codsworth',$projected);
            $this->assertStringNotContainsString('Radroach',$projected);
            $this->assertStringNotContainsString('inventory',$projected);
            $this->assertSame($original,$row);
            $history=synthFormatCompactNpcContextHistory([
                ['role'=>'user','content'=>$projected],
                ['role'=>'user','content'=>'Player: Bring four stimpaks; leave at noon.'],
                ['role'=>'assistant','content'=>'{"speaker":"Codsworth","text":"Four stimpaks and noon, sir."}'],
            ],'Codsworth');
            $this->assertLessThan(420,strlen($history));
            $this->assertStringContainsString('four stimpaks; leave at noon',$history);
            $this->assertStringContainsString('Four stimpaks and noon, sir.',$history);
        }
        $source=file_get_contents(__DIR__.'/../../lib/data_functions.php');
        $projection=strpos($source,'$rowData = synth_history_trigger_prompt_data($row, $nativeHistory);');
        $this->assertIsInt($projection);
        $this->assertLessThan(strpos($source,'// Figure out location form location field',$projection),$projection);
    }

    public function testExistingCombatInfoactionSuffixIsProjectedOnlyForExactEvent(): void
    {
        $row=$this->row('combat_bark',true); $row['type']='infoaction';
        $this->assertStringStartsWith('A combat dialogue was requested',synth_history_trigger_prompt_data($row,true));
        $row['data']=str_replace('Codsworth shouts during combat','Jun shouts during combat',$row['data']);
        $this->assertSame($row['data'],synth_history_trigger_prompt_data($row,true));
    }

    public function testDialogueQuestActionsLegacyAndUnprovenRowsRemainExact(): void
    {
        $valid=$this->row();
        $this->assertSame($valid['data'],synth_history_trigger_prompt_data($valid,false));
        foreach (['inputtext','chat','quest_events','quest_observation','logaction','player_event','actor_equipment','rechat'] as $type) {
            $row=$valid; $row['type']=$type;
            $this->assertSame($row['data'],synth_history_trigger_prompt_data($row,true));
        }
        foreach (['history_event_id','reader_identity_id'] as $key) {
            $row=$valid; unset($row[$key]);
            $this->assertSame($row['data'],synth_history_trigger_prompt_data($row,true));
        }
        foreach (['not json','{"schema":"synth.trigger.v1"}',json_encode(['schema'=>'other','kind'=>'combat_bark']),
            $valid['data'].' trailing text',str_replace('combat_bark','quest_updated',$valid['data']),
            str_replace('"fo4"','"other"',$valid['data'])] as $data) {
            $row=$valid; $row['data']=$data;
            $this->assertSame($data,synth_history_trigger_prompt_data($row,true));
        }
    }
}
