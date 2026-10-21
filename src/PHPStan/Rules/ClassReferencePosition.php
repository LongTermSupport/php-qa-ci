<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Where a class name stands in code, which decides whether PHP asks the autoloader
 * for the class there. {@see ClassReferenceScanner} assigns it, and
 * {@see ForbidUnautoloadableClassReferenceRule} words its message by it.
 */
enum ClassReferencePosition
{
    /** `new`, a static call or fetch, a class constant, `extends`, `implements` or a trait. */
    case Loading;

    /** `X::class`, other than as an operand of `===` or `!==`. */
    case ClassName;

    /** An attribute, loaded when it is read with `newInstance()`. */
    case Attribute;

    /** A type in a method's signature, loaded when it must be checked against an inherited one. */
    case MethodSignature;

    /** `instanceof`, `catch`, any other type, or `X::class` compared as text: nothing is loaded. */
    case Inert;
}
