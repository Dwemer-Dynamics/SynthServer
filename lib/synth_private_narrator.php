<?php

require_once dirname(__DIR__).'/managers/Narrator/PrivateNarratorProtocol.php';

// Legacy function names for the private narrator protocol rules. The logic lives in
// managers/Narrator/PrivateNarratorProtocol.php; these are thin hooks for existing callers.

const SYNTH_PRIVATE_NARRATOR_CAPABILITY = \Synth\Managers\Narrator\PrivateNarratorProtocol::CAPABILITY;

function synth_private_narrator_supported(array $event): bool
{
    return \Synth\Managers\Narrator\PrivateNarratorProtocol::supported($event);
}

function synth_private_narrator_valid_definition_id($value): bool
{
    return \Synth\Managers\Narrator\PrivateNarratorProtocol::validDefinitionId($value);
}

function synth_private_narrator_valid_record_id($value, int $minimum = 1): bool
{
    return \Synth\Managers\Narrator\PrivateNarratorProtocol::validRecordId($value, $minimum);
}

function synth_private_narrator_validate_payload(string $type, array $payload): array
{
    return \Synth\Managers\Narrator\PrivateNarratorProtocol::validatePayload($type, $payload);
}

function synth_private_narrator_schema_ready($db): bool
{
    return \Synth\Managers\Narrator\PrivateNarratorProtocol::schemaReady($db);
}

function synth_private_narrator_capture_context($db, array $event, string $sessionId): void
{
    \Synth\Managers\Narrator\PrivateNarratorProtocol::captureContext($db, $event, $sessionId);
}
