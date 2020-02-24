<?php

namespace Drupal\npr_pull\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\npr_pull\NprPullClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves NPR stories and creates Drupal story nodes.
 */
class NprPullGetStory extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\npr_pull\NprPullClient $client
   *   The NPR client.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, NprPullClient $client) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
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

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $author_id = $this->config('npr_pull.settings')->get('npr_pull_author');
    $user = $this->entityTypeManager->getStorage('user')->load($author_id);
    $username = $user->getUsername() ?: 'Anonymous';

    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NPR API story URL'),
      '#required' => TRUE,
      '#description' => $this->t('Full URL for a story on NPR.org.'),
    ];

    $form['author'] = [
      '#type' => 'item',
      '#markup' => $this->t('The story author will be the Drupal user: %author',
        ['%author' => $username]
      ),
    ];

    $form['publish_flag'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish stories upon retrieval?'),
      '#default_value' => '',
      '#description' => $this->t('If checked stories will automatically be published. If not, stories will still be retrieved and saved in your database - but not published.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Get story'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $url_value = $form_state->getValue(['url']);

    if (!UrlHelper::isValid($url_value, TRUE)) {
      $form_state->setErrorByName('url', $this->t('Does not appear to be a valid URL.'));
      return;
    }

    if (!$this->client->extractId($url_value)) {
      $form_state->setErrorByName('url', $this->t('Could not extract an NPR ID from given URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Get the ID of the story.
    $url_value = $form_state->getValue(['url']);
    $story_id = $this->client->extractId($url_value) ?: 0;

    // Get the publish flag.
    $published = $form_state->getValue(['publish_flag']);

    // Save or update the story.
    $display_messages = TRUE;
    $this->client->addOrUpdateNode($story_id, $published, $display_messages);
  }

}
