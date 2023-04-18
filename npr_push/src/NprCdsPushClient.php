<?php

namespace Drupal\npr_push;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\node\NodeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\npr_api\NprCdsClient;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Push data from Drupal nodes to the NPR API.
 */
class NprCdsPushClient implements NprPushClientInterface {

  use StringTranslationTrait;
  use MessengerTrait;

  /**
   * Npr Api Client.
   *
   * @var \Drupal\npr_api\NprCdsClient
   */
  protected $client;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Push config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $pushConfig;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\npr_api\NprCdsClient $client
   *   NPR Client.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(ConfigFactoryInterface $configFactory, NprCdsClient $client, MessengerInterface $messenger, LoggerInterface $logger, EntityTypeManagerInterface $entityTypeManager) {
    $this->config = $configFactory;
    $this->pushConfig = $configFactory->get('npr_push.settings');
    $this->client = $client;
    $client->setUrl($this->pushConfig->get('cds_ingest_url'));
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public function createOrUpdateStory(array $story) {
    // Get the story field mappings and send the data.
    $org_id = $this->pushConfig->get('org_id');
    $options = [
      RequestOptions::JSON => $story,
    ];

    $response = $this->client->request('PUT', '/v1/documents/' . $story['id'], $options);
    if ($response->getStatusCode() == 200) {
      $sent_message = new FormattableMarkup('Story sent to the NPR story API at
      the URL @url with the following data: <pre>@xml</pre>', [
          '@url' => '/v1/documents/' . $story['id'],
          '@xml' => print_r($options, TRUE),
        ]
      );
      $this->logger->info($sent_message);
    } else {
      $message = $response->getBody()->getContents();
      $this->nprError('Error sending story: ' . $message);
    }

    return $response;
  }

