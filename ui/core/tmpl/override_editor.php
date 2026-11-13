<?php
// Unified Override Editor Component
// Configurable for both NPC Master (extended_data) and Core Profiles (metadata)

// Configuration parameters (must be set before including this file)
// $overrideEditorConfig = [
// 'mode' => 'npc' | 'profile', // Which mode to operate in
// 'fieldName' => 'extended_data' | 'metadata', // Which field to save to
// 'settingsCatalog' => [...], // Setting schema keyed by runtime override key
// 'inherited' => [...], // synthDescribeInheritedSettings(): what each override replaces
// 'reservedKeys' => [...], // Keys that should not appear in UI (only for npc mode)
// 'currentData' => [], // Current override data
// 'systemFields' => [], // System-managed fields (only for npc mode)
// ];

if (!isset($overrideEditorConfig)) {
 throw new Exception('Override editor config not set');
}

$mode = $overrideEditorConfig['mode'] ?? 'npc';
$fieldName = $overrideEditorConfig['fieldName'] ?? 'extended_data';
$settingsCatalog = $overrideEditorConfig['settingsCatalog'] ?? [];
$inherited = $overrideEditorConfig['inherited'] ?? [];
$reservedKeys = $overrideEditorConfig['reservedKeys'] ?? [];
$currentData = $overrideEditorConfig['currentData'] ?? [];
$systemFields = $overrideEditorConfig['systemFields'] ?? [];
unset($currentData['ENFORCE_ACTIONS_PROMPT']);

$isNpcMode = ($mode === 'npc');
$isProfileMode = ($mode === 'profile');

// CSS prefix for unique styling
$prefix = $isProfileMode ? 'prof-' : '';

