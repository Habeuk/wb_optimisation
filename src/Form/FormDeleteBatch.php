<?php

namespace Drupal\wb_optimisation\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Render\Markup;
use Drupal\domain\DomainNegotiatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wb_optimisation\Service\OptimisationHandler;
use Drupal\Core\Url;

/**
 * Class LanguageLighterForm.
 */
class FormDeleteBatch extends FormBase {
  
  /**
   *
   * @var \Drupal\domain\DomainNegotiatorInterface
   */
  protected $domainNegotiator;
  
  /**
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  
  /**
   *
   * @var OptimisationHandler
   */
  protected $optimisationHandler;
  
  /**
   *
   * @var string
   */
  protected $field_domain;
  
  public static function create(ContainerInterface $container) {
    return new static($container->get('domain.negotiator'), $container->get('entity_type.manager'), $container->get('wb_optimisation.handler'));
  }
  
  /**
   *
   * @param DomainNegotiatorInterface $domainNegotiator
   * @param EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(DomainNegotiatorInterface $domainNegotiator, EntityTypeManagerInterface $entity_type_manager, OptimisationHandler $optimisation_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->domainNegotiator = $domainNegotiator;
    $this->optimisationHandler = $optimisation_handler;
    $this->field_domain = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wb_optimisation_delete_form';
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $query = \Drupal::database()->select("domain_ovh_entity", "domain");
    $query->fields("domain", [
      "id",
      "domain_id_drupal",
      "sub_domain"
    ]);
    // on empeche la selection des domaines proteges.
    $or = $query->orConditionGroup();
    $or->condition('type_site', 'test');
    $or->isNull('type_site');
    $query->condition($or);
    
    $request = $this->getRequest();
    $contain = $request->query->get("contain");
    /**
     *
     * @var \Drupal\Core\Database\Query\PagerSelectExtender $pager
     */
    $pager = $query->extend("Drupal\Core\Database\Query\PagerSelectExtender")->limit($request->query->get("limit") ?? 10);
    if ($contain) {
      $pager->condition("domain_id_drupal", "%$contain%", "LIKE");
    }
    $entities = $pager->execute()->fetchAll();
    // dd($entities);
    if ($entities) {
      
      foreach ($entities as $value) {
        $entity_id = $value->id;
        $options[$entity_id] = [
          "#type" => "details",
          "#title" => $value->sub_domain . ": " . $value->domain_id_drupal,
          "#open" => false
        ];
        $subEntitiesCount = $this->optimisationHandler->CountDomainSubEntities($value->domain_id_drupal);
        foreach ($subEntitiesCount as $sub_entity_id => $count) {
          $options[$entity_id][$sub_entity_id] = [
            '#markup' => Markup::create("<div>$sub_entity_id: $count</div>")
          ];
          $options[$entity_id][$sub_entity_id]['contents'] = [
            "#type" => "details",
            "#title" => $sub_entity_id,
            "#open" => false
          ];
          $entities = $this->optimisationHandler->loadEntities($sub_entity_id, -1, $value->domain_id_drupal);
          $links = [];
          foreach ($entities as $id) {
            /**
             *
             * @var \Drupal\node\Entity\Node $content
             */
            $content = $this->entityTypeManager->getStorage($sub_entity_id)->load($id);
            if ($content) {
              $link = [
                'title' => $content->label() . ' (' . $id . ') ',
                'url' => null
              ];
              $link_templates = $content->getEntityType()->getLinkTemplates();
              if (isset($link_templates['canonical'])) {
                $link['url'] = $content->toUrl();
              }
              if ($content->getEntityType()->getBaseTable() && $content->hasField($this->field_domain)) {
                $link['title'] = $content->get($this->field_domain)->target_id . ' | ' . $link['title'];
              }
              $links[] = $link;
            }
            else {
              $this->messenger()->addWarning('Contenu non accessible : ' . $sub_entity_id . " :: id " . $id);
            }
          }
          $options[$entity_id][$sub_entity_id]['contents']['links'] = [
            '#theme' => 'links',
            '#links' => $links
          ];
        }
      }
      $form["ovh_entities"] = [
        "#type" => "checkboxes",
        "#title" => $this->t("Domain to delete"),
        "#options" => $options
      ];
      
      $form["pager"] = [
        '#type' => "pager"
      ];
      $form['actions']['#type'] = 'actions';
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Deleting sites'),
        '#button_type' => 'primary'
      ];
    }
    else {
      $form["empty"] = [
        '#markup' => Markup::create('<div>' . $this->t('No entity found') . '</div>')
      ];
    }
    return $form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValues() as $key => $value) {
      // @TODO: Validate fields.
    }
    parent::validateForm($form, $form_state);
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entities = [];
    foreach ($form_state->getValue("ovh_entities") as $key => $ovh_entity) {
      if ($ovh_entity)
        $entities[$ovh_entity] = (int) $ovh_entity;
      else
        break;
    }
    $this->optimisationHandler->deleteDomainBatch($entities);
  }
  
}
