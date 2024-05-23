<?php

namespace Drupal\wb_optimisation\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Url;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\language\Form\ContentLanguageSettingsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ovh_api_rest\Entity\DomainOvhEntity;

/**
 * Class LanguageLighterForm.
 */
class FilterForm extends FormBase {

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

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('domain.negotiator'),
      $container->get('entity_type.manager'),
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
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->domainNegotiator = $domainNegotiator;
  }


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wb_optimisation_delete_form_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $query = \Drupal::database()->select("domain_ovh_entity", "domain");
    $query->fields("domain", ["id", "domain_id_drupal"]);
    $request = $this->getRequest();
    $form = [
      "#attributes" => [
        "class" => [
          "form-items-inline"
        ]
      ],
      'contain' => [
        '#type' => 'textfield',
        '#title' => $this->t('Champ Texte'),
        '#size' => 30,
        '#maxlength' => 128,
        "#default_value" => $request->query->get("contain") ?? ""
      ],
      'limit' => [
        '#type' => 'number',
        '#title' => $this->t('Champ Numérique'),
        '#min' => 1, // Définir selon les besoins
        "#default_value" => $request->query->get("limit") ?? 50
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Filtrer'),
      ],
    ];

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
    $request = $this->getRequest();
    $filter = [
      "contain" => $form_state->getValue("contain"),
      "limit" => $form_state->getValue("limit")
    ];
    $form_state->setRedirect("<current>", array_merge($request->query->all(), $filter));
  }
}
