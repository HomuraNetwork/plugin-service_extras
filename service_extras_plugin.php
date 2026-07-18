<?php

use Blesta\Core\Util\Input\Fields\Html as FieldsHtml;
use Blesta\Core\Util\PackageOptions\Logic as OptionLogic;

class ServiceExtrasPlugin extends Plugin
{
    public function __construct()
    {
        Language::loadLang('service_extras_plugin', null, dirname(__FILE__) . DS . 'language' . DS);
        Loader::loadComponents($this, ['Input', 'Record']);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    public function install($plugin_id)
    {
        try {
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('name', ['type' => 'varchar', 'size' => 255])
                ->setField('capability', ['type' => 'varchar', 'size' => 128])
                ->setField('parent_package_ids', ['type' => 'text'])
                ->setField('parent_group_ids', ['type' => 'text'])
                ->setField('product_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField(
                    'required_option_name',
                    ['type' => 'varchar', 'size' => 128, 'is_null' => true, 'default' => null]
                )
                ->setField('required_option_values', ['type' => 'text'])
                ->setField('enabled', ['type' => 'char', 'size' => 1, 'default' => '1'])
                ->setField('date_added', ['type' => 'datetime'])
                ->setField('date_updated', ['type' => 'datetime'])
                ->setKey(['id'], 'primary')
                ->setKey(['company_id', 'enabled'], 'index')
                ->create('service_extra_rules', true);
        } catch (\Throwable $e) {
            $this->Input->setErrors(['db' => ['create' => $e->getMessage()]]);
        }
    }

    public function uninstall($plugin_id, $last_instance)
    {
        if ($last_instance) {
            $this->Record->drop('service_extra_rules');
        }
    }

    public function getActions()
    {
        return [
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/service_extras/admin_main/',
                'name' => 'ServiceExtrasPlugin.nav.admin',
                'options' => ['parent' => 'packages/']
            ]
        ];
    }

    public function allowsServiceTabs()
    {
        return true;
    }

    public function __call($method, $arguments)
    {
        if (preg_match('/^tabServiceExtraRule(\d+)$/', $method, $matches)) {
            return $this->tabServiceExtra((int) $matches[1], ...$arguments);
        }

        throw new BadMethodCallException('Unknown Service Extras action: ' . $method);
    }

    private function serviceOptionValue($option)
    {
        if (isset($option->option_value) && $option->option_value !== '') {
            return (string) $option->option_value;
        }
        if (isset($option->value) && $option->value !== '') {
            return (string) $option->value;
        }
        if (isset($option->qty)) {
            return (string) $option->qty;
        }

        return '';
    }

    private function matchesRequiredOption(stdClass $service, stdClass $rule)
    {
        $required_name = trim((string) ($rule->required_option_name ?? ''));
        if ($required_name === '') {
            return true;
        }

        $allowed_values = array_map('strval', (array) ($rule->required_option_values ?? []));
        foreach (($service->options ?? []) as $option) {
            if ((string) ($option->option_name ?? '') !== $required_name) {
                continue;
            }

            return empty($allowed_values)
                || in_array($this->serviceOptionValue($option), $allowed_values, true);
        }

        return false;
    }

    private function context(stdClass $service, $rule_id = null)
    {
        Loader::loadModels($this, [
            'Packages',
            'ModuleManager',
            'Services',
            'ServiceExtras.ServiceExtraRules'
        ]);

        $service = $this->Services->get((int) ($service->id ?? 0)) ?: $service;
        $package = $this->Packages->get(($service->package ?? null)->id ?? null);
        if (!$package) {
            return null;
        }

        $rules = [];
        foreach ($this->ServiceExtraRules->getMatching($package->company_id, $package->id) as $rule) {
            if ($rule_id !== null && (int) $rule->id !== (int) $rule_id) {
                continue;
            }
            if ($this->matchesRequiredOption($service, $rule)) {
                $rules[] = $rule;
            }
        }
        if (empty($rules)) {
            return null;
        }

        $capabilities = $this->ModuleManager->moduleRpc(
            $package->module_id,
            'getServiceExtraCapabilities',
            [$package, $service],
            $service->module_row_id
        );

        return [
            'service' => $service,
            'package' => $package,
            'rules' => $rules,
            'capabilities' => is_array($capabilities) ? $capabilities : []
        ];
    }

