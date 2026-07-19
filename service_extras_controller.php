<?php

class ServiceExtrasController extends AppController
{
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();

        Language::loadLang(
            [Loader::fromCamelCase(get_class($this))],
            null,
            dirname(__FILE__) . DS . 'language' . DS
        );

        $this->view->view = 'default';
        $this->orig_structure_view = $this->structure->view;
        $this->structure->view = 'default';
    }

    protected function outputPackageOptions($client_id = null)
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri);
        }

        $this->uses(['Packages', 'Services']);
        $service = $this->Services->get((int) ($this->get[0] ?? 0));
        $package = $service
            ? $this->Packages->get(($service->package ?? null)->id ?? null)
            : null;
        if (!$service || !$package
            || ($client_id !== null && (int) $service->client_id !== (int) $client_id)
            || ($client_id === null && (int) $package->company_id !== (int) Configure::get('Blesta.company_id'))) {
            header($this->server_protocol . ' 404 Not Found');
            return false;
        }

        if (!class_exists('ServiceExtrasPlugin', false)) {
            require_once dirname(__FILE__) . DS . 'service_extras_plugin.php';
        }

        $plugin = new ServiceExtrasPlugin();
        $plugin->base_uri = $this->base_uri;
        $vars = $this->get;
        unset($vars[0], $vars[1], $vars[2]);
        $result = $plugin->getServiceExtraConfiguration(
            $service,
            (int) ($this->get[1] ?? 0),
            (int) ($this->get[2] ?? 0),
            $vars
        );
        if (($errors = $plugin->errors()) || !$result) {
            header($this->server_protocol . ' 422 Unprocessable Entity');
            echo $this->outputAsJson(['errors' => $errors ?: []]);
            return false;
        }

        echo $this->outputAsJson($result);
        return false;
    }
}
