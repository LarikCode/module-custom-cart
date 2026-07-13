<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model\Total\Quote;

use Ivanchenko\CustomCart\Model\LoyaltyPointsCalculator;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * Informational-only "estimated loyalty points earned" total row.
 *
 * Magento Open Source has no loyalty/rewards points concept at all
 * (Reward Points is an Adobe Commerce-only feature), so this is a
 * genuine capability gap-fill rather than a duplicate of existing
 * platform functionality -- unlike a hand-rolled cart discount, which
 * would substantially overlap native Cart Price Rules.
 *
 * Deliberately never calls addTotalAmount()/addBaseTotalAmount(): this
 * total must never affect grand total math. It doesn't even need to
 * override collect() -- AbstractTotal's inherited default is already a
 * pure no-op with respect to registering amounts.
 */
class LoyaltyPoints extends AbstractTotal
{
    private LoyaltyPointsCalculator $calculator;

    public function __construct(LoyaltyPointsCalculator $calculator)
    {
        $this->calculator = $calculator;
        $this->setCode('loyalty_points');
    }

    /**
     * @inheritDoc
     */
    public function fetch(Quote $quote, Total $total): array
    {
        $points = $this->calculator->calculate((float)$total->getSubtotal());

        if ($points < 1) {
            // Returning [] hides this row entirely below the threshold --
            // Magento\Quote\Model\Quote\TotalsReader::fetch() explicitly
            // skips any collector whose fetch() returns an empty array,
            // the same convention several core collectors use for their
            // own conditionally-hidden rows.
            return [];
        }

        return [
            'code' => $this->getCode(),
            'title' => __('Estimated Loyalty Points Earned'),
            'value' => $points,
        ];
    }
}
