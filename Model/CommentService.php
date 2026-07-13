<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model;

use Ivanchenko\CustomCart\Model\Exception\InvalidCartRequestException;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Validates and persists per-cart-item comments (e.g. "gift wrap please").
 *
 * Comment text is stored verbatim (post length-trim), never stripped of
 * HTML -- the defense against stored XSS is escape-on-render
 * ($block->escapeHtml() server-side, .textContent DOM assignment
 * client-side; see view/frontend/templates/cart/index.phtml and
 * web/js/customcart.js), not strip-on-input. Stripping/blocklisting tags
 * is bypassable and lossy; output-encoding is the standard, robust defense
 * (OWASP's own recommendation), and it's what actually gets exercised here
 * regardless of what the input looked like.
 */
class CommentService
{
    private const MAX_COMMENT_LENGTH = 500;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartItemCommentRepository $commentRepository
    ) {
    }

    public function getCommentForItem(int $quoteItemId): ?string
    {
        return $this->commentRepository->getByQuoteItemId($quoteItemId);
    }

    /**
     * @return array{item_id: int, comment: string}
     * @throws InvalidCartRequestException
     */
    public function saveComment(mixed $itemId, mixed $commentText): array
    {
        $quoteItemId = $this->assertItemBelongsToCurrentQuote($itemId);
        $text = $this->validateCommentText($commentText);

        $this->commentRepository->save($quoteItemId, $text);

        return ['item_id' => $quoteItemId, 'comment' => $text];
    }

    /**
     * Security-relevant check: confirms the requested item actually belongs
     * to the CURRENT session's quote before any read/write, so one session
     * can't read or overwrite another session's comment by guessing a
     * quote_item_id. getItemById() is sufficient on its own -- Magento's
     * quote item collection is already scoped to $quote->getId() -- so no
     * separate manual comparison against the comments table is needed.
     *
     * @throws InvalidCartRequestException
     */
    private function assertItemBelongsToCurrentQuote(mixed $itemId): int
    {
        $itemId = filter_var($itemId, FILTER_VALIDATE_INT);
        if ($itemId === false) {
            throw new InvalidCartRequestException(__('Invalid item.'));
        }

        $quote = $this->checkoutSession->getQuote();
        $item = $quote->getItemById($itemId);

        // getItemById() returns false (not null) when the item isn't found
        // or doesn't belong to this quote -- same error either way, so we
        // never confirm/deny whether an item id exists elsewhere.
        if ($item === false) {
            throw new InvalidCartRequestException(__('The requested cart item was not found.'));
        }

        return $itemId;
    }

    /**
     * @throws InvalidCartRequestException
     */
    private function validateCommentText(mixed $commentText): string
    {
        if (!is_string($commentText)) {
            throw new InvalidCartRequestException(__('Comment text is invalid.'));
        }

        $trimmed = trim($commentText);

        // mb_strlen, not strlen -- a byte count would miscount/truncate
        // multibyte comment text mid-character at the length boundary.
        if (mb_strlen($trimmed) > self::MAX_COMMENT_LENGTH) {
            throw new InvalidCartRequestException(
                __('Comment must be %1 characters or fewer.', self::MAX_COMMENT_LENGTH)
            );
        }

        return $trimmed;
    }
}
