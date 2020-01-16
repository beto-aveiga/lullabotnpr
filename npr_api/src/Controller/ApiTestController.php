<?php

/**
 * @file
 * Contains \Drupal\npr_api\Controller\ApiTestController.
 */

namespace Drupal\npr_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\npr_api\NprClient;

/**
 * NPR API test controller for the npr_api module.
 */
class ApiTestController extends ControllerBase {

  /**
   * Sends a test query to the API and returns result.
   *
   * @throws \Exception
   */
  public function testConnection() {

    // Create a new NprClient with the default configuration.
    $defaults = NprClient::getDefaultConfiguration();
    $client = new NprClient(new \GuzzleHttp\Client($defaults));

    // Make a request.
    $options = ['id' => 1126];
    $client->getXmlStories($options);
    $client->parse();
    $result = $client->report();

    return [
      '#theme' => 'item_list',
      '#title' => 'Test Result',
      '#items' => $result,
    ];
  }

}

