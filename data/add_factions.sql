CREATE TABLE IF NOT EXISTS  synth.factions (
    name text,
    formid text PRIMARY KEY,
    vendor_cont text,
    stock jsonb,
    caps numeric,
    player_rank numeric
);


ALTER TABLE synth.factions OWNER TO dwemer;

--
-- Name: TABLE factions; Type: COMMENT; Schema: public; Owner: dwemer
--

COMMENT ON TABLE synth.factions IS 'factions sent from plugin';
