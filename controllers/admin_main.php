<?php

class AdminMain extends ServiceExtrasController
{
    public function preAction()
    {
        parent::preAction();
        $this->requireLogin();
        $this->uses([
            'PackageGroups',
            'Packages',
            'PluginManager',
            'ServiceExtras.ServiceExtraRules'
        ]);

        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
    }

    private function pluginId()
    {
        $plugins = $this->PluginManager->getByDir('service_extras', $this->company_id);
        $plugin = is_array($plugins) ? ($plugins[0] ?? null) : $plugins;
        return $plugin->id ?? null;
    }

    private function viewVars($rule = null)
    {
        if (!$rule) {
            return (object) [
                'name' => '',
                'capability' => '',
                'parent_package_ids' => [],
                'parent_group_ids' => [],
                'product_group_id' => '',
                'required_option_name' => '',
                'required_option_values' => '',
                'enabled' => '1'
            ];
        }

        if (is_array($rule->required_option_values ?? null)) {
            $rule->required_option_values = implode(', ', $rule->required_option_values);
        }
        return $rule;
    }

    private function selectionLists()
    {
        $packages = [];
        foreach ($this->Packages->getAll(
            $this->company_id,
            ['name' => 'ASC'],
            null,
            null,
            ['hidden' => true]
        ) as $package) {
            $label = ($package->name ?? ('Package ' . $package->id)) . ' (#' . $package->id . ')';
            if (($package->status ?? 'active') !== 'active') {
                $label .= ' [' . ucfirst($package->status) . ']';
            }
            $packages[(int) $package->id] = $label;
        }

        $groups = [];
        foreach ($this->PackageGroups->getAll($this->company_id) as $group) {
            $groups[(int) $group->id] = ($group->name ?? ('Group ' . $group->id))
                . ' (#' . $group->id . ', ' . ucfirst($group->type) . ')';
        }

        $this->set('packages', $packages);
        $this->set('groups', $groups);
    }

    public function index()
    {
        $this->set('rules', $this->ServiceExtraRules->getAll($this->company_id));
        $this->selectionLists();
        return $this->renderAjaxWidgetIfAsync();
    }

    public function add()
    {
        $vars = $this->viewVars();
        if (!empty($this->post)) {
            $data = $this->post;
            $data['company_id'] = $this->company_id;
            $data['enabled'] = isset($data['enabled']) ? '1' : '0';
            $id = $this->ServiceExtraRules->add($data);
            if (($errors = $this->ServiceExtraRules->errors())) {
                $this->setMessage('error', $errors);
                $vars = (object) $data;
            } else {
                $this->ServiceExtraRules->syncPluginParentAssociations($this->pluginId(), $this->company_id);
                $this->flashMessage('message', Language::_('AdminMain.!success.added', true));
                $this->redirect($this->base_uri . 'plugin/service_extras/admin_main/');
            }
        }

        $this->selectionLists();
        $this->set('vars', $vars);
        $this->set('rule_id', null);
    }

    public function edit()
    {
        $id = (int) ($this->get[0] ?? 0);
        $rule = $this->ServiceExtraRules->get($id, $this->company_id);
        if (!$rule) {
            $this->redirect($this->base_uri . 'plugin/service_extras/admin_main/');
        }

        $vars = $this->viewVars($rule);
        if (!empty($this->post)) {
            $data = $this->post;
            $data['company_id'] = $this->company_id;
            $data['enabled'] = isset($data['enabled']) ? '1' : '0';
            $this->ServiceExtraRules->edit($id, $data);
            if (($errors = $this->ServiceExtraRules->errors())) {
                $this->setMessage('error', $errors);
                $vars = (object) $data;
            } else {
                $this->ServiceExtraRules->syncPluginParentAssociations($this->pluginId(), $this->company_id);
                $this->flashMessage('message', Language::_('AdminMain.!success.updated', true));
                $this->redirect($this->base_uri . 'plugin/service_extras/admin_main/');
            }
        }

        $this->selectionLists();
        $this->set('vars', $vars);
        $this->set('rule_id', $id);
    }

    public function delete()
    {
        $id = (int) ($this->post['id'] ?? 0);
        if ($id) {
            $this->ServiceExtraRules->delete($id, $this->company_id);
            $this->ServiceExtraRules->syncPluginParentAssociations($this->pluginId(), $this->company_id);
            $this->flashMessage('message', Language::_('AdminMain.!success.deleted', true));
        }
        $this->redirect($this->base_uri . 'plugin/service_extras/admin_main/');
    }
}
