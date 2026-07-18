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

$software_service = (object) ['options' => [
    (object) ['option_name' => 'software', 'option_value' => 'plesk', 'qty' => 1]
]];
assertSameValue(
    true,
    callPrivate($plugin, 'matchesRequiredOption', [
        $software_service,
        (object) ['required_option_name' => 'software', 'required_option_values' => ['plesk', 'wordpress']]
    ]),
    'A rule must match the parent service Configurable Option internal value.'
);
assertSameValue(
    false,
    callPrivate($plugin, 'matchesRequiredOption', [
        $software_service,
        (object) ['required_option_name' => 'software', 'required_option_values' => ['directadmin']]
    ]),
    'A rule must reject a parent service with a different Configurable Option value.'
);
assertSameValue(
    true,
    callPrivate($plugin, 'matchesRequiredOption', [
        (object) ['options' => [(object) ['option_name' => 'ipv4', 'option_value' => null, 'qty' => 3]]],
        (object) ['required_option_name' => 'ipv4', 'required_option_values' => ['3']]
    ]),
    'Quantity Configurable Options must be eligible by their selected quantity.'
);

assertSameValue(
    true,
    is_callable([$plugin, 'tabServiceExtraRule42']),
    'Each enabled rule must be dispatchable as an independent service tab.'
);

$source = file_get_contents(dirname(__DIR__) . '/service_extras_plugin.php');
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

echo "review regressions: ok\n";
