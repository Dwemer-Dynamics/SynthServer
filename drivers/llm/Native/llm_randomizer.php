<?php

// Loaded - will use Logger class for runtime logging

/**
 * LLM Randomizer - Exponential probability-based connector rotation
 * 
 * Switches between the 4 LLM connectors with increasing probability
 * the longer a connector has been used consecutively.
 */
class LLMRandomizer {
    
    // Hardcoded settings optimized for 2-3 turn average
    private const BASE_CHANCE = 25;      // 25% initial chance
    private const MULTIPLIER = 2.0;       // 2x multiplier per use
    private const MAX_USES = 5;           // Force switch after 5 uses
    private const PLAYER2_FORCE_ENABLED_KEY = 'PLAYER2_FORCE_ALL_LLM';
    private const PLAYER2_FORCE_CONNECTOR_KEY = 'PLAYER2_FORCE_CONNECTOR_ID';
    private const PLAYER2_DEFAULT_LABEL = 'Player2 Local';
    private const PLAYER2_DEFAULT_URL = 'http://127.0.0.1:4315/v1/chat/completions';

    private static $player2ForceEnabled = null;
    private static $player2ForceConnectorId = null;

    private static function isTruthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int)$value) === 1;
        }

        $raw = strtolower(trim((string)$value));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
    
    /**
     * Determine which connector slot to use (1-4)
     * 
     * @param array $profileData Profile data with metadata
     * @param array $npcData NPC data with metadata
     * @param NpcMaster $npcMaster NPC manager instance for updates
     * @return int Connector slot to use (1-4)
     */
    public static function getConnectorSlot($profileData, &$npcData, $npcMaster) {
        // Check if randomizer is enabled in profile
        $profileMeta = [];
        if (!empty($profileData['metadata'])) {
            $tmp = json_decode($profileData['metadata'], true);
            if (is_array($tmp)) {
                $profileMeta = $tmp;
            }
        }
        
        $randomizerEnabled = self::isTruthy($profileMeta['LLM_RANDOMIZER_ENABLED'] ?? false);
        
        if (!$randomizerEnabled) {
            // Randomizer disabled, use global setting (no logging)
            return self::getGlobalConnectorSlot();
        }
        
        // Randomizer is enabled - log activity
        Logger::info("[LLM_RANDOMIZER] Active for NPC: " . ($npcData['npc_name'] ?? 'unknown'));
        
        // Randomizer enabled - apply exponential probability logic
        
        // Get NPC's randomizer state from metadata
        $npcMeta = [];
        if (!empty($npcData['metadata'])) {
            $tmp = json_decode($npcData['metadata'], true);
            if (is_array($tmp)) {
                $npcMeta = $tmp;
            }
        }
        
        $currentSlot = isset($npcMeta['randomizer_current_slot']) ? (int)$npcMeta['randomizer_current_slot'] : 1;
        $consecutiveUses = isset($npcMeta['randomizer_consecutive_uses']) ? (int)$npcMeta['randomizer_consecutive_uses'] : 0;
        
        // Ensure valid slot
        if ($currentSlot < 1 || $currentSlot > 4) {
            $currentSlot = 1;
        }
        
        // Check if we need to switch
        $shouldSwitch = false;
        
        // Force switch if max uses reached
        if ($consecutiveUses >= self::MAX_USES) {
            $shouldSwitch = true;
            Logger::info("[LLM_RANDOMIZER] Max uses ({" . self::MAX_USES . "}) reached for slot {$currentSlot}, forcing switch");
        } else {
            // Calculate probability: baseChance * (multiplier ^ consecutiveUses)
            $probability = self::BASE_CHANCE * pow(self::MULTIPLIER, $consecutiveUses);
            $probability = min($probability, 100); // Cap at 100%
            
            // Roll the dice
            $roll = mt_rand(1, 100);
            $shouldSwitch = $roll <= $probability;
            
            Logger::info("[LLM_RANDOMIZER] Slot {$currentSlot}, uses: {$consecutiveUses}, probability: " . round($probability, 1) . "%, roll: {$roll}, switch: " . ($shouldSwitch ? 'YES' : 'NO'));
        }
        
        if ($shouldSwitch) {
            // Pick a different random slot
            $availableSlots = [1, 2, 3, 4];
            $otherSlots = array_diff($availableSlots, [$currentSlot]);
            $newSlot = $otherSlots[array_rand($otherSlots)];
            
            // Update state
            $npcMeta['randomizer_current_slot'] = $newSlot;
            $npcMeta['randomizer_consecutive_uses'] = 1;
            
            $currentSlot = $newSlot;
            
            Logger::info("[LLM_RANDOMIZER] Switched to slot {$newSlot}");
        } else {
            // Increment consecutive uses
            $consecutiveUses++;
            $npcMeta['randomizer_consecutive_uses'] = $consecutiveUses;
        }
        
        // Save updated metadata back to NPC
        $npcData['metadata'] = json_encode($npcMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $npcMaster->updateByArray(['id' => $npcData['id'], 'metadata' => $npcData['metadata']]);
        
        return $currentSlot;
    }
    
    /**
     * Get the global connector slot setting (fallback when randomizer disabled)
     * 
     * @return int Connector slot (1-4)
     */
    private static function getGlobalConnectorSlot() {
        if (isset($GLOBALS['SYNTH_REQUEST_EVENT']['json']['payload']['control']['model_slot']))
            return intval(\Synth\Bootstrap::requestEvent()['json']['payload']['control']['model_slot']);
        $db = \Synth\Bootstrap::db();
        $result = ['value' => \Synth\Managers\ConfigManager\ConfigManager::shared()->get('conf', 'synth_profile_model')];
        
        if (isset($result['value']) && $result['value'] >= 1 && $result['value'] <= 4) {
            return (int)$result['value'];
        }
        
        return 1; // Default to primary
    }
    
    /**
     * Get connector ID for a given slot from profile data
     * 
     * @param array $profileData Profile data
     * @param int $slot Slot number (1-4)
     * @return int|null Connector ID or null
     */
    public static function getConnectorIdForSlot($profileData, $slot) {
        $forcedConnectorId = self::getForcedPlayer2ConnectorId();
        if (!empty($forcedConnectorId)) {
            return (int)$forcedConnectorId;
        }

        if (!is_array($profileData) || empty($profileData)) {
            return null;
        }

        $slotMap = [
            1 => 'llm_primary_id',
            2 => 'llm_secondary_id',
            3 => 'llm_tertiary_id',
            4 => 'llm_quaternary_id'
        ];

        $fieldName = $slotMap[$slot] ?? 'llm_primary_id';
        $selectedConnectorId = isset($profileData[$fieldName]) ? (int)$profileData[$fieldName] : 0;
        if ($selectedConnectorId > 0) {
            return $selectedConnectorId;
        }

        // An acknowledged native slot must not silently become a different model for this NPC.
        if (isset($GLOBALS['SYNTH_REQUEST_EVENT']['json']['payload']['control']['model_slot'])) return null;

        // Fallback: chosen slot is empty/unset, use first configured connector in profile.
        foreach (['llm_primary_id', 'llm_secondary_id', 'llm_tertiary_id', 'llm_quaternary_id'] as $fallbackField) {
            $candidateId = isset($profileData[$fallbackField]) ? (int)$profileData[$fallbackField] : 0;
            if ($candidateId > 0) {
                return $candidateId;
            }
        }

        return null;
    }

    public static function getConnectorIdForField($profileData, $fieldName) {
        $forcedConnectorId = self::getForcedPlayer2ConnectorId();
        if (!empty($forcedConnectorId)) {
            return (int)$forcedConnectorId;
        }

        if (!is_array($profileData) || empty($fieldName)) {
            return null;
        }

        return isset($profileData[$fieldName]) ? (int)$profileData[$fieldName] : null;
    }
    
    /**
     * Get slot name for logging
     * 
     * @param int $slot Slot number (1-4)
     * @return string Slot name
     */
    public static function getSlotName($slot) {
        $names = [
            1 => 'Standard',
            2 => 'Fast',
            3 => 'Powerful',
            4 => 'Experimental'
        ];
        return $names[$slot] ?? 'Unknown';
    }

    public static function isPlayer2ForceEnabled() {
        if (self::$player2ForceEnabled !== null) {
            return self::$player2ForceEnabled;
        }

        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            self::$player2ForceEnabled = false;
            return false;
        }

        self::$player2ForceEnabled = self::isTruthy(\Synth\Managers\ConfigManager\ConfigManager::shared()->get('conf', self::PLAYER2_FORCE_ENABLED_KEY, '0'));
        return self::$player2ForceEnabled;
    }

    public static function setPlayer2ForceEnabled($enabled) {
        self::upsertConfOpt(self::PLAYER2_FORCE_ENABLED_KEY, $enabled ? '1' : '0');
        self::$player2ForceEnabled = (bool)$enabled;

        if ($enabled) {
            return self::ensurePlayer2ConnectorId();
        }

        return self::getStoredPlayer2ConnectorId();
    }

    public static function ensurePlayer2ConnectorId() {
        $existingId = self::getStoredPlayer2ConnectorId();
        if (!empty($existingId)) {
            return (int)$existingId;
        }

        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            return null;
        }

        $connectors = \Synth\Core\DbManager\Dao\LlmConnectorDao::current($db);
        $connectorId = intval($connectors->findIdByDriver('player2json', 'player2 local'));

        $player2ApiBadgeId = self::ensurePlayer2ApiBadgeId();

        if ($connectorId <= 0) {
            $metadata = json_encode(['player2_game_key' => 'SYNTH'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $insertedConnectorId = $connectors->insertPlayer2(
                self::PLAYER2_DEFAULT_LABEL,
                $metadata,
                self::PLAYER2_DEFAULT_URL,
                $player2ApiBadgeId === null ? null : intval($player2ApiBadgeId)
            );

            $connectorId = !empty($insertedConnectorId) ? intval($insertedConnectorId) : 0;
            if ($connectorId <= 0) {
                $connectorId = intval($connectors->findIdByDriver('player2json', 'player2 local'));
            }
        } elseif (!empty($player2ApiBadgeId)) {
            if (empty($connectors->apiKeyId(intval($connectorId)))) {
                $connectors->setApiKeyId(intval($connectorId), intval($player2ApiBadgeId));
            }
        }

        if (!empty($connectorId)) {
            self::upsertConfOpt(self::PLAYER2_FORCE_CONNECTOR_KEY, (string)$connectorId);
            self::$player2ForceConnectorId = (int)$connectorId;
            return (int)$connectorId;
        }

        return null;
    }

    private static function getForcedPlayer2ConnectorId() {
        if (!self::isPlayer2ForceEnabled()) {
            return null;
        }

        $connectorId = self::getStoredPlayer2ConnectorId();
        if (!empty($connectorId)) {
            return (int)$connectorId;
        }

        return self::ensurePlayer2ConnectorId();
    }

    private static function getStoredPlayer2ConnectorId() {
        if (self::$player2ForceConnectorId !== null) {
            return self::$player2ForceConnectorId ?: null;
        }

        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            self::$player2ForceConnectorId = 0;
            return null;
        }

        $connectorId = intval(\Synth\Managers\ConfigManager\ConfigManager::shared()->get('conf', self::PLAYER2_FORCE_CONNECTOR_KEY, '0'));

        if ($connectorId > 0) {
            if (\Synth\Core\DbManager\Dao\LlmConnectorDao::current($db)->existsWithDriver($connectorId, 'player2json')) {
                self::$player2ForceConnectorId = $connectorId;
                return self::$player2ForceConnectorId;
            }
        }

        self::$player2ForceConnectorId = 0;
        return null;
    }

    private static function upsertConfOpt($id, $value) {
        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            return false;
        }

        return \Synth\Managers\ConfigManager\ConfigManager::shared()->set('conf', (string)$id, (string)$value);
    }

    // Player2 needs no stored key: the connector sends its fixed game key when it has none, and the shared key store holds
    // only real provider keys. The connector is created without a key reference.
    private static function ensurePlayer2ApiBadgeId() {
        return null;
    }
}
