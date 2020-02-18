<?php

namespace Drupal\npr_api\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\npr_pull\NprPullClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes story data from the NPR API and create story nodes.
 *
 * Queue items are added by cron processing.
 *
 * @QueueWorker(
 *   id = "npr_api.queue.story",
 *   title = @Translation("NPR API story processor"),
 *   cron = {"time" = 60}
 * )
 *
 * @see npr_pull_cron()
 * @see \Drupal\Core\Annotation\QueueWorker
 * @see \Drupal\Core\Annotation\Translation
 */
class StoryQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * NPR API logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $logger;

  /**
   * NPR API pull client.
   *
   * @var \Drupal\npr_pull\NprPullClient
   */
  private $nprPullClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelInterface $logger,
    NprPullClient $npr_pull_client
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->nprPullClient = $npr_pull_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.channel.npr_api'),
      $container->get('npr_pull.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item): void {
    // TODO: Get this from config.
    $published = TRUE;
    $this->nprPullClient->saveOrUpdateNode($item, $published);
  }

}
