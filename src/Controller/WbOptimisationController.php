<?php

namespace Drupal\wb_optimisation\Controller;

use Drupal\Core\Controller\ControllerBase;

use Drupal\Core\Url;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\lesroidelareno\lesroidelareno;
use Drupal\commerce_shipping\ShippingMethodManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\wb_optimisation\Service\OptimisationHandler;

/**
 * Class DonneeSiteInternetEntityController.
 *
 * Returns responses for Donnee site internet des utilisateurs routes.
 */
class WbOptimisationController extends ControllerBase {
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
   * @param Request $request
   */
  public function getDeletationForm(Request $request) {
    /**
     * @var OptimisationHandler $opt
     */
    // $opt = \Drupal::service("wb_optimisation.handler");
    // dd($opt->CountDomainSubEntities("test973_wb_horizon_kksa"));
    // $ovh_entity = $this->entityTypeManager->getStorage("domain_ovh_entity")->load(972);
    // $this->optimisationHandler->CountDomainSubEntities($ovh_entity->get("domain_id_drupal"));
    // dd($ovh_entity->get("domain_id_drupal")->getValue()[0]["target_id"]);
    $form = [
      "filter" => $this->formBuilder()->getForm("Drupal\wb_optimisation\Form\FilterForm"),
      "form" => $this->formBuilder()->getForm("Drupal\wb_optimisation\Form\FormDeleteBatch"),
    ];

    return $form;
  }
}
