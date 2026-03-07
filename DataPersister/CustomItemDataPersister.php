<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\DataPersister;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;

final class CustomItemDataPersister implements ProcessorInterface
{
    public function __construct(private CustomItemModel $customItemModel)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof CustomItem);

        if ($operation instanceof DeleteOperationInterface) {
            $this->customItemModel->delete($data);

            return null;
        }

        $this->customItemModel->save($data);

        return $data;
    }
}
