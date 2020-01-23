<?php

/**
 * @file
 * Contains \Drupal\npr_api\Controller\ApiTestController.
 */

namespace Drupal\npr_api\Controller;

use Drupal\Core\Controller\ControllerBase;

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

    // Make a request.
    $params = ['id' => 1126];
    $client = npr_api_fetch_object($params);

    $result = $client->report();

    return [
      '#theme' => 'item_list',
      '#title' => 'Test Result',
      '#items' => $result,
    ];
  }

}

