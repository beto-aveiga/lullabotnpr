<?php

namespace Drupal\npr_pull;

use Drupal\node\NodeInterface;

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
