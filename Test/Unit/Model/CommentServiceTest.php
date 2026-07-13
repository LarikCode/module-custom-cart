<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model;

use Ivanchenko\CustomCart\Model\CartItemCommentRepository;
use Ivanchenko\CustomCart\Model\CommentService;
use Ivanchenko\CustomCart\Model\Exception\InvalidCartRequestException;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Escaper;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;

/**
 * Covers CommentService's validation: ownership (the security-relevant
 * check), length, and -- the task's explicit safety requirement -- that
 * SQLi-style and <script>-style payloads are passed through to storage as
 * inert string data (never specially handled/interpreted) and are
 * correctly neutralized by Magento's own Escaper on render, proving the
 * "does not execute when rendered back" half of the requirement without
 * needing a browser.
 */
class CommentServiceTest extends TestCase
{
    private CheckoutSession $checkoutSession;
    private CartItemCommentRepository $commentRepository;
    private CommentService $commentService;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createStub(CheckoutSession::class);
        $this->commentRepository = $this->createMock(CartItemCommentRepository::class);

        $this->commentService = new CommentService($this->checkoutSession, $this->commentRepository);
    }

    public function testValidCommentSavesCorrectly(): void
    {
        $this->stubQuoteWithItem(5);

        $this->commentRepository->expects($this->once())
            ->method('save')
            ->with(5, 'gift wrap please');

        $result = $this->commentService->saveComment(5, 'gift wrap please');

        $this->assertSame(5, $result['item_id']);
        $this->assertSame('gift wrap please', $result['comment']);
    }

    public function testTrimsWhitespaceBeforeSaving(): void
    {
        $this->stubQuoteWithItem(5);

        $this->commentRepository->expects($this->once())
            ->method('save')
            ->with(5, 'gift wrap please');

        $this->commentService->saveComment(5, "  gift wrap please  \n");
    }

    public function testOverLengthCommentIsRejectedWithoutTouchingTheRepository(): void
    {
        $this->stubQuoteWithItem(5);

        $this->commentRepository->expects($this->never())->method('save');

        $this->expectException(InvalidCartRequestException::class);

        $this->commentService->saveComment(5, str_repeat('a', 501));
    }

    public function testExactlyFiveHundredCharactersIsAccepted(): void
    {
        $this->stubQuoteWithItem(5);
        $text = str_repeat('a', 500);

        $this->commentRepository->expects($this->once())->method('save')->with(5, $text);

        $this->commentService->saveComment(5, $text);
    }

    public function testMultibyteCommentIsCountedByCharacterNotByte(): void
    {
        $this->stubQuoteWithItem(5);
        // 500 multibyte characters -- well over 500 *bytes* (each takes
        // 2-3 bytes in UTF-8), so a strlen()-based check would wrongly
        // reject this; mb_strlen() must not.
        $text = str_repeat('é', 500);

        $this->commentRepository->expects($this->once())->method('save')->with(5, $text);

        $this->commentService->saveComment(5, $text);
    }

    public function testSqlInjectionStylePayloadIsPassedThroughAsAnInertStringParameter(): void
    {
        $this->stubQuoteWithItem(5);
        $payload = "'; DROP TABLE ivanchenko_customcart_item_comment; --";

        // The assertion IS that save() receives this as a plain string
        // bind argument, exactly like any other comment -- proving there
        // is no code path in this service that treats it as SQL syntax
        // rather than opaque data.
        $this->commentRepository->expects($this->once())
            ->method('save')
            ->with(5, $payload);

        $result = $this->commentService->saveComment(5, $payload);

        $this->assertSame($payload, $result['comment']);
    }

    public function testScriptTagPayloadIsStoredVerbatimNotStripped(): void
    {
        $this->stubQuoteWithItem(5);
        $payload = '<script>alert(1)</script>';

        // Stored as-is -- the defense is escape-on-render, not
        // strip-on-input (see CommentService's class docblock).
        $this->commentRepository->expects($this->once())
            ->method('save')
            ->with(5, $payload);

        $result = $this->commentService->saveComment(5, $payload);

        $this->assertSame($payload, $result['comment']);
    }

    public function testScriptTagPayloadIsNeutralizedByEscaperOnRender(): void
    {
        $payload = '<script>alert(1)</script>';

        // Companion to the "stored verbatim" test above -- proves the
        // "does not execute... when rendered back" half of the
        // requirement: the exact stored string, run through the same
        // Escaper the template uses, is not executable markup.
        $escaper = new Escaper();

        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $escaper->escapeHtml($payload)
        );
    }

    public function testCommentForAnItemNotOnTheCurrentQuoteIsRejected(): void
    {
        $quote = $this->createStub(Quote::class);
        $quote->method('getItemById')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->commentRepository->expects($this->never())->method('save');

        $this->expectException(InvalidCartRequestException::class);

        $this->commentService->saveComment(999, 'hello');
    }

    private function stubQuoteWithItem(int $itemId): void
    {
        $item = $this->createStub(Item::class);
        $item->method('getId')->willReturn($itemId);

        // createMock(), not createStub() -- ->with() argument matching on
        // getItemById() requires a mock; stubs only support unconditional
        // canned returns via ->method(), not per-argument matching.
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemById')->with($itemId)->willReturn($item);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
    }
}
