<?php

namespace Drupal\mass_feedback_loop\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\mass_feedback_loop\Service\MassFeedbackLoopContentFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MassFeedbackLoopAuthorInterfaceForm.
 */
class MassFeedbackLoopAuthorInterfaceForm extends FormBase {

  /**
   * Custom service to fetch content used in feedback author interface.
   *
   * @var \Drupal\mass_feedback_loop\Service\MassFeedbackLoopContentFetcher
   */
  protected $contentFetcher;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * {@inheritdoc}
   */
  public function __construct(MassFeedbackLoopContentFetcher $content_fetcher, EntityTypeManagerInterface $entity_type_manager, FormBuilderInterface $form_builder) {
    $this->contentFetcher = $content_fetcher;
    $this->entityTypeManager = $entity_type_manager;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mass_feedback_loop.content_fetcher'),
      $container->get('entity_type.manager'),
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mass_feedback_loop_author_interface_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Checks for query params in case pager link was used.
    /** @var \Symfony\Component\HttpFoundation\ParameterBag $query */
    $query = $this->getRequest()->query;

    // Checks for feedback options prior to building form.
    $feedback_options = [];
    $feedback_option_keys = [
      'filter_by_page',
      'filter_by_tag',
      'sort_by',
      'filter_by_info_found',
      'page',
    ];
    // Checks for default values, if available.
    foreach ($feedback_option_keys as $key) {
      // ParameterBag::getDigits returns an int with '+' and '-' stripped out.
      if (is_numeric($query->getDigits($key))) {
        $feedback_options[$key] = $query->getInt($key);
      }
    }

    // Begins form construction.
    $form = [];

    $form['form_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => 'form-wrapper',
      ],
    ];

    // Adds help text for using Feedback Manager.
    $form['form_wrapper']['help_text'] = [
      '#markup' => $this->t('<em><a href="https://medium.com/massdigital/the-feedback-manager-93421d68c268">Learn how to use the Feedback Manager.</a></em>'),
    ];

