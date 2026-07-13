<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model;

use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;

/**
 * Same underlying data source as Magento\Directory\Block\Data
 * (getCountryCollection()/getRegionsJs()), reused directly via plain
 * constructor DI rather than pulling in that Block -- a heavy
 * Context-based constructor, the exact class of dependency
 * ViewModel\Cart\Index already avoids for this page. Shape of
 * getRegionsByCountry() matches core's getRegionsJs() output exactly:
 * {country_id: {region_id: {code, name}}}.
 *
 * A real country list via loadByStore() (respects general/country/allow),
 * not hardcoded to Canada -- even though only Canada has tax rules
 * configured in this install, hardcoding the dropdown to just Canada
 * would be assuming rather than matching how Magento's own equivalent
 * widget actually sources its list.
 */
class RegionDataProvider
{
    public function __construct(
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly RegionCollectionFactory $regionCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getCountries(): array
    {
        $collection = $this->countryCollectionFactory->create()->loadByStore();

        $options = $collection->toOptionArray(false);

        return array_map(
            static fn (array $option): array => ['value' => (string)$option['value'], 'label' => (string)$option['label']],
            $options
        );
    }

    /**
     * @return array<string, array<int, array{code: string, name: string}>>
     */
    public function getRegionsByCountry(): array
    {
        $countryIds = array_column($this->getCountries(), 'value');

        $regionCollection = $this->regionCollectionFactory->create()
            ->addCountryFilter($countryIds)
            ->load();

        $regions = [];
        foreach ($regionCollection as $region) {
            $regionId = (int)$region->getRegionId();
            if (!$regionId) {
                continue;
            }

            $regions[$region->getCountryId()][$regionId] = [
                'code' => (string)$region->getCode(),
                'name' => (string)$region->getName(),
            ];
        }

        return $regions;
    }
}
