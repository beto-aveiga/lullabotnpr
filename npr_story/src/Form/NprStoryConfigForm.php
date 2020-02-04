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

    // Get a list of potential node types.
    $drupal_node_types = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $node_type_options = array_combine($drupal_node_types, $drupal_node_types);
    $form['story_node_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal story node type'),
      '#default_value' => $config->get('story_node_type'),
      '#options' => $node_type_options,
    ];

    // Story content field mappings.
    $form['story_field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Story field mappings'),
      '#open' => TRUE,
    ];
    $story_node_type = $config->get('story_node_type');
    if (!empty($story_node_type)) {
      $story_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('node', $story_node_type));
      $story_field_options = ['unused' => 'unused'] + array_combine($story_fields, $story_fields);
      $npr_story_fields = $config->get('story_field_mappings');
      foreach ($npr_story_fields as $field_name => $field_value) {
        $default = !empty($npr_story_fields[$field_name]) ?? 'unused';
        $form['story_field_mappings'][$field_name] = [
          '#type' => 'select',
          '#title' => $field_name,
          '#options' => $story_field_options,
          '#default_value' => $npr_story_fields[$field_name],
        ];
      }
    }
    else {
      $form['story_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save Drupal story node type to choose field mappings.',
      ];
    }

    // Get a list of potential media types.
    $media_types = array_keys($this->entityTypeManager->getStorage('media_type')->loadMultiple());
    $media_type_options = array_combine($media_types, $media_types);
    $form['image_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal image media type'),
      '#default_value' => $config->get('image_media_type'),
      '#options' => $media_type_options,
    ];

    // Media image field mappings.
    $form['image_field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Image field mappings'),
      '#open' => TRUE,
    ];
    $image_media_type = $config->get('image_media_type');
    if (!empty($image_media_type)) {
      $image_media_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('media', $image_media_type));
      $image_field_options = ['unused' => 'unused'] +
        array_combine($image_media_fields, $image_media_fields);
      $npr_image_fields = $config->get('image_field_mappings');
      foreach ($npr_image_fields as $npr_image_field => $field_value) {
        $default = !empty($npr_image_fields[$npr_image_field]) ?? 'unused';
        $form['image_field_mappings'][$npr_image_field] = [
          '#type' => 'select',
          '#title' => $npr_image_field,
          '#options' => $image_field_options,
          '#default_value' => $npr_image_fields[$npr_image_field],
        ];
      }
    }
    else {
      $form['image_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save the Drupal image media type to choose field mappings.',
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

    $config->set('image_media_type', $values['image_media_type']);
    $config->set('story_node_type', $values['story_node_type']);

    $npr_story_fields = $config->get('story_field_mappings');
    foreach ($npr_story_fields as $field_name => $field_value) {
      if (isset($values[$field_name])) {
        $config->set('story_field_mappings.' . $field_name, $values[$field_name]);
      }
    }
    $npr_image_fields = $config->get('image_field_mappings');
    foreach ($npr_image_fields as $field_name => $field_value) {
      if (isset($values[$field_name])) {
        $config->set('image_field_mappings.' . $field_name, $values[$field_name]);
      }
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}

