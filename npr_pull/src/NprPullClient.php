<?php

namespace Drupal\npr_pull;

use DateTime;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\Entity\Media;
use Drupal\npr_api\NprClient;
use Drupal\taxonomy\Entity\Term;

/**
 * Performs CRUD opertions on Drupal nodes using data from the NPR API.
 */
class NprPullClient extends NprClient {

  use StringTranslationTrait;

  /**
   * State key for the last update DateTime.
   */
  const LAST_UPDATE_KEY = 'npr_pull.last_update';

  /**
   * The story node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * The audio field on the story.
   *
   * @var string
   */
  protected $audioField;

  /**
   * The image field on the story.
   *
   * @var string
   */
  protected $imageField;

  /**
   * Create a story node.
   *
   * Converts an NPRMLEntity story object to a node object and saves it to the
   * database (the D8 equivalent of npr_pull_save_story).
   *
   * @param object $story
   *   An NPRMLEntity story object.
   * @param bool $published
   *   Story should be published immediately.
   * @param bool $display_messages
   *   Messages should be displayed.
   */
  public function addOrUpdateNode($story, $published, $display_messages = FALSE) {

    $this->displayMessages = $display_messages;
    if (!is_object($story)) {
      $this->nprError('The story could not be added or updated.');
      return;
    }

    $this->node = NULL;
    $node_manager = $this->entityTypeManager->getStorage('node');

    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');

    // Verify that the required fields are configured.
    $id_field = $story_mappings['id'];
    if ($id_field == 'unused') {
      $this->nprError('Please configure the story id field.');
      return NULL;
    }
    $node_last_modified = $story_mappings['lastModifiedDate'];
    if ($node_last_modified == 'unused') {
      $this->nprError('Please configure the story last modified date field.');
      return;
    }
    $text_format = $story_config->get('body_text_format');
    if (empty($text_format)) {
      $this->nprError('Please configure the story body text format.');
      return;
    }

    $pull_author = $this->config->get('npr_pull.settings')->get('npr_pull_author');

    $this->node = $node_manager->loadByProperties([$id_field => $story->id]);
    // Check to see if a story node already exists in Drupal.
    if (!empty($this->node)) {
      // Record the operation being performed for a later status message.
      $operation = "updated";
      if (count($this->node) > 1) {
        $this->nprError(
          $this->t('More than one story with the Drupal ID @id exists. Please delete the duplicate stories.', [
            '@id' => $story->id,
          ])
        );
        return;
      }
      $this->node = reset($this->node);

      // Don't update stories that have not been updated.
      $drupal_story_last_modified = strtotime($this->node->get($node_last_modified)->value);
      $npr_story_last_modified = strtotime($story->lastModifiedDate->value);
      if ($drupal_story_last_modified == $npr_story_last_modified) {
        if ($this->displayMessages) {
          // No need to log this message, so just display it.
          $this->messenger->addStatus(
            $this->t('The NPR story with the NPR ID @id has not been updated in the NPR API so it was not updated in Drupal.', [
              '@id' => $story->id,
            ]
          ));
          return;
        }
      }

      // Otherwise, update the title, status, and author.
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

    // Make the image field available to other methods.
    $this->imageField = $story_mappings['image'];
    $image_field = $this->imageField;
    // Add a reference to the media image.
    $media_image_id = $this->addOrUpdateMediaImage($story);
    if (!empty($image_field) && $image_field !== 'unused' && !empty($media_image_id)) {
      $this->node->{$image_field}[] = ['target_id' => $media_image_id];
    }

    // Make the audio field available to other methods.
    $this->audioField = $story_mappings['audio'];
    $audio_field = $this->audioField;
    // Add a reference to the media audio.
    $media_audio_ids = $this->addOrUpdateMediaAudio($story);
    if ($audio_field == 'unused') {
      $this->nprError('This story contains audio, but the audio field for NPR stories has not been configured. Please configured it.');
      return;
    }
    if (!empty($audio_field) && $audio_field !== 'unused' && !empty($media_audio_ids)) {
      foreach ($media_audio_ids as $media_audio_id) {
        $this->node->{$audio_field}[] = ['target_id' => $media_audio_id];
      }
    }
    // Add data to the remaining fields except image and audio.
    foreach ($story_mappings as $key => $value) {

      // Don't add unused fields.
      if ($value == 'unused' || empty($value)) {
        continue;
      }

      if (!in_array($key, ['image', 'audio'])) {

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
        elseif ($key == 'link') {
          $this->node->set($value, ['uri' => $story->link['html']]);
        }
        elseif (in_array($key, array_keys($story_config->get('parent_vocabulary')))) {
          // Get the vocabulary for the current "parent" item (topic, tag, etc).
          $parent_item_vocabulary = $story_config->get('parent_vocabulary.' . $key);
          // Get the vocabulary prefix for the current "parent" item.
          $parent_item_vocabulary_prefix = $story_config->get('parent_vocabulary_prefix.' . $key . '_prefix');
          // Get the story field for the current "parent" item.
          $parent_item_field = $story_config->get('story_field_mappings.' . $key);
          if (empty($story->parent)) {
            continue;
          }
          foreach ($story->parent as $item) {
            if ($item->type == $key && $parent_item_field != 'unused') {
              // Add a prefix to the term, if necessary.
              if ($parent_item_vocabulary_prefix != '') {
                $saved_term = $parent_item_vocabulary_prefix . $item->title->value;
              }
              else {
                $saved_term = $item->title->value;
              }
              if (!empty($saved_term)) {
                // Get the existing referenced item or create one.
                $tid = $this->getTermId($saved_term, $item->id, $parent_item_vocabulary);
                $ref_terms = $this->node->get($parent_item_field)->getValue();
                // Get a list of all items already referenced in the field.
                $referenced_ids = array_column($ref_terms, 'target_id');
                // If the item is not already referenced, add a reference.
                if ($tid > 0 && !in_array($tid, $referenced_ids)) {
                  $this->node->{$parent_item_field}[] = ['target_id' => $tid];
                }
              }
            }
          }
        }
        elseif ($key == 'byline' && !empty($story->byline)) {
          // Make byline an array if it is not.
          if (!is_array($story->byline)) {
            $story->byline = [$story->byline];
          }
          foreach ($story->byline as $author) {
            // Not all of the authors in the byline have a link.
            if (isset($author->link->value)) {
              $uri = $author->link->value;
            }
            elseif (isset($author->link[0]->value)) {
              $uri = $author->link[0]->value;
            }
            else {
              $uri = 'route:<nolink>';
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

    foreach ($nodes_affected as $node_affected) {
      $link = Link::fromTextAndUrl($node_affected->label(),
        $node_affected->toUrl())->toString();
      $this->nprStatus($this->t('Story @link was @operation.', [
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
  protected function addOrUpdateMediaImage($story) {
    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get reguired configuration.
    $story_config = $this->config->get('npr_story.settings');
    $mappings = $story_config->get('image_field_mappings');
    $image_media_type = $story_config->get('image_media_type');
    $crop_selected = $story_config->get('image_crop_size');

    // Verify required image field mappings.
    $image_field = $mappings['image_field'];
    $image_id_field = $mappings['image_id'];
    if ($image_id_field == 'unused' || $mappings['image_title'] == 'unused' || $image_field == 'unused') {
      $this->nprError('Please configure the image_id, title, and image_field settings for media images.');
      return;
    }

    if (empty($image_media_type) || empty($crop_selected)) {
      $this->nprError('Please configure the NPR story image settings.');
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
    if ($media_image = $media_manager->loadByProperties([$image_id_field => $image->id])) {
      if (count($media_image) > 1) {
        $this->nprError(
          $this->t('More than one image with the ID @id ("@title") exist. Please delete the duplicate images.', [
            '@id' => $image->id,
            '@title' => $image->title->value,
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
      // Remove the references to the images on the media item.
      $media_image->{$image_field} = NULL;
      // Remove the references to the media image on the story node.
      $this->node->set($this->imageField, NULL);
    }
    else {
      // Create a media entity.
      $media_image = Media::create([
        // TODO: determine if we have to truncate titles.
        $mappings['image_title'] => substr($image->title->value, 0, 255),
        'bundle' => $image_media_type,
        'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
        'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      ]);
    }

    // Create a image file. First check the main image.
    if (!empty($image->type) && $image->type == $crop_selected) {
      $image_url = $image->src;
    }
    // Next check the images in the "crop" array.
    elseif (!empty($image->crop)) {
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
    }
    // If the preferred image size doesn't exist anywhere, but there is an
    // image, use the default image as a last resort.
    if (empty($image_url) && !empty($image->src)) {
      $image_url = $image->src;
    }
    if (empty($image_url)) {
      $this->nprError(
        $this->t('There is no image of type @crop available for story @title.', [
          '@crop' => $crop_selected,
          '@title' => $story->title,
        ]));
      return;
    }
    // Strip of any parameters.
    $image_url = strtok($image_url, '?');
    // Get the filename.
    $filename = basename($image_url);

    $directory_uri = 'public://npr_story_images/';
    if (preg_match("/[0-9]{4}\/(0[1-9]|1[0-2])\/(0[1-9]|[1-2][0-9]|3[0-1])/", $image_url)) {
      // Get the directory as YYYY/MM/DD from the image URL, if it exists.
      $full_directory = dirname($image_url);
      $directory_uri .= substr($full_directory, -10);
    }
    else {
      // Otherwise, create the directory from today's date as YYYY/MM/DD.
      $directory_uri .= date('Y/m/d');
    }
    $this->fileSystem->prepareDirectory($directory_uri, FileSystemInterface::CREATE_DIRECTORY);

    try {
      $file_data = $this->client->request('GET', $image_url);
    }
    catch (\Exception $e) {
      if ($e->hasResponse()) {
        $this->nprError($this->t('There is no image at @image_url for story @title (source URL: @story_url).', [
          '@image_url' => $image_url,
          '@title' => $story->title,
          '@story_url' => $story->link['html'],
        ]));
      }
      return;
    }

    // Save the image.
    $file = file_save_data($file_data->getBody(), $directory_uri . "/" . $filename, FileSystemInterface::EXISTS_REPLACE);

    // Attached the image file to the media item.
    $media_image->set($image_field, [
      'target_id' => $file->id(),
      'alt' => $image->caption->value,
    ]);

    // Map all of the remaining fields except image_title and image_field,
    // which are used above.
    foreach ($mappings as $key => $value) {
      if (!empty($value) && $value !== 'unused' && !in_array($key, ['image_title', 'image_field'])) {
        // ID doesn't have a "value" property.
        if ($key == 'image_id') {
          $media_image->set($value, $image->id);
        }
        elseif ($key == 'provider_url') {
          $media_image->set($value, $image->provider->url);
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
  protected function addOrUpdateMediaAudio($story) {

    // Skip if there is no audio.
    if (empty($story->audio)) {
      return;
    }

    // Get and check the configuration.
    $story_config = $this->config->get('npr_story.settings');
    $audio_media_type = $story_config->get('audio_media_type');
    $audio_format = $story_config->get('audio_format');
    if (empty($audio_media_type) || empty($audio_format)) {
      $this->nprError('Please configure the NPR story audio type and format.');
      return;
    }

    // Get the entity manager.
    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get, and verify, the necessary configuration.
    $mappings = $this->config->get('npr_story.settings')->get('audio_field_mappings');
    $audio_id_field = $mappings['audio_id'];
    if ($audio_id_field == 'unused' || $mappings['audio_title'] == 'unused' || $mappings['remote_audio'] == 'unused') {
      $this->nprError('Please configure the audio_id, audio_title, and remote_audio settings.');
      return NULL;
    }
    $remote_audio_field = $mappings['remote_audio'];

    // Create the audio media item(s).
    foreach ($story->audio as $audio) {

      // MP3 files looks a little bit different.
      if ($audio_format == 'mp3' && !empty($audio->format->mp3['m3u']->value)) {
        $m3u_uri = $audio->format->mp3['m3u']->value;
        // Get the mp3 file from the m3u file.
        $full_audio_uri = file_get_contents($m3u_uri);
        // Strip of any parameters.
        $audio_uri = strtok($full_audio_uri, '?');
        $file_info = pathinfo($audio_uri);
        if ($file_info['extension'] !== 'mp3') {
          $this->nprError(
            $this->t('The audio for the story @title does not contain a valid mp3 file.', [
              '@title' => $story->title,
            ]));
          return;
        }
      }
      elseif (!empty($audio->format->{$audio_format}->value)) {
        $audio_uri = $audio->format->{$audio_format}->value;
      }
      else {
        return;
      }

      // Check to see if a story node already exists in Drupal.
      if ($media_audio = $media_manager->loadByProperties([$audio_id_field => $audio->id])) {
        if (count($media_audio) > 1) {
          $this->nprError(
            $this->t('More than one audio media item with the ID @id ("@title") exist. Please delete the duplicate audio media.', [
              '@id' => $audio->id,
              '@title' => $story->title,
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
        // Otherwise, create a new media audio entity. Use the title of the
        // story for the title of the audio.
        $media_audio = Media::create([
          $mappings['audio_title'] => $story->title,
          'bundle' => $audio_media_type,
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'langcode' => Language::LANGCODE_NOT_SPECIFIED,
          $remote_audio_field => ['uri' => $audio_uri],
        ]);
      }
      // Map all of the remaining fields except title and remote_audio.
      foreach ($mappings as $key => $value) {
        if (!empty($value) && $value !== 'unused' && !in_array($key, ['audio_title', 'remote_audio'])) {
          // ID doesn't have a "value" property.
          if ($key == 'audio_id') {
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

  /**
   * Gets the date and time of the last API content type sync.
   *
   * @return \DateTime
   *   Date and time of last API content type sync.
   */
  public function getLastUpdateTime(): DateTime {
    return $this->state->get(
      self::LAST_UPDATE_KEY,
      new DateTime('@1')
    );
  }

  /**
   * Sets the date and time of the last API content type sync.
   *
   * @param \DateTime $time
   *   Date and time to set.
   */
  public function setLastUpdateTime(DateTime $time): void {
    $this->state->set(self::LAST_UPDATE_KEY, $time);
  }

  /**
   * Resets the stored date and time of the last API content type sync.
   */
  public function resetLastUpdateTime(): void {
    $this->state->delete(self::LAST_UPDATE_KEY);
  }

  /**
   * Adds items to the API story queue based on a time constraint.
   *
   * @param \DateTime|null $since
   *   Time constraint for the queue cutoff. Defaults to the last time the queue
   *   was updated.
   *
   * @return bool
   *   TRUE if the queue update fully completes, FALSE if it does not.
   */
  public function updateQueue(DateTime $since = NULL): bool {
    $dt_start = new DateTime();

    $this->stories = [];
    if (empty($since)) {
      $since = $this->getLastUpdateTime();
    }

    $pull_config = $this->config->get('npr_pull.settings');
    $num_results = $pull_config->get('num_results');
    // By default, check stories for the past 3 days.
    $start = date("Y-m-d", time() - 259200);
    $end = date("Y-m-d");

    // Get a list of IDs subscribed to.
    $npr_ids = $this->getSubscriptionIds();

    // Make separate API calls for each topic. If there are many, many topics
    // slected, we may not get data for all of them.
    foreach ($npr_ids as $npr_id) {
      $params = [
        'id' => $npr_id,
        'numResults' => $num_results,
        'startDate' => $start,
        'endDate' => $end,
        'fields' => 'all',
      ];
      $this->getStories($params);

      foreach ($this->stories as $story) {
        $updated_at = new DateTime($story->lastModifiedDate);
        if ($updated_at > $since) {
          $this->getQueue()->createItem($story);
        }
      }
    }

    $this->setLastUpdateTime($dt_start);

    return TRUE;
  }

  /**
   * Gets a term ID either by loading it or creating it.
   *
   * @param string $term_name
   *   The name of the term.
   * @param int $id
   *   The NPR ID of the term.
   * @param string $vid
   *   The vocabulary id.
   *
   * @return int
   *   The integer of the taxonomy term.
   */
  protected function getTermId($term_name, $id, $vid) {
    if (empty($term_name)) {
      return 0;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['field_npr_news_id' => $id]);
    $term = reset($term);
    if (empty($term)) {
      $term = Term::create([
        'name' => $term_name,
        'vid' => $vid,
        'field_npr_news_id' => $id,
      ]);
      $term->save();
      $this->nprStatus($this->t('The term @title was added to @vocab', [
        '@title' => $term_name,
        '@vocab' => $vid,
      ]));
    }
    if (is_array($term) && count($term) > 1) {
      $this->nprError(
        $this->t('Multiple terms with the id @id exist. Please delete the duplicate term(s).', [
          '@id' => $id,
        ]));
      return 0;
    }
    return $term->id();
  }

  /**
   * Get a list of NPR IDs subscribed to.
   *
   * @return array
   *   A list of NPR IDs or just "News" if none are selected.
   */
  public function getSubscriptionIds() {
    $pull_config = $this->config->get('npr_pull.settings');
    $subscribe_method = $pull_config->get('subscribe_method');
    if ($subscribe_method == 'taxonomy') {
      if ($subscribed_terms = $this->getSubscriptionTerms()) {
        // Get the NPR ids for all of the terms that have been subscribed to.
        foreach ($subscribed_terms as $subscribed_term) {
          if ($npr_id = $subscribed_term->get('field_npr_news_id')->value) {
            $npr_ids[$npr_id] = $npr_id;
          }
        }
      }
    }
    // If the terms have been selected using the predetermined checkboxes, get
    // those as a list of topic IDs.
    elseif ($subscribe_method == 'checkbox') {
      $npr_ids = $pull_config->get('npr_ids');
    }
    // If there are no topic IDs, just get "News".
    if (empty($npr_ids)) {
      $npr_ids = [1001 => 1001];
    }
    return $npr_ids;
  }

  /**
   * Get taxonomy terms subscribed to.
   *
   * @return array
   *   An array of taxonomy terms.
   */
  public function getSubscriptionTerms() {
    $pull_config = $this->config->get('npr_pull.settings');
    $topic_vocabularies = $pull_config->get('topic_vocabularies');
    if (empty(array_filter($topic_vocabularies))) {
      return [];
    }
    $taxonomy_manager = $this->entityTypeManager->getStorage('taxonomy_term');
    $all_subscribed_terms = [];
    // For each vocabularly used for subscription, check for taxonomy terms
    // with the "subscribe" box checked.
    foreach (array_keys($topic_vocabularies) as $vocabulary) {
      $subscribed_terms = $taxonomy_manager->loadByProperties([
        'field_npr_subscribe' => 1,
        'vid' => $vocabulary,
      ]);
      foreach ($subscribed_terms as $term) {
        // Only include subscribed terms that have a NPR ID.
        if (!empty($term->get('field_npr_news_id')->value)) {
          $all_subscribed_terms[] = $term;
        }
      }
    }
    return $all_subscribed_terms;
  }

  /**
   * Helper function for error messages.
   *
   * @param string $text
   *   The message to log or display.
   */
  private function nprError($text) {
    $this->logger->error($text);
    if (!empty($this->displayMessages)) {
      $this->messenger->addError($text);
    }
  }

  /**
   * Helper function for error notices.
   *
   * @param string $text
   *   The message to log or display.
   */
  private function nprStatus($text) {
    $this->logger->notice($text);
    if (!empty($this->displayMessages)) {
      $this->messenger->addStatus($text);
    }
  }

}
