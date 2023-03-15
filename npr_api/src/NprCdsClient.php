<?php

namespace Drupal\npr_api;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\npr_api\Normalizer\NPRCdsEntityNormalizer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Retrieves and parses NPR Content
 *
 * Documentation: https://npr.github.io/content-distribution-service/
 */
class NprCdsClient implements NprClientInterface {

  const NPR_API_CDS_PROD_HOST = 'https://content.api.npr.org';

  const NPR_API_CDS_STAGE_HOST = 'https://stage-content.api.npr.org';

  const NPR_API_CDS_DEV_HOST = 'https://dev-content.api.npr.org';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Default options for api requests.
   *
   * @var array
   */
  protected array $default_options;

  protected $config;

  protected $serializer;

  /**
   * Base url for API.
   *
   * @var string
   */
  protected string $base_url;

  /**
   * @param \GuzzleHttp\ClientInterface $client
   */
  public function __construct(ClientInterface $client, ConfigFactoryInterface $config_factory, SerializerInterface $serializer) {
    $this->client = $client;
    $this->config = $config_factory->get('npr_api.settings');
    $token = $this->config->get('npr_api_cds_api_key');
    $this->default_options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
      ],
    ];
    $this->setUrl($this->config->get('npr_api_url'));
    $this->serializer = $serializer;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('json.serializer')
    );
  }

  public function setUrl(string $url) {
    switch ($url) {
      case 'staging':
        $this->base_url = self::NPR_API_CDS_STAGE_HOST;
        break;
      case 'development':
        $this->base_url = self::NPR_API_CDS_DEV_HOST;
        break;
      default:
        $this->base_url = self::NPR_API_CDS_PROD_HOST;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function request($method, $uri, array $options = []) {
    $options = $this->default_options + $options;
    if (!str_starts_with($uri, 'http')) {
      $uri = $this->base_url . ($uri[0] == '/' ? $uri : '/' . $uri);
    }
    try {
      return $this->client->request($method, $uri, $options);
    } catch (ClientException $e) {
      return $e->getResponse();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function requestAsync($method, $uri, array $options = []) {
    return $this->client->requestAsync($method, $uri, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function send(RequestInterface $request, array $options = []) {
    return $this->client->send($request, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function sendAsync(RequestInterface $request, array $options = []) {
    return $this->client->sendAsync($request, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function getConfig($option = null) {
    return $this->client->getConfig($option);
  }

  /**
   * {@inheritDoc}
   */
  public function getStories(array $params) {
    $url = 'v1/documents';
    if (isset($params['id'])) {
      $url .= '/' . $params['id'];
      unset($params['id']);
    }
    $params['transclude'] = 'images,collections,corrections,bylines,audio';
    $options = [
        'query' => $params,
    ];
    $response = $this->request('GET', $url, $options);
    if ($response->getStatusCode() != 200) {
      return [];
    }
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $normalizer = new NPRCdsEntityNormalizer();
    $entities = [];
    foreach ($data['resources'] as $resource) {
      $entities[] = $normalizer->denormalize($resource, NPRMLEntity::class);
    }
    return $entities;
  }

  /**
   * {@inheritDoc}
   */
  public function report() {
    $url = 'v1/documents';
    $params = [
      'sort' => 'publishDateTime:desc',
      'offset' => 0,
      'limit' => 10,
      'transclude' => 'bylines,layout,transcript,items',
      'collectionIds' => '1126',
    ];
    $options = [
      'query' => $params,
    ];
    $entities = $this->getStories($params);
    $params = 'Request params were:';
    foreach ($options['query'] as $k => $v) {
      $params .= ' [' . $k . '=>' . $v . ']';
    }
    $report[] = $params;
    $response = $this->request('GET', $url, $options);
    $report[] = 'Response code was ' . $response->getStatusCode();
    if ($response->getStatusCode() == 200) {
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $resources = $data['resources'];
      $report[] = 'Request returned ' . count($resources) . ' stories:';
      foreach ($resources as $resource) {
        $report[] = $resource['title'] . '(ID: ' . $resource['id'] . ')';
      }
    }
    return $report;
  }
}
