CREATE TABLE IF NOT EXISTS synth.core_itt_connector (
    id SERIAL PRIMARY KEY,
    driver TEXT NOT NULL,
    label TEXT NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    local_api_key_id INTEGER CONSTRAINT core_itt_connector_api_badge_id_fkey REFERENCES synth.core_api_badge(id),
    url TEXT
);

CREATE INDEX IF NOT EXISTS core_itt_connector_label_idx
    ON synth.core_itt_connector (LOWER(label));

--
-- The shared key column comes last, as in the migration that adds it; a connector uses at most one key store: a shared key (api_key_id) or a SYNTH-only key (local_api_key_id).
--

ALTER TABLE ONLY synth.core_itt_connector
    ADD COLUMN IF NOT EXISTS api_key_id uuid;

DO $do$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'core_itt_connector_one_key_store') THEN
    ALTER TABLE ONLY synth.core_itt_connector
    ADD CONSTRAINT core_itt_connector_one_key_store CHECK (api_key_id IS NULL OR local_api_key_id IS NULL);
END IF; END $do$;
