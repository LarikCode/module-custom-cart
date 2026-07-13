<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\ViewModel\Cart;

use Ivanchenko\CustomCart\Model\CartService;
use Ivanchenko\CustomCart\Model\RegionDataProvider;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Exposes CartService's data to the cart/index.phtml template. A
 * ViewModel rather than a Block subclass -- no framework constructor
 * chain (Context/AbstractBlock/Template) to build, which keeps this
 * trivially unit-testable with plain createStub()/createMock(), unlike
 * this module's existing Block\Sales\Order\Totals (whose test needs
 * getMockBuilder()->disableOriginalConstructor() to work around that
 * chain). This is Magento's current convention for "expose data to a
 * phtml, no HTML-generation logic of its own."
 */
class Index implements ArgumentInterface
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly RegionDataProvider $regionDataProvider,
        private readonly Json $jsonSerializer
    ) {
    }

    public function getCartData(): array
    {
        return $this->cartService->getCartData();
    }

    public function getCountries(): array
    {
        return $this->regionDataProvider->getCountries();
    }

    /**
     * Baked into the page via a data-regions attribute (see
     * cart/index.phtml) rather than a <script type="application/json">
     * block -- sidesteps the </script>-early-termination class of
     * escaping bug entirely, since $block->escapeHtmlAttr() is already
     * this template's established convention for anything going into an
     * HTML attribute.
     */
    public function getRegionsByCountryJson(): string
    {
        return $this->jsonSerializer->serialize($this->regionDataProvider->getRegionsByCountry());
    }
}
