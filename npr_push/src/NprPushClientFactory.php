<?php

namespace Drupal\npr_push;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\npr_pull\NprPushClientInterface;

class NprPushClientFactory {
  /**
   * NPR Pull Settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @param ConfigFactoryInterface $configFactory
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('npr_pull.settings');
  }

  public function build(): NprPushClientInterface {
    $service = $this->config->get('npr_push_service') ?? 'xml';
    return \Drupal::service('npr_push.' . $service . '_client');
  }
}
