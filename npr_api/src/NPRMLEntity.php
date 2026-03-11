<?php

namespace Drupal\npr_api;

/**
 * Basic OOP container for NPR entity (story, author, etc.).
 */
#[\AllowDynamicProperties]
class NPRMLEntity {

    /**
     * The HTML content of the entity.
     * @var string
     */
    public $body;

}
