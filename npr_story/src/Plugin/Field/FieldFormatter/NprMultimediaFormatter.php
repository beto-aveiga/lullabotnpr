<?php
namespace Drupal\npr_story\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
* Plugin implementation of the 'NPR Multimedia' formatter.
*
* @FieldFormatter(
*   id = "NPR_Multimedia",
*   label = @Translation("NPR Multimedia Player"),
*   field_types = {
*     "link"
*   }
* )
*/
class NprMultimediaFormatter extends FormatterBase {

  /**
  * {@inheritdoc}
  */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('NPR Multimedia Player.');
    return $summary;
  }

  /**
  * {@inheritdoc}
  */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      // Get the parts needed to build the player.
      $entity = $item->getEntity();

      $width = $entity->get('field_npr_multimedia_width')->value;
      $height = $entity->get('field_npr_multimedia_height')->value;
      $id = $entity->get('field_npr_multimedia_id')->value;
      $duration  = $entity->get('field_npr_multimedia_duration')->value;

      $url = $item->uri;

      $elements[$delta] = array(
        '#theme' => 'npr_multimedia_formatter',
        '#url' => $url,
        '#width' => $width,
        '#height' => $height,
        '#id' => $id,
        '#duration' => $duration,
      );
    }

    return $elements;
  }

}
