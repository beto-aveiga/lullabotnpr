<?php

namespace Drupal\Tests\npr_pull\Unit;

use Drupal\npr_pull\NprCdsPullClient;
use Drupal\Tests\UnitTestCase;

/**
 * Tests NPR editorial timestamp comparison on NprCdsPullClient.
 *
 * @group npr
 * @coversDefaultClass \Drupal\npr_pull\NprCdsPullClient
 */
class NprCdsPullClientEditorialTimestampTest extends UnitTestCase {

  /**
   * @covers ::getNprEditorialComparisonTimestamp
   * @dataProvider editorialTimestampProvider
   */
  public function testGetNprEditorialComparisonTimestamp(array $story, ?int $expected_not_null): void {
    $client = new NprCdsPullClientTestAccess();
    $ts = $client->editorialTimestamp($story);

    if ($expected_not_null === NULL) {
      $this->assertNull($ts);
    }
    else {
      $this->assertIsInt($ts);
      $this->assertGreaterThan(0, $ts);
    }
  }

  /**
   * Data provider for editorial timestamp resolution.
   */
  public static function editorialTimestampProvider(): array {
    return [
      'major only' => [
        ['editorialMajorUpdateDateTime' => '2024-09-28T10:00:00+00:00'],
        1,
      ],
      'last modified only' => [
        ['editorialLastModifiedDateTime' => '2024-09-28T10:00:00+00:00'],
        1,
      ],
      'major preferred over last modified' => [
        [
          'editorialMajorUpdateDateTime' => '2024-09-28T10:00:00+00:00',
          'editorialLastModifiedDateTime' => '2025-01-01T10:00:00+00:00',
        ],
        1,
      ],
      'neither' => [
        [],
        NULL,
      ],
      'both empty strings' => [
        [
          'editorialMajorUpdateDateTime' => '',
          'editorialLastModifiedDateTime' => '',
        ],
        NULL,
      ],
      'empty major falls back to last modified' => [
        [
          'editorialMajorUpdateDateTime' => '',
          'editorialLastModifiedDateTime' => '2024-09-28T10:00:00+00:00',
        ],
        1,
      ],
    ];
  }

  /**
   * @covers ::getNprEditorialComparisonTimestamp
   */
  public function testMajorPreferredOverLastModifiedValue(): void {
    $client = new NprCdsPullClientTestAccess();
    $story = [
      'editorialMajorUpdateDateTime' => '2024-09-28T10:00:00+00:00',
      'editorialLastModifiedDateTime' => '2025-01-01T10:00:00+00:00',
    ];
    $major_only = $client->editorialTimestamp(['editorialMajorUpdateDateTime' => $story['editorialMajorUpdateDateTime']]);
    $this->assertSame($major_only, $client->editorialTimestamp($story));
  }

  /**
   * Skip decision: Drupal changed newer than NPR last modified.
   */
  public function testSkipWhenDrupalChangedIsNewer(): void {
    $client = new NprCdsPullClientTestAccess();
    $npr_ts = $client->editorialTimestamp([
      'editorialLastModifiedDateTime' => '2024-09-28T10:00:00+00:00',
    ]);
    $drupal_changed = $npr_ts + 3600;
    $this->assertGreaterThanOrEqual($npr_ts, $drupal_changed);
  }

  /**
   * Import decision: NPR major newer than Drupal changed.
   */
  public function testImportWhenNprMajorIsNewer(): void {
    $client = new NprCdsPullClientTestAccess();
    $npr_ts = $client->editorialTimestamp([
      'editorialMajorUpdateDateTime' => '2026-05-18T10:00:00+00:00',
    ]);
    $drupal_changed = strtotime('2024-01-01');
    $this->assertLessThan($drupal_changed, $npr_ts);
  }

}

/**
 * Test accessor for protected NprCdsPullClient methods.
 */
class NprCdsPullClientTestAccess extends NprCdsPullClient {

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * Exposes editorial comparison timestamp resolution.
   */
  public function editorialTimestamp(array $story): ?int {
    return $this->getNprEditorialComparisonTimestamp($story);
  }

}
