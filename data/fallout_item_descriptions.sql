-- Fallout 4 item-description baseline.
-- Provenance: live records synchronized by the connected Fallout 4/Fallout 4 VR client.
-- No FNV/Fallout 3 form IDs are retained and display names are never treated as identity.

BEGIN;
DELETE FROM synth.descriptions
WHERE lower(plugin) IN (
    'falloutnv.esm', 'deadmoney.esm', 'honesthearts.esm', 'oldworldblues.esm',
    'lonesomeroad.esm', 'gunrunnersarsenal.esm', 'classicpack.esm',
    'mercenarypack.esm', 'tribalpack.esm', 'caravanpack.esm', 'fallout3.esm',
    'anchorage.esm', 'thepitt.esm', 'brokensteel.esm', 'pointlookout.esm', 'zeta.esm'
);
COMMIT;
