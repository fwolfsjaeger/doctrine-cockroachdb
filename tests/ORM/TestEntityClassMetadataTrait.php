<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;
use DoctrineCockroachDB\Tests\ORM\Persisters\Entity\TestEntity;

trait TestEntityClassMetadataTrait
{
    private function getTestEntityClassMetadata(): ClassMetadata
    {
        $classMetadata = new ClassMetadata(TestEntity::class);
        $classMetadata->initializeReflection(new RuntimeReflectionService());
        $classMetadata->identifier = ['id', 'id2'];
        $classMetadata->generatorType = ClassMetadataInfo::GENERATOR_TYPE_CUSTOM;
        $classMetadata->idGenerator = new SerialGenerator();
        $classMetadata->customGeneratorDefinition = ['class' => SerialGenerator::class];
        $commonFieldMapping = [
                'type' => Types::INTEGER,
                'id' => true,
                'options' => ['unsigned' => true],
            ];
        $classMetadata->fieldMappings = [
            'id' => array_merge($commonFieldMapping, ['fieldName' => 'id', 'columnName' => 'an_identifier']),
            'id2' => array_merge($commonFieldMapping, ['fieldName' => 'id2', 'columnName' => 'second_identifier']),
        ];
        $classMetadata->fieldNames = ['an_identifier' => 'id', 'second_identifier' => 'id2'];

        return $classMetadata;
    }
}
