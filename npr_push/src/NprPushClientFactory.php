<?php

namespace Drupal\npr_push;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\npr_pull\NprPushClientInterface;

/**
 * Factory for generating NPR Push Clients.
 */
class NprPushClientFactory {

  /**
   * NPR Pull Settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('npr_pull.settings');
  }

  /**
   * Build the NPR Push Client.
   *
   * @return \Drupal\npr_pull\NprPushClientInterface
   *   The proper NPR Push Client based on config.
   */
  public function build(): NprPushClientInterface {
    $service = $this->config->get('npr_push_service') ?? 'xml';
    return \Drupal::service('npr_push.' . $service . '_client');
  }

}
