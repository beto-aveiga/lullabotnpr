<?php

namespace Drupal\npr_story\Plugin\Field\FieldFormatter;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime\Plugin\Field\FieldFormatter\DateTimeFormatterBase;

/**
 * Formatter that shows the date in formats other than ISO8601.
 *
 * @FieldFormatter(
 *   id = "npr_date_formatter",
 *   label = @Translation("Formatted Date"),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class NprDateFormatter extends DateTimeFormatterBase {

  /**
   * {@inheritdoc}
   *
   * Only show this formatter if the field is on an NPR bundle and has date in
   * the name.
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {

    $is_npr = FALSE;
    $bundle = $field_definition->getTargetBundle();
    if ($bundle && strpos($bundle, 'npr_') !== FALSE) {
      $is_npr = TRUE;
    }

    $is_date = FALSE;
    $field_name = $field_definition->getName();
    if (strpos($field_name, 'date') !== FALSE) {
      $is_date = TRUE;
    }

    return parent::isApplicable($field_definition) && $is_npr && $is_date;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $date = new DrupalDateTime();
    $summary[] = $this->t('Format: @display', ['@display' => $this->formatDate($date)]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      // NPR sends dates as ISO. We need to convert them to a Drupal Date
      // object, then format.
      $date = DrupalDateTime::createFromFormat(DATE_ISO8601, $item->value . '+0000');
      $elements[$delta] = ['#markup' => $this->formatDate($date)];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function formatDate($date) {
    $format_type = $this->getSetting('format_type');
    $timezone = $this->getSetting('timezone_override') ?: $date->getTimezone()->getName();
    return $this->dateFormatter->format($date->getTimestamp(), $format_type, '', $timezone != '' ? $timezone : NULL);
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'format_type' => 'medium',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {

    $form = parent::settingsForm($form, $form_state);

    $time = new DrupalDateTime();
    $format_types = $this->dateFormatStorage->loadMultiple();
    $options = [];
    foreach ($format_types as $type => $type_info) {
      $format = $this->dateFormatter->format($time->getTimestamp(), $type);
      $options[$type] = $type_info->label() . ' (' . $format . ')';
    }

    $form['format_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Date format'),
      '#description' => $this->t("Choose a format for displaying the date."),
      '#options' => $options,
      '#default_value' => $this->getSetting('format_type'),
    ];

    return $form;
  }

}
