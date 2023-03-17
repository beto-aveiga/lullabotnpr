<?php

namespace Drupal\npr_pull;

use DateTime;

interface NprPullClientInterface {
  /**
   * Gets the date and time of the last API content type sync.
   *
   * @return \DateTime
   *   Date and time of last API content type sync.
   */
  public function getLastUpdateTime(): DateTime;

  /**
   * Adds items to the API story queue based on a time constraint.
   *
   * @return bool
   *   TRUE if the queue update fully completes, FALSE if it does not.
   */
  public function updateQueue(): bool;

  /**
   * Create a story node.
   *
   * Converts an NPRMLEntity story object to a node object and saves it to the
   * database (the D8 equivalent of npr_pull_save_story).
   *
   * @param object $story
   *   An NPRMLEntity story object.
   * @param bool $published
   *   Story should be published immediately.
   * @param bool $display_messages
   *   Messages should be displayed.
   * @param bool $manual_import
   *   Story should be marked as "Imported Manually".
   * @param bool $force
   *   Force an update the story.
   */
  public function addOrUpdateNode($story, $published, $display_messages = FALSE, $manual_import = FALSE, $force = FALSE);

  /**
   * Get taxonomy terms subscribed to.
   *
   * @return array
   *   An array of taxonomy terms.
   */
  public function getSubscriptionTerms();

  /**
   * Extracts an NPR ID from an NPR URL.
   *
   * @param string $url
   *   A URL.
   *
   * @return array
   *   The ID of the NPR story.
   */
  public function extractId($url);

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
   * Get story by organization.
   *
   * @param int $id
   *   The organization id.
   * @param array $options
   *   Additional query parameters.
   *
   * @return array
   *   The api response.
   */
  public function getStoriesByOrgId(int $id, array $options = []): array;

  /**
   * Get story by topic.
   *
   * @param int $id
   *   The topic id.
   * @param array $options
   *   Additional query parameters.
   *
   * @return array
   *   The api response.
   */
  public function getStoriesByTopicId(int $id, array $options = []): array;
}
