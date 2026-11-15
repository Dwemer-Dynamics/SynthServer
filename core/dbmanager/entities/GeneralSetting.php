<?php

namespace Synth\Core\Entities;

use Doctrine\ORM\Mapping as ORM;

// Read-only mapping of the two columns settings reads use. The table, its key and every write stay owned by
// lib/core/database_schema/general_settings.sql, debug/db_updates.php and lib/settings.php; Doctrine never
// creates, alters, migrates or flushes it.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'general_settings', schema: 'synth')]
class GeneralSetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $id;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;
}
