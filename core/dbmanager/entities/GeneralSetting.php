<?php

namespace Synth\Core\Entities;

use Doctrine\ORM\Mapping as ORM;

// Read-only mapping of the general scope of core_config_setting (the two columns settings reads use). Every write
// stays in lib/settings.php; Doctrine never creates, alters, migrates or flushes the table.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_config_setting', schema: 'public')]
class GeneralSetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $scope;

    #[ORM\Id]
    #[ORM\Column(name: 'config_key', type: 'text')]
    private string $id;

    #[ORM\Column(name: 'config_value', type: 'text', nullable: true)]
    private ?string $value = null;
}