$schema = is_array($settingsCatalog) ? $settingsCatalog : [];
// Inline script JSON: labels, values and descriptions are stored data, so < > & ' are emitted as \u escapes
// and a stored "</script>" cannot end the script element. The decoded values are unchanged.
$jsonScriptFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
?><style>
.<?= $prefix ?>ovr-container { display:flex; flex-direction:column; gap:8px; <?php if ($isNpcMode): ?>border:1px solid #4a4a4a; border-radius:8px; padding:10px; background:#262626; margin-bottom:12px;<?php endif; ?> }
.<?= $prefix ?>ovr-toolbar { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
.<?= $prefix ?>ovr-count { color:#9fb1c9; font-size:12px; }
.<?= $prefix ?>ovr-list { display:flex; flex-direction:column; gap:4px; }
.<?= $prefix ?>ovr-item { background:#1a1a1a; border:1px solid #3a3a3a; border-radius:6px; padding:6px 8px; display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; align-items:center; }
.<?= $prefix ?>ovr-item.is-ignored { border-color:#7a5a2a; }
.<?= $prefix ?>ovr-info { display:flex; flex-direction:column; gap:2px; min-width:0; }
.<?= $prefix ?>ovr-key { font-weight:600; color:#e9efff; font-size:13px; display:flex; gap:6px; align-items:baseline; flex-wrap:wrap; }
.<?= $prefix ?>ovr-id { color:#7f8da3; font-size:11px; font-family:monospace; font-weight:400; }
.<?= $prefix ?>ovr-values { display:flex; gap:6px; align-items:baseline; flex-wrap:wrap; font-size:12px; color:#9fb1c9; min-width:0; }
.<?= $prefix ?>ovr-value { color:#e9efff; font-weight:600; overflow-wrap:anywhere; }
.<?= $prefix ?>ovr-inherit { overflow-wrap:anywhere; }
.<?= $prefix ?>ovr-badge { display:inline-block; padding:0 6px; border-radius:999px; font-size:11px; line-height:18px; border:1px solid #4a4a4a; color:#cfd9ea; background:#2a2a2a; white-space:nowrap; }
.<?= $prefix ?>ovr-badge.is-set { border-color:var(--synth-accent, rgb(182, 193, 33)); color:var(--synth-accent, rgb(182, 193, 33)); }
.<?= $prefix ?>ovr-badge.is-ignored { border-color:#c9963a; color:#f0c470; }
.<?= $prefix ?>ovr-actions { display:flex; gap:4px; }
.<?= $prefix ?>ovr-empty { color:#9fb1c9; font-size:12px; padding:8px; border:1px dashed #3a3a3a; border-radius:6px; }
.btn-<?= $prefix ?>ovr { padding:3px 10px; border-radius:4px; border:1px solid #4a4a4a; background:#3a3a3a; color:#e9efff; cursor:pointer; font-size:12px; }
.btn-<?= $prefix ?>ovr:hover { background:#4a4a4a; }
.btn-<?= $prefix ?>ovr.danger { color:#ff8b8b; }
.btn-<?= $prefix ?>ovr.danger:hover { background:#5a2a2a; border-color:#7a3a3a; }
.btn-<?= $prefix ?>ovr:focus-visible, .btn-add-<?= $prefix ?>ovr:focus-visible, .<?= $prefix ?>ovr-setting-option:focus-visible { outline:2px solid var(--synth-accent, rgb(182, 193, 33)); outline-offset:1px; }
.btn-add-<?= $prefix ?>ovr { padding:5px 12px; border-radius:6px; border:1px solid var(--synth-accent, rgb(182, 193, 33)); background:var(--synth-accent, rgb(182, 193, 33)); color:#111; cursor:pointer; font-weight:700; font-size:13px; }
.btn-add-<?= $prefix ?>ovr:hover { opacity:.9; }
.<?= $prefix ?>ovr-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:<?= $isProfileMode ? '10010' : '10100' ?>; align-items:center; justify-content:center; }
.<?= $prefix ?>ovr-modal { background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; max-width:640px; width:92%; max-height:84vh; display:flex; flex-direction:column; }
.<?= $prefix ?>ovr-modal:focus { outline:none; }
.<?= $prefix ?>ovr-modal-header, .<?= $prefix ?>ovr-modal-footer { padding:10px 14px; display:flex; gap:8px; align-items:center; }
.<?= $prefix ?>ovr-modal-header { border-bottom:1px solid #4a4a4a; justify-content:space-between; }
.<?= $prefix ?>ovr-modal-footer { border-top:1px solid #4a4a4a; justify-content:flex-end; }
.<?= $prefix ?>ovr-modal-title { font-weight:700; color:var(--synth-accent, rgb(182, 193, 33)); font-size:16px; margin:0; }
.<?= $prefix ?>ovr-modal-body { padding:12px 14px; overflow-y:auto; flex:1; }
.<?= $prefix ?>ovr-search { box-sizing:border-box; width:100%; padding:6px 8px; border-radius:6px; border:1px solid #4a4a4a; background:#1a1a1a; color:#e9efff; margin-bottom:8px; }
.<?= $prefix ?>ovr-settings-list { display:flex; flex-direction:column; gap:4px; }
.<?= $prefix ?>ovr-category { margin:10px 0 2px; color:var(--synth-accent, rgb(182, 193, 33)); font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.<?= $prefix ?>ovr-category:first-child { margin-top:0; }
.<?= $prefix ?>ovr-setting-option { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:2px 8px; width:100%; text-align:left; padding:6px 8px; border:1px solid #3a3a3a; border-radius:6px; cursor:pointer; background:#1a1a1a; color:#e9efff; font:inherit; }
.<?= $prefix ?>ovr-setting-option:hover { background:#2f2f2f; border-color:var(--synth-accent, rgb(182, 193, 33)); }
.<?= $prefix ?>ovr-setting-name { font-weight:600; font-size:13px; }
.<?= $prefix ?>ovr-setting-meta { color:#9fb1c9; font-size:11px; text-align:right; white-space:nowrap; }
.<?= $prefix ?>ovr-setting-desc { grid-column:1 / -1; color:#9fb1c9; font-size:11px; }
.<?= $prefix ?>ovr-input-group { display:flex; flex-direction:column; gap:6px; }
.<?= $prefix ?>ovr-input-label { font-weight:700; color:var(--synth-accent, rgb(182, 193, 33)); font-size:14px; }
.<?= $prefix ?>ovr-hint { color:#9fb1c9; font-size:12px; }
.<?= $prefix ?>ovr-inherit-box { font-size:12px; color:#cfd9ea; background:#1a1a1a; border:1px solid #3a3a3a; border-radius:6px; padding:6px 8px; overflow-wrap:anywhere; }
.<?= $prefix ?>ovr-input { box-sizing:border-box; width:100%; padding:6px 8px; border-radius:6px; border:1px solid #4a4a4a; background:#1a1a1a; color:#e9efff; font-size:14px; }
.<?= $prefix ?>ovr-input:focus { outline:none; border-color:var(--synth-accent, rgb(182, 193, 33)); }
.<?= $prefix ?>ovr-error { color:#ff8b8b; font-size:12px; }
<?php if ($isNpcMode): ?>
.advanced-json-toggle { margin-top:4px; cursor:pointer; color:#9fb1c9; font-size:12px; background:none; border:0; padding:0; text-align:left; }
.advanced-json-toggle:hover { color:var(--synth-accent, rgb(182, 193, 33)); }
.advanced-json-content { display:none; margin-top:6px; border:1px solid #4a4a4a; border-radius:6px; padding:8px; background:#1a1a1a; }
<?php endif; ?>
@media (max-width:560px) {
 .<?= $prefix ?>ovr-item { grid-template-columns:1fr; }
 .<?= $prefix ?>ovr-actions { justify-content:flex-end; }
}
</style><div class="<?= $prefix ?>ovr-container"><div class="<?= $prefix ?>ovr-toolbar"><span class="<?= $prefix ?>ovr-count" id="<?= $prefix ?>ovr-count" aria-live="polite"></span><button type="button" id="btn-add-<?= $prefix ?>ovr" class="btn-add-<?= $prefix ?>ovr">+ Add override</button></div><div id="<?= $prefix ?>ovr-list" class="<?= $prefix ?>ovr-list"></div><?php if ($isNpcMode): ?><button type="button" class="advanced-json-toggle" id="advanced-json-toggle" aria-expanded="false" aria-controls="advanced-json-content">Advanced: raw JSON editor</button><div class="advanced-json-content" id="advanced-json-content"><div id="extended_data_raw_editor" style="min-height: 300px;"></div></div><?php endif; ?></div><!-- Modal --><div id="<?= $prefix ?>ovr-modal" class="<?= $prefix ?>ovr-modal-backdrop"><div class="<?= $prefix ?>ovr-modal" role="dialog" aria-modal="true" tabindex="-1" aria-labelledby="<?= $prefix ?>ovr-modal-title"><div class="<?= $prefix ?>ovr-modal-header"><h2 class="<?= $prefix ?>ovr-modal-title" id="<?= $prefix ?>ovr-modal-title">Add override</h2><button type="button" class="btn-<?= $prefix ?>ovr" id="<?= $prefix ?>ovr-modal-close" aria-label="Close">Close</button></div><div class="<?= $prefix ?>ovr-modal-body"><div id="<?= $prefix ?>ovr-select-stage"><label class="<?= $prefix ?>ovr-hint" for="<?= $prefix ?>ovr-search">Choose a setting to override for this <?= $isProfileMode ? 'profile' : 'NPC' ?>.</label><input type="search" id="<?= $prefix ?>ovr-search" class="<?= $prefix ?>ovr-search" placeholder="Filter settings" autocomplete="off"><div id="<?= $prefix ?>ovr-settings-list" class="<?= $prefix ?>ovr-settings-list"></div></div><div id="<?= $prefix ?>ovr-value-stage" style="display: none;"><div id="<?= $prefix ?>ovr-value-form"></div></div></div><div class="<?= $prefix ?>ovr-modal-footer"><button type="button" class="btn-<?= $prefix ?>ovr" id="<?= $prefix ?>ovr-modal-back" style="display:none;">Back</button><button type="button" class="btn-<?= $prefix ?>ovr danger" id="<?= $prefix ?>ovr-modal-clear" style="display:none;">Clear override</button><button type="button" class="btn-<?= $prefix ?>ovr" id="<?= $prefix ?>ovr-modal-cancel">Cancel</button><button type="button" class="btn-add-<?= $prefix ?>ovr" id="<?= $prefix ?>ovr-modal-save" style="display:none;">Save override</button></div></div></div><script>
(function(){
 const MODE = <?= json_encode($mode, $jsonScriptFlags) ?>;
 const IS_NPC_MODE = MODE === 'npc';
 // Display name mappings for UI labels
 const displayNames = { 'xtts-fastapi': 'xtts/chatterbox' };
 const IS_PROFILE_MODE = MODE === 'profile';
 const SCOPE_LABEL = IS_PROFILE_MODE ? 'Profile' : 'NPC';
 const FIELD_NAME = <?= json_encode($fieldName, $jsonScriptFlags) ?>;
 const PREFIX = <?= json_encode($prefix, $jsonScriptFlags) ?>;
 const RESERVED_KEYS = <?= json_encode($reservedKeys, $jsonScriptFlags) ?>;
 const SYSTEM_FIELDS = <?= json_encode((object)$systemFields, $jsonScriptFlags) ?>;
 const CURRENT_DATA = <?= json_encode((object)$currentData, $jsonScriptFlags) ?>;
 const SCHEMA = <?= json_encode((object)$schema, $jsonScriptFlags) ?>;
 const INHERITED = <?= json_encode((object)$inherited, $jsonScriptFlags) ?>;
 const GLOBAL_ONLY = <?= json_encode(array_values(function_exists('synthGetGlobalOnlySettingIds') ? synthGetGlobalOnlySettingIds() : []), $jsonScriptFlags) ?>;

 let overridesState = {...CURRENT_DATA};
 let selectedSetting = null;
 let editingKey = null;
 let rawJsonEditor = null;
 let returnFocus = null;

 // Get element by ID with prefix
 function el(id) {
 return document.getElementById(PREFIX + id);
 }

 // Custom label mapping
 function getLabel(key) {
 if (SCHEMA[key] && SCHEMA[key].ui_label) {
 return SCHEMA[key].ui_label;
 }
 const customLabels = {
 'RECHAT_H': 'RECHAT RESPONSE ROUNDS',
 'RECHAT_P': 'RECHAT PROBAILITY',
 'WORLDKNOWLEDGE_AMOUNT': 'WORLDKNOWLEDGE ARTICLES AMOUNT',
 'BORED_EVENT': 'BORED EVENT CHANCE',
 'CONTEXT_HISTORY': 'CONTEXT HISTORY EVENT COUNT',
 'CONTEXT_HISTORY_DIARY': 'CONTEXT HISTORY DIARY EVENT COUNT',
 'CONTEXT_HISTORY_DYNAMIC_PROFILE': 'CONTEXT HISTORY DYNAMIC PROFILE EVENT COUNT'
 };
 const normalizedKey = String(key).toUpperCase();
 if (customLabels[normalizedKey]) {
 return customLabels[normalizedKey];
 }
 return String(key)
 .split('@')
 .map(part => part.replace(/_/g, ' '))
 .join(' -> ');
 }

 function isOn(value) {
 if (value === true || value === 1) return true;
 return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase());
 }

 // Mirrors the runtime skip rules: CoreProfile::setOldGlobals ignores null and '';
 // NpcMaster::setOldGlobalsFromCurrentNpcData also ignores empty arrays (PHP empty() unless numeric/bool).
 function isApplied(key, value) {
 if (GLOBAL_ONLY.includes(key)) return false;
 if (value === null || value === undefined || value === '') return false;
 if (IS_NPC_MODE && typeof value === 'object' && Object.keys(value).length === 0) return false;
 return true;
 }

 // Human-readable value as the runtime would normalize it
 function formatValue(key, value) {
 if (value === null || value === undefined) return '(no value)';
 if (value === '') return '(empty)';
 const schema = SCHEMA[key] || {};
 if (schema.type === 'boolean') return isOn(value) ? 'On' : 'Off';
 if (Array.isArray(value) || typeof value === 'object') return JSON.stringify(value);
 const text = String(value);
 const labels = schema.value_labels || {};
 if (labels[text] !== undefined) return labels[text];
 if (displayNames[text] !== undefined) return displayNames[text];
 return text.length > 80 ? text.slice(0, 77) + '...' : text;
 }

 function inheritedText(key) {
 const info = INHERITED[key];
 if (!info) return '';
 if (info.scope === 'config') return 'Server config (no stored global value)';
 if (info.scope === 'unknown') return `unknown (${info.source})`;
 return `${info.source}: ${formatValue(key, info.value)}`;
 }

 // Settings the editor knows; other stored keys (server-written NPC data such as baseid or voice_metadata) are not
 // settings, so they are kept unchanged and not listed as overrides.
 function isSetting(key) {
 return SCHEMA.hasOwnProperty(key) || GLOBAL_ONLY.includes(key);
 }

 function overrideKeys() {
 return Object.keys(overridesState).filter(key => key !== 'middle_term_memory' && isSetting(key));
 }

 function storedNote() {
 const stored = Object.keys(overridesState).filter(key => key !== 'middle_term_memory' && !isSetting(key));
 if (stored.length === 0) return '';
 return `<div class="${PREFIX}ovr-hint">Also stored, not settings, kept unchanged: ${escapeHtml(stored.join(', '))}.${IS_NPC_MODE ? ' Edit them in the raw JSON editor.' : ''}</div>`;
 }

 // Render overrides list
 function renderOverridesList() {
 const list = el('ovr-list');
 if (!list) return;

 const keys = overrideKeys();
 const count = el('ovr-count');
 const skipped = keys.filter(key => !isApplied(key, overridesState[key])).length;
 if (count) count.textContent = (keys.length === 1 ? '1 override' : `${keys.length} overrides`) + (skipped ? `, ${skipped} not applied` : '');
 if (keys.length === 0) {
 const scopeText = IS_PROFILE_MODE ? 'this profile inherits every global setting' : 'this NPC inherits every setting from its profile and the global settings';
 list.innerHTML = `<div class="${PREFIX}ovr-empty">No overrides: ${scopeText}.</div>` + storedNote();
 syncOverrides();
 return;
 }

 list.innerHTML = keys.map(key => {
 const value = overridesState[key];
 const label = getLabel(key);
 const globalOnly = GLOBAL_ONLY.includes(key);
 const ignored = !isApplied(key, value);
 const inherit = inheritedText(key);
 const badge = globalOnly
 ? `<span class="${PREFIX}ovr-badge is-ignored" title="Read from the global settings only; this stored override has no effect.">Not applied: global only</span>`
 : ignored
 ? `<span class="${PREFIX}ovr-badge is-ignored" title="The runtime skips empty values, so this stored entry has no effect.">Not applied: empty</span>`
 : `<span class="${PREFIX}ovr-badge is-set">${SCOPE_LABEL}</span>`;
 const inheritHtml = inherit ? `<span class="${PREFIX}ovr-inherit">${ignored ? 'inherits' : 'replaces'} ${escapeHtml(inherit)}</span>` : '';
 const editButton = globalOnly ? '' :`<button type="button" class="btn-${PREFIX}ovr btn-edit-${PREFIX}ovr" data-key="${escapeHtml(key)}" aria-label="Edit ${escapeHtml(label)} override">Edit</button>`;
 return `<div class="${PREFIX}ovr-item${ignored ? ' is-ignored' : ''}" data-key="${escapeHtml(key)}"><div class="${PREFIX}ovr-info"><div class="${PREFIX}ovr-key">${escapeHtml(label)}<span class="${PREFIX}ovr-id">${escapeHtml(key)}</span></div><div class="${PREFIX}ovr-values">${badge}<span class="${PREFIX}ovr-value" title="${escapeHtml(typeof value === 'object' ? JSON.stringify(value) : String(value))}">${escapeHtml(formatValue(key, value))}</span>${inheritHtml}</div></div><div class="${PREFIX}ovr-actions">${editButton}<button type="button" class="btn-${PREFIX}ovr danger btn-remove-${PREFIX}ovr" data-key="${escapeHtml(key)}" aria-label="Clear ${escapeHtml(label)} override and inherit">Clear</button></div></div>`;
 }).join('') + storedNote();

 // Rebind event listeners
 list.querySelectorAll(`.btn-edit-${PREFIX}ovr`).forEach(btn => {
 btn.addEventListener('click', function() {
 editOverride(this.getAttribute('data-key'), this);
 });
 });

 list.querySelectorAll(`.btn-remove-${PREFIX}ovr`).forEach(btn => {
 btn.addEventListener('click', function() {
 removeOverride(this.getAttribute('data-key'));
 });
 });

 syncOverrides();
 }

 // Sync overrides to form
 function syncOverrides() {
 if (IS_NPC_MODE) {
 syncToTextarea();
 } else if (IS_PROFILE_MODE) {
 syncToMetadata();
 }
 }

 // Sync to textarea (NPC mode)
 function syncToTextarea() {
 const textarea = document.querySelector(`textarea[name="${FIELD_NAME}"]`);
 if (!textarea) return;

 const merged = {...overridesState, ...SYSTEM_FIELDS};
 textarea.value = JSON.stringify(merged);

 if (rawJsonEditor && typeof rawJsonEditor.set === 'function') {
 try {
 rawJsonEditor.set({json: merged});
 } catch (e) {
 console.error('Failed to update raw JSON editor:', e);
 }
 }
 }

 // Sync to metadata via hidden inputs (Profile mode). An empty value deletes the key server-side.
 // Stored null, '', array or object values are not representable as one scalar input; they are left
 // to the posted metadata JSON unchanged instead of being stringified.
 function syncToMetadata() {
 const container = document.querySelector(`.${PREFIX}ovr-container`);
 if (!container) return;

 container.querySelectorAll('input[name^="meta_vis["]').forEach(el => el.remove());

 const allKeys = Array.from(new Set([
 ...Object.keys(SCHEMA),
 ...Object.keys(CURRENT_DATA || {}),
 ...Object.keys(overridesState || {})
 ]));
 for (const key of allKeys) {
 let value = '';
 if (overridesState.hasOwnProperty(key)) {
 const stored = overridesState[key];
 if (stored === null || stored === '' || typeof stored === 'object') continue;
 value = String(stored);
 }
 const input = document.createElement('input');
 input.type = 'hidden';
 input.name = `meta_vis[${key}]`;
 input.value = value;
 container.appendChild(input);
 }
 }

 function openModal(trigger) {
 returnFocus = trigger || document.activeElement;
 el('ovr-modal').style.display = 'flex';
 }

 function isModalOpen() {
 const modal = el('ovr-modal');
 return !!modal && modal.style.display === 'flex';
 }

 function dialogElement() {
 return el('ovr-modal').querySelector('[role="dialog"]');
 }

 // Visible, enabled controls inside the dialog, in tab order
 function focusableInDialog() {
 const selector = 'button, input, select, textarea, a[href], [tabindex]:not([tabindex="-1"])';
 return Array.from(dialogElement().querySelectorAll(selector))
 .filter(node => !node.disabled && node.getClientRects().length > 0);
 }

 // Keep Tab and Shift+Tab inside the dialog while it is open
 function trapTab(e) {
 const nodes = focusableInDialog();
 if (nodes.length === 0) {
 e.preventDefault();
 return;
 }
 const first = nodes[0];
 const last = nodes[nodes.length - 1];
 const active = document.activeElement;
 const inside = nodes.includes(active);
 if (e.shiftKey && (active === first || !inside)) {
 e.preventDefault();
 last.focus();
 } else if (!e.shiftKey && (active === last || !inside)) {
 e.preventDefault();
 first.focus();
 }
 }

 // Open add modal
 function openAddModal() {
 editingKey = null;
 selectedSetting = null;
 el('ovr-modal-title').textContent = 'Add override';
 showSelectStage();
 const searchInput = el('ovr-search');
 if (searchInput) searchInput.value = '';
 renderSettingsList();
 openModal(document.getElementById(`btn-add-${PREFIX}ovr`));
 if (searchInput) searchInput.focus();
 }

 function showSelectStage() {
 el('ovr-select-stage').style.display = 'block';
 el('ovr-value-stage').style.display = 'none';
 el('ovr-modal-save').style.display = 'none';
 el('ovr-modal-back').style.display = 'none';
 el('ovr-modal-clear').style.display = 'none';
 }

 function showValueStage(canGoBack) {
 el('ovr-select-stage').style.display = 'none';
 el('ovr-value-stage').style.display = 'block';
 el('ovr-modal-save').style.display = 'inline-block';
 el('ovr-modal-back').style.display = canGoBack ? 'inline-block' : 'none';
 el('ovr-modal-clear').style.display = overridesState.hasOwnProperty(selectedSetting) ? 'inline-block' : 'none';
 const input = el('ovr-value-input');
 if (input) input.focus();
 }

 // Edit existing override
 function editOverride(key, trigger) {
 editingKey = key;
 selectedSetting = key;
 el('ovr-modal-title').textContent = 'Edit override';
 renderValueForm(key, overridesState[key]);
 openModal(trigger);
 showValueStage(false);
 }

 // Clearing removes the key so the profile/global value applies again
 function removeOverride(key) {
 if (!confirm(`Clear the ${SCOPE_LABEL.toLowerCase()} override for "${getLabel(key)}" and inherit ${inheritedText(key) || 'the next level'}?`)) return false;
 delete overridesState[key];
 renderOverridesList();
 const addBtn = document.getElementById(`btn-add-${PREFIX}ovr`);
 if (addBtn) addBtn.focus();
 return true;
 }

 // Render settings list grouped by category
 function renderSettingsList(filter = '') {
 const list = el('ovr-settings-list');
 if (!list) return;

 const filterLower = filter.toLowerCase();
 const categories = {};
 for (const [key, schema] of Object.entries(SCHEMA)) {
 const category = schema.category || 'Other';
 if (!categories[category]) categories[category] = [];
 categories[category].push(key);
 }

 let html = '';
 for (const [category, keys] of Object.entries(categories)) {
 const filteredKeys = keys.filter(key => {
 if (!filter) return true;
 const label = getLabel(key).toLowerCase();
 const desc = (SCHEMA[key].description || '').toLowerCase();
 return label.includes(filterLower) || desc.includes(filterLower) || key.toLowerCase().includes(filterLower);
 });
 if (filteredKeys.length === 0) continue;

 html += `<div class="${PREFIX}ovr-category">${escapeHtml(category)}</div>`;
 filteredKeys.forEach(key => {
 const desc = (SCHEMA[key].description || '').replace(/<[^>]*>/g, '').substring(0, 120);
 const meta = overridesState.hasOwnProperty(key)
 ? `${SCOPE_LABEL}: ${formatValue(key, overridesState[key])}`
 : (inheritedText(key) || '');
 html += `<button type="button" class="${PREFIX}ovr-setting-option" data-key="${escapeHtml(key)}"><span class="${PREFIX}ovr-setting-name">${escapeHtml(getLabel(key))}</span><span class="${PREFIX}ovr-setting-meta">${escapeHtml(meta)}</span><span class="${PREFIX}ovr-setting-desc">${escapeHtml(desc)}${desc.length >= 120 ? '...' : ''}</span></button>`;
 });
 }

 if (html === '') {
 list.innerHTML = `<div class="${PREFIX}ovr-empty">No settings found${filter ? ' matching "' + escapeHtml(filter) + '"' : ''}</div>`;
 return;
 }
 list.innerHTML = html;

 // Bind click events
 list.querySelectorAll(`.${PREFIX}ovr-setting-option`).forEach(opt => {
 opt.addEventListener('click', function() {
 selectSetting(this.getAttribute('data-key'));
 });
 });
 }

 // Select a setting
 function selectSetting(key) {
 selectedSetting = key;
 renderValueForm(key, overridesState[key]);
 showValueStage(true);
 }

 // Render value form
 function renderValueForm(key, currentValue) {
 const form = el('ovr-value-form');
 if (!form) return;

 const schema = SCHEMA[key] || {};
 const type = schema.type || 'string';
 const desc = (schema.description || '').replace(/<[^>]*>/g, '');
 const values = schema.values || [];
 const valueLabels = schema.value_labels || {};
 const inputId = `${PREFIX}ovr-value-input`;
 const info = INHERITED[key];
 const startValue = (!isApplied(key, currentValue) && info && info.scope !== 'config' && info.scope !== 'unknown') ? info.value : currentValue;

 let inputHtml = '';
 let validationHint = '';

 if (type === 'boolean') {
 const on = isOn(startValue);
 inputHtml = `<select id="${inputId}" class="${PREFIX}ovr-input"><option value="true" ${on ? 'selected' : ''}>On</option><option value="false" ${on ? '' : 'selected'}>Off</option></select>`;
 validationHint = `<div class="${PREFIX}ovr-hint">Off is saved as an explicit override. Use Clear to inherit instead.</div>`;
 } else if (type === 'select' && values.length > 0) {
 inputHtml = `<select id="${inputId}" class="${PREFIX}ovr-input" required><option value="">-- Select --</option>${values.map(v => `<option value="${escapeHtml(v)}" ${String(startValue) === String(v) ? 'selected' : ''}>${escapeHtml(valueLabels[v] || displayNames[v] || v)}</option>`).join('')}</select>`;
 } else if (type === 'integer') {
 inputHtml = `<input type="number" id="${inputId}" class="${PREFIX}ovr-input" value="${escapeHtml(startValue ?? '')}" step="1" required>`;
 validationHint = `<div class="${PREFIX}ovr-hint">Whole number. 0 is saved as an explicit override.</div>`;
 } else if (type === 'number') {
 inputHtml = `<input type="number" id="${inputId}" class="${PREFIX}ovr-input" value="${escapeHtml(startValue ?? '')}" step="any" required>`;
 validationHint = `<div class="${PREFIX}ovr-hint">Number. 0 is saved as an explicit override.</div>`;
 } else if (type === 'longstring') {
 inputHtml = `<textarea id="${inputId}" class="${PREFIX}ovr-input" rows="6" required>${escapeHtml(startValue ?? '')}</textarea>`;
 } else {
 inputHtml = `<input type="text" id="${inputId}" class="${PREFIX}ovr-input" value="${escapeHtml(startValue ?? '')}" required>`;
 }

 const inherit = inheritedText(key);
 form.innerHTML = `<div class="${PREFIX}ovr-input-group"><label class="${PREFIX}ovr-input-label" for="${inputId}">${escapeHtml(getLabel(key))} <span class="${PREFIX}ovr-id">${escapeHtml(key)}</span></label>${desc ? `<div class="${PREFIX}ovr-hint">${escapeHtml(desc)}</div>` : ''}${inherit ? `<div class="${PREFIX}ovr-inherit-box">Without this override: <strong>${escapeHtml(inherit)}</strong></div>` : ''}${inputHtml}${validationHint}<div class="${PREFIX}ovr-error" id="${PREFIX}ovr-error" role="alert"></div></div>`;
 }

 // Save override
 function saveOverride() {
 if (!selectedSetting) return;

 const schema = SCHEMA[selectedSetting] || {};
 const type = schema.type || 'string';
 const input = el('ovr-value-input');
 if (!input) return;

 let value = null;
 let errorMsg = '';

 if (type === 'boolean') {
 value = input.value === 'true';
 } else if (type === 'select') {
 value = input.value;
 if (value === '') errorMsg = 'Select a value, or use Clear to inherit.';
 } else if (type === 'integer') {
 value = parseInt(input.value, 10);
 if (isNaN(value)) errorMsg = 'Enter a whole number.';
 } else if (type === 'number') {
 value = parseFloat(input.value);
 if (isNaN(value)) errorMsg = 'Enter a number.';
 } else {
 value = input.value.trim();
 // An empty string is treated as unset by the runtime, so it cannot be an override.
 if (value === '') errorMsg = 'Enter a value, or use Clear to inherit.';
 }

 if (errorMsg !== '') {
 const error = el('ovr-error');
 if (error) error.textContent = errorMsg;
 input.focus();
 return;
 }

 overridesState[selectedSetting] = value;
 renderOverridesList();
 closeModal();
 }

 // Close modal
 function closeModal() {
 el('ovr-modal').style.display = 'none';
 selectedSetting = null;
 editingKey = null;
 let target = returnFocus;
 // The list is re-rendered on save, so find the new Edit button for the same key
 if (target && !document.body.contains(target)) {
 const key = target.getAttribute('data-key');
 target = Array.from(el('ovr-list').querySelectorAll(`.btn-edit-${PREFIX}ovr`))
 .find(btn => btn.getAttribute('data-key') === key) || null;
 }
 if (!target) target = document.getElementById(`btn-add-${PREFIX}ovr`);
 if (target) target.focus();
 returnFocus = null;
 }

 // Escape HTML
 function escapeHtml(str) {
 const div = document.createElement('div');
 div.textContent = String(str ?? '');
 return div.innerHTML.replace(/"/g, '&quot;');
 }

 // Initialize
 function init() {
 renderOverridesList();

 // Bind events
 const addBtn = document.getElementById(`btn-add-${PREFIX}ovr`);
 if (addBtn) addBtn.addEventListener('click', openAddModal);

 const closeBtn = el('ovr-modal-close');
 if (closeBtn) closeBtn.addEventListener('click', closeModal);

 const cancelBtn = el('ovr-modal-cancel');
 if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

 const saveBtn = el('ovr-modal-save');
 if (saveBtn) saveBtn.addEventListener('click', saveOverride);

 const clearBtn = el('ovr-modal-clear');
 if (clearBtn) {
 clearBtn.addEventListener('click', function() {
 const key = selectedSetting;
 if (key && removeOverride(key)) {
 returnFocus = document.getElementById(`btn-add-${PREFIX}ovr`);
 closeModal();
 }
 });
 }

 const backBtn = el('ovr-modal-back');
 if (backBtn) {
 backBtn.addEventListener('click', function() {
 selectedSetting = null;
 showSelectStage();
 const searchInput = el('ovr-search');
 if (searchInput) searchInput.focus();
 });
 }

 const searchInput = el('ovr-search');
 if (searchInput) {
 searchInput.addEventListener('input', function() {
 renderSettingsList(this.value);
 });
 }

 if (IS_NPC_MODE) {
 // Advanced JSON toggle
 const jsonToggle = document.getElementById('advanced-json-toggle');
 const jsonContent = document.getElementById('advanced-json-content');
 if (jsonToggle && jsonContent) {
 jsonToggle.addEventListener('click', function() {
 const isVisible = jsonContent.style.display === 'block';
 jsonContent.style.display = isVisible ? 'none' : 'block';
 this.setAttribute('aria-expanded', isVisible ? 'false' : 'true');

 if (!isVisible && !rawJsonEditor) {
 initRawJsonEditor();
 }
 });
 }
 }

 // Close modal on backdrop click or Escape
 const modal = el('ovr-modal');
 if (modal) {
 modal.addEventListener('click', function(e) {
 if (e.target === modal) closeModal();
 });
 modal.addEventListener('keydown', function(e) {
 if (e.key === 'Escape') {
 e.stopPropagation();
 closeModal();
 } else if (e.key === 'Tab') {
 trapTab(e);
 } else if (e.key === 'Enter' && e.target && e.target.id === PREFIX + 'ovr-value-input' && e.target.tagName === 'INPUT') {
 e.preventDefault();
 saveOverride();
 }
 });
 // Pull focus back if it escapes the open dialog (for example a click on an outer page control)
 document.addEventListener('focusin', function(e) {
 if (!isModalOpen() || modal.contains(e.target)) return;
 const nodes = focusableInDialog();
 (nodes[0] || dialogElement()).focus();
 });
 }
 }

 // Initialize raw JSON editor (NPC mode only)
 async function initRawJsonEditor() {
 try {
 const { createJSONEditor } = await import('https://cdn.jsdelivr.net/npm/vanilla-jsoneditor/standalone.js');
 const textarea = document.querySelector(`textarea[name="${FIELD_NAME}"]`);
 const initialContent = textarea ? JSON.parse(textarea.value || '{}') : {};

 rawJsonEditor = createJSONEditor({
 target: document.getElementById('extended_data_raw_editor'),
 props: {
 content: { json: initialContent },
 onChange: (updatedContent) => {
 try {
 const newData = updatedContent.json || {};
 const newOverrides = {};
 for (const key in newData) {
 if (!RESERVED_KEYS.includes(key)) {
 newOverrides[key] = newData[key];
 }
 }
 overridesState = newOverrides;
 renderOverridesList();
 } catch (e) {
 console.error('JSON editor update error:', e);
 }
 }
 }
 });
 } catch (e) {
 console.error('Failed to initialize raw JSON editor:', e);
 }
 }

 // Export sync functions
 if (IS_NPC_MODE) {
 window.syncExtendedDataOverrides = syncToTextarea;
 } else if (IS_PROFILE_MODE) {
 window.syncProfileGlobalOverrides = syncToMetadata;
 }

 // Initialize on DOM ready
 if (document.readyState === 'loading') {
 document.addEventListener('DOMContentLoaded', init);
 } else {
 init();
 }
})();
</script>
