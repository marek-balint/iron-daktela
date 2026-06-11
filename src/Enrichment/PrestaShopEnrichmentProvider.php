<?php
/**
 * Read-only enrichment from the existing PrestaShop DB (docs/02 §3).
 *
 * Security (docs/06 §1): every external value is cast (int) or escaped (pSQL),
 * never concatenated raw. LIKE terms — built from AI key_phrases, i.e. model
 * output derived from customer text — are escaped with pSQL($v, true). No writes
 * to core tables.
 */

declare(strict_types=1);

namespace Daktela\Enrichment;

use Daktela\Dto\Ticket;
use Daktela\Support\ModuleConfig;

final class PrestaShopEnrichmentProvider implements EnrichmentProviderInterface
{
    public function enrichCustomer(Ticket $ticket): void
    {
        $email = strtolower(trim($ticket->contactEmail));
        if ($email === '') {
            return;
        }
        $db = \Db::getInstance();

        $idCustomer = (int) $db->getValue(
            'SELECT id_customer FROM ' . _DB_PREFIX_ . 'customer
             WHERE email = "' . pSQL($email) . '" AND deleted = 0'
        );
        if ($idCustomer <= 0) {
            return; // guest / unknown — value stays 0 (docs/03)
        }
        $ticket->idCustomer = $idCustomer;

        $ticket->orderCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders
             WHERE id_customer = ' . (int) $idCustomer . ' AND valid = 1'
        );

        // Lifetime spend in the shop's default currency.
        $ticket->totalSpend = (float) $db->getValue(
            'SELECT COALESCE(SUM(total_paid_real / NULLIF(conversion_rate,0)), 0)
             FROM ' . _DB_PREFIX_ . 'orders
             WHERE id_customer = ' . (int) $idCustomer . ' AND valid = 1'
        );

        // Open order = paid but not yet shipped/delivered (docs/05 open_order_bonus).
        $open = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders o
             INNER JOIN ' . _DB_PREFIX_ . 'order_state s ON s.id_order_state = o.current_state
             WHERE o.id_customer = ' . (int) $idCustomer . '
               AND o.valid = 1 AND s.paid = 1 AND s.shipped = 0 AND s.delivery = 0'
        );
        $ticket->hasOpenOrder = $open > 0;
    }

    public function catalogContext(Ticket $ticket): string
    {
        if (!ModuleConfig::bool('DAKTELA_GROUNDING', true)) {
            return '';
        }
        $phrases = array_filter(
            $ticket->keyPhrases,
            static fn ($p) => is_string($p) && mb_strlen(trim($p)) >= 3
        );
        if ($phrases === []) {
            return '';
        }

        $idLang = (int) (\Context::getContext()->language->id ?? \Configuration::get('PS_LANG_DEFAULT'));
        $db = \Db::getInstance();
        $found = [];

        foreach (array_slice(array_values($phrases), 0, 4) as $phrase) {
            $like = pSQL(trim($phrase), true); // like-safe escaping
            $rows = $db->executeS(
                'SELECT p.id_product, pl.name, p.price, m.name AS brand,
                        COALESCE(sa.quantity, 0) AS qty
                 FROM ' . _DB_PREFIX_ . 'product p
                 INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                        ON pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . '
                 LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                        ON m.id_manufacturer = p.id_manufacturer
                 LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa
                        ON sa.id_product = p.id_product AND sa.id_product_attribute = 0
                 WHERE pl.name LIKE "%' . $like . '%"
                 LIMIT 5'
            ) ?: [];
            foreach ($rows as $r) {
                $found[(int) $r['id_product']] = $r;
            }
            if (count($found) >= 5) {
                break;
            }
        }
        if ($found === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($found, 0, 5, true) as $id => $r) {
            $lines[] = sprintf(
                '- %s (SKU %d) — price %.2f, in stock %d%s',
                (string) $r['name'],
                (int) $id,
                (float) $r['price'],
                (int) $r['qty'],
                $r['brand'] ? ', brand ' . $r['brand'] : ''
            );
        }
        return "Catalog data (answer ONLY from this; if not here, say you don't know):\n"
            . implode("\n", $lines);
    }
}
