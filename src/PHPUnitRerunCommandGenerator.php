<?php declare(strict_types=1);

namespace EdmondsCommerce\PHPQA;

class PHPUnitRerunCommandGenerator
{
    /**
     * @var string
     */
    private $logPath;

    /**
     * @var \SimpleXMLElement
     */
    private $simpleXml;

    private $toRerun = [];

    public function __construct()
    {
    }

    public function main(string $junitLogPath = null)
    {
        $this->toRerun = [];
        $this->logPath = $junitLogPath ?? $this->getDefaultFilePath();
        $this->load();
        $failureNodes = $this->simpleXml->xpath(
            '//testsuite/testcase[error] | //testsuite/testcase[failure] '
            .'| //testsuite/testcase[skipped] | //testsuite/testcase[incomplete]'
        );
        foreach ($failureNodes as $testCaseNode) {
            $attributes              = $testCaseNode->attributes();
            $class                   = str_replace('\\', '\\\\', (string)$attributes->class);
            $this->toRerun[$class][] = (string)$attributes->name;
        }
        if ($this->toRerun === []) {
            #no failed tests so just include all
            return '/.*/';
        }
        $command = '/(';
        foreach ($this->toRerun as $class => $testNames) {
            foreach ($testNames as $testName) {
                $command .= "$class::$testName|";
            }
        }
        $command = rtrim($command, '|');
        $command .= ')/';

        return $command;
    }


    protected function load()
    {
        $this->simpleXml = simplexml_load_string(file_get_contents($this->logPath));
    }

    /**
     * @return string
     * @throws \Exception
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    protected function getDefaultFilePath(): string
    {
        return Config::getProjectRootDirectory().'/var/qa/phpunit.junit.log.xml';
    }
}