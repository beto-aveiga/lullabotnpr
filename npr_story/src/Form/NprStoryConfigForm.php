<?php

namespace Drupal\npr_story\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a configuration form for story nodes.
 */
class NprStoryConfigForm extends ConfigFormBase {

  use StringTranslationTrait;

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
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
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

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('npr_story.settings');

    // Node type configuration.
    $form['node_type_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Node type settings'),
      '#open' => TRUE,
    ];
    $drupal_node_types = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $node_type_options = array_combine($drupal_node_types, $drupal_node_types);
    $form['node_type_settings']['story_node_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal story node type'),
      '#default_value' => $config->get('story_node_type'),
      '#options' => $node_type_options,
    ];
    // Create a list of text formats.
    foreach (filter_formats() as $format) {
      $formats[$format->get('format')] = $format->get('name');
    }
    $form['node_type_settings']['body_text_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Body text format'),
      '#description' => $this->t('The body field is selected below.'),
      '#default_value' => $config->get('body_text_format'),
      '#options' => $formats,
    ];
    // Story node field mappings.
    $form['node_type_settings']['story_field_mappings'] = [
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
        $form['node_type_settings']['story_field_mappings'][$field_name] = [
          '#type' => 'select',
          '#title' => $field_name,
          '#options' => $story_field_options,
          '#default_value' => $npr_story_fields[$field_name],
        ];
        $form['node_type_settings']['story_field_mappings']['id']['#required'] = TRUE;
        $form['node_type_settings']['story_field_mappings']['audio']['#required'] = TRUE;
        $form['node_type_settings']['story_field_mappings']['image']['#required'] = TRUE;
      }
    }
    else {
      $form['node_type_settings']['story_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save Drupal story node type to choose field mappings.',
      ];
    }

    // Image media type configuration.
    $form['image_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Image settings'),
      '#open' => TRUE,
    ];
    $media_types = array_keys($this->entityTypeManager->getStorage('media_type')->loadMultiple());
    $media_type_options = array_combine($media_types, $media_types);
    $form['image_settings']['image_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal image media type'),
      '#default_value' => $config->get('image_media_type'),
      '#options' => $media_type_options,
    ];
    $image_sizes = ['standard', 'square', 'wide', 'enlargement', 'custom'];
    $image_options = array_combine($image_sizes, $image_sizes);
    $form['image_settings']['image_crop_size'] = [
      '#type' => 'select',
      '#title' => 'Image crop size',
      '#options' => $image_options,
      '#default_value' => $config->get('image_crop_size'),
    ];
    // Media image field mappings.
    $form['image_settings']['image_field_mappings'] = [
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
        $form['image_settings']['image_field_mappings'][$npr_image_field] = [
          '#type' => 'select',
          '#title' => $npr_image_field,
          '#options' => $image_field_options,
          '#default_value' => $npr_image_fields[$npr_image_field],
        ];
      }
      // Mark the required fields.
      $form['image_settings']['image_field_mappings']['image_id']['#required'] = TRUE;
      $form['image_settings']['image_field_mappings']['title']['#required'] = TRUE;
      $form['image_settings']['image_field_mappings']['image_field']['#required'] = TRUE;
    }
    else {
      $form['image_settings']['image_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save the Drupal image media type to choose field mappings.',
      ];
    }

    // Audio media type configuration.
    $form['audio_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Audio settings'),
      '#open' => TRUE,
    ];
    $form['audio_settings']['audio_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal remote audio media type'),
      '#default_value' => $config->get('audio_media_type'),
      '#description' => $this->t('Out of the box, Drupal does not come with a "remote audio" media type, and neither the default "audio " format nor "remote video" should be used. Rather, the npr_story module includes a plugin so that provides the ability to create a media type of source "NPR Remote Audio" that can be used.'),
      '#options' => $media_type_options,
    ];
    $formats = ['mp3', 'm3u', 'mp4', 'hlsOnDemand', 'mediastream'];
    $format_options = array_combine($formats, $formats);
    $form['audio_settings']['audio_format'] = [
      '#type' => 'select',
      '#title' => 'Audio format',
      '#options' => $format_options,
      '#default_value' => $config->get('audio_format'),
    ];
    $audio_media_type = $config->get('audio_media_type');
    $form['audio_settings']['audio_field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Audio field mappings'),
      '#open' => TRUE,
    ];
    if (!empty($audio_media_type)) {
      $audio_media_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('media', $audio_media_type));
      $audio_field_options = ['unused' => 'unused'] +
        array_combine($audio_media_fields, $audio_media_fields);
      $audio_field_mappings = $config->get('audio_field_mappings');
      foreach ($audio_field_mappings as $npr_audio_field => $audio_field_value) {
        $form['audio_settings']['audio_field_mappings'][$npr_audio_field] = [
          '#type' => 'select',
          '#title' => $npr_audio_field,
          '#options' => $audio_field_options,
          '#default_value' => $audio_field_mappings[$npr_audio_field],
        ];
        // Mark the required fields.
        $form['audio_settings']['audio_field_mappings']['audio_id']['#required'] = TRUE;
        $form['audio_settings']['audio_field_mappings']['title']['#required'] = TRUE;
        $form['audio_settings']['audio_field_mappings']['remote_audio']['#required'] = TRUE;
      }
    }
    else {
      $form['audio_settings']['audio_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save the Drupal audio media type to choose field mappings.',
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

    $config->set('story_node_type', $values['story_node_type']);
    $config->set('body_text_format', $values['body_text_format']);
    $config->set('image_media_type', $values['image_media_type']);
    $config->set('image_crop_size', $values['image_crop_size']);
    $config->set('audio_media_type', $values['audio_media_type']);
    $config->set('audio_format', $values['audio_format']);

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
    $npr_audio_fields = $config->get('audio_field_mappings');
    foreach ($npr_audio_fields as $field_name => $field_value) {
      if (isset($values[$field_name])) {
        $config->set('audio_field_mappings.' . $field_name, $values[$field_name]);
      }
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
