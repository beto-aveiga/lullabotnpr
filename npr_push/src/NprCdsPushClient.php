<?php

namespace Drupal\npr_push;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\gpb_api_integration\GpbApiSimpleXMLElement;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\npr_api\NprCdsClient;
use Drupal\npr_pull\NprPushClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Push data from Drupal nodes to the NPR API.
 */
class NprCdsPushClient implements NprPushClientInterface {

  use StringTranslationTrait;

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
    $this->client = $client;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public function createOrUpdateStory(NodeInterface $node) {
    $story = $this->createNprmlEntity($node);
    // Add audio data, if there is an audio file.
    if (!$node->get('field_audio')->isEmpty() &&
      $media_audio = $node->get('field_audio')->referencedEntities()) {
      $story = $this->createNprmlAudio($story, reset($media_audio));
    }

    // Add byline data, if available.
    if (!$node->get('field_author')->isEmpty()) {
      $story = $this->createNprmlByline($story, $node);
    }

    // Add NPR One data, if available.
    if (!empty($values['include_in_npr_one'])) {
      $story = $this->createNprmlNprOne($story, $node, $values);
    }

    // Use the Deck field, if available, for the teaser.
    if (!$node->get('field_summary')->isEmpty()) {
      $story = $this->createNprmlDeck($story, $node);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function deleteStory(NodeInterface $node) {
    // TODO: Implement deleteStory() method.
  }

  /**
   * {@inheritDoc}
   */
  public function createNprmlEntity(NodeInterface $node) {

    $idPrefix = 'gpb';
    $serviceId = 's448';
    $serviceUrl = 'https://organization.api.npr.org/v4/services/' . $serviceId;

    $story = [
      'id' => $idPrefix . '-',
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

      $textSummary = text_summary($body);
      $story['teaser'] = $textSummary;
    }

    // Story date and publication date.
    $story_date = \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'custom', "c");
    $story['editorialMajorUpdateDateTime'] = $story_date;

    // Story URL.
    $url = $node->toUrl()->setAbsolute()->toString();
    $story['webpages'] = [
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
   * Adds audio to an NPRMLEntity story object.
   *
   * @param object $story_xml
   *   An NPRMLEntity story object.
   * @param object $media_audio
   *   A Drupal "NPR Audio" entities attached to the News Article node.
   *
   * @return object
   *   An NPRMLEntity story object.
   */
  private function createNprmlAudio($story_xml, $media_audio) {

    $xml = new \SimpleXMLElement($story_xml);
    $story = $xml->list->story;

    // Get the Stream Guys Recast ID from the media audio.
    if (!$media_audio->hasField('field_sgrecast_id') && $media_audio->hasField('field_npr_news_id')) {
      $this->messenger()->addError('The attached NPR Audio was not sent to NPR. Only SGrecast Audio can be sent to the API.');
      return $story_xml;
    }
    elseif (!$media_audio->get('field_sgrecast_id')->isEmpty()) {
      $sgrecast_id = $media_audio->get('field_sgrecast_id')->value;
    }
    else {
      return $story_xml;
    }

    // Load the audio data from Stream Guys.
    if (!is_array($sgrecast_id) && $sgrecast_audio = $this->sgRecast->getAudioContent($sgrecast_id)) {
      // Create the audio part of the NPRMLEntity.
      $audio = $story->addChild('audio');
      $audio->addAttribute('type', 'primary');

      // Duration example: <duration>42</duration>.
      $audio->addChild('duration', $sgrecast_audio['duration']);
    }

    // Get the "rightsHolder" value from the "Credit" field on the media audio.
    // rightsHolder example: <rightsHolder>Stephen Thompson</rightsHolder>.
    if ($credit = $media_audio->field_audio_credit->getValue()) {
      $credit = reset($credit);
      $audio->addChild('rightsHolder', $credit['value']);
    }

    // Add the Stream Guys audio extension and URL.
    if (!empty($sgrecast_audio['extension']) && !empty($sgrecast_audio['url'])) {
      // Format example:
      // <format>
      //   <mp3>https://ondemand.npr.org/example1.mp3</mp3>
      //   <mp4>https://ondemand.npr.org/example1.aac</mp4>
      // </format>
      $format = $audio->addChild('format');
      $format->addChild($sgrecast_audio['extension'], $sgrecast_audio['url']);
    }

    return $xml->asXML();

  }

  /**
   * Adds byline to an NPRMLEntity story object.
   *
   * @param object $story_xml
   *   An NPRMLEntity story object.
   * @param Drupal\node\Entity\Node $node
   *   An News Article node.
   *
   * @return object
   *   An NPRMLEntity story object.
   */
  private function createNprmlByline($story_xml, Node $node) {

    $xml = new \SimpleXMLElement($story_xml);
    $story = $xml->list->story;

    $authors = $node->get('field_author')->referencedEntities();
    foreach ($authors as $author) {
      $given_name = $author->get('field_full_name')->given;
      $family_name = $author->get('field_full_name')->family;
      if (!empty($given_name) && !empty($family_name)) {
        $byline = $story->addChild('byline');
        $name = $byline->addChild('name', $given_name . " " . $family_name);
        // If a personId exists in the API, it doesn't matter what name is sent
        // to NPR -- it will just retrieve the record for the personId on file.
        if (!$author->get('field_npr_guid')->isEmpty() &&
          $personId = $author->get('field_npr_guid')->value) {
          $name = $name->addAttribute('personId', $personId);
        }
      }
    }

    return $xml->asXML();

  }

  /**
   * Add NPR One data to an NPRMLEntity story object.
   *
   * @param object $story_xml
   *   An NPRMLEntity story object.
   * @param Drupal\node\Entity\Node $node
   *   An News Article node.
   * @param array $values
   *   An form values.
   *
   * @return object
   *   An NPRMLEntity story object.
   */
  private function createNprmlNprOne($story_xml, Node $node, array $values) {

    $xml = new \SimpleXMLElement($story_xml);
    $story = $xml->list->story;

    // Mark for inclusion in NPR One.
    $npr_one = $story->addChild('parent');
    $npr_one->addAttribute('id', '319418027');
    $npr_one->addAttribute('type', 'collection');

    // Mark stories that should be featured locally in NPR One.
    if (!empty($values['feature_locally_in_npr_one'])) {
      $local = $story->addChild('parent');
      $local->addAttribute('id', '500549367');
      $local->addAttribute('type', 'collection');

      // Add the audioRunByDate, if entered.
      if (!empty($values['npr_one_expiration_date'])) {
        $dt_exp_date = new DrupalDateTime(
          $values['npr_one_expiration_date'],
          DateTimeItemInterface::STORAGE_TIMEZONE
        );
        // NPR requires dates to be in RFC2822 format ("D, d M Y H:i:s O").
        $npr_exp_date = $dt_exp_date->format(DATE_RFC2822);
        $npr_one_exp_date = $story->addChild('audioRunByDate', $npr_exp_date);
      }
    }

    return $xml->asXML();

  }

  /**
   * Adds the deck field to the NPRMLEntity story object.
   *
   * @param object $story_xml
   *   An NPRMLEntity story object.
   * @param Drupal\node\Entity\Node $node
   *   An News Article node.
   *
   * @return object
   *   An NPRMLEntity story object.
   */
  private function createNprmlDeck($story_xml, Node $node) {
    $xml = new GpbApiSimpleXMLElement($story_xml);
    $story = $xml->list->story;

    $deck = $node->get('field_summary')[0]->value;
    // Remove the teaser field.
    unset($story->teaser);
    // Add the deck as the teaser.
    $story->addChildWithCDATA('teaser', trim($deck));

    return $xml->asXML();
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
