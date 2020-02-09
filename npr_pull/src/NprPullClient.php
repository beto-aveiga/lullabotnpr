<?php

namespace Drupal\npr_pull;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Link;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\npr_api\NprClient;

/**
 * Performs CRUD opertions on Drupal nodes using data from the NPR API.
 */
class NprPullClient extends NprClient {

  /**
   * Create a story node.
   *
   * Converts an NPRMLEntity story object to a node object and saves it to the
   * database (the D8 equivalent of npr_pull_save_story).
   *
   * @param string $story_id
   *   The ID of an NPR story.
   * @param bool $published
   *   Story should be published immediately.
   */
  public function saveOrUpdateNode($story_id, $published) {

    // Make a request.
    $this->getXmlStories(['id' => $story_id]);
    $this->parse();

    if (empty($this->stories)) {
      $this->messenger->addError($story_id . ' is not a valid story ID.');
      return;
    }

    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');
    $text_format = $story_config->get('body_text_format');

    if (empty($text_format)) {
      // TODO: Add a link to the config page.
      $this->messenger->addError('You must select a body text format.');
      return;
    }

    foreach ($this->stories as $story) {

      // Add the published flag to the story object.
      $story->published = $published;

      $is_update = FALSE;

      if (!empty($story->nid)) {
        // Load the story if it already exits.
        $node = Node::load($story->nid);
        $nodes_updated[] = $node;
      }
      else {
        // Create a new story node if this is new.
        $node = Node::create([
          'type' => $story_config->get('story_node_type'),
          'title' => $story->title,
          'language' => 'en',
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'status' => $published,
        ]);

        // Add a reference to the media image.
        $media_image_id = $this->createMediaImage($story);
        $image_field = $story_mappings['image'];
        if (!empty($image_field) && $image_field !== 'unused' && !empty($media_image_id)) {
          $node->{$image_field}->target_id = $media_image_id;
        }

        // Add a reference to the media audio.
        $media_audio_ids = $this->createMediaAudio($story);
        $audio_field = $story_mappings['audio'];
        if ($audio_field == 'unused') {
          $this->messenger->addError('This story contains audio, but the audio field for NPR stories has not been configured. Please configured it.');
          return;
        }
        if (!empty($audio_field) && $audio_field !== 'unused' && !empty($media_audio_ids)) {
          foreach ($media_audio_ids as $audio_id) {
            $node->{$audio_field}[] = ['target_id' => $audio_id];
          }
        }

        // Add data to the remaining fields except image and audio.
        foreach ($story_mappings as $key => $value) {
          if (!empty($value) && $value !== 'unused' && !in_array($key, ['image', 'audio'])) {
            // ID doesn't have a "value" key.
            if ($key == 'id') {
              $node->set($value, $story->id);
            }
            elseif ($key == 'body') {
              $node->set($value, [
                'value' => $story->body,
                'format' => $text_format,
              ]);
            }
            elseif ($key == 'byline' && !empty($story->byline)) {
              // Make byline an array if it is not.
              if (!is_array($story->byline)) {
                $story->byline = [$story->byline];
              }
              foreach ($story->byline as $author) {
                // Not all of the authors in the byline have a link.
                $uri = $author->link[0]->value ?: "<nolink>";
                $byline[] = [
                  // It looks like we always want the first link ("html")
                  // rather than the second one ("api").
                  'uri' => $uri,
                  'title' => $author->name->value,
                ];
                $node->set($value, $byline);
              }
            }
            // All of the other fields have a "value" property.
            elseif (!empty($story->{$key}->value)) {
              $node->set($value, $story->{$key}->value);
            }
          }
        }

      }
      $node->save();
      $nodes_created[] = $node;
    }
    foreach ($nodes_created as $node_created) {
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
   * @return array
   *   The ID of the NPR story.
   */
  protected function extractId($url) {
    // Handle URL formats such as /yyyy/mm/dd/id and /blogs/name/yyyy/mm/dd/id.
    preg_match('/https\:\/\/[^\s\/]*npr\.org\/((([^\/]*\/){3,5})([0-9]{8,12}))\/.*/', $url, $matches);
    if (!empty($matches[4])) {
      return $matches[4];
    }
    else {
      // Handle URL format /templates/story/story.php?storyId=id.
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
   * @return string|null
   *   A media image id or null.
   */
  protected function createMediaImage($story) {

    $story_config = $this->config->get('npr_story.settings');
    $image_media_type = $story_config->get('image_media_type');
    $crop_selected = $story_config->get('image_crop_size');

    if (empty($image_media_type) || empty($crop_selected)) {
      $this->messenger->addError('Please configure the NPR story image settings.');
      return;
    }

    // We will only get the first image (at least for now).
    if (!empty($story->image[0])) {
      $image = $story->image[0];
    }
    else {
      return;
    }

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

    // Get the directory in the form of YYYY/MM/DD and make sure it exists.
    $full_directory = dirname($image_url);
    $directory_uri = 'public://npr_story_images/' . substr($full_directory, -10);
    \Drupal::service('file_system')->prepareDirectory($directory_uri, FileSystemInterface::CREATE_DIRECTORY);

    // Save the image.
    $file = file_save_data($file_data, $directory_uri . "/" . $filename, FILE_EXISTS_REPLACE);

    // Create a media entity.
    $mappings = $this->config->get('npr_story.settings')->get('image_field_mappings');
    if ($mappings['title'] == 'unused' || $mappings['image_field'] == 'unused') {
      $this->messenger->addError('Please configure the title and image field settings for media images.');
      return;
    }
    $media = Media::create([
      $mappings['title'] => $image->title->value,
      'bundle' => $image_media_type,
      'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'status' => $story->published,
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

  /**
   * Creates a media audio item based on the configured field values.
   *
   * @param object $story
   *   A single NPRMLEntity.
   *
   * @return string|null
   *   A audo media id or null.
   */
  protected function createMediaAudio($story) {

    // Skip if there is no audio.
    if (empty($story->audio)) {
      return;
    }

    // Get and check the configuration.
    $story_config = $this->config->get('npr_story.settings');
    $audio_media_type = $story_config->get('audio_media_type');

    // TODO: Currently this is not used. Do we need this config?
    $audio_format = $story_config->get('audio_format');

    if (empty($audio_media_type) || empty($audio_format)) {
      $this->messenger->addError('Please configure the NPR story audio type and format.');
      return;
    }

    // Create a media audio entity.
    $mappings = $this->config->get('npr_story.settings')->get('audio_field_mappings');
    if ($mappings['title'] == 'unused' || $mappings['remote_audio'] == 'unused') {
      $this->messenger->addError('Please configure the title and remote audio settings.');
      return NULL;
    }

    // Create the audio media item(s).
    foreach ($story->audio as $audio) {
      $audio_uri = $audio->format->mp3['m3u']->value;
      $media = Media::create([
        $mappings['title'] => $audio->title->value,
        'bundle' => $audio_media_type,
        'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
        'langcode' => Language::LANGCODE_NOT_SPECIFIED,
        'status' => $story->published,
        $mappings['remote_audio'] => ['uri' => $audio_uri],
      ]);
      // Map all of the remaining fields except title and remote_audio.
      foreach ($mappings as $key => $value) {
        if (!empty($value) &&
            $value !== 'unused' &&
            !empty($audio->{$key}->value) &&
            !in_array($key, ['title', 'remote_audio'])
        ) {
          $media->set($value, $audio->{$key}->value);
        }
      }
      $media->save();
      $audio_ids[] = $media->id();
    }
    return $audio_ids;
  }

}
