<?php

declare(strict_types=1);

namespace Drupal\npr_pull;

/**
 * Outcome of a single addOrUpdateNode() import attempt.
 */
final class NprPullStoryImportResult {

  public const INVALID_STORY = 'invalid_story';

  public const CONFIG_ERROR = 'config_error';

  public const DUPLICATE_NODES = 'duplicate_nodes';

  public const SKIPPED_HOOK = 'skipped_hook';

  public const SKIPPED_UNCHANGED = 'skipped_unchanged';

  public const CREATED = 'created';

  public const UPDATED = 'updated';

  public const SAVE_FAILED = 'save_failed';

  public function __construct(
    public readonly string $outcome,
    public readonly ?string $nprId = NULL,
    public readonly ?int $nid = NULL,
    public readonly ?string $message = NULL,
  ) {}

  public static function invalidStory(?string $nprId = NULL): self {
    return new self(self::INVALID_STORY, $nprId);
  }

  public static function configError(?string $nprId = NULL, ?string $message = NULL): self {
    return new self(self::CONFIG_ERROR, $nprId, NULL, $message);
  }

  public static function duplicateNodes(string $nprId): self {
    return new self(self::DUPLICATE_NODES, $nprId);
  }

  public static function skippedHook(string $nprId, int $nid, ?string $message = NULL): self {
    return new self(self::SKIPPED_HOOK, $nprId, $nid, $message);
  }

  public static function skippedUnchanged(string $nprId, int $nid): self {
    return new self(self::SKIPPED_UNCHANGED, $nprId, $nid);
  }

  public static function created(string $nprId, int $nid): self {
    return new self(self::CREATED, $nprId, $nid);
  }

  public static function updated(string $nprId, int $nid): self {
    return new self(self::UPDATED, $nprId, $nid);
  }

  public static function saveFailed(string $nprId, ?int $nid = NULL, ?string $message = NULL): self {
    return new self(self::SAVE_FAILED, $nprId, $nid, $message);
  }

  public function saved(): bool {
    return in_array($this->outcome, [self::CREATED, self::UPDATED], TRUE);
  }

  /**
   * Formats this result for a single-line debug log suffix.
   */
  public function formatLogSuffix(float $durationMs): string {
    $parts = [
      'outcome=' . $this->outcome,
      sprintf('duration_ms=%.0f', $durationMs),
    ];
    if ($this->nprId !== NULL) {
      $parts[] = 'npr_id=' . $this->nprId;
    }
    if ($this->nid !== NULL) {
      $parts[] = 'nid=' . $this->nid;
    }
    if ($this->message !== NULL && $this->message !== '') {
      $message = preg_replace('/\s+/', ' ', strip_tags($this->message)) ?? '';
      $message = trim($message);
      if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
      }
      if ($message !== '') {
        $parts[] = 'detail=' . $message;
      }
    }
    return implode(' ', $parts);
  }

}
