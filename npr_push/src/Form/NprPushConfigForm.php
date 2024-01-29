<?php

namespace Drupal\npr_push\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a configuration form for story nodes.
 */
class NprPushConfigForm extends ConfigFormBase {

  /**
   * Route Builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  private $routeBuilder;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Configuration factory.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $routeBuilder
   *   Route builder.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RouteBuilderInterface $routeBuilder) {
    parent::__construct($config_factory);
    $this->routeBuilder = $routeBuilder;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('router.builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_push_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['npr_push.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('npr_push.settings');

    $form['org_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID'),
      '#default_value' => $config->get('org_id'),
      '#description' => $this->t('The ID of the organization (orgId) to use when pushing stories to NPR.'),
    ];
    $form['ingest_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ingest URL'),
      '#default_value' => $config->get('ingest_url'),
      '#description' => $this->t('The URL to use when pushing stories to NPR.'),
    ];
    $form['cds_doc_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CDS Document ID Prefix'),
      '#default_value' => $config->get('cds_doc_id_prefix'),
      '#description' => $this->t('Prefix for document IDs when pushing using CDS.'),
    ];
    $form['cds_ingest_url'] = [
      '#type' => 'select',
      '#title' => $this->t('CDS Ingest URL'),
      '#default_value' => $config->get('cds_ingest_url'),
      '#description' => $this->t('The URL to use when pushing stories to NPR CDS API.'),
      '#options' => [
        'staging' => $this->t('Staging'),
        'production' => $this->t('Production'),
      ],
    ];
    $form['npr_push_service'] = [
      '#type' => 'select',
      '#title' => $this->t('NPR Push Service'),
      '#default_value' => $config->get('npr_push_service'),
      '#options' => [
        'xml' => 'XML',
        'cds' => 'CDS',
      ],
    ];

    $filter_formats = filter_formats();
    $filter_formats_as_options = ['' => $this->t('Same as story\'s body format')];
    foreach ($filter_formats as $filter_format) {
      $filter_formats_as_options[$filter_format->id()] = $filter_format->label();
    }

    $form['npr_cds_push_body_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Body format when pushing'),
      '#default_value' => $config->get('npr_cds_push_body_format'),
      '#options' => $filter_formats_as_options,
    ];

    $form['npr_cds_push_verbose_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verbose logging'),
      '#default_value' => $config->get('npr_cds_push_verbose_logging'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('npr_push.settings');

    $config->set('org_id', $values['org_id']);
    $config->set('ingest_url', $values['ingest_url']);
    $config->set('cds_doc_id_prefix', $values['cds_doc_id_prefix']);
    $config->set('cds_ingest_url', $values['cds_ingest_url']);
    $config->set('npr_push_service', $values['npr_push_service']);
    $config->set('npr_cds_push_verbose_logging', $values['npr_cds_push_verbose_logging']);
    $config->set('npr_cds_push_body_format', $values['npr_cds_push_body_format']);

    $config->save();
    $this->routeBuilder->rebuild();

    parent::submitForm($form, $form_state);
  }

}
