<?php

namespace Drupal\npr_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves and parses NPRML.
 */
class NprClient implements ClientInterface {

  // HTTP status code = OK
  const NPRAPI_STATUS_OK = 200;
  const BASE_URI = 'http://api.npr.org/query/';


  // NPRML CONSTANTS
  const NPRML_DATA = '<?xml version="1.0" encoding="UTF-8"?><nprml></nprml>';
  const NPRML_NAMESPACE = 'xmlns:nprml=https://api.npr.org/nprml';
  const NPRML_VERSION = '0.92.2';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

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
   * Constructs a NprClient object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current logged in user.
   */
  public function __construct(ClientInterface $client, ConfigFactoryInterface $config_factory, AccountInterface $current_user) {
    $this->client = $client;
    $this->config = $config_factory;
    $this->currentUser = $current_user;

    // TODO: Is this needed?
    $this->response = new \stdClass;
    $this->response->code = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('current_user')
    );
  }

  /**
    * {@inheritdoc}
    */
  public function request($method = 'GET', $url, array $options = []) {
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
  public function getConfig($option = null) {
    return $this->client->getConfig($option);
  }

  /**
   * Get the default Guzzle client configuration array.
   *
   * @return array An array of configuration options suitable for use with Guzzle.
   */
  public static function getDefaultConfiguration() {
    $config = [
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ],
    ];
    if (empty($handler)) {
      $handler = HandlerStack::create();
    }
    $config['handler'] = $handler;
    return $config;
  }

  /**
   * Make a GET request without needing the BASE_URI.
   *
   * @return array An array of configuration options suitable for use with Guzzle.
   */
  public function getXmlStories($options) {

    $this->options = $options;
    // Add the API key. It feels icky using the Drupal class, but it also
    // seems to simplify things.
    $key = \Drupal::config('npr_api.settings')->get('npr_api_api_key');
    $options['apiKey'] = $key;

    // TODO: Store these for the report function.
    $this->response = $this->request('GET', self::BASE_URI, ['query' => $options]);
    $this->params = $options;

    $this->xml = $this->response->getBody()->getContents();
  }

  /**
   * Parses object. Turns raw XML(NPRML) into various object properties.
   */
  function parse() {
    if (!empty($this->xml)) {
      $xml = $this->xml;
    }

    else {
      $this->notices[] = 'No XML to parse.';
      return;
    }

    $object = simplexml_load_string($xml);
    $this->addSimplexmlAttributes($object, $this);

    if (!empty($object->message)) {
      $this->message->id = $this->getAttribute($object->message, 'id');
      $this->message->level = $this->getAttribute($object->message, 'level');
    }

    if (!empty($object->list->story)) {
      foreach ($object->list->story as $story) {
        $parsed = new NPRMLEntity();
        $this->addSimplexmlAttributes($story, $parsed);

        //Iterate trough the XML document and list all the children
        $xml_iterator = new \SimpleXMLIterator($story->asXML());
        $key = NULL;
        $current = NULL;
        for($xml_iterator->rewind(); $xml_iterator->valid(); $xml_iterator->next()) {
          $current = $xml_iterator->current();
          $key = $xml_iterator->key();

          if ($key == 'image' || $key == 'audio' || $key == 'link') {
            if ($key == 'image') {
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
            if ($key == 'audio') {
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
            if ($key == 'link') {
              $type = $this->getAttribute($current, 'type');
              $parsed->{$key}[$type] = $this->parseSimplexmlElement($current);
            }
          }
          else {
            if (empty($parsed->{$key})){
              // The $key wasn't parsed already, so just add the current element.
              $parsed->{$key} = $this->parseSimplexmlElement($current);
            }
            else {
              // If $parsed->$key exists and it's not an array, create an array
              // out of the existing element
              if (!is_array($parsed->{$key})){
                $parsed->{$key} = array($parsed->{$key});
              }
              // Add the new child
              $parsed->{$key}[] = $this->parseSimplexmlElement($current);
            }
          }
        }
        $body ='';
        if (!empty($parsed->textWithHtml->paragraphs)) {
          foreach ($parsed->textWithHtml->paragraphs as $paragraph) {
            $body = $body . $paragraph->value . "\n\n";
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
  function parseSimplexmlElement($element) {
    $NPRMLElement = new NPRMLElement();
    $this->addSimplexmlAttributes($element, $NPRMLElement);
    if (count($element->children())) {
      foreach ($element->children() as $i => $child) {
        if ($i == 'paragraph' || $i == 'mp3') {
          if ($i == 'paragraph') {
            $paragraph = $this->parseSimplexmlElement($child);
            $NPRMLElement->paragraphs[$paragraph->num] = $paragraph;
          }
          if ($i == 'mp3') {
            $mp3 = $this->parseSimplexmlElement($child);
            $NPRMLElement->mp3[$mp3->type] = $mp3;
          }
        }
        else {
          // If $i wasn't parsed already, so just add the current element.
          if (empty($NPRMLElement->$i)){
            $NPRMLElement->$i = $this->parseSimplexmlElement($child);
          }
          else {
            // If $NPRMLElement->$i exists and is not an array, create an array
            // out of the existing element
            if (!is_array($NPRMLElement->$i)) {
              $NPRMLElement->$i = array($NPRMLElement->$i);
            }
            // Add the new child.
            $NPRMLElement->{$i}[] = $this->parseSimplexmlElement($child);
          }
        }
      }
    }
    else {
      $NPRMLElement->value = (string)$element;
    }
    return $NPRMLElement;
  }

  /**
   * Extracts value of a given attribute from a SimpleXML element.
   *
   * @param object $element
   *   A SimpleXML element.
   *
   * @param string $attribute
   *   The name of an attribute of the element.
   *
   * @return string
   *   The value of the attribute (if it exists in element).
   */
  function getAttribute($element, $attribute) {
    foreach ($element->attributes() as $k => $v) {
      if ($k == $attribute) {
        return (string)$v;
      }
    }
  }

  /**
   * Generates basic report of NPRML object.
   *
   * @return array
   *   Various messages (strings) .
   */
  function report() {
    $msg = array();

    $xml = simplexml_load_string($this->xml);

    $params = '';
    if (isset($this->params)) {
      foreach ($this->params as $k => $v) {
        $params .= " [$k => $v]";
      }
      $msg[] =  'Request params were: ' . $params;
    }
    else {
      $msg[] = 'Request had no parameters.';
    }

    if ($this->response->getStatusCode()) {
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
   * Takes attributes of a SimpleXML element and adds them to an object (as
   * properties).
   *
   * @param object $element
   *   A SimpleXML element.
   *
   * @param object $object
   *   Any PHP object.
   */
  function addSimplexmlAttributes($element, $object) {
    if (count($element->attributes())) {
      foreach ($element->attributes() as $attr => $value) {
        $object->$attr = (string)$value;
      }
    }
  }

  /**
   * Helper function to "flatten" the NPR story.
   */
  function flatten() {
    foreach($this->stories as $i => $story) {
      foreach($story->parent as $parent) {
        if ($parent->type == 'tag') {
          $this->stories[$i]->tags[] = $parent->title->value;
        }
      }
    }
  }
}
