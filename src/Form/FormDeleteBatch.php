<?php

namespace Drupal\wb_optimisation\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Render\Markup;
use Drupal\domain\DomainNegotiatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wb_optimisation\Service\OptimisationHandler;

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
   * @var OptimisationHandler
   */
  protected $optimisationHandler;

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('domain.negotiator'),
      $container->get('entity_type.manager'),
      $container->get('wb_optimisation.handler')
    );
  }



  /**
   *
   * @param DomainNegotiatorInterface $domainNegotiator
   * @param EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(
    DomainNegotiatorInterface $domainNegotiator,
    EntityTypeManagerInterface $entity_type_manager,
    OptimisationHandler $optimisation_handler,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->domainNegotiator = $domainNegotiator;
    $this->optimisationHandler = $optimisation_handler;
  }


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wb_optimisation_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $query = \Drupal::database()->select("domain_ovh_entity", "domain");
    $query->fields("domain", ["id", "domain_id_drupal", "sub_domain"]);
    $request = $this->getRequest();
    $contain = $request->query->get("contain");
    /**
     * @var \Drupal\Core\Database\Query\PagerSelectExtender $pager
     */
    $pager = $query->extend("Drupal\Core\Database\Query\PagerSelectExtender")->limit($request->query->get("limit") ?? 50);
    if ($contain) {
      $pager->condition("domain_id_drupal", "%$contain%", "LIKE");
    }
    $entities  = $pager->execute()->fetchAll();
    // dd($entities);
    if ($entities) {

      foreach ($entities as $value) {
        $entity_id = $value->id;
        $options[$entity_id] = [
          "#type" => "details",
          "#title" => $value->sub_domain . ": " . $value->domain_id_drupal,
          "#open" => false,
        ];
        $subEntitiesCount = $this->optimisationHandler->CountDomainSubEntities($value->domain_id_drupal);
        foreach ($subEntitiesCount as $sub_entity_id => $count) {
          $options[$entity_id][$sub_entity_id] = [
            '#markup' => Markup::create("<div>$sub_entity_id: $count</div>"),
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
        '#value' => $this->t('Save configuration'),
        '#button_type' => 'primary',
      ];
    } else {
      $form["empty"] = [
        '#markup' => Markup::create('<div>' . $this->t('No entity found') . '</div>'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValues() as $key => $value) {
      // @TODO: Validate fields.
    }
    parent::validateForm($form, $form_state);
  }

  /**
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
