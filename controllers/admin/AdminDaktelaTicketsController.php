<?php
/**
 * Back-office "prioritized tickets" view + manual-score override + run-sync.
 *
 * Security (docs/06 §4): extends ModuleAdminController, so it inherits employee
 * authentication, Tab permission checks and CSRF token validation. State-changing
 * actions (manual score, run sync) flow through postProcess and validate input
 * server-side. Customer/AI text is escaped on output in the template (no |raw).
 * Exceptions are logged server-side; the browser sees a generic message (§3).
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'daktela/autoload.php';

use Daktela\Service\PipelineFactory;
use Daktela\Store\DbTicketStore;
use Daktela\Support\Logger;
use Daktela\Support\ModuleConfig;

class AdminDaktelaTicketsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        // Guarantee a module instance for ->display()/->l() (docs/06 §4 controller).
        if (!($this->module instanceof Module)) {
            $this->module = Module::getInstanceByName('daktela');
        }
    }

    public function postProcess()
    {
        // ModuleAdminController has already validated the CSRF token for this request.
        if (Tools::isSubmit('submitManualScore')) {
            $this->processManualScore();
        } elseif (Tools::isSubmit('submitRunSync')) {
            $this->processRunSync();
        }

        return parent::postProcess();
    }

    private function processManualScore(): void
    {
        $id = (int) Tools::getValue('id_daktela_ticket');
        $raw = Tools::getValue('manual_score');

        if ($id <= 0 || !is_numeric($raw)) {
            $this->errors[] = $this->module->l('Invalid manual score.');
            return;
        }
        $score = max(0, min(100, (int) $raw)); // clamp server-side (docs/05/06)

        $employee = $this->context->employee;
        $by = trim(($employee->firstname ?? '') . ' ' . ($employee->lastname ?? ''))
            . ' (#' . (int) $employee->id . ')';

        if ((new DbTicketStore())->setManualScore($id, $score, $by)) {
            $this->confirmations[] = $this->module->l('Manual priority saved.');
        } else {
            $this->errors[] = $this->module->l('Could not save manual priority.');
        }
    }

    private function processRunSync(): void
    {
        try {
            $result = PipelineFactory::create()->run(ModuleConfig::int('DAKTELA_SYNC_MAX', 100));
            $this->confirmations[] = sprintf(
                $this->module->l('Sync done — pulled %1$d, classified %2$d, failed %3$d.'),
                $result['pulled'],
                $result['classified'],
                $result['failed']
            );
        } catch (\Throwable $e) {
            // Log details server-side, show a generic message (docs/06 §3).
            Logger::error('Run-sync failed: ' . $e->getMessage());
            $this->errors[] = $this->module->l('Sync failed. Check the logs for details.');
        }
    }

    public function initContent()
    {
        parent::initContent();

        try {
            $rows = (new DbTicketStore())->fetchRanked(200);
        } catch (\Throwable $e) {
            // A missing-table / DB error must not 500 the page (docs/06 §3).
            Logger::error('Loading tickets failed: ' . $e->getMessage());
            $rows = [];
            $this->warnings[] = $this->module->l('Could not load tickets — is the module installed (tables created)?');
        }

        $this->context->smarty->assign([
            'daktela_rows' => $rows,
            'daktela_token' => $this->token,
            'daktela_index' => self::$currentIndex,
            'run_sync_url' => self::$currentIndex . '&submitRunSync=1&token=' . $this->token,
            'score_mode' => ModuleConfig::get('DAKTELA_SCORE_MODE', 'ai'),
            'manual_enabled' => in_array(ModuleConfig::get('DAKTELA_SCORE_MODE', 'ai'), ['manual', 'ai_assisted'], true),
            'tip' => $this->tooltips(),
        ]);

        // Render through Module::display(): PrestaShop resolves the module
        // template dir and its Smarty security policy allows it. A raw
        // smarty->fetch() of an absolute path is rejected in the back office
        // (SmartyException) — that was the 500.
        $this->content .= $this->module->display(
            _PS_MODULE_DIR_ . 'daktela/daktela.php',
            'views/templates/admin/tickets.tpl'
        );
        $this->context->smarty->assign('content', $this->content);
    }

    /** EN/SK column help (docs/02), resolved to the employee's language. */
    private function tooltips(): array
    {
        $iso = strtolower((string) ($this->context->language->iso_code ?? 'en'));
        $t = [
            'priority' => [
                'en' => '0–100 ranking of how urgently this ticket should be answered; higher = answer first.',
                'sk' => 'Hodnotenie 0–100, ako súrne treba odpovedať; vyššie = odpovedať skôr.',
            ],
            'category' => [
                'en' => 'What the ticket is about, detected by AI from the message text.',
                'sk' => 'O čom ticket je, rozpoznané AI z textu správy.',
            ],
            'sentiment' => [
                'en' => "The customer's mood detected by AI; angrier raises priority.",
                'sk' => 'Nálada zákazníka rozpoznaná AI; nahnevanejší zvyšuje prioritu.',
            ],
            'value' => [
                'en' => 'How valuable this customer is (orders, lifetime spend, open order).',
                'sk' => 'Aký hodnotný je zákazník (objednávky, útrata, otvorená objednávka).',
            ],
            'waiting' => [
                'en' => 'Whether the ticket is still waiting for an agent reply and for how long.',
                'sk' => 'Či ticket stále čaká na odpoveď agenta a ako dlho.',
            ],
            'manual' => [
                'en' => 'Override the AI priority with your own 0–100 value; logged with your name.',
                'sk' => 'Prepíšte AI prioritu vlastnou hodnotou 0–100; zaznamená sa s vaším menom.',
            ],
            'draft' => [
                'en' => 'AI-written reply grounded in catalog data — review and edit before sending. Never sent automatically.',
                'sk' => 'Návrh odpovede od AI z údajov katalógu — pred odoslaním skontrolujte. Nikdy sa neodošle automaticky.',
            ],
        ];
        $out = [];
        foreach ($t as $k => $pair) {
            $out[$k] = $pair[$iso] ?? $pair['en'];
        }
        return $out;
    }
}
