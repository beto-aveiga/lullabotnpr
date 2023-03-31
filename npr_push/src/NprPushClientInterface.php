<?php

namespace Drupal\npr_pull;

use Drupal\node\NodeInterface;

/**
 * Interface for pushing data to the NPR api.
 */
interface NprPushClientInterface {

  /**
   * Converts Drupal story node into an NPRMLEntity story object.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A Drupal storynode.
   *
   * @return object|null
   *   An NPRMLEntity story object.
   */
  public function createNprmlEntity(NodeInterface $node);

}
