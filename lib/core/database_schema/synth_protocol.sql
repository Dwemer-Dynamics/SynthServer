CREATE SCHEMA IF NOT EXISTS synth;
CREATE SCHEMA IF NOT EXISTS synth_meta;

-- Stable Fallout 4 identity; display names are descriptive and never identity keys.
CREATE TABLE IF NOT EXISTS synth.actor_identities (
    id BIGSERIAL PRIMARY KEY,
    game TEXT NOT NULL DEFAULT 'fo4' CHECK (game = 'fo4'),
    full_form_id VARCHAR(10) NOT NULL CHECK (full_form_id ~ '^0x[0-9A-Fa-f]{8}$'),
    origin_plugin TEXT NOT NULL CHECK (origin_plugin ~* '^[^/\\]+\.(esm|esp|esl)$'),
    playthrough_id TEXT NOT NULL CHECK (playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    display_name TEXT,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE UNIQUE INDEX IF NOT EXISTS actor_identities_canonical_key
    ON synth.actor_identities (game, upper(full_form_id), lower(origin_plugin), playthrough_id);
CREATE INDEX IF NOT EXISTS actor_identities_display_name
    ON synth.actor_identities (lower(display_name)) WHERE display_name IS NOT NULL;

ALTER TABLE IF EXISTS synth.core_npc_master
    ADD COLUMN IF NOT EXISTS actor_identity_id BIGINT REFERENCES synth.actor_identities(id) ON DELETE SET NULL;
CREATE UNIQUE INDEX IF NOT EXISTS core_npc_master_actor_identity
    ON synth.core_npc_master (actor_identity_id) WHERE actor_identity_id IS NOT NULL;

-- Native names are labels; retain name uniqueness only for the unbound legacy lane.
ALTER TABLE synth.core_npc_master DROP CONSTRAINT IF EXISTS npc_master_npc_name_key;
CREATE UNIQUE INDEX IF NOT EXISTS core_npc_master_legacy_name
    ON synth.core_npc_master (npc_name) WHERE actor_identity_id IS NULL;

-- One authoritative generation lane per connected runtime session.
CREATE TABLE IF NOT EXISTS synth_meta.runtime_sessions (
    session_id TEXT PRIMARY KEY CHECK (session_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    playthrough_id TEXT NOT NULL CHECK (playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    game TEXT NOT NULL CHECK (game = 'fo4'),
    runtime_variant TEXT NOT NULL CHECK (runtime_variant IN ('flat', 'vr')),
    client_version TEXT NOT NULL,
    runtime_version TEXT NOT NULL,
    active_generation BIGINT NOT NULL CHECK (active_generation > 0),
    capabilities JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(capabilities) = 'array'),
    state TEXT NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'halted', 'expired')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS runtime_sessions_playthrough_state
    ON synth_meta.runtime_sessions (playthrough_id, state, updated_at DESC);

-- Additive upgrade: legacy sessions have no negotiated logical response owner.
ALTER TABLE synth_meta.runtime_sessions
    ADD COLUMN IF NOT EXISTS active_turn_id TEXT,
    ADD COLUMN IF NOT EXISTS active_turn_sequence BIGINT NOT NULL DEFAULT 0 CHECK (active_turn_sequence >= 0);

-- Canonical request claims make duplicates replayable and body conflicts deterministic.
CREATE TABLE IF NOT EXISTS synth_meta.request_executions (
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id) ON DELETE CASCADE,
    request_id TEXT NOT NULL CHECK (request_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    turn_id TEXT NOT NULL CHECK (turn_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    generation BIGINT NOT NULL CHECK (generation > 0),
    request_sha256 CHAR(64) NOT NULL CHECK (request_sha256 ~ '^[0-9a-f]{64}$'),
    event_type TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT 'claimed' CHECK (state IN ('claimed', 'streaming', 'complete', 'cancelled', 'failed')),
    response_content_type TEXT,
    response_body BYTEA,
    error_code TEXT,
    claimed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    PRIMARY KEY (session_id, request_id)
);
CREATE INDEX IF NOT EXISTS request_executions_turn_generation
    ON synth_meta.request_executions (session_id, turn_id, generation, claimed_at);

CREATE TABLE IF NOT EXISTS synth_meta.protocol_events (
    id BIGSERIAL PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id) ON DELETE CASCADE,
    request_id TEXT NOT NULL,
    turn_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    event_type TEXT NOT NULL,
    canonical_event JSONB NOT NULL,
    event_sha256 CHAR(64) NOT NULL CHECK (event_sha256 ~ '^[0-9a-f]{64}$'),
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (session_id, request_id),
    FOREIGN KEY (session_id, request_id)
        REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS protocol_events_turn_generation
    ON synth_meta.protocol_events (session_id, turn_id, generation, received_at);

CREATE TABLE IF NOT EXISTS synth_meta.context_snapshots (
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id) ON DELETE CASCADE,
    generation BIGINT NOT NULL CHECK (generation > 0),
    snapshot_sequence BIGINT NOT NULL CHECK (snapshot_sequence > 0),
    playthrough_id TEXT NOT NULL,
    player_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    target_identity_id BIGINT REFERENCES synth.actor_identities(id),
    audience_identity_ids JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(audience_identity_ids) = 'array'),
    context_payload JSONB NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id, generation, snapshot_sequence)
);

-- Emission evidence is independent of relationship evaluation. Completion/body proof is still required.
CREATE TABLE IF NOT EXISTS synth_meta.dialogue_origin_policies (
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    execution_mode TEXT NOT NULL,
    policy JSONB NOT NULL CHECK (jsonb_typeof(policy)='object'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id,request_id),
    FOREIGN KEY (session_id,request_id)
        REFERENCES synth_meta.request_executions(session_id,request_id) ON DELETE CASCADE
);

-- Existing origins stay unknown; never reconstruct their player input from mixed historical event rows.
ALTER TABLE synth_meta.dialogue_origin_policies ADD COLUMN IF NOT EXISTS input_text TEXT;

CREATE TABLE IF NOT EXISTS synth_meta.response_routes (
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    line_id TEXT NOT NULL CHECK (length(line_id) BETWEEN 1 AND 160),
    line_sha256 CHAR(64) NOT NULL CHECK (line_sha256 ~ '^[0-9a-f]{64}$'),
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    speaker JSONB NOT NULL CHECK (jsonb_typeof(speaker) = 'object'),
    listener_state TEXT NOT NULL CHECK (listener_state IN ('addressed', 'unavailable', 'ambiguous')),
    listener JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id, request_id, line_id),
    FOREIGN KEY (session_id, request_id)
        REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id, generation, context_sequence)
        REFERENCES synth_meta.context_snapshots(session_id, generation, snapshot_sequence),
    CHECK ((listener_state = 'addressed' AND listener IS NOT NULL AND jsonb_typeof(listener) = 'object')
        OR (listener_state <> 'addressed' AND listener IS NULL))
);

