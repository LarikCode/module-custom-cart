<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Controller\Cart;

use Ivanchenko\CustomCart\Model\CartService;
use Ivanchenko\CustomCart\Model\Exception\InvalidCartRequestException;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * AJAX endpoint: POST customcart/cart/update {item_id, qty} -> updated
 * cart items + totals as JSON. All validation lives in CartService; this
 * controller only translates its result/exceptions into an HTTP response.
 *
 * Relies on Magento's default CsrfValidator XHR-detection path
 * (isXmlHttpRequest()), not CsrfAwareActionInterface -- web/js/customcart.js
 * always sends X-Requested-With: XMLHttpRequest on these fetch() calls,
 * the same mechanism core's own AJAX cart controllers
 * (e.g. Magento\Checkout\Controller\Cart\UpdateItemQty) rely on.
 */
class Update implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CartService $cartService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        try {
            $data = $this->cartService->updateItemQty(
                $this->request->getParam('item_id'),
                $this->request->getParam('qty')
            );

            return $result->setData(['success' => true] + $data);
        } catch (InvalidCartRequestException $e) {
            // Client-input error -- message is always safe to show, since
            // it never contains anything beyond what the client already sent.
            $result->setHttpResponseCode(400);
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Never leak a stack trace to the client -- log server-side,
            // return a generic message.
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $result->setHttpResponseCode(500);
            return $result->setData([
                'success' => false,
                'message' => (string)__('Something went wrong. Please try again.'),
            ]);
        }
    }
}
