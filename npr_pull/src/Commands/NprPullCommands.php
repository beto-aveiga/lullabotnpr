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
   * @param array $options
   *   Associative array of options.
   *
   * @usage drush npr-gs 12345678 --published=FALSE
   *   Import the story without publishing it.
   * @usage drush npr-gs 12345678 --display_messages=FALSE
   *   Import the story, but just log the results and don't display the output.
   *
   * @command npr_pull:getStory
   * @aliases npr-gs
   */
  public function getStoryById($story_id, array $options = ['published' => TRUE, 'display_messages' => TRUE]) {

    $params['id'] = $story_id;
    $stories = $this->client->getStories($params);

    // Create the stories in Drupal.
    foreach ($stories as $story) {
      $this->client->addOrUpdateNode($story, $options['published'], $options['display_messages']);
    }
  }

  /**
   * Command to add NPR stories to the queue by topic ID.
   *
   * @param array $options
   *   Associative array of options.
   *
   * @usage drush npr-gst --num_results=50
   *   Import 50 stories (the maximum for one command) rather than 1.
   * @usage drush npr-gst --topic_id=1013
   *   Import 1 education (1013) story.
   * @usage drush npr-gst --start_num=50 --num_results=20
   *   Import 20 stories, starting with number 50.
   * @usage drush npr-gst --sort=dateAsc
   *   Import the oldest story available.
   * @usage drush npr-gst --start_date=2020-04-06 --end_date=2020-04-07
   *   Import all stories (up to 50) published on April 6 or April 7.
   *
   * @command npr_pull:getStoriesByTopicId
   * @aliases npr-gst
   */
  public function getStoriesByTopicId(array $options = [
    'num_results' => 1,
    'topic_id' => 1001,
    'start_num' => 0,
    'sort' => 'dateDesc',
    'start_date' => '',
    'end_date' => '',
  ]) {

    if ($options['num_results'] > 50) {
      throw new \Exception(dt('Because this command accepts a date range, and due to the way the NPR API works, this command cannot process more than 50 stories at one time.'));
    }

    $params = [
      'numResults' => $options['num_results'],
      'id' => $options['topic_id'],
      'sort' => $options['sort'],
      'fields' => 'all',
    ];

    if ($options['start_num'] > 0) {
      $params['startNum'] = $options['start_num'];
    }

    // Add start and end dates, if included.
    $start_date = $options['start_date'];
    if (!empty($start_date)) {
      if ($this->validateDate($start_date)) {
        $params['startDate'] = $options['start_date'];
      }
      else {
        throw new \Exception(dt('The start date needs to be in the format YYYY-MM-DD.'));
      }
    }
    $end_date = $options['end_date'];
    if (!empty($end_date)) {
      if ($this->validateDate($end_date)) {
        $params['endDate'] = $options['end_date'];
      }
      else {
        throw new \Exception(dt('The end date needs to be in the format YYYY-MM-DD.'));
      }
    }

    if ($stories = $this->client->getStories($params)) {
      $this->processStories($stories);
    };
    $this->output()->writeln(dt('Process the stories with `drush queue-run npr_api.queue.story` or run cron.'));
  }

  /**
   * Command to add NPR stories to the queue by organization ID.
   *
   * @param int $org_id
   *   The organization ID.
   * @param array $options
   *   Associative array of options.
   *
   * @usage drush npr-gso 1 --num_results=5
   *   Import the 5 most recent stories from National Public Radio.
   * @usage drush npr-gso 449 --num_results=1000 --start_num=500
   *   Add 1000 stories from Georgia Public Broadcasting to the queue, starting
   *   with the 500th result.
   * @usage drush npr-gso 1 --num_results=10 --start_date=2020-06-10 --end_date=2020-06-11
   *   Get 10 stories from National Public Radio between June 10 and June 12,
   *   2020.
   *
   * @command npr_pull:getStoriesByOrgId
   * @aliases npr-gso
   */
  public function getStoriesByOrgId($org_id, array $options = [
    'num_results' => 1,
    'start_num' => 0,
    'start_date' => '',
    'end_date' => '',
  ]) {

    if (is_null($org_id)) {
      $this->logger()->info(dt('An organization ID is required.'));
      throw new \Exception(dt('An organization ID is required.'));
    }
    $params = [
      'orgId' => $org_id,
      'fields' => 'all',
      'dateType' => 'story',
    ];

    // Add start and end dates, if included.
    $start_date = $options['start_date'];
    if (!empty($start_date)) {
      if ($this->validateDate($start_date)) {
        $params['startDate'] = $options['start_date'];
      }
      else {
        throw new \Exception(dt('The start date needs to be in the format YYYY-MM-DD.'));
      }
    }
    $end_date = $options['end_date'];
    if (!empty($end_date)) {
      if ($this->validateDate($end_date)) {
        $params['endDate'] = $options['end_date'];
      }
      else {
        throw new \Exception(dt('The end date needs to be in the format YYYY-MM-DD.'));
      }
    }

    // The maximum number of stories per NRP API request is 50.
    if ($options['num_results'] <= 50) {
      $params['numResults'] = $options['num_results'];
      $stories = $this->client->getStories($params);
      $this->processStories($stories);
    }
    elseif ($options['num_results'] > 50) {
      for ($i = $options['start_num']; $i < ($options['num_results'] + $options['start_num']); $i += 50) {
        $params['numResults'] = 50;
        $params['startNum'] = $i;
        // Clear the stories.
        $this->client->stories = [];
        $stories = $this->client->getStories($params);
        $this->processStories($stories);
      }
    }
    $this->output()->writeln(dt('Process the stories with `drush queue-run npr_api.queue.story` or run cron.'));
  }

  /**
   * Adds stories to the queue.
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
      $this->output()->writeln(dt('@total stories have been added to the queue (@count_after total).', [
        '@total' => $total,
        '@count_after' => $count_after,
      ]));
    }
    else {
      $this->output()->writeln(dt('No stories were added to the queue.'));
    }
  }

  /**
   * Confirm that a date is in the yyyy-mm-dd format.
   *
   * @return bool
   *   Whether the date is in the correct format or not.
   */
  public function validateDate($date, $format = 'Y-m-d') {
    $dt = \DateTime::createFromFormat($format, $date);
    return $dt && $dt->format($format) === $date;
  }

}