-- Existing routes stay uncorrelated; neither printed names nor TTS cache keys prove an utterance.
ALTER TABLE synth_meta.response_routes ADD COLUMN IF NOT EXISTS utterance_id TEXT
    CHECK (utterance_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$');
CREATE UNIQUE INDEX IF NOT EXISTS response_routes_utterance_unique
    ON synth_meta.response_routes(session_id,request_id,utterance_id) WHERE utterance_id IS NOT NULL;

-- Native continuation budgets never reroll because a temporary file expires or a caller retries.
CREATE TABLE IF NOT EXISTS synth_meta.rechat_chains (
    id BIGSERIAL PRIMARY KEY,
    session_id TEXT NOT NULL,
    root_request_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    turn_id TEXT NOT NULL,
    runtime_variant TEXT NOT NULL CHECK (runtime_variant IN ('flat', 'vr')),
    mode TEXT NOT NULL CHECK (mode IN ('tight', 'conversational', 'group')),
    policy JSONB NOT NULL CHECK (jsonb_typeof(policy) = 'object'),
    budget BIGINT NOT NULL CHECK (budget >= 0),
    used_rounds BIGINT NOT NULL DEFAULT 0 CHECK (used_rounds >= 0 AND used_rounds <= budget),
    terminal_reason TEXT CHECK (terminal_reason IN ('budget_exhausted', 'private', 'no_candidate', 'ineligible')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (session_id, root_request_id),
    UNIQUE (id, session_id),
    FOREIGN KEY (session_id, root_request_id)
        REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id, generation, context_sequence)
        REFERENCES synth_meta.context_snapshots(session_id, generation, snapshot_sequence)
);

-- A parent response can authorize only one child, regardless of split line or changed child ID.
CREATE TABLE IF NOT EXISTS synth_meta.rechat_continuations (
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    chain_id BIGINT NOT NULL,
    parent_request_id TEXT NOT NULL,
    parent_line_id TEXT NOT NULL,
    parent_response_sha256 CHAR(64) NOT NULL CHECK (parent_response_sha256 ~ '^[0-9a-f]{64}$'),
    round_number BIGINT,
    status TEXT NOT NULL CHECK (status IN ('reserved', 'terminal')),
    terminal_reason TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id, request_id),
    UNIQUE (session_id, parent_request_id),
    UNIQUE (chain_id, round_number),
    FOREIGN KEY (chain_id, session_id) REFERENCES synth_meta.rechat_chains(id, session_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id, request_id)
        REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id, parent_request_id)
        REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE,
    CHECK ((status = 'reserved' AND round_number IS NOT NULL AND round_number > 0 AND terminal_reason IS NULL)
        OR (status = 'terminal' AND round_number IS NULL AND terminal_reason IS NOT NULL))
);

-- Selected response ownership is immutable and separate from merely reserving a round.
CREATE TABLE IF NOT EXISTS synth_meta.rechat_selections (
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('npc', 'narrator', 'terminal')),
    actor_identity_id BIGINT REFERENCES synth.actor_identities(id),
    -- Historical IDs must not prevent explicit profile management; dispatch must revalidate live bindings.
    npc_id INTEGER CHECK (npc_id > 0),
    profile_id INTEGER CHECK (profile_id > 0),
    identity JSONB,
    terminal_reason TEXT CHECK (terminal_reason IN ('no_candidate', 'ineligible', 'private', 'budget_exhausted')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id, request_id),
    FOREIGN KEY (session_id, request_id)
        REFERENCES synth_meta.rechat_continuations(session_id, request_id) ON DELETE CASCADE,
    CHECK ((role='npc' AND actor_identity_id IS NOT NULL AND npc_id IS NOT NULL
            AND profile_id IS NOT NULL AND identity IS NOT NULL AND jsonb_typeof(identity)='object' AND terminal_reason IS NULL)
        OR (role='narrator' AND actor_identity_id IS NULL AND npc_id IS NULL AND profile_id IS NOT NULL
            AND identity IS NULL AND terminal_reason IS NULL)
        OR (role='terminal' AND actor_identity_id IS NULL AND npc_id IS NULL AND profile_id IS NULL
            AND identity IS NULL AND terminal_reason IS NOT NULL))
);

-- Only the atomic coordinator may authorize a stored choice for downstream generation.
ALTER TABLE synth_meta.rechat_selections ADD COLUMN IF NOT EXISTS prepared_at TIMESTAMPTZ;

-- Runtime-managed agents use canonical identity rather than mutable display names.
CREATE TABLE IF NOT EXISTS synth_meta.active_agents (
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id) ON DELETE CASCADE,
    generation BIGINT NOT NULL CHECK (generation > 0),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id) ON DELETE CASCADE,
    source TEXT NOT NULL CHECK (source IN ('manual', 'automatic')),
    state TEXT NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'inactive', 'stale')),
    activated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id, generation, actor_identity_id)
);
CREATE INDEX IF NOT EXISTS active_agents_dispatch
    ON synth_meta.active_agents (session_id, generation, state, last_seen_at DESC);

