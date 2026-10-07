-- Stop workers and restore the preceding source before this pre-correction rollback.
-- Populated corrections are deliberately retained; use verified backups instead.
BEGIN;
SET LOCAL lock_timeout='2s';
SET LOCAL statement_timeout='10s';
LOCK TABLE synth_meta.middleterm_control_revisions IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
    IF COALESCE((SELECT version FROM synth.database_versioning WHERE tablename='synth_protocol_schema'),0)<>20260907009 THEN
        RAISE EXCEPTION 'Rollback requires exact protocol schema 20260907009';
    END IF;
    IF EXISTS(SELECT 1 FROM synth_meta.middleterm_control_revisions) THEN
        RAISE EXCEPTION 'Narrative corrections exist; rollback refused to avoid data loss';
    END IF;
END $$;
DROP TABLE synth_meta.middleterm_control_revisions;
ALTER TABLE synth_meta.history_events DROP CONSTRAINT history_request_or_manual;
ALTER TABLE synth_meta.history_events ADD CONSTRAINT history_request_or_manual CHECK (
    request_id IS NOT NULL OR (COALESCE(event_payload->>'type','') IN ('relationship_manual','visual_manual','diary_control')
        AND event_payload->>'delivery_state' IS NOT DISTINCT FROM 'derived'));
UPDATE synth.database_versioning SET version=20260907008 WHERE tablename='synth_protocol_schema' AND version=20260907009;
COMMIT;
