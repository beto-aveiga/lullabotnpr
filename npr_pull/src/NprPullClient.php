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

    $story_config = $this->config->get('npr_story.settings');
    $story_node_type = $story_config->get('story_node_type');
    // Create variables for each story field mapping.
    extract($this->config->get('npr_story.settings')->get('story_field_mappings'));

    foreach($this->stories as $story) {
      // Impersonate the proper user.
      $user = $this->currentUser;
      // $original_user = $user;
      // $user = \drupal::entitytypemanager()
      //   ->getstorage('user')
      //   ->load(\drupal::config('npr_pull.settings')
      //   ->get('npr_pull_author'));

      $is_update = FALSE;

      if (!empty($story->nid)) {
        // Load the story if it already exits.
        $node = Node::load($story->nid);
        $nodes_updated[] = $node;
      }
      else {
        $media_id = $this->createMediaImage($story);
        // Create a new story if this is new.
        $node = Node::create([
          'type' => $story_node_type,
          'title' => $story->title,
          'language' => 'en',
          'uid' => 1,
          'status' => $published,
          $id => $story->id,
        ]);
        if (!empty($subtitle) && $subtitle !== 'unused' && !empty($story->subtitle->value)) {
          $node->set($subtitle, $story->subtitle->value);
        }
        if (!empty($miniTeaser) && $miniTeaser !== 'unused' && !empty($story->miniTeaser->value)) {
          $node->set($miniTeaser, $story->miniTeaser->value);
        }
        if (!empty($shortTitle) && $shortTitle !== 'unused' && !empty($story->shortTitle->value)) {
          $node->set($shortTitle, $story->shortTitle->value);
        }
        if (!empty($slug) && $slug !== 'unused' && !empty($story->slug->value)) {
          $node->set($slug, $story->slug->value);
        }
        if (!empty($image) && $image !== 'unused' && !empty($media_id)) {
          $node->{$image}->target_id = $media_id;
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
    // Create variables for each field mapping.
    extract($this->config->get('npr_story.settings')->get('image_field_mappings'));

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

    $file_data = file_get_contents($image_url);
    // TODO: Pick an image directory for all NPR news images.
    $file = file_save_data($file_data, 'public://' . $image->id . ".jpg", FILE_EXISTS_REPLACE);

    $story_config = $this->config->get('npr_story.settings');
    // Create a media entity.
    $media = Media::create([
      $title => $image->title->value,
      'bundle' => $image_media_type,
      'uid' => $this->currentUser->id(),
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => 1,
      $image_field => [
        'target_id' => $file->id(),
        'alt' => $image->caption->value,
      ],
    ]);

    // Map the remaining fields.
    // TODO: This is not the fanciest way to do this.
    if (!empty($caption) && $caption !== 'unused' && !empty($image->caption->value)) {
      $media->set($caption, $image->caption->value);
    }
    if (!empty($credit) && $credit !== 'unused' && !empty($image->credit->value)) {
      $media->set($credit, $image->credit->value);
    }
    if (!empty($producer) && $producer !== 'unused' && !empty($image->producer->value)) {
      $media->set($producer, $image->producer->value);
    }
    if (!empty($provider) && $provider !== 'unused' && !empty($image->provider->value)) {
      $media->set($provider, $image->provider->value);
    }
    if (!empty($copyright) && $copyright !== 'unused' && !empty($image->copyright->value)) {
      $media->set($copyright, $image->copyright->value);
    }
    $media->save();

    return $media->id();
  }


}
