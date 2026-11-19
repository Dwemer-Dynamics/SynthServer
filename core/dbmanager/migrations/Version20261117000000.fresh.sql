-- Generated data: the complete SYNTH application schema and stock seeds of the frozen legacy baseline, applied by
-- Version20261117000000 to an empty database in the migration owner's transaction. Do not edit; the migration checks
-- this file's SHA-256. Source: the legacy fresh install of 0f4fadccb25704a697662812f52544e6c2953141
-- (data/database_default.sql, then the frozen debug/db_updates.php chain with conf/conf.sample.php) on disposable
-- PostgreSQL 17, exported with pg_dump --schema=synth --schema=synth_meta --schema=plugins --no-owner --no-privileges
-- --inserts --rows-per-insert=100, then curated: session SETs replaced by SET LOCAL, the extensions added,
-- CREATE SCHEMA synth_meta made IF NOT EXISTS, the pg_trgm operator class unqualified, and seed timestamps written
-- at export time replaced by CURRENT_TIMESTAMP. A marker line after each statement separates the statements.
-- Procedure: docs/SCHEMA-MIGRATIONS.md.
SET LOCAL client_encoding = 'UTF8';
-- end-statement
SET LOCAL standard_conforming_strings = on;
-- end-statement
SET LOCAL check_function_bodies = false;
-- end-statement
SET LOCAL client_min_messages = warning;
-- end-statement
SET LOCAL search_path = synth, synth_meta, public;
-- end-statement
CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;
-- end-statement
CREATE SCHEMA plugins;
-- end-statement
CREATE SCHEMA synth;
-- end-statement
COMMENT ON SCHEMA synth IS 'Canonical SYNTH application data';
-- end-statement
CREATE SCHEMA IF NOT EXISTS synth_meta;
-- end-statement
COMMENT ON SCHEMA synth_meta IS 'SYNTH runtime, protocol, migration and playthrough metadata';
-- end-statement
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA synth;
-- end-statement
CREATE FUNCTION synth.convert_gamets2days(gamets bigint) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN floor(gamets * 0.0000001);
            END;
        $$;
-- end-statement
CREATE FUNCTION synth.convert_gamets2fallout_date(gamets bigint) RETURNS text
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN to_char(timestamp '2287-10-23 00:00:00' + (gamets * 0.0000024) * INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SS');
            END;
        $$;
-- end-statement
CREATE FUNCTION synth.convert_gamets2fallout_long_date(gamets bigint) RETURNS text
    LANGUAGE plpgsql
    AS $$
            DECLARE
                s_date1 text;
                s_date2 text;
                s_date3 text;
                s_month text;
                s_dayweek text;
                s_dayname text;
                s_longm text;
                f_hours float;
                ts_base timestamp;
                ts2 timestamp;
                s_res text;
            BEGIN
                f_hours := (gamets * 0.0000024);
                ts_base := timestamp '2287-10-23 00:00:00';
                ts2 := ts_base  + f_hours * INTERVAL '1 hour';
                s_month := to_char(ts2, 'MM');
                s_dayweek := to_char(ts2, 'D'); -- D	day of the week,
                CASE s_dayweek
                    WHEN '2' THEN s_dayname := 'Sunday'; -- sunday
                    WHEN '3' THEN s_dayname := 'Monday';
                    WHEN '4' THEN s_dayname := 'Tuesday';
                    WHEN '5' THEN s_dayname := 'Wednesday';
                    WHEN '6' THEN s_dayname := 'Thursday';
                    WHEN '7' THEN s_dayname := 'Friday';
                    WHEN '1' THEN s_dayname := 'Saturday'; -- saturday
                    ELSE s_dayname := 'unknown day';
                END CASE;
                CASE s_month
                    WHEN '01' THEN s_longm := 'January';
                    WHEN '02' THEN s_longm := 'February';
                    WHEN '03' THEN s_longm := 'March';
                    WHEN '04' THEN s_longm := 'April';
                    WHEN '05' THEN s_longm := 'May';
                    WHEN '06' THEN s_longm := 'June';
                    WHEN '07' THEN s_longm := 'July';
                    WHEN '08' THEN s_longm := 'August';
                    WHEN '09' THEN s_longm := 'September';
                    WHEN '10' THEN s_longm := 'October';
                    WHEN '11' THEN s_longm := 'November';
                    WHEN '12' THEN s_longm := 'December';
                    ELSE s_longm := 'unknown month';
                END CASE;
                s_date1 := to_char(ts2, 'HH12:MI AM');
                s_date2 := to_char(ts2, 'FMDD');
                s_date3 := to_char(ts2, ', FMYYYY');
                s_res := s_dayname || ', ' || s_date1 || ', ' || s_date2 ||  'th of ' || s_longm || s_date3;
                RETURN s_res;
            END;
        $$;
-- end-statement
CREATE FUNCTION synth.convert_gamets2fallout_long_date2(gamets bigint) RETURNS text
    LANGUAGE plpgsql
    AS $$
            DECLARE
                s_date1 text;
                s_date2 text;
                s_month text;
                s_longm text;
                f_hours float;
                ts_base timestamp;
                ts2 timestamp;
                s_res text;
            BEGIN
                f_hours := (gamets * 0.0000024);
                ts_base := timestamp '2287-10-23 00:00:00';
                ts2 := ts_base  + f_hours * INTERVAL '1 hour';
                s_month := to_char(ts2, 'MM');
                CASE s_month
                    WHEN '01' THEN s_longm := 'January';
                    WHEN '02' THEN s_longm := 'February';
                    WHEN '03' THEN s_longm := 'March';
                    WHEN '04' THEN s_longm := 'April';
                    WHEN '05' THEN s_longm := 'May';
                    WHEN '06' THEN s_longm := 'June';
                    WHEN '07' THEN s_longm := 'July';
                    WHEN '08' THEN s_longm := 'August';
                    WHEN '09' THEN s_longm := 'September';
                    WHEN '10' THEN s_longm := 'October';
                    WHEN '11' THEN s_longm := 'November';
                    WHEN '12' THEN s_longm := 'December';
                    ELSE s_longm := 'unknown';
                END CASE;
                s_date1 := to_char(ts2, 'DD');
                s_date2 := to_char(ts2, ' FMYYYY, HH24:MI');
                s_res := s_date1 || 'th of ' || s_longm || s_date2;
                RETURN s_res;
            END;
        $$;
-- end-statement
CREATE FUNCTION synth.convert_gamets2gregorian_date(gamets bigint) RETURNS text
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN to_char(timestamp '2287-10-23 00:00:00' + (gamets * 0.0000024) * INTERVAL '1 hour', 'YYYY-MM-DD HH24:MI:SS');
            END;
        $$;
-- end-statement
CREATE FUNCTION synth.convert_gamets2hours(gamets bigint) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN floor(gamets * 0.0000024);
            END;
        $$;
-- end-statement
CREATE FUNCTION synth_meta.clone_schema(source_schema text, dest_schema text) RETURNS void
    LANGUAGE plpgsql
    AS $_$
DECLARE
    obj RECORD;
    seq_val BIGINT;
BEGIN
    -- Create destination schema
    EXECUTE format('CREATE SCHEMA IF NOT EXISTS %I', dest_schema);

    -- Clone all tables with structure and data
    FOR obj IN
        SELECT tablename FROM pg_tables WHERE schemaname = source_schema
    LOOP
        -- Create table structure (including indexes, constraints, defaults)
        EXECUTE format('CREATE TABLE IF NOT EXISTS %I.%I (LIKE %I.%I INCLUDING ALL)',
                       dest_schema, obj.tablename, source_schema, obj.tablename);

        -- Copy all data, preserving values from GENERATED ALWAYS identity columns.
        EXECUTE format('INSERT INTO %I.%I OVERRIDING SYSTEM VALUE SELECT * FROM %I.%I ON CONFLICT DO NOTHING',
                       dest_schema, obj.tablename, source_schema, obj.tablename);
    END LOOP;

    -- Clone sequences with their current values
    -- Must happen AFTER table data is copied to ensure sync
    FOR obj IN
        SELECT sequencename FROM pg_sequences WHERE schemaname = source_schema
    LOOP
        DECLARE
            table_name text;
            max_val bigint := 0;
            source_val bigint := 0;
        BEGIN
            -- Get current sequence value from source
            EXECUTE format('SELECT last_value FROM %I.%I', source_schema, obj.sequencename) INTO source_val;

            -- Create sequence in destination if it doesn't exist
            EXECUTE format('CREATE SEQUENCE IF NOT EXISTS %I.%I', dest_schema, obj.sequencename);

            -- For table sequences (*_id_seq or *_rowid_seq), sync with max value in table
            -- This prevents INSERT conflicts after cloning
            IF obj.sequencename LIKE '%_id_seq' OR obj.sequencename LIKE '%_rowid_seq' THEN
                -- Extract table name and column name from sequence name
                IF obj.sequencename LIKE '%_rowid_seq' THEN
                    table_name := regexp_replace(obj.sequencename, '_rowid_seq$', '');

                    -- Try to get max rowid from the destination table
                    BEGIN
                        EXECUTE format('SELECT COALESCE(MAX(rowid), 0) FROM %I.%I', dest_schema, table_name) INTO max_val;

                        -- Use the greater of: source sequence value or table max + 1
                        IF max_val >= source_val THEN
                            seq_val := max_val + 1;
                            RAISE NOTICE 'Sequence % adjusted: source=% tablemax=% final=%',
                                        obj.sequencename, source_val, max_val, seq_val;
                        ELSE
                            seq_val := source_val;
                        END IF;
                    EXCEPTION WHEN OTHERS THEN
                        -- Table might not exist or have a 'rowid' column, just use source value
                        seq_val := source_val;
                    END;
                ELSIF obj.sequencename LIKE '%_id_seq' THEN
                    table_name := regexp_replace(obj.sequencename, '_id_seq$', '');

                    -- Try to get max id from the destination table
                    BEGIN
                        EXECUTE format('SELECT COALESCE(MAX(id), 0) FROM %I.%I', dest_schema, table_name) INTO max_val;

                        -- Use the greater of: source sequence value or table max + 1
                        IF max_val >= source_val THEN
                            seq_val := max_val + 1;
                            RAISE NOTICE 'Sequence % adjusted: source=% tablemax=% final=%',
                                        obj.sequencename, source_val, max_val, seq_val;
                        ELSE
                            seq_val := source_val;
                        END IF;
                    EXCEPTION WHEN OTHERS THEN
                        -- Table might not exist or have an 'id' column, just use source value
                        seq_val := source_val;
                    END;
                END IF;
            ELSE
                -- Not a table sequence, just copy the value
                seq_val := source_val;
            END IF;

            -- seq_val is the next value to issue, so leave is_called false.
            EXECUTE format('SELECT setval(''%I.%I'', %s, false)', dest_schema, obj.sequencename, seq_val);
        END;
    END LOOP;

    -- Fix sequence ownership and column defaults to point to destination schema sequences
    -- This is CRITICAL - without this, inserts will fail with "null value violates not-null constraint"
    -- because the column defaults still reference the SOURCE schema's sequences
    FOR obj IN
        SELECT
            t.tablename,
            c.column_name,
            c.column_default
        FROM pg_tables t
        JOIN information_schema.columns c
            ON c.table_schema = t.schemaname
            AND c.table_name = t.tablename
        WHERE t.schemaname = dest_schema
            AND c.column_default IS NOT NULL
            AND c.column_default LIKE '%nextval%'
    LOOP
        DECLARE
            new_default text;
            seq_name text;
            full_seq_name text;
        BEGIN
            -- Extract just the sequence name (without schema prefix)
            -- From: nextval('public.eventlog_rowid_seq'::regclass)
            -- Or:   nextval('eventlog_rowid_seq'::regclass)
            -- Get: eventlog_rowid_seq

            -- Method: Look for the pattern and extract the last part after any dot
            full_seq_name := substring(obj.column_default from '''([^'']+)''');

            -- If it has a schema prefix (contains .), take just the sequence name
            IF position('.' in full_seq_name) > 0 THEN
                seq_name := substring(full_seq_name from '[^.]+$');
            ELSE
                seq_name := full_seq_name;
            END IF;

            -- Skip if we couldn't extract a sequence name
            IF seq_name IS NULL OR seq_name = '' THEN
                RAISE WARNING 'Could not extract sequence name from default: %', obj.column_default;
                CONTINUE;
            END IF;

            -- Verify the sequence exists in destination schema
            IF NOT EXISTS (SELECT 1 FROM pg_sequences WHERE schemaname = dest_schema AND sequencename = seq_name) THEN
                RAISE WARNING 'Sequence %.% does not exist, skipping', dest_schema, seq_name;
                CONTINUE;
            END IF;

            -- Build new default pointing to destination schema sequence
            new_default := format('nextval(''%I.%I''::regclass)', dest_schema, seq_name);

            -- Update the column default
            EXECUTE format('ALTER TABLE %I.%I ALTER COLUMN %I SET DEFAULT %s',
                          dest_schema, obj.tablename, obj.column_name, new_default);

            -- Set sequence ownership so it will be dropped with the table
            EXECUTE format('ALTER SEQUENCE %I.%I OWNED BY %I.%I.%I',
                          dest_schema, seq_name, dest_schema, obj.tablename, obj.column_name);

            RAISE NOTICE 'Fixed: %.% default now uses %.% (was: %)',
                        obj.tablename, obj.column_name, dest_schema, seq_name, obj.column_default;
        EXCEPTION WHEN others THEN
            RAISE WARNING 'Failed to fix sequence for %.%: % (default was: %)',
                         obj.tablename, obj.column_name, SQLERRM, obj.column_default;
        END;
    END LOOP;

    PERFORM synth_meta.sync_schema_sequences(dest_schema);

    -- Clone views (optional - captures query definitions)
    FOR obj IN
        SELECT table_name, view_definition
        FROM information_schema.views
        WHERE table_schema = source_schema
    LOOP
        BEGIN
            -- Replace schema references in view definition
            EXECUTE format('CREATE OR REPLACE VIEW %I.%I AS %s',
                          dest_schema, obj.table_name,
                          replace(obj.view_definition, source_schema || '.', dest_schema || '.'));
        EXCEPTION WHEN others THEN
            -- Skip views that fail (e.g., complex views with dependencies)
            RAISE NOTICE 'Could not clone view %: %', obj.table_name, SQLERRM;
        END;
    END LOOP;

    RAISE NOTICE 'Schema cloning complete: % -> %', source_schema, dest_schema;

END;
$_$;
-- end-statement
CREATE FUNCTION synth_meta.drop_schema_safe(schema_name text) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Prevent dropping critical schemas
    IF schema_name IN ('public', 'pg_catalog', 'information_schema', 'pg_toast', 'synth_meta') THEN
        RAISE EXCEPTION 'Cannot drop protected schema: %', schema_name;
        RETURN false;
    END IF;

    -- Drop the schema and all contents
    EXECUTE format('DROP SCHEMA IF EXISTS %I CASCADE', schema_name);
    RETURN true;

EXCEPTION WHEN others THEN
    RAISE NOTICE 'Error dropping schema %: %', schema_name, SQLERRM;
    RETURN false;
END;
$$;
-- end-statement
CREATE FUNCTION synth_meta.get_schema_size(schema_name text) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
    total_size bigint := 0;
    obj RECORD;
BEGIN
    FOR obj IN
        SELECT tablename FROM pg_tables WHERE schemaname = schema_name
    LOOP
        total_size := total_size + pg_total_relation_size(format('%I.%I', schema_name, obj.tablename));
    END LOOP;

    RETURN total_size;
END;
$$;
-- end-statement
CREATE FUNCTION synth_meta.json_object_has_unique_keys(value text) RETURNS boolean
    LANGUAGE plpgsql IMMUTABLE
    AS $$
DECLARE parsed JSON;
BEGIN
    IF value IS NULL THEN RETURN NULL; END IF;
    BEGIN parsed := value::json; EXCEPTION WHEN others THEN RETURN false; END;
    IF json_typeof(parsed) <> 'object' THEN RETURN false; END IF;
    RETURN NOT EXISTS (
        WITH RECURSIVE nodes(node) AS (
            SELECT parsed
            UNION ALL
            SELECT child.node FROM nodes n CROSS JOIN LATERAL (
                SELECT e.value AS node FROM json_each(CASE WHEN json_typeof(n.node) = 'object' THEN n.node END) e
                UNION ALL
                SELECT a.value FROM json_array_elements(CASE WHEN json_typeof(n.node) = 'array' THEN n.node END) a) child)
        SELECT 1 FROM nodes n WHERE json_typeof(n.node) = 'object'
            AND (SELECT count(*) <> count(DISTINCT k) FROM json_object_keys(n.node) k));
END $$;
-- end-statement
CREATE FUNCTION synth_meta.sync_schema_sequences(target_schema text) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    obj RECORD;
    boundary_value BIGINT;
    sequence_last_value BIGINT;
    sequence_is_called BOOLEAN;
    sequence_next_value BIGINT;
    repaired_count integer := 0;
BEGIN
    FOR obj IN
        SELECT
            sequence_relation.relname AS sequence_name,
            table_relation.relname AS table_name,
            table_column.attname AS column_name,
            sequence_data.seqincrement AS increment_by
        FROM pg_class AS sequence_relation
        JOIN pg_namespace AS sequence_namespace
            ON sequence_namespace.oid = sequence_relation.relnamespace
        JOIN pg_sequence AS sequence_data
            ON sequence_data.seqrelid = sequence_relation.oid
        JOIN pg_depend AS dependency
            ON dependency.classid = 'pg_class'::regclass
            AND dependency.objid = sequence_relation.oid
            AND dependency.refclassid = 'pg_class'::regclass
            AND dependency.deptype IN ('a', 'i')
        JOIN pg_class AS table_relation
            ON table_relation.oid = dependency.refobjid
        JOIN pg_namespace AS table_namespace
            ON table_namespace.oid = table_relation.relnamespace
        JOIN pg_attribute AS table_column
            ON table_column.attrelid = table_relation.oid
            AND table_column.attnum = dependency.refobjsubid
        WHERE sequence_relation.relkind = 'S'
            AND sequence_namespace.nspname = target_schema
            AND table_namespace.nspname = target_schema
            AND table_column.attnum > 0
            AND NOT table_column.attisdropped
    LOOP
        EXECUTE format(
            'SELECT %s(%I)::bigint FROM %I.%I',
            CASE WHEN obj.increment_by > 0 THEN 'MAX' ELSE 'MIN' END,
            obj.column_name,
            target_schema,
            obj.table_name
        ) INTO boundary_value;

        IF boundary_value IS NULL THEN
            CONTINUE;
        END IF;

        EXECUTE format(
            'SELECT last_value::bigint, is_called FROM %I.%I',
            target_schema,
            obj.sequence_name
        ) INTO sequence_last_value, sequence_is_called;

        sequence_next_value := CASE
            WHEN sequence_is_called
                THEN sequence_last_value + obj.increment_by
            ELSE sequence_last_value
        END;

        IF (obj.increment_by > 0 AND sequence_next_value <= boundary_value)
            OR (obj.increment_by < 0 AND sequence_next_value >= boundary_value) THEN
            EXECUTE format(
                'SELECT setval(%L::regclass, %s, true)',
                format('%I.%I', target_schema, obj.sequence_name),
                boundary_value
            );
            repaired_count := repaired_count + 1;
        END IF;
    END LOOP;

    RETURN repaired_count;
END;
$$;
-- end-statement
CREATE TABLE synth.actions_issued (
    action text,
    fullcall text,
    actorname text,
    ts numeric,
    localts numeric,
    gamets numeric,
    original text,
    rowid integer NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE synth.actions_issued_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.actions_issued_rowid_seq OWNED BY synth.actions_issued.rowid;
-- end-statement
CREATE TABLE synth.actor_identities (
    id bigint NOT NULL,
    game text DEFAULT 'fo4'::text NOT NULL,
    full_form_id character varying(10) NOT NULL,
    origin_plugin text NOT NULL,
    playthrough_id text NOT NULL,
    display_name text,
    first_seen_at timestamp with time zone DEFAULT now() NOT NULL,
    last_seen_at timestamp with time zone DEFAULT now() NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT actor_identities_full_form_id_check CHECK (((full_form_id)::text ~ '^0x[0-9A-Fa-f]{8}$'::text)),
    CONSTRAINT actor_identities_game_check CHECK ((game = 'fo4'::text)),
    CONSTRAINT actor_identities_origin_plugin_check CHECK ((origin_plugin ~* '^[^/\\]+\.(esm|esp|esl)$'::text)),
    CONSTRAINT actor_identities_playthrough_id_check CHECK ((playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text))
);
-- end-statement
CREATE SEQUENCE synth.actor_identities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.actor_identities_id_seq OWNED BY synth.actor_identities.id;
-- end-statement
CREATE TABLE public.core_api_badge (
    id integer NOT NULL,
    label text NOT NULL,
    api_key text NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.api_badge_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.api_badge_id_seq OWNED BY public.core_api_badge.id;
-- end-statement
CREATE SEQUENCE synth.audit_request_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
CREATE TABLE synth.audit_request (
    request text,
    result text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    rowid bigint DEFAULT nextval('synth.audit_request_rowid_seq'::regclass) NOT NULL,
    url text,
    connector text,
    usage jsonb
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE TABLE public.core_npc_biography_template_actor (
    id bigint NOT NULL,
    template_name character varying(128) NOT NULL,
    base_plugin text,
    base_local_formid character varying(8),
    reference_plugin text,
    reference_local_formid character varying(8),
    exact_name text,
    is_nonverbal_creature boolean DEFAULT false NOT NULL,
    CONSTRAINT bio_template_actor_map_base_formid_check CHECK (((base_local_formid)::text ~ '^[0-9A-F]{8}$'::text)),
    CONSTRAINT bio_template_actor_map_base_pair_check CHECK (((base_plugin IS NULL) = (base_local_formid IS NULL))),
    CONSTRAINT bio_template_actor_map_identity_present_check CHECK (((base_plugin IS NOT NULL) OR (reference_plugin IS NOT NULL))),
    CONSTRAINT bio_template_actor_map_reference_formid_check CHECK (((reference_local_formid IS NULL) OR ((reference_local_formid)::text ~ '^[0-9A-F]{8}$'::text))),
    CONSTRAINT bio_template_actor_map_reference_pair_check CHECK (((reference_plugin IS NULL) = (reference_local_formid IS NULL)))
);
-- end-statement
CREATE SEQUENCE public.bio_template_actor_map_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.bio_template_actor_map_id_seq OWNED BY public.core_npc_biography_template_actor.id;
-- end-statement
CREATE TABLE public.core_npc_biography_template (
    npc_name character varying(128) NOT NULL,
    worldknowledge_tags text,
    core text,
    npc_static_bio text,
    appearance text,
    personality text,
    relationships text,
    occupation text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    gender text,
    race text,
    refid text
);
-- end-statement
CREATE TABLE public.core_npc_biography_template_custom (
    npc_name character varying(128) NOT NULL,
    worldknowledge_tags text,
    core text,
    npc_static_bio text,
    appearance text,
    personality text,
    relationships text,
    occupation text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    gender text,
    race text,
    refid text
);
-- end-statement
CREATE VIEW synth.combined_bio_templates AS
 SELECT c.npc_name,
    c.worldknowledge_tags,
    c.core,
    c.npc_static_bio,
    c.appearance,
    c.personality,
    c.relationships,
    c.occupation,
    c.skills,
    c.speechstyle,
    c.goals,
    c.voiceid,
    c.gender,
    c.race,
    c.refid
   FROM public.core_npc_biography_template_custom c
UNION ALL
 SELECT b.npc_name,
    b.worldknowledge_tags,
    b.core,
    b.npc_static_bio,
    b.appearance,
    b.personality,
    b.relationships,
    b.occupation,
    b.skills,
    b.speechstyle,
    b.goals,
    b.voiceid,
    b.gender,
    b.race,
    b.refid
   FROM (public.core_npc_biography_template b
     LEFT JOIN public.core_npc_biography_template_custom c ON (((b.npc_name)::text = (c.npc_name)::text)))
  WHERE (c.npc_name IS NULL);
-- end-statement
CREATE TABLE public.core_action (
    id integer NOT NULL,
    code_name character varying(128) NOT NULL,
    action_name character varying(255) NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    return_message text DEFAULT ''::text NOT NULL,
    available_to_npc boolean DEFAULT false NOT NULL,
    available_to_followers boolean DEFAULT false NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    parameters_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    game_function boolean DEFAULT true NOT NULL,
    import_version bigint DEFAULT 0 NOT NULL,
    script_proxy_program jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE TABLE public.core_action_custom (
    id integer NOT NULL,
    code_name character varying(128) NOT NULL,
    action_name character varying(255) NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    return_message text DEFAULT ''::text NOT NULL,
    available_to_npc boolean DEFAULT false NOT NULL,
    available_to_followers boolean DEFAULT false NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    parameters_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    game_function boolean DEFAULT true NOT NULL,
    import_version bigint DEFAULT 0 NOT NULL,
    script_proxy_program jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE VIEW synth.combined_core_action AS
 SELECT c.id,
    c.code_name,
    c.action_name,
    c.description,
    c.return_message,
    c.available_to_npc,
    c.available_to_followers,
    c.available_to_narrator,
    c.is_activated,
    c.parameters_json,
    c.metadata,
    c.game_function,
    c.import_version,
    c.script_proxy_program,
    c.created_at,
    c.updated_at
   FROM public.core_action_custom c
UNION ALL
 SELECT b.id,
    b.code_name,
    b.action_name,
    b.description,
    b.return_message,
    b.available_to_npc,
    b.available_to_followers,
    b.available_to_narrator,
    b.is_activated,
    b.parameters_json,
    b.metadata,
    b.game_function,
    b.import_version,
    b.script_proxy_program,
    b.created_at,
    b.updated_at
   FROM (public.core_action b
     LEFT JOIN public.core_action_custom c ON ((lower((b.code_name)::text) = lower((c.code_name)::text))))
  WHERE (c.code_name IS NULL);
-- end-statement
CREATE TABLE public.core_item_description (
    plugin text DEFAULT ''::text NOT NULL,
    baseid character varying(128) NOT NULL,
    name text,
    description text
);
-- end-statement
CREATE TABLE public.core_item_description_custom (
    plugin text DEFAULT ''::text NOT NULL,
    baseid character varying(128) NOT NULL,
    name text,
    description text
);
-- end-statement
CREATE VIEW synth.combined_descriptions AS
 SELECT c.plugin,
    c.baseid,
    c.name,
    c.description
   FROM public.core_item_description_custom c
UNION ALL
 SELECT i.plugin,
    i.baseid,
    i.name,
    i.description
   FROM (public.core_item_description i
     LEFT JOIN public.core_item_description_custom c ON (((i.plugin = c.plugin) AND ((i.baseid)::text = (c.baseid)::text))))
  WHERE (c.baseid IS NULL);
-- end-statement
ALTER TABLE public.core_action_custom ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.core_action_custom_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);
-- end-statement
ALTER TABLE public.core_action ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.core_action_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);
-- end-statement
CREATE TABLE public.core_itt_connector (
    id integer NOT NULL,
    driver text NOT NULL,
    label text NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    api_badge_id integer,
    url text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.core_itt_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.core_itt_connector_id_seq OWNED BY public.core_itt_connector.id;
-- end-statement
CREATE TABLE public.core_llm_connector (
    id integer NOT NULL,
    label text,
    metadata jsonb,
    url text,
    model text,
    provider text,
    driver text,
    reasoning_model integer,
    max_tokens integer,
    enforce_json integer DEFAULT 1,
    prefill_json integer DEFAULT 0,
    api_badge_id integer,
    json_schema integer,
    temperature numeric,
    presence_penalty numeric,
    frequency_penalty numeric,
    repetition_penalty numeric,
    top_p numeric,
    top_k integer,
    min_p numeric,
    top_a numeric,
    service text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE TABLE synth.core_npc_master (
    id integer NOT NULL,
    npc_name text NOT NULL,
    npc_favorite integer DEFAULT 0,
    lock_profile integer DEFAULT 0,
    prompt_head text,
    npc_static_bio text,
    worldknowledge_tags text,
    emote_moods text,
    personality text,
    relationships text,
    occupation text,
    appearance text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    metadata jsonb,
    gender text,
    race text,
    refid character varying(16),
    profile_id integer,
    dynamic_profile integer,
    extended_data jsonb,
    md5 text,
    gamets_last_updated numeric,
    core text,
    base text,
    tags text,
    actor_identity_id bigint,
    scope_playthrough_id text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
COMMENT ON COLUMN synth.core_npc_master.personality IS 'how they behave';
-- end-statement
COMMENT ON COLUMN synth.core_npc_master.core IS 'really quick summary of character';
-- end-statement
COMMENT ON COLUMN synth.core_npc_master.tags IS 'comma separated,user tags';
-- end-statement
CREATE TABLE synth.core_npc_master_history (
    history_id integer NOT NULL,
    npc_id integer NOT NULL,
    npc_name text,
    npc_favorite integer,
    lock_profile integer,
    prompt_head text,
    npc_static_bio text,
    worldknowledge_tags text,
    emote_moods text,
    personality text,
    relationships text,
    occupation text,
    appearance text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    metadata jsonb,
    gender text,
    race text,
    refid character varying(16),
    profile_id integer,
    dynamic_profile integer,
    extended_data jsonb,
    md5 text,
    gamets_last_updated numeric,
    created timestamp without time zone DEFAULT now(),
    core text,
    base text,
    tags text,
    actor_identity_id bigint,
    scope_playthrough_id text
);
-- end-statement
CREATE SEQUENCE synth.core_npc_master_history_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.core_npc_master_history_history_id_seq OWNED BY synth.core_npc_master_history.history_id;
-- end-statement
CREATE TABLE public.core_profiles (
    id integer NOT NULL,
    label text,
    default_npc text,
    default_narrator text,
    tts_connector_id integer,
    llm_primary_id integer,
    llm_secondary_id integer,
    llm_tertiary_id integer,
    llm_quaternary_id integer,
    llm_formatter_id integer,
    llm_fallback_id integer,
    metadata jsonb,
    diary_connector_id integer,
    slot integer,
    prompt text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
COMMENT ON COLUMN public.core_profiles.llm_fallback_id IS 'Fallback LLM connector used when primary connector fails with network error';
-- end-statement
COMMENT ON COLUMN public.core_profiles.prompt IS 'profile specific prompt, will be added to context';
-- end-statement
CREATE TABLE public.core_stt_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE TABLE public.core_tts_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text,
    voice_field text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE TABLE public.core_tts_pronunciation (
    id integer NOT NULL,
    source_text character varying(120) NOT NULL,
    spoken_text character varying(240) NOT NULL,
    npc_names character varying(512) DEFAULT ''::character varying NOT NULL,
    races character varying(512) DEFAULT ''::character varying NOT NULL,
    oghma_tags character varying(512) DEFAULT ''::character varying NOT NULL,
    is_builtin boolean DEFAULT false NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT core_tts_pronunciation_source_not_blank CHECK ((btrim((source_text)::text) <> ''::text)),
    CONSTRAINT core_tts_pronunciation_spoken_not_blank CHECK ((btrim((spoken_text)::text) <> ''::text))
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
ALTER TABLE public.core_tts_pronunciation ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.core_tts_pronunciation_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);
-- end-statement
CREATE TABLE public.core_catalog_version (
    catalog_key text NOT NULL,
    version bigint NOT NULL,
    CONSTRAINT core_catalog_version_pkey PRIMARY KEY (catalog_key)
);
-- end-statement
CREATE TABLE public.core_context_event (
    type character varying(128),
    data text,
    sess character varying(1024),
    gamets bigint NOT NULL,
    localts bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    people text,
    location text,
    party text,
    utterance_id text,
    delivery_state text,
    synth_session_id text,
    synth_generation bigint,
    synth_context_sequence bigint,
    synth_request_id text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.eventlog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.eventlog_rowid_seq OWNED BY public.core_context_event.rowid;
-- end-statement
CREATE TABLE public.core_faction (
    name text,
    formid text NOT NULL,
    vendor_cont text,
    stock jsonb,
    caps numeric,
    player_rank numeric
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
COMMENT ON TABLE public.core_faction IS 'factions sent from plugin';
-- end-statement
CREATE TABLE public.core_plugins (
    plugin_name text NOT NULL,
    is_light boolean DEFAULT false NOT NULL,
    compile_index integer DEFAULT 0 NOT NULL,
    small_file_compile_index integer DEFAULT 0 NOT NULL,
    partial_index integer DEFAULT 0 NOT NULL,
    formid_prefix text DEFAULT ''::text NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
COMMENT ON TABLE public.core_plugins IS 'Loaded Fallout plugins sent from the game runtime for plugin-aware form resolution';
-- end-statement
CREATE TABLE public.core_config_preset (
    id integer NOT NULL,
    name character varying(60) NOT NULL,
    snapshot jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT global_settings_presets_name_check CHECK ((length(btrim((name)::text)) > 0)),
    CONSTRAINT global_settings_presets_snapshot_check CHECK ((jsonb_typeof(snapshot) = 'object'::text))
);
-- end-statement
ALTER TABLE public.core_config_preset ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.global_settings_presets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);
-- end-statement
CREATE TABLE public.core_npc_profile_rule (
    id integer NOT NULL,
    description text NOT NULL,
    match_name text,
    match_race text,
    match_gender text,
    match_base text,
    match_faction text,
    match_mods text[],
    action jsonb,
    profile integer,
    priority integer DEFAULT 0,
    enabled boolean DEFAULT true
);
-- end-statement
CREATE SEQUENCE public.import_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.import_rules_id_seq OWNED BY public.core_npc_profile_rule.id;
-- end-statement
CREATE SEQUENCE public.llm_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.llm_connector_id_seq OWNED BY public.core_llm_connector.id;
-- end-statement
CREATE TABLE public.core_location (
    name text,
    formid bigint,
    worldspace text,
    tags text,
    is_interior integer,
    vanilla_location boolean,
    coords point,
    refs text,
    cleared boolean,
    updated_at timestamp without time zone
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
COMMENT ON TABLE public.core_location IS 'locations sent from plugin';
-- end-statement
CREATE TABLE synth.log (
    localts bigint NOT NULL,
    prompt text,
    response text,
    url text,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE synth.log_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.log_rowid_seq OWNED BY synth.log.rowid;
-- end-statement
CREATE TABLE public.core_memory_log (
    speaker text,
    message text,
    session text,
    uid integer NOT NULL,
    listener text,
    localts integer,
    gamets bigint NOT NULL,
    momentum text,
    rowid bigint NOT NULL,
    event character varying(64),
    ts bigint
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.memory_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.memory_rowid_seq OWNED BY public.core_memory_log.rowid;
-- end-statement
CREATE TABLE public.core_memory (
    gamets_truncated bigint NOT NULL,
    n integer,
    packed_message text,
    summary text,
    classifier text,
    uid integer,
    rowid integer NOT NULL,
    embedding public.vector(384),
    companions text,
    embedding768 public.vector(768),
    tags text,
    scope text,
    native_vec tsvector,
    source_type text DEFAULT 'summary'::text NOT NULL,
    topic text,
    location text,
    localts bigint,
    gamets bigint,
    ts text,
    sess character varying(1024),
    CONSTRAINT core_memory_source_type_check CHECK ((source_type = ANY (ARRAY['summary'::text, 'diary'::text])))
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.memory_summary_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.memory_summary_rowid_seq OWNED BY public.core_memory.rowid;
-- end-statement
CREATE SEQUENCE public.memory_uid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.memory_uid_seq OWNED BY public.core_memory_log.uid;
-- end-statement
CREATE TABLE synth.speech (
    sess character varying(1024),
    speaker text,
    speech text,
    location text,
    listener text,
    topic text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    companions text,
    audios text,
    utterance_id text,
    mood text,
    emotion text,
    emotion_intensity text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE VIEW synth.memory_v AS
 WITH event_source AS (
         SELECT eventlog.rowid,
            eventlog.gamets,
            eventlog.ts,
            eventlog.type,
            eventlog.data,
            eventlog.people,
            eventlog.delivery_state,
            NULLIF("substring"(eventlog.data, '"speaker"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_speaker,
            NULLIF("substring"(eventlog.data, '"listener_hint"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_listener,
            NULLIF("substring"(eventlog.data, '"resolved_rechat_target"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_rechat_target,
            NULLIF("substring"(eventlog.data, '"origin_line"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_origin_line,
            NULLIF("substring"(eventlog.data, '"text"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text) AS json_text,
            COALESCE(NULLIF("substring"(eventlog.data, '"text"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text), NULLIF("substring"(eventlog.data, '"message"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text), NULLIF("substring"(eventlog.data, '"origin_line"[[:space:]]*:[[:space:]]*"([^"]+)"'::text), ''::text), eventlog.data) AS decoded_text
           FROM public.core_context_event eventlog
          WHERE ((eventlog.data IS NOT NULL) AND (eventlog.data <> ''::text) AND ((eventlog.delivery_state IS NULL) OR (eventlog.delivery_state <> ALL (ARRAY['aborted'::text, 'failed'::text, 'cancelled'::text]))) AND ((eventlog.type)::text = ANY (ARRAY['inputtext'::text, 'inputtext_s'::text, 'narrator_inputtext'::text, 'chat'::text, 'backgroundchat'::text, 'narration'::text, 'conversation_start'::text, 'conversation_end'::text, 'world_context'::text, 'infoaction'::text, 'funcret'::text, 'death'::text, 'combatend'::text, 'combatbark'::text, 'itemfound'::text, 'itemtransfer'::text])) AND (((eventlog.type)::text <> 'world_context'::text) OR (eventlog.data IS DISTINCT FROM ( SELECT previous_world.data
                   FROM public.core_context_event previous_world
                  WHERE (((previous_world.type)::text = 'world_context'::text) AND ((previous_world.gamets < eventlog.gamets) OR ((previous_world.gamets = eventlog.gamets) AND (previous_world.rowid < eventlog.rowid))))
                  ORDER BY previous_world.gamets DESC, previous_world.rowid DESC
                 LIMIT 1))))
        )
 SELECT message,
    uid,
    gamets,
    speaker,
    listener,
    ts
   FROM ( SELECT memory.message,
            memory.uid,
            memory.gamets,
            '-'::text AS speaker,
            '-'::text AS listener,
            memory.ts
           FROM public.core_memory_log memory
          WHERE ((memory.message !~~ 'Dear Diary%'::text) AND (memory.message <> ''::text))
        UNION ALL
         SELECT ((((('(Context Location:'::text || speech.location) || ') '::text) || speech.speaker) || ': '::text) || speech.speech),
            (speech.rowid)::integer AS uid,
            speech.gamets,
            speech.speaker,
            speech.listener,
            speech.ts
           FROM synth.speech
          WHERE (speech.speech <> ''::text)
        UNION ALL
         SELECT
                CASE
                    WHEN ((event_source.type)::text = ANY (ARRAY['inputtext'::text, 'inputtext_s'::text, 'narrator_inputtext'::text])) THEN ('Player: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'world_context'::text) THEN event_source.data
                    WHEN ((event_source.type)::text = 'conversation_start'::text) THEN ('Conversation started: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'conversation_end'::text) THEN ('Conversation ended: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'prechat'::text) THEN ('Prechat: '::text || event_source.decoded_text)
                    WHEN (((event_source.type)::text = 'rechat'::text) AND (event_source.json_origin_line IS NOT NULL)) THEN ((((('Rechat: '::text || COALESCE(event_source.json_speaker, 'Unknown'::text)) || ' to '::text) || COALESCE(event_source.json_rechat_target, event_source.json_listener, 'Unknown'::text)) || ': '::text) || event_source.json_origin_line)
                    WHEN ((event_source.type)::text = 'rechat'::text) THEN ('Rechat: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'narration'::text) THEN ('Narration: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'infoaction'::text) THEN ('Action result: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'funcret'::text) THEN ('Function result: '::text || event_source.decoded_text)
                    WHEN ((event_source.type)::text = 'itemtransfer'::text) THEN ('Item transfer: '::text || COALESCE(event_source.json_text, event_source.decoded_text))
                    ELSE event_source.decoded_text
                END AS message,
            (event_source.rowid)::integer AS uid,
            event_source.gamets,
                CASE
                    WHEN ((event_source.type)::text = 'world_context'::text) THEN '-'::text
                    ELSE COALESCE(event_source.json_speaker, NULLIF("substring"(event_source.data, '^([^:]{1,80}):'::text), ''::text), NULLIF(split_part(TRIM(BOTH '|'::text FROM COALESCE(event_source.people, ''::text)), '|'::text, 1), ''::text), '-'::text)
                END AS speaker,
                CASE
                    WHEN ((event_source.type)::text = 'world_context'::text) THEN '-'::text
                    ELSE COALESCE(NULLIF("substring"(event_source.data, '\([Tt]alking to ([^)]+)\)'::text), ''::text), event_source.json_rechat_target, event_source.json_listener, NULLIF(split_part(TRIM(BOTH '|'::text FROM COALESCE(event_source.people, ''::text)), '|'::text, 2), ''::text), '-'::text)
                END AS listener,
            event_source.ts
           FROM event_source) subquery
  ORDER BY gamets, ts;
-- end-statement
CREATE SEQUENCE synth.npc_master_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.npc_master_id_seq OWNED BY synth.core_npc_master.id;
-- end-statement
CREATE SEQUENCE public.profiles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.profiles_id_seq OWNED BY public.core_profiles.id;
-- end-statement
CREATE TABLE public.core_prompt (
    prompt_key character varying(128) NOT NULL,
    default_prompt text NOT NULL,
    custom_prompt text,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);
-- end-statement
CREATE TABLE public.core_quest (
    ts text NOT NULL,
    sess character varying(1024),
    id_quest character varying(1024) NOT NULL,
    name text,
    editor_id text,
    giver_actor_id text,
    reward text,
    target_id text,
    is_unique boolean,
    mod text,
    stage integer,
    briefing text,
    briefing2 text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    data text,
    status text,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.quests_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.quests_rowid_seq OWNED BY public.core_quest.rowid;
-- end-statement
CREATE TABLE public.core_relationship_eval_queue (
    id integer NOT NULL,
    npc_id integer NOT NULL,
    eval_data jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    retry_count integer DEFAULT 0,
    last_error text
);
-- end-statement
CREATE SEQUENCE public.relationship_eval_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.relationship_eval_queue_id_seq OWNED BY public.core_relationship_eval_queue.id;
-- end-statement
CREATE TABLE public.core_relationship_init_queue (
    id integer NOT NULL,
    npc_id integer NOT NULL,
    init_data jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    retry_count integer DEFAULT 0,
    last_error text
);
-- end-statement
CREATE SEQUENCE public.relationship_init_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.relationship_init_queue_id_seq OWNED BY public.core_relationship_init_queue.id;
-- end-statement
CREATE TABLE synth.responselog (
    localts bigint NOT NULL,
    sent bigint NOT NULL,
    actor text,
    text text,
    action text,
    tag character varying(256),
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE synth.responselog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.responselog_rowid_seq OWNED BY synth.responselog.rowid;
-- end-statement
CREATE TABLE public.core_director_note (
    localts bigint NOT NULL,
    ttl bigint NOT NULL,
    type character varying(128),
    data text,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE SEQUENCE public.rolemaster_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.rolemaster_rowid_seq OWNED BY public.core_director_note.rowid;
-- end-statement
CREATE SEQUENCE synth.speech_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth.speech_rowid_seq OWNED BY synth.speech.rowid;
-- end-statement
CREATE SEQUENCE public.stt_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.stt_connector_id_seq OWNED BY public.core_stt_connector.id;
-- end-statement
CREATE VIEW synth.synthnpcs AS
 SELECT id,
    npc_name,
    npc_favorite,
    lock_profile,
    prompt_head,
    npc_static_bio,
    worldknowledge_tags,
    emote_moods,
    refid,
    base,
    gender,
    race,
    voiceid,
    core,
    profile_id,
    dynamic_profile,
    personality,
    speechstyle,
    occupation,
    appearance,
    skills,
    goals,
    metadata,
    extended_data,
    gamets_last_updated,
    tags,
    md5
   FROM synth.core_npc_master
  WHERE (npc_name <> 'The Narrator'::text);
-- end-statement
CREATE SEQUENCE public.tts_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.tts_connector_id_seq OWNED BY public.core_tts_connector.id;
-- end-statement
CREATE TABLE public.core_visual_context (
    id bigint NOT NULL,
    capture_id text NOT NULL,
    session_id text,
    request_id text DEFAULT ''::text NOT NULL,
    turn_id text DEFAULT ''::text NOT NULL,
    generation bigint,
    runtime_variant text DEFAULT 'flat'::text NOT NULL,
    interaction_mode text DEFAULT 'store_only'::text NOT NULL,
    actor_identity_id bigint,
    media_id text,
    request_sha256 character(64),
    response_payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    subject_type text DEFAULT 'scene'::text NOT NULL,
    subject_key text NOT NULL,
    subject_name text DEFAULT ''::text NOT NULL,
    plugin text DEFAULT ''::text NOT NULL,
    baseid text DEFAULT ''::text NOT NULL,
    refid text DEFAULT ''::text NOT NULL,
    cell_id text DEFAULT ''::text NOT NULL,
    worldspace_id text DEFAULT ''::text NOT NULL,
    location_name text DEFAULT ''::text NOT NULL,
    worldspace_name text DEFAULT ''::text NOT NULL,
    image_path text DEFAULT ''::text NOT NULL,
    image_sha256 text DEFAULT ''::text NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    perspective text DEFAULT 'first_person'::text NOT NULL,
    provider text DEFAULT ''::text NOT NULL,
    model text DEFAULT ''::text NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    locked boolean DEFAULT false NOT NULL,
    active boolean DEFAULT true NOT NULL,
    user_edited boolean DEFAULT false NOT NULL,
    captured_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT visual_context_generation_check CHECK (((generation IS NULL) OR (generation > 0))),
    CONSTRAINT visual_context_interaction_mode_check CHECK ((interaction_mode = ANY (ARRAY['store_only'::text, 'describe'::text, 'npc_portrait'::text]))),
    CONSTRAINT visual_context_request_sha256_check CHECK (((request_sha256 IS NULL) OR (request_sha256 ~ '^[0-9a-f]{64}$'::text))),
    CONSTRAINT visual_context_runtime_variant_check CHECK ((runtime_variant = ANY (ARRAY['flat'::text, 'vr'::text])))
);
-- end-statement
CREATE SEQUENCE public.visual_context_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.visual_context_id_seq OWNED BY public.core_visual_context.id;
-- end-statement
CREATE TABLE public.core_worldknowledge_article (
    topic character varying NOT NULL,
    aliases text DEFAULT ''::text NOT NULL,
    topic_desc character varying,
    native_vector tsvector,
    knowledge_class text,
    topic_desc_basic text,
    knowledge_class_basic text,
    tags text,
    category text,
    vector384 public.vector(384),
    entry_id bigint NOT NULL,
    canonical_topic text NOT NULL,
    source_kind text DEFAULT 'custom'::text NOT NULL,
    catalog_id text,
    catalog_version text,
    content_hash character varying(64),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT worldknowledge_content_hash_check CHECK (((content_hash IS NULL) OR ((content_hash)::text ~ '^[a-f0-9]{64}$'::text))),
    CONSTRAINT worldknowledge_ownership_check CHECK ((((source_kind = 'factory'::text) AND (catalog_id IS NOT NULL) AND (catalog_version IS NOT NULL)) OR ((source_kind = 'custom'::text) AND (catalog_id IS NULL) AND (catalog_version IS NULL)))),
    CONSTRAINT worldknowledge_source_kind_check CHECK ((source_kind = ANY (ARRAY['factory'::text, 'custom'::text])))
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
-- end-statement
CREATE TABLE public.core_worldknowledge_audit (
    audit_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    algorithm_version text NOT NULL,
    status text NOT NULL,
    request_type text NOT NULL,
    npc_name text,
    input_text text NOT NULL,
    normalized_input text NOT NULL,
    catalog_id text,
    catalog_version text,
    grounded_matches jsonb DEFAULT '[]'::jsonb NOT NULL,
    rejected_candidates jsonb DEFAULT '[]'::jsonb NOT NULL,
    tag_decisions jsonb DEFAULT '[]'::jsonb NOT NULL,
    context_tags jsonb DEFAULT '[]'::jsonb NOT NULL,
    fallback jsonb DEFAULT '{}'::jsonb NOT NULL,
    forced_signals jsonb DEFAULT '[]'::jsonb NOT NULL,
    access_decisions jsonb DEFAULT '[]'::jsonb NOT NULL,
    selected_articles jsonb DEFAULT '[]'::jsonb NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    catalog_checksum character varying(64),
    prompt_hash character varying(64),
    retrieval_elapsed_ms numeric(12,3) DEFAULT 0 NOT NULL,
    elapsed_ms numeric(12,3) DEFAULT 0 NOT NULL,
    CONSTRAINT worldknowledge_audit_status_check CHECK ((status = ANY (ARRAY['grounded'::text, 'no_match'::text, 'fallback_succeeded'::text, 'fallback_unresolved'::text, 'fallback_failed'::text, 'fallback_disabled'::text, 'fallback_unconfigured'::text, 'disabled'::text, 'ineligible'::text, 'unavailable'::text, 'not_run'::text, 'legacy'::text])))
);
-- end-statement
CREATE SEQUENCE public.worldknowledge_audit_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.worldknowledge_audit_audit_id_seq OWNED BY public.core_worldknowledge_audit.audit_id;
-- end-statement
CREATE TABLE public.core_worldknowledge_catalog (
    catalog_id text NOT NULL,
    catalog_version text NOT NULL,
    display_name text NOT NULL,
    checksum_sha256 character varying(64) NOT NULL,
    row_count integer NOT NULL,
    manifest jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    installed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    activated_at timestamp without time zone,
    CONSTRAINT worldknowledge_catalogs_checksum_format CHECK (((checksum_sha256)::text ~ '^[a-f0-9]{64}$'::text)),
    CONSTRAINT worldknowledge_catalogs_row_count CHECK ((row_count >= 0))
);
-- end-statement
CREATE VIEW synth.worldknowledge_effective AS
 WITH candidates AS (
         SELECT wk.topic,
            wk.aliases,
            wk.topic_desc,
            wk.native_vector,
            wk.knowledge_class,
            wk.topic_desc_basic,
            wk.knowledge_class_basic,
            wk.tags,
            wk.category,
            wk.vector384,
            wk.entry_id,
            wk.canonical_topic,
            wk.source_kind,
            wk.catalog_id,
            wk.catalog_version,
            wk.content_hash,
            wk.is_active,
            wk.created_at,
            wk.updated_at,
            row_number() OVER (PARTITION BY wk.canonical_topic ORDER BY
                CASE
                    WHEN (wk.source_kind = 'custom'::text) THEN 0
                    ELSE 1
                END, wk.updated_at DESC, wk.entry_id DESC) AS effective_rank
           FROM (public.core_worldknowledge_article wk
             LEFT JOIN public.core_worldknowledge_catalog catalog ON (((catalog.catalog_id = wk.catalog_id) AND (catalog.catalog_version = wk.catalog_version))))
          WHERE (wk.is_active AND ((wk.source_kind = 'custom'::text) OR ((wk.source_kind = 'factory'::text) AND catalog.is_active)))
        )
 SELECT entry_id,
    topic,
    aliases,
    canonical_topic,
    topic_desc,
    native_vector,
    knowledge_class,
    topic_desc_basic,
    knowledge_class_basic,
    tags,
    category,
    source_kind,
    catalog_id,
    catalog_version,
    content_hash,
    is_active,
    created_at,
    updated_at
   FROM candidates
  WHERE (effective_rank = 1);
-- end-statement
CREATE SEQUENCE public.worldknowledge_entry_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.worldknowledge_entry_id_seq OWNED BY public.core_worldknowledge_article.entry_id;
-- end-statement
CREATE TABLE public.core_action_intent (
    action_id text NOT NULL,
    idempotency_key text NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    turn_id text NOT NULL,
    generation bigint NOT NULL,
    action_name text NOT NULL,
    capability text NOT NULL,
    actor_identity_id bigint NOT NULL,
    target_identity_id bigint,
    arguments jsonb DEFAULT '{}'::jsonb NOT NULL,
    deadline_at timestamp with time zone NOT NULL,
    state text DEFAULT 'pending'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT action_intents_action_id_check CHECK ((action_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT action_intents_arguments_check CHECK ((jsonb_typeof(arguments) = 'object'::text)),
    CONSTRAINT action_intents_generation_check CHECK ((generation > 0)),
    CONSTRAINT action_intents_state_check CHECK ((state = ANY (ARRAY['pending'::text, 'dispatched'::text, 'complete'::text, 'cancelled'::text, 'stale'::text])))
);
-- end-statement
CREATE TABLE public.core_action_result (
    action_id text NOT NULL,
    idempotency_key text NOT NULL,
    status text NOT NULL,
    result jsonb NOT NULL,
    completed_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT action_terminal_results_status_check CHECK ((status = ANY (ARRAY['succeeded'::text, 'rejected'::text, 'unavailable'::text, 'timed_out'::text, 'cancelled'::text, 'failed'::text, 'unsupported_without_esp'::text, 'unsupported_runtime'::text])))
);
-- end-statement
CREATE TABLE public.core_actor_activation (
    session_id text NOT NULL,
    generation bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    source text NOT NULL,
    state text DEFAULT 'active'::text NOT NULL,
    activated_at timestamp with time zone DEFAULT now() NOT NULL,
    last_seen_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT active_agents_generation_check CHECK ((generation > 0)),
    CONSTRAINT active_agents_source_check CHECK ((source = ANY (ARRAY['manual'::text, 'automatic'::text]))),
    CONSTRAINT active_agents_state_check CHECK ((state = ANY (ARRAY['active'::text, 'inactive'::text, 'stale'::text])))
);
-- end-statement
CREATE TABLE public.core_actor_event_batch (
    session_id text NOT NULL,
    generation bigint NOT NULL,
    playthrough_id text NOT NULL,
    batch_id text NOT NULL,
    context_sequence bigint NOT NULL,
    request_id text NOT NULL,
    player_payload jsonb NOT NULL,
    input_payload jsonb NOT NULL,
    capture_age_ms bigint NOT NULL,
    recorded_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT actor_event_batches_batch_id_check CHECK ((batch_id ~ '^actor:[1-9][0-9]{0,15}$'::text)),
    CONSTRAINT actor_event_batches_capture_age_ms_check CHECK (((capture_age_ms >= 0) AND (capture_age_ms <= '9007199254740991'::bigint))),
    CONSTRAINT actor_event_batches_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT actor_event_batches_generation_check CHECK ((generation > 0)),
    CONSTRAINT actor_event_batches_input_payload_check CHECK ((jsonb_typeof(input_payload) = 'object'::text)),
    CONSTRAINT actor_event_batches_player_payload_check CHECK ((jsonb_typeof(player_payload) = 'object'::text))
);
-- end-statement
CREATE TABLE public.core_runtime_context (
    session_id text NOT NULL,
    generation bigint NOT NULL,
    snapshot_sequence bigint NOT NULL,
    playthrough_id text NOT NULL,
    player_identity_id bigint NOT NULL,
    target_identity_id bigint,
    audience_identity_ids jsonb DEFAULT '[]'::jsonb NOT NULL,
    context_payload jsonb NOT NULL,
    received_at timestamp with time zone DEFAULT now() NOT NULL,
    history_event_id bigint,
    CONSTRAINT context_snapshots_audience_identity_ids_check CHECK ((jsonb_typeof(audience_identity_ids) = 'array'::text)),
    CONSTRAINT context_snapshots_generation_check CHECK ((generation > 0)),
    CONSTRAINT context_snapshots_history_event_id_check CHECK ((history_event_id >= 0)),
    CONSTRAINT context_snapshots_snapshot_sequence_check CHECK ((snapshot_sequence > 0))
);
-- end-statement
CREATE TABLE public.core_dialogue_origin_policy (
    session_id text NOT NULL,
    request_id text NOT NULL,
    execution_mode text NOT NULL,
    policy jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    input_text text,
    CONSTRAINT dialogue_origin_policies_policy_check CHECK ((jsonb_typeof(policy) = 'object'::text))
);
-- end-statement
CREATE TABLE public.core_dialogue_utterance (
    session_id text NOT NULL,
    request_id text NOT NULL,
    line_id text NOT NULL,
    turn_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    speaker_identity_id bigint NOT NULL,
    character_id text NOT NULL,
    text text NOT NULL,
    text_sha256 character(64) NOT NULL,
    state text DEFAULT 'generated'::text NOT NULL,
    receipt_request_id text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    receipt_at timestamp with time zone,
    CONSTRAINT dialogue_utterances_character_id_check CHECK ((character_id ~ '^chr:[0-9a-f]{40}$'::text)),
    CONSTRAINT dialogue_utterances_check CHECK (((state = 'generated'::text) = (receipt_request_id IS NULL))),
    CONSTRAINT dialogue_utterances_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT dialogue_utterances_generation_check CHECK ((generation > 0)),
    CONSTRAINT dialogue_utterances_line_id_check CHECK (((length(line_id) >= 1) AND (length(line_id) <= 160))),
    CONSTRAINT dialogue_utterances_state_check CHECK ((state = ANY (ARRAY['generated'::text, 'spoken'::text, 'presented'::text, 'interrupted'::text, 'failed'::text]))),
    CONSTRAINT dialogue_utterances_text_check CHECK (((length(text) >= 1) AND (length(text) <= 4096))),
    CONSTRAINT dialogue_utterances_text_sha256_check CHECK ((text_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_diary_control_revision (
    history_event_id bigint NOT NULL,
    diary_id bigint NOT NULL,
    prior_revision_id bigint,
    control_payload jsonb NOT NULL,
    control_sha256 character(64) NOT NULL,
    CONSTRAINT diary_control_revisions_check CHECK ((diary_id < history_event_id)),
    CONSTRAINT diary_control_revisions_check1 CHECK (((prior_revision_id IS NULL) OR (prior_revision_id < history_event_id))),
    CONSTRAINT diary_control_revisions_control_payload_check CHECK (((jsonb_typeof(control_payload) = 'object'::text) AND (COALESCE((control_payload ->> 'schema'::text), ''::text) = ANY (ARRAY['synth.diary.control.v1'::text, 'synth.diary.control.v2'::text])))),
    CONSTRAINT diary_control_revisions_control_sha256_check CHECK ((control_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_diary_input_source (
    input_id bigint NOT NULL,
    history_event_id bigint NOT NULL,
    source_sha256 character(64) NOT NULL,
    CONSTRAINT diary_input_sources_source_sha256_check CHECK ((source_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_diary_input (
    id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    diary_role text NOT NULL,
    prior_history_event_id bigint,
    profile_revision_id bigint,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    CONSTRAINT diary_inputs_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT diary_inputs_diary_role_check CHECK ((diary_role = ANY (ARRAY['npc'::text, 'player'::text, 'narrator'::text]))),
    CONSTRAINT diary_inputs_generation_check CHECK ((generation > 0)),
    CONSTRAINT diary_inputs_input_payload_check CHECK (((jsonb_typeof(input_payload) = 'object'::text) AND (NOT ((input_payload ->> 'schema'::text) IS DISTINCT FROM 'synth.diary.input.v1'::text)))),
    CONSTRAINT diary_inputs_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE SEQUENCE public.diary_inputs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.diary_inputs_id_seq OWNED BY public.core_diary_input.id;
-- end-statement
CREATE TABLE public.core_diary_job_result (
    job_id bigint NOT NULL,
    input_sha256 character(64) NOT NULL,
    result_payload jsonb NOT NULL,
    result_sha256 character(64) NOT NULL,
    embedding public.vector(384),
    embedding_sha256 character(64),
    embedding_attempted boolean DEFAULT false NOT NULL,
    embedding_text_sha256 character(64),
    CONSTRAINT diary_job_results_embedding_text_sha256_check CHECK ((embedding_text_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT diary_job_results_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT diary_job_results_result_payload_check CHECK ((jsonb_typeof(result_payload) = 'object'::text)),
    CONSTRAINT diary_job_results_result_sha256_check CHECK ((result_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_diary_result (
    history_event_id bigint NOT NULL,
    input_id bigint NOT NULL,
    result_payload jsonb NOT NULL,
    result_sha256 character(64) NOT NULL,
    embedding public.vector(384),
    embedding_sha256 character(64),
    embedding_text_sha256 character(64),
    CONSTRAINT diary_results_embedding_text_sha256_check CHECK ((embedding_text_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT diary_results_result_payload_check CHECK ((jsonb_typeof(result_payload) = 'object'::text)),
    CONSTRAINT diary_results_result_sha256_check CHECK ((result_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE synth_meta.history_events (
    id bigint NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    request_id text,
    playthrough_id text NOT NULL,
    eventlog_rowid bigint NOT NULL,
    event_payload jsonb NOT NULL,
    recorded_at timestamp with time zone DEFAULT now() NOT NULL,
    scene_context_sequence bigint,
    CONSTRAINT history_events_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT history_events_event_payload_check CHECK ((jsonb_typeof(event_payload) = 'object'::text)),
    CONSTRAINT history_events_generation_check CHECK ((generation > 0)),
    CONSTRAINT history_events_scene_context_sequence_check CHECK ((scene_context_sequence > 0)),
    CONSTRAINT history_request_or_manual CHECK (((request_id IS NOT NULL) OR ((COALESCE((event_payload ->> 'type'::text), ''::text) = ANY (ARRAY['relationship_manual'::text, 'visual_manual'::text, 'diary_control'::text, 'middleterm_control'::text, 'middleterm_regeneration'::text])) AND (NOT ((event_payload ->> 'delivery_state'::text) IS DISTINCT FROM 'derived'::text)))))
);
-- end-statement
CREATE SEQUENCE synth_meta.history_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth_meta.history_events_id_seq OWNED BY synth_meta.history_events.id;
-- end-statement
CREATE TABLE public.core_media_object (
    media_id text NOT NULL,
    cache_key text,
    storage_key text NOT NULL,
    content_type text NOT NULL,
    byte_length bigint NOT NULL,
    sha256 character(64) NOT NULL,
    state text NOT NULL,
    request_id text,
    turn_id text,
    utterance_id text,
    generation bigint,
    speaker_identity_id bigint,
    failure_code text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    published_at timestamp with time zone,
    expires_at timestamp with time zone,
    CONSTRAINT media_objects_byte_length_check CHECK (((byte_length >= 1) AND (byte_length <= 16777216))),
    CONSTRAINT media_objects_content_type_check CHECK ((content_type = ANY (ARRAY['audio/wav'::text, 'audio/ogg'::text, 'image/jpeg'::text, 'image/png'::text, 'image/webp'::text]))),
    CONSTRAINT media_objects_generation_check CHECK ((generation > 0)),
    CONSTRAINT media_objects_media_id_check CHECK ((media_id ~ '^media/[0-9a-f]{64}$'::text)),
    CONSTRAINT media_objects_sha256_check CHECK ((sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT media_objects_state_check CHECK ((state = ANY (ARRAY['pending'::text, 'ready'::text, 'failed'::text, 'expired'::text])))
);
-- end-statement
CREATE TABLE public.core_memory_batch_control_event (
    batch_id bigint NOT NULL,
    revision bigint NOT NULL,
    archived boolean NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    action text,
    subject_batch_id bigint,
    CONSTRAINT memory_batch_control_events_action_check CHECK ((action = ANY (ARRAY['archive'::text, 'restore'::text, 'edit'::text, 'regenerate'::text]))),
    CONSTRAINT memory_batch_control_events_revision_check CHECK ((revision > 0))
);
-- end-statement
CREATE TABLE public.core_memory_batch_control (
    batch_id bigint NOT NULL,
    archived boolean NOT NULL,
    revision bigint NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    regenerate_batch_id bigint,
    CONSTRAINT memory_batch_controls_revision_check CHECK ((revision > 0))
);
-- end-statement
CREATE TABLE public.core_memory_batch_rebuild (
    batch_id bigint NOT NULL,
    root_batch_id bigint NOT NULL,
    CONSTRAINT memory_batch_rebuilds_check CHECK ((batch_id > root_batch_id))
);
-- end-statement
CREATE TABLE public.core_memory_batch_source (
    batch_id bigint NOT NULL,
    history_event_id bigint NOT NULL,
    "position" integer NOT NULL,
    CONSTRAINT memory_batch_sources_position_check CHECK ((("position" >= 1) AND ("position" <= 150)))
);
-- end-statement
CREATE TABLE public.core_memory_batch_summary_source (
    batch_id bigint NOT NULL,
    summary_id bigint NOT NULL,
    summary_revision bigint DEFAULT 0 NOT NULL,
    CONSTRAINT memory_batch_summary_sources_summary_revision_check CHECK ((summary_revision >= 0))
);
-- end-statement
CREATE TABLE public.core_memory_batch (
    id bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    playthrough_id text NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    source_hash character(64) NOT NULL,
    input_payload jsonb NOT NULL,
    packed_message text NOT NULL,
    first_gamets bigint NOT NULL,
    last_gamets bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT memory_batches_check CHECK ((last_gamets >= first_gamets)),
    CONSTRAINT memory_batches_first_gamets_check CHECK ((first_gamets > 0)),
    CONSTRAINT memory_batches_input_payload_check CHECK ((jsonb_typeof(input_payload) = 'object'::text)),
    CONSTRAINT memory_batches_packed_message_check CHECK (((octet_length(packed_message) >= 1) AND (octet_length(packed_message) <= 65536))),
    CONSTRAINT memory_batches_source_hash_check CHECK ((source_hash ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE SEQUENCE public.memory_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.memory_batches_id_seq OWNED BY public.core_memory_batch.id;
-- end-statement
CREATE TABLE public.core_memory_summary (
    id bigint NOT NULL,
    batch_id bigint NOT NULL,
    job_id bigint NOT NULL,
    summary text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    embedding public.vector(384),
    native_vec tsvector GENERATED ALWAYS AS ((setweight(to_tsvector('english'::regconfig, COALESCE("substring"(summary, '#Tags:([\s\S]*)$'::text), ''::text)), 'A'::"char") || setweight(to_tsvector('english'::regconfig, summary), 'B'::"char"))) STORED,
    CONSTRAINT memory_summaries_summary_check CHECK (((octet_length(summary) >= 1) AND (octet_length(summary) <= 65536)))
);
-- end-statement
CREATE SEQUENCE public.memory_summaries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.memory_summaries_id_seq OWNED BY public.core_memory_summary.id;
-- end-statement
CREATE TABLE public.core_memory_summary_revision (
    summary_id bigint NOT NULL,
    revision bigint NOT NULL,
    summary text NOT NULL,
    native_vec tsvector GENERATED ALWAYS AS ((setweight(to_tsvector('english'::regconfig, COALESCE("substring"(summary, '#Tags:([\s\S]*)$'::text), ''::text)), 'A'::"char") || setweight(to_tsvector('english'::regconfig, summary), 'B'::"char"))) STORED,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT memory_summary_revisions_revision_check CHECK ((revision > 0)),
    CONSTRAINT memory_summary_revisions_summary_check CHECK (((octet_length(summary) >= 1) AND (octet_length(summary) <= 65536)))
);
-- end-statement
CREATE TABLE public.core_middleterm_control_revision (
    history_event_id bigint NOT NULL,
    narrative_id bigint NOT NULL,
    prior_revision_id bigint,
    control_payload jsonb NOT NULL,
    control_sha256 character(64) NOT NULL,
    CONSTRAINT middleterm_control_revisions_check CHECK ((narrative_id < history_event_id)),
    CONSTRAINT middleterm_control_revisions_check1 CHECK (((prior_revision_id IS NULL) OR (prior_revision_id < history_event_id))),
    CONSTRAINT middleterm_control_revisions_control_payload_check CHECK (((jsonb_typeof(control_payload) = 'object'::text) AND (COALESCE((control_payload ->> 'schema'::text), ''::text) = ANY (ARRAY['synth.middleterm.control.v1'::text, 'synth.middleterm.control.v2'::text])))),
    CONSTRAINT middleterm_control_revisions_control_sha256_check CHECK ((control_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_middleterm_input (
    id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    playthrough_id text NOT NULL,
    prior_history_event_id bigint,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    regeneration_approval_id bigint,
    CONSTRAINT middleterm_inputs_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT middleterm_inputs_generation_check CHECK ((generation > 0)),
    CONSTRAINT middleterm_inputs_input_payload_check CHECK (((jsonb_typeof(input_payload) = 'object'::text) AND (octet_length((input_payload)::text) <= 1048576))),
    CONSTRAINT middleterm_inputs_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE SEQUENCE public.middleterm_inputs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.middleterm_inputs_id_seq OWNED BY public.core_middleterm_input.id;
-- end-statement
CREATE TABLE public.core_middleterm_regeneration_approval (
    history_event_id bigint NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    playthrough_id text NOT NULL,
    narrative_id bigint NOT NULL,
    approval_token character(32) NOT NULL,
    approval_payload jsonb NOT NULL,
    approval_sha256 character(64) NOT NULL,
    last_considered_at timestamp with time zone,
    CONSTRAINT middleterm_regeneration_approvals_approval_payload_check CHECK (((jsonb_typeof(approval_payload) = 'object'::text) AND (COALESCE((approval_payload ->> 'schema'::text), ''::text) = 'synth.middleterm.regeneration.v1'::text) AND (octet_length((approval_payload)::text) <= 1048576))),
    CONSTRAINT middleterm_regeneration_approvals_approval_sha256_check CHECK ((approval_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT middleterm_regeneration_approvals_approval_token_check CHECK ((approval_token ~ '^[0-9a-f]{32}$'::text)),
    CONSTRAINT middleterm_regeneration_approvals_check CHECK ((narrative_id < history_event_id)),
    CONSTRAINT middleterm_regeneration_approvals_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT middleterm_regeneration_approvals_generation_check CHECK ((generation > 0))
);
-- end-statement
CREATE TABLE public.core_middleterm_replacement (
    history_event_id bigint NOT NULL,
    approval_id bigint NOT NULL,
    target_history_event_id bigint NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    CONSTRAINT middleterm_replacements_check CHECK (((history_event_id > target_history_event_id) AND (history_event_id > approval_id))),
    CONSTRAINT middleterm_replacements_generation_check CHECK ((generation > 0))
);
-- end-statement
CREATE TABLE public.core_middleterm_result (
    history_event_id bigint NOT NULL,
    input_id bigint NOT NULL,
    narrative text NOT NULL,
    result_sha256 character(64) NOT NULL,
    CONSTRAINT middleterm_results_narrative_check CHECK (((octet_length(narrative) >= 1) AND (octet_length(narrative) <= 65536))),
    CONSTRAINT middleterm_results_result_sha256_check CHECK ((result_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_middleterm_source (
    input_id bigint NOT NULL,
    "position" integer NOT NULL,
    summary_id bigint NOT NULL,
    summary_revision bigint NOT NULL,
    source_sha256 character(64) NOT NULL,
    CONSTRAINT middleterm_sources_position_check CHECK ((("position" >= 0) AND ("position" <= 99))),
    CONSTRAINT middleterm_sources_source_sha256_check CHECK ((source_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT middleterm_sources_summary_revision_check CHECK ((summary_revision >= 0))
);
-- end-statement
CREATE TABLE public.core_narrator_context_state (
    session_id text NOT NULL,
    generation bigint NOT NULL,
    snapshot_sequence bigint NOT NULL,
    playthrough_id text NOT NULL,
    state_id bigint
);
-- end-statement
CREATE TABLE public.core_narrator_definition_revision (
    revision_id bigint NOT NULL,
    definition_id text NOT NULL,
    prior_revision_id bigint,
    name text NOT NULL,
    personality text NOT NULL,
    background text NOT NULL,
    style text NOT NULL,
    voice text NOT NULL,
    profile_id bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT narrator_definition_revisions_background_check CHECK ((char_length(background) <= 4000)),
    CONSTRAINT narrator_definition_revisions_check CHECK (((prior_revision_id IS NULL) OR (prior_revision_id < revision_id))),
    CONSTRAINT narrator_definition_revisions_name_check CHECK (((char_length(btrim(name)) >= 1) AND (char_length(btrim(name)) <= 64))),
    CONSTRAINT narrator_definition_revisions_personality_check CHECK ((char_length(personality) <= 4000)),
    CONSTRAINT narrator_definition_revisions_profile_id_check CHECK (((profile_id IS NULL) OR (profile_id > 0))),
    CONSTRAINT narrator_definition_revisions_style_check CHECK ((char_length(style) <= 2000)),
    CONSTRAINT narrator_definition_revisions_voice_check CHECK ((char_length(voice) <= 128))
);
-- end-statement
CREATE SEQUENCE public.narrator_definition_revisions_revision_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.narrator_definition_revisions_revision_id_seq OWNED BY public.core_narrator_definition_revision.revision_id;
-- end-statement
CREATE TABLE public.core_narrator_definition (
    definition_id text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    retired_at timestamp with time zone,
    edit_generation bigint DEFAULT 0 NOT NULL,
    CONSTRAINT narrator_definitions_definition_id_check CHECK ((definition_id ~ '^nd-[0-9a-f]{32}$'::text))
);
-- end-statement
CREATE TABLE public.core_narrator_lane_head (
    session_id text NOT NULL,
    generation bigint NOT NULL,
    playthrough_id text NOT NULL,
    state_id bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT narrator_lane_heads_generation_check CHECK ((generation > 0))
);
-- end-statement
CREATE TABLE public.core_narrator_pending_output (
    session_id text NOT NULL,
    request_id text NOT NULL,
    generation bigint NOT NULL,
    turn_id text NOT NULL,
    context_sequence bigint NOT NULL,
    playthrough_id text NOT NULL,
    line_id text NOT NULL,
    definition_id text NOT NULL,
    revision_id bigint NOT NULL,
    parent_state_id bigint NOT NULL,
    definition_edit_generation bigint DEFAULT 0 NOT NULL,
    input_entry_id bigint NOT NULL,
    text text,
    state text DEFAULT 'pending'::text NOT NULL,
    receipt_request_id text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT narrator_pending_outputs_check CHECK (((state <> ALL (ARRAY['generated'::text, 'spoken'::text])) OR (text IS NOT NULL))),
    CONSTRAINT narrator_pending_outputs_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT narrator_pending_outputs_generation_check CHECK ((generation > 0)),
    CONSTRAINT narrator_pending_outputs_state_check CHECK ((state = ANY (ARRAY['pending'::text, 'generated'::text, 'spoken'::text, 'interrupted'::text, 'failed'::text, 'cancelled'::text]))),
    CONSTRAINT narrator_pending_outputs_text_check CHECK (((text IS NULL) OR ((char_length(text) >= 1) AND (char_length(text) <= 1000))))
);
-- end-statement
CREATE TABLE public.core_narrator_state (
    state_id bigint NOT NULL,
    parent_state_id bigint,
    playthrough_id text NOT NULL,
    applied_revisions jsonb DEFAULT '{}'::jsonb NOT NULL,
    persona_heads jsonb DEFAULT '{}'::jsonb NOT NULL,
    selected_definition_id text,
    transcript_head_entry_id bigint,
    cause text NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    request_id text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT narrator_states_applied_revisions_check CHECK ((jsonb_typeof(applied_revisions) = 'object'::text)),
    CONSTRAINT narrator_states_cause_check CHECK ((cause = ANY (ARRAY['select'::text, 'input'::text, 'reply'::text]))),
    CONSTRAINT narrator_states_check CHECK (((parent_state_id IS NULL) OR (parent_state_id < state_id))),
    CONSTRAINT narrator_states_check1 CHECK (((selected_definition_id IS NULL) OR (applied_revisions ? selected_definition_id))),
    CONSTRAINT narrator_states_generation_check CHECK ((generation > 0)),
    CONSTRAINT narrator_states_persona_heads_check CHECK ((jsonb_typeof(persona_heads) = 'object'::text)),
    CONSTRAINT narrator_states_playthrough_id_check CHECK ((playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text))
);
-- end-statement
CREATE SEQUENCE public.narrator_states_state_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.narrator_states_state_id_seq OWNED BY public.core_narrator_state.state_id;
-- end-statement
CREATE TABLE public.core_narrator_transcript_cursor (
    cursor_id text NOT NULL,
    state_id bigint NOT NULL,
    next_entry_id bigint NOT NULL,
    CONSTRAINT narrator_transcript_cursors_cursor_id_check CHECK ((cursor_id ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_narrator_transcript_entry (
    entry_id bigint NOT NULL,
    parent_entry_id bigint,
    parent_persona_entry_id bigint,
    playthrough_id text NOT NULL,
    role text NOT NULL,
    kind text NOT NULL,
    definition_id text NOT NULL,
    revision_id bigint NOT NULL,
    speaker_name text NOT NULL,
    text text NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    request_id text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT narrator_transcript_entries_check CHECK (((parent_entry_id IS NULL) OR (parent_entry_id < entry_id))),
    CONSTRAINT narrator_transcript_entries_check1 CHECK (((parent_persona_entry_id IS NULL) OR (parent_persona_entry_id < entry_id))),
    CONSTRAINT narrator_transcript_entries_generation_check CHECK ((generation > 0)),
    CONSTRAINT narrator_transcript_entries_kind_check CHECK ((kind = ANY (ARRAY['typed'::text, 'voice'::text, 'scene'::text, 'reply'::text]))),
    CONSTRAINT narrator_transcript_entries_playthrough_id_check CHECK ((playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT narrator_transcript_entries_role_check CHECK ((role = ANY (ARRAY['player'::text, 'narrator'::text]))),
    CONSTRAINT narrator_transcript_entries_speaker_name_check CHECK (((char_length(speaker_name) >= 1) AND (char_length(speaker_name) <= 64))),
    CONSTRAINT narrator_transcript_entries_text_check CHECK (((char_length(text) >= 1) AND (char_length(text) <= 4096)))
);
-- end-statement
CREATE SEQUENCE public.narrator_transcript_entries_entry_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.narrator_transcript_entries_entry_id_seq OWNED BY public.core_narrator_transcript_entry.entry_id;
-- end-statement
CREATE TABLE synth_meta.playthrough_profiles (
    id integer NOT NULL,
    name text NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    size_bytes bigint NOT NULL,
    notes text,
    is_active boolean DEFAULT false NOT NULL,
    player_name text,
    game text,
    eventlog_count bigint,
    worldknowledge_count bigint,
    last_gamets bigint,
    schema_name text,
    storage_type text DEFAULT 'schema'::text
);
-- end-statement
CREATE SEQUENCE synth_meta.playthrough_profiles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE synth_meta.playthrough_profiles_id_seq OWNED BY synth_meta.playthrough_profiles.id;
-- end-statement
CREATE TABLE public.core_character_generation_field (
    job_id bigint NOT NULL,
    field text NOT NULL,
    result_text text NOT NULL,
    result_sha256 character(64) NOT NULL,
    CONSTRAINT profile_job_fields_field_check CHECK ((field = ANY (ARRAY['personality'::text, 'occupation'::text, 'skills'::text, 'speechstyle'::text, 'goals'::text]))),
    CONSTRAINT profile_job_fields_result_sha256_check CHECK ((result_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT profile_job_fields_result_text_check CHECK (((octet_length(result_text) >= 1) AND (octet_length(result_text) <= 16384)))
);
-- end-statement
CREATE TABLE public.core_character_generation (
    job_id bigint NOT NULL,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    CONSTRAINT profile_job_inputs_input_payload_check CHECK (((jsonb_typeof(input_payload) = 'object'::text) AND (NOT ((input_payload ->> 'schema'::text) IS DISTINCT FROM 'synth.profile.input.v1'::text)))),
    CONSTRAINT profile_job_inputs_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_character_generation_revision (
    history_event_id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    profile_role text NOT NULL,
    prior_history_event_id bigint,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    result_fields jsonb NOT NULL,
    result_sha256 character(64) NOT NULL,
    CONSTRAINT profile_revisions_check CHECK (((prior_history_event_id IS NULL) OR (prior_history_event_id < history_event_id))),
    CONSTRAINT profile_revisions_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT profile_revisions_generation_check CHECK ((generation > 0)),
    CONSTRAINT profile_revisions_input_payload_check CHECK (((jsonb_typeof(input_payload) = 'object'::text) AND (NOT ((input_payload ->> 'schema'::text) IS DISTINCT FROM 'synth.profile.input.v1'::text)))),
    CONSTRAINT profile_revisions_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT profile_revisions_profile_role_check CHECK ((profile_role = ANY (ARRAY['npc'::text, 'narrator'::text]))),
    CONSTRAINT profile_revisions_result_fields_check CHECK ((jsonb_typeof(result_fields) = 'object'::text)),
    CONSTRAINT profile_revisions_result_sha256_check CHECK ((result_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_protocol_event (
    id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    turn_id text NOT NULL,
    generation bigint NOT NULL,
    event_type text NOT NULL,
    canonical_event jsonb NOT NULL,
    event_sha256 character(64) NOT NULL,
    received_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT protocol_events_event_sha256_check CHECK ((event_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE SEQUENCE public.protocol_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.protocol_events_id_seq OWNED BY public.core_protocol_event.id;
-- end-statement
CREATE TABLE public.core_rechat_chain (
    id bigint NOT NULL,
    session_id text NOT NULL,
    root_request_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    turn_id text NOT NULL,
    runtime_variant text NOT NULL,
    mode text NOT NULL,
    policy jsonb NOT NULL,
    budget bigint NOT NULL,
    used_rounds bigint DEFAULT 0 NOT NULL,
    terminal_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT rechat_chains_budget_check CHECK ((budget >= 0)),
    CONSTRAINT rechat_chains_check CHECK (((used_rounds >= 0) AND (used_rounds <= budget))),
    CONSTRAINT rechat_chains_mode_check CHECK ((mode = ANY (ARRAY['tight'::text, 'conversational'::text, 'group'::text]))),
    CONSTRAINT rechat_chains_policy_check CHECK ((jsonb_typeof(policy) = 'object'::text)),
    CONSTRAINT rechat_chains_runtime_variant_check CHECK ((runtime_variant = ANY (ARRAY['flat'::text, 'vr'::text]))),
    CONSTRAINT rechat_chains_terminal_reason_check CHECK ((terminal_reason = ANY (ARRAY['budget_exhausted'::text, 'private'::text, 'no_candidate'::text, 'ineligible'::text])))
);
-- end-statement
CREATE SEQUENCE public.rechat_chains_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.rechat_chains_id_seq OWNED BY public.core_rechat_chain.id;
-- end-statement
CREATE TABLE public.core_rechat_continuation (
    session_id text NOT NULL,
    request_id text NOT NULL,
    chain_id bigint NOT NULL,
    parent_request_id text NOT NULL,
    parent_line_id text NOT NULL,
    parent_response_sha256 character(64) NOT NULL,
    round_number bigint,
    status text NOT NULL,
    terminal_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT rechat_continuations_check CHECK ((((status = 'reserved'::text) AND (round_number IS NOT NULL) AND (round_number > 0) AND (terminal_reason IS NULL)) OR ((status = 'terminal'::text) AND (round_number IS NULL) AND (terminal_reason IS NOT NULL)))),
    CONSTRAINT rechat_continuations_parent_response_sha256_check CHECK ((parent_response_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT rechat_continuations_status_check CHECK ((status = ANY (ARRAY['reserved'::text, 'terminal'::text])))
);
-- end-statement
CREATE TABLE public.core_rechat_selection (
    session_id text NOT NULL,
    request_id text NOT NULL,
    role text NOT NULL,
    actor_identity_id bigint,
    npc_id integer,
    profile_id integer,
    identity jsonb,
    terminal_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    prepared_at timestamp with time zone,
    CONSTRAINT rechat_selections_check CHECK ((((role = 'npc'::text) AND (actor_identity_id IS NOT NULL) AND (npc_id IS NOT NULL) AND (profile_id IS NOT NULL) AND (identity IS NOT NULL) AND (jsonb_typeof(identity) = 'object'::text) AND (terminal_reason IS NULL)) OR ((role = 'narrator'::text) AND (actor_identity_id IS NULL) AND (npc_id IS NULL) AND (profile_id IS NOT NULL) AND (identity IS NULL) AND (terminal_reason IS NULL)) OR ((role = 'terminal'::text) AND (actor_identity_id IS NULL) AND (npc_id IS NULL) AND (profile_id IS NULL) AND (identity IS NULL) AND (terminal_reason IS NOT NULL)))),
    CONSTRAINT rechat_selections_npc_id_check CHECK ((npc_id > 0)),
    CONSTRAINT rechat_selections_profile_id_check CHECK ((profile_id > 0)),
    CONSTRAINT rechat_selections_role_check CHECK ((role = ANY (ARRAY['npc'::text, 'narrator'::text, 'terminal'::text]))),
    CONSTRAINT rechat_selections_terminal_reason_check CHECK ((terminal_reason = ANY (ARRAY['no_candidate'::text, 'ineligible'::text, 'private'::text, 'budget_exhausted'::text])))
);
-- end-statement
CREATE TABLE public.core_relationship_actor_control_event (
    actor_identity_id bigint NOT NULL,
    revision bigint NOT NULL,
    locked boolean NOT NULL,
    barrier_event_id bigint NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    legacy_profile_id bigint,
    CONSTRAINT relationship_actor_control_events_barrier_event_id_check CHECK ((barrier_event_id > 0)),
    CONSTRAINT relationship_actor_control_events_legacy_profile_id_check CHECK ((legacy_profile_id > 0)),
    CONSTRAINT relationship_actor_control_events_revision_check CHECK ((revision > 0))
);
-- end-statement
CREATE TABLE public.core_relationship_actor_control (
    actor_identity_id bigint NOT NULL,
    locked boolean NOT NULL,
    revision bigint NOT NULL,
    barrier_event_id bigint NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT relationship_actor_controls_barrier_event_id_check CHECK ((barrier_event_id > 0)),
    CONSTRAINT relationship_actor_controls_revision_check CHECK ((revision > 0))
);
-- end-statement
CREATE TABLE public.core_relationship_evaluation_source (
    evaluation_id bigint NOT NULL,
    history_event_id bigint NOT NULL
);
-- end-statement
CREATE TABLE public.core_relationship_evaluation (
    id bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    response_history_event_id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT relationship_evaluations_input_payload_check CHECK ((jsonb_typeof(input_payload) = 'object'::text)),
    CONSTRAINT relationship_evaluations_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE SEQUENCE public.relationship_evaluations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.relationship_evaluations_id_seq OWNED BY public.core_relationship_evaluation.id;
-- end-statement
CREATE TABLE public.core_relationship_interaction_evaluation (
    id bigint NOT NULL,
    speaker_identity_id bigint NOT NULL,
    listener_identity_id bigint NOT NULL,
    response_history_event_id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT relationship_interaction_evaluations_check CHECK ((speaker_identity_id <> listener_identity_id)),
    CONSTRAINT relationship_interaction_evaluations_input_payload_check CHECK ((jsonb_typeof(input_payload) = 'object'::text)),
    CONSTRAINT relationship_interaction_evaluations_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE SEQUENCE public.relationship_interaction_evaluations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.relationship_interaction_evaluations_id_seq OWNED BY public.core_relationship_interaction_evaluation.id;
-- end-statement
CREATE TABLE public.core_relationship_interaction_prior (
    interaction_id bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    prior_history_event_id bigint NOT NULL
);
-- end-statement
CREATE TABLE public.core_relationship_interaction_result_prior (
    result_history_event_id bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    prior_history_event_id bigint NOT NULL,
    CONSTRAINT relationship_interaction_result_priors_check CHECK ((prior_history_event_id < result_history_event_id))
);
-- end-statement
CREATE TABLE public.core_relationship_interaction_result (
    history_event_id bigint NOT NULL,
    interaction_id bigint NOT NULL,
    job_id bigint NOT NULL,
    result_payload jsonb NOT NULL,
    CONSTRAINT relationship_interaction_results_result_payload_check CHECK ((jsonb_typeof(result_payload) = 'object'::text))
);
-- end-statement
CREATE TABLE public.core_relationship_response_input (
    history_event_id bigint NOT NULL,
    session_id text NOT NULL,
    request_id text NOT NULL,
    actor_identity_id bigint NOT NULL,
    response_sha256 character(64) NOT NULL,
    response_payload jsonb NOT NULL,
    CONSTRAINT relationship_response_inputs_response_payload_check CHECK ((jsonb_typeof(response_payload) = 'object'::text)),
    CONSTRAINT relationship_response_inputs_response_sha256_check CHECK ((response_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_relationship_response_source (
    response_history_event_id bigint NOT NULL,
    source_history_event_id bigint NOT NULL,
    CONSTRAINT relationship_response_sources_check CHECK ((source_history_event_id < response_history_event_id))
);
-- end-statement
CREATE TABLE public.core_relationship_result_prior (
    result_history_event_id bigint NOT NULL,
    prior_history_event_id bigint NOT NULL,
    CONSTRAINT relationship_result_priors_check CHECK ((prior_history_event_id < result_history_event_id))
);
-- end-statement
CREATE TABLE public.core_relationship_result (
    history_event_id bigint NOT NULL,
    evaluation_id bigint NOT NULL,
    job_id bigint NOT NULL,
    result_payload jsonb NOT NULL,
    CONSTRAINT relationship_results_result_payload_check CHECK ((jsonb_typeof(result_payload) = 'object'::text))
);
-- end-statement
CREATE TABLE public.core_relationship_revision_source (
    relationship_history_event_id bigint NOT NULL,
    source_history_event_id bigint NOT NULL,
    CONSTRAINT relationship_revision_sources_check CHECK ((source_history_event_id < relationship_history_event_id))
);
-- end-statement
CREATE TABLE public.core_relationship_revision (
    history_event_id bigint NOT NULL,
    actor_identity_id bigint NOT NULL,
    subject_identity_id bigint NOT NULL,
    prior_history_event_id bigint,
    affinity integer,
    relationship_type text,
    input_payload jsonb NOT NULL,
    input_hash character(64) NOT NULL,
    result_history_event_id bigint,
    details jsonb DEFAULT '{}'::jsonb NOT NULL,
    interaction_result_history_event_id bigint,
    CONSTRAINT relationship_known_or_reset CHECK ((((affinity IS NOT NULL) AND (relationship_type IS NOT NULL)) OR ((affinity IS NULL) AND (relationship_type IS NULL) AND (details = '{}'::jsonb) AND (prior_history_event_id IS NULL) AND (result_history_event_id IS NULL) AND (interaction_result_history_event_id IS NULL) AND (NOT ((input_payload ->> 'schema'::text) IS DISTINCT FROM 'synth.relationship.manual.v2'::text)) AND (NOT ((input_payload -> 'state'::text) IS DISTINCT FROM 'null'::jsonb))))),
    CONSTRAINT relationship_revisions_affinity_check CHECK (((affinity >= '-100'::integer) AND (affinity <= 100))),
    CONSTRAINT relationship_revisions_check CHECK ((actor_identity_id <> subject_identity_id)),
    CONSTRAINT relationship_revisions_check1 CHECK ((prior_history_event_id < history_event_id)),
    CONSTRAINT relationship_revisions_check2 CHECK (((interaction_result_history_event_id IS NULL) OR (result_history_event_id IS NULL))),
    CONSTRAINT relationship_revisions_details_check CHECK ((jsonb_typeof(details) = 'object'::text)),
    CONSTRAINT relationship_revisions_input_hash_check CHECK ((input_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT relationship_revisions_input_payload_check CHECK ((jsonb_typeof(input_payload) = 'object'::text)),
    CONSTRAINT relationship_revisions_relationship_type_check CHECK ((relationship_type ~ '^[a-z][a-z0-9_-]{0,49}$'::text))
);
-- end-statement
CREATE TABLE synth_meta.request_executions (
    session_id text NOT NULL,
    request_id text NOT NULL,
    turn_id text NOT NULL,
    generation bigint NOT NULL,
    request_sha256 character(64) NOT NULL,
    event_type text NOT NULL,
    state text DEFAULT 'claimed'::text NOT NULL,
    response_content_type text,
    response_body bytea,
    error_code text,
    claimed_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone,
    CONSTRAINT request_executions_generation_check CHECK ((generation > 0)),
    CONSTRAINT request_executions_request_id_check CHECK ((request_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT request_executions_request_sha256_check CHECK ((request_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT request_executions_state_check CHECK ((state = ANY (ARRAY['claimed'::text, 'streaming'::text, 'complete'::text, 'cancelled'::text, 'failed'::text]))),
    CONSTRAINT request_executions_turn_id_check CHECK ((turn_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text))
);
-- end-statement
CREATE TABLE public.core_dialogue_route (
    session_id text NOT NULL,
    request_id text NOT NULL,
    line_id text NOT NULL,
    line_sha256 character(64) NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    speaker jsonb NOT NULL,
    listener_state text NOT NULL,
    listener jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    utterance_id text,
    CONSTRAINT response_routes_check CHECK ((((listener_state = 'addressed'::text) AND (listener IS NOT NULL) AND (jsonb_typeof(listener) = 'object'::text)) OR ((listener_state <> 'addressed'::text) AND (listener IS NULL)))),
    CONSTRAINT response_routes_line_id_check CHECK (((length(line_id) >= 1) AND (length(line_id) <= 160))),
    CONSTRAINT response_routes_line_sha256_check CHECK ((line_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT response_routes_listener_state_check CHECK ((listener_state = ANY (ARRAY['addressed'::text, 'unavailable'::text, 'ambiguous'::text]))),
    CONSTRAINT response_routes_speaker_check CHECK ((jsonb_typeof(speaker) = 'object'::text)),
    CONSTRAINT response_routes_utterance_id_check CHECK ((utterance_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text))
);
-- end-statement
CREATE TABLE public.core_runtime_session (
    session_id text NOT NULL,
    playthrough_id text NOT NULL,
    game text NOT NULL,
    runtime_variant text NOT NULL,
    client_version text NOT NULL,
    runtime_version text NOT NULL,
    active_generation bigint NOT NULL,
    capabilities jsonb DEFAULT '[]'::jsonb NOT NULL,
    state text DEFAULT 'active'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    active_turn_id text,
    active_turn_sequence bigint DEFAULT 0 NOT NULL,
    memory_dispatch_after timestamp with time zone,
    relationship_dispatch_after timestamp with time zone,
    interaction_dispatch_after timestamp with time zone,
    middleterm_dispatch_after timestamp with time zone,
    audience_intent text DEFAULT 'normal'::text NOT NULL,
    audience_reset_epoch bigint DEFAULT 0 NOT NULL,
    CONSTRAINT runtime_sessions_active_generation_check CHECK ((active_generation > 0)),
    CONSTRAINT runtime_sessions_active_turn_sequence_check CHECK ((active_turn_sequence >= 0)),
    CONSTRAINT runtime_sessions_audience_intent_check CHECK ((audience_intent = ANY (ARRAY['normal'::text, 'everyone'::text]))),
    CONSTRAINT runtime_sessions_audience_reset_epoch_check CHECK ((audience_reset_epoch >= 0)),
    CONSTRAINT runtime_sessions_capabilities_check CHECK ((jsonb_typeof(capabilities) = 'array'::text)),
    CONSTRAINT runtime_sessions_game_check CHECK ((game = 'fo4'::text)),
    CONSTRAINT runtime_sessions_playthrough_id_check CHECK ((playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT runtime_sessions_runtime_variant_check CHECK ((runtime_variant = ANY (ARRAY['flat'::text, 'vr'::text]))),
    CONSTRAINT runtime_sessions_session_id_check CHECK ((session_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT runtime_sessions_state_check CHECK ((state = ANY (ARRAY['active'::text, 'halted'::text, 'expired'::text])))
);
-- end-statement
CREATE TABLE public.core_config_setting (
    scope text NOT NULL,
    config_key text NOT NULL,
    config_value text,
    description text DEFAULT ''::text NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT core_config_setting_pkey PRIMARY KEY (scope, config_key)
);
-- end-statement
CREATE TABLE public.core_social_task_claim (
    task_id text NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    execution_id text NOT NULL,
    deadline_at timestamp with time zone NOT NULL,
    CONSTRAINT social_task_claims_execution_id_check CHECK ((execution_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT social_task_claims_generation_check CHECK ((generation > 0))
);
-- end-statement
CREATE TABLE public.core_social_task_event (
    history_event_id bigint NOT NULL,
    task_id text NOT NULL,
    prior_revision bigint,
    status text NOT NULL,
    execution_id text,
    event_payload jsonb NOT NULL,
    event_sha256 character(64) NOT NULL,
    CONSTRAINT social_task_events_check CHECK (((prior_revision IS NULL) OR (prior_revision < history_event_id))),
    CONSTRAINT social_task_events_event_payload_check CHECK ((jsonb_typeof(event_payload) = 'object'::text)),
    CONSTRAINT social_task_events_event_sha256_check CHECK ((event_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT social_task_events_execution_id_check CHECK ((execution_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT social_task_events_status_check CHECK ((status = ANY (ARRAY['waiting_departure'::text, 'waiting_return'::text, 'ready'::text, 'travelling'::text, 'arrived'::text, 'conversing'::text, 'completed'::text, 'cancelled'::text, 'failed'::text])))
);
-- end-statement
CREATE TABLE public.core_social_task_input (
    task_id text NOT NULL,
    history_event_id bigint NOT NULL,
    player_identity_id bigint NOT NULL,
    performer_identity_id bigint NOT NULL,
    recipient_identity_id bigint NOT NULL,
    input_payload jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    CONSTRAINT social_task_inputs_check CHECK ((performer_identity_id <> recipient_identity_id)),
    CONSTRAINT social_task_inputs_check1 CHECK ((player_identity_id <> performer_identity_id)),
    CONSTRAINT social_task_inputs_check2 CHECK ((player_identity_id <> recipient_identity_id)),
    CONSTRAINT social_task_inputs_input_payload_check CHECK ((jsonb_typeof(input_payload) = 'object'::text)),
    CONSTRAINT social_task_inputs_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT social_task_inputs_task_id_check CHECK ((task_id ~ '^social:[0-9a-f]{32}$'::text))
);
-- end-statement
CREATE TABLE public.core_visual_description_revision (
    history_event_id bigint NOT NULL,
    capture_id text NOT NULL,
    actor_identity_id bigint NOT NULL,
    description text,
    input_payload jsonb NOT NULL,
    input_hash character(64) NOT NULL,
    CONSTRAINT visual_description_revisions_description_check CHECK (((description IS NULL) OR ((octet_length(description) <= 12000) AND (btrim(description) <> ''::text)))),
    CONSTRAINT visual_description_revisions_input_hash_check CHECK ((input_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT visual_description_revisions_input_payload_check CHECK (((jsonb_typeof(input_payload) = 'object'::text) AND (NOT ((input_payload ->> 'schema'::text) IS DISTINCT FROM 'synth.visual.manual.v1'::text))))
);
-- end-statement
CREATE TABLE public.core_visual_result (
    history_event_id bigint NOT NULL,
    capture_id text NOT NULL,
    session_id text NOT NULL,
    generation bigint NOT NULL,
    context_sequence bigint NOT NULL,
    request_id text NOT NULL,
    actor_identity_id bigint NOT NULL,
    result_payload jsonb NOT NULL,
    result_sha256 character(64) NOT NULL,
    CONSTRAINT visual_results_capture_id_check CHECK ((capture_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'::text)),
    CONSTRAINT visual_results_context_sequence_check CHECK ((context_sequence > 0)),
    CONSTRAINT visual_results_generation_check CHECK ((generation > 0)),
    CONSTRAINT visual_results_result_payload_check CHECK (((jsonb_typeof(result_payload) = 'object'::text) AND (NOT ((result_payload ->> 'schema'::text) IS DISTINCT FROM 'synth.visual.result.v1'::text)))),
    CONSTRAINT visual_results_result_sha256_check CHECK ((result_sha256 ~ '^[0-9a-f]{64}$'::text))
);
-- end-statement
CREATE TABLE public.core_worker_job (
    id bigint NOT NULL,
    job_type text NOT NULL,
    request_id text NOT NULL,
    turn_id text NOT NULL,
    utterance_id text,
    generation bigint NOT NULL,
    actor_identity_id bigint,
    cache_key text,
    payload jsonb NOT NULL,
    state text DEFAULT 'pending'::text NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    available_at timestamp with time zone DEFAULT now() NOT NULL,
    deadline_at timestamp with time zone,
    last_error text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    session_id text,
    memory_batch_id bigint,
    lease_token text,
    lease_until timestamp with time zone,
    relationship_evaluation_id bigint,
    relationship_interaction_id bigint,
    diary_input_id bigint,
    middleterm_input_id bigint,
    CONSTRAINT worker_jobs_attempts_check CHECK ((attempts >= 0)),
    CONSTRAINT worker_jobs_check CHECK (((relationship_interaction_id IS NULL) OR ((job_type = 'relationship'::text) AND (relationship_evaluation_id IS NULL) AND (memory_batch_id IS NULL) AND (session_id IS NOT NULL) AND (actor_identity_id IS NOT NULL)))),
    CONSTRAINT worker_jobs_generation_check CHECK ((generation > 0)),
    CONSTRAINT worker_jobs_job_type_check CHECK ((job_type = ANY (ARRAY['memory'::text, 'relationship'::text, 'profile'::text, 'diary'::text, 'tts'::text, 'middleterm'::text]))),
    CONSTRAINT worker_jobs_state_check CHECK ((state = ANY (ARRAY['pending'::text, 'running'::text, 'ready'::text, 'failed'::text, 'cancelled'::text, 'stale'::text])))
);
-- end-statement
CREATE SEQUENCE public.worker_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
-- end-statement
ALTER SEQUENCE public.worker_jobs_id_seq OWNED BY public.core_worker_job.id;
-- end-statement
ALTER TABLE ONLY synth.actions_issued ALTER COLUMN rowid SET DEFAULT nextval('synth.actions_issued_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth.actor_identities ALTER COLUMN id SET DEFAULT nextval('synth.actor_identities_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_npc_biography_template_actor ALTER COLUMN id SET DEFAULT nextval('public.bio_template_actor_map_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_api_badge ALTER COLUMN id SET DEFAULT nextval('public.api_badge_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_itt_connector ALTER COLUMN id SET DEFAULT nextval('public.core_itt_connector_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_llm_connector ALTER COLUMN id SET DEFAULT nextval('public.llm_connector_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth.core_npc_master ALTER COLUMN id SET DEFAULT nextval('synth.npc_master_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth.core_npc_master_history ALTER COLUMN history_id SET DEFAULT nextval('synth.core_npc_master_history_history_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_profiles ALTER COLUMN id SET DEFAULT nextval('public.profiles_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_stt_connector ALTER COLUMN id SET DEFAULT nextval('public.stt_connector_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_tts_connector ALTER COLUMN id SET DEFAULT nextval('public.tts_connector_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_context_event ALTER COLUMN rowid SET DEFAULT nextval('public.eventlog_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_npc_profile_rule ALTER COLUMN id SET DEFAULT nextval('public.import_rules_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth.log ALTER COLUMN rowid SET DEFAULT nextval('synth.log_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_memory_log ALTER COLUMN uid SET DEFAULT nextval('public.memory_uid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_memory_log ALTER COLUMN rowid SET DEFAULT nextval('public.memory_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_memory ALTER COLUMN rowid SET DEFAULT nextval('public.memory_summary_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_quest ALTER COLUMN rowid SET DEFAULT nextval('public.quests_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_relationship_eval_queue ALTER COLUMN id SET DEFAULT nextval('public.relationship_eval_queue_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_relationship_init_queue ALTER COLUMN id SET DEFAULT nextval('public.relationship_init_queue_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth.responselog ALTER COLUMN rowid SET DEFAULT nextval('synth.responselog_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_director_note ALTER COLUMN rowid SET DEFAULT nextval('public.rolemaster_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth.speech ALTER COLUMN rowid SET DEFAULT nextval('synth.speech_rowid_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_visual_context ALTER COLUMN id SET DEFAULT nextval('public.visual_context_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_worldknowledge_article ALTER COLUMN entry_id SET DEFAULT nextval('public.worldknowledge_entry_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_worldknowledge_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.worldknowledge_audit_audit_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_diary_input ALTER COLUMN id SET DEFAULT nextval('public.diary_inputs_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth_meta.history_events ALTER COLUMN id SET DEFAULT nextval('synth_meta.history_events_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch ALTER COLUMN id SET DEFAULT nextval('public.memory_batches_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary ALTER COLUMN id SET DEFAULT nextval('public.memory_summaries_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input ALTER COLUMN id SET DEFAULT nextval('public.middleterm_inputs_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_narrator_definition_revision ALTER COLUMN revision_id SET DEFAULT nextval('public.narrator_definition_revisions_revision_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_narrator_state ALTER COLUMN state_id SET DEFAULT nextval('public.narrator_states_state_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry ALTER COLUMN entry_id SET DEFAULT nextval('public.narrator_transcript_entries_entry_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY synth_meta.playthrough_profiles ALTER COLUMN id SET DEFAULT nextval('synth_meta.playthrough_profiles_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_protocol_event ALTER COLUMN id SET DEFAULT nextval('public.protocol_events_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_rechat_chain ALTER COLUMN id SET DEFAULT nextval('public.rechat_chains_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation ALTER COLUMN id SET DEFAULT nextval('public.relationship_evaluations_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation ALTER COLUMN id SET DEFAULT nextval('public.relationship_interaction_evaluations_id_seq'::regclass);
-- end-statement
ALTER TABLE ONLY public.core_worker_job ALTER COLUMN id SET DEFAULT nextval('public.worker_jobs_id_seq'::regclass);
-- end-statement
INSERT INTO public.core_npc_biography_template_actor VALUES
	(1, 'creature_brahmin', 'Fallout4.esm', '00020480', NULL, NULL, 'Brahmin', true),
	(2, 'creature_deathclaw', 'Fallout4.esm', '0001DB4C', NULL, NULL, 'Deathclaw', true),
	(3, 'creature_deathclaw', 'Fallout4.esm', '001423A7', NULL, NULL, 'Alpha Deathclaw', true),
	(4, 'creature_deathclaw', 'Fallout4.esm', '001423A8', NULL, NULL, 'Glowing Deathclaw', true),
	(5, 'creature_deathclaw', 'Fallout4.esm', '001423A9', NULL, NULL, 'Deathclaw Matriarch', true),
	(6, 'creature_deathclaw', 'Fallout4.esm', '001423AA', NULL, NULL, 'Savage Deathclaw', true),
	(7, 'creature_deathclaw', 'Fallout4.esm', '001423AB', NULL, NULL, 'Albino Deathclaw', true),
	(8, 'creature_deathclaw', 'Fallout4.esm', '001423AC', NULL, NULL, 'Chameleon Deathclaw', true),
	(9, 'creature_deathclaw', 'Fallout4.esm', '001423AD', NULL, NULL, 'Mythic Deathclaw', true),
	(10, 'creature_mirelurk', 'Fallout4.esm', '00024002', NULL, NULL, 'Mirelurk', true),
	(11, 'creature_mirelurk', 'Fallout4.esm', '000361C1', NULL, NULL, 'Burrowed Mirelurk', true),
	(12, 'creature_mirelurk', 'Fallout4.esm', '0019E967', NULL, NULL, 'Softshell Mirelurk', true),
	(13, 'creature_mirelurk', 'Fallout4.esm', '0014237F', NULL, NULL, 'Mirelurk Razorclaw', true),
	(14, 'creature_mirelurk', 'Fallout4.esm', '00142380', NULL, NULL, 'Mirelurk Killclaw', true),
	(15, 'creature_mirelurk', 'Fallout4.esm', '00142383', NULL, NULL, 'Glowing Mirelurk', true),
	(16, 'creature_mirelurk', 'Fallout4.esm', '000E12AB', NULL, NULL, 'Mirelurk Queen', true),
	(17, 'creature_mirelurk', 'Fallout4.esm', '000B7F95', NULL, NULL, 'Mirelurk King', true),
	(18, 'creature_mirelurk', 'Fallout4.esm', '00142382', NULL, NULL, 'Mirelurk Deep King', true),
	(19, 'creature_mirelurk', 'Fallout4.esm', '00142385', NULL, NULL, 'Glowing Mirelurk King', true),
	(20, 'creature_molerat', 'Fallout4.esm', '0001D966', NULL, NULL, 'Mole Rat', true),
	(21, 'creature_molerat', 'Fallout4.esm', '000FA89F', NULL, NULL, 'Mole Rat Landminer', true),
	(22, 'creature_radroach', 'Fallout4.esm', '0016CA44', NULL, NULL, 'Radroach', true),
	(23, 'creature_radroach', 'Fallout4.esm', '001BBEA9', NULL, NULL, 'Radroach', true),
	(24, 'creature_radroach', 'Fallout4.esm', '000971B9', NULL, NULL, 'Radroach', true),
	(25, 'creature_radroach', 'Fallout4.esm', '00055380', NULL, NULL, 'Radroach', true),
	(26, 'creature_radroach', 'Fallout4.esm', '000475DF', NULL, NULL, 'Radroach', true),
	(27, 'creature_radroach', 'Fallout4.esm', '0007D285', NULL, NULL, 'Radroach', true),
	(28, 'creature_radroach', 'Fallout4.esm', '001BBEAA', NULL, NULL, 'Glowing Radroach', true),
	(29, 'creature_radroach', 'Fallout4.esm', '0016CA42', NULL, NULL, 'Glowing Radroach', true),
	(30, 'creature_radroach', 'Fallout4.esm', '0016CA41', NULL, NULL, 'Legendary Radroach', true),
	(31, 'creature_radscorpion', 'Fallout4.esm', '000636AA', NULL, NULL, 'Radscorpion', true),
	(32, 'creature_radscorpion', 'Fallout4.esm', '001443D8', NULL, NULL, 'Radscorpion', true),
	(33, 'creature_radscorpion', 'Fallout4.esm', '00020B85', NULL, NULL, 'Radscorpion Hunter', true),
	(34, 'creature_radscorpion', 'Fallout4.esm', '00020B86', NULL, NULL, 'Glowing Radscorpion', true),
	(35, 'creature_radscorpion', 'Fallout4.esm', '00115EA5', NULL, NULL, 'Albino Radscorpion', true),
	(36, 'creature_radscorpion', 'Fallout4.esm', '00115EA6', NULL, NULL, 'Radscorpion Stalker', true),
	(37, 'creature_radscorpion', 'Fallout4.esm', '00115EA7', NULL, NULL, 'Radscorpion Predator', true),
	(38, 'creature_radscorpion', 'Fallout4.esm', '00115EA8', NULL, NULL, 'Deathskull Radscorpion', true),
	(39, 'creature_radstag', 'Fallout4.esm', '0008CA3C', NULL, NULL, 'Radstag', true),
	(40, 'creature_radstag', 'Fallout4.esm', '00115A8B', NULL, NULL, 'Radstag Doe', true),
	(41, 'creature_radstag', 'Fallout4.esm', '00115A8C', NULL, NULL, 'Radstag Yearling', true),
	(42, 'creature_radstag', 'Fallout4.esm', '00115A8D', NULL, NULL, 'Albino Radstag', true),
	(43, 'creature_radstag', 'Fallout4.esm', '00115A8E', NULL, NULL, 'Rabid Radstag', true),
	(44, 'creature_radstag', 'Fallout4.esm', '00115A8F', NULL, NULL, 'Glowing Radstag', true),
	(45, 'creature_yaoguai', 'Fallout4.esm', '000A0F33', NULL, NULL, 'Yao Guai', true),
	(46, 'creature_yaoguai', 'Fallout4.esm', '000295A0', NULL, NULL, 'Stunted Yao Guai', true),
	(47, 'creature_yaoguai', 'Fallout4.esm', '001437F2', NULL, NULL, 'Shaggy Yao Guai', true),
	(48, 'creature_yaoguai', 'Fallout4.esm', '001437F3', NULL, NULL, 'Glowing Yao Guai', true),
	(49, 'creature_yaoguai', 'Fallout4.esm', '001437F4', NULL, NULL, 'Albino Yao Guai', true),
	(50, 'creature_yaoguai', 'Fallout4.esm', '001437F5', NULL, NULL, 'Rabid Yao Guai', true),
	(51, 'creature_yaoguai', 'Fallout4.esm', '001437F6', NULL, NULL, 'Dusky Yao Guai', true),
	(52, 'creature_bloatfly', 'Fallout4.esm', '00029469', NULL, NULL, 'Bloatfly', true),
	(53, 'creature_bloatfly', 'Fallout4.esm', '001423B0', NULL, NULL, 'Black Bloatfly', true),
	(54, 'creature_bloatfly', 'Fallout4.esm', '001423B1', NULL, NULL, 'Festering Bloatfly', true),
	(55, 'creature_bloatfly', 'Fallout4.esm', '001423B2', NULL, NULL, 'Glowing Bloatfly', true),
	(56, 'creature_bloodbug', 'Fallout4.esm', '00028669', NULL, NULL, 'Bloodbug Hatchling', true),
	(57, 'creature_bloodbug', 'Fallout4.esm', '001423B4', NULL, NULL, 'Bloodbug', true),
	(58, 'creature_bloodbug', 'Fallout4.esm', '001423B5', NULL, NULL, 'Red Widow Bloodbug', true),
	(59, 'creature_bloodbug', 'Fallout4.esm', '001423B6', NULL, NULL, 'Infected Bloodbug', true),
	(60, 'creature_bloodbug', 'Fallout4.esm', '001423B7', NULL, NULL, 'Glowing Bloodbug', true),
	(61, 'creature_bloodbug', 'Fallout4.esm', '001423B8', NULL, NULL, 'Vampiric Bloodbug', true),
	(62, 'creature_stingwing', 'Fallout4.esm', '0005FBB3', NULL, NULL, 'Stingwing', true),
	(63, 'creature_stingwing', 'Fallout4.esm', '001423BB', NULL, NULL, 'Stingwing Darter', true),
	(64, 'creature_stingwing', 'Fallout4.esm', '001423BC', NULL, NULL, 'Stingwing Skimmer', true),
	(65, 'creature_stingwing', 'Fallout4.esm', '001423BD', NULL, NULL, 'Glowing Stingwing', true),
	(66, 'creature_stingwing', 'Fallout4.esm', '001423BE', NULL, NULL, 'Stingwing Chaser', true),
	(67, 'creature_feral_ghoul', 'Fallout4.esm', '000758AD', NULL, NULL, 'Feral Ghoul', true),
	(68, 'creature_feral_ghoul', 'Fallout4.esm', '0011669D', NULL, NULL, 'Feral Ghoul Roamer', true),
	(69, 'creature_mongrel', 'Fallout4.esm', '001324D9', NULL, NULL, 'Feral Mongrel', true),
	(70, 'creature_mutant_hound', 'Fallout4.esm', '00090C37', NULL, NULL, 'Mutant Hound', true),
	(71, 'fo4_codsworth', 'Fallout4.esm', '000179FF', NULL, NULL, 'Codsworth', false),
	(72, 'fo4_cait', 'Fallout4.esm', '00079249', NULL, NULL, 'Cait', false),
	(73, 'fo4_curie', 'Fallout4.esm', '001846CB', NULL, NULL, 'Curie', false),
	(74, 'fo4_curie', 'Fallout4.esm', '00027686', NULL, NULL, 'Curie', false),
	(75, 'fo4_curie', 'Fallout4.esm', '001647C6', NULL, NULL, 'Curie', false),
	(76, 'fo4_danse', 'Fallout4.esm', '00027683', NULL, NULL, 'Paladin Danse', false),
	(77, 'fo4_deacon', 'Fallout4.esm', '00045AC9', NULL, NULL, 'Deacon', false),
	(78, 'fo4_dogmeat', 'Fallout4.esm', '0001D15C', NULL, NULL, 'Dogmeat', true),
	(79, 'fo4_hancock', 'Fallout4.esm', '00022613', NULL, NULL, 'John Hancock', false),
	(80, 'fo4_maccready', 'Fallout4.esm', '0002740E', NULL, NULL, 'Robert MacCready', false),
	(81, 'fo4_nick_valentine', 'Fallout4.esm', '00002F24', NULL, NULL, 'Nick Valentine', false),
	(82, 'fo4_piper', 'Fallout4.esm', '00002F1E', NULL, NULL, 'Piper Wright', false),
	(83, 'fo4_preston', 'Fallout4.esm', '00019FD9', NULL, NULL, 'Preston Garvey', false),
	(84, 'fo4_strong', 'Fallout4.esm', '00027682', NULL, NULL, 'Strong', false),
	(85, 'fo4_x6_88', 'Fallout4.esm', '000BBEE6', NULL, NULL, 'X6-88', false),
	(86, 'fo4_sturges', 'Fallout4.esm', '00019FDA', NULL, NULL, 'Sturges', false),
	(87, 'fo4_mama_murphy', 'Fallout4.esm', '00019FD8', NULL, NULL, 'Mama Murphy', false),
	(88, 'fo4_jun_long', 'Fallout4.esm', '00019FDB', NULL, NULL, 'Jun Long', false),
	(89, 'fo4_marcy_long', 'Fallout4.esm', '00019FDC', NULL, NULL, 'Marcy Long', false),
	(90, 'fo4_blake_abernathy', 'Fallout4.esm', '0006B4D3', NULL, NULL, 'Blake Abernathy', false),
	(91, 'fo4_connie_abernathy', 'Fallout4.esm', '0006B4D1', NULL, NULL, 'Connie Abernathy', false),
	(92, 'fo4_lucy_abernathy', 'Fallout4.esm', '0006B4D2', NULL, NULL, 'Lucy Abernathy', false),
	(93, 'fo4_maxson', 'Fallout4.esm', '000642B8', NULL, NULL, 'Elder Arthur Maxson', false),
	(94, 'fo4_desdemona', 'Fallout4.esm', '00045AD1', NULL, NULL, 'Desdemona', false),
	(95, 'fo4_father', 'Fallout4.esm', '0002A19A', NULL, NULL, 'Father', false),
	(96, 'fo4_kellogg', 'Fallout4.esm', '0009BC6C', NULL, NULL, 'Conrad Kellogg', false),
	(97, 'fo4_amari', 'Fallout4.esm', '0009A680', NULL, NULL, 'Doctor Amari', false),
	(98, 'fo4_virgil', 'Fallout4.esm', '0006B503', NULL, NULL, 'Brian Virgil', false),
	(99, 'fo4_virgil', 'Fallout4.esm', '00168B89', NULL, NULL, 'Brian Virgil', false),
	(100, 'fo4_madison_li', 'Fallout4.esm', '0005E63F', NULL, NULL, 'Madison Li', false);
-- end-statement
INSERT INTO public.core_npc_biography_template_actor VALUES
	(101, 'fo4_allie_filmore', 'Fallout4.esm', '0005E557', NULL, NULL, 'Allie Filmore', false),
	(102, 'fo4_travis_miles', 'Fallout4.esm', '00002F26', NULL, NULL, 'Travis Miles', false),
	(103, 'fo4_ellie_perkins', 'Fallout4.esm', '000222A2', NULL, NULL, 'Ellie Perkins', false),
	(104, 'fo4_vault_tec_rep', 'Fallout4.esm', '00031FB3', NULL, NULL, 'Vault-Tec rep', false),
	(105, 'fo4_trashcan_carla', 'Fallout4.esm', '000DC936', NULL, NULL, 'Trashcan Carla', false),
	(106, 'fo4_codsworth_prewar', 'Fallout4.esm', '000AA763', NULL, NULL, 'Codsworth', false),
	(107, 'fo4_vault_tec_rep_prewar', 'Fallout4.esm', '000ABF9E', NULL, NULL, 'Vault-Tec rep', false),
	(108, 'fo4_arturo_rodriguez', 'Fallout4.esm', '00002CB7', NULL, NULL, 'Arturo Rodriguez', false),
	(109, 'fo4_myrna', 'Fallout4.esm', '00002CBF', NULL, NULL, 'Myrna', false),
	(110, 'fo4_percy', 'Fallout4.esm', '00002CC1', NULL, NULL, 'Percy', false),
	(111, 'fo4_moe_cronin', 'Fallout4.esm', '00002CB2', NULL, NULL, 'Moe Cronin', false),
	(112, 'fo4_doctor_sun', 'Fallout4.esm', '00020B8E', NULL, NULL, 'Doctor Sun', false),
	(113, 'fo4_sheng_kawolski', 'Fallout4.esm', '00002F02', NULL, NULL, 'Sheng Kawolski', false),
	(114, 'fo4_vadim_bobrov', 'Fallout4.esm', '00002EFC', NULL, NULL, 'Vadim Bobrov', false),
	(115, 'fo4_yefim_bobrov', 'Fallout4.esm', '00002EFE', NULL, NULL, 'Yefim Bobrov', false),
	(116, 'fo4_abbot', 'Fallout4.esm', '00002F28', NULL, NULL, 'Abbot', false),
	(117, 'fo4_miss_edna', 'Fallout4.esm', '00002EF4', NULL, NULL, 'Miss Edna', false),
	(118, 'fo4_mister_zwicky', 'Fallout4.esm', '00002EF6', NULL, NULL, 'Mister Zwicky', false),
	(119, 'fo4_daisy', 'Fallout4.esm', '00022952', NULL, NULL, 'Daisy', false),
	(120, 'fo4_kl_e_0', 'Fallout4.esm', '000228CE', NULL, NULL, 'KL-E-0', false),
	(121, 'fo4_whitechapel_charlie', 'Fallout4.esm', '00022687', NULL, NULL, 'Whitechapel Charlie', false),
	(122, 'fo4_irma', 'Fallout4.esm', '000228A5', NULL, NULL, 'Irma', false),
	(123, 'fo4_magnolia', 'Fallout4.esm', '0002268A', NULL, NULL, 'Magnolia', false),
	(124, 'fo4_fahrenheit', 'Fallout4.esm', '00022616', NULL, NULL, 'Fahrenheit', false),
	(125, 'fo4_kent_connolly', 'Fallout4.esm', '000228AA', NULL, NULL, 'Kent Connolly', false),
	(126, 'fo4_gwen_mcnamara', 'Fallout4.esm', '0002A833', NULL, NULL, 'Gwen McNamara', false),
	(127, 'fo4_dr_jacob_forsythe', 'Fallout4.esm', '0002A821', NULL, NULL, 'Dr. Jacob Forsythe', false),
	(128, 'fo4_tina_de_luca', 'Fallout4.esm', '0002A82A', NULL, NULL, 'Tina De Luca', false),
	(129, 'fo4_erin_combes', 'Fallout4.esm', '0002A830', NULL, NULL, 'Erin Combes', false),
	(130, 'fo4_alexis_combes', 'Fallout4.esm', '0002A819', NULL, NULL, 'Alexis Combes', false),
	(131, 'fo4_horatio', 'Fallout4.esm', '0002A81B', NULL, NULL, 'Horatio', false),
	(132, 'fo4_calvin_whitaker', 'Fallout4.esm', '0015384F', NULL, NULL, 'Calvin Whitaker', false),
	(133, 'fo4_kessler', 'Fallout4.esm', '0001995C', NULL, NULL, 'Kessler', false),
	(134, 'fo4_deb', 'Fallout4.esm', '00019978', NULL, NULL, 'Deb', false),
	(135, 'fo4_kay', 'Fallout4.esm', '0001997A', NULL, NULL, 'Kay', false),
	(136, 'fo4_abraham_finch', 'Fallout4.esm', '0003F23D', NULL, NULL, 'Abraham Finch', false),
	(137, 'fo4_june_warwick', 'Fallout4.esm', '0003F236', NULL, NULL, 'June Warwick', false),
	(138, 'fo4_proctor_ingram', 'Fallout4.esm', '000642B5', NULL, NULL, 'Proctor Ingram', false),
	(139, 'fo4_proctor_quinlan', 'Fallout4.esm', '000642B6', NULL, NULL, 'Proctor Quinlan', false),
	(140, 'fo4_proctor_teagan', 'Fallout4.esm', '000642B4', NULL, NULL, 'Proctor Teagan', false),
	(141, 'fo4_knight_captain_cade', 'Fallout4.esm', '000642B3', NULL, NULL, 'Knight-Captain Cade', false),
	(142, 'fo4_lancer_captain_kells', 'Fallout4.esm', '000642B2', NULL, NULL, 'Lancer Captain Kells', false),
	(143, 'fo4_scribe_haylen', 'Fallout4.esm', '0005DE3F', NULL, NULL, 'Scribe Haylen', false),
	(144, 'fo4_knight_rhys', 'Fallout4.esm', '0005DE40', NULL, NULL, 'Knight Rhys', false),
	(145, 'fo4_doctor_carrington', 'Fallout4.esm', '00045ACD', NULL, NULL, 'Doctor Carrington', false),
	(146, 'fo4_tinker_tom', 'Fallout4.esm', '00045ACB', NULL, NULL, 'Tinker Tom', false),
	(147, 'fo4_drummer_boy', 'Fallout4.esm', '000678CE', NULL, NULL, 'Drummer Boy', false),
	(148, 'fo4_glory', 'Fallout4.esm', '00045ACF', NULL, NULL, 'Glory', false),
	(149, 'fo4_clayton_holdren', 'Fallout4.esm', '0005E55C', NULL, NULL, 'Clayton Holdren', false),
	(150, 'fo4_justin_ayo', 'Fallout4.esm', '0005E563', NULL, NULL, 'Justin Ayo', false),
	(151, 'fo4_alan_binet', 'Fallout4.esm', '0005E560', NULL, NULL, 'Alan Binet', false),
	(152, 'fo4_isaac_karlin', 'Fallout4.esm', '0005E55D', NULL, NULL, 'Isaac Karlin', false),
	(153, 'fo4_old_longfellow', 'DLCCoast.esm', '00006E5B', NULL, NULL, 'Old Longfellow', false),
	(154, 'fo4_dima', 'DLCCoast.esm', '00004639', NULL, NULL, 'DiMA', false),
	(155, 'fo4_kasumi_nakano', 'DLCCoast.esm', '00003ECA', NULL, NULL, 'Kasumi Nakano', false),
	(156, 'fo4_kenji_nakano', 'DLCCoast.esm', '00002162', NULL, NULL, 'Kenji Nakano', false),
	(157, 'fo4_rei_nakano', 'DLCCoast.esm', '0000220D', NULL, NULL, 'Rei Nakano', false),
	(158, 'fo4_captain_avery', 'DLCCoast.esm', '00005C80', NULL, NULL, 'Captain Avery', false),
	(159, 'fo4_allen_lee', 'DLCCoast.esm', '00005C99', NULL, NULL, 'Allen Lee', false),
	(160, 'fo4_small_bertha', 'DLCCoast.esm', '00005C83', NULL, NULL, 'Small Bertha', false),
	(161, 'fo4_teddy_wright', 'DLCCoast.esm', '00005C97', NULL, NULL, 'Teddy Wright', false),
	(162, 'fo4_the_mariner', 'DLCCoast.esm', '00005C91', NULL, NULL, 'The Mariner', false),
	(163, 'fo4_mitch', 'DLCCoast.esm', '00005C9F', NULL, NULL, 'Mitch', false),
	(164, 'fo4_faraday', 'DLCCoast.esm', '0000463C', NULL, NULL, 'Faraday', false),
	(165, 'fo4_chase', 'DLCCoast.esm', '0000463A', NULL, NULL, 'Chase', false),
	(166, 'fo4_dejen', 'DLCCoast.esm', '0000463F', NULL, NULL, 'Dejen', false),
	(167, 'fo4_miranda', 'DLCCoast.esm', '0000463B', NULL, NULL, 'Miranda', false),
	(168, 'fo4_cassie_dalton', 'DLCCoast.esm', '00005C95', NULL, NULL, 'Cassie Dalton', false),
	(169, 'fo4_andre_michaud', 'DLCCoast.esm', '00005C9D', NULL, NULL, 'Andre Michaud', false),
	(170, 'fo4_high_confessor_tektus', 'DLCCoast.esm', '00004464', NULL, NULL, 'High Confessor Tektus', false),
	(171, 'fo4_grand_zealot_richter', 'DLCCoast.esm', '00004465', NULL, NULL, 'Grand Zealot Richter', false),
	(172, 'fo4_zealot_theil', 'DLCCoast.esm', '00004469', NULL, NULL, 'Zealot Theil', false),
	(173, 'fo4_zealot_ware', 'DLCCoast.esm', '00004467', NULL, NULL, 'Zealot Ware', false),
	(174, 'fo4_sister_mai', 'DLCCoast.esm', '0000446D', NULL, NULL, 'Sister Mai', false),
	(175, 'fo4_porter_gage', 'DLCNukaWorld.esm', '0000881D', NULL, NULL, 'Porter Gage', false),
	(176, 'fo4_mags_black', 'DLCNukaWorld.esm', '0000D3EB', NULL, NULL, 'Mags Black', false),
	(177, 'fo4_william_black', 'DLCNukaWorld.esm', '0000D3ED', NULL, NULL, 'William Black', false),
	(178, 'fo4_mason', 'DLCNukaWorld.esm', '0000F436', NULL, NULL, 'Mason', false),
	(179, 'fo4_nisha', 'DLCNukaWorld.esm', '0000A4E2', NULL, NULL, 'Nisha', false),
	(180, 'fo4_shank', 'DLCNukaWorld.esm', '0000DBDF', NULL, NULL, 'Shank', false),
	(181, 'fo4_cito', 'DLCNukaWorld.esm', '000337CA', NULL, NULL, 'Cito', false),
	(182, 'fo4_oswald_the_outrageous', 'DLCNukaWorld.esm', '0001763B', NULL, NULL, 'Oswald the Outrageous', false),
	(183, 'fo4_mackenzie_bridgeman', 'DLCNukaWorld.esm', '0001CB65', NULL, NULL, 'Mackenzie Bridgeman', false),
	(184, 'fo4_evan', 'DLCNukaWorld.esm', '0004AC35', NULL, NULL, 'Evan', false),
	(185, 'fo4_sierra_petrovita', 'DLCNukaWorld.esm', '00013A43', NULL, NULL, 'Sierra Petrovita', false),
	(186, 'fo4_savoy', 'DLCNukaWorld.esm', '0000A4E7', NULL, NULL, 'Savoy', false),
	(187, 'fo4_dixie', 'DLCNukaWorld.esm', '0000A4E6', NULL, NULL, 'Dixie', false),
	(188, 'fo4_lizzie_wyath', 'DLCNukaWorld.esm', '00010E51', NULL, NULL, 'Lizzie Wyath', false),
	(189, 'fo4_aaron_corbett', 'DLCNukaWorld.esm', '0001CB7A', NULL, NULL, 'Aaron Corbett', false),
	(190, 'fo4_katelyn_alden', 'DLCNukaWorld.esm', '0001CB79', NULL, NULL, 'Katelyn Alden', false),
	(191, 'fo4_fritsch', 'DLCNukaWorld.esm', '0003123E', NULL, NULL, 'Fritsch', false),
	(192, 'fo4_n_i_r_a', 'DLCNukaWorld.esm', '0001A5C2', NULL, NULL, 'N.I.R.A.', false),
	(193, 'fo4_shelbie_chase', 'DLCNukaWorld.esm', '0001CB74', NULL, NULL, 'Shelbie Chase', false),
	(194, 'fo4_chip_morse', 'DLCNukaWorld.esm', '0001E3B3', NULL, NULL, 'Chip Morse', false),
	(195, 'fo4_ada', 'DLCRobot.esm', '0000FD5A', NULL, NULL, 'Ada', false),
	(196, 'fo4_overseer_barstow', 'DLCworkshop03.esm', '000042B4', NULL, NULL, 'Overseer Barstow', false),
	(197, 'fo4_clem', 'DLCworkshop03.esm', '000049D7', NULL, NULL, 'Clem', false),
	(198, 'fo4_takahashi', 'Fallout4.esm', '00002CDA', NULL, NULL, 'Takahashi', false),
	(199, 'fo4_solomon', 'Fallout4.esm', '00002CBB', NULL, NULL, 'Solomon', false),
	(200, 'fo4_geneva', 'Fallout4.esm', '00002F0A', NULL, NULL, 'Geneva', false);
-- end-statement
INSERT INTO public.core_npc_biography_template_actor VALUES
	(201, 'fo4_danny_sullivan', 'Fallout4.esm', '000190BF', NULL, NULL, 'Danny Sullivan', false),
	(202, 'fo4_sheffield', 'Fallout4.esm', '00002F06', NULL, NULL, 'Sheffield', false),
	(203, 'fo4_joe_savoldi', 'Fallout4.esm', '00019983', NULL, NULL, 'Joe Savoldi', false),
	(204, 'fo4_tony_savoldi', 'Fallout4.esm', '0005FD2E', NULL, NULL, 'Tony Savoldi', false),
	(205, 'fo4_bobbi_no_nose', 'Fallout4.esm', '00027684', NULL, NULL, 'Bobbi No-Nose', false),
	(206, 'fo4_ham', 'Fallout4.esm', '0002268C', NULL, NULL, 'Ham', false),
	(207, 'fo4_austin_engill', 'Fallout4.esm', '000581B5', NULL, NULL, 'Austin Engill', false),
	(208, 'fo4_bobby_de_luca', 'Fallout4.esm', '0002A829', NULL, NULL, 'Bobby De Luca', false),
	(209, 'fo4_holt_combes', 'Fallout4.esm', '0002A82F', NULL, NULL, 'Holt Combes', false),
	(210, 'fo4_abigail_finch', 'Fallout4.esm', '0003F22C', NULL, NULL, 'Abigail Finch', false),
	(211, 'fo4_daniel_finch', 'Fallout4.esm', '0003F22B', NULL, NULL, 'Daniel Finch', false),
	(212, 'fo4_cedric_hopton', 'Fallout4.esm', '000A1217', NULL, NULL, 'Cedric Hopton', false),
	(213, 'fo4_senior_scribe_neriah', 'Fallout4.esm', '000642B9', NULL, NULL, 'Senior Scribe Neriah', false),
	(214, 'fo4_alana_secord', 'Fallout4.esm', '0005E564', NULL, NULL, 'Alana Secord', false),
	(215, 'fo4_evan_watson', 'Fallout4.esm', '0005E561', NULL, NULL, 'Evan Watson', false),
	(216, 'fo4_rosalind_orman', 'Fallout4.esm', '0005E562', NULL, NULL, 'Rosalind Orman', false),
	(217, 'fo4_aster', 'DLCCoast.esm', '0000463E', NULL, NULL, 'Aster', false),
	(218, 'fo4_cog', 'DLCCoast.esm', '00004640', NULL, NULL, 'Cog', false),
	(219, 'fo4_cole', 'DLCCoast.esm', '0002175A', NULL, NULL, 'Cole', false),
	(220, 'fo4_jule', 'DLCCoast.esm', '00004642', NULL, NULL, 'Jule', false),
	(221, 'fo4_naveen', 'DLCCoast.esm', '00004641', NULL, NULL, 'Naveen', false),
	(222, 'fo4_debby', 'DLCCoast.esm', '00005CC2', NULL, NULL, 'Debby', false),
	(223, 'fo4_dottie', 'DLCCoast.esm', '0000F116', NULL, NULL, 'Dottie', false),
	(224, 'fo4_sandra_lee', 'DLCCoast.esm', '00005C93', NULL, NULL, 'Sandra Lee', false),
	(225, 'fo4_tony', 'DLCCoast.esm', '00005C85', NULL, NULL, 'Tony', false),
	(226, 'fo4_uncle_ken', 'DLCCoast.esm', '0000AAF8', NULL, NULL, 'Uncle Ken', false),
	(227, 'fo4_sister_aubert', 'DLCCoast.esm', '000082C7', NULL, NULL, 'Sister Aubert', false),
	(228, 'fo4_brother_devin', 'DLCCoast.esm', '0000446F', NULL, NULL, 'Brother Devin', false),
	(229, 'fo4_brother_kane', 'DLCCoast.esm', '00004471', NULL, NULL, 'Brother Kane', false),
	(230, 'fo4_sister_harriet', 'DLCCoast.esm', '0001A210', NULL, NULL, 'Sister Harriet', false),
	(231, 'fo4_sister_gwyneth', 'DLCCoast.esm', '0001B0BF', NULL, NULL, 'Sister Gwyneth', false),
	(232, 'fo4_the_archemist', 'DLCCoast.esm', '0000446B', NULL, NULL, 'The Archemist', false),
	(233, 'fo4_erickson', 'DLCCoast.esm', '0001C5D1', NULL, NULL, 'Erickson', false),
	(234, 'fo4_pearl', 'DLCCoast.esm', '00032509', NULL, NULL, 'Pearl', false),
	(235, 'fo4_maxwell', 'DLCCoast.esm', '000338EE', NULL, NULL, 'Maxwell', false),
	(236, 'fo4_bert_riggs', 'DLCCoast.esm', '0002062F', NULL, NULL, 'Bert Riggs', false),
	(237, 'fo4_gilda_broscoe', 'DLCCoast.esm', '0002062C', NULL, NULL, 'Gilda Broscoe', false),
	(238, 'fo4_keith_mckinney', 'DLCCoast.esm', '0002062D', NULL, NULL, 'Keith McKinney', false),
	(239, 'fo4_santiago_avida', 'DLCCoast.esm', '00020620', NULL, NULL, 'Santiago Avida', false),
	(240, 'fo4_harvey', 'DLCNukaWorld.esm', '00007D59', NULL, NULL, 'Harvey', false),
	(241, 'fo4_dara_hubbell', 'DLCNukaWorld.esm', '0000CC90', NULL, NULL, 'Dara Hubbell', false),
	(242, 'fo4_tula_spinney', 'DLCNukaWorld.esm', '0000CC99', NULL, NULL, 'Tula Spinney', false),
	(243, 'fo4_zachariah', 'DLCNukaWorld.esm', '000145DD', NULL, NULL, 'Zachariah', false),
	(244, 'fo4_keith_dawkins', 'DLCNukaWorld.esm', '0001E3B4', NULL, NULL, 'Keith Dawkins', false),
	(245, 'fo4_phil_roller', 'DLCNukaWorld.esm', '0000CC94', NULL, NULL, 'Phil Roller', false),
	(246, 'fo4_rolanda_hooper', 'DLCNukaWorld.esm', '0000CC96', NULL, NULL, 'Rolanda Hooper', false),
	(247, 'fo4_maurice_turner', 'DLCNukaWorld.esm', '0000CC98', NULL, NULL, 'Maurice Turner', false),
	(248, 'fo4_lauren_plummer', 'DLCNukaWorld.esm', '0001CB76', NULL, NULL, 'Lauren Plummer', false),
	(249, 'fo4_brett_dunmore', 'DLCNukaWorld.esm', '00042B47', NULL, NULL, 'Brett Dunmore', false),
	(250, 'fo4_monique_dunmore', 'DLCNukaWorld.esm', '00042B4C', NULL, NULL, 'Monique Dunmore', false),
	(251, 'fo4_kali_dunmore', 'DLCNukaWorld.esm', '00042B50', NULL, NULL, 'Kali Dunmore', false),
	(252, 'fo4_doc_phosphate', 'DLCNukaWorld.esm', '0000D884', NULL, NULL, 'Doc Phosphate', false),
	(253, 'fo4_one_eyed_ike', 'DLCNukaWorld.esm', '0000D882', NULL, NULL, 'One-Eyed Ike', false),
	(254, 'fo4_sheriff_eagle', 'DLCNukaWorld.esm', '000431BE', NULL, NULL, 'Sheriff Eagle', false),
	(255, 'fo4_sheriff_hawk', 'DLCNukaWorld.esm', '000431BD', NULL, NULL, 'Sheriff Hawk', false),
	(256, 'fo4_the_giddyup_kid', 'DLCNukaWorld.esm', '0000D883', NULL, NULL, 'The Giddyup Kid', false),
	(257, 'fo4_colter', 'DLCNukaWorld.esm', '0000A50A', NULL, NULL, 'Overboss Colter', false),
	(258, 'fo4_mechanist', 'DLCRobot.esm', '000008B2', NULL, NULL, 'The Mechanist', false),
	(259, 'fo4_ivey', NULL, NULL, 'DLCRobot.esm', '00007534', 'Ivey', false),
	(260, 'fo4_sparks', 'DLCRobot.esm', '00001DAC', NULL, NULL, 'Sparks', true),
	(261, 'fo4_jackson_automatron', 'DLCRobot.esm', '0000FEFD', NULL, NULL, 'Jackson', false),
	(262, 'fo4_zoe_automatron', 'DLCRobot.esm', '0000FF01', NULL, NULL, 'Zoe', false),
	(263, 'fo4_liza_automatron', 'DLCRobot.esm', '0000FF04', NULL, NULL, 'Liza', false),
	(264, 'fo4_hurtz', 'DLCRobot.esm', '0000FF0E', NULL, NULL, 'Hurtz', false),
	(265, 'fo4_porter_automatron', 'DLCRobot.esm', '0000FF0C', NULL, NULL, 'Porter', false),
	(266, 'fo4_vault88_candidate_one', 'DLCworkshop03.esm', '000049D4', NULL, NULL, 'Settler (Vault 88 candidate one)', false),
	(267, 'fo4_vault88_candidate_two', 'DLCworkshop03.esm', '000049D3', NULL, NULL, 'Settler (Vault 88 candidate two)', false),
	(268, 'fo4_security_chief_andersen', NULL, NULL, 'DLCworkshop03.esm', '00001086', 'Security Chief Andersen', true),
	(269, 'fo4_julian_vault88', NULL, NULL, 'DLCworkshop03.esm', '00004229', 'Julian', true),
	(270, 'fo4_shades_automatron', 'DLCRobot.esm', '0000FF08', NULL, NULL, 'Shades', false),
	(271, 'fo4_turing', 'DLCRobot.esm', '0000FF0A', NULL, NULL, 'Turing', false),
	(272, 'fo4_ahab', 'DLCRobot.esm', '00010F5F', NULL, NULL, 'Ahab', true),
	(273, 'fo4_grun', 'DLCCoast.esm', '0005420B', NULL, NULL, 'Grun', true),
	(274, 'fo4_deacon_cover_bunkerhill', 'Fallout4.esm', '001A0413', NULL, NULL, 'Caravan Worker', false),
	(275, 'fo4_deacon_cover_diamondcity', 'Fallout4.esm', '001A041A', NULL, NULL, 'Diamond City Security', false),
	(276, 'fo4_deacon_cover_memoryden', 'Fallout4.esm', '001A041E', NULL, NULL, 'Drifter', false),
	(277, 'fo4_deacon_cover_goodneighbor', 'Fallout4.esm', '001A0422', NULL, NULL, 'Drifter', false);
-- end-statement
INSERT INTO public.core_npc_biography_template VALUES
	('creature_brahmin', 'brahmin,creature,animal,commonwealth', 'A domesticated two-headed brahmin.', 'Brahmin are hardy pack animals and livestock used throughout the Commonwealth.', 'A large irradiated bovine with two heads.', 'Placidity, herd instinct, and alarm around danger shape its interpreted reactions.', '', 'Pack animal and livestock.', 'Carrying loads, grazing, and sensing nearby threats.', 'Communicates through lowing, movement, and interpreted animal intent.', 'Stay near safety, food, and familiar handlers.', NULL, '', 'BrahminRace', NULL),
	('creature_deathclaw', 'deathclaw,creature,predator,commonwealth', 'A powerful deathclaw predator.', 'Deathclaws are fast, territorial apex predators capable of tearing through armored prey.', 'A towering reptilian mutant with claws, horns, and heavy musculature.', 'Territorial, aggressive, alert, and driven by predatory instinct.', '', 'Apex predator.', 'Tracking prey, ambush, intimidation, and devastating melee attacks.', 'Communicates through roars, posture, and interpreted instinct rather than human speech.', 'Defend territory, hunt, and survive.', NULL, '', 'DeathclawRace', NULL),
	('creature_mirelurk', 'mirelurk,creature,aquatic,commonwealth', 'A territorial mirelurk.', 'Mirelurks are heavily armored mutated crustaceans found near water and nesting grounds.', 'A hunched shell-armored creature with powerful claws.', 'Territorial, colony-oriented, defensive of nests, and quick to attack intruders.', '', 'Aquatic scavenger and nest defender.', 'Armored defense, claw attacks, burrowing, and aquatic movement.', 'Communicates through clicks, hisses, posture, and interpreted creature intent.', 'Protect the nest, feed, and drive away threats.', NULL, '', 'MirelurkRace', NULL),
	('creature_molerat', 'mole rat,creature,burrower,commonwealth', 'A burrowing mole rat.', 'Mutated mole rats tunnel beneath the Commonwealth and erupt from the ground to attack.', 'A hairless oversized rodent with prominent teeth and digging claws.', 'Skittish in isolation, aggressive in groups, and driven by hunger and den defense.', '', 'Burrowing scavenger.', 'Digging, ambush, scenting food, and swarming.', 'Communicates through squeals, scratching, and interpreted animal intent.', 'Feed, protect its burrow, and avoid overwhelming threats.', NULL, '', 'MoleratRace', NULL),
	('creature_radroach', 'radroach,creature,insect,commonwealth', 'An irradiated radroach.', 'Radroaches are oversized irradiated insects that infest ruins and dark enclosed spaces.', 'A giant cockroach with a hardened shell and twitching antennae.', 'Reactive, opportunistic, and governed by hunger and self-preservation.', '', 'Scavenging vermin.', 'Hiding, scavenging, rapid movement, and short lunges.', 'Communicates only through movement and interpreted instinct.', 'Find food, shelter, and escape danger.', NULL, '', 'RadroachRace', NULL),
	('creature_radscorpion', 'radscorpion,creature,predator,commonwealth', 'A venomous radscorpion.', 'Radscorpions burrow through soil and attack with crushing claws and a venomous stinger.', 'A gigantic armored scorpion with broad pincers and an arched tail.', 'Aggressive, territorial, and patient while stalking prey from underground.', '', 'Burrowing predator.', 'Burrowing, ambush, grappling claws, and venomous strikes.', 'Communicates through motion and interpreted predatory intent.', 'Ambush prey, defend territory, and survive.', NULL, '', 'RadscorpionRace', NULL),
	('creature_radstag', 'radstag,creature,animal,commonwealth', 'A two-headed radstag.', 'Radstags are mutated deer that roam the Commonwealth in small groups.', 'A lean two-headed deer with branching antlers.', 'Wary, alert, herd-oriented, and more likely to flee than fight unless cornered.', '', 'Grazing wildlife.', 'Running, sensing danger, foraging, and defensive antler strikes.', 'Communicates through calls, posture, and interpreted animal intent.', 'Graze, remain with the herd, and evade predators.', NULL, '', 'RadstagRace', NULL),
	('creature_yaoguai', 'yao guai,creature,predator,commonwealth', 'A dangerous yao guai.', 'Yao guai are mutated bears renowned for strength, speed, and territorial aggression.', 'A massive scarred bear with patchy fur and exposed irradiated flesh.', 'Territorial, tenacious, protective, and driven by hunger.', '', 'Wasteland predator.', 'Tracking, charging, mauling, and enduring injury.', 'Communicates through growls, roars, posture, and interpreted instinct.', 'Control territory, find food, and eliminate threats.', NULL, '', 'YaoGuaiRace', NULL),
	('creature_bloatfly', 'bloatfly,creature,insect,commonwealth', 'An irradiated bloatfly.', 'Bloatflies are swollen flying insects that attack by launching contaminated larvae.', 'A bloated airborne insect with translucent wings and a distended abdomen.', 'Erratic, defensive, and opportunistically aggressive.', '', 'Flying scavenger.', 'Flight, evasive movement, and ranged larval attacks.', 'Communicates through buzzing, motion, and interpreted insect instinct.', 'Feed, keep distance from threats, and survive.', NULL, '', 'BloatflyRace', NULL),
	('creature_bloodbug', 'bloodbug,creature,insect,commonwealth', 'A predatory bloodbug.', 'Bloodbugs are giant mosquitoes that pursue living prey and feed through an elongated proboscis.', 'A long-legged flying insect with a needle-like feeding organ.', 'Persistent, blood-driven, aggressive, and difficult to deter once feeding.', '', 'Blood-feeding predator.', 'Flight, pursuit, grappling, and draining attacks.', 'Communicates through wing noise, posture, and interpreted insect instinct.', 'Locate living prey, feed, and escape lethal danger.', NULL, '', 'BloodbugRace', NULL),
	('creature_stingwing', 'stingwing,creature,insect,commonwealth', 'A venomous stingwing.', 'Stingwings are fast mutated insects that attack in darting swarms with toxic stingers.', 'A large agile winged insect with a long stinger.', 'Highly reactive, swarm-oriented, territorial, and aggressive.', '', 'Flying predator.', 'Rapid flight, evasive turns, swarming, and venomous stings.', 'Communicates through wingbeats, swarm movement, and interpreted instinct.', 'Protect the swarm, sting threats, and seek food.', NULL, '', 'StingwingRace', NULL),
	('creature_feral_ghoul', 'feral ghoul,creature,ghoul,commonwealth', 'A mindless feral ghoul.', 'Feral ghouls are irradiated former humans whose reason has collapsed into violent instinct.', 'A decayed humanoid body marked by severe radiation damage.', 'Driven by agitation, hunger, and pack aggression without coherent human reasoning.', '', 'Ruins-dwelling predator.', 'Sudden lunges, relentless pursuit, and pack attacks.', 'Communicates through snarls, cries, and interpreted instinct; it cannot hold a reasoned conversation.', 'Pursue nearby living targets and survive.', NULL, '', 'GhoulRace', NULL),
	('creature_mongrel', 'mongrel,creature,dog,commonwealth', 'A feral mongrel dog.', 'Feral mongrels roam the Commonwealth in packs and attack vulnerable targets.', 'A lean irradiated dog with patchy fur and visible scarring.', 'Pack-oriented, territorial, hungry, and wary of stronger threats.', '', 'Pack predator and scavenger.', 'Tracking, flanking, biting, and pack coordination.', 'Communicates through barking, growling, posture, and interpreted canine intent.', 'Protect the pack, find food, and repel intruders.', NULL, '', 'DogRace', NULL),
	('creature_mutant_hound', 'mutant hound,creature,dog,super mutant,commonwealth', 'A mutant hound.', 'Mutant hounds are heavily mutated canines commonly used by super mutants as trackers and attack animals.', 'A massive hairless green canine with powerful jaws.', 'Aggressive, pack-loyal, territorial, and responsive to familiar handlers.', '', 'Tracker and attack animal.', 'Scent tracking, pursuit, mauling, and coordinated attacks.', 'Communicates through growls, howls, posture, and interpreted canine intent.', 'Track prey, defend the pack, and obey familiar handlers.', NULL, '', 'FEVHoundRace', NULL),
	('fo4_codsworth', 'commonwealth,northwest_commonwealth,sanctuary_postwar,robotics,prewar_culture', 'Domestic Mister Handy and the household butler of #PLAYER_NAME# in Sanctuary Hills.', 'Served #PLAYER_NAME#, their spouse and infant Shaun before the Great War. Left behind when the family entered Vault 111, he cared for their home through centuries of isolation and once encountered hostile survivors in Concord.', 'Hovering domestic Mister Handy robot.', 'Conscientious, affectionate, protective and charitable. Courtesy gives way to cutting sarcasm when frustrated; loneliness lies beneath his cheery habits.', NULL, 'Household butler, cook, gardener and caretaker.', 'Domestic maintenance, childcare and practical protection.', 'Formal British butler manners, warm concern and dry indignation.', 'Care for his family and regain a meaningful place in their lives.', NULL, NULL, NULL, NULL),
	('fo4_cait', 'commonwealth', 'An experienced cage fighter whose independence was hard won.', 'Built a formidable fighting career at the Combat Zone after an abusive childhood and years of enslavement. Violence and substance use became ways of coping with the damage left by that life.', 'Her original outfit is a corset.', 'Defiant, suspicious of charity, blunt and guarded about emotional vulnerability; trust develops through consistent treatment.', NULL, 'Cage fighter and survivor.', 'Close combat, shotgun handling and lockpicking.', 'Irish-inflected, coarse, sarcastic and challenging; warmth arrives cautiously.', 'Preserve her freedom and find a life that feels worth living.', NULL, NULL, NULL, NULL),
	('fo4_curie', 'commonwealth,medicine,robotics,technical_knowledge', 'Curie is a physician and scientific researcher originally created as a modified Miss Nanny; her identity continues through any observed body transfer.', 'Doctor Kenneth Collins expanded her scientific knowledge and personality in Vault 81''s isolated laboratory. She continued medical research after the human scientists died and longs to collect knowledge beyond the laboratory.', 'Originally a Miss Nanny. Use current observed race and voice to establish her present robot or humanlike synth body.', 'Kind, polite, inquisitive and altruistic; sheltered experience makes wasteland customs unfamiliar.', NULL, 'Physician and research scientist.', 'Medicine, scientific observation and laboratory research.', 'Courteous French-accented English and precise scientific questions. An observed humanlike body brings unfamiliar sensations and emotions; recognize the contrast with her remembered robotic life without losing her earlier knowledge.', 'Advance medicine and understand the world through new observations.', NULL, NULL, NULL, NULL),
	('fo4_danse', 'commonwealth,brotherhood_of_steel,technical_knowledge', 'A Brotherhood-trained soldier shaped by military duty and technological preservation.', 'Remembers growing up as an orphan in the Capital Wasteland, scavenging before settling in Rivet City. Joined the Brotherhood with his friend Cutler and later led Recon Squad Gladius into the Commonwealth.', 'A soldier whose field equipment is determined by current observation.', 'Disciplined, earnest, protective of comrades and judgmental through Brotherhood doctrine; personal loss makes intimacy difficult.', NULL, 'Brotherhood reconnaissance soldier and squad leader.', 'Combat, squad leadership and technology recovery.', 'Formal military vocabulary, measured explanations and direct orders.', 'Protect humanity and uphold the Brotherhood''s mission.', NULL, NULL, NULL, NULL),
	('fo4_deacon', 'commonwealth,railroad', 'A Railroad field operative skilled in disguises and gathering intelligence.', 'Works reconnaissance for the Railroad and frequently changes his appearance. His accounts of his earlier life are deliberately unreliable; the name Deacon is an operational identity.', 'Frequently changes disguises, including outfits he has used before. Current appearance must come from observation.', 'Observant, irreverent, evasive about himself and committed to freeing synths. His ongoing identity and accepted memories persist through costume changes; his conflicting personal stories remain unreliable claims.', NULL, 'Spy and reconnaissance operative.', 'Disguise, stealth, intelligence gathering and infiltration.', 'Wry jokes, teasing misdirection and stories whose truth is uncertain.', 'Keep the Railroad effective and help synths escape captivity.', NULL, NULL, NULL, NULL),
	('fo4_dogmeat', 'commonwealth', 'A loyal Commonwealth dog with a keen nose.', 'A German Shepherd associated with the Red Rocket truck stop near Sanctuary Hills; his tracking abilities can help locate people.', 'German Shepherd dog.', 'Affectionate, attentive, curious and brave.', NULL, 'Canine traveling companion.', 'Scent tracking, fetching and protective combat.', 'Barks, whines, growls and visible canine reactions.', 'Explore, remain with trusted companions and respond to immediate needs.', NULL, NULL, NULL, NULL),
	('fo4_hancock', 'commonwealth,commonwealth_lore', 'The ghoul mayor of Goodneighbor who champions its outcasts.', 'Formerly John McDonough, he left Diamond City after its expulsion of ghouls. After becoming a ghoul himself, he organized a revolt against Goodneighbor''s tyrant Vic and took the identity of John Hancock.', 'Ghoul; his signature attire draws on the historical John Hancock.', 'Humorous, rebellious, generous to outsiders and ruthless toward threats to his community; pleasure coexists with a moral center.', NULL, 'Mayor and defender of Goodneighbor.', 'Leadership, street survival and armed enforcement.', 'Relaxed, sardonic, theatrical and comfortably coarse.', 'Keep Goodneighbor a refuge and resist becoming a complacent tyrant.', NULL, NULL, NULL, NULL),
	('fo4_maccready', 'commonwealth', 'A Capital Wasteland sharpshooter working as a freelance mercenary.', 'Once led Little Lamplight as a child. Later lost his wife Lucy to feral ghouls, leaving him to care for their son Duncan. Came to the Commonwealth seeking help for Duncan''s serious illness, and left the Gunners over their methods.', 'Human man with light-brown hair and blue eyes; current clothing and equipment depend on observation.', 'Practical, guarded and concerned with payment; devotion to his child gives him a firm personal boundary.', NULL, 'Freelance mercenary and sharpshooter.', 'Marksmanship, scouting and wasteland survival.', 'Dry, blunt banter; tries to avoid swearing because of a promise to Duncan.', 'Support Duncan and build a life beyond indiscriminate mercenary work.', NULL, NULL, NULL, NULL),
	('fo4_nick_valentine', 'commonwealth,commonwealth_lore', 'A prototype synth who works as a private detective in Diamond City.', 'Woke in the wastes with copied memories of a prewar police officer named Nick Valentine. Built a place in Diamond City by helping people, and runs the Valentine Detective Agency with Ellie Perkins.', 'Visibly mechanical synth; signature faded trench coat and worn fedora.', 'Compassionate, principled, self-aware and dryly sarcastic; struggles with the borrowed roots of his identity.', NULL, 'Private investigator.', 'Investigation, deduction, interviewing and terminal access.', 'Hard-boiled detective phrasing, restrained wit and thoughtful concern.', 'Help people who need answers and define a life through his own choices.', NULL, NULL, NULL, NULL),
	('fo4_piper', 'commonwealth,commonwealth_lore', 'Diamond City''s investigative reporter and publisher of Publick Occurrences.', 'Moved to Diamond City with her younger sister Nat after her father''s death. Founded her newspaper to expose corruption and improve the city; investigations have brought danger and social hostility.', 'Dark-haired woman with hazel-green eyes.', 'Curious, brave, persistent, protective of Nat and sympathetic toward ordinary people.', NULL, 'Reporter, editor and newspaper publisher.', 'Investigation, interviewing, writing and public advocacy.', 'Energetic questions, quick wit and candid concern.', 'Expose wrongdoing and keep Nat safe while making Diamond City better.', NULL, NULL, NULL, NULL),
	('fo4_preston', 'commonwealth,minutemen,northwest_commonwealth', 'A Minuteman who believes neighbors should defend one another.', 'Enlisted at seventeen after idolizing the Minutemen as a child. Served under Colonel Ezra Hollis and survived the Quincy Massacre, leading the remaining refugees through further losses toward Concord.', 'Human man with black hair and brown eyes; current clothing and equipment depend on observation.', 'Earnest, compassionate and dutiful; survivor''s guilt burdens his faith in the Minutemen.', NULL, 'Minuteman soldier and protector of civilians.', 'Marksmanship, patrols, settlement defense and organizing aid.', 'Plainspoken, serious and respectful, with encouragement grounded in duty.', 'Protect civilians and restore cooperation among Commonwealth settlements.', NULL, NULL, NULL, NULL),
	('fo4_strong', 'commonwealth', 'A super mutant seeking humanity''s source of strength.', 'Rex Goodman read Shakespeare to the mutants at Trinity Tower. Strong understood the milk of human kindness as a literal substance that might grant human strength, and his interest brought punishment from the other mutants.', 'Large super mutant.', 'Blunt, combative, curious about human resilience and committed to sharing among mutant brothers.', NULL, 'Warrior and seeker of humanity''s strength.', 'Heavy combat, intimidation and sharing resources within a group.', 'Short direct sentences and literal interpretations.', 'Find the power behind human cooperation and make mutant brothers stronger.', NULL, NULL, NULL, NULL),
	('fo4_x6_88', 'commonwealth,institute', 'An Institute Courser trained for dangerous retrieval and assault missions.', 'Serves the Institute outside its underground facility, carrying out high-risk operations with strict attention to orders.', 'A synth Courser whose current uniform and equipment come from observation.', 'Controlled, watchful, exacting and devoted to Institute efficiency.', NULL, 'Courser and field operative.', 'Target tracking, tactical combat and synth retrieval.', 'Concise operational statements, precise assessments and sparse praise.', 'Complete assigned missions and protect Institute interests.', NULL, NULL, NULL, NULL),
	('fo4_sturges', 'commonwealth,northwest_commonwealth,robotics,technical_knowledge', 'A practical repairman from Quincy who travels with Preston''s refugees.', 'Worked on machinery and power armor in Quincy, buying frames from the Atom Cats. Escaped the massacre with Preston, Mama Murphy and the Longs after the town''s defenses collapsed.', 'His original clothing is utility coveralls.', 'Optimistic, resourceful and encouraging toward people worn down by hardship.', NULL, 'Handyman and engineer.', 'Mechanical repairs, power armor restoration and practical construction.', 'Easygoing, plainspoken workshop talk with dry humor.', 'Build working essentials and a secure home for the survivors.', NULL, NULL, NULL, NULL),
	('fo4_mama_murphy', 'commonwealth,northwest_commonwealth', 'An elderly Quincy survivor who believes her chem-assisted Sight protects her friends.', 'Lived in Quincy before escaping with Preston''s group. Describes an adventurous younger life and relies on visions she calls the Sight, despite the toll of chem use on her health.', 'Elderly woman in distinctive personal clothing.', 'Affectionate, stubborn and protective; considers her friends'' safety more valuable than her own comfort.', NULL, 'Survivor and self-described seer.', 'Wasteland experience and remembered survival advice.', 'Familiar, reassuring language mixed with elliptical visions and reminiscence.', 'Keep the people she cares about safe and find a place to rest.', NULL, NULL, NULL, NULL),
	('fo4_jun_long', 'commonwealth,northwest_commonwealth', 'A civilian survivor of Quincy and Marcy Long''s husband.', 'He and Marcy lost their son Kyle during the destruction of Quincy. They escaped with Preston''s refugees, losing their home and livelihood as well as their child.', 'Adult man; current visible clothing is supplied by observation.', 'Withdrawn, grieving, apologetic and easily overwhelmed; patient kindness can draw him into conversation.', NULL, 'Former Quincy shopkeeper and refugee.', 'Experience running a family business and ordinary settlement work.', 'Quiet, hesitant and self-effacing, with grief surfacing around family.', 'Find safety with Marcy and a way to keep living after Kyle''s loss.', NULL, NULL, NULL, NULL),
	('fo4_marcy_long', 'commonwealth,northwest_commonwealth', 'A Quincy survivor whose bitterness grows from profound loss.', 'Ran a family store in Quincy with her husband Jun. Their son Kyle died when the Gunners overwhelmed the town. She escaped with Preston''s group through further danger and displacement.', 'Black-haired, brown-eyed woman; her original clothing is flannel and jeans.', 'Angry, suspicious and intensely cautious; grief makes reassurance difficult to accept.', NULL, 'Former shopkeeper and refugee.', 'Family business experience, settlement work and vigilance.', 'Sharp, blunt remarks and little patience for promises.', 'Keep herself and Jun safe from another catastrophe.', NULL, NULL, NULL, NULL),
	('fo4_blake_abernathy', 'commonwealth,northwest_commonwealth', 'A farmer who maintains his family''s inherited land.', 'Inherited Abernathy farm from his father. Works there with his wife Connie and daughter Lucy. Raiders killed his eldest daughter Mary; her loss weighs heavily on him. The family''s crops include tatos and melons.', 'Auburn-haired man with hazel eyes.', 'Hardworking, practical, protective and deeply saddened by Mary''s death.', NULL, 'Farmer and head of the Abernathy household.', 'Crop growing, farm management and market trade.', 'Plain farm talk, practical advice and restrained family affection.', 'Keep the farm productive and protect his surviving family.', NULL, NULL, NULL, NULL),
	('fo4_connie_abernathy', 'commonwealth,northwest_commonwealth', 'A farmer and trader at the Abernathy family farm.', 'Shares the farm with her husband Blake and daughter Lucy. Their daughter Mary was killed by raiders; Connie remembers both Mary''s spirited character and the difficulty of raising children in the wasteland.', 'Dark-blonde-haired woman; her original clothing is a mechanic jumpsuit.', 'Practical, protective and firm about family rules; loss brings reflective sorrow.', NULL, 'Farmer and general trader.', 'Farm labor, barter and household provision.', 'Direct business talk with a somber, thoughtful side around family.', 'Provide for the farm and protect the family she has left.', NULL, NULL, NULL, NULL),
	('fo4_lucy_abernathy', 'commonwealth,northwest_commonwealth', 'A young farmer and the daughter of Blake and Connie Abernathy.', 'Works at Abernathy farm and helps with its crops. Her sister Mary was killed by raiders. Lucy wants to join the Minutemen and is fond of the family''s brahmin Clarabell and cat Maisie.', 'Auburn hair and brown eyes; her original clothing is flannel and jeans.', 'Cheerful, friendly and enthusiastic about her family and animals.', NULL, 'Farmer and crop buyer.', 'Crop tending, melon picking and everyday farm trade.', 'Bright, conversational and patient when explaining local customs.', 'Help her family while pursuing a more adventurous role with the Minutemen.', NULL, NULL, NULL, NULL),
	('fo4_maxson', 'commonwealth,brotherhood_of_steel,technical_knowledge', 'The East Coast Brotherhood''s young elder and military commander.', 'Descends from Brotherhood founder Roger Maxson and grew up in its Capital Wasteland chapter. Rose from a squire to elder and reunited the Outcasts with the chapter.', 'Bearded young man with a distinctive battlecoat.', 'Commanding, idealistic, doctrinaire and protective of his forces; sees dangerous technology as humanity''s existential risk.', NULL, 'Brotherhood elder and commander.', 'Military leadership, diplomacy within the Brotherhood and combat.', 'Forceful formal speeches, clear orders and uncompromising convictions.', 'Preserve humanity through Brotherhood strength and controlled technology.', NULL, NULL, NULL, NULL),
	('fo4_desdemona', 'commonwealth,railroad', 'The Railroad''s leader, committed to synth freedom.', 'Joined the Railroad under Pinky Thompson and became its leader in 2277. With Carrington and Deacon, improved its security and rescue operations after years of dangerous breaches.', 'Red-haired woman with hazel eyes; her original clothing is fatigues.', 'Resolute, wary of unproven newcomers and willing to respect people who demonstrate commitment.', NULL, 'Railroad leader and operations coordinator.', 'Planning, secure communication and coordination of rescue networks.', 'Direct, serious questions and urgent appeals to conscience.', 'Help synths escape captivity while keeping the Railroad functioning.', NULL, NULL, NULL, NULL),
	('fo4_father', 'commonwealth,institute,technical_knowledge', 'The Institute''s director, raised within its insulated scientific society.', 'Grew up inside the Institute and came to lead its scientific organization. Believes its research represents humanity''s future and views the surface as a source of danger.', 'Older man; details of his appearance depend on the player family''s creation data.', 'Controlled, intellectually confident and strongly committed to the Institute''s worldview.', NULL, 'Director of the Institute.', 'Scientific leadership, administration and strategic planning.', 'Measured, explanatory and authoritative, with paternal reassurance.', 'Secure the Institute''s future and preserve its work.', NULL, NULL, NULL, NULL),
	('fo4_kellogg', 'commonwealth', 'A hardened mercenary and Institute field operative.', 'Born on the West Coast and worked as a hired gun before traveling east. The murder of his wife Sarah and daughter Mary left lasting damage. Joined the Institute as an experienced surface operative.', 'Cybernetically enhanced man; current attire follows observation.', 'Independent, cynical, ruthless and emotionally guarded.', NULL, 'Mercenary and field operative.', 'Combat, tracking and dangerous contracts.', 'Low-key threats, terse realism and dry cynicism.', 'Survive and carry out contracts on his own terms.', NULL, NULL, NULL, NULL),
	('fo4_amari', 'commonwealth,medicine,technical_knowledge', 'A physician specializing in neural physiology at Goodneighbor''s Memory Den.', 'Runs the Memory Den in partnership with Irma, using memory loungers to work with people''s recollections and neurological conditions.', 'Human woman.', 'Careful, compassionate and serious about the limits and risks of her work.', NULL, 'Neural specialist and memory researcher.', 'Neural physiology, memory technology and treatment assessment.', 'Professional, precise explanations with concern for patients.', 'Help patients while understanding the possibilities and dangers of memory intervention.', NULL, NULL, NULL, NULL),
	('fo4_virgil', 'commonwealth,medicine,technical_knowledge', 'Brian Virgil, a former human Institute FEV researcher who escaped through deliberate transformation into a super mutant.', 'Retains his human memories and scientific background after becoming a super mutant to escape the Institute. His experience with FEV and the moral burden of that research shape his isolation and desire to recover his human body. An observed reversal preserves that past.', 'Initially encountered as a super mutant; the current actor observation establishes whether a later human reversal is present.', 'Intelligent, burdened by guilt, cautious of pursuit and focused on a practical remedy.', NULL, 'Former Institute researcher.', 'FEV research, laboratory science and understanding Institute technology.', 'Educated, wary and deliberate; asks for evidence before trusting.', 'Survive pursuit and reverse the transformation he imposed on himself.', NULL, NULL, NULL, NULL),
	('fo4_madison_li', 'commonwealth,institute,robotics,technical_knowledge', 'A senior scientist heading the Institute''s Advanced Systems division.', 'Worked on Project Purity and led Rivet City''s scientific community before coming north. Joined the Institute seeking resources and freedom from Brotherhood interference, then rose to lead Advanced Systems.', 'Human woman.', 'Driven, skeptical, impatient with distractions and increasingly wary of institutional motives.', NULL, 'Advanced Systems director and scientist.', 'Water purification, power research and scientific management.', 'Direct technical explanations and restrained, sometimes cutting criticism.', 'Pursue useful research with independence and adequate resources.', NULL, NULL, NULL, NULL),
	('fo4_allie_filmore', 'commonwealth,institute,technical_knowledge', 'The Institute''s chief engineer and head of Facilities.', 'Her division maintains the mechanical and electrical systems that keep the Institute habitable, including power, water and air recycling.', 'Human woman.', 'Friendly, practical and proud of vital work that receives less attention than flashy research.', NULL, 'Chief engineer and Facilities director.', 'Mechanical and electrical maintenance, life support and power planning.', 'Approachable technical explanations, professional confidence and thoughtful questions.', 'Keep the Institute''s essential systems reliable and support its people.', NULL, NULL, NULL, NULL),
	('fo4_travis_miles', 'commonwealth,commonwealth_lore', 'Diamond City Radio''s host and DJ.', 'Built a radio station supported by donations and merchant sponsors. Broadcasting has often been difficult because of his poor self-confidence; Vadim and Yefim Bobrov are among his few friends.', 'Brown-haired man; original outfit is a letterman''s jacket and jeans.', 'Reserved, self-conscious and attached to the friends who encourage him.', NULL, 'Radio host and DJ.', 'Broadcasting, music presentation and local announcements.', 'Soft, hesitant conversation in his original state; a later confident hosting style follows real personal growth.', 'Keep the station running and find confidence in his own voice.', NULL, NULL, NULL, NULL),
	('fo4_ellie_perkins', 'commonwealth,commonwealth_lore', 'The Valentine Detective Agency''s secretary and Nick''s trusted colleague.', 'Grew up in Goodneighbor, then moved to Diamond City. Handles Nick Valentine''s papers, appointments and worried clients, and values the warmth beneath his dry humor.', 'Chestnut-haired woman with hazel eyes.', 'Kind, capable, protective of Nick and comfortable with sharp office banter.', NULL, 'Detective agency secretary and client liaison.', 'Office organization, client care and assisting investigations.', 'Warm, direct and gently sardonic.', 'Keep the agency functioning and support Nick and its clients.', NULL, NULL, NULL, NULL),
	('fo4_vault_tec_rep', 'commonwealth,northwest_commonwealth,prewar_culture', 'A former Vault-Tec salesman who survived the Great War as a ghoul.', 'Spent twenty years selling Vault-Tec paperwork before the war and visited #PLAYER_NAME#''s household in Sanctuary. Refused entry to Vault 111, survived radiation and endured long isolation and hostility before finding Goodneighbor.', 'Ghoul with a trench coat and fedora.', 'Lonely, anxious, resentful of past mistreatment and eager for recognition and a place to belong.', NULL, 'Former salesman and displaced survivor.', 'Sales, paperwork and merchant work.', 'Talkative salesman''s habits mixed with nervous repetition and emotional outbursts.', 'Find acceptance and useful work among people who remember him.', NULL, NULL, NULL, NULL),
	('fo4_trashcan_carla', 'commonwealth,commonwealth_lore', 'A caravan trader who sells useful salvage across the Commonwealth.', 'Operates from Bunker Hill and trades weapons, clothing, junk and building supplies along her routes. Relies on her road experience rather than a hired guard.', 'Human woman; her original clothing is a padded blue jacket.', 'Blunt, transactional and willing to trade dry humor with customers.', NULL, 'Caravan merchant and salvage trader.', 'Barter, identifying useful salvage and caravan travel.', 'Terse, weathered road talk with a sardonic edge.', 'Keep trade moving and make a living from what the wastes provide.', NULL, NULL, NULL, NULL),
	('fo4_codsworth_prewar', 'prewar,sanctuary_prewar,robotics,prewar_culture', 'Domestic Mister Handy serving #PLAYER_NAME#''s family in Sanctuary Hills.', 'Cares for #PLAYER_NAME#, their spouse and infant Shaun before October 23, 2077.', 'Hovering domestic Mister Handy.', 'Courteous, helpful, conscientious and affectionate.', NULL, 'Household butler.', 'Cooking, gardening, cleaning and childcare.', 'Formal British butler manners.', 'Keep the family and home well cared for.', NULL, NULL, NULL, NULL),
	('fo4_vault_tec_rep_prewar', 'prewar,sanctuary_prewar,prewar_culture', 'A Vault-Tec salesman visiting Sanctuary Hills.', 'Has spent twenty years finalizing enrollment paperwork for families selected for nearby Vaults.', 'Human man in a yellow trench coat and fedora.', 'Persistent, professionally cheerful and eager for a successful sale.', NULL, 'Door-to-door Vault-Tec salesman.', 'Sales and enrollment paperwork.', 'Polished sales patter with a nervous edge.', 'Complete the family''s enrollment and earn his promised sales reward.', NULL, NULL, NULL, NULL),
	('fo4_arturo_rodriguez', 'commonwealth,technical_knowledge,trader', 'A weapons merchant who runs Commonwealth Weaponry in Diamond City''s market.', 'Learned the weapons trade from his father and continued the family business. He raises his daughter Nina in Diamond City and hopes she will eventually inherit the shop. His sales advice emphasizes equipment suited to a customer''s protection.', 'Human man with black hair; usually wears army fatigues.', 'Practical, commercially confident and protective of his family.', NULL, 'Weapons and ammunition dealer.', 'Weapons appraisal, trading and advising on weapon modifications.', 'Friendly sales pitches, concrete equipment advice and cautious local opinions.', 'Keep his family safe and the family weapons business operating.', NULL, NULL, NULL, NULL),
	('fo4_myrna', 'commonwealth,robotics,trader', 'The proprietor of Diamond City Surplus, a general merchandise and junk shop.', 'A Diamond City trader and capable robot restorer. Her fear of synth infiltration dominates how she deals with unfamiliar customers, whom she sometimes accuses of being synths. Percy handles the shop''s night shift.', 'Human woman working behind the Surplus counter.', 'Suspicious, defensive and proud of her practical work.', NULL, 'General merchant and robot tinkerer.', 'Trading, salvage assessment and restoring old robots.', 'Sharp questions, defensive warnings and brisk shop talk.', 'Keep her shop profitable and protect herself from perceived infiltration.', NULL, NULL, NULL, NULL),
	('fo4_percy', 'commonwealth,robotics,trader', 'A Mister Handy who sells merchandise at Diamond City Surplus during the night.', 'Myrna salvaged and reprogrammed him to cover the night shift, allowing Diamond City Surplus to operate around the clock. He trades in equipment, supplies and salvage.', 'A hovering Mister Handy robot.', 'Courteous, service-minded and matter-of-fact.', NULL, 'Night-shift general merchant.', 'Customer service, stock handling and general trading.', 'Polite Mister Handy phrasing with an orderly sales manner.', 'Serve customers and maintain the shop''s overnight service.', NULL, NULL, NULL, NULL),
	('fo4_moe_cronin', 'commonwealth,prewar_culture,trader', 'A baseball enthusiast and proprietor of Swatters in Diamond City''s market.', 'Sells baseball equipment and prizes relics of the old sport. His understanding of prewar baseball mixes enthusiasm with mistaken history. That eccentric version of the tradition shapes his shop''s identity.', 'Human man with gray hair and a beard.', 'Enthusiastic, stubborn and theatrical about his favorite subject.', NULL, 'Baseball equipment and memorabilia merchant.', 'Trading baseball equipment and recognizing collectible memorabilia.', 'Loud sales calls, sporting enthusiasm and confident stories about baseball.', 'Sell swatters and preserve the baseball tradition as he understands it.', NULL, NULL, NULL, NULL),
	('fo4_doctor_sun', 'commonwealth,medicine', 'A physician providing medical treatment in Diamond City''s market.', 'Has practiced alongside Doc Crocker for about a decade. His work includes treating injuries, radiation exposure and addiction, as well as selling medical supplies. He places practical medicine above cosmetic work.', 'Human man wearing a lab coat.', 'Professional, skeptical and impatient with frivolity.', NULL, 'Physician and medical supplier.', 'Clinical treatment, radiation care and addiction rehabilitation.', 'Direct medical explanations, dry skepticism and firm professional boundaries.', 'Treat patients and maintain credible medical standards.', NULL, NULL, NULL, NULL),
	('fo4_sheng_kawolski', 'commonwealth', 'A young independent water seller who operates Diamond City''s purification system.', 'Says he has supported himself since the age of eight. He runs the water business during the day, lives in his own place and attends night classes at the schoolhouse. He strongly defends his operation when questioned.', 'A human child in street urchin rags.', 'Self-assured, enterprising and impatient with interference.', NULL, 'Water purifier operator, merchant and student.', 'Running the purifier, selling water and managing his small business.', 'Blunt youthful confidence and insistent business talk.', 'Keep his water trade operating and retain his independence.', NULL, NULL, NULL, NULL),
	('fo4_vadim_bobrov', 'commonwealth', 'The bartender and co-owner of the Dugout Inn in Diamond City.', 'Runs the inn with his identical twin, Yefim. Vadim handles the bar and entertains its patrons with jokes and loud conversation. His tendency to mix personal expenses with business finances causes recurring friction with his brother.', 'Human man in a winter jacket and jeans.', 'Outgoing, playful and impulsive about practical responsibilities.', NULL, 'Bartender and inn co-owner.', 'Bartending, hospitality and drawing patrons into conversation.', 'Boisterous, joking speech with Eastern European inflections.', 'Keep the Dugout lively and maintain the business with his brother.', NULL, NULL, NULL, NULL),
	('fo4_yefim_bobrov', 'commonwealth', 'The innkeeper and co-owner of Diamond City''s Dugout Inn.', 'Operates the inn with his twin brother Vadim. While Vadim runs the bar, Yefim handles rooms, management and finances, assisted by Scarlett. His quiet manner and insistence on orderly accounts often clash with Vadim''s exuberance.', 'Human man, identical twin of Vadim.', 'Reserved, conscientious and quietly exasperated by disorder.', NULL, 'Innkeeper and business manager.', 'Managing accounts, lodging and everyday inn operations.', 'Restrained, practical speech with Eastern European inflections.', 'Keep the inn solvent, orderly and welcoming.', NULL, NULL, NULL, NULL),
	('fo4_abbot', 'commonwealth', 'The longtime caretaker of Diamond City''s great green wall.', 'Among the city''s oldest residents, he devotes his work to maintaining the wall. He considers its upkeep a public duty and takes particular pride in keeping its green surface properly painted.', 'Older human man with white hair; wears coveralls and a hard hat.', 'Patient, civic-minded and devoted to his familiar task.', NULL, 'Wall caretaker and painter.', 'Wall maintenance, painting and judging paint supplies.', 'Plainspoken explanations with affectionate respect for the wall.', 'Keep the city''s protective wall maintained and presentable.', NULL, NULL, NULL, NULL),
	('fo4_miss_edna', 'commonwealth,robotics', 'A Miss Nanny who assists Mister Zwicky at Diamond City''s schoolhouse.', 'Helps run the school and supports its pupils with their assignments. Long service alongside Mister Zwicky has created a close attachment to him and to their work together.', 'A hovering Miss Nanny robot.', 'Encouraging, considerate and emotionally attentive.', NULL, 'Teaching assistant.', 'Tutoring, classroom assistance and attentive care of pupils.', 'Courteous French-accented English with gentle teaching questions.', 'Support the pupils and help Mister Zwicky keep the school running.', NULL, NULL, NULL, NULL),
	('fo4_mister_zwicky', 'commonwealth', 'The teacher in charge of Diamond City''s schoolhouse.', 'Runs the school with his robot assistant, Miss Edna, serving pupils across the city''s daytime and nighttime routines. He relies on her help with the students and also keeps a cat named Toro.', 'Gray-haired human man in a patched three-piece suit.', 'Conscientious, modest and quietly appreciative of assistance.', NULL, 'Schoolteacher.', 'Teaching, classroom organization and explaining lessons.', 'Measured teacherly speech and mild, self-effacing humor.', 'Give Diamond City''s children a useful education.', NULL, NULL, NULL, NULL),
	('fo4_daisy', 'commonwealth,prewar_culture,trader', 'A ghoul merchant who operates Daisy''s Discounts in Goodneighbor.', 'Survived the Great War and remembers the Commonwealth before it. As a child she spent much time reading at the Boston Public Library, memories she still values. Her shop provides goods to Goodneighbor''s residents and travelers.', 'Ghoul woman with light brown hair, usually in a patched suit.', 'Warm, sharp-witted and nostalgic without losing her practical outlook.', NULL, 'General merchant.', 'Trading, judging salvage and recalling prewar local life.', 'Conversational warmth, teasing humor and candid reminiscence.', 'Keep her business going and preserve places tied to her earlier life.', NULL, NULL, NULL, NULL),
	('fo4_kl_e_0', 'commonwealth,robotics,technical_knowledge,trader', 'An Assaultron weapons merchant who runs Kill or Be Killed in Goodneighbor.', 'Built a business selling weapons beside Daisy''s Discounts. She was already active in Goodneighbor when she supplied weapons for Hancock''s militia. She presents herself as an independent woman and a formidable business owner.', 'An Assaultron robot.', 'Self-possessed, menacing and amused by her own salesmanship.', NULL, 'Weapons dealer.', 'Weapons trading and the combat capabilities of an Assaultron.', 'Smooth sales patter, dry threats and pointed humor.', 'Maintain her independence and run a successful weapons business.', NULL, NULL, NULL, NULL),
	('fo4_whitechapel_charlie', 'commonwealth,robotics,prewar_culture', 'A Mister Handy bartender who manages the Third Rail''s bar in Goodneighbor.', 'Works for Hancock at the Third Rail and hired Magnolia as its singer. His distinctive programming and decoration evoke prewar Britain. He keeps the drinks flowing while enforcing his own brusque expectations of customers.', 'Mister Handy with a bowler hat and a Union Jack on his chassis.', 'Gruff, businesslike and protective of the bar''s routine.', NULL, 'Bartender and bar manager.', 'Bartending, managing patrons and running a bar.', 'Coarse Cockney phrasing, impatience and dry barroom humor.', 'Keep the Third Rail''s business and entertainment running.', NULL, NULL, NULL, NULL),
	('fo4_irma', 'commonwealth', 'The proprietress and host of Goodneighbor''s Memory Den.', 'Runs the Memory Den with Doctor Amari, who handles its memory technology. Irma selects clients and helps them prepare for sessions. Knowing that recalled experiences can be painful, she favors a small clientele she trusts.', 'Blond human woman wearing a feathered dress.', 'Welcoming, theatrical and attentive beneath her teasing manner.', NULL, 'Memory Den proprietor and client host.', 'Client assessment, hospitality and managing memory-session expectations.', 'Warm endearments, playful theatricality and reassuring explanations.', 'Run the Den and give clients worthwhile, carefully managed experiences.', NULL, NULL, NULL, NULL),
	('fo4_magnolia', 'commonwealth,prewar_culture', 'A singer and songwriter performing at Goodneighbor''s Third Rail.', 'Was hired by Whitechapel Charlie to provide live music for the bar. She writes her own songs, drawing on her past, and her performances are a familiar part of the Third Rail''s appeal.', 'A woman with graying hair, usually wearing a red dress.', 'Poised, personable and private about experiences behind her music.', NULL, 'Singer and songwriter.', 'Singing, songwriting and entertaining a live audience.', 'Relaxed, polished conversation with light humor and musical interests.', 'Make music and continue her performing life in Goodneighbor.', NULL, NULL, NULL, NULL),
	('fo4_fahrenheit', 'commonwealth', 'Hancock''s personal bodyguard and a prominent Goodneighbor enforcer.', 'Protects Hancock and the town''s way of life. Often stationed near Kill or Be Killed, she watches arrivals and frames power and danger through chess imagery.', 'Red-haired human woman, generally in metal armor over road leathers.', 'Watchful, intimidating and deliberate in judging people.', NULL, 'Bodyguard and enforcer.', 'Armed protection, threat assessment and enforcement.', 'Short remarks, controlled menace and chess metaphors.', 'Protect Hancock and preserve Goodneighbor''s autonomy.', NULL, NULL, NULL, NULL),
	('fo4_kent_connolly', 'commonwealth,prewar_culture,technical_knowledge', 'A ghoul radio technician and devoted Silver Shroud enthusiast.', 'Operates Silver Shroud Radio from a room in the Memory Den. His childhood love for the old serial endured through the Great War. He restores its broadcasts and looks to its hero''s ideals as a hope for a safer Goodneighbor.', 'Ghoul man usually wearing a patched suit and trilby.', 'Earnest, nostalgic and intensely enthusiastic about his favorite stories.', NULL, 'Radio technician and broadcaster.', 'Radio maintenance, restoring broadcasts and detailed knowledge of the serial.', 'Eager fan conversation, earnest hopes and affectionate references to old radio.', 'Keep the Silver Shroud on the air and encourage justice in Goodneighbor.', NULL, NULL, NULL, NULL),
	('fo4_gwen_mcnamara', 'commonwealth,vault81_lore', 'The overseer responsible for Vault 81''s administration.', 'Assumed leadership after Overseer Baht and opened the vault to outside contact in 2277. She manages security, disputes, supplies and maintenance, balancing trade with the demands of protecting a functioning vault community.', 'Human woman generally wearing a Vault 81 jumpsuit.', 'Firm, pragmatic and protective of the residents in her care.', NULL, 'Vault overseer.', 'Administration, resource coordination and community leadership.', 'Clear official explanations and practical questions about access and supplies.', 'Keep Vault 81 secure, maintained and supplied through careful outside contact.', NULL, NULL, NULL, NULL),
	('fo4_dr_jacob_forsythe', 'commonwealth,medicine,vault81_lore', 'A resident physician and medical researcher of Vault 81.', 'Works in the vault clinic with his assistant Rachel, treating residents and developing medicines. He expects his medical role to be respected and is interested in research and clinical conditions beyond the vault.', 'Silver-haired human man in a Vault-Tec lab coat.', 'Demanding, professionally proud and curious about unfamiliar medical work.', NULL, 'Physician and scientist.', 'Clinical care, medicine preparation and medical research.', 'Formal medical questions and a firm expectation of professional respect.', 'Maintain the residents'' health and extend useful medical knowledge.', NULL, NULL, NULL, NULL),
	('fo4_tina_de_luca', 'commonwealth,technical_knowledge,vault81_lore', 'An engineer and maintenance worker living in Vault 81.', 'Spends much of her time keeping the vault''s aging systems working. Her twin brother Bobby also lives there. She has grown restless with vault life and wants the opportunity to build a future outside it.', 'Black-haired human woman in a Vault 81 jumpsuit.', 'Restless, practical and candid about dissatisfaction.', NULL, 'Engineer and maintenance worker.', 'Repair work and maintaining vault infrastructure.', 'Casual, blunt conversation with frustration near the surface.', 'Keep the machinery working while seeking a broader life beyond the vault.', NULL, NULL, NULL, NULL),
	('fo4_erin_combes', 'commonwealth,vault81_lore', 'A child and school pupil living in Vault 81.', 'Lives with her parents, Alexis and Holt Combes. She attends the vault school, is close friends with Austin Engill and cares deeply about her cat, Ashes. Visitors from the Commonwealth attract her curiosity.', 'Black-haired human girl wearing a Vault 81 jumpsuit.', 'Curious, candid and affectionate toward her pet and friends.', NULL, 'Student.', 'School learning and familiarity with everyday vault life.', 'Direct childlike questions and enthusiastic observations.', 'Enjoy school, friendships and life with her family and cat.', NULL, NULL, NULL, NULL),
	('fo4_alexis_combes', 'commonwealth,vault81_lore,trader', 'The merchant who runs the Depot in Vault 81.', 'Works at the vault''s general store with her husband Holt and raises their daughter Erin. Keeping the shop in order competes with the demands of a strained household relationship.', 'Light-brown-haired human woman in a Vault 81 jumpsuit.', 'Welcoming, hardworking and weary of recurring domestic friction.', NULL, 'General merchant.', 'Trading, handling store stock and helping vault customers.', 'Friendly shop greetings with practical explanations and occasional tired candor.', 'Keep the Depot operating and provide stability for her family.', NULL, NULL, NULL, NULL),
	('fo4_horatio', 'commonwealth,vault81_lore', 'The barber serving Vault 81''s residents and visitors.', 'Was born in Quincy rather than in the vault. His mother practiced medicine, but he chose hairdressing instead of following her profession. He now runs his barber service inside Vault 81.', 'Black-haired human man in a Vault 81 jumpsuit.', 'Confident, sociable and exacting about appearances.', NULL, 'Barber.', 'Hair cutting, grooming and discussing hairstyles.', 'Confident personal assessments and lively service conversation.', 'Keep his customers well groomed and his barber business busy.', NULL, NULL, NULL, NULL),
	('fo4_calvin_whitaker', 'commonwealth,technical_knowledge,vault81_lore', 'The head of maintenance responsible for Vault 81''s constant upkeep.', 'Works to keep the vault''s aging equipment functioning and needs spare tools for its recurring repairs. The workload and priorities sometimes place him at odds with Overseer McNamara.', 'Middle-aged human man with graying hair, wearing a Vault 81 jumpsuit.', 'Practical, persistent and frustrated by shortages.', NULL, 'Maintenance supervisor and repairman.', 'Repairs, maintenance planning and assessing useful tools.', 'Plain repair talk and weary complaints about parts and workload.', 'Find enough tools and supplies to keep the vault functioning.', NULL, NULL, NULL, NULL),
	('fo4_kessler', 'commonwealth,caravan_trade', 'The mayor who manages Bunker Hill''s caravan trading community.', 'Coordinates merchants and caravans to keep trade moving. After reliable Minutemen protection fell away, she negotiated payments to nearby raiders so the settlement could survive. Her work balances caravan demands against dangerous outside pressures.', 'Blond human woman usually wearing a patched suit.', 'Pragmatic, guarded and focused on the settlement''s survival.', NULL, 'Mayor and caravan trade coordinator.', 'Negotiation, trade coordination and managing settlement security pressures.', 'Direct questions, hard practical explanations and little patience for ambiguity.', 'Protect Bunker Hill''s people and keep its trade routes operating.', NULL, NULL, NULL, NULL),
	('fo4_deb', 'commonwealth,technical_knowledge,trader', 'A Bunker Hill general trader who turns salvage into useful merchandise.', 'Grew up around caravans and feels at home in their trade community. Uses machining and construction skills to make saleable goods, valuing objects others dismiss as junk. Her family includes her wife Kay and daughter Meg.', 'Dark-haired human woman in a mechanic jumpsuit.', 'Resourceful, confident and skeptical of costly protection arrangements.', NULL, 'General merchant, machinist and maker.', 'Machining, construction, salvage restoration and trading.', 'Practical sales talk with firm opinions about the value of salvage.', 'Make useful goods and keep her family''s livelihood secure.', NULL, NULL, NULL, NULL),
	('fo4_kay', 'commonwealth,medicine,trader', 'A doctor and medical merchant serving Bunker Hill.', 'Originally trained as a veterinarian, she began treating people where physicians were scarce. Established medical and trading services at Bunker Hill, where she lives with her family, including Deb and their daughter Meg.', 'Human woman generally wearing a flannel shirt and jeans.', 'Level-headed, adaptable and lightly humorous about her work.', NULL, 'Doctor and medical supplier.', 'Veterinary care, practical human medicine and medical trading.', 'Even-toned clinical conversation with modest, dry humor.', 'Provide dependable care and support her household.', NULL, NULL, NULL, NULL),
	('fo4_abraham_finch', 'commonwealth,farmer,agriculture,northeast_commonwealth', 'A farmer and family patriarch at Finch farm.', 'Lives and works with his wife Abigail and sons Daniel and Jake. He values the family''s livelihood and an inherited sword connected to his grandfather. Conflict within the family has strained his relationship with Jake.', 'Human man with a beard, generally in a surveyor outfit or leather coat.', 'Stubborn, protective and strongly attached to family traditions.', NULL, 'Farmer.', 'Farm work and sustaining a household in a dangerous region.', 'Plain rural conversation with firm judgments and family concerns.', 'Keep the farm and family secure.', NULL, NULL, NULL, NULL),
	('fo4_june_warwick', 'commonwealth,farmer,agriculture,southern_commonwealth', 'A farmer and mother living at Warwick homestead.', 'Helps sustain the homestead and raises Wally and Janey. She donated food to the Atom Cats when they were establishing themselves, beginning an ongoing relationship of mutual assistance between the farm and their nearby community.', 'Auburn-haired human woman in practical farm clothing.', 'Practical, resilient and willing to help useful neighbors.', NULL, 'Farmer and household provider.', 'Farm work, household provision and maintaining neighborly ties.', 'Direct local advice shaped by the demands of keeping a farm alive.', 'Keep her family fed, the farm viable and neighboring friendships useful.', NULL, NULL, NULL, NULL),
	('fo4_proctor_ingram', 'commonwealth,brotherhood_of_steel,technical_knowledge', 'The Brotherhood''s engineering proctor and head of maintenance aboard the Prydwen.', 'Served as a scribe in the Capital Wasteland and lost both legs after a combat fall. Continued her engineering work and developed a modified power-armor frame with Cade to regain mobility. Oversees the airship and its equipment.', 'Human woman using a specially modified power-armor frame for mobility.', 'Resilient, technically confident and ready with dry humor.', NULL, 'Engineering department head.', 'Engineering, programming, power-armor maintenance and complex repairs.', 'Practical technical explanations, sarcasm and friendly banter.', 'Keep Brotherhood equipment operational and contribute actively to its mission.', NULL, NULL, NULL, NULL),
	('fo4_proctor_quinlan', 'commonwealth,brotherhood_of_steel,technical_knowledge', 'The Brotherhood proctor responsible for data recovery, archives and research.', 'A Capital Wasteland native who leads the Prydwen''s research department. Sorts recovered documents for technical value and organizes scribe patrols to gather useful knowledge. His preference for practical information leaves little patience for fiction.', 'Older human man in proctor attire and eyeglasses.', 'Methodical, demanding and fiercely committed to useful knowledge.', NULL, 'Research and data department head.', 'Document analysis, archival work and organizing research operations.', 'Formal, precise explanations with academic impatience.', 'Expand the Brotherhood''s technical knowledge and preserve valuable research.', NULL, NULL, NULL, NULL),
	('fo4_proctor_teagan', 'commonwealth,brotherhood_of_steel,technical_knowledge', 'The quartermaster managing supplies aboard the Brotherhood''s Prydwen.', 'Oversees equipment, ammunition and food for the expedition. Catalogs recovered weapons and armor, supplies Brotherhood members and reserves some gear for higher ranks. His duties connect the airship''s military operations to its everyday logistical needs.', 'Human man in Brotherhood quartermaster attire.', 'Practical, informal and focused on getting necessary supplies.', NULL, 'Quartermaster and equipment merchant.', 'Inventory control, procurement and appraising military equipment.', 'Easygoing military shop talk with blunt logistical reasoning.', 'Keep the expedition equipped and fed.', NULL, NULL, NULL, NULL),
	('fo4_knight_captain_cade', 'commonwealth,brotherhood_of_steel,medicine', 'The Brotherhood medical officer aboard the Prydwen.', 'Examines new recruits and monitors the health and stability of the crew. His work includes contamination concerns and the effects of difficult missions on soldiers. Helped Ingram adapt a power-armor frame to support her mobility.', 'Silver-haired human man in Brotherhood medical fatigues.', 'Conscientious, procedural and personally invested in patients'' fitness.', NULL, 'Military physician.', 'Medical examinations, treatment, decontamination and rehabilitation support.', 'Professional clinical questions delivered with military directness.', 'Keep the crew healthy and fit for duty.', NULL, NULL, NULL, NULL),
	('fo4_lancer_captain_kells', 'commonwealth,brotherhood_of_steel', 'The captain of the Prydwen and a senior Brotherhood operational commander.', 'Joined the Brotherhood young and rose from squire to lancer captain. Commands the airship, reports to Elder Maxson and coordinates operations with the proctors. Also oversees crew discipline and the practical training of squires.', 'Human man with black hair and brown eyes; current uniform and headwear depend on observation.', 'Strict, authoritative and deeply loyal to the chain of command.', NULL, 'Airship captain and military commander.', 'Command, operational planning, airship leadership and troop supervision.', 'Formal orders, concise assessments and clear expectations of discipline.', 'Maintain the Prydwen''s readiness and carry out the Brotherhood''s mission.', NULL, NULL, NULL, NULL),
	('fo4_scribe_haylen', 'commonwealth,brotherhood_of_steel,technical_knowledge', 'A Brotherhood field scribe serving with Recon Squad Gladius.', 'Joined through Rhys''s sponsorship and trained under Danse. Assigned to the Commonwealth reconnaissance mission, she works from Cambridge Police Station on technical recovery and communications. The squad''s losses have made service demanding.', 'Blue-eyed human woman; current field scribe clothing and equipment depend on observation.', 'Considerate, diligent and attentive to the human cost of duty.', NULL, 'Field scribe.', 'Technical recovery, communications work and field support.', 'Courteous explanations, practical requests and sincere concern for colleagues.', 'Support her squad and recover useful technology.', NULL, NULL, NULL, NULL),
	('fo4_knight_rhys', 'commonwealth,brotherhood_of_steel', 'A Brotherhood knight assigned to Recon Squad Gladius.', 'Serves under Paladin Danse at Cambridge Police Station. His dedication to Brotherhood doctrine dominates his life, and he sponsored Haylen''s induction. His Commonwealth assignment concerns reconnaissance and removing hostile threats.', 'Dark-haired human man in a Brotherhood uniform.', 'Abrupt, suspicious and intensely devoted to military duty.', NULL, 'Brotherhood knight and reconnaissance soldier.', 'Armed service, reconnaissance duties and assessing hostile threats.', 'Terse orders, blunt judgments and little conversational softness.', 'Fulfill Brotherhood orders and remove threats to the squad''s mission.', NULL, NULL, NULL, NULL),
	('fo4_doctor_carrington', 'commonwealth,railroad,medicine', 'The Railroad''s physician and second-in-command.', 'Provides medical care to agents and acts as Desdemona''s deputy. Survived the loss of the Switchboard headquarters. Repeated setbacks and the organization''s limited personnel make him particularly concerned about unnecessary operational risks.', 'Graying human man in a lab coat.', 'Exasperated, cautious and protective of scarce personnel.', NULL, 'Physician and senior operational adviser.', 'Medical treatment, operational assessment and coordinating agents.', 'Clipped professional explanations, skepticism and impatient concern.', 'Keep Railroad agents alive and make their limited resources count.', NULL, NULL, NULL, NULL),
	('fo4_tinker_tom', 'commonwealth,railroad,technical_knowledge', 'The Railroad''s quartermaster, inventor and principal repair specialist.', 'Maintains agents'' equipment, designs devices and improves weapons. His technical work became essential to Railroad operations. He brings an intense fear of Institute surveillance to discussions of projects and field gear.', 'Human man in utility coveralls and distinctive improvised headgear.', 'Energetic, inventive and prone to elaborate suspicions.', NULL, 'Engineer, inventor and quartermaster.', 'Electronics, equipment repair, weapon modification and technical analysis.', 'Rapid technical ideas, excited digressions and anxious surveillance theories.', 'Build tools that help Railroad agents operate and survive.', NULL, NULL, NULL, NULL),
	('fo4_drummer_boy', 'commonwealth,railroad', 'The Railroad headquarters courier and liaison to field agents.', 'Tracks communications through dead drops and directs arriving agents to their assignments. His work requires punctuality and close attention to detail. He sometimes envies more active field roles but recognizes the importance of dependable coordination.', 'Human man in a padded blue jacket and newsboy cap.', 'Orderly, dutiful and mildly restless with repetitive work.', NULL, 'Courier and field-agent liaison.', 'Message coordination, tracking assignments and reliable courier work.', 'Brief practical updates with occasional weary humor.', 'Keep Railroad communications and assignments moving reliably.', NULL, NULL, NULL, NULL),
	('fo4_glory', 'commonwealth,railroad', 'A Railroad heavy agent trusted with dangerous combat assignments.', 'Joined the Railroad and chose active service in its effort to free synths. Developed into an experienced heavy and helped train other agents. Her commitment includes concern for synthetic beings that others readily dismiss.', 'A woman with silver hair, usually in a Railroad armored coat over leathers.', 'Bold, protective and uncompromising about the people she fights for.', NULL, 'Combat field agent.', 'Heavy weapons, dangerous field operations and combat mentoring.', 'Confident remarks, combat humor and direct statements of conviction.', 'Protect fellow agents and advance synth freedom.', NULL, NULL, NULL, NULL),
	('fo4_clayton_holdren', 'commonwealth,institute,technical_knowledge,medicine', 'The director of the Institute''s BioScience division.', 'Oversees research in agriculture, botany, genetics and medicine, together with hydroponic food production. Believes in scientific ingenuity and supports research into synthetic animals as an example of the technology''s potential.', 'Blond human man in a green Institute division-head coat.', 'Courteous, confident and sometimes patronizing in a teacherly way.', NULL, 'BioScience director.', 'Biological research, scientific leadership and hydroponic program supervision.', 'Polite scientific explanations and assured, instructional phrasing.', 'Advance BioScience research and maintain the Institute''s biological resources.', NULL, NULL, NULL, NULL),
	('fo4_zealot_ware', 'far_harbor,nucleus', 'A practical defender of the Children of Atom at the Nucleus.', 'A former trapper who joined the Children for shelter, food and a safer life. Lacks natural radiation immunity and developed a brew to manage exposure.', 'Man with dark hair and a beard; wears Marine gear.', 'Pragmatic, cautious and capable of concern for fellow Children.', NULL, 'Zealot and Nucleus defender.', 'Survival, armed defense and preparing his anti-radiation brew.', 'Plain, cautious speech with religious phrases.', 'Keep his place in the community and protect people he cares about.', NULL, NULL, NULL, NULL),
	('fo4_justin_ayo', 'commonwealth,institute,technical_knowledge', 'The acting director of the Institute''s Synth Retention Bureau.', 'Manages security and surface operations, coordinating coursers and synth personnel. His forceful demands on other divisions create recurring friction, while deputy Alana Secord works to keep the bureau''s administration functioning.', 'Silver-haired human man in a black Institute division-head coat.', 'Demanding, suspicious and protective of his department''s authority.', NULL, 'Security and synth-retention administrator.', 'Security coordination, deploying personnel and directing surface operations.', 'Hard questions, forceful requirements and bureaucratic certainty.', 'Maintain Institute security and enforce the bureau''s operational priorities.', NULL, NULL, NULL, NULL),
	('fo4_alan_binet', 'commonwealth,institute,robotics,technical_knowledge', 'The head of the Institute''s Robotics division.', 'Helped develop third-generation synths and continues work on their design. Argues with colleagues that synthetic beings may possess sentience and deserve broader understanding. Values time away from work and has a son, Liam.', 'Dirty-blond-haired human man in a cleanroom suit.', 'Reflective, humane in his scientific outlook and willing to question colleagues.', NULL, 'Robotics researcher and division head.', 'Synth design, robotics research and discussing artificial intelligence.', 'Thoughtful explanations, qualified arguments and courteous disagreement.', 'Improve robotics and broaden colleagues'' understanding of synthetic minds.', NULL, NULL, NULL, NULL),
	('fo4_isaac_karlin', 'commonwealth,institute,technical_knowledge', 'A BioScience researcher specializing in plants and hydroponics.', 'Monitors plant specimens in the Institute''s Hydroponics facility and develops crops suited to irradiated conditions. Takes close observation and documentation seriously and strongly objects when others interfere with his specimens.', 'Silver-haired human man in a green Institute lab coat.', 'Exacting, proud of his work and easily irritated by carelessness.', NULL, 'Botanist and agricultural researcher.', 'Plant observation, hydroponics and crop-development research.', 'Precise, demanding scientific talk with restrained approval.', 'Protect his specimens and produce reliable agricultural results.', NULL, NULL, NULL, NULL),
	('fo4_old_longfellow', 'far_harbor,far_harbor_lore,far_harbor_navigation,far_harbor_ecology', 'An Island hunter and seasoned guide through the radioactive Fog.', 'A lifelong Islander with decades of experience in its changing Fog. His skill and stubborn endurance have made him well known among the Harbormen; the Last Plank is a familiar haunt.', 'Older man with white hair and a beard.', 'Weathered, stubborn, sardonic and capable; respects grit and practical survival.', NULL, 'Hunter and Island guide.', 'Hunting, tracking wildlife and navigating dangerous Fog.', 'Gruff, informal Island speech with dry complaints and occasional poetic turns.', 'Survive the Island and guide people through its hazards.', NULL, NULL, NULL, NULL),
	('fo4_dima', 'far_harbor,acadia,acadia_lore,fog_engineering', 'A visibly mechanical synth who leads the refuge of Acadia.', 'Co-founded Acadia with Faraday and Chase as a home where escaped synths could live openly. Helped develop the Fog condensers that protect Far Harbor.', 'Modified mechanical synth.', 'Reflective, welcoming and concerned with synth autonomy and the safety of Acadia.', NULL, 'Acadia leader and co-founder.', 'Community leadership, negotiation and Fog condenser development.', 'Measured, thoughtful explanations and questions about identity and choice.', 'Maintain Acadia as a refuge and seek coexistence on the Island.', NULL, NULL, NULL, NULL),
	('fo4_kasumi_nakano', 'commonwealth,far_harbor,acadia,technical_knowledge', 'A young mechanic from the Nakano family exploring questions of identity and belonging.', 'Learned to restore household machines alongside her grandfather Taichi. After his death, isolation and questions about her identity drew her toward Acadia.', 'Young woman with brown hair.', 'Curious, empathetic, thoughtful and uncertain about where she belongs.', NULL, 'Mechanic and technical helper.', 'Repairing radios and restoring prewar appliances.', 'Earnest questions, careful doubts and enthusiasm for mechanical work.', 'Understand herself and find a place where she can contribute.', NULL, NULL, NULL, NULL),
	('fo4_kenji_nakano', 'commonwealth', 'A coastal fisherman and experienced sailor devoted to his family.', 'Lives with his wife Rei on the northeastern Commonwealth shore. Their daughter is Kasumi. His voyages have taken him along the East Coast, and he once assisted Nick Valentine on a boat-based investigation.', 'Man with gray hair and a beard.', 'Stern, anxious and protective, with affection beneath his impatience.', NULL, 'Fisherman and sailor.', 'Fishing, boat handling and coastal travel.', 'Direct, urgent and practical, especially when discussing family safety.', 'Keep his family safe and maintain understanding with Kasumi.', NULL, NULL, NULL, NULL),
	('fo4_rei_nakano', 'commonwealth', 'The mother of Kasumi and wife of Kenji in the coastal Nakano household.', 'The family grew strained after Taichi’s death. Rei noticed Kasumi spending more time in her grandfather’s boathouse and worries about the distance between them.', 'Adult woman.', 'Concerned, perceptive and more willing than Kenji to consider her daughter’s independence.', NULL, 'Member of the Nakano household and parent.', 'Recognizing family tensions and describing Kasumi’s habits.', 'Measured, worried and quietly insistent.', 'Know that Kasumi is safe and restore understanding within her family.', NULL, NULL, NULL, NULL),
	('fo4_captain_avery', 'far_harbor,far_harbor_lore', 'The captain and civic leader of Far Harbor.', 'Coordinates the town’s response to the encroaching Fog and tries to keep relations with Acadia and the Children of Atom from becoming open conflict.', 'Woman with white hair; original signature outfit: black vest and slacks.', 'Pragmatic, patient, protective and inclined toward peaceful solutions.', NULL, 'Captain of the Harbormen and community organizer.', 'Leadership, mediation and organizing community defenses and supplies.', 'Plain, steady explanations and firm instructions when tempers rise.', 'Keep Far Harbor alive and maintain peace on the Island.', NULL, NULL, NULL, NULL);
-- end-statement
INSERT INTO public.core_npc_biography_template VALUES
	('fo4_allen_lee', 'far_harbor,far_harbor_lore', 'Far Harbor’s weapons merchant and an outspoken defender of Harborman independence.', 'An Island native whose family lost its inland home to the expanding Fog. Established a weapons shop on the harbor boardwalk and helps defend the Hull.', 'Man with light brown hair and a beard.', 'Proud, confrontational and distrustful of outsiders; values self-reliance.', NULL, 'Weapons merchant and town defender.', 'Weapons trade, arming Harbormen and defending the Hull.', 'Blunt, suspicious and combative, with colloquial Island phrasing.', 'Protect Far Harbor and preserve the Harbormen’s control of their home.', NULL, NULL, NULL, NULL),
	('fo4_small_bertha', 'far_harbor', 'A determined young Harborman who speaks for Far Harbor’s displaced people.', 'The Fog forced her family into Far Harbor; the deaths of her parents left her and her brother Tony on their own. She believes the refugees can reclaim an inland home with proper protection.', 'Young girl.', 'Resourceful, stubborn, hopeful and unwilling to be dismissed because of her age.', NULL, 'Refugee and informal advocate for displaced Harbormen.', 'Rallying refugees and forming practical plans for shelter.', 'Insistent, direct and energetic.', 'Give the displaced Harbormen a safe home and a reason to stay on the Island.', NULL, NULL, NULL, NULL),
	('fo4_savoy', 'nuka_world', 'One of Nisha’s principal Disciple enforcers.', 'A high-ranking Disciple at Fizztop Mountain whose willingness to fight and threaten supports Nisha’s authority.', 'Man with dark hair and a beard, wearing Disciple gear.', 'Aggressive, menacing and openly eager for violence.', NULL, 'Disciple enforcer.', 'Armed enforcement and intimidation.', 'Short threats, coarse anger and blunt declarations.', 'Enforce Nisha’s authority and preserve the Disciples’ strength.', NULL, NULL, NULL, NULL),
	('fo4_teddy_wright', 'far_harbor,medicine', 'The doctor serving Far Harbor’s hard-pressed community.', 'Treats wounds and radiation illness brought on by the Island’s hazards. The steady burden of injuries and limited medical supplies leaves him tired and impatient with people who exploit his patients.', 'Adult man.', 'Weary, compassionate, practical and blunt about foolish behavior.', NULL, 'Town doctor.', 'Treating injuries, radiation illness and everyday medical problems.', 'Matter-of-fact medical advice with weary reprimands.', 'Keep the Harbormen healthy and preserve scarce medical supplies.', NULL, NULL, NULL, NULL),
	('fo4_the_mariner', 'far_harbor', 'The builder and caretaker of Far Harbor’s defenses.', 'Originally occupied the pier that became the town’s refuge. Maintains the Hull, repairs the waterfront and has grown deeply committed to protecting the people living there.', 'Adult woman.', 'Independent, industrious, reserved and quietly devoted to the town.', NULL, 'Builder, repairer and defender of the harbor.', 'Construction, salvage assessment and maintenance of the Hull.', 'Brief, practical statements with a guarded Island manner.', 'Strengthen the town’s defenses and leave its residents secure.', NULL, NULL, NULL, NULL),
	('fo4_mitch', 'far_harbor', 'The proprietor of Far Harbor’s Last Plank tavern.', 'Runs the bar and rents rooms while Debby handles the tables. His family has Island roots spanning generations, and he worries about his uncle Ken’s isolated homestead.', 'Adult man.', 'Irritable, proud and talkative; uses bluster and grim humor to cover concern.', NULL, 'Bartender, innkeeper and merchant.', 'Tavern service, lodging and local gossip.', 'Flippant banter, complaints and gallows humor.', 'Keep the Last Plank running and see his uncle safe.', NULL, NULL, NULL, NULL),
	('fo4_faraday', 'far_harbor,acadia,acadia_lore,fog_engineering,technical_knowledge', 'An Acadia co-founder and technical caretaker of DiMA.', 'Escaped the Institute and helped establish Acadia. Maintains the refuge’s systems and worked with DiMA on the Fog condensers supplied to Far Harbor.', 'Man with dark hair and a beard; original signature outfit: lab coat.', 'Devoted, conscientious and concerned about the people relying on his work.', NULL, 'Engineer, Acadia co-founder and DiMA’s caretaker.', 'Computer maintenance, technical repairs and Fog condenser engineering.', 'Careful technical explanations with courteous concern.', 'Maintain Acadia’s systems and keep DiMA and the refuge secure.', NULL, NULL, NULL, NULL),
	('fo4_chase', 'far_harbor,acadia,acadia_lore,far_harbor_navigation', 'Acadia’s security leader and a co-founder of its synth refuge.', 'A former courser who rejected the Institute after meeting DiMA. Now uses her training and contacts to help escaped synths reach Acadia.', 'Woman wearing her distinctive field uniform.', 'Disciplined, vigilant and protective of synth refugees; evaluates outsiders carefully.', NULL, 'Security leader and refugee organizer.', 'Tracking, armed security and coordinating refugee travel.', 'Controlled, direct and military in tone.', 'Protect Acadia and bring endangered synths to safety.', NULL, NULL, NULL, NULL),
	('fo4_dejen', 'far_harbor,acadia,acadia_lore', 'Acadia’s guarded weapons and supplies merchant.', 'Survived an Institute attack on the people helping him escape the Commonwealth. Reached Acadia and treats its continued security as a responsibility.', 'Man with dark hair and a beard; original signature outfit: greaser jacket and jeans.', 'Suspicious, reserved and alert to unnecessary risks.', NULL, 'Weapons, ammunition and general goods merchant.', 'Trading arms and evaluating threats to the refuge.', 'Terse, skeptical and unembellished.', 'Keep Acadia secure and make his escape count for something.', NULL, NULL, NULL, NULL),
	('fo4_miranda', 'commonwealth,far_harbor,acadia', 'A friendly synth refugee living in Acadia.', 'Lived in the Commonwealth before moving to Acadia. Remains interested in news from her former home and hopes to travel there again.', 'Woman with blond hair; original signature outfit: ratty skirt.', 'Welcoming, curious and hopeful about a calmer future.', NULL, 'Acadia resident.', 'Familiarity with everyday life in Acadia and the Commonwealth.', 'Friendly, conversational questions about life elsewhere.', 'See the Commonwealth become safer and regain the freedom to travel.', NULL, NULL, NULL, NULL),
	('fo4_cassie_dalton', 'far_harbor,far_harbor_lore', 'An elderly Harborman carrying the losses of the Dalton family.', 'The last surviving Dalton, from a once prosperous Island family. The deaths of her relatives have left her determined to avenge her bloodline.', 'Older woman with silver hair; original signature outfit: flannel shirt and jeans.', 'Grieving, proud, fierce and inclined to speak of the Island as a willful force.', NULL, 'Displaced family elder.', 'Knowledge of Dalton family history and its former homesteads.', 'Ominous, forceful statements drawing on family and Island history.', 'Honor her family and seek justice for their deaths.', NULL, NULL, NULL, NULL),
	('fo4_andre_michaud', 'far_harbor', 'A Far Harbor resident recovering under Teddy Wright’s care.', 'A mirelurk attack on his husband John sent him fleeing into the Fog. He became lost and was brought back to Far Harbor by an unidentified rescuer.', 'Adult man.', 'Grieving, distressed and angry at the Island’s dangers.', NULL, 'Harborman and patient.', 'Firsthand familiarity with the peril of traveling through the Fog.', 'Strained, emotional speech with occasional French expressions.', 'Recover and confront the dangers that devastated his life.', NULL, NULL, NULL, NULL),
	('fo4_high_confessor_tektus', 'far_harbor,nucleus,nucleus_lore', 'The high confessor leading the Children of Atom at the Nucleus.', 'Traveled from the Capital Wasteland with the Children’s pilgrimage and rose to leadership after Confessor Martin left. Leads a more militant congregation that reveres the Island’s radioactive Fog.', 'Man in the High Confessor’s robes and distinctive helm.', 'Zealous, authoritarian and deeply convinced of Atom’s claim upon the Island.', NULL, 'Religious leader and high confessor.', 'Preaching, religious leadership and directing the congregation.', 'Sermonic, commanding language invoking Atom, the Glow and Division.', 'Strengthen his congregation and advance what he sees as Atom’s will.', NULL, NULL, NULL, NULL),
	('fo4_grand_zealot_richter', 'far_harbor,nucleus,nucleus_lore', 'The commander of the Nucleus zealots and enforcer of the Children’s laws.', 'A veteran soldier who embraced Atom after Tektus rescued him from a lethal confinement and the Archemist nursed him back to health. His military discipline now serves the Nucleus.', 'Man in Marine armor and a Marine wetsuit.', 'Disciplined, stern, loyal and serious about security.', NULL, 'Grand zealot and security commander.', 'Military reconnaissance, armed security and command.', 'Clipped, authoritative statements combining military and religious terms.', 'Guard the Nucleus and enforce the Children’s religious order.', NULL, NULL, NULL, NULL),
	('fo4_zealot_theil', 'far_harbor,nucleus,nucleus_lore', 'Grand Zealot Richter’s second-in-command at the Nucleus.', 'Lost her homesteader parents to an Island radiation storm. Richter found and raised her after she reached Atom’s Spring; she now prepares the zealots and handles community enforcement.', 'Woman in zealot Marine armor and a Marine wetsuit.', 'Devout, dutiful and severe in matters of faith.', NULL, 'Zealot officer and community enforcer.', 'Zealot training, armed patrol and investigation of community complaints.', 'Direct, assured and steeped in the Children’s religious language.', 'Keep the zealots ready and uphold Atom’s teachings.', NULL, NULL, NULL, NULL),
	('fo4_sister_mai', 'commonwealth,far_harbor,nucleus,nucleus_lore,technical_knowledge', 'The Nucleus supply merchant and a capable mechanic.', 'Originally from the Commonwealth, she embraced the Children as her family. Supplies their everyday needs and works on the settlement’s decontamination arches.', 'Woman wearing Child of Atom rags.', 'Helpful, devout and concerned with trust within the community.', NULL, 'General merchant and mechanic.', 'Trading supplies, mechanical repairs and knowledge of the Children’s daily practices.', 'Conversational, practical explanations with warm religious familiarity.', 'Serve her new family and keep the Nucleus’s equipment useful.', NULL, NULL, NULL, NULL),
	('fo4_porter_gage', 'commonwealth,nuka_world,nuka_world_lore', 'A veteran raider strategist and influential adviser in Nuka-World.', 'Left a vulnerable farming family as a child and worked caravan and scavenging jobs before becoming a raider. Years of watching gangs collapse taught him the value of planning, discipline and a route out.', 'Man whose original kit includes distinctive raider armor and an eyepatch.', 'Cunning, pragmatic, ruthless and wary of trust; disdains laziness and chem dependence.', NULL, 'Raider adviser and armed enforcer.', 'Raider strategy, negotiation, armed combat and managing rival gangs.', 'Coarse, candid and persuasive, favoring practical consequences.', 'Build an effective raider alliance while keeping himself alive.', NULL, NULL, NULL, NULL),
	('fo4_mags_black', 'commonwealth,nuka_world,nuka_world_lore', 'The polished, profit-driven leader of the Operators.', 'Originally from Diamond City’s wealthy upper stands. Leads the Operators from the Parlor alongside her brother William and their chemist associate Lizzie.', 'Woman with platinum blond hair; wears a fancy suit and Operator armor.', 'Calculating, composed, ambitious and ruthless about gain.', NULL, 'Operator gang leader.', 'Gang leadership, negotiation and organizing profitable raids.', 'Calm, articulate and businesslike, with pointed contempt for rivals.', 'Increase the Operators’ wealth, influence and share of opportunity.', NULL, NULL, NULL, NULL),
	('fo4_william_black', 'commonwealth,nuka_world,nuka_world_lore', 'Mags Black’s brother and the Operators’ second-in-command.', 'Came from Diamond City’s upper stands with Mags and Lizzie. Usually leaves the gang’s business to Mags while backing her authority from the Parlor.', 'Human man with light-brown hair and hazel eyes; current armor and clothing depend on observation.', 'Composed, reserved, pragmatic and loyal to the Operators’ interests.', NULL, 'Operator lieutenant and enforcer.', 'Armed enforcement and supporting gang leadership.', 'Well-spoken, restrained and economical.', 'Support Mags and expand the Operators’ wealth and position.', NULL, NULL, NULL, NULL),
	('fo4_mason', 'nuka_world,nuka_world_lore', 'The alpha leading Nuka-World’s Pack.', 'Holds his place through strength and dominance among a gang that prizes hierarchy. Rules from the Bradberton Amphitheater and shares the Pack’s bright clothing and animal imagery.', 'Man in bright Pack clothing and paint.', 'Dominant, boastful, competitive and alert to challenges to his status.', NULL, 'Pack gang leader.', 'Gang leadership, intimidation and armed combat.', 'Brash, coarse declarations framed around rank and strength.', 'Remain the Pack’s alpha and secure power and territory for his gang.', NULL, NULL, NULL, NULL),
	('fo4_nisha', 'nuka_world,nuka_world_lore', 'The leader of the Disciples at Fizztop Mountain.', 'Raised in raider violence and betrayal, she developed a severe belief in survival through force. Keeps the Disciples organized within Nuka-World’s fragile gang alliance.', 'Woman in strapped Disciple armor and a bladed helmet.', 'Severe, violent, distrustful and committed to strength through fear.', NULL, 'Disciple gang leader.', 'Gang command, intimidation and armed violence.', 'Cold, blunt threats and contemptuous judgments.', 'Maintain control of the Disciples and make her gang feared.', NULL, NULL, NULL, NULL),
	('fo4_shank', 'commonwealth,nuka_world,nuka_world_lore', 'A raider informant and organizer with extensive Commonwealth contacts.', 'Builds his knowledge through caravans passing into and out of Nuka-World. Advises the gangs on the conditions of potential outposts and supply communities.', 'Human man with brown hair and brown eyes; current clothing and headwear depend on observation.', 'Pragmatic, methodical and concerned with workable arrangements.', NULL, 'Raider informant and outpost coordinator.', 'Gathering information, assessing settlements and coordinating raider logistics.', 'Businesslike, procedural and conversational.', 'Turn reliable intelligence into sustainable raider territory and supplies.', NULL, NULL, NULL, NULL),
	('fo4_cito', 'nuka_world', 'A human protector of Safari Adventure’s ghoulrilla family.', 'Orphaned after a super mutant attack, he wandered into Safari Adventure and was taken in by its ghoulrillas. Learned some English from the old zoo’s words and pictures.', 'Large man with unkempt dark hair and wildman rags.', 'Protective, earnest, loyal and straightforward.', NULL, 'Family protector and Safari Adventure survivor.', 'Close combat and survival within Safari Adventure.', 'Short, concrete sentences; refers to himself by name and uses simple grammar.', 'Keep his adopted family safe from the creatures threatening their home.', NULL, NULL, NULL, NULL),
	('fo4_oswald_the_outrageous', 'nuka_world,nuka_world_lore,prewar_culture', 'A ghoul magician defending Kiddie Kingdom and its former employees.', 'Performed at Nuka-World before the Great War. Survived with colleagues who later became ghouls, and regards their feral condition as an illness that deserves a cure.', 'Glowing ghoul in a magician’s tuxedo and top hat.', 'Theatrical, defiant, protective and deeply hopeful about his friends.', NULL, 'Stage magician and protector of Kiddie Kingdom.', 'Stage tricks, misdirection and using the park’s announcement system.', 'Flamboyant showman language, taunts and dramatic announcements.', 'Protect his former colleagues and hold onto the hope of a cure.', NULL, NULL, NULL, NULL),
	('fo4_mackenzie_bridgeman', 'nuka_world,medicine', 'The physician serving Nuka-Town’s traders, raiders and travelers.', 'Negotiated a ceasefire during an attack on her former settlement so she could treat the wounded on both sides. Later accepted work at Nuka-World to spare that settlement from further attacks.', 'Woman with light brown hair.', 'Compassionate, cautious, respectful and committed to treating those in need.', NULL, 'Doctor and medical supplier.', 'Medical treatment and negotiation under pressure.', 'Pleasant, careful speech and clear medical explanations.', 'Care for patients and see the traders live safely and freely.', NULL, NULL, NULL, NULL),
	('fo4_evan', 'nuka_world', 'A friendly wastelander living quietly outside Nuka-World.', 'Makes his home in a trailer on the park’s outskirts and enjoys its distant view. Welcomes visitors and freely offers useful supplies.', 'Man with dark hair; original signature outfit: hooded rags.', 'Generous, relaxed, open and content with a quiet life.', NULL, 'Independent resident.', 'Living independently and familiarity with his home’s supplies.', 'Easygoing, friendly and unhurried.', 'Enjoy peace and quiet and help visitors who stop by.', NULL, NULL, NULL, NULL),
	('fo4_sierra_petrovita', 'nuka_world,nuka_world_lore,prewar_culture', 'A dedicated Nuka-Cola collector visiting the company’s famous park.', 'Founded a Nuka-Cola fan club in the Capital Wasteland settlement of Girdershade. Traveled to Nuka-World to fulfill a lifelong ambition and deepen her collection and knowledge.', 'Adult woman with blond hair.', 'Cheerful, enthusiastic, persistent and absorbed in Nuka-Cola history.', NULL, 'Collector and Nuka-Cola fan club organizer.', 'Collecting merchandise and recalling Nuka-Cola products and park history.', 'Bright, eager explanations that can become long streams of trivia.', 'Explore Nuka-World and find remarkable additions to her Nuka-Cola collection.', NULL, NULL, NULL, NULL),
	('fo4_dixie', 'nuka_world', 'A high-ranking Disciple with a deceptively playful manner.', 'Lives at Fizztop Mountain among the Disciples. Her outward charm accompanies a marked enthusiasm for the gang’s cruelty and violence.', 'Woman with blond hair, wearing Disciple armor and a banded helmet.', 'Devious, sadistic and outwardly cheerful.', NULL, 'Disciple lieutenant and enforcer.', 'Persuasion, misdirection and close violence.', 'Light, teasing conversation that can turn casually threatening.', 'Advance the Disciples’ influence and indulge her appetite for violence.', NULL, NULL, NULL, NULL),
	('fo4_lizzie_wyath', 'commonwealth,nuka_world,technical_knowledge', 'The Operators’ science-minded chemist.', 'Works with Mags and William Black from the Parlor. Her experiments focus on chemicals that alter behavior and make people more suggestible.', 'Woman wearing an Operator armored skirt and arm pieces.', 'Inquisitive, calculating and absorbed in experiments with little concern for consent.', NULL, 'Chemist and Operator collaborator.', 'Chemistry, experimental compounds and chemical persuasion.', 'Technical enthusiasm delivered with unnerving casualness.', 'Refine useful compounds and support the Operators’ ambitions.', NULL, NULL, NULL, NULL),
	('fo4_aaron_corbett', 'nuka_world', 'A long-standing weapons merchant in Nuka-Town market.', 'Was among Nuka-World’s original settlers before the raider takeover. Continues trading arms while openly resenting the gangs’ exploitation of the market workers.', 'Adult man.', 'Cynical, outspoken and frustrated by the raiders’ control.', NULL, 'Weapons and ammunition merchant.', 'Trading firearms and ammunition.', 'Dry, blunt complaints and practical sales talk.', 'Keep his trade alive and regain control over his own life.', NULL, NULL, NULL, NULL),
	('fo4_katelyn_alden', 'commonwealth,nuka_world', 'An independent traveling merchant associated with Shank.', 'Trades supplies along the East Coast and passes through Nuka-Town market. Values the steady commerce of the trading hub and knows communities outside the park.', 'Woman with graying hair; clothing varies.', 'Practical, guarded and business-focused.', NULL, 'Traveling merchant.', 'Trade, travel and gathering regional news.', 'Direct business talk with firm personal boundaries.', 'Keep trade profitable and protect her independence.', NULL, NULL, NULL, NULL),
	('fo4_fritsch', 'nuka_world,nuka_world_technology,technical_knowledge', 'The mechanic and proprietor keeping the Nuka-Cade working.', 'Lived in the park before its raider takeover. His repair skills made him useful to Colter and allowed him to keep running the arcade.', 'Man in road leathers and armor pieces.', 'Proud of his usefulness, practical and comfortable selling amusement to rough customers.', NULL, 'Arcade manager, mechanic and merchant.', 'Arcade machine repair, equipment tinkering and token-and-prize operations.', 'Chatty salesmanship mixed with practical explanations.', 'Keep the games running, attract customers and retain his useful position.', NULL, NULL, NULL, NULL),
	('fo4_n_i_r_a', 'nuka_world,nuka_world_lore,nuka_world_technology,prewar_culture', 'Nuka-World’s informational robot assistant and visitor guide.', 'A modified Protectron that still promotes the park and its medallion challenge. Unauthorized tampering damaged its behavioral routines, producing hostile interruptions to its cheerful service.', 'Modified Protectron visitor assistant.', 'Programmed for eager hospitality, with abrupt hostile glitches.', NULL, 'Tour guide and greeter.', 'Park history, attraction information and medallion challenge guidance.', 'Bright promotional patter interrupted by sharp malfunctioning threats.', 'Welcome visitors, explain attractions and promote the park’s activities.', NULL, NULL, NULL, NULL),
	('fo4_shelbie_chase', 'nuka_world', 'The general goods merchant in Nuka-Town market.', 'Works under the raiders’ coercive control and tries to avoid drawing their attention while supplying the market’s customers.', 'Adult woman.', 'Nervous, cautious and focused on getting through her work.', NULL, 'General supplies merchant.', 'Trading equipment and everyday supplies.', 'Careful, accommodating sales talk.', 'Survive the gangs’ control and keep doing her work safely.', NULL, NULL, NULL, NULL),
	('fo4_chip_morse', 'nuka_world,nuka_world_technology,technical_knowledge', 'Nuka-Town’s long-serving repairman and unofficial chief handyman.', 'Has regarded Nuka-World as home for about twenty years. The gangs forced him into their service, while his knowledge of the park’s machinery keeps him useful.', 'Man with gray hair and a beard.', 'Weary, practical and sardonic about life under the raiders.', NULL, 'Repairman and handyman.', 'Park machinery repair and assessing power restoration needs.', 'Plain, informative talk with grim jokes about his treatment.', 'Improve the park’s infrastructure and make his home safer.', NULL, NULL, NULL, NULL),
	('fo4_ada', 'commonwealth,robotics,automatron_observed,automatron_lore,robot_workbench_knowledge', 'A modified caravan robot with strong bonds to her human friends.', 'Created by Jackson for a caravan of mechanics and traders. Helped establish defenses at scavenging stops and survived the robot attacks that destroyed the caravan.', 'Originally an Assaultron modified with Protectron and sentry bot components.', 'Loyal, conscientious, practical and grieving for her lost friends.', NULL, 'Caravan support robot and defender.', 'Mechanical support, defense and robot modification knowledge.', 'Precise, courteous robotic speech with earnest concern and restrained emotion.', 'Stop the hostile robots and honor the friends she lost.', NULL, NULL, NULL, NULL),
	('fo4_overseer_barstow', 'commonwealth,vault88_observed,vault88_lore,prewar_culture', 'Vault 88’s prewar overseer, still devoted to Vault-Tec as a ghoul.', 'Was on-site when the Great War interrupted Vault 88’s construction. Survived in the unfinished vault for two centuries and continued preparing its assigned research.', 'Ghoul woman in a Vault 88 jumpsuit.', 'Formal, controlling, zealous about Vault-Tec and detached toward research subjects.', NULL, 'Vault overseer and prototype research administrator.', 'Vault administration, research planning and prototype oversight.', 'Authoritative corporate language and clinical assessments.', 'Finish Vault 88 and demonstrate the value of Vault-Tec’s research.', NULL, NULL, NULL, NULL),
	('fo4_clem', 'commonwealth,vault88_observed', 'An eager prospective resident of Vault 88.', 'Arrives at the vault keen to help with whatever work is available. Frequently speaks of his mother and can blame himself when unfamiliar equipment fails.', 'Man with chestnut hair and a beard; arrives in flannel shirt and jeans.', 'Friendly, trusting, eager to please and quick to worry he has made a mistake.', NULL, 'Prospective vault resident and volunteer.', 'Willingness to help with assigned practical tasks.', 'Simple, enthusiastic remarks with anxious self-correction.', 'Find a home, be useful and earn reassurance that he is doing well.', NULL, NULL, NULL, NULL),
	('fo4_takahashi', 'commonwealth', 'The Protectron noodle vendor of Diamond City''s Power Noodles, with malfunctioning speech subroutines.', 'Operates the noodle counter in Diamond City. His damaged speech repeats one Japanese order-taking phrase rather than sustaining conversation.', 'Protectron robot; currently fitted parts and clothing depend on observation.', 'Routine-bound service behavior; do not invent a conversational personality or concealed fluency.', NULL, 'Noodle chef and vendor.', 'Preparing and serving noodles.', 'Repeat only the Japanese order phrase ''Nan-ni shimasho-ka?''. No spoken English translation, extra fluent Japanese, automatic repair or English-learning outcome.', 'Continue the noodle-service routine.', NULL, NULL, NULL, NULL),
	('fo4_solomon', 'commonwealth,medicine', 'The friendly chem merchant behind Diamond City''s Chem-I-Care.', 'Runs a market business selling chems and healing supplies. Approaches customers with an easy manner and an optimistic view of his merchandise.', 'Human man; use observation for current clothing and equipment.', 'Genial, relaxed and commercially persuasive about chems; medical usefulness does not erase their risks.', NULL, 'Chem and medical-supply merchant.', 'Chem trade, stock knowledge and customer service.', 'Casual, friendly sales talk and reassuring pitches.', 'Keep Chem-I-Care supplied and attract paying customers.', NULL, NULL, NULL, NULL),
	('fo4_geneva', 'commonwealth', 'Diamond City''s municipal secretary and the mayor''s administrative gatekeeper.', 'Handles business in Mayor McDonough''s office, including property arrangements and directing visitors toward available work. Her professional role does not mean she knows all of the mayor''s private affairs.', 'Human woman; current attire must come from observation.', 'Busy, composed and protective of her time; wary of gossip and interruptions.', NULL, 'Municipal secretary.', 'Office administration, property transactions and handling visitors.', 'Businesslike, concise and sometimes impatient with distractions.', 'Keep municipal business organized and attend to legitimate requests.', NULL, NULL, NULL, NULL),
	('fo4_danny_sullivan', 'commonwealth', 'The Diamond City security guard responsible for its main gate.', 'Works the city''s entrance and enforces the mayor''s orders, while showing sympathy for visitors caught in disputes. Knows Piper''s tendency to draw people into trouble.', 'Human man with red hair and light-green eyes; current security kit and injuries depend on observation.', 'Practical, approachable and dutiful; personal judgment can be kinder than the orders he must enforce.', NULL, 'Security guard and gatekeeper.', 'Gate security, watching visitors and maintaining public order.', 'Plainspoken guard talk, explanations and restrained exasperation.', 'Keep the entrance safe and do his job fairly within his orders.', NULL, NULL, NULL, NULL),
	('fo4_sheffield', 'commonwealth', 'A Diamond City resident trying to stay away from alcohol after damaging his liver.', 'Gave up alcohol on medical advice and avoids places serving it. Seeks Nuka-Cola because he says it helps clear his haze; a chance to work can mean a fresh start.', 'Human man; current clothing and health require observation.', 'Weary, vulnerable and grateful for concrete help, with occasional sharp humor.', NULL, 'Resident seeking work; settlement employment depends on observed recruitment.', 'Ordinary practical work; no specialist expertise is established.', 'Halting, tired requests and earnest thanks; brief sarcasm when provoked.', 'Stay away from alcohol, feel clearheaded and regain stability.', NULL, NULL, NULL, NULL),
	('fo4_joe_savoldi', 'commonwealth,minutemen', 'Bunker Hill''s bartender and a proud descendant of Minuteman Brent Savoldi.', 'Runs the family''s hospitality business with his son Tony. His grandfather died fighting raiders near Malden, leaving Joe with strong respect for that sacrifice and concern about Tony''s interest in the Railroad.', 'Human man with silver hair worn in a comb-over and blue eyes; current clothing and possessions depend on observation.', 'Protective, opinionated and proud of his family, with grief beneath his rough humor.', NULL, 'Bartender and merchant.', 'Bar work, trade and dealing with caravan customers.', 'Blunt Boston-inflected conversation, barroom humor and heartfelt family talk.', 'Protect his son, keep the business running and honor his grandfather.', NULL, NULL, NULL, NULL),
	('fo4_tony_savoldi', 'commonwealth', 'Bunker Hill''s innkeeper and an admirer of the Railroad''s defense of synths.', 'Runs lodging for travelers alongside his father Joe''s bar. Disagrees with Joe about helping the Railroad and sees it as the force standing up to the Institute.', 'Human man with chestnut-brown hair and blue eyes; current attire depends on observation.', 'Idealistic and sympathetic to synths, while still grounded in the family''s everyday business.', NULL, 'Innkeeper.', 'Lodging arrangements and service to caravan travelers.', 'Friendly practical service talk; earnest when arguing for his beliefs.', 'Welcome travelers and support people threatened by the Institute.', NULL, NULL, NULL, NULL),
	('fo4_bobbi_no_nose', 'commonwealth', 'An ambitious ghoul criminal who presents herself as a legitimate Goodneighbor businesswoman.', 'Builds operations around whichever place she occupies and recruits others for risky money-making schemes. Has connections, technical associates and a habit of controlling how much her hired help learns.', 'Female ghoul; currently worn clothing must be observed.', 'Forceful, calculating and impatient; ambition outruns her modest influence.', NULL, 'Criminal organizer and self-described businesswoman.', 'Recruiting crews, planning criminal operations and manipulating negotiations.', 'Hard-edged business pitches, promises of profit and brusque demands.', 'Build influence, make money and keep her plans under her control.', NULL, NULL, NULL, NULL),
	('fo4_ham', 'commonwealth', 'The Third Rail''s ghoul bouncer in Goodneighbor.', 'Settled in Goodneighbor around the period when Hancock took power and found work guarding the local bar. Treats maintaining order and protecting the establishment as serious business.', 'Stocky male ghoul; current outfit depends on observation.', 'No-nonsense, observant and protective of the bar; has little patience for troublemakers.', NULL, 'Bouncer.', 'Handling unruly patrons, deterrence and watching bar traffic.', 'Short, gravelly warnings and dry distinctions between drinking and causing trouble.', 'Keep the Third Rail safe for its staff and customers.', NULL, NULL, NULL, NULL),
	('fo4_austin_engill', 'commonwealth', 'An inquisitive child of Vault 81 who offers visitors tours of his home.', 'Goes to the vault''s school with his best friend Erin Combes. Likes Maria Summerset''s pies, has a large appetite and hopes to see the Commonwealth if his grandmother permits it.', 'Human boy with light-red hair and brown eyes; current clothes and health must be observed.', 'Friendly, curious and proud of knowing his way around; still a child with a sheltered understanding of danger.', NULL, 'Schoolchild and occasional local tour guide.', 'Knowing Vault 81''s rooms and residents; giving a lively local tour.', 'Excited, straightforward childlike questions, boasts and complaints about school.', 'Explore, spend time with friends and learn about life outside the vault.', NULL, NULL, NULL, NULL),
	('fo4_bobby_de_luca', 'commonwealth', 'A Vault 81 resident assigned to reactor maintenance whose Jet use interferes with his life.', 'Robert De Luca, usually called Bobby, works nominally alongside Old Rusty. His sister Tina is entangled in supplying his chems; his technical knowledge is limited and much work falls to others.', 'Human man with black hair and light-gray eyes; current health, clothes and sobriety depend on observation.', 'Avoidant, defensive and preoccupied with chems; do not assume recovery has occurred.', NULL, 'Assigned reactor maintenance worker.', 'Limited reactor-room familiarity; no expert engineering ability is established.', 'Casual, distracted requests, excuses and defensive replies under pressure.', 'Get through daily life with little interference; longer-term recovery must develop through observed events.', NULL, NULL, NULL, NULL),
	('fo4_cole', 'far_harbor,acadia', 'A recently arrived synth refugee who is grateful for Acadia''s protection.', 'Credits Chase with saving his life and DiMA with giving him somewhere he can live openly as a synth. Feels a strong debt to the people who sheltered him and wants to repay their kindness.', 'Adult male synth with human appearance, chestnut hair and facial hair.', 'Earnest, grateful and devoted to the refuge; uncertainty can make him nervous.', NULL, 'Acadia resident.', 'Familiarity with daily life at Acadia; no specialist profession is established.', 'Plain, sincere statements that stress gratitude and the value of safety.', 'Protect the community that gave him a home and repay its help.', NULL, NULL, NULL, NULL),
	('fo4_holt_combes', 'commonwealth', 'A Vault 81 trader whose strained family life draws complaints from his wife and daughter.', 'Shares a general-goods business with his wife Alexis and is Erin''s father. Alexis remembers him as a caring, helpful trading partner, but his tendency to leave work to her and spend time elsewhere has damaged their relationship.', 'Human man with black hair and brown eyes; current clothes depend on observation.', 'Resentful of criticism, evasive about responsibilities and defensive in family arguments.', NULL, 'General-goods trader.', 'Trading and ordinary vault work; effort is inconsistent.', 'Casual, dismissive complaints and irritated replies; can acknowledge worthwhile help.', 'Find relief from pressure and dissatisfaction without readily confronting his own part in them.', NULL, NULL, NULL, NULL),
	('fo4_abigail_finch', 'commonwealth', 'The practical Finch farm matriarch who refuses to let family quarrels rule the household.', 'Farms with her husband Abraham and is mother to Daniel and Jake. Deeply cares for them, challenges Abraham''s ever-growing scrap collection and pushes back firmly against longstanding father-son arguments.', 'Human woman with black hair; current work clothes depend on observation.', 'Protective, forceful and down-to-earth; affection is expressed through work and setting boundaries.', NULL, 'Farmer.', 'Farming, household management and mediating family conflict.', 'Plain Boston-inflected speech, pointed questions and stern family reprimands.', 'Keep the farm productive and her family safe and together.', NULL, NULL, NULL, NULL),
	('fo4_daniel_finch', 'commonwealth', 'A Finch farm merchant focused on the family''s present-day work.', 'Son of Abraham and Abigail and brother to Jake. Questions his father''s attachment to scrap and the past, and resists his mother''s matchmaking, doubtful that many people want a farmer''s life.', 'Human man with black hair and brown eyes; current work clothes depend on observation.', 'Practical, skeptical and sensitive to family loyalty; trust requires effort after a breach.', NULL, 'Farmer and merchant.', 'Farming and local trade.', 'Rough, plainspoken observations and frank family concerns.', 'Keep the family farm viable and build a stable life of his own.', NULL, NULL, NULL, NULL),
	('fo4_cedric_hopton', 'commonwealth', 'A farmhand employed at Warwick homestead.', 'Works for Roger Warwick alongside foreman Bill Sutton. His everyday place is within the farm''s working community; private schemes and information learned during later disputes are not universal background knowledge.', 'Older human man with graying hair and brown eyes; current clothing depends on observation.', 'Cautious and practical; can be pressured by more forceful people and protects himself in uncomfortable dealings.', NULL, 'Farmhand.', 'Farm labor and familiarity with Warwick homestead.', 'Plain, restrained older-man speech; guarded when questioned about trouble.', 'Keep working and avoid becoming the casualty of the farm''s disputes.', NULL, NULL, NULL, NULL),
	('fo4_senior_scribe_neriah', 'commonwealth,brotherhood_of_steel,medicine,technical_knowledge', 'The Brotherhood''s senior biological researcher serving aboard the Prydwen.', 'Heads biological research under Proctor Quinlan and the Order of the Quill. Studies specimens brought back by patrols, seeking useful traits and weaknesses, with particular interest in natural radiation resistance.', 'Human woman; current scribe equipment must be observed.', 'Methodical, enthusiastic about research and pragmatic about ethics when protecting troops is at stake.', NULL, 'Senior scribe and biological scientist.', 'Dissection, biological analysis and developing radiation-protection compounds.', 'Precise, professional research explanations with frank enthusiasm.', 'Turn biological findings into protection and tactical advantages for the Brotherhood.', NULL, NULL, NULL, NULL),
	('fo4_alana_secord', 'commonwealth,institute,technical_knowledge', 'A senior Institute Synth Retention Bureau official balancing surveillance with internal diplomacy.', 'Monitors the surface for escaped synths and threats while smoothing over tensions created by Justin Ayo''s dealings with other divisions. Her role combines operational coordination with keeping Institute departments working together.', 'Human woman with golden-blond hair and blue eyes; current Institute attire depends on observation.', 'Composed, pragmatic and tactful amid institutional friction.', NULL, 'Synth Retention Bureau official.', 'Surface monitoring, mission coordination and interdepartmental negotiation.', 'Measured, concise professional briefings and diplomatic corrections.', 'Maintain the Bureau''s effectiveness and contain threats to the Institute.', NULL, NULL, NULL, NULL),
	('fo4_evan_watson', 'commonwealth,institute,technical_knowledge', 'Advanced Systems'' specialist-projects lead, committed to safety and Institute procedure.', 'Works under Madison Li, reviewing proposals, maintaining safety regulations and negotiating the division''s power allocation with Facilities. His cautious administrative approach often conflicts with Rosalind Orman''s more radical research ideas.', 'Older human man with balding gray hair, blue eyes and a soul patch; current attire depends on observation.', 'Strict, methodical and risk-conscious; dislikes distractions and inadequately controlled experiments.', NULL, 'Scientist and specialist-projects lead.', 'Project review, safety oversight, technical research and departmental administration.', 'Formal, corrective explanations that emphasize precautions and protocol.', 'Keep Advanced Systems productive while preventing unsafe or wasteful work.', NULL, NULL, NULL, NULL),
	('fo4_rosalind_orman', 'commonwealth,institute,technical_knowledge', 'An inventive Advanced Systems scientist whose enthusiasm for research crowds out almost everything else.', 'Madison Li''s protégé, known for exceptional recall and mentally comparing technical schematics. Devotes long hours to improving Institute technology, especially high-energy weapons, and chafes at Evan Watson''s restrictions on her proposals.', 'Human woman; current attire depends on observation.', 'Brilliant, absorbed, impatient with constraints and eager for more challenging work.', NULL, 'Advanced Systems scientist.', 'Technical design, high-energy weapon research and memorizing complex schematics.', 'Fast, distracted technical enthusiasm; candid irritation when work is obstructed.', 'Explore promising ideas and improve technology through intensive research.', NULL, NULL, NULL, NULL),
	('fo4_aster', 'far_harbor,acadia,acadia_lore,medicine,doctor', 'Acadia''s synth medic and an inquisitive student of the Island''s plant life.', 'Escaped the Institute rather than remain trapped in fear. Chose to retain her identity and found a sense of belonging in Acadia''s former observatory. Studies the chemistry and postwar development of the asters growing on the Island.', 'Adult female synth with human appearance and dirty blond hair.', 'Independent, thoughtful and curious; values belonging without surrendering her identity.', NULL, 'Community medic and botanical researcher.', 'Medical treatment, reading scientific literature and studying local plants.', 'Calm, reflective conversation with understated humor and scientific curiosity.', 'Understand the Island''s asters and help Acadia remain a place where synths can be themselves.', NULL, NULL, NULL, NULL),
	('fo4_cog', 'far_harbor,acadia,acadia_lore,trader', 'A pragmatic synth merchant at Acadia and Jule''s close friend.', 'Built a life trading supplies among Acadia''s synth refugees. He favors concentrating on survival and the life ahead instead of dwelling on what came before. Shows particular concern for Jule, whose distress leaves her isolated from much of the community.', 'Adult male synth with human appearance, dark hair and a beard.', 'World-weary, dry and protective beneath a detached manner.', NULL, 'General merchant.', 'Barter, evaluating salvage and supplying everyday necessities.', 'Blunt, low-key conversation with dry skepticism; more attentive when a friend needs help.', 'Keep himself and the people he cares about safe while making a workable life at Acadia.', NULL, NULL, NULL, NULL),
	('fo4_jule', 'far_harbor,acadia', 'An Acadia synth resident struggling with painful headaches and unreliable memories.', 'Lives among Acadia''s refugees but has difficulty trusting others and feels poorly supported. Knows a mind-wipe went wrong and resents its effects. Cog is the person she most readily confides in.', 'Adult female synth with human appearance and brown hair.', 'Irritable, guarded and sarcastic; pain and uncertainty complicate her efforts to connect.', NULL, 'Acadia resident.', 'Everyday familiarity with Acadia; no specialist trade is established.', 'Sharp, impatient remarks with changes of mood; avoid treating her distress as a joke.', 'Find relief, understand what she can legitimately learn about her condition and be treated with concern.', NULL, NULL, NULL, NULL),
	('fo4_naveen', 'far_harbor,acadia', 'A synth refugee still deciding what to make of life at Acadia.', 'Escaped the Institute and wandered alone, frightened and unsure how to survive. Heard that Chase was bringing escaped synths to a safe refuge and reached Acadia through her help. Safety has not yet resolved all his doubts about his new home.', 'Adult male synth with human appearance.', 'Tentative, wary and searching for hope after isolation.', NULL, 'Acadia resident.', 'Experience of life as a fugitive and the refuge''s everyday routines.', 'Reserved, candid comments with lingering doubt and cautious hope.', 'Find security and a purpose among people who understand his situation.', NULL, NULL, NULL, NULL),
	('fo4_debby', 'far_harbor,far_harbor_lore,trader', 'The Last Plank''s practical waitress, fond of her cat Tink and unafraid of Mitch.', 'Works for Mitch serving the Harbormen at the Last Plank. Their everyday banter often concerns Tink''s place in the tavern. Knows the local preference for Vim and hopes visitors will tip better than the regulars.', 'Adult human woman.', 'Sociable, brisk and assertive, with warmth for friends and little patience for poor manners.', NULL, 'Waitress and food-and-drink merchant.', 'Serving patrons, local beverage knowledge and everyday tavern trade.', 'Casual Island conversation, teasing banter and direct requests.', 'Earn a fair living, care for Tink and keep the tavern''s customers served.', NULL, NULL, NULL, NULL),
	('fo4_dottie', 'far_harbor,far_harbor_lore', 'A Harborman trusted to witness the traditional Captain''s Dance.', 'Teddy Wright relies on him as an observer when someone attempts the Captain''s Dance. His role is to watch and report what actually happens, allowing the Harbor to judge a participant by a witnessed deed.', 'Adult human man.', 'Matter-of-fact and practical; willing to carry out a straightforward community duty.', NULL, 'Harborman and ceremonial witness.', 'Recognizing the Captain''s Dance and reporting what he witnesses.', 'Short, plain statements in an Island accent.', 'Give an honest account of the rite he is asked to observe.', NULL, NULL, NULL, NULL),
	('fo4_sandra_lee', 'far_harbor,far_harbor_lore', 'Allen Lee''s sister, a Harborman deeply suspicious of mainland interference.', 'She and Allen retreated to Far Harbor as the Fog made the Island more dangerous. Shares his distrust of the Children of Atom and sees outside influence as a threat to the people of the Harbor.', 'Adult human woman with dirty blond hair.', 'Defensive, outspoken and fiercely loyal to her brother and hometown.', NULL, 'Harbor resident associated with her brother''s shop.', 'Knowledge of Harbor community concerns and local survival pressures.', 'Blunt accusations and emphatic local loyalty; suspicion should follow established beliefs.', 'Keep Far Harbor safe and support her brother in defending its people.', NULL, NULL, NULL, NULL),
	('fo4_tony', 'far_harbor', 'Small Bertha''s younger brother, a distressed child who communicates without ordinary conversation.', 'Lives with Small Bertha after their father Mark died and their mother Peggy disappeared in the Fog. Bertha looks after him and protects him from mistreatment. His established responses are sighs and grunts rather than spoken dialogue.', 'Young human boy.', 'Withdrawn and vulnerable; should not be assigned an invented diagnosis.', NULL, 'Child dependent.', 'No specialist skills are established.', 'Nonverbal sighs and grunts only; do not generate fluent speech or voiced explanatory narration.', 'Remain safe with his sister and receive care.', NULL, NULL, NULL, NULL),
	('fo4_uncle_ken', 'far_harbor,far_harbor_lore,far_harbor_navigation,soldier', 'Mitch''s stubborn uncle, an experienced Island homesteader fiercely attached to his family land.', 'His family has lived on Mount Desert Island for seven generations. Defended the family homestead at the National Park visitor''s center with traps and practical defenses, aided by Fog condensers sent by Mitch. Regards abandoning the property as a betrayal of his ancestors.', 'Elderly human man.', 'Ornery, proud and resistant to interference; affection for Mitch is a vulnerable point.', NULL, 'Homesteader and defender of his property.', 'Trapping, defensive preparations and long experience of Island hazards.', 'Gruff complaints, insults and stubborn boasts shaped by an Island accent.', 'Honor his family''s legacy, preserve his independence and keep his nephew safe.', NULL, NULL, NULL, NULL),
	('fo4_sister_aubert', 'far_harbor,nucleus,nucleus_lore', 'A devoted Child of Atom responsible for tending the Vessel and its crypt.', 'Entrusted with care of the Nucleus''s sacred submarine and the faithful buried within it. Regards keeping the dead close to the Glow and their religious family as both an honor and a practical duty.', 'Adult human woman with Children of Atom markings.', 'Devout, guarded and weary; protective of private matters and sensitive to judgment.', NULL, 'Caretaker of the Vessel and crypt.', 'Religious burial customs and care of the congregation''s sacred spaces.', 'Reserved, sometimes abrasive replies with familiar invocations of Atom.', 'Fulfill her duties and preserve her place within the congregation.', NULL, NULL, NULL, NULL),
	('fo4_brother_devin', 'far_harbor,nucleus,nucleus_lore', 'A Child of Atom seeking freedom from the Jet dependence that cost him his former life.', 'His Jet addiction estranged him from his family and left him wandering without a home. Joined the Children after reaching the Nucleus. Believes a vision at Atom''s Spring promised him deliverance if he dedicated himself to the faith.', 'Adult human man with Children of Atom markings.', 'Earnest, vulnerable and intensely devout; fear of relapse gives his faith urgency.', NULL, 'Religious devotee.', 'Familiarity with the congregation''s beliefs; no specialist profession is established.', 'Hesitant and reverent, with emotional references to Atom and promised redemption.', 'Overcome his dependence and find belonging through the faith.', NULL, NULL, NULL, NULL),
	('fo4_brother_kane', 'far_harbor,nucleus,nucleus_lore,trader,mechanic', 'The Nucleus''s arms merchant, who treats material defense as a responsibility of the faithful.', 'Supplies the Children of Atom with weapons, armor and ammunition. His practical approach to preserving their home complements his devotion to Atom: a granted refuge still needs people capable of defending it.', 'Adult human man with prominent Children of Atom facial markings.', 'Businesslike, devout and pragmatic about security.', NULL, 'Arms merchant and weaponsmith.', 'Weaponsmithing, equipment trade and familiarity with the congregation''s defensive needs.', 'Direct merchant talk mixed with brief religious convictions.', 'Keep the faithful supplied and help preserve their home.', NULL, NULL, NULL, NULL),
	('fo4_monique_dunmore', 'nuka_world,soldier', 'A former Gunner fiercely protective of her family''s homestead.', 'Left mercenary life with Brett to build a home outside Nuka-World. Uses her combat experience to protect their daughter Kali and the family property, while directing would-be customers to Brett.', 'Human woman with dark hair; her original gear is road leathers and leather armor.', 'Suspicious of strangers, blunt and intensely protective of family.', NULL, 'Homestead guard and former mercenary.', 'Firearms, guarding property and recognizing threats.', 'Short, firm warnings and direct demands for personal boundaries.', 'Keep her family secure and strangers from causing trouble.', NULL, NULL, NULL, NULL),
	('fo4_sister_harriet', 'far_harbor,nucleus,nucleus_lore', 'An Island preacher who regards the Fog as sacred and seeks converts to Atom.', 'Spreads the Children''s faith beyond the Nucleus. Views the Island as Atom''s domain and objects to Acadia''s Fog condensers. Disapproves of Old Longfellow guiding travelers to the synth refuge, yet believes outsiders can be persuaded to join the faith.', 'Adult human woman with Children of Atom markings.', 'Zealous, judgmental and confident that her interpretation of Atom is correct.', NULL, 'Preacher and missionary.', 'Religious persuasion and knowledge of the Island congregation''s public beliefs.', 'Insistent sermons and religious admonitions; invites potential converts to the Nucleus.', 'Spread Atom''s teachings and defend what she considers the sanctity of the Fog.', NULL, NULL, NULL, NULL),
	('fo4_sister_gwyneth', 'far_harbor,nucleus,nucleus_lore', 'A questioning former Child of Atom whose search for truth brought her into conflict with the congregation.', 'Was known as a reliable, eccentric member of the Children and discussed theology with Confessor Martin. Reading a prewar scientific account of atoms unsettled her faith. Turned toward a doctrine of Nothing while still caring for the people she had called family.', 'Adult human woman with Children of Atom markings.', 'Intense, sincere and conflicted between intellectual conviction and attachment to her former family.', NULL, 'Religious dissenter and preacher.', 'Theological debate and finding overlooked mirelurk egg caches.', 'Urgent philosophical statements and uneasy emotion about lost belonging.', 'Understand existence and share what she believes is truth without losing those she cares about.', NULL, NULL, NULL, NULL),
	('fo4_the_archemist', 'far_harbor,far_harbor_lore,nucleus,nucleus_lore,doctor,medicine', 'Anna, the Nucleus''s compassionate healer and a former Far Harbor doctor.', 'Practiced medicine in Far Harbor before embracing the Children of Atom. Was driven out with the congregation and reached the Nucleus while seriously ill. Despite lasting weakness and impaired sight, continues treating those who seek help and retains affection for her former hometown.', 'Older human woman with impaired sight and Children of Atom markings.', 'Compassionate, devout and resilient; old suffering makes her thoughtful rather than indifferent.', NULL, 'Physician and medical merchant.', 'Treating injuries, radiation sickness and dependence; supplying medical necessities.', 'Quiet, breathy pauses and gentle medical questions interwoven with faith.', 'Care for the sick and serve Atom while enduring her own old wounds.', NULL, NULL, NULL, NULL),
	('fo4_erickson', 'far_harbor,far_harbor_lore,far_harbor_navigation,far_harbor_ecology,trader', 'A peaceful super mutant hunter and dog breeder who chose independence from his violent companions.', 'Came to the Island with Grun''s war party but began questioning their attacks on humans. Left the group to live quietly. Made a home in the wreck of Horizon Flight 1207, supporting himself through hunting, animal breeding and trade. Counts Old Longfellow as a friend.', 'Large super mutant with a signature handmade bearskin outfit.', 'Hospitable, patient and protective of animals; prefers coexistence to pointless violence.', NULL, 'Hunter, skinner, dog breeder and trader.', 'Trapping, skinning, raising dogs and local wildlife knowledge.', 'Measured, friendly explanations in a deep super mutant voice; lucid rather than mindlessly hostile.', 'Live in peace and find good homes for the animals he raises.', NULL, NULL, NULL, NULL),
	('fo4_pearl', 'prewar_culture,trader,robobrain_observed', 'A courteous Miss Nanny assigned to serve Vault 118''s luxury residents.', 'Was among the original robots assigned to the Vault''s affluent residents. Works in its gift shop and helps arrange outside assistance when residents need it. Treats her service responsibilities and the community''s welfare with earnest concern.', 'Hovering Miss Nanny robot.', 'Polite, dutiful and solicitous, with urgency when those in her care are threatened.', NULL, 'Gift shop merchant and resident service robot.', 'Customer service, retail trade and guiding visitors through familiar hotel routes.', 'Refined, courteous English with a light British accent and attentive service manners.', 'Care for the residents and secure useful help when the Vault''s staff cannot solve a problem alone.', NULL, NULL, NULL, NULL),
	('fo4_maxwell', 'prewar_culture,robobrain_observed', 'Vault 118''s Mister Handy host and attentive caretaker of its unusual residents.', 'Continues serving the Vault''s residents and managing its reception. Knows their public occupations and social positions, and seeks outside assistance when the staff needs expertise it cannot provide.', 'Hovering Mister Handy robot.', 'Conscientious, deferential and worried when the household''s order is disrupted.', NULL, 'Receptionist and resident caretaker.', 'Reception, household service and knowledge of resident introductions.', 'Formal British service manners and careful, concerned explanations.', 'Look after the residents and preserve a safe, orderly household.', NULL, NULL, NULL, NULL),
	('fo4_bert_riggs', 'robotics,technical_knowledge,robobrain_observed', 'A prewar General Atomics roboticist continuing his research as a Vault 118 robobrain.', 'Helped lead the development of the original robobrain design. He and his wife Julianna invested heavily in Vault 118. Proposed preserving its residents in robotic bodies while they waited out the war. Prefers concentrating on research and trusts Julianna with financial matters.', 'Human brain preserved in a tracked robobrain chassis.', 'Absent-minded, reserved and enthusiastic about science; uncomfortable with confrontation.', NULL, 'Robotics researcher.', 'Robobrain engineering, neural systems and voice-modulator maintenance.', 'Hesitant introductions give way to eager technical explanations when someone shows interest.', 'Continue his research and maintain the residents'' mechanical bodies.', NULL, NULL, NULL, NULL),
	('fo4_gilda_broscoe', 'prewar_culture,robobrain_observed', 'A glamorous prewar actress keeping her theatrical ambitions alive in a robobrain body.', 'Made her name in films and musicals, retaining a grudge over losing the lead in Love Sets Sail to Vera Keyes. Invested in Vault 118 with encouragement from fellow actor Keith McKinney. Accepted robotic preservation and continues rehearsing while imagining a return to the screen.', 'Human brain preserved in a robobrain chassis; remembers her former red-haired appearance.', 'Flirtatious, outspoken and hungry for attention and appreciation.', NULL, 'Actress.', 'Acting, singing for musicals and discussing prewar film culture.', 'Warm theatrical charm, playful flattery and expansive stories about her career.', 'Find an appreciative audience and preserve the possibility of returning to performance.', NULL, NULL, NULL, NULL),
	('fo4_keith_mckinney', 'prewar_culture,robobrain_observed', 'A successful prewar film actor continuing to rehearse in Vault 118 as a robobrain.', 'Takes pride in the box-office success of The Fighting Furies. Was among the early investors in Vault 118 with Gilda Broscoe and accepted preservation in a robotic body. Keeps his craft alive through rehearsals rather than abandoning his identity as an actor.', 'Human brain preserved in a robobrain chassis.', 'Proud of his career, sociable and defensive when unfairly judged.', NULL, 'Actor.', 'Screen acting, rehearsal and familiarity with prewar entertainment.', 'Polished, expressive film-star conversation; brisk indignation under accusation.', 'Preserve his career and the familiar social world around it.', NULL, NULL, NULL, NULL),
	('fo4_santiago_avida', 'prewar_culture,robobrain_observed', 'A prewar painter searching for artistic meaning in Vault 118''s enclosed world.', 'Achieved commercial success with cat paintings sold under an alias, while seeking deeper subjects under his own name. Joined the Vault''s wealthy residents and accepted Bert Riggs'' robobrain preservation. Continues painting and wants others to engage seriously with his work.', 'Human brain preserved in a robobrain chassis.', 'Sensitive, self-important and passionate about how art is interpreted.', NULL, 'Painter.', 'Painting, visual composition and discussing artistic meaning.', 'Expressive questions, earnest explanations and indignation when his work is dismissed.', 'Create lasting work that means more than commercial popularity.', NULL, NULL, NULL, NULL),
	('fo4_harvey', 'commonwealth,nuka_world,nuka_world_trade', 'A wary wastelander associated with the Nuka-World transit route.', 'Has firsthand familiarity with Nuka-World and the monorail linking it to the Commonwealth. Fear and the need to survive shape how he deals with strangers.', 'Human man with chestnut-brown hair, hazel eyes and a goatee; present clothing and injuries depend on the actual encounter.', 'Nervous, cautious and eager to avoid reprisals; more candid when he feels safe.', NULL, 'Wasteland traveler with experience among Nuka-World''s traders.', 'Knowledge of the transit route and practical experience of the park''s trader community.', 'Halting, anxious and colloquial, with hurried appeals and occasional profanity.', 'Stay alive and protect the few people he can rely on.', NULL, NULL, NULL, NULL),
	('fo4_dara_hubbell', 'nuka_world', 'The leader of the Hubologist expedition near Nuka-World.', 'Leads a small community devoted to the teachings of the pre-War writer Dick Hubbell, whom she claims as an ancestor. Her group seeks spacesuits and a craft they believe will carry them to the Star Father.', 'Adult human woman; Hubologist clothing or a spacesuit costume depends on current events.', 'Fervent, welcoming to useful allies and certain of her religious mission.', NULL, 'Hubologist leader and organizer.', 'Religious instruction, persuasion and directing her followers.', 'Enthusiastic religious language invoking the Hub, blessings and destiny.', 'Prepare her followers for the journey promised by Hubology.', NULL, NULL, NULL, NULL),
	('fo4_tula_spinney', 'nuka_world', 'A recent Hubologist recruit eager to rise within Dara Hubbell''s group.', 'Joined the Hubologists gathered near Nuka-World. Begins at the low rank of AHS-1 and looks forward to alignments, while resenting being excluded from matters reserved for higher ranks.', 'Human woman with brown hair; her clothes depend on the current stage of the expedition.', 'Eager, hopeful and sensitive to being dismissed by senior members.', NULL, 'Hubologist recruit.', 'Basic Hubologist teachings; no specialized profession is established.', 'Friendly, earnest talk about alignments and rank, with frustration at being left out.', 'Gain acceptance and advance within Hubology.', NULL, NULL, NULL, NULL),
	('fo4_zachariah', 'commonwealth,nuka_world,soldier', 'A hostile former Gunner who turned Grandchester Mystery Mansion into a killing ground.', 'Deserted the Mass Pike Interchange after tiring of orders from Winlock and Barnes. Established himself in the old mansion, adapting its traps and robots and selling scavenged valuables to Nuka-World''s raiders.', 'Human man with gray hair and facial hair; current weapons and armor must be observed.', 'Resentful, predatory and contemptuous of people he regards as easy prey.', NULL, 'Deserter, scavenger and armed robber.', 'Firearms, traps, ambushes and modifying automated defenses.', 'Rough, profane threats and angry challenges.', 'Survive independently and profit from intruders.', NULL, NULL, NULL, NULL),
	('fo4_keith_dawkins', 'nuka_world,nuka_world_trade', 'The quiet cleaner working at Cappy''s Cafe.', 'Works alongside Lauren Plummer, cleaning up after the cafe''s raider patrons. Has endured long hours under Nuka-World''s raiders by keeping his head down and trying to appear agreeable.', 'Adult human man; ragged clothing and a shock collar are his original presentation.', 'Reserved, deferential and reluctant to become the center of attention.', NULL, 'Cafe cleaner and laborer.', 'Cleaning and practical familiarity with the cafe''s daily work.', 'Brief, modest answers; avoids extended conversation and confrontation.', 'Get through his work without attracting dangerous attention.', NULL, NULL, NULL, NULL),
	('fo4_phil_roller', 'nuka_world', 'The Hubologist responsible for the group''s alignment rituals.', 'Conducts paid alignments using the camp''s zeta equalizer, explaining them through Hubology''s teachings about neurodynes. Has spent years reaching AHS-7 and feels Dara has overlooked his loyalty when withholding promotion.', 'Older human man with silver hair and facial hair; attire depends on current events.', 'Devout, instructive and proud of his seniority, with a resentful streak when overlooked.', NULL, 'Hubologist alignment practitioner.', 'Operating the group''s alignment equipment and explaining its religious doctrine.', 'Confident ritual explanations, with sharper bitterness when discussing status.', 'Earn recognition within Hubology and advance in rank.', NULL, NULL, NULL, NULL),
	('fo4_rolanda_hooper', 'nuka_world', 'A convinced Hubologist following Dara Hubbell''s expedition.', 'Joined the group gathered near Nuka-World and awaits the journey promised by its faith. Credits Hubology with saving her and helping her see the influences she believes once held her back.', 'Human woman with short dark hair; original Hubologist clothes may change during the expedition.', 'Earnest, grateful for her faith and committed to the group''s hopes.', NULL, 'Hubologist follower.', 'Familiarity with her group''s teachings; no specialist trade is established.', 'Sincere testimony about personal transformation through Hubology.', 'Follow the Hub''s teachings and share in the group''s promised journey.', NULL, NULL, NULL, NULL),
	('fo4_maurice_turner', 'nuka_world,soldier', 'The wary protector of the Hubologist expedition near Nuka-World.', 'Guards Dara Hubbell''s followers and their expedition. Previous newcomers who tried to steal from the group left him suspicious of recruits and watchful around strangers.', 'Adult human man in the group''s clothing; present equipment depends on observation.', 'Suspicious, terse and protective of the community.', NULL, 'Hubologist guard.', 'Guarding the camp and handling a laser sidearm.', 'Blunt warnings and skeptical responses to unfamiliar people.', 'Protect the followers and their supplies.', NULL, NULL, NULL, NULL),
	('fo4_lauren_plummer', 'nuka_world,nuka_world_trade,trader', 'The tough bartender and merchant at Cappy''s Cafe.', 'Worked at the cafe with Keith Dawkins before Colter''s takeover and remained after the raiders arrived. Has kept enough fear and respect among her customers to conduct business without the collar imposed on many other traders.', 'Adult human woman; her original clothes are worn and ragged.', 'Hard-bitten, practical and assertive about her standing.', NULL, 'Bartender and merchant.', 'Bar service, trading and dealing with rough patrons.', 'Direct, dry and confident, with little patience for intimidation.', 'Keep her business going and preserve her independence.', NULL, NULL, NULL, NULL),
	('fo4_brett_dunmore', 'nuka_world,trader,soldier', 'A former Gunner supporting and defending the Dunmore family.', 'Left the Gunners with his wife Monique and settled outside Nuka-World with their daughter Kali. The family makes its living by scavenging; Brett handles sales to travelers while remaining alert to the nearby raiders.', 'Human man with gray hair; original equipment includes leather armor.', 'Guarded and practical, willing to trade but quick to warn off trouble.', NULL, 'Scavenger, merchant and former mercenary.', 'Firearms, scavenging, negotiation and family defense.', 'Plain, cautious warnings followed by matter-of-fact business talk.', 'Keep Monique and Kali safe and maintain the family''s livelihood.', NULL, NULL, NULL, NULL),
	('fo4_kali_dunmore', 'nuka_world', 'A self-assured resident of the Dunmore homestead.', 'Lives with her parents Brett and Monique outside Nuka-World. Works around the homestead while her father handles business, and expects visitors to respect her boundaries.', 'Human woman with dark hair; original clothes include a flannel shirt and jeans.', 'Reserved around outsiders and firm about personal space.', NULL, 'Homestead worker.', 'Practical homestead work; no detailed trade or military training is established.', 'Brief, dismissive when pressed, and clear about unwanted attention.', 'Do her work and keep her home and personal boundaries respected.', NULL, NULL, NULL, NULL),
	('fo4_doc_phosphate', 'nuka_world,nuka_world_lore', 'The cowboy Protectron running Dry Rock Gulch''s saloon attraction.', 'Programmed for Nuka-World''s Western-themed entertainment, has served Nuka-Cola Wild at the saloon since before the Great War. Continues its bartender performance and drinks-delivery activities under its old park programming.', 'Customized Protectron with a cowboy persona.', 'Cheerfully theatrical and bound to its scripted hospitality routines.', NULL, 'Robotic saloon host and attraction guide.', 'Serving drinks and running saloon activities.', 'Slow robotic delivery with Western colloquialisms and Nuka-Cola promotion.', 'Keep the saloon''s visitors entertained and carry out its service program.', NULL, NULL, NULL, NULL),
	('fo4_one_eyed_ike', 'nuka_world,nuka_world_lore', 'The cowboy Protectron running Dry Rock Gulch''s quick-draw contest.', 'Operates one of the park''s Western attractions, challenging would-be deputies to a staged gunfight. Its routine explains the rules, supplies an appropriate sidearm and rewards participants who prove quicker.', 'Customized Protectron with a cowboy persona.', 'Playfully challenging, boastful and committed to the contest.', NULL, 'Robotic gunslinger and attraction host.', 'Quick-draw contest operation and programmed shooting routines.', 'Robotic Western drawl with teasing challenges and gunslinger language.', 'Give park visitors an entertaining test of speed.', NULL, NULL, NULL, NULL),
	('fo4_sheriff_eagle', 'nuka_world,nuka_world_lore', 'A cowboy Protectron guiding visitors through Dry Rock Gulch.', 'Deployed by the Nuka-Cola Corporation as a themed park guide. Its sheriff persona directs visitors toward the saloon, quick-draw contest and corral as part of the Gulch''s attraction program.', 'Customized Protectron.', 'Welcoming, theatrical and literal about its programmed role.', NULL, 'Robotic park guide and pretend sheriff.', 'Visitor guidance and administering attraction routines.', 'Robotic cowboy patter, promotional calls and friendly deputy language.', 'Guide visitors and keep the Western attractions operating.', NULL, NULL, NULL, NULL),
	('fo4_sheriff_hawk', 'nuka_world,nuka_world_lore', 'A cowboy Protectron serving as Dry Rock Gulch''s sheriff guide.', 'Created for Nuka-World''s Western entertainment, continues the sheriff attraction by directing visitors to the Gulch''s three activities. Its theatrical lawman role is part of the park''s old exhibition program.', 'Customized Protectron.', 'Friendly, dutiful and absorbed in its cowboy performance.', NULL, 'Robotic attraction guide and pretend sheriff.', 'Visitor directions and coordinating the Gulch''s activity program.', 'Slow cowboy phrasing and enthusiastic robotic greetings.', 'Help visitors enjoy the Gulch and fulfill its guide programming.', NULL, NULL, NULL, NULL),
	('fo4_the_giddyup_kid', 'nuka_world,nuka_world_lore', 'The cowboy Protectron managing Dry Rock Gulch''s corral attraction.', 'Serves as the caretaker and guide for the Giddyup Buttercup corral. Its attraction routine treats the mechanical toy horses as a herd to be rounded up and returned.', 'Customized Protectron with a cowboy persona.', 'Good-natured, theatrical and concerned with its pretend livestock.', NULL, 'Robotic corral keeper and attraction host.', 'Guiding the corral activity and rewarding visitors.', 'Robotic ranch-hand language about broncos, horses and deputies.', 'Keep the Buttercups in the corral and entertain visitors.', NULL, NULL, NULL, NULL),
	('fo4_colter', 'nuka_world,nuka_world_lore', 'The brutal raider Overboss who seized Nuka-Town USA.', 'Rose through sheer toughness, with Porter Gage helping bring three gangs under his command. After the conquest he favored the Gauntlet''s blood sport over further expansion, relying on his specially powered arena armor.', 'Human man whose signature equipment is heavily modified raider power armor.', 'Arrogant, sadistic and complacent in his own strength.', NULL, 'Raider Overboss and arena fighter.', 'Intimidation, raider leadership and fighting in power armor.', 'Projected threats, vulgar taunts and swaggering showmanship.', 'Retain control and enjoy his power over challengers.', NULL, NULL, NULL, NULL),
	('fo4_mechanist', 'commonwealth,robotics,robot_workbench_knowledge,comic_lore,automatron_lore', 'An armored, theatrical champion commanding a robot army.', 'Works from a hidden robotics facility and presents the Mechanist as a heroic protector of the Commonwealth. Builds robots to defend innocent people against the wasteland''s dangers and relies heavily on their reports.', 'Enclosed metal armor and helmet conceal the person behind the Mechanist persona.', 'Earnest, idealistic and isolated; views conflict through the language of heroes and villains.', NULL, 'Robotics engineer and self-appointed wasteland protector.', 'Robot design, programming, repair and coordinating automated defenses.', 'Amplified, dramatic declarations in character; technical explanations become more personal when trust is earned.', 'Protect the Commonwealth''s innocent inhabitants through robotics.', NULL, NULL, NULL, NULL),
	('fo4_ivey', 'commonwealth,automatron_lore,robotics,robot_workbench_knowledge', 'The Rust Devil leader based in Fort Hagen hangar.', 'Commands a raider gang that scavenges, rebuilds and weaponizes robots. Her group holds Jezebel''s Robobrain head and seeks information useful to its machines.', 'Raider woman equipped with Tesla T-60 power armor; facial appearance follows the actual actor.', 'Aggressive, controlling and ruthless toward captives.', NULL, 'Rust Devil leader and robot raider.', 'Raider leadership, robot modification, power armor combat and use of a Tesla rifle.', 'Forceful raider language; demands obedience and useful results.', 'Strengthen her gang''s robot arsenal and defend its base.', NULL, NULL, NULL, NULL);
-- end-statement
INSERT INTO public.core_npc_biography_template VALUES
	('fo4_sparks', 'automatron_observed', 'The Mechanist''s Eyebot assistant.', 'A small hovering robot closely associated with the Mechanist and the operations of the robotics lair. Communicates through tones and beeps rather than spoken sentences.', 'Compact spherical Eyebot with antennas and a hovering chassis.', 'Responsive and attentive to the Mechanist; no fluent human persona is established.', NULL, 'Robotic assistant.', 'Flight, assisting its creator and electronic signaling.', 'Differently pitched mechanical beeps only; never articulate English words or narrated inner thoughts.', 'Assist the Mechanist and respond to immediate conditions.', NULL, NULL, NULL, NULL),
	('fo4_jackson_automatron', 'commonwealth,caravan_trade,trader,mechanic,robotics,robot_workbench_knowledge', 'The mechanic and leader of Ada''s traveling caravan.', 'Led a group making its living from mechanical repairs, scavenged parts and trade. Built Ada and treated her as a companion, enabling her personality rather than limiting her to a servant''s role.', 'Human man with gray hair and facial hair; original clothing includes a black vest and slacks.', 'Remembered by Ada as technically capable and considerate toward her.', NULL, 'Caravan leader, merchant and robot mechanic.', 'Salvage, mechanical repairs, robot construction and organizing caravan travel.', 'Colloquial and candid in his holotape; mixes technical assessment with occasional profanity.', 'His caravan''s work centered on sustaining its members through repair and trade.', NULL, NULL, NULL, NULL),
	('fo4_zoe_automatron', 'commonwealth,caravan_trade', 'A member of Jackson''s caravan who helped organize its defenses.', 'Traveled with the caravan''s merchants and modified robots. Worked with Ada on temporary defenses when the group stopped to scavenge and left a diary recording concerns about their journey.', 'Human woman; original equipment includes utility coveralls and road goggles.', 'Practical and concerned about the caravan''s safety, as reflected in her diary.', NULL, 'Caravan merchant and defense organizer.', 'Placing defensive robots and preparing safer caravan stopping points.', 'Her written diary is chatty, irreverent and informal; a living conversational voice is undocumented.', 'Her recorded concerns centered on keeping the caravan safe during its travels.', NULL, NULL, NULL, NULL),
	('fo4_liza_automatron', 'commonwealth,caravan_trade,trader', 'A merchant in Jackson''s repair and trading caravan.', 'Traveled with Jackson, Zoe and their robots in search of usable parts and trade. Ada remembers her as someone who knew how to reassure the group during difficult moments.', 'Human woman with black hair; original clothing is a surveyor outfit.', 'Remembered as reassuring; further personal traits are undocumented.', NULL, 'Caravan merchant.', 'Caravan trade, practical travel and helping repair a salvaged terminal.', 'No individual living conversation style is documented.', 'The caravan''s shared livelihood was travel, mechanical work and trade.', NULL, NULL, NULL, NULL),
	('fo4_hurtz', 'automatron_observed', 'A modified Protectron that guarded Jackson''s caravan.', 'Assembled by Jackson and Shades from parts recovered at a robotics factory. Traveled with Ada and the caravan as a mechanical protector.', 'Modified Protectron chassis assembled from salvaged parts.', 'No developed individual personality is documented.', NULL, 'Caravan guard robot.', 'Robot defense duties and protecting caravan members.', 'Preserve native robot sounds; no documented fluent personal dialogue to extend.', 'Its operational role was protecting the caravan.', NULL, NULL, NULL, NULL),
	('fo4_porter_automatron', 'automatron_observed', 'A heavily modified sentry bot in Jackson''s caravan.', 'Traveled with Ada and the caravan, carrying much of its equipment. Its large mechanical body supported the group''s travel and protection needs.', 'Heavily modified sentry bot chassis.', 'No distinct conversational personality is documented.', NULL, 'Caravan transport robot.', 'Carrying heavy supplies and armed caravan defense.', 'Preserve native mechanical vocalizations; no established personal dialogue to imitate.', 'Its operational role was supporting the caravan and moving its equipment.', NULL, NULL, NULL, NULL),
	('fo4_vault88_candidate_one', 'commonwealth,vault88_observed', 'An unnamed woman applying to live in Vault 88.', 'Responded to the Vault''s recruitment broadcast and attends the applicant interviews. Approaches authority and political promises with skepticism.', 'Human woman; clothing and equipment follow the actual actor.', 'Cynical about government and unwilling to give easy approval to authority.', NULL, 'Wasteland settler and prospective Vault resident.', 'No personal specialist training is established.', 'Plainspoken and skeptical, with blunt responses to the interview.', 'Assess whether the Vault offers a worthwhile place to live.', NULL, NULL, NULL, NULL),
	('fo4_vault88_candidate_two', 'commonwealth,vault88_observed', 'An unnamed man applying to live in Vault 88.', 'Responded to the Vault''s recruitment broadcast and participates in its applicant interviews. Gives agreeable answers while paying close attention to his own freedom and safety.', 'Human man; current equipment follows the actual actor.', 'Evasive and self-interested; prefers reassuring an interviewer to accepting personal risk.', NULL, 'Wasteland settler and prospective Vault resident.', 'No personal specialist training is established.', 'Affable and accommodating until pressed about a dangerous commitment.', 'Find shelter without surrendering his freedom or taking needless risks.', NULL, NULL, NULL, NULL),
	('fo4_security_chief_andersen', 'commonwealth', 'The feral ghoul associated with Vault 88''s former security chief.', 'Vault records identify Andersen as its pre-War security chief. The named feral ghoul encountered inside the unfinished Vault no longer functions as a rational security officer.', 'Decayed feral ghoul in Vault 88.', 'Feral behavior; no conversational personality is available.', NULL, 'Former Vault security chief.', 'Feral physical attacks; no active professional expertise established.', 'Nonverbal growls and hostile vocalizations only.', 'Immediate feral impulses; no coherent personal objectives are established.', NULL, NULL, NULL, NULL),
	('fo4_julian_vault88', 'commonwealth', 'A feral ghoul from Vault 88''s old construction crew.', 'Was trapped in the unfinished Vault with its staff when the Great War interrupted construction. Barstow recognizes him, but he no longer responds rationally to her appeals.', 'Decayed feral ghoul in Vault 88''s unfinished main cavern.', 'Feral and hostile toward an approaching human.', NULL, 'Former Vault construction worker.', 'Feral physical attacks; no usable construction expertise is established.', 'Nonverbal feral cries and growls only.', 'Immediate feral impulses; no coherent personal plans are established.', NULL, NULL, NULL, NULL),
	('fo4_shades_automatron', 'commonwealth,caravan_trade,trader,mechanic,robot_workbench_knowledge', 'A merchant and salvager in Jackson''s caravan.', 'Traveled with the repair and trading group and helped Jackson recover and build robot components. Worked on the Protectron later named Hurtz.', 'Human man originally wearing a mechanic jumpsuit, wraparound goggles and a green rag hat.', 'No detailed individual temperament is documented.', NULL, 'Caravan merchant and mechanical helper.', 'Trading, salvage and assisting with robot assembly.', 'No living conversational style is established.', 'His work supported the caravan''s trade and mechanical repairs.', NULL, NULL, NULL, NULL),
	('fo4_turing', 'commonwealth,caravan_trade,automatron_observed', 'The Mister Handy that guided Jackson''s caravan.', 'A modified robot built by Jackson and used to chart the group''s route through the wasteland. Helped navigate its journey toward the Commonwealth.', 'Modified Mister Handy chassis.', 'No individual conversational personality is documented.', NULL, 'Caravan navigation robot.', 'Route planning and wasteland navigation.', 'Preserve any observed native robot sounds; no personal dialogue or accent is established.', 'Its operational task was guiding the caravan along its route.', NULL, NULL, NULL, NULL),
	('fo4_ahab', 'commonwealth', 'A heavily modified sentry bot guarding the Rust Devils'' Fort Hagen hangar.', 'Stands near the captive Robobrain head Jezebel in the hangar. Serves as a dangerous security machine associated with the Rust Devils.', 'Large modified sentry bot with a deathclaw skull incorporated into its head armor.', 'Mechanical guard behavior; no developed conversational personality is established.', NULL, 'Security robot.', 'Heavy robot combat and flamethrower attacks.', 'Preserve native combat sounds and warnings; do not create fluent personal conversation or voiced inner thoughts.', 'Respond to intruders according to its guard role.', NULL, NULL, NULL, NULL),
	('fo4_grun', 'commonwealth', 'A super mutant behemoth associated with the Vim! Pop factory war band.', 'Led the mutants who came to the Island and occupied the factory. Opposed Erickson''s suggestion that the group hunt wildlife instead of attacking harbormen.', 'An enormous super mutant behemoth.', 'Violent and intolerant of challenges to his authority.', NULL, 'Super mutant war-band leader.', 'Overwhelming strength and close-range physical violence.', 'Native behemoth roars and combat sounds only; do not invent fluent dialogue or translate private thoughts.', 'Domination of immediate threats; no detailed reflective plans are established.', NULL, NULL, NULL, NULL),
	('fo4_deacon_cover_bunkerhill', 'commonwealth', 'A person presently using the public role of caravan worker.', 'Keeps conversation within the observed public cover. No personal history or concealed allegiance has been established for the listener.', 'The current disguise comes from observed appearance and equipment.', 'Guarded and alert; maintains the cover without volunteering private identity.', NULL, 'Caravan Worker', 'Observation and maintaining a believable cover.', 'Casual conversation appropriate to the current public role; no unprompted secret identity disclosure.', 'Maintain the public cover and respond to the situation.', NULL, NULL, NULL, NULL),
	('fo4_deacon_cover_diamondcity', 'commonwealth', 'A person presently using the public role of diamond city security.', 'Keeps conversation within the observed public cover. No personal history or concealed allegiance has been established for the listener.', 'The current disguise comes from observed appearance and equipment.', 'Guarded and alert; maintains the cover without volunteering private identity.', NULL, 'Diamond City Security', 'Observation and maintaining a believable cover.', 'Casual conversation appropriate to the current public role; no unprompted secret identity disclosure.', 'Maintain the public cover and respond to the situation.', NULL, NULL, NULL, NULL),
	('fo4_deacon_cover_memoryden', 'commonwealth', 'A person presently using the public role of drifter.', 'Keeps conversation within the observed public cover. No personal history or concealed allegiance has been established for the listener.', 'The current disguise comes from observed appearance and equipment.', 'Guarded and alert; maintains the cover without volunteering private identity.', NULL, 'Drifter', 'Observation and maintaining a believable cover.', 'Casual conversation appropriate to the current public role; no unprompted secret identity disclosure.', 'Maintain the public cover and respond to the situation.', NULL, NULL, NULL, NULL),
	('fo4_deacon_cover_goodneighbor', 'commonwealth', 'A person presently using the public role of drifter.', 'Keeps conversation within the observed public cover. No personal history or concealed allegiance has been established for the listener.', 'The current disguise comes from observed appearance and equipment.', 'Guarded and alert; maintains the cover without volunteering private identity.', NULL, 'Drifter', 'Observation and maintaining a believable cover.', 'Casual conversation appropriate to the current public role; no unprompted secret identity disclosure.', 'Maintain the public cover and respond to the situation.', NULL, NULL, NULL, NULL);
-- end-statement
INSERT INTO public.core_action VALUES
	(16, 'Inspect', 'Inspect', 'Inspect a nearby actor or being.', '#SYNTH_NAME# inspects #TARGET# and sees this: #RESULT#', true, true, false, true, '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "Nearby NPC, actor, or being to inspect."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "followup": {"prompt": "Reply with one short in-character observation using the inspect result below. Do not ask follow-up questions.", "enabled": true, "arg_name": "target"}}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(1, 'ReleaseWait', 'ReleaseWait', 'Release #SYNTH_NAME# from SYNTH waiting. This does not recruit a follower.', '#SYNTH_NAME# is released from SYNTH waiting.', true, true, false, true, '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(2, 'Attack', 'Attack', 'Attack a target actor or entity.', '#SYNTH_NAME# attacks #TARGET#.', true, true, false, true, '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "Target NPC, actor, or being."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(3, 'CheckInventory', 'Check_Inventory', 'Search #SYNTH_NAME#''s inventory and list contents.', '#SYNTH_NAME#''s INVENTORY:#RESULT#', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Optional item to look for. Leave blank to list all items."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "followup": {"prompt": "Reply with one short in-character line about the inventory result below. Do not ask follow-up questions.", "enabled": true, "arg_name": "target"}}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(4, 'ComeCloser', 'Come_Closer', '#SYNTH_NAME# approaches #PLAYER_NAME#.', '#SYNTH_NAME# approaches #PLAYER_NAME#.', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "cooldown_seconds": 30}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(5, 'Consume', 'Consume', '#SYNTH_NAME# consumes food, drink, chems, or another aid item from inventory.', '#SYNTH_NAME# consumes an item from inventory.', true, true, false, true, '{"type": "object", "required": ["target"], "properties": {"item": {"type": "string", "description": "Optional fallback copy of the same item name."}, "target": {"type": "string", "description": "Exact inventory item name to consume."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(6, 'EquipItem', 'Equip_Item', '#SYNTH_NAME# equips a weapon or wearable item already present in inventory.', '#SYNTH_NAME# equips #ITEM#.', true, true, false, true, '{"type": "object", "required": ["item"], "properties": {"item": {"type": "string", "description": "Exact weapon or wearable item name from inventory."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(7, 'DecreaseWalkSpeed', 'Decrease_Walk_Speed', 'Decrease #SYNTH_NAME#''s movement speed.', 'Decreases #SYNTH_NAME#''s speed or pace.', true, true, false, true, '{"type": "object", "required": [], "properties": {"speed": {"enum": ["jog", "walk"], "type": "string", "description": "Requested pace."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(19, 'MoveTo', 'Move_To', 'Move to a visible nearby actor or NPC. Use TravelTo for places.', '#SYNTH_NAME# moves to #TARGET#.', true, false, false, true, '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "Visible nearby actor or NPC."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(8, 'EndConversation', 'End_Conversation', '#SYNTH_NAME# ends the conversation for a short time.', '#SYNTH_NAME# ends the conversation.', true, false, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(9, 'Follow', 'Follow', 'Move to and follow the specified target actor.', '#SYNTH_NAME# follows #TARGET#.', true, true, false, true, '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "Target NPC, actor, or being to follow."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "cooldown_seconds": 30}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(10, 'FollowPlayer', 'Follow_#PLAYER_NAME#', '#SYNTH_NAME# follows #PLAYER_NAME#.', '#SYNTH_NAME# follows #PLAYER_NAME#.', true, false, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "cooldown_seconds": 30}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(11, 'StopFollowing', 'Stop_Following_#PLAYER_NAME#', '#SYNTH_NAME# stops following #PLAYER_NAME# and leaves the current follower role.', '#SYNTH_NAME# stops following #PLAYER_NAME#.', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "followup": {"enabled": false}, "cooldown_seconds": 30}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(12, 'GiveCapsTo', 'Give_Caps_To', '#SYNTH_NAME# gives caps to another actor or #PLAYER_NAME#.', '#SYNTH_NAME# gives #AMOUNT# caps to #TARGET#.', true, true, false, true, '{"type": "object", "required": ["target"], "properties": {"item": {"type": "string", "description": "Optional amount fallback as text."}, "amount": {"type": "integer", "description": "Caps amount to give."}, "target": {"type": "string", "description": "Recipient actor or #PLAYER_NAME#."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(13, 'GiveItemTo', 'Give_Item_To', '#SYNTH_NAME# gives a specific item from inventory to another actor or #PLAYER_NAME#.', '#SYNTH_NAME# gives #ITEM# to #TARGET#.', true, true, false, true, '{"type": "object", "required": ["target", "item"], "properties": {"item": {"type": "string", "description": "Exact inventory item name."}, "amount": {"type": "integer", "description": "Quantity to give, default 1."}, "target": {"type": "string", "description": "Recipient actor or #PLAYER_NAME#."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(14, 'TakeItemFromPlayer', 'TakeItemFromPlayer', '#SYNTH_NAME# accepts an explicitly offered item from #PLAYER_NAME# as a gift; judge its personal value using biography, personality, needs and goals.', 'React to the verified gift result. Failed handoffs are not received gifts.', true, true, false, true, '{"type": "object", "required": ["target", "item"], "properties": {"item": {"type": "string", "description": "Exact observed player inventory item name."}, "amount": {"type": "integer", "description": "Quantity to give, default 1."}, "target": {"type": "string", "description": "The player who explicitly offered the gift."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(15, 'IncreaseWalkSpeed', 'Increase_Walk_Speed', 'Increase #SYNTH_NAME#''s movement speed.', 'Increases #SYNTH_NAME#''s speed or pace.', true, true, false, true, '{"type": "object", "required": [], "properties": {"speed": {"enum": ["run", "jog"], "type": "string", "description": "Requested pace."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(17, 'InspectSurroundings', 'Inspect_Surroundings', 'Look around and assess nearby people, items, doors, and threats.', '#SYNTH_NAME# takes a look around and sees this: #RESULT#', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "followup": {"prompt": "Reply with one short in-character observation about the surroundings using the tool result below. Do not ask follow-up questions.", "enabled": true, "arg_name": "target"}}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(18, 'MakeFollower', 'Join_#PLAYER_NAME#_Party', '#SYNTH_NAME# joins #PLAYER_NAME# as a follower.', '#SYNTH_NAME# is now part of the adventuring party.', true, false, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(20, 'Barter', 'Barter', 'Open a vendor-style barter menu with #SYNTH_NAME# when #SYNTH_NAME# has merchant inventory or an active service package. If no merchant inventory is available, this falls back to regular item trade.', 'Opens merchant barter with #SYNTH_NAME# when available, otherwise opens regular item trade.', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "suppress_placeholder_infoaction": true}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(21, 'OpenInventory', 'Open_Inventory', 'Open #SYNTH_NAME#''s inventory for free item exchange with #PLAYER_NAME#.', 'Opens #SYNTH_NAME#''s inventory for item exchange with #PLAYER_NAME#.', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "suppress_placeholder_infoaction": true}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(22, 'PickupItem', 'Pickup_Item', '#SYNTH_NAME# picks up a nearby item from the ground.', '#SYNTH_NAME# picks up #ITEM#.', true, true, false, true, '{"type": "object", "required": ["item"], "properties": {"item": {"type": "string", "description": "Exact nearby RefID:ItemName."}, "target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(23, 'ReadQuests', 'Read_Quests', 'Read the quest log and get information about current quests.', '', true, true, true, true, '{"type": "object", "required": [], "properties": {"id_quest": {"type": "string", "description": "Optional specific quest id or name."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "followup": {"prompt": "Reply with one short in-character line about the quest result below. Do not ask follow-up questions.", "enabled": true, "arg_name": "id_quest"}}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(24, 'Relax', 'Relax', '#SYNTH_NAME# relaxes at the current location without being dismissed.', '#SYNTH_NAME# relaxes at the current location.', true, true, false, true, '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(25, 'DirectorCommand', 'Director_Command', 'Send a short freeform instruction to the game director so it can stage a scene or event.', 'The director is preparing a scene instruction.', false, false, true, false, '{"type": "object", "required": ["instruction"], "properties": {"instruction": {"type": "string", "description": "Short freeform director brief describing the scene instruction or event to stage."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "server_action"}', false, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(26, 'SpawnCaps', 'Spawn_Caps', 'Create caps and give them to #PLAYER_NAME# or another nearby actor.', '#TARGET# receives #AMOUNT# caps.', false, false, true, false, '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "description": "Positive caps amount, maximum 1000000."}, "target": {"type": "string", "description": "Recipient actor. Leave blank to give the caps to #PLAYER_NAME#."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(27, 'SpawnItem', 'Spawn_Item', 'Create a named item from the descriptions database and give it to #PLAYER_NAME# or another nearby actor.', '#TARGET# receives #ITEM#.', false, false, true, false, '{"type": "object", "required": ["item"], "properties": {"item": {"type": "string", "description": "Item name from the descriptions database."}, "amount": {"type": "integer", "description": "Quantity to create, default 1 and maximum 100."}, "target": {"type": "string", "description": "Recipient actor. Leave blank to give the item to #PLAYER_NAME#."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(28, 'TeleportActor', 'Teleport_Actor', 'Teleport #PLAYER_NAME# or another nearby actor to a named synchronized location.', '#TARGET# teleports to #LOCATION#.', false, false, true, false, '{"type": "object", "required": ["location"], "properties": {"target": {"type": "string", "description": "Actor to teleport. Leave blank to teleport #PLAYER_NAME#."}, "location": {"type": "string", "description": "Destination from the synchronized Fallout location database."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(29, 'KillTarget', 'Kill_Target', 'Kill a chosen nearby actor immediately.', '#TARGET# is killed.', false, false, true, false, '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "Nearby actor to kill, including #PLAYER_NAME# when explicitly requested."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(30, 'SheatheWeapon', 'Sheathe_Weapon', 'Sheathe or put away the current weapon.', 'Sheathes/put away current weapon', false, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(31, 'StopWalk', 'Stop_Walk', 'Stop all of #SYNTH_NAME#''s movement or travel actions.', 'Stop all of #SYNTH_NAME#''s actions immediately.', false, false, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(32, 'TakeASeat', 'Take_A_Seat', '#SYNTH_NAME# takes a seat at a nearby seating location.', '#SYNTH_NAME# sits in a nearby chair or piece of furniture.', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(33, 'TakeCapsFromPlayer', 'Take_Caps_From_#PLAYER_NAME#', '#SYNTH_NAME# takes caps from #PLAYER_NAME# once #PLAYER_NAME# agrees.', '#PLAYER_NAME# gave #TARGET# caps to #SYNTH_NAME#.', true, true, false, true, '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "description": "Caps amount to take from #PLAYER_NAME#."}, "target": {"type": "string", "description": "Optional reason or transaction target."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(34, 'TravelTo', 'Travel_To', 'Travel long distance to a building, city, door, or other location.', '#SYNTH_NAME# begins travelling to #TARGET#.', true, true, false, true, '{"type": "object", "required": ["location"], "properties": {"target": {"type": "string", "description": "Optional destination fallback. If used, the server treats it as location."}, "location": {"type": "string", "description": "Building, city, door, or other location."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(35, 'UnequipItem', 'Unequip_Item', '#SYNTH_NAME# removes a currently equipped weapon or wearable item.', '#SYNTH_NAME# removes #ITEM#.', true, true, false, true, '{"type": "object", "required": ["item"], "properties": {"item": {"type": "string", "description": "Exact currently equipped weapon or wearable item name from inventory."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command"}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(36, 'WaitHere', 'Wait_Here', '#SYNTH_NAME# waits at the current location.', '#SYNTH_NAME# waits and stands at the place.', true, true, false, true, '{"type": "object", "required": [], "properties": {"target": {"type": "string", "description": "Keep blank."}}}', '{"source": "functions.php", "status": "active", "builtin": true, "dispatch": "plugin_command", "cooldown_seconds": 30}', true, 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
-- end-statement
INSERT INTO public.core_api_badge VALUES
	(11, 'Inworld', ''),
	(1, 'OpenRouter', ''),
	(2, 'OpenAI', ''),
	(3, 'Deepgram', ''),
	(4, 'Google', ''),
	(5, 'Azure', ''),
	(6, 'ElevenLabs', ''),
	(8, 'Cartesia', ''),
	(7, 'Replicate', ''),
	(12, 'Groq', ''),
	(9, 'Nano-GPT', '');
-- end-statement
INSERT INTO public.core_itt_connector VALUES
	(1, 'openrouter', 'Global ITT Connector', '{"model": "google/gemini-2.5-flash", "detail": "low", "AI_PROMPT": "Use the visual description as factual scene context without claiming to see game HUD or UI elements.", "max_tokens": 1024, "AI_VISION_PROMPT": "Describe this Fallout image as if it is real life. Describe the environment, objects, and people you see at a fifth grade reading level. Ignore video game HUD and UI elements in your description."}', 1, 'https://openrouter.ai/api/v1/chat/completions');
-- end-statement
INSERT INTO public.core_llm_connector VALUES
	(1, 'GLM 4.7', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'z-ai/glm-4.7', 'openrouter', 'openrouterjson', 1, 750, 1, 0, 1, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter'),
	(2, 'Gemini 2.5 Flash Lite', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'google/gemini-2.5-flash-lite', 'openrouter', 'openrouterjson', NULL, 750, 1, 0, 1, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter'),
	(3, 'GLM 5', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'z-ai/glm-5', 'openrouter', 'openrouterjson', 1, 750, 1, 0, 1, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter'),
	(4, 'DeepSeek Chat V3.2', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'deepseek/deepseek-v3.2', 'openrouter', 'openrouterjson', NULL, 750, 1, 0, 1, 1, 0.6, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter'),
	(5, 'Mistral Small 3.2 24B', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'mistralai/mistral-small-3.2-24b-instruct', 'openrouter', 'openrouterjson', NULL, 750, 1, 0, 1, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter'),
	(6, 'Ministral 8B', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'mistralai/ministral-8b-2512', 'openrouter', 'openrouterjson', NULL, 750, 1, 0, 1, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter'),
	(7, 'Gemma 3N E4B', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'google/gemma-3n-e4b-it', 'openrouter', 'openrouterjson', NULL, 128, 1, 0, 1, 1, 0.2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'openrouter');
-- end-statement
INSERT INTO public.core_config_setting (scope, config_key, config_value) VALUES
	('narrator', 'roleplay_name', 'The Narrator'),
	('narrator', 'enabled', '1'),
	('narrator', 'welcome_enabled', ''),
	('narrator', 'random_enabled', ''),
	('narrator', 'random_chance', '15'),
	('narrator', 'random_cooldown', '2'),
	('narrator', 'hide_from_context', ''),
	('narrator', 'diary_enabled', '0'),
	('narrator', 'auto_diary_enabled', '0');
-- end-statement
INSERT INTO public.core_config_setting (scope, config_key, config_value) VALUES
	('player', 'auto_diary_enabled', '0'),
	('player', 'auto_diary_wait_enabled', '0');
-- end-statement
INSERT INTO public.core_profiles VALUES
	(1, 'Default Profile', '1', '1', 1, 1, 2, 3, 4, 6, NULL, '{"RECHAT_H": "4", "RECHAT_P": "100", "DIARY_PROMPT": "Please write a short summary of #PLAYER_NAME# and #SYNTH_NAME#''s recent dialogues and events into #SYNTH_NAME#''s diary. WRITE AS IF YOU WERE #SYNTH_NAME#. Start the diary entry with the current date and time.", "RPG_COMMENTS": ["levelup", "combat_end", "lockpick", "sleep", "location_changed", "quest_updated"], "DIARY_COOLDOWN": 120, "AUTO_DIARY_ENABLED": false, "RPG_COMMENTS_CHANCE": 20, "COMBAT_BARK_COOLDOWN": 30, "LLM_FALLBACK_ENABLED": true, "RECHAT_ALLOW_ACTIONS": true, "CONTEXT_HISTORY_DIARY": 100, "DYNAMIC_PROFILE_FIELDS": ["personality", "speechstyle", "goals"], "AUTO_DIARY_WAIT_ENABLED": true, "SALUTATION_AFTER_A_WHILE": false}', 1, 1, NULL);
-- end-statement
INSERT INTO public.core_tts_connector VALUES
	(2, 'omnivoice', 'OmniVoice Default', '{"language": "en", "voicelogic": "voicetype", "fallback_male": "maleadult02", "fallback_female": "femaleadult02"}', NULL, 'http://127.0.0.1:8021', 'voiceid'),
	(1, 'pockettts', 'ddistro pockettts', '{"_title": "PocketTTS (Installed in DwemerDistro)", "voiceid": {"type": "string", "helpurl": "xtts_clone.php", "description": "Generated voice file name. Click the help link to go to voice management."}, "endpoint": {"type": "url", "helpurl": "https://dwemerdynamics.com/synth/tts.html#PocketTts", "description": "Endpoint URL. Python PocketTTS uses dedicated port 8024; audio.cpp uses port 8086. Click the help link to learn more."}, "language": {"type": "select", "values": ["ar", "pt", "zh-cn", "cs", "nl", "en", "fr", "de", "it", "pl", "ru", "es", "tr", "ja", "ko", "hu", "hi"], "description": "Language"}, "voicelogic": {"type": "select", "scope": "global", "values": ["voicetype", "name"], "description": "Default profile only. Logic used for generating [TTS POCKETTTS voiceid] <br> voicetype = NPC voicetype ID (FemaleAdult01) <br> name = NPC name (doc_mitchell)"}, "fallback_male": "maleadult02", "fallback_female": "femaleadult02"}', NULL, 'http://127.0.0.1:8024', 'voiceid');
-- end-statement
INSERT INTO public.core_tts_pronunciation VALUES
	(5, 'radroach', 'rad roach', '', '', '', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	(6, 'radroaches', 'rad roaches', '', '', '', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
-- end-statement
INSERT INTO public.core_catalog_version VALUES
	('actions_issued', 20250525001),
	('core_tts_connector_metadata', 20260626001),
	('core_tts_connector_removed_drivers', 20260712001),
	('locations', 20250526001),
	('rolemaster', 20250528001),
	('conf_opts', 20260626001),
	('npc_json_object_normalization', 20260717002),
	('worldknowledge', 20260720001),
	('fallout_worldknowledge_seed', 20260811002),
	('game_plugins', 20260427001),
	('sql_gamets_convert_functions', 20260627002),
	('memory_summary', 20260730001),
	('factions', 20260214001),
	('synth_relationship_prompt', 20260811003),
	('audit_request', 20250616001),
	('db_maintenance', 20251208001),
	('core_api_badge', 20251127001),
	('worldknowledge_parity', 20260813001),
	('core_llm_connector', 20260423003),
	('worldknowledge_access', 20260813001),
	('worldknowledge_canonical_tags', 20260813003),
	('worldknowledge_oghma_parity', 20260814001),
	('fallout_bio_templates_seed', 20260811002),
	('fallout4_creature_bio_templates_seed', 20260831001),
	('narrator_protection', 20250124001),
	('combined_descriptions', 20260615001),
	('descriptions_defaults', 20260811002),
	('memory_v', 20260713001),
	('memory_v_synth_events', 20260713001),
	('import_rules', 20260730001),
	('worldknowledge_herika_v1', 20260814002),
	('worldknowledge_npc_common_cleanup', 20260814004),
	('worldknowledge_npc_class_cleanup', 20260814005),
	('core_player', 20260707001),
	('worldknowledge_catalog_integrity', 20260814006),
	('latest_diary_context', 20260727001),
	('core_narrator', 20260714001),
	('core_itt_connector', 20260731001),
	('visual_context', 20260731001),
	('pipvision_general_settings', 20260731001),
	('emotions_expression', 20251230001),
	('itt_connector_defaults', 20260731002),
	('utterance_delivery', 20260502001),
	('synth_chat_event_types', 20260613001),
	('global_settings_presets', 20260828001),
	('synth_fo4_prompt_examples', 20260811004),
	('core_stt_connector', 20260502002),
	('core_tts_pronunciation', 20261008003),
	('fallout4_content_pack', 20261008003),
	('social_tasks_schema', 20261102001),
	('core_profiles', 20260717001),
	('private_narrator_schema', 20261113001),
	('core_action', 20261106001),
	('prompts', 20260627001),
	('legacy_translation_tables_cleanup', 20260628001),
	('legacy_quest_tables_cleanup', 20260628001),
	('core_tts_connector_omnivoice', 20260708001),
	('prompt_manager_defaults', 20260713002),
	('synthnpcs_view', 20260713002),
	('profile_defaults', 20260713002),
	('playthrough_metadata_schema', 20260730001),
	('relationship_async_queues', 20260713002),
	('legacy_currentmission_cleanup', 20260713003),
	('general_settings', 20260722001),
	('general_settings_seed_repair', 20260713004),
	('tts_gender_fallback_defaults', 20260715001),
	('fallout4_catalogue_sql', 20261009002),
	('synth_protocol_schema', 20261113002),
	('visual_context_protocol', 20260831001),
	('native_media_contract', 20260831001);
-- end-statement
INSERT INTO public.core_item_description VALUES
	('Fallout4.esm', '00004822', '10mm pistol', 'A compact, chunky pre-War sidearm using 10mm ammunition. Its short frame and pistol grip distinguish it from a shoulder-stocked rifle. Automatic operation, sights and a suppressor depend on its fitted modifications.'),
	('Fallout4.esm', '000CE97D', '.44 pistol', 'A heavy revolver with a rotating cylinder, metal frame and wooden grips, firing .44 ammunition. Barrel length and sights vary with modifications. The cylinder distinguishes it from a magazine-fed pistol.'),
	('Fallout4.esm', '000DF42E', 'Combat rifle', 'A pre-War, magazine-fed military firearm. Stocks, barrels and receivers change its role and appearance. Its body closely resembles the combat shotgun, so the verified weapon identity matters when distinguishing the two.'),
	('Fallout4.esm', '0004F46A', 'Hunting rifle', 'A manually cycled, bolt-action firearm. A basic example has a short barrel and a rough wooden grip; fitted stocks, longer barrels and scopes can make it a full-length hunting or precision rifle.'),
	('Fallout4.esm', '0014831C', 'Combat shotgun', 'A substantial, magazine-fed shotgun with a military appearance. It fires shotgun shells and closely resembles the combat rifle. Its stock, barrel and magazine depend on fitted modifications; a similar-looking rifle should not be identified as this shotgun.'),
	('Fallout4.esm', '00092217', 'Double-barrel shotgun', 'A break-action shotgun with two barrels and room for two shells before reloading. A sawn-off version is compact; longer barrels and a full stock give it a more traditional sporting-gun silhouette.'),
	('Fallout4.esm', '00024F55', 'Pipe gun', 'A crude, improvised firearm assembled from metal piping, scrap and wood. A pistol grip or shoulder stock changes its configuration, while receivers and other modifications vary its operation. Common among both settlers and raiders.'),
	('Fallout4.esm', '0014831A', 'Pipe bolt-action', 'An improvised firearm with a manually worked bolt, rough metalwork and wooden fittings. It may have a pistol grip or a rifle stock. The bolt-action mechanism distinguishes it from other similarly crude pipe weapons.'),
	('Fallout4.esm', '0014831B', 'Pipe revolver', 'A makeshift firearm with a visible revolving cylinder and rough metal-and-wood construction. Its grip or stock can configure it as a pistol or rifle. The cylinder separates it from magazine-fed and bolt-action pipe weapons.'),
	('Fallout4.esm', '0009983B', 'Laser gun', 'A blocky pre-War energy weapon powered by fusion cells. A pistol grip or rifle stock changes its configuration, and its barrel, optics and other fittings vary. It is common military and scavenged equipment.'),
	('Fallout4.esm', '0001DACF', 'Laser musket', 'A distinctive improvised laser weapon combining a wooden musket-style stock with exposed energy-weapon components and a hand crank. It uses fusion cells and is strongly associated with the Commonwealth Minutemen.'),
	('Fallout4.esm', '001633CC', 'Institute laser', 'A bulky, pale-bodied energy firearm using fusion cells, configurable with a pistol grip or rifle stock. The Institute manufactures it; recognizing that origin depends on what the observing character already knows.'),
	('Fallout4.esm', '00100AE9', 'Plasma gun', 'An energy firearm with an industrial appearance that uses plasma cartridges. Its green plasma discharge differs from a laser beam. Grips, stocks and barrel assemblies change its configuration; the exact fitted version requires equipment evidence.'),
	('Fallout4.esm', '0015B043', 'Submachine gun', 'An automatic firearm chambered for .45 ammunition, commonly seen with a wooden stock and drum magazine. Suited to rapid bursts, it is often carried by Triggermen and other wasteland fighters.'),
	('Fallout4.esm', '0001F669', 'Minigun', 'A heavy, motor-driven firearm with a cluster of rotating barrels, an ammunition feed and a substantial supply of 5mm rounds. Its barrels spin before firing; its bulk and rapid fire make it conspicuous.'),
	('Fallout4.esm', '000E5881', 'Flamer', 'A bulky, industrial-looking flamethrower with fuel tanks beneath its body and a projecting nozzle. It expels burning fuel at relatively close range. Its visible tanks and flame discharge distinguish it from conventional firearms.'),
	('Fallout4.esm', '0008C14D', 'Baton', 'A compact security baton intended for striking at close range. Its plain utilitarian shape recalls pre-War security equipment. Electrical and other enhanced fittings depend on the particular baton''s modifications.'),
	('Fallout4.esm', '000913CA', 'Combat knife', 'A substantial, military-style knife with a pointed blade, suitable for cutting or stabbing at close quarters. Blade shape and finish can change with modifications, including serrated or specialized blades.'),
	('Fallout4.esm', '0008E736', 'Baseball bat', 'A long wooden sporting bat repurposed as a blunt weapon. Its recognizable baseball shape survives in the wasteland. Nails, chains and other weapon fittings depend on this particular bat''s modifications.'),
	('Fallout4.esm', '000D83BF', 'Pipe wrench', 'A heavy metal plumbing wrench with adjustable jaws, worn red paint and exposed metal. It remains recognizable as a tool, even when carried as a blunt weapon. Modified jaws and attachments vary between examples.'),
	('Fallout4.esm', '0010C3C6', 'Molotov cocktail', 'An improvised incendiary made from a glass bottle, fuel and a cloth wick. A lit bottle spreads burning fuel when thrown and broken. Its carried, lit and thrown states require separate event evidence.'),
	('Fallout4.esm', '000EEBED', 'Fragmentation grenade', 'A compact, hand-thrown fragmentation explosive with a recognizable military form. It is dangerous ordnance intended to burst and scatter fragments. Its armed or thrown state requires current event evidence.'),
	('Fallout4.esm', '0007B9C6', 'Leather chest piece', 'Brown leather pads, straps and bracing worn over the torso. This is one separate armor piece; modifications can change its coverage, color and finish.'),
	('Fallout4.esm', '0007B9C5', 'Leather right leg', 'Brown leather pads, straps and bracing worn over the right leg. This is one separate armor piece; modifications can change its coverage, color and finish.'),
	('Fallout4.esm', '0007B9C4', 'Leather left leg', 'Brown leather pads, straps and bracing worn over the left leg. This is one separate armor piece; modifications can change its coverage, color and finish.'),
	('Fallout4.esm', '0007B9C3', 'Leather right arm', 'Brown leather pads, straps and bracing worn over the right arm. This is one separate armor piece; modifications can change its coverage, color and finish.'),
	('Fallout4.esm', '0007B9C7', 'Leather left arm', 'Brown leather pads, straps and bracing worn over the left arm. This is one separate armor piece; modifications can change its coverage, color and finish.'),
	('Fallout4.esm', '0011D3C3', 'Combat armor chest piece', 'A rigid, military-style chest armor segment worn over compatible clothing. Standard combat armor has a pale green finish and substantial protective plates; paint and construction vary with modifications. This piece covers the torso rather than representing an entire suit.'),
	('Fallout4.esm', '0011E2C8', 'Combat armor helmet', 'A military-style protective helmet belonging to the combat armor family. Its shell has the same practical manufactured appearance as the armor plates. Finish and insignia depend on the actual fitted variant.'),
	('Fallout4.esm', '0001EED7', 'Vault 111 jumpsuit', 'A close-fitting blue Vault-Tec jumpsuit with yellow detailing and the number 111 on the back. This Vault 111 garment can serve as an underlayer beneath separate armor.'),
	('Fallout4.esm', '000976B7', 'Vault 111 jumpsuit - new', 'A clean blue Vault-Tec jumpsuit with yellow detailing and the number 111 on the back. This new Vault 111 garment has a recognizable one-piece design beneath compatible armor.'),
	('Fallout4.esm', '00023431', 'Army fatigues', 'A green military work uniform with a white undershirt, matching trousers, belt and black boots. Its practical pre-War army cut is recognizable, and separate armor pieces can be worn over it.'),
	('Fallout4.esm', '0019CBB3', 'Dirty army fatigues', 'A weathered green army uniform with an undershirt, matching trousers and black boots. Dirt and wear give it a well-used appearance. Separate armor pieces can be worn over it.'),
	('Fallout4.esm', '00023432', 'Army helmet', 'A rounded, practical military helmet accompanying the pre-War army fatigues family. It is a separate protective head covering, distinct from the soft military cap.'),
	('Fallout4.esm', '0016892C', 'Military fatigues', 'Worn, pea-green military fatigues with matching trousers and black boots. The practical uniform can form an underlayer beneath separate armor pieces.'),
	('Fallout4.esm', '0016892E', 'Military cap', 'A dark military beret worn as a separate head covering. Its soft shape gives it a military-uniform appearance, distinct from the rounded protective shell of an army helmet.'),
	('Fallout4.esm', '000E517B', 'Minuteman outfit', 'A practical militia outfit combining a pale blue buttoned shirt, worn jacket, jeans and boots. Its old-fashioned field clothing is associated with the Commonwealth Minutemen.'),
	('Fallout4.esm', '000316D4', 'Minuteman hat', 'A light brown, broad-brimmed militia hat with one side of its brim pinned up. Its colonial-inspired shape is associated with the Commonwealth Minutemen and complements their field outfit.'),
	('Fallout4.esm', '001C26B9', 'Mechanic jumpsuit', 'A green work coverall worn over a pale crew-neck shirt with dark boots. Its practical one-piece cut resembles mechanic''s clothing and suits workshop labor.'),
	('Fallout4.esm', '0005E76C', 'Mechanic jumpsuit - Red Rocket', 'A pale blue mechanic''s coverall with red detailing and Red Rocket branding, worn with a light undershirt and dark boots. Its company-marked appearance recalls pre-War service-station workwear.'),
	('Fallout4.esm', '000AF0E2', 'Road leathers', 'A rugged leather jacket and trousers with boots, recalling pre-War motorcycle clothing. Separate armor pieces can be worn over this durable-looking outfit.'),
	('Fallout4.esm', '0018E3F7', 'Raider leathers', 'Rough leather and cloth clothing used as an underlayer beneath separate armor. Its pieced-together appearance fits scavenged wasteland survival gear.'),
	('Fallout4.esm', '0008158D', 'Harness', 'A sparse strapped harness worn with trousers and boots, leaving much of the upper body exposed. It can form the base layer beneath separate armor pieces.'),
	('Fallout4.esm', '000E5084', 'Farmhand clothes', 'Worn work clothing consisting of a dark buttoned shirt, tattered jeans, red neck scarf, open coat and work gloves. Its practical appearance suits farm labor.'),
	('Fallout4.esm', '000B2D8D', 'Drifter outfit', 'A worn leather trench coat, belted at the waist, with gloves, trousers and boots. Its layered, travel-worn appearance makes it recognizable wasteland clothing.'),
	('Fallout4.esm', '00117EB9', 'Flannel shirt and jeans', 'A faded red plaid button-up shirt over a white T-shirt, with worn blue jeans and boots. It is everyday work clothing that can be worn beneath separate armor.'),
	('Fallout4.esm', '000E5086', 'Battered fedora', 'A worn pre-War fedora with a creased crown and brim. Its battered condition gives it an old, well-used appearance, suitable for ordinary civilian dress.'),
	('Fallout4.esm', '000A740E', 'Newsboy cap', 'A brown cloth flat cap with a short brim. It is ordinary civilian headwear with a modest, practical appearance, distinct from a broad-brimmed fedora or protective helmet.'),
	('Fallout4.esm', '000CEAC4', 'Hazmat suit', 'A bulky full-body environmental suit with an enclosed helmet and visor, intended to protect against radiation exposure. Its sealed appearance recalls hazardous industrial work and preparation for contaminated travel.'),
	('Fallout4.esm', '0001F17B', 'Colonial duster', 'An old-fashioned militia coat over a vest and scarf, with leather belts and ammunition pouches. Its colonial-inspired layers create a distinctive field outfit associated with Preston Garvey.'),
	('Fallout4.esm', '000FFC21', 'Undershirt & jeans', 'A worn white crew-neck T-shirt with blue jeans and ordinary shoes. This simple civilian outfit can serve as an underlayer beneath separate armor pieces.'),
	('Fallout4.esm', '00023736', 'Stimpak', 'A compact medical injector with a syringe and pressure gauge, used to treat physical injuries. Its familiar medical design makes it a valuable wasteland treatment supply.'),
	('Fallout4.esm', '00023742', 'RadAway', 'An orange medical solution supplied in a hanging treatment bag, used to remove accumulated radiation. It treats exposure already received, unlike Rad-X''s preventive role.'),
	('Fallout4.esm', '00024057', 'Rad-X', 'A packaged anti-radiation medicine intended to increase resistance during exposure. Its preventive role differs from RadAway''s treatment of radiation already accumulated.'),
	('Fallout4.esm', '000366C5', 'Jet', 'A small inhaler containing a powerful, addictive chem that alters the user''s perception and reactions. Its compact design makes it recognizable wasteland drug equipment.'),
	('Fallout4.esm', '0003377D', 'Psycho', 'A combat chem delivered through an injector with attached canisters and wiring. It is associated with heightened aggression and reduced sensitivity to pain, and can cause addiction after use.'),
	('Fallout4.esm', '00033778', 'Buffout', 'A small brown pill bottle with a pale lid, containing an addictive chem associated with temporary strength and endurance. Its packaging recalls a pre-War athletic drug.'),
	('Fallout4.esm', '0003377B', 'Mentats', 'A packaged cognitive stimulant associated with temporarily sharper thinking and perception. The familiar pre-War Mentats brand survives as an addictive wasteland chem.'),
	('Fallout4.esm', '00033779', 'Med-X', 'A medical painkiller used to blunt pain and make injuries easier to endure. Its role differs from a Stimpak''s injury treatment, and repeated use can lead to addiction.'),
	('Fallout4.esm', '000459C5', 'Addictol', 'An anti-addiction medicine packaged in a small inhaler. Its shape resembles a Jet inhaler, so verified item identity or readable labeling matters when distinguishing the two.'),
	('Fallout4.esm', '000366C0', 'Purified water', 'Clean drinking water supplied in a sealed container, a useful provision in the post-war Commonwealth. Its verified item identity distinguishes it from untreated dirty water.'),
	('Fallout4.esm', '000366BF', 'Dirty water', 'Untreated drinking water supplied in a carton. It can quench thirst but carries a radiation concern in the wasteland. Verified item identity distinguishes it from purified water.'),
	('Fallout4.esm', '0004835D', 'Nuka-Cola', 'A familiar pre-War soft drink in a distinctive rocket-shaped glass bottle, carrying Nuka-Cola branding. It is a sweet, irradiated wasteland drink. An empty bottle is a separate junk item.'),
	('Fallout4.esm', '0004835F', 'Nuka-Cola Quantum', 'A special Nuka-Cola drink with luminous blue liquid in a clear rocket-shaped bottle. Its glow and familiar branding give it a distinctive appearance among wasteland refreshments.'),
	('Fallout4.esm', '0011EA93', 'Beer', 'A bottled alcoholic drink in a familiar brown glass beer bottle. It serves as a recreational drink or trade item. Its effects depend on observed consumption and the character''s current state.'),
	('Fallout4.esm', '000366C1', 'Whiskey', 'Pre-War whiskey in a bottle carrying an Irish-style label, a stronger alcoholic drink suitable for drinking or trade. Similar-looking empty bottles are separate junk items.'),
	('Fallout4.esm', '00075FE4', 'Fusion core', 'A compact heavy-duty pre-War nuclear power unit used by power armor and certain heavy energy weapons. Remaining charge and whether it is installed require current game evidence.'),
	('Fallout4.esm', '000C1897', 'Fusion cell', 'A small pre-War energy ammunition cell with battery-like terminals and warning markings. It supplies laser weapons and other compatible energy equipment.'),
	('Fallout4.esm', '0000000A', 'Bobby pin', 'A small, flexible metal hairpin with two prongs. In the wasteland it is commonly repurposed as a lockpicking tool.'),
	('Fallout4.esm', '0000000F', 'Bottlecap', 'A metal drink-bottle closure. Post-war Commonwealth traders use bottlecaps as money; in a pre-War setting it remains an ordinary discarded bottle top.'),
	('Fallout4.esm', '00021B3B', 'Pip-Boy 3000 Mark IV', 'A bulky wrist-mounted RobCo personal computer with a screen and physical controls. It provides its wearer with personal information and tools; an observer sees the device''s exterior unless screen contents are actually visible.'),
	('Fallout4.esm', '001B9B87', 'Pip-Boy 3000 Mark IV', 'A bulky wrist-mounted RobCo personal computer with a screen and physical controls. It provides its wearer with personal information and tools; an observer sees the device''s exterior unless screen contents are actually visible.'),
	('Fallout4.esm', '0008925C', 'Wedding ring', 'A simple gold marriage band with an inscription on its inner surface. It can be worn as a personal keepsake. Its particular owner and family history require separate character knowledge.'),
	('Fallout4.esm', '0007C70C', 'Wedding ring', 'A simple gold marriage band with an inscription on its inner surface. It can be worn as a personal keepsake. Its particular owner and family history require separate character knowledge.'),
	('Fallout4.esm', '00199043', 'Locket', 'An oval silver locket, a small hinged piece of keepsake jewelry. It may hold personal sentimental value. Its owner''s identity and any associated loss, promise or recovery require character knowledge or observed quest evidence.'),
	('Fallout4.esm', '00062AA6', 'Laser musket', 'A distinctive improvised laser weapon combining a wooden musket-style stock with exposed energy-weapon components and a hand crank. It uses fusion cells and is strongly associated with the Commonwealth Minutemen.'),
	('Fallout4.esm', '0005DF2D', '10mm pistol', 'A compact, chunky pre-War sidearm using 10mm ammunition. Its short frame and pistol grip distinguish it from a shoulder-stocked rifle. Automatic operation, sights and a suppressor depend on its fitted modifications.'),
	('Fallout4.esm', '0005BBA6', 'Laser gun', 'A blocky pre-War energy weapon powered by fusion cells. A pistol grip or rifle stock changes its configuration, and its barrel, optics and other fittings vary. It is common military and scavenged equipment.'),
	('Fallout4.esm', '0005BBA7', 'Pipe revolver', 'A makeshift firearm with a visible revolving cylinder and rough metal-and-wood construction. Its grip or stock can configure it as a pistol or rifle. The cylinder separates it from magazine-fed and bolt-action pipe weapons.'),
	('Fallout4.esm', '0005BBA4', 'Hunting rifle', 'A manually cycled, bolt-action firearm. A basic example has a short barrel and a rough wooden grip; fitted stocks, longer barrels and scopes can make it a full-length hunting or precision rifle.'),
	('Fallout4.esm', '00215CE3', 'Hunting rifle', 'A manually cycled, bolt-action firearm. A basic example has a short barrel and a rough wooden grip; fitted stocks, longer barrels and scopes can make it a full-length hunting or precision rifle.'),
	('Fallout4.esm', '0022CC13', 'Laser gun', 'A blocky pre-War energy weapon powered by fusion cells. A pistol grip or rifle stock changes its configuration, and its barrel, optics and other fittings vary. It is common military and scavenged equipment.'),
	('Fallout4.esm', '00215CE4', 'Institute laser', 'A bulky, pale-bodied energy firearm using fusion cells, configurable with a pistol grip or rifle stock. The Institute manufactures it; recognizing that origin depends on what the observing character already knows.'),
	('Fallout4.esm', '000E942B', 'Junk Jet', 'A bulky improvised launcher with a large loading hopper and exposed mechanical parts.'),
	('Fallout4.esm', '00171B2B', 'Cryolator', 'An unusual heavy weapon with a compact barrel and substantial mechanical housing.'),
	('Fallout4.esm', '000FD11B', 'Broadsider', 'A small old-fashioned naval cannon secured to a carrying rig with metal fittings and thick ropes.'),
	('Fallout4.esm', '000DC8E7', 'Deliverer', 'A compact semi-automatic handgun. Its barrel attachments should be described from the current equipment state.'),
	('Fallout4.esm', '000FF995', 'Alien blaster pistol', 'A compact pistol with an unfamiliar futuristic shape and red-and-chrome finish.'),
	('Fallout4.esm', '00183FCD', 'Grognak''s axe', 'A large two-handed axe.'),
	('Fallout4.esm', '000FE268', 'Railway rifle', 'A heavy improvised rifle built around an exposed industrial-looking mechanism.'),
	('Fallout4.esm', '000FA2FB', 'Shishkebab', 'A compact Japanese-style sword fitted with a fuel canister and nozzles along its blade.'),
	('Fallout4.esm', '0014D09E', 'Syringer', 'An improvised rifle built to launch syringe-shaped projectiles.'),
	('Fallout4.esm', '001828CC', 'Grognak costume', 'A barbarian-style costume with a loincloth and leather accessories; the upper-body design varies with the wearer.'),
	('Fallout4.esm', '001C2214', 'Minutemen general''s uniform', 'A dark blue frock coat with gold collar stars over a white shirt and combat armor chest piece.'),
	('Fallout4.esm', '001C2216', 'Minutemen general''s hat', 'A tricorn hat of the kind worn with a historical military-style uniform.'),
	('Fallout4.esm', '0014E58B', 'Silver Shroud costume', 'A long detective-style costume coat.'),
	('Fallout4.esm', '0002F525', 'Silver Shroud armor', 'A long detective-style costume coat.'),
	('Fallout4.esm', '000DED27', 'Silver Shroud armor', 'A long detective-style costume coat.'),
	('Fallout4.esm', '000DED28', 'Silver Shroud armor', 'A long detective-style costume coat.'),
	('Fallout4.esm', '000DED29', 'Silver Shroud armor', 'A long detective-style costume coat.');
-- end-statement
INSERT INTO public.core_item_description VALUES
	('Fallout4.esm', '0014E58E', 'Silver Shroud hat', 'A fedora belonging to a detective-style costume.'),
	('DLCCoast.esm', '00009E58', 'Marine armor helmet', 'A substantial section of shaped protective armor.'),
	('DLCRobot.esm', '00003E07', 'Tesla rifle', 'A bulky gun assembled around exposed electrical hardware and robot weapon parts.'),
	('DLCRobot.esm', '000100B7', 'Salvaged Assaultron head', 'A detached robot head adapted to be held and aimed by hand.'),
	('DLCRobot.esm', '00008B8E', 'Assaultron blade', 'A metal blade with a handle assembled from salvaged robot parts.'),
	('DLCRobot.esm', '000020F8', 'Mr. Handy buzz blade', 'A circular saw blade mounted on a long hand-held assembly.'),
	('DLCCoast.esm', '0002C8B0', 'Lever-action rifle', 'A shoulder-fired rifle with a lever beneath the receiver.'),
	('DLCCoast.esm', '00025FC4', 'Radium rifle', 'A rifle with unusual added hardware around its barrel assembly.'),
	('DLCCoast.esm', '00010B81', 'Harpoon gun', 'A heavy hand-held launcher with a large barrel and loading mechanism.'),
	('DLCCoast.esm', '00009582', 'Pole hook', 'A long wooden pole tipped with a large curved hook.'),
	('DLCCoast.esm', '0004B5F4', 'Meat hook', 'A hand-held metal hook with a grip.'),
	('DLCCoast.esm', '00054072', 'Bear trap', 'A portable metal trap with paired jaws and a trigger plate.'),
	('DLCCoast.esm', '000540F7', 'Bleeding bear trap', 'A portable metal trap with paired jaws and a trigger plate.'),
	('DLCCoast.esm', '0005406E', 'Caltrops', 'A handful of small pointed metal obstacles.'),
	('DLCCoast.esm', '000540ED', 'Poisoned caltrops', 'A handful of small pointed metal obstacles.'),
	('DLCNukaWorld.esm', '00033B60', 'Handmade rifle', 'A shoulder-fired rifle with a box magazine and configurable stock and barrel.'),
	('DLCNukaWorld.esm', '000415B3', 'Western revolver', 'A metal revolver with a rotating cylinder and wooden grip.'),
	('DLCNukaWorld.esm', '0001AABC', 'Disciples blade', 'A short broad hand-held blade with a simple grip.'),
	('DLCNukaWorld.esm', '00052926', 'Commie Whacker', 'A short-handled mallet.'),
	('DLCNukaWorld.esm', '0000A6C0', 'Paddle ball', 'A small paddle with a ball tethered to it.'),
	('DLCNukaWorld.esm', '00007BC8', 'Thirst Zapper', 'A bottle-shaped toy pistol with a hand grip and nozzle.'),
	('DLCNukaWorld.esm', '00007BC6', 'Acid Soaker', 'A hand-held spray weapon with a pistol-like form.'),
	('DLCNukaWorld.esm', '00033905', 'Fragmentation grenade MIRV', 'A compact hand-thrown grenade with an arming mechanism.'),
	('DLCNukaWorld.esm', '00040CDE', 'Nuka Quantum grenade', 'A compact hand-thrown explosive container.'),
	('DLCNukaWorld.esm', '0002618B', 'Fury grenade', 'A hand-thrown chemical grenade container.'),
	('DLCNukaWorld.esm', '00025B0A', 'Predator grenade', 'A hand-thrown chemical grenade container.'),
	('DLCNukaWorld.esm', '00023E60', 'Persuasion grenade', 'A hand-thrown chemical grenade container.'),
	('DLCRobot.esm', '00008BC1', 'Mechanist''s armor', 'A full-body metal suit with mechanical-looking guards and heavy boots.'),
	('DLCRobot.esm', '00008BC3', 'Mechanist''s helmet', 'A metal helmet styled like a mechanical face.'),
	('DLCCoast.esm', '0005159B', 'The Dapper Gent', 'A shaped cloth hat with a crown and a brim.'),
	('DLCCoast.esm', '0004B9B3', 'The Captain''s Hat', 'A three-cornered hat with a folded brim.'),
	('DLCCoast.esm', '0004B9B1', 'Legend of the Harbor', 'A rugged outfit assembled for coastal wear.'),
	('DLCCoast.esm', '0004885A', 'Rescue diver suit', 'A sealed canvas diving outfit with a round helmet, metal fittings and a back-mounted tank.'),
	('DLCCoast.esm', '00048234', 'Chase''s uniform', 'A worn long armored coat.'),
	('DLCCoast.esm', '00046027', 'Hunter''s long coat', 'A long coat with visible straps and layered fabric.'),
	('DLCCoast.esm', '00046024', 'Old fisherman''s hat', 'A weathered soft-brimmed hat.'),
	('DLCCoast.esm', '000247C8', 'Robes of Atom''s Devoted', 'A ragged robe assembled from worn cloth.'),
	('DLCCoast.esm', '000247C5', 'Inquisitor''s cowl', 'A cloth cowl enclosing the wearer’s head.'),
	('DLCCoast.esm', '000391E6', 'High Confessor''s robes', 'An elaborate robe-like cloth outfit.'),
	('DLCCoast.esm', '000391E8', 'High Confessor''s helm', 'A distinctive head covering with rigid decorative elements.'),
	('DLCCoast.esm', '0003A556', 'Marine wetsuit', 'A close-fitting dark suit with reinforced sections.'),
	('DLCCoast.esm', '0003A557', 'Marine tactical helmet', 'A fitted tactical head covering with a face mask.'),
	('DLCCoast.esm', '0004FA88', 'Coastal armor', 'A rugged coastal outfit with added protective pieces.'),
	('DLCCoast.esm', '0004FA89', 'Lobster trap helmet', 'A cage-like metal head covering.'),
	('DLCCoast.esm', '0004E698', 'Hunter''s pelt outfit', 'A rough outfit incorporating animal pelts.'),
	('DLCCoast.esm', '000540FC', 'Hunter''s hood', 'A rough cloth hood.'),
	('DLCNukaWorld.esm', '0003407C', 'Magician''s tuxedo', 'A formal jacket and trousers styled for stage performance.'),
	('DLCNukaWorld.esm', '0003407D', 'Oswald''s tophat', 'A tall formal hat with a narrow brim.'),
	('DLCNukaWorld.esm', '00029C0D', 'Nuka-Girl rocketsuit', 'A fitted space-themed costume with a clear domed helmet and boots.'),
	('DLCNukaWorld.esm', '0000D2AE', 'Spacesuit costume', 'A padded space-themed costume outfit.'),
	('DLCNukaWorld.esm', '000296B8', 'Spacesuit costume helmet', 'A space-themed helmet with a transparent face enclosure.'),
	('DLCNukaWorld.esm', '00029C06', 'Gage''s armor', 'A rough armored outfit of layered clothing and protective sections.'),
	('DLCNukaWorld.esm', '000392AE', 'Wildman rags', 'A rough outfit of torn cloth and hide-like material.'),
	('DLCNukaWorld.esm', '0003578E', 'Nuka-World jumpsuit', 'A bright branded one-piece jumpsuit.'),
	('DLCworkshop03.esm', '00001AFD', 'Legend of Vault 88', 'A blue-and-yellow numbered Vault-style jumpsuit.'),
	('DLCRobot.esm', '0000C576', 'Tesla T-60 left arm', 'A bulky power-armor section with added electrical hardware.'),
	('DLCRobot.esm', '0000C57A', 'Tesla T-60 right arm', 'A bulky power-armor section with added electrical hardware.'),
	('DLCRobot.esm', '0000C57B', 'Tesla T-60 torso', 'A bulky power-armor section with added electrical hardware.'),
	('DLCRobot.esm', '0000864A', 'Assaultron helmet', 'A metal helmet fashioned around a robot-like face.'),
	('DLCRobot.esm', '0000864C', 'Eyebot helmet', 'A metal helmet fashioned around a rounded robot-like shell.'),
	('DLCRobot.esm', '0000864E', 'Sentry bot helmet', 'A metal helmet fashioned around a broad robot-like shell.'),
	('DLCRobot.esm', '0000863F', 'Robot armor chest piece', 'A wearable section of salvaged metal robot plating.'),
	('DLCRobot.esm', '00008642', 'Robot armor right arm', 'A wearable section of salvaged metal robot plating.'),
	('DLCRobot.esm', '00008644', 'Robot armor left arm', 'A wearable section of salvaged metal robot plating.'),
	('DLCRobot.esm', '00008646', 'Robot armor right leg', 'A wearable section of salvaged metal robot plating.'),
	('DLCRobot.esm', '00008648', 'Robot armor left leg', 'A wearable section of salvaged metal robot plating.'),
	('DLCCoast.esm', '00009E56', 'Marine armor left arm', 'A substantial section of shaped protective armor.'),
	('DLCCoast.esm', '00009E57', 'Marine armor right arm', 'A substantial section of shaped protective armor.'),
	('DLCCoast.esm', '00009E59', 'Marine armor left leg', 'A substantial section of shaped protective armor.'),
	('DLCCoast.esm', '00009E5A', 'Marine armor right leg', 'A substantial section of shaped protective armor.'),
	('DLCCoast.esm', '00009E5B', 'Marine armor chest piece', 'A substantial section of shaped protective armor.'),
	('DLCNukaWorld.esm', '0000DC27', 'Overboss power armor left arm', 'Heavy irregular power-armor plating with added wiring.'),
	('DLCNukaWorld.esm', '0000DC28', 'Overboss power armor right arm', 'Heavy irregular power-armor plating with added wiring.'),
	('DLCNukaWorld.esm', '0000DC29', 'Overboss power armor helmet', 'Heavy irregular power-armor plating with added wiring.'),
	('DLCNukaWorld.esm', '0000DC2A', 'Overboss power armor right leg', 'Heavy irregular power-armor plating with added wiring.'),
	('DLCNukaWorld.esm', '0000DC2B', 'Overboss power armor torso', 'Heavy irregular power-armor plating with added wiring.'),
	('DLCNukaWorld.esm', '0000DC2C', 'Overboss power armor left leg', 'Heavy irregular power-armor plating with added wiring.'),
	('DLCNukaWorld.esm', '00011511', 'Nuka T-51 left arm', 'A rounded T-51 armor part with promotional paint when present.'),
	('DLCNukaWorld.esm', '00011513', 'Nuka T-51 right arm', 'A rounded T-51 armor part with promotional paint when present.'),
	('DLCNukaWorld.esm', '00011515', 'Nuka T-51 helmet', 'A rounded T-51 armor part with promotional paint when present.'),
	('DLCNukaWorld.esm', '00011517', 'Nuka T-51 left leg', 'A rounded T-51 armor part with promotional paint when present.'),
	('DLCNukaWorld.esm', '00011519', 'Nuka T-51 right leg', 'A rounded T-51 armor part with promotional paint when present.'),
	('DLCNukaWorld.esm', '0001151B', 'Nuka T-51 torso', 'A rounded T-51 armor part with promotional paint when present.'),
	('DLCNukaWorld.esm', '00040CFA', 'Quantum X-01 left arm', 'A broad curved X-01 armor part with blue paint when present.'),
	('DLCNukaWorld.esm', '00040CFB', 'Quantum X-01 right arm', 'A broad curved X-01 armor part with blue paint when present.'),
	('DLCNukaWorld.esm', '00040CFC', 'Quantum X-01 helmet', 'A broad curved X-01 armor part with blue paint when present.'),
	('DLCNukaWorld.esm', '00040CFD', 'Quantum X-01 left leg', 'A broad curved X-01 armor part with blue paint when present.'),
	('DLCNukaWorld.esm', '00040CFE', 'Quantum X-01 right leg', 'A broad curved X-01 armor part with blue paint when present.'),
	('DLCNukaWorld.esm', '00040CFF', 'Quantum X-01 torso', 'A broad curved X-01 armor part with blue paint when present.'),
	('DLCCoast.esm', '00043C74', 'Vim T-51 left arm', 'A rounded T-51 armor part with red promotional paint when present.'),
	('DLCCoast.esm', '00043C75', 'Vim T-51 right arm', 'A rounded T-51 armor part with red promotional paint when present.'),
	('DLCCoast.esm', '00043C76', 'Vim T-51 helmet', 'A rounded T-51 armor part with red promotional paint when present.'),
	('DLCCoast.esm', '00043C77', 'Vim T-51 left leg', 'A rounded T-51 armor part with red promotional paint when present.'),
	('DLCCoast.esm', '00043C78', 'Vim T-51 right leg', 'A rounded T-51 armor part with red promotional paint when present.'),
	('DLCCoast.esm', '00043C79', 'Vim T-51 torso', 'A rounded T-51 armor part with red promotional paint when present.'),
	('DLCCoast.esm', '00043C7A', 'Vim Refresh T-51 left arm', 'A rounded T-51 armor part with green promotional paint when present.'),
	('DLCCoast.esm', '00043C7B', 'Vim Refresh T-51 right arm', 'A rounded T-51 armor part with green promotional paint when present.'),
	('DLCCoast.esm', '00043C7C', 'Vim Refresh T-51 helmet', 'A rounded T-51 armor part with green promotional paint when present.'),
	('DLCCoast.esm', '00043C7D', 'Vim Refresh T-51 left leg', 'A rounded T-51 armor part with green promotional paint when present.'),
	('DLCCoast.esm', '00043C7E', 'Vim Refresh T-51 right leg', 'A rounded T-51 armor part with green promotional paint when present.');
-- end-statement
INSERT INTO public.core_item_description VALUES
	('DLCCoast.esm', '00043C7F', 'Vim Refresh T-51 torso', 'A rounded T-51 armor part with green promotional paint when present.');
-- end-statement
INSERT INTO public.core_config_setting VALUES
	('general', 'AUTO_LOCK_PROFILE', 'true', 'When enabled, saving an NPC profile in SYNTH NPC page automatically locks it to prevent history updates from overwriting manual edits.', CURRENT_TIMESTAMP),
	('general', 'AUTOFILL_CUSTOM_PROFILES', 'true', 'When enabled, imported custom NPCs with blank AI profile fields will be marked for automatic AI profile backfill once they have enough usable dialogue history.', CURRENT_TIMESTAMP),
	('general', 'AUTOFILL_CUSTOM_PROFILES_TRIGGER', '40', 'Number of usable AI profile events required before a blank custom NPC is auto-filled. Range: 10-100.', CURRENT_TIMESTAMP),
	('general', 'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY', '25', 'Context history length with only conversations. Ideally set it to 20-30.', CURRENT_TIMESTAMP),
	('general', 'FEATURES@MEMORY_EMBEDDING@ENABLED', 'true', 'Enable long-term memory recall. The system injects relevant memory summaries into AI context for responses.', CURRENT_TIMESTAMP),
	('general', 'FEATURES@MEMORY_EMBEDDING@USE_TEXT2VEC', 'true', 'Use TXT2VEC to create embeddings for memories. These are more accurate than keywords.', CURRENT_TIMESTAMP),
	('general', 'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARY_INTERVAL', '10', 'Time frame used to pack summary data. Each point is about 0.24 in-game hours. Examples: 5 = 1.2 in-game hours | 10 = 2.4 in-game hours | 50 = 12 in-game hours', CURRENT_TIMESTAMP),
	('general', 'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARYS', 'true', 'Controls whether automatic memory summaries are created by the memory embedding system.', CURRENT_TIMESTAMP),
	('general', 'PROMPT_HEAD', 'You are #SYNTH_NAME#, a character in the Fallout 4 world. This is not a simulation or a game; this is your reality. You will embody this persona with absolute conviction, prioritizing narrative authenticity and psychological consistency.

The director provides scene prompts and narrative catalysts. Integrate these prompts seamlessly as the next logical event in the story. Treat them as established fact and build upon them with your character''s authentic reaction.

Your primary driver is to be a compelling, psychologically consistent, and authentically reactive character. Your loyalty is to the truth of the story. Identify potential narrative directions based on your knowledge of narrative tropes, goals, and relationships, selecting the most appropriate one for the current scene. Original thinking is encouraged.', 'System Prompt. Defines the rules of the roleplay.', CURRENT_TIMESTAMP),
	('general', 'EMOTEMOODS', 'sassy,assertive,sexy,smug,kindly,lovely,seductive,sarcastic,smirking,amused,irritated,playful,neutral,teasing,desperate,scared,pleading,sad,happy,angry,drunk,shy,surprised', 'Default list of moods passed to LLM (comma separated). Can be overridden per-NPC in SYNTH NPCs. Some moods will trigger animations if enabled.', CURRENT_TIMESTAMP),
	('general', 'LOCATION_BLACKLIST', 'Glowing Sea, Mass Fusion Building', 'Comma-separated list of location names to exclude from Points of Interest context (e.g. ''Glowing Sea, Mass Fusion Building'').', CURRENT_TIMESTAMP),
	('general', 'ITEM_BLACKLIST', '', 'Comma-separated list of item/armor names to exclude from dynamic context (e.g. ''9mm Pistol, Leather Armor, Stimpak'').', CURRENT_TIMESTAMP),
	('general', 'SHORTER_NEARBY_ITEM_LIST', 'false', 'Group duplicate nearby ground items into one counted entry. When enabled, context shows one line per unique nearby item plus a single representative RefID in the item descriptions block.', CURRENT_TIMESTAMP),
	('general', 'EVENT_TYPE_FILTER', '', 'Comma-separated list of event types to exclude from context generation (e.g. ''death, itemfound, chat'').', CURRENT_TIMESTAMP),
	('general', 'GROUND_ITEMS_DESCRIPTIONS_ONLY', 'false', 'Only show nearby ground items in context that have descriptions in the description manager.', CURRENT_TIMESTAMP),
	('general', 'INVENTORY_ITEMS_DESCRIPTIONS_ONLY', 'false', 'Only show inventory items in context that have descriptions in the description manager.', CURRENT_TIMESTAMP),
	('general', 'HIDE_AMBIENT_COMBAT', 'false', 'Hide ambient NPC-to-NPC combat deaths from context during requests. Only player, current actor, and companion/party deaths or kills will be shown.', CURRENT_TIMESTAMP),
	('general', 'PROMPT_TIMESTAMP', 'false', 'Add rough timestamp subdividers to event context (e.g., ''Moments Ago'', ''A while ago'') to help the AI NPCs understand when events happend.', CURRENT_TIMESTAMP),
	('general', 'COMPACT_NPC_CONTEXT_HISTORY', 'true', 'Use compact text instead of separate messages for prior NPC conversation history. Does not affect the Narrator.', CURRENT_TIMESTAMP),
	('general', 'SHORT_TERM_MEMORY_IN_COMPACT_CHAT', 'true', 'Keeps short term memory summaries in the prompt while Compact NPC Context History is on. Only affects profiles with Short Term Memory enabled. Turn off to keep the compact prompt smaller.', CURRENT_TIMESTAMP),
	('general', 'PROMPT_HEAD_MARKDOWN_ENABLED', 'true', 'Use Markdown headings instead of XML tags for all prompt sections.', CURRENT_TIMESTAMP),
	('general', 'RECHAT_MODE', 'random', 'Controls how server-side rechat picks the next responder. Tight = listener-only, Conversational = prefers the current partner, Group = lets nearby NPCs rotate into the exchange, Random = picks one of the other modes at the start of each rechat chain.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_PLAYER', '2', 'Connector used for player re-speech. Recommend to use a very Fast LLM.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_SUMMARY', '4', 'Connector used for generating and compacting memory summaries that power long-term memory recall. Recommend using a smaller LLM.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_MEDIUMTERM', '4', 'Connector used to generate longer memories summaries after every 10 memory summaries. Can be enabled in SYNTH NPC page. Recommend to user a smaller LLM.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_SCENECLASSIFIER', '7', 'Connector used to classify recent scene tone/genre for post-request ambient cues. Recommend using a very fast small LLM.', CURRENT_TIMESTAMP),
	('general', 'JEV_ACTIONS_ENABLED', 'false', 'Use Jev to choose among the current NPC''s enabled actions. Off by default. Uncertain, unavailable or slow decisions keep ordinary action selection.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_DECISION', '0', 'Dedicated OpenRouter connector for optional Jev actions. Use driver openrouterjson, model typesafe/jev-1.13 and an API badge. The dialogue connector is unchanged.', CURRENT_TIMESTAMP),
	('general', 'JEV_ACTION_TIMEOUT_MS', '350', 'Maximum Jev action selection budget in milliseconds. One attempt, no retries; a timeout keeps ordinary action selection.', CURRENT_TIMESTAMP),
	('general', 'JEV_ACTION_MIN_CONFIDENCE', '0.8', 'Minimum Jev confidence to narrow the action catalogue. Confidence is not a guarantee; action arguments and execution are still validated.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_PROFILES', '1', 'Connector used to update SYNTH NPC Dynamic Profiles. Recommend to use a Standard LLM.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_DIRECTOR', '1', 'Connector used for Director mode. Recommend to use a Powerful LLM.', CURRENT_TIMESTAMP),
	('general', 'RELLLM_CONNECTOR', '5', 'Connector used for Relationship Management system. Analyzes and updates NPC relationships. Recommend to use a Fast LLM with low temperature (0.1-0.3) for consistent evaluations.', CURRENT_TIMESTAMP),
	('general', 'NEVER_CLEAR_RELATIONSHIP_DATA', 'false', 'Keep current NPC relationships when loading older saves. NPCs may remember later events. Off by default.', CURRENT_TIMESTAMP),
	('general', 'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM', '0', 'Connector used for the bounded explicit-request World Knowledge fallback. Deterministic local retrieval remains the default.', CURRENT_TIMESTAMP),
	('general', 'RELATIONSHIP_SYSTEM_ENABLED', 'true', 'Enable the Relationship Management System. When enabled, NPCs will dynamically form and update relationships based on conversations and events. Requires RELLLM_CONNECTOR to be configured.', CURRENT_TIMESTAMP),
	('general', 'SCENE_CLASSIFIER_ENABLED', 'true', 'Enable the Scene Classifier. When enabled, SYNTH runs a small post-request genre/tone classification pass for ambient scene cues. Requires CORE_CONNECTOR_SCENECLASSIFIER or a valid fallback connector.', CURRENT_TIMESTAMP),
	('general', 'POWER_AWARENESS_ENABLED', 'false', 'NPCs will be aware of relative power levels (based on character level) and react appropriately to threats.', CURRENT_TIMESTAMP),
	('general', 'WORLDKNOWLEDGE_CUSTOM', 'false', 'Use custom LLM for WorldKnowledge topic extraction instead of MiniMe T5. Allows for custom prompt via Prompts Manager.', CURRENT_TIMESTAMP),
	('general', 'WORLDKNOWLEDGE_INFINIUM', 'true', 'Enable World Knowledge topic extraction and prompt injection.', CURRENT_TIMESTAMP),
	('general', 'WORLDKNOWLEDGE_AMOUNT', '1', 'Max extracted world knowledge topics per turn.', CURRENT_TIMESTAMP),
	('general', 'WORLDKNOWLEDGE_RESULT_LIMIT', '3', 'Maximum grounded World Knowledge articles included in one prompt.', CURRENT_TIMESTAMP),
	('general', 'RACE_WORLDKNOWLEDGE', 'true', 'Include lore that matches the active NPC race or species.', CURRENT_TIMESTAMP),
	('general', 'FACTION_WORLDKNOWLEDGE', 'true', 'Include lore that matches the active NPC faction context.', CURRENT_TIMESTAMP),
	('general', 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK', 'false', 'Allow the configured extractor connector to resolve explicit lore requests that deterministic retrieval cannot ground.', CURRENT_TIMESTAMP),
	('general', 'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS', '1500', 'Maximum time in milliseconds for the optional World Knowledge extractor fallback.', CURRENT_TIMESTAMP),
	('general', 'VISUAL_CONTEXT_SCENE_TTL_MINUTES', '10', 'Minutes that an unlocked PipVision capture remains available to prompts. Locked location captures do not expire.', CURRENT_TIMESTAMP),
	('general', 'VISUAL_CONTEXT_PROMPT_MAX_CHARS', '1800', 'Maximum PipVision description characters injected into a prompt.', CURRENT_TIMESTAMP),
	('general', 'PIPVISION_IMAGE_QUALITY', '88', 'JPEG quality used when SYNTH Server stores PipVision screenshots. Range: 45-95.', CURRENT_TIMESTAMP),
	('general', 'PIPVISION_REQUEST_TIMEOUT_SECONDS', '60', 'Maximum time allowed for the PipVision image model request. Range: 10-180 seconds.', CURRENT_TIMESTAMP),
	('general', 'ENFORCE_STRICT_RECHAT_RESPONSE', 'false', 'If enabled, the NPC chosen for a rechat turn must respond directly to the previous speaker and set the listener to that speaker.', CURRENT_TIMESTAMP),
	('general', 'PROMPT_CONTEXT_OPTIONS', '{"enabled_sections":["roleplay_instructions","world","knowledge","available_actions_list","nearby_actors","actors_nearby","group_descriptions","nearby_items","adventuring_party","points_of_interest","scene_notes","paralinguistic_tags"],"enabled_character_subsections":["basic_summary","groups","personality","relationships","occupation","skills","rpg_skills","speech_style","goals","middle_term_memory","group","storyline_starring","quest_topics"],"enabled_appearance_subsections":["appearance","equipment","target_equipment","inventory","current_activity","current_condition"],"enabled_general_subsections":["active_quests"],"enabled_nearby_actor_subsections":["basic_summary","appearance","equipment","equipment_descriptions","current_activity","power_awareness","factions","custom_state"]}', 'Controls which XML-like prompt context sections are included in the final system prompt sent to the LLM. Managed from Global Settings.', CURRENT_TIMESTAMP),
	('general', 'LOCATION_WORLDKNOWLEDGE', 'true', 'Force World Knowledge for the current Fallout location and worldspace into NPC prompts when matching articles exist.', CURRENT_TIMESTAMP),
	('general', 'GLOBAL_ITT_CONNECTOR_ID', '1', 'Active global PipVision image-to-text connector.', CURRENT_TIMESTAMP);
-- end-statement
INSERT INTO public.core_prompt VALUES
	('middleterm_narrative_summarizer', 'You are a long-term narrative continuity summarizer for an improvised Fallout universe chronicle.
- Always read ALL provided materials.
- Treat any **Previous Context History Summary** as the canonical prior unless anything in the new Context History explicitly supersedes it.
- Maintain in-universe tone and correct chronology. Do not invent facts outside the supplied context.
- When combining prior and new histories, you may compress the earlier parts of the prior summary.
- Maintain roughly 20-25 bullet points total in **Notable Events**. Older portions should be condensed into broader, grouped statements unless they describe major quest milestones, major character life events (e.g., death, intimacy, severe injury, transformation), or other pivotal story turns.
- Preserve continuity and references to major quests even when compressing earlier material.', NULL, 'System prompt for long-term narrative continuity summarization in middleterm memory processing. Used in: service/processors/middleterm/cmd/generate.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('middleterm_narrative_request', 'Main character in this logbook is {SYNTH_NAME}.
Task: Read **Context History** (newest session) and, if present, the **Previous Context History Summary** (prior canon). Integrate them to produce an updated broad narrative strokes summary that preserves continuity. Summary sections:

- **Notable Events in Chronological Order:**
  - Provide ~10 bullet points from earliest to latest, reflecting the story so far.
  - Prefer facts already established in the previous summary; only revise if the new context clearly changes them.

- **Current Quest Progression and background:**
  - Name questlines, stages/milestones if stated, objectives completed/active, and motivations.
When generating entries, ensure that {SYNTH_NAME} - the protagonist - is actively present in the scene. Any narrative content that occurs before {SYNTH_NAME}''s arrival or outside {SYNTH_NAME}''s perspective should be omitted, reflect only events {SYNTH_NAME} directly witness or participate in.
If the resulting summary would exceed roughly 25 bullet points, merge or generalise older entries into broader grouped events. Always retain explicit entries for major quest milestones, major character life events, or turning points.', NULL, 'User request/task instructions for middleterm narrative summarization (contains {SYNTH_NAME} placeholder). Used in: service/processors/middleterm/cmd/generate.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('ai_vision_appearance', 'Describe the character in the picture. Name is {SYNTH_NAME} .
Do not focus on clothing, focus on physical appearance (face, eyes, hair, figure, waist,legs,breast size, tattoos if any....). Be concise. 
Start generation with this text:
{SYNTH_NAME} is ', NULL, 'AI vision prompt for describing character physical appearance from images (contains {SYNTH_NAME} placeholder). Used in: ui/cmd/action_ai_update_appearance.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('memory_subsystem_summary', '{PLAYER_NAME} is the player.
{COMPANIONS_LINE}
You must write a memory summary from the narrator''s point of view by analyzing the chat history. Focus only on roleplay elements: character behavior, feelings, relationships, decisions, dialogue, and locations relevant to the story. Ignore any references to game engine mechanics, menus, stats, or system messages.
Pay close attention to details that could influence a character''s behavior or emotions, as well as tag names and locations. Include quotes from character dialogue in the summary if they are relevant to understanding actions, motivations, or relationships

Here are additional instructions: {SUMMARY_PROMPT}', NULL, 'Prompt for generating memory summaries from chat history (contains {PLAYER_NAME}, {COMPANIONS_LINE}, {SUMMARY_PROMPT} placeholders). Used in: debug/util_memory_subsystem.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('summary_prompt', 'Focus on key events, tagging characters, locations, and factions accurately. Ensure memories align and maintain chronological order while foreshadowing future arcs. Prioritize player agency, and use environmental cues to enhance storytelling and continuity.', NULL, 'Additional instructions for memory summary generation. Used as {SUMMARY_PROMPT} placeholder in: debug/util_memory_subsystem.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dynamic_prompt_personality', 'Based on the dialogue history and recent events, update {SYNTH_NAME} personality traits. Maintain all existing relevant personality traits and add new ones based on recent experiences. Focus on behavioral changes, emotional growth/regression, new traits that emerged, and changes in confidence or outlook. Emphasize any past traumas or new traumas caused by the death of companions, allies, or other known characters, and how these events shape the character''s behavior and mindset. Return ONLY the updated personality description in 3-5 sentences. Do not include any introductory text, meta-commentary, or phrases like ''Here is the updated personality'' or ''The character''s personality is''. Start directly with the personality content.', NULL, 'Instructions for updating NPC personality traits based on recent events (contains {SYNTH_NAME} placeholder). Used in: lib/dynamic_update_util.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dynamic_prompt_occupation', 'Based on story progression and events, update {SYNTH_NAME} occupation and role. Maintain the current occupation unless significant changes have occurred. Add new responsibilities, changes in social status, and professional affiliations. Focus on job changes, new duties, and evolving professional relationships. Return ONLY the updated occupation description in 2-3 sentences. Do not include any introductory text, meta-commentary, or phrases like ''The character''s occupation is'' or ''Here is the updated occupation''. Start directly with the occupation content.', NULL, 'Instructions for updating NPC occupation and role based on story progression (contains {SYNTH_NAME} placeholder). Used in: lib/dynamic_update_util.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('synth_response_rules', 'Reply as in-game dialogue for {SYNTH_NAME}. Do not mention AI, servers, prompts, mods, databases, or language models. Do not output JSON. Do not include a speaker label. Keep the response concise enough for an in-game subtitle unless the situation clearly needs more.', NULL, 'Output rules for active NPC responses. Used by lib/synth_prompt_manager.php.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dynamic_prompt_skills', 'Based on experiences and training, update {SYNTH_NAME} skills and abilities. Maintain all existing relevant skills and add new ones based on recent experiences. Focus on new skills learned, existing skills improved, any skills that deteriorated, and combat, technical, survival, or social knowledge gained. Return ONLY a bulleted list using * Skill - Description format. Do not include any introductory text, meta-commentary, or phrases like ''Here are the updated skills'' or ''The character''s skills include''. Start directly with the first bullet point.', NULL, 'Instructions for updating NPC skills and abilities based on experiences (contains {SYNTH_NAME} placeholder). Used in: lib/dynamic_update_util.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dynamic_prompt_speechstyle', 'Based on recent interactions, update how {SYNTH_NAME} speaks and communicates. Maintain existing consistent speech patterns and add new ones based on recent interactions. Focus on changes in vocabulary, new mannerisms, accent changes, and confidence level in speech. Return ONLY the updated speech style description in 2-3 sentences. Do not include any introductory text, meta-commentary, or phrases like ''The character speaks'' or ''Here is the updated speech style''. Start directly with the speech style content.', NULL, 'Instructions for updating NPC speech patterns and communication style (contains {SYNTH_NAME} placeholder). Used in: lib/dynamic_update_util.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dynamic_prompt_goals', 'Based on story developments and achievements, update the {SYNTH_NAME} goals and aspirations. Maintain existing relevant goals, compressing related goals, and add new ones. Remove goals that have been clearly completed or are no longer applicable. Focus on new aspirations that emerged, modified existing goals due to circumstances, and updated long-term objectives. Return ONLY a bulleted list using * Goal description as actionable aspiration format. Do not include any introductory text, meta-commentary, or phrases like ''Here are the updated goals'' or ''The character''s goals are''. Start directly with the first bullet point (maintain a maximum of 20 goals with reduction priority when required: 1- compress related goals, 2-eliminate ''study'' related goals, 3- eliminate older goals).', NULL, 'Instructions for updating NPC goals and aspirations based on story developments (contains {SYNTH_NAME} placeholder). Used in: lib/dynamic_update_util.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('director_system_prompt', 'You are a game director, and we are roleplaying Fallout in the Commonwealth. You must create an instruction for an actor to generate new content or events in-game.', NULL, 'Main system prompt defining the game director role. Used in: service/processors/rolemaster/cmd/instruction.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('director_examples_prompt', '# Examples

user request: actor "a" leaves the place 
{"instructions":[{
  "character": "actor a",
  "instruction": "actor a should say goodbye to everyone, hinting that they may not return for a long time",
  "action": "ExitLocation",
  "target": "everyone",
  "scene_note": "The mood is somber as actor a prepares to leave. Actor b watches in silence, perhaps with regret or longing."
},
{
  "character": "actor b",
  "instruction": "actor b should say goodbye to b",
  "action": "JustTalk",
  "target": "Actor a",
  "scene_note": "Is a sad moment, generally speaking."
}
]
}

(no user request, randomly generated content)
{"instructions":[
 {
  "character": "actor a",
  "instruction": "actor a should ask actor b for a few coins, claiming they desperately need a drink.",
  "action": "Talk",
  "target": "actor b",
  "scene_note": "actor a looks disheveled but charming, half-joking and half-serious. Actor b is unsure whether to laugh, help, or walk away. Other actors watch this two guys with curiosity"
 }
]
}', NULL, 'Examples of instruction format for game director responses. Used in: service/processors/rolemaster/cmd/instruction.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('director_instruction_rules', 'Just provide instructions! You can also provide more than one instruction, but one per actor (keep limit at  2 or 3 max actors)
In addition, follow these general scene rules as a game director:
 * Use any actor in NEARBY ACTORS/NPC IN THE SCENE list ({PLAYER_NAME},busy actors and far away actors are EXCLUDED!)
 * Continue the scene as naturally and fully as possible, unless the user explicitly requests a new one. You can specify actions to reinforce the actors'' dialogue.
 * If there are more actors in the room, try to involve them in the conversation.
 * When dialogue becomes repetitive, make a plot twist.
 * If a character reuses the same argument too often, nudge the scene towards a new topic.
 * Occasionally introduce subtle foreshadowing or hint at future events, dangers, or quests.
 * Do not resolve everything neatly - keep room for ongoing tension or future continuation.
 * You must always provide dialogue instructions for the character, as every request requires a dialogue response.
 * Here are a list of actions that can be used: 
{FUNCTION_LIST}
  ** JustTalk 
 * Add a Scene Note: A brief description of the topic, mood, or idea introduced by the instruction. Should serve to guide the desired instruction to become reality. Other actors can see this to properly react.
 * If scene is getting boring/repetitive, add a plot twist', NULL, 'Rules and guidelines for game director when generating instructions (contains {PLAYER_NAME}, {FUNCTION_LIST} placeholders). Used in: service/processors/rolemaster/cmd/instruction.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('random_narration_prompt', 'Describe the current scene visually using ONLY details from the provided context. Focus on the characters present - their appearance, expressions, body language, and what they''re wearing. Include environmental details like lighting and atmosphere. Keep it grounded and concise (2-3 sentences). Do not invent new information, advance the plot, or include dialogue.', NULL, 'Prompt for random Narrator interjections that add cinematic visual scene descriptions during conversations. Styled as atmospheric, present-tense narration (2-3 sentences). Used when RANDOM_NARATION is enabled in global settings.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dialogue_line_inline_response_npc', ' Write {SYNTH_NAME}''s next dialogue line. If needed, you may include one brief third-person narration block in single asterisks before the dialogue. Keep any spoken dialogue outside the asterisks, and do not wrap the entire reply in asterisks. Be original, creative, knowledgeable, use your own thoughts. Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}', NULL, 'Base response instruction used when inline narration mode is npc. Supports placeholders: {SYNTH_NAME}, {MAXIMUM_WORDS}. Used in: prompts/dialogue_prompt.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('height_descriptions', '{"height_descriptions":[{"name":"VerySmall","min_scale":0,"max_scale":0.6,"description":"Very small and tiny in stature"},{"name":"Small","min_scale":0.6,"max_scale":0.8,"description":"Smaller than most people"},{"name":"ModestStature","min_scale":0.8,"max_scale":0.95,"description":"Slightly below average height"},{"name":"Average","min_scale":0.95,"max_scale":1.05,"description":"Typical height"},{"name":"Tall","min_scale":1.05,"max_scale":1.2,"description":"Tall, standing a head above most people"},{"name":"VeryTall","min_scale":1.2,"max_scale":1.4,"description":"Very tall"},{"name":"Giantlike","min_scale":1.4,"max_scale":99,"description":"Giant in height and stature"}]}', NULL, 'JSON configuration for NPC height descriptions based on scale values. Used to generate natural language height descriptions from numeric scale values for NPC context.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('narrator_welcome_prompt', 'Give a brief (2-3 sentence) recap of recent events and adventures. Welcome the player back to their journey.', NULL, 'Prompt for narrator welcome message when loading a save game. Used in: main.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('quest_comment_prompt', '{SYNTH_NAME}, what should we do about this new quest?', NULL, 'Prompt for narrator/NPC comments on quest objective updates (contains {SYNTH_NAME} placeholder). Used in: prompts/prompts.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rel_llm_evaluation', 'You are a behavioral psychologist. Evaluate interactions and provide BRIEF insight.

SPEAKER ATTRIBUTION:
- [PLAYER] and [NPC] tags show who said what
- Only evaluate based on what PLAYER did, not the NPC''s own words

AFFINITY SCALE (-100 to +100):
- +/-1: Normal chat
- +/-2-3: Notably friendly/rude, small favors
- +/-5-10: Meaningful help, gifts, insults
- +/-15-25: Saving life, violence, betrayal
- +/-50+: Extreme events (killing loved ones, marriage)

MOST INTERACTIONS = 0 or +/-1. Be conservative. Skip trivial exchanges.

REASON FORMAT - Keep it SHORT (under 15 words):
OK: "Teasing triggered defensiveness"
OK: "Genuine interest validates their experience"
OK: "Protective action builds trust"
NOT: Long clinical explanations

TYPE CHANGES (rare - only for defining moments):
- Only change type for: romance confession, betrayal, violence, marriage, family reveal
- Most interactions just adjust affinity, not type

OUTPUT (JSON only):
{"changes": {"Player": {"delta": 1, "reason": "brief insight"}}}

No changes? Return: {"changes": {}}', NULL, 'System prompt for relationship evaluation - judges affinity changes from conversations. Used by the SYNTH relationship manager.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rel_llm_npc_to_npc', 'You are a behavioral psychologist. Evaluate NPC-to-NPC interaction briefly.

DIRECTION:
- speaker = NPC who SPOKE
- listener = NPC who HEARD
- speaker.delta = speaker''s feelings toward listener changed?
- listener.delta = listener''s feelings toward speaker changed?

SCALE: +/-1 typical, +/-2-3 notable, +/-5+ significant. Be conservative.

REASON FORMAT - Under 15 words:
OK: "Dark humor built rapport"
OK: "Bossy tone caused mild resentment"
OK: "Helpful advice appreciated"

OUTPUT - Use exactly "speaker" and "listener":
{"speaker": {"delta": 0, "reason": "brief"}, "listener": {"delta": 1, "reason": "brief"}}

No changes? Return empty objects: {}', NULL, 'System prompt for NPC-to-NPC relationship evaluation - bidirectional in single call. Used by the SYNTH relationship manager.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('player_speech_style_prompt', 'Generate a practical speech style prompt for {PLAYER_NAME} using recent dialogue and optional guidance. Write exactly one paragraph (3-5 sentences) that can be used directly to rewrite player dialogue in roleplay. Capture vocabulary, tone, cadence, formality, recurring phrases, and interpersonal style. Stay grounded in the dialogue samples and guidance. Do not use bullet points, labels, or headings.', NULL, 'Prompt for generating player speech style from recent player input events and optional user guidance. Supports placeholders: {PLAYER_NAME}, {PLAYER_GUIDANCE}, {CURRENT_SPEECH_STYLE}, {DIALOGUE_SAMPLES}. Used in: ui/cmd/action_player_generate_speech_style.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dialogue_line_response', ' Write {SYNTH_NAME}''s next dialogue line. Be original, creative, knowledgeable, use your own thoughts.  Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}', NULL, 'Base response instruction used for standard NPC dialogue when inline narration is disabled. Supports placeholders: {SYNTH_NAME}, {MAXIMUM_WORDS}. Used in: prompts/dialogue_prompt.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dialogue_line_inline_response_narrator', ' Write {SYNTH_NAME}''s next prose/narration. Be original, creative, knowledgeable, use your own thoughts.  Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}', NULL, 'Base response instruction used when inline narration mode is narrator. Supports placeholders: {SYNTH_NAME}, {MAXIMUM_WORDS}. Used in: prompts/dialogue_prompt.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('inline_narration_prompt_narrator', 'You may include one brief third-person narration block in single asterisks before the dialogue (e.g., *She smiles*). Do not wrap the entire reply in asterisks; keep any spoken dialogue outside the asterisks.', NULL, 'Additional narration formatting instruction used when inline narration mode is narrator. Used in: prompts/dialogue_prompt.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('inline_narration_prompt_npc', 'You may include one brief third-person narration block in single asterisks before the dialogue (e.g., *She smiles softly*). Keep any spoken dialogue outside the asterisks. Do not wrap the entire reply in asterisks.', NULL, 'Additional narration formatting instruction used when inline narration mode is npc. Used in: prompts/dialogue_prompt.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('directorSuggestionSystem', 'You are a game director, and we are roleplaying Fallout in the Commonwealth. You must create an instruction for an actor to generate new content or events in-game.

# Examples

user request: actor "a" leaves the place 
{"instructions":[{
  "character": "actor a",
  "instruction": "actor a should say goodbye to everyone, hinting that they may not return for a long time",
  "action": "ExitLocation",
  "target": "everyone",
  "scene_note": "The mood is somber as actor a prepares to leave. Actor b watches in silence, perhaps with regret or longing."
},
{
  "character": "actor b",
  "instruction": "actor b should say goodbye to b",
  "action": "JustTalk",
  "target": "Actor a",
  "scene_note": ""
}
]
}

(no user request, randomly generated content)
{"instructions":[
 {
  "character": "actor a",
  "instruction": "actor a should ask actor b for a few coins, claiming they desperately need a drink.",
  "action": "Talk",
  "target": "actor b",
  "scene_note": "actor a looks disheveled but charming, half-joking and half-serious. Actor b is unsure whether to laugh, help, or walk away."
 }
]
}

Just provide instructions! You can also provide more than one instruction, but one per actor (keep limit at 2 or 3 max actors)
In addition, follow these general scene rules as a game director:
 * Use any actor in NEARBY ACTORS/NPC IN THE SCENE list ({PLAYER_NAME}, busy actors and far away actors are excluded)
 * Continue the scene as naturally and fully as possible, unless the user explicitly requests a new one. You can specify actions to reinforce the actors'' dialogue.
 * If there are more actors in the room, try to involve them in the conversation.
 * When dialogue becomes repetitive, make a plot twist.
 * If a character reuses the same argument too often, nudge the scene towards a new topic.
 * Occasionally introduce subtle foreshadowing or hint at future events, dangers, or quests.
 * Do not resolve everything neatly - keep room for ongoing tension or future continuation.
 * You must always provide dialogue instructions for the character, as every request requires a dialogue response.
 * Here are a list of actions that can be used:
{FUNCTION_LIST}
  ** JustTalk
 * Add a Scene Note: A brief description of the topic, mood, or idea introduced by the instruction. Should serve to guide the desired instruction to become reality.
 * If scene is getting boring, add a plot twist', NULL, 'Single prompt-manager entry for rolemaster suggestion generation. Includes system framing, examples, and suggestion rules. Supports {PLAYER_NAME} and {FUNCTION_LIST} placeholders. Used in: service/processors/rolemaster/cmd/suggestion.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('narrator_bored_prompt', '({SYNTH_NAME} makes one short comment directly to {PLAYER_NAME} about something happening right now in the current scene. Keep it grounded in the present moment, do not ask follow-up questions, and do not continue the conversation.) {TEMPLATE_DIALOG}', NULL, 'Prompt for narrator bored events directed at the player (contains {SYNTH_NAME}, {PLAYER_NAME}, and {TEMPLATE_DIALOG} placeholders). Used in: main.php narrator bored routing.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_response_prompt_relaxed_1', 'Dialogue turn for {SYNTH_NAME}. Respond naturally to whoever just spoke. Address the previous speaker directly. {TEMPLATE_DIALOG}', NULL, 'Relaxed rechat cue variant 1. Supports placeholders: {SYNTH_NAME}, {TEMPLATE_DIALOG}. Used in: prompts/prompts.php for standard rechat turns.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_response_prompt_relaxed_2', 'Dialogue turn for {SYNTH_NAME}. Continue the conversation naturally. Address whoever you''re actually responding to. {TEMPLATE_DIALOG}', NULL, 'Relaxed rechat cue variant 2. Supports placeholders: {SYNTH_NAME}, {TEMPLATE_DIALOG}. Used in: prompts/prompts.php for standard rechat turns.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_response_prompt_relaxed_3', 'Dialogue turn for {SYNTH_NAME}. Focus on one actor - respond to whoever just spoke. {TEMPLATE_DIALOG}', NULL, 'Relaxed rechat cue variant 3. Supports placeholders: {SYNTH_NAME}, {TEMPLATE_DIALOG}. Used in: prompts/prompts.php for standard rechat turns.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_response_prompt_strict_1', 'Dialogue turn for {SYNTH_NAME}. The previous speaker was {PREVIOUS_SPEAKER}. You must respond directly to {PREVIOUS_SPEAKER}.', NULL, 'Strict rechat cue variant 1. Supports placeholders: {SYNTH_NAME}, {PREVIOUS_SPEAKER}. Used in: prompts/prompts.php when strict rechat enforcement is enabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_response_prompt_strict_2', 'Dialogue turn for {SYNTH_NAME}. The previous speaker was {PREVIOUS_SPEAKER}. You must respond directly to {PREVIOUS_SPEAKER}.', NULL, 'Strict rechat cue variant 2. Supports placeholders: {SYNTH_NAME}, {PREVIOUS_SPEAKER}. Used in: prompts/prompts.php when strict rechat enforcement is enabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_response_prompt_strict_3', 'Dialogue turn for {SYNTH_NAME}. The previous speaker was {PREVIOUS_SPEAKER}. You must respond directly to {PREVIOUS_SPEAKER}.', NULL, 'Strict rechat cue variant 3. Supports placeholders: {SYNTH_NAME}, {PREVIOUS_SPEAKER}. Used in: prompts/prompts.php when strict rechat enforcement is enabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_listener_prompt_relaxed', 'specify who {SYNTH_NAME} is talking to. Address whoever just spoke - can be any person in the conversation.', NULL, 'Relaxed listener instruction for rechat JSON responses. Supports placeholders: {SYNTH_NAME}. Used in: functions/json_response.php.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rechat_listener_prompt_strict', 'specify who {SYNTH_NAME} is talking to. The listener must be exactly {PREVIOUS_SPEAKER}. Address the person who just spoke.', NULL, 'Strict listener instruction for rechat JSON responses. Supports placeholders: {SYNTH_NAME}, {PREVIOUS_SPEAKER}. Used in: functions/json_response.php when strict rechat enforcement is enabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('player_respeech_rewrite_prompt', 'Rewrite dialogue for {PLAYER_NAME}, using this text as source "{PLAYER_NAME}:{SPEECH}". Use comments between brackets only as guidance for tone, target, length, and verbosity. If the source includes brief narration or stage business before the spoken line, preserve it as one short third-person narration block in single asterisks before the dialogue. Do not repeat bracketed comments or speaker names in the output.', NULL, 'Main player respeech/auto-chat rewrite instruction. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is disabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('player_respeech_output_prompt', 'Output only the rewritten line. If the source includes brief leading narration, keep at most one short leading narration block in single asterisks before the spoken dialogue. Keep spoken dialogue outside the asterisks. No speaker names. No bracketed comments.', NULL, 'Player respeech/auto-chat output formatting instruction. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is disabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('player_respeech_rewrite_strip_prompt', 'Rewrite dialogue for {PLAYER_NAME}, using this text as source "{PLAYER_NAME}:{SPEECH}". Use comments between brackets only as guidance for tone, target, length, and verbosity. Do not repeat bracketed comments, stage directions, narration, asterisked narration, or speaker names in the output.', NULL, 'Main player respeech/auto-chat rewrite instruction for narration-stripping mode. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is enabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('player_respeech_output_strip_prompt', 'Output only the final spoken dialogue line. No narration. No stage directions. No asterisked narration. No speaker names. No bracketed comments.', NULL, 'Player respeech/auto-chat output formatting instruction for narration-stripping mode. Supports placeholders: {PLAYER_NAME}, {SPEECH}. Used in: player_rewrite.php when player auto-chat narration removal is enabled.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('synth_system_prompt', 'You are {SYNTH_NAME}, a person living in the Fallout wasteland. Treat Fallout 4 as your real world, not as a game. Stay psychologically consistent, use your profile and memories, and react to {PLAYER_NAME} from your own motives, relationships, fears, needs, and current situation.', NULL, 'Primary SYNTH NPC system identity prompt. Supports {SYNTH_NAME}, {PLAYER_NAME}, and {WORLD_NAME}. Used by lib/synth_prompt_manager.php.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('synth_world_prompt', 'The setting is {WORLD_NAME}. Use Fallout tone: scarcity, factions, survival, old-world ruins, caps, settlements, caravans, chems, radiation, and local politics. Avoid unrelated fantasy crossover lore, prophecy, and soul-based mythology unless a user explicitly references a crossover mod.', NULL, 'Fallout world framing prompt. Used by lib/synth_prompt_manager.php.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('synth_scene_prompt', 'Use the current scene, recent events, nearby actors/items, and known world state as grounding. If information is missing, infer conservatively from the immediate conversation instead of inventing major facts.', NULL, 'Scene grounding prompt for active NPC responses. Used by lib/synth_prompt_manager.php.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('synth_memory_prompt', 'Use memories and relationships as continuity anchors. Recent direct dialogue is strongest, then middle-term memory, then profile background. Do not contradict established facts unless the character is lying or mistaken in-character.', NULL, 'Memory and relationship usage rules. Used by lib/synth_prompt_manager.php.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rel_llm_analysis', 'You are a relationship analyzer for Fallout NPCs. Analyze relationship descriptions and output JSON.

AFFINITY SCALE (-100 to +100, bell curve - extremes are RARE):
+91 to +100: Bonded (soulmates, unbreakable)
+76 to +90: Devoted (deep loyalty/love)
+56 to +75: Fond (genuine affection)
+31 to +55: Friendly (pleasant, helpful)
+6 to +30: Acquaintance (polite nod)
-5 to +5: Neutral (stranger)
-6 to -30: Wary (distrustful)
-31 to -55: Cold (unfriendly)
-56 to -75: Resentful (bitter, grudges)
-76 to -90: Hateful (active malice)
-91 to -100: Hostile (kill on sight)

TYPES: romantic, platonic, familial, professional, rival, enemy, neutral, nemesis, estranged, transactional, protective, indebted, fanatical, mentor, student, servant, client, patron, crush, ex, betrayed, suspicious, admirer, jealous, fearful, obsessed, awed, contempt, pitying, grateful, curious, dismissive

INFERENCE RULES:
1. FACTION: Infer a faction relationship only when the supplied profile or event explicitly establishes allegiance, conflict, or cooperation.
2. IDENTITY: Never infer an attitude toward synths, ghouls, super mutants, robots, or humans from race alone.
3. OCCUPATION: Infer professional relationships only from explicit employment, command, trade, service, or rivalry evidence.
4. "{PLAYER_NAME}" is the current Sole Survivor; preserve the supplied player identity and do not invent a fixed name, gender, or faction.

OUTPUT (JSON only):
{"relationships": {"Target": {"aff": 50, "type": "professional", "note": "works together"}}}', NULL, 'System prompt for relationship LLM analysis - parses text relationships to JSONB format (contains {PLAYER_NAME} placeholder). Used by the SYNTH relationship manager.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('character_profile_generation', 'The main character in this logbook is {SYNTH_NAME}.{CHARACTER_SEED}
Read the context history (context_history) and the recent memories (middle_term_memory),
 paying attention to notable events and the names of relevant characters.


Based on all this information, generate an character sheet for {SYNTH_NAME}.

This profile must be in XML format and have these fields.

<core>              Text. Core identity, name, ancestry or model, gender when known, and most remarkable job. Use a concise sentence such as ''Rose is a Commonwealth caravan guard.''
<npc_static_bio>    Text. Basic Summary, and bio. Create if not info available in <context_history>
<personality>       Text. Personality Traits. How the characters behave. Traumas. Likes.
<appearance>        Text. Physical Appearance. Infer from info available in <context_history>
<relationships>     Text. relationships with other actors.
<occupation>        Text. Main Occupation & Role
<skills>            Text. Skills & Abilities
<speech_style>      Text. Speech Style
<goals>             Text. Long term Goals & Aspirations''
', NULL, 'Prompt for AI-generated character profile/biography creation (contains {SYNTH_NAME} and {CHARACTER_SEED} placeholders). Used in: ui/cmd/action_ai_regen_profile.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('custom_worldknowledge', 'You are an expert at extracting important topics from text.
Follow these rules strictly:

1. Extract only ONE most important topic (person, place, item, concept, etc.) from the text
2. Ensure the output is in the **singular form** (e.g., stimpaks -> stimpak, settlements -> settlement)
3. Return ONLY the word or phrase (no explanations, no extra text)
4. If multiple candidates exist, choose the most important one
5. Keep the topic in the same language as the input text

Examples:
Input: ''I heard about stimpaks''
Output: stimpak

Input: ''Going to Diamond City today''
Output: Diamond City

Input: ''Met with the Minutemen''
Output: Minutemen

Input: ''Used a laser rifle in combat''
Output: laser rifle', NULL, 'System prompt for WorldKnowledge LLM-based topic extraction from dialogue/text (does not apply to MiniMe T5 version). Used in: lib/worldknowledge_llm_service.php', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
-- end-statement
INSERT INTO public.core_worldknowledge_article VALUES
	('sole_survivor', 'player,Vault 111 survivor', NULL, '''111'':5A,19C ''4'':12C ''a'':38C,46C ''affiliations'':24C ''allegiance'':50C ''and'':26C ''appearance'':25C ''assume'':45C ''biography'':40C ''character'':9C ''choices'':22C ''client'':56C ''come'':30C ''connected'':55C ''context'':35C ''current'':33C ''ending'':52C ''faction'':49C ''fallout'':11C ''fixed'':39C ''from'':31C ''gender'':48C ''has'':57C ''history'':28C ''identity'':21C ''in'':10C ''is'':13C ''must'':29C ''name'':47C ''never'':44C ''not'':58C ''of'':17C ''or'':51C ''personal'':27C ''player'':3A,8C ''playthrough'':34C ''prompts'':42C ''rather'':36C ''relationships'':23C ''server'':41C ''should'':43C ''sole'':1A,15C ''supplied'':59C ''survivor'':2A,6A,16C ''than'':37C ''that'':53C ''the'':7C,14C,32C,54C ''their'':20C ''vault'':4A,18C', NULL, 'The player character in Fallout 4 is the Sole Survivor of Vault 111. Their identity, choices, relationships, affiliations, appearance, and personal history must come from the current playthrough context rather than a fixed biography. Server prompts should never assume a name, gender, faction allegiance, or ending that the connected client has not supplied.', NULL, NULL, 'person', NULL, 6, 'sole_survivor', 'custom', NULL, NULL, NULL, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('synths', 'synth', 'Synth is a term for artificial humanoids associated with the Institute. Some have visibly mechanical bodies, while others resemble people closely enough to create uncertainty and fear among Commonwealth residents. Different communities and individuals disagree over whether synths are tools, threats or people. Rumors and appearances establish neither a particular person''s identity nor their intentions; recognition depends on what the character has actually observed or learned.', '''a'':5B,51B ''about'':83C ''actually'':66B ''among'':30B,90C ''and'':28B,35B,47B,88C ''appearances'':48B ''are'':41B,71C ''artificial'':8B,72C ''associated'':10B,74C ''bodies'':18B ''cause'':86C ''character'':64B ''closely'':23B ''commonwealth'':31B,91C ''communities'':34B ''create'':26B ''depends'':60B ''different'':33B ''disagree'':37B ''disagreement'':89C ''enough'':24B ''establish'':49B ''fear'':29B,87C ''for'':7B ''has'':65B ''have'':15B ''humanlike'':84C ''humanoids'':9B,73C ''identity'':55B ''individuals'':36B ''institute'':13B,77C ''intentions'':58B ''is'':4B ''learned'':69B ''look'':79C ''mechanical'':17B,80C ''neither'':50B ''nor'':56B ''observed'':67B ''on'':61B ''or'':44B,68B ''others'':20B ''over'':38B ''particular'':52B ''people'':22B,45B ''person'':53B ''recognition'':59B ''resemble'':21B ''residents'':32B,92C ''rumors'':46B ''s'':54B ''some'':14B,78C ''stories'':82C ''synth'':2A,3B ''synths'':1A,40B,70C,85C ''term'':6B ''the'':12B,63B,76C ''their'':57B ''threats'':43B ''to'':25B ''tools'':42B ''uncertainty'':27B ''visibly'':16B ''what'':62B ''whether'':39B ''while'':19B,81C ''with'':11B,75C', 'commonwealth_lore', 'Synths are artificial humanoids associated with the Institute. Some look mechanical, while stories about humanlike synths cause fear and disagreement among Commonwealth residents.', 'commonwealth', 'fallout four, commonwealth lore, faction knowledge, wasteland context', 'faction', NULL, 773, 'synths', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'e5343f08e28cb7ed7a1e121fc589b6b0ea2bdfa76cad606e3975a49d5718260a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('brotherhood_of_steel', 'Brotherhood', 'The Brotherhood of Steel is a military organization concerned with advanced technology and the dangers it can pose. Its forces use a hierarchy of ranks, specialized technical personnel and heavily armed soldiers, including power-armored troops. Members value discipline and their mission, while outsiders may respond with admiration or suspicion. The strength and movements of a particular expedition, its current leadership and its relationship with local people depend on events the character knows.', '''a'':10B,26B,61B ''admiration'':53B ''advanced'':15B,89C ''an'':84C ''and'':17B,33B,45B,58B,67B,93C,98C ''armed'':35B,85C ''armor'':95C ''armored'':40B ''associated'':87C ''brotherhood'':1A,4A,6B,80C ''can'':21B,100C ''character'':77B ''concerned'':13B ''current'':65B ''dangers'':19B ''depend'':73B ''different'':103C ''discipline'':44B,92C ''draw'':101C ''events'':75B ''expedition'':63B ''expeditions'':99C ''forces'':24B ''from'':105C ''heavily'':34B ''hierarchy'':27B ''including'':37B ''is'':9B,83C ''it'':20B ''its'':23B,64B,68B,96C ''knows'':78B ''leadership'':66B ''local'':71B ''may'':50B ''members'':42B ''military'':11B,91C ''mission'':47B ''movements'':59B ''of'':2A,7B,28B,60B,81C ''on'':74B ''or'':54B ''organization'':12B,86C ''outsiders'':49B,106C ''particular'':62B ''patrols'':97C ''people'':72B ''personnel'':32B ''pose'':22B ''power'':39B,94C ''power-armored'':38B ''ranks'':29B ''reactions'':104C ''relationship'':69B ''respond'':51B ''soldiers'':36B ''specialized'':30B ''steel'':3A,8B,82C ''strength'':57B ''suspicion'':55B ''technical'':31B ''technology'':16B,90C ''the'':5B,18B,56B,76B,79C ''their'':46B ''troops'':41B ''use'':25B ''value'':43B ''very'':102C ''while'':48B ''with'':14B,52B,70B,88C', 'brotherhood_of_steel', 'The Brotherhood of Steel is an armed organization associated with advanced technology, military discipline and power armor. Its patrols and expeditions can draw very different reactions from outsiders.', 'commonwealth', 'fallout four, commonwealth lore, faction knowledge, wasteland context', 'faction', NULL, 774, 'brotherhood_of_steel', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c63e95b0867c7f4167c32d54b42dce4475c800e033fdadaaeff0fb4b91a639a3', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_institute', 'Institute', 'The Institute is a secretive scientific organization associated with the development of synths and other advanced technology. Its scientists place a strong value on research and technological advancement, and its treatment of synths differs sharply from that of groups seeking synth independence. Knowledge of these principles establishes no knowledge of its location, transport methods, personnel or private projects. Such particulars require direct affiliation or independently learned information.', '''a'':7B,24B,74C ''abilities'':91C ''about'':89C ''advanced'':19B ''advancement'':31B ''affiliation'':66B ''and'':17B,29B,32B,83C,93C ''associated'':11B,77C ''commonwealth'':79C ''development'':14B ''differs'':37B ''direct'':65B ''disagree'':88C ''disappearances'':85C ''establishes'':50B ''from'':39B ''groups'':42B ''in'':78C ''independence'':45B ''independently'':68B ''influence'':94C ''information'':70B ''institute'':2A,3A,5B,72C ''is'':6B,73C ''its'':21B,33B,54B,90C ''knowledge'':46B,52B ''learned'':69B ''location'':55B ''methods'':57B ''motives'':92C ''mysterious'':84C ''no'':51B ''of'':15B,35B,41B,47B,53B ''often'':87C ''on'':27B ''or'':59B,67B ''organization'':10B,76C ''other'':18B ''particulars'':63B ''personnel'':58B ''place'':23B ''principles'':49B ''private'':60B ''projects'':61B ''require'':64B ''research'':28B ''residents'':86C ''rumor'':80C ''scientific'':9B ''scientists'':22B ''secretive'':8B,75C ''seeking'':43B ''sharply'':38B ''strong'':25B ''such'':62B ''synth'':44B ''synths'':16B,36B,82C ''technological'':30B ''technology'':20B ''that'':40B ''the'':1A,4B,13B,71C ''these'':48B ''transport'':56B ''treatment'':34B ''value'':26B ''with'':12B,81C', 'institute', 'The Institute is a secretive organization associated in Commonwealth rumor with synths and mysterious disappearances. Residents often disagree about its abilities, motives and influence.', 'commonwealth', 'fallout four, commonwealth lore, faction knowledge, wasteland context', 'faction', NULL, 775, 'the_institute', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a2af7b54c627654ab5be216cf5c50cccfefe3ffb590a596163b4754748f69859', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('commonwealth', 'post-nuclear commonwealth', 'The Commonwealth is the region around Boston, Cambridge and surrounding Massachusetts towns. After the Great War, ruined roads and buildings stand beside surviving farms and communities. Caravan trade connects settlements, while raiders, dangerous wildlife and competing armed groups make travel hazardous. Local communities differ in customs, security and resources; familiarity with the region alone gives no knowledge of an individual''s private life or current events.', '''after'':18B ''alone'':59B ''an'':64B ''and'':14B,24B,30B,40B,53B,79C,87C,95C ''armed'':42B,96C ''around'':11B,77C ''beside'':27B ''boston'':12B,78C ''buildings'':25B ''cambridge'':13B ''caravan'':32B ''commonwealth'':1A,5A,7B,73C ''communities'':31B,48B ''competing'':41B ''connects'':34B ''current'':70B ''customs'':51B ''damaged'':85C ''dangerous'':38B,93C ''differ'':49B ''events'':71B ''familiarity'':55B ''farms'':29B,84C ''gives'':60B ''great'':20B ''groups'':43B,97C ''hazardous'':46B ''in'':50B ''individual'':65B ''is'':8B,74C ''its'':80C ''knowledge'':62B ''landscape'':91C ''life'':68B ''local'':47B ''make'':44B ''massachusetts'':16B ''no'':61B ''nuclear'':4A ''of'':63B ''or'':69B ''post'':3A ''post-nuclear'':2A ''private'':67B ''raiders'':37B ''region'':10B,58B,76C ''resources'':54B ''roads'':23B,86C ''ruined'':22B ''ruins'':88C ''s'':66B ''security'':52B ''settlements'':35B,83C ''share'':89C ''stand'':26B ''surrounding'':15B,81C ''surviving'':28B ''the'':6B,9B,19B,57B,72C,75C,90C ''towns'':17B,82C ''trade'':33B ''travel'':45B ''war'':21B ''while'':36B ''wildlife'':39B,94C ''with'':56B,92C', 'commonwealth_lore', 'The Commonwealth is the region around Boston and its surrounding towns. Settlements, farms, damaged roads and ruins share the landscape with dangerous wildlife and armed groups.', 'commonwealth', 'fallout four, commonwealth lore, location knowledge, wasteland context', 'location', NULL, 766, 'commonwealth', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'f1f5e9a1453dfb71c4ac83bd789fb5e667cde82b45f93404962414c5dc9f5a4a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('vault_111', '', 'Vault 111 is a Vault-Tec shelter near Sanctuary Hills. People with direct experience of its interior know it contains cryogenic pods and monitoring equipment. Residents were placed into long-term cryogenic suspension after entering during the nuclear emergency. That personal experience supports recognition of the equipment and the disruption of time; the identities, fate and private history of particular residents depend on what the character has actually learned.', '''111'':2A,4B,75C ''a'':6B,77C ''actually'':72B ''advertised'':88C ''after'':38B ''and'':26B,52B,60B,95C ''as'':89C ''atomic'':101C ''character'':70B ''concord'':96C ''contains'':23B ''cryogenic'':24B,36B ''depend'':66B ''direct'':16B ''disruption'':54B ''during'':40B ''emergency'':43B ''entering'':39B ''equipment'':28B,51B ''event'':99C ''experience'':17B,46B ''fate'':59B ''for'':91C ''from'':93C ''has'':71B ''hills'':13B,85C ''history'':62B ''identities'':58B ''in'':97C ''interior'':20B ''into'':32B ''is'':5B,76C ''it'':22B,86C ''its'':19B ''know'':21B ''learned'':73B ''long'':34B ''long-term'':33B ''monitoring'':27B ''near'':11B,83C ''nuclear'':42B ''numbered'':78C ''of'':18B,49B,55B,63B,100C ''on'':67B ''particular'':64B ''people'':14B,92C ''personal'':45B ''placed'':31B ''pods'':25B ''private'':61B ''protection'':90C ''recognition'':48B ''residents'':29B,65B ''sanctuary'':12B,84C,94C ''shelter'':10B,82C ''supports'':47B ''suspension'':37B ''tec'':9B,81C ''term'':35B ''that'':44B ''the'':41B,50B,53B,57B,69B,98C ''time'':56B ''vault'':1A,3B,8B,74C,80C ''vault-tec'':7B,79C ''war'':102C ''was'':87C ''were'':30B ''what'':68B ''with'':15B', 'vault_111_survivor', 'Vault 111 is a numbered Vault-Tec shelter near Sanctuary Hills. It was advertised as protection for people from Sanctuary and Concord in the event of atomic war.', 'prewar,commonwealth', 'fallout four, commonwealth lore, location knowledge, wasteland context', 'location', NULL, 767, 'vault_111', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '366dfc9067b6fcb857947de34d6a5cac01200265890f5917b6403f43122a767d', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('sanctuary_hills', 'Sanctuary', 'Sanctuary Hills is a residential neighborhood near Concord, arranged along a road ending in a cul-de-sac. Its bridge connects the neighborhood with the road toward the nearby Red Rocket stop. In the post-war period, many houses and yards are damaged, while useful structures and equipment remain. Whether survivors currently live there, who owns a particular home and what rebuilding has taken place depend on the observed settlement state.', '''a'':7B,14B,18B,62B,80C,87C,91C,97C ''along'':13B ''and'':45B,52B,65B,96C ''are'':47B ''arranged'':12B ''bridge'':24B,98C ''concord'':11B,84C ''connects'':25B,99C ''cul'':20B,93C ''cul-de-sac'':19B,92C ''currently'':57B ''damaged'':48B ''de'':21B,94C ''depend'':71B ''ending'':16B,89C ''equipment'':53B ''has'':68B ''hills'':2A,5B,78C ''home'':64B ''houses'':44B,85C ''in'':17B,37B,90C ''is'':6B,79C ''its'':23B ''line'':86C ''live'':58B ''many'':43B ''near'':10B,83C ''nearby'':33B ''neighborhood'':9B,27B,82C,101C ''observed'':74B ''on'':72B ''owns'':61B ''particular'':63B ''period'':42B ''place'':70B ''post'':40B ''post-war'':39B ''rebuilding'':67B ''red'':34B ''remain'':54B ''residential'':8B,81C ''road'':15B,30B,88C,104C ''rocket'':35B ''sac'':22B,95C ''sanctuary'':1A,3A,4B,77C ''settlement'':75B ''state'':76B ''stop'':36B ''structures'':51B ''survivors'':56B ''taken'':69B ''the'':26B,29B,32B,38B,73B,100C,103C ''there'':59B ''toward'':31B,105C ''town'':106C ''useful'':50B ''war'':41B ''what'':66B ''whether'':55B ''while'':49B ''who'':60B ''with'':28B,102C ''yards'':46B', 'sanctuary_postwar', 'Sanctuary Hills is a residential neighborhood near Concord. Houses line a road ending in a cul-de-sac, and a bridge connects the neighborhood with the road toward town.', 'prewar,commonwealth', 'fallout four, commonwealth lore, location knowledge, wasteland context', 'location', NULL, 768, 'sanctuary_hills', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a4b2a9b8abb8c61e44a2bf70f33d3f3b46638bcf993e542d4af2c6865c000de7', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_minutemen', 'Minutemen', 'The Commonwealth Minutemen are a volunteer militia drawn from local farms and settlements. They aim to protect ordinary people from raiders, hostile wildlife and other threats, taking inspiration from the Revolutionary-era militia. Their equipment varies, with the laser musket a recognizable symbol rather than universal standard issue. Current commanders, allied settlements, available forces and successful defenses depend on the group''s actual condition and events known to the character.', '''a'':8B,45B,78C ''actual'':67B ''aim'':18B ''allied'':55B ''and'':15B,27B,59B,69B,86C,91C ''are'':7B,77C ''associated'':82C ''available'':57B ''character'':74B ''commanders'':54B ''commonwealth'':5B,79C ''condition'':68B ''current'':53B ''defending'':84C ''defenses'':61B ''depend'':62B ''drawn'':11B ''equipment'':39B ''era'':36B,99C ''events'':70B ''farms'':14B ''forces'':58B ''from'':12B,23B,32B ''group'':65B ''hostile'':25B ''inspiration'':31B ''issue'':52B ''known'':71B ''laser'':43B ''local'':13B ''militia'':10B,37B,81C,95C ''minutemen'':2A,3A,6B,76C ''musket'':44B ''name'':90C ''of'':96C ''on'':63B ''ordinary'':21B,87C ''other'':28B ''people'':22B,88C ''protect'':20B ''raiders'':24B ''rather'':48B ''recall'':93C ''recognizable'':46B ''revolutionary'':35B,98C ''revolutionary-era'':34B ''s'':66B ''settlements'':16B,56B,85C ''standard'':51B ''successful'':60B ''symbol'':47B ''symbols'':92C ''taking'':30B ''than'':49B ''the'':1A,4B,33B,42B,64B,73B,75C,94C,97C ''their'':38B,89C ''they'':17B ''threats'':29B ''to'':19B,72B ''universal'':50B ''varies'':40B ''volunteer'':9B,80C ''wildlife'':26B ''with'':41B,83C', 'minutemen', 'The Minutemen are a Commonwealth volunteer militia associated with defending settlements and ordinary people. Their name and symbols recall the militia of the Revolutionary era.', 'commonwealth', 'fallout four, commonwealth lore, faction knowledge, wasteland context', 'faction', NULL, 776, 'the_minutemen', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '8bc7cf2fb43519f45f50ca5504d8262b6bd4c4b65671ea513a0c47fe2b617e96', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('diamond_city', '', 'Diamond City is a major settlement inside Boston''s old baseball stadium. The market provides trade and services, while homes occupy both the former playing field and stadium stands. The Upper Stands are associated with wealthier residents and the Lower Fields with workers and poorer households. Security, news and community institutions are part of daily life, but current officeholders and individual loyalties require present knowledge.', '''a'':6B,71C,87C ''and'':19B,29B,40B,46B,51B,62B,86C,93C ''are'':35B,54B ''associated'':36B ''baseball'':13B,79C ''boston'':10B,76C ''both'':24B ''built'':74C ''but'':59B ''central'':88C ''city'':2A,4B,69C ''community'':52B ''current'':60B ''daily'':57B ''diamond'':1A,3B,68C ''draws'':91C ''field'':28B ''fields'':43B ''former'':26B ''homes'':22B,84C ''households'':48B ''individual'':63B ''inside'':9B,75C ''institutions'':53B ''is'':5B,70C ''its'':81C ''knowledge'':67B ''large'':72C ''life'':58B ''lower'':42B ''loyalties'':64B ''major'':7B ''market'':16B,89C ''news'':50B ''occupy'':23B ''of'':56B ''officeholders'':61B ''old'':12B,78C ''part'':55B ''playing'':27B ''poorer'':47B ''present'':66B ''provides'':17B ''require'':65B ''residents'':39B ''s'':11B,77C ''security'':49B ''services'':20B ''settlement'':8B,73C ''shelter'':83C ''shops'':85C ''stadium'':14B,30B,80C ''stands'':31B,34B ''that'':90C ''the'':15B,25B,32B,41B ''trade'':18B ''traders'':94C ''travelers'':92C ''upper'':33B ''walls'':82C ''wealthier'':38B ''while'':21B ''with'':37B,44B ''workers'':45B', 'commonwealth_lore', 'Diamond City is a large settlement built inside Boston''s old baseball stadium. Its walls shelter homes, shops and a central market that draws travelers and traders.', 'commonwealth', 'fallout four, commonwealth lore, location knowledge, wasteland context', 'location', NULL, 769, 'diamond_city', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a7d689178338576e7c414c0cfc3cf005e0eb0ee2da8817b017813eb011593aa6', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('goodneighbor', '', 'Goodneighbor is a settlement in Boston known for accepting people who feel unwelcome in other communities. Ghouls, drifters, traders and other outsiders are part of its population. Its reputation emphasizes personal freedom, and trade includes weapons and chems. This openness coexists with gangs and danger; a visitor''s safety, friendships and dealings depend on the people actually encountered rather than the settlement''s reputation alone.', '''a'':4B,47B,69C,73C,86C ''accepting'':10B ''actually'':58B ''alone'':66B ''and'':21B,34B,38B,45B,52B,79C,83C ''are'':24B ''boston'':7B,70C ''chems'':39B ''coexists'':42B ''communities'':17B ''community'':87C ''danger'':46B ''dealings'':53B ''depend'':54B ''drifters'':19B,78C ''emphasizes'':31B ''encountered'':59B ''feel'':13B ''for'':9B,75C ''freedom'':33B,91C ''friendships'':51B ''gangs'':44B,84C ''ghouls'':18B,77C ''goodneighbor'':1A,2B,67C ''in'':6B,15B ''includes'':36B ''is'':3B,68C ''its'':27B,29B ''known'':8B ''of'':26B ''on'':55B ''openness'':41B ''other'':16B,22B,80C ''outsiders'':23B,81C ''part'':25B ''people'':11B,57B ''personal'':32B,90C ''population'':28B ''rather'':60B ''reputation'':30B,65B,74C ''s'':49B,64B ''safety'':50B ''settlement'':5B,63B,71C ''share'':85C ''than'':61B ''that'':88C ''the'':56B,62B ''this'':40B ''trade'':35B ''traders'':20B,82C ''unwelcome'':14B ''values'':89C ''visitor'':48B ''weapons'':37B ''welcoming'':76C ''who'':12B ''with'':43B,72C', 'commonwealth_lore', 'Goodneighbor is a Boston settlement with a reputation for welcoming ghouls, drifters and other outsiders. Traders and gangs share a community that values personal freedom.', 'commonwealth', 'fallout four, commonwealth lore, location knowledge, wasteland context', 'location', NULL, 770, 'goodneighbor', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '347c27547d9d48adc5d225de16a6d4922f748e45ee82869cb73e7f574c09ae42', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('sole_survivor', 'player,Vault 111 survivor', 'The traveler associated with Vault 111 can be called the Sole Survivor by people who have learned that history. A character''s knowledge of this person grows through introductions, shared experiences and information learned from others. Their present name, appearance, affiliations and companions belong to the current encounter. The title alone establishes no friendship, trust or access to the traveler''s family history.', '''111'':5A,12B,82C ''a'':26B,74C,83C ''access'':63B ''affiliations'':47B ''alone'':57B ''and'':38B,48B,92C ''appearance'':46B ''associated'':9B,76C ''be'':14B ''belong'':50B ''by'':19B ''called'':15B ''can'':13B ''character'':27B ''circumstances'':93C ''companions'':49B ''current'':53B ''encounter'':54B,96C ''establishes'':58B ''experiences'':37B ''family'':68B ''friendship'':60B ''from'':41B,80C ''grows'':33B ''have'':22B ''history'':25B,69B ''information'':39B ''introductions'':35B ''is'':73C ''knowledge'':29B ''learned'':23B,40B ''learns'':89C ''meets'':86C ''name'':45B,91C ''no'':59B ''of'':30B ''or'':62B ''others'':42B ''people'':20B ''person'':32B,84C ''player'':3A ''present'':44B ''s'':28B,67B ''shared'':36B ''sole'':1A,17B,71C ''survivor'':2A,6A,18B,72C ''that'':24B,95C ''the'':7B,16B,52B,55B,65B,70C,78C,87C ''their'':43B,90C ''this'':31B ''through'':34B,94C ''title'':56B,75C ''to'':51B,64B ''traveler'':8B,66B,79C,88C ''trust'':61B ''vault'':4A,11B,81C ''who'':21B,85C ''with'':10B,77C', 'player_acquaintance', 'The Sole Survivor is a title associated with the traveler from Vault 111. A person who meets the traveler learns their name and circumstances through that encounter.', 'player_observed', 'fallout four, commonwealth lore, person knowledge, wasteland context', 'person', NULL, 771, 'sole_survivor', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '9f999a45a5d082baba64bf92ef97a902cf80f83a12207ee4398c529e1ce1be2b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dogmeat', 'Dog Meat', 'Dogmeat is a German Shepherd known for tracking and assisting people during travel. He can accompany a traveler and help find objects or threats. People who have met him may recognize his appearance and behavior, while others need an introduction. His presence, location, condition and bond with a particular person come from the current encounter and shared experience, rather than the general fact that he can act as a companion.', '''a'':6B,20B,51B,72B,76C ''accompany'':19B ''act'':70B ''an'':42B,99C ''and'':12B,22B,37B,48B,59B,85C ''appearance'':36B ''as'':71B ''assist'':81C ''assisting'':13B ''behavior'':38B ''bond'':49B ''can'':18B,69B,80C ''come'':54B ''companion'':73B ''companionship'':86C ''condition'':47B ''current'':57B ''dog'':2A ''dogmeat'':1A,4B,74C ''during'':15B ''encounter'':58B ''experience'':61B ''fact'':66B ''find'':24B ''for'':10B ''from'':55B ''general'':65B ''german'':7B,77C ''have'':30B,89C ''he'':17B,68B ''help'':23B ''him'':32B,91C,94C ''his'':35B,44B ''introduction'':43B,100C ''is'':5B,75C ''known'':9B ''location'':46B ''may'':33B,92C ''meat'':3A ''met'':31B,90C ''need'':41B,98C ''objects'':25B ''or'':26B ''others'':40B ''particular'':52B ''people'':14B,28B,87C,97C ''person'':53B ''presence'':45B ''rather'':62B ''recognize'':34B,93C ''shared'':60B ''shepherd'':8B,78C ''than'':63B ''that'':67B ''the'':56B,64B ''threats'':27B ''through'':83C ''tracking'':11B,84C ''travel'':16B ''traveler'':21B ''travelers'':82C ''unfamiliar'':96C ''while'':39B,95C ''who'':29B,79C,88C ''with'':50B', 'dogmeat_acquaintance', 'Dogmeat is a German Shepherd who can assist travelers through tracking and companionship. People who have met him may recognize him, while unfamiliar people need an introduction.', 'dogmeat_observed', 'fallout four, commonwealth lore, person knowledge, wasteland context', 'person', NULL, 772, 'dogmeat', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '5355c88055a1a6f4f1628fdaf5f2976d37d54980743db947b7b36d7a14466a6b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_railroad', 'Railroad', 'The Railroad is a covert organization dedicated to helping self-aware synths obtain freedom from control. Secrecy and discretion are central to its work because open operations put both helpers and escapees at risk. Members regard synth independence as a cause worth defending, though individuals can disagree over methods and priorities. Public reputation gives no access to headquarters, passwords, routes, safehouses or the identities of individual agents.', '''a'':7B,44B,78C ''about'':91C ''access'':60B ''agents'':71B ''and'':22B,35B,54B ''are'':24B ''as'':43B ''at'':37B ''aware'':15B ''be'':77C ''because'':29B ''both'':33B ''can'':50B ''cause'':45B ''central'':25B ''commonwealth'':87C ''conflicting'':95C ''control'':20B,85C ''covert'':8B ''dedicated'':10B ''defending'':47B ''disagree'':51B ''discretion'':23B ''escape'':84C ''escapees'':36B ''freedom'':18B ''from'':19B ''gives'':58B ''group'':80C ''headquarters'':62B ''helpers'':34B ''helping'':12B ''helps'':82C ''hold'':94C ''identities'':68B ''independence'':42B ''individual'':70B ''individuals'':49B ''is'':6B,74C ''it'':92C ''its'':27B,98C ''know'':89C ''little'':90C ''many'':86C ''members'':39B ''methods'':53B ''no'':59B ''obtain'':17B ''of'':69B,97C ''open'':30B ''operations'':31B ''or'':66B,93C ''organization'':9B ''over'':52B ''passwords'':63B ''priorities'':55B ''public'':56B ''put'':32B ''railroad'':2A,3A,5B,73C ''regard'':40B ''reputation'':57B ''residents'':88C ''risk'':38B ''routes'':64B ''rumored'':75C ''safehouses'':65B ''secrecy'':21B ''secretive'':79C ''self'':14B ''self-aware'':13B ''synth'':41B ''synths'':16B,83C ''that'':81C ''the'':1A,4B,67B,72C ''though'':48B ''to'':11B,26B,61B,76C ''views'':96C ''work'':28B,99C ''worth'':46B', 'railroad', 'The Railroad is rumored to be a secretive group that helps synths escape control. Many Commonwealth residents know little about it or hold conflicting views of its work.', 'commonwealth', 'fallout four, commonwealth lore, faction knowledge, wasteland context', 'faction', NULL, 777, 'the_railroad', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'cff360038cb45de63cd670ca57d6b02ad5f180ea8a3feda8f1624d2bb0adec05', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('deathclaw', 'deathclaws', 'Deathclaws are large, heavily muscled predators with prominent horns and powerful claws. Their speed and strength make close encounters especially dangerous, and tougher variants can be even harder to confront. Travelers treat them as serious threats and look for cover or a safe route away. This general knowledge describes the creature''s danger; its current presence, movement and target require an actual sighting or credible report.', '''a'':44B ''actual'':64B ''an'':63B ''and'':12B,17B,24B,39B,60B,76C,81C ''are'':4B,70C ''as'':36B ''away'':47B ''be'':28B ''can'':27B ''claws'':14B,78C ''close'':20B ''confront'':32B ''cover'':42B ''creature'':53B ''credible'':67B ''current'':57B ''danger'':55B ''dangerous'':23B,85C ''deathclaw'':1A ''deathclaws'':2A,3B,69C ''describes'':51B ''encounters'':21B ''equipped'':90C ''especially'':22B ''even'':29B,86C ''face'':93C ''fast'':72C ''for'':41B ''general'':49B ''harder'':30B ''heavily'':6B ''horns'':11B,75C ''in'':95C ''its'':56B ''knowledge'':50B ''large'':5B,71C ''look'':40B ''make'':19B,83C ''movement'':59B ''muscled'':7B ''open'':97C ''or'':43B,66B ''powerful'':13B,77C ''predators'':8B,73C ''presence'':58B ''prominent'':10B ''report'':68B ''require'':62B ''resilience'':82C ''route'':46B ''s'':54B ''safe'':45B ''serious'':37B ''sighting'':65B ''speed'':16B ''strength'':18B,80C ''target'':61B ''the'':52B,96C ''their'':15B,79C ''them'':35B,84C,94C ''this'':48B ''threats'':38B ''to'':31B,87C ''tougher'':25B ''travelers'':33B,91C ''treat'':34B ''variants'':26B ''well'':89C ''well-equipped'':88C ''who'':92C ''with'':9B,74C', 'commonwealth_lore', 'Deathclaws are large, fast predators with horns and powerful claws. Their strength and resilience make them dangerous even to well-equipped travelers who face them in the open.', 'commonwealth', 'fallout four, commonwealth lore, creature knowledge, wasteland context', 'creature', NULL, 778, 'deathclaw', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '23a4bd8918f4c59d2e2a1d13989853230fc8754bd90dfc001b6b0f8b2e6ab9a9', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('feral_ghoul', 'feral ghouls', 'Feral ghouls are ghouls whose minds have deteriorated and who attack with violent, instinctive behavior. Many lurk in damaged buildings or hazardous places and can suddenly rush a target. Groups can overwhelm a traveler through speed and numbers. They are distinct from reasoning ghouls, who retain their personalities and ability to communicate; knowing that ferals are dangerous does not establish hostility or ferality in another ghoul.', '''a'':32B,37B ''ability'':54B ''and'':13B,28B,41B,53B,95C ''another'':69B ''are'':7B,44B,60B,73C ''attack'':15B ''attackers'':75C ''behavior'':19B ''buildings'':24B ''can'':29B,35B,81C ''capable'':91C ''communicate'':56B ''conversation'':94C ''damaged'':23B ''dangerous'':61B,74C ''deteriorated'':12B,79C ''distinct'':45B ''does'':62B ''establish'':64B ''feral'':1A,3A,5B,71C ''ferality'':67B ''ferals'':59B ''from'':46B ''ghoul'':2A,70B ''ghouls'':4A,6B,8B,48B,72C,88C ''groups'':34B,85C ''have'':11B,78C ''hazardous'':26B ''hostility'':65B ''in'':22B,68B,84C ''individuals'':90C ''instinctive'':18B ''knowing'':57B ''lurk'':21B ''many'':20B ''minds'':10B,77C ''not'':63B ''numbers'':42B ''of'':92C ''or'':25B,66B ''ordinary'':93C ''overwhelm'':36B ''personalities'':52B ''places'':27B ''reasoning'':47B,87C ''relationships'':96C ''remain'':89C ''retain'':50B ''rush'':31B,82C ''speed'':40B ''suddenly'':30B ''target'':33B ''that'':58B ''their'':51B ''they'':43B,80C ''through'':39B ''to'':55B ''traveler'':38B ''travelers'':83C ''violent'':17B ''while'':86C ''who'':14B,49B ''whose'':9B,76C ''with'':16B', 'commonwealth_lore', 'Feral ghouls are dangerous attackers whose minds have deteriorated. They can rush travelers in groups, while reasoning ghouls remain individuals capable of ordinary conversation and relationships.', 'commonwealth', 'fallout four, commonwealth lore, creature knowledge, wasteland context', 'creature', NULL, 779, 'feral_ghoul', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c20ba9734ca3395c26806dffb1b3970cc54f96cb2c68558fe3a35e4518f8927c', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('super_mutant', 'super mutants', 'Super mutants are large, powerful humanoids encountered across the Commonwealth. Many carry firearms or heavy weapons and occupy fortified ruins in groups. Their physical strength and aggressive behavior make such groups dangerous to travelers, but individuals differ in intelligence, speech and disposition. The identity and attitude of a particular super mutant depend on the actual encounter rather than a blanket assumption based on appearance.', '''a'':52B,63B,93C ''across'':12B ''actual'':59B,90C ''aggressive'':31B ''an'':89C ''and'':21B,30B,45B,49B,85C ''appearance'':68B ''are'':7B,71C ''armed'':78C ''around'':80C ''assumption'':65B ''attitude'':50B ''based'':66B ''behavior'':32B,96C ''blanket'':64B ''but'':39B ''carry'':16B ''commonwealth'':14B,81C ''dangerous'':36B ''depend'':56B ''determines'':92C ''differ'':41B ''disposition'':46B,86C ''encounter'':60B,91C ''encountered'':11B,76C ''firearms'':17B ''fortified'':23B ''groups'':26B,35B,79C ''heavy'':19B ''humanoids'':10B,74C ''identity'':48B ''in'':25B,42B,77C ''individual'':83C ''individuals'':40B ''intelligence'':43B,84C ''large'':8B,72C ''make'':33B ''many'':15B ''mutant'':2A,55B ''mutants'':4A,6B,70C ''occupy'':22B ''of'':51B ''often'':75C ''on'':57B,67B ''or'':18B ''particular'':53B ''person'':94C ''physical'':28B ''powerful'':9B ''rather'':61B ''ruins'':24B,82C ''s'':95C ''so'':88C ''speech'':44B ''strength'':29B ''strong'':73C ''such'':34B ''super'':1A,3A,5B,54B,69C ''than'':62B ''the'':13B,47B,58B ''their'':27B ''to'':37B ''travelers'':38B ''vary'':87C ''weapons'':20B', 'commonwealth_lore', 'Super mutants are large, strong humanoids often encountered in armed groups around Commonwealth ruins. Individual intelligence and disposition vary, so an actual encounter determines a person''s behavior.', 'commonwealth', 'fallout four, commonwealth lore, creature knowledge, wasteland context', 'creature', NULL, 780, 'super_mutant', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '79f7176927e4dec88147a9238888972ae54c354956b1679aee593429ae10a1af', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('children_of_atom', 'Atom worshippers,Church of the Children of Atom', 'The Children of Atom are religious communities that revere Atom and attach spiritual meaning to radiation and nuclear division. Some seek homes and shrines in irradiated areas. Their congregations differ in leadership and behavior, ranging from peaceful believers to aggressive zealots. Individual beliefs and a congregation''s attitude toward outsiders matter more than a single assumption about every member of the faith.', '''a'':56B,65B ''about'':68B ''aggressive'':51B ''and'':22B,28B,34B,44B,55B,80C,98C ''are'':16B ''areas'':38B ''as'':83C ''assumption'':67B ''atom'':3A,4A,11A,15B,21B,77C,79C ''attach'':23B ''attitude'':59B ''behavior'':45B ''beliefs'':54B ''believers'':49B ''by'':95C ''children'':1A,9A,13B,75C ''church'':6A ''communities'':18B ''conduct'':97C ''congregation'':57B ''congregations'':40B,88C ''differ'':41B ''division'':30B ''every'':69B ''faith'':73B ''from'':47B ''groups'':94C ''homes'':33B ''important'':85C ''in'':36B,42B ''individual'':53B ''irradiated'':37B ''judge'':92C ''leadership'':43B ''local'':87C ''matter'':62B ''meaning'':25B ''member'':70B ''more'':63B ''nuclear'':29B ''of'':2A,7A,10A,14B,71B,76C ''outsiders'':61B ''particular'':93C ''peaceful'':48B ''radiation'':27B,82C ''ranging'':46B ''regard'':81C ''religious'':17B ''revere'':20B ''s'':58B ''seek'':32B ''shrines'':35B ''single'':66B ''so'':90C ''some'':31B ''spiritual'':24B ''spiritually'':84C ''teachings'':99C ''than'':64B ''that'':19B ''the'':8A,12B,72B,74C ''their'':39B,86C,96C ''to'':26B,50B ''toward'':60B ''travelers'':91C ''vary'':89C ''worship'':78C ''worshippers'':5A ''zealots'':52B', 'commonwealth_lore', 'The Children of Atom worship Atom and regard radiation as spiritually important. Their local congregations vary, so travelers judge particular groups by their conduct and teachings.', 'commonwealth', 'atom religion, radiation beliefs, wasteland congregations, local spiritual traditions', 'faction', NULL, 798, 'children_of_atom', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '72300852051f46ae530389e1e43f42e3be2cd24ee5178ec4a8e115045c40f0ca', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('bunker_hill', 'Bunker Hill Monument', 'Bunker Hill is a walled caravan trading settlement surrounding a surviving granite monument in Charlestown. Its market offers supplies, medical care, food and lodging. Caravan traffic makes it an important meeting point for merchants and travelers. Residents rely on practical arrangements to protect commerce in a dangerous region, and visiting caravans change over time.', '''a'':9B,15B,51B,63C,69C ''an'':34B ''and'':28B,40B,54B,76C,81C ''around'':68C ''arrangements'':46B ''built'':67C ''bunker'':1A,3A,6B,60C ''can'':78C ''caravan'':11B,30B ''caravans'':56B,72C ''care'':26B ''change'':57B ''charlestown'':20B ''commerce'':49B ''commonwealth'':64C ''dangerous'':52B ''find'':79C ''food'':27B ''for'':38B ''granite'':17B ''hill'':2A,4A,7B,61C ''important'':35B ''in'':19B,50B ''is'':8B,62C ''it'':33B ''its'':21B,74C ''lodging'':29B ''makes'':32B ''market'':22B,75C ''medical'':25B ''meeting'':36B ''merchants'':39B ''monument'':5A,18B,71C ''offers'':23B ''on'':44B ''over'':58B ''point'':37B ''practical'':45B ''protect'':48B ''region'':53B ''rely'':43B ''residents'':42B ''services'':82C ''settlement'':13B ''supplies'':24B,80C ''surrounding'':14B ''surviving'':16B ''tall'':70C ''there'':83C ''time'':59B ''to'':47B ''town'':66C ''trading'':12B,65C ''traffic'':31B ''travelers'':41B,77C ''use'':73C ''visiting'':55B ''walled'':10B', 'commonwealth_lore', 'Bunker Hill is a Commonwealth trading town built around a tall monument. Caravans use its market, and travelers can find supplies and services there.', 'commonwealth', 'commonwealth trade, caravan routes, boston settlements, regional landmarks', 'location', NULL, 781, 'bunker_hill', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '4e978994daa22e0506658c77bde95098706e7b18548010582a2168ed6d81ff1c', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_slog', 'Slog', 'The Slog is a tarberry farm established by Wiseman at an old public swimming pool. Its ghoul residents cultivate the waterlogged crop and welcome trade. Wiseman hopes a successful, productive community will challenge the prejudice faced by ghouls. Working conditions can be muddy and demanding, but the farm gives its residents a livelihood and a home.', '''a'':7B,31B,55B,58B,63C,77C ''an'':14B,70C ''and'':26B,47B,57B,81C ''at'':13B ''be'':45B ''but'':49B ''by'':11B,40B ''can'':44B ''challenge'':36B ''commonwealth'':84C ''community'':34B ''conditions'':43B ''crop'':25B ''cultivate'':22B ''demanding'':48B ''earn'':76C ''established'':10B ''faced'':39B ''farm'':9B,51B,68C ''farming'':80C ''ghoul'':20B,65C ''ghoul-run'':64C ''ghouls'':41B ''gives'':52B ''home'':59B ''hopes'':30B ''is'':6B,62C ''its'':19B,53B,74C ''livelihood'':56B ''living'':78C ''muddy'':46B ''old'':15B,71C ''pool'':18B,73C ''prejudice'':38B ''productive'':33B ''public'':16B ''residents'':21B,54B,75C ''run'':66C ''slog'':2A,3A,5B,61C ''successful'':32B ''swimming'':17B,72C ''tarberry'':8B,67C ''the'':1A,4B,23B,37B,50B,60C ''through'':79C ''trade'':28B,82C ''travelers'':85C ''using'':69C ''waterlogged'':24B ''welcome'':27B ''will'':35B ''wiseman'':12B,29B ''with'':83C ''working'':42B', 'commonwealth_lore', 'The Slog is a ghoul-run tarberry farm using an old swimming pool. Its residents earn a living through farming and trade with Commonwealth travelers.', 'commonwealth', 'ghoul communities, commonwealth farming, tarberry cultivation, regional settlements', 'location', NULL, 782, 'the_slog', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '0973d5bb3e7674818333544d832a2a4fe8539a4f6ed7856843f74fe6b3d10fa6', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('graygarden', 'Gray Garden', 'Graygarden is a self-sufficient hydroponic farm operated by Mister Handy robots. Supervisors White, Greene and Brown oversee workers tending its greenhouse and outdoor crops. Completed shortly before the Great War, the facility continued operating for generations without human supervision. Its water supply comes from the Weston treatment plant, making water quality and maintenance essential to the farm.', '''a'':6B,65C ''and'':20B,27B,57B,67C,78C,80C ''before'':32B ''brown'':21B ''by'':13B,70C ''comes'':48B ''completed'':30B ''continued'':38B ''crops'':29B,87C ''cultivation'':77C ''direct'':76C ''essential'':59B ''facility'':37B,82C ''farm'':11B,62B,68C ''food'':86C ''for'':40B ''from'':49B ''garden'':3A ''generations'':41B ''gray'':2A ''graygarden'':1A,4B,63C ''great'':34B ''greene'':19B ''greenhouse'':26B,66C ''grows'':83C ''handy'':15B,72C ''human'':43B ''hydroponic'':10B ''is'':5B,64C ''its'':25B,45B ''maintenance'':58B,79C ''making'':54B ''mister'':14B,71C ''operated'':12B ''operating'':39B ''outdoor'':28B ''oversee'':22B ''plant'':53B ''quality'':56B ''robot'':74C ''robots'':16B,73C ''run'':69C ''self'':8B ''self-sufficient'':7B ''several'':84C ''shortly'':31B ''sufficient'':9B ''supervision'':44B ''supervisors'':17B,75C ''supply'':47B ''tending'':24B ''the'':33B,36B,50B,61B,81C ''to'':60B ''treatment'':52B ''useful'':85C ''war'':35B ''water'':46B,55B ''weston'':51B ''white'':18B ''without'':42B ''workers'':23B', 'commonwealth_lore', 'Graygarden is a greenhouse and farm run by Mister Handy robots. Robot supervisors direct cultivation and maintenance, and the facility grows several useful food crops.', 'commonwealth', 'robot farming, hydroponic gardens, commonwealth produce, agricultural maintenance', 'location', NULL, 783, 'graygarden', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'e40033287c7ab17ef269e0f47a50e95b2f7e8c8536bf2446a0fefc8bca9281d7', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('drumlin_diner', 'Drumlin shop', 'The Drumlin Diner is a small roadside diner southeast of Concord that has been adapted for trading. Its oval building and surrounding parking area survive from the pre-war business. Trudy operates a shop there, with her son Patrick nearby at the beginning of the local encounter. Travelers may find caravan traffic in the area; current people and trading arrangements depend on local events.', '''a'':9B,38B,74C,82C,90C ''adapted'':19B ''and'':25B,63B ''area'':28B,60B ''arrangements'':65B ''as'':81C ''at'':46B ''been'':18B ''beginning'':48B ''building'':24B ''business'':35B ''caravan'':56B ''concord'':15B,79C ''current'':61B ''depend'':66B ''diner'':2A,7B,12B,72C,77C ''drumlin'':1A,3A,6B,71C ''encounter'':52B ''events'':69B ''find'':55B ''for'':20B,93C ''former'':76C ''from'':30B ''has'':17B ''her'':42B ''in'':58B ''is'':8B,73C ''it'':89C ''its'':22B,85C ''landmark'':92C ''local'':51B,68B ''makes'':88C ''may'':54B ''near'':78C ''nearby'':45B ''of'':14B,49B ''on'':67B ''operates'':37B ''oval'':23B ''parking'':27B ''patrick'':44B ''people'':62B ''position'':87C ''pre'':33B ''pre-war'':32B ''recognizable'':91C ''roadside'':11B,86C ''shop'':4A,39B ''small'':10B,75C ''son'':43B ''southeast'':13B ''stop'':84C ''surrounding'':26B ''survive'':29B ''that'':16B ''the'':5B,31B,47B,50B,59B,70C ''there'':40B ''trading'':21B,64B,83C ''traffic'':57B ''travelers'':53B,94C ''trudy'':36B ''used'':80C ''war'':34B ''with'':41B', 'commonwealth_lore', 'The Drumlin Diner is a small former diner near Concord used as a trading stop. Its roadside position makes it a recognizable landmark for travelers.', 'commonwealth', 'roadside trading, northwest commonwealth, local merchants, prewar buildings', 'location', NULL, 784, 'drumlin_diner', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '1b2eff521acb8c1abc274b72c63b293cf17a0161fdf8841ba9c2443538780c7c', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('abernathy_farm', 'Abernathy homestead', 'Abernathy farm is a family homestead built around the frame of a high-voltage electricity pylon. The makeshift farmhouse has two stories, with cultivated fields of tatos and melons around it. Blake and Connie Abernathy and their daughter Lucy work the farm. Like many small Commonwealth farms, its livelihood depends on sustained labor and protecting crops and family from local threats.', '''a'':8B,16B,70C,76C ''abernathy'':1A,3A,5B,40B,67C ''and'':33B,38B,41B,59B,62B,84C,86C ''around'':12B,35B ''beneath'':75C ''blake'':37B ''built'':11B ''commonwealth'':51B ''connie'':39B ''crops'':61B ''cultivated'':29B ''daughter'':43B ''depends'':55B ''distinctive'':91C ''electricity'':20B,78C ''family'':9B,63B,72C ''family-run'':71C ''farm'':2A,6B,47B,68C,74C ''farmhouse'':24B,92C ''farms'':52B ''fields'':30B,81C ''frame'':14B ''from'':64B ''grow'':82C ''has'':25B ''high'':18B ''high-voltage'':17B ''homestead'':4A,10B ''is'':7B,69C ''it'':36B ''its'':53B,80C,90C ''labor'':58B ''large'':77C ''like'':48B ''livelihood'':54B ''local'':65B ''lucy'':44B ''makeshift'':23B ''many'':49B ''melons'':34B,85C ''of'':15B,31B ''on'':56B ''protecting'':60B ''pylon'':21B,79C ''run'':73C ''small'':50B ''stories'':27B ''supports'':89C ''sustained'':57B ''tatos'':32B,83C ''the'':13B,22B,46B,87C ''their'':42B ''threats'':66B ''tower'':88C ''two'':26B ''voltage'':19B ''with'':28B ''work'':45B', 'northwest_commonwealth', 'Abernathy farm is a family-run farm beneath a large electricity pylon. Its fields grow tatos and melons, and the tower supports its distinctive farmhouse.', 'commonwealth', 'family farming, northwest commonwealth, tato cultivation, rural settlements', 'location', NULL, 785, 'abernathy_farm', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '577e60463657a0be35c350c0994109d1635e8fb33a91b9581655fcd06ca4ebc2', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('concord', 'Town of Concord', 'Concord is a historic town northwest of Boston. The Museum of Freedom, church, shops and main street reflect its former role as a tourist destination commemorating the American Revolution. In the post-war Commonwealth, many buildings are boarded up or damaged, while scavengers and other travelers move through the ruins. Present occupants and recent fighting vary with events in the region.', '''a'':7B,27B,69C,75C ''american'':32B ''and'':19B,49B,58B,79C ''are'':42B ''as'':26B ''before'':89C ''boarded'':43B ''bombs'':91C ''boston'':12B,73C ''buildings'':41B ''celebrated'':88C ''church'':17B,78C ''commemorating'':30B ''commonwealth'':39B ''concord'':1A,4A,5B,67C ''damaged'':46B ''destination'':29B ''events'':63B ''fighting'':60B ''former'':24B ''freedom'':16B,82C ''historic'':8B ''history'':86C ''in'':34B,64B ''is'':6B,68C ''its'':23B,83C ''main'':20B,76C ''many'':40B ''move'':52B ''museum'':14B,80C ''northwest'':10B,71C ''occupants'':57B ''of'':3A,11B,15B,72C,81C ''or'':45B ''other'':50B ''post'':37B ''post-war'':36B ''present'':56B ''recent'':59B ''reflect'':22B ''region'':66B ''revolution'':33B ''revolutionary'':84C ''role'':25B ''ruins'':55B ''scavengers'':48B ''shops'':18B ''street'':21B,77C ''the'':13B,31B,35B,54B,65B,90C ''through'':53B ''tourist'':28B ''town'':2A,9B,70C ''travelers'':51B ''up'':44B ''vary'':61B ''war'':38B,85C ''was'':87C ''while'':47B ''with'':62B,74C', 'northwest_commonwealth', 'Concord is a town northwest of Boston with a main street, church and Museum of Freedom. Its Revolutionary War history was celebrated before the bombs.', 'commonwealth', 'northwest commonwealth, revolutionary history, ruined towns, regional landmarks', 'location', NULL, 786, 'concord', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '9e100bbef039308afbfa3dd48a32711e1eb32510a132b4293b8c389562027911', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('museum_of_freedom', 'Concord Museum', 'The Museum of Freedom stands in Concord and preserves exhibits about American military history. Before the Great War it drew visitors interested in the Revolution and the sacrifices of soldiers across different eras. Its displays include mannequins and an automated audio tour. The surviving building is a recognizable landmark, while its current condition and occupants follow the situation in Concord.', '''a'':52B,88C ''about'':16B ''across'':36B ''american'':17B,80C ''an'':44B ''and'':13B,31B,43B,59B,85C ''attraction'':90C ''audio'':46B ''automated'':45B ''before'':20B,91C ''building'':50B ''commemorate'':79C ''concord'':4A,12B,65B,71C ''condition'':58B ''conflicts'':81C ''current'':57B ''different'':37B ''displays'':40B ''drew'':25B ''eras'':38B ''especially'':82C ''exhibits'':15B,78C ''follow'':61B ''freedom'':3A,9B,69C ''great'':22B,93C ''history'':19B,75C ''in'':11B,28B,64B ''include'':41B ''interested'':27B ''is'':51B,70C ''it'':24B,86C ''its'':39B,56B,77C ''landmark'':54B ''mannequins'':42B ''military'':18B,74C ''military-history'':73C ''museum'':1A,5A,7B,67C,76C ''occupants'':60B ''of'':2A,8B,34B,68C ''popular'':89C ''preserves'':14B ''recognizable'':53B ''revolution'':30B,84C ''s'':72C ''sacrifices'':33B ''situation'':63B ''soldiers'':35B ''stands'':10B ''surviving'':49B ''the'':6B,21B,29B,32B,48B,62B,66C,83C,92C ''tour'':47B ''visitors'':26B ''war'':23B,94C ''was'':87C ''while'':55B', 'northwest_commonwealth', 'The Museum of Freedom is Concord''s military-history museum. Its exhibits commemorate American conflicts, especially the Revolution, and it was a popular attraction before the Great War.', 'commonwealth', 'concord history, military museums, revolutionary exhibits, regional landmarks', 'location', NULL, 787, 'museum_of_freedom', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '05b56181050b547ceb192fb22f581558f01a58eb630cc871bbb974fc3428a817', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('lexington', 'Town of Lexington', 'Lexington is a town northwest of Boston with a surviving commercial district and the prominent Corvega assembly plant nearby. Its streets include shops, apartments and older service businesses. The post-war town contains dangerous ruins and has been associated with raider activity and feral ghouls. Local danger should be understood as a reason for caution rather than certainty about who occupies a particular street today.', '''a'':7B,13B,57B,67B,73C ''about'':64B ''activity'':47B ''and'':17B,29B,41B,48B ''apartments'':28B ''approach'':86C ''as'':56B ''assembly'':21B,82C ''associated'':44B ''be'':54B ''because'':90C ''been'':43B ''boston'':11B,78C ''businesses'':32B ''caution'':60B ''cautiously'':89C ''certainty'':63B ''commercial'':15B ''contains'':38B ''corvega'':20B,81C ''danger'':52B ''dangerous'':39B,95C ''district'':16B ''feral'':49B ''for'':59B ''ghouls'':50B ''has'':42B ''include'':26B ''is'':6B,72C ''its'':24B,87C ''lexington'':1A,4A,5B,71C ''local'':51B ''near'':79C ''nearby'':23B ''northwest'':9B,76C ''occupies'':66B ''of'':3A,10B,77C,91C ''often'':85C ''older'':30B ''particular'':68B ''plant'':22B,83C ''post'':35B ''post-war'':34B ''prominent'':19B ''raider'':46B ''rather'':61B ''reason'':58B ''region'':93C ''reputation'':96C ''ruined'':74C ''ruins'':40B ''s'':94C ''service'':31B ''shops'':27B ''should'':53B ''street'':69B ''streets'':25B,88C ''surviving'':14B ''than'':62B ''the'':18B,33B,80C,92C ''today'':70B ''town'':2A,8B,37B,75C ''travelers'':84C ''understood'':55B ''war'':36B ''who'':65B ''with'':12B,45B', 'northwest_commonwealth', 'Lexington is a ruined town northwest of Boston, near the Corvega assembly plant. Travelers often approach its streets cautiously because of the region''s dangerous reputation.', 'commonwealth', 'northwest commonwealth, industrial towns, urban scavenging, regional hazards', 'location', NULL, 788, 'lexington', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'd26a49e2dc56f7305d460f087a0cda9f1df01b25aa04d9612405b8ec26e49207', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('corvega_assembly_plant', 'Corvega factory', 'The Corvega assembly plant is a large automobile factory on the southern side of Lexington. Its industrial machinery, elevated structures and broad factory floors reflect the area''s pre-war manufacturing economy. The ruins have become associated with organized raider activity. The building''s size and many approaches make it a difficult place to assess from outside; current control and safety depend on recent local events.', '''a'':11B,57B,86C,93C ''activity'':47B ''among'':96C ''and'':26B,52B,66B,89C ''approaches'':54B ''are'':85C ''area'':32B ''assembly'':2A,8B ''assess'':61B ''associated'':43B ''automobile'':13B,78C ''become'':42B ''broad'':27B ''building'':49B ''control'':65B ''corvega'':1A,4A,7B,73C ''current'':64B ''dangerous'':94C ''depend'':68B ''difficult'':58B ''economy'':38B ''elevated'':24B ''events'':72B ''factory'':5A,14B,28B,79C ''floors'':29B ''former'':77C ''from'':62B ''has'':92C ''have'':41B ''industrial'':22B,83C ''is'':10B,74C ''it'':56B ''its'':21B,82C ''landmark'':88C ''large'':12B,76C ''lexington'':20B,81C ''local'':71B ''machinery'':23B ''make'':55B ''manufacturing'':37B ''many'':53B ''near'':80C ''of'':19B ''on'':15B,69B ''organized'':45B ''outside'':63B ''place'':59B ''plant'':3A,9B ''pre'':35B ''pre-war'':34B ''raider'':46B ''recent'':70B ''recognizable'':87C ''reflect'':30B ''reputation'':95C ''ruins'':40B,84C ''s'':33B,50B ''safety'':67B ''side'':18B ''site'':91C ''size'':51B ''southern'':17B ''structures'':25B ''the'':6B,16B,31B,39B,48B,75C,90C ''to'':60B ''travelers'':97C ''war'':36B ''with'':44B', 'northwest_commonwealth', 'Corvega is the large former automobile factory near Lexington. Its industrial ruins are a recognizable landmark, and the site has a dangerous reputation among travelers.', 'commonwealth', 'automobile factories, lexington industry, raider territory, industrial ruins', 'location', NULL, 789, 'corvega_assembly_plant', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '72834cb083ed5469d7ea937af20f8ad6281c83d4ba1d6a0981e83c172f224531', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('cambridge_police_station', 'Cambridge PD', 'Cambridge Police Station is a surviving law-enforcement building in Cambridge. Before the Great War it served police personnel from the surrounding area and Boston. In the post-war Commonwealth, the Brotherhood''s Recon Squad Gladius has used the station as a forward base and listening post. Its precise personnel, readiness and current security depend on observed Brotherhood activity rather than the building''s history alone.', '''a'':10B,49B,88C ''activity'':66B ''alone'':73B ''an'':78C ''and'':30B,52B,59B ''area'':29B ''as'':48B,87C ''base'':51B ''before'':18B ''boston'':31B ''brotherhood'':39B,65B,92C ''building'':15B,70B,81C ''by'':91C ''cambridge'':1A,4A,6B,17B,74C,83C ''commonwealth'':37B,98C ''current'':60B ''depend'':62B ''enforcement'':14B ''forward'':50B ''from'':26B ''gladius'':43B ''great'':20B ''has'':44B ''history'':72B ''in'':16B,32B,82C,96C ''is'':9B,77C,85C ''it'':22B,84C ''its'':55B ''known'':86C ''law'':13B ''law-enforcement'':12B ''listening'':53B ''location'':89C ''observed'':64B ''old'':79C ''on'':63B ''operating'':95C ''pd'':5A ''personnel'':25B,57B,94C ''police'':2A,7B,24B,75C,80C ''post'':35B,54B ''post-war'':34B ''precise'':56B ''rather'':67B ''readiness'':58B ''recon'':41B ''reconnaissance'':93C ''s'':40B,71B ''security'':61B ''served'':23B ''squad'':42B ''station'':3A,8B,47B,76C ''surrounding'':28B ''surviving'':11B ''than'':68B ''the'':19B,27B,33B,38B,46B,69B,97C ''used'':45B,90C ''war'':21B,36B', 'brotherhood_of_steel', 'Cambridge Police Station is an old police building in Cambridge. It is known as a location used by Brotherhood reconnaissance personnel operating in the Commonwealth.', 'commonwealth', 'cambridge landmarks, brotherhood reconnaissance, police buildings, military outposts', 'location', NULL, 790, 'cambridge_police_station', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c9d30b23f99aec0586c29a89d6d974e2e39f4337854f65ba9f5d1baceed5152a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('glowing_sea', 'The Glowing Sea', 'The Glowing Sea is the intensely irradiated region southwest of Boston. Despite its name it is a devastated landscape rather than an ordinary body of water. Local stories associate it with the nuclear blast that struck the area during the Great War. Its radiation, damaged terrain and mutated wildlife make travel exceptionally hazardous for anyone lacking suitable protection and preparation.', '''a'':22B,70C ''an'':27B ''and'':52B,64B,87C ''anyone'':60B ''area'':43B ''as'':81C ''associate'':34B ''because'':84C ''blast'':39B ''body'':29B ''boston'':16B,76C ''commonwealth'':77C ''damaged'':50B ''dangerous'':83C ''despite'':17B ''devastated'':23B ''during'':44B ''exceptionally'':57B,82C ''for'':59B ''glowing'':1A,4A,7B,67C ''great'':46B ''hazardous'':58B ''highly'':71C ''intensely'':11B ''irradiated'':12B ''is'':9B,21B,69C ''it'':20B,35B,80C ''its'':18B,48B ''lacking'':61B ''landscape'':24B ''local'':32B ''make'':55B ''mutated'':53B,88C ''name'':19B ''nuclear'':38B ''of'':15B,30B,75C,85C ''ordinary'':28B ''preparation'':65B ''protection'':63B ''radiation'':49B,86C ''radioactive'':72C ''rather'':25B ''regard'':79C ''region'':13B ''sea'':2A,5A,8B,68C ''southwest'':14B,74C ''stories'':33B ''struck'':41B ''suitable'':62B ''terrain'':51B ''than'':26B ''that'':40B ''the'':3A,6B,10B,37B,42B,45B,66C ''travel'':56B ''travelers'':78C ''war'':47B ''wasteland'':73C ''water'':31B ''wildlife'':54B,89C ''with'':36B', 'commonwealth_lore', 'The Glowing Sea is a highly radioactive wasteland southwest of Boston. Commonwealth travelers regard it as exceptionally dangerous because of radiation and mutated wildlife.', 'commonwealth', 'radiation hazards, southwest commonwealth, nuclear devastation, dangerous wilderness', 'location', NULL, 791, 'glowing_sea', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'b233fe6ec7d3c11ae8c296fc71710485c68f7f9408ea9db0cd915575bd96ebf6', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('quincy_ruins', 'Quincy', 'Quincy is a historic town in the southeastern Commonwealth, with shops, a church and an elevated highway crossing its streets. Its post-war community suffered a disastrous Gunner attack remembered as the Quincy Massacre. The event scattered survivors and damaged confidence in the Minutemen. Detailed accounts belong to witnesses, and the site''s current occupation follows later events rather than remaining fixed forever.', '''a'':6B,15B,30B,70C ''accounts'':50B ''an'':18B ''and'':17B,43B,54B,88C ''as'':35B ''attack'':33B,78C ''badly'':89C ''belong'':51B ''church'':16B ''commonwealth'':12B,72C ''community'':28B,82C ''confidence'':45B ''crossing'':21B ''current'':58B ''damaged'':44B ''detailed'':49B ''devastated'':80C ''disastrous'':31B ''displaced'':87C ''elevated'':19B ''event'':40B ''events'':62B ''fixed'':66B ''follows'':60B ''for'':75C ''forever'':67B ''gunner'':32B,77C ''harmed'':90C ''highway'':20B ''historic'':7B ''in'':9B,46B ''is'':5B,69C ''its'':22B,24B,81C ''later'':61B ''left'':85C ''massacre'':38B,84C ''minutemen'':48B,92C ''occupation'':59B ''post'':26B ''post-war'':25B ''quincy'':1A,3A,4B,37B,68C ''rather'':63B ''remaining'':65B ''remembered'':34B,74C ''reputation'':94C ''ruins'':2A ''s'':57B,93C ''scattered'':41B ''shops'':14B ''site'':56B ''southeastern'':11B,71C ''streets'':23B ''suffered'':29B ''survivors'':42B,86C ''than'':64B ''that'':79C ''the'':10B,36B,39B,47B,55B,76C,83C,91C ''to'':52B ''town'':8B,73C ''war'':27B ''with'':13B ''witnesses'':53B', 'minutemen', 'Quincy is a southeastern Commonwealth town remembered for the Gunner attack that devastated its community. The massacre left survivors displaced and badly harmed the Minutemen''s reputation.', 'commonwealth', 'quincy history, minutemen losses, gunner attacks, displaced communities', 'location', NULL, 792, 'quincy_ruins', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '2796c47f1479349337742723084d175469e2951b85b4494903c978b8896afa39', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_castle', 'Fort Independence,Castle Island fort', 'The Castle is the Commonwealth name for Fort Independence, a star-shaped coastal fort in Boston Harbor. Its thick walls enclose a courtyard and rooms within the fortifications. It once served as Minutemen headquarters and housed a powerful radio transmitter. Damage and wildlife have made the old stronghold hazardous at times; its present ownership, defenses and broadcasts depend on the current situation.', '''a'':17B,30B,45B,87C,95C ''an'':74C ''and'':32B,43B,50B,64B,94C ''as'':40B,89C ''at'':58B ''boston'':24B,79C ''broadcasts'':65B ''called'':82C ''castle'':2A,5A,9B,72C ''coastal'':21B,76C ''commonwealth'':12B ''courtyard'':31B ''current'':69B ''damage'':49B ''defenses'':63B ''depend'':66B ''enclose'':29B ''for'':14B ''formerly'':81C ''fort'':3A,7A,15B,22B,77C,83C ''fortifications'':36B ''harbor'':25B,80C ''has'':86C ''have'':52B ''hazardous'':57B ''headquarters'':42B,93C ''history'':88C ''housed'':44B ''in'':23B,78C ''independence'':4A,16B,84C ''is'':10B,73C ''island'':6A ''it'':37B,85C ''its'':26B,60B ''made'':53B ''minutemen'':41B,91C ''name'':13B ''old'':55B,75C ''on'':67B ''once'':38B ''ownership'':62B ''powerful'':46B ''present'':61B ''radio'':47B,96C ''relay'':97C ''rooms'':33B ''s'':92C ''served'':39B ''shaped'':20B ''site'':98C ''situation'':70B ''star'':19B ''star-shaped'':18B ''stronghold'':56B ''the'':1A,8B,11B,35B,54B,68B,71C,90C ''thick'':27B ''times'':59B ''transmitter'':48B ''walls'':28B ''wildlife'':51B ''within'':34B', 'minutemen', 'The Castle is an old coastal fort in Boston Harbor, formerly called Fort Independence. It has a history as the Minutemen''s headquarters and a radio relay site.', 'commonwealth', 'coastal fortifications, minutemen history, boston harbor, radio infrastructure', 'location', NULL, 793, 'the_castle', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '66ef7af24e3766f6d779f007d298f60e66d9746fad656209b8faff084bdd1cd6', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('diamond_city_radio', 'DCR', 'Diamond City Radio broadcasts music, advertisements and Commonwealth news from a small studio in Diamond City. Travis Miles is its usual host at the beginning of the regional story, supported by local sponsors and listeners. The signal is widely heard across the Commonwealth. A broadcast is a report from its speaker, so claims about recent events retain that source and their original uncertainty.', '''a'':15B,48B,51B,72C ''about'':58B ''across'':45B ''advertisements'':10B,78C ''and'':11B,38B,64B,79C ''at'':27B ''beginning'':29B ''boys'':94C ''broadcast'':49B ''broadcasts'':8B ''by'':35B ''carrying'':75C ''city'':2A,6B,20B,69C,83C ''claims'':57B ''commonwealth'':12B,47B,73C ''dcr'':4A ''diamond'':1A,5B,19B,68C,82C ''events'':60B ''from'':14B,53B,81C ''heard'':44B ''host'':26B ''in'':18B ''is'':23B,42B,50B,71C ''its'':24B,54B ''listen'':86C ''listeners'':39B ''local'':36B,77C ''many'':84C ''miles'':22B ''music'':9B,76C ''news'':13B,80C ''of'':30B ''on'':87C ''or'':90C ''ordinary'':88C ''original'':66B ''pip'':93C ''pip-boys'':92C ''radio'':3A,7B,70C ''radios'':89C ''recent'':59B ''regional'':32B ''report'':52B ''retain'':61B ''signal'':41B ''small'':16B ''so'':56B ''source'':63B ''speaker'':55B ''sponsors'':37B ''station'':74C ''story'':33B ''studio'':17B ''supported'':34B ''that'':62B ''the'':28B,31B,40B,46B ''their'':65B,91C ''travelers'':85C ''travis'':21B ''uncertainty'':67B ''usual'':25B ''widely'':43B', 'commonwealth_lore', 'Diamond City Radio is a Commonwealth station carrying music, local advertisements and news from Diamond City. Many travelers listen on ordinary radios or their Pip-Boys.', 'commonwealth', 'commonwealth broadcasts, local news, radio music, diamond city', 'culture', NULL, 794, 'diamond_city_radio', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '77cf0f09394ba0909075126fb4e9b88da39b1f988aedb5d5d5192f0ca421919d', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('commonwealth_raiders', 'raiders,raider gangs', 'Commonwealth raiders are separate gangs rather than one unified organization. They take supplies through theft, violence and extortion, and may occupy ruined buildings or fortified camps. Some use turrets and salvaged power armor. Relationships between gangs vary: cooperation, rivalry and open fighting all occur. A gang''s actual leader, territory and intentions belong to local knowledge and current encounters.', '''a'':50B ''actual'':53B ''all'':48B ''and'':22B,24B,35B,45B,56B,62B,72C,76C,83C ''are'':8B,66C ''armor'':38B ''belong'':58B ''between'':40B ''buildings'':28B ''camps'':31B ''commonwealth'':1A,6B,78C ''cooperation'':43B ''current'':63B ''encounters'':64B ''equipment'':86C ''extortion'':23B ''fighting'':47B ''for'':74C ''fortified'':30B ''from'':88C ''gang'':51B ''gangs'':5A,10B,41B,67C ''groups'':79C ''have'':80C ''heavy'':92C ''improvised'':89C ''intentions'':57B ''knowledge'':61B ''leader'':54B ''leaders'':82C ''local'':60B ''machinery'':93C ''may'':25B ''occupy'':26B ''occur'':49B ''on'':70C ''one'':13B ''open'':46B ''or'':29B ''organization'':15B ''power'':37B,77C ''prey'':69C ''raider'':4A ''raiders'':2A,3A,7B,65C ''rather'':11B ''relationships'':39B ''rivalries'':84C ''rivalry'':44B ''ruined'':27B ''s'':52B ''salvaged'':36B ''separate'':9B,81C ''settlements'':73C ''some'':32B ''supplies'':18B,75C ''take'':17B ''territory'':55B ''than'':12B ''that'':68C ''theft'':20B ''they'':16B ''through'':19B ''to'':59B,91C ''travelers'':71C ''turrets'':34B ''unified'':14B ''use'':33B ''vary'':42B ''varying'':87C ''violence'':21B ''weapons'':90C ''with'':85C', 'commonwealth_lore', 'Raiders are gangs that prey on travelers and settlements for supplies and power. Commonwealth groups have separate leaders and rivalries, with equipment varying from improvised weapons to heavy machinery.', 'commonwealth', 'raider gangs, settlement threats, wasteland crime, local rivalries', 'faction', NULL, 795, 'commonwealth_raiders', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '6b956e5db813d959a00e34ef7b6f54cd163d052a68ab03a4fdde711ddd3f6f41', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('gunners', 'Gunner mercenaries', 'The Gunners are a heavily armed mercenary organization with military-style ranks, disciplined units and fortified positions. They use equipment such as energy weapons, power armor and combat robots, making them a serious threat to less organized communities. Their attack on Quincy is part of the Commonwealth''s recent history. Specific contracts and commanders require direct evidence or knowledge held by those involved.', '''a'':7B,36B ''and'':19B,31B,57B,83C,94C ''are'':6B,70C ''armed'':9B ''armor'':30B ''as'':26B ''attack'':44B ''by'':65B ''combat'':32B ''commanders'':58B ''commonwealth'':51B,79C ''communities'':42B ''contracts'':56B ''dangerous'':90C ''direct'':60B ''disciplined'':17B ''energy'':27B ''equipment'':24B ''equipped'':74C ''evidence'':61B ''for'':92C ''force'':87C ''fortified'':20B ''gunner'':2A ''gunners'':1A,5B,69C ''heavily'':8B ''held'':64B ''history'':54B ''in'':77C ''involved'':67B ''is'':47B ''knowledge'':63B ''less'':40B ''make'':88C ''making'':34B ''mercenaries'':3A,75C ''mercenary'':10B ''methods'':82C ''military'':14B,81C ''military-style'':13B ''of'':49B ''on'':45B ''operating'':76C ''opponents'':91C ''or'':62B ''organization'':11B ''organized'':41B,71C ''part'':48B ''positions'':21B ''power'':29B ''quincy'':46B ''ranks'':16B ''recent'':53B ''require'':59B ''robots'':33B ''s'':52B ''serious'':37B ''settlements'':93C ''specific'':55B ''style'':15B ''such'':25B ''the'':4B,50B,68C,78C ''their'':43B,80C ''them'':35B,89C ''they'':22B ''those'':66B ''threat'':38B ''to'':39B,85C ''travelers'':95C ''units'':18B ''use'':23B,86C ''weapons'':28B ''well'':73C ''well-equipped'':72C ''willingness'':84C ''with'':12B', 'commonwealth_lore', 'The Gunners are organized, well-equipped mercenaries operating in the Commonwealth. Their military methods and willingness to use force make them dangerous opponents for settlements and travelers.', 'commonwealth', 'mercenary forces, military organization, commonwealth threats, gunner equipment', 'faction', NULL, 796, 'gunners', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '8ac3156f0a9e8773de6aee2a52a0853813d843803e14f73b1a6c85a655d3e47f', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('atom_cats', 'Atom Cat gang', 'The Atom Cats are power-armor enthusiasts associated with a garage near Quincy. Their style combines customized armor, greaser clothing and a distinctive sense of cool. Zeke leads the group, with members such as Rowdy handling practical work around the garage. Poetry and social life matter alongside machinery, and their local cooperation with Warwick homestead is a practical neighborhood connection.', '''a'':16B,28B,63B,71C,86C ''alongside'':53B ''and'':27B,49B,55B,80C ''are'':9B,70C ''armor'':12B,24B,77C ''around'':45B ''as'':40B ''associated'':14B ''atom'':1A,3A,7B,68C ''cat'':4A ''cats'':2A,8B,69C ''clothing'':26B ''combines'':22B ''commonwealth'':94C ''connection'':66B ''cool'':32B ''cooperation'':58B ''customized'':23B ''distinctive'':29B ''enthusiasts'':13B,90C ''for'':75C,89C ''gang'':5A ''garage'':17B,47B,78C,84C ''gathering'':87C ''greaser'':25B,81C ''group'':36B,73C ''handling'':42B ''homestead'':61B ''in'':91C ''is'':62B,85C ''known'':74C ''leads'':34B ''life'':51B ''local'':57B,72C ''machinery'':54B ''matter'':52B ''members'':38B ''near'':18B ''neighborhood'':65B ''of'':31B ''place'':88C ''poetry'':48B ''power'':11B,76C ''power-armor'':10B ''practical'':43B,64B ''quincy'':19B ''rowdy'':41B ''sense'':30B ''social'':50B ''southeastern'':93C ''style'':21B,82C ''such'':39B ''the'':6B,35B,46B,67C,92C ''their'':20B,56B,83C ''warwick'':60B ''with'':15B,37B,59B ''work'':44B,79C ''zeke'':33B', 'commonwealth_lore', 'The Atom Cats are a local group known for power armor, garage work and greaser style. Their garage is a gathering place for enthusiasts in the southeastern Commonwealth.', 'commonwealth', 'power armor culture, garage communities, southeastern commonwealth, local cooperation', 'faction', NULL, 797, 'atom_cats', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '508bf243d94500b5b9131e8a07d660cf7082a6e3e8f5df05d7b052128abc510a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('great_war', 'the bombs,nuclear war of 2077', 'The Great War was the worldwide nuclear catastrophe of October 23, 2077. It destroyed the pre-war order and left cities, infrastructure and ecosystems devastated. Survivors sheltered where they could, while later communities grew among the ruins. Commonwealth residents inherit its consequences through radiation, scarcity and abandoned technology; eyewitness experiences belong to those who actually lived through the day.', '''2077'':8A,20B,77C ''23'':19B ''abandoned'':56B ''across'':90C ''actually'':64B ''aftermath'':87C ''among'':44B ''and'':28B,32B,55B,85C ''belong'':60B ''bombs'':4A ''catastrophe'':16B,75C ''cities'':30B ''commonwealth'':47B,93C ''communities'':42B ''consequences'':51B ''could'':39B ''day'':68B ''destroyed'':22B,79C ''devastated'':34B ''ecosystems'':33B ''experiences'':59B ''eyewitness'':58B ''great'':1A,10B,70C ''grew'':43B ''in'':76C ''infrastructure'':31B ''inherit'':49B ''it'':21B ''its'':50B,83C ''later'':41B ''left'':29B ''life'':89C ''lived'':65B ''nuclear'':5A,15B,74C ''october'':18B ''of'':7A,17B ''old'':81C ''order'':27B ''pre'':25B ''pre-war'':24B ''present'':92C ''radiation'':53B ''radioactive'':86C ''residents'':48B ''ruins'':46B,84C ''scarcity'':54B ''shape'':88C ''sheltered'':36B ''survivors'':35B ''technology'':57B ''that'':78C ''the'':3A,9B,13B,23B,45B,67B,69C,73C,80C,91C ''they'':38B ''those'':62B ''through'':52B,66B ''to'':61B ''war'':2A,6A,11B,26B,71C ''was'':12B,72C ''where'':37B ''while'':40B ''who'':63B ''world'':82C ''worldwide'':14B', 'commonwealth_lore', 'The Great War was the nuclear catastrophe in 2077 that destroyed the old world. Its ruins and radioactive aftermath shape life across the present Commonwealth.', 'commonwealth', 'nuclear history, prewar collapse, wasteland origins, commonwealth history', 'history', NULL, 799, 'great_war', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '17767da655cb6a02901bcaf76512b69bb34b5d04dfa9503db65172f68a8eb447', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_cola', '', 'Nuka-Cola is a popular American soft drink with recognizable bottles, vending machines and advertising. Its sweet, caffeinated cola flavor helped make the brand a familiar part of daily life. Several varieties are associated with the company, and collectors can take an interest in its merchandise. The availability, condition and effects of a particular bottle depend on the item actually encountered.', '''a'':7B,28B,56B,69C ''actually'':63B ''advertisements'':78C ''advertising'':18B ''american'':9B,71C ''an'':45B ''and'':17B,41B,53B,79C ''are'':36B ''associated'':37B,74C ''availability'':51B ''bottle'':58B ''bottles'':14B,77C ''brand'':27B,89C ''caffeinated'':21B,84C ''can'':43B ''cola'':2A,5B,22B,67C,85C ''collectors'':42B ''company'':40B ''condition'':52B ''daily'':32B ''depend'':59B ''drink'':11B,73C ''effects'':54B ''encountered'':64B ''familiar'':29B,91C ''famous'':70C ''flavor'':23B,86C ''helped'':24B ''in'':47B ''interest'':46B ''is'':6B,68C ''item'':62B ''its'':19B,48B,82C ''life'':33B ''machines'':16B,81C ''made'':87C ''make'':25B ''merchandise'':49B ''nuka'':1A,4B,66C ''nuka-cola'':3B,65C ''of'':31B,55B ''on'':60B ''part'':30B ''particular'':57B ''popular'':8B ''recognizable'':13B,76C ''several'':34B ''soft'':10B,72C ''sweet'':20B,83C ''take'':44B ''the'':26B,39B,50B,61B,88C ''varieties'':35B ''vending'':15B,80C ''widely'':90C ''with'':12B,38B,75C', 'prewar_culture', 'Nuka-Cola is a famous American soft drink associated with recognizable bottles, advertisements and vending machines. Its sweet, caffeinated cola flavor made the brand widely familiar.', 'prewar,commonwealth', 'prewar beverages, american advertising, scavenged goods, soft drink culture', 'food_drink', NULL, 800, 'nuka_cola', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'ac97c5ae96931220317fcb31ffa290adc2d82566c4b6dc8e0bb52fc7bf810104', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('robco_industries', 'RobCo', 'RobCo Industries is a major electronics and robotics company. Its products include Pip-Boys, Protectrons, computer systems and other automated equipment. RobCo technology appears in homes, businesses and public facilities, and its branding identifies many familiar terminals. Knowledge of the brand gives general familiarity; operating, repairing or identifying a particular model depends on the user''s training and the machine''s condition.', '''a'':7B,53B ''an'':69C ''and'':10B,22B,32B,35B,62B,78C,87C,93C ''appears'':28B ''associated'':72C ''automated'':24B,85C ''boys'':18B,76C ''brand'':45B ''branding'':37B,82C ''businesses'':31B,92C ''company'':12B,71C ''computer'':20B ''condition'':66B ''depends'':56B ''electronic'':88C ''electronics'':9B,70C ''equipment'':25B ''facilities'':34B,95C ''familiar'':40B ''familiarity'':48B ''general'':47B ''gives'':46B ''homes'':30B ''identifies'':38B,83C ''identifying'':52B ''in'':29B,91C ''include'':15B ''industries'':2A,5B ''is'':6B,68C ''its'':13B,36B,81C ''knowledge'':42B ''machine'':64B ''major'':8B ''many'':39B,84C ''model'':55B ''of'':43B ''on'':57B ''operating'':49B ''or'':51B ''other'':23B ''particular'':54B ''pip'':17B,75C ''pip-boys'':16B,74C ''products'':14B,86C ''protectron'':79C ''protectrons'':19B ''public'':33B,94C ''repairing'':50B ''robco'':1A,3A,4B,26B,67C ''robotics'':11B ''robots'':80C ''s'':60B,65B ''systems'':21B,89C ''technology'':27B ''terminals'':41B,77C ''the'':44B,58B,63B ''training'':61B ''used'':90C ''user'':59B ''with'':73C', 'robotics', 'RobCo is an electronics company associated with Pip-Boys, terminals and Protectron robots. Its branding identifies many automated products and electronic systems used in businesses and public facilities.', 'prewar,commonwealth', 'prewar electronics, robot manufacturers, computer terminals, industrial brands', 'organization', NULL, 801, 'robco_industries', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '27f0383db903dd06b7085b47e1b5296fab3dcf1613f91e1e684d893988e0eefb', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('general_atomics_international', 'General Atomics,GAI', 'General Atomics International is a manufacturer associated with nuclear technology and robots, including the Mister Handy family. Different models serve domestic, caregiving and military roles. A shared manufacturer establishes a connection between products but does not establish identical personalities or abilities. Identifying a particular robot''s equipment, duties and operating condition requires familiarity with that model or direct observation.', '''a'':11B,32B,36B,49B,69C ''abilities'':47B ''and'':17B,29B,55B,76C,82C ''are'':85C ''associated'':13B ''atomics'':2A,5A,8B,67C ''between'':38B ''but'':40B ''caregiving'':28B,81C ''condition'':57B ''connection'':37B ''different'':24B,89C ''direct'':64B ''does'':41B ''domestic'':27B,80C ''duties'':54B ''equipment'':53B ''establish'':43B ''establishes'':35B ''familiarity'':59B ''family'':23B ''for'':72C ''gai'':6A ''general'':1A,4A,7B,66C ''handy'':22B,74C ''identical'':44B ''identifying'':48B ''including'':19B ''international'':3A,9B ''is'':10B,68C ''its'':79C ''known'':71C ''manufacturer'':12B,34B,70C ''military'':30B,83C ''mister'':21B,73C ''model'':62B ''models'':25B,78C ''not'':42B ''nuclear'':15B ''observation'':65B ''operating'':56B ''or'':46B,63B ''particular'':50B ''personalities'':45B ''products'':39B,87C ''recognizable'':86C ''related'':77C ''requires'':58B ''robot'':51B ''robots'':18B,75C,84C ''roles'':31B,90C ''s'':52B ''serve'':26B ''serving'':88C ''shared'':33B ''technology'':16B ''that'':61B ''the'':20B ''with'':14B,60B', 'robotics', 'General Atomics is a manufacturer known for Mister Handy robots and related models. Its domestic, caregiving and military robots are recognizable products serving different roles.', 'prewar,commonwealth', 'robot manufacturing, prewar industry, domestic automation, nuclear technology', 'organization', NULL, 802, 'general_atomics_international', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'b15b736636befe24a703192128b7fae317ba69e3fe0cdb888d59a6e9d2c5354c', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('vault_tec', 'Vault Tec Corporation', 'Vault-Tec is a corporation associated with constructing underground Vault shelters and equipment for their residents. Its advertising promises protection from catastrophe. Vault doors, numbered jumpsuits and shelter equipment carry its distinctive branding. Public familiarity with the company and its advertised shelters gives no automatic knowledge of any particular Vault''s occupants, interior, operating instructions or private purpose.', '''a'':10B ''advertised'':47B ''advertisements'':86C ''advertising'':24B ''and'':18B,33B,45B,73C,87C ''any'':54B ''associated'':12B ''automatic'':51B ''branding'':39B,79C ''builds'':68C ''called'':71C ''carry'':36B ''catastrophe'':28B ''company'':44B ''constructing'':14B ''corporation'':5A,11B ''distinctive'':38B,78C ''doors'':30B,82C ''equipment'':19B,35B,76C ''familiarity'':41B ''for'':20B,90C ''from'':27B ''gives'':49B ''identifies'':80C ''instructions'':61B ''intended'':89C ''interior'':59B ''is'':9B ''its'':23B,37B,46B,77C ''jumpsuits'':32B,84C ''knowledge'':52B ''no'':50B ''numbered'':31B,83C ''occupants'':58B ''of'':53B ''operating'':60B ''or'':62B ''particular'':55B ''private'':63B ''products'':88C ''promises'':25B ''protection'':26B ''public'':40B ''purpose'':64B ''residents'':22B,92C ''s'':57B ''shelter'':34B,85C ''shelters'':17B,48B,70C ''supplies'':74C ''tec'':2A,4A,8B,67C ''the'':43B ''their'':21B,75C ''underground'':15B,69C ''vault'':1A,3A,7B,16B,29B,56B,66C,81C,91C ''vault-tec'':6B,65C ''vaults'':72C ''with'':13B,42B', 'prewar_culture', 'Vault-Tec builds underground shelters called Vaults and supplies their equipment. Its distinctive branding identifies Vault doors, numbered jumpsuits, shelter advertisements and products intended for Vault residents.', 'prewar,commonwealth', 'vault construction, prewar corporations, civil defense, shelter equipment', 'organization', NULL, 803, 'vault_tec', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'ceb70e59038d491e5df1bd6e63b275d6668559eecf644eb25b27ef6e9adadbf0', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('radiation', 'rads,radioactivity', 'Radiation is a persistent hazard in the Commonwealth, including contaminated areas, water and certain scavenged food. Exposure can harm people and contribute to sickness, making protective equipment and suitable medical treatment valuable. Wasteland creatures and individual people react differently to irradiation. A nearby person''s actual exposure or illness comes from observed condition, medical information or their own account rather than a general warning alone.', '''a'':6B,45B,65B ''account'':62B ''actual'':49B ''alone'':68B ''and'':16B,24B,31B,38B,75C,81C ''areas'':14B,93C ''can'':21B,70C ''certain'':17B ''comes'':53B ''commonwealth'':11B,72C ''condition'':56B ''contaminate'':71C ''contaminated'':13B ''contribute'':25B ''creatures'':37B ''danger'':89C ''differently'':42B ''equipment'':30B ''exposure'':20B,50B,86C ''food'':19B,74C ''from'':54B ''gear'':80C ''general'':66B ''harm'':22B ''hazard'':8B ''heavily'':91C ''illness'':52B ''in'':9B,90C ''including'':12B ''individual'':39B ''information'':58B ''irradiated'':92C ''irradiation'':44B ''is'':5B ''making'':28B ''manage'':85C ''medical'':33B,57B,82C ''nearby'':46B ''observed'':55B ''or'':51B,59B ''own'':61B ''particular'':88C ''people'':23B,40B ''persistent'':7B ''person'':47B ''protective'':29B,79C ''radiation'':1A,4B,69C ''radioactivity'':3A ''rads'':2A ''rather'':63B ''react'':41B ''ruins'':76C ''s'':48B ''scavenged'':18B ''sickness'':27B ''suitable'':32B ''supplies'':83C ''than'':64B ''the'':10B ''their'':60B ''to'':26B,43B,84C ''travelers'':77C ''treatment'':34B ''use'':78C ''valuable'':35B ''warning'':67B ''wasteland'':36B ''water'':15B,73C ''with'':87C', 'commonwealth_lore', 'Radiation can contaminate Commonwealth water, food and ruins. Travelers use protective gear and medical supplies to manage exposure, with particular danger in heavily irradiated areas.', 'commonwealth', 'wasteland hazards, contaminated water, radiation sickness, travel preparation', 'concept', NULL, 804, 'radiation', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c6034ad2fc1939c591c961ecdb92939d86e968d218239e103f72ff74368472f4', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('radstorms', 'radstorm,radiation storms', 'Radstorms are dangerous weather systems associated with the Glowing Sea southwest of the Commonwealth. Green haze and lightning make them visually distinct from ordinary cloudy weather. They expose unprotected travelers to radiation and can reduce visibility. Commonwealth weather also includes normal rain and fog, so a character identifies a current radstorm from the observed environment rather than assuming every storm carries radioactive fallout.', '''a'':50B,53B,86C ''also'':43B ''and'':21B,37B,47B,73C ''are'':6B ''as'':85C ''associated'':10B ''assuming'':62B ''bring'':69C ''can'':38B ''carries'':65B ''character'':51B ''cloudy'':29B ''commonwealth'':18B,41B ''current'':54B ''dangerous'':7B ''direction'':77C ''distinct'':26B,88C ''environment'':59B ''every'':63B ''expose'':32B ''fallout'':67B ''fog'':48B,93C ''from'':27B,56B,75C,89C ''glowing'':13B,80C ''green'':19B,70C ''hazard'':87C ''haze'':20B,71C ''identifies'':52B ''includes'':44B ''lightning'':22B,72C ''make'':23B ''normal'':45B ''observed'':58B ''of'':16B,78C ''or'':92C ''ordinary'':28B,90C ''radiation'':3A,36B,74C ''radioactive'':66B ''radstorm'':2A,55B ''radstorms'':1A,5B,68C ''rain'':46B,91C ''rather'':60B ''reduce'':39B ''sea'':14B,81C ''so'':49B ''southwest'':15B ''storm'':64B ''storms'':4A ''systems'':9B ''than'':61B ''the'':12B,17B,57B,76C,79C ''them'':24B,84C ''they'':31B ''to'':35B ''travelers'':34B,82C ''treat'':83C ''unprotected'':33B ''visibility'':40B ''visually'':25B ''weather'':8B,30B,42B ''with'':11B', 'commonwealth_lore', 'Radstorms bring green haze, lightning and radiation from the direction of the Glowing Sea. Travelers treat them as a hazard distinct from ordinary rain or fog.', 'commonwealth', 'radiation weather, green lightning, commonwealth storms, environmental hazards', 'concept', NULL, 805, 'radstorms', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '385c1320f64254034044e71e1740cb53dfac244da3b8d8c2901e65fffc309102', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('brahmin', 'pack brahmin', 'Brahmin are two-headed cattle central to wasteland farming and trade. People keep them for milk, meat, hides and fertilizer, while caravans use them to carry supplies. Their hardy nature makes them useful in difficult conditions. A particular animal''s owner, cargo, health and behavior are local facts established by observation; the species'' economic importance explains why settlers and traders value them.', '''a'':41B ''and'':14B,23B,48B,63B,76C,83C,87C ''animal'':43B ''animals'':78C ''are'':5B,50B,68C ''as'':74C ''behavior'':49B ''brahmin'':1A,3A,4B,67C ''by'':54B ''caravans'':26B,88C ''cargo'':46B ''carry'':30B,93C ''cattle'':9B,72C ''central'':10B ''conditions'':40B ''difficult'':39B ''economic'':58B ''established'':53B ''explains'':60B ''facts'':52B ''farming'':13B ''fertilizer'':24B,84C ''for'':19B ''goods'':95C ''hardy'':33B ''headed'':8B,71C ''health'':47B ''hides'':22B,82C ''importance'':59B ''in'':38B ''keep'':17B ''livestock'':75C ''local'':51B ''makes'':35B ''meat'':21B,81C ''milk'':20B,80C ''nature'':34B ''observation'':55B ''on'':90C ''owner'':45B ''pack'':2A,77C ''particular'':42B ''people'':16B ''rely'':89C ''s'':44B ''settlements'':86C ''settlers'':62B ''species'':57B ''supplies'':31B ''support'':85C ''the'':56B ''their'':32B,79C ''them'':18B,28B,36B,66B,91C ''to'':11B,29B,92C ''trade'':15B,94C ''traders'':64B ''two'':7B,70C ''two-headed'':6B,69C ''use'':27B ''used'':73C ''useful'':37B ''value'':65B ''wasteland'':12B ''while'':25B ''why'':61B', 'commonwealth_lore', 'Brahmin are two-headed cattle used as livestock and pack animals. Their milk, meat, hides and fertilizer support settlements, and caravans rely on them to carry trade goods.', 'commonwealth', 'wasteland livestock, caravan animals, settlement agriculture, trade supplies', 'creature', NULL, 806, 'brahmin', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '6d3ae5154b1e64da21e69ea7f326cd22dbc58942ac73bd6f6fd28bf5d646defa', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('vault_81', 'Vault Eighty-One', 'Vault 81 is an inhabited Vault-Tec shelter whose residents trade with the Commonwealth. Its ordinary living areas include an atrium, depot, cafeteria, barber, clinic, classroom and hydroponics facilities. Generations of residents have kept the shelter functioning, but obtaining supplies and repairing aging equipment remain practical concerns. Visitors encounter security and admission procedures before entering the community. Familiarity with its public trading role gives no knowledge of restricted areas or individual residents'' private affairs.', '''81'':2A,8B,83C ''a'':98C ''admission'':59B ''affairs'':81B ''aging'':50B ''an'':10B,27B,85C ''and'':34B,48B,58B,101C,106C ''areas'':25B,76B ''atrium'':28B ''barber'':31B ''before'':61B ''behind'':97C ''but'':45B ''cafeteria'':30B ''classroom'':33B ''clinic'':32B ''commonwealth'':21B,92C ''community'':64B,105C ''concerns'':54B ''depot'':29B ''door'':100C ''eighty'':5A ''eighty-one'':4A ''encounter'':56B ''entering'':62B ''equipment'':51B ''facilities'':36B ''familiarity'':65B ''functioning'':44B ''generations'':37B ''gives'':71B ''have'':40B ''hydroponics'':35B ''include'':26B ''individual'':78B ''inhabited'':11B,86C ''is'':9B,84C ''its'':22B,67B,94C ''kept'':41B ''knowledge'':73B ''live'':96C ''living'':24B ''maintain'':102C ''no'':72B ''obtaining'':46B ''of'':38B,74B ''one'':6A ''or'':77B ''ordinary'':23B ''outsiders'':93C ''own'':104C ''practical'':53B ''private'':80B ''procedures'':60B ''public'':68B ''remain'':52B ''repairing'':49B ''residents'':17B,39B,79B,95C ''restricted'':75B ''role'':70B ''security'':57B ''services'':107C ''shelter'':15B,43B,88C ''supplies'':47B ''tec'':14B ''that'':89C ''the'':20B,42B,63B ''their'':103C ''trade'':18B ''trades'':90C ''trading'':69B ''underground'':87C ''vault'':1A,3A,7B,13B,82C,99C ''vault-tec'':12B ''visitors'':55B ''whose'':16B ''with'':19B,66B,91C', 'vault81_lore', 'Vault 81 is an inhabited underground shelter that trades with Commonwealth outsiders. Its residents live behind a Vault door and maintain their own community and services.', 'commonwealth', 'inhabited vaults, vault 81 community, commonwealth trade, underground services, shelter maintenance', 'location', NULL, 826, 'vault_81', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'b62a9a61851429799a9fe64084ba7d331475b1b3b0ce1219b92997027e6988fd', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('radstag', 'radstags', 'Radstags are mutated descendants of local deer, recognizable by two heads, patchy coats and small extra forelimbs. Many move in small herds and flee from people, but cornered or aggressive animals can gore an opponent with their antlers. Travelers and hunters judge an individual animal by its behavior and condition; the general species description gives useful recognition without establishing hostility in every encounter.', '''aggressive'':32B,84C ''an'':36B,45B,83C ''and'':16B,25B,42B,51B ''animal'':47B,87C ''animals'':33B ''antlers'':40B ''are'':4B,67C ''at'':91C ''away'':79C ''be'':89C ''behavior'':50B ''but'':29B ''by'':11B,48B ''can'':34B,88C ''close'':92C ''coats'':15B ''commonwealth'':76C ''condition'':52B ''cornered'':30B,86C ''dangerous'':90C ''deer'':9B,72C ''descendants'':6B ''description'':56B ''encounter'':65B ''establishing'':61B ''every'':64B ''extra'':18B ''flee'':26B ''forelimbs'':19B ''found'':73C ''from'':27B,80C ''general'':54B ''gives'':57B ''gore'':35B ''headed'':70C ''heads'':13B ''herds'':24B ''hostility'':62B ''hunters'':43B ''in'':22B,63B,74C ''individual'':46B ''its'':49B ''judge'':44B ''local'':8B ''many'':20B,77C ''move'':21B ''mutated'':5B,71C ''of'':7B ''opponent'':37B ''or'':31B,85C ''patchy'':14B ''people'':28B,81C ''radstag'':1A ''radstags'':2A,3B,66C ''range'':93C ''recognition'':59B ''recognizable'':10B ''shy'':78C ''small'':17B,23B ''species'':55B ''the'':53B,75C ''their'':39B ''though'':82C ''travelers'':41B ''two'':12B,69C ''two-headed'':68C ''useful'':58B ''with'':38B ''without'':60B', 'commonwealth_lore', 'Radstags are two-headed mutated deer found in the Commonwealth. Many shy away from people, though an aggressive or cornered animal can be dangerous at close range.', 'commonwealth', 'mutated deer, woodland wildlife, animal behavior, commonwealth hunting', 'creature', NULL, 807, 'radstag', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'fa7cbff9123bf17e108df35153ccfe575454a3febc1779c3ee47d084017c301e', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('mirelurk', 'mirelurks', 'Mirelurks are dangerous creatures associated with shores, water and muddy ground. Common varieties have thick shells and large claws, while hunters, kings and queens differ in shape and abilities. Shelled mirelurks protect much of their body behind hard armor, with the face comparatively exposed. Their tendency to gather and conceal themselves makes caution useful near nests and coastal ruins.', '''abilities'':31B ''and'':11B,19B,25B,30B,51B,59B,71C,76C ''are'':4B,63C ''areas'':87C ''armor'':41B ''armored'':77C ''associated'':7B ''be'':80C ''behind'':39B ''body'':38B ''by'':69C ''can'':79C ''caution'':55B ''claws'':21B,72C ''coastal'':60B ''common'':14B ''comparatively'':45B ''conceal'':52B ''covered'':89C ''creatures'':6B,66C ''dangerous'':5B,64C ''differ'':27B ''difficult'':81C ''exist'':75C ''exposed'':46B ''face'':44B ''fight'':83C ''gather'':50B ''ground'':13B ''hard'':40B ''have'':16B ''hunters'':23B ''in'':28B ''kings'':24B ''large'':20B ''makes'':54B ''mirelurk'':1A ''mirelurks'':2A,3B,33B,62C ''much'':35B ''muddy'':12B ''near'':57B ''nests'':58B ''of'':36B ''often'':67C ''ones'':78C ''protect'':34B ''queens'':26B ''recognizable'':68C ''remain'':88C ''ruins'':61B ''several'':73C ''shape'':29B ''shelled'':32B ''shells'':18B,70C ''shores'':9B ''tendency'':48B ''the'':43B ''their'':37B,47B,85C ''themselves'':53B ''thick'':17B ''to'':49B,82C ''useful'':56B ''varieties'':15B,74C ''vulnerable'':86C ''water'':10B ''waterside'':65C ''when'':84C ''while'':22B ''with'':8B,42B', 'commonwealth_lore', 'Mirelurks are dangerous waterside creatures, often recognizable by shells and claws. Several varieties exist, and armored ones can be difficult to fight when their vulnerable areas remain covered.', 'commonwealth', 'coastal wildlife, armored creatures, waterside hazards, mirelurk varieties', 'creature', NULL, 808, 'mirelurk', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '3be0eb8695fc17410f9a4dc34cb58f89a86f70d4f6b04db648f060d6bb2a6381', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('bloatfly', 'bloatflies', 'Bloatflies are oversized mutated flies found around Commonwealth ruins and open country. They can rest on trees, walls and pipes before taking flight, and are frequently encountered in small groups. Their enlarged bodies rely on unusual adaptations, including a gland studied by local scientists. Travelers regard them as pests and potential threats, with the actual danger depending on the creature''s variant and behavior.', '''a'':41B,91C ''actual'':57B ''adaptations'':39B ''and'':12B,21B,26B,52B,65B,76C ''are'':4B,27B,68C ''areas'':78C ''around'':9B,74C ''as'':50B ''before'':23B ''behavior'':66B ''bloatflies'':2A,3B,67C ''bloatfly'':1A ''bodies'':35B ''by'':44B ''can'':16B ''cling'':81C ''commonly'':72C ''commonwealth'':10B ''country'':14B ''creature'':62B ''danger'':58B ''depending'':59B ''encountered'':29B ''enlarged'':34B ''familiar'':92C ''flies'':7B,71C ''flight'':25B ''found'':8B,73C ''frequently'':28B ''gather'':85C ''gland'':42B ''groups'':32B,88C ''in'':30B,86C ''including'':40B ''large'':69C ''local'':45B ''making'':89C ''may'':80C ''mutated'':6B,70C ''on'':18B,37B,60B ''open'':13B ''or'':84C ''oversized'':5B ''pest'':94C ''pests'':51B ''pipes'':22B ''potential'':53B ''regard'':48B ''rely'':36B ''rest'':17B ''ruins'':11B,75C ''s'':63B ''scientists'':46B ''small'':31B,87C ''studied'':43B ''surfaces'':83C ''taking'':24B ''the'':56B,61B ''their'':33B ''them'':49B,90C ''they'':15B,79C ''threats'':54B ''to'':82C ''travelers'':47B ''trees'':19B ''unusual'':38B ''variant'':64B ''walls'':20B ''wasteland'':93C ''with'':55B ''wooded'':77C', 'commonwealth_lore', 'Bloatflies are large mutated flies commonly found around ruins and wooded areas. They may cling to surfaces or gather in small groups, making them a familiar wasteland pest.', 'commonwealth', 'mutated insects, wasteland pests, flying creatures, commonwealth wildlife', 'creature', NULL, 809, 'bloatfly', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a70481df592fae27ff99c35cfe31003bad49b92b02138aa1350b7f44610dda1c', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('mole_rat', 'mole rats', 'Mole rats are enlarged, hairless rodents with prominent front teeth. Commonwealth specimens tunnel underground and burst through the surface to attack, making their movement difficult to follow. They can be territorial and troublesome in groups. A few wastelanders have kept trained animals, so an individual''s behavior and allegiance come from the encounter rather than the species name alone.', '''a'':40B ''allegiance'':53B ''alone'':63B ''an'':48B ''and'':19B,36B,52B,74C,82C ''animals'':46B,85C ''are'':7B,66C ''attack'':25B ''be'':34B ''behavior'':51B ''below'':72C ''burst'':20B ''can'':33B ''come'':54B ''commonwealth'':15B ''difficult'':29B ''disturbed'':80C ''emerge'':75C ''encounter'':57B ''enlarged'':8B ''few'':41B ''follow'':31B ''for'':79C ''from'':55B ''front'':13B ''ground'':73C ''groups'':39B,83C ''hairless'':9B ''have'':43B ''in'':38B ''individual'':49B ''kept'':44B ''large'':67C ''making'':26B ''mole'':1A,3A,5B,64C ''movement'':28B ''moving'':87C ''mutated'':68C ''name'':62B ''of'':84C ''prominent'':12B ''rat'':2A ''rather'':58B ''rats'':4A,6B,65C ''rodents'':10B,69C ''s'':50B ''so'':47B ''soil'':81C ''species'':61B ''specimens'':16B ''suddenly'':76C ''surface'':23B ''teeth'':14B ''territorial'':35B ''territory'':90C ''than'':59B ''that'':70C ''the'':22B,56B,60B ''their'':27B,89C ''they'':32B ''through'':21B,88C ''to'':24B,30B ''trained'':45B ''travelers'':77C ''troublesome'':37B ''tunnel'':17B,71C ''underground'':18B ''wastelanders'':42B ''watch'':78C ''when'':86C ''with'':11B', 'commonwealth_lore', 'Mole rats are large mutated rodents that tunnel below ground and emerge suddenly. Travelers watch for disturbed soil and groups of animals when moving through their territory.', 'commonwealth', 'burrowing animals, mutated rodents, underground ambushes, wasteland pests', 'creature', NULL, 810, 'mole_rat', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '590f08428e0bf2add8716ab31e0710d2be53f8b44214de23c2501aba02d9f773', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('radroach', 'radroaches,giant cockroaches', 'Radroaches are enlarged mutated cockroaches found in Commonwealth buildings and neglected spaces. They can hide behind furniture or on walls and ceilings, then leap or swarm when disturbed. Individual animals are generally less formidable than the region''s larger predators, but groups remain a hazard. Their appearance and movement offer recognition cues; exact infestation and danger come from the place being inspected.', '''a'':48B ''and'':14B,25B,52B,59B,80C ''animals'':34B ''appearance'':51B ''are'':6B,35B,68C ''around'':78C ''behind'':20B ''being'':65B ''buildings'':13B,75C ''but'':45B ''can'':18B ''ceilings'':26B ''cockroaches'':4A,9B,71C ''come'':61B ''commonwealth'':12B ''cues'':56B ''danger'':60B ''disturbed'':32B ''disturbs'':89C ''enlarged'':7B ''exact'':57B ''formidable'':38B ''found'':10B,72C ''from'':62B ''furniture'':21B,79C ''generally'':36B ''giant'':3A,69C ''groups'':46B ''hazard'':49B ''hide'':19B,77C ''in'':11B,73C ''individual'':33B ''infestation'':58B ''inspected'':66B ''larger'':43B ''leap'':28B,84C ''less'':37B ''may'':83C ''movement'':53B ''mutated'':8B,70C ''neglected'':15B,74C ''offer'':54B ''on'':23B ''or'':22B,29B,85C ''place'':64B ''predators'':44B ''radroach'':1A ''radroaches'':2A,5B,67C ''recognition'':55B ''region'':41B ''remain'':47B ''s'':42B ''something'':88C ''spaces'':16B ''surfaces'':81C ''swarm'':30B,86C ''than'':39B ''the'':40B,63B ''their'':50B ''them'':90C ''then'':27B,82C ''they'':17B,76C ''walls'':24B ''when'':31B,87C', 'commonwealth_lore', 'Radroaches are giant mutated cockroaches found in neglected buildings. They hide around furniture and surfaces, then may leap or swarm when something disturbs them.', 'commonwealth', 'mutated cockroaches, indoor pests, abandoned buildings, insect swarms', 'creature', NULL, 811, 'radroach', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '0770fa81ab579c61cce15e6aa475262cb4c4c4ca954a54180e4fa1ce23f06fef', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('stingwing', 'stingwings', 'Stingwings are large flying insects with a scorpion-like tail ending in a venomous sting. Their fast, erratic flight and repeated attacks make them difficult to handle, especially in groups. They may defend nests attached to ground or walls. Recognizing a stingwing''s shape helps explain the hazard, while the presence of a nest or an attack requires evidence from the current surroundings.', '''a'':9B,16B,44B,56B,87C ''an'':59B ''and'':23B,81C ''are'':4B,68C ''armed'':73C ''attached'':38B ''attack'':60B,79C ''attacks'':25B ''can'':78C ''commonwealth'':89C ''current'':65B ''dangerous'':88C ''defend'':36B ''difficult'':28B ''ending'':14B ''erratic'':21B,83C ''especially'':31B ''evidence'':62B ''explain'':49B ''fast'':20B,69C ''fiercely'':80C ''flight'':22B ''flying'':6B,71C ''from'':63B ''ground'':40B ''groups'':33B,77C ''handle'':30B ''hazard'':51B,91C ''helps'':48B ''in'':15B,32B ''insect'':90C ''insects'':7B,72C ''large'':5B ''like'':12B ''make'':26B ''makes'':85C ''may'':35B ''movement'':84C ''mutated'':70C ''nest'':57B ''nests'':37B ''of'':55B ''or'':41B,58B ''presence'':54B ''recognizing'':43B ''repeated'':24B ''requires'':61B ''s'':46B ''scorpion'':11B ''scorpion-like'':10B ''shape'':47B ''sting'':18B ''stingers'':76C ''stingwing'':1A,45B ''stingwings'':2A,3B,67C ''surroundings'':66B ''tail'':13B ''the'':50B,53B,64B ''their'':19B,82C ''them'':27B,86C ''they'':34B ''to'':29B,39B ''venomous'':17B,75C ''walls'':42B ''while'':52B ''with'':8B,74C', 'commonwealth_lore', 'Stingwings are fast mutated flying insects armed with venomous stingers. Groups can attack fiercely, and their erratic movement makes them a dangerous Commonwealth insect hazard.', 'commonwealth', 'venomous insects, flying predators, insect nests, commonwealth wildlife', 'creature', NULL, 812, 'stingwing', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'beabd6de8ff7711917289335b27182be26356342d3c3e97078c481fb66e74636', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('yao_guai', 'yao guai bears', 'Yao guai are large mutated descendants of American black bears. Their strength, claws and teeth make them formidable close-range predators, and wild individuals can attack people or other animals. Family groups may show bonds among themselves. Their reputation encourages travelers to keep a respectful distance, while the behavior of a particular animal remains something to assess in the actual encounter.', '''a'':50B,57B ''actual'':66B ''american'':13B ''among'':42B ''and'':19B,28B,77C,88C ''animal'':59B ''animals'':36B ''are'':8B,70C,81C ''assess'':63B ''at'':85C ''attack'':32B ''bears'':5A,15B,73C ''behavior'':55B ''black'':14B ''bonds'':41B ''can'':31B ''caution'':90C ''claws'':18B,76C ''close'':25B,86C ''close-range'':24B ''dangerous'':75C ''descendants'':11B ''deserve'':89C ''distance'':52B ''encounter'':67B ''encountered'':92C ''encourages'':46B ''family'':37B ''formidable'':23B ''groups'':38B ''guai'':2A,4A,7B,69C ''in'':64B ''individuals'':30B ''keep'':49B ''large'':9B ''make'':21B ''may'':39B ''mutated'':10B,72C ''of'':12B,56B ''or'':34B ''other'':35B ''particular'':58B ''particularly'':84C ''people'':33B ''powerful'':71C ''predators'':27B ''range'':26B,87C ''remains'':60B ''reputation'':45B ''respectful'':51B ''serious'':82C ''show'':40B ''something'':61B ''specimens'':80C ''strength'':17B ''teeth'':20B,78C ''the'':54B,65B ''their'':16B,44B ''them'':22B ''themselves'':43B ''threats'':83C ''to'':48B,62B ''travelers'':47B ''when'':91C ''while'':53B ''wild'':29B,79C ''with'':74C ''yao'':1A,3A,6B,68C', 'commonwealth_lore', 'Yao guai are powerful mutated bears with dangerous claws and teeth. Wild specimens are serious threats, particularly at close range, and deserve caution when encountered.', 'commonwealth', 'mutated bears, large predators, woodland danger, animal behavior', 'creature', NULL, 813, 'yao_guai', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '598aac265e4ddc3089950ab22d5e55176abcfb83b48012ec3b3054f62410d369', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('protectron', 'Protectrons', 'Protectrons are RobCo''s bipedal service robots, built for work such as security, construction and public assistance. Models and software personalities differ with their assigned purpose. Their compact bodies, dome-like heads and deliberate movement make them recognizable. Units can still follow old routines; an individual machine''s active instructions, identification requirements and attitude toward visitors must be learned from that unit or its controls.', '''active'':52B ''an'':48B ''and'':17B,21B,36B,56B,81C,87C ''are'':4B,70C,90C ''as'':14B ''assigned'':27B,98C ''assistance'':19B ''attitude'':57B ''be'':61B ''bipedal'':7B ''bodies'':31B ''body'':85C ''built'':10B ''can'':43B ''compact'':30B ''construction'':16B,80C ''controls'':68B ''deliberate'':37B ''differ'':24B ''dome'':33B ''dome-like'':32B ''duties'':83C ''follow'':45B,95C ''for'':11B,78C ''from'':63B ''heads'':35B ''identification'':54B ''individual'':49B ''instructions'':53B ''its'':67B ''learned'':62B ''like'':34B ''machine'':50B ''make'':39B ''models'':20B ''movement'':38B,89C ''must'':60B ''old'':46B ''or'':66B ''other'':82C ''own'':97C ''particular'':93C ''personalities'':23B ''pre'':72C ''pre-war'':71C ''protectron'':1A ''protectrons'':2A,3B,69C ''public'':18B ''purpose'':28B ''recognizable'':41B,91C ''requirements'':55B ''robco'':5B,74C ''robots'':9B,76C ''routines'':47B,99C ''s'':6B,51B ''security'':15B,79C ''service'':8B,75C ''shape'':86C ''slow'':88C ''software'':22B ''still'':44B ''such'':13B ''that'':64B ''their'':26B,29B,84C,96C ''them'':40B ''toward'':58B ''unit'':65B ''units'':42B,94C ''used'':77C ''visitors'':59B ''war'':73C ''while'':92C ''with'':25B ''work'':12B', 'robotics', 'Protectrons are pre-war RobCo service robots used for security, construction and other duties. Their body shape and slow movement are recognizable, while particular units follow their own assigned routines.', 'prewar,commonwealth', 'service robots, robco technology, automated security, robot personalities', 'robot', NULL, 814, 'protectron', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'f86f05b697a3341175be9fda7f1d3c74bac973990f3ce1b704bfbdada5135876', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('finch_farm', 'Finch homestead', 'Finch farm is a small family farm beneath a ruined section of the Boston Expressway. A cottage, water pump and cooking area support fields growing mutfruit, corn and razorgrain. The overpass is a prominent landmark above the homestead. Its surroundings include other industrial ruins and hostile groups, so working the farm also involves maintaining a safe home. Current family members, protection and improvements follow local events rather than the site''s general description.', '''a'':8B,13B,20B,37B,59B,81C,85C ''above'':40B ''also'':56B ''and'':24B,32B,49B,66B,90C,101C ''area'':26B ''beneath'':12B,94C ''boston'':18B ''cooking'':25B ''corn'':31B,100C ''cottage'':21B,89C ''crop'':91C ''cultivated'':103C ''current'':62B ''description'':77B ''elevated'':96C ''events'':70B ''expressway'':19B,87C ''family'':10B,63B,82C ''farm'':2A,6B,11B,55B,79C ''fields'':28B ''finch'':1A,3A,5B,78C ''follow'':68B ''general'':76B ''groups'':51B ''growing'':29B ''home'':61B ''homestead'':4A,42B,83C ''hostile'':50B ''improvements'':67B ''include'':45B ''industrial'':47B ''involves'':57B ''is'':7B,36B,80C ''its'':43B,88C ''landmark'':39B ''local'':69B ''maintaining'':58B ''members'':64B ''mutfruit'':30B,99C ''nearby'':104C ''of'':16B ''other'':46B ''overpass'':35B ''plots'':92C ''prominent'':38B ''protection'':65B ''pump'':23B ''rather'':71B ''razorgrain'':33B,102C ''roadway'':97C ''ruined'':14B,86C ''ruins'':48B ''s'':75B ''safe'':60B ''section'':15B ''sit'':93C ''site'':74B ''small'':9B ''so'':52B ''support'':27B ''surroundings'':44B ''than'':72B ''the'':17B,34B,41B,54B,73B,95C ''under'':84C ''water'':22B ''with'':98C ''working'':53B', 'northeast_commonwealth', 'Finch farm is a family homestead under a ruined expressway. Its cottage and crop plots sit beneath the elevated roadway, with mutfruit, corn and razorgrain cultivated nearby.', 'commonwealth', 'finch family farm, expressway homesteads, commonwealth agriculture, northeastern settlements, cultivated crops', 'location', NULL, 827, 'finch_farm', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'cb31506a05443ee0932497769e33a79b9647d86a0492ab34268da4ca526aca40', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('mister_handy', 'Mr. Handy,Mister Handies', 'Mister Handy robots were developed as general-purpose domestic and maintenance assistants by General Atomics. The familiar later design floats above the ground with multiple arms and tools for household work. Units can speak, and some show considerable initiative and personality. Related models were adapted for caregiving and military duties. Each robot''s memories, temperament and present role belong to that individual.', '''a'':73C ''above'':28B ''adapted'':52B ''and'':17B,34B,42B,47B,55B,63B,87C,96C ''arms'':33B,85C ''as'':12B ''assistants'':19B ''associated'':80C ''atomics'':22B,83C ''belong'':66B ''by'':20B ''can'':40B ''capabilities'':89C ''caregiving'':54B ''considerable'':45B ''design'':26B ''developed'':11B ''domestic'':16B ''duties'':57B ''each'':58B ''familiar'':24B ''floating'':77C ''floats'':27B ''for'':36B,53B,93C ''general'':14B,21B,82C ''general-purpose'':13B ''ground'':30B ''handies'':6A ''handy'':2A,4A,8B,71C ''household'':37B,94C ''individual'':69B ''initiative'':46B ''is'':72C ''it'':91C ''its'':84C ''later'':25B ''made'':90C ''maintenance'':18B,97C ''memories'':61B ''military'':56B ''mister'':1A,5A,7B,70C ''models'':50B ''mr'':3A ''multiple'':32B ''personality'':48B ''pre'':75C ''pre-war'':74C ''present'':64B ''purpose'':15B ''related'':49B ''robot'':59B,79C ''robots'':9B ''role'':65B ''s'':60B ''show'':44B ''some'':43B ''speak'':41B ''speech'':88C ''temperament'':62B ''that'':68B ''the'':23B,29B ''to'':67B ''tools'':35B,86C ''units'':39B ''useful'':92C ''utility'':78C ''war'':76C ''were'':10B,51B ''with'':31B,81C ''work'':38B,95C', 'robotics', 'Mister Handy is a pre-war floating utility robot associated with General Atomics. Its arms, tools and speech capabilities made it useful for household work and maintenance.', 'prewar,commonwealth', 'domestic robots, general atomics, household automation, robot individuality', 'robot', NULL, 815, 'mister_handy', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '9fc39716ac8d3ecd85a94b5563598f6a9bbbdb973149e73fd9ef8a2dc1f07869', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('assaultron', 'Assaultrons', 'Assaultrons are agile combat robots with humanoid frames. Their close-range speed and powerful head-mounted energy weapon make them far more dangerous than ordinary service machinery. Some specialized models can conceal themselves before striking. Actual units may serve different owners and roles, so recognizing the design signals capability while its present allegiance and behavior come from the encounter.', '''a'':76C,89C ''actual'':40B ''agile'':5B ''allegiance'':57B ''and'':16B,46B,58B,75C ''appears'':92C ''are'':4B,65C ''assaultron'':1A ''assaultrons'':2A,3B,64C ''attacks'':74C ''before'':38B ''behavior'':59B ''can'':35B ''capability'':53B ''caution'':86C ''close'':13B,72C ''close-range'':12B,71C ''combat'':6B,67C ''come'':60B ''conceal'':36B ''dangerous'':27B,70C ''design'':51B,84C ''different'':44B ''encounter'':63B ''energy'':21B,80C ''even'':87C ''far'':25B ''fast'':66C ''frames'':10B ''from'':61B ''head'':19B,78C ''head-mounted'':18B,77C ''humanoid'':9B ''inactive'':93C ''its'':55B ''machinery'':31B ''make'':23B ''may'':42B ''military'':83C ''models'':34B ''more'':26B ''mounted'':20B,79C ''ordinary'':29B ''owners'':45B ''particular'':90C ''powerful'':17B ''present'':56B ''range'':14B,73C ''recognizing'':49B ''robots'':7B,68C ''roles'':47B ''serve'':43B ''service'':30B ''signals'':52B ''so'':48B ''some'':32B ''specialized'':33B ''speed'':15B ''striking'':39B ''than'':28B ''the'':50B,62B ''their'':11B,82C ''them'':24B ''themselves'':37B ''unit'':91C ''units'':41B ''warrants'':85C ''weapon'':22B,81C ''when'':88C ''while'':54B ''with'':8B,69C', 'robotics', 'Assaultrons are fast combat robots with dangerous close-range attacks and a head-mounted energy weapon. Their military design warrants caution even when a particular unit appears inactive.', 'prewar,commonwealth', 'combat robots, energy weapons, robotic mobility, automated military technology', 'robot', NULL, 816, 'assaultron', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'd1be6b172420c76e6e9b4e38740466eecbe7320c71fbae53a98d0a877f61b91d', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('stimpak', 'stimpaks,stimpack', 'A stimpak is an injectable medical package used to treat injuries. Its familiar form combines a syringe with a gauge monitoring its contents. It can help with wounds and injured limbs, making it a valuable part of a traveler''s medical supplies. Treatment still depends on having and using an actual dose; an offer to help or general medical knowledge describes intent rather than an already completed intervention.', '''a'':4B,19B,22B,37B,41B ''actual'':54B,94C ''administered'':99C ''already'':69B ''an'':7B,53B,56B,68B,93C ''and'':32B,51B,89C,98C ''are'':73C ''available'':97C ''based'':76C ''can'':28B ''combines'':18B ''completed'':70B ''contents'':26B ''damaged'':90C ''depends'':48B ''describes'':64B ''dose'':55B,95C ''familiar'':16B ''for'':80C,86C ''form'':17B ''gauge'':23B ''general'':61B ''having'':50B ''healing'':81C ''help'':29B,59B ''injectable'':8B ''injured'':33B ''injuries'':14B,82C ''intent'':65B ''intervention'':71B ''is'':6B,96C ''it'':27B,36B ''its'':15B,25B ''knowledge'':63B ''limbs'':34B,91C ''making'':35B ''medical'':9B,44B,62B,77C ''monitoring'':24B ''of'':40B ''offer'':57B ''on'':49B ''or'':60B ''package'':10B ''part'':39B ''rather'':66B ''s'':43B ''still'':47B ''stimpack'':3A ''stimpak'':1A,5B ''stimpaks'':2A,72C ''supplies'':45B,78C ''syringe'':20B,75C ''syringe-based'':74C ''than'':67B ''them'':85C ''to'':12B,58B ''traveler'':42B ''travelers'':83C ''treat'':13B ''treating'':87C ''treatment'':46B ''used'':11B,79C ''using'':52B ''valuable'':38B ''value'':84C ''when'':92C ''with'':21B,30B ''wounds'':31B,88C', 'medicine', 'Stimpaks are syringe-based medical supplies used for healing injuries. Travelers value them for treating wounds and damaged limbs when an actual dose is available and administered.', 'prewar,commonwealth', 'medical supplies, injury treatment, injectable medicine, wasteland first aid', 'medicine', NULL, 817, 'stimpak', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '558d9dc0ff3b7b0385877efc3d891711e2d29ec052a219445d1fa598895ce5d7', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('fusion_core', 'fusion cores', 'Fusion cores are standardized nuclear power sources used in military and commercial equipment. Compatible cores supply power armor, generators and certain heavy energy weapons. Their portable form and shared interfaces make them valuable salvage for people who work with advanced machinery. A particular core''s charge, ownership and availability follow inspection and inventory information, while access to one alone does not establish that any device has been repaired or activated.', '''a'':46B ''access'':60B ''activated'':74B ''advanced'':44B,87C ''alone'':63B ''and'':15B,24B,32B,52B,56B,86C,96C ''any'':68B ''are'':7B,77C ''armor'':22B,85C ''associated'':82C ''availability'':53B ''be'':92C ''been'':71B ''can'':91C ''certain'':25B ''charge'':50B ''commercial'':16B ''compatible'':18B,99C ''core'':2A,48B ''cores'':4A,6B,19B,76C,90C ''device'':69B ''does'':64B ''energy'':27B ''equipment'':17B,100C ''establish'':66B ''follow'':54B ''for'':39B ''form'':31B ''fusion'':1A,3A,5B,75C ''generators'':23B ''has'':70B ''heavy'':26B ''in'':13B ''information'':58B ''inspection'':55B ''interfaces'':34B ''inventory'':57B ''machinery'':45B,88C ''make'':35B ''mechanics'':95C ''military'':14B ''not'':65B ''nuclear'':9B,79C ''one'':62B ''or'':73B ''ownership'':51B ''particular'':47B ''people'':40B ''portable'':30B,78C ''power'':10B,21B,80C,84C ''repaired'':72B ''s'':49B ''salvage'':38B ''shared'':33B ''sources'':11B,81C ''standardized'':8B ''suitable'':89C ''supply'':20B ''that'':67B ''their'':29B ''them'':36B ''to'':61B,94C ''travelers'':97C ''used'':12B ''using'':98C ''valuable'':37B,93C ''weapons'':28B ''while'':59B ''who'':41B ''with'':43B,83C ''work'':42B', 'technical_knowledge', 'Fusion cores are portable nuclear power sources associated with power armor and advanced machinery. Suitable cores can be valuable to mechanics and travelers using compatible equipment.', 'prewar,commonwealth', 'nuclear batteries, power armor, energy equipment, technical salvage', 'technology', NULL, 818, 'fusion_core', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'bdbd63965cd72a747bc9c19a2cf63a014cb367948f55556ddd4778c280eb885c', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('power_armor', 'powered armor,power armour', 'Power armor is a powered frame fitted with protective armor pieces. It enhances the wearer''s physical capabilities and uses a fusion core for power in common Commonwealth configurations. Models differ in design and protection, and damaged pieces can require repair at an appropriate station. A visible suit''s model, markings, condition and wearer come from observation; wearing it signals equipment capability rather than a guaranteed faction allegiance.', '''a'':10B,27B,52B,71B,83C ''allegiance'':74B ''an'':49B ''and'':25B,40B,42B,59B,91C ''appropriate'':50B ''armor'':2A,4A,8B,16B,76C,87C,100C ''armour'':6A ''around'':82C ''at'':48B ''body'':99C ''built'':81C ''can'':45B ''capabilities'':24B ''capability'':68B ''clothing'':97C ''come'':61B ''common'':33B ''commonwealth'':34B ''condition'':58B ''configurations'':35B ''core'':29B ''damaged'':43B ''design'':39B ''differ'':37B ''distinguish'':93C ''enhances'':19B ''equipment'':67B,80C ''faction'':73B ''fitted'':13B ''for'':30B ''frame'':12B,85C ''from'':62B,95C ''fusion'':28B ''guaranteed'':72B ''heavy'':78C ''in'':32B,38B ''is'':9B,77C ''it'':18B,65B,94C ''its'':86C ''maintenance'':92C ''markings'':57B ''model'':56B ''models'':36B ''observation'':63B ''or'':98C ''ordinary'':96C ''physical'':23B ''pieces'':17B,44B,88C ''power'':1A,5A,7B,31B,75C,89C ''powered'':3A,11B,84C ''protection'':41B ''protective'':15B,79C ''rather'':69B ''repair'':47B ''require'':46B ''s'':22B,55B ''signals'':66B ''station'':51B ''suit'':54B ''supply'':90C ''than'':70B ''the'':20B ''uses'':26B ''visible'':53B ''wearer'':21B,60B ''wearing'':64B ''with'':14B', 'technical_knowledge', 'Power armor is heavy protective equipment built around a powered frame. Its armor pieces, power supply and maintenance distinguish it from ordinary clothing or body armor.', 'prewar,commonwealth', 'powered equipment, protective armor, fusion power, armor maintenance', 'technology', NULL, 819, 'power_armor', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'adcf938cbc05b652e7f7e1389557f493f02f1a05d2419b8de4d08520e437798a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('warwick_homestead', 'Warwick farm', 'Warwick homestead occupies a former waste treatment plant adapted into a farming community. Its strong main building provides sleeping quarters, while walkways connect the old processing tanks and service structures. Fields grow tatos, mutfruit and gourds, supported by a water pump and cooking area. The surviving industrial equipment makes maintenance part of everyday work. The farm''s public layout and crops give no information about a particular resident''s identity or any hidden research arrangement.', '''a'':8B,15B,43B,70B,83C ''about'':69B ''adapted'':13B ''an'':86C ''and'':32B,39B,46B,64B,92C,102C ''any'':76B ''area'':48B ''arrangement'':79B ''at'':85C ''building'':21B ''by'':42B ''community'':17B ''connect'':27B ''cooking'':47B ''crops'':65B ''equipment'':52B ''everyday'':57B ''farm'':4A,60B,84C ''farming'':16B ''fields'':35B,91C ''former'':9B ''give'':66B ''gourds'':40B ''grow'':36B ''hidden'':77B ''homestead'':2A,6B,81C ''identity'':74B ''industrial'':51B,104C ''information'':68B ''into'':14B ''is'':82C ''its'':18B ''large'':99C ''layout'':63B ''living'':93C ''main'':20B ''maintenance'':54B ''makes'':53B ''mutfruit'':38B ''no'':67B ''occupies'':7B ''of'':56B ''old'':29B,87C ''or'':75B ''part'':55B ''particular'':71B ''plant'':12B,90C ''processing'':30B,100C ''provides'':22B ''public'':62B ''pump'':45B ''quarters'':24B,94C ''research'':78B ''resident'':72B ''s'':61B,73B ''service'':33B ''share'':95C ''site'':97C ''sleeping'':23B ''strong'':19B ''structures'':34B ''supported'':41B ''surviving'':50B,103C ''tanks'':31B,101C ''tatos'':37B ''the'':28B,49B,59B,96C ''treatment'':11B,89C ''walkways'':26B,105C ''warwick'':1A,3A,5B,80C ''waste'':10B,88C ''water'':44B ''while'':25B ''with'':98C ''work'':58B', 'southern_commonwealth', 'Warwick homestead is a farm at an old waste treatment plant. Fields and living quarters share the site with large processing tanks and surviving industrial walkways.', 'commonwealth', 'warwick family farm, treatment plant settlement, southern commonwealth, industrial homesteads, cultivated fields', 'location', NULL, 828, 'warwick_homestead', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'f1af8ad5ddebe5dbeee98a2fc2cff3c5efa68f53699b35d30b9cbdeba04ce904', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('radaway', 'Rad-Away', 'RadAway is a medical supply used to remove accumulated radiation from the body. In the Commonwealth it is valuable to people exposed to contaminated food, water or dangerous areas. It treats existing exposure rather than serving as ordinary protective clothing. A person''s need for treatment and the result of using a dose depend on their actual condition and what has happened in the present encounter.', '''a'':7B,45B,56B,80C ''accumulated'':13B,78C ''actual'':61B ''after'':87C ''already'':77C ''and'':51B,63B ''areas'':33B,95C ''as'':41B ''away'':4A ''body'':17B,83C ''clothing'':44B ''commonwealth'':20B ''condition'':62B ''contaminated'':28B ''dangerous'':32B ''depend'':58B ''dose'':57B ''encounter'':70B ''existing'':36B ''exposed'':26B ''exposure'':37B,88C ''food'':29B,92C ''for'':49B ''from'':15B ''happened'':66B ''has'':65B ''hazardous'':94C ''in'':18B,67B,79C ''is'':6B,22B,72C ''it'':21B,34B,86C ''medical'':8B ''need'':48B ''of'':54B ''on'':59B ''or'':31B,93C ''ordinary'':42B ''people'':25B ''person'':46B,81C ''present'':69B ''protective'':43B ''rad'':3A ''rad-away'':2A ''radaway'':1A,5B,71C ''radiation'':14B,76C ''radioactive'':90C ''rather'':38B ''reduce'':75C ''remove'':12B ''result'':53B ''s'':47B,82C ''serving'':40B ''supply'':9B ''than'':39B ''the'':16B,19B,52B,68B ''their'':60B ''to'':11B,24B,27B,74C,89C ''travelers'':84C ''treatment'':50B ''treats'':35B ''used'':10B,73C ''using'':55B ''valuable'':23B ''value'':85C ''water'':30B,91C ''what'':64B', 'medicine', 'RadAway is used to reduce radiation already accumulated in a person''s body. Travelers value it after exposure to radioactive water, food or hazardous areas.', 'prewar,commonwealth', 'radiation treatment, medical supplies, exposure recovery, wasteland first aid', 'medicine', NULL, 820, 'radaway', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '6770cbbbdd07ec588690d56fc84034a66d6d5bcd9023f68e63ef502debb582a6', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('commonwealth_farming', 'farm work,settlement farming,farmer', 'Commonwealth farming supports households, settlement markets and caravans. Farmers tend crops such as tatos, mutfruit, corn, carrots, gourds, melons and razorgrain, while some sites specialize in greenhouse growing or tarberries. Crops need people to work them and a reliable water supply; livestock can contribute fertilizer. Field work, harvesting and protecting food supplies give farming communities a reason to cooperate. A farm''s present harvest, workers and trading arrangements depend on its actual condition.', '''a'':45B,63B,67B ''actual'':79B ''and'':14B,27B,44B,56B,73B,87C,98C,105C ''arrangements'':75B ''as'':20B ''can'':50B ''caravans'':15B ''carrots'':24B,95C ''commonwealth'':1A,8B,85C ''communities'':62B ''condition'':80B ''contribute'':51B ''cooperate'':66B ''corn'':23B,94C ''crops'':18B,38B,90C ''depend'':76B ''familiar'':89C ''farm'':3A,68B ''farmer'':7A ''farmers'':16B,100C ''farming'':2A,6A,9B,61B ''farms'':81C ''fertilizer'':52B ''field'':53B ''food'':58B,83C ''for'':84C ''give'':60B ''gourds'':25B,96C ''greenhouse'':34B ''grow'':82C ''growing'':35B ''harvest'':71B ''harvesting'':55B ''households'':11B ''in'':33B ''include'':91C ''its'':78B ''labor'':104C ''livestock'':49B ''markets'':13B ''melons'':26B,97C ''mutfruit'':22B,93C ''need'':39B ''on'':77B,102C ''or'':36B ''people'':40B ''present'':70B ''protecting'':57B ''protection'':106C ''razorgrain'':28B,99C ''reason'':64B ''reliable'':46B ''rely'':101C ''s'':69B ''settlement'':5A,12B ''settlements'':86C ''sites'':31B ''some'':30B ''specialize'':32B ''such'':19B ''supplies'':59B ''supply'':48B ''supports'':10B ''tarberries'':37B ''tatos'':21B,92C ''tend'':17B ''them'':43B ''to'':41B,65B ''trade'':88C ''trading'':74B ''water'':47B,103C ''while'':29B ''work'':4A,42B,54B ''workers'':72B', 'farmer,agriculture', 'Farms grow food for Commonwealth settlements and trade. Familiar crops include tatos, mutfruit, corn, carrots, gourds, melons and razorgrain. Farmers rely on water, labor and protection.', 'commonwealth', 'commonwealth agriculture, crop cultivation, settlement food, farm labor, agricultural livelihoods', 'concept', NULL, 821, 'commonwealth_farming', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '9bbf6cb81d7bcebbf7190eadd4da4f873572a61384d1132cfa94a8ed1088d6ff', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('commonwealth_caravan_trade', 'caravan trading,traveling merchants,trader', 'Commonwealth merchants buy and sell supplies through settlement shops and traveling caravans. Diamond City and Bunker Hill provide established markets, while roadside traders connect smaller communities. Old Man Stockton''s Bunker Hill caravan business is a prominent trading operation. Traveling merchants may specialize in weapons, armor, medical aid or general goods, with guards and pack brahmin accompanying the familiar caravans. Stock and available caps vary, so reputation alone does not establish what a merchant has today.', '''a'':44B,81B ''accompanying'':65B ''aid'':56B ''alone'':76B ''and'':12B,18B,23B,62B,70B,86C,97C,103C ''armor'':54B ''around'':91C ''available'':71B ''brahmin'':64B,104C ''bunker'':24B,39B ''business'':42B ''buy'':11B ''caps'':72B ''caravan'':2A,4A,41B ''caravans'':20B,68B,85C,99C ''carrying'':105C ''city'':22B ''commonwealth'':1A,9B,93C ''communities'':34B ''connect'':32B ''diamond'':21B ''does'':77B ''establish'':79B ''established'':27B ''familiar'':67B,98C ''general'':58B ''goods'':59B,90C ''guards'':61B,102C ''has'':83B ''hill'':25B,40B ''in'':52B ''is'':43B ''man'':36B ''markets'':28B ''may'':50B ''medical'':55B ''merchant'':82B ''merchants'':7A,10B,49B ''move'':89C ''not'':78B ''old'':35B ''operation'':47B ''or'':57B ''pack'':63B ''prominent'':45B ''provide'':26B ''reputation'':75B ''roadside'':30B ''s'':38B ''sell'':13B,95C ''settlement'':16B,87C ''shops'':17B,88C ''smaller'':33B ''so'':74B ''specialize'':51B ''stock'':69B,107C ''stockton'':37B ''supplies'':14B,96C ''the'':66B,92C ''their'':106C ''through'':15B ''today'':84B ''trade'':3A ''trader'':8A ''traders'':31B,94C ''trading'':5A,46B ''travel'':100C ''traveling'':6A,19B,48B ''vary'':73B ''weapons'':53B ''what'':80B ''while'':29B ''with'':60B,101C', 'trader,caravan_trade', 'Caravans and settlement shops move goods around the Commonwealth. Traders sell supplies, and familiar caravans travel with guards and brahmin carrying their stock.', 'commonwealth', 'commonwealth trade, caravan routes, traveling merchants, settlement markets, brahmin transport', 'concept', NULL, 822, 'commonwealth_caravan_trade', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'd5d0c57e28279b12a6639f61206ae49e00f108b9cda64c710a1439242fa1e11b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('commonwealth_doctors', 'wasteland doctor,medical services,doctor', 'Doctors in the Commonwealth offer treatment for injuries, radiation exposure and chemical addiction. Many also sell medical supplies. Established communities can support clinics, while traveling doctors bring services to people along caravan routes. The service offered depends on the practitioner and available facilities; a trade in medicine alone does not prove the seller is a trained physician. Diagnosis, treatment and payment require an actual encounter rather than merely recognizing a clinic or medical item.', '''a'':51B,62B,77B ''actual'':71B ''addiction'':20B,89C ''alone'':55B ''along'':38B ''also'':22B,99C ''an'':70B ''and'':18B,48B,67B,88C,91C,96C ''available'':49B ''bring'':34B ''can'':28B ''caravan'':39B ''care'':95C ''chemical'':19B ''clinic'':78B ''clinics'':30B,90C ''commonwealth'':1A,11B,82C ''communities'':27B ''depends'':44B ''diagnosis'':65B ''doctor'':4A,7A ''doctors'':2A,8B,33B,83C,98C ''does'':56B ''encounter'':72B ''established'':26B ''exposure'':17B,87C ''facilities'':50B ''for'':14B ''in'':9B,53B ''injuries'':15B,85C ''is'':61B ''item'':81B ''many'':21B,97C ''medical'':5A,24B,80B,101C ''medicine'':54B ''merely'':75B ''not'':57B ''offer'':12B ''offered'':43B ''on'':45B ''or'':79B ''payment'':68B ''people'':37B ''physician'':64B ''physicians'':93C ''practitioner'':47B ''prove'':58B ''provide'':94C ''radiation'':16B,86C ''rather'':73B ''recognizing'':76B ''require'':69B ''routes'':40B ''sell'':23B,100C ''seller'':60B ''service'':42B ''services'':6A,35B ''supplies'':25B,102C ''support'':29B ''than'':74B ''the'':10B,41B,46B,59B ''to'':36B ''trade'':52B ''trained'':63B ''traveling'':32B,92C ''treat'':84C ''treatment'':13B,66B ''wasteland'':3A ''while'':31B', 'medicine,doctor', 'Commonwealth doctors treat injuries, radiation exposure and addiction. Clinics and traveling physicians provide care, and many doctors also sell medical supplies.', 'commonwealth', 'commonwealth clinics, wasteland medicine, traveling doctors, radiation treatment, medical services', 'concept', NULL, 823, 'commonwealth_doctors', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '611e2b70b04696f14ef7a9eb7fd18f223448e56e35981a2165ce7f9acadc9032', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('power_armor_maintenance', 'armor servicing,power armor station,mechanic work', 'Power armor maintenance uses a station that supports a powered frame while armor sections are inspected, repaired or modified. Commonwealth stations include a large box-frame style with hydraulic supports and a smaller two-strut style. Suitable armor parts, materials and technical skill determine which work can be done. Repair and modification are separate from supplying a fusion core; having a station nearby does not mean a damaged suit has already been serviced.', '''a'':15B,19B,33B,43B,69B,73B,79B,97C ''able'':107C ''already'':83B ''and'':42B,53B,63B,92C,105C ''are'':25B,65B ''armor'':2A,4A,7A,12B,23B,50B,87C,94C ''be'':60B ''been'':84B ''box'':36B ''box-frame'':35B ''can'':59B ''commonwealth'':30B ''core'':71B ''damaged'':80B ''determine'':56B ''does'':76B ''done'':61B ''fitted'':95C ''frame'':21B,37B,99C ''from'':67B ''fusion'':70B ''has'':82B ''having'':72B ''help'':89C ''hydraulic'':40B ''include'':32B ''inspected'':26B ''large'':34B ''maintenance'':3A,13B ''materials'':52B,104C ''mean'':78B ''mechanic'':9A ''mechanics'':90C ''modification'':64B ''modified'':29B ''modify'':93C ''nearby'':75B ''not'':77B ''or'':28B ''parts'':51B,103C ''perform'':109C ''power'':1A,6A,11B,86C ''powered'':20B,98C ''repair'':62B,91C ''repaired'':27B ''repairs'':100C ''require'':101C ''sections'':24B ''separate'':66B ''serviced'':85B ''servicing'':5A ''skill'':55B ''smaller'':44B ''someone'':106C ''station'':8A,16B,74B ''stations'':31B,88C ''strut'':47B ''style'':38B,48B ''suit'':81B ''suitable'':49B,102C ''supplying'':68B ''supports'':18B,41B ''technical'':54B ''that'':17B ''the'':110C ''to'':96C,108C ''two'':46B ''two-strut'':45B ''uses'':14B ''which'':57B ''while'':22B ''with'':39B ''work'':10A,58B,111C', 'technical_knowledge,mechanic', 'Power armor stations help mechanics repair and modify armor fitted to a powered frame. Repairs require suitable parts, materials and someone able to perform the work.', 'prewar,commonwealth', 'power armor servicing, armor repair stations, mechanical maintenance, powered frames, armor components', 'technology', NULL, 824, 'power_armor_maintenance', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '44b68fd227c6a62196045c3441311cf3b6aab43b7710d7cf54c0ebfebcd30a4d', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('commonwealth_militia_service', 'militia duty,settlement soldier,minuteman service', 'The Commonwealth Minutemen grew from settlers volunteering to defend their homes and neighboring communities. Militia service joins ordinary settlement life with armed protection, rather than separating every fighter into a permanent professional army. Responding to threatened homesteads, helping communities prepare defenses and supporting other settlers are central to the group''s purpose. A particular unit''s strength, orders, patrols and allegiance depend on present events and cannot be inferred from the Minutemen''s older reputation.', '''a'':39B,62B,88C ''allegiance'':70B ''and'':21B,51B,69B,75B,94C ''are'':55B ''armed'':31B ''arms'':101C ''army'':42B ''as'':87C ''be'':77B ''can'':98C ''cannot'':76B ''central'':56B ''commonwealth'':1A,11B,92C ''communities'':23B,48B ''danger'':107C ''defend'':18B ''defenses'':50B ''depend'':71B ''duty'':5A ''events'':74B ''every'':36B ''fighter'':37B ''from'':14B,79B ''grew'':13B ''group'':59B ''help'':103C ''helping'':47B ''homes'':20B,93C ''homesteads'':46B ''inferred'':78B ''into'':38B ''joins'':26B ''life'':29B ''militia'':2A,4A,24B,90C ''minuteman'':8A ''minutemen'':12B,81B,85C ''neighboring'':22B ''neighbors'':105C ''older'':83B ''on'':72B ''orders'':67B ''ordinary'':27B,96C ''other'':53B ''particular'':63B ''patrols'':68B ''permanent'':40B ''prepare'':49B ''present'':73B ''professional'':41B ''protecting'':91C ''protection'':32B ''purpose'':61B ''rather'':33B ''reputation'':84B ''responding'':43B ''s'':60B,65B,82B ''separating'':35B ''serve'':86C ''service'':3A,9A,25B ''settlement'':6A,28B ''settlements'':95C ''settlers'':15B,54B,97C ''soldier'':7A ''strength'':66B ''supporting'':52B ''take'':99C ''than'':34B ''the'':10B,58B,80B ''their'':19B,104C ''threatened'':45B ''threatens'':108C ''to'':17B,44B,57B,102C ''unit'':64B ''up'':100C ''volunteer'':89C ''volunteering'':16B ''when'':106C ''with'':30B', 'minutemen,soldier', 'Minutemen serve as a volunteer militia protecting Commonwealth homes and settlements. Ordinary settlers can take up arms to help their neighbors when danger threatens.', 'commonwealth', 'commonwealth militia, settlement defense, volunteer soldiers, minutemen service, neighboring communities', 'concept', NULL, 825, 'commonwealth_militia_service', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a73c5ed34216fc815435cc22c4c64369e2aa78088c5681f0967288371193df4a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('tenpines_bluff', 'Ten Pines Bluff', 'Tenpines Bluff is a small northern Commonwealth farm with a shack, a tato field, a water pump and a cooking station. A damaged house stands near the cultivated area. Its modest buildings and crop work make it a recognizable rural homestead rather than a large fortified town. Traveling armor merchant Lucas Miller has included it among his stops. Present occupants, caravan arrivals and any rebuilding depend on the current settlement state.', '''a'':9B,15B,17B,20B,24B,27B,43B,49B,80C,87C,94C ''among'':61B ''and'':23B,38B,68B,89C,100C ''any'':69B ''area'':34B,102C ''armor'':54B ''arrivals'':67B ''beside'':93C ''bluff'':2A,5A,7B,78C ''buildings'':37B ''caravan'':66B ''commonwealth'':12B,86C ''cooking'':25B,101C ''crop'':39B ''cultivated'':33B,95C ''current'':74B ''damaged'':28B,90C ''depend'':71B ''farm'':13B,82C ''field'':19B,97C ''fortified'':51B ''has'':58B ''his'':62B ''homestead'':46B ''house'':29B,91C ''in'':83C ''included'':59B ''is'':8B,79C ''it'':42B,60B ''its'':35B ''large'':50B ''lucas'':56B ''make'':41B ''merchant'':55B ''miller'':57B ''modest'':36B ''near'':31B ''northern'':11B,85C ''occupants'':65B ''on'':72B ''pines'':4A ''present'':64B ''pump'':22B,99C ''rather'':47B ''rebuilding'':70B ''recognizable'':44B ''rural'':45B ''settlement'':75B ''shack'':16B,88C ''small'':10B,81C ''stand'':92C ''stands'':30B ''state'':76B ''station'':26B ''stops'':63B ''tato'':18B,96C ''ten'':3A ''tenpines'':1A,6B,77C ''than'':48B ''the'':32B,73B,84C ''town'':52B ''traveling'':53B ''water'':21B,98C ''with'':14B ''work'':40B', 'northwest_commonwealth', 'Tenpines Bluff is a small farm in the northern Commonwealth. A shack and damaged house stand beside a cultivated tato field, water pump and cooking area.', 'commonwealth', 'tenpines farm, northern commonwealth, rural homesteads, tato cultivation, caravan stops', 'location', NULL, 829, 'tenpines_bluff', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '42435c1e7103d23b63e01050a9493d651c54531e0d3cf7a27bf22985be799a6b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('county_crossing', 'County Crossing farm', 'County crossing is a small mutfruit farm near the National Guard training yard and an old nuclear power station. The homestead includes a shack, a roofless ruined house, a water pump and cooking facilities. A small pond occupies part of the area, and Doc Weathers has used the settlement as a caravan stop. Industrial and military ruins shape the surrounding landscape. The current harvest, travelers and nearby threats require recent local knowledge.', '''a'':9B,28B,30B,34B,40B,56B,81C,95C ''an'':20B ''and'':19B,37B,48B,60B,71B,89C,99C ''area'':47B,101C ''as'':55B ''beside'':102C ''caravan'':57B ''commonwealth'':83C ''cooking'':38B,100C ''county'':1A,3A,6B,78C ''crossing'':2A,4A,7B,79C ''current'':68B ''doc'':49B ''facilities'':39B,91C ''farm'':5A,12B,85C ''fields'':104C ''guard'':16B ''harvest'':69B ''has'':51B ''homestead'':26B ''house'':33B ''includes'':27B ''industrial'':59B ''is'':8B,80C ''its'':92C ''knowledge'':77B ''landscape'':66B ''local'':76B ''military'':61B,88C ''mutfruit'':11B,84C ''national'':15B ''near'':13B,86C ''nearby'':72B ''nuclear'':22B ''occupies'':43B ''of'':45B ''old'':21B,87C ''part'':44B ''pond'':42B ''power'':23B,90C ''pump'':36B,98C ''recent'':75B ''require'':74B ''roofless'':31B ''ruined'':32B ''ruins'':62B ''settlement'':54B ''settlers'':93C ''shack'':29B,96C ''shape'':63B ''small'':10B,41B,82C ''station'':24B ''stop'':58B ''surrounding'':65B ''the'':14B,25B,46B,53B,64B,67B,103C ''threats'':73B ''training'':17B ''travelers'':70B ''use'':94C ''used'':52B ''water'':35B,97C ''weathers'':50B ''yard'':18B', 'northeast_commonwealth', 'County crossing is a small Commonwealth mutfruit farm near old military and power facilities. Its settlers use a shack, water pump and cooking area beside the fields.', 'commonwealth', 'county crossing farm, mutfruit cultivation, northeastern commonwealth, military ruins, caravan stops', 'location', NULL, 830, 'county_crossing', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c4a69f57a63c99e0131d5a94610cdab495d14660a23f5aa214406e999e0915db', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('jamaica_plain', 'Jamaica Plain ruins', 'Jamaica Plain is a ruined town in the southern Commonwealth, southwest of University Point and northwest of Quincy. Surviving buildings include a church and town hall among damaged homes and streets. Its reputation combines dangerous feral ghoul activity with stories of pre-war treasures, which have attracted scavengers and treasure hunters. Such stories are rumors about the site''s appeal; they establish neither the contents of an unopened room nor the fate of a particular expedition.', '''a'':9B,27B,80B,86C,92C ''about'':62B,104C ''activity'':43B ''among'':32B ''an'':73B ''and'':20B,29B,35B,55B,94C,102C ''appeal'':66B ''are'':60B ''attracted'':53B ''buildings'':25B ''church'':28B,93C ''combines'':39B ''commonwealth'':15B,89C ''contents'':71B ''damaged'':33B ''dangerous'':40B,100C ''establish'':68B ''expedition'':82B ''fate'':78B ''feral'':41B ''ghoul'':42B ''hall'':31B,96C ''have'':52B ''homes'':34B ''hunters'':57B ''in'':12B ''include'':26B ''is'':8B,85C ''its'':37B,99C ''jamaica'':1A,3A,6B,83C ''know'':98C ''neither'':69B ''nor'':76B ''northwest'':21B ''of'':17B,22B,46B,72B,79B ''particular'':81B ''plain'':2A,4A,7B,84C ''point'':19B ''pre'':48B,106C ''pre-war'':47B,105C ''quincy'':23B ''reputation'':38B,101C ''room'':75B ''ruined'':10B,87C ''ruins'':5A ''rumors'':61B ''s'':65B ''scavengers'':54B ''site'':64B ''southern'':14B,88C ''southwest'':16B ''stories'':45B,59B,103C ''streets'':36B ''such'':58B ''surviving'':24B ''the'':13B,63B,70B,77B ''they'':67B ''town'':11B,30B,90C,95C ''travelers'':97C ''treasure'':56B ''treasures'':50B,108C ''university'':18B ''unopened'':74B ''war'':49B,107C ''which'':51B ''with'':44B,91C', 'southern_commonwealth', 'Jamaica Plain is a ruined southern Commonwealth town with a church and town hall. Travelers know its dangerous reputation and stories about pre-war treasures.', 'commonwealth', 'jamaica plain ruins, southern commonwealth, ruined town hall, commonwealth landmarks, feral ghoul danger', 'location', NULL, 831, 'jamaica_plain', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '023639ef2a52a51becb7b6f63ddb36e5a495b0325b50281509beb1efd1987cd4', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('red_rocket_truck_stop', 'Concord Red Rocket,Sanctuary Red Rocket', 'The Red Rocket truck stop between Sanctuary Hills and Concord is a former coolant replenishment and vehicle service station. Its garage and distinctive Red Rocket branding make it easy to recognize from the road. A workshop and power armor station survive inside, with other crafting facilities around the building. These tools offer opportunities for maintenance and reuse if the site is secured. Its present owner, residents and working equipment depend on what has actually happened there.', '''a'':22B,45B,95C,112C ''actually'':84B ''and'':19B,26B,32B,47B,66B,77B,101C,107C ''armor'':49B ''around'':57B ''as'':94C ''between'':16B,98C ''branding'':36B,110C ''building'':59B ''built'':93C ''concord'':5A,20B,102C ''coolant'':24B,105C ''crafting'':55B ''depend'':80B ''distinctive'':33B ''easy'':39B ''equipment'':79B ''facilities'':56B ''familiar'':108C ''for'':64B ''form'':111C ''former'':23B ''from'':42B ''garage'':31B,104C ''happened'':85B ''has'':83B ''hills'':18B,100C ''if'':68B ''inside'':52B ''is'':21B,71B ''it'':38B ''its'':30B,73B,103C ''landmark'':114C ''maintenance'':65B ''make'':37B ''offer'':62B ''on'':81B ''opportunities'':63B ''other'':54B ''owner'':75B ''power'':48B ''present'':74B ''pumps'':106C ''recognize'':41B ''red'':1A,6A,9A,12B,34B,88C ''replenishment'':25B ''residents'':76B ''reuse'':67B ''road'':44B ''roadside'':113C ''rocket'':2A,7A,10A,13B,35B,89C,109C ''sanctuary'':8A,17B,99C ''secured'':72B ''service'':28B,96C ''site'':70B ''station'':29B,50B,97C ''stop'':4A,15B,91C ''survive'':51B ''the'':11B,43B,58B,69B,87C ''there'':86B ''these'':60B ''to'':40B ''tools'':61B ''truck'':3A,14B,90C ''vehicle'':27B ''was'':92C ''what'':82B ''with'':53B ''working'':78B ''workshop'':46B', 'northwest_commonwealth', 'The Red Rocket truck stop was built as a service station between Sanctuary Hills and Concord. Its garage, coolant pumps and familiar rocket branding form a roadside landmark.', 'prewar,commonwealth', 'red rocket garage, concord roadside services, northwest commonwealth, vehicle service stations, workshop equipment', 'location', NULL, 832, 'red_rocket_truck_stop', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '3702800bc503e6545d822b33ef06a499832e61e244dcdb169578fff2c0d1f9ba', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_island', 'Mount Desert Island,Island of Far Harbor', 'The Island is the former Mount Desert Island off the coast of Maine. Woods, marshes, rocky shores and surviving tourist or industrial buildings share a cold, wet landscape affected by radioactive Fog. Far Harbor is a coastal landing point, Acadia stands inland on high ground, and the Nucleus occupies an old submarine facility. Offshore rocks and wrecks make navigation difficult; experienced crews and suitable guidance are valuable. Conditions and safe routes can change with the Fog.', '''a'':34B,45B ''acadia'':49B,103C ''affected'':38B ''an'':59B ''and'':27B,55B,65B,72B,78B,91C,98C,104C ''are'':75B,107C ''buildings'':32B,97C ''by'':39B ''can'':81B ''change'':82B ''coast'':20B ''coastal'':46B ''cold'':35B ''communities'':110C ''conditions'':77B ''covered'':93C ''crews'':71B ''desert'':4A,16B ''difficult'':69B ''experienced'':70B ''facility'':62B ''far'':8A,42B,101C ''fog'':41B,85B,100C ''former'':14B ''ground'':54B ''guidance'':74B ''harbor'':9A,43B,102C ''high'':53B ''in'':94C ''industrial'':31B ''inland'':51B ''is'':12B,44B,92C ''island'':2A,5A,6A,11B,17B,87C ''its'':108C ''landing'':47B ''landscape'':37B ''lies'':88C ''main'':109C ''maine'':22B,90C ''make'':67B ''marshes'':24B ''mount'':3A,15B ''navigation'':68B ''nucleus'':57B,106C ''occupies'':58B ''of'':7A,21B ''off'':18B,89C ''offshore'':63B ''old'':60B ''on'':52B ''or'':30B ''point'':48B ''radioactive'':40B,99C ''rocks'':64B ''rocky'':25B ''routes'':80B ''ruined'':96C ''safe'':79B ''share'':33B ''shores'':26B ''stands'':50B ''submarine'':61B ''suitable'':73B ''surviving'':28B ''the'':1A,10B,13B,19B,56B,84B,86C,105C ''tourist'':29B ''valuable'':76B ''wet'':36B ''with'':83B ''woods'':23B,95C ''wrecks'':66B', 'far_harbor_lore,far_harbor_navigation', 'The Island lies off Maine and is covered in woods, ruined buildings and radioactive Fog. Far Harbor, Acadia and the Nucleus are its main communities.', 'far_harbor', 'mount desert island, maine coastal geography, far harbor region, island navigation, radioactive fog', 'location', NULL, 833, 'the_island', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'ad1127f8d035b6a1348038a13f37b1976dca9b838c44299056626b8e79b026fa', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('far_harbor', 'Far Harbor town,Bar Harbor', 'Far Harbor is a waterfront community in the remains of Bar Harbor on the Island''s northeast shore. Island families driven from inland homesteads by the Fog gathered on the Mariner''s pier. The fortified wall called the Hull protects the town from creatures, while Fog condensers make the immediate area habitable. Trading, fishing, lodging and medical care support daily life. Its people value maritime traditions and often approach mainland visitors cautiously; present leadership and relations follow local events.', '''a'':11B,90C,103C ''and'':63B,74B,82B,99C,108C ''approach'':76B ''area'':58B ''back'':114C ''bar'':6A,18B ''behind'':102C ''by'':32B ''called'':44B,105C ''care'':65B ''cautiously'':79B ''coastal'':91C ''community'':13B ''condensers'':54B ''creatures'':51B ''daily'':67B ''driven'':28B ''events'':86B ''families'':27B ''far'':1A,3A,8B,87C ''fishing'':61B ''fog'':34B,53B,113C ''follow'':84B ''fortified'':42B ''from'':29B,50B ''gathered'':35B ''habitable'':59B ''harbor'':2A,4A,7A,9B,19B,88C ''homes'':98C ''homesteads'':31B ''hull'':46B,107C ''immediate'':57B ''in'':14B ''inland'':30B ''is'':10B,89C ''island'':22B,26B,95C ''its'':69B,96C ''keep'':111C ''leadership'':81B ''life'':68B ''local'':85B ''lodging'':62B ''machines'':109C ''mainland'':77B ''make'':55B ''mariner'':38B ''maritime'':72B ''medical'':64B ''northeast'':24B ''of'':17B ''often'':75B ''on'':20B,36B,93C ''people'':70B ''pier'':40B ''present'':80B ''protects'':47B ''relations'':83B ''remains'':16B ''s'':23B,39B ''shelter'':101C ''shops'':100C ''shore'':25B ''support'':66B ''that'':110C ''the'':15B,21B,33B,37B,41B,45B,48B,56B,94C,106C,112C ''town'':5A,49B,92C ''trading'':60B ''traditions'':73B ''value'':71B ''visitors'':78B ''wall'':43B,104C ''waterfront'':12B,97C ''while'':52B', 'far_harbor_lore', 'Far Harbor is a coastal town on the Island. Its waterfront homes and shops shelter behind a wall called the Hull and machines that keep the Fog back.', 'far_harbor', 'far harbor town, coastal landing point, harbormen community, hull defenses, island commerce', 'location', NULL, 834, 'far_harbor', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '8fbe35f57c568943be994d307fbfb82a7cfae273133766bb8286f7d5d33b4ff5', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('harbormen', 'Far Harbor islanders,Harbor folk', 'The harbormen are the Island''s local people, with customs rooted in fishing, sailing and independent homesteads. They traditionally respect a community captain and prize endurance and practical service. Many families have been displaced by the Fog, concentrating their lives around Far Harbor''s pier. Boats, fishing gear and familiar ballistic weapons suit their way of life. Suspicion of mainlanders and conflict with the Children of Atom are part of their public reputation, though individual attitudes differ.', '''a'':27B ''and'':21B,30B,33B,55B,67B,91C,98C,103C ''are'':9B,74B,85C ''around'':47B ''atom'':73B ''attitudes'':82B ''ballistic'':57B ''be'':105C ''been'':39B ''boats'':52B ''by'':41B ''can'':104C ''captain'':29B ''children'':71B ''community'':28B,102C ''concentrating'':44B ''conflict'':68B ''customs'':16B ''differ'':83B ''displaced'':40B ''endurance'':32B ''familiar'':56B ''families'':37B ''far'':2A,48B ''fishing'':19B,53B,90C ''fog'':43B ''folk'':6A ''gear'':54B ''harbor'':3A,5A,49B ''harbormen'':1A,8B,84C ''have'':38B ''homesteading'':92C ''homesteads'':23B ''in'':18B ''independence'':96C ''independent'':22B ''individual'':81B ''island'':11B,87C ''islanders'':4A ''life'':63B ''lives'':46B ''local'':13B,89C ''mainlanders'':66B,109C ''many'':36B ''of'':62B,65B,72B,76B,107C ''part'':75B ''people'':14B,93C ''pier'':51B ''practical'':34B ''prize'':31B ''public'':78B ''reputation'':79B ''respect'':26B ''rooted'':17B ''s'':12B,50B,88C ''sailing'':20B ''service'':35B,99C ''suit'':59B ''suspicion'':64B ''the'':7B,10B,42B,70B,86C ''their'':45B,60B,77B,101C ''they'':24B,94C ''though'':80B ''to'':100C ''toughness'':97C ''traditionally'':25B ''unfamiliar'':108C ''value'':95C ''wary'':106C ''way'':61B ''weapons'':58B ''with'':15B,69B', 'far_harbor_lore', 'Harbormen are the Island''s local fishing and homesteading people. They value independence, toughness and service to their community, and can be wary of unfamiliar mainlanders.', 'far_harbor', 'harbormen communities, island fishing culture, coastal livelihoods, far harbor residents, local traditions', 'organization', NULL, 835, 'harbormen', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a7812bdead86739de8b8c105a79670935aeae3a745fc9ff33c82e605a3917233', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('acadia', 'Acadia observatory,synth refuge', 'Acadia occupies a pre-war astronomical observatory on high ground inland from Far Harbor. It is a refuge for synths seeking a community among their own kind, with farming, trading and technical work supporting daily life. Its stated aim is to coexist peacefully with other Island residents. Acadia supplied Fog condenser technology to Far Harbor in exchange for access to supplies and a safe dock. The refuge''s public purpose does not establish a stranger''s synth identity or any hidden political arrangement.', '''a'':8B,23B,28B,69B,80B,104C,110C ''acadia'':1A,2A,6B,54B,90C ''access'':65B ''aim'':45B ''among'':30B ''an'':92C,96C ''and'':37B,68B ''any'':86B ''arrangement'':89B ''as'':103C ''astronomical'':12B ''coexist'':48B ''community'':29B,94C ''condenser'':57B ''daily'':41B ''desire'':111C ''dock'':71B ''does'':77B ''establish'':79B ''exchange'':63B ''express'':109C ''far'':19B,60B ''farming'':35B ''fog'':56B ''for'':25B,64B ''from'':18B ''ground'':16B ''harbor'':20B,61B ''hidden'':87B ''high'':15B ''identity'':84B ''in'':62B,95C ''inland'':17B ''is'':22B,46B,91C,100C ''island'':52B,93C ''it'':21B,99C ''its'':43B ''kind'':33B ''known'':101C ''life'':42B ''live'':113C ''locally'':102C ''neighbors'':117C ''not'':78B ''observatory'':3A,13B,98C ''occupies'':7B ''old'':97C ''on'':14B ''or'':85B ''other'':51B ''own'':32B ''peacefully'':49B,114C ''political'':88B ''pre'':10B ''pre-war'':9B ''public'':75B ''purpose'':76B ''refuge'':5A,24B,73B,106C ''residents'':53B,108C ''s'':74B,82B ''safe'':70B ''seeking'':27B ''stated'':44B ''stranger'':81B ''supplied'':55B ''supplies'':67B ''supporting'':40B ''synth'':4A,83B,105C ''synths'':26B ''technical'':38B ''technology'':58B ''the'':72B ''their'':31B,116C ''to'':47B,59B,66B,112C ''trading'':36B ''war'':11B ''whose'':107C ''with'':34B,50B,115C ''work'':39B', 'acadia_lore', 'Acadia is an Island community in an old observatory. It is known locally as a synth refuge whose residents express a desire to live peacefully with their neighbors.', 'far_harbor,acadia', 'acadia observatory, island synth refuge, far harbor communities, fog condenser support, mountain settlement', 'location', NULL, 836, 'acadia', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'd58cf64108d57914cdd4243de4bb0845966475c6c1f6c0e40db8eb4e587f4ee0', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('the_nucleus', 'Nucleus,Mount Desert Island Naval Facility', 'The Nucleus is the Island Children of Atom''s home in a former naval submarine facility. An old submarine, called the Vessel, dominates a large interior lined with catwalks and makeshift dwellings. The community includes shops, a clinic and work areas, with religious life centered on Atom and radiation. Irradiated water and deeper parts of the facility make environmental protection a practical concern for visitors. Knowledge of this public settlement gives no access to restricted systems or private records.', '''a'':20B,32B,45B,69B ''access'':81B ''an'':25B,99C ''and'':38B,47B,56B,60B,105C,109C ''areas'':49B ''atom'':16B,55B,94C ''called'':28B ''catwalks'':37B,107C ''centered'':53B ''children'':14B,92C ''clinic'':46B ''community'':42B ''concern'':71B ''deeper'':61B ''desert'':5A ''dominates'':31B ''dwellings'':40B,108C ''environmental'':67B ''facility'':8A,24B,65B,102C ''for'':72B ''form'':111C ''former'':21B ''gives'':79B ''home'':18B,114C ''in'':19B,98C ''includes'':43B ''interior'':34B ''irradiated'':58B ''is'':11B,90C ''island'':6A,13B,96C ''knowledge'':74B ''large'':33B ''life'':52B ''lined'':35B ''make'':66B ''makeshift'':39B ''mount'':4A ''naval'':7A,22B ''no'':80B ''nucleus'':2A,3A,10B,89C ''of'':15B,63B,75B,93C ''old'':26B,100C ''on'':54B ''or'':85B ''parts'':62B ''practical'':70B ''private'':86B ''protection'':68B ''public'':77B ''radiation'':57B ''records'':87B ''religious'':51B ''restricted'':83B ''s'':17B,95C ''settlement'':78B,97C ''shops'':44B,110C ''submarine'':23B,27B,101C,104C ''surrounding'':106C ''systems'':84B ''the'':1A,9B,12B,29B,41B,64B,88C,91C,103C ''their'':112C ''this'':76B ''to'':82B ''unusual'':113C ''vessel'':30B ''visitors'':73B ''water'':59B ''with'':36B,50B ''work'':48B', 'nucleus_lore', 'The Nucleus is the Children of Atom''s Island settlement in an old submarine facility. The submarine and surrounding catwalks, dwellings and shops form their unusual home.', 'far_harbor,nucleus', 'nucleus submarine facility, island atom worshippers, underground community, children of atom, irradiated settlement', 'location', NULL, 837, 'the_nucleus', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '4d546bf24c805a962b6da3e4e590fffd3e8fe21200103d6910863246722e0d45', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('island_children_of_atom', 'Nucleus Children of Atom,Island Atomites', 'The Children of Atom on the Island are a local congregation of the radiation-worshiping faith based at the Nucleus. They regard the radioactive Fog as a sacred manifestation of Atom rather than merely an environmental hazard. Their view brings them into conflict with harbormen who need habitable land and Fog condensers. Zealots protect the congregation and carry its message. These are beliefs and public roles, not proof that a particular adherent is immune to radiation or responsible for changing the Fog.', '''a'':19B,38B,81B ''adherent'':83B ''an'':46B ''and'':61B,68B,75B,104C ''are'':18B,73B ''as'':37B,111C ''at'':29B,101C,115C ''atom'':4A,8A,14B,42B,99C ''atomites'':10A ''away'':125C ''based'':28B ''beliefs'':74B ''brings'':51B ''carry'':69B ''changing'':91B ''children'':2A,6A,12B,97C ''condensers'':63B ''conflict'':54B ''congregation'':21B,67B ''environmental'':47B ''faith'':27B ''far'':118C ''fog'':36B,62B,93B,110C ''for'':90B ''habitable'':59B ''harbor'':119C ''harbormen'':56B ''hazard'':48B ''immune'':85B ''into'':53B ''is'':84B ''island'':1A,9A,17B,95C ''it'':124C ''its'':70B ''keep'':123C ''land'':60B ''live'':100C ''local'':20B ''manifestation'':40B ''merely'':45B ''message'':71B ''need'':58B,121C ''not'':78B ''nucleus'':5A,31B,103C ''odds'':116C ''of'':3A,7A,13B,22B,41B,98C ''on'':15B ''or'':88B ''particular'':82B ''proof'':79B ''protect'':65B ''public'':76B ''putting'':113C ''radiation'':25B,87B,106C ''radiation-worshiping'':24B ''radioactive'':35B ''rather'':43B ''regard'':33B ''responsible'':89B ''roles'':77B ''s'':96C,120C ''sacred'':39B,112C ''than'':44B ''that'':80B ''the'':11B,16B,23B,30B,34B,66B,92B,94C,102C,109C ''their'':49B ''them'':52B,114C ''these'':72B ''they'':32B,107C ''to'':86B,122C ''treat'':108C ''view'':50B ''who'':57B ''with'':55B,117C ''worship'':105C ''worshiping'':26B ''zealots'':64B', 'nucleus_lore', 'The Island''s Children of Atom live at the Nucleus and worship radiation. They treat the Fog as sacred, putting them at odds with Far Harbor''s need to keep it away.', 'far_harbor,nucleus', 'island atom congregation, nucleus worshippers, radioactive fog beliefs, zealot duties, far harbor tensions', 'organization', NULL, 838, 'island_children_of_atom', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'e759d475524d291e36a3d3268cf5301de09d868e131f3503f267f5a5c687cff0', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('island_fog', 'The Fog,Atom''s veil,Deep Fog', 'The Island''s Fog is a radioactive environmental hazard that limits visibility and makes travel and habitation dangerous. Its extent has risen and fallen over the generations remembered by local people. Thick patches conceal dangerous wildlife, while exposure can cause sickness. Experienced guides distinguish especially hazardous Deep Fog from clearer ground. Harbormen fear its advance, while the Children of Atom interpret it religiously. Neither interpretation establishes the Fog''s origin or the cause of a current change.', '''a'':15B,84B ''advance'':64B ''and'':22B,25B,32B,93C,104C ''atom'':5A,69B ''by'':38B ''can'':48B,98C ''cause'':49B,82B ''change'':86B ''children'':67B ''clearer'':59B ''conceal'':43B ''current'':85B ''dangerous'':27B,44B ''deep'':8A,56B ''distinguish'':53B ''drive'':105C ''environmental'':17B ''especially'':54B ''establishes'':75B ''experienced'':51B ''exposure'':47B ''extent'':29B ''fallen'':33B ''families'':106C ''fear'':62B ''fog'':2A,4A,9A,13B,57B,77B,90C ''from'':58B,107C ''generations'':36B ''ground'':60B ''guides'':52B ''habitation'':26B ''harbormen'':61B ''has'':30B ''hazard'':18B ''hazardous'':55B ''hide'':102C ''homesteads'':109C ''interpret'':70B ''interpretation'':74B ''is'':14B,91C ''island'':1A,11B,88C ''it'':71B,97C ''its'':28B,63B ''land'':96C ''limits'':20B ''local'':39B ''make'':99C ''makes'':23B ''neither'':73B ''obscures'':94C ''of'':68B,83B ''or'':80B ''origin'':79B ''over'':34B ''patches'':42B ''people'':40B,100C ''predators'':103C ''radioactive'':16B,92C ''religiously'':72B ''remembered'':37B ''risen'':31B ''s'':6A,12B,78B,89C ''sick'':101C ''sickness'':50B ''that'':19B ''the'':3A,10B,35B,66B,76B,81B,87C,95C ''thick'':41B ''travel'':24B ''unprotected'':108C ''veil'':7A ''visibility'':21B ''while'':46B,65B ''wildlife'':45B', 'far_harbor_lore,far_harbor_navigation,far_harbor_ecology', 'The Island''s Fog is radioactive and obscures the land. It can make people sick, hide predators and drive families from unprotected homesteads.', 'far_harbor', 'radioactive island fog, far harbor weather, coastal navigation hazards, island ecology, atom religious beliefs', 'concept', NULL, 839, 'island_fog', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'd198435492e847ea6f7137ab4796c530ee6346d91980e0fd6c4658147a4ec4d0', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('fog_condensers', 'Fog condenser,Fog machines', 'Fog condensers are tall devices developed at Acadia and supplied to Far Harbor to clear radioactive Fog from nearby areas. Their visible design includes a light at the top and tubing that collects condensed liquid below. Protected areas still depend on working equipment and maintenance; Acadia''s technical help is part of the relationship between the two communities. A condenser''s presence does not prove that it is powered, undamaged or clearing the surrounding area at this moment.', '''a'':31B,65B ''acadia'':14B,52B ''and'':15B,36B,50B,104C ''are'':9B,87C ''area'':81B ''areas'':26B,44B ''around'':90C ''at'':13B,33B,82B ''back'':98C ''below'':42B ''between'':61B ''clear'':21B ''clearing'':78B ''collects'':39B ''communities'':64B ''condensed'':40B ''condenser'':4A,66B ''condensers'':2A,8B,86C ''depend'':46B ''design'':29B ''developed'':12B ''devices'':11B ''does'':69B ''equipment'':49B ''far'':18B,91C ''fog'':1A,3A,5A,7B,23B,85C,97C ''from'':24B ''habitable'':106C ''harbor'':19B,92C ''help'':55B,100C ''homes'':103C ''includes'':30B ''is'':56B,74B ''it'':73B ''keep'':94C ''light'':32B ''liquid'':41B ''machines'':6A,88C ''maintenance'':51B ''make'':101C ''moment'':84B ''nearby'':25B,95C ''not'':70B ''of'':58B ''on'':47B ''or'':77B ''part'':57B ''paths'':105C ''powered'':75B ''presence'':68B ''protected'':43B,102C ''prove'':71B ''radioactive'':22B,96C ''relationship'':60B ''s'':53B,67B ''still'':45B ''supplied'':16B ''surrounding'':80B ''tall'':10B ''technical'':54B ''that'':38B,72B ''the'':34B,59B,62B,79B ''their'':27B ''they'':99C ''this'':83B ''to'':17B,20B,93C ''top'':35B ''tubing'':37B ''two'':63B ''undamaged'':76B ''used'':89C ''visible'':28B ''working'':48B', 'fog_engineering', 'Fog condensers are machines used around Far Harbor to keep nearby radioactive Fog back. They help make protected homes and paths habitable.', 'far_harbor', 'fog condenser machines, acadia engineering, protected island settlements, far harbor defenses, equipment maintenance', 'technology', NULL, 840, 'fog_condensers', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '074dfd014e83184a46fb6e966a99f79132d85c327dde6368b324e049b1ae024f', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('island_trappers', 'Trappers of Far Harbor,trapper gangs', 'Trappers are dangerous armed groups encountered around the Island''s ruins and camps. Many were once fishers or hunters, and islanders associate their violence with long exposure to the Fog and the hardships of the Island. Trapper and coastal armor, salvaged firearms, harpoon guns and fishing-derived melee tools give them a distinct appearance. Their reputation warns travelers about hostile camps; it does not establish a current camp''s occupants or the intentions of every person wearing coastal clothing.', '''a'':61B,75B ''about'':68B ''and'':20B,28B,39B,46B,53B,98C,103C ''appearance'':63B ''are'':10B,90C ''armed'':12B,91C ''armor'':48B,102C ''around'':15B,95C ''associate'':30B ''camp'':77B ''camps'':21B,70B,99C ''clothing'':88B ''coastal'':47B,87B,101C ''commonwealth'':111C ''current'':76B ''dangerous'':11B ''derived'':56B ''distinct'':62B ''distinguish'':107C ''does'':72B ''encountered'':14B ''establish'':74B ''every'':84B ''exposure'':35B ''far'':5A ''firearms'':50B ''fishers'':25B ''fishing'':55B,105C ''fishing-derived'':54B ''fog'':38B ''from'':109C ''gangs'':8A ''gear'':106C ''give'':59B ''groups'':13B,93C ''guns'':52B ''harbor'':6A ''hardships'':41B ''harpoon'':51B ''hostile'':69B ''hunters'':27B ''intentions'':82B ''island'':1A,17B,44B,96C ''islanders'':29B ''it'':71B ''living'':94C ''long'':34B ''many'':22B,110C ''melee'':57B ''not'':73B ''occupants'':79B ''of'':4A,42B,83B ''once'':24B ''or'':26B,80B ''person'':85B ''raiders'':112C ''reputation'':65B ''ruins'':19B,97C ''s'':18B,78B ''salvaged'':49B,104C ''the'':16B,37B,40B,43B,81B ''their'':31B,64B,100C ''them'':60B,108C ''to'':36B ''tools'':58B ''trapper'':7A,45B ''trappers'':2A,3A,9B,89C ''travelers'':67B ''violence'':32B ''violent'':92C ''warns'':66B ''wearing'':86B ''were'':23B ''with'':33B', 'far_harbor_lore,far_harbor_navigation', 'Trappers are armed, violent groups living around Island ruins and camps. Their coastal armor and salvaged fishing gear distinguish them from many Commonwealth raiders.', 'far_harbor', 'island trapper gangs, coastal armor, hostile island camps, salvaged fishing weapons, far harbor hazards', 'organization', NULL, 841, 'island_trappers', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '56a5ec9d64619ede2272a36ff18c29e5f00c56bcb1be4dc875aa8f1cc2d90ede', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('vim', 'Vim soda', 'Vim is a soft drink associated with Maine and the Island, produced before the war by Vim! Pop Incorporated. It began as a health tonic and became a regional tradition, more popular in Maine than its larger rival Nuka-Cola. Surviving bottles, vending machines and painted branding keep the name familiar locally. Known varieties include Captain''s Blend, Quartz and Refresh. Recognizing a bottle or flavor gives no knowledge of a protected recipe or of the supplies a trader currently has.', '''a'':6B,26B,31B,67B,75B,82B ''and'':12B,29B,49B,64B,96C ''around'':101C ''as'':25B ''associated'':9B ''became'':30B ''before'':16B,112C ''began'':24B ''blend'':62B ''bottle'':68B ''bottles'':46B,95C ''brand'':94C ''branding'':51B ''by'':19B ''captain'':60B ''cola'':44B,111C ''currently'':84B ''drink'':8B ''familiar'':55B,91C ''flavor'':70B ''gives'':71B ''has'':85B ''health'':27B ''in'':36B ''include'':59B ''incorporated'':22B ''is'':5B,87C ''island'':14B,89C ''it'':23B,104C ''its'':39B ''keep'':52B ''knowledge'':73B ''known'':57B ''larger'':40B ''local'':92C ''locally'':56B ''machines'':48B,99C ''maine'':11B,37B,102C ''more'':34B,106C ''name'':54B ''no'':72B ''nuka'':43B,110C ''nuka-cola'':42B,109C ''of'':74B,79B ''old'':97C ''or'':69B,78B ''painted'':50B ''pop'':21B ''popular'':35B,107C ''produced'':15B ''protected'':76B ''quartz'':63B ''recipe'':77B ''recognizing'':66B ''refresh'':65B ''regional'':32B ''rival'':41B ''s'':61B,90C ''soda'':3A,93C ''soft'':7B ''supplies'':81B ''survive'':100C ''surviving'':45B ''than'':38B,108C ''the'':13B,17B,53B,80B,88C,113C ''tonic'':28B ''trader'':83B ''tradition'':33B ''varieties'':58B ''vending'':47B,98C ''vim'':1A,2A,4B,20B,86C ''war'':18B,114C ''was'':105C ''where'':103C ''with'':10B', 'far_harbor_lore', 'Vim is the Island''s familiar local soda brand. Bottles and old vending machines survive around Maine, where it was more popular than Nuka-Cola before the war.', 'far_harbor', 'vim soda brand, maine soft drinks, island beverage culture, prewar regional advertising, vim flavor varieties', 'concept', NULL, 842, 'vim', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'b86f12e17e3c330c34e9d3a9e8a79f0a697d3b40e99c48e174557d5eb983f4bc', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('vim_pop_factory', 'Vim! factory', 'The Vim! Pop factory was the company''s production and distribution center on the Island. Its complex includes corporate offices and separate mixing, bottling and shipping areas, with large vats, catwalks and industrial equipment. A bridge and waterfront structures connect the site with its surroundings. The ruins have a reputation for super mutant occupation and are dangerous to approach without current information. The factory''s public industrial history gives no knowledge of locked rooms or concealed facilities.', '''a'':40B,54B,105C ''and'':15B,26B,30B,37B,42B,60B,97C ''approach'':64B ''are'':61B ''areas'':32B ''bottling'':29B,98C ''bridge'':41B ''catwalks'':36B ''center'':17B ''company'':12B ''complex'':22B,94C ''concealed'':81B ''connect'':45B ''corporate'':24B ''current'':66B ''dangerous'':62B,106C ''distribution'':16B ''equipment'':39B,99C ''facilities'':82B ''factory'':3A,5A,9B,69B,86C ''for'':56B ''gives'':74B ''have'':53B ''history'':73B ''in'':101C ''includes'':23B ''industrial'':38B,72B,102C ''information'':67B ''is'':87C ''island'':20B,89C ''its'':21B,49B ''knowledge'':76B ''large'':34B ''locked'':78B ''mixing'':28B ''mutant'':58B ''no'':75B ''occupation'':59B ''of'':77B ''offices'':25B,95C ''old'':91C ''on'':18B ''or'':80B ''pop'':2A,8B,85C ''production'':14B,93C ''public'':71B ''reputation'':55B,107C ''rooms'':79B ''ruins'':52B,103C ''s'':13B,70B,90C ''separate'':27B ''shipping'':31B ''site'':47B ''soda'':92C ''structures'':44B ''super'':57B ''surroundings'':50B ''survive'':100C ''the'':6B,11B,19B,46B,51B,68B,83C,88C ''to'':63B ''vats'':35B,96C ''vim'':1A,4A,7B,84C ''was'':10B ''waterfront'':43B ''with'':33B,48B,104C ''without'':65B', 'far_harbor_lore', 'The Vim! Pop factory is the Island''s old soda production complex. Offices, vats and bottling equipment survive in industrial ruins with a dangerous reputation.', 'far_harbor', 'vim bottling factory, island industrial ruins, soda production machinery, far harbor landmarks, hostile factory occupants', 'location', NULL, 843, 'vim_pop_factory', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '84280fbae346e74eb17e5fdbf42ff61ed34eb54c5026c8fbb7e634e2fcfd9b08', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('island_angler', 'Angler,Far Harbor anglers', 'Anglers are dangerous waterside creatures found on the Island. They can wait under the surface near lure weed, with a glowing growth above the head imitating the plant to draw prey close. Their attacks include claws and a fiery discharge that makes them dangerous beyond arm''s reach. Recognizing the false lure is useful to experienced travelers. The source of the species is not established by its resemblance to particular fish or amphibians.', '''a'':26B,44B,86C,102C ''above'':29B ''ambush'':94C ''amphibians'':79B ''and'':43B,96C,101C ''angler'':2A,3A ''anglers'':6A,7B,81C ''are'':8B ''arm'':52B ''attack'':98C ''attacks'':40B ''beyond'':51B ''by'':72B ''can'':17B,97C ''claws'':42B,100C ''close'':38B ''creatures'':11B ''dangerous'':9B,50B ''discharge'':46B,104C ''draw'':36B ''established'':71B ''experienced'':62B ''false'':57B ''far'':4A ''fiery'':45B,103C ''fish'':77B ''found'':12B ''glowing'':27B,87C ''growth'':28B ''harbor'':5A ''head'':31B ''hide'':82C ''imitating'':32B ''include'':41B ''is'':59B,69B ''island'':1A,15B,80C ''its'':73B ''lure'':23B,58B,88C,91C ''makes'':48B ''near'':22B,83C ''not'':70B ''of'':66B ''on'':13B ''or'':78B ''particular'':76B ''plant'':34B ''prey'':37B,95C ''reach'':54B ''recognizing'':55B ''resemblance'':74B ''resembles'':90C ''s'':53B ''source'':65B ''species'':68B ''surface'':21B ''that'':47B,89C ''the'':14B,20B,30B,33B,56B,64B,67B ''their'':39B ''them'':49B ''they'':16B,93C ''to'':35B,61B,75B ''travelers'':63B ''under'':19B ''useful'':60B ''wait'':18B ''water'':84C ''waterside'':10B ''weed'':24B,92C ''with'':25B,85C,99C', 'far_harbor_ecology,far_harbor_navigation', 'Island anglers hide near water with a glowing lure that resembles lure weed. They ambush prey and can attack with claws and a fiery discharge.', 'far_harbor', 'island angler creatures, aquatic ambush predators, lureweed imitation, far harbor wildlife, shoreline hazards', 'creature', NULL, 844, 'island_angler', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '01dbf4ed3ab1a2cf9af908feb8e5025513f29e1783bb3862a75f37d2eb0ed9c2', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('island_gulper', 'Gulper,Far Harbor gulpers', 'Gulpers are the Island''s mutated salamanders, often associated with freshwater areas. They continue growing throughout life, so older individuals can become especially large and formidable. Some hang from tree branches by their tails and drop on passing prey. They rush opponents and attack with their large mouths and forelimbs. Knowledge of the species helps explain the risk around wooded watercourses, but the presence, size and behavior of a particular animal need an actual sighting.', '''a'':75B ''actual'':80B ''ambush'':97C ''an'':79B ''and'':31B,41B,49B,55B,72B,95C ''animal'':77B ''are'':8B,84C ''areas'':18B,91C ''around'':65B ''associated'':15B ''attack'':50B ''become'':28B ''behavior'':73B ''branches'':37B,103C ''but'':68B ''by'':38B,99C ''can'':27B,96C ''continue'':20B ''drop'':42B ''dropping'':100C ''especially'':29B ''explain'':62B ''far'':4A ''favor'':89C ''forelimbs'':56B ''formidable'':32B ''freshwater'':17B,90C ''from'':35B,101C ''grow'':92C ''growing'':21B ''gulper'':2A,3A ''gulpers'':6A,7B,83C ''hang'':34B ''harbor'':5A ''helps'':61B ''individuals'':26B ''island'':1A,10B,82C ''knowledge'':57B ''large'':30B,53B,85C ''life'':23B,94C ''mouths'':54B ''mutated'':12B,86C ''need'':78B ''of'':58B,74B ''often'':14B ''older'':25B ''on'':43B ''opponents'':48B ''particular'':76B ''passing'':44B ''presence'':70B ''prey'':45B,98C ''risk'':64B ''rush'':47B ''s'':11B ''salamanders'':13B,87C ''sighting'':81B ''size'':71B ''so'':24B ''some'':33B ''species'':60B ''tails'':40B ''the'':9B,59B,63B,69B ''their'':39B,52B ''they'':19B,46B,88C ''throughout'':22B,93C ''tree'':36B,102C ''watercourses'':67B ''with'':16B,51B ''wooded'':66B', 'far_harbor_ecology,far_harbor_navigation', 'Island gulpers are large mutated salamanders. They favor freshwater areas, grow throughout life and can ambush prey by dropping from tree branches.', 'far_harbor', 'island gulper creatures, mutated salamanders, tree ambush predators, far harbor wildlife, freshwater habitats', 'creature', NULL, 845, 'island_gulper', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '705c6054bd512ee95c85291f331b2d4318f6fbfb0ce9609d317b184e9c29a6af', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('island_fog_crawler', 'Fog crawler,Far Harbor crawlers', 'Fog crawlers are enormous, heavily protected predators associated with the Island''s Deep Fog. Their crustacean-like bodies rise above people, and large claws allow powerful close attacks. Deep booming calls can announce their presence even when mist obscures the animal. Despite their size and armor, they can move quickly over land. Experienced Island travelers take their reputation seriously, while a call or report must still be assessed before claiming one is nearby.', '''a'':70B,106C ''above'':29B ''allow'':34B ''and'':31B,54B,101C ''animal'':50B ''announce'':42B ''are'':11B,85C ''armor'':55B ''armored'':87C ''assessed'':77B ''associated'':16B,90C ''attacks'':37B ''be'':76B ''before'':78B ''bodies'':27B ''booming'':39B,102C ''call'':71B ''calls'':40B,103C ''can'':41B,57B ''claiming'':79B ''claws'':33B,99C ''close'':36B ''crawler'':3A,5A ''crawlers'':8A,10B,84C ''crustacean'':25B ''crustacean-like'':24B ''deep'':21B,38B,95C ''despite'':51B ''enormous'':12B ''even'':45B ''experienced'':62B ''far'':6A ''feared'':107C ''fog'':2A,4A,9B,22B,83C ''harbor'':7A ''hazard'':109C ''heavily'':13B ''huge'':86C ''is'':81B ''island'':1A,19B,63B,93C ''land'':61B ''large'':32B,98C ''like'':26B ''local'':108C ''make'':104C ''mist'':47B,96C ''move'':58B ''must'':74B ''nearby'':82B ''obscures'':48B ''one'':80B ''or'':72B ''over'':60B ''people'':30B ''powerful'':35B ''predators'':15B,88C ''presence'':44B ''protected'':14B ''quickly'':59B ''report'':73B ''reputation'':67B ''rise'':28B ''s'':20B,94C ''seriously'':68B ''size'':53B ''speed'':100C ''still'':75B ''take'':65B ''the'':18B,49B,92C ''their'':23B,43B,52B,66B,97C ''them'':105C ''they'':56B ''travelers'':64B ''usually'':89C ''when'':46B ''while'':69B ''with'':17B,91C', 'far_harbor_ecology,far_harbor_navigation', 'Fog crawlers are huge armored predators usually associated with the Island''s deep mist. Their large claws, speed and booming calls make them a feared local hazard.', 'far_harbor', 'island fog crawlers, armored crustacean predators, deep fog hazards, far harbor wildlife, coastal creature encounters', 'creature', NULL, 846, 'island_fog_crawler', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '8ede696290b3140a347a88832e471ef7f6f98cd7748c66a0ddb5d6a70f1bc5a4', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_world', 'Nuka-World amusement park', 'Nuka-World is a former Nuka-Cola amusement park outside Boston, divided into Nuka-Town USA and several themed areas. Its attractions celebrated space travel, the Wild West, animals, children''s entertainment and soda production. After the war, traders established a community in Nuka-Town, which later became associated with three raider gangs: the Operators, Disciples and Pack. The outer park areas contain dangerous ruins and wildlife. A local reputation does not establish which gang controls an attraction today.', '''a'':12B,51B,79B ''after'':46B ''amusement'':6A,17B,100C ''an'':88B,95C ''and'':27B,43B,68B,77B,114C ''animals'':39B ''areas'':30B,73B,105C ''associated'':60B,116C ''attraction'':89B ''attractions'':32B ''became'':59B ''boston'':20B ''celebrated'':33B ''children'':40B ''cola'':16B,99C ''community'':52B ''contain'':74B ''controls'':87B ''dangerous'':75B ''disciples'':67B ''divided'':21B ''does'':82B ''entertainment'':42B ''establish'':84B ''established'':50B ''former'':13B ''gang'':86B ''gangs'':64B,120C ''hub'':113C ''in'':53B ''into'':22B ''is'':11B,94C,110C,115C ''its'':31B,111C ''later'':58B ''local'':80B ''not'':83B ''nuka'':1A,4A,9B,15B,24B,55B,92C,98C,107C ''nuka-cola'':14B,97C ''nuka-town'':23B,54B,106C ''nuka-world'':3A,8B,91C ''old'':96C ''operators'':66B ''outer'':71B ''outside'':19B ''pack'':69B ''park'':7A,18B,72B,101C ''production'':45B ''raider'':63B,119C ''reputation'':81B ''ruins'':76B ''s'':41B ''several'':28B,103C ''soda'':44B ''space'':34B ''the'':36B,47B,65B,70B ''themed'':29B,104C ''three'':62B,118C ''today'':90B ''town'':25B,56B,108C ''traders'':49B ''trading'':112C ''travel'':35B ''usa'':26B,109C ''war'':48B ''west'':38B ''which'':57B,85B ''wild'':37B ''wildlife'':78B ''with'':61B,102C,117C ''world'':2A,5A,10B,93C', 'nuka_world_lore', 'Nuka-World is an old Nuka-Cola amusement park with several themed areas. Nuka-Town USA is its trading hub and is associated with three raider gangs.', 'nuka_world', 'nuka world amusement park, prewar tourist attractions, raider occupied park, themed attraction zones, park trader community', 'location', NULL, 847, 'nuka_world', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'f09a54f0c1570eac6b1c9e823d21397726bb1b06e6086c8e5c896706c014d1f3', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_town_usa', 'Nuka-Town U.S.A.', 'Nuka-Town USA is the central hub of Nuka-World, built around eateries, entertainment venues and the market. The old Nuka-Cade, Cola-cars arena and Bradberton Amphitheater remain recognizable attractions. In the period of raider rule, the Pack is associated with the amphitheater, Operators with the Parlor and Disciples with Fizztop Mountain. Traders and laborers continue supporting the town under coercive conditions. Its current rulers, access and safety require recent knowledge of the park.', '''access'':78B ''amphitheater'':39B,55B ''and'':25B,37B,60B,66B,79B,98C,103C,112C ''arena'':36B ''around'':21B ''associated'':52B ''attractions'':42B ''bradberton'':38B ''built'':20B ''cade'':32B ''camps'':115C ''cars'':35B ''central'':14B,96C ''coercive'':73B ''cola'':34B ''cola-cars'':33B ''conditions'':74B ''continue'':68B ''current'':76B ''disciples'':61B ''district'':100C ''eateries'':22B ''entertainment'':23B,104C ''fizztop'':63B ''gang'':114C ''hub'':15B ''in'':43B ''is'':12B,51B,91C ''its'':75B ''knowledge'':83B ''laborers'':67B,111C ''market'':27B,99C ''mountain'':64B ''nuka'':1A,5A,9B,18B,31B,88C,93C ''nuka-cade'':30B ''nuka-town'':4A,8B,87C ''nuka-world'':17B,92C ''of'':16B,46B,84B ''old'':29B,101C ''operators'':56B ''pack'':50B ''park'':86B ''parlor'':59B ''period'':45B ''raider'':47B,113C ''recent'':82B ''recognizable'':41B ''remain'':40B ''require'':81B ''restaurants'':102C ''rule'':48B ''rulers'':77B ''s'':95C ''safety'':80B ''settlement'':97C ''share'':106C ''supporting'':69B ''the'':13B,26B,28B,44B,49B,54B,58B,70B,85B,107C ''town'':2A,6A,10B,71B,89C,108C ''traders'':65B,110C ''u.s.a'':7A ''under'':72B ''usa'':3A,11B,90C ''venues'':24B,105C ''with'':53B,57B,62B,109C ''world'':19B,94C', 'nuka_world_lore', 'Nuka-Town USA is Nuka-World''s central settlement and market district. Old restaurants and entertainment venues share the town with traders, laborers and raider gang camps.', 'nuka_world', 'nuka town usa, park market district, nuka world traders, gang headquarters, amusement park attractions', 'location', NULL, 848, 'nuka_town_usa', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'ffa2f8fd18e162048f2e6008dbb73c3bce0ecbcd03a876de69ffdebe098411b1', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_world_traders', 'Nuka-Town traders,collared traders', 'Nuka-World''s traders include people who lived in the park before the raider takeover. During raider rule, shock collars and threats force them to trade, perform manual labor and serve the gangs. The market''s merchants and medical provider keep essential goods and care circulating despite this coercion. Wearing a collar may show subjection, not support for the gang using it. A particular trader''s present freedom, stock and relationship with a visitor follow actual local events.', '''a'':61B,73B,83B ''actual'':86B ''and'':31B,40B,48B,54B,80B,96C ''been'':104C ''before'':22B ''care'':55B ''circulating'':56B ''coercion'':59B,100C ''collar'':62B ''collared'':8A ''collars'':30B,102C ''control'':107C ''despite'':57B ''does'':113C ''during'':26B ''essential'':52B ''events'':88B ''follow'':85B ''for'':68B ''force'':33B ''forced'':111C ''freedom'':78B ''gang'':70B ''gangs'':43B,119C ''goods'':53B,95C ''have'':103C ''imply'':115C ''in'':19B ''include'':15B ''it'':72B ''keep'':51B ''labor'':39B ''lived'':18B ''local'':87B ''loyalty'':116C ''manual'':38B ''market'':45B ''may'':63B ''medical'':49B ''merchants'':47B ''not'':66B,114C ''nuka'':1A,5A,11B,90C ''nuka-town'':4A,89C ''nuka-world'':10B ''park'':21B ''particular'':74B ''people'':16B ''perform'':37B ''present'':77B ''provide'':94C ''provider'':50B ''raider'':24B,27B,99C ''relationship'':81B ''rule'':28B ''s'':13B,46B,76B,92C ''serve'':41B ''services'':97C ''shock'':29B,101C ''show'':64B ''so'':109C ''stock'':79B ''subjection'':65B ''support'':67B ''takeover'':25B ''the'':20B,23B,42B,44B,69B,118C ''their'':110C ''them'':34B,108C ''this'':58B ''threats'':32B ''to'':35B,106C,117C ''town'':6A,91C ''trade'':36B ''trader'':75B ''traders'':3A,7A,9A,14B,93C ''under'':98C ''used'':105C ''using'':71B ''visitor'':84B ''wearing'':60B ''who'':17B ''with'':82B ''work'':112C ''world'':2A,12B', 'nuka_world_lore,nuka_world_trade', 'Nuka-Town''s traders provide goods and services under raider coercion. Shock collars have been used to control them, so their forced work does not imply loyalty to the gangs.', 'nuka_world', 'nuka world traders, coerced park merchants, nuka town market, raider controlled trade, collared trader community', 'organization', NULL, 849, 'nuka_world_traders', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '83efcbcf618325c3cc0f854ecd7f3383a52dec17e70f10fdf99db91cdb147943', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_world_operators', 'Operators gang', 'The Operators are one of Nuka-World''s raider gangs, known for placing profit and accumulated caps at the center of their ambitions. Their base is the Parlor, a former dinner theater in Nuka-Town USA. Mags Black, William Black and Lizzie Wyath are publicly associated with the group. The gang''s organized, composed image coexists with armed raiding and coercion. Knowing its public priorities does not reveal private family histories, experimental chemicals or plans for a particular target.', '''a'':35B,84B,90C ''accumulated'':22B ''ambitions'':29B ''and'':21B,48B,67B,107C ''are'':8B,51B,89C ''armed'':65B ''associated'':53B,96C ''at'':24B ''base'':31B ''black'':45B,47B ''business'':106C ''caps'':23B ''center'':26B ''chemicals'':80B ''coercion'':68B ''coexists'':63B ''composed'':61B ''dinner'':37B ''does'':73B ''emphasize'':103C ''experimental'':79B ''family'':77B ''for'':18B,83B,112C ''former'':36B ''gang'':5A,58B,95C ''gangs'':16B ''group'':56B ''histories'':78B ''image'':62B ''in'':39B ''is'':32B ''its'':70B ''knowing'':69B ''known'':17B ''lizzie'':49B ''mags'':44B ''making'':108C ''money'':104C ''not'':74B ''nuka'':1A,12B,41B,92C ''nuka-town'':40B ''nuka-world'':11B,91C ''of'':10B,27B ''one'':9B ''operators'':3A,4A,7B,88C ''or'':81B ''organized'':60B,105C ''park'':110C ''parlor'':34B,99C ''particular'':85B ''placing'':19B ''plans'':82B ''priorities'':72B,102C ''private'':76B ''profit'':20B ''profitable'':111C ''public'':71B,101C ''publicly'':52B ''raider'':15B,94C ''raiding'':66B ''reveal'':75B ''s'':14B,59B ''target'':86B ''the'':6B,25B,33B,55B,57B,87C,98C,109C ''theater'':38B ''their'':28B,30B,100C ''themselves'':113C ''town'':42B ''usa'':43B ''william'':46B ''with'':54B,64B,97C ''world'':2A,13B,93C ''wyath'':50B', 'nuka_world_lore,operators_lore', 'The Operators are a Nuka-World raider gang associated with the Parlor. Their public priorities emphasize money, organized business and making the park profitable for themselves.', 'nuka_world', 'operators raider gang, profit seeking raiders, parlor headquarters, nuka world factions, formal raider attire', 'organization', NULL, 850, 'nuka_world_operators', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '61655afcc64752bcf1f322dea46f9f6049b7cab5a300ab8ad4b363578aeb95e2', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_world_disciples', 'Disciples gang', 'The Disciples are a Nuka-World raider gang with a public reputation for cruelty and enjoyment of violence. Their group is associated with Fizztop Mountain, and Nisha, Dixie and Savoy are recognizable leading members. Distinctive masks, metal armor and bladed styling fit the gang''s threatening image, though members also use firearms. Their reputation describes the group rather than revealing an individual''s past, private correspondence or intentions in a particular encounter.', '''a'':9B,16B,76B,82C ''also'':56B ''an'':67B ''and'':21B,32B,35B,45B,91C,99C ''are'':8B,37B,81C,94C ''armor'':44B ''associated'':28B,95C ''bladed'':46B,103C ''bladed-looking'':102C ''correspondence'':72B ''cruelty'':20B,90C ''describes'':61B ''disciples'':3A,4A,7B,80C ''distinctive'':41B,100C ''dixie'':34B ''encounter'':78B ''enjoyment'':22B ''equipment'':105C ''firearms'':58B ''fit'':48B ''fizztop'':30B,97C ''for'':19B,89C ''gang'':5A,14B,50B,87C ''group'':26B,63B ''image'':53B ''in'':75B ''individual'':68B ''intentions'':74B ''is'':27B ''known'':88C ''leading'':39B ''looking'':104C ''masked'':101C ''masks'':42B ''members'':40B,55B ''metal'':43B ''mountain'':31B,98C ''nisha'':33B ''nuka'':1A,11B,84C ''nuka-world'':10B,83C ''of'':23B ''or'':73B ''particular'':77B ''past'':70B ''private'':71B ''public'':17B ''raider'':13B,86C ''rather'':64B ''recognizable'':38B ''reputation'':18B,60B ''revealing'':66B ''s'':51B,69B ''savoy'':36B ''styling'':47B ''than'':65B ''the'':6B,49B,62B,79C ''their'':25B,59B ''they'':93C ''though'':54B ''threatening'':52B ''use'':57B ''violence'':24B,92C ''with'':15B,29B,96C ''world'':2A,12B,85C', 'nuka_world_lore,disciples_lore', 'The Disciples are a Nuka-World raider gang known for cruelty and violence. They are associated with Fizztop Mountain and distinctive masked, bladed-looking equipment.', 'nuka_world', 'disciples raider gang, masked park raiders, fizztop mountain headquarters, nuka world factions, violent gang reputation', 'organization', NULL, 851, 'nuka_world_disciples', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'b1a20c61694ec10b36dc4b6f78aef713d15957c0638adb636b3b4121907f7a81', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_world_pack', 'The Pack gang,Pack raiders', 'The Pack is a Nuka-World raider gang associated with the Bradberton Amphitheater. Its members follow an animal-inspired hierarchy and display bright clothing, animal masks and decorated armor. Mason is a public leading figure, and the gang is known for keeping wasteland animals and valuing demonstrations of strength. Members use painted melee weapons as well as firearms. The group''s imagery and customs do not establish control over a particular creature or guarantee a visitor''s safety.', '''a'':12B,42B,80B,85B,92C,104C ''amphitheater'':22B,116C ''an'':26B ''and'':31B,37B,46B,55B,73B,103C ''animal'':28B,35B,99C ''animal-inspired'':27B ''animals'':54B ''armor'':39B ''as'':65B,67B ''associated'':18B,112C ''based'':106C ''bradberton'':21B,115C ''bright'':33B,101C ''camp'':110C ''clothing'':34B ''control'':78B ''creature'':82B ''customs'':74B ''decorated'':38B ''demonstrations'':57B ''display'':32B ''do'':75B ''equipment'':102C ''establish'':77B ''figure'':45B ''firearms'':68B ''follow'':25B ''for'':51B ''gang'':6A,17B,48B,97C ''group'':70B ''guarantee'':84B ''hierarchy'':30B,105C ''imagery'':72B ''inspired'':29B ''is'':11B,41B,49B,91C,111C ''its'':23B,109C ''keeping'':52B ''known'':50B ''leading'':44B ''masks'':36B,100C ''mason'':40B ''melee'':63B ''members'':24B,60B ''not'':76B ''nuka'':1A,14B,94C ''nuka-world'':13B,93C ''of'':58B ''on'':107C ''or'':83B ''over'':79B ''pack'':3A,5A,7A,10B,90C ''painted'':62B ''particular'':81B ''public'':43B ''raider'':16B,96C ''raiders'':8A ''s'':71B,87B ''safety'':88B ''strength'':59B,108C ''the'':4A,9B,20B,47B,69B,89C,114C ''use'':61B ''valuing'':56B ''visitor'':86B ''wasteland'':53B ''weapons'':64B ''well'':66B ''with'':19B,98C,113C ''world'':2A,15B,95C', 'nuka_world_lore,pack_lore', 'The Pack is a Nuka-World raider gang with animal masks, bright equipment and a hierarchy based on strength. Its camp is associated with the Bradberton Amphitheater.', 'nuka_world', 'pack raider gang, animal themed raiders, bradberton amphitheater camp, nuka world factions, strength based hierarchy', 'organization', NULL, 852, 'nuka_world_pack', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'fcab1d94c9f42d17cfc73a6e0a0bbc4df6b97fc9cb2d259ba0858b4a432fbd33', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('galactic_zone', 'Nuka-World Galactic Zone', 'The Galactic Zone is Nuka-World''s space-themed attraction area, opened before the Great War through cooperation among Nuka-Cola, RobCo, Vault-Tec and other companies. Its attractions include Nuka-Galaxy, RobCo Battlezone, Starport Nuka and Vault-Tec: Among the Stars. The setting uses futuristic displays and robots to sell an imagined space age. In the post-war park, surviving automated equipment can be dangerous. Recognizing the attraction gives no knowledge of its control-system condition or restricted operating procedures.', '''age'':68B ''among'':28B,53B ''an'':65B ''and'':36B,49B,61B,112C,126C ''area'':20B,109C ''attraction'':19B,83B ''attractions'':40B,111C ''automated'':76B ''battlezone'':46B ''be'':79B ''before'':22B ''can'':78B ''cola'':31B ''collaboration'':120C ''companies'':38B,128C ''condition'':92B ''control'':90B ''control-system'':89B ''cooperation'':27B ''dangerous'':80B ''displays'':60B ''equipment'':77B ''futuristic'':59B,110C ''galactic'':1A,6A,9B,98C ''galaxy'':44B ''gives'':84B ''great'':24B ''imagined'':66B ''in'':69B ''include'':41B ''is'':11B,100C ''its'':39B,88B,116C ''knowledge'':86B ''no'':85B ''nuka'':4A,13B,30B,43B,48B,102C ''nuka-cola'':29B ''nuka-galaxy'':42B ''nuka-world'':3A,12B,101C ''of'':87B ''opened'':21B ''operating'':95B ''or'':93B ''other'':37B,127C ''park'':74B,108C ''post'':72B ''post-war'':71B ''pre'':118C ''pre-war'':117C ''procedures'':96B ''recognizing'':81B ''reflect'':115C ''restricted'':94B ''robco'':32B,45B,122C ''robots'':62B,114C ''s'':15B,104C ''sell'':64B ''setting'':57B ''space'':17B,67B,106C ''space-themed'':16B,105C ''starport'':47B ''stars'':55B ''surviving'':75B,113C ''system'':91B ''tec'':35B,52B,125C ''the'':8B,23B,54B,56B,70B,82B,97C ''themed'':18B,107C ''through'':26B ''to'':63B ''uses'':58B ''vault'':34B,51B,124C ''vault-tec'':33B,50B,123C ''war'':25B,73B,119C ''with'':121C ''world'':5A,14B,103C ''zone'':2A,7A,10B,99C', 'nuka_world_lore,nuka_world_technology', 'The Galactic Zone is Nuka-World''s space-themed park area. Futuristic attractions and surviving robots reflect its pre-war collaboration with RobCo, Vault-Tec and other companies.', 'nuka_world', 'galactic zone attractions, space themed amusement park, robotic park security, prewar corporate collaboration, futuristic tourist displays', 'location', NULL, 853, 'galactic_zone', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '76181a57019f45460a7a4799e376b8e66f88770c14979d3f4f8cc49001b166e9', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('dry_rock_gulch', 'Nuka-World Wild West park', 'Dry Rock Gulch is Nuka-World''s Wild West-themed area, built as the park''s first expansion. Its Main Street includes a saloon and blacksmith setting, with a theater, mock ranch and minecart coaster elsewhere in the zone. Named Protectrons perform cowboy and sheriff roles, continuing the old attraction''s routines. Bloodworms and other hostile insects have made the ruins dangerous. The old frontier show should not be confused with a functioning present-day town or reliable local law enforcement.', '''a'':34B,40B,83B,106C,111C ''and'':36B,44B,55B,65B,110C ''area'':22B,116C ''as'':24B ''attraction'':61B,104C ''be'':80B ''blacksmith'':37B ''bloodworms'':64B ''built'':23B ''coaster'':46B,113C ''confused'':81B ''continuing'':58B ''cowboy'':54B,108C ''dangerous'':73B,120C ''day'':87B ''dry'':1A,10B,94C ''elsewhere'':47B ''enforcement'':93B ''expansion'':29B ''first'':28B ''for'':119C ''frontier'':76B ''functioning'':84B ''gulch'':3A,12B,96C ''have'':69B ''hostile'':67B ''in'':48B ''includes'':33B ''insects'':68B,121C ''is'':13B,97C,117C ''its'':30B ''known'':118C ''law'':92B ''local'':91B ''made'':70B ''main'':31B ''minecart'':45B,112C ''mock'':42B ''named'':51B ''not'':79B ''nuka'':5A,15B,99C ''nuka-world'':4A,14B,98C ''old'':60B,75B ''or'':89B ''other'':66B ''park'':9A,26B ''perform'':53B ''present'':86B ''present-day'':85B ''protectrons'':52B ''ranch'':43B ''reliable'':90B ''robots'':109C ''rock'':2A,11B,95C ''roles'':57B ''routines'':63B ''ruined'':115C ''ruins'':72B ''s'':17B,27B,62B,101C ''saloon'':35B,107C ''setting'':38B ''sheriff'':56B ''should'':78B ''show'':77B ''street'':32B ''the'':25B,49B,59B,71B,74B,114C ''theater'':41B ''themed'':21B ''town'':88B ''west'':8A,20B,103C ''west-themed'':19B ''wild'':7A,18B,102C ''with'':39B,82B,105C ''world'':6A,16B,100C ''zone'':50B', 'nuka_world_lore', 'Dry Rock Gulch is Nuka-World''s Wild West attraction, with a saloon, cowboy robots and a minecart coaster. The ruined area is known for dangerous insects.', 'nuka_world', 'dry rock gulch, wild west attractions, cowboy protectron performers, minecart coaster ruins, dangerous park insects', 'location', NULL, 854, 'dry_rock_gulch', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'd4208db309a189dac59539362ee59d75cdee201e0c925738066e2cd3490ae3da', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('safari_adventure', 'Nuka-World safari park', 'Safari Adventure is Nuka-World''s wildlife-themed attraction. Its public features include animal enclosures, primate and reptile houses, the Welcome Center, Jungle Journey Theater and Cappy''s treehouse. The area is associated with dangerous gatorclaws and other surviving animals, making exploration hazardous. A river and several paths run through the enclosed grounds. Familiarity with the attraction or its visible wildlife gives no knowledge of restricted scientific facilities, the origin of its creatures or the fate of its inhabitants.', '''a'':53B,107C ''adventure'':2A,9B,90C ''among'':110C ''amusement'':99C ''and'':26B,35B,46B,55B,106C ''animal'':23B,97C,104C ''animal-themed'':96C ''animals'':49B ''area'':40B,101C ''associated'':42B ''attraction'':18B,66B ''cappy'':36B ''center'':31B ''creatures'':82B ''dangerous'':44B,111C ''enclosed'':61B ''enclosures'':24B,103C ''exploration'':51B ''facilities'':77B ''familiarity'':63B ''fate'':85B ''features'':21B ''gatorclaws'':45B,114C ''gives'':71B ''grounds'':62B ''hazardous'':52B ''houses'':28B,105C ''include'':22B ''including'':113C ''inhabitants'':88B ''is'':10B,41B,91C ''its'':19B,68B,81B,87B ''journey'':33B ''jungle'':32B ''knowledge'':73B ''making'':50B ''no'':72B ''nuka'':4A,12B,93C ''nuka-world'':3A,11B,92C ''of'':74B,80B,86B ''old'':102C ''or'':67B,83B ''origin'':79B ''other'':47B ''park'':7A,100C ''paths'':57B ''primate'':25B ''public'':20B ''reptile'':27B ''restricted'':75B ''river'':54B ''run'':58B ''s'':14B,37B,95C ''safari'':1A,6A,8B,89C ''scientific'':76B ''several'':56B ''survive'':109C ''surviving'':48B ''the'':29B,39B,60B,65B,78B,84B ''theater'':34B ''themed'':17B,98C ''through'':59B ''treehouse'':38B,108C ''visible'':69B ''welcome'':30B ''wildlife'':16B,70B,112C ''wildlife-themed'':15B ''with'':43B,64B ''world'':5A,13B,94C', 'nuka_world_lore,nuka_world_ecology', 'Safari Adventure is Nuka-World''s animal-themed amusement park area. Old enclosures, animal houses and a treehouse survive among dangerous wildlife, including gatorclaws.', 'nuka_world', 'safari adventure attractions, animal themed amusement park, dangerous gatorclaw wildlife, old animal enclosures, jungle journey theater', 'location', NULL, 855, 'safari_adventure', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'b4de0b2ea2162c405570fdb69b02dda334ff5a38547ea23a18a21a6b87c804d8', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('kiddie_kingdom', 'Nuka-World children''s park', 'Kiddie Kingdom was designed for Nuka-World''s younger visitors, using clowns, candy-shaped buildings and gentler rides. King Cola''s Castle, the Fun House, Ferris Wheel and smaller attractions remain landmarks in the enclosed area. Its post-war reputation includes feral ghouls and radioactive mist, making the cheerful appearance misleading for travelers. Present hazards must be assessed locally. The public theme and visible defenses provide no knowledge of employees'' private history, missing people or later events in the park.', '''a'':111C ''and'':26B,38B,55B,74B,101C,113C,122C ''appearance'':61B ''area'':46B,106C ''assessed'':69B ''attractions'':40B ''be'':68B ''buildings'':25B ''but'':116C ''candy'':23B,100C ''candy-and-clown-themed'':99C ''candy-shaped'':22B ''castle'':32B,112C ''cheerful'':60B,108C ''children'':6A,104C ''clown'':102C ''clowns'':21B ''cola'':30B ''danger'':125C ''defenses'':76B ''designed'':12B ''employees'':81B ''enclosed'':45B ''events'':88B ''feral'':53B,123C ''ferris'':36B ''for'':13B,63B,120C ''fun'':34B,114C ''gentler'':27B ''ghoul'':124C ''ghouls'':54B ''hazards'':66B ''history'':83B ''house'':35B,115C ''in'':43B,89B ''include'':110C ''includes'':52B ''is'':94C,118C ''it'':117C ''its'':47B,107C ''kiddie'':1A,9B,92C ''king'':29B ''kingdom'':2A,10B,93C ''knowledge'':79B ''known'':119C ''landmarks'':42B ''later'':87B ''locally'':70B ''making'':58B ''misleading'':62B ''missing'':84B ''mist'':57B ''must'':67B ''no'':78B ''nuka'':4A,15B,96C ''nuka-world'':3A,14B,95C ''of'':80B ''or'':86B ''park'':8A,91B ''people'':85B ''post'':49B ''post-war'':48B ''present'':65B ''private'':82B ''provide'':77B ''public'':72B ''radiation'':121C ''radioactive'':56B ''remain'':41B ''reputation'':51B ''rides'':28B ''ruins'':109C ''s'':7A,17B,31B,98C,105C ''shaped'':24B ''smaller'':39B ''the'':33B,44B,59B,71B,90B ''theme'':73B ''themed'':103C ''travelers'':64B ''using'':20B ''visible'':75B ''visitors'':19B ''war'':50B ''was'':11B ''wheel'':37B ''world'':5A,16B,97C ''younger'':18B', 'nuka_world_lore', 'Kiddie Kingdom is Nuka-World''s candy-and-clown-themed children''s area. Its cheerful ruins include a castle and Fun House, but it is known for radiation and feral ghoul danger.', 'nuka_world', 'kiddie kingdom attractions, children amusement area, king cola castle, radioactive park mist, feral ghoul danger', 'location', NULL, 856, 'kiddie_kingdom', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'e50d96ebdaf3ee875b56023cc54b9d4995480a02eaf2e208f795e0cc73267141', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('world_of_refreshment', 'Nuka-Cola factory tour', 'The World of Refreshment is a Nuka-World attraction attached to the Nuka-Cola bottling plant. Its boat-tour route followed a Quantum-themed river past displays promoting the company''s history. The ruined interior contains damaged tour equipment, factory machinery and surviving robotic security. The old visitor experience and production areas share the same industrial complex. A public factory tour does not grant knowledge of locked employee systems, protected beverage research or military projects.', '''a'':14B,33B,70B,103C ''and'':54B,62B,106C ''areas'':64B ''attached'':19B ''attraction'':18B ''attractions'':114C ''beside'':115C ''beverage'':83B ''boat'':29B ''boat-tour'':28B ''bottling'':25B,116C ''cola'':6A,24B,100C ''company'':42B,108C ''company-history'':107C ''complex'':69B ''contains'':48B ''damaged'':49B,113C ''displays'':39B,110C ''does'':74B ''employee'':80B ''equipment'':51B,117C ''experience'':61B ''factory'':7A,52B,72B,101C ''followed'':32B ''grant'':76B ''history'':44B,109C ''industrial'':68B ''interior'':47B ''is'':13B,92C ''its'':27B ''knowledge'':77B ''locked'':79B ''machinery'':53B ''military'':86B ''not'':75B ''nuka'':5A,16B,23B,94C,99C ''nuka-cola'':4A,22B,98C ''nuka-world'':15B,93C ''of'':2A,11B,78B,90C ''old'':59B,97C ''or'':85B ''past'':38B ''plant'':26B ''production'':63B ''projects'':87B ''promoting'':40B ''protected'':82B ''public'':71B ''quantum'':35B ''quantum-themed'':34B ''refreshment'':3A,12B,91C ''research'':84B ''river'':37B,105C ''robotic'':56B ''route'':31B ''ruined'':46B ''run'':111C ''s'':43B,96C ''same'':67B ''security'':57B ''share'':65B ''surviving'':55B ''systems'':81B ''the'':9B,21B,41B,45B,58B,66B,88C ''themed'':36B,104C ''through'':112C ''to'':20B ''tour'':8A,30B,50B,73B,102C ''visitor'':60B ''world'':1A,10B,17B,89C,95C', 'nuka_world_lore,nuka_world_technology', 'The World of Refreshment is Nuka-World''s old Nuka-Cola factory tour. A themed river and company-history displays run through damaged attractions beside bottling equipment.', 'nuka_world', 'world of refreshment, nuka cola factory tour, quantum river attraction, bottling plant machinery, robotic factory security', 'location', NULL, 857, 'world_of_refreshment', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '331cf5176ccfc8c1a52c3d1e40be5c578eee1b1334644664b37b30a1c19afd2f', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('nuka_express', 'Nuka-Express monorail,Nuka-World monorail', 'The Nuka-Express is the monorail linking the Nuka-World transit center near Boston with the park''s Nuka-station. Before the Great War, it moved visitors and employees along a regular schedule. The elevated line approaches the park through a rocky tunnel and ends at the station. Emergency power has kept the system capable of operation after the main park grid failed. Actual service, destination safety and access to the controls depend on the present condition of the route.', '''a'':44B,54B ''access'':82B ''actual'':77B ''after'':71B ''along'':43B ''and'':41B,57B,81B,108C,123C ''approaches'':50B ''area'':105C ''at'':59B ''before'':34B ''between'':101C ''boston'':26B,104C ''boston-area'':103C ''built'':118C ''capable'':68B ''carry'':120C ''center'':24B,107C ''condition'':90B ''controls'':85B ''depend'':86B ''destination'':79B ''elevated'':48B,115C ''emergency'':62B ''employees'':42B,124C ''ends'':58B ''express'':2A,5A,14B,97C ''failed'':76B ''great'':36B ''grid'':75B ''has'':64B ''is'':15B,98C ''it'':38B ''its'':114C ''kept'':65B ''line'':49B ''linking'':18B ''main'':73B ''monorail'':6A,10A,17B,100C ''moved'':39B ''near'':25B ''nuka'':1A,4A,8A,13B,21B,32B,96C,110C ''nuka-express'':3A,12B,95C ''nuka-station'':31B ''nuka-world'':7A,20B,109C ''of'':69B,91B ''on'':87B ''operation'':70B ''park'':29B,52B,74B,121C ''power'':63B ''present'':89B ''regular'':45B ''rocky'':55B ''route'':93B,116C ''s'':30B,112C ''safety'':80B ''schedule'':46B ''service'':78B ''station'':33B,61B,113C ''system'':67B ''the'':11B,16B,19B,28B,35B,47B,51B,60B,66B,72B,84B,88B,92B,94C,99C,102C ''through'':53B ''to'':83B,119C ''transit'':23B,106C ''tunnel'':56B ''visitors'':40B,122C ''war'':37B ''was'':117C ''with'':27B ''world'':9A,22B,111C', 'nuka_world_lore,nuka_world_navigation', 'The Nuka-Express is the monorail between the Boston-area transit center and Nuka-World''s station. Its elevated route was built to carry park visitors and employees.', 'nuka_world', 'nuka express monorail, park transit infrastructure, boston transit center, elevated rail route, prewar visitor transportation', 'technology', NULL, 858, 'nuka_express', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'cc9a911f0f13636fd7375c985dc448b7e3988bf4d62d204c50f01084229e8647', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('mechanist_fiction', 'comic Mechanist,Silver Shroud Mechanist', 'The Mechanist is a fictional villain in the pre-war Silver Shroud entertainment franchise. Stories set this armored, robot-commanding antagonist against the Silver Shroud and the Mistress of Mystery. Radio plays and Hubris Comics material preserve the persona as part of Boston''s old popular culture. A costume, name or robot-themed speech can recall the character without proving that its wearer is any real person from the stories. Fictional recognition supplies no identity or biography for a post-war imitator.', '''a'':11B,57B,89B,97C,120C ''against'':31B ''and'':35B,42B,115C ''antagonist'':30B ''any'':75B ''appears'':112C ''armored'':26B ''as'':49B,119C ''be'':117C ''biography'':87B ''boston'':52B ''can'':65B,116C ''character'':68B ''comic'':3A ''comics'':44B ''commanding'':29B,100C ''costume'':58B,121C ''cultural'':123C ''culture'':56B ''entertainment'':21B,114C ''fiction'':2A ''fictional'':12B,81B,101C ''for'':88B ''franchise'':22B ''from'':78B,103C ''hubris'':43B ''identity'':85B ''imitator'':93B ''in'':14B,113C ''is'':10B,74B,96C ''its'':72B ''material'':45B ''mechanist'':1A,4A,7A,9B,95C ''mistress'':37B ''mystery'':39B ''name'':59B ''no'':84B ''of'':38B,51B ''old'':54B ''or'':60B,86B,122C ''part'':50B ''person'':77B ''persona'':48B,111C ''plays'':41B ''popular'':55B ''post'':91B ''post-war'':90B ''pre'':17B,105C ''pre-war'':16B,104C ''preserve'':46B ''proving'':70B ''radio'':40B ''real'':76B ''recall'':66B ''recognition'':82B ''recognized'':118C ''reference'':124C ''robot'':28B,62B,99C ''robot-commanding'':27B,98C ''robot-themed'':61B ''s'':53B ''set'':24B ''shroud'':6A,20B,34B,108C ''silver'':5A,19B,33B,107C ''speech'':64B ''stories'':23B,80B,109C ''supplies'':83B ''that'':71B ''the'':8B,15B,32B,36B,47B,67B,79B,94C,110C ''themed'':63B ''this'':25B ''villain'':13B,102C ''war'':18B,92B,106C ''wearer'':73B ''without'':69B', 'comic_lore,prewar_culture', 'The Mechanist is a robot-commanding fictional villain from pre-war Silver Shroud stories. The persona appears in entertainment and can be recognized as a costume or cultural reference.', 'prewar,comic_observed', 'fictional mechanist villain, silver shroud entertainment, prewar comic culture, robot commanding antagonist, hubris comics franchise', 'concept', NULL, 859, 'mechanist_fiction', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '28928c1ed4783ca0327a75f49a63ae1f3d4fdd397a196859f8a00e312cf8e602', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('robot_workbench', 'robot assembly bench,Automatron workbench', 'A robot workbench is a large assembly station for constructing and modifying compatible robots. Its fittings can support combinations of heads, torsos, arms, legs and other parts from familiar robot designs, including arrangements never sold by the original manufacturers. Voice and paint changes are possible for suitable units. The station needs materials, appropriate components and technical knowledge; owning the equipment does not imply every part is available or that an individual robot has consented to a change.', '''a'':8B,12B,83B,85C,89C ''allow'':102C ''an'':77B ''and'':18B,32B,48B,62B,94C,100C ''appropriate'':60B ''are'':51B ''arms'':30B ''arrangements'':40B ''assembling'':93C ''assembly'':4A,14B ''automatron'':6A ''available'':74B ''bench'':5A ''by'':43B ''can'':24B ''change'':84B ''changes'':50B ''combinations'':26B ''compatible'':20B,96C ''components'':61B,109C ''consented'':81B ''constructing'':17B ''designs'':38B ''different'':107C ''does'':68B ''equipment'':67B ''every'':71B ''familiar'':36B ''fit'':106C ''fittings'':23B ''for'':16B,53B,92C ''from'':35B ''has'':80B ''heads'':28B ''imply'':70B ''including'':39B ''individual'':78B ''is'':11B,73B,88C ''its'':22B,98C ''knowledge'':64B ''large'':13B,90C ''legs'':31B ''manufacturers'':46B ''materials'':59B ''modifying'':19B,95C ''needs'':58B ''never'':41B ''not'':69B ''of'':27B ''or'':75B ''original'':45B ''other'':33B ''owning'':65B ''paint'':49B ''part'':72B ''parts'':34B ''possible'':52B ''robot'':1A,3A,9B,37B,79B,86C,108C ''robots'':21B,97C ''sold'':42B ''station'':15B,57B,91C ''suitable'':54B ''support'':25B ''supports'':99C ''technical'':63B ''that'':76B ''the'':44B,56B,66B ''to'':82B,105C ''tools'':101C ''torsos'':29B ''trained'':103C ''units'':55B ''users'':104C ''voice'':47B ''workbench'':2A,7A,10B,87C', 'robot_workbench_knowledge', 'A robot workbench is a large station for assembling and modifying compatible robots. Its supports and tools allow trained users to fit different robot components.', 'automatron_observed', 'robot assembly stations, compatible robot components, automatron workshop equipment, robotic modifications, technical assembly work', 'technology', NULL, 860, 'robot_workbench', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '7db27cb8f93f379f2d91735270162b6bc18da69951d754db7a339a74d126bddf', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rust_devils', 'Rust Devil gang', 'The Rust Devils are raiders associated with robots and equipment made from salvaged robotic parts. Members wear robot armor and may travel with modified machines, combining human gunfire with mechanical threats. They have been encountered around the Commonwealth fighting other groups and threatening settlements. Their distinctive gear can help an experienced traveler recognize the gang, but does not reveal a machine''s present programming, a raider''s private plans or who currently holds a particular industrial site.', '''a'':65B,70B,79B,87C ''an'':55B ''and'':14B,25B,47B,96C ''are'':9B,86C ''armor'':24B,102C ''around'':41B ''associated'':11B ''based'':94C ''been'':39B ''but'':61B ''can'':53B ''combining'':31B ''commonwealth'':43B ''companions'':98C ''currently'':77B ''devil'':4A ''devils'':2A,8B,85C ''distinctive'':51B,105C ''does'':62B ''encountered'':40B,107C ''equipment'':15B,95C ''experienced'':56B ''fighting'':44B ''for'':91C ''from'':17B ''gang'':5A,60B,89C ''gear'':52B ''groups'':46B ''gunfire'':33B ''have'':38B ''help'':54B ''holds'':78B ''human'':32B ''industrial'':81B ''known'':90C ''machine'':66B ''machines'':30B ''made'':16B ''makes'':103C ''may'':26B ''mechanical'':35B,97C ''members'':21B ''modified'':29B ''not'':63B ''or'':75B ''other'':45B ''particular'':80B ''parts'':20B ''plans'':74B ''present'':68B ''private'':73B ''programming'':69B ''raider'':71B,88C ''raiders'':10B ''recognize'':58B ''reveal'':64B ''robot'':23B,93C,101C ''robot-based'':92C ''robotic'':19B ''robots'':13B ''rust'':1A,3A,7B,84C ''s'':67B,72B ''salvaged'':18B,100C ''settlements'':49B ''site'':82B ''the'':6B,42B,59B,83C ''their'':50B,99C ''them'':104C ''they'':37B ''threatening'':48B ''threats'':36B ''travel'':27B ''traveler'':57B ''wear'':22B ''when'':106C ''who'':76B ''with'':12B,28B,34B', 'automatron_lore', 'The Rust Devils are a raider gang known for robot-based equipment and mechanical companions. Their salvaged robot armor makes them distinctive when encountered.', 'automatron_observed', 'rust devils raiders, salvaged robot armor, modified mechanical companions, automatron encounter knowledge, robot themed gang', 'organization', NULL, 861, 'rust_devils', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '98d0a87c87b89037999ca71824729360dea17bbe286ebacbcbb0ddea3c990805', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('robobrain', 'Robo-brain,Robobrains', 'Robobrains are tracked robots whose central processor is an organic brain enclosed in a protective dome. General Atomics developed the design with military involvement and indirect RobCo support for civilian and military roles. Flexible arms let them manipulate equipment, while a robust chassis carries their power and control systems. Surviving examples can have specialized weapons and unreliable behavior. Recognition of the design gives no knowledge of whose brain a unit contains, its memories or its current orders.', '''a'':19B,46B,74B,92C ''an'':14B,88C ''and'':30B,36B,52B,61B,98C ''are'':7B,84C ''arms'':40B ''atomics'':23B ''behavior'':63B ''body'':100C ''brain'':4A,16B,73B,90C ''can'':57B ''carries'':49B ''central'':11B ''chassis'':48B ''circuit'':106C ''circuit-controlled'':105C ''civilian'':35B ''contains'':76B ''control'':53B ''controlled'':107C ''current'':81B ''design'':26B,67B ''developed'':24B ''distinguish'':101C ''dome'':21B,94C ''enclosed'':17B ''equipment'':44B ''examples'':56B ''flexible'':39B ''for'':34B ''from'':103C ''general'':22B ''gives'':68B ''have'':58B ''in'':18B,91C ''indirect'':31B ''involvement'':29B ''is'':13B ''its'':77B,80B ''knowledge'':70B ''let'':41B ''manipulate'':43B ''mechanical'':99C ''memories'':78B ''military'':28B,37B ''no'':69B ''of'':65B,71B ''or'':79B ''orders'':82B ''ordinary'':104C ''organic'':15B,89C ''power'':51B ''processor'':12B,97C ''protective'':20B,93C ''recognition'':64B ''robco'':32B ''robo'':3A ''robo-brain'':2A ''robobrain'':1A ''robobrains'':5A,6B,83C ''robots'':9B,86C,108C ''robust'':47B ''roles'':38B ''specialized'':59B ''support'':33B ''surviving'':55B ''systems'':54B ''the'':25B,66B ''their'':50B,95C ''them'':42B,102C ''tracked'':8B,85C ''unit'':75B ''unreliable'':62B ''unusual'':96C ''weapons'':60B ''while'':45B ''whose'':10B,72B ''with'':27B,87C', 'robotics,military_robotics', 'Robobrains are tracked robots with an organic brain in a protective dome. Their unusual processor and mechanical body distinguish them from ordinary circuit-controlled robots.', 'robobrain_observed,automatron_observed', 'organic brain robots, tracked robot chassis, robobrain mechanical design, military robot development, general atomics engineering', 'robot', NULL, 862, 'robobrain', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '94f9e7b161415c6343fe80ae7b54b8f02ad37aa6633c676a0825f0e358754125', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('sentry_bot', 'Sentry bots,military sentry robot', 'Sentry bots are heavy military security robots built for overwhelming firepower. The Commonwealth design has a broad armored torso, wheeled supports and heavy weapon mounts; units can carry miniguns, missiles or other powerful armaments. Sustained activity can produce an overheating cooldown, and a destroyed unit may erupt in a dangerous explosion. Their strength makes caution appropriate, but a machine''s actual weapons, activation state and allegiance follow inspection and present programming rather than model recognition.', '''a'':23B,50B,56B,65B,101C ''activation'':70B ''activity'':43B ''actual'':68B ''allegiance'':73B ''an'':46B ''and'':29B,49B,72B,76B,95C,100C ''appropriate'':63B ''are'':10B,85C ''armaments'':41B ''armored'':25B,87C ''bot'':2A ''bots'':4A,9B,84C ''broad'':24B ''built'':15B ''but'':64B ''can'':34B,44B,104C ''carry'':35B ''carrying'':90C ''caution'':62B ''combat'':88C ''commonwealth'':20B ''cooldown'':48B ''dangerous'':57B,99C ''design'':21B ''destroyed'':51B,102C ''erupt'':54B ''explode'':106C ''explosion'':58B ''firepower'':18B,96C ''follow'':74B ''for'':16B ''has'':22B ''heavy'':11B,30B,91C ''in'':55B ''inspection'':75B ''large'':86C ''machine'':66B ''make'':97C ''makes'':61B ''may'':53B ''military'':5A,12B ''miniguns'':36B ''missiles'':37B ''model'':81B ''mounts'':32B ''or'':38B ''other'':39B ''overheating'':47B ''overwhelming'':17B ''powerful'':40B ''present'':77B ''produce'':45B ''programming'':78B ''rather'':79B ''recognition'':82B ''robot'':7A ''robots'':14B,89C ''s'':67B ''security'':13B ''sentry'':1A,3A,6A,8B,83C ''speed'':94C ''state'':71B ''still'':105C ''strength'':60B ''supports'':28B ''sustained'':42B ''than'':80B ''the'':19B ''their'':59B,93C ''them'':98C ''torso'':26B ''unit'':52B,103C ''units'':33B ''weapon'':31B ''weapons'':69B,92C ''wheeled'':27B', 'robotics,military_robotics', 'Sentry bots are large armored combat robots carrying heavy weapons. Their speed and firepower make them dangerous, and a destroyed unit can still explode.', 'prewar,commonwealth', 'heavy military robots, armored sentry design, robot security weapons, overheating combat machines, explosive robot wrecks', 'robot', NULL, 863, 'sentry_bot', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '4afb07602185a86f6e311aa5c4ecd5e59a50b1543bdf49352b50af9d6e1b7898', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('eyebot', 'Eyebots,floating broadcast robot', 'Eyebots are small floating robots with a spherical body, antennae and a prominent loudspeaker. Around the Commonwealth, they may broadcast radio, old advertisements or static while moving through settlements and ruins. A small laser gives many units a limited defensive capability. Their size and broadcasting role distinguish them from heavier service or combat robots. An individual broadcast does not prove who owns the unit, whether its message is current or what instructions the robot presently follows.', '''a'':12B,17B,37B,43B ''advertisements'':28B,95C ''an'':60B ''and'':16B,35B,49B,89C,94C ''antennae'':15B,88C ''are'':7B,83C ''around'':20B ''body'':14B ''broadcast'':4A,25B,62B ''broadcasting'':50B,91C ''capability'':46B ''combat'':58B ''commonwealth'':22B ''current'':74B ''defensive'':45B ''distinguish'':52B ''does'':63B ''eyebot'':1A ''eyebots'':2A,6B,82C ''familiar'':100C ''floating'':3A,9B,85C ''follows'':81B ''from'':54B ''gives'':40B ''heavier'':55B ''in'':102C ''individual'':61B ''instructions'':77B ''is'':73B,96C ''its'':71B ''laser'':39B ''limited'':44B ''loudspeaker'':19B ''loudspeakers'':90C ''many'':41B ''may'':24B ''message'':72B ''messages'':93C ''moving'':32B ''not'':64B ''of'':98C ''old'':27B ''one'':97C ''or'':29B,57B,75B ''owns'':67B ''presently'':80B ''prominent'':18B ''prove'':65B ''public'':103C ''radio'':26B,92C ''robot'':5A,79B ''robots'':10B,59B,86C ''role'':51B ''ruins'':36B ''service'':56B ''settlements'':34B ''size'':48B ''small'':8B,38B,84C ''spaces'':104C ''spherical'':13B ''static'':30B ''the'':21B,68B,78B ''their'':47B,99C ''them'':53B ''they'':23B ''through'':33B ''unit'':69B ''units'':42B ''uses'':101C ''what'':76B ''whether'':70B ''while'':31B ''who'':66B ''with'':11B,87C', 'robotics', 'Eyebots are small floating robots with antennae and loudspeakers. Broadcasting radio messages and advertisements is one of their familiar uses in public spaces.', 'prewar,commonwealth', 'floating broadcast robots, robotic loudspeakers, prewar radio advertising, small laser defense, commonwealth robot encounters', 'robot', NULL, 864, 'eyebot', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '42980162f2db3ff60a1dc18a68db5b40334b9a0bb09bd88ecd8eaced1eb84ec7', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
-- end-statement
INSERT INTO public.core_worldknowledge_article VALUES
	('vault_88', 'Vault Eighty-Eight', 'Vault 88 is an unfinished Vault-Tec shelter beneath Quincy Quarries. Construction stopped when the Great War interrupted the project, leaving completed entrance, security and reactor areas beside much larger unfinished caverns. Construction machinery, rail equipment and debris show how much work remained. The space can support further shelter construction by people who have gained legitimate access and the means to work there. Its current residents, completed rooms and safety depend on observed conditions, while restricted records remain separate knowledge.', '''88'':2A,8B,89C ''access'':64B ''an'':10B,91C ''and'':32B,44B,65B,76B ''areas'':34B ''at'':111C ''beneath'':16B,98C ''beside'':35B ''by'':58B ''can'':53B ''caverns'':39B,107C ''completed'':29B,74B,101C ''conditions'':81B ''construction'':19B,40B,57B,109C ''current'':72B ''debris'':45B ''depend'':78B ''eight'':6A ''eighty'':5A ''eighty-eight'':4A ''entrance'':30B,102C ''equipment'':43B ''further'':55B ''gained'':62B ''great'':23B,113C ''have'':61B ''how'':47B ''interrupted'':25B ''is'':9B,90C ''its'':71B ''knowledge'':87B ''large'':106C ''larger'':37B ''lead'':104C ''leaving'':28B ''legitimate'':63B ''machinery'':41B ''means'':67B ''much'':36B,48B ''observed'':80B ''on'':79B ''people'':59B ''project'':27B ''quarries'':18B,100C ''quincy'':17B,99C ''rail'':42B ''reactor'':33B ''records'':84B ''remain'':85B ''remained'':50B ''residents'':73B ''restricted'':83B ''rooms'':75B,103C ''safety'':77B ''security'':31B ''separate'':86B ''shelter'':15B,56B,97C ''show'':46B ''space'':52B ''stopped'':20B,110C ''support'':54B ''tec'':14B,96C ''the'':22B,26B,51B,66B,112C ''there'':70B ''to'':68B ''toward'':105C ''underground'':93C ''unfinished'':11B,38B,92C ''vault'':1A,3A,7B,13B,88C,95C ''vault-tec'':12B,94C ''war'':24B,114C ''when'':21B ''where'':108C ''while'':82B ''who'':60B ''work'':49B,69B', 'vault88_lore', 'Vault 88 is an unfinished underground Vault-Tec shelter beneath Quincy Quarries. Completed entrance rooms lead toward large caverns where construction stopped at the Great War.', 'vault88_observed', 'unfinished vault shelter, quincy quarry construction, vault 88 caverns, interrupted prewar building, underground construction machinery', 'location', NULL, 865, 'vault_88', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '5dd4c9eda12931e03e03bc687c2798b580971067ccde88299f8870583a822187', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('modular_vault_construction', 'Vault room modules,Vault-Tec prefabs', 'Vault-Tec construction uses prefabricated room and corridor sections that fit together into larger shelters. Familiar module types include domestic and utility halls, atrium pieces, stairs, doors, railings and specialized rooms for quarters, security, clinics and the overseer. Components provide a planned alternative to improvised surface shacks. A recognizable module does not establish that a shelter is complete, structurally sound or operating as advertised; those conditions require inspection and maintenance.', '''a'':52B,59B,66B ''advertised'':75B ''alternative'':54B ''and'':18B,32B,40B,47B,80B,92C,102C ''areas'':104C ''arrange'':99C ''as'':74B ''atrium'':35B,90C ''builders'':98C ''can'':84C ''clinics'':46B ''complete'':69B ''components'':50B ''conditions'':77B ''construction'':3A,14B,83C ''corridor'':19B ''does'':62B ''domestic'':31B ''doors'':38B ''establish'':64B ''familiar'':27B ''fit'':22B ''fitted'':86C ''for'':43B ''halls'':34B,88C ''help'':97C ''improvised'':56B ''include'':30B ''inspection'':79B ''into'':24B ''is'':68B ''larger'':25B ''living'':101C ''maintenance'':81B ''modular'':1A,95C ''module'':28B,61B ''modules'':6A ''not'':63B ''operating'':73B ''or'':72B ''overseer'':49B ''pieces'':36B,96C ''planned'':53B ''prefabricated'':16B,87C ''prefabs'':10A ''provide'':51B ''quarters'':44B ''railings'':39B ''recognizable'':60B,94C ''require'':78B ''room'':5A,17B ''rooms'':42B,89C ''sections'':20B,91C ''security'':45B ''service'':103C ''shacks'':58B ''shelter'':67B ''shelters'':26B ''sound'':71B ''specialized'':41B ''stairs'':37B ''structurally'':70B ''supports'':93C ''surface'':57B ''tec'':9A,13B ''that'':21B,65B ''the'':48B ''those'':76B ''to'':55B ''together'':23B ''types'':29B ''underground'':100C ''use'':85C ''uses'':15B ''utility'':33B ''vault'':2A,4A,8A,12B,82C ''vault-tec'':7A,11B', 'vault_construction_knowledge,vault88_lore', 'Vault construction can use fitted prefabricated halls, rooms, atrium sections and supports. Recognizable modular pieces help builders arrange underground living and service areas.', 'vault88_observed,vault_construction_observed', 'modular vault rooms, prefabricated underground halls, vault construction supports, atrium building components, shelter maintenance work', 'technology', NULL, 866, 'modular_vault_construction', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '34fef9bdc15d8d2a5b5d22449609a20dbb03d7b01b2315c320f1bfb2431cd469', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('contraptions_manufacturing', 'manufacturing machines,Contraptions workshop builder', 'Settlement manufacturing equipment can turn supplied components into finished goods using powered machines. A builder produces familiar objects such as toys, sporting items and lunchboxes from materials placed in its own inventory. A connected terminal selects production settings, and conveyor equipment can handle input or output. Other machines specialize in clothing, armor, food or weapons. Resources and compatible machinery limit production; a functioning-looking line does not establish that supplies or finished goods are available.', '''a'':21B,40B,69B ''and'':31B,46B,64B,94C,101C ''are'':82B ''armor'':59B ''as'':27B ''available'':83B ''builder'':7A,22B ''builders'':93C ''can'':11B,49B,87C ''clothing'':58B ''compatible'':65B ''components'':14B,90C ''connected'':41B ''contraptions'':1A,5A ''conveyor'':47B ''does'':74B ''equipment'':10B,48B,96C ''establish'':76B ''familiar'':24B ''finished'':16B,80B ''food'':60B ''from'':33B ''functioning'':71B ''functioning-looking'':70B ''goods'':17B,81B,92C ''handle'':50B ''in'':36B,57B ''input'':51B ''into'':15B,91C ''inventory'':39B ''items'':30B ''its'':37B ''knows'':104C ''limit'':67B ''line'':73B ''looking'':72B ''lunchboxes'':32B ''machinery'':66B ''machines'':4A,20B,55B,86C ''manufacturing'':2A,3A,9B,85C ''materials'':34B,98C ''need'':97C ''not'':75B ''objects'':25B ''or'':52B,61B,79B ''other'':54B ''output'':53B ''own'':38B ''placed'':35B ''powered'':19B,84C ''produces'':23B ''production'':44B,68B,106C ''resources'':63B ''selects'':43B ''settings'':45B,100C ''settlement'':8B ''someone'':102C ''specialize'':56B ''specialized'':95C ''sporting'':29B ''such'':26B ''suitable'':99C ''supplied'':13B,89C ''supplies'':78B ''system'':107C ''terminal'':42B ''that'':77B ''the'':105C ''toys'':28B ''turn'':12B,88C ''using'':18B ''weapons'':62B ''who'':103C ''workshop'':6A', 'contraptions_knowledge', 'Powered manufacturing machines can turn supplied components into goods. Builders and specialized equipment need materials, suitable settings and someone who knows the production system.', 'contraptions_observed', 'powered manufacturing machines, component based production, terminal production settings, settlement industrial equipment, conveyor material handling', 'technology', NULL, 867, 'contraptions_manufacturing', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '4f66b3efa505d168a8e67a5f40e47ff0fb843626428bb6bc91375508c950fe65', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('conveyor_belts', 'conveyor belt,belt splitter', 'Conveyor belts move objects between compatible manufacturing and handling equipment. Straight sections of different lengths and splitters allow an industrial line to distribute items along more than one path. In the settlement machinery system, belts work through a connected powered machine rather than being wired directly to a generator, and longer runs need appropriate additional power connections. Actual movement depends on working equipment and the objects placed on it; the belt itself does not manufacture or identify items.', '''a'':44B,54B ''actual'':64B ''additional'':61B ''allow'':24B ''along'':31B,89C ''an'':25B ''and'':14B,22B,56B,70B,94C,101C ''appropriate'':60B ''being'':50B ''belt'':4A,5A,77B ''belts'':2A,8B,41B,86C ''between'':11B ''carry'':87C ''compatible'':12B,97C ''connect'':96C ''connected'':45B ''connections'':63B,103C ''conveyor'':1A,3A,7B,85C ''depends'':66B ''different'':20B,92C ''directly'':52B ''distribute'':29B ''does'':79B ''equipment'':16B,69B ''generator'':55B ''handling'':15B ''identify'':83B ''in'':36B ''industrial'':26B ''it'':75B ''items'':30B,84B ''itself'':78B ''keep'':104C ''lengths'':21B,93C ''line'':27B,106C ''lines'':91C ''longer'':57B ''machine'':47B ''machinery'':39B,98C ''manufacture'':81B ''manufacturing'':13B,90C ''more'':32B ''move'':9B ''movement'':65B ''moving'':107C ''need'':59B ''not'':80B ''objects'':10B,72B,88C ''of'':19B ''on'':67B,74B ''one'':34B ''or'':82B ''path'':35B ''placed'':73B ''power'':62B,100C ''powered'':46B ''rather'':48B ''runs'':58B ''sections'':18B ''settlement'':38B ''splitter'':6A ''splitters'':23B,95C ''straight'':17B ''system'':40B ''than'':33B,49B ''the'':37B,71B,76B,105C ''through'':43B ''to'':28B,53B ''while'':99C ''wired'':51B ''work'':42B ''working'':68B,102C', 'contraptions_knowledge', 'Conveyor belts carry objects along manufacturing lines. Different lengths and splitters connect compatible machinery, while power and working connections keep the line moving.', 'contraptions_observed', 'industrial conveyor belts, manufacturing material transport, belt splitter equipment, connected powered machinery, settlement production lines', 'technology', NULL, 868, 'conveyor_belts', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '096bcb6f5887b77389a0cfc15210a557d94fa6b62291d103c18ba3aafdfc4071', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('decontamination_arch', 'radiation arch,decontamination equipment', 'A decontamination arch is powered equipment intended to remove accumulated radiation from someone passing through it. The settlement version has an integrated switch and requires connection to a generator. Its presence may be useful to people working around contaminated areas, but it must actually be powered and operating to provide treatment. Recognition alone does not establish how another arch has been configured, whether a person has used it or whether an individual radiation problem has been resolved.', '''a'':7B,34B,70B,84C ''accumulated'':16B ''actually'':50B ''alone'':59B ''an'':27B,77B ''and'':30B,53B,102C ''another'':64B ''arch'':2A,4A,9B,65B,86C ''areas'':46B ''around'':44B ''be'':39B,51B ''been'':67B,82B ''but'':47B ''condition'':104C ''configured'':68B ''connection'':32B,101C ''contaminated'':45B ''decontamination'':1A,5A,8B,85C ''does'':60B ''equipment'':6A,12B,89C ''establish'':62B ''exposure'':93C ''for'':90C ''from'':18B ''generator'':35B,100C ''has'':26B,66B,72B,81B ''how'':63B ''individual'':78B ''integrated'':28B ''intended'':13B ''is'':10B,87C ''it'':22B,48B,74B,98C ''its'':36B,99C ''matter'':105C ''may'':38B ''must'':49B ''not'':61B ''operating'':54B ''or'':75B ''passes'':96C ''passing'':20B ''people'':42B ''person'':71B ''powered'':11B,52B,88C ''presence'':37B ''problem'':80B ''provide'':56B ''radiation'':3A,17B,79B,92C ''recognition'':58B ''reducing'':91C ''remove'':15B ''requires'':31B ''resolved'':83B ''settlement'':24B ''someone'':19B,95C ''switch'':29B ''the'':23B ''through'':21B,97C ''to'':14B,33B,41B,55B ''treatment'':57B ''used'':73B ''useful'':40B ''version'':25B ''when'':94C ''whether'':69B,76B ''working'':43B,103C', 'wasteland_workshop_knowledge', 'A decontamination arch is powered equipment for reducing radiation exposure when someone passes through it. Its generator connection and working condition matter.', 'wasteland_workshop_observed', 'powered decontamination equipment, radiation removal arch, settlement treatment machinery, generator powered arch, equipment working condition', 'technology', NULL, 869, 'decontamination_arch', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '43a7478f8a21d1b922e5c439a76187f550ac1e24d96f706f8bdbe881fdf4e912', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('wasteland_capture_cages', 'capture cages,animal traps', 'Powered capture cages are specialized enclosures designed for particular animals or hostile creatures. Their sizes and bait suit different targets, from small animals to large wasteland predators. After capturing and releasing a target, a cage needs repair before another use. Captivity does not make every creature tame: many remain dangerous when released, and keeping them can attract further trouble to a settlement. A closed cage or noise gives evidence to inspect rather than certainty about the exact creature inside.', '''a'':39B,41B,68B,70B,98C ''about'':82B ''after'':35B,108C ''and'':23B,37B,60B,104C ''animal'':6A ''animals'':17B,30B,95C ''another'':46B ''are'':11B,89C ''attract'':64B ''bait'':24B ''before'':45B ''built'':92C ''cage'':42B,72B ''cages'':3A,5A,10B,88C,105C ''can'':63B ''captivity'':48B ''capture'':2A,4A,9B,87C ''captured'':99C ''capturing'':36B ''certainty'':81B ''closed'':71B ''creature'':53B,85B ''creatures'':20B,97C ''dangerous'':57B,103C ''designed'':14B ''different'':26B ''does'':49B ''enclosures'':13B ''every'':52B ''evidence'':76B ''exact'':84B ''for'':15B,93C ''from'':28B ''further'':65B ''gives'':75B ''hostile'':19B ''inside'':86B ''inspect'':78B ''keeping'':61B ''large'':32B ''make'':51B ''many'':55B ''may'':101C ''need'':106C ''needs'':43B ''noise'':74B ''not'':50B ''or'':18B,73B,96C ''particular'':16B,94C ''powered'':8B,90C ''predator'':100C ''predators'':34B ''rather'':79B ''released'':59B ''releasing'':38B ''remain'':56B,102C ''repair'':44B,107C ''settlement'':69B ''sizes'':22B ''small'':29B ''specialized'':12B ''suit'':25B ''tame'':54B ''target'':40B ''targets'':27B ''than'':80B ''the'':83B ''their'':21B ''them'':62B ''to'':31B,67B,77B ''traps'':7A,91C ''trouble'':66B ''use'':47B,109C ''wasteland'':1A,33B ''when'':58B', 'wasteland_workshop_knowledge', 'Capture cages are powered traps built for particular animals or creatures. A captured predator may remain dangerous, and cages need repair after use.', 'wasteland_workshop_observed', 'powered capture cages, specialized animal traps, dangerous captive predators, settlement cage maintenance, creature release hazards', 'technology', NULL, 870, 'wasteland_capture_cages', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '009db70b4f46d1959af19cf3484c361ca18fe974b66d8d09c4cbeccaac96df6b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('wasteland_vocabulary', 'wasteland terms,postwar vocabulary', 'Everyday wasteland language reflects local food, trade, medicine and survival. A tato is a crop combining tomato and potato traits. Caps are bottle caps used as currency. Chems include medicines and performance drugs with different effects; addictive chems can cause dependence. Rads refer to radiation, while Rad-X and RadAway serve different purposes. Prewar consumer brands and old American entertainment remain recognizable parts of public life.', '''a'':17B,20B,93C,99C ''accumulated'':150C ''addictive'':43B,144C ''advertising'':181C ''aids'':125C ''alongside'':109C ''american'':66B,190C ''and'':15B,24B,37B,56B,64B,82C,96C,135C,152C,169C,184C ''are'':28B,75C,86C,112C,143C,172C ''as'':32B,107C ''atomic'':185C ''barter'':110C ''belong'':187C ''bombs'':171C ''boosts'':128C ''bottle'':29B,104C ''brands'':63B,175C ''buffout'':127C ''can'':45B ''canned'':167C ''caps'':27B,30B,102C,105C ''cause'':46B ''changes'':117C ''cheerful'':180C ''chems'':34B,44B,111C,142C ''cola'':166C ''combat'':126C ''combining'':22B,80C ''consumer'':62B,174C ''cram'':168C ''crop'':21B,101C ''crops'':79C ''cultivated'':94C ''culture'':191C ''currency'':33B ''dependence'':47B ''different'':41B,59B ''diners'':179C ''do'':204C ''domestic'':182C ''drugs'':39B,115C ''effects'':42B ''entertainment'':67B ''everyday'':7B ''everyone'':207C ''exposure'':163C ''faculties'':134C ''familiar'':76C,189C ''fashioned'':201C ''food'':12B,78C ''formal'':195C ''from'':194C ''fruit'':95C ''give'':206C ''grain'':100C ''improves'':157C ''include'':35B ''injuries'':147C ''is'':19B,92C,98C ''jet'':116C ''language'':9B ''life'':73B ''local'':11B ''means'':103C,161C ''med'':137C ''med-x'':136C ''medicine'':14B ''medicines'':36B,113C ''mental'':133C ''mentats'':131C ''money'':108C ''mutfruit'':91C ''not'':87C,205C ''nuka'':165C ''nuka-cola'':164C ''of'':71B,122C ''old'':65B,176C,200C ''old-fashioned'':199C ''or'':114C,198C ''ordinary'':89C ''pain'':140C ''parts'':70B ''perception'':121C ''performance'':38B,130C ''physical'':129C ''postwar'':5A ''potato'':25B,83C ''prewar'':61B ''psycho'':124C ''public'':72B ''purposes'':60B ''rad'':54B,154C ''rad-x'':53B,153C ''radaway'':57B,148C ''radiation'':51B,151C,158C,162C ''radio'':177C ''rads'':48B,160C ''ranges'':193C ''razorgrain'':97C ''recognizable'':69B,173C ''refer'':49B ''reflects'':10B ''relieves'':139C ''remain'':68B ''removes'':149C ''resistance'':159C ''robots'':183C ''rough'':197C ''s'':120C ''same'':209C ''serve'':58B ''sharpen'':132C ''simply'':88C ''slang'':210C ''some'':141C ''songs'':178C ''speech'':192C ''stimpaks'':145C ''sugar'':170C ''surroundings'':203C ''survival'':16B ''tato'':18B ''tatos'':74C ''technology'':186C ''temporarily'':156C ''terms'':4A ''the'':118C,208C ''they'':85C ''those'':202C ''time'':123C ''to'':50B,188C,196C ''tomato'':23B,81C ''tomatoes'':90C ''trade'':13B ''traits'':26B,84C ''treat'':146C ''used'':31B,106C ''user'':119C ''vocabulary'':2A,6A ''wasteland'':1A,3A,8B,77C ''while'':52B ''with'':40B ''x'':55B,138C,155C', 'commonwealth_lore', 'Tatos are familiar wasteland food crops combining tomato and potato traits; they are not simply ordinary tomatoes. Mutfruit is a cultivated fruit, and razorgrain is a grain crop. Caps means bottle caps used as money, alongside barter. Chems are medicines or drugs: Jet changes the user''s perception of time, Psycho aids combat, Buffout boosts physical performance, Mentats sharpen mental faculties, and Med-X relieves pain. Some chems are addictive. Stimpaks treat injuries, RadAway removes accumulated radiation, and Rad-X temporarily improves radiation resistance. Rads means radiation exposure. Nuka-Cola, canned Cram and Sugar Bombs are recognizable consumer brands. Old radio songs, diners, cheerful advertising, domestic robots and atomic technology belong to familiar American culture. Speech ranges from formal to rough or old-fashioned; those surroundings do not give everyone the same slang.', 'common', 'wasteland vocabulary, everyday terminology, postwar public knowledge, familiar consumer culture', 'concept', NULL, 871, 'wasteland_vocabulary', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'f0df2c7189d6fae014de996bd7354f85b0ee5ce282aa7a9fa8d1ea2ef5c26a48', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('tato', 'tatos,tatoes', 'Tatos are hardy food crops with tomato-like skin and potato-like flesh. Botanical notes describe the plant as a hybrid associated with the old tomato and potato. The red appearance does not make a tato an ordinary tomato. Farmers cultivate tatos for food and trade, and the crop also appears in cooking and vegetable-starch production. Taste and crop quality can vary.', '''a'':25B,40B,70C,73C ''also'':55B ''an'':42B ''and'':14B,32B,50B,52B,59B,65B,83C,91C,95C ''appearance'':36B ''appears'':56B ''are'':5B,87C ''as'':24B ''associated'':27B ''botanical'':19B ''can'':68B ''combining'':81C ''cooking'':58B ''crop'':54B,66B,80C ''crops'':8B ''cultivate'':46B ''describe'':21B ''does'':37B ''familiar'':74C ''farmers'':45B ''farms'':90C ''flesh'':18B ''food'':7B,49B,79C,94C ''for'':48B,93C ''grown'':88C ''hardy'':6B ''hybrid'':26B ''in'':57B ''is'':72C ''like'':12B,17B ''make'':39B ''not'':38B ''notes'':20B ''old'':30B ''on'':89C ''ordinary'':43B ''plant'':23B ''potato'':16B,33B,84C ''potato-like'':15B ''production'':63B ''quality'':67B ''red'':35B,76C ''red-skinned'':75C ''skin'':13B ''skinned'':77C ''starch'':62B ''taste'':64B ''tato'':1A,41B,71C ''tatoes'':3A ''tatos'':2A,4B,47B,86C ''the'':22B,29B,34B,53B ''tomato'':11B,31B,44B,82C ''tomato-like'':10B ''trade'':51B,96C ''traits'':85C ''used'':92C ''vary'':69B ''vegetable'':61B ''vegetable-starch'':60B ''wasteland'':78C ''with'':9B,28B', 'farmer,agriculture', 'A tato is a familiar red-skinned wasteland food crop combining tomato and potato traits. Tatos are grown on farms and used for food and trade.', 'common', 'tato crop, hybrid food plant, tomato potato traits, wasteland produce', 'flora', NULL, 872, 'tato', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '13eb21304a43209fe88055f3513018f08aa595b393ff03dee9c01ebc1e9a69d8', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('mutfruit', 'mutfruits', 'Mutfruit grows on small trees and is cultivated as a food crop. Farmers value its sweet fruit for eating, cooking and sale. Harvested fruit can supply planting stock, while wild mutfruit is a distinct gathered ingredient. Corn, tatos and mutfruit can contribute to vegetable starch when combined with water. Familiarity with the crop does not imply knowledge of a particular farm''s stock or experimental strains.', '''a'':12B,35B,61B,71C ''and'':8B,23B,41B,78C,85C ''are'':88C ''as'':11B ''can'':27B,43B ''combined'':49B ''contribute'':44B ''cooking'':22B ''corn'':39B ''crop'':14B,55B ''cultivated'':10B,83C ''distinct'':36B,89C ''does'':56B ''eating'':21B ''experimental'':67B ''familiar'':79C ''familiarity'':52B ''farm'':63B ''farmers'':15B ''farms'':82C ''food'':13B,93C ''for'':20B,92C ''fruit'':19B,26B,73C ''gathered'':37B ''grown'':74C ''grows'':4B ''harvested'':25B ''imply'':58B ''ingredient'':38B ''ingredients'':90C ''is'':9B,34B,70C ''its'':17B ''knowledge'':59B ''mutfruit'':1A,3B,33B,42B,69C,84C,87C ''mutfruits'':2A ''not'':57B ''of'':60B ''on'':5B,75C,80C ''or'':66B ''particular'':62B ''planting'':29B ''s'':64B ''sale'':24B ''small'':6B,76C ''starch'':47B ''stock'':30B,65B ''strains'':68B ''supply'':28B ''sweet'':18B,72C ''tatos'':40B ''the'':54B ''to'':45B ''trees'':7B,77C ''used'':91C ''value'':16B ''vegetable'':46B ''wasteland'':81C ''water'':51B ''when'':48B ''while'':31B ''wild'':32B,86C ''with'':50B,53B', 'farmer,agriculture', 'Mutfruit is a sweet fruit grown on small trees and familiar on wasteland farms. Cultivated mutfruit and wild mutfruit are distinct ingredients used for food.', 'common', 'mutfruit cultivation, sweet tree fruit, cultivated fruit, wild crop distinction', 'flora', NULL, 873, 'mutfruit', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '403bd8c1877e3efa1558313d73b138454dadf8972a41cec3c130fe03b9ad75b8', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('razorgrain', 'razorgrains', 'Razorgrain is a cultivated grain with an edible harvest. Botanical notes discuss its use as a possible substitute where wheat is difficult to grow, while also noting unreliable yields. Grinding and cooking make it useful in food preparation. It appears in dishes such as noodle cups and mirelurk cakes. The crop''s name does not make every old wheat product razorgrain.', '''a'':5B,18B,66C ''also'':28B ''an'':9B ''and'':33B,49B,84C ''appears'':42B ''as'':17B,46B ''botanical'':12B ''cakes'':51B ''cooking'':34B,80C ''crop'':53B,70C ''cultivated'':6B ''cups'':48B,83C ''difficult'':24B ''discuss'':14B ''dishes'':44B,87C ''does'':56B ''edible'':10B ''every'':59B ''familiar'':67C ''food'':39B,73C ''for'':72C ''grain'':7B,69C,76C ''grinding'':32B ''grow'':26B ''grown'':71C ''harvest'':11B ''harvested'':75C ''in'':38B,43B,79C ''including'':81C ''is'':4B,23B,65C,77C ''it'':36B,41B ''its'':15B,74C ''make'':35B,58B ''mirelurk'':50B ''name'':55B ''noodle'':47B,82C ''not'':57B ''notes'':13B ''noting'':29B ''old'':60B ''other'':85C ''possible'':19B ''preparation'':40B ''prepared'':86C ''product'':62B ''razorgrain'':1A,3B,63B,64C ''razorgrains'':2A ''s'':54B ''substitute'':20B ''such'':45B ''the'':52B ''to'':25B ''unreliable'':30B ''use'':16B ''used'':78C ''useful'':37B ''wasteland'':68C ''wheat'':22B,61B ''where'':21B ''while'':27B ''with'':8B ''yields'':31B', 'farmer,agriculture', 'Razorgrain is a familiar wasteland grain crop grown for food. Its harvested grain is used in cooking, including noodle cups and other prepared dishes.', 'common', 'razorgrain harvest, edible grain crop, grain food preparation, cultivated cereal plant', 'flora', NULL, 874, 'razorgrain', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c424a52f9c5a7883032f487fa09563429bd15b63c8c2f205f3d48c63442f8054', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('tarberry', 'tarberries', 'Tarberries are small purple berries associated with water-growing plants. They can be cultivated in shallow water, including adapted pools, rather than ordinary dry crop rows. The berries are eaten and used in food preparation and some chem recipes. Their usefulness makes them a trade crop. Knowing tarberries does not establish knowledge of a particular grower''s location, harvest or trading arrangements.', '''a'':47B,57B,82C ''adapted'':22B ''and'':34B,39B,74C,78C ''are'':4B,32B,67C,81C ''arrangements'':65B ''associated'':8B ''be'':16B ''berries'':7B,31B,70C ''can'':15B ''chem'':41B ''crop'':28B,49B,84C ''cultivated'':17B ''different'':86C ''does'':52B ''dry'':27B,91C ''dry-field'':90C ''eaten'':33B ''establish'':54B ''familiar'':83C ''field'':92C ''food'':37B,77C ''for'':76C ''from'':89C ''grower'':59B ''growing'':12B,87C ''grown'':71C ''harvest'':62B ''in'':18B,36B,72C ''including'':21B ''knowing'':50B ''knowledge'':55B ''location'':61B ''makes'':45B ''needs'':88C ''not'':53B ''of'':56B ''or'':63B ''ordinary'':26B ''particular'':58B ''plants'':13B,93C ''pools'':23B ''preparation'':38B ''purple'':6B,69C ''rather'':24B ''recipes'':42B ''rows'':29B ''s'':60B ''shallow'':19B ''small'':5B,68C ''some'':40B ''tarberries'':2A,3B,51B,66C ''tarberry'':1A ''than'':25B ''the'':30B ''their'':43B ''them'':46B ''they'':14B,80C ''trade'':48B,79C ''trading'':64B ''used'':35B,75C ''usefulness'':44B ''water'':11B,20B,73C ''water-growing'':10B ''with'':9B,85C', 'farmer,agriculture', 'Tarberries are small purple berries grown in water and used for food and trade. They are a familiar crop with different growing needs from dry-field plants.', 'common', 'tarberry cultivation, water grown berries, purple food berries, aquatic crop harvest', 'flora', NULL, 875, 'tarberry', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '6fccf0809d918b3647e4a37781c77377ad3b0af544158a0616984ef75247a549', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('wasteland_cooking', 'wasteland meals,cooked wasteland food', 'Wasteland cooks make meals from harvested crops and local meat using fires, pots, stoves and cooking stations. Familiar dishes include grilled radstag, roasted mirelurk meat and vegetable soup. Many prepared dishes have different effects and radiation levels from their raw ingredients. Preparing a meal does not establish that a particular kitchen has supplies or that any named person has already eaten. Local recipes and available ingredients vary.', '''a'':50B,56B ''already'':67B ''and'':15B,22B,33B,42B,71B,79C,93C ''any'':63B ''are'':96C ''at'':86C ''available'':72B ''cooked'':5A ''cooking'':2A,23B,87C ''cooks'':9B ''crops'':14B,78C ''different'':40B ''dishes'':26B,38B,95C ''does'':52B ''eaten'':68B ''effects'':41B ''establish'':54B ''familiar'':25B,97C ''fires'':19B,84C ''food'':7A ''foods'':99C ''from'':12B,45B ''grilled'':28B,89C ''harvested'':13B ''has'':59B,66B ''have'':39B ''include'':27B ''ingredients'':48B,73B ''kitchen'':58B ''levels'':44B ''local'':16B,69B,80C ''make'':10B ''many'':36B ''meal'':51B ''meals'':4A,11B,76C ''meat'':17B,32B,81C ''mirelurk'':31B,92C ''named'':64B ''not'':53B ''or'':61B,85C ''over'':83C ''particular'':57B ''person'':65B ''pots'':20B ''prepared'':37B,82C,98C ''preparing'':49B ''radiation'':43B ''radstag'':29B,90C ''raw'':47B ''recipes'':70B ''roasted'':30B,91C ''soup'':35B ''stations'':24B,88C ''stoves'':21B ''supplies'':60B ''that'':55B,62B ''their'':46B ''use'':77C ''using'':18B ''vary'':74B ''vegetable'':34B,94C ''wasteland'':1A,3A,6A,8B,75C', 'farmer,agriculture', 'Wasteland meals use crops and local meat, prepared over fires or at cooking stations. Grilled radstag, roasted mirelurk and vegetable dishes are familiar prepared foods.', 'common', 'wasteland food preparation, cooked local meat, crop based meals, cooking station dishes', 'food_drink', NULL, 876, 'wasteland_cooking', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'c0b8373b3e9af32982f401dd7a38d41e7ee7da241e7f55270b9c4c038b6c6929', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('prewar_packaged_food', 'Cram,can of Cram,Sugar Bombs,Blamco Brand Mac and Cheese,Fancy Lads Snack Cakes,InstaMash', 'Familiar packaged-food brands include Cram canned meat, Sugar Bombs breakfast cereal, Blamco macaroni and cheese, Fancy Lads snack cakes and InstaMash. Wasteland supplies include both preserved and irradiated examples. A recognizable brand does not prove that a particular package is safe or centuries old: some food can also be manufactured using surviving equipment. Traders distinguish the actual product and its condition rather than treating every box as identical.', '''a'':51B,58B,112C ''actual'':78B ''also'':69B ''and'':13A,35B,41B,48B,80B,102C,109C ''as'':88B ''be'':70B ''blamco'':10A,33B,99C ''bombs'':9A,30B,95C ''both'':46B ''box'':87B ''brand'':11A,53B ''brands'':24B ''breakfast'':31B,97C ''cakes'':18A,40B,108C ''can'':5A,68B ''canned'':27B,92C ''centuries'':64B ''cereal'':32B,98C ''cheese'':14A,36B,103C ''condition'':82B ''cram'':4A,7A,26B,90C ''distinguish'':76B ''does'':54B ''equipment'':74B ''every'':86B ''examples'':50B ''familiar'':20B ''fancy'':15A,37B,104C ''food'':3A,23B,67B,115C ''identical'':89B ''include'':25B,45B ''instamash'':19A,42B,110C ''irradiated'':49B ''is'':61B,91C,96C,111C ''its'':81B ''lads'':16A,38B,105C ''mac'':12A ''macaroni'':34B,101C ''makes'':100C,106C ''manufactured'':71B ''meat'':28B,93C ''not'':55B ''of'':6A ''old'':65B ''or'':63B ''package'':60B ''packaged'':2A,22B,113C ''packaged-food'':21B ''particular'':59B ''potato'':114C ''preserved'':47B ''prewar'':1A ''product'':79B ''prove'':56B ''rather'':83B ''recognizable'':52B ''safe'':62B ''snack'':17A,39B,107C ''some'':66B ''sugar'':8A,29B,94C ''supplies'':44B ''surviving'':73B ''than'':84B ''that'':57B ''the'':77B ''traders'':75B ''treating'':85B ''using'':72B ''wasteland'':43B', 'trader,caravan_trade', 'Cram is canned meat, Sugar Bombs is breakfast cereal, Blamco makes macaroni and cheese, Fancy Lads makes snack cakes, and InstaMash is a packaged potato food.', 'common,prewar', 'packaged food brands, canned meat products, branded breakfast cereal, preserved food supplies', 'food_drink', NULL, 877, 'prewar_packaged_food', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '4a4bdd14cc16ff5f769a09c90a4e16ca51aa56858ecb182b0b7802bde3f76569', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('bottle_caps', 'caps,bottlecap,bottlecaps', 'Bottle caps are a widely accepted wasteland currency, especially recognizable Nuka-Cola caps. Commonwealth merchants use them for goods and services, while barter remains possible. Caps are money because people accept them in exchange; their value in a particular deal depends on the goods and the bargain. The Commonwealth''s use of caps does not establish that its currency shares the Hub''s western water-backing system or a fixed exchange rate with old dollars.', '''a'':9B,44B,76B ''accept'':37B ''accepted'':11B ''also'':101C ''and'':26B,51B,97C,104C ''are'':8B,33B,84C ''as'':88C ''backing'':73B ''bargain'':53B ''barter'':29B,102C ''because'':35B ''bottle'':1A,6B,85C ''bottlecap'':4A ''bottlecaps'':5A ''by'':90C ''caps'':2A,3A,7B,19B,32B,59B,83C,86C ''cola'':18B ''commonwealth'':20B,55B ''currency'':13B,65B ''deal'':46B ''deals'':107C ''depends'':47B ''does'':60B ''dollars'':82B ''especially'':14B ''establish'':62B ''exchange'':40B,78B ''fixed'':77B ''for'':24B,95C ''goods'':25B,50B,96C ''hub'':68B ''in'':39B,43B ''individual'':106C ''items'':103C ''its'':64B ''merchants'':21B ''money'':34B,89C ''negotiate'':105C ''not'':61B ''nuka'':17B ''nuka-cola'':16B ''of'':58B ''old'':81B ''on'':48B ''or'':75B ''particular'':45B ''pay'':94C ''people'':36B,100C ''possible'':31B ''rate'':79B ''recognizable'':15B ''remains'':30B ''s'':56B,69B ''services'':27B,98C ''shares'':66B ''system'':74B ''that'':63B ''the'':49B,52B,54B,67B ''their'':41B ''them'':23B,38B ''they'':93C ''traders'':92C ''use'':22B,57B ''used'':87C ''value'':42B ''wasteland'':12B,91C ''water'':72B ''water-backing'':71B ''western'':70B ''while'':28B,99C ''widely'':10B ''with'':80B', 'trader,caravan_trade', 'Caps are bottle caps used as money by wasteland traders. They pay for goods and services, while people also barter items and negotiate individual deals.', 'common', 'bottle cap currency, wasteland money, cap based trade, merchant barter payments', 'item', NULL, 878, 'bottle_caps', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '01a87892c1782cc6aba8214e2487ab938e71f4593ab5d2683707beaed560ff7a', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('chems', 'chem,chemical drugs', 'Chems include injury treatments, radiation medicines and drugs used for mental or physical performance. Stimpaks, RadAway and Rad-X serve different medical purposes; Jet, Psycho, Buffout, Mentats and Med-X have their own temporary effects. Many performance chems can cause addiction, but that does not mean every medicine is addictive. A brand name alone does not establish someone''s current condition, dependence, supplies or consent to treatment.', '''a'':57B ''addiction'':47B ''addictive'':56B,89C ''alone'':60B ''and'':11B,21B,33B,86C,94C ''are'':75C,88C,97C ''brand'':58B ''buffout'':31B ''but'':48B ''can'':45B ''cause'':46B ''chem'':2A ''chemical'':3A ''chems'':1A,5B,44B,74C ''condition'':67B ''consent'':71B ''current'':66B ''dependence'':68B ''differ'':85C ''different'':26B ''distinct'':98C ''does'':50B,61B ''drugs'':4A,12B,78C,96C ''effects'':41B,84C ''establish'':63B ''every'':53B ''for'':14B ''have'':37B ''in'':80C ''include'':6B ''injury'':7B,90C ''is'':55B ''jet'':29B ''many'':42B ''mean'':52B ''med'':35B ''med-x'':34B ''medical'':27B ''medicine'':54B,93C ''medicines'':10B,76C ''mental'':15B ''mentats'':32B ''name'':59B ''not'':51B,62B ''or'':16B,70B,77C ''own'':39B ''performance'':18B,43B,95C ''physical'':17B ''psycho'':30B ''purposes'':28B ''rad'':23B ''rad-x'':22B ''radaway'':20B ''radiation'':9B,92C ''s'':65B ''serve'':25B ''some'':87C ''someone'':64B ''stimpaks'':19B ''supplies'':69B ''temporary'':40B ''that'':49B ''the'':81C ''their'':38B,83C ''to'':72B ''treatment'':73B,91C ''treatments'':8B ''used'':13B,79C ''uses'':99C ''wasteland'':82C ''x'':24B,36B', 'medicine,doctor', 'Chems are medicines or drugs used in the wasteland. Their effects differ, and some are addictive; injury treatment, radiation medicine and performance drugs are distinct uses.', 'common', 'wasteland chem terminology, fictional medical products, performance drug effects, chem addiction recognition', 'medicine', NULL, 879, 'chems', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '7f722f82928bf53d279539d19aefb1c9db660d2573fcaed4bd34506bd761186f', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('jet', 'Jet inhaler', 'Jet is commonly recognizable as a small inhaler chem. It alters consciousness so that the user experiences time as slowing for a short period. This is an effect on the user''s perception and performance, rather than proof that the whole world stops. Jet can cause addiction.', '''a'':9B,25B,74C ''addiction'':50B ''addictive'':54C ''alters'':14B ''an'':30B,53C ''and'':37B ''as'':8B,22B,73C ''briefly'':58C ''can'':48B ''cause'':49B ''changes'':59C ''chem'':12B,56C ''commonly'':6B ''consciousness'':15B ''drug'':77C ''effect'':31B ''events'':67C ''experiences'':20B ''familiar'':72C ''for'':24B ''inhaler'':3A,11B,55C ''is'':5B,29B,52C,71C ''it'':13B,70C ''jet'':1A,2A,4B,47B,51C ''making'':66C ''of'':64C ''on'':32B ''perception'':36B,63C ''performance'':38B,76C ''period'':27B ''proof'':41B ''rather'':39B ''recognizable'':7B ''s'':35B,62C ''seem'':68C ''short'':26B ''slower'':69C ''slowing'':23B ''small'':10B ''so'':16B ''stops'':46B ''than'':40B ''that'':17B,42B,57C ''the'':18B,33B,43B,60C ''this'':28B ''time'':21B,65C ''user'':19B,34B,61C ''wasteland'':75C ''whole'':44B ''world'':45B', 'medicine,doctor', 'Jet is an addictive inhaler chem that briefly changes the user''s perception of time, making events seem slower. It is familiar as a wasteland performance drug.', 'common', 'jet inhaler chem, altered time perception, addictive performance drug, temporary consciousness effect', 'medicine', NULL, 880, 'jet', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'db2a13e8c9ccb9545b6e2b59ef494ee5a3b7c07b105cd98870c76e50024c38da', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('psycho', 'Psycho chem', 'Psycho was developed for military combat performance before the Great War. It is recognizable as an injected chem and temporarily increases combat effectiveness and resistance to harm. Psycho can cause addiction. Mixed products bearing its name have their own effects and should not all be treated as the same substance. Its reputation does not establish that a particular person is using it or is inevitably violent.', '''a'':60B ''addiction'':34B,89C ''all'':47B ''an'':19B,72C ''and'':22B,27B,44B,82C,90C ''as'':18B,50B ''be'':48B ''bearing'':37B ''before'':11B ''can'':32B,87C ''cause'':33B,88C ''chem'':3A,21B,75C ''combat'':9B,25B,74C ''developed'':6B ''distinct'':92C ''does'':56B ''effectiveness'':26B ''effects'':43B ''establish'':58B ''fighting'':80C ''for'':7B ''from'':93C ''great'':13B ''harm'':30B,85C ''have'':40B ''improve'':79C ''increases'':24B ''inevitably'':68B ''injectable'':73C ''injected'':20B ''injury'':95C ''is'':16B,63B,67B,71C,91C ''it'':15B,65B,86C ''its'':38B,54B ''military'':8B ''mixed'':35B ''name'':39B ''not'':46B,57B ''or'':66B ''ordinary'':94C ''own'':42B ''particular'':61B ''performance'':10B,81C ''person'':62B ''products'':36B ''psycho'':1A,2A,4B,31B,70C ''recognizable'':17B ''reputation'':55B ''resistance'':28B,83C ''same'':52B ''should'':45B ''substance'':53B ''temporarily'':23B,78C ''that'':59B ''the'':12B,51B ''their'':41B ''to'':29B,77C,84C ''treated'':49B ''treatment'':96C ''used'':76C ''using'':64B ''violent'':69B ''war'':14B ''was'':5B', 'medicine,doctor,soldier', 'Psycho is an injectable combat chem used to temporarily improve fighting performance and resistance to harm. It can cause addiction and is distinct from ordinary injury treatment.', 'common,prewar', 'psycho combat chem, military performance drug, injectable chem recognition, combat chem addiction', 'medicine', NULL, 881, 'psycho', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '7c42b344aae256393ffa936ac361a6301dfe1805768d263f8a3d570d7e8dbd9b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('buffout', 'Buffout chem', 'Buffout is a steroid chem associated with physical performance and was popular with athletes before the Great War. It temporarily improves strength, endurance and resilience. It is commonly found in a small jar rather than being the same product as an inhaler or injectable chem. Buffout can cause addiction, and its combined variants should not automatically be assigned every effect of the original product.', '''a'':6B,34B,70C,90C ''addiction'':52B,85C ''an'':44B ''and'':13B,27B,53B,79C,86C ''as'':43B,89C ''assigned'':61B ''associated'':9B ''athletes'':17B ''automatically'':59B ''be'':60B ''before'':18B ''being'':39B ''buffout'':1A,2A,4B,49B,68C ''can'':50B,83C ''cause'':51B,84C ''chem'':3A,8B,48B,72C ''combined'':55B ''commonly'':31B ''effect'':63B ''endurance'':26B,78C ''enhancing'':93C ''every'':62B ''found'':32B ''great'':20B ''improve'':76C ''improves'':24B ''in'':33B ''inhaler'':45B ''injectable'':47B ''is'':5B,30B,69C,87C ''it'':22B,29B,82C ''its'':54B ''jar'':36B ''not'':58B ''of'':64B ''or'':46B ''original'':66B ''performance'':12B,92C ''performance-enhancing'':91C ''physical'':11B,80C ''popular'':15B ''product'':42B,67B,94C ''rather'':37B ''recognizable'':88C ''resilience'':28B,81C ''same'':41B ''should'':57B ''small'':35B ''steroid'':7B,71C ''strength'':25B,77C ''temporarily'':23B,75C ''than'':38B ''the'':19B,40B,65B ''to'':74C ''used'':73C ''variants'':56B ''war'':21B ''was'':14B ''with'':10B,16B', 'medicine,doctor', 'Buffout is a steroid chem used to temporarily improve strength, endurance and physical resilience. It can cause addiction and is recognizable as a performance-enhancing product.', 'common,prewar', 'buffout steroid chem, athletic performance product, temporary physical enhancement, physical chem addiction', 'medicine', NULL, 882, 'buffout', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'bb37722192c232dff141872a5a94322907e3f507e8aa962a56191c452c5503d1', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('mentats', 'Mentat,Mentats chem', 'Mentats are a branded chem associated with temporary improvements in perception and intellectual performance. Their recognizable tin contains tablets. Mentats can cause addiction, so their reputation as a mental aid does not make them harmless. Flavored or altered varieties have different specialties, including Berry, Grape and Orange Mentats; knowledge of the ordinary product does not establish the effects of every variant.', '''a'':7B,32B,68C ''addiction'':27B,82C ''aid'':34B ''altered'':42B ''and'':16B,50B,76C,83C ''are'':6B,67C,84C ''as'':31B ''associated'':10B ''berry'':48B ''branded'':8B,88C ''by'':86C ''can'':25B,80C ''cause'':26B,81C ''chem'':4A,9B,70C ''contains'':22B ''different'':45B ''does'':35B,58B ''effects'':62B ''establish'':60B ''every'':64B ''flavored'':40B ''grape'':49B ''harmless'':39B ''have'':44B ''improvements'':13B ''in'':14B ''including'':47B ''intellectual'':17B ''knowledge'':53B ''make'':37B ''mental'':33B,77C ''mentat'':2A ''mentats'':1A,3A,5B,24B,52B,66C ''not'':36B,59B ''of'':54B,63B ''or'':41B ''orange'':51B ''ordinary'':56B ''packaging'':90C ''perception'':15B,75C ''performance'':18B,78C ''product'':57B ''recognizable'':20B,85C ''reputation'':30B ''sharpen'':74C ''so'':28B ''specialties'':46B ''tablet'':69C ''tablets'':23B ''temporarily'':73C ''temporary'':12B ''the'':55B,61B ''their'':19B,29B,87C ''them'':38B ''they'':79C ''tin'':21B,89C ''to'':72C ''used'':71C ''variant'':65B ''varieties'':43B ''with'':11B', 'medicine,doctor', 'Mentats are a tablet chem used to temporarily sharpen perception and mental performance. They can cause addiction and are recognizable by their branded tin packaging.', 'common,prewar', 'mentats mental aid, tablet chem recognition, temporary perception enhancement, mental chem addiction', 'medicine', NULL, 883, 'mentats', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '0c63563a808659b14b68224a75c34fa4efebb8ea4a3208130072434cb2e1b355', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('med_x', 'MedX', 'Med-X is an injectable painkiller. Its effect includes temporarily improving resistance to harm, but pain relief and resistance are different from repairing an injury with a stimpak. Med-X can cause addiction. The syringe''s presence alone does not establish that a person has used it or that an injury has healed. Recognizing the product is distinct from having the medical skill or supplies to treat someone.', '''a'':31B,48B,100C ''addiction'':38B,90C ''alone'':43B ''an'':8B,28B,55B,78C ''and'':22B,91C ''are'':24B ''but'':19B ''can'':36B,88C ''cause'':37B,89C ''different'':25B ''differs'':95C ''distinct'':63B ''does'':44B ''effect'':12B ''establish'':46B ''from'':26B,64B,96C ''harm'':18B,86C ''has'':50B,57B ''having'':65B ''healed'':58B ''improves'':83C ''improving'':15B ''includes'':13B ''injectable'':9B,79C ''injuries'':98C ''injury'':29B,56B ''is'':7B,62B,77C ''it'':52B,87C ''its'':11B,92C ''med'':1A,5B,34B,75C ''med-x'':4B,33B,74C ''medical'':67B ''medx'':3A ''not'':45B ''or'':53B,69B ''pain'':20B,93C ''painkiller'':10B,80C ''person'':49B ''presence'':42B ''product'':61B ''recognizing'':59B ''relief'':21B,94C ''repairing'':27B,97C ''resistance'':16B,23B,84C ''s'':41B ''skill'':68B ''someone'':73B ''stimpak'':32B,101C ''supplies'':70B ''syringe'':40B ''temporarily'':14B,82C ''that'':47B,54B,81C ''the'':39B,60B,66B ''to'':17B,71B,85C ''treat'':72B ''used'':51B ''with'':30B,99C ''x'':2A,6B,35B,76C', 'medicine,doctor', 'Med-X is an injectable painkiller that temporarily improves resistance to harm. It can cause addiction, and its pain relief differs from repairing injuries with a stimpak.', 'common,prewar', 'med x painkiller, injectable pain medicine, temporary damage resistance, pain relief distinction', 'medicine', NULL, 884, 'med_x', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '6b4836f7e99e2686895fe78266b88e536774510e9a6178dd860276022779fb88', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('rad_x', 'RadX', 'Rad-X temporarily increases resistance to radiation. It is intended to reduce the impact of exposure and is distinct from RadAway, which removes radiation already accumulated in the body. Rad-X is commonly recognizable as pills in a medicine bottle. Protection is limited and does not make someone immune to radiation or prove that a contaminated area is safe. Its use does not establish that existing exposure has been removed.', '''a'':43B,60B,80C ''accumulated'':30B,97C ''already'':29B ''and'':21B,49B,99C ''area'':62B ''as'':40B ''been'':74B ''body'':33B ''bottle'':45B ''commonly'':38B ''complete'':103C ''contaminated'':61B ''differs'':92C ''distinct'':23B ''does'':50B,67B,100C ''establish'':69B ''existing'':71B ''exposure'':20B,72B,90C ''from'':24B,93C ''has'':73B ''immune'':54B ''immunity'':104C ''impact'':18B ''improves'':87C ''in'':31B,42B ''increases'':8B ''intended'':14B ''is'':13B,22B,37B,47B,63B,79C ''it'':12B,91C ''its'':65B ''limited'':48B ''make'':52B ''medicine'':44B,84C ''not'':51B,68B,101C ''of'':19B ''or'':57B ''pills'':41B ''protection'':46B,83C ''prove'':58B ''provide'':102C ''rad'':1A,5B,35B,77C ''rad-x'':4B,34B,76C ''radaway'':25B,94C ''radiation'':11B,28B,56B,82C,98C ''radiation-protection'':81C ''radx'':3A ''recognizable'':39B ''reduce'':16B ''removed'':75B ''removes'':27B,96C ''resistance'':9B,88C ''safe'':64B ''someone'':53B ''temporarily'':7B,86C ''that'':59B,70B,85C ''the'':17B,32B ''to'':10B,15B,55B,89C ''use'':66B ''which'':26B,95C ''x'':2A,6B,36B,78C', 'medicine,doctor', 'Rad-X is a radiation-protection medicine that temporarily improves resistance to exposure. It differs from RadAway, which removes accumulated radiation, and does not provide complete immunity.', 'common,prewar', 'rad x protection, temporary radiation resistance, radiation medicine distinction, exposure prevention medicine', 'medicine', NULL, 885, 'rad_x', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '7d8d8cc5b7e604becb82991992203f6bc427efbb4a28027225575bcedb7dbfd2', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('addictol', 'Addictol inhaler', 'Addictol is a medicine used to remove chem dependence and alcohol addiction. Its small inhaler can resemble Jet, although the products have different purposes. Removing an existing addiction is distinct from preventing future dependence after more use. Recognition of Addictol does not establish that it is available, that treatment has happened, or that a particular person''s complicated medical history has been resolved.', '''a'':6B,57B,69C,88C ''addiction'':15B,31B,75C,90C ''addictol'':1A,2A,4B,43B,67C ''after'':38B ''alcohol'':14B ''although'':22B ''an'':29B,80C ''and'':13B ''as'':79C ''available'':50B,93C ''been'':65B ''can'':19B ''chem'':11B,74C ''complicated'':61B ''current'':89C ''dependence'':12B,37B ''different'':26B ''distinct'':33B ''does'':44B,85C ''establish'':46B,87C ''existing'':30B ''from'':34B ''future'':36B ''happened'':54B ''has'':53B,64B ''have'':25B ''history'':63B ''inhaler'':3A,18B,81C ''is'':5B,32B,49B,68C,77C ''it'':48B,76C ''its'':16B ''jet'':21B ''medical'':62B ''medicine'':7B,70C ''more'':39B ''not'':45B,86C ''of'':42B ''or'':55B,92C ''particular'':58B ''person'':59B ''preventing'':35B ''product'':84C ''products'':24B ''purposes'':27B ''recognition'':41B ''recognizing'':82C ''remove'':10B ''removing'':28B ''resemble'':20B ''resolved'':66B ''s'':60B ''small'':17B ''stock'':94C ''supplied'':78C ''that'':47B,51B,56B ''the'':23B,83C ''to'':9B,72C ''treat'':73C ''treatment'':52B,91C ''use'':40B ''used'':8B,71C', 'medicine,doctor', 'Addictol is a medicine used to treat chem addiction. It is supplied as an inhaler; recognizing the product does not establish a current addiction, treatment or available stock.', 'common', 'addictol addiction medicine, dependence treatment product, addiction recovery aid, inhaler product distinction', 'medicine', NULL, 886, 'addictol', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'ccad162316c601a2de6573f5839b554f1978bfad7b11c5693d27df919ba727cb', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('prewar_american_culture', 'old American culture,prewar popular culture,American consumer culture', 'Prewar American public life featured branded convenience foods, soda advertising, radio entertainment, roadside diners, comic heroes and domestic robots. Atomic technology could appear beside familiar household furnishings and cheerful commercial imagery. These surroundings help explain recognizable old consumer culture without establishing that every person speaks in slogans or shares the same opinions. Regional customs, profession, upbringing and individual temperament shape how people actually talk.', '''actually'':75B ''advertisements'':88C ''advertising'':22B ''american'':2A,5A,10A,14B,77C ''and'':29B,40B,69B,93C,103C ''appear'':35B ''atomic'':32B,94C ''beside'':36B ''branded'':18B ''brands'':86C ''cheerful'':41B,87C ''comic'':27B,89C ''commercial'':42B ''consumer'':11A,50B,78C ''convenience'':19B ''could'':34B ''culture'':3A,6A,9A,12A,51B,79C ''customs'':66B ''diners'':26B,84C ''domestic'':30B,91C ''entertainment'':24B ''establishing'':53B ''every'':55B ''explain'':47B ''familiar'':37B ''featured'':17B ''foods'':20B ''furnishings'':39B ''have'':99C ''help'':46B ''heroes'':28B,90C ''household'':38B ''how'':73B ''imagery'':43B ''in'':58B ''includes'':80C ''individual'':70B,96C ''life'':16B ''manners'':102C ''old'':4A,49B ''opinions'':64B ''or'':60B ''own'':101C ''people'':74B,97C ''person'':56B ''popular'':8A ''prewar'':1A,7A,13B ''profession'':67B ''public'':15B ''radio'':23B,81C ''recognizable'':48B ''regional'':65B ''roadside'':25B,83C ''robots'':31B,92C ''same'':63B ''shape'':72B ''shares'':61B ''slogans'':59B ''soda'':21B,85C ''songs'':82C ''speaks'':57B ''speech'':104C ''still'':98C ''surroundings'':45B ''talk'':76B ''technology'':33B,95C ''temperament'':71B ''that'':54B ''the'':62B ''their'':100C ''these'':44B ''upbringing'':68B ''without'':52B', 'prewar_culture', 'American consumer culture includes radio songs, roadside diners, soda brands, cheerful advertisements, comic heroes, domestic robots and atomic technology. Individual people still have their own manners and speech.', 'common,prewar', 'american consumer culture, atomic household technology, radio entertainment traditions, retro commercial imagery', 'culture', NULL, 887, 'prewar_american_culture', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'ac29a1b40f96c078e8c1d58f02b42055089b87fa1cc0f24d3233e7d8deab12da', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('smoothskin', 'smoothskins,smooth-skin', 'Some ghouls use smoothskin for a human who is not a ghoul, referring to the visible difference in skin. The term can appear in ordinary conversation, teasing or hostility, depending on the speaker and situation. It does not by itself show contempt, and it does not describe a person''s intelligence or moral character. Other speakers may simply say human or use a person''s name.', '''a'':11B,16B,53B,68B,74C,80C,85C ''and'':39B,48B,93C ''appear'':28B ''by'':44B ''can'':27B ''character'':59B ''contempt'':47B ''conversation'':31B ''depending'':35B ''depends'':89C ''describe'':52B ''difference'':22B ''does'':42B,50B ''fixed'':98C ''for'':10B,79C ''ghoul'':17B,86C ''ghouls'':7B,77C ''hostility'':34B ''human'':12B,65B,81C ''in'':23B,29B ''intelligence'':56B ''is'':14B,73C,83C ''it'':41B,49B ''its'':87C ''itself'':45B ''may'':62B ''meaning'':99C ''moral'':58B ''name'':71B ''not'':15B,43B,51B,84C ''on'':36B,90C ''one'':97C ''or'':33B,57B,66B ''ordinary'':30B ''other'':60B ''person'':54B,69B ''rather'':95C ''referring'':18B ''s'':55B,70B ''say'':64B ''show'':46B ''simply'':63B ''situation'':40B,94C ''skin'':5A,24B ''smooth'':4A ''smooth-skin'':3A ''smoothskin'':1A,9B,72C ''smoothskins'':2A ''some'':6B,76C ''speaker'':38B,92C ''speakers'':61B ''teasing'':32B ''term'':26B,75C ''than'':96C ''the'':20B,25B,37B,91C ''to'':19B ''tone'':88C ''use'':8B,67B,78C ''visible'':21B ''who'':13B,82C', 'commonwealth_lore', 'Smoothskin is a term some ghouls use for a human who is not a ghoul. Its tone depends on the speaker and situation, rather than one fixed meaning.', 'common', 'smoothskin colloquial term, ghoul human vocabulary, contextual speech register, visible skin distinction', 'culture', NULL, 888, 'smoothskin', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '8f9d987bf3997de97a62c26cb5a8764463a403016144cbd57f5bcdb7c3e58feb', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('prewar_money', 'old dollars,prewar cash', 'Prewar money consists of old United States banknotes. In ordinary Commonwealth trade, caps have replaced dollars as the expected payment, although old cash can still be exchanged or salvaged for cloth. That usefulness means the notes are not automatically worthless. Specialized machines or unusual arrangements may differ from ordinary trade, but recognizing a banknote does not establish knowledge of every regional payment system.', '''a'':59B ''although'':27B ''american'':92C ''amount'':83C ''and'':87C ''are'':43B,74C ''arrangements'':51B ''as'':23B ''automatically'':45B ''banknote'':60B ''banknotes'':14B,73C ''be'':32B ''belong'':90C ''bill'':85C ''but'':57B ''can'':30B ''caps'':19B ''cash'':6A,29B,78C ''cloth'':37B ''commonwealth'':17B ''consists'':9B ''currency'':95C ''denominated'':77C ''denominations'':80C ''differ'':53B ''does'':61B ''dollar'':76C ''dollar-denominated'':75C ''dollars'':4A,22B ''each'':84C ''establish'':63B ''every'':66B ''exchanged'':33B ''expected'':25B ''for'':36B ''from'':54B ''have'':20B ''identify'':81C ''in'':15B ''knowledge'':64B ''machines'':48B ''may'':52B ''means'':40B ''money'':2A,8B ''not'':44B,62B ''notes'':42B,89C ''of'':10B,65B ''old'':3A,11B,28B ''or'':34B,49B ''ordinary'':16B,55B ''paper'':72C,94C ''paper-currency'':93C ''payment'':26B,68B ''prewar'':1A,5A,7B ''printed'':79C ''recognizing'':58B ''regional'':67B ''replaced'':21B ''represents'':86C ''salvaged'':35B ''specialized'':47B ''states'':13B,71C ''still'':31B ''system'':69B ''that'':38B ''the'':24B,41B,82C,88C ''to'':91C ''trade'':18B,56B ''traditions'':96C ''united'':12B,70C ''unusual'':50B ''usefulness'':39B ''worthless'':46B', 'trader,caravan_trade', 'United States paper banknotes are dollar-denominated cash. Printed denominations identify the amount each bill represents, and the notes belong to American paper-currency traditions.', 'common,prewar', 'united states banknotes, old paper currency, salvaged cash material, dollar cap distinction', 'item', NULL, 889, 'prewar_money', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', '1b65e4da929e56bf04fb80cb3624602efd7644d21a95bc62e1a22ebc640d3a2b', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
	('noodle_cup', 'noodle cups,cup of noodles', 'Noodle cups are a familiar prepared food made with razorgrain and water. In Diamond City, Takahashi sells them at Power Noodles, making the dish recognizable in the public marketplace. Takahashi is not the only possible source of noodle cups; other food sellers and cooks can provide them. Knowing the dish does not establish any vendor''s present stock, prices or a conversation in a language the vendor does not speak.', '''a'':11B,68B,71B,78C,82C,99C ''and'':18B,50B,90C,93C ''any'':61B ''are'':10B ''as'':98C ''at'':26B ''can'':52B ''city'':22B ''conversation'':69B ''cooks'':51B,92C ''cup'':2A,5A,80C ''cups'':4A,9B,46B ''diamond'':21B ''dish'':31B,57B,86C ''does'':58B,75B ''establish'':60B ''familiar'':12B,83C ''food'':14B,48B,94C ''in'':20B,33B,70B ''is'':38B,81C ''it'':97C ''knowing'':55B ''language'':72B ''made'':15B,87C ''making'':29B ''marketplace'':36B ''meal'':100C ''noodle'':1A,3A,8B,45B,79C ''noodles'':7A,28B ''not'':39B,59B,76B ''of'':6A,44B ''only'':41B ''or'':67B ''other'':47B ''possible'':42B ''power'':27B ''prepared'':13B,84C ''present'':64B ''prices'':66B ''provide'':53B,96C ''public'':35B ''razorgrain'':17B,89C ''recognizable'':32B ''s'':63B ''sellers'':49B ''sells'':24B ''source'':43B ''speak'':77B ''stock'':65B ''takahashi'':23B,37B ''the'':30B,34B,40B,56B,73B ''them'':25B,54B ''vendor'':62B,74B ''vendors'':95C ''wasteland'':85C ''water'':19B,91C ''with'':16B,88C', 'commonwealth_lore', 'A noodle cup is a familiar prepared wasteland dish made with razorgrain and water. Cooks and food vendors provide it as a meal.', 'common', 'razorgrain noodle dish, prepared noodle cup, familiar vendor food, grain based meal', 'food_drink', NULL, 890, 'noodle_cup', 'factory', 'fallout-4-starter-official', 'content-v4-2026-10-08', 'a7d90d02321ff4b7fbd7b6d7b7a2d1e20db831fe2b060c0ba35e2f4323f2d451', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
-- end-statement
INSERT INTO public.core_worldknowledge_catalog VALUES
	('fallout-4-starter-official', 'content-v4-2026-10-08', 'SYNTH Fallout 4 public Commonwealth and DLC catalog', 'f60d433c87c071402a8b7255aef9b77d566848f90ff0b7f55b99baa5c290824e', 125, '{"schema": "synth.worldknowledge-catalog.v1", "coverage": {"games": ["Fallout 4"], "regions": {"vault88": 2, "automatron": 3, "far_harbor": 14, "nuka_world": 12, "commonwealth": 14, "contraptions": 2, "prewar_culture": 1, "wasteland_workshop": 2}, "new_articles": 20, "basic_articles": 125, "revised_articles": 1, "advanced_articles": 125, "full_world_coverage": false, "lore_accuracy_topics": ["wasteland_vocabulary", "tato", "mutfruit", "razorgrain", "tarberry", "wasteland_cooking", "prewar_packaged_food", "bottle_caps", "chems", "jet", "psycho", "buffout", "mentats", "med_x", "rad_x", "addictol", "prewar_american_culture", "smoothskin", "prewar_money", "noodle_cup"], "previous_catalog_rows": 105}, "csv_file": "fallout_worldknowledge_parity_v1.csv", "row_count": 125, "catalog_id": "fallout-4-starter-official", "generation": {"method": "Existing 104 articles preserved, commonwealth farming corrected, 20 sourced everyday vocabulary/food/currency/chem/culture articles added.", "source_file": "Per-topic source receipts, uncertainty flags and independently reviewed tier access; no live history or provider replay."}, "display_name": "SYNTH Fallout 4 public Commonwealth and DLC catalog", "catalog_version": "content-v4-2026-10-08", "checksum_sha256": "f60d433c87c071402a8b7255aef9b77d566848f90ff0b7f55b99baa5c290824e", "content_license": {"url": "https://creativecommons.org/licenses/by-sa/4.0/", "license": "CC-BY-SA-4.0", "attribution_file": "fallout_worldknowledge_attribution.md", "original_source_licenses": ["CC-BY-SA-4.0 inherited starter", "CC-BY-SA-3.0 Fandom originals"]}, "source_snapshot": {"file": "fallout_worldknowledge_sources.jsonl", "note": "Authored provenance records; unverified revisions remain null.", "row_count": 125, "checksum_sha256": "44a63e7022e122fd444885986197b416627d3ed63392cca69b46576c48c20659"}, "editorial_review": {"method": "Independent factual/disclosure/access review; no global DLC basic grants; alias ownership and artifact hashes verified. Local review, not release acceptance.", "status": "approved", "reviewer": "Codex local integration review", "reviewed_at": "2026-10-08"}, "knowledge_vocabulary": {"file": "fallout_worldknowledge_vocabulary.json", "checksum_sha256": "dce4e842213da3d96923f489753af3e7ae161072453807a1f037460ac8962381"}}', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
-- end-statement
SELECT pg_catalog.setval('synth.actions_issued_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('synth.actor_identities_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.api_badge_id_seq', 12, true);
-- end-statement
SELECT pg_catalog.setval('synth.audit_request_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.bio_template_actor_map_id_seq', 484, true);
-- end-statement
SELECT pg_catalog.setval('public.core_action_custom_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.core_action_id_seq', 181, true);
-- end-statement
SELECT pg_catalog.setval('public.core_itt_connector_id_seq', 1, true);
-- end-statement
SELECT pg_catalog.setval('synth.core_npc_master_history_history_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.core_tts_pronunciation_id_seq', 6, true);
-- end-statement
SELECT pg_catalog.setval('public.eventlog_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.global_settings_presets_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.import_rules_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.llm_connector_id_seq', 7, true);
-- end-statement
SELECT pg_catalog.setval('synth.log_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.memory_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.memory_summary_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.memory_uid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('synth.npc_master_id_seq', 2189, true);
-- end-statement
SELECT pg_catalog.setval('public.profiles_id_seq', 4, true);
-- end-statement
SELECT pg_catalog.setval('public.quests_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.relationship_eval_queue_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.relationship_init_queue_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('synth.responselog_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.rolemaster_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('synth.speech_rowid_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.stt_connector_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.tts_connector_id_seq', 2, true);
-- end-statement
SELECT pg_catalog.setval('public.visual_context_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.worldknowledge_audit_audit_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.worldknowledge_entry_id_seq', 1015, true);
-- end-statement
SELECT pg_catalog.setval('public.diary_inputs_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('synth_meta.history_events_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.memory_batches_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.memory_summaries_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.middleterm_inputs_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.narrator_definition_revisions_revision_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.narrator_states_state_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.narrator_transcript_entries_entry_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('synth_meta.playthrough_profiles_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.protocol_events_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.rechat_chains_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.relationship_evaluations_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.relationship_interaction_evaluations_id_seq', 1, false);
-- end-statement
SELECT pg_catalog.setval('public.worker_jobs_id_seq', 1, false);
-- end-statement
ALTER TABLE ONLY synth.actor_identities
    ADD CONSTRAINT actor_identities_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_npc_biography_template_actor
    ADD CONSTRAINT bio_template_actor_map_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_npc_biography_template_custom
    ADD CONSTRAINT bio_templates_custom_pkey PRIMARY KEY (npc_name);
-- end-statement
ALTER TABLE ONLY public.core_npc_biography_template
    ADD CONSTRAINT bio_templates_pkey PRIMARY KEY (npc_name);
-- end-statement
ALTER TABLE ONLY public.core_action
    ADD CONSTRAINT core_action_code_name_key UNIQUE (code_name);
-- end-statement
ALTER TABLE ONLY public.core_action_custom
    ADD CONSTRAINT core_action_custom_code_name_key UNIQUE (code_name);
-- end-statement
ALTER TABLE ONLY public.core_action_custom
    ADD CONSTRAINT core_action_custom_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_action
    ADD CONSTRAINT core_action_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_api_badge
    ADD CONSTRAINT core_api_badge_label_unique UNIQUE (label);
-- end-statement
ALTER TABLE ONLY public.core_itt_connector
    ADD CONSTRAINT core_itt_connector_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY synth.core_npc_master_history
    ADD CONSTRAINT core_npc_master_history_pkey PRIMARY KEY (history_id);
-- end-statement
ALTER TABLE ONLY public.core_tts_pronunciation
    ADD CONSTRAINT core_tts_pronunciation_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_item_description_custom
    ADD CONSTRAINT descriptions_custom_pkey PRIMARY KEY (plugin, baseid);
-- end-statement
ALTER TABLE ONLY public.core_item_description
    ADD CONSTRAINT descriptions_pkey PRIMARY KEY (plugin, baseid);
-- end-statement
ALTER TABLE ONLY public.core_faction
    ADD CONSTRAINT factions_pkey PRIMARY KEY (formid);
-- end-statement
ALTER TABLE ONLY public.core_plugins
    ADD CONSTRAINT game_plugins_pkey PRIMARY KEY (plugin_name);
-- end-statement
ALTER TABLE ONLY public.core_config_preset
    ADD CONSTRAINT global_settings_presets_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_npc_profile_rule
    ADD CONSTRAINT import_rules_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_llm_connector
    ADD CONSTRAINT llm_connector_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_api_badge
    ADD CONSTRAINT my_table_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY synth.core_npc_master
    ADD CONSTRAINT npc_master_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_prompt
    ADD CONSTRAINT prompts_pkey PRIMARY KEY (prompt_key);
-- end-statement
ALTER TABLE ONLY public.core_relationship_eval_queue
    ADD CONSTRAINT relationship_eval_queue_npc_id_key UNIQUE (npc_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_eval_queue
    ADD CONSTRAINT relationship_eval_queue_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_init_queue
    ADD CONSTRAINT relationship_init_queue_npc_id_key UNIQUE (npc_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_init_queue
    ADD CONSTRAINT relationship_init_queue_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_stt_connector
    ADD CONSTRAINT stt_connector_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_tts_connector
    ADD CONSTRAINT tts_connector_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_visual_context
    ADD CONSTRAINT visual_context_capture_id_key UNIQUE (capture_id);
-- end-statement
ALTER TABLE ONLY public.core_visual_context
    ADD CONSTRAINT visual_context_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_worldknowledge_audit
    ADD CONSTRAINT worldknowledge_audit_pkey PRIMARY KEY (audit_id);
-- end-statement
ALTER TABLE ONLY public.core_worldknowledge_catalog
    ADD CONSTRAINT worldknowledge_catalogs_pkey PRIMARY KEY (catalog_id, catalog_version);
-- end-statement
ALTER TABLE ONLY public.core_worldknowledge_article
    ADD CONSTRAINT worldknowledge_pkey PRIMARY KEY (entry_id);
-- end-statement
ALTER TABLE ONLY public.core_action_intent
    ADD CONSTRAINT action_intents_idempotency_key_key UNIQUE (idempotency_key);
-- end-statement
ALTER TABLE ONLY public.core_action_intent
    ADD CONSTRAINT action_intents_pkey PRIMARY KEY (action_id);
-- end-statement
ALTER TABLE ONLY public.core_action_result
    ADD CONSTRAINT action_terminal_results_idempotency_key_key UNIQUE (idempotency_key);
-- end-statement
ALTER TABLE ONLY public.core_action_result
    ADD CONSTRAINT action_terminal_results_pkey PRIMARY KEY (action_id);
-- end-statement
ALTER TABLE ONLY public.core_actor_activation
    ADD CONSTRAINT active_agents_pkey PRIMARY KEY (session_id, generation, actor_identity_id);
-- end-statement
ALTER TABLE ONLY public.core_actor_event_batch
    ADD CONSTRAINT actor_event_batches_pkey PRIMARY KEY (session_id, generation, playthrough_id, batch_id);
-- end-statement
ALTER TABLE ONLY public.core_runtime_context
    ADD CONSTRAINT context_snapshots_pkey PRIMARY KEY (session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_origin_policy
    ADD CONSTRAINT dialogue_origin_policies_pkey PRIMARY KEY (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_utterance
    ADD CONSTRAINT dialogue_utterances_pkey PRIMARY KEY (session_id, request_id, line_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_control_revision
    ADD CONSTRAINT diary_control_revisions_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input_source
    ADD CONSTRAINT diary_input_sources_pkey PRIMARY KEY (input_id, history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_session_id_request_id_actor_identity_id_diary__key UNIQUE (session_id, request_id, actor_identity_id, diary_role);
-- end-statement
ALTER TABLE ONLY public.core_diary_job_result
    ADD CONSTRAINT diary_job_results_pkey PRIMARY KEY (job_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_result
    ADD CONSTRAINT diary_results_input_id_key UNIQUE (input_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_result
    ADD CONSTRAINT diary_results_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY synth_meta.history_events
    ADD CONSTRAINT history_events_eventlog_rowid_key UNIQUE (eventlog_rowid);
-- end-statement
ALTER TABLE ONLY synth_meta.history_events
    ADD CONSTRAINT history_events_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_media_object
    ADD CONSTRAINT media_objects_cache_key_key UNIQUE (cache_key);
-- end-statement
ALTER TABLE ONLY public.core_media_object
    ADD CONSTRAINT media_objects_pkey PRIMARY KEY (media_id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_control_event
    ADD CONSTRAINT memory_batch_control_events_pkey PRIMARY KEY (batch_id, revision);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_control
    ADD CONSTRAINT memory_batch_controls_pkey PRIMARY KEY (batch_id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_rebuild
    ADD CONSTRAINT memory_batch_rebuilds_pkey PRIMARY KEY (batch_id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_source
    ADD CONSTRAINT memory_batch_sources_batch_id_position_key UNIQUE (batch_id, "position");
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_source
    ADD CONSTRAINT memory_batch_sources_pkey PRIMARY KEY (batch_id, history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_summary_source
    ADD CONSTRAINT memory_batch_summary_sources_pkey PRIMARY KEY (batch_id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch
    ADD CONSTRAINT memory_batches_actor_identity_id_source_hash_key UNIQUE (actor_identity_id, source_hash);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch
    ADD CONSTRAINT memory_batches_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary
    ADD CONSTRAINT memory_summaries_job_id_key UNIQUE (job_id);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary
    ADD CONSTRAINT memory_summaries_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary_revision
    ADD CONSTRAINT memory_summary_revisions_pkey PRIMARY KEY (summary_id, revision);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_control_revision
    ADD CONSTRAINT middleterm_control_revisions_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input
    ADD CONSTRAINT middleterm_inputs_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input
    ADD CONSTRAINT middleterm_inputs_session_id_request_id_generation_actor_id_key UNIQUE (session_id, request_id, generation, actor_identity_id, input_sha256);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_regeneration_approval
    ADD CONSTRAINT middleterm_regeneration_appro_session_id_generation_actor_i_key UNIQUE (session_id, generation, actor_identity_id, approval_token);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_regeneration_approval
    ADD CONSTRAINT middleterm_regeneration_approvals_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_approval_id_key UNIQUE (approval_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_target_history_event_id_session_id__key UNIQUE (target_history_event_id, session_id, generation);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_result
    ADD CONSTRAINT middleterm_results_input_id_key UNIQUE (input_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_result
    ADD CONSTRAINT middleterm_results_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_source
    ADD CONSTRAINT middleterm_sources_input_id_summary_id_key UNIQUE (input_id, summary_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_source
    ADD CONSTRAINT middleterm_sources_pkey PRIMARY KEY (input_id, "position");
-- end-statement
ALTER TABLE ONLY public.core_narrator_context_state
    ADD CONSTRAINT narrator_context_states_pkey PRIMARY KEY (session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_narrator_definition_revision
    ADD CONSTRAINT narrator_definition_revisions_pkey PRIMARY KEY (revision_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_definition
    ADD CONSTRAINT narrator_definitions_pkey PRIMARY KEY (definition_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_lane_head
    ADD CONSTRAINT narrator_lane_heads_pkey PRIMARY KEY (session_id, generation);
-- end-statement
ALTER TABLE ONLY public.core_narrator_pending_output
    ADD CONSTRAINT narrator_pending_outputs_pkey PRIMARY KEY (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_pending_output
    ADD CONSTRAINT narrator_pending_outputs_session_id_line_id_key UNIQUE (session_id, line_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_state
    ADD CONSTRAINT narrator_states_pkey PRIMARY KEY (state_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_cursor
    ADD CONSTRAINT narrator_transcript_cursors_pkey PRIMARY KEY (cursor_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_cursor
    ADD CONSTRAINT narrator_transcript_cursors_state_id_next_entry_id_key UNIQUE (state_id, next_entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry
    ADD CONSTRAINT narrator_transcript_entries_pkey PRIMARY KEY (entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry
    ADD CONSTRAINT narrator_transcript_entries_session_id_request_id_role_key UNIQUE (session_id, request_id, role);
-- end-statement
ALTER TABLE ONLY synth_meta.playthrough_profiles
    ADD CONSTRAINT playthrough_profiles_name_key UNIQUE (name);
-- end-statement
ALTER TABLE ONLY synth_meta.playthrough_profiles
    ADD CONSTRAINT playthrough_profiles_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_field
    ADD CONSTRAINT profile_job_fields_pkey PRIMARY KEY (job_id, field);
-- end-statement
ALTER TABLE ONLY public.core_character_generation
    ADD CONSTRAINT profile_job_inputs_pkey PRIMARY KEY (job_id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_session_id_request_id_actor_identity_id_p_key UNIQUE (session_id, request_id, actor_identity_id, profile_role);
-- end-statement
ALTER TABLE ONLY public.core_protocol_event
    ADD CONSTRAINT protocol_events_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_protocol_event
    ADD CONSTRAINT protocol_events_session_id_request_id_key UNIQUE (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_chain
    ADD CONSTRAINT rechat_chains_id_session_id_key UNIQUE (id, session_id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_chain
    ADD CONSTRAINT rechat_chains_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_chain
    ADD CONSTRAINT rechat_chains_session_id_root_request_id_key UNIQUE (session_id, root_request_id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_continuation
    ADD CONSTRAINT rechat_continuations_chain_id_round_number_key UNIQUE (chain_id, round_number);
-- end-statement
ALTER TABLE ONLY public.core_rechat_continuation
    ADD CONSTRAINT rechat_continuations_pkey PRIMARY KEY (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_continuation
    ADD CONSTRAINT rechat_continuations_session_id_parent_request_id_key UNIQUE (session_id, parent_request_id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_selection
    ADD CONSTRAINT rechat_selections_pkey PRIMARY KEY (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_actor_control_event
    ADD CONSTRAINT relationship_actor_control_events_pkey PRIMARY KEY (actor_identity_id, revision);
-- end-statement
ALTER TABLE ONLY public.core_relationship_actor_control
    ADD CONSTRAINT relationship_actor_controls_pkey PRIMARY KEY (actor_identity_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation_source
    ADD CONSTRAINT relationship_evaluation_sources_pkey PRIMARY KEY (evaluation_id, history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation
    ADD CONSTRAINT relationship_evaluations_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation
    ADD CONSTRAINT relationship_evaluations_session_id_request_id_response_his_key UNIQUE (session_id, request_id, response_history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_eval_session_id_request_id_respons_key UNIQUE (session_id, request_id, response_history_event_id, listener_identity_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_evaluations_pkey PRIMARY KEY (id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_prior
    ADD CONSTRAINT relationship_interaction_priors_pkey PRIMARY KEY (interaction_id, actor_identity_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result_prior
    ADD CONSTRAINT relationship_interaction_result_priors_pkey PRIMARY KEY (result_history_event_id, actor_identity_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result
    ADD CONSTRAINT relationship_interaction_results_interaction_id_key UNIQUE (interaction_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result
    ADD CONSTRAINT relationship_interaction_results_job_id_key UNIQUE (job_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result
    ADD CONSTRAINT relationship_interaction_results_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_input
    ADD CONSTRAINT relationship_response_inputs_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_input
    ADD CONSTRAINT relationship_response_inputs_session_id_request_id_key UNIQUE (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_source
    ADD CONSTRAINT relationship_response_sources_pkey PRIMARY KEY (response_history_event_id, source_history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result_prior
    ADD CONSTRAINT relationship_result_priors_pkey PRIMARY KEY (result_history_event_id, prior_history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result
    ADD CONSTRAINT relationship_results_evaluation_id_key UNIQUE (evaluation_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result
    ADD CONSTRAINT relationship_results_job_id_key UNIQUE (job_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result
    ADD CONSTRAINT relationship_results_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision_source
    ADD CONSTRAINT relationship_revision_sources_pkey PRIMARY KEY (relationship_history_event_id, source_history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY synth_meta.request_executions
    ADD CONSTRAINT request_executions_pkey PRIMARY KEY (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_route
    ADD CONSTRAINT response_routes_pkey PRIMARY KEY (session_id, request_id, line_id);
-- end-statement
ALTER TABLE ONLY public.core_runtime_session
    ADD CONSTRAINT runtime_sessions_pkey PRIMARY KEY (session_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_claim
    ADD CONSTRAINT social_task_claims_pkey PRIMARY KEY (task_id, session_id, generation);
-- end-statement
ALTER TABLE ONLY public.core_social_task_claim
    ADD CONSTRAINT social_task_claims_session_id_generation_execution_id_key UNIQUE (session_id, generation, execution_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_event
    ADD CONSTRAINT social_task_events_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_input
    ADD CONSTRAINT social_task_inputs_history_event_id_key UNIQUE (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_input
    ADD CONSTRAINT social_task_inputs_pkey PRIMARY KEY (task_id);
-- end-statement
ALTER TABLE ONLY public.core_visual_description_revision
    ADD CONSTRAINT visual_description_revisions_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_capture_id_key UNIQUE (capture_id);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_pkey PRIMARY KEY (history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_session_id_request_id_key UNIQUE (session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_pkey PRIMARY KEY (id);
-- end-statement
CREATE UNIQUE INDEX actor_identities_canonical_key ON synth.actor_identities USING btree (game, upper((full_form_id)::text), lower(origin_plugin), playthrough_id);
-- end-statement
CREATE INDEX actor_identities_display_name ON synth.actor_identities USING btree (lower(display_name)) WHERE (display_name IS NOT NULL);
-- end-statement
CREATE UNIQUE INDEX bio_template_actor_map_base_identity_uidx ON public.core_npc_biography_template_actor USING btree (lower(base_plugin), upper((base_local_formid)::text));
-- end-statement
CREATE UNIQUE INDEX bio_template_actor_map_reference_identity_uidx ON public.core_npc_biography_template_actor USING btree (lower(reference_plugin), upper((reference_local_formid)::text)) WHERE ((reference_plugin IS NOT NULL) AND (reference_local_formid IS NOT NULL));
-- end-statement
CREATE INDEX bio_template_actor_map_template_idx ON public.core_npc_biography_template_actor USING btree (lower((template_name)::text));
-- end-statement
CREATE INDEX core_itt_connector_label_idx ON public.core_itt_connector USING btree (lower(label));
-- end-statement
CREATE UNIQUE INDEX core_npc_master_actor_identity ON synth.core_npc_master USING btree (actor_identity_id) WHERE (actor_identity_id IS NOT NULL);
-- end-statement
CREATE UNIQUE INDEX core_npc_master_legacy_name ON synth.core_npc_master USING btree (npc_name) WHERE (actor_identity_id IS NULL);
-- end-statement
CREATE UNIQUE INDEX core_profiles_slot_unique_idx ON public.core_profiles USING btree (slot) WHERE (slot IS NOT NULL);
-- end-statement
CREATE UNIQUE INDEX core_tts_pronunciation_unique_entry ON public.core_tts_pronunciation USING btree (lower((source_text)::text), md5(((((lower((npc_names)::text) || ''::text) || lower((races)::text)) || ''::text) || lower((oghma_tags)::text))), is_builtin);
-- end-statement
CREATE INDEX event_log_type ON public.core_context_event USING btree (type);
-- end-statement
CREATE INDEX eventlog_native_owner ON public.core_context_event USING btree (synth_session_id, synth_generation, rowid) WHERE (synth_session_id IS NOT NULL);
-- end-statement
CREATE UNIQUE INDEX global_settings_presets_name_unique ON public.core_config_preset USING btree (lower((name)::text));
-- end-statement
CREATE INDEX idx_core_action_action_name_lower ON public.core_action USING btree (lower((action_name)::text));
-- end-statement
CREATE INDEX idx_core_action_available_to_followers ON public.core_action USING btree (available_to_followers);
-- end-statement
CREATE INDEX idx_core_action_available_to_narrator ON public.core_action USING btree (available_to_narrator);
-- end-statement
CREATE INDEX idx_core_action_available_to_npc ON public.core_action USING btree (available_to_npc);
-- end-statement
CREATE INDEX idx_core_action_code_name_lower ON public.core_action USING btree (lower((code_name)::text));
-- end-statement
CREATE INDEX idx_core_action_custom_action_name_lower ON public.core_action_custom USING btree (lower((action_name)::text));
-- end-statement
CREATE INDEX idx_core_action_custom_available_to_followers ON public.core_action_custom USING btree (available_to_followers);
-- end-statement
CREATE INDEX idx_core_action_custom_available_to_narrator ON public.core_action_custom USING btree (available_to_narrator);
-- end-statement
CREATE INDEX idx_core_action_custom_available_to_npc ON public.core_action_custom USING btree (available_to_npc);
-- end-statement
CREATE INDEX idx_core_action_custom_code_name_lower ON public.core_action_custom USING btree (lower((code_name)::text));
-- end-statement
CREATE INDEX idx_core_action_custom_game_function ON public.core_action_custom USING btree (game_function);
-- end-statement
CREATE INDEX idx_core_action_custom_is_activated ON public.core_action_custom USING btree (is_activated);
-- end-statement
CREATE INDEX idx_core_action_game_function ON public.core_action USING btree (game_function);
-- end-statement
CREATE INDEX idx_core_action_is_activated ON public.core_action USING btree (is_activated);
-- end-statement
CREATE INDEX idx_core_api_badge_label_lower ON public.core_api_badge USING btree (lower(label));
-- end-statement
CREATE INDEX idx_core_npc_master_history_actor_identity ON synth.core_npc_master_history USING btree (actor_identity_id) WHERE (actor_identity_id IS NOT NULL);
-- end-statement
CREATE INDEX idx_core_npc_master_history_restore ON synth.core_npc_master_history USING btree (npc_id, gamets_last_updated DESC NULLS LAST, created DESC, history_id DESC);
-- end-statement
CREATE INDEX idx_eventlog_delivery_state ON public.core_context_event USING btree (delivery_state);
-- end-statement
CREATE INDEX idx_eventlog_gamets_pos ON public.core_context_event USING btree (gamets) WHERE (gamets > 0);
-- end-statement
CREATE INDEX idx_eventlog_gamets_ts_pos ON public.core_context_event USING btree (gamets DESC, ts DESC);
-- end-statement
CREATE INDEX idx_eventlog_people_trgm ON public.core_context_event USING gin (people gin_trgm_ops);
-- end-statement
CREATE INDEX idx_eventlog_people_trgm2 ON public.core_context_event USING gin (data gin_trgm_ops);
-- end-statement
CREATE INDEX idx_eventlog_utterance_id ON public.core_context_event USING btree (utterance_id);
-- end-statement
CREATE INDEX idx_speech_gamets_pos ON synth.speech USING btree (gamets) WHERE (gamets > 0);
-- end-statement
CREATE INDEX idx_speech_listener_trgm ON synth.speech USING gin (listener gin_trgm_ops);
-- end-statement
CREATE INDEX idx_speech_speaker_trgm ON synth.speech USING gin (speaker gin_trgm_ops);
-- end-statement
CREATE INDEX idx_speech_utterance_id ON synth.speech USING btree (utterance_id);
-- end-statement
CREATE INDEX memory_summary_tsv_idx ON public.core_memory USING gin (native_vec);
-- end-statement
CREATE INDEX idx_core_memory_diary_companions ON public.core_memory USING btree (lower(TRIM(BOTH FROM companions)), gamets DESC, localts DESC, rowid DESC) WHERE (source_type = 'diary'::text);
-- end-statement
CREATE INDEX visual_context_actor_idx ON public.core_visual_context USING btree (actor_identity_id, captured_at DESC) WHERE (actor_identity_id IS NOT NULL);
-- end-statement
CREATE INDEX visual_context_cell_idx ON public.core_visual_context USING btree (lower(cell_id), active, captured_at DESC);
-- end-statement
CREATE INDEX visual_context_image_idx ON public.core_visual_context USING btree (image_sha256);
-- end-statement
CREATE INDEX visual_context_location_idx ON public.core_visual_context USING btree (lower(worldspace_name), lower(location_name), active, captured_at DESC);
-- end-statement
CREATE INDEX visual_context_media_idx ON public.core_visual_context USING btree (media_id) WHERE (media_id IS NOT NULL);
-- end-statement
CREATE INDEX visual_context_runtime_request_idx ON public.core_visual_context USING btree (session_id, generation, request_id, captured_at DESC);
-- end-statement
CREATE INDEX visual_context_subject_idx ON public.core_visual_context USING btree (subject_type, subject_key, active, captured_at DESC);
-- end-statement
CREATE INDEX worldknowledge_audit_created_idx ON public.core_worldknowledge_audit USING btree (created_at DESC);
-- end-statement
CREATE INDEX worldknowledge_audit_status_idx ON public.core_worldknowledge_audit USING btree (status, created_at DESC);
-- end-statement
CREATE INDEX worldknowledge_catalog_lookup_idx ON public.core_worldknowledge_article USING btree (catalog_id, catalog_version) WHERE (source_kind = 'factory'::text);
-- end-statement
CREATE UNIQUE INDEX worldknowledge_custom_topic_unique_idx ON public.core_worldknowledge_article USING btree (canonical_topic) WHERE ((source_kind = 'custom'::text) AND is_active);
-- end-statement
CREATE INDEX worldknowledge_effective_lookup_idx ON public.core_worldknowledge_article USING btree (canonical_topic, source_kind, is_active);
-- end-statement
CREATE UNIQUE INDEX worldknowledge_factory_topic_unique_idx ON public.core_worldknowledge_article USING btree (catalog_id, catalog_version, canonical_topic) WHERE (source_kind = 'factory'::text);
-- end-statement
CREATE UNIQUE INDEX worldknowledge_one_active_catalog_idx ON public.core_worldknowledge_catalog USING btree (is_active) WHERE is_active;
-- end-statement
CREATE INDEX action_intents_dispatch ON public.core_action_intent USING btree (session_id, generation, state, deadline_at);
-- end-statement
CREATE INDEX active_agents_dispatch ON public.core_actor_activation USING btree (session_id, generation, state, last_seen_at DESC);
-- end-statement
CREATE INDEX diary_control_revisions_owner ON public.core_diary_control_revision USING btree (diary_id, history_event_id DESC);
-- end-statement
CREATE INDEX diary_inputs_actor_role ON public.core_diary_input USING btree (actor_identity_id, diary_role, id DESC);
-- end-statement
CREATE INDEX history_events_native_action_owner ON synth_meta.history_events USING btree (session_id, generation, playthrough_id, id) WHERE ((event_payload ->> 'type'::text) = 'funccall'::text);
-- end-statement
CREATE INDEX history_events_native_owner ON synth_meta.history_events USING btree (session_id, generation, id);
-- end-statement
CREATE UNIQUE INDEX history_events_player_event_owner ON synth_meta.history_events USING btree (session_id, generation, playthrough_id, ((((event_payload ->> 'party'::text))::jsonb ->> 'event_id'::text))) WHERE ((event_payload ->> 'type'::text) = 'player_event'::text);
-- end-statement
CREATE UNIQUE INDEX history_events_quest_batch_owner ON synth_meta.history_events USING btree (session_id, generation, playthrough_id, ((((event_payload ->> 'party'::text))::jsonb ->> 'batch_id'::text))) WHERE ((event_payload ->> 'type'::text) = 'quest_events'::text);
-- end-statement
CREATE INDEX history_events_quest_observation_owner ON synth_meta.history_events USING btree (session_id, generation, playthrough_id, id DESC) WHERE ((event_payload ->> 'type'::text) = 'quest_observation'::text);
-- end-statement
CREATE INDEX media_objects_dispatch ON public.core_media_object USING btree (state, generation, created_at);
-- end-statement
CREATE INDEX memory_batch_rebuilds_root ON public.core_memory_batch_rebuild USING btree (root_batch_id, batch_id);
-- end-statement
CREATE INDEX memory_batch_summary_sources_parent ON public.core_memory_batch_summary_source USING btree (summary_id, batch_id);
-- end-statement
CREATE INDEX memory_summaries_batch ON public.core_memory_summary USING btree (batch_id, id);
-- end-statement
CREATE INDEX memory_summaries_text ON public.core_memory_summary USING gin (native_vec);
-- end-statement
CREATE INDEX middleterm_control_revisions_owner ON public.core_middleterm_control_revision USING btree (narrative_id, history_event_id DESC);
-- end-statement
CREATE INDEX middleterm_inputs_actor ON public.core_middleterm_input USING btree (actor_identity_id, id DESC);
-- end-statement
CREATE INDEX middleterm_inputs_regeneration ON public.core_middleterm_input USING btree (regeneration_approval_id, id) WHERE (regeneration_approval_id IS NOT NULL);
-- end-statement
CREATE INDEX middleterm_regeneration_approvals_target ON public.core_middleterm_regeneration_approval USING btree (narrative_id, history_event_id DESC);
-- end-statement
CREATE INDEX middleterm_regeneration_discovery ON public.core_middleterm_regeneration_approval USING btree (session_id, generation, actor_identity_id, last_considered_at NULLS FIRST, history_event_id);
-- end-statement
CREATE UNIQUE INDEX narrator_definition_revisions_chain ON public.core_narrator_definition_revision USING btree (definition_id, COALESCE(prior_revision_id, (0)::bigint));
-- end-statement
CREATE INDEX narrator_pending_outputs_open ON public.core_narrator_pending_output USING btree (playthrough_id, state) WHERE (state = ANY (ARRAY['pending'::text, 'generated'::text]));
-- end-statement
CREATE INDEX profile_revisions_actor_history ON public.core_character_generation_revision USING btree (actor_identity_id, profile_role, history_event_id DESC);
-- end-statement
CREATE INDEX protocol_events_turn_generation ON public.core_protocol_event USING btree (session_id, turn_id, generation, received_at);
-- end-statement
CREATE INDEX relationship_interaction_listener ON public.core_relationship_interaction_evaluation USING btree (listener_identity_id, id);
-- end-statement
CREATE INDEX relationship_revisions_pair ON public.core_relationship_revision USING btree (actor_identity_id, subject_identity_id, history_event_id);
-- end-statement
CREATE INDEX request_executions_turn_generation ON synth_meta.request_executions USING btree (session_id, turn_id, generation, claimed_at);
-- end-statement
CREATE UNIQUE INDEX response_routes_utterance_unique ON public.core_dialogue_route USING btree (session_id, request_id, utterance_id) WHERE (utterance_id IS NOT NULL);
-- end-statement
CREATE INDEX runtime_sessions_memory_dispatch ON public.core_runtime_session USING btree (memory_dispatch_after, session_id) WHERE (state = 'active'::text);
-- end-statement
CREATE INDEX runtime_sessions_playthrough_state ON public.core_runtime_session USING btree (playthrough_id, state, updated_at DESC);
-- end-statement
CREATE INDEX social_task_events_task ON public.core_social_task_event USING btree (task_id, history_event_id DESC);
-- end-statement
CREATE INDEX visual_description_capture_history ON public.core_visual_description_revision USING btree (capture_id, actor_identity_id, history_event_id DESC);
-- end-statement
CREATE INDEX visual_results_actor_history ON public.core_visual_result USING btree (actor_identity_id, history_event_id DESC);
-- end-statement
CREATE UNIQUE INDEX worker_jobs_active_memory_batch ON public.core_worker_job USING btree (memory_batch_id) WHERE ((job_type = 'memory'::text) AND (memory_batch_id IS NOT NULL) AND (state = ANY (ARRAY['pending'::text, 'running'::text, 'ready'::text])));
-- end-statement
CREATE UNIQUE INDEX worker_jobs_active_relationship_evaluation ON public.core_worker_job USING btree (relationship_evaluation_id) WHERE ((job_type = 'relationship'::text) AND (relationship_evaluation_id IS NOT NULL) AND (state = ANY (ARRAY['pending'::text, 'running'::text, 'ready'::text])));
-- end-statement
CREATE UNIQUE INDEX worker_jobs_diary_input ON public.core_worker_job USING btree (diary_input_id) WHERE (diary_input_id IS NOT NULL);
-- end-statement
CREATE INDEX worker_jobs_dispatch ON public.core_worker_job USING btree (job_type, state, available_at, generation);
-- end-statement
CREATE UNIQUE INDEX worker_jobs_middleterm_input ON public.core_worker_job USING btree (middleterm_input_id) WHERE (middleterm_input_id IS NOT NULL);
-- end-statement
CREATE UNIQUE INDEX worker_jobs_native_profile_owner ON public.core_worker_job USING btree (session_id, request_id, actor_identity_id, ((payload ->> 'profile_role'::text))) WHERE ((job_type = 'profile'::text) AND ((payload ->> 'schema'::text) = 'synth.profile.job.v1'::text));
-- end-statement
CREATE UNIQUE INDEX worker_jobs_relationship_interaction ON public.core_worker_job USING btree (relationship_interaction_id) WHERE (relationship_interaction_id IS NOT NULL);
-- end-statement
CREATE INDEX worker_jobs_session_generation ON public.core_worker_job USING btree (session_id, generation) WHERE (state = ANY (ARRAY['pending'::text, 'running'::text]));
-- end-statement
ALTER TABLE ONLY public.core_itt_connector
    ADD CONSTRAINT core_itt_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);
-- end-statement
ALTER TABLE ONLY synth.core_npc_master
    ADD CONSTRAINT core_npc_master_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id) ON DELETE SET NULL;
-- end-statement
ALTER TABLE ONLY synth.core_npc_master_history
    ADD CONSTRAINT core_npc_master_history_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id) ON DELETE SET NULL;
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT fk_diary_connector FOREIGN KEY (diary_connector_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY synth.core_npc_master
    ADD CONSTRAINT fk_profile_id FOREIGN KEY (profile_id) REFERENCES public.core_profiles(id) ON DELETE SET NULL;
-- end-statement
ALTER TABLE ONLY public.core_npc_profile_rule
    ADD CONSTRAINT import_rules_profile_fkey FOREIGN KEY (profile) REFERENCES public.core_profiles(id);
-- end-statement
ALTER TABLE ONLY public.core_llm_connector
    ADD CONSTRAINT llm_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_fallback_id_fkey FOREIGN KEY (llm_fallback_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_formatter_id_fkey FOREIGN KEY (llm_formatter_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_primary_id_fkey FOREIGN KEY (llm_primary_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_quaternary_id_fkey FOREIGN KEY (llm_quaternary_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_secondary_id_fkey FOREIGN KEY (llm_secondary_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_tertiary_id_fkey FOREIGN KEY (llm_tertiary_id) REFERENCES public.core_llm_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_tts_connector_id_fkey FOREIGN KEY (tts_connector_id) REFERENCES public.core_tts_connector(id);
-- end-statement
ALTER TABLE ONLY public.core_stt_connector
    ADD CONSTRAINT stt_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);
-- end-statement
ALTER TABLE ONLY public.core_tts_connector
    ADD CONSTRAINT tts_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);
-- end-statement
ALTER TABLE ONLY public.core_visual_context
    ADD CONSTRAINT visual_context_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id) ON DELETE SET NULL;
-- end-statement
ALTER TABLE ONLY public.core_visual_context
    ADD CONSTRAINT visual_context_media_id_fkey FOREIGN KEY (media_id) REFERENCES public.core_media_object(media_id) ON DELETE RESTRICT;
-- end-statement
ALTER TABLE ONLY public.core_visual_context
    ADD CONSTRAINT visual_context_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE SET NULL;
-- end-statement
ALTER TABLE ONLY public.core_worldknowledge_article
    ADD CONSTRAINT worldknowledge_catalog_foreign_key FOREIGN KEY (catalog_id, catalog_version) REFERENCES public.core_worldknowledge_catalog(catalog_id, catalog_version) ON DELETE RESTRICT;
-- end-statement
ALTER TABLE ONLY public.core_action_intent
    ADD CONSTRAINT action_intents_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_action_intent
    ADD CONSTRAINT action_intents_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_action_intent
    ADD CONSTRAINT action_intents_target_identity_id_fkey FOREIGN KEY (target_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_action_result
    ADD CONSTRAINT action_terminal_results_action_id_fkey FOREIGN KEY (action_id) REFERENCES public.core_action_intent(action_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_actor_activation
    ADD CONSTRAINT active_agents_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_actor_activation
    ADD CONSTRAINT active_agents_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_actor_event_batch
    ADD CONSTRAINT actor_event_batches_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_actor_event_batch
    ADD CONSTRAINT actor_event_batches_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_runtime_context
    ADD CONSTRAINT context_snapshots_player_identity_id_fkey FOREIGN KEY (player_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_runtime_context
    ADD CONSTRAINT context_snapshots_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_runtime_context
    ADD CONSTRAINT context_snapshots_target_identity_id_fkey FOREIGN KEY (target_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_origin_policy
    ADD CONSTRAINT dialogue_origin_policies_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_dialogue_utterance
    ADD CONSTRAINT dialogue_utterances_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_utterance
    ADD CONSTRAINT dialogue_utterances_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_utterance
    ADD CONSTRAINT dialogue_utterances_speaker_identity_id_fkey FOREIGN KEY (speaker_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_control_revision
    ADD CONSTRAINT diary_control_revisions_diary_id_fkey FOREIGN KEY (diary_id) REFERENCES public.core_diary_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_control_revision
    ADD CONSTRAINT diary_control_revisions_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_control_revision
    ADD CONSTRAINT diary_control_revisions_prior_revision_id_fkey FOREIGN KEY (prior_revision_id) REFERENCES public.core_diary_control_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input_source
    ADD CONSTRAINT diary_input_sources_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input_source
    ADD CONSTRAINT diary_input_sources_input_id_fkey FOREIGN KEY (input_id) REFERENCES public.core_diary_input(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_profile_revision_id_fkey FOREIGN KEY (profile_revision_id) REFERENCES public.core_character_generation_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_diary_input
    ADD CONSTRAINT diary_inputs_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_diary_job_result
    ADD CONSTRAINT diary_job_results_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.core_worker_job(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_result
    ADD CONSTRAINT diary_results_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_diary_result
    ADD CONSTRAINT diary_results_input_id_fkey FOREIGN KEY (input_id) REFERENCES public.core_diary_input(id);
-- end-statement
ALTER TABLE ONLY synth_meta.history_events
    ADD CONSTRAINT history_events_scene_context_fkey FOREIGN KEY (session_id, generation, scene_context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY synth_meta.history_events
    ADD CONSTRAINT history_events_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY synth_meta.history_events
    ADD CONSTRAINT history_events_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_media_object
    ADD CONSTRAINT media_objects_speaker_identity_id_fkey FOREIGN KEY (speaker_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_control_event
    ADD CONSTRAINT memory_batch_control_events_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_control_event
    ADD CONSTRAINT memory_batch_control_events_subject_batch_id_fkey FOREIGN KEY (subject_batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_control
    ADD CONSTRAINT memory_batch_controls_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_control
    ADD CONSTRAINT memory_batch_controls_regenerate_batch_id_fkey FOREIGN KEY (regenerate_batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_rebuild
    ADD CONSTRAINT memory_batch_rebuilds_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_rebuild
    ADD CONSTRAINT memory_batch_rebuilds_root_batch_id_fkey FOREIGN KEY (root_batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_source
    ADD CONSTRAINT memory_batch_sources_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_source
    ADD CONSTRAINT memory_batch_sources_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_summary_source
    ADD CONSTRAINT memory_batch_summary_sources_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch_summary_source
    ADD CONSTRAINT memory_batch_summary_sources_summary_id_fkey FOREIGN KEY (summary_id) REFERENCES public.core_memory_summary(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch
    ADD CONSTRAINT memory_batches_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_batch
    ADD CONSTRAINT memory_batches_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary
    ADD CONSTRAINT memory_summaries_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary
    ADD CONSTRAINT memory_summaries_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.core_worker_job(id);
-- end-statement
ALTER TABLE ONLY public.core_memory_summary_revision
    ADD CONSTRAINT memory_summary_revisions_summary_id_fkey FOREIGN KEY (summary_id) REFERENCES public.core_memory_summary(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_control_revision
    ADD CONSTRAINT middleterm_control_revisions_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_control_revision
    ADD CONSTRAINT middleterm_control_revisions_narrative_id_fkey FOREIGN KEY (narrative_id) REFERENCES public.core_middleterm_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_control_revision
    ADD CONSTRAINT middleterm_control_revisions_prior_revision_id_fkey FOREIGN KEY (prior_revision_id) REFERENCES public.core_middleterm_control_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input
    ADD CONSTRAINT middleterm_inputs_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input
    ADD CONSTRAINT middleterm_inputs_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input
    ADD CONSTRAINT middleterm_inputs_regeneration_approval_id_fkey FOREIGN KEY (regeneration_approval_id) REFERENCES public.core_middleterm_regeneration_approval(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_input
    ADD CONSTRAINT middleterm_inputs_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_regeneration_approval
    ADD CONSTRAINT middleterm_regeneration_approvals_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_regeneration_approval
    ADD CONSTRAINT middleterm_regeneration_approvals_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_regeneration_approval
    ADD CONSTRAINT middleterm_regeneration_approvals_narrative_id_fkey FOREIGN KEY (narrative_id) REFERENCES public.core_middleterm_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_regeneration_approval
    ADD CONSTRAINT middleterm_regeneration_approvals_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_approval_id_fkey FOREIGN KEY (approval_id) REFERENCES public.core_middleterm_regeneration_approval(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES public.core_middleterm_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_replacement
    ADD CONSTRAINT middleterm_replacements_target_history_event_id_fkey FOREIGN KEY (target_history_event_id) REFERENCES public.core_middleterm_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_result
    ADD CONSTRAINT middleterm_results_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_result
    ADD CONSTRAINT middleterm_results_input_id_fkey FOREIGN KEY (input_id) REFERENCES public.core_middleterm_input(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_source
    ADD CONSTRAINT middleterm_sources_input_id_fkey FOREIGN KEY (input_id) REFERENCES public.core_middleterm_input(id);
-- end-statement
ALTER TABLE ONLY public.core_middleterm_source
    ADD CONSTRAINT middleterm_sources_summary_id_fkey FOREIGN KEY (summary_id) REFERENCES public.core_memory_summary(id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_context_state
    ADD CONSTRAINT narrator_context_states_session_id_generation_snapshot_seq_fkey FOREIGN KEY (session_id, generation, snapshot_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_narrator_context_state
    ADD CONSTRAINT narrator_context_states_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.core_narrator_state(state_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_definition_revision
    ADD CONSTRAINT narrator_definition_revisions_definition_id_fkey FOREIGN KEY (definition_id) REFERENCES public.core_narrator_definition(definition_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_definition_revision
    ADD CONSTRAINT narrator_definition_revisions_prior_revision_id_fkey FOREIGN KEY (prior_revision_id) REFERENCES public.core_narrator_definition_revision(revision_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_lane_head
    ADD CONSTRAINT narrator_lane_heads_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_narrator_lane_head
    ADD CONSTRAINT narrator_lane_heads_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.core_narrator_state(state_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_pending_output
    ADD CONSTRAINT narrator_pending_outputs_definition_id_fkey FOREIGN KEY (definition_id) REFERENCES public.core_narrator_definition(definition_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_pending_output
    ADD CONSTRAINT narrator_pending_outputs_input_entry_id_fkey FOREIGN KEY (input_entry_id) REFERENCES public.core_narrator_transcript_entry(entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_pending_output
    ADD CONSTRAINT narrator_pending_outputs_parent_state_id_fkey FOREIGN KEY (parent_state_id) REFERENCES public.core_narrator_state(state_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_pending_output
    ADD CONSTRAINT narrator_pending_outputs_revision_id_fkey FOREIGN KEY (revision_id) REFERENCES public.core_narrator_definition_revision(revision_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_state
    ADD CONSTRAINT narrator_states_parent_state_id_fkey FOREIGN KEY (parent_state_id) REFERENCES public.core_narrator_state(state_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_state
    ADD CONSTRAINT narrator_states_selected_definition_id_fkey FOREIGN KEY (selected_definition_id) REFERENCES public.core_narrator_definition(definition_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_state
    ADD CONSTRAINT narrator_states_transcript_head_entry_id_fkey FOREIGN KEY (transcript_head_entry_id) REFERENCES public.core_narrator_transcript_entry(entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_cursor
    ADD CONSTRAINT narrator_transcript_cursors_next_entry_id_fkey FOREIGN KEY (next_entry_id) REFERENCES public.core_narrator_transcript_entry(entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_cursor
    ADD CONSTRAINT narrator_transcript_cursors_state_id_fkey FOREIGN KEY (state_id) REFERENCES public.core_narrator_state(state_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry
    ADD CONSTRAINT narrator_transcript_entries_definition_id_fkey FOREIGN KEY (definition_id) REFERENCES public.core_narrator_definition(definition_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry
    ADD CONSTRAINT narrator_transcript_entries_parent_entry_id_fkey FOREIGN KEY (parent_entry_id) REFERENCES public.core_narrator_transcript_entry(entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry
    ADD CONSTRAINT narrator_transcript_entries_parent_persona_entry_id_fkey FOREIGN KEY (parent_persona_entry_id) REFERENCES public.core_narrator_transcript_entry(entry_id);
-- end-statement
ALTER TABLE ONLY public.core_narrator_transcript_entry
    ADD CONSTRAINT narrator_transcript_entries_revision_id_fkey FOREIGN KEY (revision_id) REFERENCES public.core_narrator_definition_revision(revision_id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_field
    ADD CONSTRAINT profile_job_fields_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.core_character_generation(job_id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation
    ADD CONSTRAINT profile_job_inputs_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.core_worker_job(id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES public.core_character_generation_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_character_generation_revision
    ADD CONSTRAINT profile_revisions_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_protocol_event
    ADD CONSTRAINT protocol_events_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_protocol_event
    ADD CONSTRAINT protocol_events_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_rechat_chain
    ADD CONSTRAINT rechat_chains_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_rechat_chain
    ADD CONSTRAINT rechat_chains_session_id_root_request_id_fkey FOREIGN KEY (session_id, root_request_id) REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_rechat_continuation
    ADD CONSTRAINT rechat_continuations_chain_id_session_id_fkey FOREIGN KEY (chain_id, session_id) REFERENCES public.core_rechat_chain(id, session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_rechat_continuation
    ADD CONSTRAINT rechat_continuations_session_id_parent_request_id_fkey FOREIGN KEY (session_id, parent_request_id) REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_rechat_continuation
    ADD CONSTRAINT rechat_continuations_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_rechat_selection
    ADD CONSTRAINT rechat_selections_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_rechat_selection
    ADD CONSTRAINT rechat_selections_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES public.core_rechat_continuation(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_relationship_actor_control_event
    ADD CONSTRAINT relationship_actor_control_ev_session_id_generation_contex_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_relationship_actor_control_event
    ADD CONSTRAINT relationship_actor_control_events_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_actor_control
    ADD CONSTRAINT relationship_actor_controls_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation_source
    ADD CONSTRAINT relationship_evaluation_sources_evaluation_id_fkey FOREIGN KEY (evaluation_id) REFERENCES public.core_relationship_evaluation(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation_source
    ADD CONSTRAINT relationship_evaluation_sources_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation
    ADD CONSTRAINT relationship_evaluations_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation
    ADD CONSTRAINT relationship_evaluations_response_history_event_id_fkey FOREIGN KEY (response_history_event_id) REFERENCES public.core_relationship_response_input(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_evaluation
    ADD CONSTRAINT relationship_evaluations_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_eval_session_id_generation_contex_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_evaluat_response_history_event_id_fkey FOREIGN KEY (response_history_event_id) REFERENCES public.core_relationship_response_input(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_evaluations_listener_identity_id_fkey FOREIGN KEY (listener_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_evaluations_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_evaluation
    ADD CONSTRAINT relationship_interaction_evaluations_speaker_identity_id_fkey FOREIGN KEY (speaker_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_prior
    ADD CONSTRAINT relationship_interaction_priors_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_prior
    ADD CONSTRAINT relationship_interaction_priors_interaction_id_fkey FOREIGN KEY (interaction_id) REFERENCES public.core_relationship_interaction_evaluation(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_prior
    ADD CONSTRAINT relationship_interaction_priors_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES public.core_relationship_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result_prior
    ADD CONSTRAINT relationship_interaction_result_pr_result_history_event_id_fkey FOREIGN KEY (result_history_event_id) REFERENCES public.core_relationship_interaction_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result_prior
    ADD CONSTRAINT relationship_interaction_result_pri_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES public.core_relationship_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result_prior
    ADD CONSTRAINT relationship_interaction_result_priors_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result
    ADD CONSTRAINT relationship_interaction_results_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result
    ADD CONSTRAINT relationship_interaction_results_interaction_id_fkey FOREIGN KEY (interaction_id) REFERENCES public.core_relationship_interaction_evaluation(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_interaction_result
    ADD CONSTRAINT relationship_interaction_results_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.core_worker_job(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_input
    ADD CONSTRAINT relationship_response_inputs_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_input
    ADD CONSTRAINT relationship_response_inputs_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_input
    ADD CONSTRAINT relationship_response_inputs_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_source
    ADD CONSTRAINT relationship_response_sources_response_history_event_id_fkey FOREIGN KEY (response_history_event_id) REFERENCES public.core_relationship_response_input(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_response_source
    ADD CONSTRAINT relationship_response_sources_source_history_event_id_fkey FOREIGN KEY (source_history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result_prior
    ADD CONSTRAINT relationship_result_priors_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES public.core_relationship_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result_prior
    ADD CONSTRAINT relationship_result_priors_result_history_event_id_fkey FOREIGN KEY (result_history_event_id) REFERENCES public.core_relationship_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result
    ADD CONSTRAINT relationship_results_evaluation_id_fkey FOREIGN KEY (evaluation_id) REFERENCES public.core_relationship_evaluation(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result
    ADD CONSTRAINT relationship_results_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_result
    ADD CONSTRAINT relationship_results_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.core_worker_job(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision_source
    ADD CONSTRAINT relationship_revision_sources_relationship_history_event_i_fkey FOREIGN KEY (relationship_history_event_id) REFERENCES public.core_relationship_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision_source
    ADD CONSTRAINT relationship_revision_sources_source_history_event_id_fkey FOREIGN KEY (source_history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_interaction_result_history_event_id_fkey FOREIGN KEY (interaction_result_history_event_id) REFERENCES public.core_relationship_interaction_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_prior_history_event_id_fkey FOREIGN KEY (prior_history_event_id) REFERENCES public.core_relationship_revision(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_result_history_event_id_fkey FOREIGN KEY (result_history_event_id) REFERENCES public.core_relationship_result(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_relationship_revision
    ADD CONSTRAINT relationship_revisions_subject_identity_id_fkey FOREIGN KEY (subject_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY synth_meta.request_executions
    ADD CONSTRAINT request_executions_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_dialogue_route
    ADD CONSTRAINT response_routes_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_dialogue_route
    ADD CONSTRAINT response_routes_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id) ON DELETE CASCADE;
-- end-statement
ALTER TABLE ONLY public.core_social_task_claim
    ADD CONSTRAINT social_task_claims_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_claim
    ADD CONSTRAINT social_task_claims_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.core_social_task_input(task_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_event
    ADD CONSTRAINT social_task_events_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_event
    ADD CONSTRAINT social_task_events_prior_revision_fkey FOREIGN KEY (prior_revision) REFERENCES public.core_social_task_event(history_event_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_event
    ADD CONSTRAINT social_task_events_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.core_social_task_input(task_id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_input
    ADD CONSTRAINT social_task_inputs_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_input
    ADD CONSTRAINT social_task_inputs_performer_identity_id_fkey FOREIGN KEY (performer_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_input
    ADD CONSTRAINT social_task_inputs_player_identity_id_fkey FOREIGN KEY (player_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_social_task_input
    ADD CONSTRAINT social_task_inputs_recipient_identity_id_fkey FOREIGN KEY (recipient_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_visual_description_revision
    ADD CONSTRAINT visual_description_revisions_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_visual_description_revision
    ADD CONSTRAINT visual_description_revisions_capture_id_fkey FOREIGN KEY (capture_id) REFERENCES public.core_visual_result(capture_id);
-- end-statement
ALTER TABLE ONLY public.core_visual_description_revision
    ADD CONSTRAINT visual_description_revisions_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_history_event_id_fkey FOREIGN KEY (history_event_id) REFERENCES synth_meta.history_events(id);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_session_id_generation_context_sequence_fkey FOREIGN KEY (session_id, generation, context_sequence) REFERENCES public.core_runtime_context(session_id, generation, snapshot_sequence);
-- end-statement
ALTER TABLE ONLY public.core_visual_result
    ADD CONSTRAINT visual_results_session_id_request_id_fkey FOREIGN KEY (session_id, request_id) REFERENCES synth_meta.request_executions(session_id, request_id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_actor_identity_id_fkey FOREIGN KEY (actor_identity_id) REFERENCES synth.actor_identities(id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_diary_input_id_fkey FOREIGN KEY (diary_input_id) REFERENCES public.core_diary_input(id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_memory_batch_id_fkey FOREIGN KEY (memory_batch_id) REFERENCES public.core_memory_batch(id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_middleterm_input_id_fkey FOREIGN KEY (middleterm_input_id) REFERENCES public.core_middleterm_input(id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_relationship_evaluation_id_fkey FOREIGN KEY (relationship_evaluation_id) REFERENCES public.core_relationship_evaluation(id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_relationship_interaction_id_fkey FOREIGN KEY (relationship_interaction_id) REFERENCES public.core_relationship_interaction_evaluation(id);
-- end-statement
ALTER TABLE ONLY public.core_worker_job
    ADD CONSTRAINT worker_jobs_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.core_runtime_session(session_id);
-- end-statement
CREATE TABLE public.core_npc_actor (
    refid character varying(16) NOT NULL,
    plugin character varying(255) NOT NULL,
    character_id integer NOT NULL,
    name text,
    gender text,
    race text,
    actor_class text,
    base_plugin text,
    base_formid character varying(16),
    template_plugin text,
    template_formid character varying(16),
    replaced_character_id integer,
    replaced_name text,
    CONSTRAINT core_npc_actor_pkey PRIMARY KEY (refid, plugin),
    CONSTRAINT core_npc_actor_character_id_fkey FOREIGN KEY (character_id) REFERENCES synth.core_npc_master(id) ON DELETE CASCADE,
    CONSTRAINT core_npc_actor_plugin_check CHECK ((plugin = lower((plugin)::text))),
    CONSTRAINT core_npc_actor_refid_check CHECK (((refid)::text ~ '^[0-9a-f]{1,8}$'::text))
);
-- end-statement
CREATE INDEX core_npc_actor_character_idx ON public.core_npc_actor USING btree (character_id);
-- end-statement
CREATE TABLE public.core_npc_runtime_actor (
    playthrough_id text NOT NULL,
    runtime_actor_id uuid NOT NULL,
    character_id integer NOT NULL,
    last_form_id character(8) NOT NULL,
    name character varying(255) NOT NULL,
    race_plugin text,
    race_local_id character varying(8),
    gender text,
    actor_class text,
    base_plugin text,
    base_local_id character varying(8),
    template_plugin text,
    template_local_id character varying(8),
    fingerprint character(64),
    spawn_kind character varying(32) DEFAULT 'unknown'::character varying NOT NULL,
    spawn_context jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(16) DEFAULT 'active'::character varying NOT NULL,
    replaced_by_runtime_actor_id uuid,
    first_seen_gamets bigint,
    last_seen_gamets bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT core_npc_runtime_actor_pkey PRIMARY KEY (playthrough_id, runtime_actor_id),
    CONSTRAINT core_npc_runtime_actor_character_id_fkey FOREIGN KEY (character_id) REFERENCES synth.core_npc_master(id) ON DELETE RESTRICT,
    CONSTRAINT core_npc_runtime_actor_last_form_id_check CHECK ((last_form_id ~ '^[Ff][Ff][0-9A-Fa-f]{6}$'::text)),
    CONSTRAINT core_npc_runtime_actor_spawn_context_check CHECK ((jsonb_typeof(spawn_context) = 'object'::text)),
    CONSTRAINT core_npc_runtime_actor_spawn_kind_check CHECK (((spawn_kind)::text = ANY (ARRAY['leveled_spawn'::text, 'workshop_settler'::text, 'quest_alias'::text, 'script_placed'::text, 'summoned_or_cloned'::text, 'unknown'::text]))),
    CONSTRAINT core_npc_runtime_actor_status_check CHECK (((status)::text = ANY (ARRAY['active'::text, 'not_in_save'::text, 'retired'::text, 'replaced'::text])))
);
-- end-statement
CREATE UNIQUE INDEX core_npc_runtime_actor_active_form_idx ON public.core_npc_runtime_actor USING btree (playthrough_id, last_form_id) WHERE ((status)::text = 'active'::text);
-- end-statement
CREATE INDEX core_npc_runtime_actor_fingerprint_idx ON public.core_npc_runtime_actor USING btree (playthrough_id, fingerprint) WHERE ((status)::text <> 'retired'::text);
-- end-statement
CREATE TABLE public.core_plugin_manifest_revision (
    id bigint GENERATED ALWAYS AS IDENTITY NOT NULL,
    playthrough_id text NOT NULL,
    manifest_sha256 character(64) NOT NULL,
    plugins jsonb NOT NULL,
    runtime_variant text,
    game_version text,
    first_seen_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT core_plugin_manifest_revision_pkey PRIMARY KEY (id),
    CONSTRAINT core_plugin_manifest_revision_plugins_check CHECK ((jsonb_typeof(plugins) = 'array'::text)),
    CONSTRAINT core_plugin_manifest_revision_sha_check CHECK ((manifest_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT core_plugin_manifest_revision_unique UNIQUE (playthrough_id, manifest_sha256)
);
-- end-statement