-- Media IDs and cache keys are the only client-visible references; storage_key remains server-internal.
CREATE TABLE IF NOT EXISTS synth_meta.media_objects (
    media_id TEXT PRIMARY KEY CHECK (media_id ~ '^media/[0-9a-f]{64}$'),
    cache_key TEXT UNIQUE,
    storage_key TEXT NOT NULL,
    content_type TEXT NOT NULL CHECK (content_type IN (
        'audio/wav', 'audio/ogg', 'image/jpeg', 'image/png', 'image/webp'
    )),
    byte_length BIGINT NOT NULL CHECK (byte_length BETWEEN 1 AND 16777216),
    sha256 CHAR(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    state TEXT NOT NULL CHECK (state IN ('pending', 'ready', 'failed', 'expired')),
    request_id TEXT,
    turn_id TEXT,
    utterance_id TEXT,
    generation BIGINT CHECK (generation > 0),
    speaker_identity_id BIGINT REFERENCES synth.actor_identities(id),
    failure_code TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    published_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS media_objects_dispatch
    ON synth_meta.media_objects (state, generation, created_at);

CREATE TABLE IF NOT EXISTS synth_meta.action_intents (
    action_id TEXT PRIMARY KEY CHECK (action_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    idempotency_key TEXT NOT NULL UNIQUE,
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id) ON DELETE CASCADE,
    request_id TEXT NOT NULL,
    turn_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK (generation > 0),
    action_name TEXT NOT NULL,
    capability TEXT NOT NULL,
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    target_identity_id BIGINT REFERENCES synth.actor_identities(id),
    arguments JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(arguments) = 'object'),
    deadline_at TIMESTAMPTZ NOT NULL,
    state TEXT NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'dispatched', 'complete', 'cancelled', 'stale')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS action_intents_dispatch
    ON synth_meta.action_intents (session_id, generation, state, deadline_at);

CREATE TABLE IF NOT EXISTS synth_meta.action_terminal_results (
    action_id TEXT PRIMARY KEY REFERENCES synth_meta.action_intents(action_id) ON DELETE CASCADE,
    idempotency_key TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL CHECK (status IN (
        'succeeded', 'rejected', 'unavailable', 'timed_out', 'cancelled', 'failed',
        'unsupported_without_esp', 'unsupported_runtime'
    )),
    result JSONB NOT NULL,
    completed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Background work carries the same correlation tuple as its originating dialogue turn.
CREATE TABLE IF NOT EXISTS synth_meta.worker_jobs (
    id BIGSERIAL PRIMARY KEY,
    job_type TEXT NOT NULL CHECK (job_type IN ('memory', 'relationship', 'profile', 'diary', 'tts')),
    request_id TEXT NOT NULL,
    turn_id TEXT NOT NULL,
    utterance_id TEXT,
    generation BIGINT NOT NULL CHECK (generation > 0),
    actor_identity_id BIGINT REFERENCES synth.actor_identities(id),
    cache_key TEXT,
    payload JSONB NOT NULL,
    state TEXT NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'running', 'ready', 'failed', 'cancelled', 'stale')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deadline_at TIMESTAMPTZ,
    last_error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS worker_jobs_dispatch
    ON synth_meta.worker_jobs (job_type, state, available_at, generation);

-- Unattributable legacy jobs stay unowned; never infer their session from reused request IDs.
ALTER TABLE synth_meta.worker_jobs
    ADD COLUMN IF NOT EXISTS session_id TEXT REFERENCES synth_meta.runtime_sessions(session_id);
CREATE INDEX IF NOT EXISTS worker_jobs_session_generation
    ON synth_meta.worker_jobs (session_id, generation) WHERE state IN ('pending', 'running');

-- Preserve unassigned legacy rows. Attribution is written only by a claimed native request.
ALTER TABLE synth.eventlog
    ADD COLUMN IF NOT EXISTS synth_session_id TEXT,
    ADD COLUMN IF NOT EXISTS synth_generation BIGINT,
    ADD COLUMN IF NOT EXISTS synth_context_sequence BIGINT,
    ADD COLUMN IF NOT EXISTS synth_request_id TEXT;
CREATE INDEX IF NOT EXISTS eventlog_native_owner
    ON synth.eventlog (synth_session_id, synth_generation, rowid)
    WHERE synth_session_id IS NOT NULL;

-- Immutable insertion evidence. Later edits to eventlog are NOT silently revisions of this record.
CREATE TABLE IF NOT EXISTS synth_meta.history_events (
    id BIGSERIAL PRIMARY KEY,
    session_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK (generation > 0),
    context_sequence BIGINT NOT NULL CHECK (context_sequence > 0),
    request_id TEXT NOT NULL,
    playthrough_id TEXT NOT NULL,
    eventlog_rowid BIGINT NOT NULL,
    event_payload JSONB NOT NULL CHECK (jsonb_typeof(event_payload) = 'object'),
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id),
    FOREIGN KEY (session_id, generation, context_sequence)
        REFERENCES synth_meta.context_snapshots(session_id, generation, snapshot_sequence),
    UNIQUE (eventlog_rowid)
);
CREATE INDEX IF NOT EXISTS history_events_native_owner
    ON synth_meta.history_events (session_id, generation, id);

-- Preserve immutable request ownership separately from the scene that witnessed its speech.
ALTER TABLE synth_meta.history_events ADD COLUMN IF NOT EXISTS scene_context_sequence BIGINT
    CHECK (scene_context_sequence > 0);
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='synth_meta.history_events'::regclass
        AND conname='history_events_scene_context_fkey') THEN
        ALTER TABLE synth_meta.history_events ADD CONSTRAINT history_events_scene_context_fkey
            FOREIGN KEY (session_id,generation,scene_context_sequence)
            REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence);
    END IF;
END $$;

-- Raw actor source evidence is NOT dialogue history. Only proven player knowledge is projected separately.
CREATE TABLE IF NOT EXISTS synth_meta.actor_event_batches (
    session_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK (generation > 0),
    playthrough_id TEXT NOT NULL,
    batch_id TEXT NOT NULL CHECK (batch_id ~ '^actor:[1-9][0-9]{0,15}$'),
    context_sequence BIGINT NOT NULL CHECK (context_sequence > 0),
    request_id TEXT NOT NULL,
    player_payload JSONB NOT NULL CHECK (jsonb_typeof(player_payload)='object'),
    input_payload JSONB NOT NULL CHECK (jsonb_typeof(input_payload)='object'),
    capture_age_ms BIGINT NOT NULL CHECK (capture_age_ms BETWEEN 0 AND 9007199254740991),
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (session_id,generation,playthrough_id,batch_id),
    FOREIGN KEY (session_id,generation,context_sequence)
        REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
    FOREIGN KEY (session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id)
);
-- Journal deduplication must not scan every later dialogue/world row in a long session.
CREATE INDEX IF NOT EXISTS history_events_quest_observation_owner
    ON synth_meta.history_events (session_id, generation, playthrough_id, id DESC)
    WHERE event_payload->>'type'='quest_observation';

CREATE UNIQUE INDEX IF NOT EXISTS history_events_quest_batch_owner
    ON synth_meta.history_events (session_id, generation, playthrough_id, (((event_payload->>'party')::jsonb)->>'batch_id'))
    WHERE event_payload->>'type'='quest_events';

CREATE UNIQUE INDEX IF NOT EXISTS history_events_player_event_owner
    ON synth_meta.history_events (session_id, generation, playthrough_id, (((event_payload->>'party')::jsonb)->>'event_id'))
    WHERE event_payload->>'type'='player_event';

-- Administrative action issuance lookups must not scan unrelated dialogue/world history.
CREATE INDEX IF NOT EXISTS history_events_native_action_owner
    ON synth_meta.history_events (session_id, generation, playthrough_id, id)
    WHERE event_payload->>'type'='funccall';

-- NULL is an older context with no captured frontier; zero is a captured empty insertion history.
ALTER TABLE synth_meta.context_snapshots
    ADD COLUMN IF NOT EXISTS history_event_id BIGINT CHECK (history_event_id >= 0);

