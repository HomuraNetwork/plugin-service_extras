<?php

use Blesta\Core\Util\Input\Fields\Html as FieldsHtml;
use Blesta\Core\Util\PackageOptions\Logic as OptionLogic;

class ServiceExtrasPlugin extends Plugin
{
    private const UNPAID_ORDER_TTL_HOURS = 12;

    public function __construct()
    {
        Language::loadLang('service_extras_plugin', null, dirname(__FILE__) . DS . 'language' . DS);
        Loader::loadComponents($this, ['Input', 'Record']);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    public function install($plugin_id)
    {
        try {
            $this->createRuleTable();
            $this->createOrderTable();
            $this->addCronTasks($this->getCronTasks());
        } catch (\Throwable $e) {
            $this->Input->setErrors(['db' => ['create' => $e->getMessage()]]);
        }
    }

    public function upgrade($current_version, $plugin_id)
    {
        if (!version_compare($this->getVersion(), $current_version, '>')) {
            return;
        }

        try {
            if (version_compare($current_version, '1.1.0', '<')) {
                if (!$this->tableColumnExists('service_extra_rules', 'product_package_ids')) {
                    $this->Record
                        ->setField('product_package_ids', ['type' => 'text', 'is_null' => true, 'default' => null])
                        ->alter('service_extra_rules');
                }

                $rules = $this->Record->select([
                    'id', 'company_id', 'parent_package_ids', 'parent_group_ids', 'product_group_id'
                ])->from('service_extra_rules')->fetchAll();

                foreach ($rules as $rule) {
                    $parent_ids = $this->storedIds($rule->parent_package_ids ?? '[]');
                    $parent_group_ids = $this->storedIds($rule->parent_group_ids ?? '[]');
                    if (!empty($parent_group_ids)) {
                        $rows = $this->Record->select('package_group.package_id')->from('package_group')
                            ->innerJoin(
                                'package_groups',
                                'package_groups.id',
                                '=',
                                'package_group.package_group_id',
                                false
                            )
                            ->where('package_group.package_group_id', 'in', $parent_group_ids)
                            ->where('package_groups.company_id', '=', (int) $rule->company_id)
                            ->fetchAll();
                        foreach ($rows as $row) {
                            $parent_ids[] = (int) $row->package_id;
                        }
                    }

                    $product_ids = [];
                    if ((int) $rule->product_group_id > 0) {
                        $rows = $this->Record->select('package_group.package_id')->from('package_group')
                            ->innerJoin('packages', 'packages.id', '=', 'package_group.package_id', false)
                            ->where('package_group.package_group_id', '=', (int) $rule->product_group_id)
                            ->where('packages.company_id', '=', (int) $rule->company_id)
                            ->fetchAll();
                        foreach ($rows as $row) {
                            $product_ids[] = (int) $row->package_id;
                        }
                    }

                    $this->Record->where('id', '=', (int) $rule->id)->update('service_extra_rules', [
                        'parent_package_ids' => json_encode(array_values(array_unique($parent_ids))),
                        'product_package_ids' => json_encode(array_values(array_unique($product_ids)))
                    ]);
                }
            }

            if (version_compare($current_version, '1.1.2', '<')) {
                $this->dropLegacyRuleColumns();
            }

            if (version_compare($current_version, '1.2.0', '<')) {
                $this->createOrderTable();
                $this->addCronTasks($this->getCronTasks());
            }

            Loader::loadModels($this, ['ServiceExtras.ServiceExtraRules']);
            $plugin_instances = $this->Record->select(['id', 'company_id'])->from('plugins')
                ->where('dir', '=', 'service_extras')
                ->fetchAll();
            foreach ($plugin_instances as $instance) {
                $this->ServiceExtraRules->syncPluginParentAssociations(
                    $instance->id,
                    $instance->company_id
                );
            }
        } catch (\Throwable $e) {
            $this->Input->setErrors(['db' => ['upgrade' => $e->getMessage()]]);
        }
    }

    private function tableColumnExists($table, $column)
    {
        $statement = $this->Record->query(
            'SELECT COUNT(*) AS `count` FROM `information_schema`.`COLUMNS`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
            $table,
            $column
        );
        $result = $statement->fetch();
        $count = is_array($result)
            ? ($result['count'] ?? 0)
            : (is_object($result) ? ($result->count ?? 0) : 0);
        return (int) $count > 0;
    }

    private function tableExists($table)
    {
        $statement = $this->Record->query(
            'SELECT COUNT(*) AS `count` FROM `information_schema`.`TABLES`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            $table
        );
        $result = $statement->fetch();
        $count = is_array($result)
            ? ($result['count'] ?? 0)
            : (is_object($result) ? ($result->count ?? 0) : 0);
        return (int) $count > 0;
    }

    private function createRuleTable()
    {
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('name', ['type' => 'varchar', 'size' => 255])
            ->setField('parent_package_ids', ['type' => 'text'])
            ->setField('product_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('product_package_ids', ['type' => 'text'])
            ->setField('enabled', ['type' => 'char', 'size' => 1, 'default' => '1'])
            ->setField('date_added', ['type' => 'datetime'])
            ->setField('date_updated', ['type' => 'datetime'])
            ->setKey(['id'], 'primary')
            ->setKey(['company_id', 'enabled'], 'index')
            ->create('service_extra_rules', true);
    }

    private function createOrderTable()
    {
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('rule_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('parent_service_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('service_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('invoice_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField(
                'status',
                ['type' => 'enum', 'size' => "'pending','completed','expired','error'", 'default' => 'pending']
            )
            ->setField('last_error', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('date_added', ['type' => 'datetime'])
            ->setField('date_updated', ['type' => 'datetime'])
            ->setKey(['id'], 'primary')
            ->setKey(['service_id'], 'unique')
            ->setKey(['invoice_id'], 'unique')
            ->setKey(['company_id', 'status', 'date_added'], 'index')
            ->create('service_extra_orders', true);
    }

    private function legacyRuleColumns()
    {
        return [
            'capability',
            'parent_group_ids',
            'required_option_name',
            'required_option_values'
        ];
    }

    private function dropLegacyRuleColumns()
    {
        $alter = false;
        foreach ($this->legacyRuleColumns() as $column) {
            if ($this->tableColumnExists('service_extra_rules', $column)) {
                $this->Record->setField($column, null, false);
                $alter = true;
            }
        }

        if ($alter) {
            $this->Record->alter('service_extra_rules');
        }
    }

    private function storedIds($value)
    {
        $ids = json_decode((string) $value, true);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public function uninstall($plugin_id, $last_instance)
    {
        Loader::loadModels($this, ['CronTasks']);
        $company_id = (int) Configure::get('Blesta.company_id');

        if ($this->tableExists('service_extra_orders')) {
            $this->Record->from('service_extra_orders')->where('company_id', '=', $company_id)->delete();
        }
        if ($this->tableExists('service_extra_rules')) {
            $this->Record->from('service_extra_rules')->where('company_id', '=', $company_id)->delete();
        }

        foreach ($this->getCronTasks() as $task) {
            $task_run = $this->CronTasks->getTaskRunByKey(
                $task['key'],
                $task['dir'],
                false,
                $task['task_type']
            );
            if ($task_run) {
                $this->CronTasks->deleteTaskRun($task_run->task_run_id);
            }

            if ($last_instance) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        }

        if ($last_instance) {
            $this->Record->drop('service_extra_orders');
            $this->Record->drop('service_extra_rules');
        }
    }

    public function cron($key)
    {
        if ($key === 'expire_unpaid_extras') {
            $this->expireUnpaidOrders((int) Configure::get('Blesta.company_id'));
        }
    }

    private function getCronTasks()
    {
        return [
            [
                'key' => 'expire_unpaid_extras',
                'dir' => 'service_extras',
                'task_type' => 'plugin',
                'name' => Language::_('ServiceExtrasPlugin.cron.expire_unpaid_name', true),
                'description' => Language::_('ServiceExtrasPlugin.cron.expire_unpaid_desc', true),
                'type' => 'interval',
                'type_value' => 5,
                'enabled' => 1
            ]
        ];
    }

    private function addCronTasks(array $tasks)
    {
        Loader::loadModels($this, ['CronTasks']);
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);
            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                $task_id = $cron_task->id ?? null;
            }

            if ($task_id) {
                $vars = ['enabled' => $task['enabled']];
                if ($task['type'] === 'interval') {
                    $vars['interval'] = $task['type_value'];
                } else {
                    $vars['time'] = $task['type_value'];
                }
                $this->CronTasks->addTaskRun($task_id, $vars);
            }
        }
    }

    private function errorMessage($errors, $fallback)
    {
        $messages = [];
        $iterator = function ($value) use (&$iterator, &$messages) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $iterator($item);
                }
            } elseif (is_scalar($value) && trim((string) $value) !== '') {
                $messages[] = trim((string) $value);
            }
        };
        $iterator($errors);

        return $messages ? implode(' ', $messages) : $fallback;
    }

