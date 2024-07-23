<?php

namespace Drupal\npr_pull\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\npr_pull\NprPullClientFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a configuration form for story nodes.
 */
class NprPullConfigForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The NPR Pull service.
   *
   * @var \Drupal\npr_pull\NprPullClientInterface
   */
  protected $client;

  /**
   * Constructs a new NprStoryConfigForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date Formatter service.
   * @param \Drupal\npr_pull\NprPullClientFactory $client
   *   The NPR client.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, NprPullClientFactory $client) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->client = $client->build();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('npr_pull.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'npr_pull_config_form';
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
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('npr_pull.settings');

    // Add a NPR Pull section to the API Settings configuration page.
    $form['npr_pull_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('NPR Pull'),
    ];
    $form['npr_pull_config']['npr_pull_url'] = [
      '#type' => 'select',
      '#title' => $this->t('NPR Pull URL'),
      '#default_value' => $config->get('npr_pull_url'),
      '#options' => [
        'staging' => $this->t('Staging'),
        'production' => $this->t('Production'),
      ],
    ];
    $form['npr_pull_config']['npr_pull_service'] = [
      '#type' => 'select',
      '#title' => $this->t('NPR Pull Service'),
      '#default_value' => $config->get('npr_pull_service'),
      '#options' => [
        'xml' => 'XML',
        'cds' => 'CDS',
      ],
    ];

    // Create an array of all Drupal users.
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple();
    $all_users = [];
    foreach ($users as $user) {
      $all_users[$user->id()] = $user->getDisplayName();
    }
    unset($all_users[0]);
    asort($all_users);

    $form['npr_pull_config']['npr_pull_author'] = [
      '#type' => 'select',
      '#title' => 'Drupal author of pulled stories',
      '#default_value' => $config->get('npr_pull_author'),
      '#options' => $all_users,
    ];

    $form['queue_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automated queue building'),
      '#description' => $this->t('Enable incremental updates to local Story
      nodes from NPR API data.'),
      '#default_value' => $config->get('queue_enable'),
      '#return_value' => TRUE,
    ];

    $interval_options = [1, 600, 1800, 3600, 10800, 21600, 43200, 86400, 604800];
    $form['queue_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Queue builder update interval'),
      '#description' => $this->t('How often to check the NPR API for new or
        updated stories to add to the queue. The queue itself is processed
        one every cron ron (or by an external cron operation).'),
      '#default_value' => $config->get('queue_interval'),
      '#options' => array_map([$this->dateFormatter, 'formatInterval'],
        array_combine($interval_options, $interval_options)),
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['num_results'] = [
      '#type' => 'select',
      '#title' => $this->t('Number of stories to retrieve per cron run per topic'),
      '#default_value' => $config->get('num_results'),
      '#options' => [
        5 => 5,
        10 => 10,
        25 => 25,
        50 => 50,
        100 => 100,
      ],
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['start_date'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Days back'),
      '#default_value' => $config->get('start_date'),
      '#description' => $this->t('The numbers of days back (from today) to query for stories.'),
      '#required' => TRUE,
      '#size' => 3,
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['org_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Limit by organization ID'),
      '#default_value' => $config->get('org_id'),
      '#description' => $this->t('Leave this blank to allow for all organizations.'),
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['subscribe_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Select method for subscription'),
      '#description' => $this->t('Both methods produce a list of NPR tag and topic IDs to query. One method allows selecting terms for a list, while the other method is more flexible and uses data from a configurable taxonomy vocabulary.'),
      '#default_value' => $config->get('subscribe_method'),
      '#options' => [
        'checkbox' => $this->t('Checkbox'),
        'taxonomy' => $this->t('Taxonomy'),
      ],
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['topic_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Limit by topic'),
      '#default_value' => $config->get('topic_ids'),
      '#options' => $this->getTopics(),
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
          'select[name="subscribe_method"]' => ['value' => 'checkbox'],
        ],
      ],
    ];

    $vocabs = array_keys($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple());
    $vocabulary_options = array_combine($vocabs, $vocabs);
    $form['topic_vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select taxonomies to use for subscription'),
      '#default_value' => $config->get('topic_vocabularies'),
      '#description' => $this->t('Each vocabulary selected MUST have a integer field with the machine name `field_npr_news_id`, a boolean field with the machine name `field_npr_subscribe`, and use the `name` field for the title.'),
      '#options' => $vocabulary_options,
      '#states' => [
        'visible' => [
          'input[name="queue_enable"]' => ['checked' => TRUE],
          'select[name="subscribe_method"]' => ['value' => 'taxonomy'],
        ],
      ],
    ];

    if ($subscriptions = $this->client->getSubscriptionTerms()) {
      foreach ($subscriptions as $subscription) {
        // Name is a required field and can't be blank.
        $tid = $subscription->get('tid')->value;
        $name = $subscription->get('name')->value;
        $npr_id = $subscription->get('field_npr_news_id')->value;
        $bundle = $subscription->bundle();
        $url = Url::fromRoute('entity.taxonomy_term.edit_form', [
          'taxonomy_term' => $tid,
        ])->toString(TRUE)->getGeneratedUrl();
        $items[] = $this->t('<a href="@url">@name</a> (@bundle, @npr_id)', [
          '@url' => $url,
          '@name' => $name,
          '@bundle' => $bundle,
          '@npr_id' => $npr_id,
        ]);
      }
      $form['subscriptions'] = [
        '#type' => 'markup',
        '#theme' => 'item_list',
        '#title' => 'Subscriptions',
        '#items' => $items,
        '#states' => [
          'visible' => [
            'select[name="subscribe_method"]' => ['value' => 'taxonomy'],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('npr_pull.settings');

    $config->set('npr_pull_url', $values['npr_pull_url']);
    $config->set('npr_pull_service', $values['npr_pull_service']);
    $config->set('npr_pull_author', $values['npr_pull_author']);
    $config->set('queue_interval', $values['queue_interval']);
    $config->set('queue_enable', $values['queue_enable']);
    $config->set('num_results', $values['num_results']);
    $config->set('start_date', $values['start_date']);
    $config->set('org_id', $values['org_id']);
    $config->set('subscribe_method', $values['subscribe_method']);
    $config->set('topic_vocabularies', array_filter($values['topic_vocabularies']));
    $config->set('topic_ids', array_filter($values['topic_ids']));
    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Return a list of all topics keyed by topic id.
   *
   * @see https://www.npr.org/api/mappingCodes.php
   */
  public function getTopics() {
    $topics = [
      1126 => 'Africa',
      1059 => 'Analysis',
      1132 => 'Animals',
      1142 => 'Architecture',
      1047 => 'Art & Design',
      1008 => 'Arts & Life',
      1125 => 'Asia',
      1033 => 'Author Interviews',
      1161 => 'Book News & Features',
      1034 => 'Book Reviews',
      1032 => 'Books',
      1006 => 'Business',
      1030 => "Children's Health",
      1145 => 'Dance',
      1051 => 'Diversions',
      1017 => 'Economy',
      1013 => 'Education',
      139482413 => 'Elections',
      1131 => 'Energy',
      1025 => 'Environment',
      1124 => 'Europe',
      1141 => 'Fine Art',
      1134 => 'Fitness & Nutrition',
      1053 => 'Food',
      1052 => 'Games & Humor',
      1054 => 'Gardening',
      1031 => 'Global Health',
      1120 => 'Gov. Sarah Palin',
      1128 => 'Health',
      1027 => 'Health Care',
      1136 => 'History',
      1002 => 'Home Page Top Stories',
      139545299 => 'House & Senate Races',
      1150 => 'Investigations',
      1093 => 'Katrina & Beyond',
      1127 => 'Latin America',
      1070 => 'Law',
      1074 => 'Lost & Found Sound',
      1076 => 'Low-Wage America',
      1020 => 'Media',
      1135 => 'Medical Treatments',
      1029 => 'Mental Health',
      1009 => 'Middle East',
      1137 => 'Movie Interviews',
      4467349 => 'Movie Reviews',
      1045 => 'Movies',
      1003 => 'National',
      1122 => 'National Security',
      1001 => 'News',
      1062 => 'Obituaries',
      1028 => 'On Aging',
      1133 => 'On Disabilities',
      1057 => 'Opinion',
      1046 => 'Performing Arts',
      1143 => 'Photography',
      1014 => 'Politics',
      1048 => 'Pop Culture',
      139544303 => 'Presidential Race',
      1015 => 'Race',
      1023 => 'Radio Expeditions',
      1139 => 'Recipes',
      1016 => 'Religion',
      1024 => 'Research News',
      1007 => 'Science',
      1117 => 'Sen. Barack Obama (D-IL)',
      1116 => 'Sen. Hillary Clinton (D-NY)',
      1118 => 'Sen. John McCain (R-AZ)',
      1119 => 'Sen. Joseph Biden (D-DE)',
      1083 => 'Social Security Debate',
      1026 => 'Space',
      1055 => 'Sports',
      139545485 => 'Statewide Races',
      1146 => 'Strange News',
      1088 => 'Summer',
      1087 => 'Summer Reading: Cooking',
      1085 => 'Summer Reading: Fiction',
      1086 => 'Summer Reading: Kids',
      1089 => 'Summer Reading: Nonfiction',
      1019 => 'Technology',
      1138 => 'Television',
      1077 => 'The Second Term',
      1144 => 'Theater',
      1163 => 'TV Reviews',
      1004 => 'World',
      1066 => 'Your Health',
      1018 => 'Your Money',
      139998151 => 'Blues',
      10003 => 'Classical',
      1109 => 'Concerts',
      92792712 => 'Country',
      135408474 => 'Electronic/Dance',
      139999257 => 'Folk',
      735750628 => 'Gospel',
      10005 => 'Hip-Hop',
      1040 => 'In Performance',
      10002 => 'Jazz',
      139996449 => 'Latin',
      1039 => 'Music',
      613820055 => 'Music Features',
      1105 => 'Music Interviews',
      1107 => 'Music Lists',
      1106 => 'Music News',
      1151 => 'Music Quizzes',
      1104 => 'Music Reviews',
      1110 => 'Music Videos',
      1108 => 'New Music',
      10006 => 'Other',
      139997200 => 'Pop',
      139998808 => 'R&B/Soul',
      740095648 => 'Reggae',
      10001 => 'Rock',
      1103 => 'Studio Sessions',
      10004 => 'World',
    ];
    asort($topics);
    return $topics;
  }

}
