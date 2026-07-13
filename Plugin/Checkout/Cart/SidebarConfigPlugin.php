<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Plugin\Checkout\Cart;

use Ivanchenko\CustomCart\Model\Config;
use Magento\Checkout\Block\Cart\Sidebar;
use Magento\Framework\UrlInterface;

/**
 * Points the minicart's "View and Edit Cart" link at /customcart/cart when
 * the same admin toggle IndexRedirectPlugin reads is enabled, so the two
 * behaviors can never independently drift out of sync.
 *
 * Sidebar::getConfig() returns a plain array (confirmed by reading it
 * directly) including 'shoppingCartUrl', computed fresh on every call --
 * no instance-level cache to fight. The minicart's Knockout template
 * already just binds an href to whatever value that key holds
 * (data-bind="attr: {href: shoppingCartUrl}"), so swapping the one array
 * entry here is sufficient on its own -- no template or JS override
 * needed at all, confirmed the least invasive of the options considered.
 *
 * One real caveat, not a bug: the minicart's rendered HTML is cached
 * client-side via Magento's customer-data "sections" mechanism
 * (mage-cache-storage in localStorage), so flipping the admin toggle
 * won't retroactively update an already-open browser tab's minicart until
 * that tab's cart section next invalidates -- a normal page reload is
 * enough, but an already-open tab watched live will not update itself.
 */
class SidebarConfigPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function afterGetConfig(Sidebar $subject, array $result): array
    {
        if ($this->config->isCustomCartPrimary()) {
            $result['shoppingCartUrl'] = $this->urlBuilder->getUrl('customcart/cart');
        }

        return $result;
    }
}
