<?php

namespace Drupal\npr_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\npr_api\NprClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NPR API test controller for the npr_api module.
 */
class ApiTestController extends ControllerBase {

  /**
   * The NPR API service.
   *
   * @var \Drupal\npr_api\NprClient
   */
  protected $client;

  /**
   * Constructs the ApiTestController.
   *
   * @param \Drupal\npr_api\NprClient $npr_client
   *   The NPR API service.
   */
  public function __construct(NprClient $npr_client) {
    $this->client = $npr_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('npr_api.client')
    );
  }

  /**
   * Sends a test query to the API and returns result.
   *
   * @throws \Exception
   */
  public function testConnection() {

    // Make a request.
    $params = ['id' => 1126];
    $this->client->getXmlStories($params);
    $this->client->parse();
    $result = $this->client->report();

    return [
      '#theme' => 'item_list',
      '#title' => 'Test Result',
      '#items' => $result,
    ];
  }

}
