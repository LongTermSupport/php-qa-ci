# PHP-QA-CI

## Install

First, ensure your bin dir config is set in your composer.json like this

```
  "config": {
    "bin-dir": "bin",
```

Then install the current bleeding edge, run

```
composer require --dev lts/php-qa-ci:dev-master@dev
```

For Symfony - you can accept the prompts to run recipes, but you will then need to decide to either stick with Symfony defaults or the php-qa-ci default which are more extensive

If you decide to stick with the lts defaults, then you should remove the config files that the symfony recipe created. If you want to keep config files in your root directory, you can choose to symlink them to the php-qa-ci files, eg

Note that you should properly compare the files before doing this.

```
# revert to php-qa-ci PHPUnit configs
rm phpunit.xml.dist
ln -s vendor/lts/php-qa-ci/configDefaults/generic/phpunit.xml 
```



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



