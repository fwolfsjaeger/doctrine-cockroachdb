<?php

namespace DoctrineCockroachDB\Platforms\Keywords;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\Deprecations\Deprecation;

/**
 * Reserved keywords list corresponding to the CockroachDB platform.
 */
class CockroachDBKeywords extends KeywordList
{
    /**
     * {@inheritdoc}
     *
     * @deprecated
     */
    public function getName(): string
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5433',
            'CockroachDBKeywords::getName() is deprecated.',
        );

        return 'CockroachDB';
    }

    /**
     * {@inheritdoc}
     */
    protected function getKeywords(): array
    {
        return [
            'ALL',
            'ANALYSE',
            'ANALYZE',
            'AND',
            'ANY',
            'ARRAY',
            'AS',
            'ASC',
            'ASYMMETRIC',
            'AUTHORIZATION',
            'BINARY',
            'BOTH',
            'CASE',
            'CAST',
            'CHECK',
            'COLLATE',
            'COLLATION',
            'COLUMN',
            'CONCURRENTLY',
            'CONSTRAINT',
            'CREATE',
            'CROSS',
            'CURRENT_CATALOG',
            'CURRENT_DATE',
            'CURRENT_ROLE',
            'CURRENT_SCHEMA',
            'CURRENT_TIME',
            'CURRENT_TIMESTAMP',
            'CURRENT_USER',
            'DEFAULT',
            'DEFERRABLE',
            'DESC',
            'DISTINCT',
            'DO',
            'ELSE',
            'END',
            'EXCEPT',
            'FALSE',
            'FETCH',
            'FOR',
            'FOREIGN',
            'FREEZE',
            'FROM',
            'FULL',
            'GRANT',
            'GROUP',
            'HAVING',
            'ILIKE',
            'IN',
            'INITIALLY',
            'INNER',
            'INTERSECT',
            'INTO',
            'IS',
            'ISNULL',
            'JOIN',
            'LATERAL',
            'LEADING',
            'LEFT',
            'LIKE',
            'LIMIT',
            'LOCALTIME',
            'LOCALTIMESTAMP',
            'NATURAL',
            'NOT',
            'NOTNULL',
            'NULL',
            'OFFSET',
            'ON',
            'ONLY',
            'OR',
            'ORDER',
            'OUTER',
            'OVERLAPS',
            'PLACING',
            'PRIMARY',
            'REFERENCES',
            'RETURNING',
            'RIGHT',
            'SELECT',
            'SESSION_USER',
            'SIMILAR',
            'SOME',
            'SYMMETRIC',
            'TABLE',
            'THEN',
            'TO',
            'TRAILING',
            'TRUE',
            'UNION',
            'UNIQUE',
            'USER',
            'USING',
            'VARIADIC',
            'VERBOSE',
            'WHEN',
            'WHERE',
            'WINDOW',
            'WITH',
        ];
    }
}
