<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Block\Sales\Order;

use Ivanchenko\CustomCart\Block\Sales\Order\Totals;
use Ivanchenko\CustomCart\Model\LoyaltyPointsCalculator;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * initTotals() is the only method under test -- it's the hook
 * Magento\Sales\Block\Order\Totals::_beforeToHtml() calls on every child
 * block that declares it (see Magento_Weee's identical pattern in
 * Block\Sales\Order\Totals). The block is built via
 * disableOriginalConstructor() + reflection to inject the calculator,
 * since Template's real constructor requires a fully wired
 * Template\Context this test has no reason to assemble -- initTotals()
 * never touches any Template-specific plumbing, only getParentBlock().
 */
class TotalsTest extends TestCase
{
    private Totals $block;

    protected function setUp(): void
    {
        $this->block = $this->getMockBuilder(Totals::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentBlock'])
            ->getMock();

        (new \ReflectionProperty(Totals::class, 'calculator'))->setValue($this->block, new LoyaltyPointsCalculator());
    }

    public function testAddsFormattedPointsRowAfterLastTotalWhenAboveThreshold(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getSubtotal')->willReturn(45.0);

        $parentBlock = $this->createMock(\Magento\Sales\Block\Order\Totals::class);
        $parentBlock->method('getSource')->willReturn($order);
        $parentBlock->expects($this->once())
            ->method('addTotal')
            ->with(
                $this->callback(function (DataObject $total) {
                    return $total->getCode() === 'loyalty_points'
                        && (string)$total->getValue() === '4 pts'
                        && $total->getIsFormated() === true;
                }),
                'last'
            );

        $this->block->method('getParentBlock')->willReturn($parentBlock);

        $this->block->initTotals();
    }

    public function testAddsNothingWhenSubtotalIsBelowThreshold(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getSubtotal')->willReturn(9.99);

        $parentBlock = $this->createMock(\Magento\Sales\Block\Order\Totals::class);
        $parentBlock->method('getSource')->willReturn($order);
        $parentBlock->expects($this->never())->method('addTotal');

        $this->block->method('getParentBlock')->willReturn($parentBlock);

        $this->block->initTotals();
    }
}
