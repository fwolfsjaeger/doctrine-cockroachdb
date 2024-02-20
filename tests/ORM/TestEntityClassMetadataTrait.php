<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
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
        $classMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_CUSTOM;
        $classMetadata->idGenerator = new SerialGenerator();
        $classMetadata->customGeneratorDefinition = ['class' => SerialGenerator::class];

        $idFieldMapping = new FieldMapping(Types::INTEGER, 'id', 'an_identifier');
        $idFieldMapping->options['id'] = true;
        $idFieldMapping->options['options'] = ['unsigned' => true];

        $id2FieldMapping = new FieldMapping(Types::INTEGER, 'id2', 'second_identifier');
        $id2FieldMapping->options['id'] = true;
        $id2FieldMapping->options['options'] = ['unsigned' => true];

        $classMetadata->fieldNames = ['an_identifier' => 'id', 'second_identifier' => 'id2'];
        $classMetadata->fieldMappings = [
            'id' => $idFieldMapping,
            'id2' => $id2FieldMapping,
        ];

        return $classMetadata;
    }
}