    // Builds "Filter by page" input.
    $form['form_wrapper']['filter_by_page'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Filter by page'),
      '#target_type' => 'node',
      // Updates form input with default value, if available.
      '#default_value' => (!empty($feedback_options['filter_by_page'])) ? $this->entityTypeManager->getStorage('node')->load($feedback_options['filter_by_page']) : NULL,
      // Uses custom selection handler to filter for user flagged content.
      // @see \Drupal\mass_feedback_loop\Plugin\EntityReferenceSelection\MassFeedbackLoopSelection
      '#selection_handler' => 'mass_feedback_loop_selection',
      '#attributes' => [
        'placeholder' => $this->t('Start typing the page title…'),
      ],
      '#ajax' => [
        'callback' => [$this, 'rebuildFeedbackTable'],
        'event' => 'autocompleteclose',
        'wrapper' => 'table-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating feedback…'),
        ],
      ],
    ];

    // Fetches tags.
    $tags = $this->contentFetcher->fetchAllTags();
    // Builds list of tags.
    $tag_select_list = ['' => $this->t('- Select a tag -')] + $tags;
    // Builds "Filter by tag" input.
    $form['form_wrapper']['filter_by_tag'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by tag'),
      '#options' => $tag_select_list,
      // Updates form input with default value, if available.
      '#default_value' => (!empty($feedback_options['filter_by_tag'])) ? $feedback_options['filter_by_tag'] : NULL,
      '#ajax' => [
        'callback' => [$this, 'rebuildFeedbackTable'],
        'event' => 'change',
        'wrapper' => 'table-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating feedback…'),
        ],
      ],
    ];

    // Builds "Sort by" input.
    $form['form_wrapper']['sort_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by'),
      '#options' => [
        '0' => $this->t('Date (Newest first)'),
        '1' => $this->t('Date (Oldest first)'),
      ],
      '#default_value' => (!empty($feedback_options['sort_by'])) ? $feedback_options['sort_by'] : NULL,
      '#ajax' => [
        'callback' => [$this, 'rebuildFeedbackTable'],
        'event' => 'change',
        'wrapper' => 'table-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating feedback…'),
        ],
      ],
    ];

    // Builds 'Filter by "Did you find?" status' input.
    $form['form_wrapper']['filter_by_info_found'] = [
      '#type' => 'radios',
      '#title' => $this->t('Filter by "Did you find?" status'),
      '#options' => [
        '1' => $this->t('Yes'),
        '0' => $this->t('No'),
        '-1' => $this->t('Show all'),
      ],
      '#default_value' => (isset($feedback_options['filter_by_info_found']) && is_numeric($feedback_options['filter_by_info_found'])) ? $feedback_options['filter_by_info_found'] : -1,
      '#ajax' => [
        'callback' => [$this, 'rebuildFeedbackTable'],
        'event' => 'change',
        'wrapper' => 'table-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating feedback…'),
        ],
      ],
    ];

    // Hidden value used for tracking current page on pager in case of reload.
    $form['form_wrapper']['page'] = [
      '#type' => 'hidden',
      // Updates form input with default value, if available.
      '#value' => (!empty($feedback_options['page'])) ? $feedback_options['page'] : NULL,
    ];

    // Begins table construction with surrounding container.
    $form['table_wrapper'] = [
      '#type' => 'container',
      '#prefix' => '<div id="table-wrapper">',
      '#suffix' => '</div>',
    ];
    // Fetches feedback.
    $response = $this->contentFetcher->fetchFeedback($feedback_options);
    // Builds table and pager.
    $form['table_wrapper']['feedback_table'] = $this->buildFeedbackTable($response['results'], $tags, $response['is_watching_content']);
    $form['table_wrapper']['pager'] = $this->buildPager($response['total'], $response['per_page']);

    // Attaches necessary JS library to run single-page app.
    $form['#attached']['library'][] = 'mass_feedback_loop/mass-feedback-author-interface';

    // Adds sorting information to drupalSettings.
    $form['#attached']['drupalSettings']['massFeedbackLoop']['sortingVariants'] = MassFeedbackLoopContentFetcher::SORTING_VARIANTS;

    return $form;
  }

  /**
   * Helper function to build tag list.
   *
   * @param array $tags
   *   Array of all existing tags in human-readable format, keyed by ID.
   *
   * @return array
   *   Render array.
   */
  protected function buildSelectTagList(array $tags) {
    // Builds list of tags.
    $tag_select_list = ['' => $this->t('- Select a tag -')] + $tags;

    // Returns render array for select list of tags.
    return [
      '#type' => 'select',
      '#title' => $this->t('Filter by tag'),
      '#options' => $tag_select_list,
      '#ajax' => [
        'callback' => [$this, 'rebuildFeedbackTable'],
        'event' => 'change',
        'wrapper' => 'table-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating feedback…'),
        ],
      ],
    ];
  }

  /**
   * Helper function to build feedback table.
   *
   * @param array $results
   *   Array of feedback data from external API.
   * @param array $all_tags
   *   Array of all existing tags in human-readable format, keyed by ID.
   * @param bool $is_watching_content
   *   Boolean to check whether user is currently watching content.
   *
   * @return array
   *   Render array.
   */
  protected function buildFeedbackTable(array $results, array $all_tags, $is_watching_content = TRUE) {
    // Builds base table.
    $table = [
      '#type' => 'table',
      '#header' => [
        [
          'data' => [
            '#markup' => $this->t('Date') . '<span data-sort-by="submit_date" />',
          ],
        ],
        [
          'data' => [
            '#markup' => $this->t('Did You Find?') . '<span data-sort-by="info_found" />',
          ],
        ],
        [
          'data' => [
            '#markup' => $this->t('Source Page') . '<span data-sort-by="source_page" />',
          ],
        ],
        [
          'data' => [
            '#markup' => $this->t('Feedback Text'),
          ],
        ],
        [
          'data' => [
            '#markup' => $this->t('Tags'),
          ],
        ],
        // Adds an additional empty column to match results column count.
        // Needed for the 'Add Tag' results column.
        [],
      ],
      // Links to Watched Content dashboard, if user is not watching content.
      '#empty' => ($is_watching_content) ? $this->t('No feedback available.') : Link::createFromRoute($this->t('You must be watching content to view related feedback.'), 'view.watched_content.page')->toRenderable(),
      '#responsive' => TRUE,
      '#attributes' => [
        'id' => 'feedback-table',
      ],
    ];

    // Builds table rows from feedback.
    if (!empty($results)) {
      foreach ($results as $key => $feedback) {
        if (!empty($feedback)) {
          // Builds "Date".
          $date = new DrupalDateTime($feedback['submit_date']);
          $formatted_date = $date->format('n/j/Y');
          $table[$key]['submit_date'] = [
            '#markup' => $formatted_date,
          ];

          // Builds "Did You Find?".
          $info_found = (!empty($feedback['info_found']) && $feedback['info_found']) ? $this->t('Yes') : $this->t('No');
          $table[$key]['info_found'] = [
            '#markup' => $info_found,
          ];

          // Builds "Source Page".
          // Uses data stored in drupalSettings object on initial page load.
          // @see \Drupal\mass_feedback_loop\Form\MassFeedbackLoopAuthorInterfaceForm
          $source_page = (!empty($feedback['node_id'])) ? $this->entityTypeManager->getStorage('node')
            ->load($feedback['node_id'])
            ->toLink()
            ->toString() : '';
          $table[$key]['source_page'] = [
            '#markup' => $source_page,
          ];

          // Builds "Feedback Text".
          $feedback_text = (!empty($feedback['text'])) ? $feedback['text'] : '';
          $table[$key]['text'] = [
            '#markup' => Html::escape($feedback_text),
          ];

          // Builds Tags section.
          $feedback_tags = [];
          if (!empty($tags = $feedback['tags'])) {
            foreach ($tags as $tag) {
              // Creates link to Remove Tag form with necessary data as arguments.
              $url = Url::fromRoute(
                'mass_feedback_loop.open_modal_tag_form',
                [
                  'action' => 'remove',
                  'comment_id' => $feedback['id'],
                  'tag_id' => $tag['tag_id'],
                  'tag_unique_id' => $tag['id'],
                ],
                [
                  'attributes' => [
                    'class' => [
                      'link-open-modal-remove-tag',
                      'use-ajax',
                    ],
                    'data-dialog-type' => 'modal',
                    'title' => $this->t('Remove tag'),
                  ],
                ]
              );
              $link = Link::fromTextAndUrl($this->t('Remove tag'), $url)->toString();
              $feedback_tags[] = [
                '#prefix' => '<div class="button">',
                '#markup' => $all_tags[$tag['tag_id']] . ' ' . $link,
                '#suffix' => '</div>',
              ];
            }
          }

          // Creates item list to render tag-related content.
          $table[$key]['tags'] = [
            '#type' => 'markup',
            '#theme' => 'item_list',
            '#list_type' => 'ul',
            '#attributes' => [
              'id' => 'feedback-tags-list',
            ],
            '#empty' => $this->t('Not tagged'),
            '#items' => $feedback_tags,
          ];

          // Creates link for adding a tag.
          $table[$key]['add_tag'] = [
            '#type' => 'link',
            '#title' => $this->t('Add tag'),
            // Creates link to Add Tag form with necessary data as arguments.
            '#url' => Url::fromRoute('mass_feedback_loop.open_modal_tag_form', [
              'action' => 'add',
              'comment_id' => $feedback['id'],
            ]),
            '#attributes' => [
              'class' => [
                'link-open-modal-add-tag',
                'use-ajax',
                'button',
              ],
              'data-dialog-type' => 'modal',
              'title' => $this->t('Add tag'),
            ],
          ];
        }
      }
    }

    // Returns table render array with feedback data.
    return $table;
  }

  /**
   * Helper function to build pager for feedback table.
   *
   * @param int $total
   *   The total number of items to be paged.
   * @param int $limit
   *   The number of items the calling code will display per page.
   * @param array $parameters
   *   Optional parameters to use within pager links to preserve form values.
   *
   * @return array
   *   Render array.
   */
  protected function buildPager($total, $limit, array $parameters = []) {
    // Initializes pager based on feedback response.
    pager_default_initialize($total, $limit);

    // Returns pager render array, with parameters if available.
    return [
      '#type' => 'pager',
      '#tags' => [
        $this->t('First'),
        $this->t('Previous'),
        NULL,
        $this->t('Next'),
        $this->t('Last'),
      ],
      '#parameters' => $parameters,
    ];
  }

  /**
   * Custom AJAX callback to rebuild feedback table.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return array
   *   Render array.
   */
  public function rebuildFeedbackTable(array &$form, FormStateInterface $form_state) {
    // Gets current form values to be preserved during table rebuild.
    $form_values = $form_state->getValues();
    // Specifies the relevant form value keys.
    $feedback_option_keys = [
      'filter_by_page',
      'filter_by_tag',
      'sort_by',
      'filter_by_info_found',
      // NOTE: Skips 'page', because pager is being recalculated as well.
    ];
    $feedback_options = array_intersect_key($form_values, array_flip($feedback_option_keys));
    // Fetches feedback.
    $response = $this->contentFetcher->fetchFeedback($feedback_options);
    // Fetches tags.
    $tags = $this->contentFetcher->fetchAllTags();

    // Builds table, pager, and surrounding container.
    $table_wrapper = [
      '#type' => 'container',
      '#prefix' => '<div id="table-wrapper">',
      '#suffix' => '</div>',
    ];
    $table_wrapper['feedback_table'] = $this->buildFeedbackTable($response['results'], $tags, $response['is_watching_content']);
    // Passes feedback options to pager to preserve form values in pager links.
    $table_wrapper['pager'] = $this->buildPager($response['total'], $response['per_page'], $feedback_options);

    return $table_wrapper;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Form never needs to be submitted.
  }

}
