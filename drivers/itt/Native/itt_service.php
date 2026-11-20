<?php

require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');
require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'visual_context.php');
require_once(dirname(__DIR__, 3) . '/drivers/itt/IttWrapper.php');

if (!function_exists('synthIttBuildHints')) {
    function synthIttBuildHints(array $captureMetadata): string
    {
        $hints = [];
        $worldspace = trim(strval($captureMetadata['worldspace'] ?? ''));
        $location = trim(strval($captureMetadata['location'] ?? ''));
        $subject = is_array($captureMetadata['subject'] ?? null) ? $captureMetadata['subject'] : [];
        $subjectName = trim(strval($subject['name'] ?? ''));

        if ($worldspace !== '') {
            $hints[] = "Worldspace: {$worldspace}.";
        }
        if ($location !== '') {
            $hints[] = "Location: {$location}.";
        }
        if ($subjectName !== '') {
            $hints[] = "The player targeted {$subjectName}.";
        }
        $nearby = $captureMetadata['nearby_actors'] ?? [];
        if (is_array($nearby) && $nearby) {
            $names = array_values(array_filter(array_map(static fn($value) => trim(strval($value)), $nearby)));
            if ($names) {
                $hints[] = 'Nearby actors reported by the game: ' . implode(', ', array_slice($names, 0, 20)) . '.';
            }
        }
        return implode("\n", $hints);
    }
}

if (!function_exists('synthIttTimeout')) {
    function synthIttTimeout(): int
    {
        return max(10, min(synthGetGeneralSettingInt('PIPVISION_REQUEST_TIMEOUT_SECONDS', 60), 180));
    }
}

// Thin hooks: read the connector's globals (the key was already resolved through the API badge store by
// ITTConnector::setOldGlobals) and let IttWrapper's drivers do the call.
if (!function_exists('synthIttOpenAiCompatible')) {
    function synthIttOpenAiCompatible(string $provider, string $imagePath, string $hints, bool $openRouter = false): string
    {
        $config = is_array(\Synth\Bootstrap::config('ITT')[$provider] ?? null) ? \Synth\Bootstrap::config('ITT')[$provider] : [];
        return \Synth\Drivers\Itt\IttWrapper::describe($provider, [
            'url' => strval($config['url'] ?? ($config['URL'] ?? '')),
            'model' => strval($config['model'] ?? ''),
            'api_key' => strval($config['API_KEY'] ?? ''),
            'prompt' => strval($config['AI_VISION_PROMPT'] ?? ''),
            'detail' => strval($config['detail'] ?? 'low'),
            'max_tokens' => intval($config['max_tokens'] ?? 1024),
            'timeout' => synthIttTimeout(),
        ], new \Synth\Drivers\Itt\IttRequest($imagePath, $hints));
    }
}

if (!function_exists('synthIttLlamaCpp')) {
    function synthIttLlamaCpp(string $imagePath, string $hints): string
    {
        $config = is_array(\Synth\Bootstrap::config('ITT')['llamacpp'] ?? null) ? \Synth\Bootstrap::config('ITT')['llamacpp'] : [];
        return \Synth\Drivers\Itt\IttWrapper::describe('llamacpp', [
            'url' => strval($config['URL'] ?? ($config['url'] ?? '')),
            'prompt' => strval($config['AI_VISION_PROMPT'] ?? ''),
            'timeout' => synthIttTimeout(),
        ], new \Synth\Drivers\Itt\IttRequest($imagePath, $hints));
    }
}

if (!function_exists('synthIttDescribe')) {
    function synthIttDescribe(string $imagePath, array $captureMetadata, ?array $connectorRow = null): array
    {
        $connector = new ITTConnector();
        if (!$connectorRow) {
            $connectorId = synthGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
            $connectorRow = $connectorId > 0 ? $connector->getById($connectorId) : null;
        }
        if (!$connectorRow) {
            throw new RuntimeException('No PipVision ITT connector is configured');
        }

        $driver = $connector->normalizeDriverValue($connectorRow['driver'] ?? '');
        if ($driver === '') {
            throw new RuntimeException('PipVision ITT connector driver is unsupported');
        }
        $connector->setOldGlobals($connectorRow);
        $providerFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'itt' . DIRECTORY_SEPARATOR . 'itt-' . $driver . '.php';
        if (!is_file($providerFile)) {
            throw new RuntimeException('PipVision ITT provider is unavailable');
        }
        require_once($providerFile);
        if (!function_exists('itt')) {
            throw new RuntimeException('PipVision ITT provider did not register');
        }

        $description = trim(strval(itt($imagePath, synthIttBuildHints($captureMetadata))));
        if ($description === '') {
            throw new RuntimeException('PipVision ITT connector returned an empty description');
        }
        $metadata = $connector->decodeMetadata($connectorRow['metadata'] ?? '{}');
        return [
            'description' => synthVisualContextText($description, 12000),
            'provider' => $driver,
            'model' => trim(strval($metadata['model'] ?? ($driver === 'llamacpp' ? 'llama.cpp' : ''))),
        ];
    }
}
