<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model;

use Ivanchenko\CustomCart\Model\CartItemCommentRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * Proves CartItemCommentRepository never builds SQL by string
 * concatenation: every assertion here is on the *shape of the call* into
 * Magento's adapter API (exact table name, exact bind array, exact
 * placeholder-form where() argument), which is only possible to assert
 * this precisely because those calls are genuinely parameterized --
 * there's no raw SQL string to intercept, because none is ever built.
 */
class CartItemCommentRepositoryTest extends TestCase
{
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private CartItemCommentRepository $repository;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->repository = new CartItemCommentRepository($this->resourceConnection);
    }

    public function testSaveCallsInsertOnDuplicateWithExactTableBindAndUpdateColumns(): void
    {
        $this->resourceConnection->method('getTableName')
            ->with('ivanchenko_customcart_item_comment')
            ->willReturn('magento_ivanchenko_customcart_item_comment');

        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'magento_ivanchenko_customcart_item_comment',
                ['quote_item_id' => 5, 'comment_text' => 'gift wrap please'],
                ['comment_text']
            );

        $this->repository->save(5, 'gift wrap please');
    }

    public function testSavePassesAnInjectionStylePayloadThroughAsAnOpaqueBindValue(): void
    {
        $payload = "'; DROP TABLE ivanchenko_customcart_item_comment; --";
        $this->resourceConnection->method('getTableName')->willReturn('ivanchenko_customcart_item_comment');

        // The assertion is that insertOnDuplicate() receives this exact
        // string as a plain array value -- the same call shape as any
        // other comment. There is no branch anywhere in save() that
        // formats $commentText into a SQL string, so there is nothing for
        // this payload to "break out" of.
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                $this->anything(),
                ['quote_item_id' => 5, 'comment_text' => $payload],
                $this->anything()
            );

        $this->repository->save(5, $payload);
    }

    public function testGetByQuoteItemIdBuildsAParameterizedWhereClauseNotAnInterpolatedString(): void
    {
        $this->resourceConnection->method('getTableName')->willReturn('ivanchenko_customcart_item_comment');

        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'where'])
            ->getMock();
        $select->method('from')->willReturnSelf();
        // The '?' placeholder form, not "quote_item_id = " . $quoteItemId --
        // this is what proves the value is bound, not interpolated.
        $select->expects($this->once())
            ->method('where')
            ->with('quote_item_id = ?', 5)
            ->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->with($select)->willReturn('gift wrap please');

        $result = $this->repository->getByQuoteItemId(5);

        $this->assertSame('gift wrap please', $result);
    }

    public function testGetByQuoteItemIdReturnsNullNotEmptyStringWhenNoRowExists(): void
    {
        $this->resourceConnection->method('getTableName')->willReturn('ivanchenko_customcart_item_comment');

        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'where'])
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        // fetchOne() returns false (Zend_Db's "no row" sentinel), not '',
        // when nothing matches.
        $this->connection->method('fetchOne')->willReturn(false);

        $result = $this->repository->getByQuoteItemId(999);

        $this->assertNull($result);
    }
}
