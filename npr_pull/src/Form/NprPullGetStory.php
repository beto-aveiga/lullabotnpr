<?php

/**
 * @file
 * Contains \Drupal\npr_pull\Form\NprPullGetStory.
 */

namespace Drupal\npr_pull\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\npr_pull\NprPullClient;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NprPullGetStory extends ConfigFormBase {

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The NPR Pull service.
   *
   * @var \Drupal\npr_pull\NprPullClient
   */
  protected $client;

  /**
   * MyModuleService constructor.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\npr_pull\NprPullClient $client
   *   The NPR client.
   *
   */
  public function __construct(MessengerInterface $messenger, NprPullClient $client) {
    $this->messenger = $messenger;
    $this->client = $client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('npr_pull.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['npr_pull.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_pull_get_story';
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {

    $key = $this->config('npr_api.settings')->get('npr_api_api_key');
    $author_id = $this->config('npr_pull.settings')->get('npr_pull_author');
    $user = User::load($author_id);

    $form['url'] = [
      '#type' => 'textfield',
      '#title' => t('NPR API story URL'),
      '#required' => TRUE,
      '#description' => t('Full URL for a story on NPR.org.'),
    ];

    $form['author'] = [
      '#type' => 'item',
      '#markup' => $this->t('The story author will be the Drupal user: %author',
        ['%author' => $user->getUsername()]
      ),
    ];

    $form['publish_flag'] = [
      '#type' => 'checkbox',
      '#title' => t('Publish stories upon retrieval?'),
      '#default_value' => '',
      '#description' => $this->t('If checked stories will automatically be published. If not, stories will still be retrieved and saved in your database - but not published.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Get story'),
    ];

    return $form;
  }

  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {

    $url_value = $form_state->getValue(['url']);

    if (!UrlHelper::isValid($url_value, TRUE)) {
      $form_state->setErrorByName('url', t('Does not appear to be a valid URL.'));
      return;
    }

    if (!$this->client->extractId($url_value)) {
      $form_state->setErrorByName('url', t('Could not extract an NPR ID from given URL.'));
    }
  }

  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {

    // Get the ID of the story.
    $story_id = 0;
    $url_value = $form_state->getValue(['url']);
    $story_id = $this->client->extractId($url_value);

    // Get the publish flag.
    $published = $form_state->getValue(['publish_flag']);

    // Save or update the story.
    $this->client->saveOrUpdateNode($story_id, $published);
  }

}
