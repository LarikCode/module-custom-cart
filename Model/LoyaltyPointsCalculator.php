<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model;

/**
 * Shared by the quote-side totals collector (cart/checkout REST context)
 * and the order-view/email totals row (Block\Sales\Order\Totals), so the
 * $10-per-point rule has one definition instead of two independently
 * drifting copies.
 */
class LoyaltyPointsCalculator
{
    private const DOLLARS_PER_POINT = 10.0;

    public function calculate(float $subtotal): int
    {
        return (int)floor($subtotal / self::DOLLARS_PER_POINT);
    }
}
