# PHPStan Rules — WRONG LOCATION

Custom PHPStan rules MUST NOT live in `src/`. They belong in `qaConfig/PHPStan/Rules/`.

## Why

- PHPStan and PhpParser are dev-only dependencies
- Code in `src/` is production code and must not depend on dev packages
- Composer require checker will flag PHPStan/PhpParser symbols in `src/` as unknown

## Correct Location

- **Directory:** `qaConfig/PHPStan/Rules/`
- **Namespace:** `QaConfig\PHPStan\Rules`
- **Autoloaded via:** `composer.json` `autoload-dev` PSR-4 entry
- **Registered in:** `qaConfig/phpstan.neon` under `rules:`

## Reference

See `qaConfig/PHPStan/CLAUDE.md` for full documentation on creating custom rules.
