<?php

namespace Drupal\npr_pull\Commands;

use DateTime;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 */
class NprPullCommands extends DrushCommands {

  /**
   * Command to pull a single NPR story.
   *
   * @param integer $id
   *   The NPR API id of the story.
   * @param  boolean $published
   *   Should the story be published or not.
   * @param boolean $display_messages
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

}
