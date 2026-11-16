<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Test\Unit\Service\Skills\Content;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\HyvaCms\Service\Skills\Content\InstanceComponent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstanceComponentTest extends TestCase
{
    public static function aclCases(): array
    {
        return [
            'reading the rules' => [['action' => 'get_rules'], InstanceComponent::ACL_VIEW],
            'creating' => [['action' => 'create'], InstanceComponent::ACL_SAVE],
            'no action fails closed' => [[], InstanceComponent::ACL_SAVE],
            'unknown action fails closed' => [['action' => 'delete'], InstanceComponent::ACL_SAVE],
        ];
    }

    #[DataProvider('aclCases')]
    public function testAclPerAction(array $input, string $expected): void
    {
        $this->assertSame($expected, $this->skill(true)->getMagentoAcl($input));
    }

    public function testOnlyAvailableWhileHyvaInstanceComponentsIsEnabled(): void
    {
        $this->assertTrue($this->skill(true)->isAvailable());
        $this->assertFalse($this->skill(false)->isAvailable());
    }

    private function skill(bool $hyvaEnabled): InstanceComponent
    {
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnMap([['Hyva_CmsInstanceComponents', $hyvaEnabled]]);

        return new InstanceComponent($this->createStub(AuthorizationInterface::class), $moduleManager);
    }
}
