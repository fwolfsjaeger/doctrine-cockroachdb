<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\ORM\Id;

use BadMethodCallException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

/**
 * Custom generator for supporting CockroachDB's SERIAL primary key.
 */
final class SerialGenerator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, null|object $entity): mixed
    {
        throw new BadMethodCallException(
            'CockroachDB doesn\'t support getting last ID/SERIAL value. ' .
                'Make sure to use the the custom BasicEntityPersister when using this strategy',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function isPostInsertGenerator(): bool
    {
        return true;
    }
}
