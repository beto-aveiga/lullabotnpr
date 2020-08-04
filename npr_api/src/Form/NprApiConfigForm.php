<?php

namespace Drupal\npr_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Provides a base form for NPR API integration.
 */
class NprApiConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_api_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['npr_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $npr_api_config = $this->config('npr_api.settings');

    $form['npr_api_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NPR API Key'),
      '#default_value' => $npr_api_config->get('npr_api_api_key'),
      '#description' => $this->t('To get an API Key, visit <a href="https://api.npr.org" target="_blank">https://api.npr.org</a>'),
    ];

    $form['npr_api_production_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NPR API production URL'),
      '#default_value' => $npr_api_config->get('npr_api_production_url'),
      '#disabled' => TRUE,
    ];

    $form['npr_api_stage_url'] = [
      '#type' => 'select',
      '#title' => $this->t('NPR API staging URL'),
      '#default_value' => $npr_api_config->get('npr_api_stage_url'),
      '#options' => [
        'https://api-s1.npr.org' => 'https://api-s1.npr.org',
        'https://api-s4.npr.org' => 'https://api-s4.npr.org',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('npr_api.settings');

    $config->set('npr_api_api_key', $values['npr_api_api_key']);
    $config->set('npr_api_production_url', $values['npr_api_production_url']);
    $config->set('npr_api_stage_url', $values['npr_api_stage_url']);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
