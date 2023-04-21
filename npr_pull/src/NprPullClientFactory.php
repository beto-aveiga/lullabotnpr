<?php

namespace Drupal\npr_pull;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Factory for NPR API Pull Clients.
 */
class NprPullClientFactory {
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
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('npr_pull.settings');
  }

  /**
   * Build the required pull client based on config.
   *
   * @return \Drupal\npr_pull\NprPullClientInterface
   *   The Pull Client.
   */
  public function build(): NprPullClientInterface {
    $service = $this->config->get('npr_pull_service') ?? 'xml';
    return \Drupal::service('npr_pull.' . $service . '_client');
  }

}
