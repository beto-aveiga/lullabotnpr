<?php

namespace Drupal\npr_story\Plugin\media\Source;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\media\MediaSourceBase;

/**
 * Provides a media source plugin for non-oEmbed remote audio resources.
 *
 * @MediaSource(
 *   id = "npr_multimedia",
 *   label = @Translation("NPR Remote Multimedia"),
 *   description = @Translation("Remote URL for reusable multimedia."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "video.png",
 *   deriver = "",
 *   providers = {},
 * )
 */
class NprRemoteMultimedia extends MediaSourceBase {

  /**
   * Constructs a new class instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager service.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $field_type_manager
   *   The field type plugin manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, FieldTypePluginManagerInterface $field_type_manager, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_field_manager, $field_type_manager, $config_factory);
    // Ensure the source field is a link field so that the Audiofield field
    // formatter can be used.
    $this->pluginDefinition['allowed_field_types'] = ['link'];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [];
  }

}
