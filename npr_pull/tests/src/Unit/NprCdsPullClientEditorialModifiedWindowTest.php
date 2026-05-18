<?php

namespace Drupal\Tests\npr_pull\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\npr_pull\NprCdsPullClient;
use Drupal\Tests\UnitTestCase;

/**
 * Tests editorial-last-modified queue window calculation.
 *
 * @group npr
 * @coversDefaultClass \Drupal\npr_pull\NprCdsPullClient
 */
class NprCdsPullClientEditorialModifiedWindowTest extends UnitTestCase {

  /**
   * @covers ::getEditorialModifiedSinceForQueue
   */
  public function testEditorialSinceUsesOverlapAndDaysBackFloor(): void {
    $last_update = new \DateTime('@1700000000');
    $client = new NprCdsPullClientEditorialWindowTestAccess();
    $client->initForTest($last_update, 3, 24);

    $since = $client->editorialSinceForQueue();

    $expected_overlap = 1700000000 - (24 * 3600);
    $expected_floor = time() - (3 * 86400);
    $expected = max($expected_overlap, $expected_floor);

    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $expected), $since);
  }

}

/**
 * Test accessor for protected window method.
 */
class NprCdsPullClientEditorialWindowTestAccess extends NprCdsPullClient {

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * Injects config and state for the window calculation.
   */
  public function initForTest(\DateTime $lastUpdate, int $startDateDays, int $overlapHours): void {
    $pull_config = $this->createMock(Config::class);
    $pull_config->method('get')->willReturnCallback(function (string $key) use ($startDateDays, $overlapHours) {
      return match ($key) {
        'start_date' => $startDateDays,
        'editorial_modified_overlap_hours' => $overlapHours,
        default => NULL,
      };
    });
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('npr_pull.settings')->willReturn($pull_config);
    $this->config = $config_factory;

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn($lastUpdate);
    $this->state = $state;
  }

  /**
   * Exposes editorial modified since calculation.
   */
  public function editorialSinceForQueue(): string {
    return $this->getEditorialModifiedSinceForQueue();
  }

}
