<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Plugin\Checkout\Cart;

use Ivanchenko\CustomCart\Model\Config;
use Ivanchenko\CustomCart\Plugin\Checkout\Cart\SidebarConfigPlugin;
use Magento\Checkout\Block\Cart\Sidebar;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class SidebarConfigPluginTest extends TestCase
{
    private Config $config;
    private UrlInterface $urlBuilder;
    private SidebarConfigPlugin $plugin;
    private Sidebar $subject;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        // createMock(), not createStub() -- one test below uses ->with()
        // on getUrl(), which requires a mock.
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->subject = $this->createStub(Sidebar::class);

        $this->plugin = new SidebarConfigPlugin($this->config, $this->urlBuilder);
    }

    public function testSwapsShoppingCartUrlWhenToggleIsEnabled(): void
    {
        $this->config->method('isCustomCartPrimary')->willReturn(true);
        $this->urlBuilder->method('getUrl')->with('customcart/cart')->willReturn('https://example.test/customcart/cart/');

        $result = $this->plugin->afterGetConfig($this->subject, [
            'shoppingCartUrl' => 'https://example.test/checkout/cart/',
            'checkoutUrl' => 'https://example.test/checkout/',
        ]);

        $this->assertSame('https://example.test/customcart/cart/', $result['shoppingCartUrl']);
        // Other keys pass through untouched.
        $this->assertSame('https://example.test/checkout/', $result['checkoutUrl']);
    }

    public function testLeavesConfigUntouchedWhenToggleIsDisabled(): void
    {
        $this->config->method('isCustomCartPrimary')->willReturn(false);
        $this->urlBuilder->expects($this->never())->method('getUrl');

        $original = [
            'shoppingCartUrl' => 'https://example.test/checkout/cart/',
            'checkoutUrl' => 'https://example.test/checkout/',
        ];

        $result = $this->plugin->afterGetConfig($this->subject, $original);

        $this->assertSame($original, $result);
    }
}
