<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    $config->add(
        ClassSet::fromDir(__DIR__ . '/src'),
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('ArkitectFixture\\Controller'))
            ->should(new HaveNameMatching('*Controller'))
            ->because('controllers must be named consistently'),
    );
};
