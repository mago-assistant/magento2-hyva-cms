<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

/*
 * The module is autoloaded through the vendor/ of the Magento install it is mounted into as a path
 * repository (extensions/<module> or vendor/mago-assistant/<package>).
 */
$autoloaders = array_filter(
    [__DIR__ . '/../../../vendor/autoload.php', __DIR__ . '/../../../../vendor/autoload.php'],
    'is_file'
);
if ($autoloaders === []) {
    throw new \RuntimeException('No composer autoloader found. Run the suite from a Magento install.');
}
require reset($autoloaders);

// Magento generates this factory on first use; a unit run may come before that.
if (!class_exists(\Hyva\CmsInstanceComponents\Api\Data\InstanceComponentInterfaceFactory::class)) {
    require __DIR__ . '/Fixtures/InstanceComponentInterfaceFactory.php';
}