-- Frozen summarizer inputs. No legacy memory is assigned to a native actor/save by inference.
CREATE TABLE IF NOT EXISTS synth_meta.memory_batches (
    id BIGSERIAL PRIMARY KEY,
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    playthrough_id TEXT NOT NULL,
    session_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    source_hash CHAR(64) NOT NULL CHECK (source_hash ~ '^[0-9a-f]{64}$'),
    input_payload JSONB NOT NULL CHECK (jsonb_typeof(input_payload) = 'object'),
    packed_message TEXT NOT NULL CHECK (octet_length(packed_message) BETWEEN 1 AND 65536),
    first_gamets BIGINT NOT NULL CHECK (first_gamets > 0),
    last_gamets BIGINT NOT NULL CHECK (last_gamets >= first_gamets),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    FOREIGN KEY (session_id,generation,context_sequence)
        REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
    UNIQUE (actor_identity_id,source_hash)
);
CREATE TABLE IF NOT EXISTS synth_meta.memory_batch_sources (
    batch_id BIGINT NOT NULL REFERENCES synth_meta.memory_batches(id),
    history_event_id BIGINT NOT NULL REFERENCES synth_meta.history_events(id),
    position INTEGER NOT NULL CHECK (position BETWEEN 1 AND 150),
    PRIMARY KEY (batch_id,history_event_id),
    UNIQUE (batch_id,position)
);

ALTER TABLE synth_meta.worker_jobs
    ADD COLUMN IF NOT EXISTS memory_batch_id BIGINT REFERENCES synth_meta.memory_batches(id),
    ADD COLUMN IF NOT EXISTS lease_token TEXT,
    ADD COLUMN IF NOT EXISTS lease_until TIMESTAMPTZ;
CREATE UNIQUE INDEX IF NOT EXISTS worker_jobs_active_memory_batch
    ON synth_meta.worker_jobs (memory_batch_id)
    WHERE job_type='memory' AND memory_batch_id IS NOT NULL AND state IN ('pending','running','ready');

-- Completed records are append-only in the application; retries cannot replace an accepted result.
CREATE TABLE IF NOT EXISTS synth_meta.memory_summaries (
    id BIGSERIAL PRIMARY KEY,
    batch_id BIGINT NOT NULL REFERENCES synth_meta.memory_batches(id),
    job_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.worker_jobs(id),
    summary TEXT NOT NULL CHECK (octet_length(summary) BETWEEN 1 AND 65536),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS memory_summaries_batch ON synth_meta.memory_summaries(batch_id,id);

ALTER TABLE synth_meta.memory_summaries
    ADD COLUMN IF NOT EXISTS embedding public.vector(384),
    ADD COLUMN IF NOT EXISTS native_vec tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english'::regconfig,COALESCE(substring(summary from '#Tags:([\s\S]*)$'),'')),'A')
        || setweight(to_tsvector('english'::regconfig,summary),'B')
    ) STORED;
CREATE INDEX IF NOT EXISTS memory_summaries_text ON synth_meta.memory_summaries USING gin(native_vec);

-- Durable per-session dispatch cooldown; no scheduler/provider work is added to response completion.
ALTER TABLE synth_meta.runtime_sessions
    ADD COLUMN IF NOT EXISTS memory_dispatch_after TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS runtime_sessions_memory_dispatch
    ON synth_meta.runtime_sessions (memory_dispatch_after,session_id) WHERE state='active';

-- A frozen input may cite one earlier accepted summary. Keep the edge separate for explicit retention/cleanup.
CREATE TABLE IF NOT EXISTS synth_meta.memory_batch_summary_sources (
    batch_id BIGINT PRIMARY KEY REFERENCES synth_meta.memory_batches(id),
    summary_id BIGINT NOT NULL REFERENCES synth_meta.memory_summaries(id)
);
CREATE INDEX IF NOT EXISTS memory_batch_summary_sources_parent
    ON synth_meta.memory_batch_summary_sources (summary_id,batch_id);

-- Reversible management overlays never rewrite accepted summaries or their frozen source edges.
CREATE TABLE IF NOT EXISTS synth_meta.memory_batch_controls (
    batch_id BIGINT PRIMARY KEY REFERENCES synth_meta.memory_batches(id),
    archived BOOLEAN NOT NULL,
    revision BIGINT NOT NULL CHECK (revision > 0),
    changed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE IF NOT EXISTS synth_meta.memory_batch_control_events (
    batch_id BIGINT NOT NULL REFERENCES synth_meta.memory_batches(id),
    revision BIGINT NOT NULL CHECK (revision > 0),
    archived BOOLEAN NOT NULL,
    session_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    changed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (batch_id,revision)
);

-- Summary corrections are immutable versions. Revision zero always names the original generated text.
CREATE TABLE IF NOT EXISTS synth_meta.memory_summary_revisions (
    summary_id BIGINT NOT NULL REFERENCES synth_meta.memory_summaries(id),
    revision BIGINT NOT NULL CHECK (revision>0),
    summary TEXT NOT NULL CHECK (octet_length(summary) BETWEEN 1 AND 65536),
    native_vec tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english'::regconfig,COALESCE(substring(summary from '#Tags:([\s\S]*)$'),'')),'A')
        || setweight(to_tsvector('english'::regconfig,summary),'B')) STORED,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (summary_id,revision)
);
ALTER TABLE synth_meta.memory_batch_summary_sources
    ADD COLUMN IF NOT EXISTS summary_revision BIGINT NOT NULL DEFAULT 0 CHECK (summary_revision>=0);
ALTER TABLE synth_meta.memory_batch_control_events
    ADD COLUMN IF NOT EXISTS action TEXT CHECK (action IN ('archive','restore','edit'));

-- Replacement inputs remain separate immutable batches sharing the original archive/control identity.
CREATE TABLE IF NOT EXISTS synth_meta.memory_batch_rebuilds (
    batch_id BIGINT PRIMARY KEY REFERENCES synth_meta.memory_batches(id),
    root_batch_id BIGINT NOT NULL REFERENCES synth_meta.memory_batches(id),
    CHECK (batch_id>root_batch_id)
);
CREATE INDEX IF NOT EXISTS memory_batch_rebuilds_root ON synth_meta.memory_batch_rebuilds(root_batch_id,batch_id);

-- An explicit approval can retire one manual correction from future recall, never from evidence history.
ALTER TABLE synth_meta.memory_batch_controls ADD COLUMN IF NOT EXISTS regenerate_batch_id BIGINT
    REFERENCES synth_meta.memory_batches(id);
ALTER TABLE synth_meta.memory_batch_control_events ADD COLUMN IF NOT EXISTS subject_batch_id BIGINT
    REFERENCES synth_meta.memory_batches(id);
