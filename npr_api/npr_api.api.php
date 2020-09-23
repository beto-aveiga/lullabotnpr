<?php

/**
 * @file
 * Describes methods to change NPR stories before saving.
 */

/**
 * Perform alterations on a story object.
 *
 * @param object $story
 *   The story object.
 */
function hook_npr_story_object_alter(&$story) {
  if (!empty($story->htmlAsset->value) && !empty($story->htmlAsset->id)) {
    // Add a placeholder for HTML assets in place of the HTML. (Each
    // placeholder will be replaced in a presave hook with an entity embed.)
    $story->htmlAsset->value = "npr_html_asset_" . $story->htmlAsset->id;
  }
}
