<?php

namespace Drupal\npr_api\Normalizer;

use Drupal\serialization\Normalizer\NormalizerBase;
use Drupal\npr_api\NPRMLElement;
use Drupal\npr_api\NPRMLEntity;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class NPRCdsEntityNormalizer extends NormalizerBase implements DenormalizerInterface {

  protected $supportedInterfaceOrClass = NPRMLEntity::class;

  /**
   * {@inheritDoc}
   */
  public function normalize($object, $format = null, array $context = []) {
    // TODO: Implement normalize() method.
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function denormalize($data, $type, $format = null, array $context = []) {
    $body_content = [];
    foreach ($data as $key => $element) {
      switch ($key) {
        case 'layout':
        case 'images':
        case 'bylines':
          $data[$key] = $this->parseAssets($element, $data['assets']);
      }
    }
    foreach ($data['layout'] as $index => $element) {
      $type = $this->getType($element);
      switch ($type) {
        case '/v1/profiles/text':
          $body_content[$index] = _filter_autop($element['text']);
          break;
        /*case 'staticHtml':
                // Add the static html assets in the body.
                if (isset($items->num)) {
                  if ($parsed->htmlAsset->id == $items->refId) {
                    $body_content[$items->num] = $parsed->htmlAsset->value;
                  }
                }
                else {
                  foreach ($items as $item) {
                    foreach ($parsed->htmlAsset as $html_asset) {
                      if ($html_asset->id == $item->refId) {
                        $body_content[$item->num] = $html_asset->value;
                      }
                    }
                  }
                }
                break;

              case 'image':
                // Add a placeholder for each referenced image to the body.
                // But check to see if the object is multidimensional first.
                if (isset($items->num)) {
                  $body_content[$items->num] = "[npr_image:" . $items->refId . "]";
                }
                else {
                  foreach ($items as $item) {
                    $body_content[$item->num] = "[npr_image:" . $item->refId . "]";
                  }
                }
                break;

              case 'multimedia':
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

              case 'externalAsset':
                $external_asset_field = $story_mappings['externalAsset'];
                if (!empty($external_asset_field) || $external_asset_field !== 'unused') {
                  // Add a placeholder for each referenced asset to the body.
                  // But check to see if the object is multidimensional first.
                  if (isset($items->num)) {
                    $body_content[$items->num] = "[npr_external:" .
                      $items->refId . "]";
                  }
                  else {
                    foreach ($items as $item) {
                      $body_content[$item->num] = "[npr_external:" .
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
    $data['body'] = implode(NULL, $body_content);

    return $data;
  }

  protected function getType($element) {
    foreach ($element['profiles'] as $profile) {
      if ($profile['rels'][0] == 'type') {
        return $profile['href'];
      }
    }
    return NULL;
  }

  protected function parseAssets($data, $assets) {
    $elements = [];
    foreach ($data as $asset) {
      $parts = explode('/', $asset['href']);
      $id = $parts[2];
      $elements[] = $assets[$id];
    }
    return $elements;
  }

  protected function parseElements($data) {
    $elements = [];
    foreach ($data as $element) {
      $elements[] = $this->parseElement($element);
    }
    return $elements;
  }
  protected function parseElement($data) {
    $element = new NPRMLElement();
    if (!is_array($data)) {
      $element->value = $data;
      return $element;
    }
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $element->{$key} = $this->parseElement($value);
      } else {
        $element->{$key} = $value;
      }
    }
    return $element;
  }
}
