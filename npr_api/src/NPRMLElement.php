<?php
namespace Drupal\npr_api;

/**
 * Basic OOP container for NPRML element.
 */
class NPRMLElement {
  function __toString() {
    return $this->value;
  }
}
