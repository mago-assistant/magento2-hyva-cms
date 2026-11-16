<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace Hyva\CmsInstanceComponents\Api\Data;

/**
 * Stand-in for the factory Magento generates on first use, for unit runs that come before that
 */
class InstanceComponentInterfaceFactory
{
    /**
     * @param array $data
     * @return InstanceComponentInterface
     */
    public function create(array $data = []): InstanceComponentInterface
    {
        throw new \LogicException('Mock this factory in the test.');
    }
}