    private function expireUnpaidOrders($company_id)
    {
        Loader::loadModels($this, [
            'Invoices',
            'Services',
            'ServiceExtras.ServiceExtraOrders'
        ]);

        foreach ($this->ServiceExtraOrders->getExpiredPending(
            $company_id,
            self::UNPAID_ORDER_TTL_HOURS
        ) as $order) {
            try {
                $service = $this->Services->get((int) $order->service_id);
                $invoice = $this->Invoices->get((int) $order->invoice_id);

                if (!$service) {
                    $this->ServiceExtraOrders->setStatus($order->id, 'expired');
                    continue;
                }
                if (($service->status ?? null) !== 'pending') {
                    $this->ServiceExtraOrders->setStatus($order->id, 'completed');
                    continue;
                }
                if ($invoice && ((float) ($invoice->paid ?? 0) > 0 || (float) ($invoice->due ?? 0) <= 0)) {
                    $this->ServiceExtraOrders->setStatus($order->id, 'completed');
                    continue;
                }

                if ($invoice && ($invoice->status ?? null) !== 'void') {
                    $this->Invoices->edit((int) $invoice->id, ['status' => 'void']);
                    if (($errors = $this->Invoices->errors())) {
                        $this->ServiceExtraOrders->setStatus(
                            $order->id,
                            'error',
                            $this->errorMessage($errors, 'The unpaid invoice could not be voided.')
                        );
                        continue;
                    }
                    $invoice = $this->Invoices->get((int) $invoice->id);
                    if (($invoice->status ?? null) !== 'void') {
                        $this->ServiceExtraOrders->setStatus(
                            $order->id,
                            'error',
                            'The unpaid invoice did not enter the void status.'
                        );
                        continue;
                    }
                }

                $this->Services->delete((int) $service->id);
                if (($errors = $this->Services->errors())) {
                    $this->ServiceExtraOrders->setStatus(
                        $order->id,
                        'error',
                        $this->errorMessage($errors, 'The pending service could not be removed.')
                    );
                    continue;
                }

                $this->ServiceExtraOrders->setStatus($order->id, 'expired');
            } catch (\Throwable $e) {
                $this->ServiceExtraOrders->setStatus($order->id, 'error', $e->getMessage());
            }
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
        if (preg_match('/^tabExtraAddon(\d+)$/', $method, $matches)) {
            return $this->tabServiceExtra((int) $matches[1], ...$arguments);
        }

        throw new BadMethodCallException('Unknown Service Extras action: ' . $method);
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
            $rules[] = $rule;
        }
        if (empty($rules)) {
            return null;
        }

        return [
            'service' => $service,
            'package' => $package,
            'rules' => $rules
        ];
    }

