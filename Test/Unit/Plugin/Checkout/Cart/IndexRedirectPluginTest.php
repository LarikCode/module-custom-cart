<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Plugin\Checkout\Cart;

use Ivanchenko\CustomCart\Model\Config;
use Ivanchenko\CustomCart\Plugin\Checkout\Cart\IndexRedirectPlugin;
use Magento\Checkout\Controller\Cart\Index as CartIndex;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use PHPUnit\Framework\TestCase;

class IndexRedirectPluginTest extends TestCase
{
    private Config $config;
    private RedirectFactory $redirectFactory;
    private IndexRedirectPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->redirectFactory = $this->createStub(RedirectFactory::class);

        $this->plugin = new IndexRedirectPlugin($this->config, $this->redirectFactory);
    }

    public function testRedirectsToCustomCartWhenToggleIsEnabled(): void
    {
        $this->config->method('isCustomCartPrimary')->willReturn(true);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('customcart/cart/index')
            ->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($redirect);

        $subject = $this->createStub(CartIndex::class);
        $proceed = function () {
            $this->fail('proceed() should not be called when the toggle is enabled.');
        };

        $result = $this->plugin->aroundExecute($subject, $proceed);

        $this->assertSame($redirect, $result);
    }

    public function testCallsProceedWhenToggleIsDisabled(): void
    {
        $this->config->method('isCustomCartPrimary')->willReturn(false);

        $originalResult = $this->createStub(Page::class);
        $subject = $this->createStub(CartIndex::class);
        $proceedCalled = false;
        $proceed = function () use ($originalResult, &$proceedCalled): ResultInterface {
            $proceedCalled = true;
            return $originalResult;
        };

        $result = $this->plugin->aroundExecute($subject, $proceed);

        $this->assertTrue($proceedCalled);
        $this->assertSame($originalResult, $result);
    }
}
