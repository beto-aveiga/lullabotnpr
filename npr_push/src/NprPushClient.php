<?php

namespace Drupal\npr_push;

use DateTime;
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
    $nprml_version->value = self::NPRML_VERSION;
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
      $body_cdata = $xml->createCDATASection($body);
      $text = $xml->createElement('text');
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
      format_date($node->getCreatedTime(), 'custom', "D, d M Y G:i:s O ") ;
    $story->appendChild($xml->createElement('storyDate', $story_date));
    $story->appendChild($xml->createElement('pubDate', $now));

    // Story URL.
    $url = $node->toUrl()->setAbsolute()->toString();
    $url_cdata = $xml->createCDATASection($url);
    $url_type = $xml->createAttribute('type');
    $url_type->value = 'html';
    $url_element = $xml->createElement('link');
    $url_element->appendChild($url_cdata);
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

    // Subtitle.
    $subtitle_field = $story_mappings['subtitle'];
    $subtitle = $node->{$subtitle_field}->value;
    if (!empty($subtitle) && !empty($subtitle_field) && $subtitle_field !== 'unused') {
      $element = $xml->createElement($subtitle_field, $subtitle);
      $element = $xml->createAttribute('subtitle');
      $element->value = $subtitle;
      $story->appendChild($element);
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
        $image_references = $media_image->{$image_image_field};
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

    /*************************
    // Audio.
    // TODO: This code does not quite work. Add audio functionality.
    if ($audio_field = $story_mappings['audio']) {
      if (!empty($audio_field) && $audio_field !== 'unused') {

        // Get and check the configuration.
        $audio_media_type = $story_config->get('audio_media_type');
        $audio_format = $story_config->get('audio_format');
        $audio_mappings = $story_config->get('audio_field_mappings');
        $duration_value = $audio_mappings['duration'];
        if (empty($audio_media_type) || empty($audio_format) || $duration_value == 'unused') {
          $this->nprError('Please configure the NPR story audio type, format, and duration to push audio to NPR.');
          return;
        }
        $media_audio = $node->get($audio_field)->referencedEntities();
        $media_audio = reset($media_audio);

        $element = $xml->createElement('audio');
        if (!empty($audio_primary_set)) {
          $audio_type = $xml->createAttribute('type');
          $audio_type->value = 'primary';
          $element->appendChild($audio_type);
          $audio_primary_set = TRUE;
        }
        $title = $xml->createElement('title', $media_audio->getName());
        $element->appendChild($title);

        $duration = $xml->createElement('duration', $media_audio->get($duration_value)->getValue());
        $element->appendChild($duration);

        // $description = $xml->createElement('description', $v['description']);
        // $element->appendChild($description);

        $format = $xml->createElement('format');
        $mp3 = $xml->createElement('mp3', $v['mp3']);
        $mp3type = $xml->createAttribute('type');
        $mp3type->value = 'm3u';
        $mp3->appendChild($mp3type);
        $format->appendChild($mp3);

        // $mediastream = $xml->createElement('mediastream', $v['mediastream']);
        // $format->appendChild($mediastream);

        // $wm = $xml->createElement('wm', $v['wm']);
        // $format->appendChild($wm);

        $element->appendChild($format);

        // $permissions = $xml->createElement('permissions');

        // $download = $xml->createElement('download');
        // $download_allow = $xml->createAttribute('allow');
        // $download_allow->value = $v['download'] ? 'true' : 'false';
        // $download->appendChild($download_allow);
        // $permissions->appendChild($download);

        // $stream = $xml->createElement('stream');
        // $stream_allow = $xml->createAttribute('stream');
        // $stream_allow->value = $v['stream'] ? 'true' : 'false';
        // $stream->appendChild($stream_allow);
        // $permissions->appendChild($stream);

        // $embed = $xml->createElement('embed');
        // $embed_allow = $xml->createAttribute('allow');
        // $embed_allow->value = $v['embed'] ? 'true' : 'false';
        // $embed->appendChild($embed_allow);
        // $permissions->appendChild($embed);

        // $element->appendChild($permissions);

        if (is_object($element)) {
          $story->appendChild($element);
        }
      }
      **************************/

    $list->appendChild($story);
    return $xml->saveXML();
    }
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
