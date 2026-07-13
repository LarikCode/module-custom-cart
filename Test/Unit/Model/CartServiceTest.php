<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model;

use Ivanchenko\CustomCart\Model\CartService;
use Ivanchenko\CustomCart\Model\CommentService;
use Ivanchenko\CustomCart\Model\Exception\InvalidCartRequestException;
use Ivanchenko\CustomCart\Model\RegionDataProvider;
use Magento\Catalog\Block\Product\Image as ImageBlock;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers CartService, the business logic behind the custom cart page
 * (/customcart/cart). Quote/Item/Address are partial mocks (onlyMethods on
 * their real declared methods) with row_total set via the real, inherited
 * DataObject::setData() -- getRowTotal() is magic-only, so it's only ever
 * reachable that way in a bare unit test, not something PHPUnit can
 * configure via ->method() directly.
 *
 * The tax-breakdown fixtures below (getShippingAddress()->getAppliedTaxes())
 * are shaped from an empirically observed live quote, not guessed -- see
 * CartService::buildTaxBreakdown()'s docblock for why
 * $quote->getTotals()['tax']->getData('full_info') (the more obvious-looking
 * read) does NOT work, and this test pins the correct one so a future
 * refactor can't silently drift back to the broken path without a test
 * failure.
 */
class CartServiceTest extends TestCase
{
    private CheckoutSession $checkoutSession;
    private CartRepositoryInterface $quoteRepository;
    private CommentService $commentService;
    private ImageFactory $imageFactory;
    private RegionDataProvider $regionDataProvider;
    private CartService $cartService;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createStub(CheckoutSession::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        // createMock(), not createStub() -- one test below uses ->with()
        // on getCommentForItem(), which requires a mock.
        $this->commentService = $this->createMock(CommentService::class);
        $this->imageFactory = $this->createStub(ImageFactory::class);
        $this->regionDataProvider = $this->createStub(RegionDataProvider::class);

        $this->cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );
    }

    public function testGetCartDataOnEmptyQuoteReturnsEmptyItemsWithoutError(): void
    {
        $quote = $this->quoteStub([], $this->totalsFixture(0.0, 0.0), []);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $result = $this->cartService->getCartData();

        $this->assertSame([], $result['items']);
        $this->assertSame(0.0, $result['totals']['subtotal']);
        $this->assertSame(0.0, $result['totals']['grand_total']);
        $this->assertSame([], $result['totals']['tax_rates']);
    }

    public function testGetCartDataBuildsGstQstBreakdownFromAnEmpiricallyObservedFixture(): void
    {
        $item = $this->itemStub(1, 'Wayfarer Messenger Bag', '24-MB05', 1.0, 45.0, 45.0);
        $this->commentService->method('getCommentForItem')->with(1)->willReturn('gift wrap please');

        // Shaped exactly like the real array captured from
        // $quote->getShippingAddress()->getAppliedTaxes() against a live
        // Quebec-address quote (see README's "Verifying the tax math on
        // the custom cart page" section): a combined amount per rate
        // group, not a per-rate dollar figure.
        $appliedTaxes = [
            'CA-GSTCA-QC-QST' => [
                'amount' => 6.74,
                'base_amount' => 6.74,
                'percent' => 14.975,
                'id' => 'CA-GSTCA-QC-QST',
                'rates' => [
                    ['percent' => 5, 'code' => 'CA-GST', 'title' => 'CA-GST'],
                    ['percent' => 9.975, 'code' => 'CA-QC-QST', 'title' => 'CA-QC-QST'],
                ],
                'item_id' => '15',
                'item_type' => 'product',
                'associated_item_id' => null,
                'process' => 0,
            ],
        ];

        $quote = $this->quoteStub([$item], $this->totalsFixture(45.0, 6.74, 51.74), $appliedTaxes);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $result = $this->cartService->getCartData();

        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['items'][0]['item_id']);
        $this->assertSame('Wayfarer Messenger Bag', $result['items'][0]['name']);
        $this->assertSame('24-MB05', $result['items'][0]['sku']);
        $this->assertSame(1.0, $result['items'][0]['qty']);
        $this->assertSame(45.0, $result['items'][0]['price']);
        // row_total is magic-only on Item -- proves buildItemData() reads
        // it correctly via the real inherited DataObject getter.
        $this->assertSame(45.0, $result['items'][0]['row_total']);
        $this->assertSame('gift wrap please', $result['items'][0]['comment']);

        $this->assertCount(2, $result['totals']['tax_rates']);
        $this->assertSame('CA-GST', $result['totals']['tax_rates'][0]['title']);
        $this->assertSame(5.0, $result['totals']['tax_rates'][0]['percent']);
        // Derived (percent x pre-tax subtotal), not read verbatim -- the
        // source data only carries a combined group amount, not a
        // per-rate dollar figure. See CartService's docblock.
        $this->assertSame(2.25, $result['totals']['tax_rates'][0]['amount']);
        $this->assertSame('CA-QC-QST', $result['totals']['tax_rates'][1]['title']);
        $this->assertSame(9.975, $result['totals']['tax_rates'][1]['percent']);
        $this->assertSame(4.49, $result['totals']['tax_rates'][1]['amount']);
    }

    public function testGetCartDataToleratesNoAddressEstimatedYet(): void
    {
        // The realistic "no address estimated yet" shape: getAppliedTaxes()
        // returns an empty array (never null), same as a real quote with
        // no shipping address set.
        $quote = $this->quoteStub([], $this->totalsFixture(45.0, 0.0), []);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $result = $this->cartService->getCartData();

        $this->assertSame([], $result['totals']['tax_rates']);
    }

    /**
     * Regression guard for a real bug caught during manual verification:
     * Address::getAppliedTaxes() can return null, not just [] -- its own
     * `$taxes ? unserialize($taxes) : []` guard only catches an
     * empty/falsy stored value, not a stored value that successfully
     * unserializes TO null (observed on an emptied-out cart). An earlier
     * version of buildTaxBreakdown() foreach()'d over this directly and
     * threw a TypeError.
     */
    public function testGetCartDataToleratesAppliedTaxesReturningNull(): void
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAppliedTaxes'])
            ->getMock();
        $address->method('getAppliedTaxes')->willReturn(null);
        $address->setData('discount_amount', 0.0);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'collectTotals', 'getTotals', 'getShippingAddress'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getTotals')->willReturn($this->totalsFixture(0.0, 0.0));
        $quote->method('getShippingAddress')->willReturn($address);

        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $result = $this->cartService->getCartData();

        $this->assertSame([], $result['totals']['tax_rates']);
    }

    public function testUpdateItemQtyHappyPathCallsSetQtyThenCollectTotalsThenSave(): void
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'setQty'])
            ->getMock();
        $item->method('getId')->willReturn(7);
        $item->expects($this->once())->method('setQty')->with(3);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById', 'getAllVisibleItems', 'collectTotals', 'getTotals', 'getShippingAddress'])
            ->getMock();
        $quote->method('getItemById')->with(7)->willReturn($item);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getTotals')->willReturn($this->totalsFixture(0.0, 0.0));
        $quote->method('getShippingAddress')->willReturn($this->addressStub([]));
        // atLeastOnce(), not once(): the real call sequence is
        // setQty() -> collectTotals() -> save() -> (internally)
        // getCartData() -> collectTotals() again to build the response.
        // In production Quote::collectTotals() no-ops on a repeat call
        // within the same request via its own internal flag, making the
        // second call free; that flag isn't reproduced by this mock, so
        // asserting an exact count here would pin an implementation
        // detail rather than behavior that matters.
        $quote->expects($this->atLeastOnce())->method('collectTotals');

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        $this->cartService->updateItemQty(7, 3);
    }

    #[DataProvider('invalidQtyProvider')]
    public function testUpdateItemQtyRejectsInvalidQuantityWithoutTouchingTheQuote(mixed $invalidQty): void
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setQty'])
            ->getMock();
        $item->expects($this->never())->method('setQty');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById'])
            ->getMock();
        $quote->method('getItemById')->willReturn($item);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->never())->method('save');

        $this->expectException(InvalidCartRequestException::class);

        $this->cartService->updateItemQty(1, $invalidQty);
    }

    public static function invalidQtyProvider(): array
    {
        return [
            'over the maximum' => [101],
            'zero' => [0],
            'negative' => [-1],
            'non-integer string' => ['5.5'],
            'non-numeric string' => ['abc'],
        ];
    }

    public function testUpdateItemQtyRejectsAnItemNotOnTheCurrentQuote(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById'])
            ->getMock();
        $quote->method('getItemById')->willReturn(false);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->never())->method('save');

        $this->expectException(InvalidCartRequestException::class);

        $this->cartService->updateItemQty(999, 3);
    }

    public function testRemoveItemHappyPathCallsRemoveItemThenCollectTotalsThenSave(): void
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $item->method('getId')->willReturn(7);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById', 'removeItem', 'getAllVisibleItems', 'collectTotals', 'getTotals', 'getShippingAddress'])
            ->getMock();
        $quote->method('getItemById')->with(7)->willReturn($item);
        $quote->expects($this->once())->method('removeItem')->with(7);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getTotals')->willReturn($this->totalsFixture(0.0, 0.0));
        $quote->method('getShippingAddress')->willReturn($this->addressStub([]));
        // atLeastOnce(), not once() -- see testUpdateItemQtyHappyPath...'s
        // comment above for why.
        $quote->expects($this->atLeastOnce())->method('collectTotals');

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        $this->cartService->removeItem(7);
    }

    public function testRemoveItemRejectsAnItemNotOnTheCurrentQuote(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById'])
            ->getMock();
        $quote->method('getItemById')->willReturn(false);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->never())->method('save');

        $this->expectException(InvalidCartRequestException::class);

        $this->cartService->removeItem(999);
    }

    public function testBuildItemDataIncludesImageUrlWhenProductIsAttached(): void
    {
        $product = $this->createStub(Product::class);

        $item = $this->itemStub(1, 'Wayfarer Messenger Bag', '24-MB05', 1.0, 45.0, 45.0);
        $item->setData('product', $product);

        // onlyMethods([]) explicitly, not omitted: omitting onlyMethods()
        // entirely makes PHPUnit auto-stub every real declared method it
        // can reflect on, INCLUDING setData()/getData() themselves
        // (inherited from DataObject) -- which would silently turn
        // setData() below into a no-op and break the magic getImageUrl()
        // read that depends on it. onlyMethods([]) means "override
        // nothing," leaving the whole DataObject storage chain real.
        $imageBlock = $this->getMockBuilder(ImageBlock::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        // getImageUrl() is magic-only (no declared method on Image), so
        // it can't be ->method()-configured -- set the real, inherited
        // DataObject data key instead, same pattern as row_total above.
        $imageBlock->setData('image_url', 'https://example.test/media/catalog/product/w/a/wayfarer.jpg');

        $this->imageFactory = $this->createMock(ImageFactory::class);
        $this->imageFactory->expects($this->once())
            ->method('create')
            ->with($product, 'cart_page_product_thumbnail')
            ->willReturn($imageBlock);

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $quote = $this->quoteStub([$item], $this->totalsFixture(45.0, 0.0), []);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $result = $cartService->getCartData();

        $this->assertSame(
            'https://example.test/media/catalog/product/w/a/wayfarer.jpg',
            $result['items'][0]['image_url']
        );
    }

    public function testBuildItemDataImageUrlIsNullWhenNoProductIsAttached(): void
    {
        $item = $this->itemStub(1, 'Wayfarer Messenger Bag', '24-MB05', 1.0, 45.0, 45.0);
        $this->imageFactory = $this->createMock(ImageFactory::class);
        $this->imageFactory->expects($this->never())->method('create');

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $quote = $this->quoteStub([$item], $this->totalsFixture(45.0, 0.0), []);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $result = $cartService->getCartData();

        $this->assertNull($result['items'][0]['image_url']);
    }

    public function testEstimateTaxHappyPathSetsAddressThenCollectTotalsThenSave(): void
    {
        $this->regionDataProvider = $this->createStub(RegionDataProvider::class);
        $this->regionDataProvider->method('getCountries')->willReturn([
            ['value' => 'CA', 'label' => 'Canada'],
        ]);
        $this->regionDataProvider->method('getRegionsByCountry')->willReturn([
            'CA' => [76 => ['code' => 'QC', 'name' => 'Quebec']],
        ]);

        // setCollectShippingRates() is magic-only (no declared method on
        // Address), so it's deliberately left out of onlyMethods() below
        // and out of the assertions -- it can't be ->method()-configured,
        // and the real inherited magic setter is harmless to leave running.
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setCountryId', 'setRegionId', 'setPostcode'])
            ->getMock();
        $address->method('setCountryId')->with('CA')->willReturnSelf();
        $address->method('setRegionId')->with(76)->willReturnSelf();
        $address->method('setPostcode')->with('H2X1Y6')->willReturnSelf();

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getAllVisibleItems', 'collectTotals', 'getTotals'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getTotals')->willReturn($this->totalsFixture(0.0, 0.0));
        $quote->expects($this->atLeastOnce())->method('collectTotals');

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $cartService->estimateTax('CA', '76', 'H2X1Y6');
    }

    public function testEstimateTaxAcceptsNoRegionForACountryWithNone(): void
    {
        $this->regionDataProvider = $this->createStub(RegionDataProvider::class);
        $this->regionDataProvider->method('getCountries')->willReturn([
            ['value' => 'US', 'label' => 'United States'],
        ]);
        $this->regionDataProvider->method('getRegionsByCountry')->willReturn([]);

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setCountryId', 'setRegionId', 'setPostcode'])
            ->getMock();
        $address->method('setCountryId')->willReturnSelf();
        $address->expects($this->once())->method('setRegionId')->with(null)->willReturnSelf();
        $address->method('setPostcode')->willReturnSelf();

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getAllVisibleItems', 'collectTotals', 'getTotals'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getTotals')->willReturn($this->totalsFixture(0.0, 0.0));

        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $cartService->estimateTax('US', '', '');
    }

    /**
     * Regression test for the grand-total mismatch bug: qty update pushes
     * subtotal over $200, auto-applying this store's sample "20% OFF Every
     * $200-plus purchase!" Cart Price Rule (-$45 on a $225 subtotal), then
     * a Quebec tax estimate is submitted. Reproduces the exact sequence
     * (setQty -> collectTotals -> save, then setCountryId/setRegionId ->
     * collectTotals -> save) against the SAME quote/address mock instances,
     * mirroring how a single HTTP session actually behaves.
     *
     * Before the fix: buildTotals() never read discount_amount, and
     * buildTaxBreakdown() was passed the raw pre-discount subtotal, so
     * neither the displayed Discount row nor the GST/QST breakdown
     * reconciled against grand_total (observed: $206.96 shown against a
     * Subtotal/GST/QST breakdown that summed to $258.69). This test pins
     * grand_total == subtotal + discount_amount + tax_amount, and pins the
     * per-rate breakdown amounts to the POST-discount base, so both gaps
     * stay fixed.
     */
    public function testGrandTotalReconcilesAfterQtyUpdateThenTaxEstimateWithAnActiveDiscount(): void
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'setQty', 'getName', 'getSku', 'getQty', 'getPrice'])
            ->getMock();
        $item->method('getId')->willReturn(7);
        $item->expects($this->once())->method('setQty')->with(5);
        $item->method('getName')->willReturn('Wayfarer Messenger Bag');
        $item->method('getSku')->willReturn('24-MB05');
        $item->method('getQty')->willReturn(5.0);
        $item->method('getPrice')->willReturn(45.0);
        $item->setData('row_total', 225.0);

        // -45.0: the sales rule's discount, as Magento's real collectTotals()
        // would have set it on the shipping address after the qty change
        // crossed the rule's $200 threshold (225 x 20%).
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAppliedTaxes', 'setCountryId', 'setRegionId', 'setPostcode'])
            ->getMock();
        $address->method('setCountryId')->with('CA')->willReturnSelf();
        $address->method('setRegionId')->with(76)->willReturnSelf();
        $address->method('setPostcode')->with('H2X1Y6')->willReturnSelf();
        // GST 5% + QST 9.975% on the $180 post-discount subtotal (225 - 45),
        // the same empirically observed shape used elsewhere in this file.
        $address->method('getAppliedTaxes')->willReturn([
            'CA-GSTCA-QC-QST' => [
                'amount' => 26.96,
                'base_amount' => 26.96,
                'percent' => 14.975,
                'id' => 'CA-GSTCA-QC-QST',
                'rates' => [
                    ['percent' => 5, 'code' => 'CA-GST', 'title' => 'CA-GST'],
                    ['percent' => 9.975, 'code' => 'CA-QC-QST', 'title' => 'CA-QC-QST'],
                ],
                'item_id' => '7',
                'item_type' => 'product',
                'associated_item_id' => null,
                'process' => 0,
            ],
        ]);
        $address->setData('discount_amount', -45.0);

        $this->regionDataProvider = $this->createStub(RegionDataProvider::class);
        $this->regionDataProvider->method('getCountries')->willReturn([
            ['value' => 'CA', 'label' => 'Canada'],
        ]);
        $this->regionDataProvider->method('getRegionsByCountry')->willReturn([
            'CA' => [76 => ['code' => 'QC', 'name' => 'Quebec']],
        ]);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById', 'getAllVisibleItems', 'collectTotals', 'getTotals', 'getShippingAddress'])
            ->getMock();
        $quote->method('getItemById')->with(7)->willReturn($item);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('getShippingAddress')->willReturn($address);
        // Grand total as Magento's own Grand::collect() would additively
        // sum it (subtotal + discount + tax = 225 - 45 + 26.96), correct
        // end to end even before this fix -- only this page's own display
        // was ever wrong.
        $quote->method('getTotals')->willReturn($this->totalsFixture(225.0, 26.96, 206.96));
        $quote->expects($this->atLeastOnce())->method('collectTotals');

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->quoteRepository->expects($this->exactly(2))->method('save')->with($quote);

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $cartService->updateItemQty(7, 5);
        $result = $cartService->estimateTax('CA', '76', 'H2X1Y6');

        $totals = $result['totals'];
        $this->assertSame(225.0, $totals['subtotal']);
        $this->assertSame(-45.0, $totals['discount_amount']);
        $this->assertSame(26.96, $totals['tax_amount']);
        $this->assertSame(206.96, $totals['grand_total']);
        $this->assertEqualsWithDelta(
            $totals['grand_total'],
            $totals['subtotal'] + $totals['discount_amount'] + $totals['tax_amount'],
            0.001,
            'grand_total must equal subtotal + discount_amount + tax_amount'
        );

        // The GST/QST breakdown must also reconcile: it's computed against
        // the post-discount subtotal (180), not the raw one (225) -- the
        // second gap caught during this same investigation.
        $this->assertCount(2, $totals['tax_rates']);
        $this->assertSame(9.0, $totals['tax_rates'][0]['amount']);
        $this->assertSame(17.96, $totals['tax_rates'][1]['amount']);
        $this->assertEqualsWithDelta(
            $totals['tax_amount'],
            $totals['tax_rates'][0]['amount'] + $totals['tax_rates'][1]['amount'],
            0.01,
            'the tax breakdown rows must sum to the displayed tax_amount'
        );
    }

    public function testEstimateTaxRejectsAnUnknownCountryWithoutTouchingTheQuote(): void
    {
        $this->regionDataProvider = $this->createStub(RegionDataProvider::class);
        $this->regionDataProvider->method('getCountries')->willReturn([
            ['value' => 'CA', 'label' => 'Canada'],
        ]);

        $this->quoteRepository->expects($this->never())->method('save');

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $this->expectException(InvalidCartRequestException::class);

        $cartService->estimateTax('ZZ', '', '');
    }

    public function testEstimateTaxRejectsARegionThatDoesNotBelongToTheGivenCountry(): void
    {
        $this->regionDataProvider = $this->createStub(RegionDataProvider::class);
        $this->regionDataProvider->method('getCountries')->willReturn([
            ['value' => 'CA', 'label' => 'Canada'],
            ['value' => 'US', 'label' => 'United States'],
        ]);
        $this->regionDataProvider->method('getRegionsByCountry')->willReturn([
            'CA' => [76 => ['code' => 'QC', 'name' => 'Quebec']],
        ]);

        $this->quoteRepository->expects($this->never())->method('save');

        $cartService = new CartService(
            $this->checkoutSession,
            $this->quoteRepository,
            $this->commentService,
            $this->imageFactory,
            $this->regionDataProvider
        );

        $this->expectException(InvalidCartRequestException::class);

        // Quebec's region_id (76), submitted against the US, not Canada.
        $cartService->estimateTax('US', '76', '');
    }

    private function itemStub(
        int $id,
        string $name,
        string $sku,
        float $qty,
        float $price,
        float $rowTotal
    ): Item {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getSku', 'getQty', 'getPrice'])
            ->getMock();
        $item->method('getId')->willReturn($id);
        $item->method('getName')->willReturn($name);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getPrice')->willReturn($price);
        // getRowTotal() is magic-only (no declared method on Item/AbstractItem),
        // so it can't be ->method()-configured -- set the real, inherited
        // DataObject data key instead and let the real magic getter read it.
        $item->setData('row_total', $rowTotal);
        // getProduct() (real, declared) falls through to null harmlessly
        // when no 'product' data key is set and no product_id is set
        // either (confirmed by reading AbstractItem::getProduct()
        // directly) -- these existing fixtures were never given a
        // product, so resolveImageUrl()'s null-guard branch is what runs
        // for them, which is fine since none of these tests assert on
        // image_url.

        return $item;
    }

    /**
     * discountAmount defaults to 0.0 (no discount), matching the existing
     * callers/fixtures that predate discount support -- backward
     * compatible on purpose, not just for convenience.
     */
    private function addressStub(array $appliedTaxes, float $discountAmount = 0.0): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAppliedTaxes'])
            ->getMock();
        $address->method('getAppliedTaxes')->willReturn($appliedTaxes);
        // getDiscountAmount() is magic-only (@method docblock, no real
        // declared method on Address) -- same class of accessor as
        // getRowTotal() on Item elsewhere in this file, so it's set via
        // the real inherited setData() rather than ->method()-configured,
        // which would throw CannotUseOnlyMethodsException.
        $address->setData('discount_amount', $discountAmount);

        return $address;
    }

    private function quoteStub(array $items, array $totals, array $appliedTaxes, float $discountAmount = 0.0): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'collectTotals', 'getTotals', 'getShippingAddress'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getTotals')->willReturn($totals);
        $quote->method('getShippingAddress')->willReturn($this->addressStub($appliedTaxes, $discountAmount));

        return $quote;
    }

    /**
     * @return array<string, Total>
     */
    private function totalsFixture(float $subtotal, float $taxAmount, ?float $grandTotal = null): array
    {
        return [
            'subtotal' => new Total(['value' => $subtotal], new Json()),
            'tax' => new Total(['value' => $taxAmount], new Json()),
            'grand_total' => new Total(['value' => $grandTotal ?? ($subtotal + $taxAmount)], new Json()),
        ];
    }
}
