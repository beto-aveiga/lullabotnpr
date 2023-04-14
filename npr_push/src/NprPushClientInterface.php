<?php

namespace Drupal\npr_push;

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

  /**
   * Create or update story on NPR.
   *
   * @param array $node
   *   The node containing the story to be sent to NPR.
   */
  public function createOrUpdateStory(array $node);

  /**
   * Delete story from NPR.
   *
   * @param NodeInterface $node
   *   The node of the story to be removed from NPR.
   */
  public function deleteStory(NodeInterface $node);

}
