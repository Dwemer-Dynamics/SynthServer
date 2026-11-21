<?php

declare(strict_types=1);

namespace Synth\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// The durable outbound message queue (docs/CHEVO-PARITY.md, MessageHandler). One row is one result the client fetches by id:
// created `pending` when the work is admitted (a speech job), `completed` with its final JSON payload when the work finishes,
// `delivered` by the one fetch that returns it, or `cancelled` / `expired`. The lifecycle is the CHIM 4 server's
// core_message_queue, with a session and generation in place of its request scope: SYNTH scopes stale work by the runtime
// session and generation, and an utterance (dedup_key) is admitted once per request. Statuses only move forward:
// pending -> completed -> delivered, and pending or completed -> cancelled or expired (core/dbmanager/dao/MessageQueueDao).
// Fresh installs and upgrades both run this migration, so every statement is idempotent. Uses addSql() only.
final class Version20261120110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_message_queue, the durable outbound message queue';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    /** @return list<string> */
    public static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS public.core_message_queue ('
            . 'id bigserial NOT NULL,'
            . ' message_type text NOT NULL,'
            . ' session_id text NOT NULL,'
            . ' request_id text NOT NULL,'
            . ' generation bigint NOT NULL,'
            . ' dedup_key text NOT NULL,'
            . ' payload jsonb NULL,'
            . " status text NOT NULL DEFAULT 'pending',"
            . ' created_at timestamp with time zone NOT NULL DEFAULT now(),'
            . ' completed_at timestamp with time zone NULL,'
            . ' delivered_at timestamp with time zone NULL,'
            . ' cancelled_at timestamp with time zone NULL,'
            . ' expires_at timestamp with time zone NOT NULL,'
            . ' notified_at timestamp with time zone NULL,'
            . ' notify_attempts integer NOT NULL DEFAULT 0,'
            . ' last_error text NULL,'
            . ' CONSTRAINT core_message_queue_pkey PRIMARY KEY (id),'
            . ' CONSTRAINT core_message_queue_generation_check CHECK (generation > 0),'
            . ' CONSTRAINT core_message_queue_status_check CHECK (status = ANY (ARRAY[\'pending\', \'completed\', \'delivered\', \'cancelled\', \'expired\']))'
            . ')',
            'CREATE UNIQUE INDEX IF NOT EXISTS core_message_queue_dedup ON public.core_message_queue (session_id, request_id, dedup_key)',
            'CREATE INDEX IF NOT EXISTS core_message_queue_status ON public.core_message_queue (status, created_at, id)',
            'CREATE INDEX IF NOT EXISTS core_message_queue_session ON public.core_message_queue (session_id, generation)',
        ];
    }

    public function up(Schema $schema): void
    {
        foreach (self::statements() as $statement) {
            $this->addSql($statement);
        }
    }

    // Like the earlier migrations, the owner's revertTo() removes only the ledger record; the table stays.
    public function down(Schema $schema): void
    {
    }
}
