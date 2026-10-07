<?php

require_once __DIR__.'/synth_rechat_prepare.php';
require_once __DIR__.'/core/npc_master.class.php';
require_once __DIR__.'/core/narrator.class.php';
require_once __DIR__.'/core/core_profiles.class.php';
require_once __DIR__.'/core/llm_connector.class.php';
require_once __DIR__.'/core/tts_connector.class.php';
require_once __DIR__.'/llm_randomizer.php';

// Load only the prepared role's live profile. Voice synthesis/resolution remains in the speech stage, outside this lock.
function synth_rechat_load_profile($db, array $event): array
{
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event) {
        if ($owner!==$event) throw new LogicException('rechat_profile_owner_changed');
        if (!@pg_query($connection,'SAVEPOINT synth_rechat_profile')) throw new RuntimeException('rechat_profile_savepoint_failed');
        try {
            $selection=synth_rechat_prepared_selection($db,$event);
            if ($selection['role']==='terminal') throw new LogicException('rechat_profile_terminal');
            $npcMaster=new NpcMaster();
            $narrator=null;
            if ($selection['role']==='npc') {
                $character=$npcMaster->getById($selection['npc_id']);
                $stateWriter=$npcMaster;
            } else {
                $narrator=new Narrator();
                $character=$narrator->getNarratorData();
                $character['metadata']=$narrator->get('rechat_randomizer_state') ?? '{}';
                // The narrator's conceptual ID is not a core_npc_master primary key.
                $stateWriter=new class($narrator) {
                    public function __construct(private Narrator $narrator) {}
                    public function updateByArray(array $data): bool
                    {
                        if (!$this->narrator->set('rechat_randomizer_state',$data['metadata'])) {
                            throw new RuntimeException('rechat_narrator_randomizer_write_failed');
                        }
                        return true;
                    }
                };
            }
            if (!$character || intval($character['profile_id'] ?? 0)!==intval($selection['profile_id'])
                || ($selection['role']==='npc' && intval($character['actor_identity_id'] ?? 0)!==intval($selection['actor_identity_id']))) {
                throw new LogicException('rechat_selected_binding_changed');
            }
            $profileManager=new CoreProfile();
            $profile=$profileManager->getById($selection['profile_id']);
            if (!$profile) throw new LogicException('rechat_selected_binding_changed');
            $slot=LLMRandomizer::getConnectorSlot($profile,$character,$stateWriter);
            $connectorManager=new LLMConnector();
            $connector=$connectorManager->getById(LLMRandomizer::getConnectorIdForSlot($profile,$slot));
            if (!$connector) throw new LogicException('rechat_profile_connector_unavailable');
            $token=strval($character['md5'] ?? '');
            if ($token==='') throw new LogicException('rechat_profile_token_unavailable');

            $inputKeys=['STTFUNCTION','TTSFUNCTION_PLAYER','TTSFUNCTION_PLAYER_VOICE','TTSFUNCTION_PLAYER_VOICE_ID',
                'TTSFUNCTION_PLAYER_LANGUAGE'];
            $inputSettings=array_intersect_key($GLOBALS,array_fill_keys($inputKeys,true));
            $connectorManager->setOldGlobals($connector);
            $profileManager->setOldGlobals($profile);
            if ($narrator!==null) {
                $narrator->loadIntoGlobals();
                $narrator->loadCharacterIntoGlobals();
            } else {
                // Preserve the immutable captured label for prompt/wire routing without renaming the managed row.
                $promptCharacter=$character;
                $promptCharacter['npc_name']=$selection['identity']['display_name'];
                $npcMaster->setOldGlobalsFromCurrentNpcData($promptCharacter,false);
            }
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=$character;
            $GLOBALS['SYNTH_CORE_CURRENT_PROFILE_DATA']=$profile;
            $GLOBALS['SYNTH_CORE_CURRENT_CONNECTOR_DATA']=$connector;
            $GLOBALS['active_profile']=$token;
            $GLOBALS['SYNTH_NAME']=$selection['role']==='npc' ? $selection['identity']['display_name'] : 'The Narrator';
            foreach ($inputKeys as $key) {
                if (array_key_exists($key,$inputSettings)) $GLOBALS[$key]=$inputSettings[$key]; else unset($GLOBALS[$key]);
            }
            // Profile/NPC overrides cannot restore actions which the admitted chain disabled.
            $GLOBALS['RECHAT_ALLOW_ACTIONS']=$selection['policy']['allow_actions'];
            $GLOBALS['RECHAT_MODE']=$selection['policy']['mode'];
            if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_profile')) throw new RuntimeException('rechat_profile_release_failed');
            return ['selection'=>$selection,'character'=>$character,'profile'=>$profile,'connector'=>$connector];
        } catch (Throwable $error) {
            if (!@pg_query($connection,'ROLLBACK TO SAVEPOINT synth_rechat_profile')
                || !@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_profile')) throw new RuntimeException('rechat_profile_rollback_failed',0,$error);
            throw $error;
        }
    },2000);
}
