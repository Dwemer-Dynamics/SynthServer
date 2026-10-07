-- Fallout 4 NPC biography baseline.
-- Provenance: canonical actor identity and actor-profile data supplied by the connected
-- Fallout 4/Fallout 4 VR runtime. Static FNV/Fallout 3 name-based biographies are unsafe
-- because names are not identities and quest outcomes differ by playthrough.

BEGIN;
TRUNCATE TABLE synth.bio_templates;
COMMIT;
