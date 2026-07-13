<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Plugin\Quote\Item;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Updater;

/**
 * Guards against unreasonable cart quantities before Magento's core
 * Quote\Item\Updater applies them.
 *
 * This is a "before" plugin: it inspects the incoming update info and
 * throws a LocalizedException with a clear, actionable message when the
 * requested quantity is out of bounds, but never rewrites the arguments
 * passed to the original method. A valid quantity update therefore
 * behaves exactly as it would without this module installed; core update
 * behavior is left untouched, only invalid input is rejected earlier and
 * with a clearer message than the default stock/qty validation provides.
 */
class UpdaterPlugin
{
    /**
     * Maximum quantity of a single item allowed in the cart.
     *
     * This is a sane guard against fat-fingered quantities (an extra
     * zero, a pasted SKU landing in the qty field, etc.) and basic
     * scripted abuse; it is intentionally not tied to real inventory
     * limits, which Magento's own stock validation already enforces
     * separately.
     */
    private const MAX_QTY = 100;

    /**
     * @param Updater $subject
     * @param Item $item
     * @param array $info Matches Updater::update()'s real signature --
     *     NOT a DataObject buy request, despite that being the more
     *     common convention elsewhere in Quote (e.g. Cart::addProduct()).
     * @return void
     * @throws LocalizedException
     */
    public function beforeUpdate(Updater $subject, Item $item, array $info): void
    {
        if (!isset($info['qty'])) {
            return;
        }

        $qty = $info['qty'];

        if ((float)$qty > self::MAX_QTY) {
            $productName = $item->getProduct() ? $item->getProduct()->getName() : $item->getName();

            throw new LocalizedException(
                __(
                    'The requested quantity (%1) for "%2" is more than the maximum allowed per order (%3). '
                    . 'Please contact us for bulk orders.',
                    $qty,
                    $productName,
                    self::MAX_QTY
                )
            );
        }
    }
}
