<?php

namespace Drupal\npr_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves and parses NPRML.
 */
class NprClient implements NprClientInterface {

  // HTTP status code = OK.
  const NPRAPI_STATUS_OK = 200;

  // NPRML constants.
  const NPRML_DATA = '<?xml version="1.0" encoding="UTF-8"?><nprml></nprml>';
  const NPRML_NAMESPACE = 'xmlns:nprml=https://api.npr.org/nprml';

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The media manager settings config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The current logged in user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * State interface.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a NprClient object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \GuzzleHttp\ClientInterface $client
   *   The HTTP client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current logged in user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   Queue factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The filesystem service.
   */
  public function __construct(LoggerInterface $logger, ClientInterface $client, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, AccountInterface $current_user, MessengerInterface $messenger, QueueFactory $queue_factory, StateInterface $state, ModuleHandlerInterface $module_handler, FileSystemInterface $file_system = NULL) {
    $this->logger = $logger;
    $this->client = $client;
    $this->entityTypeManager = $entity_type_manager;
    $this->config = $config_factory;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.channel.npr_api'),
      $container->get('http_client'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('queue'),
      $container->get('state'),
      $container->get('module_handler'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function request($method = 'GET', $url = '', array $options = []) {
    return $this->client->request($method, $url, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function send(RequestInterface $request, array $options = []) {
    return $this->client->send($request, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function sendAsync(RequestInterface $request, array $options = []) {
    return $this->client->sendAsync($request, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function requestAsync($method, $uri, array $options = []) {
    return $this->client->requestAsync($method, $uri, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig($option = NULL) {
    return $this->client->getConfig($option);
  }

  /**
   * Gets stories narrowed by query-type parameters.
   *
   * @param array $params
   *   An array of query-type parameters.
   *
   * @return array|null
   *   An array of parsed object of NPRML stories.
   */
  public function getStories(array $params): array|null {
    $this->getXmlStories($params);
    $this->parse();
    if (!empty($this->stories)) {
      return $this->stories;
    }
    return NULL;
  }

  /**
   * Get story by organization.
   *
   * @param int $id
   *   The organization id.
   * @param array $options
   *   Additional query parameters.
   *
   * @return array|object
   *   The api response.
   */
  public function getStoriesByOrgId(int $id, array $options = [
    'num_results' => 1,
    'start_num' => 0,
    'start_date' => '',
    'end_date' => '',
  ]): array|object {
    $params = [
      'orgId' => $id,
      'fields' => 'all',
      'dateType' => 'story',
    ];
    if (!empty($options['start_date'])) {
      $params['startDate'] = $options['start_date'];
    }
    if (!empty($options['end_date'])) {
      $params['endDate'] = $options['end_date'];
    }

    $params['startNum'] = $options['start_num'];
    $params['numResults'] = $options['num_results'];

    return $this->getStories($params);
  }

  /**
   * Get story by topic.
   *
   * @param int $id
   *   The topic id.
   * @param array $options
   *   Additional query parameters.
   *
   * @return array|object
   *   The api response.
   */
  public function getStoriesByTopicId(int $id, array $options = [
    'num_results' => 1,
    'start_num' => 0,
    'sort' => 'dateDesc',
    'start_date' => '',
    'end_date' => '',
  ]): array|object {
    if ($options['num_results'] > 50) {
      throw new \Exception(dt('Because this command accepts a date range, and due to the way the NPR API works, this command cannot process more than 50 stories at one time.'));
    }

    $params = [
      'numResults' => $options['num_results'],
      'id' => $id,
      'sort' => $options['sort'],
      'fields' => 'all',
    ];

    if ($options['start_num'] > 0) {
      $params['startNum'] = $options['start_num'];
    }

    // Add start and end dates, if included.
    if (!empty($options['start_date'])) {
      $params['startDate'] = $options['start_date'];
    }
    if (!empty($options['end_date'])) {
      $params['endDate'] = $options['end_date'];
    }

    return $this->getStories($params);
  }

  /**
   * Make a GET request.
   */
  public function getXmlStories($options) {

    $this->options = $options;
    $api_config = $this->config->get('npr_api.settings');
    $key = $api_config->get('npr_api_api_key');
    if ($server = $this->config->get('npr_pull.settings')->get('npr_pull_url')) {
      if ($server == 'staging') {
        $base_uri = $api_config->get('npr_api_stage_url');
      }
      else {
        $base_uri = $api_config->get('npr_api_production_url');
      }
    }
    $base_uri .= "/query/";

    if (empty($key) || empty($base_uri)) {
      $this->nprError('The configured NPR API Key is not correct.');
      return;
    }

    // Add the API key to the parameters.
    $options['apiKey'] = $key;
    // @todo Which way should we be sorting?
    $options['sort'] = 'dateDesc';

    // @todo Store these for the report function.
    $this->response = $this->request('GET', $base_uri, ['query' => $options]);
    // Log any errors.
    if ($this->response->getStatusCode() != '200') {
      $this->logger->error($this->response->getReasonPhrase());
      return;
    }
    $this->params = $options;

    $this->xml = $this->response->getBody()->getContents();
  }

  /**
   * Turns raw XML(NPRML) into various object properties.
   */
  public function parse() {
    if (!empty($this->xml)) {
      $xml = $this->xml;
    }
    else {
      $this->notices[] = 'No XML to parse.';
      return;
    }

    $object = simplexml_load_string($xml);
    $this->addSimplexmlAttributes($object, $this);

    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');

    $this->stories = [];
    if (!empty($object->list->story)) {
      foreach ($object->list->story as $story) {
        $parsed = new NPRMLEntity();
        $this->addSimplexmlAttributes($story, $parsed);

        // Iterate trough the XML document and list all the children.
        $xml_iterator = new \SimpleXMLIterator($story->asXML());
        $key = NULL;
        $current = NULL;
        for ($xml_iterator->rewind(); $xml_iterator->valid(); $xml_iterator->next()) {
          $current = $xml_iterator->current();
          $key = $xml_iterator->key();

          if ($key == 'image' || $key == 'audio' || $key == 'multimedia' || $key == 'link') {
            if ($key == 'image') {
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
            if ($key == 'audio') {
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
            if ($key == 'multimedia') {
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
            if ($key == 'link') {
              $type = $this->getAttribute($current, 'type');
              $parsed->{$key}[$type] = $this->parseSimplexmlElement($current);
            }
          }
          else {
            if (empty($parsed->{$key})) {
              // The $key wasn't parsed already, so add the current element.
              $parsed->{$key} = $this->parseSimplexmlElement($current);
            }
            else {
              // If $parsed->$key exists and it's not an array, create an array
              // out of the existing element.
              if (!is_array($parsed->{$key})) {
                $parsed->{$key} = [$parsed->{$key}];
              }
              // Add the new child.
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
          }
        }
        $body = '';
        if (!empty($parsed->layout->storytext) && !empty($parsed->textWithHtml->paragraphs)) {

          // Allow modules to alter the NPR story object.
          $this->moduleHandler->alter('npr_story_object', $parsed);

          // Get the paragraphs and put them into an array for reference later.
          $paragraphs = [];
          foreach ($parsed->textWithHtml->paragraphs as $paragraph) {
            $paragraphs[$paragraph->num] = $paragraph->value;
          }
          // Add the content referenced by each storytext element to the body.
          $body_content = [];
          foreach ($parsed->layout->storytext as $type => $items) {
            switch ($type) {
              case 'text':
                // Add each paragraph to the body.
                // But check to see if the object is multidimensional first.
                if (isset($items->num)) {
                  $body_content[$items->num] = _filter_autop($paragraphs[$items->paragraphNum]);
                }
                else {
                  foreach ($items as $item) {
                    $body_content[$item->num] = _filter_autop($paragraphs[$item->paragraphNum]);
                  }
                }
                break;

              case 'staticHtml':
                // Add the static html assets in the body.
                if (isset($items->num)) {
                  if ($parsed->htmlAsset->id == $items->refId) {
                    $body_content[$items->num] = $parsed->htmlAsset->value;
                  }
                }
                else {
                  foreach ($items as $item) {
                    foreach ($parsed->htmlAsset as $html_asset) {
                      if ($html_asset->id == $item->refId) {
                        $body_content[$item->num] = $html_asset->value;
                      }
                    }
                  }
                }
                break;

              case 'image':
                // Add a placeholder for each referenced image to the body.
                // But check to see if the object is multidimensional first.
                if (isset($items->num)) {
                  $body_content[$items->num] = "[npr_image:" . $items->refId . "]";
                }
                else {
                  foreach ($items as $item) {
                    $body_content[$item->num] = "[npr_image:" . $item->refId . "]";
                  }
                }
                break;

              case 'multimedia':
                $multimedia_field = $story_mappings['multimedia'];
                if (!empty($multimedia_field) && $multimedia_field !== 'unused') {
                  // Add laceholder for each referenced multimedia to the body.
                  // But check to see if the object is multidimensional first.
                  if (isset($items->num)) {
                    $body_content[$items->num] = "[npr_multimedia:" .
                      $items->refId . "]";
                  }
                  else {
                    foreach ($items as $item) {
                      $body_content[$item->num] = "[npr_multimedia:" .
                        $item->refId . "]";
                    }
                  }
                }
                break;

              case 'externalAsset':
                $external_asset_field = $story_mappings['externalAsset'];
                if (!empty($external_asset_field) || $external_asset_field !== 'unused') {
                  // Add a placeholder for each referenced asset to the body.
                  // But check to see if the object is multidimensional first.
                  if (isset($items->num)) {
                    $body_content[$items->num] = "[npr_external:" .
                      $items->refId . "]";
                  }
                  else {
                    foreach ($items as $item) {
                      $body_content[$item->num] = "[npr_external:" .
                        $item->refId . "]";
                    }
                  }
                }
                break;

              default:
                break;
            }
          }
          // Sort it back into the correct order.
          ksort($body_content);
          // Stitch it together.
          $body = implode('', $body_content);
        }
        elseif (!empty($parsed->textWithHtml->paragraphs)) {
          foreach ($parsed->textWithHtml->paragraphs as $paragraph) {
            $body = $body . _filter_autop($paragraph->value);
          }
        }
        $parsed->body = $body;
        $this->stories[] = $parsed;
      }
    }
  }

  /**
   * Converts SimpleXML element into NPRMLElement.
   *
   * @param object $element
   *   A SimpleXML element.
   *
   * @return object
   *   An NPRML element.
   */
  public function parseSimplexmlElement($element) {
    $nprmlElement = new NPRMLElement();
    $this->addSimplexmlAttributes($element, $nprmlElement);
    if (count($element->children())) {
      foreach ($element->children() as $i => $child) {
        if ($i == 'paragraph' || $i == 'mp3') {
          if ($i == 'paragraph') {
            $paragraph = $this->parseSimplexmlElement($child);
            $nprmlElement->paragraphs[$paragraph->num] = $paragraph;
          }
          if ($i == 'mp3') {
            $mp3 = $this->parseSimplexmlElement($child);
            $nprmlElement->mp3[$mp3->type] = $mp3;
          }
        }
        else {
          // If $i wasn't parsed already, so just add the current element.
          if (empty($nprmlElement->$i)) {
            $nprmlElement->$i = $this->parseSimplexmlElement($child);
          }
          else {
            // If $nprmlElement->$i exists and is not an array, create an array
            // out of the existing element.
            if (!is_array($nprmlElement->$i)) {
              $nprmlElement->$i = [$nprmlElement->$i];
            }
            // Add the new child.
            $nprmlElement->{$i}[] = $this->parseSimplexmlElement($child);
          }
        }
      }
    }
    else {
      $nprmlElement->value = (string) $element;
    }
    return $nprmlElement;
  }

  /**
   * Extracts value of a given attribute from a SimpleXML element.
   *
   * @param object $element
   *   A SimpleXML element.
   * @param string $attribute
   *   The name of an attribute of the element.
   *
   * @return string
   *   The value of the attribute (if it exists in element).
   */
  protected function getAttribute($element, $attribute) {
    foreach ($element->attributes() as $k => $v) {
      if ($k == $attribute) {
        return (string) $v;
      }
    }
  }

  /**
   * Generates basic report of NPRML object.
   *
   * @return array
   *   Various messages (strings) .
   */
  public function report() {
    $msg = [];

    $params = '';
    if (isset($this->params)) {
      foreach ($this->params as $k => $v) {
        $params .= " [$k => $v]";
      }
      $msg[] = 'Request params were: ' . $params;
    }
    else {
      $msg[] = 'Request had no parameters.';
    }

    if (!empty($this->response) && !empty($this->response->getStatusCode())) {
      $msg[] = 'Response code was ' . $this->response->getStatusCode() . '.';
    }
    if (!empty($this->stories)) {
      $msg[] = 'Request returned ' . count($this->stories) . ' stories:';
      foreach ($this->stories as $story) {
        if (!empty($story->title) && !empty($story->id)) {
          $msg[] = "$story->title (ID: $story->id)";
        }
      }
    }
    else {
      $msg[] = 'The API key is probably incorrect';
    }

    return $msg;
  }

  /**
   * Add attributes of a SimpleXML element to an object (as properties).
   *
   * @param object $element
   *   A SimpleXML element.
   * @param object $object
   *   Any PHP object.
   */
  protected function addSimplexmlAttributes($element, $object) {
    if (count($element->attributes())) {
      foreach ($element->attributes() as $attr => $value) {
        $object->$attr = (string) $value;
      }
    }
  }

  /**
   * Gets the queue for the API content type.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   API content type update queue.
   */
  public function getQueue(): QueueInterface {
    return $this->queueFactory->get('npr_api.queue.story');
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
