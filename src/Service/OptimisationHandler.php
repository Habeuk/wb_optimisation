<?php

namespace Drupal\wb_optimisation\Service;

use Drupal\Core\Entity\Annotation\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service description.
 */
class OptimisationHandler {

  /**
   * @var string
   */
  protected $field_domain;

  /**
   * @var array
   */
  protected $entity_to_delete =  [];
  /**
   * @var string 
   */
  protected $field_public = "is_public";
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var EntityFieldManager $entityFieldManager
   */

  protected $entityFieldManager;
  /**
   * Constructs an OptimisationHandler object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManager $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->field_domain = \Drupal\domain_source\DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD;
  }

  /**
   * @return array
   */
  protected function getStorageEntities() {
    $entities = $this->entityTypeManager->getDefinitions();
    if (!$this->entity_to_delete) {

      $entities = array_filter($entities, function (EntityTypeInterface $entity) {
        if ($entity->getBaseTable()) {
          $entity_id = $entity->id();
          return isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_domain]);
        }
        return false;
      });

      foreach ($entities as $key => $entity) {
        if ($entity->getBaseTable()) {
          $entity_id = $entity->id();
          if (isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_domain])) {
            $this->entity_to_delete[$key] = [
              $this->field_public => isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_public])
            ];
          }
        }
      }
    }
    return $this->entity_to_delete;
  }


  public function CountDomainSubEntities($domain_id) {
    $entities_type = $this->getStorageEntities();
    $result = [];
    foreach ($entities_type as $entity_type_id => $entity_type) {
      $entityManager = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $entityManager->getQuery();
      $query->condition($this->field_domain, $domain_id);
      if ($entity_type[$this->field_public]) {
        $query->condition($this->field_public, false);
      }
      $result[$entity_type_id] = $query->count()->execute();
    }
    return $result;
  }

  public function deleteMultipleByDomain($entity_id, $number, $domain_id) {
    $entityManager = $this->entityTypeManager->getStorage($entity_id);
    $subEntities = $this->getStorageEntities();
    $query = $entityManager->getQuery();
    $query->condition($this->field_domain, $domain_id);
    if ($subEntities[$entity_id][$this->field_public]) {
      $query->condition($this->field_public, false);
    }
    $entitiesToDelete = $query->range(0, $number)->execute();
    $entityManager->delete($entityManager->loadMultiple($entitiesToDelete));
  }
}