ALTER TABLE synth_meta.memory_batch_control_events DROP CONSTRAINT IF EXISTS memory_batch_control_events_action_check;
ALTER TABLE synth_meta.memory_batch_control_events ADD CONSTRAINT memory_batch_control_events_action_check
    CHECK (action IN ('archive','restore','edit','regenerate'));

-- Native relationship state has its own publication frontier; late results never backdate into a save.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_revisions (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    subject_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    prior_history_event_id BIGINT REFERENCES synth_meta.relationship_revisions(history_event_id),
    affinity INTEGER NOT NULL CHECK (affinity BETWEEN -100 AND 100),
    relationship_type TEXT NOT NULL CHECK (relationship_type ~ '^[a-z][a-z0-9_-]{0,49}$'),
    input_payload JSONB NOT NULL CHECK (jsonb_typeof(input_payload)='object'),
    input_hash CHAR(64) NOT NULL CHECK (input_hash ~ '^[0-9a-f]{64}$'),
    CHECK (actor_identity_id<>subject_identity_id),
    CHECK (prior_history_event_id<history_event_id)
);
CREATE INDEX IF NOT EXISTS relationship_revisions_pair
    ON synth_meta.relationship_revisions(actor_identity_id,subject_identity_id,history_event_id);
CREATE TABLE IF NOT EXISTS synth_meta.relationship_revision_sources (
    relationship_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_revisions(history_event_id),
    source_history_event_id BIGINT NOT NULL REFERENCES synth_meta.history_events(id),
    PRIMARY KEY (relationship_history_event_id,source_history_event_id),
    CHECK (source_history_event_id<relationship_history_event_id)
);

-- Processed model speech before presentation cleanup, attached only to a completed protocol response.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_response_inputs (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    response_sha256 CHAR(64) NOT NULL CHECK (response_sha256 ~ '^[0-9a-f]{64}$'),
    response_payload JSONB NOT NULL CHECK (jsonb_typeof(response_payload)='object'),
    UNIQUE(session_id,request_id),
    FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id)
);

-- Bind processed player/STT input to its response without making post-snapshot events generally visible.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_response_sources (
    response_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_response_inputs(history_event_id),
    source_history_event_id BIGINT NOT NULL REFERENCES synth_meta.history_events(id),
    PRIMARY KEY(response_history_event_id,source_history_event_id),
    CHECK(source_history_event_id<response_history_event_id)
);

-- Dedicated model inputs are frozen separately from response evidence and never read from global profiles.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_evaluations (
    id BIGSERIAL PRIMARY KEY,
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    response_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_response_inputs(history_event_id),
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object'),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
    UNIQUE(session_id,request_id,response_history_event_id)
);
CREATE TABLE IF NOT EXISTS synth_meta.relationship_evaluation_sources (
    evaluation_id BIGINT NOT NULL REFERENCES synth_meta.relationship_evaluations(id),
    history_event_id BIGINT NOT NULL REFERENCES synth_meta.history_events(id),
    PRIMARY KEY(evaluation_id,history_event_id)
);
ALTER TABLE synth_meta.worker_jobs ADD COLUMN IF NOT EXISTS relationship_evaluation_id BIGINT REFERENCES synth_meta.relationship_evaluations(id);
CREATE UNIQUE INDEX IF NOT EXISTS worker_jobs_active_relationship_evaluation ON synth_meta.worker_jobs(relationship_evaluation_id)
    WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL AND state IN ('pending','running','ready');

-- A result (including no change) has its own save-visible publication boundary and all prior-state dependencies.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_results (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    evaluation_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.relationship_evaluations(id),
    job_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.worker_jobs(id),
    result_payload JSONB NOT NULL CHECK(jsonb_typeof(result_payload)='object')
);
CREATE TABLE IF NOT EXISTS synth_meta.relationship_result_priors (
    result_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_results(history_event_id),
    prior_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_revisions(history_event_id),
    PRIMARY KEY(result_history_event_id,prior_history_event_id),
    CHECK(prior_history_event_id<result_history_event_id)
);
ALTER TABLE synth_meta.relationship_revisions ADD COLUMN IF NOT EXISTS result_history_event_id BIGINT REFERENCES synth_meta.relationship_results(history_event_id);
ALTER TABLE synth_meta.runtime_sessions ADD COLUMN IF NOT EXISTS relationship_dispatch_after TIMESTAMPTZ;
ALTER TABLE synth_meta.relationship_revisions ADD COLUMN IF NOT EXISTS details JSONB NOT NULL DEFAULT '{}'::jsonb CHECK(jsonb_typeof(details)='object');

-- Current actor/playthrough policy is separate from save-visible fictional relationship history.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_actor_controls (
    actor_identity_id BIGINT PRIMARY KEY REFERENCES synth.actor_identities(id),
    locked BOOLEAN NOT NULL,
    revision BIGINT NOT NULL CHECK(revision>0),
    barrier_event_id BIGINT NOT NULL CHECK(barrier_event_id>0),
    changed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE IF NOT EXISTS synth_meta.relationship_actor_control_events (
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    revision BIGINT NOT NULL CHECK(revision>0),
    locked BOOLEAN NOT NULL,
    barrier_event_id BIGINT NOT NULL CHECK(barrier_event_id>0),
    session_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    changed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(actor_identity_id,revision),
    FOREIGN KEY(session_id,generation,context_sequence) REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence)
);

-- One immutable two-perspective input per accepted response/listener, not a mutable legacy NPC/name pair.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_interaction_evaluations (
    id BIGSERIAL PRIMARY KEY,
    speaker_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    listener_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    response_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_response_inputs(history_event_id),
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    generation BIGINT NOT NULL,
    context_sequence BIGINT NOT NULL,
    input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object'),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    CHECK(speaker_identity_id<>listener_identity_id),
    FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
    FOREIGN KEY(session_id,generation,context_sequence) REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
    UNIQUE(session_id,request_id,response_history_event_id,listener_identity_id)
);
CREATE TABLE IF NOT EXISTS synth_meta.relationship_interaction_priors (
    interaction_id BIGINT NOT NULL REFERENCES synth_meta.relationship_interaction_evaluations(id),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    prior_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_revisions(history_event_id),
    PRIMARY KEY(interaction_id,actor_identity_id)
);

-- Paired jobs reuse lifecycle cancellation without qualifying for single-speaker workers.
ALTER TABLE synth_meta.worker_jobs ADD COLUMN IF NOT EXISTS relationship_interaction_id BIGINT
    REFERENCES synth_meta.relationship_interaction_evaluations(id)
    CHECK (relationship_interaction_id IS NULL OR (job_type='relationship' AND relationship_evaluation_id IS NULL
        AND memory_batch_id IS NULL AND session_id IS NOT NULL AND actor_identity_id IS NOT NULL));
