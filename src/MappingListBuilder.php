<?php

declare(strict_types=1);

namespace Drupal\crm_bridge;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\crm_bridge\Entity\CrmBridgeMappingInterface;

/**
 * Lists CRM mappings.
 */
class MappingListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Mapping');
    $header['source'] = $this->t('Drupal');
    $header['target'] = $this->t('CRM');
    $header['direction'] = $this->t('Direction');
    $header['fields'] = $this->t('Fields');
    $header['problems'] = $this->t('Problems');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof CrmBridgeMappingInterface);

    $row['label'] = $entity->label();
    $row['source'] = $entity->getMappedEntityTypeId() . '.' . $entity->getBundle();
    $row['target'] = $entity->getConnectorId() . ':' . $entity->getRemoteObject();
    $row['direction'] = $entity->getDirection();
    $row['fields'] = (string) count($entity->getFieldMappings());

    // A mapping can be saved and later become invalid, for instance when a
    // field is renamed elsewhere in configuration. Surfacing that on the
    // collection page means an operator sees it while looking at the list
    // rather than when a sync starts writing the wrong thing.
    $problems = $entity->validateStructure();
    $row['problems'] = $problems === []
      ? $this->t('None')
      : $this->formatPlural(count($problems), '1 problem', '@count problems');

    return $row + parent::buildRow($entity);
  }

}
