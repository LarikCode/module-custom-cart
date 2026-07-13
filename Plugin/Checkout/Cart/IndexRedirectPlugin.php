<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Plugin\Checkout\Cart;

use Ivanchenko\CustomCart\Model\Config;
use Magento\Checkout\Controller\Cart\Index as CartIndex;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * Config-gated redirect from Magento's own /checkout/cart to this module's
 * /customcart/cart, so there is one consistent cart URL/experience site-wide
 * once the admin toggle (Model\Config::isCustomCartPrimary()) is enabled.
 *
 * around, not before/after: execute() needs to be replaced wholesale with a
 * redirect result when the toggle is on, never both a redirect AND the
 * original page. Scoped to Cart\Index::execute() only (the GET page-render
 * action) -- every other Checkout\Controller\Cart\* class (Add,
 * UpdateItemQty, CouponPost, EstimatePost, EstimateUpdatePost, etc.) is
 * separately routed and doesn't depend on this one having rendered, so
 * this redirect cannot break any of Luma's own AJAX cart machinery
 * (confirmed by reading Controller\Cart\Index and grepping for internal
 * references to it, not assumed).
 *
 * Deliberately a 302 (the default when setHttpResponseCode() is never
 * called -- Magento\Framework\Controller\Result\Redirect::render() falls
 * back to the inherited Response::setRedirect($url, $code = 302), read
 * directly to confirm rather than assumed), not a 301: this redirect is
 * reversible via the admin toggle, so it must never be permanently cached
 * by a browser or intermediary as if it were.
 */
class IndexRedirectPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function aroundExecute(CartIndex $subject, callable $proceed): ResultInterface
    {
        if (!$this->config->isCustomCartPrimary()) {
            return $proceed();
        }

        return $this->redirectFactory->create()->setPath('customcart/cart/index');
    }
}