CREATE UNIQUE INDEX IF NOT EXISTS worker_jobs_relationship_interaction
    ON synth_meta.worker_jobs(relationship_interaction_id) WHERE relationship_interaction_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS relationship_interaction_listener
    ON synth_meta.relationship_interaction_evaluations(listener_identity_id,id);

-- One accepted paired result (including no change), with both independent prior dependencies.
CREATE TABLE IF NOT EXISTS synth_meta.relationship_interaction_results (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    interaction_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.relationship_interaction_evaluations(id),
    job_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.worker_jobs(id),
    result_payload JSONB NOT NULL CHECK(jsonb_typeof(result_payload)='object')
);
CREATE TABLE IF NOT EXISTS synth_meta.relationship_interaction_result_priors (
    result_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_interaction_results(history_event_id),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    prior_history_event_id BIGINT NOT NULL REFERENCES synth_meta.relationship_revisions(history_event_id),
    PRIMARY KEY(result_history_event_id,actor_identity_id),
    CHECK(prior_history_event_id<result_history_event_id)
);
ALTER TABLE synth_meta.relationship_revisions ADD COLUMN IF NOT EXISTS interaction_result_history_event_id BIGINT
    REFERENCES synth_meta.relationship_interaction_results(history_event_id)
    CHECK (interaction_result_history_event_id IS NULL OR result_history_event_id IS NULL);
ALTER TABLE synth_meta.runtime_sessions ADD COLUMN IF NOT EXISTS interaction_dispatch_after TIMESTAMPTZ;
-- Audit the explicitly confirmed source ID without preventing later profile deletion; native actor ownership is retained above.
ALTER TABLE synth_meta.relationship_actor_control_events ADD COLUMN IF NOT EXISTS legacy_profile_id BIGINT CHECK (legacy_profile_id>0);

-- A confirmed administrator correction is not an impersonated game request.
ALTER TABLE synth_meta.history_events ALTER COLUMN request_id DROP NOT NULL;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='synth_meta.history_events'::regclass AND conname='history_request_or_manual') THEN
        ALTER TABLE synth_meta.history_events ADD CONSTRAINT history_request_or_manual CHECK (
            request_id IS NOT NULL OR (event_payload->>'type' IS NOT DISTINCT FROM 'relationship_manual'
                AND event_payload->>'delivery_state' IS NOT DISTINCT FROM 'derived'));
    END IF;
END $$;

-- Reset revisions retain pair/history identity without inventing observed affinity or type.
ALTER TABLE synth_meta.relationship_revisions ALTER COLUMN affinity DROP NOT NULL;
ALTER TABLE synth_meta.relationship_revisions ALTER COLUMN relationship_type DROP NOT NULL;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='synth_meta.relationship_revisions'::regclass AND conname='relationship_known_or_reset') THEN
        ALTER TABLE synth_meta.relationship_revisions ADD CONSTRAINT relationship_known_or_reset CHECK (
            (affinity IS NOT NULL AND relationship_type IS NOT NULL) OR
            (affinity IS NULL AND relationship_type IS NULL AND details='{}'::jsonb
                AND prior_history_event_id IS NULL AND result_history_event_id IS NULL AND interaction_result_history_event_id IS NULL
                AND input_payload->>'schema' IS NOT DISTINCT FROM 'synth.relationship.manual.v2'
                AND input_payload->'state' IS NOT DISTINCT FROM 'null'::jsonb));
    END IF;
END $$;

