<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\HyvaCms\Test\Unit\Service\Skills\InstanceComponent;

use Hyva\CmsInstanceComponents\Api\Data\InstanceComponentInterface;
use Hyva\CmsInstanceComponents\Api\Data\InstanceComponentInterfaceFactory;
use Hyva\CmsInstanceComponents\Api\InstanceComponentRepositoryInterface;
use Hyva\CmsInstanceComponents\Model\AiSafeDefinitionValidator;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\HyvaCms\Service\Skills\InstanceComponent\CreateAction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Runs against Hyvä's real validator, so a rule change upstream shows up here.
 */
#[AllowMockObjectsWithoutExpectations]
class CreateActionTest extends TestCase
{
    private const VALID = [
        'action' => 'create',
        'identifier' => 'usp-bar',
        'label' => 'USP bar',
        'fields' => [['key' => 'text', 'type' => 'text', 'label' => 'Text']],
        'template' => '<section class="flex gap-4"><p>{text}</p></section>',
    ];

    private InstanceComponentRepositoryInterface&MockObject $repository;
    private InstanceComponentInterface&MockObject $component;
    private TypeListInterface&MockObject $cacheTypeList;
    private CreateAction $action;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(InstanceComponentRepositoryInterface::class);
        $this->component = $this->createMock(InstanceComponentInterface::class);
        $factory = $this->createMock(InstanceComponentInterfaceFactory::class);
        $factory->method('create')->willReturn($this->component);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        $this->action = new CreateAction(
            new AiSafeDefinitionValidator(),
            $this->repository,
            $factory,
            $this->cacheTypeList,
            new Json()
        );
    }

    public function testRefusesAlpineInTemplateBeforeConfirmation(): void
    {
        $params = ['template' => '<div x-data="dropdown"><p>{text}</p></div>'] + self::VALID;

        $refusal = $this->action->findRefusal($params);

        $this->assertStringContainsString('x-data', $refusal['error'] ?? '');
    }

    public function testNeverOverwritesAnExistingIdentifier(): void
    {
        $this->repository->method('getByIdentifier')->willReturn($this->component);
        $this->repository->expects($this->never())->method('save');

        $this->assertStringContainsString('already exists', $this->action->findRefusal(self::VALID)['error'] ?? '');
        $this->assertStringContainsString('already exists', $this->action->execute(self::VALID, 1)['error'] ?? '');
    }

    public function testSavesInactiveAndCleansHyvaCmsCache(): void
    {
        $this->repository->method('getByIdentifier')->willThrowException(new NoSuchEntityException());
        $this->component->expects($this->once())->method('setIsActive')->with(false);
        $this->component->expects($this->once())->method('setIdentifier')->with('usp-bar');
        $this->component->method('getId')->willReturn(7);
        $this->repository->expects($this->once())->method('save')->willReturn($this->component);
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('hyva_cms');

        $this->assertNull($this->action->findRefusal(self::VALID));
        $result = $this->action->execute(self::VALID, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['id']);
        $this->assertFalse($result['is_active']);
    }
}
