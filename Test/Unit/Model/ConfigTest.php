<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Test\Unit\Model;

use Ivanchenko\CustomCart\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testIsCustomCartPrimaryReturnsTrueWhenFlagIsSet(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('customcart/general/primary_cart', ScopeInterface::SCOPE_WEBSITE)
            ->willReturn(true);

        $config = new Config($scopeConfig);

        $this->assertTrue($config->isCustomCartPrimary());
    }

    public function testIsCustomCartPrimaryReturnsFalseWhenFlagIsNotSet(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $config = new Config($scopeConfig);

        $this->assertFalse($config->isCustomCartPrimary());
    }
}
