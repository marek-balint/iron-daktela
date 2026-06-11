<?php
/**
 * Daktela ticket prioritization (Claude AI) — PrestaShop 8.1 module.
 *
 * Pulls Daktela tickets, enriches with e-shop order data, classifies them with
 * an LLM (Anthropic Claude = documented target; Groq supported for now) and ranks
 * them by business priority. See .claude/docs/ for the full spec.
 *
 * Security posture is summarised per area in docs/06; the admin controller
 * enforces auth/permission/CSRF, secrets live in Configuration/.env (never
 * logged), and all DB access is escaped/parameterised.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

use Daktela\Support\ModuleConfig;

class Daktela extends Module
{
    public function __construct()
    {
        $this->name = 'daktela';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'E-shop';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Daktela ticket prioritization (Claude AI)', [], 'Modules.Daktela.Admin');
        $this->description = $this->trans(
            'Pulls Daktela tickets, enriches them with order data, classifies them with AI and ranks them by priority.',
            [],
            'Modules.Daktela.Admin'
        );
        $this->confirmUninstall = $this->trans('Remove all Daktela module data (tickets, scores)?', [], 'Modules.Daktela.Admin');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('displayBackOfficeHeader')
            && $this->runSqlFile('install.sql')
            && $this->installTab()
            && $this->installDefaults();
    }

    public function uninstall(): bool
    {
        return $this->uninstallTab()
            && $this->runSqlFile('uninstall.sql')
            && $this->deleteConfig()
            && parent::uninstall();
    }

    // --- Tab (back-office menu entry, gated by employee permissions) ----------

    private function installTab(): bool
    {
        if (Tab::getIdFromClassName('AdminDaktelaTickets')) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminDaktelaTickets';
        $tab->module = $this->name;
        $tab->active = 1;
        $tab->icon = 'support_agent';
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentCustomer');
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Daktela tickets';
        }
        return (bool) $tab->add();
    }

    private function uninstallTab(): bool
    {
        $id = (int) Tab::getIdFromClassName('AdminDaktelaTickets');
        if (!$id) {
            return true;
        }
        $tab = new Tab($id);
        return (bool) $tab->delete();
    }

    // --- SQL / config lifecycle ---------------------------------------------

    private function runSqlFile(string $file): bool
    {
        $path = __DIR__ . '/sql/' . $file;
        if (!is_readable($path)) {
            return false;
        }
        $sql = (string) file_get_contents($path);
        $sql = str_replace(['PREFIX_', 'ENGINE_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_], $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            if (!Db::getInstance()->execute($statement)) {
                return false;
            }
        }
        return true;
    }

    private function installDefaults(): bool
    {
        foreach (ModuleConfig::DEFAULTS as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        return true;
    }

    private function deleteConfig(): bool
    {
        foreach (ModuleConfig::OWNED_KEYS as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    // --- Assets --------------------------------------------------------------

    public function hookDisplayBackOfficeHeader(): void
    {
        if (Tools::getValue('configure') === $this->name
            || Tools::getValue('controller') === 'AdminDaktelaTickets') {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    // --- Configuration page --------------------------------------------------

    public function getContent(): string
    {
        $output = '';
        if (Tools::isSubmit('submitDaktelaConfig')) {
            $output .= $this->processConfig();
        }
        return $output . $this->renderConfigForm() . $this->renderLinks();
    }

    private function processConfig(): string
    {
        $errors = [];

        // Whitelisted scalar settings.
        $provider = Tools::getValue('DAKTELA_AI_PROVIDER');
        if (!in_array($provider, ['anthropic', 'groq'], true)) {
            $errors[] = $this->trans('Invalid AI provider.', [], 'Modules.Daktela.Admin');
        }
        $scoreMode = Tools::getValue('DAKTELA_SCORE_MODE');
        if (!in_array($scoreMode, ['ai', 'manual', 'ai_assisted'], true)) {
            $errors[] = $this->trans('Invalid score mode.', [], 'Modules.Daktela.Admin');
        }
        $baseUrl = trim((string) Tools::getValue('DAKTELA_BASE_URL'));
        if ($baseUrl !== '' && (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !str_starts_with($baseUrl, 'https://'))) {
            $errors[] = $this->trans('Daktela base URL must be a valid https:// URL.', [], 'Modules.Daktela.Admin');
        }

        if ($errors) {
            return $this->displayError(implode('<br>', array_map('htmlspecialchars', $errors)));
        }

        Configuration::updateValue('DAKTELA_AI_PROVIDER', $provider);
        Configuration::updateValue('DAKTELA_SCORE_MODE', $scoreMode);
        Configuration::updateValue('DAKTELA_BASE_URL', $baseUrl);
        Configuration::updateValue('DAKTELA_USE_MOCK', (int) (bool) Tools::getValue('DAKTELA_USE_MOCK'));
        Configuration::updateValue('DAKTELA_GROUNDING', (int) (bool) Tools::getValue('DAKTELA_GROUNDING'));
        Configuration::updateValue('DAKTELA_SYNC_MAX', max(1, (int) Tools::getValue('DAKTELA_SYNC_MAX')));

        foreach (['GROQ_MODEL_TIER1', 'GROQ_MODEL_TIER2', 'ANTHROPIC_MODEL_TIER1', 'ANTHROPIC_MODEL_TIER2'] as $k) {
            Configuration::updateValue($k, $this->sanitizeModel((string) Tools::getValue($k)));
        }
        foreach (['DAKTELA_W_SLA', 'DAKTELA_W_VALUE', 'DAKTELA_W_SENTIMENT', 'DAKTELA_W_CATEGORY', 'DAKTELA_W_URGENCY'] as $k) {
            $v = (float) str_replace(',', '.', (string) Tools::getValue($k));
            Configuration::updateValue($k, (string) max(0.0, min(1.0, $v)));
        }

        // Secrets: write-only — only update when a new value is supplied (docs/06 §3).
        foreach (['GROQ_API_KEY', 'ANTHROPIC_API_KEY', 'DAKTELA_ACCESS_TOKEN'] as $secret) {
            $val = (string) Tools::getValue($secret);
            if (trim($val) !== '') {
                Configuration::updateValue($secret, trim($val));
            }
        }

        return $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Daktela.Admin'));
    }

    private function sanitizeModel(string $v): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._\-]/', '', $v), 0, 64);
    }

    private function renderConfigForm(): string
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitDaktelaConfig';

        // Secret fields are intentionally never pre-filled.
        $helper->fields_value = [
            'DAKTELA_AI_PROVIDER' => ModuleConfig::get('DAKTELA_AI_PROVIDER'),
            'DAKTELA_USE_MOCK' => ModuleConfig::bool('DAKTELA_USE_MOCK'),
            'DAKTELA_BASE_URL' => ModuleConfig::get('DAKTELA_BASE_URL'),
            'DAKTELA_ACCESS_TOKEN' => '',
            'GROQ_API_KEY' => '',
            'GROQ_MODEL_TIER1' => ModuleConfig::get('GROQ_MODEL_TIER1'),
            'GROQ_MODEL_TIER2' => ModuleConfig::get('GROQ_MODEL_TIER2'),
            'ANTHROPIC_API_KEY' => '',
            'ANTHROPIC_MODEL_TIER1' => ModuleConfig::get('ANTHROPIC_MODEL_TIER1'),
            'ANTHROPIC_MODEL_TIER2' => ModuleConfig::get('ANTHROPIC_MODEL_TIER2'),
            'DAKTELA_SCORE_MODE' => ModuleConfig::get('DAKTELA_SCORE_MODE'),
            'DAKTELA_W_SLA' => ModuleConfig::get('DAKTELA_W_SLA'),
            'DAKTELA_W_VALUE' => ModuleConfig::get('DAKTELA_W_VALUE'),
            'DAKTELA_W_SENTIMENT' => ModuleConfig::get('DAKTELA_W_SENTIMENT'),
            'DAKTELA_W_CATEGORY' => ModuleConfig::get('DAKTELA_W_CATEGORY'),
            'DAKTELA_W_URGENCY' => ModuleConfig::get('DAKTELA_W_URGENCY'),
            'DAKTELA_SYNC_MAX' => ModuleConfig::get('DAKTELA_SYNC_MAX'),
            'DAKTELA_GROUNDING' => ModuleConfig::bool('DAKTELA_GROUNDING'),
        ];

        return $helper->generateForm([['form' => $this->formStructure()]]);
    }

    /** @return array<string,mixed> */
    private function formStructure(): array
    {
        $secretSet = static fn (string $k): string => ModuleConfig::has($k) ? ' (configured)' : ' (not set)';

        return [
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-cogs'],
            'input' => [
                $this->switchField('DAKTELA_USE_MOCK', $this->trans('Use mock Daktela data', [], 'Modules.Daktela.Admin'),
                    $this->trans('Replay tests/fixtures instead of calling the live API (dev).', [], 'Modules.Daktela.Admin')),
                $this->textField('DAKTELA_BASE_URL', $this->trans('Daktela base URL', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('base_url')),
                $this->passwordField('DAKTELA_ACCESS_TOKEN', $this->trans('Daktela access token', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('access_token') . $secretSet('DAKTELA_ACCESS_TOKEN')),
                $this->selectField('DAKTELA_AI_PROVIDER', $this->trans('AI provider', [], 'Modules.Daktela.Admin'),
                    [['id' => 'anthropic', 'name' => 'Anthropic (Claude)'], ['id' => 'groq', 'name' => 'Groq']],
                    $this->trans('Anthropic is the documented target; Groq is supported for now.', [], 'Modules.Daktela.Admin')),
                $this->passwordField('ANTHROPIC_API_KEY', $this->trans('Anthropic API key', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('anthropic_key') . $secretSet('ANTHROPIC_API_KEY')),
                $this->textField('ANTHROPIC_MODEL_TIER1', $this->trans('Anthropic model — Tier 1', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('model')),
                $this->textField('ANTHROPIC_MODEL_TIER2', $this->trans('Anthropic model — Tier 2', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('model')),
                $this->passwordField('GROQ_API_KEY', $this->trans('Groq API key', [], 'Modules.Daktela.Admin'),
                    $this->trans('Key for Groq; used to classify tickets. Kept server-side only.', [], 'Modules.Daktela.Admin') . $secretSet('GROQ_API_KEY')),
                $this->textField('GROQ_MODEL_TIER1', $this->trans('Groq model — Tier 1', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('model')),
                $this->textField('GROQ_MODEL_TIER2', $this->trans('Groq model — Tier 2', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('model')),
                $this->selectField('DAKTELA_SCORE_MODE', $this->trans('Score mode', [], 'Modules.Daktela.Admin'),
                    [
                        ['id' => 'ai', 'name' => $this->trans('AI (automatic)', [], 'Modules.Daktela.Admin')],
                        ['id' => 'manual', 'name' => $this->trans('Manual', [], 'Modules.Daktela.Admin')],
                        ['id' => 'ai_assisted', 'name' => $this->trans('AI-assisted', [], 'Modules.Daktela.Admin')],
                    ],
                    $this->tooltip('score_mode')),
                $this->textField('DAKTELA_W_SLA', $this->trans('Weight: waiting / SLA', [], 'Modules.Daktela.Admin'), $this->tooltip('weights')),
                $this->textField('DAKTELA_W_VALUE', $this->trans('Weight: customer value', [], 'Modules.Daktela.Admin'), $this->tooltip('weights')),
                $this->textField('DAKTELA_W_SENTIMENT', $this->trans('Weight: sentiment', [], 'Modules.Daktela.Admin'), $this->tooltip('weights')),
                $this->textField('DAKTELA_W_CATEGORY', $this->trans('Weight: category', [], 'Modules.Daktela.Admin'), $this->tooltip('weights')),
                $this->textField('DAKTELA_W_URGENCY', $this->trans('Weight: urgency', [], 'Modules.Daktela.Admin'), $this->tooltip('weights')),
                $this->textField('DAKTELA_SYNC_MAX', $this->trans('Max tickets per sync', [], 'Modules.Daktela.Admin'),
                    $this->trans('Upper bound of tickets processed in one run.', [], 'Modules.Daktela.Admin')),
                $this->switchField('DAKTELA_GROUNDING', $this->trans('Generate grounded drafts', [], 'Modules.Daktela.Admin'),
                    $this->tooltip('grounding')),
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Modules.Daktela.Admin')],
        ];
    }

    private function textField(string $name, string $label, string $desc): array
    {
        return ['type' => 'text', 'label' => $label, 'name' => $name, 'desc' => $desc];
    }

    private function passwordField(string $name, string $label, string $desc): array
    {
        return ['type' => 'password', 'label' => $label, 'name' => $name, 'desc' => $desc];
    }

    private function selectField(string $name, string $label, array $options, string $desc): array
    {
        return [
            'type' => 'select', 'label' => $label, 'name' => $name, 'desc' => $desc,
            'options' => ['query' => $options, 'id' => 'id', 'name' => 'name'],
        ];
    }

    private function switchField(string $name, string $label, string $desc): array
    {
        return [
            'type' => 'switch', 'label' => $label, 'name' => $name, 'desc' => $desc, 'is_bool' => true,
            'values' => [
                ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.Daktela.Admin')],
                ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Daktela.Admin')],
            ],
        ];
    }

    /**
     * One-sentence help bubbles in EN/SK (docs/02). Resolved from the employee's
     * language so the template only ever receives an already-localised string.
     * (Can be migrated to the $this->trans() catalog later; kept here so SK works
     * out of the box without shipping a translation export.)
     */
    private function tooltip(string $key): string
    {
        $iso = strtolower((string) ($this->context->language->iso_code ?? 'en'));
        $t = [
            'base_url' => [
                'en' => 'Your Daktela instance URL — the API is served from https://<instance>.daktela.com/api/v6/, not daktela.com.',
                'sk' => 'URL vašej Daktela inštancie — API beží na https://<instancia>.daktela.com/api/v6/, nie na daktela.com.',
            ],
            'access_token' => [
                'en' => 'Long-lived token of a read-only API user; pulls tickets, activities and contacts. Secret, never shown in logs.',
                'sk' => 'Trvalý token API používateľa len na čítanie; sťahuje tickety, aktivity a kontakty. Tajný, nikdy sa nezobrazuje v logoch.',
            ],
            'anthropic_key' => [
                'en' => 'Key for Claude, used to classify tickets (category, urgency, sentiment). Billed per use; kept server-side only.',
                'sk' => 'Kľúč pre Claude na klasifikáciu ticketov (kategória, naliehavosť, nálada). Účtuje sa za použitie; len na serveri.',
            ],
            'model' => [
                'en' => 'Which model classifies tickets — a cheap/fast one for simple cases, a stronger one for complex tickets.',
                'sk' => 'Ktorý model klasifikuje tickety — lacný/rýchly pre jednoduché, silnejší pre zložité prípady.',
            ],
            'score_mode' => [
                'en' => 'How priority is set: AI computes it, Manual uses a human value, AI-assisted lets an agent adjust the AI score.',
                'sk' => 'Ako sa určuje priorita: AI ju počíta, Manuálne používa hodnotu človeka, AI s asistenciou umožní agentovi upraviť AI skóre.',
            ],
            'weights' => [
                'en' => 'How much each factor (waiting, value, sentiment, category, urgency) influences the final priority. Tune without code.',
                'sk' => 'Ako veľmi každý faktor (čakanie, hodnota, nálada, kategória, naliehavosť) ovplyvňuje prioritu. Ladí sa bez zásahu do kódu.',
            ],
            'grounding' => [
                'en' => 'For simple product questions, draft a reply grounded in catalog data for an agent to review. Never auto-sent.',
                'sk' => 'Pri jednoduchých otázkach o produkte navrhne odpoveď z údajov katalógu na kontrolu agentom. Nikdy sa neodošle automaticky.',
            ],
        ];
        return $t[$key][$iso] ?? $t[$key]['en'] ?? '';
    }

    private function renderLinks(): string
    {
        $url = $this->context->link->getAdminLink('AdminDaktelaTickets');
        $label = $this->trans('Open prioritized tickets', [], 'Modules.Daktela.Admin');
        return '<div class="panel"><a class="btn btn-default" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<i class="icon-list"></i> ' . htmlspecialchars($label, ENT_QUOTES) . '</a></div>';
    }
}
