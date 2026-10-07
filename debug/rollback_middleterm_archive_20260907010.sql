-- Stop workers and restore the preceding source before this pre-archive rollback.
BEGIN;
SET LOCAL lock_timeout='2s';
SET LOCAL statement_timeout='10s';
LOCK TABLE synth_meta.middleterm_control_revisions IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
    IF COALESCE((SELECT version FROM synth.database_versioning WHERE tablename='synth_protocol_schema'),0)<>20260907010 THEN
        RAISE EXCEPTION 'Rollback requires exact protocol schema 20260907010';
    END IF;
    IF EXISTS(SELECT 1 FROM synth_meta.middleterm_control_revisions WHERE control_payload->>'schema'<>'synth.middleterm.control.v1') THEN
        RAISE EXCEPTION 'Narrative archive controls exist; rollback refused to preserve saved state';
    END IF;
END $$;
ALTER TABLE synth_meta.middleterm_control_revisions DROP CONSTRAINT middleterm_control_revisions_control_payload_check;
ALTER TABLE synth_meta.middleterm_control_revisions ADD CONSTRAINT middleterm_control_revisions_control_payload_check
    CHECK(jsonb_typeof(control_payload)='object' AND COALESCE(control_payload->>'schema','')='synth.middleterm.control.v1');
UPDATE synth.database_versioning SET version=20260907009 WHERE tablename='synth_protocol_schema' AND version=20260907010;
COMMIT;
