<?php

/**
 * @file
 * Contains \Drupal\npr_story\Form\NprStoryConfigForm.
 */

namespace Drupal\npr_story\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\npr_story\NprClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NprStoryConfigForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new NprStoryConfigForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_story_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['npr_story.settings'];
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {

    $config = $this->config('npr_story.settings');

    $drupal_content_types = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $content_type_options = array_combine($drupal_content_types, $drupal_content_types);
    $form['drupal_story_content'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal Story content type'),
      '#default_value' => $config->get('drupal_story_content'),
      '#options' => $content_type_options,
    ];

    // Field Mappings.
    $form['field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Story field mappings'),
      '#open' => TRUE,
    ];
    $story_content_type = $config->get('drupal_story_content');
    if (!empty($story_content_type)) {
      $story_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('node', $story_content_type));
      $story_field_options = ['unused' => 'unused'] + array_combine($story_fields, $story_fields);
      $npr_story_fields = $config->get('mappings');
      foreach ($npr_story_fields as $field_name => $field_value) {
        $default = !empty($npr_story_fields[$field_name]) ?? 'unused';
        $form['field_mappings'][$field_name] = [
          '#type' => 'select',
          '#title' => $field_name,
          '#options' => $story_field_options,
          '#default_value' => $npr_story_fields[$field_name],
        ];
      }
    }
    else {
      $form['field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save Drupal Story content typ to choose field mappings.',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('npr_story.settings');

    $config->set('drupal_story_content', $values['drupal_story_content']);

    $npr_story_fields = $config->get('mappings');
    foreach ($npr_story_fields as $field_name => $field_value) {
      if (isset($values[$field_name])) {
        $config->set('mappings.' . $field_name, $values[$field_name]);
      }
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}

