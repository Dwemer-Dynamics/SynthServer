<?php

function synthCommandPayloadOrderedArgs(string $commandName, array $args = []): array
{
    if (array_is_list($args)) {
        return array_values(array_map('strval', $args));
    }

    $normalizedCommand = strtolower(trim($commandName));
    $orderedKeys = [
        'debugnotification' => ['message'],
        'togglemodel' => ['model'],
        'setconf' => ['setting', 'value', 'restart', 'scope'],
        'renamenpc' => ['refid', 'name'],
        'scriptproxy' => ['payload'],
        'impersonateplayer' => ['speech', 'request_type'],
        'instruction' => ['character', 'instruction', 'task_id'],
        'suggestion' => ['character', 'instruction', 'task_id'],
        'teleportactor' => ['target', 'location'],
    ];

    $ordered = [];
    foreach ($orderedKeys[$normalizedCommand] ?? [] as $key) {
        if (array_key_exists($key, $args) && $args[$key] !== null && strval($args[$key]) !== '') {
            $ordered[] = strval($args[$key]);
        }
    }

    if (!empty($ordered)) {
        return $ordered;
    }

    foreach ($args as $value) {
        if (is_scalar($value) && strval($value) !== '') {
            $ordered[] = strval($value);
        }
    }

    return $ordered;
}

function synthActionPayloadOrderedArgs(string $actionName, $parameter = null, string $parameterString = ''): array
{
    $decodedParameter = null;
    if (is_array($parameter)) {
        $decodedParameter = $parameter;
    } elseif ($parameterString !== '') {
        $trimmed = trim($parameterString);
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
            $json = json_decode($trimmed, true);
            if (is_array($json)) {
                $decodedParameter = $json;
            }
        }
    }

    if (is_array($decodedParameter)) {
        if (array_is_list($decodedParameter)) {
            return array_values(array_map('strval', $decodedParameter));
        }

        $normalizedAction = strtolower(trim($actionName));
        $orderedKeys = [
            'attack' => ['target'],
            'barter' => ['target'],
            'checkinventory' => ['target'],
            'comecloser' => ['target'],
            'consume' => ['target', 'item', 'amount'],
            'follow' => ['target'],
            'givecapsto' => ['target', 'amount'],
            'giveitemto' => ['target', 'item', 'amount'],
            'inspect' => ['target'],
            'moveto' => ['target'],
            'openinventory' => ['target'],
            'pickupitem' => ['item', 'target', 'amount'],
            'spawncaps' => ['target', 'amount'],
            'spawnitem' => ['target', 'item', 'amount'],
            'teleportactor' => ['target', 'location'],
            'killtarget' => ['target'],
            'takecapsfromplayer' => ['target', 'amount'],
            'travelto' => ['target', 'destination', 'location'],
        ];

        $ordered = [];
        foreach ($orderedKeys[$normalizedAction] ?? [] as $key) {
            if (array_key_exists($key, $decodedParameter) && is_scalar($decodedParameter[$key]) && trim(strval($decodedParameter[$key])) !== '') {
                $ordered[] = trim(strval($decodedParameter[$key]));
            }
        }
        if (!empty($ordered)) {
            return $ordered;
        }

        foreach ($decodedParameter as $value) {
            if (is_scalar($value) && trim(strval($value)) !== '') {
                $ordered[] = trim(strval($value));
            }
        }
        return $ordered;
    }

    $parameterString = trim($parameterString);
    if ($parameterString !== '') {
        return [$parameterString];
    }

    if (is_scalar($parameter) && trim(strval($parameter)) !== '') {
        return [trim(strval($parameter))];
    }

    return [];
}