    private function serviceExtraAvailability(
        $parent_package,
        $parent_service,
        $extra_package,
        array $config_options = []
    )
    {
        $module_id = (int) ($extra_package->module_id ?? 0);
        if ($module_id < 1) {
            return ['available' => true, 'definition' => [], 'review' => []];
        }

        $module = $this->ModuleManager->initModule($module_id);
        if (!$module) {
            return ['available' => false, 'definition' => [], 'review' => []];
        }

        $module_row_id = (int) ($parent_package->module_id ?? 0) === $module_id
            ? (int) ($parent_service->module_row_id ?? 0)
            : null;

        if (is_callable([$module, 'getServiceExtraAvailability'])) {
            $availability = $this->ModuleManager->moduleRpc(
                $module_id,
                'getServiceExtraAvailability',
                [$parent_package, $parent_service, $extra_package, $config_options],
                $module_row_id
            );
            if (is_array($availability) && array_key_exists('available', $availability)) {
                return [
                    'available' => !empty($availability['available']),
                    'definition' => is_array($availability['definition'] ?? null)
                        ? $availability['definition']
                        : [],
                    'review' => is_array($availability['review'] ?? null)
                        ? $availability['review']
                        : []
                ];
            }
            return ['available' => false, 'definition' => [], 'review' => []];
        }

        if (is_callable([$module, 'getServiceExtraDefinition'])) {
            $definition = $this->ModuleManager->moduleRpc(
                $module_id,
                'getServiceExtraDefinition',
                [$parent_package, $parent_service, $extra_package],
                $module_row_id
            );
            return [
                'available' => !empty($definition),
                'definition' => is_array($definition) ? $definition : [],
                'review' => []
            ];
        }

        return ['available' => true, 'definition' => [], 'review' => []];
    }

