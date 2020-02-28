<?php

namespace Drupal\npr_pull\Commands;

use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 */
class NprPullCommands extends DrushCommands {

  /**
   * Command to pull a single NPR story.
   *
   * @param int $id
   *   The NPR API id of the story.
   * @param bool $published
   *   Should the story be published or not.
   * @param bool $display_messages
   *   Messages should be displayed or not.
   *
   * @command npr_pull:getStory
   * @aliases npr-gs
   */
  public function getStoryById($id, $published = TRUE, $display_messages = TRUE) {

    // Get the story from the API.
    /** @var \Drupal\npr_pull\NprPullClient $client */
    $client = \Drupal::service('npr_pull.client');
    $params['id'] = $id;
    $stories = $client->getStories($params);

    // Create the stories in Drupal.
    foreach ($stories as $story) {
      $client->addOrUpdateNode($story, $published, $display_messages);
    }
  }

  /**
   * Command to pull NPR stories by topic ID.
   *
   * @param int $num_results
   *   The number of results to get from the API.
   * @param int $topic_id
   *   The NPR topic ID corrected with topics such as News, Education, or Music.
   * @param int $start_num
   *   The page to start from.
   * @param string $sort
   *   The storting order, either dateDesc or dateAsc.
   * @param string $start_date
   *   The starting date for the request, such as 2020-02-22.
   * @param string $end_date
   *   The ending date for the request, such as 2020-02-24.
   * @param bool $published
   *   Should the story be published or not.
   * @param bool $display_messages
   *   Messages should be displayed or not.
   *
   * @command npr_pull:getStoriesByTopicId
   * @aliases npr-gst
   */
  public function getStoriesByTopicId($num_results = 1, $topic_id = 1001, $start_num = 0, $sort = 'dateDesc', $start_date = '', $end_date = '', $published = TRUE, $display_messages = TRUE) {

    $params = [
      'numResults' => $num_results,
      'id' => $topic_id,
      'sort' => $sort,
    ];

    if ($start_num > 0) {
      $params['startNum'] = $start_num;
    }

    if (!empty($start_date)) {
      $params['startDate'] = $start_date;
    }
    if (!empty($end_date)) {
      $params['endDate'] = $end_date;
      $start_date = date("Y-m-d");
    }

    // Get the stories from the API.
    /** @var \Drupal\npr_pull\NprPullClient $client */
    $client = \Drupal::service('npr_pull.client');
    $stories = $client->getStories($params);

    // Create the stories in Drupal.
    foreach ($stories as $story) {
      $client->addOrUpdateNode($story, $published, $display_messages);
    }
  }

}
