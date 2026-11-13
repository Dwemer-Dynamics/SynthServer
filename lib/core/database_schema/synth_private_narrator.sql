-- Private narrator: named definitions with immutable revisions, and per-save private state that branches with the
-- existing context snapshots. Nothing here is read by eventlog, history_events, diary or memory sources.
-- Source migration only: apply through debug/db_updates.php, never at request time.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_definitions (
 definition_id TEXT PRIMARY KEY CHECK(definition_id ~ '^nd-[0-9a-f]{32}$'),
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 retired_at TIMESTAMPTZ,
 edit_generation BIGINT NOT NULL DEFAULT 0
);
-- An edit is a new row; a revision is never updated. prior_revision_id makes each definition one linear chain.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_definition_revisions (
 revision_id BIGSERIAL PRIMARY KEY,
 definition_id TEXT NOT NULL REFERENCES synth_meta.narrator_definitions(definition_id),
 prior_revision_id BIGINT REFERENCES synth_meta.narrator_definition_revisions(revision_id),
 name TEXT NOT NULL CHECK(char_length(btrim(name)) BETWEEN 1 AND 64),
 personality TEXT NOT NULL CHECK(char_length(personality)<=4000),
 background TEXT NOT NULL CHECK(char_length(background)<=4000),
 style TEXT NOT NULL CHECK(char_length(style)<=2000),
 voice TEXT NOT NULL CHECK(char_length(voice)<=128),
 profile_id BIGINT CHECK(profile_id IS NULL OR profile_id>0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 CHECK(prior_revision_id IS NULL OR prior_revision_id<revision_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS narrator_definition_revisions_chain
 ON synth_meta.narrator_definition_revisions(definition_id,COALESCE(prior_revision_id,0));
-- Private transcript entries form parent-linked branches; a save names only its head through a state.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_transcript_entries (
 entry_id BIGSERIAL PRIMARY KEY,
 parent_entry_id BIGINT REFERENCES synth_meta.narrator_transcript_entries(entry_id),
 parent_persona_entry_id BIGINT REFERENCES synth_meta.narrator_transcript_entries(entry_id),
 playthrough_id TEXT NOT NULL CHECK(playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 role TEXT NOT NULL CHECK(role IN ('player','narrator')),
 kind TEXT NOT NULL CHECK(kind IN ('typed','voice','scene','reply')),
 definition_id TEXT NOT NULL REFERENCES synth_meta.narrator_definitions(definition_id),
 revision_id BIGINT NOT NULL REFERENCES synth_meta.narrator_definition_revisions(revision_id),
 speaker_name TEXT NOT NULL CHECK(char_length(speaker_name) BETWEEN 1 AND 64),
 text TEXT NOT NULL CHECK(char_length(text) BETWEEN 1 AND 4096),
 session_id TEXT NOT NULL,
 generation BIGINT NOT NULL CHECK(generation>0),
 request_id TEXT NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(session_id,request_id,role),
 CHECK(parent_entry_id IS NULL OR parent_entry_id<entry_id),
 CHECK(parent_persona_entry_id IS NULL OR parent_persona_entry_id<entry_id)
);
-- One immutable applied state: applied definition revisions, selection and transcript head.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_states (
 state_id BIGSERIAL PRIMARY KEY,
 parent_state_id BIGINT REFERENCES synth_meta.narrator_states(state_id),
 playthrough_id TEXT NOT NULL CHECK(playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 applied_revisions JSONB NOT NULL DEFAULT '{}'::jsonb CHECK(jsonb_typeof(applied_revisions)='object'),
 persona_heads JSONB NOT NULL DEFAULT '{}'::jsonb CHECK(jsonb_typeof(persona_heads)='object'),
 selected_definition_id TEXT REFERENCES synth_meta.narrator_definitions(definition_id),
 transcript_head_entry_id BIGINT REFERENCES synth_meta.narrator_transcript_entries(entry_id),
 cause TEXT NOT NULL CHECK(cause IN ('select','input','reply')),
 session_id TEXT NOT NULL,
 generation BIGINT NOT NULL CHECK(generation>0),
 request_id TEXT NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 CHECK(parent_state_id IS NULL OR parent_state_id<state_id),
 CHECK(selected_definition_id IS NULL OR applied_revisions ? selected_definition_id)
);
-- The live lane pointer of one runtime session generation. It is not save authority: only the immutable
-- capture below, keyed by an acknowledged context snapshot, is ever restored.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_lane_heads (
 session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id) ON DELETE CASCADE,
 generation BIGINT NOT NULL CHECK(generation>0),
 playthrough_id TEXT NOT NULL,
 state_id BIGINT REFERENCES synth_meta.narrator_states(state_id),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 PRIMARY KEY(session_id,generation)
);
-- Immutable private snapshot of each context publication. It holds opaque IDs only.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_context_states (
 session_id TEXT NOT NULL,
 generation BIGINT NOT NULL,
 snapshot_sequence BIGINT NOT NULL,
 playthrough_id TEXT NOT NULL,
 state_id BIGINT REFERENCES synth_meta.narrator_states(state_id),
 PRIMARY KEY(session_id,generation,snapshot_sequence),
 FOREIGN KEY(session_id,generation,snapshot_sequence)
  REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence) ON DELETE CASCADE
);
-- One reply per narrator turn. Only an exact completed-audio receipt for a still-current lane moves it into the
-- transcript; cancel, edit, switch, load and session replacement retire it.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_pending_outputs (
 session_id TEXT NOT NULL,
 request_id TEXT NOT NULL,
 generation BIGINT NOT NULL CHECK(generation>0),
 turn_id TEXT NOT NULL,
 context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
 playthrough_id TEXT NOT NULL,
 line_id TEXT NOT NULL,
 definition_id TEXT NOT NULL REFERENCES synth_meta.narrator_definitions(definition_id),
 revision_id BIGINT NOT NULL REFERENCES synth_meta.narrator_definition_revisions(revision_id),
 parent_state_id BIGINT NOT NULL REFERENCES synth_meta.narrator_states(state_id),
 definition_edit_generation BIGINT NOT NULL DEFAULT 0,
 input_entry_id BIGINT NOT NULL REFERENCES synth_meta.narrator_transcript_entries(entry_id),
 text TEXT CHECK(text IS NULL OR char_length(text) BETWEEN 1 AND 1000),
 state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','generated','spoken','interrupted','failed','cancelled')),
 receipt_request_id TEXT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 PRIMARY KEY(session_id,request_id),
 UNIQUE(session_id,line_id),
 CHECK(state NOT IN ('generated','spoken') OR text IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS narrator_pending_outputs_open
 ON synth_meta.narrator_pending_outputs(playthrough_id,state) WHERE state IN ('pending','generated');
-- Issued page cursors are bound to one immutable state, so clients cannot jump into another transcript branch.
CREATE TABLE IF NOT EXISTS synth_meta.narrator_transcript_cursors (
 cursor_id TEXT PRIMARY KEY CHECK(cursor_id ~ '^[0-9a-f]{64}$'),
 state_id BIGINT NOT NULL REFERENCES synth_meta.narrator_states(state_id),
 next_entry_id BIGINT NOT NULL REFERENCES synth_meta.narrator_transcript_entries(entry_id),
 UNIQUE(state_id,next_entry_id)
);
