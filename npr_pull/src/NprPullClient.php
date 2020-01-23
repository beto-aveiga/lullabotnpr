<?php

namespace Drupal\npr_pull;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\node\Entity\Node;
use GuzzleHttp\ClientInterface;
use Drupal\npr_api\NprClient;

/**
 * Performs CRUD opertions on Drupal nodes using data from the NPR API.
 */
class NprPullClient extends NprClient {

  /**
   * Constructs a NprClient object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $client, ConfigFactoryInterface $config_factory) {
    parent::__construct($client, $config_factory);
  }

  /**
   * Converts an NPRMLEntity story object into a node object and saves it to the
   * database (the D8 equivalent of npr_pull_save_story).
   *
   * @param string $story_id
   *   The ID of an NPR story.
   */
  function saveOrUpdateNode($story_id) {

    // Make a request.
    $this->getXmlStories(['id' => $story_id]);
    $this->parse();

    $story_config = $this->config->get('npr_story.settings');
    $story_content_type = $story_config->get('drupal_story_content');
    $story_id_field = $story_config->get('mappings.id');

    foreach($this->stories as $story) {
      // Impersonate the proper user.
      $user = \Drupal::currentUser();
      // $original_user = $user;
      // $user = \drupal::entitytypemanager()
      //   ->getstorage('user')
      //   ->load(\drupal::config('npr_pull.settings')
      //   ->get('npr_pull_author'));

      $is_update = FALSE;

      if (!empty($story->nid)) {
        // Load the story if it already exits.
        $node = Node::load($story->nid);
        $nodes_updated[] = $node;
      }
      else {
        // Create a new story if this is new.
        $node = Node::create([
          'type' => $story_content_type,
          'title' => $story->title,
          'language' => 'en',
          'uid' => 1,
          'status' => 1,
          $story_id_field => $story->id,
        ]);
      }
      $node->save();
      $nodes_created[] = $node;
    }
    foreach($nodes_created as $node_created) {
      $link = Link::fromTextAndUrl($node_created->label(), $node_created->toUrl())->toString();
      \Drupal::messenger()->addStatus(t("Story @link was created.", [
        '@link' => $link,
      ]));
    }
  }

  /**
   * Extracts an NPR ID from an NPR URL.
   *
   * @param string $url
   *   A URL.
   *
   * @return string $matches
   *   The ID of the NPR story.
   */
  function extractId($url) {
    // URL format: /yyyy/mm/dd/id
    // URL format: /blogs/name/yyyy/mm/dd/id
    preg_match('/https\:\/\/[^\s\/]*npr\.org\/((([^\/]*\/){3,5})([0-9]{8,12}))\/.*/', $url, $matches);
    if (!empty($matches[4])) {
      return $matches[4];
    }
    else {
      // URL format: /templates/story/story.php?storyId=id
      preg_match('/https\:\/\/[^\s\/]*npr\.org\/([^&\s\<]*storyId\=([0-9]+)).*/', $url, $matches);
      if (!empty($matches[2])) {
        return $matches[2];
      }
    }
  }


}
