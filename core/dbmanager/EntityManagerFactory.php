<?php

namespace Synth\Core\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Proxy\ProxyFactory;

// Builds the Doctrine EntityManager for the pilot reads on the legacy sql wrapper's shared session. It has no
// credentials, schema tool, migrations, metadata/result cache or writable path of its own.
final class EntityManagerFactory
{
    public static function forSharedSession(object $db): EntityManagerInterface
    {
        // Production dependencies come only from the root composer.lock; a missing tree is a deployment error,
        // never a reason to fall back to another read path.
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException('Synthserver production dependencies are missing; run composer install --no-dev in the server root');
        }
        require_once $autoload;
        require_once __DIR__.'/SharedSessionDriver.php';
        require_once __DIR__.'/entities/GeneralSetting.php';

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/entities', dirname(__DIR__).'/entities/base']));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            // Read-only scalar queries never need a proxy; eval mode keeps any that Doctrine builds out of the
            // filesystem, so the directory is only the setting Doctrine requires and is never written.
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Synth\Core\Entities\Proxies');
            $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        }

        $connection = new Connection([], new SharedSessionDriver($db), $config);
        return new EntityManager($connection, $config);
    }
}
