<?php

class ServiceExtraOrders extends AppModel
{
    public function add(array $vars)
    {
        $now = gmdate('Y-m-d H:i:s');
        $vars = array_merge($vars, [
            'status' => 'pending',
            'last_error' => null,
            'date_added' => $now,
            'date_updated' => $now
        ]);

        $this->Record->insert('service_extra_orders', $vars, [
            'company_id', 'rule_id', 'parent_service_id', 'service_id', 'invoice_id',
            'status', 'last_error', 'date_added', 'date_updated'
        ]);

        return $this->Record->lastInsertId();
    }

    public function getExpiredPending($company_id, $hours)
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, (int) $hours) * 3600);

        return $this->Record->select()->from('service_extra_orders')
            ->where('company_id', '=', (int) $company_id)
            ->where('status', 'in', ['pending', 'error'])
            ->where('date_added', '<=', $cutoff)
            ->order(['date_added' => 'ASC'])
            ->fetchAll();
    }

    public function setStatus($id, $status, $last_error = null)
    {
        $this->Record->where('id', '=', (int) $id)->update('service_extra_orders', [
            'status' => $status,
            'last_error' => $last_error,
            'date_updated' => gmdate('Y-m-d H:i:s')
        ], ['status', 'last_error', 'date_updated']);
    }
}
