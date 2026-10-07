-- Stop workers and restore preceding source before this pre-approval rollback.
BEGIN;
SET LOCAL lock_timeout='2s';
SET LOCAL statement_timeout='10s';
LOCK TABLE synth_meta.middleterm_regeneration_approvals IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
    IF COALESCE((SELECT version FROM synth.database_versioning WHERE tablename='synth_protocol_schema'),0)<>20260907011 THEN
        RAISE EXCEPTION 'Rollback requires exact protocol schema 20260907011';
    END IF;
    IF EXISTS(SELECT 1 FROM synth_meta.middleterm_regeneration_approvals)
        OR EXISTS(SELECT 1 FROM synth_meta.history_events WHERE event_payload->>'type'='middleterm_regeneration') THEN
        RAISE EXCEPTION 'Regeneration approval history exists; rollback refused to preserve intent';
    END IF;
END $$;
DROP TABLE synth_meta.middleterm_regeneration_approvals;
ALTER TABLE synth_meta.history_events DROP CONSTRAINT history_request_or_manual;
ALTER TABLE synth_meta.history_events ADD CONSTRAINT history_request_or_manual CHECK (
    request_id IS NOT NULL OR (COALESCE(event_payload->>'type','') IN ('relationship_manual','visual_manual','diary_control','middleterm_control')
        AND event_payload->>'delivery_state' IS NOT DISTINCT FROM 'derived'));
UPDATE synth.database_versioning SET version=20260907010 WHERE tablename='synth_protocol_schema' AND version=20260907011;
COMMIT;
