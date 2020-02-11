<?php

namespace Drupal\npr_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Provides a help form for API definitions.
 */
class NprApiHelpForm extends FormBase {

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new NprApiHelpForm.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(RendererInterface $renderer) {
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_api_help_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $npr_fields = npr_api_get_nprml_fields();
    $header = [
      $this->t('NPRML Field'),
      $this->t('Description'),
      $this->t('Type'),
      $this->t('Accepted Types for mapping'),
    ];

    $form['intro'] = [
      '#markup' => 'Listed below are the avaiable fields from NPR to map content to, and descriptions. If you are having trouble, please visit the Support Center at <a href="https://nprsupport.desk.com/" target="_blank">https://nprsupport.desk.com/</a>',
    ];

    $rows = [];
    $table = NULL;
    foreach ($npr_fields as $name => $field) {
      $new_row = [];
      $accepted_types = "";
      foreach ($field['accepted_types'] as $value) {
        $accepted_types .= $value;
      }
      $description = isset($field['description']) ? $field['description'] : '';
      $new_row = [$name, $description, $field['type'], $accepted_types];
      array_push($rows, $new_row);
    }

    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];
    $markup = $this->renderer($table);

    $form['NPRML_fields'] = [
      '#type' => 'fieldset',
      '#title' => strtoupper('NPRML Fields'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];
    $form['NPRML_fields']['info'] = [
      '#markup' => $this->t('Title, teaser, and body will automatically be mapped to the corresponding NPRML elements.'),
    ];
    $form['NPRML_fields']['table'] = ['#markup' => $markup];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
