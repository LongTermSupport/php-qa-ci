# PHP-QA-CI

## Introduction

PHP-QA-CI is a quality assurance and continuous integration pipeline written in BASH that can be run both on the desktop
as part of your development process and then also as part of a continuous integration (CI) pipeline.

It runs tools in a logical order and will fail as quickly as possible.

This package is written for and has only been tested on Linux.

## Docs

Documentation is something of a work in progress, however you can find various docs in the [./docs](./docs) folder

## Other notes

### Specify PHP Binary Path

if you are running multiple PHP versions, you can specify which one to use like so:

```bash
export PHP_QA_CI_PHP_EXECUTABLE=/bin/php81

./bin/qa
```

or 

```
PHP_QA_CI_PHP_EXECUTABLE=/bin/php81 ./bin/qa
```


## Long Term Support

This package was brought to you by Long Term Support LTD, a company run and founded by Joseph Edmonds

You can get in touch with Joseph at https://joseph.edmonds.contact/

Check out Joseph's recent book [The Art of Modern PHP 8](https://joseph.edmonds.contact/#book)



