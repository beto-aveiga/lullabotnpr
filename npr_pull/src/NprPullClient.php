<?php

namespace Drupal\npr_pull;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\Entity\Media;
use Drupal\npr_api\NprClient;

/**
 * Performs CRUD opertions on Drupal nodes using data from the NPR API.
 */
class NprPullClient extends NprClient {

  use StringTranslationTrait;

  /**
   * The story node.
   *
   * TODO: For now, this is only used for updating audio.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * The story node.
   *
   * TODO: For now, this is only used for updating audio.
   *
   * @var string
   */
  protected $audioField;

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

    $node_manager = $this->entityTypeManager->getStorage('node');

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

      $pull_author = $this->config->get('npr_pull.settings')
        ->get('npr_pull_author');

      // Check to see if a story node already exists in Drupal.
      if ($this->node = $node_manager->loadByProperties(['field_id' => $story->id])) {
        // Not the operation being performed for a later status message.
        $operation = "updated";
        if (count($this->node) > 1) {
          $this->messenger->addError($this->t('More than one story with the Drupal ID @id exists. Please delete the duplicate stories.', ['@id' => $story->id]));
          return;
        }
        $this->node = reset($this->node);
        // If so, update the title, status, and author.
        $this->node->set('title', $story->title);
        $this->node->set('uid', $pull_author);
        $this->node->set('status', $published);
      }
      // Otherwise, create a new story node if this is new.
      else {
        $operation = "created";
        $this->node = $node_manager->create([
          'type' => $story_config->get('story_node_type'),
          'title' => $story->title,
          'language' => 'en',
          'uid' => $pull_author,
          'status' => $published,
        ]);
      }

      // Add a reference to the media image.
      $media_image_id = $this->createOrUpdateMediaImage($story);
      $image_field = $story_mappings['image'];
      if (!empty($image_field) && $image_field !== 'unused' && !empty($media_image_id)) {
        $this->node->{$image_field}[] = ['target_id' => $media_image_id];
      }

      // Make the audio field available to other methods.
      $this->audioField = $story_mappings['audio'];
      $audio_field = $this->audioField;
      // Add a reference to the media audio.
      $media_audio_ids = $this->createOrUpdateMediaAudio($story);
      if ($audio_field == 'unused') {
        $this->messenger->addError('This story contains audio, but the audio field for NPR stories has not been configured. Please configured it.');
        return;
      }
      if (!empty($audio_field) && $audio_field !== 'unused' && !empty($media_audio_ids)) {
        foreach ($media_audio_ids as $media_audio_id) {
          $this->node->{$audio_field}[] = ['target_id' => $media_audio_id];
        }
      }

      // Add data to the remaining fields except image and audio.
      foreach ($story_mappings as $key => $value) {
        if (!empty($value) && $value !== 'unused' && !in_array($key, ['image', 'audio'])) {
          // ID doesn't have a "value" property.
          if ($key == 'id') {
            $this->node->set($value, $story->id);
          }
          elseif ($key == 'body') {
            $this->node->set($value, [
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
              if (isset($author->link[0]->value)) {
                $uri = $author->link[0]->value;
              }
              else {
                $uri = '<nolink>';
              }
              $byline[] = [
                // It looks like we always want the first link ("html")
                // rather than the second one ("api").
                'uri' => $uri,
                'title' => $author->name->value,
              ];
              $this->node->set($value, $byline);
              $this->node->save();
            }
          }
          // All of the other fields have a "value" property.
          elseif (!empty($story->{$key}->value)) {
            $this->node->set($value, $story->{$key}->value);
          }
        }
      }
      $this->node->save();
      $nodes_affected[] = $this->node;
    }