    private function ruleIsAvailable(stdClass $rule, array $capabilities)
    {
        return array_key_exists($rule->capability, $capabilities);
    }

    public function getClientServiceTabs(stdClass $service)
    {
        if (($service->status ?? null) !== 'active') {
            return [];
        }

        $context = $this->context($service);
        if (!$context) {
            return [];
        }

        $tabs = [];
        foreach ($context['rules'] as $rule) {
            if ($this->ruleIsAvailable($rule, $context['capabilities'])) {
                $tabs['tabServiceExtraRule' . (int) $rule->id] = [
                    'name' => $rule->name,
                    'icon' => 'fas fa-plus-circle'
                ];
            }
        }

        return $tabs;
    }

    private function offerings(stdClass $rule, array $capabilities, $currency, $parent_module_id)
    {
        if (!$this->ruleIsAvailable($rule, $capabilities)) {
            return [];
        }

        $capability_info = is_array($capabilities[$rule->capability])
            ? $capabilities[$rule->capability]
            : [];
        $offerings = [];
        $packages = $this->Packages->getAllPackagesByGroup(
            $rule->product_group_id,
            'active',
            ['hidden' => true]
        );
        foreach ($packages as $package) {
            if ((int) $package->company_id !== (int) $rule->company_id) {
                continue;
            }
            if (!empty($capability_info['requires_parent_module_row'])
                && (int) $package->module_id !== (int) $parent_module_id) {
                continue;
            }

            foreach (($package->pricing ?? []) as $pricing) {
                if (($pricing->currency ?? null) !== $currency) {
                    continue;
                }
                if (!empty($capability_info['allowed_periods'])
                    && !in_array($pricing->period, (array) $capability_info['allowed_periods'], true)) {
                    continue;
                }

                $offerings[(int) $pricing->id] = [
                    'rule' => $rule,
                    'package' => $package,
                    'pricing' => $pricing,
                    'package_group_id' => (int) $rule->product_group_id,
                    'capability' => $rule->capability,
                    'capability_info' => $capability_info
                ];
            }
        }

        return $offerings;
    }

