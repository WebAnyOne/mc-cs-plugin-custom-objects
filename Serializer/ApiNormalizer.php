<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Serializer;

use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\IriConverterInterface;
use Doctrine\ORM\EntityManager;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class ApiNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    private NormalizerInterface $decorated;

    private IriConverterInterface $iriConverter;

    public function __construct(
        NormalizerInterface $decorated,
        private CustomFieldTypeProvider $customFieldTypeProvider,
        private CustomItemModel $customItemModel,
        IriConverterInterface $iriConverter,
        private EntityManager $em)
    {
        if (!$decorated instanceof DenormalizerInterface) {
            throw new InvalidArgumentException(sprintf('The decorated normalizer must implement the %s.', DenormalizerInterface::class));
        }

        $this->decorated    = $decorated;
        $this->iriConverter = $iriConverter;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    /**
     * @return array<string, mixed>|string|int|float|bool|\ArrayObject|null
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if ($object instanceof CustomItem) {
            return $this->normalizeCustomItem($object, $format, $context);
        }

        return $this->decorated->normalize($object, $format, $context);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->decorated->supportsDenormalization($data, $type, $format, $context);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ExceptionInterface
     */
    public function denormalize(mixed $data, string $class, ?string $format = null, array $context = []): mixed
    {
        if (CustomItem::class === $class) {
            return $this->denormalizeCustomItem($data, $class, $format, $context);
        }

        if (CustomField::class === $class) {
            return $this->denormalizeCustomField($data, $class, $format, $context);
        }

        if (CustomFieldOption::class === $class) {
            return $this->denormalizeCustomFieldOption($data, $class, $format, $context);
        }

        return $this->decorated->denormalize($data, $class, $format, $context);
    }

    /**
     * @return array<string, string[]>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [CustomItem::class => true, CustomField::class => true, CustomFieldOption::class => true];
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|string|int|float|bool|\ArrayObject|null
     */
    private function normalizeCustomItem(CustomItem $object, ?string $format, array $context): array|string|int|float|bool|\ArrayObject|null
    {
        $objectCustomItem = $this->customItemModel->fetchEntity($object->getId());
        $normalizedObject = $this->decorated->normalize($objectCustomItem, $format, $context);
        if (is_array($normalizedObject) && array_key_exists('fieldValues', $normalizedObject)) {
            foreach ($normalizedObject['fieldValues'] as &$values) {
                $customField      = $this->em->find(CustomField::class, (int) $values['id']);
                $values['id']     = $this->iriConverter->getIriFromResource($customField);
            }
        }

        return $normalizedObject;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @throws ExceptionInterface
     */
    private function denormalizeCustomItem(array $data, string $class, ?string $format, array $context): mixed
    {
        if (array_key_exists('fieldValues', $data) && is_iterable($data['fieldValues'])) {
            foreach ($data['fieldValues'] as &$values) {
                $values['id'] = $this->iriConverter->getResourceFromIri($values['id'])->getId();
            }
        }

        return $this->decorated->denormalize($data, $class, $format, $context);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @throws ExceptionInterface
     * @throws InvalidArgumentException
     */
    private function denormalizeCustomField(array $data, string $class, ?string $format, array $context): mixed
    {
        $optionEntitiesCollection = null;
        $defaultValue             = null;
        if (array_key_exists('options', $data) && is_array($data['options']) && count($data['options']) > 0) {
            $options = $data['options'];
            unset($data['options']);
            $optionEntities = [];
            foreach ($options as $option) {
                $optionEntities[] = $this->decorated->denormalize($option, CustomFieldOption::class, $format, $context);
            }
            $optionEntitiesCollection = new \Doctrine\Common\Collections\ArrayCollection($optionEntities);
        } elseif (array_key_exists('options', $data) && is_array($data['options'])) {
            unset($data['options']);
        }
        if (array_key_exists('defaultValue', $data)) {
            $defaultValue = $data['defaultValue'];
            unset($data['defaultValue']);
        }

        $entity = $this->decorated->denormalize($data, $class, $format, $context);

        try {
            if (array_key_exists('type', $data)) {
                $type       = $data['type'];
                $typeObject = $this->customFieldTypeProvider->getType($type);
                $entity->setTypeObject($typeObject);
            }
            if ($optionEntitiesCollection) {
                foreach ($optionEntitiesCollection as $optionEntity) {
                    $entity->addOption($optionEntity);
                }
            }
            if ($defaultValue) {
                $entity->setDefaultValue($defaultValue);
            }
        } catch (NotFoundException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
        if (!$entity->getTypeObject()) {
            throw new InvalidArgumentException('Custom field type is missing.');
        }

        return $entity;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @throws ExceptionInterface
     * @throws InvalidArgumentException
     */
    private function denormalizeCustomFieldOption(array $data, string $class, ?string $format, array $context): mixed
    {
        $value = null;
        if (array_key_exists('value', $data)) {
            $value = $data['value'];
        }
        $customFieldId = null;
        if (array_key_exists('customField', $data)) {
            $customField       = $data['customField'];
            $customFieldEntity = $this->iriConverter->getResourceFromIri($customField);
            if ($customFieldEntity instanceof CustomField) {
                $customFieldId = $customFieldEntity->getId();
            }
        }
        $existingId = (bool) count($this->em->getRepository(CustomFieldOption::class)->findBy(['customField' => $customFieldId, 'value' => $value]));
        if ($existingId) {
            throw new InvalidArgumentException('Custom field and value is not unique.');
        }

        return $this->decorated->denormalize($data, $class, $format, $context);
    }
}
