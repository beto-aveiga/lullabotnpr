<?php

namespace Drupal\npr_api\Normalizer;

use Drupal\serialization\Normalizer\NormalizerBase;
use Drupal\npr_api\NPRMLEntity;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Normalize CDS array into array for import.
 */
class NPRCdsEntityNormalizer extends NormalizerBase implements DenormalizerInterface {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = NPRMLEntity::class;

  /**
   * {@inheritDoc}
   */
  public function normalize($object, $format = NULL, array $context = []) {
    // @todo Implement normalize() method.
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function denormalize($data, $type, $format = NULL, array $context = []) {
    $body_content = [];
    foreach ($data['layout'] as $index => $element) {
      $element = $element['embed'];
      $type = $this->getType($element);
      switch ($type) {
        case '/v1/profiles/text':
          $body_content[$index] = _filter_autop($element['text']);
          break;

        case '/v1/profiles/image':
          // Add a placeholder for each referenced image to the body.
          $body_content[$index] = "[npr_image:" . $element['id'] . "]";
          break;

        case '/v1/profiles/promo-card':
          // @todo This content exists but I am not sure what to do with it.
          break;

        case '/v1/profiles/html-block':
          $data['html-block'][] = $element;
          $body_content[$index] = '[npr_html:' . $element['id'] . ']';
          break;

        case '/v1/profiles/youtube-video':
          $data['externalAsset'][] = [
            'id' => $element['id'],
            'type' => $type,
            'url' => 'https://www.youtube.com/watch?v=' . $element['videoId'],
            'externalId' => $element['videoId'],
            'credit' => $element['headline'] ?? '',
            'caption' => $element['subheadline'] ?? '',
          ];
          $body_content[$index] = '[npr_external:' . $element['id'] . ']';
          break;

        case '/v1/profiles/resource-container':
          break;

/*case 'multimedia':
  $multimedia_field = $story_mappings['multimedia'];
  if (!empty($multimedia_field) && $multimedia_field !== 'unused') {
    // Add laceholder for each referenced multimedia to the body.
    // But check to see if the object is multidimensional first.
    if (isset($items->num)) {
      $body_content[$items->num] = "[npr_multimedia:" .
        $items->refId . "]";
    }
    else {
      foreach ($items as $item) {
        $body_content[$item->num] = "[npr_multimedia:" .
          $item->refId . "]";
      }
    }
  }
  break;
*/
        default:
          break;
      }
    }
    $data['body'] = implode('', $body_content);

    return $data;
  }

  /**
   * Gets the type of the element.
   *
   * @param array $element
   *   Element needing to be typed.
   *
   * @return string|null
   *   Type or null.
   */
  protected function getType(array $element) {
    foreach ($element['profiles'] as $profile) {
      if (isset($profile['rels']) && $profile['rels'][0] == 'type') {
        return $profile['href'];
      }
    }
    return NULL;
  }

}
