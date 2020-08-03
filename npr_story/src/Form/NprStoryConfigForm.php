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
    $form['node_settings]'] = [
      '#type' => 'details',
      '#title' => $this->t('Node settings'),
      '#description' => $this->t('To make this module as flexible as possible, there are many configuration options available. The story field mappings are probably the most important, but the vocabulary settings are required for various topics/tags and the image/audio settings are required to have images and audio.'),
      '#open' => TRUE,
    ];
    $drupal_node_types = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $node_type_options = array_combine($drupal_node_types, $drupal_node_types);
    $form['node_settings]']['story_node_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal story node type'),
      '#default_value' => $config->get('story_node_type'),
      '#options' => $node_type_options,
    ];

    // Text format configuration.
    foreach (filter_formats() as $format) {
      $formats[$format->get('format')] = $format->get('name');
    }
    $form['node_settings]']['body_text_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Body text format'),
      '#description' => $this->t('The body field is selected below'),
      '#default_value' => $config->get('body_text_format'),
      '#options' => $formats,
    ];
    $form['node_settings]']['teaser_text_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Teaser text format'),
      '#description' => $this->t('The teaser field is selected below'),
      '#default_value' => $config->get('teaser_text_format'),
      '#options' => $formats,
    ];
    $form['node_settings]']['correction_text_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Correction text format'),
      '#description' => $this->t('The correction field is selected below'),
      '#default_value' => $config->get('correction_text_format'),
      '#options' => $formats,
    ];

    // Story node field mappings.
    $form['story_field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Story field mappings'),
      '#open' => FALSE,
    ];
    $story_node_type = $config->get('story_node_type');
    if (!empty($story_node_type)) {
      $story_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('node', $story_node_type));
      $story_field_options = ['unused' => 'unused'] + array_combine($story_fields, $story_fields);
      $npr_story_fields = $config->get('story_field_mappings');
      foreach ($npr_story_fields as $field_name => $field_value) {
        $form['story_field_mappings'][$field_name] = [
          '#type' => 'select',
          '#title' => $field_name,
          '#options' => $story_field_options,
          '#default_value' => $npr_story_fields[$field_name],
        ];
        // Make some fields required fields.
        // TODO: Add form validation so required fields cannot be "unused".
        $form['story_field_mappings']['id']['#required'] = TRUE;
        $form['story_field_mappings']['audio']['#required'] = TRUE;
        $form['story_field_mappings']['audio']['#description'] = $this->t('This must be a media reference field to a media type with a source of "NPR Remote Audio".');
        $form['story_field_mappings']['primary_image']['#description'] = $this->t('All images will be downloaded and inserted in the body field, regardless of whether or not this field is configured. To add an entity (media) reference to the primary image from the story node, configure this field.');
        $form['story_field_mappings']['additional_images']['#description'] = $this->t('All images will be downloaded and inserted in the body field, regardless of whether or not this field is configured. To add entity (media) references to the additional media image(s) from the story node, configure this field.');
        $form['story_field_mappings']['multimedia']['#required'] = TRUE;
        $form['story_field_mappings']['multimedia']['#description'] = $this->t
        ('This must be a media reference field to a media type with a source of "NPR Remote Multimedia".');
        $form['story_field_mappings']['lastModifiedDate']['#required'] = TRUE;
        $form['story_field_mappings']['lastModifiedDate']['#description'] = $this->t('This must be a plain text field.');
      }
    }
    else {
      $form['story_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save Drupal story node type to choose field mappings.',
      ];
    }

    // Topic vocabulary configuration.
    $vocabs = array_keys($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple());
    $vocabulary_options = ['unused' => 'unused'] + array_combine($vocabs, $vocabs);
    $form['vocabulary_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Vocabulary settings'),
      '#description' => $this->t('Select the kinds of data that you would like to collect and store in taxonomy terms, such as topics, tags, genres, artists, series, and more. Any, all, or none of the vocabularies. can be configured.'),
      '#open' => FALSE,
    ];
    $parent_fields = $config->get('parent_vocabulary');
    foreach (array_keys($parent_fields) as $field) {
      $form['vocabulary_settings'][$field . '_settings'] = [
        '#type' => 'details',
        '#title' => $this->t('@field settings', ['@field' => $field]),
        '#open' => TRUE,
      ];
      $form['vocabulary_settings'][$field . '_settings']['parent_vocabulary_' . $field] = [
        '#type' => 'select',
        '#title' => $this->t('@field vocabulary', ['@field' => $field]),
        '#description' => $this->t('Configure vocabulary for "@field" terms. The vocabulary must contain a `name` field and a field with the machine name `field_npr_news_id`.', ['@field' => $field]),
        '#default_value' => $config->get('parent_vocabulary.' . $field),
        '#options' => $vocabulary_options,
      ];
      $form['vocabulary_settings'][$field . '_settings']['parent_vocabulary_' . $field . '_prefix'] = [
        '#type' => 'textfield',
        '#title' => $this->t('@field prefix', ['@field' => $field]),
        '#description' => $this->t('Configure a prefix for "@field" terms.', ['@field' => $field]),
        '#default_value' => $config->get('parent_vocabulary_prefix.' . $field . '_prefix'),
      ];
    }

    // Image media type configuration.
    $form['image_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Image settings'),
      '#open' => FALSE,
    ];
    $media_types = array_keys($this->entityTypeManager->getStorage('media_type')->loadMultiple());
    $media_type_options = ['unused' => 'unused'] +
      array_combine($media_types, $media_types);
    $form['image_settings']['image_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal image media type'),
      '#default_value' => $config->get('image_media_type'),
      '#options' => $media_type_options,
    ];
    $image_sizes = [
      'primary',
      'standard',
      'square',
      'wide',
      'enlargement',
      'custom',
    ];
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
      '#open' => FALSE,
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
      $form['image_settings']['image_field_mappings']['image_title']['#required'] = TRUE;
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
      '#open' => FALSE,
    ];
    $form['audio_settings']['audio_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal remote audio media type'),
      '#default_value' => $config->get('audio_media_type'),
      '#description' => $this->t('Out of the box, Drupal does not come with a "remote audio" media type, and neither the default "audio " format nor "remote video" should be used. Rather, the npr_story module includes a plugin so that provides the ability to create a media type of source "NPR Remote Audio" that can be used.'),
      '#options' => $media_type_options,
    ];
    $formats = ['mp3', 'mp4', 'hlsOnDemand', 'mediastream'];
    $format_options = array_combine($formats, $formats);
    $form['audio_settings']['audio_format'] = [
      '#type' => 'select',
      '#title' => 'Audio format',
      '#options' => $format_options,
      '#default_value' => $config->get('audio_format'),
      '#disabled' => TRUE,
    ];
    $form['audio_settings']['alternate_audio_format'] = [
      '#type' => 'select',
      '#title' => 'Alternate audio format',
      '#options' => $format_options,
      '#default_value' => $config->get('alternate_audio_format'),
      '#disabled' => TRUE,
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
        $form['audio_settings']['audio_field_mappings']['audio_title']['#required'] = TRUE;
        $form['audio_settings']['audio_field_mappings']['remote_audio']['#required'] = TRUE;
      }
    }
    else {
      $form['audio_settings']['audio_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save the Drupal audio media type to choose field mappings.',
      ];
    }

    // Multimedia media type configuration.
    $form['multimedia_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Multimedia settings'),
      '#open' => FALSE,
    ];
    $form['multimedia_settings']['multimedia_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal multimedia media type'),
      '#default_value' => $config->get('multimedia_media_type'),
      '#description' => $this->t('The npr_story module includes a plugin so that provides the ability to create a media type of source "NPR Remote Multimedia" that can be used.'),
      '#options' => $media_type_options,
    ];
    $multimedia_media_type = $config->get('multimedia_media_type');
    $form['multimedia_settings']['multimedia_field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('Multimedia field mappings'),
      '#open' => TRUE,
    ];
    if (!empty($multimedia_media_type)) {
      $multimedia_media_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('media', $multimedia_media_type));
      $multimedia_field_options = ['unused' => 'unused'] +
        array_combine($multimedia_media_fields, $multimedia_media_fields);
      $multimedia_field_mappings = $config->get('multimedia_field_mappings');
      foreach ($multimedia_field_mappings as $npr_multimedia_field => $multimedia_field_value) {
        $form['multimedia_settings']['multimedia_field_mappings'][$npr_multimedia_field] = [
          '#type' => 'select',
          '#title' => $npr_multimedia_field,
          '#options' => $multimedia_field_options,
          '#default_value' => $multimedia_field_mappings[$npr_multimedia_field],
        ];
        // Mark the required fields.
        $form['multimedia_settings']['multimedia_field_mappings']['multimedia_id
        ']['#required'] = TRUE;
        $form['multimedia_settings']['multimedia_field_mappings']['multimedia_title
        ']['#required'] = TRUE;
        $form['multimedia_settings']['multimedia_field_mappings']['remote_multimedia
        ']['#required'] = TRUE;
      }
    }
    else {
      $form['multimedia_settings']['multimedia_field_mappings']['mappings_required']
        = [
        '#type' => 'item',
        '#markup' => 'Select and save the Drupal multimedia media type to choose field mappings.',
      ];
    }
    // External asset media type configuration.
    $form['external_asset_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('External Asset Settings'),
      '#open' => FALSE,
    ];
    $external_asset_media_type = $config->get('external_asset_media_type');
    $form['external_asset_settings']['external_asset_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal external asset media type'),
      '#default_value' => $external_asset_media_type,
      '#description' => $this->t('Probably just use the Drupal "Remote video" media type.'),
      '#options' => $media_type_options,
    ];
    $form['external_asset_settings']['external_asset_field_mappings'] = [
      '#type' => 'details',
      '#title' => $this->t('external_asset field mappings'),
      '#open' => TRUE,
    ];
    if (!empty($external_asset_media_type)) {
      $external_asset_media_fields = array_keys($this
        ->entityFieldManager
        ->getFieldDefinitions('media', $external_asset_media_type));
      $external_asset_field_options = ['unused' => 'unused'] +
        array_combine($external_asset_media_fields, $external_asset_media_fields);
      $external_asset_field_mappings = $config->get('external_asset_field_mappings');
      foreach ($external_asset_field_mappings as $npr_external_asset_field => $external_asset_field_value) {
        $form['external_asset_settings']['external_asset_field_mappings'][$npr_external_asset_field] = [
          '#type' => 'select',
          '#title' => $npr_external_asset_field,
          '#options' => $external_asset_field_options,
          '#default_value' => $external_asset_field_mappings[$npr_external_asset_field],
        ];
        // Mark the required fields.
        $form['external_asset_settings']['external_asset_field_mappings']['external_asset_title']['#required'] = TRUE;
        $form['external_asset_settings']['external_asset_field_mappings']['external_asset_id']['#required'] = TRUE;
        $form['external_asset_settings']['external_asset_field_mappings']['oEmbed']['#required'] = TRUE;
      }
    }
    else {
      $form['external_asset_settings']['external_asset_field_mappings']['mappings_required'] = [
        '#type' => 'item',
        '#markup' => 'Select and save the Drupal external_asset media type to choose field mappings.',
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
    $config->set('teaser_text_format', $values['teaser_text_format']);
    $config->set('image_media_type', $values['image_media_type']);
    $config->set('image_crop_size', $values['image_crop_size']);
    $config->set('audio_media_type', $values['audio_media_type']);
    $config->set('audio_format', $values['audio_format']);

    $parent_fields = $config->get('parent_vocabulary');
    foreach (array_keys($parent_fields) as $field) {
      $config->set('parent_vocabulary.' . $field, $values['parent_vocabulary_' . $field]);
      $config->set('parent_vocabulary_prefix.' . $field . '_prefix', $values['parent_vocabulary_' . $field . '_prefix']);
    }

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
    $external_asset_fields = $config->get('external_asset_field_mappings');
    foreach ($external_asset_fields as $field_name => $field_value) {
      if (isset($values[$field_name])) {
        $config->set('external_asset_field_mappings.' . $field_name, $values[$field_name]);
      }
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
