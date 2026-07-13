<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model;

use Ivanchenko\CustomCart\Model\Exception\InvalidCartRequestException;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;

/**
 * Business logic for the custom cart page (/customcart/cart). Reuses
 * Magento's own Quote/CheckoutSession/CartRepositoryInterface as the data
 * layer -- no separate storage for cart items, quantities, or totals.
 * Controllers stay thin and only call into this class.
 */
class CartService
{
    /**
     * Mirrors Plugin\Quote\Item\UpdaterPlugin::MAX_QTY for a consistent
     * user-facing limit across both cart UIs. Intentionally NOT
     * shared/imported: that plugin only guards
     * Magento\Quote\Model\Quote\Item\Updater::update(), which this page's
     * $item->setQty() call does not go through (see etc/di.xml's docblock
     * on that plugin registration) -- the storefront cart page and cart
     * REST endpoints set qty directly on the item, bypassing it entirely.
     * This page therefore needs its own independent enforcement of the
     * same policy rather than relying on that plugin firing.
     */
    private const MAX_ITEM_QTY = 100;
    private const MIN_ITEM_QTY = 1;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CommentService $commentService,
        private readonly ImageFactory $imageFactory,
        private readonly RegionDataProvider $regionDataProvider
    ) {
    }

    /**
     * @return array{items: array, totals: array}
     */
    public function getCartData(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $quote->collectTotals();

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = $this->buildItemData($item);
        }

        return [
            'items' => $items,
            'totals' => $this->buildTotals($quote),
        ];
    }

    /**
     * @return array{items: array, totals: array}
     * @throws InvalidCartRequestException
     */
    public function updateItemQty(mixed $itemId, mixed $qty): array
    {
        $quote = $this->checkoutSession->getQuote();
        $item = $this->getValidatedItem($quote, $itemId);
        $validatedQty = $this->getValidatedQty($qty);

        $item->setQty($validatedQty);
        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        return $this->getCartData();
    }

    /**
     * @return array{items: array, totals: array}
     * @throws InvalidCartRequestException
     */
    public function removeItem(mixed $itemId): array
    {
        $quote = $this->checkoutSession->getQuote();
        $item = $this->getValidatedItem($quote, $itemId);

        $quote->removeItem((int)$item->getId());
        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        return $this->getCartData();
    }

    /**
     * Sets country/region/postcode on the quote's shipping address and
     * recollects totals -- the same minimal sequence
     * Magento\Checkout\Model\Cart::save() itself uses (read directly:
     * getShippingAddress()->setCollectShippingRates(true) ->
     * $quote->collectTotals() -> $quoteRepository->save($quote), no
     * setTotalsCollectedFlag(false) call anywhere), replicated here
     * directly rather than routing through Cart\Model\Cart, which
     * CartService deliberately avoids everywhere else.
     *
     * @return array{items: array, totals: array}
     * @throws InvalidCartRequestException
     */
    public function estimateTax(mixed $countryId, mixed $regionId, mixed $postcode): array
    {
        $quote = $this->checkoutSession->getQuote();
        $validatedCountryId = $this->getValidatedCountryId($countryId);
        $validatedRegionId = $this->getValidatedRegionId($validatedCountryId, $regionId);

        $address = $quote->getShippingAddress();
        $address->setCountryId($validatedCountryId)
            ->setRegionId($validatedRegionId)
            ->setPostcode(is_string($postcode) && trim($postcode) !== '' ? trim($postcode) : null)
            ->setCollectShippingRates(true);

        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        return $this->getCartData();
    }

    private function buildItemData(Item $item): array
    {
        $itemId = (int)$item->getId();

        return [
            'item_id' => $itemId,
            'name' => $item->getName(),
            'sku' => $item->getSku(),
            'qty' => (float)$item->getQty(),
            'price' => (float)$item->getPrice(),
            'row_total' => (float)$item->getRowTotal(),
            'comment' => $this->commentService->getCommentForItem($itemId),
            'image_url' => $this->resolveImageUrl($item),
        ];
    }

    /**
     * Same image ID string ('cart_page_product_thumbnail') and the same
     * ImageFactory-based resolution Magento's own
     * Checkout\Block\Cart\Item\Renderer::getImage() uses for cart rows
     * (that class still injects the older, now-deprecated ImageBuilder --
     * ImageFactory is the current, non-deprecated equivalent API, equally
     * plain-constructor-DI-friendly, so used here instead). Mirrors the
     * existing getProduct() ? ... : ... null-guard convention already
     * used in Plugin\Quote\Item\UpdaterPlugin -- getProduct() can
     * theoretically return null for an orphaned quote item.
     */
    private function resolveImageUrl(Item $item): ?string
    {
        $product = $item->getProduct();
        if (!$product) {
            return null;
        }

        return $this->imageFactory->create($product, 'cart_page_product_thumbnail')->getImageUrl();
    }

    /**
     * Subtotal/tax amount/discount amount/grand total are read directly
     * from $quote->getTotals(), per the task's explicit instruction -- not
     * recomputed by hand. Quote::getTotals() returns a Total DataObject
     * per total code ('subtotal', 'tax', 'discount', 'grand_total', plus
     * this module's own 'loyalty_points' -- deliberately skipped/ignored
     * here rather than special-cased, so this page doesn't resurrect
     * showing loyalty points on a cart page, which was deliberately not
     * pursued elsewhere in this module).
     *
     * discount_amount was NOT originally read here -- a real bug, not a
     * hypothetical one. This store's sample data ships an active,
     * no-coupon-required Cart Price Rule ("20% OFF Every $200-plus
     * purchase!", condition base_subtotal >= 200) that legitimately
     * discounts the cart once subtotal crosses $200. Grand Total was
     * always correct end to end (Magento\SalesRule\Model\Quote\Discount
     * registers into the same additive totalAmounts pool
     * Magento\Quote\Model\Quote\Address\Total\Grand::collect() sums, sort
     * order 300, well before Grand's own 500) -- confirmed by reading
     * both classes directly and by reproducing the exact rule match
     * (subtotal $225 x 20% = $45, matching the observed discrepancy to
     * the cent). This page's own totals display just never read or
     * rendered that segment, so a mathematically-correct grand_total
     * looked inconsistent against a Subtotal/GST/QST breakdown that
     * silently omitted the one line that explained the difference.
     * Exposed here now instead of forcing grand_total to equal
     * subtotal+tax by hand, which would have been actively wrong (and
     * would silently break again the next time a discount, shipping
     * charge, or other segment legitimately applies).
     *
     * $quote->getTotals()['discount'] turned out to be empty even with an
     * active discount -- the exact same class of gap already documented
     * on buildTaxBreakdown() below, verified rather than assumed to be
     * the same cause: TotalsCollector::collect() (the pipeline that
     * populates real values) only copies shippingAmount/subtotal/
     * subtotalWithDiscount/grandTotal from each address's computed Total
     * up onto the quote-level data used to seed getTotals()'s reader
     * pipeline -- discount_amount is never among those copied fields, so
     * Magento\SalesRule\Model\Quote\Discount::fetch() reads it as null
     * from an object that never had it set, and (per that class's own
     * `if ($amount != 0)` guard) returns null, so the 'discount' key
     * never appears in getTotals() at all despite the amount being real
     * and already reflected in grand_total. Read from the shipping
     * address directly instead (confirmed populated correctly via
     * $address->addData($total->getData()) in
     * TotalsCollector::collectAddressTotals()), same source
     * buildTaxBreakdown() already relies on for the same reason.
     *
     * The per-rate GST/QST breakdown is a separate story -- see
     * buildTaxBreakdown()'s docblock; verifying it turned up a real gap
     * between "where the code reads like it should be" and where it
     * actually is.
     */
    private function buildTotals(Quote $quote): array
    {
        $totals = $quote->getTotals();

        $subtotal = isset($totals['subtotal']) ? (float)$totals['subtotal']->getValue() : (float)$quote->getSubtotal();
        $taxAmount = isset($totals['tax']) ? (float)$totals['tax']->getValue() : 0.0;
        // Magento\Quote\Model\Quote\Address::getDiscountAmount() is a
        // negative amount (e.g. -45.0 for a $45 discount) when a Cart
        // Price Rule applies, 0.0/null otherwise -- 0.0 here means "no
        // discount", not "a $0 discount row to display".
        $discountAmount = (float)$quote->getShippingAddress()->getDiscountAmount();
        $grandTotal = isset($totals['grand_total'])
            ? (float)$totals['grand_total']->getValue()
            : (float)$quote->getGrandTotal();

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'tax_rates' => $this->buildTaxBreakdown($quote, $subtotal + $discountAmount),
            'discount_amount' => $discountAmount,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Verified, don't assumed: the obvious-looking read --
     * $quote->getTotals()['tax']->getData('full_info') -- comes back
     * empty. Traced why: Quote::getTotals() reads from the QUOTE's own
     * aggregate data (TotalsReader::fetch($quote, $quote->getData())), and
     * Magento\Quote\Model\Quote\TotalsCollector::collect() only copies
     * shippingAmount/subtotal/subtotalWithDiscount/grandTotal from each
     * address's computed total up onto that quote-level aggregate --
     * applied-tax detail is never among the fields it copies up. The tax
     * *amount* still ends up correct at the quote level (it's summed in
     * separately), which is what made the gap easy to miss: the total was
     * right, only the breakdown was silently empty.
     *
     * The real per-rate detail lives one level down, on the shipping
     * address itself: $quote->getShippingAddress()->getAppliedTaxes()
     * (backed by the quote_address.applied_taxes column via
     * Quote\Address::getAppliedTaxes()) -- confirmed empirically against a
     * live Quebec-address quote (see README) before relying on it here.
     *
     * That data still only carries a *combined* dollar amount per rate
     * group (all rates sharing a priority -- exactly GST+QST here, see the
     * InstallCanadianTaxRates non-compounding design), not a per-rate
     * dollar figure. Each rate's own dollar contribution is derived the
     * same way Magento's own storefront Tax Knockout component computes it
     * client-side: percent x pre-tax subtotal. This is a computed display
     * value, not a value read verbatim from the backend.
     *
     * The caller must pass the POST-discount subtotal, not the raw
     * subtotal -- this store's tax configuration calculates tax on the
     * discounted amount (confirmed empirically: with a $45 discount on a
     * $225 subtotal, the real applied tax matches 180 x 14.975%, not
     * 225 x 14.975%). Passing the raw subtotal here would silently
     * reintroduce the same "breakdown doesn't sum to grand total" symptom
     * this method exists to avoid, just relocated from "discount missing
     * entirely" to "discount present but the breakdown math ignores it" --
     * caught during the same investigation, not a separate one.
     *
     * @param float $subtotalExclTaxAfterDiscount subtotal minus any Cart
     *     Price Rule discount, before tax
     * @return array<int, array{title: string, percent: float, amount: float}>
     */
    private function buildTaxBreakdown(Quote $quote, float $subtotalExclTaxAfterDiscount): array
    {
        // Observed in practice, not just theoretical: Address::getAppliedTaxes()
        // can return null (not just []) -- its own `$taxes ? unserialize($taxes) : []`
        // guard only catches an empty/falsy stored value, not a stored
        // value that successfully unserializes TO null (e.g. a leftover
        // "null" JSON string on an address with no items left to tax).
        // Caught this on an emptied-out cart during manual verification.
        $appliedTaxes = $quote->getShippingAddress()->getAppliedTaxes() ?: [];
        $rates = [];

        foreach ($appliedTaxes as $group) {
            foreach ($group['rates'] ?? [] as $rate) {
                $percent = (float)($rate['percent'] ?? 0);
                $rates[] = [
                    'title' => (string)($rate['title'] ?? ''),
                    'percent' => $percent,
                    'amount' => round($subtotalExclTaxAfterDiscount * $percent / 100, 2),
                ];
            }
        }

        return $rates;
    }

    /**
     * @throws InvalidCartRequestException
     */
    private function getValidatedItem(Quote $quote, mixed $itemId): Item
    {
        $validatedId = filter_var($itemId, FILTER_VALIDATE_INT);
        if ($validatedId === false) {
            throw new InvalidCartRequestException(__('Invalid item.'));
        }

        $item = $quote->getItemById($validatedId);

        // getItemById() returns false (not null) when the item isn't found
        // or doesn't belong to this quote -- same error either way.
        if ($item === false) {
            throw new InvalidCartRequestException(__('The requested cart item was not found.'));
        }

        return $item;
    }

    /**
     * @throws InvalidCartRequestException
     */
    private function getValidatedQty(mixed $qty): int
    {
        // FILTER_VALIDATE_INT, not a loose (int) cast -- "5.5" or "5abc"
        // must be rejected cleanly rather than silently truncated.
        $validatedQty = filter_var($qty, FILTER_VALIDATE_INT);

        if ($validatedQty === false || $validatedQty < self::MIN_ITEM_QTY) {
            throw new InvalidCartRequestException(
                __('Quantity must be a whole number of at least %1.', self::MIN_ITEM_QTY)
            );
        }

        if ($validatedQty > self::MAX_ITEM_QTY) {
            throw new InvalidCartRequestException(
                __('The requested quantity (%1) is more than the maximum allowed per item (%2).', $validatedQty, self::MAX_ITEM_QTY)
            );
        }

        return $validatedQty;
    }

    /**
     * Validated against the real country list (RegionDataProvider), not
     * just a format check -- rejects nonsense codes cleanly rather than
     * silently persisting them onto the address.
     *
     * @throws InvalidCartRequestException
     */
    private function getValidatedCountryId(mixed $countryId): string
    {
        if (!is_string($countryId) || trim($countryId) === '') {
            throw new InvalidCartRequestException(__('Please select a country.'));
        }

        $countryId = trim($countryId);
        $knownCountryIds = array_column($this->regionDataProvider->getCountries(), 'value');

        if (!in_array($countryId, $knownCountryIds, true)) {
            throw new InvalidCartRequestException(__('The selected country is not valid.'));
        }

        return $countryId;
    }

    /**
     * null/empty is valid -- most countries have no regions. When a
     * region IS provided, it must be a positive int AND must actually
     * belong to the given (already-validated) country -- rejects e.g.
     * Quebec's region_id submitted against a non-Canada country, not
     * just "is this a positive integer."
     *
     * @throws InvalidCartRequestException
     */
    private function getValidatedRegionId(string $countryId, mixed $regionId): ?int
    {
        if ($regionId === null || $regionId === '' || $regionId === false) {
            return null;
        }

        $validatedRegionId = filter_var($regionId, FILTER_VALIDATE_INT);
        if ($validatedRegionId === false || $validatedRegionId <= 0) {
            throw new InvalidCartRequestException(__('The selected region is not valid.'));
        }

        $regionsForCountry = $this->regionDataProvider->getRegionsByCountry()[$countryId] ?? [];
        if (!isset($regionsForCountry[$validatedRegionId])) {
            throw new InvalidCartRequestException(__('The selected region does not belong to the selected country.'));
        }

        return $validatedRegionId;
    }
}
