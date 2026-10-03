<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use DOMDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a phpunit.xml php-qa-ci ships or runs with uses configuration the
 * installed PHPUnit has dropped from its schema".
 *
 * PHPUnit validates its configuration against the schema of the running version. Where that
 * fails and an older schema accepts it, PHPUnit runs anyway and prints a test runner
 * deprecation ("validates against a deprecated schema"), which fails nothing. So a value the
 * new minor removed — `executionOrder="depends,random"` in PHPUnit 13.4 — ships to every
 * consumer in the default config and keeps working until the next major removes it, at which
 * point every project using the default stops running its tests at once.
 *
 * The version pins lane compares only the schema URL's major. This compares the content
 * with the schema PHPUnit itself uses.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class PhpUnitConfigSchemaTest extends TestCase
{
    /** The repository whose configurations are checked. */
    private const string REPO_ROOT = __DIR__ . '/../..';

    /** The schema of the installed PHPUnit: the one it validates its configuration against. */
    private const string SCHEMA = self::REPO_ROOT . '/vendor/phpunit/phpunit/phpunit.xsd';

    /** The shipped default, this repository's own configuration, and the root copy of it. */
    private const array CONFIGS = [
        'configDefaults/generic/phpunit.xml',
        'qaConfig/phpunit.xml',
        'phpunit.xml',
    ];

    /** A configuration the installed schema accepts, and the same one with the removed value. */
    private const string FIXTURE = '<?xml version="1.0"?><phpunit executionOrder="%s"/>';

    public function testEveryShippedAndUsedConfigurationValidatesAgainstTheInstalledSchema(): void
    {
        $invalid = [];
        foreach (self::CONFIGS as $config) {
            $errors = $this->schemaErrors(\Safe\file_get_contents(self::REPO_ROOT . '/' . $config));
            if ([] !== $errors) {
                $invalid[] = $config . ': ' . implode('; ', $errors);
            }
        }

        self::assertSame(
            [],
            $invalid,
            "Configurations the installed PHPUnit's schema rejects. PHPUnit runs them with only a "
            . 'deprecation notice, until the next major stops running them at all:',
        );
    }

    /** The guard proven to fire on the value PHPUnit 13.4 removed, and to accept its replacement. */
    public function testTheGuardRejectsAValueTheSchemaDropped(): void
    {
        self::assertNotSame([], $this->schemaErrors(\sprintf(self::FIXTURE, 'depends,random')));
        self::assertSame([], $this->schemaErrors(\sprintf(self::FIXTURE, 'random')));
    }

    /** @return list<string> */
    private function schemaErrors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->loadXML($xml);
            $document->schemaValidate(self::SCHEMA);
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }

            return $errors;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
