<?php

namespace Drupal\npr_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\npr_api\Normalizer\NPRCdsEntityNormalizer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Query;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Retrieves and parses NPR Content.
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
  protected array $defaultOptions;

  /**
   * NRP API Settings config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Base url for API.
   *
   * @var string
   */
  protected string $baseUrl;

  /**
   * Constructor.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   Http client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   */
  public function __construct(ClientInterface $client, ConfigFactoryInterface $config_factory) {
    $this->client = $client;
    $this->config = $config_factory->get('npr_api.settings');
    $token = $this->config->get('npr_api_cds_api_key');
    $this->defaultOptions = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
      ],
    ];
    $url_selection = $this->config->get('npr_api_url') ?? '';
    $this->setUrl($url_selection);
  }

  /**
   * Sets the default base url for the api calls.
   *
   * @param string $url
   *   The url.
   */
  public function setUrl(string $url) {
    switch ($url) {
      case 'staging':
        $this->baseUrl = self::NPR_API_CDS_STAGE_HOST;
        break;

      case 'development':
        $this->baseUrl = self::NPR_API_CDS_DEV_HOST;
        break;

      default:
        $this->baseUrl = self::NPR_API_CDS_PROD_HOST;
    }
  }

  /**
   * Get the url used by the API.
   *
   * @return string
   *   The base url in use.
   */
  public function getUrl(): string {
    return $this->baseUrl;
  }

  /**
   * {@inheritDoc}
   */
  public function request($method, $uri, array $options = []): ResponseInterface {
    $options = $this->defaultOptions + $options;
    if (!str_starts_with($uri, 'http')) {
      $uri = $this->baseUrl . ($uri[0] == '/' ? $uri : '/' . $uri);
    }
    try {
      return $this->client->request($method, $uri, $options);
    }
    catch (ClientException $e) {
      return $e->getResponse();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function requestAsync($method, $uri, array $options = []): PromiseInterface {
    return $this->client->requestAsync($method, $uri, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function send(RequestInterface $request, array $options = []): ResponseInterface {
    return $this->client->send($request, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
    return $this->client->sendAsync($request, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function getConfig($option = NULL) {
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
    $params['transclude'] = 'images,collections,corrections,bylines,audio,layout,corrections,videos';
    $options = [
      'query' => Query::build($params),
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
