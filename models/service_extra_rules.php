<?php

class ServiceExtraRules extends AppModel
{
    public function __construct()
    {
        parent::__construct();
        Language::loadLang('service_extra_rules', null, dirname(__FILE__) . DS . '..' . DS . 'language' . DS);
    }

    private function ids($value)
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\s,]+/', trim((string) $value));
        }

        return array_values(array_unique(array_filter(array_map('intval', $items))));
    }

    private function decodeRule($rule)
    {
        foreach (['parent_package_ids', 'product_package_ids'] as $field) {
            $rule->{$field} = $this->ids(json_decode($rule->{$field} ?? '[]', true) ?: []);
        }

        return $rule;
    }

    private function rules(array $vars)
    {
        $company_id = (int) ($vars['company_id'] ?? 0);

        return [
            'company_id' => [
                'valid' => [
                    'rule' => 'is_numeric',
                    'message' => Language::_('ServiceExtraRules.!error.company_id', true)
                ]
            ],
            'name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('ServiceExtraRules.!error.name', true)
                ],
                'length' => [
                    'rule' => ['maxLength', 255],
                    'message' => Language::_('ServiceExtraRules.!error.name_length', true)
                ]
            ],
            'parent_package_ids' => [
                'required' => [
                    'rule' => [[$this, 'hasSelection']],
                    'message' => Language::_('ServiceExtraRules.!error.parent_selection', true)
                ],
                'valid' => [
                    'rule' => [[$this, 'validCompanyPackageIds'], $company_id],
                    'message' => Language::_('ServiceExtraRules.!error.parent_packages', true)
                ]
            ],
            'product_group_id' => [
                'valid' => [
                    'rule' => [[$this, 'validProductGroup'], $company_id],
                    'message' => Language::_('ServiceExtraRules.!error.product_group', true)
                ]
            ],
            'product_package_ids' => [
                'required' => [
                    'rule' => [[$this, 'hasSelection']],
                    'message' => Language::_('ServiceExtraRules.!error.product_selection', true)
                ],
                'valid' => [
                    'rule' => [
                        [$this, 'validProductPackageIds'],
                        $vars['product_group_id'] ?? 0,
                        $company_id
                    ],
                    'message' => Language::_('ServiceExtraRules.!error.product_packages', true)
                ]
            ]
        ];
    }

    public function hasSelection($package_ids)
    {
        return !empty($this->ids($package_ids));
    }

    public function validCompanyPackageIds($value, $company_id)
    {
        $ids = $this->ids($value);
        if (empty($ids)) {
            return true;
        }
        if ((int) $company_id < 1) {
            return false;
        }

        $count = $this->Record->select('id')->from('packages')
            ->where('id', 'in', $ids)
            ->where('company_id', '=', (int) $company_id)
            ->numResults();
        return (int) $count === count($ids);
    }

    public function validProductGroup($group_id, $company_id)
    {
        $group_id = (int) $group_id;
        if ($group_id < 1 || (int) $company_id < 1) {
            return false;
        }

        $count = $this->Record->select('id')->from('package_groups')
            ->where('id', '=', $group_id)
            ->where('company_id', '=', (int) $company_id)
            ->numResults();
        return (int) $count === 1;
    }

    public function validProductPackageIds($value, $group_id, $company_id)
    {
        $ids = $this->ids($value);
        if (empty($ids)) {
            return true;
        }
        if ((int) $group_id < 1 || (int) $company_id < 1) {
            return false;
        }

        $count = $this->Record->select('packages.id')->from('packages')
            ->innerJoin('package_group', 'package_group.package_id', '=', 'packages.id', false)
            ->innerJoin('package_groups', 'package_groups.id', '=', 'package_group.package_group_id', false)
            ->where('packages.id', 'in', $ids)
            ->where('packages.company_id', '=', (int) $company_id)
            ->where('package_groups.company_id', '=', (int) $company_id)
            ->where('package_group.package_group_id', '=', (int) $group_id)
            ->numResults();
        return (int) $count === count($ids);
    }

    private function format(array $vars)
    {
        $vars['name'] = trim((string) ($vars['name'] ?? ''));
        foreach (['parent_package_ids', 'product_package_ids'] as $field) {
            $vars[$field] = json_encode($this->ids($vars[$field] ?? []));
        }
        $vars['product_group_id'] = (int) ($vars['product_group_id'] ?? 0);
        $vars['enabled'] = ($vars['enabled'] ?? '0') === '1' ? '1' : '0';
        return $vars;
    }

    private function normalize(array $vars)
    {
        foreach (['name'] as $field) {
            if (isset($vars[$field]) && is_scalar($vars[$field])) {
                $vars[$field] = trim((string) $vars[$field]);
            }
        }
        return $vars;
    }

    public function add(array $vars)
    {
        $vars = $this->normalize($vars);
        $this->Input->setRules($this->rules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        $vars = $this->format($vars);
        $vars['date_added'] = date('c');
        $vars['date_updated'] = $vars['date_added'];
        $this->Record->insert('service_extra_rules', $vars, [
            'company_id', 'name', 'parent_package_ids', 'product_group_id',
            'product_package_ids', 'enabled',
            'date_added', 'date_updated'
        ]);
        return $this->Record->lastInsertId();
    }

    public function edit($id, array $vars)
    {
        $vars = $this->normalize($vars);
        $this->Input->setRules($this->rules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        $vars = $this->format($vars);
        $vars['date_updated'] = date('c');
        $this->Record->where('id', '=', (int) $id)
            ->where('company_id', '=', (int) $vars['company_id'])
            ->update('service_extra_rules', $vars, [
                'name', 'parent_package_ids', 'product_group_id', 'product_package_ids',
                'enabled', 'date_updated'
            ]);
        return $id;
    }

    public function delete($id, $company_id)
    {
        $this->Record->from('service_extra_rules')
            ->where('id', '=', (int) $id)
            ->where('company_id', '=', (int) $company_id)
            ->delete();
    }

    public function get($id, $company_id)
    {
        $rule = $this->Record->select()->from('service_extra_rules')
            ->where('id', '=', (int) $id)
            ->where('company_id', '=', (int) $company_id)
            ->fetch();
        return $rule ? $this->decodeRule($rule) : null;
    }

    public function getAll($company_id, $enabled_only = false)
    {
        $query = $this->Record->select()->from('service_extra_rules')
            ->where('company_id', '=', (int) $company_id);
        if ($enabled_only) {
            $query->where('enabled', '=', '1');
        }

        $rules = $query->order(['name' => 'ASC'])->fetchAll();
        foreach ($rules as &$rule) {
            $rule = $this->decodeRule($rule);
        }
        return $rules;
    }

    public function getMatching($company_id, $package_id)
    {
        $matches = [];
        foreach ($this->getAll($company_id, true) as $rule) {
            if (in_array((int) $package_id, $rule->parent_package_ids, true)) {
                $matches[] = $rule;
            }
        }
        return $matches;
    }

    public function attachPluginToRuleParents($plugin_id, array $vars)
    {
        $plugin_id = (int) $plugin_id;
        if ($plugin_id < 1) {
            return;
        }

        $package_ids = $this->ids($vars['parent_package_ids'] ?? []);
        $company_id = (int) ($vars['company_id'] ?? 0);

        $valid_package_ids = [];
        if (!empty($package_ids)) {
            $packages = $this->Record->select('id')->from('packages')
                ->where('id', 'in', array_unique($package_ids))
                ->where('company_id', '=', $company_id)
                ->fetchAll();
            foreach ($packages as $package) {
                $valid_package_ids[] = (int) $package->id;
            }
        }

        foreach ($valid_package_ids as $package_id) {
            $values = ['package_id' => $package_id, 'plugin_id' => $plugin_id];
            $this->Record->duplicate('plugin_id', '=', $plugin_id)
                ->insert('package_plugins', $values);
        }
    }

    public function syncPluginParentAssociations($plugin_id, $company_id)
    {
        $plugin_id = (int) $plugin_id;
        $company_id = (int) $company_id;
        if ($plugin_id < 1 || $company_id < 1) {
            return;
        }

        $company_packages = $this->Record->select('id')->from('packages')
            ->where('company_id', '=', $company_id)
            ->fetchAll();
        $company_package_ids = [];
        foreach ($company_packages as $package) {
            $company_package_ids[] = (int) $package->id;
        }
        if (!empty($company_package_ids)) {
            $this->Record->from('package_plugins')
                ->where('plugin_id', '=', $plugin_id)
                ->where('package_id', 'in', $company_package_ids)
                ->delete();
        }

        foreach ($this->getAll($company_id, true) as $rule) {
            $this->attachPluginToRuleParents($plugin_id, [
                'company_id' => $company_id,
                'parent_package_ids' => $rule->parent_package_ids
            ]);
        }
    }
}
