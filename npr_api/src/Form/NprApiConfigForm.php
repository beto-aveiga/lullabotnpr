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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('npr_api.settings');

    foreach (Element::children($form) as $variable) {
      $config->set($variable, $form_state->getValue($form[$variable]['#parents']));
    }
    $config->save();

    if (method_exists($this, '_submitForm')) {
      $this->_submitForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
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
      '#description' => $this->t('To get an API Key, visit <a href="http://api.npr.org" target="_blank">http://api.npr.org</a>'),
    ];

    $form['npr_api_production_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NPR API production URL'),
      '#default_value' => $npr_api_config->get('npr_api_production_url'),
      '#disabled' => TRUE,
    ];

    $form['npr_api_staging_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NPR API staging URL'),
      '#default_value' => $npr_api_config->get('npr_api_staging_url'),
      '#disabled' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

}
