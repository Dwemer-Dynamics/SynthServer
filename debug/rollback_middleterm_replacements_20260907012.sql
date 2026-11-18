-- Stop workers and restore preceding source before this pre-admission rollback.
BEGIN;
SET LOCAL lock_timeout='2s';
SET LOCAL statement_timeout='10s';
LOCK TABLE public.core_middleterm_input,public.core_middleterm_replacement IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
    IF COALESCE((SELECT version FROM synth.database_versioning WHERE tablename='synth_protocol_schema'),0)<>20260907012 THEN
        RAISE EXCEPTION 'Rollback requires exact protocol schema 20260907012';
    END IF;
    IF EXISTS(SELECT 1 FROM public.core_middleterm_replacement)
        OR EXISTS(SELECT 1 FROM public.core_middleterm_input WHERE regeneration_approval_id IS NOT NULL) THEN
        RAISE EXCEPTION 'Regeneration execution exists; rollback refused to preserve owned work';
    END IF;
END $$;
DROP TABLE public.core_middleterm_replacement;
ALTER TABLE public.core_middleterm_input DROP COLUMN regeneration_approval_id;
ALTER TABLE public.core_middleterm_regeneration_approval DROP COLUMN last_considered_at;
UPDATE synth.database_versioning SET version=20260907011 WHERE tablename='synth_protocol_schema' AND version=20260907012;
COMMIT;
