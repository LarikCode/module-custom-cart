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
 * AJAX endpoint: POST customcart/cart/estimatetax {country_id, region_id,
 * postcode} -> updated cart items + totals (including the recalculated
 * GST/QST breakdown) as JSON. See Update.php for the CSRF/error-shape
 * notes, identical here.
 */
class EstimateTax implements HttpPostActionInterface
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
            $data = $this->cartService->estimateTax(
                $this->request->getParam('country_id'),
                $this->request->getParam('region_id'),
                $this->request->getParam('postcode')
            );

            return $result->setData(['success' => true] + $data);
        } catch (InvalidCartRequestException $e) {
            $result->setHttpResponseCode(400);
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $result->setHttpResponseCode(500);
            return $result->setData([
                'success' => false,
                'message' => (string)__('Something went wrong. Please try again.'),
            ]);
        }
    }
}
