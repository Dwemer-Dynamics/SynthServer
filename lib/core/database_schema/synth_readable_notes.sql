-- Readable paper notes: a deduplicated body catalogue plus typed, insert-only read and share grants. Each grant is
-- keyed by the immutable history_events marker its claimed request published under the session lock, so saved
-- branches see it through the existing insertion frontiers (native_history_scopes). The marker is data-free and has
-- no people, so no generic history, diary or memory reader shows it; a body is reachable only through a grant row.
-- Source migration only: apply through debug/db_updates.php, never at request time.
-- A changed body under the same stable key is a new revision; earlier revisions and their grants stay intact.
CREATE TABLE IF NOT EXISTS synth_meta.note_revisions (
 id BIGSERIAL PRIMARY KEY,
 note_key TEXT NOT NULL CHECK(note_key ~ '^[^/\\:[:cntrl:]]+\.(esm|esp|esl):[0-9A-F]{8}$' AND char_length(note_key)<=273),
 body_sha256 CHAR(64) NOT NULL CHECK(body_sha256 ~ '^[0-9a-f]{64}$'),
 body TEXT NOT NULL CHECK(octet_length(body) BETWEEN 1 AND 16384),
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(note_key,body_sha256)
);
-- One private read per claimed note_read request, owned by the bound player identity of that snapshot.
CREATE TABLE IF NOT EXISTS synth_meta.note_reads (
 history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
 session_id TEXT NOT NULL,
 request_id TEXT NOT NULL,
 generation BIGINT NOT NULL CHECK(generation>0),
 context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
 playthrough_id TEXT NOT NULL CHECK(playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 reader_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
 revision_id BIGINT NOT NULL REFERENCES synth_meta.note_revisions(id),
 title TEXT CHECK(title IS NULL OR octet_length(title) BETWEEN 1 AND 255),
 runtime_variant TEXT NOT NULL CHECK(runtime_variant='flat'),
 client_version TEXT NOT NULL,
 runtime_version TEXT NOT NULL,
 UNIQUE(session_id,request_id),
 FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
 FOREIGN KEY(session_id,generation,context_sequence) REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence)
);
CREATE INDEX IF NOT EXISTS note_reads_reader_history
 ON synth_meta.note_reads(reader_identity_id,session_id,generation,history_event_id DESC);
-- One explicit hand-over of one exact read revision to the snapshot's addressed target.
CREATE TABLE IF NOT EXISTS synth_meta.note_shares (
 history_event_id BIGINT PRIMARY KEY REFERENCES synth_meta.history_events(id),
 session_id TEXT NOT NULL,
 request_id TEXT NOT NULL,
 generation BIGINT NOT NULL CHECK(generation>0),
 context_sequence BIGINT NOT NULL CHECK(context_sequence>0),
 playthrough_id TEXT NOT NULL CHECK(playthrough_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 sharer_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
 recipient_identity_id BIGINT NOT NULL REFERENCES synth.actor_identities(id),
 read_history_event_id BIGINT NOT NULL REFERENCES synth_meta.note_reads(history_event_id),
 revision_id BIGINT NOT NULL REFERENCES synth_meta.note_revisions(id),
 mode TEXT NOT NULL CHECK(mode IN ('STANDARD','WHISPER','CLOSE')),
 runtime_variant TEXT NOT NULL CHECK(runtime_variant='flat'),
 client_version TEXT NOT NULL,
 runtime_version TEXT NOT NULL,
 UNIQUE(session_id,request_id),
 FOREIGN KEY(session_id,request_id) REFERENCES synth_meta.request_executions(session_id,request_id),
 FOREIGN KEY(session_id,generation,context_sequence) REFERENCES synth_meta.context_snapshots(session_id,generation,snapshot_sequence),
 CHECK(recipient_identity_id<>sharer_identity_id),
 CHECK(read_history_event_id<history_event_id)
);
CREATE INDEX IF NOT EXISTS note_shares_recipient_history
 ON synth_meta.note_shares(recipient_identity_id,session_id,generation,history_event_id DESC);

-- Grants are immutable, and each one must name the marker its own request published in the same save lane: the
-- matching note_* type and owner columns. A share must hand over the sharer's own earlier read of the same revision
-- in the same playthrough. Checked here so no caller can attach a grant to someone else's history.
CREATE OR REPLACE FUNCTION synth_meta.note_grant_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'readable note grants are immutable'; END IF;
 IF NOT EXISTS(SELECT 1 FROM synth_meta.history_events h WHERE h.id=NEW.history_event_id
   AND h.session_id=NEW.session_id AND h.generation=NEW.generation AND h.context_sequence=NEW.context_sequence
   AND h.request_id=NEW.request_id AND h.playthrough_id=NEW.playthrough_id
   AND h.event_payload->>'type'=(CASE TG_TABLE_NAME WHEN 'note_reads' THEN 'note_read' ELSE 'note_share' END)
   AND COALESCE(h.event_payload->>'people','')='') THEN
  RAISE EXCEPTION 'readable note grant has no matching history marker';
 END IF;
 IF TG_TABLE_NAME='note_shares' THEN
  IF NOT EXISTS(SELECT 1 FROM synth_meta.note_reads r
    WHERE r.history_event_id=NEW.read_history_event_id AND r.reader_identity_id=NEW.sharer_identity_id
      AND r.revision_id=NEW.revision_id AND r.playthrough_id=NEW.playthrough_id) THEN
   RAISE EXCEPTION 'readable note share does not hand over the sharer''s read';
  END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS note_reads_guard ON synth_meta.note_reads;
CREATE TRIGGER note_reads_guard BEFORE INSERT OR UPDATE OR DELETE ON synth_meta.note_reads
 FOR EACH ROW EXECUTE FUNCTION synth_meta.note_grant_guard();
DROP TRIGGER IF EXISTS note_shares_guard ON synth_meta.note_shares;
CREATE TRIGGER note_shares_guard BEFORE INSERT OR UPDATE OR DELETE ON synth_meta.note_shares
 FOR EACH ROW EXECUTE FUNCTION synth_meta.note_grant_guard();
DROP TRIGGER IF EXISTS note_revisions_guard ON synth_meta.note_revisions;
CREATE TRIGGER note_revisions_guard BEFORE UPDATE OR DELETE ON synth_meta.note_revisions
 FOR EACH ROW EXECUTE FUNCTION synth_meta.note_grant_guard();
