<?php
/**
 * Wires a Pipeline for the current environment:
 *   - Daktela source: mock fixtures (DAKTELA_USE_MOCK=1 or no token) or live API.
 *   - Enrichment:      PrestaShop DB when available, else null (value 0).
 *   - Store:           PrestaShop DB when available, else a JSON file under var/.
 *   - AI:              provider/tier per config (Anthropic or Groq).
 *
 * Used by both cli/sync.php (standalone) and the module (inside PrestaShop).
 */

declare(strict_types=1);

namespace Daktela\Service;

use Daktela\Daktela\DaktelaClient;
use Daktela\Daktela\DaktelaClientInterface;
use Daktela\Daktela\MockDaktelaClient;
use Daktela\Enrichment\EnrichmentProviderInterface;
use Daktela\Enrichment\NullEnrichmentProvider;
use Daktela\Enrichment\PrestaShopEnrichmentProvider;
use Daktela\Llm\LlmClientFactory;
use Daktela\Store\DbTicketStore;
use Daktela\Store\JsonFileTicketStore;
use Daktela\Store\TicketStoreInterface;
use Daktela\Support\ModuleConfig;

final class PipelineFactory
{
    public static function create(): Pipeline
    {
        return new Pipeline(
            self::daktela(),
            self::enrichment(),
            new ClassificationService(LlmClientFactory::tier1(), LlmClientFactory::tier2()),
            new PriorityService(),
            self::store()
        );
    }

    public static function inPrestaShop(): bool
    {
        return class_exists(\Db::class) && class_exists(\Configuration::class) && defined('_DB_PREFIX_');
    }

    public static function daktela(): DaktelaClientInterface
    {
        $useMock = ModuleConfig::bool('DAKTELA_USE_MOCK', true)
            || !ModuleConfig::has('DAKTELA_ACCESS_TOKEN')
            || !ModuleConfig::has('DAKTELA_BASE_URL');

        if ($useMock) {
            return new MockDaktelaClient(ModuleConfig::moduleRoot() . '/tests/fixtures');
        }
        return new DaktelaClient(
            ModuleConfig::get('DAKTELA_BASE_URL'),
            ModuleConfig::get('DAKTELA_ACCESS_TOKEN')
        );
    }

    private static function enrichment(): EnrichmentProviderInterface
    {
        return self::inPrestaShop()
            ? new PrestaShopEnrichmentProvider()
            : new NullEnrichmentProvider();
    }

    private static function store(): TicketStoreInterface
    {
        return self::inPrestaShop()
            ? new DbTicketStore()
            : new JsonFileTicketStore(ModuleConfig::moduleRoot() . '/var/daktela-store.json');
    }
}
