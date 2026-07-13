<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Setup\Patch\Data;

use Ivanchenko\CustomCart\Setup\Patch\Data\InstallCanadianTaxRates;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Tax\Api\Data\TaxClassInterface;
use Magento\Tax\Api\Data\TaxClassSearchResultsInterface;
use Magento\Tax\Api\Data\TaxRateInterface;
use Magento\Tax\Api\Data\TaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxRuleInterface;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\ClassModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the data patch's *call contract* against the Tax module's
 * repository/factory APIs: exact arguments passed to setRate(),
 * setTaxRegionId(), and -- most importantly -- setCalculateSubtotal()
 * on TWO SEPARATE rules (not one rule with both rates -- see the patch
 * class's own docblock for why that design is wrong: it silently drops
 * one of the two rates due to a real bug in Magento's own
 * _calculateRate()).
 *
 * This cannot prove the real database/calculation-engine behavior (see
 * README "Running the tests" for the live REST-API verification that
 * does prove that) -- it proves the patch asks Magento's tax APIs to do
 * the right thing, which is what's realistically unit-testable without
 * standing up Magento's integration test framework for one patch.
 */
class InstallCanadianTaxRatesTest extends TestCase
{
    private const QUEBEC_REGION_ID = 65;
    private const PRODUCT_TAX_CLASS_ID = 2;
    private const CUSTOMER_TAX_CLASS_ID = 3;
    private const GST_RATE_ID = 101;
    private const QST_RATE_ID = 102;

