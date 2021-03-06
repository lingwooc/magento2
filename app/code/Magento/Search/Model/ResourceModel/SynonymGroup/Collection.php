<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Search\Model\ResourceModel\SynonymGroup;

/**
 * Collection for SynonymGroup
 */
class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'group_id';

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            \Magento\Search\Model\SynonymGroup::class,
            \Magento\Search\Model\ResourceModel\SynonymGroup::class
        );
    }
}