  /**
   * {@inheritDoc}
   */
  public function deleteStory(NodeInterface $node) {
    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');

    // NPR ID field.
    $id_field = $story_mappings['id'];
    if ($id = $node->{$id_field}->value) {
      return $this->client->request('DELETE', '/v1/document/' . $id);
    }

    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function createNprmlEntity(NodeInterface $node) {

    $idPrefix = $this->pushConfig->get('cds_doc_id_prefix');
    $serviceId = $this->pushConfig->get('org_id');
    $serviceUrl = 'https://organization.api.npr.org/v4/services/' . $serviceId;

    $story = [
      'id' => $idPrefix . '-' . $node->id(),
      'owners' => [
        [
          'href' => $serviceUrl,
        ],
      ],
      'brandings' => [
        [
          'href' => $serviceUrl,
        ],
      ],
      'profiles' => [
        [
          'href' => '/v1/profiles/story',
          'rels' => [
            'type',
          ],
        ],
        [
          'href' => '/v1/profiles/publishable',
          'rels' => [
            'interface',
          ],
        ],
        [
          'href' => '/v1/profiles/renderable',
          'rels' => [
            'interface',
          ],
        ],
        [
          'href' => '/v1/profiles/document',
        ],
      ],
    ];

    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');

    // NPR ID field.
    $id_field = $story_mappings['id'];
    if ($id_field == 'unused') {
      $this->nprError('Please configure the story id field.');
      return;
    }
    if ($id_value = $node->{$id_field}->value) {
      $story['id'] = $id_value;
    }

    // Story title.
    if ($title = substr($node->getTitle(), 0, 100)) {
      $story['title'] = $title;
    }

    // Story body.
    $body_field = $story_mappings['body'];
    if ($body_field == 'unused') {
      $this->nprError('Please configure the body field.');
      return;
    }
    if ($body = $node->{$body_field}->value) {
      $body = check_markup($body, $node->{$body_field}->format);
      /** @var \Drupal\filter\FilterPluginManager $filter_plugin_manager */
      $filter_plugin_manager = \Drupal::service('plugin.manager.filter');
      /** @var \Drupal\npr_push\Plugin\Filter\RelToAbs $rel_to_abs */
      $rel_to_abs = $filter_plugin_manager->createInstance('npr_rel_to_abs');
      $body = $rel_to_abs->process($body, 'en')->getProcessedText();
      $story['layout'][] = [
        'href' => '#/assets/' . $idPrefix . '-body',
      ];
      $story['assets'][$idPrefix . '-body'] = [
        'id' => $idPrefix . '-body',
        'text' => $body,
        'profiles' => [
          [
            'href' => '/v1/profiles/text',
            'rels' => ['type'],
          ],
          [
            'href' => '/v1/profiles/document',
          ],
        ],
      ];

      $textSummary = text_summary($body);
      $story['teaser'] = $textSummary;
    }

    // Story date and publication date.
    $story_date = \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'custom', "c");
    $story['editorialMajorUpdateDateTime'] = $story_date;

    // Story URL.
    $url = $node->toUrl()->setAbsolute()->toString();
    $story['webPages'][] = [
      'href' => $url,
      'rels' => [
        'canonical',
      ],
    ];

    // Primary topic.
    $story['collections'] = [];
    $primary_topic_field = $story_mappings['primaryTopic'];
    $primary_topic = $primary_topic_field == 'unused' ? $node->get($primary_topic_field)->referencedEntities() : NULL;
    $primary_topic = is_array($primary_topic) ? reset($primary_topic) : NULL;
    $slug_field = $story_mappings['slug'];
    $slug_value = $slug_field == 'unused' ? $node->{$slug_field}->value : NULL;
    $slug_value = is_array($slug_value) ? reset($slug_value) : NULL;
    $secondary_topic_field = $story_mappings['topic'];
    $secondary_topics = $secondary_topic_field == 'unused' ? $node->get($secondary_topic_field)->referencedEntities() : NULL;
    if (is_array($secondary_topics)) {
      foreach ($secondary_topics as $topic) {
        $topic_id = $topic->field_npr_news_id->value;
        $collection = [
          'href' => '/v1/documents/' . $topic_id,
          'rels' => [
            'topic',
          ],
        ];
        if ($slug_value && $slug_value->getName() == $topic->getName()) {
          $collection['rels'][] = 'slug';
        }
        if ($primary_topic && $primary_topic->field_npr_news_id->value == $topic_id) {
          array_unshift($story['collections'], $collection);
          $primary_topic = NULL;
          continue;
        }
        $story['collections'][] = $collection;
      }
    }

    // Subtitle.
    if ($subtitle_field = $story_mappings['subtitle']) {
      if (!empty($node->{$subtitle_field}->value) &&
        !empty($subtitle_field)
        && $subtitle_field !== 'unused'
      ) {
        $subtitle = $node->{$subtitle_field}->value;
        $story['subtitle'] = $subtitle;
      }
    }

    $textfields = ['subtitle', 'shortTitle', 'miniTeaser'];
    foreach ($textfields as $field) {
      if ($drupal_field = $story_mappings[$field]) {
        if (!empty($drupal_field) && $drupal_field !== 'unused') {
          if ($value = $node->{$drupal_field}->value) {
            switch ($field) {
              case 'subtitle':
                $story['subTitle'] = $value;
                break;

              case 'shortTitle':
                $story['socialTitle'] = $value;
                break;

              case 'miniTeaser':
                $story['shortTeaser'] = $value;
                break;

              default:
                $story[$field] = $value;
            }
          }
        }
      }
    }

    // Images.
    if ($image_field = $story_mappings['primary_image']) {
      if (!empty($image_field) && $image_field !== 'unused') {

        // Verify required image field mappings.
        $image_mappings = $story_config->get('image_field_mappings');
        $image_image_field = $image_mappings['image_field'];
        if ($image_image_field == 'unused') {
          $this->nprError('In order to push media images to NPR, please configure the image_field mappings in the "Image Field Mappings" section of the "Story Settings" tab.');
          return;
        }

        $media_image = $node->get($image_field)->referencedEntities();
        $media_image = reset($media_image);
        if (!empty($media_image->{$image_image_field}) &&
          $image_references = $media_image->{$image_image_field}
        ) {
          $image_id = $idPrefix . '-media-' . $media_image->id();
          $story['profiles'][] = [
            'href' => '/v1/profiles/has-images',
            'rels' => [
              'interface'
            ],
          ];
          foreach ($image_references as $image_reference) {
            $file_id = $image_reference->get('target_id')->getValue();
            if ($image_file = $this->entityTypeManager->getStorage('file')->load($file_id)) {
              // Get the image URL.
              $image_uri = $image_file->get('uri')->getString();
              $image_url = \file_create_url($image_uri);

              $story['images'][] = [
                'href' => '#/assets/' . $image_id,
                'rels' => [
                  'primary'
                ],
              ];

              $story['assets'][$image_id] = [
                'id' => $image_id,
                'profiles' => [
                  [
                    'href' => '/v1/profiles/image',
                    'rels' => [
                      'type',
                    ],
                  ],
                  [
                    'href' => '/v1/profiles/document',
                  ],
                ],
                'enclosures' => [
                  [
                    'href' => $image_url,
                  ]
                ],
              ];
            }
          }
        }
      }
    }
    return $story;
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

}
