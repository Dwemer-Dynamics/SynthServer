CREATE TABLE IF NOT EXISTS synth.core_itt_connector (
    id SERIAL PRIMARY KEY,
    driver TEXT NOT NULL,
    label TEXT NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    api_badge_id INTEGER REFERENCES synth.core_api_badge(id),
    url TEXT
);

CREATE INDEX IF NOT EXISTS core_itt_connector_label_idx
    ON synth.core_itt_connector (LOWER(label));
