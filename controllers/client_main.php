<?php

class ClientMain extends ServiceExtrasController
{
    public function preAction()
    {
        parent::preAction();
        $this->requireLogin();
        $this->client_id = (int) $this->Session->read('blesta_client_id');
        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
    }

    public function packageOptions()
    {
        return $this->outputPackageOptions($this->client_id);
    }
}
