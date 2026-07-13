<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Controller\Cart;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the custom cart page at /customcart/cart. No business logic here
 * -- ViewModel\Cart\Index reads the quote itself at render time via
 * CartService, so this controller only needs to build the page.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory
    ) {
    }

    public function execute(): Page
    {
        return $this->pageFactory->create();
    }
}
