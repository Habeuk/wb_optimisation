<?php

namespace Drupal\wb_optimisation\Service;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityFieldManager;
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
              $this->field_public => isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_public]),
              "type" => "storage"
            ];
          }
        }
      }
    }
    return $this->entity_to_delete;
  }

  /**
   * @return array<string>
   */
  protected function getMenu($domain_id, $count = false, $number = 10) {
    $entity_type_id = "menu";
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery();
    $domain_ovh_entities = $this->entityTypeManager->getStorage('domain_ovh_entity')->loadByProperties([
      'domain_id_drupal' => $domain_id
    ]);
    $orGroup = $query->orConditionGroup();
    $orGroup->condition('id', $domain_id, 'CONTAINS');
    if (!empty($domain_ovh_entities)) {
      /**
       * @var \Drupal\ovh_api_rest\Entity\DomainOvhEntity
       */
      $domain_ovh_entity = reset($domain_ovh_entities);
      $orGroup->condition('id', $domain_ovh_entity->getsubDomain() . '_main');
      $orGroup->condition('id', $domain_ovh_entity->getsubDomain() . '-main');
      // dd($domain_ovh_entity->getsubDomain());
    }
    $query->condition($orGroup);
    $ids = $count ? $query->count()->execute() : $query->range(0, $number)->execute();
    return $ids;
  }

  /**
   * @return array<string>|int
   */
  protected function getBlocks($domain_id, bool $count = false, $number = 10) {
    $entity_type_id = "block";
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery();

    $orGroup = $query->orConditionGroup();
    $orGroup->condition('id', $domain_id, 'CONTAINS');;
    $orGroup->condition('theme', $domain_id);

    $query->condition($orGroup);

    $ids = $count ? $query->count()->execute() : $query->range(0, $number)->execute();
    return $ids;
  }

  /**
   * @param array<int> $ovh_ids
   */
  public function deleteDomainBatch(array $ovh_ids) {
    $batch = (new BatchBuilder)
      ->setTitle('Suppression des entités liées au domaine..')
      ->setFinishCallback([self::class, '_mon_module_ajouter_hello_batch_finished']);

    foreach ($ovh_ids as $ovh_id) {
      /**
       * @var \Drupal\ovh_api_rest\Entity\DomainOvhEntity
       */
      $ovh_entity = $this->entityTypeManager->getStorage("domain_ovh_entity")->load($ovh_id);
      $domain_id = $ovh_entity->get("domain_id_drupal")->getValue()[0]["target_id"];
      $subEntities = $this->CountDomainSubEntities($domain_id);
      foreach ($subEntities as $subEntity_id => $count) {
        $limit = $subEntity_id == "block" ? 1 : 10;
        for ($i = 0; $i < $count / $limit; $i++) {
          $batch->addOperation([self::class, '_wb_optimisation_entity_delete'], [
            $domain_id,
            $subEntity_id,
            $limit,
            $count
          ]);
        }
      }
    }
    // dd($batch);
    batch_set($batch->toArray());
  }

  public  static function _mon_module_ajouter_hello_batch_finished($success, $results, $operations) {
    \Drupal::messenger()->addStatus("good");
  }


  public static function _wb_optimisation_entity_delete($domain_id, $entity_id, $number, $total, &$context) {
    if (!isset($context["result"][$entity_id]))
      $context["result"][$entity_id] = $number <= $total ? $number : $total;
    else {
      $context["result"][$entity_id] += $number;
    }
    $progress = $context["result"][$entity_id] > $total ? $total : $context["result"][$entity_id];

    $context["message"] =  t('@domain \n Suppression des @entity_type_id en cours', ["@domain" => $domain_id, "@entity_type_id" => $entity_id]);
    $optimisation_handler = \Drupal::service("wb_optimisation.handler");
    $optimisation_handler->deleteMultipleByDomain($entity_id, $number, $domain_id);
  }


  public function CountDomainSubEntities($domain_id) {
    $entities_type = $this->getStorageEntities();
    $result = [];
    $result["block"]  = $this->getBlocks($domain_id, true);
    $result["menu"]  = $this->getMenu($domain_id, true);
    foreach ($entities_type as $entity_type_id => $entity_type) {
      $entityManager = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $entityManager->getQuery();
      $query->condition($this->field_domain, $domain_id);
      if ($entity_type[$this->field_public]) {
        $query->condition($this->field_public, false);
      }
      $result[$entity_type_id] = $query->count()->execute();
    }
    $uniqueEntities = [
      "domain_ovh_entity",
      "domain",
      "config_theme_entity"
    ];
    foreach ($uniqueEntities as $entity_id) {
      $result[$entity_id] = 1;
    }
    return $result;
  }

  public function deleteMultipleByDomain($entity_id, $number, $domain_id) {
    $entityManager = $this->entityTypeManager->getStorage($entity_id);
    $entitiesToDelete = [];
    switch ($entity_id) {
      case 'menu':
        $entitiesToDelete = $this->getMenu($domain_id, false, $number);
        break;
      case "block":
        $entitiesToDelete = $this->getBlocks($domain_id, false, $number);
        break;
      case "domain_ovh_entity":
        $query = $entityManager->getQuery()->accessCheck(False);
        $query->condition('domain_id_drupal', $domain_id);
        $entitiesToDelete = $query->execute();
        break;
      case "domain":
        $query = $entityManager->getQuery()->accessCheck(False);
        $query->condition('id', $domain_id, '=');
        $entitiesToDelete = $query->range(0, $number)->execute();
        break;
      case "config_theme_entity":
        $query = $entityManager->getQuery();
        $query->condition('hostname', $domain_id);
        $ids = $query->range(0, $number)->execute();
        break;
      default:
        $subEntities = $this->getStorageEntities();
        $query = $entityManager->getQuery();
        $query->condition($this->field_domain, $domain_id);
        if ($subEntities[$entity_id][$this->field_public]) {
          $query->condition($this->field_public, false);
        }
        $entitiesToDelete = $query->range(0, $number)->execute();
        # code...
        break;
    }
    // dd($entitiesToDelete);
    $entityManager->delete($entityManager->loadMultiple(array_keys($entitiesToDelete)));
  }
}
