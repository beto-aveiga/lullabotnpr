<?php

namespace Drupal\npr_pull;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Resolves the configured storyDate field to a Unix timestamp.
 */
final class StoryDateTimestampResolver {

  /**
   * Returns a Unix timestamp for the node's configured story date, or NULL.
   */
  public function resolve(FieldableEntityInterface $node, string $field_name): ?int {
    if ($field_name === '' || $field_name === 'unused') {
      return NULL;
    }

    // Base fields on content entities.
    if ($field_name === 'created') {
      return (int) $node->getCreatedTime();
    }
    if ($field_name === 'changed') {
      return (int) $node->getChangedTime();
    }

    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $field = $node->get($field_name);
    $definition = $field->getFieldDefinition();

    // Datetime fields (storage or display values).
    if ($definition->getType() === 'datetime') {
      $value = $field->value;
      if ($value === NULL || $value === '') {
        return NULL;
      }
      $dt = new DrupalDateTime($value, DateTimeItemInterface::STORAGE_TIMEZONE);
      return (int) $dt->getTimestamp();
    }

    $raw = (string) $field->value;
    if ($raw === '') {
      return NULL;
    }

    // Numeric Unix timestamp (created/changed-style values in custom fields).
    if (ctype_digit($raw)) {
      return (int) $raw;
    }

    // ISO-8601 or other parseable datetime strings from NPR-mapped fields.
    if (str_contains($raw, 'T') || preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
      try {
        return (int) (new DrupalDateTime($raw))->getTimestamp();
      }
      catch (\Exception) {
        return NULL;
      }
    }

    // Legacy: first 10 characters as a calendar date (Y-m-d).
    $date_part = substr($raw, 0, 10);
    $ts = strtotime($date_part);
    return $ts !== FALSE ? $ts : NULL;
  }

}
