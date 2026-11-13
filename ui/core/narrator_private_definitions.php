<?php

// Private narrator definitions on the Narrator page. Saving creates a new immutable revision and retires replies that
// are still waiting on the older one; it never changes what an existing save applied. Players apply a revision in game
// with Select narrator. The legacy core_narrator settings above are unrelated and unchanged.

const NARRATOR_PRIVATE_FIELDS = ['name' => 64, 'personality' => 4000, 'background' => 4000, 'style' => 2000, 'voice' => 128];
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['private_narrator_csrf'])) $_SESSION['private_narrator_csrf'] = bin2hex(random_bytes(32));

function narrator_private_ready(): bool
{
    require_once dirname(__DIR__, 2).'/lib/synth_private_narrator.php';
    try { return synth_private_narrator_schema_ready($GLOBALS['db']); }
    catch (Throwable) { return false; }
}

// Returns [success, message] for one POSTed action, or null when this request is not a private narrator action.
function narrator_private_handle_post(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['private_narrator_action'])) return null;
    if (!is_string($_POST['private_narrator_csrf'] ?? null)
        || !hash_equals($_SESSION['private_narrator_csrf'], $_POST['private_narrator_csrf']))
        return [false, 'This form expired. Reload the page and try again.'];
    foreach (['private_narrator_action', 'definition_id', 'profile_id', ...array_keys(NARRATOR_PRIVATE_FIELDS)] as $key)
        if (isset($_POST[$key]) && !is_string($_POST[$key])) return [false, 'Invalid narrator form.'];
    if (!narrator_private_ready()) return [false, 'Private narrator tables are not installed. Run the database update first.'];
    $action = strval($_POST['private_narrator_action']);
    $definitionId = trim(strval($_POST['definition_id'] ?? ''));
    if ($definitionId !== '' && preg_match('/^nd-[0-9a-f]{32}$/D', $definitionId) !== 1) return [false, 'Unknown narrator definition.'];
    try {
        if ($action === 'retire') {
            if ($definitionId === '') return [false, 'Choose a narrator to retire.'];
            $GLOBALS['db']->withManagementTransaction(static function ($connection) use ($definitionId): void {
                $retired = @pg_query_params($connection, 'UPDATE synth_meta.narrator_definitions SET retired_at=NOW()
                    WHERE definition_id=$1 AND retired_at IS NULL', [$definitionId]);
                if (!$retired || pg_affected_rows($retired) !== 1) throw new DomainException('Narrator is already retired.');
                narrator_private_retire_replies($connection, $definitionId);
            });
            // A fresh token stops a resubmitted page from repeating the change.
            $_SESSION['private_narrator_csrf'] = bin2hex(random_bytes(32));
            return [true, 'Narrator retired. Saves that already use it keep their revision.'];
        }
        if ($action !== 'save') return [false, 'Unknown private narrator action.'];
        $fields = [];
        foreach (NARRATOR_PRIVATE_FIELDS as $field => $limit) {
            $value = trim(str_replace("\r\n", "\n", strval($_POST[$field] ?? '')));
            if (mb_strlen($value) > $limit || str_contains($value, "\0") || preg_match('//u', $value) !== 1)
                return [false, ucfirst($field).' is too long or invalid.'];
            $fields[$field] = $value;
        }
        if ($fields['name'] === '') return [false, 'A narrator needs a name.'];
        $profileId = intval($_POST['profile_id'] ?? 0);
        $profileId = $profileId > 0 ? $profileId : null;
        if ($profileId !== null && !$GLOBALS['db']->fetchOne('SELECT 1 FROM synth.core_profiles WHERE id='.$profileId))
            return [false, 'Choose an existing profile or leave it on the default narrator profile.'];
        $saved = $GLOBALS['db']->withManagementTransaction(static function ($connection) use ($definitionId, $fields, $profileId): int {
            $id = $definitionId;
            if ($id === '') {
                if (!@pg_query($connection, 'LOCK TABLE synth_meta.narrator_definitions IN SHARE ROW EXCLUSIVE MODE'))
                    throw new RuntimeException('Unable to lock narrator library');
                $count = pg_fetch_row(pg_query($connection, 'SELECT COUNT(*) FROM synth_meta.narrator_definitions WHERE retired_at IS NULL'));
                if (intval($count[0] ?? 16) >= 16) throw new DomainException('Retire a narrator before creating another; the library holds 16.');
                $id = 'nd-'.bin2hex(random_bytes(16));
                if (!@pg_query_params($connection, 'INSERT INTO synth_meta.narrator_definitions(definition_id) VALUES($1)', [$id]))
                    throw new RuntimeException('Unable to create narrator.');
            }
            // The definition row lock serializes concurrent edits into one linear revision chain.
            $current = @pg_query_params($connection, 'SELECT (SELECT MAX(revision_id) FROM synth_meta.narrator_definition_revisions r
                WHERE r.definition_id=d.definition_id) AS prior FROM synth_meta.narrator_definitions d
                WHERE d.definition_id=$1 AND d.retired_at IS NULL FOR UPDATE', [$id]);
            $current = $current ? pg_fetch_assoc($current) : false;
            if (!$current) throw new DomainException('That narrator was retired or removed.');
            $inserted = @pg_query_params($connection, 'INSERT INTO synth_meta.narrator_definition_revisions
                (definition_id,prior_revision_id,name,personality,background,style,voice,profile_id)
                VALUES($1,$2,$3,$4,$5,$6,$7,$8) RETURNING revision_id', [$id, $current['prior'], $fields['name'],
                $fields['personality'], $fields['background'], $fields['style'], $fields['voice'], $profileId]);
            $inserted = $inserted ? pg_fetch_assoc($inserted) : false;
            if (!$inserted) throw new RuntimeException('Unable to save narrator revision.');
            narrator_private_retire_replies($connection, $id);
            return intval($inserted['revision_id']);
        });
        $_SESSION['private_narrator_csrf'] = bin2hex(random_bytes(32));
        return [true, "Saved {$fields['name']} as revision {$saved}. Select it in game to apply it."];
    } catch (DomainException $error) {
        return [false, $error->getMessage()];
    } catch (Throwable) {
        return [false, 'Private narrator change failed; nothing was saved.'];
    }
}

