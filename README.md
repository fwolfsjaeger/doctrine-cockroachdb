[![Latest Stable Version](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/v)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![Total Downloads](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/downloads)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![PHP Version Require](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/require/php)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![License](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/license)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)

# CockroachDB Driver

CockroachDB Driver is a Doctrine DBAL Driver and ORM patcher to handle incompatibilities with PostgreSQL.

It is based on https://github.com/lapaygroup/doctrine-cockroachdb by Lapay Group.

## CockroachDB Quick Setup Guide

- [Linux Setup Guide](https://www.cockroachlabs.com/docs/stable/install-cockroachdb-linux.html)
- [Mac Setup Guide](https://www.cockroachlabs.com/docs/v23.1/install-cockroachdb-mac)
- [Windows Setup Guide](https://www.cockroachlabs.com/docs/v23.1/install-cockroachdb-windows)

## Usage

### Configuration
```yaml
# doctrine.yaml
doctrine:
    dbal:
        user: <user>
        port: <port(26257)>
        host: <host>
        dbname: <dbname>
        sslmode: verify-full
        sslrootcert: <path-to-ca.crt>
        sslcert: <path-to-user.crt>
        sslkey: <path-to-user.key>
        driver: pdo_pgsql
        driver_class: DoctrineCockroachDB\Driver\CockroachDBDriver
```

### (Optional) Use enhanced BasicEntityPersister and SerialGenerator
For improved compatibility and performance we recommend you to override Doctrine ORM's default BasicEntityPersister
with the custom one provided with this package.
When using the custom BasicEntityPersister you can use CockroachDB's built in SERIAL generator for primary keys,
which performs vastly better than Doctrine's recommended SequenceGenerator.

Overriding is done by adding the `exclude-from-classmap` and `files` keys to your composer.json autoload section, example:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        },
        "exclude-from-classmap": ["vendor/doctrine/orm/src/Persisters/Entity/BasicEntityPersister.php"],
        "files": ["vendor/fwolfsjaeger/doctrine-cockroachdb/src/ORM/Persisters/Entity/BasicEntityPersister.php"]
    }
}
```

and then change your entities to use the `SerialGenerator` provided by this package:
```php
<?php

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;

#[Entity]
#[Table]
class Entity
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: SerialGenerator::class)]
    #[ORM\Column(name: 'id', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $id;
}
```

Finally, you should register the `DoctrineCockroachDB\ORM\Listener\AddDefaultToSerialGeneratorListener` and
`DoctrineCockroachDB\ORM\Listener\RemoveDefaultFromForeignKeysListener` (in that order)
to get proper default values for the identifiers using SerialGenerator when using Doctrine ORM.

## Troubleshooting
#### ERROR:  currval(): could not determine data type of placeholder $1
This is caused by using the IdentityGenerator as GenerateValue strategy and Doctrine ORM's default `BasicEntityPersister`.
It is solved by using our custom `BasicEntityPersister` and `SerialGenerator`, see above for instructions.

## Unit testing
Start an insecure single-node instance:
```sh
cockroach start-single-node \
  --store='type=mem,size=1GB' \
  --log='sinks: {stderr: {channels: [DEV]}}' \
  --listen-addr=127.0.0.1:26257 \
  --insecure \
  --accept-sql-without-tls
```

Connect to CockroachDB:
```sh
cockroach sql --host=127.0.0.1:26257 --insecure
```

Create the user & database for the tests:
```postgresql
CREATE USER "doctrine_tests";
CREATE DATABASE doctrine_tests OWNER "doctrine_tests";
USE doctrine_tests;
CREATE SCHEMA doctrine_tests AUTHORIZATION "doctrine_tests";
ALTER DATABASE doctrine_tests SET search_path = doctrine_tests;
GRANT ALL PRIVILEGES ON DATABASE doctrine_tests TO "doctrine_tests";
GRANT ALL PRIVILEGES ON SCHEMA doctrine_tests TO "doctrine_tests";
CREATE TABLE doctrine_tests.TestEntity (an_identifier SERIAL4 NOT NULL, second_identifier SERIAL4 NOT NULL, a_string_column VARCHAR(255) NOT NULL);
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA doctrine_tests TO "doctrine_tests";
```

## License

[MIT](https://choosealicense.com/licenses/mit/)
