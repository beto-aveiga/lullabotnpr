<?php

namespace Drupal\npr_pull;

use Drupal\Core\Config\ConfigFactoryInterface;

class NprPullClientFactory {
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

  public function build(): NprPullClientInterface {
    $service = $this->config->get('npr_pull_service') ?? 'xml';
    return \Drupal::service('npr_pull.' . $service . '_client');
  }
}
