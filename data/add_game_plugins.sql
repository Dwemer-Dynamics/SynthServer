CREATE TABLE IF NOT EXISTS synth.game_plugins (
    plugin_name text PRIMARY KEY,
    is_light boolean NOT NULL DEFAULT false,
    compile_index integer NOT NULL DEFAULT 0,
    small_file_compile_index integer NOT NULL DEFAULT 0,
    partial_index integer NOT NULL DEFAULT 0,
    formid_prefix text NOT NULL DEFAULT '',
    updated_at timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE synth.game_plugins OWNER TO dwemer;

COMMENT ON TABLE synth.game_plugins IS 'Loaded Fallout plugins sent from the game runtime for plugin-aware form resolution';
