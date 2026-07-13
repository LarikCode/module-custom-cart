<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model\Total\Quote;

use Ivanchenko\CustomCart\Model\LoyaltyPointsCalculator;
use Ivanchenko\CustomCart\Model\Total\Quote\LoyaltyPoints;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the loyalty points estimate: $10 spent = 1 point, floor-rounded,
 * hidden entirely below the 1-point threshold.
 *
 * Unlike Gst/Qst, this collector never overrides collect() (the
 * inherited AbstractTotal default is already the correct no-op for an
 * informational-only total), and fetch() only ever reads
 * $total->getSubtotal() -- so these tests build a Total directly and
 * call fetch(), without needing collect() or a ShippingAssignment stub
 * at all.
 */
class LoyaltyPointsTest extends TestCase
{
    private LoyaltyPoints $loyaltyPoints;

    protected function setUp(): void
    {
        $this->loyaltyPoints = new LoyaltyPoints(new LoyaltyPointsCalculator());
    }

    public function testRegistersItselfUnderTheLoyaltyPointsCode(): void
    {
        $this->assertSame('loyalty_points', $this->loyaltyPoints->getCode());
    }

    #[DataProvider('subtotalProvider')]
    public function testCalculatesPointsFromSubtotal(float $subtotal, int $expectedPoints): void
    {
        $result = $this->loyaltyPoints->fetch($this->createStub(Quote::class), $this->totalWithSubtotal($subtotal));

        if ($expectedPoints < 1) {
            $this->assertSame([], $result);
            return;
        }

        $this->assertSame('loyalty_points', $result['code']);
        $this->assertSame($expectedPoints, $result['value']);
    }

    public static function subtotalProvider(): array
    {
        return [
            'just below threshold' => [9.99, 0],
            'exactly at threshold' => [10.00, 1],
            'just above threshold' => [10.01, 1],
            'just below next point' => [19.99, 1],
            'exactly two points' => [20.00, 2],
            'non-round two points' => [25.00, 2],
            'ten points' => [100.00, 10],
            'zero subtotal' => [0.00, 0],
        ];
    }

    public function testFetchReturnsEmptyArrayBelowThreshold(): void
    {
        $result = $this->loyaltyPoints->fetch($this->createStub(Quote::class), $this->totalWithSubtotal(9.99));

        $this->assertSame([], $result);
    }

    public function testFetchReturnsPointsAtThreshold(): void
    {
        $result = $this->loyaltyPoints->fetch($this->createStub(Quote::class), $this->totalWithSubtotal(10.00));

        $this->assertSame('loyalty_points', $result['code']);
        $this->assertSame(1, $result['value']);
    }

    /**
     * Weaker version of Gst/Qst's equivalent regression test: this
     * collector's fetch() also only ever reads getSubtotal() (a real
     * persisted quote_address column), so it has the same
     * survives-reconstruction property Address::getTotals() relies on --
     * but unlike Gst/Qst, this collector was never at risk of the
     * transient-field bug in the first place, since it has no
     * collect()-computed state at all. Kept for suite consistency.
     */
    public function testFetchStillReturnsPointsAfterTotalIsRebuiltFromPlainArrayData(): void
    {
        $original = $this->totalWithSubtotal(25.00);

        $reconstructed = $this->newTotal();
        $reconstructed->setData($original->getData());

        $result = $this->loyaltyPoints->fetch($this->createStub(Quote::class), $reconstructed);

        $this->assertSame(2, $result['value']);
    }

    private function totalWithSubtotal(float $subtotal): Total
    {
        $total = $this->newTotal();
        $total->setSubtotal($subtotal);

        return $total;
    }

    /**
     * Total's constructor falls back to Magento's static ObjectManager
     * when no serializer is given, which isn't bootstrapped in a plain
     * unit test. Passing one explicitly avoids that entirely.
     */
    private function newTotal(): Total
    {
        return new Total([], new Json());
    }
}
