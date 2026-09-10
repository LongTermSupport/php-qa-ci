<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireSensitiveParameterAttributeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<RequireSensitiveParameterAttributeRule>
 */
#[CoversClass(RequireSensitiveParameterAttributeRule::class)]
#[Medium]
final class RequireSensitiveParameterAttributeRuleTest extends RuleTestCase
{
    private const string SENSITIVE_PARAMETER_CREDENTIAL_PARAMS_PHP = '/../../../assets/PHPStan/SensitiveParameter/CredentialParams.php';

    private const string LOOKS_LIKE_A_PLAINTEXT_CREDENTIAL_BUT_IS_MIS = 'looks like a plaintext credential but is missing the #[\SensitiveParameter] attribute. ';

    private const string ADD_SENSITIVE_PARAMETER_SO_ITS_VALUE_IS_REDA = 'Add #[\SensitiveParameter] so its value is redacted from stack traces.';

    /**
     * Custom credential name patterns injected per test so the single getRule()
     * seam can serve both the default-config and custom-config cases.
     *
     * @var list<string>|null
     */
    private ?array $customNamePatterns = null;

    #[Test]
    public function itFlagsPlaintextCredentialParamsMissingTheAttributeAndIgnoresTheRest(): void
    {
        $this->analyse(
            [__DIR__ . self::SENSITIVE_PARAMETER_CREDENTIAL_PARAMS_PHP],
            [
                [
                    'Parameter $password of method '
                    . (\LTS\PHPQA\Tests\Assets\PHPStan\SensitiveParameter\CredentialParams::class . '::loginMissing() ')
                    . self::LOOKS_LIKE_A_PLAINTEXT_CREDENTIAL_BUT_IS_MIS
                    . self::ADD_SENSITIVE_PARAMETER_SO_ITS_VALUE_IS_REDA,
                    17,
                ],
            ],
        );
    }

    #[Test]
    public function itReportsNoErrorsForAFileWhereEveryCredentialParamIsAnnotatedOrIgnored(): void
    {
        $this->analyse(
            [__DIR__ . '/../../../assets/PHPStan/SensitiveParameter/HasUsage.php'],
            [],
        );
    }

    #[Test]
    public function itDoesNotFlagUrlUriOrPathSuffixedCredentialParams(): void
    {
        // $forgotPasswordUrl, $secretUri, $secretFilePath all contain a credential
        // substring but are suppressed by the url/uri/path ignore-substrings.
        // The existing CredentialParams fixture now includes these params; the only
        // error that must come back is the pre-existing $password one.
        $this->analyse(
            [__DIR__ . self::SENSITIVE_PARAMETER_CREDENTIAL_PARAMS_PHP],
            [
                [
                    'Parameter $password of method '
                    . (\LTS\PHPQA\Tests\Assets\PHPStan\SensitiveParameter\CredentialParams::class . '::loginMissing() ')
                    . self::LOOKS_LIKE_A_PLAINTEXT_CREDENTIAL_BUT_IS_MIS
                    . self::ADD_SENSITIVE_PARAMETER_SO_ITS_VALUE_IS_REDA,
                    17,
                ],
            ],
        );
    }

    #[Test]
    public function itHonoursCustomNamePatternsFromTheConstructor(): void
    {
        // Re-target the rule at a single custom pattern: "email" now counts as a
        // credential name, while the built-in "password"/"secret" patterns no longer
        // apply. Only $email is flagged; $password and $secret are ignored.
        $this->customNamePatterns = ['email'];

        $this->analyse(
            [__DIR__ . self::SENSITIVE_PARAMETER_CREDENTIAL_PARAMS_PHP],
            [
                [
                    'Parameter $email of method '
                    . (\LTS\PHPQA\Tests\Assets\PHPStan\SensitiveParameter\CredentialParams::class . '::notify() ')
                    . self::LOOKS_LIKE_A_PLAINTEXT_CREDENTIAL_BUT_IS_MIS
                    . self::ADD_SENSITIVE_PARAMETER_SO_ITS_VALUE_IS_REDA,
                    41,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        if (null !== $this->customNamePatterns) {
            return new RequireSensitiveParameterAttributeRule($this->customNamePatterns);
        }

        return new RequireSensitiveParameterAttributeRule();
    }
}