function synthEncodeCommandAction(string $commandName, array $args = []): string
{
    $commandName = trim($commandName);
    if ($commandName === '') {
        return '';
    }

    return json_encode([
        'schema' => 'synth.command.v1',
        'command_name' => $commandName,
        'command_args' => synthCommandPayloadOrderedArgs($commandName, $args),
        'args' => (object)$args,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function synthDecodeCommandAction(string $command, array $args = []): array
{
    $commandPayload = trim($command);
    $metadata = ['args' => $args];

    if ($commandPayload !== '' && $commandPayload[0] === '{') {
        $decoded = json_decode($commandPayload, true);
        if (is_array($decoded) && strval($decoded['schema'] ?? '') === 'synth.command.v1') {
            $commandName = trim(strval($decoded['command_name'] ?? $decoded['command'] ?? ''));
            $commandArgs = $decoded['command_args'] ?? [];
            if (!is_array($commandArgs)) {
                $commandArgs = [];
            }
            $decodedArgs = $decoded['args'] ?? [];
            if (is_array($decodedArgs)) {
                $metadata['args'] = array_merge($decodedArgs, $args);
                foreach ($decodedArgs as $key => $value) {
                    if (is_string($key) && is_scalar($value) && trim(strval($value)) !== '') {
                        $metadata[$key] = trim(strval($value));
                    }
                }
            }

            return [
                'command_payload' => $commandName,
                'command_name' => $commandName,
                'command_args' => array_values(array_map('strval', $commandArgs)),
                'metadata' => $metadata,
            ];
        }
    }

    return [
        'command_payload' => '',
        'command_name' => '',
        'command_args' => [],
        'metadata' => $metadata,
    ];
}

function synthQueueCommandResponse(string $speaker, string $commandName, array $args = [], string $text = ''): void
{
    if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
        return;
    }

    $payload = synthEncodeCommandAction($commandName, $args);
    if ($payload === '') {
        return;
    }

    $GLOBALS['db']->insert(
        'responselog',
        [
            'localts' => time(),
            'sent' => 0,
            'actor' => $speaker,
            'text' => $text,
            'action' => $payload,
            'tag' => '',
        ]
    );
}

// Native transfer parameters must retain model types before generic stringification or numeric coercion.
function synthNativeTransferParametersValid($parameters, bool $caps = false): bool
{
    return is_array($parameters) && !array_is_list($parameters)
        && array_diff(array_keys($parameters), $caps ? ['target','amount'] : ['target','item','amount']) === []
        && is_string($parameters['target'] ?? null) && trim($parameters['target']) !== ''
        && strlen($parameters['target']) <= 255 && preg_match('//u', $parameters['target']) === 1
        && ($caps ? array_key_exists('amount',$parameters)
            : (is_string($parameters['item'] ?? null) && trim($parameters['item']) !== ''
                && strlen($parameters['item']) <= 255 && preg_match('//u', $parameters['item']) === 1))
        && (!array_key_exists('amount', $parameters) || (is_int($parameters['amount'])
            && $parameters['amount'] >= 1 && $parameters['amount'] <= 1000000));
}

function synthEncodeActionLine(string $actor, string $action, $parameter = '', string $parameterString = ''): string
{
    $actor = trim($actor);
    $action = trim($action);
    if ($action === '') {
        return '';
    }

    if (in_array($action,['GiveItemTo','GiveCapsTo','TakeCapsFromPlayer'],true) && ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) === 2) {
        if (!is_array($parameter) && $parameterString !== '') $parameter = json_decode($parameterString, true);
        if (!synthNativeTransferParametersValid($parameter,$action!=='GiveItemTo')) return '';
        $parameterString = json_encode($parameter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if ($parameterString === '') {
        if (is_scalar($parameter)) {
            $parameterString = trim(strval($parameter));
        } else {
            $parameterString = json_encode($parameter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    return json_encode([
        'schema' => 'synth.action.v1',
        'actor' => $actor,
        'action' => $action,
        'parameter' => $parameter,
        'parameter_string' => $parameterString,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function synthDecodeActionLine(string $line): array
{
    $line = trim($line);
    if ($line !== '' && $line[0] === '{') {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && strval($decoded['schema'] ?? '') === 'synth.action.v1') {
            $actor = trim(strval($decoded['actor'] ?? ''));
            $action = trim(strval($decoded['action'] ?? ''));
            $parameterString = trim(strval($decoded['parameter_string'] ?? ''));
            $parameter = $decoded['parameter'] ?? null;
            if ($parameterString === '' && array_key_exists('parameter', $decoded)) {
                $parameterString = is_scalar($parameter)
                    ? trim(strval($parameter))
                    : json_encode($parameter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return [
                'schema' => 'synth.action.v1',
                'actor' => $actor,
                'channel' => 'command',
                'action' => $action,
                'payload' => $parameterString,
                'parameter' => $parameter,
                'parameter_args' => synthActionPayloadOrderedArgs($action, $parameter, $parameterString),
                'parameter_string' => $parameterString,
                'original' => $line,
            ];
        }
    }

    return [
        'schema' => 'invalid',
        'actor' => '',
        'channel' => '',
        'action' => '',
        'payload' => '',
        'parameter_string' => '',
        'original' => $line,
    ];
}

?>
