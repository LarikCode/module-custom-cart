<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model;

use Ivanchenko\CustomCart\Model\LoyaltyPointsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoyaltyPointsCalculatorTest extends TestCase
{
    private LoyaltyPointsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new LoyaltyPointsCalculator();
    }

    #[DataProvider('subtotalProvider')]
    public function testCalculatesPointsFromSubtotal(float $subtotal, int $expectedPoints): void
    {
        $this->assertSame($expectedPoints, $this->calculator->calculate($subtotal));
    }

    public static function subtotalProvider(): array
    {
        return [
            'just below threshold' => [9.99, 0],
            'exactly at threshold' => [10.00, 1],
            'just above threshold' => [10.01, 1],
            'non-round two points' => [25.00, 2],
            'ten points' => [100.00, 10],
            'zero subtotal' => [0.00, 0],
        ];
    }
}
