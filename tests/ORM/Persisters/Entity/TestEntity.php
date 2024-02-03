<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM\Persisters\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'TestEntity')]
class TestEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: SerialGenerator::class)]
    #[ORM\Column(name: 'an_identifier', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $id;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: SerialGenerator::class)]
    #[ORM\Column(name: 'second_identifier', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $id2;

    #[ORM\Column(name: 'a_string_column', type: Types::STRING, length: 255)]
    private string $string;

    public function __construct(string $string)
    {
        $this->string = $string;
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }
}
