<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference;

use LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference\Elsewhere\Misplaced;
use UnclaimedVendorApi\StubbedApi;

#[StrandedAttribute]
final class Consumer extends StrandedBase implements StrandedContract
{
    use StrandedTrait;

    private ?StrandedHelper $helper = null;

    public function build(): StrandedHelper
    {
        return new StrandedHelper();
    }

    public function inEveryPosition(object $value, StrandedHelper ...$more): ?string
    {
        $made = StrandedHelper::make();
        if ($value instanceof StrandedHelper) {
            return StrandedHelper::NAME;
        }

        try {
            \strlen(\PHP_EOL);
        } catch (StrandedException $stranded) {
            return $stranded->getMessage();
        }

        $this->helper = $made;
        if (StrandedHelper::class === $value::class) {
            return null;
        }

        return StrandedHelper::class . \count($more);
    }

    public function contracted(): StrandedHelper
    {
        return new StrandedHelper();
    }

    public function accept(StrandedMarker $marked): void
    {
    }

    public function widened(AtItsPath|StrandedHelper $value): void
    {
    }

    public function same(): StrandedHelper
    {
        return $this->helper ?? new StrandedHelper();
    }

    public function quiet(): string
    {
        new AtItsPath();
        new StubbedApi();
        new Duplicated();

        return self::class . static::class;
    }

    public function miscased(): object
    {
        return new atitspath();
    }

    public function drifted(): Misplaced
    {
        return new Misplaced();
    }
}
