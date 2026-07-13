<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model;

use Ivanchenko\CustomCart\Model\RegionDataProvider;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\ResourceModel\Country\Collection as CountryCollection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * The Region collection is iterated via foreach in production code
 * (Magento\Framework\Data\Collection::getIterator() returns
 * new \ArrayIterator($this->_items), a real inherited method left
 * unstubbed here) -- fixture rows are injected directly into that
 * protected _items property via reflection rather than trying to mock
 * iteration behavior itself, the same class of workaround already
 * established elsewhere in this module's tests for framework
 * collections/magic accessors.
 */
class RegionDataProviderTest extends TestCase
{
    public function testGetCountriesReturnsValueLabelPairsFromToOptionArray(): void
    {
        $countryCollection = $this->getMockBuilder(CountryCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByStore', 'toOptionArray'])
            ->getMock();
        $countryCollection->method('loadByStore')->willReturnSelf();
        $countryCollection->method('toOptionArray')->with(false)->willReturn([
            ['value' => 'CA', 'label' => 'Canada'],
            ['value' => 'US', 'label' => 'United States'],
        ]);

        $countryCollectionFactory = $this->createStub(CountryCollectionFactory::class);
        $countryCollectionFactory->method('create')->willReturn($countryCollection);

        $regionCollectionFactory = $this->createStub(RegionCollectionFactory::class);
        $regionCollectionFactory->method('create')->willReturn(
            $this->emptyRegionCollection()
        );

        $provider = new RegionDataProvider($countryCollectionFactory, $regionCollectionFactory);

        $this->assertSame(
            [
                ['value' => 'CA', 'label' => 'Canada'],
                ['value' => 'US', 'label' => 'United States'],
            ],
            $provider->getCountries()
        );
    }

    public function testGetRegionsByCountryBuildsCountryKeyedMapSkippingZeroRegionId(): void
    {
        $countryCollection = $this->getMockBuilder(CountryCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByStore', 'toOptionArray'])
            ->getMock();
        $countryCollection->method('loadByStore')->willReturnSelf();
        $countryCollection->method('toOptionArray')->willReturn([
            ['value' => 'CA', 'label' => 'Canada'],
        ]);

        $countryCollectionFactory = $this->createStub(CountryCollectionFactory::class);
        $countryCollectionFactory->method('create')->willReturn($countryCollection);

        $quebec = $this->regionStub(76, 'CA', 'QC', 'Quebec');
        $noRegionIdRow = $this->regionStub(0, 'CA', '', '');

        $regionCollection = $this->getMockBuilder(RegionCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addCountryFilter', 'load'])
            ->getMock();
        $regionCollection->method('addCountryFilter')->with(['CA'])->willReturnSelf();
        $regionCollection->method('load')->willReturnSelf();
        $this->setCollectionItems($regionCollection, [$quebec, $noRegionIdRow]);

        $regionCollectionFactory = $this->createStub(RegionCollectionFactory::class);
        $regionCollectionFactory->method('create')->willReturn($regionCollection);

        $provider = new RegionDataProvider($countryCollectionFactory, $regionCollectionFactory);

        $this->assertSame(
            ['CA' => [76 => ['code' => 'QC', 'name' => 'Quebec']]],
            $provider->getRegionsByCountry()
        );
    }

    private function regionStub(int $regionId, string $countryId, string $code, string $name): Region
    {
        // Only getName() is a real declared method on Region -- getRegionId()/
        // getCountryId()/getCode() are magic-only (confirmed by reading the
        // class directly), so they can't be ->method()-configured; set the
        // real, inherited DataObject data keys instead, same workaround
        // pattern used elsewhere in this module's tests for Region/Item.
        $region = $this->getMockBuilder(Region::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getName'])
            ->getMock();
        $region->method('getName')->willReturn($name);
        $region->setData('region_id', $regionId);
        $region->setData('country_id', $countryId);
        $region->setData('code', $code);

        return $region;
    }

    private function emptyRegionCollection(): RegionCollection
    {
        $collection = $this->getMockBuilder(RegionCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addCountryFilter', 'load'])
            ->getMock();
        $collection->method('addCountryFilter')->willReturnSelf();
        $collection->method('load')->willReturnSelf();

        return $collection;
    }

    private function setCollectionItems(RegionCollection $collection, array $items): void
    {
        $property = new \ReflectionProperty(\Magento\Framework\Data\Collection::class, '_items');
        $property->setValue($collection, $items);
    }
}
