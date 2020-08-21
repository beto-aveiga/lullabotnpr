<?php

/**
 * @file
 * Describes methods to change story nodes before saving.
 */

use Drupal\Core\Entity\EntityInterface;

/**
 * Perform alterations on a media URL.
 *
 * @param string $image_url
 *   The URL of the image.
 */
function hook_npr_image_url_alter(&$image_url) {
  if (strpos($image_url, 'example.com') !== FALSE) {
    $image_url = str_replace('examplenews.com', 'example.com', $image_url);
  }
}

/**
 * Example of how to alter nodes before they are imported/updated.
 *
 * One way is to implement hook_entity_presave().
 */
function my_module_entity_presave(EntityInterface $entity) {

  /** @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory */
  $config_factory = \Drupal::configFactory();
  $config = $config_factory->get('npr_story.settings');
  $story_type = $config->get('story_node_type');

  // In this example, the "News Type" field is set to "Blog" if the "Blog"
  // field has been populated from a blog parent item.
  switch ($entity->bundle()) {
    case $story_type:
      if (!empty($entity->get('field_blog')->getValue())) {
        $blog = TRUE;
      }
      if (!empty($blog)) {
        $blog_taxonomy = 680;
        $entity->set('field_news_type', ['target_id' => $blog_taxonomy]);
      }
      else {
        $news_taxonomy = 681;
        $entity->set('field_news_type', ['target_id' => $news_taxonomy]);
      }
      break;
  }

}
