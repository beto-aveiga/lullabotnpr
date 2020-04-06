<?php

namespace Drupal\npr_pull\Commands;

use Drupal\npr_pull\NprPullClient;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 */
class NprPullCommands extends DrushCommands {

  /**
   * The NPR Pull service.
   *
   * @var \Drupal\npr_pull\NprPullClient
   */
  protected $client;

  /**
   * Constructs a new DrushCommands object.
   *
   * @param \Drupal\npr_pull\NprPullClient $client
   *   The NPR client.
   */
  public function __construct(NprPullClient $client) {
    $this->client = $client;
  }

  /**
   * Command to pull a single NPR story.
   *
   * @param int $story_id
   *   The NPR API id of the story.
   * @param bool $published
   *   Should the story be published or not.
   * @param bool $display_messages
   *   Messages should be displayed or not.
   *
   * @command npr_pull:getStory
   * @aliases npr-gs
   */
  public function getStoryById($story_id, $published = TRUE, $display_messages = TRUE) {

    $params['id'] = $story_id;
    $stories = $this->client->getStories($params);

    // Create the stories in Drupal.
    foreach ($stories as $story) {
      $this->client->addOrUpdateNode($story, $published, $display_messages);
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
   *
   * @command npr_pull:getStoriesByTopicId
   * @aliases npr-gst
   */
  public function getStoriesByTopicId($num_results = 1, $topic_id = 1001, $start_num = 0, $sort = 'dateDesc', $start_date = '', $end_date = '') {

    if ($num_results > 50) {
      throw new \Exception(dt('Because this command accepts a date range, and due to the way the NPR API works, this command cannot process more than 50 stories at one time.'));
    }

    $params = [
      'numResults' => $num_results,
      'id' => $topic_id,
      'sort' => $sort,
      'fields' => 'all',
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

    if ($stories = $this->client->getStories($params)) {
      $this->processStories($stories);
    };

  }

  /**
   * Command to pull NPR stories by organization ID.
   *
   * @param int $org_id
   *   The number of results to get from the API.
   * @param int $num_results
   *   The number of results to get from the API.
   * @param int $start_num
   *   The page to start from.
   *
   * @command npr_pull:getStoriesByOrgId
   * @aliases npr-gso
   */
  public function getStoriesByOrgId($org_id = NULL, $num_results = 1, $start_num = 0) {

    if (is_null($org_id)) {
      $this->logger()->info(dt('An organization ID is required.'));
      throw new \Exception(dt('An organization ID is required.'));
    }
    $params = [
      'orgId' => $org_id,
      'fields' => 'all',
    ];

    $stories = [];
    // The maximum number of stories per NRP API request is 50.
    if ($num_results <= 50) {
      $params['numResults'] = $num_results;
      $stories = $this->client->getStories($params);
    }
    elseif ($num_results > 50) {
      for ($i = $start_num; $i < ($num_results + $start_num); $i += 50) {
        $params['startNum'] = $start_num;
        $stories = array_merge($stories, $this->client->getStories($params));
      }
    }

    if (!empty($stories)) {
      $this->processStories($stories);
    };
  }

  /**
   * Addes stories to the queue.
   *
   * @param object $stories
   *   A parsed object of NPRML stories.
   */
  protected function processStories($stories) {
    // Add the stories to the queue in Drupal.
    $queue = $this->client->getQueue();
    $count_before = $queue->numberOfItems();
    foreach ($stories as $story) {
      $queue->createItem($story);
    }
    $count_after = $queue->numberOfItems();
    $total = $count_after - $count_before;

    if ($count_after > $count_before) {
      $this->output()->writeln(dt('@total stories have been added to the queue (@count_after total). Process the stories with `drush queue-run npr_api.queue.story` or run cron', [
        '@total' => $total,
        '@count_after' => $count_after,
      ]));
    }
    else {
      $this->output()->writeln(dt('No stories were added to the queue.'));
    }
  }

}
