<?php

namespace Drupal\npr_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\npr_api\NprCdsClient;
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
   * The NPR CDS Client.
   *
   * @var \Drupal\npr_api\NprCdsClient
   */
  protected $cdsClient;

  /**
   * Constructs the ApiTestController.
   *
   * @param \Drupal\npr_api\NprClient $npr_client
   *   The NPR API service.
   * @param \Drupal\npr_api\NprCdsClient $npr_cds_client
   *   The NPR API CDS service.
   */
  public function __construct(NprClient $npr_client, NprCdsClient $npr_cds_client) {
    $this->client = $npr_client;
    $this->cdsClient = $npr_cds_client;
    $this->cdsClient->setUrl('staging');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('npr_api.client'),
      $container->get('npr_api.cds_client')
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
    $this->client->getStories($params);
    $result = $this->client->report();
    $ra[] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Test Result'),
      '#items' => $result,
    ];
    $result = $this->cdsClient->report();
    $ra[] = [
      '#theme' => 'item_list',
      '#title' => $this->t('CDS Test Result'),
      '#items' => $result,
    ];

    return $ra;
  }

}
