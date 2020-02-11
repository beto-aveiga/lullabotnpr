<?php

namespace Drupal\npr_api;

/**
 * Basic OOP container for NPRML element.
 */
class NPRMLElement {

  /**
   * Returns the value.
   */
  public function __toString() {
    return $this->value;
  }

}
