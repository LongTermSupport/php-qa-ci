<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

/**
 * Pure decision: a phpunit.xml's version pins must agree with the PHPUnit that
 * is actually installed. Two pins are checked, both by MAJOR version:
 *
 *  - the schema URL in xsi:noNamespaceSchemaLocation
 *    (https://schema.phpunit.de/<version>/phpunit.xsd), which IDEs and
 *    validators use to decide which attributes are legal; and
 *  - a SYMFONY_PHPUNIT_VERSION <server>/<env> pin, which symfony/phpunit-bridge
 *    uses to choose the PHPUnit it installs and runs.
 *
 * A stale pin never fails a test run, so nothing else notices when the
 * package's PHPUnit requirement moves on; the config quietly keeps describing
 * a release that is no longer the one running the tests.
 *
 * @internal
 */
final readonly class PhpUnitConfigDetector
{
    private const string SCHEMA_PATTERN = '#xsi:noNamespaceSchemaLocation\s*=\s*"[^"]*?schema\.phpunit\.de/(\d+)(?:\.\d+)*/phpunit\.xsd"#';

    private const string SYMFONY_PIN_PATTERN = '#<(?:server|env)\s[^>]*name\s*=\s*"SYMFONY_PHPUNIT_VERSION"[^>]*value\s*=\s*"(\d+)(?:\.\d+)*"#';

    /**
     * @param string $phpunitXml       the phpunit.xml contents
     * @param string $installedVersion the installed PHPUnit version, e.g. "13.3.2"
     *
     * @return list<string> one message per pin that disagrees with the installed
     *                      major; empty when every pin present agrees (a config
     *                      with no pins at all has nothing to disagree)
     */
    public function check(string $phpunitXml, string $installedVersion): array
    {
        $installedMajor = $this->major($installedVersion);
        $problems       = [];

        if (1 === \Safe\preg_match(self::SCHEMA_PATTERN, $phpunitXml, $matches) && isset($matches[1])) {
            $schemaMajor = (int)$matches[1];
            if ($schemaMajor !== $installedMajor) {
                $problems[] = \sprintf(
                    'xsi:noNamespaceSchemaLocation points at the PHPUnit %d schema but PHPUnit %s is installed; '
                    . 'set it to https://schema.phpunit.de/%s/phpunit.xsd',
                    $schemaMajor,
                    $installedVersion,
                    $this->majorMinor($installedVersion),
                );
            }
        }

        if (1 === \Safe\preg_match(self::SYMFONY_PIN_PATTERN, $phpunitXml, $matches) && isset($matches[1])) {
            $pinMajor = (int)$matches[1];
            if ($pinMajor !== $installedMajor) {
                $problems[] = \sprintf(
                    'SYMFONY_PHPUNIT_VERSION pins PHPUnit %d but PHPUnit %s is installed; '
                    . 'set the pin to %s or remove it',
                    $pinMajor,
                    $installedVersion,
                    $this->majorMinor($installedVersion),
                );
            }
        }

        return $problems;
    }

    private function major(string $version): int
    {
        return (int)explode('.', $version)[0];
    }

    private function majorMinor(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }
}
