<?php

namespace Drupal\npr_pull;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\media\Entity\Media;
use Drupal\npr_api\NprCdsClient;
use Drupal\taxonomy\Entity\Term;
use Psr\Log\LoggerInterface;

/**
 * Pull client for the NPR CDS API.
 */
class NprCdsPullClient implements NprPullClientInterface {
  use StringTranslationTrait;

  /**
   * API client.
   *
   * @var \Drupal\npr_api\NprCdsClient
   */
  protected $client;

  /**
   * The primary image field on the story.
   *
   * @var string
   */
  protected $primaryImageField;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The multimedia field on the story.
   *
   * @var string
   */
  protected $multimediaField;

  /**
   * The secondary images field on the story.
   *
   * @var array
   */
  protected $additionalImagesField;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Logger interface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Node that is imported.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $node;

  /**
   * Field name of audio field.
   *
   * @var string
   */
  protected $audioField;

  /**
   * Queue Factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The external asset field on the story.
   *
   * @var string
   */
  protected $externalAssetField;

  /**
   * Displays error messages on the screen.
   *
   * @var bool
   */
  protected $displayMessages;

  /**
   * Constructor.
   *
   * @param \Drupal\npr_api\NprCdsClient $client
   *   NPR API client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   File system.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   Queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   State.
   */
  public function __construct(
    NprCdsClient $client,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
    FileSystemInterface $fileSystem,
    ModuleHandlerInterface $moduleHandler,
    MessengerInterface $messenger,
    QueueFactory $queue,
    StateInterface $state
  ) {
    $this->client = $client;
    $this->entityTypeManager = $entityTypeManager;
    $this->config = $configFactory;
    $this->logger = $logger;
    $this->fileSystem = $fileSystem;
    $this->moduleHandler = $moduleHandler;
    $this->messenger = $messenger;
    $this->queueFactory = $queue;
    $this->state = $state;

    $pull_url = $this->config->get('npr_pull.settings')->get('npr_pull_url');
    if (!empty($pull_url)) {
      $this->client->setUrl($pull_url);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getStories(array $params) {
    unset($params['fields']);
    return $this->client->getStories($params);
  }

  /**
   * {@inheritDoc}
   */
  public function getStoriesByOrgId(int $id, array $options = []): array|object {
    $options += [
      'num_results' => 1,
      'start_num' => 0,
      'start_date' => '',
      'end_date' => '',
    ];
    $params = [
      'ownerHrefs=' => 'https://organization.api.npr.org/v4/services/' . $id,
    ];
    if (!empty($options['start_date'])) {
      $params['publishDateTime'] = $options['start_date'];
      if (!empty($options['end_date'])) {
        $params['publishDateTime'] .= '...' . $options['end_date'];
      }
    }

    $params['offset'] = $options['start_num'];
    $params['limit'] = $options['num_results'];

    return $this->getStories($params);
  }

  /**
   * {@inheritDoc}
   */
  public function getStoriesByTopicId(int $id, array $options = []): array|object {
    $options += [
      'num_results' => 1,
      'start_num' => 0,
      'sort' => 'dateDesc',
      'start_date' => '',
      'end_date' => '',
    ];
    if ($options['num_results'] > 50) {
      throw new \Exception(dt('Because this command accepts a date range, and due to the way the NPR API works, this command cannot process more than 50 stories at one time.'));
    }

    $params = [
      'limit' => $options['num_results'],
      'collectionIds' => $id,
      'sort' => 'publishDateTime:' . ($options['sort'] == 'dateDesc' ? 'desc' : 'asc'),
    ];

    if ($options['start_num'] > 0) {
      $params['offset'] = $options['start_num'];
    }

    // Add start and end dates, if included.
    if (!empty($options['start_date'])) {
      $params['publishDateTime'] = $options['start_date'];
      if (!empty($options['end_date'])) {
        $params['publishDateTime'] .= '...' . $options['end_date'];
      }
    }

    return $this->getStories($params);
  }

  /**
   * {@inheritDoc}
   */
  public function addOrUpdateNode($story, $published, $display_messages = FALSE, $manual_import = FALSE, $force = FALSE) {
    $this->displayMessages = $display_messages;
    if (!is_array($story) && !empty($story)) {
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
    if (empty($id_field) || $id_field == 'unused') {
      $this->nprError('Please configure the story id field.');
      return NULL;
    }
    $node_last_modified = $story_mappings['lastModifiedDate'];
    if (empty($node_last_modified) || $node_last_modified == 'unused') {
      $this->nprError('Please configure the story last modified date field.');
      return;
    }
    $text_format = $story_config->get('body_text_format');
    if (empty($text_format)) {
      $this->nprError('Please configure the story body text format.');
      return;
    }
    $teaser_text_format = $story_config->get('teaser_text_format');
    $teaser = $story_mappings['teaser'];
    if (empty($teaser) || $teaser == 'unused' || empty($teaser_text_format)) {
      $this->nprError('Please configure the story teaser text format.');
      return;
    }
    $correction_text_format = $story_config->get('correction_text_format');
    $correctionText = $story_mappings['correctionText'];
    if (empty($correctionText) || $correctionText == 'unused' || empty($correction_text_format)) {
      $this->nprError('Please configure the story correction text format.');
      return;
    }
    $pull_author = $this->config->get('npr_pull.settings')->get('npr_pull_author');

    $this->node = $node_manager->loadByProperties([$id_field => $story['id']]);
    // Check to see if a story node already exists in Drupal.
    if (!empty($this->node)) {
      // Record the operation being performed for a later status message.
      $operation = "updated";
      if (count($this->node) > 1) {
        $this->nprError(
          $this->t('More than one story with the Drupal ID @id exists. Please delete the duplicate stories.', [
            '@id' => $story['id'],
          ])
        );
        return;
      }
      $this->node = reset($this->node);

      if ($this->node->field_news_type->entity->label() == "GPB News") {

        $link = Link::fromTextAndUrl($this->node->label(),
        $this->node->toUrl())->toString();
        $operation = "skipped";

        $this->nprStatus($this->t('Story @link was @operation because it belongs to "GPB News".', [
          '@link' => $link,
          '@operation' => $operation,
        ]));

        return;
      }

      // Don't update stories that have not been updated.
      $drupal_story_last_modified = $this->node->getChangedTime();

      // Convert the NPR item's last modified date to the form used in Drupal.
      $dt_npr =
        isset($story['editorialMajorUpdateDateTime']) ?
          new DrupalDateTime($story['editorialMajorUpdateDateTime']): NULL;

      if ($dt_npr) {
        $dt_npr->setTimezone(new \DateTimezone(DateTimeItemInterface::STORAGE_TIMEZONE));
        $story_last_modified = $dt_npr->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
        $npr_story_last_modified = strtotime($story_last_modified);

        if ($drupal_story_last_modified >= $npr_story_last_modified && !$force) {
          $this->nprStatus(
            $this->t('The NPR story with the NPR ID @id has not been updated in the NPR API so it was not updated in Drupal.', [
              '@id' => $story['id'],
            ]));
          return;
        }
      }

      // Otherwise, update the title, status, and author.
      $this->node->set('title', $story['title']);
      $this->node->set('uid', $pull_author);
      $this->node->set('status', $published);
    }
    // Otherwise, create a new story node if this is new.
    else {
      $operation = "created";
      $this->node = $node_manager->create([
        'type' => $story_config->get('story_node_type'),
        'title' => $story['title'],
        'language' => 'en',
        'uid' => $pull_author,
        'status' => $published,
      ]);
    }

    $html_blocks = $this->addorUpdateHtmlBlock($story);

    // Make the image fields available to other methods.
    $this->primaryImageField = $story_mappings['primary_image'];
    $primary_image_field = $this->primaryImageField;
    $this->additionalImagesField = $story_mappings['additional_images'];
    $additional_images_field = $this->additionalImagesField;
    // Create media images and add configured references.
    $media_images = $this->addOrUpdateMediaImage($story);
    if (((!empty($primary_image_field) && $primary_image_field !== 'unused') ||
        (!empty($additional_images_field) && $additional_images_field !== 'unused')) &&
      !empty($media_images)) {
      foreach ($media_images as $media_image) {
        $image_type = $story_config->get('image_field_mappings.type');
        if ($media_image->{$image_type}->value == "primary") {
          $this->node->{$primary_image_field}[] = ['target_id' => $media_image->id()];
        }
        elseif ($media_image->{$image_type}->value == "standard") {
          $this->node->{$additional_images_field}[] = ['target_id' => $media_image->id()];
        }
      }
    }

    // Make the audio field available to other methods.
    $this->audioField = $story_mappings['audio'];
    $audio_field = $this->audioField;
    // Add a reference to the media audio.
    $media_audio_ids = $this->addOrUpdateMediaAudio($story);
    if ($audio_field == 'unused') {
      $this->nprError('This story contains audio, but the audio field for NPR stories has not been configured. Please configure it.');
    }
    if (!empty($audio_field) && $audio_field !== 'unused' && !empty($media_audio_ids)) {
      foreach ($media_audio_ids as $media_audio_id) {
        $this->node->{$audio_field}[] = ['target_id' => $media_audio_id];
      }
    }

    // Make the multimedia field available to other methods.
    $this->multimediaField = $story_mappings['multimedia'];
    $multimedia_field = $this->multimediaField;
    // Add a reference to the media audio.
    $media_multimedia_ids = $this->addOrUpdateMediaMultimedia($story);
    if ($multimedia_field == 'unused') {
      $this->nprError('This story contains multimedia, but the multimedia field for NPR stories has not been configured. Please configure it.');
    }
    if (!empty($multimedia_field) && $multimedia_field !== 'unused' && !empty($media_multimedia_ids)) {
      foreach ($media_multimedia_ids as $media_multimedia_id) {
        $this->node->{$multimedia_field}[] = ['target_id' => $media_multimedia_id];
      }
    }

    // Make the external asset field available to other methods.
    $this->externalAssetField = $story_mappings['externalAsset'];
    $external_asset_field = $this->externalAssetField;
    // Add a reference to the external asset.
    $media_external_asset_ids = $this->addOrUpdateMediaExternalAsset($story);

    if ($external_asset_field == 'unused') {
      $this->nprError('This story contains external assets, but the external asset field for NPR stories has not been configured. Please configure it.');
    }
    if (!empty($external_asset_field) && $external_asset_field !== 'unused' && !empty($media_external_asset_ids)) {
      foreach ($media_external_asset_ids as $media_external_asset_id) {
        $this->node->{$external_asset_field}[] = ['target_id' => $media_external_asset_id];
      }
    }

    // Add data to the remaining fields except image and audio.
    foreach ($story_mappings as $key => $value) {

      // Don't add unused fields.
      if ($value == 'unused' || empty($value)) {
        continue;
      }

      $correction_fields = [
        'correctionTitle',
        'correctionText',
        'correctionDate',
      ];

      if (!in_array($key, ['image', 'audio'])) {

        // ID doesn't have a "value" property.
        if ($key == 'id') {
          $this->node->set($value, $story['id']);
        }
        elseif ($key == 'body') {
          // Find any image placeholders.
          preg_match_all('(\[npr_image:\d*])', $story['body'], $image_placeholders);

          if (!empty($image_placeholders[0])) {
            // Get the associated <drupal-media> tags and replace the
            // placeholders in the body text.
            $image_replacements = $this->replaceImages($image_placeholders[0]);
            $story['body'] = str_replace(array_keys($image_replacements), array_values($image_replacements), $story['body']);
          }

          // Find any multimedia placeholders.
          preg_match_all('(\[npr_multimedia:\d*])', $story['body'], $multimedia_placeholders);
          if (!empty($multimedia_placeholders[0])) {
            // Get the associated items and replace the placeholders in the
            // body text.
            if ($multimedia_replacements = $this->replaceMultimedia($multimedia_placeholders[0])) {
              $story['body'] = str_replace(array_keys($multimedia_replacements), array_values($multimedia_replacements), $story['body']);
            }
          }

          // Find any external asset placeholders.
          preg_match_all('(\[npr_external:\d*])', $story['body'], $external_placeholders);
          if (!empty($external_placeholders[0])) {
            // Get the associated items and replace the placeholders in the
            // body text.
            if ($external_replacements = $this->replaceExternalAssets($external_placeholders[0])) {
              $story['body'] = str_replace(array_keys($external_replacements), array_values($external_replacements), $story['body']);
            }
          }

          // Find any html block placeholders.
          $html_block_replacements = [];
          /** @var \Drupal\media\Entity\Media $block */
          foreach ($html_blocks as $block) {
            $token = '[npr_html:' . $block->name->value . ']';
            $uuid = $block->uuid();
            $html_block_replacements[$token] = '<drupal-media data-entity-type="media" data-entity-uuid="' . $uuid . '"></drupal-media>';
          }
          if (!empty($html_block_replacements)) {
            $story['body'] = str_replace(array_keys($html_block_replacements), array_values($html_block_replacements), $story['body']);
          }

          $this->node->set($value, [
            'value' => $story['body'],
            'format' => $text_format,
          ]);
        }
        elseif ($key == 'teaser') {
          $this->node->set($value, [
            'value' => $story['teaser'],
            'format' => $teaser_text_format,
          ]);
        }
        elseif ($key == 'link') {
          $this->node->set($value, ['uri' => $story['webPages'][0]['href']]);
        }
        elseif ($key == 'imported_manually') {
          if ($manual_import) {
            $this->node->set($value, TRUE);
          }
        }
        elseif (in_array($key, array_keys($story_config->get('parent_vocabulary')))) {
          // Get the vocabulary for the current "parent" item (topic, tag, etc).
          $parent_item_vocabulary = $story_config->get('parent_vocabulary.' . $key);
          // Get the vocabulary prefix for the current "parent" item.
          $parent_item_vocabulary_prefix = $story_config->get('parent_vocabulary_prefix.' . $key . '_prefix');
          // Get the story field for the current "parent" item.
          $parent_item_field = $story_config->get('story_field_mappings.' . $key);
          if (empty($story['collections'])) {
            continue;
          }
          if ($key == 'artist') {
            $key = 'music-artist';
          }
          foreach ($story['collections'] as $item) {
            if (in_array($key == 'primaryTopic' ? 'topic' : $key, $item['rels'] ?? []) && $parent_item_field != 'unused') {
              // Add a prefix to the term, if necessary.
              if ($parent_item_vocabulary_prefix != '') {
                $saved_term = $parent_item_vocabulary_prefix . $item['embed']['title'];
              }
              else {
                $saved_term = $item['embed']['title'];
              }
              if (!empty($saved_term)) {
                // Get the existing referenced item or create one.
                $tid = $this->getTermId($saved_term, $item['embed']['id'], $parent_item_vocabulary);
                $ref_terms = $this->node->get($parent_item_field)->getValue();
                // Get a list of all items already referenced in the field.
                $referenced_ids = array_column($ref_terms, 'target_id');
                // If the item is not already referenced, add a reference.
                if ($tid > 0 && !in_array($tid, $referenced_ids)) {
                  $this->node->{$parent_item_field}[] = ['target_id' => $tid];
                }
              }
              if ($key == 'primaryTopic') {
                break;
              }
            }
          }
        }
        elseif (in_array($key, $correction_fields) && !empty($story['corrections'])) {
          $correction = $story['corrections'][0]['embed'];
          if ($key == 'correctionText') {
            $this->node->set($value, [
              'value' => $correction['text'],
              'format' => $correction_text_format,
            ]);
          }
          elseif ($key == 'correctionDate') {
            $date_value = $this->formatDate($correction['dateTime'], $value);
            $this->node->set($value, $date_value);
          }
          elseif ($key == 'correctionTitle') {
            $this->node->set($value, $correction['title']);
          }
          elseif (!empty($correction[$key])) {
            $this->node->set($value, $correction[$key]);
          }
        }
        elseif ($key == 'slug') {
          foreach ($story['collections'] as $item) {
            if (in_array('slug', $item['rels'] ?? [])) {
              $this->node->set($value, $item['embed']['title']);
            }
          }
        }
        elseif ($key == 'byline' && !empty($story['bylines'])) {
          foreach ($story['bylines'] as $byline) {
            $uri = 'route:<nolink>';
            $title = $byline['embed']['name'] ?? '';

            if (!empty($byline['embed']['bylineDocuments'][0]['href'])) {
              $response = $this->client->request('GET', $byline['embed']['bylineDocuments'][0]['href']);
              if ($response->getStatusCode() == 200) {
                $byline = json_decode($response->getBody()->getContents(), TRUE);
                $byline = $byline['resources'][0];
                if (!empty($byline['webPages'][0]['href'])) {
                  $uri = $byline['webPages'][0]['href'];
                }
                if (!empty($byline['title'])) {
                  $title = $byline['title'];
                }
              }
            }

            $fieldValue[] = [
              'title' => $title,
              'uri' => $uri,
            ];
            $this->node->set($value, $fieldValue);
          }
        }
        elseif ($key == 'pubDate' && !empty($story['editorialLastModifiedDateTime'])) {
          $date_value = $this->formatDate($story['editorialLastModifiedDateTime'], $value);
          $this->node->set($value, $date_value);
        }
        elseif ($key == 'lastModifiedDate' && !empty($story['editorialLastModifiedDateTime'])) {
          $date_value = $this->formatDate($story['editorialLastModifiedDateTime'], $value);
          $this->node->set($value, $date_value);
        }
        elseif ($key == 'storyDate' && !empty($story['publishDateTime'])) {
          $date_value = $this->formatDate($story['publishDateTime'], $value);
          $this->node->set($value, $date_value);
        }
        elseif ($key == 'audioRunByDate') {
          // @todo Figure out what to do here.
        }
        // All of the other fields have a "value" property.
        elseif (!empty($story[$key]) && !is_array($story[$key])) {
          $this->node->set($value, $story[$key]);
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
   * {@inheritDoc}
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
   * {@inheritDoc}
   */
  public function getLastUpdateTime(): \DateTime {
    return $this->state->get(
      self::LAST_UPDATE_KEY,
      new \DateTime('@1')
    );
  }

  /**
   * Sets the date and time of the last API content type sync.
   *
   * @param \DateTime $time
   *   Date and time to set.
   */
  public function setLastUpdateTime(\DateTime $time): void {
    $this->state->set(self::LAST_UPDATE_KEY, $time);
  }

  /**
   * {@inheritDoc}
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
      $npr_ids = $pull_config->get('topic_ids');
    }
    // If there are no topic IDs, just get "News".
    if (empty($npr_ids)) {
      $npr_ids = [1001 => 1001];
    }
    return $npr_ids;
  }

  /**
   * {@inheritDoc}
   */
  public function updateQueue(): bool {
    $dt_start = new \DateTime();

    $pull_config = $this->config->get('npr_pull.settings');
    $num_results = $pull_config->get('num_results');
    $start_date = $pull_config->get('start_date');

    if (empty($start_date)) {
      $this->nprError('Please configure the "Days back" setting on the "Pull Settings" tab.');
      return FALSE;
    }

    $start_timestamp = time() - ($start_date * 86400);
    $start = date("Y-m-d", $start_timestamp);
    $end = date("Y-m-d");

    // Get a list of IDs subscribed to.
    $npr_ids = $this->getSubscriptionIds();

    // Make separate API calls for each topic. If there are many, many topics
    // selected, we may not get data for all of them.
    $update_stories = [];
    foreach ($npr_ids as $npr_id) {
      $params = [
        'num_results' => $num_results,
        'start_date' => $start,
        'end_date' => $end,
      ];
      $stories = $this->getStoriesByTopicId($npr_id, $params);
      foreach ($stories as $story) {
        $update_stories[] = $story;
      }
    }

    $stories_updated = [];
    foreach ($update_stories as $update_story) {
      // Only add a story to the queue once.
      if (!in_array($update_story['id'], $stories_updated)) {
        $this->getQueue()->createItem($update_story);
        $stories_updated[] = $update_story['id'];
      }
    }

    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');
    $imported_manually = $story_mappings['imported_manually'];

    // Add "manually imported" stories to the array of story IDs.
    if (!empty($imported_manually) && $imported_manually !== 'unused') {
      $node_manager = $this->entityTypeManager->getStorage('node');

      // Get a list of node IDS of storys where "manually imported" is checked.
      $nids = $node_manager->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $story_config->get('story_node_type'))
        ->condition($imported_manually, 1)
        ->execute();
      $start_ts = strtotime($start);
      foreach ($nids as $nid) {
        $guid_field = $story_mappings['id'];
        $story = $node_manager->load($nid);
        // Get a timestamp of the configured "Days back" value.
        $story_id = $story->{$guid_field}->value;
        // Determine if the manually-imported story was already checked.
        if (!in_array($story_id, $stories_updated)) {

          // Get a timestamp of the story.
          $story_date_field = $story_mappings['storyDate'];
          if (!empty($story_date_field) && $story_date_field !== 'unused') {
            if ($story_date = $story->{$story_date_field}->value) {
              $story_date = substr($story_date, 0, 10);
              $story_date_ts = strtotime($story_date);
            }
          }

          // If the story is within the "Days back" range add it to the queue.
          if (!empty($story_date_ts) && $story_date_ts >= $start_ts) {
            $params = [
              'id' => $story_id,
              'fields' => 'all',
            ];
            if ($story = $this->getStories($params)) {
              $story = reset($story);
              $this->getQueue()->createItem($story);
            }
          }
        }
      }
    }

    $this->setLastUpdateTime($dt_start);

    return TRUE;
  }

  /**
   * {@inheritDoc}
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
   * Creates a media multimedia item based on the configured field values.
   *
   * The assumption here is that NPR is sending content suitable for their
   * embedded, shareable JW Player. If the response is something else, this
   * will likely not work as expected.
   *
   * @param object $story
   *   A single NPRMLEntity.
   *
   * @return array|null
   *   An array of multimedia media ids or null.
   */
  protected function addOrUpdateMediaMultimedia($story) {

    // Skip if there is no multimedia.
    if (empty($story['multimedia'])) {
      return;
    }

    // Get and check the configuration.
    $story_config = $this->config->get('npr_story.settings');
    $multimedia_media_type = $story_config->get('multimedia_media_type');

    // Get the entity manager.
    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get, and verify, the necessary configuration.
    $mappings = $this->config->get('npr_story.settings')->get('multimedia_field_mappings');
    $multimedia_id_field = $mappings['multimedia_id'];
    if ($multimedia_id_field == 'unused' || $mappings['multimedia_title'] == 'unused' || $mappings['remote_multimedia'] == 'unused') {
      $this->nprError('Please configure the multimedia_id, multimedia_title, and remote_multimedia settings.');
      return NULL;
    }
    $remote_multimedia_field = $mappings['remote_multimedia'];

    // Create the multimedia media item(s).
    foreach ($story['multimedia'] as $multimedia) {
      if (!empty($multimedia['id'])) {
        $uri = 'https://www.npr.org/embedded-video';
        $query = [
          'storyId' => $story['id'],
          'mediaId' => $multimedia['id'],
        ];
        $options = [
          'query' => $query,
        ];
        $multimedia_uri = URL::fromUri($uri, $options)->toString();
      }
      else {
        return;
      }

      // Check to see if a story node already exists in Drupal.
      if ($media_multimedia = $media_manager->loadByProperties([$multimedia_id_field => $multimedia['id'], 'bundle' => $multimedia_media_type])) {
        if (count($media_multimedia) > 1) {
          $this->nprError(
            $this->t('More than one multimedia media item with the ID @id ("@title") exists. Please delete the duplicate multimedia media.', [
              '@id' => $multimedia->id,
              '@title' => $story->title,
            ]));
          return;
        }
        $media_multimedia = reset($media_multimedia);
        // Replace the multimedia field.
        $media_multimedia->set($remote_multimedia_field, ['uri' => $multimedia_uri]);
        $media_multimedia->set('uid', $this->config->get('npr_pull.settings')->get('npr_pull_author'));
        // Clear the reference from the story node.
        $this->node->set($this->multimediaField, NULL);

      }
      else {
        // Otherwise, create a new media multimedia entity. Use the title of the
        // story for the title of the multimedia.
        $media_multimedia = Media::create([
          $mappings['multimedia_title'] => $story->title,
          'bundle' => $multimedia_media_type,
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'langcode' => Language::LANGCODE_NOT_SPECIFIED,
          $remote_multimedia_field => ['uri' => $multimedia_uri],
        ]);
      }
      // Map all of the remaining fields except title and remote_audio.
      foreach ($mappings as $key => $value) {
        if (!empty($value) && $value !== 'unused' && !in_array($key, [
          'multimedia_title',
          'remote_multimedia',
        ])) {
          // ID doesn't have a "value" property.
          if ($key == 'multimedia_id') {
            $media_multimedia->set($value, $multimedia['id']);
          }
          // "duration" is used by audio in config, the key name doesn't align
          elseif ($key == 'multimedia_duration' && isset($multimedia['duration'])) {
            $media_multimedia->set($value, $multimedia['duration']);
          }
          elseif (isset($multimedia[$key])) {
            $media_multimedia->set($value, $multimedia[$key]);
          }
        }
      }
      $media_multimedia->save();
      $multimedia_ids[] = $media_multimedia->id();
    }
    return $multimedia_ids;
  }

  /**
   * Adds or updates the html block.
   *
   * @param array $story
   *   The story being imported.
   *
   * @return array
   *   An array of html block media entities.
   */
  protected function addorUpdateHtmlBlock(array $story) {
    $blocks = [];
    if (empty($story['html-block'])) {
      return $blocks;
    }

    /** @var \Drupal\Core\Entity\EntityStorageInterface $media_manager */
    $media_manager = $this->entityTypeManager->getStorage('media');
    $html_block_type = $this->config->get('npr_story.settings')->get('html_block_media_type');
    $html_block_settings = $this->config->get('npr_story.settings')->get('html_block_field_mappings');
    foreach ($story['html-block'] as $block) {
      $id = $block['id'];
      $html = $block['html'];
      $html_block = $media_manager->loadByProperties(['name' => $id]);
      if (count($html_block) > 1) {
        $this->nprError(
          $this->t('More than one html block items with the ID @id exists. Please delete the duplicate multimedia media.', [
            '@id' => $id,
          ]));
        return [];
      }
      if (!$html_block) {
        $html_block = $media_manager->create([
          $html_block_settings['html_block_id'] => $id,
          'bundle' => $html_block_type,
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'langcode' => Language::LANGCODE_NOT_SPECIFIED,
        ]);
      }
      else {
        $html_block = reset($html_block);
      }
      $html_block->{$html_block_settings['html_block_body']} = [
        'value' => $html,
        'format' => $this->config->get('npr_story.settings')->get('html_block_text_format'),
      ];
      $html_block->save();
      $blocks[] = $html_block;
    }

    return $blocks;
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
   * Creates an image media item based on the configured field values.
   *
   * @param array $story
   *   The story being imported.
   *
   * @return array|null
   *   An array of media image ids or null.
   */
  protected function addOrUpdateMediaImage(array $story) {
    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get required configuration.
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

    // If there are no images, we're done.
    if (empty($story['images'])) {
      return;
    }
    else {
      foreach ($story['images'] as $image) {

        // Truncate and clean up the title field.
        $image_title = htmlentities($image['embed']['title'] ?? '');
        $image_title = html_entity_decode($image_title, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $image_title = substr($image_title, 0, 255);

        // Check to see if a media image already exists in Drupal.
        if ($media_image = $media_manager->loadByProperties([$image_id_field => $image['embed']['id'], 'bundle' => $image_media_type])) {
          if (count($media_image) > 1) {
            $this->nprError(
              $this->t('More than one image with the ID @id ("@title") exist. Please delete the duplicate images.', [
                '@id' => $image['embed']['id'],
                '@title' => $image_title,
              ]));
            return;
          }
          /** @var \Drupal\media\Entity\Media $media_image */
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
          $this->node->set($this->primaryImageField, NULL);
          $this->node->set($this->additionalImagesField, NULL);
        }
        else {
          // Create a media entity.
          $media_image = Media::create([
            $mappings['image_title'] => $image_title,
            'bundle' => $image_media_type,
            'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
            'langcode' => Language::LANGCODE_NOT_SPECIFIED,
          ]);
        }
        $image_enclosure = [];
        foreach ($image['embed']['enclosures'] as $enclosure) {
          if (!is_array($enclosure['rels'])) {
            continue;
          }
          if (in_array('primary', $enclosure['rels'])) {
            $image_enclosure = $enclosure;
          }
          if (isset($image['rels']) && in_array('primary', $image['rels']) && in_array('primary', $enclosure['rels'])) {
            $image_enclosure = $enclosure;
            break;
          }
          if (in_array('image-' . $crop_selected, $enclosure['rels'])) {
            $image_enclosure = $enclosure;
            break;
          }
        }
        $image_url = $image_enclosure['href'];
        if (empty($image_url)) {
          $this->nprError(
            $this->t('There is no image of type @crop available for story @title.', [
              '@crop' => $crop_selected,
              '@title' => $story['title'],
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

        // Allow modules to alter the image URL.
        $this->moduleHandler->alter('npr_image_url', $image_url);

        try {
          $file_data = $this->client->request('GET', $image_url);
        }
        catch (\Exception $e) {
          if ($e->hasResponse()) {
            $this->nprError($this->t('There is no image at @image_url for story @title (source URL: @story_url).', [
              '@image_url' => $image_url,
              '@title' => $story['title'],
              '@story_url' => $story['webPages'][0]['href'],
            ]));
          }
          return;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if (!empty($extension)) {
          if (strtolower($extension) == 'jfif') {
            // Replace .jfif extension with .jpg.
            $filename = substr($filename, 0, -4) . 'jpg';
          }
        }

        // Save the image.
        $file = \Drupal::service('file.repository')->writeData($file_data->getBody(), $directory_uri . "/" . $filename, FileSystemInterface::EXISTS_RENAME);

        // Attached the image file to the media item.
        $media_image->set($image_field, [
          'target_id' => $file->id(),
          'alt' => Unicode::truncate($image['embed']['caption'] ?? '', 512, FALSE, TRUE),
        ]);

        // Map all of the remaining fields except image_title and image_field,
        // which are used above.
        foreach ($mappings as $key => $value) {
          if (!empty($value) && $value !== 'unused' && !in_array($key, [
            'image_title',
            'image_field',
          ])) {
            // ID doesn't have a "value" property.
            if ($key == 'image_id') {
              $media_image->set($value, $image['embed']['id']);
            }
            elseif ($key == 'type') {
              $media_image->set($value, isset($image['rels']) && in_array('primary', $image['rels']) ? 'primary' : $crop_selected);
            }
            elseif ($key == 'provider_url') {
              $media_image->set($value, empty($image['embed']['providerLink']) ? NULL : $image['embed']['providerLink']);
            }
            else {
              $media_image->set($value, empty($image['embed'][$key]) ? NULL : $image['embed'][$key]);
            }
          }
        }
        $media_image->save();
        $media_images[] = $media_image;
      }
      return $media_images;
    }
  }

  /**
   * Creates a media external asset item based on the configured field values.
   *
   * @param object $story
   *   A single NPRMLEntity.
   *
   * @return array|null
   *   An array of External Asset media ids or null.
   */
  protected function addOrUpdateMediaExternalAsset($story) {

    // Skip if there is no external asset.
    if (empty($story['externalAsset'])) {
      return;
    }

    // Get the entity manager.
    $media_manager = $this->entityTypeManager->getStorage('media');

    // Get, and verify, the necessary configuration.
    $mappings = $this->config->get('npr_story.settings')->get('external_asset_field_mappings');
    $external_asset_id_field = $mappings['external_asset_id'];
    if ($external_asset_id_field == 'unused' || $mappings['external_asset_title'] == 'unused' || $mappings['oEmbed'] == 'unused') {
      $this->nprError('Please configure the external_asset_id, external_asset_title, and oEmbed settings.');
      return;
    }

    // Create the external asset media item(s)
    $external_asset_ids = [];
    foreach ($story['externalAsset'] as $external_asset) {
      $external_asset_ids[] = $this->createExternalAsset($external_asset, $story, $mappings, $media_manager);
    }

    return $external_asset_ids;
  }

  /**
   * Create an External Asset.
   *
   * @param array $external_asset
   *   An external asset as provided by the API.
   * @param object $story
   *   A single NPRMLEntity.
   * @param array $mappings
   *   The configured mappings for the NPRMLEntity.
   * @param object $media_manager
   *   The media entity manager.
   *
   * @return string|null
   *   An external asset id or NULL.
   */
  protected function createExternalAsset(array $external_asset, $story, array $mappings, $media_manager) {
    // Skip if there is no URL.
    if (!empty($external_asset['url'])) {
      $external_asset_uri = $external_asset['url'];
    }
    else {
      return;
    }

    // Get and check the configuration.
    $story_config = $this->config->get('npr_story.settings');
    $external_asset_media_type = $story_config->get('external_asset_media_type');

    // Retrieve necessary mappings.
    $external_asset_id_field = $mappings['external_asset_id'];
    $oembed_field = $mappings['oEmbed'];

    // Construct the asset title.
    if (!empty($external_asset->type) && !empty($external_asset['externalId'])) {
      $asset_title = $external_asset->type . ' (' . $external_asset['externalId'] . '): ' . $story['title'];
      // This could get long, so truncate it.
      if (strlen($asset_title) > 255) {
        $asset_title = substr($asset_title, 0, 250) . '[...]';
      }
    }
    else {
      $asset_title = $story['title'];
    }

    // Check to see if an external asset entity already exists in Drupal.
    if ($media_external = $media_manager->loadByProperties([$external_asset_id_field => $external_asset['id'], 'bundle' => $external_asset_media_type])) {
      if (count($media_external) > 1) {
        $this->nprError(
          $this->t('More than one external asset media item with the ID @id ("@title") exists. Please delete the duplicate external asset media.', [
            '@id' => $external_asset['id'],
            '@title' => $asset_title,
          ]));
        return;
      }

      $media_external = reset($media_external);
      // Replace the external asset field.
      $media_external->set($mappings['external_asset_title'], $asset_title);
      $media_external->set($oembed_field, ['value' => $external_asset_uri]);
      $media_external->set('uid', $this->config->get('npr_pull.settings')->get('npr_pull_author'));
      // Clear the reference from the story node.
      $this->node->set($this->externalAssetField, NULL);

    }
    else {

      // Otherwise, create a new external asset media entity.
      $media_external = Media::create([
        $mappings['external_asset_title'] => $asset_title,
        'bundle' => $external_asset_media_type,
        'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
        'langcode' => Language::LANGCODE_NOT_SPECIFIED,
        $oembed_field => ['value' => $external_asset_uri],
      ]);
    }
    // Map all the remaining fields except title and the external asset field.
    foreach ($mappings as $key => $value) {
      if (!empty($value) && $value !== 'unused'
        && !in_array($key, ['external_asset_title', 'oEmbed'])) {
        // ID and Type don't have a "value" property.
        if ($key == 'external_asset_id') {
          $media_external->set($value, $external_asset['id']);
        }
        elseif ($key == 'external_asset_type') {
          $media_external->set($value, $external_asset['type']);
        }
        elseif (isset($external_asset[$key])) {
          // Remove the external asset prefix from the key.
          $key = str_replace('external_asset_', '', $key);
          $media_external->set($value, $external_asset[$key]);
        }
      }
    }
    $media_external->save();
    return $media_external->id();
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
   * Creates a media audio item based on the configured field values.
   *
   * @param object $story
   *   A single NPRMLEntity.
   *
   * @return array|null
   *   An array of audio media ids or null.
   */
  protected function addOrUpdateMediaAudio($story) {

    // Skip if there is no audio.
    if (empty($story['audio'])) {
      return;
    }

    // Get and check the configuration.
    $story_config = $this->config->get('npr_story.settings');
    $audio_media_type = $story_config->get('audio_media_type');
    $audio_format = $story_config->get('audio_format');
    $audio_alt_format = $story_config->get('alternate_audio_format');
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
    foreach ($story['audio'] as $audio) {

      $audio_file = [];
      foreach ($audio['embed']['enclosures'] as $enclosure) {
        // Skip passed premium audio.
        if (isset($enclosure['rels']) && in_array('premium', $enclosure['rels'])) {
          continue;
        }
        $audio_uri = strtok($enclosure['href'], '?');
        $file_info = pathinfo($audio_uri);
        if ($file_info['extension'] == $audio_format) {
          $audio_file = $enclosure;
          break;
        }
        // If the audio format is not available, use the alternate audio format.
        if ($file_info['extension'] == $audio_alt_format) {
          $audio_file = $enclosure;
        }
      }

      if (empty($audio_file)) {
        $this->nprError(
          $this->t('An audio file of the correct type could not be found for the story @title.', [
            '@title' => $story['title'],
          ]));
        continue;
      }

      // Check to see if a story node already exists in Drupal.
      if ($media_audio = $media_manager->loadByProperties([$audio_id_field => $audio['embed']['id'], 'bundle' => $audio_media_type])) {
        if (count($media_audio) > 1) {
          $this->nprError(
            $this->t('More than one audio media item with the ID @id ("@title") exist. Please delete the duplicate audio media.', [
              '@id' => $audio['embed']['id'],
              '@title' => $story['title'],
            ]));
          return;
        }
        $media_audio = reset($media_audio);
        // Replace the audio field.
        $media_audio->set($remote_audio_field, ['uri' => $audio_file['href']]);
        $media_audio->set('uid', $this->config->get('npr_pull.settings')->get('npr_pull_author'));
        // Clear the reference from the story node.
        $this->node->set($this->audioField, NULL);

      }
      else {
        // Otherwise, create a new media audio entity. Use the title of the
        // story for the title of the audio.
        $media_audio = Media::create([
          $mappings['audio_title'] => $story['title'],
          'bundle' => $audio_media_type,
          'uid' => $this->config->get('npr_pull.settings')->get('npr_pull_author'),
          'langcode' => Language::LANGCODE_NOT_SPECIFIED,
          $remote_audio_field => ['uri' => $audio_file['href']],
        ]);
      }
      // Map all of the remaining fields except title and remote_audio.
      foreach ($mappings as $key => $value) {
        if (!empty($value) && $value !== 'unused'
          && !in_array($key, ['audio_title', 'remote_audio'])) {
          // ID doesn't have a "value" property.
          if ($key == 'audio_id') {
            $media_audio->set($value, $audio['embed']['id']);
          }
          elseif (!empty($audio['embed'][$key])) {
            $media_audio->set($value, $audio['embed'][$key]);
          }
        }
      }
      $media_audio->save();
      $audio_ids[] = $media_audio->id();
    }
    return $audio_ids;
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

  /**
   * Replace image media items in body text.
   *
   * @param array $images
   *   An array of image "tokens" in the format [npr_image:xxxx].
   *
   * @return array|null
   *   An array with the "token" as the key and the media embed code
   *   (<drupal-media>) as the value, or null.
   */
  protected function replaceImages(array $images) {
    // Get the image field information.
    $primary_image_field = $this->primaryImageField;
    $additional_images_field = $this->additionalImagesField;
    // Get the images referenced in the fields.
    $referenced_images = array_merge(
      $this->node->{$primary_image_field}->referencedEntities(),
      $this->node->{$additional_images_field}->referencedEntities()
    );

    // Get mappings.
    $story_config = $this->config->get('npr_story.settings');
    $mappings = $story_config->get('image_field_mappings');
    $image_id_field = $mappings['image_id'];
    $caption_field = $mappings['caption'];
    $copyright_field = $mappings['copyright'];
    $provider_field = $mappings['provider'];
    $provider_url_field = $mappings['provider_url'];

    $image_refs = [];
    foreach ($referenced_images as $referenced_image) {
      // Retrieve the required information for each image.
      $uuid = $referenced_image->uuid();
      if (!empty($image_id_field) && $image_id_field != 'unused') {
        $npr_id = $referenced_image->get($image_id_field)->value;
      }
      if (!empty($caption_field) && $caption_field != 'unused') {
        $caption = $referenced_image->get($caption_field)->value;
        // NOTE: The API doesn't seem to send alt text, so re-using caption.
        $alt = Unicode::truncate($caption, 512, FALSE, TRUE);
      }
      if (!empty($copyright_field) && $copyright_field != 'unused') {
        $copyright = $referenced_image->get($copyright_field)->value;
      }
      if (!empty($provider_field) && $provider_field != 'unused') {
        $provider = $referenced_image->get($provider_field)->value;
      }
      if (!empty($provider_url_field) && $provider_url_field != 'unused') {
        $provider_url = $referenced_image->get($provider_url_field)->value;
      }

      // Set up the image credit.
      // If a provider URL is available, create a link.
      if (!empty($provider_url) && !empty($provider)) {
        $provider = Link::fromTextAndUrl($provider, Url::fromUri($provider_url));
      }

      // If there is either a provider or a copyright, create a credit and add
      // it to the caption.
      // For security reasons, only a limited number of HTML tags, are allowed
      // in the caption, so using <cite> to differentiate the credit.
      if (!empty($provider) || !empty($copyright)) {
        $credit = '<cite class="npr-credit">' . $provider . ' ' . $copyright . '</cite>';
        $caption .= $credit;
      }

      // Encode any HTML entities in the caption so it doesn't get stripped.
      $caption = htmlentities($caption);

      // Add image information to an array with the NPR ID as the key.
      $image_refs[$npr_id] = [
        'uuid' => $uuid,
        'caption' => $caption,
        'alt' => $alt,
      ];
    }

    $image_embed = [];
    // Loop through the images in the API response.
    foreach ($images as $image) {
      // Get the NPR refId and use it to retrieve the correct image out of the
      // array.
      $ref_id = (int) filter_var($image, FILTER_SANITIZE_NUMBER_INT);
      if (isset($image_refs[$ref_id])) {
        // Build the embedded media tag, using the original "token" as the
        // array key.
        $image_embed[$image] = '<drupal-media data-entity-type="media" data-entity-uuid="' . $image_refs[$ref_id]['uuid'] . '" data-caption="' . $image_refs[$ref_id]['caption'] . '" alt="' . $image_refs[$ref_id]['alt'] . '"></drupal-media>';
      }
    }

    return $image_embed;
  }

  /**
   * Replace multimedia items in body text.
   *
   * @param array $multimedia
   *   An array of multimedia "tokens" in the format [npr_multimedia:xxxx].
   *
   * @return array|null
   *   An array with the "token" as the key and the rendered multimedia item
   *   as the value, or null.
   */
  protected function replaceMultimedia(array $multimedia) {
    // Get the multimedia field information.
    $multimedia_field = $this->multimediaField;
    if (empty($multimedia_field) || $multimedia_field == 'unused') {
      return;
    }

    // Get the multimedia items referenced in the fields.
    if (!$this->node->{$multimedia_field}->isEmpty()) {
      $referenced_multimedia = $this->node->{$multimedia_field}->referencedEntities();
    }
    else {
      return;
    }

    // Get mappings.
    $story_config = $this->config->get('npr_story.settings');
    $mappings = $story_config->get('multimedia_field_mappings');
    $multimedia_id_field = $mappings['multimedia_id'];

    $multimedia_refs = [];
    foreach ($referenced_multimedia as $multimedia_item) {
      $uuid = $multimedia_item->uuid();
      // Retrieve the npr_id for each item.
      if (!empty($multimedia_id_field) && $multimedia_id_field != 'unused') {
        $npr_id = $multimedia_item->get($multimedia_id_field)->value;
      }

      if (isset($npr_id)) {
        // Add multimedia to an array with the NPR ID as the key.
        $multimedia_refs[$npr_id] = [
          'uuid' => $uuid,
        ];
      }
    }

    $multimedia_embed = [];
    if (!empty($multimedia_refs)) {
      // Loop through the multimedia items in the API response.
      foreach ($multimedia as $media_item) {
        // Get the NPR refId and use it to retrieve the correct multimedia item
        // out of the array.
        $ref_id = (int) filter_var($media_item, FILTER_SANITIZE_NUMBER_INT);
        if (isset($multimedia_refs[$ref_id])) {
          // Build the embedded media tag, using the original "token" as the
          // array key.
          $multimedia_embed[$media_item] = '<drupal-media data-entity-type="media" data-entity-uuid="' . $multimedia_refs[$ref_id]['uuid'] . '"></drupal-media>';
        }
      }
    }

    return $multimedia_embed;
  }

  /**
   * Replace external asset media items in body text.
   *
   * @param array $assets
   *   An array of asset "tokens" in the format [npr_external:xxxx].
   *
   * @return array|null
   *   An array with the "token" as the key and the media embed code
   *   (<drupal-media>) as the value, or null.
   */
  protected function replaceExternalAssets(array $assets) {
    // Get the external asset field information.
    $external_asset_field = $this->externalAssetField;
    if (empty($external_asset_field) || $external_asset_field == 'unused') {
      return;
    }

    // Get the assets referenced in the fields.
    if (!$this->node->{$external_asset_field}->isEmpty()) {
      $referenced_assets = $this->node->{$external_asset_field}->referencedEntities();
    }
    else {
      return;
    }

    // Get mappings.
    $story_config = $this->config->get('npr_story.settings');
    $mappings = $story_config->get('external_asset_field_mappings');
    $external_asset_id_field = $mappings['external_asset_id'];
    $caption_field = $mappings['external_asset_caption'];
    $credit_field = $mappings['external_asset_credit'];

    $external_refs = [];
    foreach ($referenced_assets as $asset) {
      $uuid = $asset->uuid();
      // Retrieve the npr_id for each item.
      if (!empty($external_asset_id_field) && $external_asset_id_field != 'unused') {
        $npr_id = $asset->get($external_asset_id_field)->value;
      }

      $caption = '';
      if (!empty($caption_field) && $caption_field != 'unused') {
        $caption = $asset->get($caption_field)->value;
      }

      if (!empty($credit_field) && $credit_field != 'unused') {
        $credit = $asset->get($credit_field)->value;
        // For security reasons, only a limited number of HTML tags, are allowed
        // in the caption, so using <cite> to differentiate the credit.
        $credit = '<cite class="npr-credit">' . $credit . '</cite>';
        $caption .= $credit;
      }

      if (isset($npr_id)) {

        // Add rendered external asset to an array with the NPR ID as the key.
        $external_refs[$npr_id] = [
          'uuid' => $uuid,
          'caption' => $caption,
        ];
      }

    }

    $external_embed = [];
    // Loop through the external assets in the API response.
    foreach ($assets as $asset) {
      // Get the NPR refId and use it to retrieve the correct asset out of the
      // array.
      $ref_id = (int) filter_var($asset, FILTER_SANITIZE_NUMBER_INT);
      if (isset($external_refs[$ref_id])) {
        // Build the embedded media tag, using the original "token" as the
        // array key.
        $external_embed[$asset] = '<drupal-media data-entity-type="media" data-entity-uuid="' . $external_refs[$ref_id]['uuid'] . '" data-caption="' . $external_refs[$ref_id]['caption'] . '"></drupal-media>';
      }
    }

    return $external_embed;
  }

  /**
   * Convert dates from NPR's format to Drupal's.
   *
   * @param string $date
   *   A date from the API.
   * @param string $field
   *   The name of the date field.
   *
   * @return string
   *   The formatted date
   */
  public function formatDate($date, $field) {
    $dt_npr = new DrupalDateTime($date);
    $dt_npr->setTimezone(new \DateTimezone(DateTimeItemInterface::STORAGE_TIMEZONE));

    if (in_array($field, ['created', 'changed'])) {
      $date_value = $dt_npr->getTimestamp();
    }
    else {
      $date_value = $dt_npr->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    }

    return $date_value;
  }

  /**
   * {@inheritDoc}
   */
  public function getQueue(): QueueInterface {
    return $this->queueFactory->get('npr_api.queue.story');
  }

}
