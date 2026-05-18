<?php

namespace Drupal\Tests\npr_pull\Unit;

use Drupal\npr_pull\NprPullStoryImportResult;
use Drupal\Tests\UnitTestCase;

/**
 * Tests NprPullStoryImportResult log formatting.
 *
 * @group npr
 * @coversDefaultClass \Drupal\npr_pull\NprPullStoryImportResult
 */
class NprPullStoryImportResultTest extends UnitTestCase {

  /**
   * @covers ::formatLogSuffix
   */
  public function testFormatLogSuffixSkippedUnchanged(): void {
    $result = NprPullStoryImportResult::skippedUnchanged('g-s1-119323', 456);
    $line = $result->formatLogSuffix(42.5);
    $this->assertStringContainsString('outcome=skipped_unchanged', $line);
    $this->assertStringContainsString('npr_id=g-s1-119323', $line);
    $this->assertStringContainsString('nid=456', $line);
    $this->assertStringContainsString('duration_ms=43', $line);
  }

  /**
   * @covers ::formatLogSuffix
   */
  public function testFormatLogSuffixSkippedHookWithDetail(): void {
    $result = NprPullStoryImportResult::skippedHook('nx-s1-1', 10, 'Story <a href="/node/10">x</a> was skipped.');
    $line = $result->formatLogSuffix(10);
    $this->assertStringContainsString('outcome=skipped_hook', $line);
    $this->assertStringContainsString('detail=Story x was skipped.', $line);
  }

}
