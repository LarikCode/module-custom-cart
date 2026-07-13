<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Controller\Cart;

use Ivanchenko\CustomCart\Model\CommentService;
use Ivanchenko\CustomCart\Model\Exception\InvalidCartRequestException;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * AJAX endpoint: POST customcart/cart/comment {item_id, comment} -> the
 * saved (validated, trimmed) comment text as JSON. See Update.php for the
 * CSRF/error-shape notes, identical here.
 *
 * $request->getParam('comment') is read as-is and passed straight into
 * CommentService::saveComment() -- no manual sanitization/stripping
 * happens in this controller. Every safety measure (ownership check,
 * length validation, parameterized persistence, output escaping) lives in
 * CommentService/CartItemCommentRepository/the template, on purpose: an
 * injection-style payload sent directly to this endpoint (bypassing any
 * client-side form) exercises exactly the same validation path as a normal
 * request, not a separate/weaker one.
 */
class Comment implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CommentService $commentService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        try {
            $data = $this->commentService->saveComment(
                $this->request->getParam('item_id'),
                $this->request->getParam('comment')
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
