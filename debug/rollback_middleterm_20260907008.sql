-- Stop server workers and restore the preceding source before running this pre-admission rollback.
-- Populated journals are deliberately refused: preserve them with a verified backup/restore instead.
BEGIN;
SET LOCAL lock_timeout='2s';
SET LOCAL statement_timeout='10s';
LOCK TABLE public.core_worker_job, public.core_middleterm_input, public.core_middleterm_source,
    public.core_middleterm_result IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
    IF COALESCE((SELECT version FROM synth.database_versioning WHERE tablename='synth_protocol_schema'),0)<>20260907008 THEN
        RAISE EXCEPTION 'Rollback requires exact protocol schema 20260907008';
    END IF;
    IF EXISTS(SELECT 1 FROM public.core_middleterm_input)
        OR EXISTS(SELECT 1 FROM public.core_worker_job WHERE job_type='middleterm') THEN
        RAISE EXCEPTION 'Narrative data exists; rollback refused to avoid data loss';
    END IF;
END $$;
ALTER TABLE public.core_worker_job DROP COLUMN middleterm_input_id;
ALTER TABLE public.core_runtime_session DROP COLUMN middleterm_dispatch_after;
DROP TABLE public.core_middleterm_result;
DROP TABLE public.core_middleterm_source;
DROP TABLE public.core_middleterm_input;
ALTER TABLE public.core_worker_job DROP CONSTRAINT worker_jobs_job_type_check;
ALTER TABLE public.core_worker_job ADD CONSTRAINT worker_jobs_job_type_check
    CHECK(job_type IN ('memory','relationship','profile','diary','tts'));
UPDATE synth.database_versioning SET version=20260907007 WHERE tablename='synth_protocol_schema' AND version=20260907008;
COMMIT;
