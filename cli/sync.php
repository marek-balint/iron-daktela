<?php
/**
 * CLI / cron entrypoint for one sync run (docs/02 scheduling, docs/03 sync).
 *
 * Runs standalone (no PrestaShop) — pulls mock tickets, classifies with the
 * configured provider (Groq right now), scores, and prints a ranked inbox. When
 * executed inside a PrestaShop install it transparently uses the DB store +
 * order-data enrichment.
 *
 * Security (docs/06 §4): refuses to run over HTTP. This must never be reachable
 * from a browser — it spends API budget.
 *
 * Usage:
 *   php cli/sync.php                 # one run (uses .env / Configuration)
 *   php cli/sync.php --max=50        # cap tickets this run
 *   php cli/sync.php --force         # ignore watermark, reprocess everything
 *   php cli/sync.php --json          # print ranked result as JSON
 *   php cli/sync.php --list-models   # (Groq) list models your key can use
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../autoload.php';

use Daktela\Llm\GroqClient;
use Daktela\Llm\LlmClientFactory;
use Daktela\Service\PipelineFactory;
use Daktela\Support\ModuleConfig;

$opts = parseArgs($argv);
if (isset($opts['help'])) {
    fwrite(STDOUT, helpText());
    exit(0);
}

if (isset($opts['list-models'])) {
    exit(listModels());
}

$provider = LlmClientFactory::provider();
if (!LlmClientFactory::isConfigured()) {
    fwrite(STDERR, "ERROR: no API key for provider '{$provider}'.\n"
        . "Set " . strtoupper($provider) . "_API_KEY in .env (copy .env.example).\n");
    exit(2);
}

$max = isset($opts['max']) ? max(1, (int) $opts['max']) : ModuleConfig::int('DAKTELA_SYNC_MAX', 100);
$force = isset($opts['force']);

$pipeline = PipelineFactory::create();
$source = PipelineFactory::daktela()->label();

fwrite(STDOUT, "Daktela sync — source: {$source} | AI: {$provider} | mode: "
    . ModuleConfig::get('DAKTELA_SCORE_MODE', 'ai') . ($force ? ' | FORCE' : '') . "\n");

try {
    $result = $pipeline->run($max, $force);
} catch (Throwable $e) {
    fwrite(STDERR, 'Run failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (isset($opts['json'])) {
    $rows = array_map(static fn ($t) => $t->toArray(), $result['tickets']);
    fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

printTable($result['tickets']);
fwrite(STDOUT, sprintf(
    "\nPulled %d | classified %d | failed %d\n",
    $result['pulled'],
    $result['classified'],
    $result['failed']
));
exit(0);

// ---------------------------------------------------------------------------

/** @return array<string,string|bool> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $kv = substr($arg, 2);
            if (str_contains($kv, '=')) {
                [$k, $v] = explode('=', $kv, 2);
                $out[$k] = $v;
            } else {
                $out[$kv] = true;
            }
        }
    }
    return $out;
}

function listModels(): int
{
    if (LlmClientFactory::provider() !== 'groq') {
        fwrite(STDERR, "--list-models is implemented for the Groq provider only.\n");
        return 2;
    }
    $client = new GroqClient(
        ModuleConfig::get('GROQ_API_KEY'),
        ModuleConfig::get('GROQ_MODEL_TIER2'),
        ModuleConfig::get('GROQ_BASE_URL')
    );
    try {
        foreach ($client->listModels() as $id) {
            fwrite(STDOUT, $id . "\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Could not list models: ' . $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

/** @param \Daktela\Dto\Ticket[] $tickets */
function printTable(array $tickets): void
{
    if ($tickets === []) {
        fwrite(STDOUT, "No new tickets.\n");
        return;
    }
    $fmt = "%-4s %-5s %-16s %-11s %-8s %-7s %-6s %s\n";
    fwrite(STDOUT, "\n" . sprintf($fmt, '#', 'SCORE', 'CATEGORY', 'SENTIMENT', 'URGENCY', 'WAIT', 'VALUE', 'SUMMARY'));
    fwrite(STDOUT, str_repeat('-', 100) . "\n");
    $i = 1;
    foreach ($tickets as $t) {
        fwrite(STDOUT, sprintf(
            $fmt,
            $i++,
            (string) $t->effectiveScore,
            substr($t->category, 0, 16),
            $t->sentiment,
            $t->urgency,
            $t->waiting ? humanWait($t->waitSeconds) : '-',
            (string) $t->compValue,
            mbTrunc(($t->flagged ? '[flag] ' : '') . ($t->summary !== '' ? $t->summary : $t->title), 48)
        ));
        if ($t->suggestedAnswer !== null && $t->answerGrounded && !$t->needsHuman) {
            fwrite(STDOUT, '      draft: ' . mbTrunc($t->suggestedAnswer, 90) . "\n");
        }
    }
}

function humanWait(int $s): string
{
    if ($s >= 86400) {
        return intdiv($s, 86400) . 'd';
    }
    if ($s >= 3600) {
        return intdiv($s, 3600) . 'h';
    }
    return max(1, intdiv($s, 60)) . 'm';
}

function mbTrunc(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

function helpText(): string
{
    return "Daktela sync\n\n"
        . "  php cli/sync.php [--max=N] [--force] [--json] [--list-models] [--help]\n\n"
        . "Config comes from .env (copy .env.example) or PrestaShop Configuration.\n";
}