    private function serviceTabs(stdClass $service)
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
            $tabs['tabExtraAddon' . (int) $rule->id] = [
                'name' => $rule->name,
                'icon' => 'fas fa-plus-circle'
            ];
        }

        return $tabs;
    }

    public function getClientServiceTabs(stdClass $service)
    {
        return $this->serviceTabs($service);
    }

    public function getAdminServiceTabs(stdClass $service)
    {
        return $this->serviceTabs($service);
    }

    private function offerings(stdClass $rule, $currency)
    {
        $offerings = [];
        $selected_ids = array_fill_keys(array_map('intval', $rule->product_package_ids), true);
        $packages = $this->Packages->getAllPackagesByGroup(
            $rule->product_group_id,
            'active',
            ['hidden' => true]
        );
        foreach ($packages as $package) {
            if (!isset($selected_ids[(int) $package->id])
                || (int) $package->company_id !== (int) $rule->company_id) {
                continue;
            }

            foreach (($package->pricing ?? []) as $pricing) {
                if (($pricing->currency ?? null) !== $currency) {
                    continue;
                }

                $offerings[(int) $pricing->id] = [
                    'rule' => $rule,
                    'package' => $package,
                    'pricing' => $pricing,
                    'package_group_id' => (int) $rule->product_group_id
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
        Loader::loadModels($this, [
            'Services',
            'Invoices',
            'PackageOptions',
            'PackageOptionConditionSets',
            'ServiceExtras.ServiceExtraOrders'
        ]);

        $package = $offering['package'];
        $pricing = $offering['pricing'];
        $data = $post;
        unset(
            $data['_csrf_token'],
            $data['review'],
            $data['purchase'],
            $data['select_product'],
            $data['displayed_pricing_id'],
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
        $requires_parent_row = !empty($offering['definition']['requires_parent_module_row'])
            || !empty($offering['use_parent_module_row']);
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

        try {
            $order_id = $this->ServiceExtraOrders->add([
                'company_id' => (int) ($package->company_id ?? 0),
                'rule_id' => (int) ($offering['rule']->id ?? 0),
                'parent_service_id' => (int) $parent_service->id,
                'service_id' => (int) $service_id,
                'invoice_id' => (int) $invoice_id
            ]);
        } catch (\Throwable $e) {
            $order_id = null;
        }
        if (!$order_id) {
            try {
                $this->Invoices->edit($invoice_id, ['status' => 'void']);
            } catch (\Throwable $e) {
            }
            try {
                $this->Services->delete($service_id);
            } catch (\Throwable $e) {
            }
            $this->Input->setErrors([
                'service_extra' => ['tracking' => Language::_('ServiceExtrasPlugin.!error.tracking', true)]
            ]);
            return null;
        }

        try {
            $this->Invoices->edit($invoice_id, [
                'note_public' => Language::_(
                    'ServiceExtrasPlugin.purchase.invoice_note',
                    true,
                    (string) (($parent_service->package ?? null)->name ?? ''),
                    (string) ($parent_service->name ?? '')
                )
            ]);
        } catch (\Throwable $e) {
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

        $condition_sets = [];
        if (!empty($option_ids)) {
            $condition_sets = $this->PackageOptionConditionSets->getAll([
                'package_id' => $package->id,
                'option_ids' => $option_ids
            ]);
        }

        $logic = new OptionLogic();
        $logic->setPackageOptionConditionSets($condition_sets);
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
        $this->view = new View();
        Loader::loadHelpers($this, ['CurrencyFormat', 'Date', 'Form', 'Html']);

        $context = $this->context($service, $rule_id);
        if (!$context) {
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
            $currency
        );
        if (empty($offerings)) {
            $this->Input->setErrors([
                'service_extra' => [
                    'unavailable' => Language::_('ServiceExtrasPlugin.!error.unavailable', true, $currency)
                ]
            ]);
            return '';
        }

        $post = $post ?? [];
        $requested_pricing_id = (int) ($post['pricing_id'] ?? 0);
        $displayed_pricing_id = (int) ($post['displayed_pricing_id'] ?? 0);
        if (!empty($post['select_product'])
            || ($displayed_pricing_id > 0 && $requested_pricing_id !== $displayed_pricing_id)) {
            unset($post['configoptions']);
            unset($post['review'], $post['purchase']);
        }
        $selected_id = (int) ($post['pricing_id'] ?? array_key_first($offerings));
        $selected = $offerings[$selected_id] ?? null;
        if (!$selected) {
            $this->Input->setErrors([
                'service_extra' => ['selection' => Language::_('ServiceExtrasPlugin.!error.selection', true)]
            ]);
            $selected_id = (int) array_key_first($offerings);
            $selected = $offerings[$selected_id];
        }

        $same_module = (int) ($selected['package']->module_id ?? 0)
            === (int) ($context['package']->module_id ?? 0);
        $selected['definition'] = [];
        $selected['use_parent_module_row'] = false;

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
            if ($same_module) {
                $extra_module->setModuleRow($extra_module->getModuleRow($service->module_row_id));
            }
            $module_fields = $extra_module->getClientAddFields($selected['package'], $vars);
            $module_fields_html = new FieldsHtml($module_fields);
        }

        $preview = null;
        $created = null;
        $pricing_totals = null;
        $scheduled_cancellation = null;
        if (!empty($post['review']) || !empty($post['purchase'])) {
            $formatted_options = $this->PackageOptions->formatOptions($post['configoptions'] ?? []);
            $availability = $this->serviceExtraAvailability(
                $context['package'],
                $service,
                $selected['package'],
                $formatted_options
            );
            if (($errors = $this->ModuleManager->errors())) {
                $this->Input->setErrors($errors);
            } elseif (empty($availability['available'])) {
                $this->Input->setErrors([
                    'service_extra' => [
                        'availability' => Language::_('ServiceExtrasPlugin.!error.availability', true)
                    ]
                ]);
            } else {
                $selected['definition'] = $availability['definition'];
                $requires_parent_module_row = !empty(
                    $selected['definition']['requires_parent_module_row']
                );
                $use_parent_module_row = $same_module && $requires_parent_module_row;
                $selected['use_parent_module_row'] = $use_parent_module_row;
                $allowed_periods = (array) ($selected['definition']['allowed_periods'] ?? []);

                if ($requires_parent_module_row && !$same_module) {
                    $this->Input->setErrors([
                        'service_extra' => [
                            'module_row' => Language::_('ServiceExtrasPlugin.!error.module_row', true)
                        ]
                    ]);
                } elseif (!empty($allowed_periods)
                    && !in_array($selected['pricing']->period, $allowed_periods, true)) {
                    $this->Input->setErrors([
                        'service_extra' => [
                            'period' => Language::_('ServiceExtrasPlugin.!error.period', true)
                        ]
                    ]);
                } else {
                    $preview = $availability['review'];
                    if ($extra_module && is_callable([$extra_module, 'previewServiceExtra'])) {
                        $preview = $this->ModuleManager->moduleRpc(
                            $selected['package']->module_id,
                            'previewServiceExtra',
                            [
                                $context['package'],
                                $service,
                                $selected['package'],
                                $formatted_options
                            ],
                            $use_parent_module_row ? $service->module_row_id : null
                        );
                    } elseif (empty($preview)) {
                        $preview = [
                            'product' => (string) ($selected['package']->name ?? '')
                        ];
                    }
                }
            }

            if (($errors = $this->ModuleManager->errors())) {
                $this->Input->setErrors($errors);
                $preview = null;
            }
            if (is_array($preview)) {
                $scheduled_cancellation = $this->serviceExtraExpiry($preview);
                if ($scheduled_cancellation === false) {
                    $this->Input->setErrors([
                        'service_extra' => ['expiry' => Language::_('ServiceExtrasPlugin.!error.expiry', true)]
                    ]);
                    $preview = null;
                } elseif (!empty($post['purchase'])) {
                    $created = $this->createServiceExtra(
                        $service,
                        $selected,
                        $post,
                        $scheduled_cancellation
                    );
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

        $this->view->setView('tab_service_extra', 'ServiceExtras.default');
        $this->view->base_uri = $this->base_uri;
        $this->view->set('service', $service);
        $this->view->set('parent_package', $context['package']);
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
        $this->view->set(
            'invoice_uri',
            !empty($created)
                ? $this->invoiceUri($service, (int) $created['invoice_id'])
                : null
        );
        $this->view->set('pricing_totals', $pricing_totals);
        $this->view->set('unpaid_order_ttl_hours', self::UNPAID_ORDER_TTL_HOURS);

        return $this->view->fetch();
    }

    private function invoiceUri(stdClass $service, $invoice_id)
    {
        $admin_base_uri = WEBDIR . trim((string) Configure::get('Route.admin'), '/') . '/';
        if ($this->base_uri === $admin_base_uri) {
            return $this->base_uri . 'clients/editinvoice/' . (int) $service->client_id
                . '/' . (int) $invoice_id . '/';
        }

        return $this->base_uri . 'pay/method/' . (int) $invoice_id . '/';
    }
}