// An edit or retirement invalidates replies that were generated from an older revision and are not heard yet.
function narrator_private_retire_replies($connection, string $definitionId): void
{
    if (!@pg_query_params($connection, 'UPDATE synth_meta.narrator_definitions SET edit_generation=edit_generation+1 WHERE definition_id=$1', [$definitionId]))
        throw new RuntimeException('Unable to invalidate narrator revision');
    if (!@pg_query_params($connection, "UPDATE synth_meta.request_executions e SET state='cancelled',response_body=NULL,
        error_code='request_cancelled',completed_at=NOW() WHERE e.event_type='narrator_turn' AND e.state IN ('claimed','streaming')
        AND EXISTS(SELECT 1 FROM synth_meta.narrator_pending_outputs p WHERE p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.definition_id=$1 AND p.state IN ('pending','generated'))", [$definitionId]))
        throw new RuntimeException('Unable to cancel narrator request');
    if (!@pg_query_params($connection, "UPDATE synth_meta.narrator_pending_outputs SET state='cancelled',updated_at=NOW()
        WHERE definition_id=$1 AND state IN ('pending','generated')", [$definitionId]))
        throw new RuntimeException('Unable to retire pending narrator replies.');
}

// $result is the [success, message] pair from narrator_private_handle_post() for this request, if any.
function narrator_private_render(string $webRoot, ?array $result = null): void
{
    $ready = narrator_private_ready();
    $definitions = $revisions = $profiles = [];
    if ($ready) {
        $definitions = $GLOBALS['db']->fetchAll('SELECT DISTINCT ON (d.definition_id) d.definition_id,r.revision_id,r.name,r.personality,
                r.background,r.style,r.voice,r.profile_id,r.created_at,
                (SELECT COUNT(*) FROM synth_meta.narrator_definition_revisions x WHERE x.definition_id=d.definition_id) AS revisions
            FROM synth_meta.narrator_definitions d JOIN synth_meta.narrator_definition_revisions r USING(definition_id)
            WHERE d.retired_at IS NULL ORDER BY d.definition_id,r.revision_id DESC') ?: [];
        usort($definitions, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        foreach ($GLOBALS['db']->fetchAll('SELECT definition_id,revision_id,name,created_at FROM synth_meta.narrator_definition_revisions
            ORDER BY revision_id DESC LIMIT 200') ?: [] as $row) $revisions[$row['definition_id']][] = $row;
        $profiles = $GLOBALS['db']->fetchAll('SELECT id,label FROM synth.core_profiles ORDER BY id') ?: [];
    }
    $editing = null;
    $requested = is_string($_GET['narrator_definition'] ?? null) ? $_GET['narrator_definition'] : '';
    // A failed save redisplays what was submitted and stays aimed at the definition it targeted, even a retired or
    // unknown one, so saving again fails again instead of creating another narrator.
    $failedSave = $result && !$result[0] && ($_POST['private_narrator_action'] ?? '') === 'save';
    if ($failedSave) $requested = is_string($_POST['definition_id'] ?? null) ? trim($_POST['definition_id']) : '';
    foreach ($definitions as $row) if ($row['definition_id'] === $requested) $editing = $row;
    $editId = $failedSave ? $requested : strval($editing['definition_id'] ?? '');
    if ($failedSave) {
        $submitted = ['definition_id' => $editId, 'profile_id' => is_string($_POST['profile_id'] ?? null) ? intval($_POST['profile_id']) : 0];
        foreach (array_keys(NARRATOR_PRIVATE_FIELDS) as $field)
            $submitted[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    $profileLabels = array_column($profiles, 'label', 'id');
    $value = static fn(string $field) => htmlspecialchars(strval(($failedSave ? $submitted : $editing)[$field] ?? ''));
    $base = '?'.(isset($_GET['embed']) ? 'embed=1&' : '');
    $home = rtrim($base, '&').'#private-narrators';
    $formAction = $editId !== '' ? $base.'narrator_definition='.urlencode($editId).'#private-narrators' : $home;
    ?>
<div class="content-section full-width-section private-narrators" id="private-narrators">
 <h2>Private Narrators</h2>
 <?php if ($result): ?>
 <p class="private-narrators-result<?php echo $result[0] ? '' : ' error'; ?>" role="<?php echo $result[0] ? 'status' : 'alert'; ?>"><?php echo htmlspecialchars(strval($result[1])); ?></p>
 <?php endif; ?>
 <span class="hint">Named narrators that only the player hears. Each save stores the narrator revisions it applied, the selection and the private transcript, and loading it restores them. Saving here creates a new revision; select the narrator again in game to apply it. Private lines never enter NPC dialogue, diaries or memories.</span>
 <?php if (!$ready): ?>
 <p class="hint">Private narrator tables are not installed yet. Run the database update, then reload this page.</p>
 <?php else: ?>
 <?php if ($definitions): ?>
 <div class="private-narrators-table-wrap"><table class="private-narrators-table">
  <thead><tr><th scope="col">Name</th><th scope="col">Revision</th><th scope="col">Profile</th><th scope="col">Voice</th><th scope="col"><span class="private-narrators-visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($definitions as $row): ?>
   <tr>
    <td><?php echo htmlspecialchars($row['name']); ?></td>
    <td>
     <details><summary><?php echo intval($row['revision_id']); ?> <span class="hint-inline">(<?php echo intval($row['revisions']); ?> total)</span></summary>
      <ul class="private-narrators-revisions">
      <?php foreach ($revisions[$row['definition_id']] ?? [] as $revision): ?>
       <li><?php echo intval($revision['revision_id']).' · '.htmlspecialchars($revision['name']).' · '.htmlspecialchars(substr(strval($revision['created_at']), 0, 16)); ?></li>
      <?php endforeach; ?>
      </ul>
     </details>
    </td>
    <td><?php echo $row['profile_id'] === null ? 'Default narrator profile' : htmlspecialchars(strval($profileLabels[$row['profile_id']] ?? 'Profile '.$row['profile_id'])); ?></td>
    <td><?php echo $row['voice'] === '' ? '<span class="hint-inline">Profile default</span>' : htmlspecialchars($row['voice']); ?></td>
    <td><div class="private-narrators-actions">
     <a class="private-narrators-btn" href="<?php echo htmlspecialchars($base.'narrator_definition='.urlencode($row['definition_id'])); ?>#private-narrators-form" aria-label="Edit <?php echo htmlspecialchars($row['name']); ?>">Edit</a>
     <form method="post" action="<?php echo htmlspecialchars($home); ?>" onsubmit="return confirm('Retire this narrator? Saves that already use it keep their revision.');">
      <input type="hidden" name="private_narrator_csrf" value="<?php echo htmlspecialchars($_SESSION['private_narrator_csrf']); ?>">
      <input type="hidden" name="private_narrator_action" value="retire"><input type="hidden" name="definition_id" value="<?php echo htmlspecialchars($row['definition_id']); ?>">
      <button type="submit" class="private-narrators-btn private-narrators-btn-muted" aria-label="Retire <?php echo htmlspecialchars($row['name']); ?>">Retire</button>
     </form>
    </div></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
 <?php else: ?>
 <p class="hint">No private narrators yet. Create one below.</p>
 <?php endif; ?>
 <form method="post" action="<?php echo htmlspecialchars($formAction); ?>" class="private-narrators-form" id="private-narrators-form">
  <input type="hidden" name="private_narrator_csrf" value="<?php echo htmlspecialchars($_SESSION['private_narrator_csrf']); ?>">
  <h3><?php echo $editing ? 'Edit '.htmlspecialchars($editing['name']).' (new revision)' : ($editId !== '' ? 'Edit unavailable narrator (new revision)' : 'New private narrator'); ?></h3>
  <input type="hidden" name="private_narrator_action" value="save">
  <input type="hidden" name="definition_id" value="<?php echo $value('definition_id'); ?>">
  <div class="private-narrators-row">
   <div><label for="private_narrator_name">Name</label><input type="text" id="private_narrator_name" name="name" maxlength="64" required value="<?php echo $value('name'); ?>"></div>
   <div><label for="private_narrator_voice">Voice</label><input type="text" id="private_narrator_voice" name="voice" maxlength="128" value="<?php echo $value('voice'); ?>" placeholder="Profile default"></div>
   <div><label for="private_narrator_profile">Profile</label><select id="private_narrator_profile" name="profile_id">
    <option value="">Default narrator profile</option>
    <?php foreach ($profiles as $profile): ?>
    <option value="<?php echo intval($profile['id']); ?>" <?php echo intval(($failedSave ? $submitted : $editing)['profile_id'] ?? 0) === intval($profile['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(strval($profile['label'] ?? 'Profile '.$profile['id'])); ?></option>
    <?php endforeach; ?>
   </select></div>
  </div>
  <label for="private_narrator_personality">Personality</label><textarea id="private_narrator_personality" name="personality" rows="3" maxlength="4000"><?php echo $value('personality'); ?></textarea>
  <label for="private_narrator_background">Background</label><textarea id="private_narrator_background" name="background" rows="3" maxlength="4000"><?php echo $value('background'); ?></textarea>
  <label for="private_narrator_style">Speaking style</label><textarea id="private_narrator_style" name="style" rows="2" maxlength="2000"><?php echo $value('style'); ?></textarea>
  <span class="hint">The profile supplies the language model and text-to-speech connector. Replies need a voiced profile.</span>
  <div class="private-narrators-buttons">
   <button type="submit" class="btn-save"><?php echo $editId !== '' ? 'Save Revision' : 'Create Narrator'; ?></button>
   <?php if ($editId !== ''): ?><a class="private-narrators-btn private-narrators-btn-muted" href="<?php echo htmlspecialchars($home); ?>">Cancel</a><?php endif; ?>
  </div>
 </form>
 <?php endif; ?>
</div>
<style>
 .private-narrators-table-wrap { overflow-x: auto; margin: 8px 0 16px; }
 .private-narrators-table { width: 100%; border-collapse: collapse; font-size: 14px; }
 .private-narrators-table th, .private-narrators-table td { padding: 8px 10px; border-bottom: 1px solid var(--synth-accent-line); text-align: left; vertical-align: top; }
 .private-narrators-table th { color: var(--synth-accent); font-weight: 600; }
 .private-narrators-table summary { cursor: pointer; }
 .private-narrators-revisions { margin: 6px 0 0; padding-left: 18px; font-size: 13px; color: #b0b0b0; }
 .private-narrators-actions { display: flex; gap: 8px; flex-wrap: wrap; }
 .private-narrators-actions form { margin: 0; }
 .private-narrators-btn { display: inline-block; padding: 6px 12px; border-radius: 6px; border: 1px solid rgba(var(--synth-accent-rgb), 0.4); background: rgba(var(--synth-accent-rgb), 0.12); color: inherit; font-size: 13px; cursor: pointer; text-decoration: none; }
 .private-narrators-btn-muted { border-color: rgba(255, 255, 255, 0.2); background: transparent; }
 .private-narrators-btn:focus-visible, .private-narrators-table summary:focus-visible { outline: 2px solid var(--synth-accent); outline-offset: 2px; }
 .private-narrators-result { margin: 8px 0 12px; padding: 8px 12px; border-left: 3px solid var(--synth-accent); background: rgba(var(--synth-accent-rgb), 0.08); }
 .private-narrators-result.error { border-left-color: #e57373; background: rgba(229, 115, 115, 0.1); }
 .private-narrators-form h3 { margin: 8px 0 4px; }
 .private-narrators-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
 .private-narrators-row label, .private-narrators-form > label { display: block; margin: 10px 0 4px; color: var(--synth-accent); }
 .private-narrators-row input, .private-narrators-row select { width: 100%; box-sizing: border-box; }
 .private-narrators-buttons { display: flex; gap: 12px; align-items: center; margin-top: 12px; }
 .private-narrators-buttons .btn-save { margin-bottom: 0; }
 .private-narrators .hint-inline { color: #9a9a9a; font-size: 12px; }
 .private-narrators-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
 @media (max-width: 768px) { .private-narrators-row { grid-template-columns: 1fr; } }
</style>
<?php
}
