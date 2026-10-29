<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Tests\Support\Assert;

final class ToolResultTest
{
    public function testExcerptLengthOfFieldOutsideFreeTextFieldsIsRejected(): void
    {
        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => new ToolResult([], false, ['motivo'], [], ['resultado' => 400]),
        );
    }

    public function testExcerptLengthOfFreeTextFieldIsAccepted(): void
    {
        $result = new ToolResult([], false, ['motivo'], [], ['motivo' => 160]);

        Assert::same(['motivo' => 160], $result->excerptLengths);
    }
}
