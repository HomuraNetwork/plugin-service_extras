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
$form_source = file_get_contents(dirname(__DIR__) . '/views/default/admin_main_form.pdt');
$admin_controller_source = file_get_contents(dirname(__DIR__) . '/controllers/admin_main.php');
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
    strpos($source, 'if (!empty($option_ids))') !== false
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
    strpos($tab_view_source, 'service-extra-product-card') !== false
        && strpos($tab_view_source, '<select') === false
        && strpos($tab_view_source, 'name="select_product_id"') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.step_select') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.step_review') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.step_payment') !== false,
    'The purchase page must use product cards and a guided select, review, and payment flow.'
);
assertSameValue(
    true,
    strpos($source, "\$post['pricing_id'] = \$selected_product_id") !== false
        && strpos($tab_view_source, "field.disabled = true") === false
        && strpos($tab_view_source, 'input[data-type="quantity"]') !== false
        && strpos($tab_view_source, "input.type = 'number'") !== false,
    'Product changes must not disable Configurable Options, and quantity options must be directly editable.'
);
assertSameValue(
    true,
    strpos($tab_view_source, "Date->cast(\$scheduled_cancellation, 'Y-m-d')") !== false
        && strpos($tab_view_source, "Date->cast(\$scheduled_cancellation, 'date_time')") === false,
    'The module-provided service end date must be displayed without a time.'
);
assertSameValue(
    true,
    substr_count($tab_view_source, 'card card-blesta') >= 3
        && strpos($tab_view_source, 'service-extra-summary-panel') !== false
        && strpos($tab_view_source, 'service-extra-config-fields') !== false,
    'The purchase flow must use Blesta Order-style Bootstrap cards for selection, configuration, and review.'
);
assertSameValue(
    true,
    strpos($tab_view_source, 'window.location.replace') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.continue_payment') !== false
        && strpos($tab_view_source, 'ServiceExtrasPlugin.purchase.pay_invoice') === false,
    'A completed purchase must continue directly into billing without instructing the client to find an invoice.'
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
        && strpos($source, 'private const UNPAID_ORDER_TTL_HOURS = 12;') !== false,
    'Tracked unpaid purchases must become eligible for cleanup after 12 hours.'
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
