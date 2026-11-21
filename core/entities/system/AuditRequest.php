<?php

// One row of core_audit_request as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractAuditRequest;

/** One row of core_audit_request. */
final class AuditRequest extends AbstractAuditRequest
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractAuditRequest $mapped): self
    {
        return self::copyOf($mapped);
    }
}
