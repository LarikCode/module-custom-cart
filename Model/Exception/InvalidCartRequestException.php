<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown for any client-input validation failure on the custom cart page's
 * endpoints (item not found/not owned by this quote, invalid quantity,
 * invalid comment text). Controllers catch this specifically and return a
 * 400 JSON response with the message -- safe to show, since these messages
 * never contain anything beyond what the client already told us. Anything
 * else (a genuine server-side failure) is caught separately as a 500 with
 * a generic message, never a stack trace.
 */
class InvalidCartRequestException extends LocalizedException
{
}
