<?php

namespace Klaviyo\Reclaim\Model\ResourceModel;

class Syncs extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    const SYNCED = 'SYNCED';

    /**
     * Define main table
     */
    protected function _construct()
    {
        $this->_init('kl_sync', 'id');
    }

    public function updateRowsToSynced($ids)
    {
        if (empty($ids)) {
            return;
        }

        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => 'SYNCED'],
            $where = ['id IN(?)' => $ids]
        );
    }

    public function updateRowsToRetry($ids)
    {
        if (empty($ids)) {
            return;
        }

        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => 'RETRY'],
            $where = ['id IN(?)' => $ids]
        );
    }

    public function updateRowsToFailed($ids)
    {
        if (empty($ids)) {
            return;
        }

        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => 'FAILED'],
            $where = ['id IN(?)' => $ids]
        );
    }

    public function deleteSyncedRows($ids)
    {
        if (empty($ids)) {
            return;
        }

        $this->getConnection()->delete(
            $this->getMainTable(),
            $where = ['id IN(?)' => $ids]
        );
    }

    public function deleteFailedRows($ids)
    {
        if (empty($ids)) {
            return;
        }

        $where = ['id IN(?)' => $ids];

        $this->getConnection()->delete(
            $this->getMainTable(),
            $where
        );
    }
}
