<?php

namespace Drupal\npr_api;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Retrieves and parses NPR Content
 *
 * Documentation: https://npr.github.io/content-distribution-service/
 */
class NprCdsClient implements ClientInterface {

  const NPR_API_CDS_PROD_HOST = 'https://content.api.npr.org/v1/';

  const NPR_API_CDS_STAGE_HOST = 'https://stage-content.api.npr.org/v1/';

  const NPR_API_CDS_DEV_HOST = 'https://dev-content.api.npr.org/v1/';

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

  /**
   * Base url for API.
   *
   * @var string
   */
  protected string $base_url;

  /**
   * @param \GuzzleHttp\ClientInterface $client
   */
  public function __construct(ClientInterface $client, ConfigFactoryInterface $config_factory) {
    $this->client = $client;
    $this->config = $config_factory->get('npr_api.settings');
    $token = $this->config->get('npr_api_cds_api_key');
    $this->default_options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
      ],
    ];
    $this->setUrl($this->config->get('npr_api_url'));
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('config.factory'),
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

  public function getStories() {

  }

  public function report() {
    $url = $this->base_url . 'documents';
    $options = $this->default_options + [
      'query' => [
        'sort' => 'publishDateTime:desc',
        'offset' => 0,
        'limit' => 10,
        'collectionIds' => '1126',
      ],
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
