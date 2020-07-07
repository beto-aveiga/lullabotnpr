<?php

namespace Drupal\npr_push\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a configuration form for story nodes.
 */
class NprPushConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_push_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['npr_push.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('npr_push.settings');

    $form['org_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID'),
      '#default_value' => $config->get('org_id'),
      '#description' => $this->t('The ID of the organization (orgId) to use when pushing stories to NPR.'),
    ];
    $form['ingest_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ingest URL'),
      '#default_value' => $config->get('ingest_url'),
      '#description' => $this->t('The URL to use when pushing stories to NPR.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('npr_push.settings');

    $config->set('org_id', $values['org_id']);
    $config->set('ingest_url', $values['ingest_url']);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
