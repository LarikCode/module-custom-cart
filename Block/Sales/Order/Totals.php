<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Block\Sales\Order;

use Ivanchenko\CustomCart\Model\LoyaltyPointsCalculator;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Adds the "Estimated Loyalty Points Earned" row to order view and order
 * confirmation email totals, mirroring Magento_Weee's own
 * Block\Sales\Order\Totals pattern: this block renders no HTML of its
 * own, it only participates via initTotals(), which the parent
 * "order_totals" block (Magento\Sales\Block\Order\Totals) calls on every
 * child block that declares the method (see its _beforeToHtml()).
 *
 * Recomputes from the order's persisted subtotal rather than reading a
 * quote total segment, because order totals aren't sourced from quote
 * collectors at render time -- same $10-per-point rule as the quote-side
 * collector (Model\Total\Quote\LoyaltyPoints), shared via
 * LoyaltyPointsCalculator so the two can't drift apart.
 */
class Totals extends Template
{
    private LoyaltyPointsCalculator $calculator;

    public function __construct(Context $context, LoyaltyPointsCalculator $calculator, array $data = [])
    {
        $this->calculator = $calculator;
        parent::__construct($context, $data);
    }

    public function initTotals(): void
    {
        $order = $this->getParentBlock()->getSource();
        $points = $this->calculator->calculate((float)$order->getSubtotal());

        if ($points < 1) {
            return;
        }

        $this->getParentBlock()->addTotal(
            new DataObject([
                'code' => 'loyalty_points',
                'label' => __('Estimated Loyalty Points Earned'),
                'value' => __('%1 pts', $points),
                'is_formated' => true,
            ]),
            'last'
        );
    }
}
