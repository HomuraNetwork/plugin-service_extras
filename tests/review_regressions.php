<?php

class Plugin
{
}

require dirname(__DIR__) . '/service_extras_plugin.php';

function callPrivate($object, $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    return $reflection->invokeArgs($object, $arguments);
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$plugin = (new ReflectionClass(ServiceExtrasPlugin::class))->newInstanceWithoutConstructor();

assertSameValue(
    null,
    callPrivate($plugin, 'serviceExtraExpiry', [[]]),
    'A module may leave a child service without a scheduled end date.'
);

$future = '2099-08-01T12:30:00+09:00';
assertSameValue(
    '2099-08-01T03:30:00Z',
    callPrivate($plugin, 'serviceExtraExpiry', [['_service_extra' => ['expires_at' => $future]]]),
    'Module-provided expiry must be normalized to UTC for services.date_canceled.'
);

assertSameValue(
    false,
    callPrivate($plugin, 'serviceExtraExpiry', [['_service_extra' => ['expires_at' => 'not-a-date']]]),
    'Invalid module expiry must prevent service creation.'
);
assertSameValue(
    false,
    callPrivate($plugin, 'serviceExtraExpiry', [['_service_extra' => ['expires_at' => '2099-08-01 03:30:00']]]),
    'Expiry without an explicit timezone must be rejected.'
);

assertSameValue(
    true,
    is_callable([$plugin, 'tabExtraAddon42']),
    'Each enabled rule must be dispatchable as an independent service tab.'
);
assertSameValue(
    ['capability', 'parent_group_ids', 'required_option_name', 'required_option_values'],
    callPrivate($plugin, 'legacyRuleColumns'),
    'The upgrade must remove every obsolete rule column from the original schema.'
);

$source = file_get_contents(dirname(__DIR__) . '/service_extras_plugin.php');
$rules_source = file_get_contents(dirname(__DIR__) . '/models/service_extra_rules.php');
$orders_source = file_get_contents(dirname(__DIR__) . '/models/service_extra_orders.php');
$settings_source = file_get_contents(dirname(__DIR__) . '/models/service_extra_settings.php');
$form_source = file_get_contents(dirname(__DIR__) . '/views/default/admin_main_form.pdt');
$admin_view_source = file_get_contents(dirname(__DIR__) . '/views/default/admin_main.pdt');
$admin_controller_source = file_get_contents(dirname(__DIR__) . '/controllers/admin_main.php');
$client_controller_source = file_get_contents(dirname(__DIR__) . '/controllers/client_main.php');
$base_controller_source = file_get_contents(dirname(__DIR__) . '/service_extras_controller.php');
$tab_view_source = file_get_contents(dirname(__DIR__) . '/views/default/tab_service_extra.pdt');
$offerings_source = substr(
    $source,
    strpos($source, 'private function offerings'),
    strpos($source, 'private function normalizeServiceExtraExpiry')
        - strpos($source, 'private function offerings')
);
$preview_condition_position = strpos(
    $source,
    "if (!empty(\$post['review']) || !empty(\$post['purchase']))"
);
$availability_call_position = strpos(
    $source,
    '$availability = $this->serviceExtraAvailability(',
    $preview_condition_position
);
$tab_method_position = strpos($source, 'private function tabServiceExtra(');
$tab_view_position = strpos($source, '$this->view = new View();', $tab_method_position);
$tab_helpers_position = strpos(
    $source,
    "Loader::loadHelpers(\$this, ['CurrencyFormat', 'Date', 'Form', 'Html']);",
    $tab_method_position
);
assertSameValue(
    true,
    strpos($source, "'parent_service_id' => \$parent_service->id") !== false,
    'Each purchase must be stored as a child service linked to its parent.'
);
assertSameValue(
    true,
    strpos($source, "'parent_service_id' => (int) \$parent_service->id") !== false
        && strpos($source, "'invoice_id' => (int) \$invoice_id") !== false
        && strpos($source, "'service_id' => (int) \$service_id") !== false,
    'Each generated invoice and pending child service must be tracked with its parent.'
);
assertSameValue(
    true,
    strpos($source, "'package_group_id' => \$offering['package_group_id']") !== false,
    'The child service must retain the product group selected by the rule.'
);
assertSameValue(
    true,
    strpos($source, "\$data['date_canceled'] = \$expires_at") !== false,
    'Module-provided expiry must schedule the child service end when it is created.'
);
assertSameValue(
    false,
    strpos($source, "'onetime'") !== false,
    'Service Extras must not force product pricing to use the one-time period.'
);
assertSameValue(
    true,
    strpos($source, "'getServiceExtraDefinition'") !== false,
    'The selected product package module must define how the extra is provisioned.'
);
assertSameValue(
    true,
    strpos($rules_source, 'product_package_ids') !== false,
    'Rules must save explicitly selected offered packages.'
);
assertSameValue(
    false,
    strpos($rules_source, "'capability'") !== false,
    'Rules must not require an administrator-entered module capability.'
);
assertSameValue(
    2,
    substr_count($rules_source, "\$this->dateToUtc(date('c'))"),
    'Rule timestamps must be converted to the SQL DATETIME format used by Blesta.'
);
assertSameValue(
    true,
    strpos($source, "version_compare(\$current_version, '1.1.2', '<')") !== false
        && strpos($source, 'setField($column, null, false)') !== false,
    'Version 1.1.2 must drop obsolete rule columns left by earlier installations.'
);
assertSameValue(
    true,
    strpos($source, "version_compare(\$current_version, '1.2.0', '<')") !== false
        && strpos($source, "->create('service_extra_orders', true)") !== false
        && strpos($source, "'expire_unpaid_extras'") !== false,
    'Version 1.2.0 must install order tracking and its unpaid-order cron task.'
);
assertSameValue(
    true,
    strpos($form_source, 'dual-select-container') !== false
        && strpos($form_source, "item.classList.remove('selected')") !== false,
    'Package selectors must support moving items and clearing a selection.'
);
assertSameValue(
    2,
    substr_count($admin_controller_source, "setMessage('error', \$errors, false, null, false)"),
    'Admin validation errors must use the Blesta system message partial instead of a plugin-local message.pdt.'
);
assertSameValue(
    true,
    strpos($source, 'public function getAdminServiceTabs(stdClass $service)') !== false
        && substr_count($source, 'return $this->serviceTabs($service);') === 2,
    'Eligible rules must appear on both client and staff service management pages.'
);
assertSameValue(
    false,
    strpos($source, 'ruleHasAvailableProduct') !== false,
    'A matching service tab must remain visible when its product configuration is incomplete.'
);
assertSameValue(
    true,
    strpos($offerings_source, 'serviceExtraDefinition') === false
        && strpos($offerings_source, 'getServiceExtraAvailability') === false
        && strpos($source, "'getServiceExtraAvailability'") !== false
        && $preview_condition_position !== false
        && $availability_call_position !== false
        && $preview_condition_position < $availability_call_position,
    'Rule-selected products must be listed before preview-time module availability validation runs.'
);
assertSameValue(
    true,
    strpos($source, "'clients/editinvoice/'") !== false
        && strpos($source, "'pay/method/'") !== false
        && strpos($tab_view_source, '$invoice_uri') !== false
        && strpos($tab_view_source, "'pay/method/'") === false,
    'Invoice links must use the correct staff or client route for the current service page.'
);
assertSameValue(
    true,
    strpos($source, 'if (!empty($condition_option_ids))') !== false
        && strpos($source, '$logic->setPackageOptionConditionSets($condition_sets);') !== false,
    'Packages without Configurable Options must not query condition sets with an empty SQL IN clause.'
);
assertSameValue(
    true,
    $tab_view_position !== false
        && $tab_helpers_position !== false
        && $tab_view_position < $tab_helpers_position,
    'Service tab helpers must be attached after the plugin creates its custom View instance.'
);
assertSameValue(
    true,
    strpos($tab_view_source, 'service-extra-product') !== false
        && strpos($tab_view_source, '<select') === false
        && strpos($tab_view_source, 'type="button"') !== false
        && strpos($tab_view_source, 'name="select_product_id"') !== false
        && strpos($tab_view_source, 'data-has-configurable-options') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.step_select') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.step_review') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.step_payment') !== false,
    'The purchase page must use product cards and a guided select, review, and payment flow.'
);
assertSameValue(
    true,
    strpos($source, "\$post['pricing_id'] = \$selected_product_id") !== false
        && strpos($source, 'public function getServiceExtraConfiguration(') !== false
        && strpos($tab_view_source, 'fetch(requestUrl') !== false
        && strpos($tab_view_source, 'event.preventDefault()') !== false
        && strpos($tab_view_source, "field.disabled = true") === false
        && strpos($tab_view_source, 'input[data-type="quantity"]') !== false
        && strpos($tab_view_source, "input.type = 'number'") !== false,
    'Product changes must load Configurable Options without a full page refresh.'
);
assertSameValue(
    true,
    strpos($tab_view_source, "Date->cast(\$scheduled_cancellation, 'Y-m-d')") !== false
        && strpos($tab_view_source, "\$preview['valid_until']") !== false
        && strpos($tab_view_source, '$service_end_date') !== false
        && strpos($tab_view_source, "Date->cast(\$scheduled_cancellation, 'date_time')") === false,
    'The module-provided period end date must be preferred and displayed without a time.'
);
$automatic_cancellation_position = strpos(
    $tab_view_source,
    'service-extra-auto-cancellation'
);
$module_review_position = strpos($tab_view_source, 'service-extra-module-review');
$review_total_position = strpos(
    $tab_view_source,
    '<div class="col-lg-4 service-extra-review-total'
);
assertSameValue(
    true,
    strpos($tab_view_source, "['_service_extra']['parent_reference']") !== false
        && strpos($tab_view_source, '$service_reference') !== false
        && $automatic_cancellation_position !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.automatic_cancellation') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.service_ends') === false
        && $module_review_position !== false
        && $module_review_position < $automatic_cancellation_position
        && $review_total_position !== false
        && $automatic_cancellation_position < $review_total_position,
    'Review must place the dated automatic cancellation notice on one line below module details.'
);
assertSameValue(
    true,
    strpos($tab_view_source, "['_service_extra']['review_html']") !== false
        && strpos($tab_view_source, 'service-extra-module-review') !== false
        && strpos($tab_view_source, '<?php echo $module_review_html; ?>') !== false,
    'Trusted modules must be able to append review markup after the standard purchase details.'
);
assertSameValue(
    true,
    strpos($tab_view_source, "if (!is_array(\$preview))") !== false
        && strpos($tab_view_source, 'id="service_extra_configuration"') !== false
        && strpos($tab_view_source, "\$has_configuration ? '' : 'hidden'") !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.no_configuration') === false
        && strpos($tab_view_source, 'name="back"') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.back') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.review_again') === false,
    'Selection and configuration must be separate from review, hide empty configuration, and provide Back.'
);
assertSameValue(
    true,
    strpos($tab_view_source, 'nav nav-pills nav-fill') !== false
        && strpos($tab_view_source, 'btn-outline-secondary') !== false
        && strpos($tab_view_source, 'bg-white') === false
        && strpos($tab_view_source, 'linear-gradient') === false,
    'The checkout must use a simple Bootstrap layout without hard-coded white cards or decorative gradients.'
);
assertSameValue(
    true,
    strpos($source, 'private function serviceExtraOptionIds(') !== false
        && strpos($source, '$pricing->package_id ?? $package->id') !== false
        && strpos($source, '$option->hidden') === false
        && strpos($source, "'allow' => \$option_ids") !== false
        && strpos($source, 'array_fill_keys($option_ids, true)') !== false
        && strpos($source, "['addable' => 1]") === false,
    'Rule-offered Extra products must display all attached Configurable Options with matching pricing.'
);
assertSameValue(
    true,
    substr_count($tab_view_source, '->generate()') === 2
        && strpos($tab_view_source, '->generate(null, $this->view)') === false,
    'Configurable Options must use the same FieldsHtml rendering path as the Order plugin.'
);
assertSameValue(
    true,
    strpos($base_controller_source, 'protected function outputPackageOptions(') !== false
        && strpos($base_controller_source, 'if (!$this->isAjax())') !== false
        && strpos($base_controller_source, 'getServiceExtraConfiguration(') !== false
        && strpos($client_controller_source, '$this->requireLogin();') !== false
        && strpos($client_controller_source, '$this->Session->read(\'blesta_client_id\')') !== false
        && strpos($client_controller_source, 'outputPackageOptions($this->client_id)') !== false
        && strpos($admin_controller_source, 'public function packageOptions()') !== false,
    'AJAX configuration requests must use an authenticated client or staff endpoint.'
);
assertSameValue(
    true,
    strpos($tab_view_source, 'window.location.replace') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.continue_payment') !== false
        && strpos($tab_view_source, 'purchase.disabled = true') === false
        && strpos($tab_view_source, "purchase.style.pointerEvents = 'none'") !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.pay_invoice') === false,
    'Payment submission must retain purchase=1 and continue directly into billing.'
);
assertSameValue(
    true,
    strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.payment_destination') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.payment_provisioning') !== false
        && strpos($tab_view_source, 'border shadow-sm px-3 py-2') !== false
        && strpos($tab_view_source, 'font-weight-bold fw-bold') !== false
        && strpos($tab_view_source, 'alert alert-warning') === false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.payment_window_heading') === false,
    'The review step must emphasize activation time without overemphasizing the payment deadline.'
);
assertSameValue(
    true,
    strpos($tab_view_source, '$parent_package->name') !== false
        && strpos($tab_view_source, '$service->name') !== false
        && strpos($tab_view_source, '$service->date_renews') !== false,
    'The purchase page must clearly identify the parent package, service label, and renewal date.'
);
assertSameValue(
    true,
    strpos($source, "preg_match('/^tabExtraAddon(\\d+)$/', \$method") !== false
        && strpos($source, "\$tabs['tabExtraAddon' . (int) \$rule->id]") !== false
        && strpos($source, 'tabServiceExtraRule') === false,
    'Dynamic service tab routes must use the concise tabExtraAddon action name.'
);
assertSameValue(
    true,
    strpos($orders_source, "max(1, (int) \$hours) * 3600") !== false
        && strpos($settings_source, 'DEFAULT_UNPAID_ORDER_TTL_HOURS = 12;') !== false
        && substr_count($source, 'getUnpaidOrderTtlHours(') >= 2,
    'The configured unpaid purchase expiry must drive both cleanup and checkout messaging.'
);
assertSameValue(
    true,
    strpos($admin_controller_source, 'setUnpaidOrderTtlHours(') !== false
        && strpos($admin_view_source, "fieldNumber(\n            'unpaid_order_ttl_hours'") !== false
        && strpos($settings_source, 'MAXIMUM_UNPAID_ORDER_TTL_HOURS = 720;') !== false,
    'Staff must be able to configure the unpaid purchase expiry in whole hours.'
);
$expiry_method_position = strpos($source, 'private function expireUnpaidOrders(');
$void_position = strpos($source, "['status' => 'void']", $expiry_method_position);
$delete_position = strpos($source, '$this->Services->delete((int) $service->id);', $expiry_method_position);
assertSameValue(
    true,
    $expiry_method_position !== false
        && $void_position !== false
        && $delete_position !== false
        && $void_position < $delete_position
        && strpos($source, "(float) (\$invoice->paid ?? 0) > 0", $expiry_method_position) !== false,
    'The expiry cron must preserve paid orders, void unpaid invoices, and then remove pending services.'
);

echo "review regressions: ok\n";
