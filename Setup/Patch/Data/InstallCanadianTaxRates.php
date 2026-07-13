<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Setup\Patch\Data;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Api\Data\TaxRateInterface;
use Magento\Tax\Api\Data\TaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\ClassModel;
use Psr\Log\LoggerInterface;

/**
 * Provisions Canadian GST (5%, all provinces) and Quebec QST (9.975%,
 * Quebec only) as native Magento tax rates, each in its OWN tax rule,
 * using Magento's own tax engine rather than a custom totals collector.
 *
 * Both rules share the same priority and calculate_subtotal=true. This
 * is deliberately NOT "one rule containing both rates" -- that was the
 * first design here, and it produces WRONG tax (silently drops one of
 * the two rates) due to a real bug in Magento's own
 * Magento\Tax\Model\ResourceModel\Calculation::_calculateRate(): its
 * inner while-loop skips ahead through every rate sharing the same
 * tax_calculation_rule_id, advancing the array pointer WITHOUT
 * accumulating each individual rate's value, so only the first rate
 * seen in sort order survives when two rates share one rule_id. Putting
 * GST and QST in separate rules avoids that loop entirely (the skip
 * only triggers within a single rule_id); keeping both rules at the
 * same priority is what routes them into the additive
 * ($result += $currentRate) branch instead of the compounding
 * (_collectPercent()) one, and calculate_subtotal=true on both is what
 * makes that additive branch reachable at all. Verified empirically via
 * the cart totals REST endpoint (see README) -- this is the one part of
 * this patch where trusting the code alone would have shipped wrong tax
 * math.
 *
 * Magento's own patch_list tracking prevents this from ever running
 * twice on a given install, so no custom idempotency guard is added
 * here -- matching how core data patches are written.
 */
class InstallCanadianTaxRates implements DataPatchInterface
{
    private const DEFAULT_PRODUCT_TAX_CLASS_NAME = 'Taxable Goods';
    private const DEFAULT_PRODUCT_TAX_CLASS_ID = 2;
    private const DEFAULT_CUSTOMER_TAX_CLASS_NAME = 'Retail Customer';
    private const DEFAULT_CUSTOMER_TAX_CLASS_ID = 3;

    private const GST_RATE = 5.0;
    private const QST_RATE = 9.975;

    public function __construct(
        private readonly TaxRateInterfaceFactory $taxRateFactory,
        private readonly TaxRateRepositoryInterface $taxRateRepository,
        private readonly TaxRuleInterfaceFactory $taxRuleFactory,
        private readonly TaxRuleRepositoryInterface $taxRuleRepository,
        private readonly TaxClassRepositoryInterface $taxClassRepository,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly RegionFactory $regionFactory,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $productTaxClassId = $this->lookupTaxClassId(
            self::DEFAULT_PRODUCT_TAX_CLASS_NAME,
            ClassModel::TAX_CLASS_TYPE_PRODUCT,
            self::DEFAULT_PRODUCT_TAX_CLASS_ID
        );
        $customerTaxClassId = $this->lookupTaxClassId(
            self::DEFAULT_CUSTOMER_TAX_CLASS_NAME,
            ClassModel::TAX_CLASS_TYPE_CUSTOMER,
            self::DEFAULT_CUSTOMER_TAX_CLASS_ID
        );

        $quebecRegionId = $this->lookupQuebecRegionId();

        $gstRate = $this->createRate('CA-GST', self::GST_RATE, 'CA', 0);
        $qstRate = $this->createRate('CA-QC-QST', self::QST_RATE, 'CA', $quebecRegionId);

        // Two separate rules, not one rule with both rates -- see class
        // docblock for why. Same priority + calculate_subtotal=true on
        // both is what makes them sum independently on the pre-tax
        // subtotal instead of compounding.
        $this->createRule('Canada GST', [$gstRate->getId()], $customerTaxClassId, $productTaxClassId);
        $this->createRule('Canada QST (Quebec)', [$qstRate->getId()], $customerTaxClassId, $productTaxClassId);

        // Gates whether the per-rate GST/QST breakdown renders as
        // separate rows under "Tax" in the storefront cart summary --
        // default is off. See Magento_Tax's TaxConfigProvider /
        // checkout/cart/totals/tax.html ifShowDetails().
        $this->configWriter->save('tax/cart_display/full_summary', 1);

        return $this;
    }

    /**
     * @param int[] $taxRateIds
     */
    private function createRule(
        string $code,
        array $taxRateIds,
        int $customerTaxClassId,
        int $productTaxClassId
    ): void {
        $rule = $this->taxRuleFactory->create();
        $rule->setCode($code)
            ->setPriority(0)
            ->setPosition(0)
            ->setCustomerTaxClassIds([$customerTaxClassId])
            ->setProductTaxClassIds([$productTaxClassId])
            ->setTaxRateIds($taxRateIds)
            // Correctness-critical: see class docblock. Both GST's and
            // QST's rules must share this same priority and both have
            // calculate_subtotal=true for the engine to sum them
            // independently on the pre-tax subtotal instead of
            // compounding.
            ->setCalculateSubtotal(true);

        $this->taxRuleRepository->save($rule);
    }

    private function createRate(string $code, float $rate, string $countryId, int $regionId): TaxRateInterface
    {
        $taxRate = $this->taxRateFactory->create();
        $taxRate->setCode($code)
            ->setRate($rate)
            ->setTaxCountryId($countryId)
            ->setTaxRegionId($regionId)
            ->setTaxPostcode('*');

        return $this->taxRateRepository->save($taxRate);
    }

    /**
     * Resolves Quebec's region ID from directory_country_region.
     *
     * Fails loudly rather than falling back to "all of Canada" (region 0):
     * a silent fallback here would mean QST gets charged nationwide --
     * a real tax-compliance bug, not just a display bug.
     */
    private function lookupQuebecRegionId(): int
    {
        $region = $this->regionFactory->create();
        $region->loadByCode('QC', 'CA');
        $regionId = (int)$region->getRegionId();

        if ($regionId === 0) {
            throw new \RuntimeException(
                'Ivanchenko_CustomCart: could not resolve Quebec (region code "QC", country "CA") from '
                . 'directory_country_region. Ensure Magento_Directory\'s data has been installed before this '
                . 'patch runs (see etc/module.xml sequence).'
            );
        }

        return $regionId;
    }

    /**
     * Looks up a default tax class by name rather than trusting a
     * hardcoded ID, since a third-party module can't assume this
     * particular install's tax classes weren't customized. Falls back
     * to Magento's own hardcoded default (core itself hardcodes these
     * same IDs in AddTaxAttributeAndTaxClasses/TaxRulesFixture), logged
     * so an unusual install is visible rather than silently wrong.
     */
    private function lookupTaxClassId(string $className, string $classType, int $fallback): int
    {
        $searchCriteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('class_name', $className)
            ->addFilter('class_type', $classType)
            ->create();

        $items = $this->taxClassRepository->getList($searchCriteria)->getItems();
        if (!empty($items)) {
            return (int)reset($items)->getClassId();
        }

        $this->logger->warning(sprintf(
            'Ivanchenko_CustomCart: tax class "%s" (%s) not found by name; falling back to default id %d.',
            $className,
            $classType,
            $fallback
        ));

        return $fallback;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
