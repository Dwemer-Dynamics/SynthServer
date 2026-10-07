CREATE TABLE IF NOT EXISTS synth.bio_template_actor_map (
    id bigserial PRIMARY KEY,
    template_name character varying(128) NOT NULL,
    base_plugin text NOT NULL,
    base_local_formid character varying(8) NOT NULL,
    reference_plugin text,
    reference_local_formid character varying(8),
    exact_name text,
    is_nonverbal_creature boolean NOT NULL DEFAULT false,
    CONSTRAINT bio_template_actor_map_base_formid_check
        CHECK (base_local_formid ~ '^[0-9A-F]{8}$'),
    CONSTRAINT bio_template_actor_map_reference_pair_check
        CHECK ((reference_plugin IS NULL) = (reference_local_formid IS NULL)),
    CONSTRAINT bio_template_actor_map_reference_formid_check
        CHECK (reference_local_formid IS NULL OR reference_local_formid ~ '^[0-9A-F]{8}$')
);

CREATE UNIQUE INDEX IF NOT EXISTS bio_template_actor_map_base_identity_uidx
    ON synth.bio_template_actor_map (lower(base_plugin), upper(base_local_formid));

CREATE UNIQUE INDEX IF NOT EXISTS bio_template_actor_map_reference_identity_uidx
    ON synth.bio_template_actor_map (lower(reference_plugin), upper(reference_local_formid))
    WHERE reference_plugin IS NOT NULL AND reference_local_formid IS NOT NULL;

CREATE INDEX IF NOT EXISTS bio_template_actor_map_template_idx
    ON synth.bio_template_actor_map (lower(template_name));
