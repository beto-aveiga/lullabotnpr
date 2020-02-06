<?php

namespace Drupal\npr_pull;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\npr_api\NprClient;
use GuzzleHttp\ClientInterface;

/**
 * Performs CRUD opertions on Drupal nodes using data from the NPR API.
 */
class NprPullClient extends NprClient {

  /**
   * Constructs a NprPullClient object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current logged in user.
   */
  public function __construct(ClientInterface $client, ConfigFactoryInterface $config_factory, AccountInterface $current_user) {
    parent::__construct($client, $config_factory, $current_user);
  }

  /**
   * Converts an NPRMLEntity story object into a node object and saves it to the
   * database (the D8 equivalent of npr_pull_save_story).
   *
   * @param string $story_id
   *   The ID of an NPR story.
   * @param bool $published
   *   Story should be published immediately.
   */
  function saveOrUpdateNode($story_id, $published) {

    // Make a request.
    $this->getXmlStories(['id' => $story_id]);
    $this->parse();

    // Get the story field mappings.
    $story_mappings = $this->config->get('npr_story.settings')->get('story_field_mappings');

    foreach($this->stories as $story) {

      $is_update = FALSE;

      if (!empty($story->nid)) {
        // Load the story if it already exits.
        $node = Node::load($story->nid);
        $nodes_updated[] = $node;
      }
      else {
        $media_id = $this->createMediaImage($story);
        // Create a new story node if this is new.
        $node = Node::create([
          'type' => $this->config->get('npr_story.settings')->get('story_node_type'),
          'title' => $story->title,
          'language' => 'en',
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'status' => $published,
        ]);

        // Add a reference to the image field.
        $image_field = $story_mappings['image'];
        if (!empty($image_field) && $image_field !== 'unused' && !empty($media_id)) {
          $node->{$image_field}->target_id = $media_id;
        }

        // Add a reference to the audio field.
        // TODO.

        // Add data to the remaining fields except image and audio.
        foreach ($story_mappings as $key => $value) {
          if (!empty($value) && $value !== 'unused' && !in_array($key, ['image', 'audio'])) {
            // ID doesn't have a "value" key.
            if ($key == 'id') {
              $node->set($value, $story->id);
            }
            // All of the other fields do have a "value."
            elseif (!empty($story->{$key}->value)) {
              $node->set($value, $story->{$key}->value);
            }
          }
        }

      }
      $node->save();
      $nodes_created[] = $node;
    }
    foreach($nodes_created as $node_created) {
      $link = Link::fromTextAndUrl($node_created->label(), $node_created->toUrl())->toString();
      \Drupal::messenger()->addStatus(t("Story @link was created.", [
        '@link' => $link,
      ]));
    }
  }

  /**
   * Extracts an NPR ID from an NPR URL.
   *
   * @param string $url
   *   A URL.
   *
   * @return string $matches
   *   The ID of the NPR story.
   */
  function extractId($url) {
    // URL format: /yyyy/mm/dd/id
    // URL format: /blogs/name/yyyy/mm/dd/id
    preg_match('/https\:\/\/[^\s\/]*npr\.org\/((([^\/]*\/){3,5})([0-9]{8,12}))\/.*/', $url, $matches);
    if (!empty($matches[4])) {
      return $matches[4];
    }
    else {
      // URL format: /templates/story/story.php?storyId=id
      preg_match('/https\:\/\/[^\s\/]*npr\.org\/([^&\s\<]*storyId\=([0-9]+)).*/', $url, $matches);
      if (!empty($matches[2])) {
        return $matches[2];
      }
    }
  }

  /**
   * Creates a image media item based on the configured field values.
   *
   * @param object $story
   *   A single NPRMLEntity.
   *
   * @return string $media
   *   A media image.
   */
  function createMediaImage($story) {

    $story_config = $this->config->get('npr_story.settings');
    $image_media_type = $story_config->get('image_media_type');
    $crop_selected = $story_config->get('image_crop_size');

    // We will only get the first image (at least for now).
    $image = $story->image[0];

    // Get the URL of the image size requested.
    if (!is_array($image->crop)) {
      $image->crop = [$image->crop];
    }
    if (!empty($image->crop)) {
      foreach ($image->crop as $crop) {
        if (!empty($crop->type) && $crop->type == $crop_selected) {
          $image_url = $crop->src;
          continue;
        }
      }
    }

    // Download the image file contents.
    $file_data = file_get_contents($image_url);

    // Get the filename.
    $filename = basename($image_url);

    // TODO: Pick an image directory for all NPR news images.
    $file = file_save_data($file_data, 'public://' . $filename, FILE_EXISTS_REPLACE);

    // Create a media entity.
    $mappings = $this->config->get('npr_story.settings')->get('image_field_mappings');
    $media = Media::create([
      $mappings['title'] => $image->title->value,
      'bundle' => $image_media_type,
      'uid' => $this->currentUser->id(),
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => 1,
      $mappings['image_field'] => [
        'target_id' => $file->id(),
        'alt' => $image->caption->value,
      ],
    ]);

    // Map all of the remaining fields except title and image_field, which are
    // used above.
    foreach ($mappings as $key => $value) {
      if (!empty($value) &&
          $value !== 'unused' &&
          !empty($image->{$key}->value) &&
          !in_array($key, ['title', 'image_field'])
      ) {
        $media->set($value, $image->{$key}->value);
      }
    }
    $media->save();

    return $media->id();
  }


}
