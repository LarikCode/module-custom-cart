<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Single source of truth for the "is the custom cart the site's primary
 * cart page" toggle -- both Plugin\Checkout\Cart\IndexRedirectPlugin (the
 * /checkout/cart -> /customcart/cart redirect) and
 * Plugin\Checkout\Cart\SidebarConfigPlugin (the minicart link) read
 * through here, so there is exactly one place that knows the config path
 * and the two behaviors can never independently drift out of sync.
 */
class Config
{
    private const XML_PATH_CUSTOM_CART_PRIMARY = 'customcart/general/primary_cart';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isCustomCartPrimary(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CUSTOM_CART_PRIMARY,
            ScopeInterface::SCOPE_WEBSITE
        );
    }
}
