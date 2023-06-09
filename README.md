[![Latest Stable Version](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/v)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![Total Downloads](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/downloads)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![PHP Version Require](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/require/php)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)
[![License](http://poser.pugx.org/fwolfsjaeger/doctrine-cockroachdb/license)](https://packagist.org/packages/fwolfsjaeger/doctrine-cockroachdb)

# CockroachDB Driver

CockroachDB Driver is a Doctrine DBAL Driver to handle incompatibilities with PostgreSQL. This package is intended to be
used for Symfony.

It is based on https://github.com/lapaygroup/doctrine-cockroachdb by Lapay Group.

## CockroachDB Quick Setup Guide

- [Linux Setup Guide](https://www.cockroachlabs.com/docs/stable/install-cockroachdb-linux.html)
- [Mac Setup Guide](https://www.cockroachlabs.com/docs/v23.1/install-cockroachdb-mac)
- [Windows Setup Guide](https://www.cockroachlabs.com/docs/v23.1/install-cockroachdb-windows)

## Usage

### Connection configuration example using a DSN

```yaml
# doctrine.yaml
doctrine:
    dbal:
        url: crdb://<user>@<host>:<port(26257)>/<dbname>?sslmode=verify-full&sslrootcert=<path-to-ca.crt>&sslcert=<path-to-user.crt>&sslkey=<path-to-user.key>
```

### Alternative: YAML connection configuration example

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
        driver: crdb
```

## License

[MIT](https://choosealicense.com/licenses/mit/)
