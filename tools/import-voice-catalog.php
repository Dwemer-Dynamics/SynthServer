<?php
// Local-only, single-voice operation; dry-run by default and never calls a cloud provider.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/voice_catalog.php';
$options = getopt('', ['catalog:', 'voice:', 'apply']);
try {
    if (!isset($options['catalog'], $options['voice'])) throw new InvalidArgumentException('Usage: php tools/import-voice-catalog.php --catalog=/absolute/catalog.csv --voice=npcmprestongarvey [--apply]');
    $result = synth_import_voice_catalog(dirname(__DIR__), $options['catalog'], $options['voice'], isset($options['apply']));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
