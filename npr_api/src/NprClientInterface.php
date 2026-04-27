<?php

namespace Drupal\npr_api;

use GuzzleHttp\ClientInterface;

/**
 * Interface for NPR api clients.
 */
interface NprClientInterface extends ClientInterface {

  /**
   * Gets stories narrowed by query-type parameters.
   *
   * @param array $params
   *   An array of query-type parameters.
   *
   * @return object|null
   *   A parsed object of NPRML stories.
   */
  public function getStories(array $params);

  /**
   * Generates basic report of NPRML object.
   *
   * @return array
   *   Various messages (strings) .
   */
  public function report();

}
