[![Latest Stable Version](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/v)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![Total Downloads](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/downloads)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![PHP Version Require](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/require/php)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![License](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/license)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)

# CockroachDB Driver

CockroachDB Driver is a Doctrine DBAL Driver to handle incompatibilities with PostgreSQL.

It is based on https://github.com/lapaygroup/doctrine-cockroachdb by Lapay Group.

## CockroachDB Quick Setup Guide

- [Linux Setup Guide](https://www.cockroachlabs.com/docs/stable/install-cockroachdb-linux.html)
- [Mac Setup Guide](https://www.cockroachlabs.com/docs/v23.1/install-cockroachdb-mac)
- [Windows Setup Guide](https://www.cockroachlabs.com/docs/v23.1/install-cockroachdb-windows)

## Usage

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

## Unit testing
Start an insecure single-node instance:
```sh
cockroach start-single-node
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
```

## License

[MIT](https://choosealicense.com/licenses/mit/)
