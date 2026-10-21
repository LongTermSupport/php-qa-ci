<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference;

use LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference\Elsewhere\Misplaced;
use UnclaimedVendorApi\StubbedApi;

final class Consumer
{
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

        return StrandedHelper::class . \count($more);
    }

    public function quiet(): string
    {
        new AtItsPath();
        new StubbedApi();

        return self::class . static::class;
    }

    public function drifted(): Misplaced
    {
        return new Misplaced();
    }
}