-- Accepted image descriptions are ordered native evidence, not reconstructed legacy gallery data.
CREATE TABLE IF NOT EXISTS synth_meta.visual_results (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    capture_id TEXT NOT NULL UNIQUE CHECK (capture_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    session_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK (generation > 0),
    context_sequence BIGINT NOT NULL CHECK (context_sequence > 0),
    request_id TEXT NOT NULL,
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    result_payload JSONB NOT NULL CHECK (jsonb_typeof(result_payload)='object'
        AND result_payload->>'schema' IS NOT DISTINCT FROM 'synth.visual.result.v1'),
    result_sha256 CHAR(64) NOT NULL CHECK (result_sha256 ~ '^[0-9a-f]{64}$'),
    FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
    FOREIGN KEY(session_id,generation,context_sequence)
        REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
    UNIQUE(session_id,request_id)
);
CREATE INDEX IF NOT EXISTS visual_results_actor_history
    ON synth_meta.visual_results(actor_identity_id,history_event_id DESC);

-- Confirmed visual edits are administrative history, never forged game requests or global observation rewrites.
ALTER TABLE synth_meta.history_events DROP CONSTRAINT IF EXISTS history_request_or_manual;
ALTER TABLE synth_meta.history_events ADD CONSTRAINT history_request_or_manual CHECK (
    request_id IS NOT NULL OR (COALESCE(event_payload->>'type','') IN ('relationship_manual','visual_manual','diary_control','middleterm_control','middleterm_regeneration')
        AND event_payload->>'delivery_state' IS NOT DISTINCT FROM 'derived'));
CREATE TABLE IF NOT EXISTS synth_meta.visual_description_revisions (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    capture_id TEXT NOT NULL REFERENCES synth_meta.visual_results(capture_id),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    description TEXT CHECK (description IS NULL OR (octet_length(description)<=12000 AND btrim(description)<>'')),
    input_payload JSONB NOT NULL CHECK (jsonb_typeof(input_payload)='object'
        AND input_payload->>'schema' IS NOT DISTINCT FROM 'synth.visual.manual.v1'),
    input_hash CHAR(64) NOT NULL CHECK (input_hash ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS visual_description_capture_history
    ON synth_meta.visual_description_revisions(capture_id,actor_identity_id,history_event_id DESC);

-- Generated profile state is saved evidence, not an overwrite of administrator-owned base profiles.
CREATE TABLE IF NOT EXISTS synth_meta.profile_revisions (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK(generation>0),
    context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    profile_role TEXT NOT NULL CHECK(profile_role IN ('npc','narrator')),
    prior_history_event_id BIGINT REFERENCES synth_meta.profile_revisions(history_event_id),
    input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object'
        AND input_payload->>'schema' IS NOT DISTINCT FROM 'synth.profile.input.v1'),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
    result_fields JSONB NOT NULL CHECK(jsonb_typeof(result_fields)='object'),
    result_sha256 CHAR(64) NOT NULL CHECK(result_sha256 ~ '^[0-9a-f]{64}$'),
    FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
    FOREIGN KEY(session_id,generation,context_sequence) REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
    UNIQUE(session_id,request_id,actor_identity_id,profile_role),
    CHECK(prior_history_event_id IS NULL OR prior_history_event_id<history_event_id)
);
CREATE INDEX IF NOT EXISTS profile_revisions_actor_history ON synth_meta.profile_revisions(actor_identity_id,profile_role,history_event_id DESC);

-- Native profile attempts retain complete provider input and independently accepted field checkpoints.
CREATE UNIQUE INDEX IF NOT EXISTS worker_jobs_native_profile_owner
    ON synth_meta.worker_jobs(session_id,request_id,actor_identity_id,(payload->>'profile_role'))
    WHERE job_type='profile' AND payload->>'schema'='synth.profile.job.v1';
CREATE TABLE IF NOT EXISTS synth_meta.profile_job_inputs (
    job_id BIGINT PRIMARY KEY REFERENCES synth_meta.worker_jobs(id),
    input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object'
        AND input_payload->>'schema' IS NOT DISTINCT FROM 'synth.profile.input.v1'),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$')
);
CREATE TABLE IF NOT EXISTS synth_meta.profile_job_fields (
    job_id BIGINT NOT NULL REFERENCES synth_meta.profile_job_inputs(job_id),
    field TEXT NOT NULL CHECK(field IN ('personality','occupation','skills','speechstyle','goals')),
    result_text TEXT NOT NULL CHECK(octet_length(result_text) BETWEEN 1 AND 16384),
    result_sha256 CHAR(64) NOT NULL CHECK(result_sha256 ~ '^[0-9a-f]{64}$'),
    PRIMARY KEY(job_id,field)
);

-- Diary reflections are private, save-visible derived evidence, never mutable name-keyed history.
CREATE TABLE IF NOT EXISTS synth_meta.diary_inputs (
    id BIGSERIAL PRIMARY KEY,
    session_id TEXT NOT NULL,
    request_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK(generation>0),
    context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    diary_role TEXT NOT NULL CHECK(diary_role IN ('npc','player','narrator')),
    prior_history_event_id BIGINT REFERENCES synth_meta.history_events(id),
    profile_revision_id BIGINT REFERENCES synth_meta.profile_revisions(history_event_id),
    input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object'
        AND input_payload->>'schema' IS NOT DISTINCT FROM 'synth.diary.input.v1'),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
    FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
    FOREIGN KEY(session_id,generation,context_sequence) REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
    UNIQUE(session_id,request_id,actor_identity_id,diary_role)
);
CREATE TABLE IF NOT EXISTS synth_meta.diary_input_sources (
    input_id BIGINT NOT NULL REFERENCES synth_meta.diary_inputs(id),
    history_event_id BIGINT NOT NULL REFERENCES synth_meta.history_events(id),
    source_sha256 CHAR(64) NOT NULL CHECK(source_sha256 ~ '^[0-9a-f]{64}$'),
    PRIMARY KEY(input_id,history_event_id)
);
CREATE TABLE IF NOT EXISTS synth_meta.diary_results (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    input_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.diary_inputs(id),
    result_payload JSONB NOT NULL CHECK(jsonb_typeof(result_payload)='object'),
    result_sha256 CHAR(64) NOT NULL CHECK(result_sha256 ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS diary_inputs_actor_role ON synth_meta.diary_inputs(actor_identity_id,diary_role,id DESC);

-- Accepted provider text survives a worker crash; publication and ready state still commit together.
ALTER TABLE synth_meta.worker_jobs ADD COLUMN IF NOT EXISTS diary_input_id BIGINT REFERENCES synth_meta.diary_inputs(id);
CREATE UNIQUE INDEX IF NOT EXISTS worker_jobs_diary_input ON synth_meta.worker_jobs(diary_input_id) WHERE diary_input_id IS NOT NULL;
CREATE TABLE IF NOT EXISTS synth_meta.diary_job_results (
    job_id BIGINT PRIMARY KEY REFERENCES synth_meta.worker_jobs(id),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
    result_payload JSONB NOT NULL CHECK(jsonb_typeof(result_payload)='object'),
    result_sha256 CHAR(64) NOT NULL CHECK(result_sha256 ~ '^[0-9a-f]{64}$')
);

-- Optional search vectors remain attached to the immutable accepted journal/checkpoint, never legacy names.
ALTER TABLE synth_meta.diary_job_results ADD COLUMN IF NOT EXISTS embedding public.vector(384);
ALTER TABLE synth_meta.diary_job_results ADD COLUMN IF NOT EXISTS embedding_sha256 CHAR(64);
ALTER TABLE synth_meta.diary_job_results ADD COLUMN IF NOT EXISTS embedding_attempted BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE synth_meta.diary_results ADD COLUMN IF NOT EXISTS embedding public.vector(384);
ALTER TABLE synth_meta.diary_results ADD COLUMN IF NOT EXISTS embedding_sha256 CHAR(64);
-- NULL preserves unknown legacy provenance; never infer a text binding for existing vectors.
ALTER TABLE synth_meta.diary_job_results ADD COLUMN IF NOT EXISTS embedding_text_sha256 CHAR(64)
    CHECK(embedding_text_sha256 ~ '^[0-9a-f]{64}$');
ALTER TABLE synth_meta.diary_results ADD COLUMN IF NOT EXISTS embedding_text_sha256 CHAR(64)
    CHECK(embedding_text_sha256 ~ '^[0-9a-f]{64}$');

-- Reversible administrative suppression follows saved ancestry; accepted journal text is never deleted.
CREATE TABLE IF NOT EXISTS synth_meta.diary_control_revisions (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    diary_id BIGINT NOT NULL REFERENCES synth_meta.diary_results(history_event_id),
    prior_revision_id BIGINT REFERENCES synth_meta.diary_control_revisions(history_event_id),
    control_payload JSONB NOT NULL CHECK(jsonb_typeof(control_payload)='object'
        AND COALESCE(control_payload->>'schema','') IN ('synth.diary.control.v1','synth.diary.control.v2')),
    control_sha256 CHAR(64) NOT NULL CHECK(control_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK(diary_id<history_event_id),
    CHECK(prior_revision_id IS NULL OR prior_revision_id<history_event_id)
);
CREATE INDEX IF NOT EXISTS diary_control_revisions_owner ON synth_meta.diary_control_revisions(diary_id,history_event_id DESC);

-- Native narrative memory is a separate saved journal, never a mutable base-profile field.
CREATE TABLE IF NOT EXISTS synth_meta.middleterm_inputs (
    id BIGSERIAL PRIMARY KEY,
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id),
    request_id TEXT NOT NULL,
    generation BIGINT NOT NULL CHECK(generation>0),
    context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    playthrough_id TEXT NOT NULL,
    prior_history_event_id BIGINT REFERENCES synth_meta.history_events(id),
    input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object' AND octet_length(input_payload::text)<=1048576),
    input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
    UNIQUE(session_id,request_id,generation,actor_identity_id,input_sha256)
);
CREATE TABLE IF NOT EXISTS synth_meta.middleterm_sources (
    input_id BIGINT NOT NULL REFERENCES synth_meta.middleterm_inputs(id),
    position INTEGER NOT NULL CHECK(position BETWEEN 0 AND 99),
    summary_id BIGINT NOT NULL REFERENCES synth_meta.memory_summaries(id),
    summary_revision BIGINT NOT NULL CHECK(summary_revision>=0),
    source_sha256 CHAR(64) NOT NULL CHECK(source_sha256 ~ '^[0-9a-f]{64}$'),
    PRIMARY KEY(input_id,position), UNIQUE(input_id,summary_id)
);
CREATE TABLE IF NOT EXISTS synth_meta.middleterm_results (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    input_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.middleterm_inputs(id),
    narrative TEXT NOT NULL CHECK(octet_length(narrative) BETWEEN 1 AND 65536),
    result_sha256 CHAR(64) NOT NULL CHECK(result_sha256 ~ '^[0-9a-f]{64}$')
);
CREATE INDEX IF NOT EXISTS middleterm_inputs_actor ON synth_meta.middleterm_inputs(actor_identity_id,id DESC);
ALTER TABLE synth_meta.worker_jobs DROP CONSTRAINT IF EXISTS worker_jobs_job_type_check;
ALTER TABLE synth_meta.worker_jobs ADD CONSTRAINT worker_jobs_job_type_check
    CHECK(job_type IN ('memory','relationship','profile','diary','tts','middleterm'));
ALTER TABLE synth_meta.worker_jobs ADD COLUMN IF NOT EXISTS middleterm_input_id BIGINT REFERENCES synth_meta.middleterm_inputs(id);
CREATE UNIQUE INDEX IF NOT EXISTS worker_jobs_middleterm_input ON synth_meta.worker_jobs(middleterm_input_id)
    WHERE middleterm_input_id IS NOT NULL;
ALTER TABLE synth_meta.runtime_sessions ADD COLUMN IF NOT EXISTS middleterm_dispatch_after TIMESTAMPTZ;

-- Save-owned narrative corrections never overwrite accepted results or their source evidence.
CREATE TABLE IF NOT EXISTS synth_meta.middleterm_control_revisions (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    narrative_id BIGINT NOT NULL REFERENCES synth_meta.middleterm_results(history_event_id),
    prior_revision_id BIGINT REFERENCES synth_meta.middleterm_control_revisions(history_event_id),
    control_payload JSONB NOT NULL CHECK(jsonb_typeof(control_payload)='object'
        AND COALESCE(control_payload->>'schema','') IN ('synth.middleterm.control.v1','synth.middleterm.control.v2')),
    control_sha256 CHAR(64) NOT NULL CHECK(control_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK(narrative_id<history_event_id),
    CHECK(prior_revision_id IS NULL OR prior_revision_id<history_event_id)
);
CREATE INDEX IF NOT EXISTS middleterm_control_revisions_owner
    ON synth_meta.middleterm_control_revisions(narrative_id,history_event_id DESC);
-- An explicit regeneration approves one immutable saved plan; it does not replace text or grant a worker owner.
CREATE TABLE IF NOT EXISTS synth_meta.middleterm_regeneration_approvals (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id),
    generation BIGINT NOT NULL CHECK(generation>0),
    context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
    actor_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
    playthrough_id TEXT NOT NULL,
    narrative_id BIGINT NOT NULL REFERENCES synth_meta.middleterm_results(history_event_id),
    approval_token CHAR(32) NOT NULL CHECK(approval_token ~ '^[0-9a-f]{32}$'),
    approval_payload JSONB NOT NULL CHECK(jsonb_typeof(approval_payload)='object'
        AND COALESCE(approval_payload->>'schema','')='synth.middleterm.regeneration.v1'
        AND octet_length(approval_payload::text)<=1048576),
    approval_sha256 CHAR(64) NOT NULL CHECK(approval_sha256 ~ '^[0-9a-f]{64}$'),
    CHECK(narrative_id<history_event_id),
    UNIQUE(session_id,generation,actor_identity_id,approval_token)
);
CREATE INDEX IF NOT EXISTS middleterm_regeneration_approvals_target
    ON synth_meta.middleterm_regeneration_approvals(narrative_id,history_event_id DESC);
-- Each replacement keeps its original approval and accepted result; old save scopes retain the original narrative.
ALTER TABLE synth_meta.middleterm_regeneration_approvals ADD COLUMN IF NOT EXISTS last_considered_at TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS middleterm_regeneration_discovery ON synth_meta.middleterm_regeneration_approvals
    (session_id,generation,actor_identity_id,last_considered_at NULLS FIRST,history_event_id);
ALTER TABLE synth_meta.middleterm_inputs ADD COLUMN IF NOT EXISTS regeneration_approval_id BIGINT
    REFERENCES synth_meta.middleterm_regeneration_approvals(history_event_id);
CREATE INDEX IF NOT EXISTS middleterm_inputs_regeneration ON synth_meta.middleterm_inputs(regeneration_approval_id,id)
    WHERE regeneration_approval_id IS NOT NULL;
CREATE TABLE IF NOT EXISTS synth_meta.middleterm_replacements (
    history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.middleterm_results(history_event_id),
    approval_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.middleterm_regeneration_approvals(history_event_id),
    target_history_event_id BIGINT NOT NULL REFERENCES synth_meta.middleterm_results(history_event_id),
    session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id),
    generation BIGINT NOT NULL CHECK(generation>0),
    CHECK(history_event_id>target_history_event_id AND history_event_id>approval_id),
    UNIQUE(target_history_event_id,session_id,generation)
);
ALTER TABLE synth_meta.middleterm_control_revisions DROP CONSTRAINT IF EXISTS middleterm_control_revisions_control_payload_check;
ALTER TABLE synth_meta.middleterm_control_revisions ADD CONSTRAINT middleterm_control_revisions_control_payload_check
    CHECK(jsonb_typeof(control_payload)='object'
        AND COALESCE(control_payload->>'schema','') IN ('synth.middleterm.control.v1','synth.middleterm.control.v2'));
-- Text corrections are immutable control receipts; retain compatibility with existing archive/restore records.
ALTER TABLE synth_meta.diary_control_revisions DROP CONSTRAINT IF EXISTS diary_control_revisions_control_payload_check;
ALTER TABLE synth_meta.diary_control_revisions ADD CONSTRAINT diary_control_revisions_control_payload_check
    CHECK(jsonb_typeof(control_payload)='object'
        AND COALESCE(control_payload->>'schema','') IN ('synth.diary.control.v1','synth.diary.control.v2'));
