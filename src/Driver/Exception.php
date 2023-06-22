<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Driver;

use Doctrine\DBAL\Driver\AbstractException;
use PDOException;

final class Exception extends AbstractException
{
    public static function new(PDOException $exception): self
    {
        if (null !== $exception->errorInfo) {
            [$sqlState, $code] = $exception->errorInfo;
            $code ??= 0;
        } else {
            $code = $exception->getCode();
            $sqlState = null;
        }

        return new self($exception->getMessage(), $sqlState, $code, $exception);
    }
}
