<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\ViewModel\Cart;

use Ivanchenko\CustomCart\Model\CartService;
use Ivanchenko\CustomCart\Model\RegionDataProvider;
use Ivanchenko\CustomCart\ViewModel\Cart\Index;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    private CartService $cartService;
    private RegionDataProvider $regionDataProvider;
    private Json $jsonSerializer;
    private Index $viewModel;

    protected function setUp(): void
    {
        $this->cartService = $this->createMock(CartService::class);
        $this->regionDataProvider = $this->createMock(RegionDataProvider::class);
        $this->jsonSerializer = $this->createMock(Json::class);

        $this->viewModel = new Index($this->cartService, $this->regionDataProvider, $this->jsonSerializer);
    }

    public function testGetCartDataDelegatesToCartService(): void
    {
        $expected = ['items' => [], 'totals' => ['subtotal' => 0.0]];
        $this->cartService->expects($this->once())->method('getCartData')->willReturn($expected);

        $this->assertSame($expected, $this->viewModel->getCartData());
    }

    public function testGetCountriesDelegatesToRegionDataProvider(): void
    {
        $expected = [['value' => 'CA', 'label' => 'Canada']];
        $this->regionDataProvider->expects($this->once())->method('getCountries')->willReturn($expected);

        $this->assertSame($expected, $this->viewModel->getCountries());
    }

    public function testGetRegionsByCountryJsonSerializesRegionDataProviderOutput(): void
    {
        $regions = ['CA' => [76 => ['code' => 'QC', 'name' => 'Quebec']]];
        $this->regionDataProvider->expects($this->once())->method('getRegionsByCountry')->willReturn($regions);
        $this->jsonSerializer->expects($this->once())
            ->method('serialize')
            ->with($regions)
            ->willReturn('{"CA":{"76":{"code":"QC","name":"Quebec"}}}');

        $this->assertSame(
            '{"CA":{"76":{"code":"QC","name":"Quebec"}}}',
            $this->viewModel->getRegionsByCountryJson()
        );
    }
}
