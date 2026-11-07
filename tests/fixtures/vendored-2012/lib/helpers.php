<?php

function bootstrap_entity_manager()
{
    $config = Doctrine\ORM\Tools\Setup::createAnnotationMetadataConfiguration(array(__DIR__ . '/../entities'), true);

    return Doctrine\ORM\EntityManager::create(array('driver' => 'pdo_sqlite', 'memory' => true), $config);
}
