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
    is_callable([$plugin, 'tabServiceExtraRule42']),
    'Each enabled rule must be dispatchable as an independent service tab.'
);
assertSameValue(
    ['capability', 'parent_group_ids', 'required_option_name', 'required_option_values'],
    callPrivate($plugin, 'legacyRuleColumns'),
    'The upgrade must remove every obsolete rule column from the original schema.'
);

$source = file_get_contents(dirname(__DIR__) . '/service_extras_plugin.php');
$rules_source = file_get_contents(dirname(__DIR__) . '/models/service_extra_rules.php');
$form_source = file_get_contents(dirname(__DIR__) . '/views/default/admin_main_form.pdt');
$admin_controller_source = file_get_contents(dirname(__DIR__) . '/controllers/admin_main.php');
$tab_view_source = file_get_contents(dirname(__DIR__) . '/views/default/tab_service_extra.pdt');
assertSameValue(
    true,
    strpos($source, "'parent_service_id' => \$parent_service->id") !== false,
    'Each purchase must be stored as a child service linked to its parent.'
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
    strpos($source, "'clients/editinvoice/'") !== false
        && strpos($source, "'pay/method/'") !== false
        && strpos($tab_view_source, '$invoice_uri') !== false
        && strpos($tab_view_source, "'pay/method/'") === false,
    'Invoice links must use the correct staff or client route for the current service page.'
);

echo "review regressions: ok\n";