    foreach ($nodes_affected as $node_affected) {
      $link = Link::fromTextAndUrl($node_affected->label(),
        $node_affected->toUrl())->toString();
      $this->messenger->addStatus($this->t('Story @link was @operation.', [
        '@link' => $link,
        '@operation' => $operation,
      ]));
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
  protected function createOrUpdateMediaImage($story) {

    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get reguired configuration.
    $story_config = $this->config->get('npr_story.settings');
    $mappings = $story_config->get('image_field_mappings');
    $image_field = $mappings['image_field'];
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

    // Check to see if a media image already exists in Drupal.
    if ($media_image = $media_manager->loadByProperties(['field_id' => $image->id])) {
      if (count($media_image) > 1) {
        $this->messenger->addError(
          $this->t('More than one image with the ID @id ("@title") exist. Please delete the duplicate images.', [
            '@id' => $image->id,
            '@title' => $image->title,
          ]));
        return;
      }
      $media_image = reset($media_image);
      // If the media item exists, delete all of the referenced image files.
      $image_references = $media_image->{$image_field};
      foreach ($image_references as $image_reference) {
        $file_id = $image_reference->get('target_id')->getValue();
        if ($referenced_file = $this->entityTypeManager->getStorage('file')->load($file_id)) {
          $referenced_file->delete();
        }
      }
      // Remove the references to the images.
      $media_image->{$image_field} = NULL;
    }
    else {
      // Create a media entity.
      if ($mappings['title'] == 'unused' || $image_field == 'unused') {
        $this->messenger->addError('Please configure the title and image field settings for media images.');
        return;
      }
      $media_image = Media::create([
        $mappings['title'] => $image->title->value,
        'bundle' => $image_media_type,
        'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
        'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      ]);
    }

    // Create a image file.
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
    if (empty($image_url)) {
      $this->messenger->addError(
        $this->t('There is no image available for the image with the ID @id.', [
          '@id' => $image->id,
        ]));
      return;
    }
    // Download the image file contents.
    $file_data = file_get_contents($image_url);
    // Get the filename.
    $filename = basename($image_url);
    // Get the directory in the form of YYYY/MM/DD and make sure it exists.
    $full_directory = dirname($image_url);
    $directory_uri = 'public://npr_story_images/' . substr($full_directory, -10);
    $this->fileSystem->prepareDirectory($directory_uri, FileSystemInterface::CREATE_DIRECTORY);
    // Save the image.
    $file = file_save_data($file_data, $directory_uri . "/" . $filename, FileSystemInterface::EXISTS_REPLACE);

    // Attached the image file to the media item.
    $media_image->set($image_field, [
      'target_id' => $file->id(),
      'alt' => $image->caption->value,
    ]);

    // Map all of the remaining fields except title and image_field, which are
    // used above.
    foreach ($mappings as $key => $value) {
      if (!empty($value) && $value !== 'unused' && !in_array($key, ['title', 'image_field'])) {
        // ID doesn't have a "value" property.
        if ($key == 'id') {
          $media_image->set($value, $image->id);
        }
        else {
          $media_image->set($value, $image->{$key}->value);
        }
      }
    }
    $media_image->save();

    return $media_image->id();
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
  protected function createOrUpdateMediaAudio($story) {

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

    // Get the entity manager.
    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get, and verify, the necessary configuration.
    $mappings = $this->config->get('npr_story.settings')->get('audio_field_mappings');
    if ($mappings['title'] == 'unused' || $mappings['remote_audio'] == 'unused') {
      $this->messenger->addError('Please configure the title and remote audio settings.');
      return NULL;
    }
    $remote_audio_field = $mappings['remote_audio'];

    // Create the audio media item(s).
    foreach ($story->audio as $audio) {

      $audio_uri = $audio->format->mp3['m3u']->value;

      // Check to see if a story node already exists in Drupal.
      if ($media_audio = $media_manager->loadByProperties(['field_id' => $audio->id])) {
        if (count($media_audio) > 1) {
          $this->messenger->addError(
            $this->t('More than one audio media item with the ID @id ("@title") exist. Please delete the duplicate audio media.', [
              '@id' => $audio->id,
              '@title' => $audio->title,
            ]));
          return;
        }
        $media_audio = reset($media_audio);
        // Replace the audio field.
        $media_audio->set($remote_audio_field, ['uri' => $audio_uri]);
        $media_audio->set('uid', $this->config->get('npr_pull.settings')->get('npr_pull_author'));
        // Clear the reference from the story node.
        $this->node->set($this->audioField, NULL);

      }
      else {
        // Otherwise, create a new media audio entity.
        $media_audio = Media::create([
          $mappings['title'] => $audio->title->value,
          'bundle' => $audio_media_type,
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'langcode' => Language::LANGCODE_NOT_SPECIFIED,
          $remote_audio_field => ['uri' => $audio_uri],
        ]);
      }
      // Map all of the remaining fields except title and remote_audio.
      foreach ($mappings as $key => $value) {
        if (!empty($value) && $value !== 'unused' && !in_array($key, ['title', 'remote_audio'])) {
          // ID doesn't have a "value" property.
          if ($key == 'id') {
            $media_audio->set($value, $audio->id);
          }
          else {
            $media_audio->set($value, $audio->{$key}->value);
          }
        }
      }
      $media_audio->save();
      $audio_ids[] = $media_audio->id();
    }
    return $audio_ids;
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
  public function extractId($url) {
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

}
