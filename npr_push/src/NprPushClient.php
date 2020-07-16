<?php

namespace Drupal\npr_push;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Link;
use Drupal\node\NodeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\media\Entity\Media;
use Drupal\npr_api\NprClient;
use Drupal\taxonomy\Entity\Term;

/**
 * Push data from Drupal nodes to the NPR API.
 */
class NprPushClient extends NprClient {

  use StringTranslationTrait;

  /**
   * The story node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * Converts Drupal story node into an NPRMLEntity story object.
   *
   * @param Drupal\node\NodeInterface $node
   *   A Drupal storynode.
   *
   * @return object|null
   *   An NPRMLEntity story object.
   */
  public function createNprmlEntity(NodeInterface $node) {

    $language = $node->language;
    $xml = new \DOMDocument();
    $xml->version = '1.0';
    $xml->encoding = 'UTF-8';

    $nprml_element = $xml->createElement('nprml');
    $nprml_version = $xml->createAttribute('version');
    $nprml_version->value = '0.94';
    $nprml_element->appendChild($nprml_version);

    $nprml = $xml->appendChild($nprml_element);
    $list = $nprml->appendChild($xml->createElement('list'));

    $story = $xml->createElement('story');

    // Get the story field mappings.
    $story_config = $this->config->get('npr_story.settings');
    $story_mappings = $story_config->get('story_field_mappings');

    // NPR ID field.
    $id_field = $story_mappings['id'];
    if ($id_field == 'unused') {
      $this->nprError('Please configure the story id field.');
      return;
    }
    if ($id_value = $node->{$id_field}->value) {
      $id_attribute = $xml->createAttribute('id');
      $id_attribute->value = $id_value;
      $story->appendChild($id_attribute);
    }

    // Story title.
    if ($title = substr($node->getTitle(), 0, 100)) {
      $title_cdata = $xml->createCDATASection($title);
      $title_element = $xml->createElement('title');
      $title_element->appendChild($title_cdata);
      $story->appendChild($title_element);
    }

    // Story body.
    $body_field = $story_mappings['body'];
    if ($body_field == 'unused') {
      $this->nprError('Please configure the body field.');
      return;
    }
    if ($body = $node->{$body_field}->value) {
      $body = check_markup($body, $node->{$body_field}->format);
      /** @var \Drupal\filter\FilterPluginManager $filter_plugin_manager */
      $filter_plugin_manager = \Drupal::service('plugin.manager.filter');
      /** @var \Drupal\npr_push\Plugin\Filter\RelToAbs $rel_to_abs */
      $rel_to_abs = $filter_plugin_manager->createInstance('npr_rel_to_abs');
      $body = $rel_to_abs->process($body, 'en')->getProcessedText();
      $body_cdata = $xml->createCDATASection($body);
      $text = $xml->createElement('textWithHtml');
      $text->appendChild($body_cdata);
      $story->appendChild($text);

      $teaser_cdata = $xml->createCDATASection(text_summary($body));
      $teaser = $xml->createElement('teaser');
      $teaser->appendChild($teaser_cdata);
      $story->appendChild($teaser);
    }

    // Story date and publication date.
    $now = format_date(REQUEST_TIME, 'custom', "D, d M Y G:i:s O ");
    $story_date = ($node->getChangedTime() == $node->getCreatedTime()) ? $now :
    format_date($node->getCreatedTime(), 'custom', "D, d M Y G:i:s O ");
    $story->appendChild($xml->createElement('storyDate', $story_date));
    $story->appendChild($xml->createElement('pubDate', $now));

    // Story URL.
    $url = $node->toUrl()->setAbsolute()->toString();
    $url_type = $xml->createAttribute('type');
    $url_type->value = 'html';
    $url_element = $xml->createElement('link', $url);
    $url_element->appendChild($url_type);
    $story->appendChild($url_element);

    // The station's org ID.
    $org_element = $xml->createElement('organization');
    $org_id = $xml->createAttribute('orgId');
    $org_id->value = $this->config->get('npr_push.settings')->get('org_id');
    $org_element->appendChild($org_id);
    $story->appendChild($org_element);

    // Partner ID (the Drupal node ID)
    $partner_id = $xml->createElement('partnerId', $node->id());
    $story->appendChild($partner_id);

    // Primary topic.
    $primary_topic_field = $story_mappings['primaryTopic'];
    $primary_topic = $node->get($primary_topic_field)->referencedEntities();
    if (!empty($primary_topic_field) && $primary_topic_field != 'unused' && is_array($primary_topic)) {
      $primary_topic = reset($primary_topic);
      $primary_topic_id_value = $primary_topic->field_npr_news_id->value;
      $primary_topic_title_value = $primary_topic->getName();
      if (!empty($primary_topic_id_value) && !empty($primary_topic_title_value)) {
        // Create the outermost element, the parent.
        $primary_topic_element = $xml->createElement('parent');
        // Add the id to the parent.
        $primary_topic_id = $xml->createAttribute('id');
        $primary_topic_id->value = $primary_topic_id_value;
        $primary_topic_element->appendChild($primary_topic_id);
        // Add the type to the parent.
        $primary_topic_type = $xml->createAttribute('type');
        $primary_topic_type->value = 'primaryTopic';
        $primary_topic_element->appendChild($primary_topic_type);
        // Add the title element to the parent.
        $primary_topic_title = $xml->createElement('title', $primary_topic_title_value);
        $primary_topic_element->appendChild($primary_topic_title);
        // Add the parent to the story.
        $story->appendChild($primary_topic_element);
      }
    }

    // Secondary topics.
    $secondary_topic_field = $story_mappings['topic'];
    $secondary_topics = $node->get($secondary_topic_field)->referencedEntities();
    if (!empty($secondary_topic_field) && $secondary_topic_field != 'unused' && is_array($secondary_topics)) {
      foreach ($secondary_topics as $secondary_topic) {
        $secondary_topic_id_value = $secondary_topic->field_npr_news_id->value;
        $secondary_topic_title_value = $secondary_topic->getName();
        if (!empty($secondary_topic_id_value) && !empty($secondary_topic_title_value)) {
          // Create the outermost element, the parent.
          $secondary_topic_element = $xml->createElement('parent');
          // Add the id to the parent.
          $secondary_topic_id = $xml->createAttribute('id');
          $secondary_topic_id->value = $secondary_topic_id_value;
          $secondary_topic_element->appendChild($secondary_topic_id);
          // Add the type to the parent.
          $secondary_topic_type = $xml->createAttribute('type');
          $secondary_topic_type->value = 'secondaryTopic';
          $secondary_topic_element->appendChild($secondary_topic_type);
          // Add the title element to the parent.
          $secondary_topic_title = $xml->createElement('title', $secondary_topic_title_value);
          $secondary_topic_element->appendChild($secondary_topic_title);
          // Add the parent to the story.
          $story->appendChild($secondary_topic_element);
        }
      }
    }

    // Subtitle.
    if ($subtitle_field = $story_mappings['subtitle']) {
      if (!empty($node->{$subtitle_field}->value) &&
        !empty($subtitle_field)
        && $subtitle_field !== 'unused'
      ) {
        $subtitle = $node->{$subtitle_field}->value;
        $element = $xml->createElement($subtitle_field, $subtitle);
        $element = $xml->createAttribute('subtitle');
        $element->value = $subtitle;
        $story->appendChild($element);
      }
    }

    $textfields = ['subtitle', 'shortTitle', 'miniTeaser', 'slug'];
    foreach ($textfields as $field) {
      if ($drupal_field = $story_mappings[$field]) {
        if (!empty($drupal_field) && $drupal_field !== 'unused') {
          if ($value = $node->{$drupal_field}->value) {
            $element = $xml->createElement($field, $value);
            $element = $xml->createAttribute($field);
            $element->value = $value;
            $story->appendChild($element);
          }
        }
      }
    }

    // Images.
    if ($image_field = $story_mappings['primary_image']) {
      if (!empty($image_field) && $image_field !== 'unused') {

        // Verify required image field mappings.
        $image_mappings = $story_config->get('image_field_mappings');
        $image_image_field = $image_mappings['image_field'];
        if ($image_image_field == 'unused') {
          $this->nprError('In order to push media images to NPR, please configure the image_field mappings in the "Image Field Mappings" section of the "Story Settings" tab.');
          return;
        }

        $media_image = $node->get($image_field)->referencedEntities();
        $media_image = reset($media_image);
        if (!empty($media_image->{$image_image_field}) &&
          $image_references = $media_image->{$image_image_field}
        ) {
          foreach ($image_references as $image_reference) {
            $file_id = $image_reference->get('target_id')->getValue();
            if ($image_file = $this->entityTypeManager->getStorage('file')->load($file_id)) {
              // Get the image URL.
              $image_uri = $image_file->get('uri')->getString();
              $image_url = \file_create_url($image_uri);

              // Create the primary image NPRML element.
              $element = $xml->createElement('image');
              $image_type = $xml->createAttribute('type');
              $image_type->value = 'primary';
              $element->appendChild($image_type);
              $src = $xml->createAttribute('src');
              $src->value = $image_url;
              $element->appendChild($src);
              $story->appendChild($element);
            }
          }
        }
      }
    }

    $list->appendChild($story);
    return $xml->saveXML();
  }

  /**
   * Helper function for error messages.
   *
   * @param string $text
   *   The message to log or display.
   */
  private function nprError($text) {
    $this->logger->error($text);
    if (!empty($this->displayMessages)) {
      $this->messenger->addError($text);
    }
  }

}
