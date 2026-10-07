-- Stop workers and restore preceding source before this pre-admission rollback.
BEGIN;
SET LOCAL lock_timeout='2s';
SET LOCAL statement_timeout='10s';
LOCK TABLE synth_meta.middleterm_inputs,synth_meta.middleterm_replacements IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
    IF COALESCE((SELECT version FROM synth.database_versioning WHERE tablename='synth_protocol_schema'),0)<>20260907012 THEN
        RAISE EXCEPTION 'Rollback requires exact protocol schema 20260907012';
    END IF;
    IF EXISTS(SELECT 1 FROM synth_meta.middleterm_replacements)
        OR EXISTS(SELECT 1 FROM synth_meta.middleterm_inputs WHERE regeneration_approval_id IS NOT NULL) THEN
        RAISE EXCEPTION 'Regeneration execution exists; rollback refused to preserve owned work';
    END IF;
END $$;
DROP TABLE synth_meta.middleterm_replacements;
ALTER TABLE synth_meta.middleterm_inputs DROP COLUMN regeneration_approval_id;
ALTER TABLE synth_meta.middleterm_regeneration_approvals DROP COLUMN last_considered_at;
UPDATE synth.database_versioning SET version=20260907011 WHERE tablename='synth_protocol_schema' AND version=20260907012;
COMMIT;
