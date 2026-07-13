<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Plugin\Quote\Item;

use Ivanchenko\CustomCart\Plugin\Quote\Item\UpdaterPlugin;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Updater;
use PHPUnit\Framework\TestCase;

/**
 * Covers the cart quantity-update validation guard.
 *
 * Updater::update()'s real signature takes array $info (not a DataObject
 * buy request, despite that being the more common convention elsewhere
 * in Quote) -- these tests exercise the plugin against that real shape.
 */
class UpdaterPluginTest extends TestCase
{
    private UpdaterPlugin $plugin;
    private Updater $updater;

    protected function setUp(): void
    {
        $this->plugin = new UpdaterPlugin();
        $this->updater = $this->createStub(Updater::class);
    }

    public function testAllowsAQuantityAtTheMaximum(): void
    {
        $item = $this->createStub(Item::class);

        // Should not throw.
        $this->plugin->beforeUpdate($this->updater, $item, ['qty' => 100]);
        $this->addToAssertionCount(1);
    }

    public function testAllowsAnOrdinaryQuantity(): void
    {
        $item = $this->createStub(Item::class);

        $this->plugin->beforeUpdate($this->updater, $item, ['qty' => 3]);
        $this->addToAssertionCount(1);
    }

    public function testAllowsAMissingQuantity(): void
    {
        $item = $this->createStub(Item::class);

        $this->plugin->beforeUpdate($this->updater, $item, []);
        $this->addToAssertionCount(1);
    }

    public function testRejectsAQuantityOverTheMaximumWithAClearMessage(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Test Product');

        $item = $this->createStub(Item::class);
        $item->method('getProduct')->willReturn($product);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The requested quantity (101) for "Test Product" is more than the maximum allowed per order (100).'
            . ' Please contact us for bulk orders.'
        );

        $this->plugin->beforeUpdate($this->updater, $item, ['qty' => 101]);
    }

    public function testFallsBackToItemNameWhenProductIsUnavailable(): void
    {
        $item = $this->createStub(Item::class);
        $item->method('getProduct')->willReturn(null);
        $item->method('getName')->willReturn('Orphaned Item');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The requested quantity (250) for "Orphaned Item" is more than the maximum allowed per order (100).'
            . ' Please contact us for bulk orders.'
        );

        $this->plugin->beforeUpdate($this->updater, $item, ['qty' => 250]);
    }
}
