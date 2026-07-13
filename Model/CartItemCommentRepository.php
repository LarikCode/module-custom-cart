<?php
declare(strict_types=1);

namespace Ivanchenko\CustomCart\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Direct ResourceConnection/AdapterInterface access to the
 * ivanchenko_customcart_item_comment table, deliberately NOT the
 * AbstractModel/ResourceModel/Collection pattern -- that stack IS Magento's
 * ORM, and this module already has a separate example of extending Magento
 * via its own repository interfaces (Setup/Patch/Data/InstallCanadianTaxRates.php
 * uses TaxRateRepositoryInterface). This class exists specifically to
 * demonstrate safe *direct* DB access: every value below reaches the
 * database through a bound parameter, never through string concatenation.
 *
 * - save() uses insertOnDuplicate(), which builds an internal bind array
 *   (see Magento\Framework\DB\Adapter\Pdo\Mysql::insertOnDuplicate()) --
 *   contrast with the unsafe version this deliberately avoids:
 *   "INSERT INTO ... VALUES ('" . $commentText . "')".
 * - getByQuoteItemId() uses where('quote_item_id = ?', $quoteItemId) --
 *   the '?' is a Zend_Db placeholder substituted via quoteInto() internally,
 *   not string interpolation. Contrast: "WHERE quote_item_id = " . $quoteItemId.
 *
 * A payload like `'; DROP TABLE ivanchenko_customcart_item_comment; --` or
 * `<script>alert(1)</script>` passed as $commentText is therefore always
 * bound as an opaque data value -- there is no code path here where it is
 * ever parsed as SQL syntax.
 */
class CartItemCommentRepository
{
    private const TABLE = 'ivanchenko_customcart_item_comment';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return string|null The comment text, or null if no comment exists for this item.
     */
    public function getByQuoteItemId(int $quoteItemId): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['comment_text'])
            ->where('quote_item_id = ?', $quoteItemId);

        $result = $connection->fetchOne($select);

        // fetchOne() returns false (Zend_Db's "no row" sentinel), not an
        // empty string, when nothing matches -- must check explicitly so
        // "no comment yet" isn't confused with a real empty-string comment.
        return $result === false ? null : (string)$result;
    }

    /**
     * Upserts the comment for a quote item. Requires the UNIQUE constraint
     * on quote_item_id (see etc/db_schema.xml) -- that's what makes
     * insertOnDuplicate() correctly update the existing row instead of
     * violating uniqueness, giving "one editable comment per item" as an
     * atomic operation rather than a separate select-then-insert-or-update.
     */
    public function save(int $quoteItemId, string $commentText): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['quote_item_id' => $quoteItemId, 'comment_text' => $commentText],
            ['comment_text']
        );
    }
}