    private function normalizeServiceExtraExpiry($expires_at)
    {
        if ($expires_at === null || $expires_at === '') {
            return null;
        }

        if (!is_scalar($expires_at)) {
            return false;
        }

        $expires_at = trim((string) $expires_at);
        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/i',
            $expires_at
        )) {
            return false;
        }

        $timestamp = strtotime($expires_at);
        if ($timestamp === false || $timestamp <= time()) {
            return false;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function serviceExtraExpiry(array $preview)
    {
        return $this->normalizeServiceExtraExpiry(
            $preview['_service_extra']['expires_at'] ?? null
        );
    }

    private function createServiceExtra(
        stdClass $parent_service,
        array $offering,
        array $post,
        $expires_at = null
    ) {
        Loader::loadModels($this, ['Services', 'Invoices', 'PackageOptions', 'PackageOptionConditionSets']);

        $package = $offering['package'];
        $pricing = $offering['pricing'];
        $data = $post;
        unset(
            $data['_csrf_token'],
            $data['preview'],
            $data['purchase'],
            $data['override_price'],
            $data['override_currency'],
            $data['date_added'],
            $data['date_renews'],
            $data['date_last_renewed'],
            $data['date_paid_through'],
            $data['date_suspended'],
            $data['date_canceled'],
            $data['date_advance_renewal'],
            $data['coupon_id'],
            $data['pricing_id'],
            $data['parent_service_id'],
            $data['package_group_id'],
            $data['client_id'],
            $data['staff_id'],
            $data['module_row_id'],
            $data['module_group_id'],
            $data['module_row'],
            $data['module_group'],
            $data['status'],
            $data['use_module'],
            $data['qty'],
            $data['id_format'],
            $data['id_value'],
            $data['suspension_reason']
        );
        $data = array_merge($data, [
            'pricing_id' => $pricing->id,
            'parent_service_id' => $parent_service->id,
            'package_group_id' => $offering['package_group_id'],
            'client_id' => $parent_service->client_id,
            'status' => 'pending',
            'use_module' => 'true',
            'qty' => 1,
            'configoptions' => $post['configoptions'] ?? []
        ]);
        if ($expires_at !== null) {
            $data['date_canceled'] = $expires_at;
        }

        $parent_package = $this->Packages->get(($parent_service->package ?? null)->id ?? null);
        $requires_parent_row = !empty($offering['capability_info']['requires_parent_module_row']);
        if ($requires_parent_row
            && (!$parent_package || (int) $parent_package->module_id !== (int) $package->module_id)) {
            $this->Input->setErrors([
                'service_extra' => ['module_row' => Language::_('ServiceExtrasPlugin.!error.module_row', true)]
            ]);
            return null;
        }
        if ($requires_parent_row) {
            $data['module_row_id'] = $parent_service->module_row_id;
        }

        $option_logic = $this->optionLogic($package, $pricing);
        if (($errors = $option_logic->validate($data['configoptions']))) {
            $this->Input->setErrors($errors);
            return null;
        }

        $this->Services->validateService($package, $data);
        if (($errors = $this->Services->errors())) {
            $this->Input->setErrors($errors);
            return null;
        }

        $service_id = $this->Services->add($data, [$package->id => $pricing->id]);
        if (($errors = $this->Services->errors())) {
            $this->Input->setErrors($errors);
            return null;
        }
        if (!$service_id) {
            $this->Input->setErrors([
                'service_extra' => ['create' => Language::_('ServiceExtrasPlugin.!error.service', true)]
            ]);
            return null;
        }

        $invoice_id = $this->Invoices->createFromServices(
            $parent_service->client_id,
            [$service_id],
            $pricing->currency,
            date('c')
        );
        if (!$invoice_id) {
            $this->Services->delete($service_id);
            $this->Input->setErrors([
                'invoice' => ['create' => Language::_('ServiceExtrasPlugin.!error.invoice', true)]
            ]);
            return null;
        }

        return ['service_id' => $service_id, 'invoice_id' => $invoice_id];
    }

    private function optionLogic($package, $pricing)
    {
        Loader::loadModels($this, ['PackageOptionConditionSets']);
        $options = $this->PackageOptions->getAllByPackageId(
            $package->id,
            $pricing->term,
            $pricing->period,
            $pricing->currency,
            null,
            ['addable' => 1]
        );
        $option_ids = [];
        foreach ($options as $option) {
            $option_ids[] = $option->id;
        }

        $logic = new OptionLogic();
        $logic->setPackageOptionConditionSets(
            $this->PackageOptionConditionSets->getAll(
                ['package_id' => $package->id, 'option_ids' => $option_ids]
            )
        );
        return $logic;
    }

    private function tabServiceExtra(
        $rule_id,
        stdClass $service,
        ?array $get = null,
        ?array $post = null,
        ?array $files = null
    ) {
        Loader::loadModels($this, [
            'Clients',
            'Invoices',
            'PackageOptions',
            'Pricings',
            'Services'
        ]);
        Loader::loadHelpers($this, ['CurrencyFormat', 'Date', 'Form', 'Html']);

        $context = $this->context($service, $rule_id);
        if (!$context || !$this->ruleIsAvailable($context['rules'][0], $context['capabilities'])) {
            $this->Input->setErrors([
                'service_extra' => ['unavailable' => Language::_('ServiceExtrasPlugin.!error.unavailable', true)]
            ]);
            return '';
        }

        $service = $context['service'];
        $rule = $context['rules'][0];
        $currency_setting = $this->Clients->getSetting($service->client_id, 'default_currency');
        $currency = $currency_setting->value ?? null;
        $offerings = $this->offerings(
            $rule,
            $context['capabilities'],
            $currency,
            $context['package']->module_id
        );
        if (empty($offerings)) {
            $this->Input->setErrors([
                'service_extra' => ['unavailable' => Language::_('ServiceExtrasPlugin.!error.unavailable', true)]
            ]);
            return '';
        }

        $post = $post ?? [];
        $selected_id = (int) ($post['pricing_id'] ?? array_key_first($offerings));
        $selected = $offerings[$selected_id] ?? null;
        if (!$selected) {
            $this->Input->setErrors([
                'service_extra' => ['selection' => Language::_('ServiceExtrasPlugin.!error.selection', true)]
            ]);
            $selected_id = (int) array_key_first($offerings);
            $selected = $offerings[$selected_id];
        }

        $vars = (object) $post;
        $package_options = $this->PackageOptions->getFields(
            $selected['package']->id,
            $selected['pricing']->term,
            $selected['pricing']->period,
            $selected['pricing']->currency,
            $vars,
            null,
            ['new' => empty($post['configoptions']) ? 1 : 0, 'addable' => 1]
        );
        $package_fields_html = new FieldsHtml($package_options);
        $option_logic = $this->optionLogic($selected['package'], $selected['pricing']);
        $option_logic->setOptionContainerSelector($package_fields_html->getContainerSelector());

        $extra_module = $this->ModuleManager->initModule($selected['package']->module_id);
        $module_fields_html = null;
        if ($extra_module) {
            $extra_module->base_uri = $this->base_uri;
            if (!empty($selected['capability_info']['requires_parent_module_row'])) {
                $extra_module->setModuleRow($extra_module->getModuleRow($service->module_row_id));
            }
            $module_fields = $extra_module->getClientAddFields($selected['package'], $vars);
            $module_fields_html = new FieldsHtml($module_fields);
        }

        $preview = null;
        $created = null;
        $pricing_totals = null;
        $scheduled_cancellation = null;
        if (!empty($post['preview']) || !empty($post['purchase'])) {
            $formatted_options = $this->PackageOptions->formatOptions($post['configoptions'] ?? []);
            $preview = $this->ModuleManager->moduleRpc(
                $context['package']->module_id,
                'previewServiceExtra',
                [
                    $selected['capability'],
                    $context['package'],
                    $service,
                    $selected['package'],
                    $formatted_options
                ],
                $service->module_row_id
            );

            if (($errors = $this->ModuleManager->errors())) {
                $this->Input->setErrors($errors);
            } elseif (!is_array($preview)) {
                $this->Input->setErrors([
                    'service_extra' => ['preview' => Language::_('ServiceExtrasPlugin.!error.preview', true)]
                ]);
            } else {
                $scheduled_cancellation = $this->serviceExtraExpiry($preview);
                if ($scheduled_cancellation === false) {
                    $this->Input->setErrors([
                        'service_extra' => ['expiry' => Language::_('ServiceExtrasPlugin.!error.expiry', true)]
                    ]);
                } elseif (!empty($post['purchase'])) {
                    $created = $this->createServiceExtra(
                        $service,
                        $selected,
                        $post,
                        $scheduled_cancellation
                    );
                    if ($created) {
                        $this->setMessage('success', Language::_('ServiceExtrasPlugin.!success.created', true));
                    }
                }
            }

            if (is_array($preview)) {
                $presenter = $this->Services->getDataPresenter(
                    $service->client_id,
                    [
                        'client_id' => $service->client_id,
                        'pricing_id' => $selected['pricing']->id,
                        'parent_service_id' => $service->id,
                        'qty' => 1,
                        'configoptions' => $post['configoptions'] ?? []
                    ],
                    [
                        'includeSetupFees' => true,
                        'startDate' => date('c'),
                        'prorateStartDate' => date('c')
                    ]
                );
                $pricing_totals = $presenter ? $presenter->totals() : null;
            }
        }

        $this->view = new View();
        $this->view->setView('tab_service_extra', 'ServiceExtras.default');
        $this->view->base_uri = $this->base_uri;
        $this->view->set('service', $service);
        $this->view->set('rule', $rule);
        $this->view->set('offerings', $offerings);
        $this->view->set('selected_id', $selected_id);
        $this->view->set('selected', $selected);
        $this->view->set('periods', $this->Pricings->getPeriods(false));
        $this->view->set('periods_plural', $this->Pricings->getPeriods(true));
        $this->view->set('package_fields_html', $package_fields_html);
        $this->view->set('module_fields_html', $module_fields_html);
        $this->view->set('option_logic_js', $option_logic->getJavascript());
        $this->view->set('preview', $preview);
        $this->view->set('scheduled_cancellation', $scheduled_cancellation);
        $this->view->set('created', $created);
        $this->view->set('pricing_totals', $pricing_totals);

        return $this->view->fetch();
    }
}
