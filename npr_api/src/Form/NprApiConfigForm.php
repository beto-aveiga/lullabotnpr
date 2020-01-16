<?php

/**
 * @file
 * Contains \Drupal\npr_api\Form\NprApiConfigForm.
 */

namespace Drupal\npr_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\npr_api\NprClient;

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

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {

    $form['npr_api_api_key'] = [
      '#type' => 'textfield',
      '#title' => t('NPR API Key'),
      '#default_value' => $this->config('npr_api.settings')->get('npr_api_api_key'),
      '#description' => t(''),
    ];

    $form['npr_api_get_key'] = [
      '#markup' => 'To get an API Key, visist <a href="http://api.npr.org" target="_blank">http://api.npr.org</a>'
    ];

    return parent::buildForm($form, $form_state);
  }

}
?>
