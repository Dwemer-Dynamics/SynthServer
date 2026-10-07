CREATE TABLE IF NOT EXISTS synth.visual_context (
    id BIGSERIAL PRIMARY KEY,
    capture_id TEXT NOT NULL UNIQUE,
    session_id TEXT,
    request_id TEXT NOT NULL DEFAULT '',
    turn_id TEXT NOT NULL DEFAULT '',
    generation BIGINT CHECK (generation IS NULL OR generation > 0),
    runtime_variant TEXT NOT NULL DEFAULT 'flat' CHECK (runtime_variant IN ('flat', 'vr')),
    interaction_mode TEXT NOT NULL DEFAULT 'store_only'
        CHECK (interaction_mode IN ('store_only', 'describe', 'npc_portrait')),
    actor_identity_id BIGINT,
    media_id TEXT,
    request_sha256 CHAR(64) CHECK (request_sha256 IS NULL OR request_sha256 ~ '^[0-9a-f]{64}$'),
    response_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    subject_type TEXT NOT NULL DEFAULT 'scene',
    subject_key TEXT NOT NULL,
    subject_name TEXT NOT NULL DEFAULT '',
    plugin TEXT NOT NULL DEFAULT '',
    baseid TEXT NOT NULL DEFAULT '',
    refid TEXT NOT NULL DEFAULT '',
    cell_id TEXT NOT NULL DEFAULT '',
    worldspace_id TEXT NOT NULL DEFAULT '',
    location_name TEXT NOT NULL DEFAULT '',
    worldspace_name TEXT NOT NULL DEFAULT '',
    image_path TEXT NOT NULL DEFAULT '',
    image_sha256 TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    perspective TEXT NOT NULL DEFAULT 'first_person',
    provider TEXT NOT NULL DEFAULT '',
    model TEXT NOT NULL DEFAULT '',
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    locked BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    user_edited BOOLEAN NOT NULL DEFAULT FALSE,
    captured_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE synth.visual_context
    ADD COLUMN IF NOT EXISTS session_id TEXT,
    ADD COLUMN IF NOT EXISTS request_id TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS turn_id TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS generation BIGINT CHECK (generation IS NULL OR generation > 0),
    ADD COLUMN IF NOT EXISTS runtime_variant TEXT NOT NULL DEFAULT 'flat',
    ADD COLUMN IF NOT EXISTS interaction_mode TEXT NOT NULL DEFAULT 'store_only',
    ADD COLUMN IF NOT EXISTS actor_identity_id BIGINT,
    ADD COLUMN IF NOT EXISTS media_id TEXT,
    ADD COLUMN IF NOT EXISTS request_sha256 CHAR(64),
    ADD COLUMN IF NOT EXISTS response_payload JSONB NOT NULL DEFAULT '{}'::jsonb;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'visual_context_generation_check'
          AND conrelid = 'synth.visual_context'::regclass
    ) THEN
        ALTER TABLE synth.visual_context
            ADD CONSTRAINT visual_context_generation_check
            CHECK (generation IS NULL OR generation > 0);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'visual_context_runtime_variant_check'
          AND conrelid = 'synth.visual_context'::regclass
    ) THEN
        ALTER TABLE synth.visual_context
            ADD CONSTRAINT visual_context_runtime_variant_check
            CHECK (runtime_variant IN ('flat', 'vr'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'visual_context_interaction_mode_check'
          AND conrelid = 'synth.visual_context'::regclass
    ) THEN
        ALTER TABLE synth.visual_context
            ADD CONSTRAINT visual_context_interaction_mode_check
            CHECK (interaction_mode IN ('store_only', 'describe', 'npc_portrait'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'visual_context_request_sha256_check'
          AND conrelid = 'synth.visual_context'::regclass
    ) THEN
        ALTER TABLE synth.visual_context
            ADD CONSTRAINT visual_context_request_sha256_check
            CHECK (request_sha256 IS NULL OR request_sha256 ~ '^[0-9a-f]{64}$');
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS visual_context_location_idx
    ON synth.visual_context (LOWER(worldspace_name), LOWER(location_name), active, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_cell_idx
    ON synth.visual_context (LOWER(cell_id), active, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_subject_idx
    ON synth.visual_context (subject_type, subject_key, active, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_image_idx
    ON synth.visual_context (image_sha256);
CREATE INDEX IF NOT EXISTS visual_context_runtime_request_idx
    ON synth.visual_context (session_id, generation, request_id, captured_at DESC);
CREATE INDEX IF NOT EXISTS visual_context_actor_idx
    ON synth.visual_context (actor_identity_id, captured_at DESC)
    WHERE actor_identity_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS visual_context_media_idx
    ON synth.visual_context (media_id)
    WHERE media_id IS NOT NULL;