    private TaxRateInterfaceFactory $taxRateFactory;
    private TaxRateRepositoryInterface $taxRateRepository;
    private TaxRuleInterfaceFactory $taxRuleFactory;
    private TaxRuleRepositoryInterface $taxRuleRepository;
    private TaxClassRepositoryInterface $taxClassRepository;
    private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory;
    private RegionFactory $regionFactory;
    private WriterInterface $configWriter;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        // These are only ever stubbed (->method()), never asserted on
        // directly -- expectations are set on the rate/rule objects
        // they *return*, not on the factories/repositories themselves.
        $this->taxRateFactory = $this->createStub(TaxRateInterfaceFactory::class);
        $this->taxRateRepository = $this->createStub(TaxRateRepositoryInterface::class);
        $this->taxRuleFactory = $this->createStub(TaxRuleInterfaceFactory::class);
        $this->taxRuleRepository = $this->createStub(TaxRuleRepositoryInterface::class);
        $this->taxClassRepository = $this->createStub(TaxClassRepositoryInterface::class);
        $this->searchCriteriaBuilderFactory = $this->createStub(SearchCriteriaBuilderFactory::class);
        $this->regionFactory = $this->createStub(RegionFactory::class);
        // These two DO get ->expects() in specific tests below, so they
        // stay createMock() (a stub can't have ->expects() added later).
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->mockSearchCriteriaBuilder();
        $this->mockTaxClassLookup(ClassModel::TAX_CLASS_TYPE_PRODUCT, self::PRODUCT_TAX_CLASS_ID);
        $this->mockTaxClassLookup(ClassModel::TAX_CLASS_TYPE_CUSTOMER, self::CUSTOMER_TAX_CLASS_ID);
        $this->mockQuebecRegion(self::QUEBEC_REGION_ID);
    }

    public function testCreatesGstRateAsFivePercentCountryWide(): void
    {
        [$gstRate] = $this->mockRateCreation();
        $this->mockRuleCreation();

        $gstRate->expects($this->once())->method('setRate')->with(5.0)->willReturnSelf();
        $gstRate->expects($this->once())->method('setTaxCountryId')->with('CA')->willReturnSelf();
        $gstRate->expects($this->once())->method('setTaxRegionId')->with(0)->willReturnSelf();

        $this->newPatch()->apply();
    }

    public function testCreatesQstRateAsNineDecimalNineSevenFivePercentQuebecOnly(): void
    {
        [, $qstRate] = $this->mockRateCreation();
        $this->mockRuleCreation();

        $qstRate->expects($this->once())->method('setRate')->with(9.975)->willReturnSelf();
        $qstRate->expects($this->once())->method('setTaxCountryId')->with('CA')->willReturnSelf();
        $qstRate->expects($this->once())->method('setTaxRegionId')->with(self::QUEBEC_REGION_ID)->willReturnSelf();

        $this->newPatch()->apply();
    }

    /**
     * The single most important assertion in this whole test class: if
     * this ever silently flips to false (or is omitted) on either rule,
     * that rate would compound on top of the other instead of being
     * computed independently on the pre-tax subtotal.
     */
    public function testBothRulesEnableCalculateSubtotalForNonCompoundingTax(): void
    {
        $this->mockRateCreation();
        [$gstRule, $qstRule] = $this->mockRuleCreation();

        $gstRule->expects($this->once())->method('setCalculateSubtotal')->with(true)->willReturnSelf();
        $qstRule->expects($this->once())->method('setCalculateSubtotal')->with(true)->willReturnSelf();

        $this->newPatch()->apply();
    }

    /**
     * GST and QST must each be in their OWN rule -- not one rule
     * containing both rate IDs, which is the design that was tried
     * first and shipped wrong tax (see patch class docblock).
     */
    public function testEachRateGetsItsOwnRuleRatherThanSharingOne(): void
    {
        $this->mockRateCreation();
        [$gstRule, $qstRule] = $this->mockRuleCreation();

        $gstRule->expects($this->once())->method('setTaxRateIds')->with([self::GST_RATE_ID])->willReturnSelf();
        $qstRule->expects($this->once())->method('setTaxRateIds')->with([self::QST_RATE_ID])->willReturnSelf();

        $this->newPatch()->apply();
    }

    public function testBothRulesShareTheSamePriority(): void
    {
        $this->mockRateCreation();
        [$gstRule, $qstRule] = $this->mockRuleCreation();

        $gstRule->expects($this->once())->method('setPriority')->with(0)->willReturnSelf();
        $qstRule->expects($this->once())->method('setPriority')->with(0)->willReturnSelf();

        $this->newPatch()->apply();
    }

    public function testEnablesFullTaxSummaryDisplayConfig(): void
    {
        $this->mockRateCreation();
        $this->mockRuleCreation();

        $this->configWriter->expects($this->once())
            ->method('save')
            ->with('tax/cart_display/full_summary', 1);

        $this->newPatch()->apply();
    }

    public function testFallsBackToDefaultClassIdAndLogsWarningWhenTaxClassNotFoundByName(): void
    {
        // Override the happy-path setUp() lookups with an empty result.
        $emptyResults = $this->createStub(TaxClassSearchResultsInterface::class);
        $emptyResults->method('getItems')->willReturn([]);

        $this->taxClassRepository = $this->createStub(TaxClassRepositoryInterface::class);
        $this->taxClassRepository->method('getList')->willReturn($emptyResults);

        $this->mockRateCreation();
        [$gstRule, $qstRule] = $this->mockRuleCreation();

        $this->logger->expects($this->atLeastOnce())->method('warning');

        // Falls back to Magento's own hardcoded defaults (2/3), on both rules.
        foreach ([$gstRule, $qstRule] as $rule) {
            $rule->expects($this->once())->method('setProductTaxClassIds')->with([2])->willReturnSelf();
            $rule->expects($this->once())->method('setCustomerTaxClassIds')->with([3])->willReturnSelf();
        }

        $this->newPatch()->apply();
    }

    public function testThrowsWhenQuebecRegionCannotBeResolved(): void
    {
        $region = $this->getMockBuilder(Region::class)->disableOriginalConstructor()->onlyMethods(['loadByCode'])->getMock();
        $region->setData('region_id', 0);

        $this->regionFactory = $this->createStub(RegionFactory::class);
        $this->regionFactory->method('create')->willReturn($region);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Quebec/');

        $this->newPatch()->apply();
    }

    public function testDeclaresNoPatchDependenciesOrAliases(): void
    {
        $this->assertSame([], InstallCanadianTaxRates::getDependencies());
        $this->assertSame([], $this->newPatch()->getAliases());
    }

    private function newPatch(): InstallCanadianTaxRates
    {
        return new InstallCanadianTaxRates(
            $this->taxRateFactory,
            $this->taxRateRepository,
            $this->taxRuleFactory,
            $this->taxRuleRepository,
            $this->taxClassRepository,
            $this->searchCriteriaBuilderFactory,
            $this->regionFactory,
            $this->configWriter,
            $this->logger
        );
    }

    private function mockSearchCriteriaBuilder(): void
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteriaInterface::class));

        $this->searchCriteriaBuilderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $this->searchCriteriaBuilderFactory->method('create')->willReturn($builder);
    }

    private function mockTaxClassLookup(string $classType, int $classId): void
    {
        $taxClass = $this->createStub(TaxClassInterface::class);
        $taxClass->method('getClassId')->willReturn($classId);

        $results = $this->createStub(TaxClassSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$taxClass]);

        $this->taxClassRepository->method('getList')->willReturn($results);
    }

    /**
     * getRegionId()/setRegionId() are magic DataObject accessors, not
     * real declared methods -- PHPUnit's mock builder can't configure
     * them via ->method(). Using a real (constructor-disabled) Region
     * instance and its real inherited magic setter works instead.
     */
    private function mockQuebecRegion(int $regionId): void
    {
        $region = $this->getMockBuilder(Region::class)->disableOriginalConstructor()->onlyMethods(['loadByCode'])->getMock();
        $region->setData('region_id', $regionId);

        $this->regionFactory->method('create')->willReturn($region);
    }

    /**
     * @return TaxRateInterface[] [$gstRate, $qstRate], each pre-wired
     *     with willReturnSelf() on every setter so the fluent chain in
     *     apply() doesn't error, and a distinct getId() so each rule's
     *     setTaxRateIds() call can be asserted against the right one.
     */
    private function mockRateCreation(): array
    {
        $gstRate = $this->createMock(TaxRateInterface::class);
        $gstRate->method('setCode')->willReturnSelf();
        $gstRate->method('setRate')->willReturnSelf();
        $gstRate->method('setTaxCountryId')->willReturnSelf();
        $gstRate->method('setTaxRegionId')->willReturnSelf();
        $gstRate->method('setTaxPostcode')->willReturnSelf();
        $gstRate->method('getId')->willReturn(self::GST_RATE_ID);

        $qstRate = $this->createMock(TaxRateInterface::class);
        $qstRate->method('setCode')->willReturnSelf();
        $qstRate->method('setRate')->willReturnSelf();
        $qstRate->method('setTaxCountryId')->willReturnSelf();
        $qstRate->method('setTaxRegionId')->willReturnSelf();
        $qstRate->method('setTaxPostcode')->willReturnSelf();
        $qstRate->method('getId')->willReturn(self::QST_RATE_ID);

        $this->taxRateFactory->method('create')->willReturnOnConsecutiveCalls($gstRate, $qstRate);
        $this->taxRateRepository->method('save')->willReturnOnConsecutiveCalls($gstRate, $qstRate);

        return [$gstRate, $qstRate];
    }

    /**
     * @return TaxRuleInterface[] [$gstRule, $qstRule], in creation order
     *     (createRule() is called for GST's rule first, then QST's).
     */
    private function mockRuleCreation(): array
    {
        $gstRule = $this->createMock(TaxRuleInterface::class);
        $gstRule->method('setCode')->willReturnSelf();
        $gstRule->method('setPriority')->willReturnSelf();
        $gstRule->method('setPosition')->willReturnSelf();
        $gstRule->method('setCustomerTaxClassIds')->willReturnSelf();
        $gstRule->method('setProductTaxClassIds')->willReturnSelf();
        $gstRule->method('setTaxRateIds')->willReturnSelf();
        $gstRule->method('setCalculateSubtotal')->willReturnSelf();

        $qstRule = $this->createMock(TaxRuleInterface::class);
        $qstRule->method('setCode')->willReturnSelf();
        $qstRule->method('setPriority')->willReturnSelf();
        $qstRule->method('setPosition')->willReturnSelf();
        $qstRule->method('setCustomerTaxClassIds')->willReturnSelf();
        $qstRule->method('setProductTaxClassIds')->willReturnSelf();
        $qstRule->method('setTaxRateIds')->willReturnSelf();
        $qstRule->method('setCalculateSubtotal')->willReturnSelf();

        $this->taxRuleFactory->method('create')->willReturnOnConsecutiveCalls($gstRule, $qstRule);
        $this->taxRuleRepository->method('save')->willReturnOnConsecutiveCalls($gstRule, $qstRule);

        return [$gstRule, $qstRule];
    }
}
