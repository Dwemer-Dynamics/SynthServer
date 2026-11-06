-- Saved social instructions are immutable inputs and append-only, save-scoped progress.
-- Source migration only: apply through the reviewed migration workflow, never at request time.
CREATE TABLE IF NOT EXISTS synth_meta.social_task_inputs (
 task_id TEXT PRIMARY KEY CHECK(task_id ~ '^social:[0-9a-f]{32}$'),
 history_event_id BIGINT NOT NULL UNIQUE REFERENCES synth_meta.history_events(id),
 player_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
 performer_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
 recipient_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
 input_payload JSONB NOT NULL CHECK(jsonb_typeof(input_payload)='object'),
 input_sha256 CHAR(64) NOT NULL CHECK(input_sha256 ~ '^[0-9a-f]{64}$'),
 CHECK(performer_identity_id<>recipient_identity_id),CHECK(player_identity_id<>performer_identity_id),CHECK(player_identity_id<>recipient_identity_id)
);
CREATE TABLE IF NOT EXISTS synth_meta.social_task_events (
 history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
 task_id TEXT NOT NULL REFERENCES synth_meta.social_task_inputs(task_id),
 prior_revision BIGINT REFERENCES synth_meta.social_task_events(history_event_id),
 status TEXT NOT NULL CHECK(status IN ('waiting_departure','waiting_return','ready','travelling','arrived','conversing','completed','cancelled','failed')),
 execution_id TEXT CHECK(execution_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 event_payload JSONB NOT NULL CHECK(jsonb_typeof(event_payload)='object'),
 event_sha256 CHAR(64) NOT NULL CHECK(event_sha256 ~ '^[0-9a-f]{64}$'),
 CHECK(prior_revision IS NULL OR prior_revision<history_event_id)
);
CREATE INDEX IF NOT EXISTS social_task_events_task ON synth_meta.social_task_events(task_id,history_event_id DESC);
-- Ephemeral execution fencing is not authoritative save state. It expires/rebinds after load.
CREATE TABLE IF NOT EXISTS synth_meta.social_task_claims (
 task_id TEXT NOT NULL REFERENCES synth_meta.social_task_inputs(task_id),
 session_id TEXT NOT NULL REFERENCES synth_meta.runtime_sessions(session_id),
 generation BIGINT NOT NULL CHECK(generation>0),
 execution_id TEXT NOT NULL CHECK(execution_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 deadline_at TIMESTAMPTZ NOT NULL,
 PRIMARY KEY(task_id,session_id,generation),UNIQUE(session_id,generation,execution_id)
);
