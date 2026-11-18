CREATE TABLE IF NOT EXISTS  public.core_faction (
    name text,
    formid text PRIMARY KEY,
    vendor_cont text,
    stock jsonb,
    caps numeric,
    player_rank numeric
);


ALTER TABLE public.core_faction OWNER TO dwemer;

--
-- Name: TABLE core_faction; Type: COMMENT; Schema: public; Owner: dwemer
--

COMMENT ON TABLE public.core_faction IS 'factions sent from plugin';
