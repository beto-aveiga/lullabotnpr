<?php

namespace Drupal\npr_api;

/**
 * Basic OOP container for NPRML element.
 */
#[\AllowDynamicProperties]
class NPRMLElement {

  /**
   * The scalar value for leaf XML nodes.
   *
   * @var string
   */
  public string $value = '';

  /**
   * Returns the value.
   */
  public function __toString() {
    return $this->value;
  }

}
