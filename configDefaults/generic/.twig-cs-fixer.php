<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer;

/*
 * Shipped default for the twigCsFixer lane. Copy to qaConfig/.twig-cs-fixer.php
 * to replace it; the project copy wins outright, it is not merged.
 *
 * The upstream TwigCsFixer standard is applied as-is, for the same reason the
 * PHP CS Fixer default leans on a published ruleset: a house style nobody
 * maintains drifts from the tool, and the point of the lane is that Twig
 * templates get the same treatment PHP already gets.
 */

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());

$config = new Config();
$config->setRuleset($ruleset);

return $config;
