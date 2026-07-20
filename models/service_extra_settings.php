<?php

class ServiceExtraSettings extends AppModel
{
    public const DEFAULT_UNPAID_ORDER_TTL_HOURS = 12;
    public const MINIMUM_UNPAID_ORDER_TTL_HOURS = 1;
    public const MAXIMUM_UNPAID_ORDER_TTL_HOURS = 720;

    private const UNPAID_ORDER_TTL_KEY = 'service_extras.unpaid_order_ttl_hours';

    public function __construct()
    {
        parent::__construct();
        Loader::loadModels($this, ['Companies']);
        Language::loadLang(
            'service_extra_settings',
            null,
            dirname(__FILE__) . DS . '..' . DS . 'language' . DS
        );
    }

    public function getUnpaidOrderTtlHours($company_id)
    {
        $setting = $this->Companies->getSetting(
            (int) $company_id,
            self::UNPAID_ORDER_TTL_KEY
        );
        $hours = $setting ? (int) $setting->value : self::DEFAULT_UNPAID_ORDER_TTL_HOURS;

        if (!$this->isValidUnpaidOrderTtl($hours)) {
            return self::DEFAULT_UNPAID_ORDER_TTL_HOURS;
        }

        return $hours;
    }

    public function setUnpaidOrderTtlHours($company_id, $hours)
    {
        $vars = ['unpaid_order_ttl_hours' => $hours];
        $this->Input->setRules([
            'unpaid_order_ttl_hours' => [
                'valid' => [
                    'rule' => [[$this, 'isValidUnpaidOrderTtl']],
                    'message' => $this->_('ServiceExtraSettings.!error.unpaid_order_ttl_hours.valid')
                ]
            ]
        ]);

        if (!$this->Input->validates($vars)) {
            return false;
        }

        $this->Companies->setSetting(
            (int) $company_id,
            self::UNPAID_ORDER_TTL_KEY,
            (int) $hours
        );
        return true;
    }

    public function installDefaults($company_id)
    {
        if (!$this->Companies->getSetting((int) $company_id, self::UNPAID_ORDER_TTL_KEY)) {
            $this->Companies->setSetting(
                (int) $company_id,
                self::UNPAID_ORDER_TTL_KEY,
                self::DEFAULT_UNPAID_ORDER_TTL_HOURS
            );
        }
    }

    public function uninstall($company_id)
    {
        $this->Companies->unsetSetting((int) $company_id, self::UNPAID_ORDER_TTL_KEY);
    }

    public function isValidUnpaidOrderTtl($hours)
    {
        return preg_match('/^\d+$/', (string) $hours) === 1
            && (int) $hours >= self::MINIMUM_UNPAID_ORDER_TTL_HOURS
            && (int) $hours <= self::MAXIMUM_UNPAID_ORDER_TTL_HOURS;
    }
}
