SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';
SET default_table_access_method = heap;


CREATE TABLE IF NOT EXISTS synth.rolemaster (
    localts bigint NOT NULL,
    ttl bigint NOT NULL,
    type character varying(128),
    data text,
    rowid bigint NOT NULL
);

ALTER TABLE IF EXISTS synth.rolemaster OWNER TO dwemer;

CREATE SEQUENCE IF NOT EXISTS synth.rolemaster_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER SEQUENCE IF EXISTS synth.rolemaster_rowid_seq OWNER TO dwemer;

-- The rest (constraints, defaults, OWNED BY) still need DO blocks
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'synth'
        AND table_name = 'rolemaster'
        AND column_name = 'rowid'
    ) THEN
        ALTER SEQUENCE synth.rolemaster_rowid_seq OWNED BY synth.rolemaster.rowid;
        ALTER TABLE synth.rolemaster ALTER COLUMN rowid SET DEFAULT nextval('synth.rolemaster_rowid_seq'::regclass);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_type = 'PRIMARY KEY'
        AND table_schema = 'synth'
        AND table_name = 'rolemaster'
        AND constraint_name = 'rolemaster_pk'
    ) THEN
        ALTER TABLE ONLY synth.rolemaster
            ADD CONSTRAINT rolemaster_pk PRIMARY KEY (rowid);
    END IF;
END
$$;
