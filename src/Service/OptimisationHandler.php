<?php

namespace Drupal\wb_optimisation\Service;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Service description.
 */
class OptimisationHandler {
  
  /**
   *
   * @var string
   */
  protected $field_domain;
  
  /**
   *
   * @var string
   */
  protected static $query_tag = "unhandle_pass";
  
  /**
   *
   * @var array
   */
  protected $entity_to_delete = [];
  /**
   *
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
   *
   * @var EntityFieldManager $entityFieldManager
   */
  protected $entityFieldManager;
  
  /**
   * Permet de determiner si le cache est DEJA encours ou pas.
   *
   * @var boolean
   */
  protected $DeleteIsRunning = true;
  
  /**
   * --
   */
  protected $DomainsToDelete = null;
  
  /**
   *
   * @var \Drupal\Core\Logger\LoggerChannel
   */
  protected $LoggerChannel;
  
  /**
   * Constructs an OptimisationHandler object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *        The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManager $entity_field_manager, LoggerChannel $LoggerChannel) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->field_domain = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
    $this->LoggerChannel = $LoggerChannel;
  }
  
  /**
   *
   * @return array
   */
  protected function getStorageEntities() {
    $entities = $this->entityTypeManager->getDefinitions();
    if (!$this->entity_to_delete) {
      
      $entities = array_filter($entities, function (EntityTypeInterface $entity) {
        if ($entity->getBaseTable()) {
          $entity_id = $entity->id();
          return isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_domain]) || isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)["domain_id"]);
        }
        return false;
      });
      foreach ($entities as $key => $entity) {
        if ($entity->getBaseTable()) {
          $entity_id = $entity->id();
          $hasDomainId = isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)["domain_id"]);
          if (isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_domain]) || $hasDomainId) {
            $this->entity_to_delete[$key] = [
              $this->field_public => isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)[$this->field_public]),
              "domain_id" => $hasDomainId,
              "type" => "storage"
            ];
          }
        }
      }
    }
    return $this->entity_to_delete;
  }
  
  /**
   *
   * @return array<string>
   */
  protected function getMenu($domain_id, $count = false, $number = -1) {
    $entity_type_id = "menu";
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery();
    $query->addTag(self::$query_tag);
    $domain_ovh_entities = $this->entityTypeManager->getStorage('domain_ovh_entity')->loadByProperties([
      'domain_id_drupal' => $domain_id
    ]);
    $orGroup = $query->orConditionGroup();
    $orGroup->condition('id', $domain_id, 'CONTAINS');
    if (!empty($domain_ovh_entities)) {
      /**
       *
       * @var \Drupal\ovh_api_rest\Entity\DomainOvhEntity $domain_ovh_entity
       */
      $domain_ovh_entity = reset($domain_ovh_entities);
      $orGroup->condition('id', $domain_ovh_entity->getsubDomain() . '_main');
      $orGroup->condition('id', $domain_ovh_entity->getsubDomain() . '-main');
    }
    $query->condition($orGroup);
    if ($count)
      $ids = $query->count()->execute();
    else
      $ids = $number === -1 ? $query->execute() : $query->range(0, $number)->execute();
    return $ids;
  }
  
  /**
   *
   * @return array<string>|int
   */
  protected function getBlocks($domain_id, bool $count = false, $number = -1) {
    $entity_type_id = "block";
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery();
    $query->addTag(self::$query_tag);
    $orGroup = $query->orConditionGroup();
    $orGroup->condition('id', $domain_id, 'CONTAINS');
    $orGroup->condition('theme', $domain_id);
    $query->condition($orGroup);
    if ($count)
      $ids = $query->count()->execute();
    else
      $ids = $number === -1 ? $query->execute() : $query->range(0, $number)->execute();
    return $ids;
  }
  
  /**
   *
   * @param array<int> $ovh_ids
   */
  public function deleteDomainBatch(array $ovh_ids) {
    $batch = (new BatchBuilder())->setTitle(' Suppression des entités liées au domaine... ')->setFinishCallback([
      self::class,
      '_deletion_batch_finished'
    ]);
    $DomainsToDelte = [];
    $ignoreDeleteEntities = [
      'domain',
      'config_theme_entity'
    ];
    foreach ($ovh_ids as $ovh_id) {
      /**
       *
       * @var \Drupal\ovh_api_rest\Entity\DomainOvhEntity
       */
      $ovh_entity = $this->entityTypeManager->getStorage("domain_ovh_entity")->load($ovh_id);
      $domain_id = $ovh_entity->get("domain_id_drupal")->getValue()[0]["target_id"];
      $DomainsToDelte[$domain_id] = $ovh_id;
      $subEntities = $this->CountDomainSubEntities($domain_id);
      
      foreach ($subEntities as $subEntity_id => $count) {
        $limit = $subEntity_id == "block" ? 1 : 10;
        for ($i = 0; $i < $count / $limit; $i++) {
          // On ignore certaines entites car elles serront supprimer
          // automatiquement à la suite d'autres.
          if (in_array($subEntity_id, $ignoreDeleteEntities))
            continue;
          $batch->addOperation([
            self::class,
            '_wb_optimisation_entity_delete'
          ], [
            $domain_id,
            $subEntity_id,
            $limit,
            $count
          ]);
        }
      }
    }
    $this->SetCacheDomainToDelete($DomainsToDelte);
    if ($this->DeleteIsRunning) {
      \Drupal::messenger()->addWarning(" Il ya déjà une suppression encours. Svp, veillez patiente 30 à 1h et re-tester ");
      return false;
    }
    // L'idee est de pouvoir suivre.
    \Stephane888\Debug\debugLog::symfonyDebug([
      'bash_generer' => $batch->toArray(),
      'ovh_ids' => $ovh_ids,
      'subEntities' => $subEntities
    ], 'deleteDomainBatch', true);
    //
    batch_set($batch->toArray());
  }
  
  /**
   * Afin de verifier de maniere efficace que les contenus supprimer ont le
   * droits d'etre supprimer.
   * On les mettres en cache, ainsi avant la suppresion de chaque domaine, on
   * pourra verifier.
   *
   * @param array $subEntities
   * @param array $subEntities
   * @return array // les domaines à supprimer.
   */
  protected function SetCacheDomainToDelete(array $subEntities) {
    $domains = $this->getCacheDomains();
    if (!empty($domains)) {
      // Un cache est deja encours.
      $this->DeleteIsRunning = true;
    }
    else {
      // Aucun cache encours.
      $this->DeleteIsRunning = false;
      // On met en cache pendant 180 minutes.
      $this->setCache('domains', $subEntities, time() + 180 * 60);
      return $subEntities;
    }
  }
  
  /**
   * --
   */
  public function clearCahes() {
    /**
     *
     * @var \Drupal\Core\Cache\ApcuBackend $cache
     */
    $cache = \Drupal::service("cache.backend.database")->get('wb_optimisation_delete_cache');
    $cache->deleteAll();
  }
  
  /**
   * Le but est de verifier que l'entité à supprimer doit effectivement
   * etre supprimer.
   */
  public function ValidationEntitiToDelete(EntityInterface $entity) {
    $DomainsToDelete = $this->getCacheDomains();
    if ($entity instanceof ContentEntityInterface) {
      $entityTypeId = $entity->getEntityTypeId();
      switch ($entityTypeId) {
        case 'menu_link_content':
        case 'path_alias':
          return true;
        case 'domain_ovh_entity__':
          $domain = $entity->getDomainIdDrupal();
          if (!isset($DomainsToDelete[$domain])) {
            $message = " Ne peut etre supprimer, car n'appartient à aucun des domaines definit pour la suppresion ";
            $this->runErrorEntity($entity, $message);
          }
          return true;
        case 'domain':
          $domain = $entity->id();
          if (!isset($DomainsToDelete[$domain])) {
            $message = " Ne peut etre supprimer, car n'appartient à aucun des domaines definit pour la suppresion ";
            $this->runErrorEntity($entity, $message);
          }
          return true;
        case 'config_theme_entity':
          $domain = $entity->getHostname();
          if (!isset($DomainsToDelete[$domain])) {
            $message = " Ne peut etre supprimer, car n'appartient à aucun des domaines definit pour la suppresion ";
            $this->runErrorEntity($entity, $message);
          }
          return true;
        default:
          if (!$entity->hasField($this->field_domain)) {
            $message = " Ne peut etre supprimer, car ne dispose pas de champs :'" . $this->field_domain;
            $this->runErrorEntity($entity, $message, $entity);
          }
          $domains = $entity->get($this->field_domain)->getValue();
          if (count($domains) > 1) {
            $message = "Ne peut etre supprimer, car contient plusieurs domaine";
            $this->runErrorEntity($entity, $message, $domains);
          }
          $domain = $entity->get($this->field_domain)->target_id;
          $this->checkIfDomainIsProtected($domain, $entity);
          if (!isset($DomainsToDelete[$domain])) {
            $message = " Ne peut etre supprimer, car n'appartient à aucun des domaines definit pour la suppresion ";
            $this->runErrorEntity($entity, $message, $domains);
          }
          return true;
          break;
      }
    }
    elseif ($entity instanceof ConfigEntityBase) {
      $entityTypeId = $entity->getEntityTypeId();
      switch ($entityTypeId) {
        case 'block':
          $domain = $entity->get('theme');
          $this->checkIfDomainIsProtected($domain, $entity);
          return true;
        case 'menu':
          $idMenu = $entity->get('id');
          if (str_contains($idMenu, "_main")) {
            $sub_domain = str_replace("_main", "", $idMenu);
          }
          elseif (str_contains($idMenu, "-main")) {
            $sub_domain = str_replace("-main", "", $idMenu);
          }
          $domain_ovh_entities = $this->entityTypeManager->getStorage('domain_ovh_entity')->loadByProperties([
            'sub_domain' => $sub_domain
          ]);
          if (count($domain_ovh_entities) > 1) {
            $message = " Ne peut etre supprimer, car il ya des risques de perte de donnée,veillez verifier cela et le faire manuellement ";
            $this->runErrorEntity($entity, $message, $domain_ovh_entities);
          }
          if (!empty($domain_ovh_entities)) {
            /**
             *
             * @var \Drupal\ovh_api_rest\Entity\DomainOvhEntity $domain_ovh_entity
             */
            $domain_ovh_entity = reset($domain_ovh_entities);
            $new_domain = $domain_ovh_entity->getDomainIdDrupal();
            if (!empty($new_domain)) {
              $this->checkIfDomainIsProtected($new_domain, $entity);
            }
            else {
              $message = " Ne peut etre supprimer, car impossible de remonter au domaine parent";
              $this->runErrorEntity($entity, $message, $domain_ovh_entities);
            }
          }
          return true;
        case 'base_field_override':
        case 'language_content_settings':
          // on autorise ces derniers, car il proviennent du main menu qui a été
          // verifier.
          return true;
      }
    }
    //
    $message = " Ne peut etre supprimer, car n'est pas pris en compte, veillez contacter le developper ... ";
    $this->runErrorEntity($entity, $message, $entity);
  }
  
  /**
   *
   * @param string $domain
   * @param EntityInterface $entity
   * @return bool
   */
  protected function checkIfDomainIsProtected($domain, EntityInterface $entity) {
    $domains = $this->entityTypeManager->getStorage('domain_ovh_entity')->loadByProperties([
      'domain_id_drupal' => $domain
    ]);
    if (!empty($domains)) {
      /**
       *
       * @var \Drupal\ovh_api_rest\Entity\DomainOvhEntity $domainObject
       */
      $domainObject = reset($domains);
      if (!$domainObject->isDeletable()) {
        $message = " Ne peut etre supprimer, car l'entité de reference 'domain_ovh_entity' indique : " . $domainObject->getTypeSite();
        $this->runErrorEntity($entity, $message);
      }
    }
    else {
      $message = " Ne peut etre supprimer, Car on ne parvient à determiner la reference au niveau de domain_ovh_entity";
      $this->runErrorEntity($entity, $message);
    }
  }
  
  /**
   *
   * @param EntityInterface $entity
   * @param string $message
   * @param array $errors
   */
  protected function runErrorEntity(EntityInterface $entity, string $message, $errors = []) {
    $info = "(" . $entity->label() . "::" . $entity->id() . ")";
    $message = $message . ".  ENTITY info : " . $info;
    // pas necessaie car les errors avec ErrorException sont deja attrapé par
    // PHP.
    $this->LoggerChannel->error($message);
    \Stephane888\Debug\debugLog::symfonyDebug([
      'entity_array' => $entity->toArray(),
      'errors' => $errors
    ], $entity->getEntityTypeId() . '___' . $entity->id() . '___', true);
    $this->clearCahes();
    Throw new \ErrorException($message);
  }
  
  protected function getCacheDomains() {
    if (!$this->DomainsToDelete) {
      /**
       *
       * @var \Drupal\Core\Cache\ApcuBackend $cache
       */
      $cache = \Drupal::service("cache.backend.database")->get('wb_optimisation_delete_cache');
      $domains = $cache->get('domains');
      if ($domains) {
        $this->DomainsToDelete = $domains->data;
      }
      else
        $this->DomainsToDelete = [];
    }
    return $this->DomainsToDelete;
  }
  
  protected function setCache($key, $value, $time) {
    /**
     *
     * @var \Drupal\Core\Cache\ApcuBackend $cache
     */
    $cache = \Drupal::service("cache.backend.database")->get('wb_optimisation_delete_cache');
    $cache->set($key, $value, $time);
  }
  
  /**
   *
   * @param string $success
   * @param array $results
   * @param array $operations
   */
  public static function _deletion_batch_finished($success, $results, $operations) {
    \Drupal::messenger()->addStatus("opération terminée");
    /**
     *
     * @var \Drupal\wb_optimisation\Service\OptimisationHandler $wb_optimisation
     */
    $wb_optimisation = \Drupal::service("wb_optimisation.handler");
    $wb_optimisation->clearCahes();
  }
  
  public static function _wb_optimisation_entity_delete($domain_id, $entity_id, $number, $total, &$context) {
    if (!isset($context["result"][$entity_id]))
      $context["result"][$entity_id] = $number <= $total ? $number : $total;
    else {
      $context["result"][$entity_id] += $number;
    }
    $progress = $context["result"][$entity_id] > $total ? $total : $context["result"][$entity_id];
    
    $context["message"] = t('@domain : Suppression des @entity_type_id en cours', [
      "@domain" => $domain_id,
      "@entity_type_id" => $entity_id
    ]);
    /**
     *
     * @var \Drupal\wb_optimisation\Service\OptimisationHandler $optimisation_handler
     */
    $optimisation_handler = \Drupal::service("wb_optimisation.handler");
    $optimisation_handler->deleteMultipleByDomain($entity_id, $number, $domain_id);
  }
  
  public function CountDomainSubEntities($domain_id) {
    $entities_type = $this->getStorageEntities();
    $result = [];
    $result["block"] = $this->getBlocks($domain_id, true);
    $result["menu"] = $this->getMenu($domain_id, true);
    foreach ($entities_type as $entity_type_id => $entity_type) {
      $entityManager = $this->entityTypeManager->getStorage($entity_type_id);
      $field_domain = $entity_type["domain_id"] ? "domain_id" : $this->field_domain;
      $query = $entityManager->getQuery();
      $query->accessCheck(FALSE);
      $query->addTag(self::$query_tag);
      $query->condition($field_domain, $domain_id);
      if ($entity_type[$this->field_public]) {
        $query->condition($this->field_public, false);
      }
      $result[$entity_type_id] = $query->count()->execute();
    }
    $uniqueEntities = [
      "domain",
      "config_theme_entity",
      "domain_ovh_entity"
    ];
    foreach ($uniqueEntities as $entity_id) {
      $result[$entity_id] = 1;
    }
    return $result;
  }
  
  /**
   * Charge les entites en function du domaine et de l'id entity.
   */
  public function loadEntities($entity_id, $number, $domain_id) {
    $entityManager = $this->entityTypeManager->getStorage($entity_id);
    $query = $entityManager->getQuery()->accessCheck(False);
    $query->addTag(self::$query_tag);
    $entities = [];
    switch ($entity_id) {
      case 'menu':
        $entities = $this->getMenu($domain_id, false, $number);
        break;
      case "block":
        $entities = $this->getBlocks($domain_id, false, $number);
        break;
      case "domain_ovh_entity":
        $query->condition('domain_id_drupal', $domain_id);
        $entities = $query->execute();
        break;
      case "domain":
        $query->condition('id', $domain_id, '=');
        if ($number === -1)
          $entities = $query->execute();
        else
          $entities = $query->range(0, $number)->execute();
        break;
      case "config_theme_entity":
        $query->condition('hostname', $domain_id);
        if ($number === -1)
          $entities = $query->execute();
        else
          $entities = $query->range(0, $number)->execute();
        break;
      default:
        $field_domain = isset($this->entityFieldManager->getActiveFieldStorageDefinitions($entity_id)["domain_id"]) ? "domain_id" : $this->field_domain;
        $subEntities = $this->getStorageEntities();
        $query->condition($field_domain, $domain_id);
        if ($subEntities[$entity_id][$this->field_public]) {
          $query->condition($this->field_public, false);
        }
        if ($number === -1)
          $entities = $query->execute();
        else
          $entities = $query->range(0, $number)->execute();
        break;
    }
    return $entities;
  }
  
  /**
   *
   * @param string $entity_id
   * @param string $number
   * @param string $domain_id
   */
  public function deleteMultipleByDomain($entity_id, $number, $domain_id) {
    $entityManager = $this->entityTypeManager->getStorage($entity_id);
    $entitiesToDelete = $this->loadEntities($entity_id, $number, $domain_id);
    $entityManager->delete($entityManager->loadMultiple(array_keys($entitiesToDelete)));
  }
  
  static public function getQueryTag() {
    return self::$query_tag;
  }
  
}
