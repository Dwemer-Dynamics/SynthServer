<?php
// Voice Samples tab for TTS Studio. Rendered by ui/xtts_clone.php. Lists the stored samples (metadata only) and
// previews an NPC's saved voice through api/v1/voice/preview.php. Nothing here changes a sample.
$vsWebRoot = rtrim(strval($webRoot ?? ''), '/');
$vsSamples = is_array($voiceSamplesList ?? null) ? $voiceSamplesList : [];
$vsNpcs = is_array($voiceSamplesNpcs ?? null) ? $voiceSamplesNpcs : [];
$vsToken = strval($voiceSamplesCsrf ?? '');
$vsHaveSample = [];
foreach ($vsSamples as $vsSample) {
    $vsHaveSample[strtolower($vsSample['voice_id'])] = true;
}
$vsFormatBytes = static fn(int $bytes): string => $bytes >= 1048576
    ? number_format($bytes / 1048576, 1) . ' MiB' : number_format(max(1, $bytes) / 1024, 0) . ' KiB';
?>
<div class="content-section full-width-section">
    <h1>NPC Voice Preview</h1>
    <p>Speak a short line with an NPC's saved voice through the TTS connector that NPC uses. Each preview is one provider call and may be charged; nothing is saved.</p>
    <div id="voice-preview" data-endpoint="<?php echo htmlspecialchars($vsWebRoot . '/api/v1/voice/preview.php'); ?>" data-token="<?php echo htmlspecialchars($vsToken); ?>">
        <?php if (empty($vsNpcs)): ?>
            <p>No NPC has a saved voice yet. Voices are saved with the NPC profile.</p>
        <?php else: ?>
            <div class="voice-preview-row">
                <label for="voice-preview-npc">NPC</label>
                <select id="voice-preview-npc">
                    <?php foreach ($vsNpcs as $vsNpc): ?>
                        <option value="<?php echo intval($vsNpc['id']); ?>"><?php echo htmlspecialchars($vsNpc['npc_name'] . ' - ' . $vsNpc['voiceid'] . (isset($vsHaveSample[strtolower($vsNpc['voiceid'])]) ? '' : ' (no local sample)')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="voice-preview-row">
                <label for="voice-preview-text">Line</label>
                <input type="text" id="voice-preview-text" maxlength="300" value="Patrolling the Commonwealth almost makes you wish for a Nuclear Winter.">
            </div>
            <div class="button-group">
                <button type="button" id="voice-preview-button" class="action-button upload-csv">Preview</button>
                <span id="voice-preview-status" role="status" aria-live="polite"></span>
            </div>
            <audio id="voice-preview-audio" controls hidden></audio>
        <?php endif; ?>
    </div>
</div>

<div class="content-section full-width-section">
    <h1>Stored Voice Samples</h1>
    <p>Samples in <code>data/voices</code>: <strong><?php echo count($vsSamples); ?></strong>. Replace a sample from its provider tab; nothing is deleted here.</p>
    <?php if (empty($vsSamples)): ?>
        <p>No voice samples are stored yet.</p>
    <?php else: ?>
        <div class="voice-samples-table-wrap">
            <table class="voice-samples-table">
                <thead><tr><th>Voice</th><th>Length</th><th>Size</th><th>Transcript</th><th>Stored</th></tr></thead>
                <tbody>
                <?php foreach ($vsSamples as $vsSample): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($vsSample['voice_id']); ?><?php echo $vsSample['playable'] ? '' : ' <span class="voice-samples-warn" title="The WAV could not be read">unreadable</span>'; ?></td>
                        <td><?php echo $vsSample['duration_seconds'] !== null ? htmlspecialchars(number_format($vsSample['duration_seconds'], 1)) . ' s' : '-'; ?></td>
                        <td><?php echo htmlspecialchars($vsFormatBytes($vsSample['bytes'])); ?></td>
                        <td title="<?php echo htmlspecialchars($vsSample['reference_text']); ?>"><?php echo $vsSample['has_reference_text'] ? 'saved' : '-'; ?></td>
                        <td><?php echo htmlspecialchars(substr($vsSample['stored_at'], 0, 10)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
    .voice-preview-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 12px 0; }
    .voice-preview-row label { min-width: 48px; color: #cfd8e3; }
    .voice-preview-row select, .voice-preview-row input[type="text"] {
        flex: 1 1 240px; min-width: 0; max-width: 640px; padding: 8px; background: #2a2a2a; color: #e8e8e8;
        border: 1px solid #4a4a4a; border-radius: 4px;
    }
    #voice-preview-status { margin-left: 12px; color: #aaa; }
    #voice-preview-status.is-error { color: #ff9b92; }
    #voice-preview-audio { display: block; margin-top: 12px; width: 100%; max-width: 640px; }
    #voice-preview-audio[hidden] { display: none; }
    .voice-samples-table-wrap { overflow-x: auto; margin-top: 12px; }
    .voice-samples-table { border-collapse: collapse; width: 100%; max-width: 900px; }
    .voice-samples-table th, .voice-samples-table td { text-align: left; padding: 6px 12px; border-bottom: 1px solid #3a3a3a; white-space: nowrap; }
    .voice-samples-table th { color: var(--synth-accent); font-weight: 600; }
    .voice-samples-warn { color: #ff9b92; font-size: 12px; }
</style>
<script>
(function () {
    var root = document.getElementById('voice-preview');
    var button = document.getElementById('voice-preview-button');
    if (!root || !button) { return; }
    var status = document.getElementById('voice-preview-status');
    var audio = document.getElementById('voice-preview-audio');
    var objectUrl = '';
    var fallback = false;
    function say(text, isError) { status.textContent = text; status.className = isError ? 'is-error' : ''; }
    button.addEventListener('click', function () {
        var text = document.getElementById('voice-preview-text').value.trim();
        if (text === '' || text.length > 300) { say('Enter 1 to 300 characters.', true); return; }
        button.disabled = true;
        say('Speaking...', false);
        fetch(root.getAttribute('data-endpoint'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'audio/wav, application/json' },
            body: JSON.stringify({
                npc_id: parseInt(document.getElementById('voice-preview-npc').value, 10),
                text: text,
                csrf_token: root.getAttribute('data-token')
            })
        }).then(function (response) {
            if (!response.ok) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    throw new Error(data.error || 'The preview failed.');
                });
            }
            fallback = response.headers.get('X-Voice-Fallback') === '1';
            return response.blob();
        }).then(function (blob) {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); }
            objectUrl = URL.createObjectURL(blob);
            audio.src = objectUrl;
            audio.hidden = false;
            say(fallback ? "This NPC has no saved voice, so the connector's fallback voice was used." : '', false);
            return audio.play().catch(function () { say('Press play to listen.', false); });
        }).catch(function (error) {
            say(error.message, true);
        }).then(function () { button.disabled = false; });
    });
}());
</script>
