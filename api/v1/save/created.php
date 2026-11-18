<?php

declare(strict_types=1);

// POST /api/v1/save/created: the player made a save. SYNTH has no save-created signal today (the client does
// not send one), so there is nothing to adapt; the route answers 501 until the signal exists.
synthApiRefuse(501, 'Not implemented', 'not_implemented');
