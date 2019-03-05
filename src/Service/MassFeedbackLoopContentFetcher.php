<?php

namespace Drupal\mass_feedback_loop\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Service class for interacting with external Mass.gov API.
 */
class MassFeedbackLoopContentFetcher {

  /**
   * Possible variants of "Sort by" behavior to be used in feedback table.
   *
   * Stored as a public constant. Also used in
   * \Drupal\mass_feedback_loop\Form\MassFeedbackLoopAuthorInterfaceForm.
   */
  const SORTING_VARIANTS = [
    0 => [
      'order_by' => 'submit_date',
      'desc' => TRUE,
    ],
    1 => [
      'order_by' => 'submit_date',
      'desc' => FALSE,
    ],
  ];

  /**
   * Static, non-sensitive configuration for making external API requests.
   */
  const EXTERNAL_API_CONFIG = [
    'api_endpoints' => [
      'feedback_endpoint' => 'feedback/',
      'tags_endpoint' => 'tags/',
      'tag_lookup_endpoint' => 'tag_lookup/',
    ],
    'api_headers' => [
      'content_type_header' => 'application/json',
      'referer_header' => 'edit.mass.gov',
    ],
  ];

  /**
   * Current user's account.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Current active database's master connection.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * Array of required read-only configuration for external API connection.
   *
   * @var array
   */
  protected $settings;

  /**
   * Config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * A Guzzle HTTP client instance.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Custom logger channel for mass_feedback_loop module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AccountProxy $current_user,
    Connection $database,
    Settings $settings,
    ConfigFactoryInterface $config_factory,
    ClientFactory $http_client_factory,
    LoggerInterface $logger
  ) {
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->settings = $settings->get('mass_feedback_loop')['external_api_config'];
    $this->config = $config_factory->get('mass_feedback_loop.external_api_config');
    $this->httpClient = $http_client_factory->fromOptions([
      'headers' => [
        'Content-Type' => self::EXTERNAL_API_CONFIG['api_headers']['content_type_header'],
        'Referer' => self::EXTERNAL_API_CONFIG['api_headers']['referer_header'],
        'Authenticate' => $this->settings['authenticate_header'],
      ],
      'base_uri' => $this->settings['api_base_url'],
    ]);
    $this->logger = $logger;
  }

  /**
   * Fetches all flagged content based on a flag ID and a user account.
   *
   * @param string $flag_id
   *   ID of flag created using contrib Flag module.
   * @param \Drupal\Core\Session\AccountProxy|null $account
   *   User account.
   * @param string $title_order
   *   ORDER BY direction for title field in database query: 'ASC' or 'DESC'.
   *
   * @return array
   *   Array of NIDs for content flagged by user.
   */
  public function fetchFlaggedContent($flag_id = 'watch_content', AccountProxy $account = NULL, $title_order = 'ASC') {
    // Uses current user's account if none is provided.
    if (empty($account)) {
      $account = $this->currentUser;
    }
    // Gets NIDs of all content being watched by the user.
    $query = $this->database->query('SELECT f.entity_id FROM {flagging} AS f LEFT JOIN {node_field_data} AS n ON f.entity_id = n.nid WHERE f.flag_id = :flag_id AND f.uid = :uid ORDER BY n.title ' . ($title_order == 'ASC' ? 'ASC' : 'DESC'), [
      ':flag_id' => $flag_id,
      ':uid' => $account->id(),
    ]);
    return $query->fetchCol();
  }

  /**
   * Fetches all tags from Mass.gov API.
   *
   * @return array
   *   Array of all existing tags in human-readable format, keyed by ID.
   */
  public function fetchAllTags() {
    try {
      $request = $this->httpClient->get(self::EXTERNAL_API_CONFIG['api_endpoints']['tag_lookup_endpoint'], [
        'json' => [
          'author_id' => $this->currentUser->id(),
        ],
      ]);
      $response = Json::decode($request->getBody());
      $tags = [];
      foreach ($response as $tag) {
        if (!empty($tag['tag_id']) && !empty($tag['tag_name'])) {
          $tags[$tag['tag_id']] = $tag['tag_name'];
        }
      }
      // Sorts array values alphabetically.
      asort($tags);
      return $tags;
    }
    catch (RequestException $e) {
      $this->handleRequestException($e);
      return [];
    }
  }

  /**
   * Fetches feedback from Mass.gov API.
   *
   * @param array $options
   *   Options for feedback request.
   *   Possible keys: 'filter_by_page', 'filter_by_tag', 'sort_by', 'page'.
   *
   * @return array
   *   Decoded JSON data.
   */
  public function fetchFeedback(array $options = []) {
    // Gets NIDs for fetching corresponding feedback.
    $nids = [];
    if (!empty($options['filter_by_page'])) {
      $nids[] = $options['filter_by_page'];
    }
    else {
      $nids = $this->fetchFlaggedContent();
    }

    if (empty($nids)) {
      // Don't fetch anything, if no NIDs are given.
      return [
        'results' => [],
        'total' => 0,
        'per_page' => $this->config->get('per_page'),
        // User is not watching content.
        'is_watching_content' => FALSE,
      ];
    }
    else {
      // Fetches feedback from external API.
      try {
        // Looks up any sorting options, if available.
        if (!empty($options['sort_by'])) {
          $order_by = self::SORTING_VARIANTS[$options['sort_by']]['order_by'];
          $desc = self::SORTING_VARIANTS[$options['sort_by']]['desc'];
        }
        // Provides default sorting variant, if none is specified.
        else {
          $order_by = 'submit_date';
          $desc = 'true';
        }

        // Looks up filter_by_info_found option.
        $info_found = NULL;
        if (isset($options['filter_by_info_found'])) {
          if ($options['filter_by_info_found'] == 1) {
            $info_found = 'true';
          }
          elseif ($options['filter_by_info_found'] == 0) {
            $info_found = 'false';
          }
        }

        $request = $this->httpClient->get(self::EXTERNAL_API_CONFIG['api_endpoints']['feedback_endpoint'], [
          'json' => [
            'node_id' => $nids,
            // Filters by tag, if available.
            'tag_id' => (!empty($options['filter_by_tag'])) ? $options['filter_by_tag'] : NULL,
            // Sorts results, if order is specified.
            'order_by' => $order_by,
            'desc' => $desc,
            // Filters by info_found, if available.
            'info_found' => $info_found,
            // Logs current user ID as the requester.
            'author_id' => $this->currentUser->id(),
            // Per-page amount is set in config.
            'per_page' => $this->config->get('per_page'),
            // Page needs to be incremented by 1.
            // Drupal indexes starting at 0, whereas API indexes starting at 1.
            'page' => (!empty($options['page'])) ? $options['page'] + 1 : 1,
          ],
        ]);
        return Json::decode($request->getBody()) + [
          'per_page' => $this->config->get('per_page'),
          // User is watching content.
          'is_watching_content' => TRUE,
        ];
      }
      catch (RequestException $e) {
        $this->handleRequestException($e);
        return [];
      }
    }
  }

  /**
   * Adds a tag to a piece of feedback via the external API.
   *
   * @param int $comment_id
   *   Comment ID number.
   * @param int $tag_id
   *   Tag ID number.
   */
  public function addTag($comment_id, $tag_id) {
    try {
      $this->httpClient->post(self::EXTERNAL_API_CONFIG['api_endpoints']['tags_endpoint'], [
        'json' => [
          'comment_id' => $comment_id,
          'tag_id' => $tag_id,
          'author_id' => $this->currentUser->id(),
        ],
      ]);
    }
    catch (RequestException $e) {
      $this->handleRequestException($e);
    }
  }

  /**
   * Removes a tag from a piece of feedback via the external API.
   *
   * @param int $comment_id
   *   Comment ID number.
   * @param int $tag_id
   *   Tag ID number.
   * @param int $tag_unique_id
   *   Unique tag ID number (generated on a per-feedback basis).
   */
  public function removeTag($comment_id, $tag_id, $tag_unique_id) {
    try {
      $this->httpClient->delete(self::EXTERNAL_API_CONFIG['api_endpoints']['tags_endpoint'], [
        'json' => [
          'comment_id' => $comment_id,
          'tag_id' => $tag_id,
          'id' => $tag_unique_id,
          'author_id' => $this->currentUser->id(),
        ],
      ]);
    }
    catch (RequestException $e) {
      $this->handleRequestException($e);
    }
  }

  /**
   * Custom exception handler for making requests to external API.
   *
   * @param \GuzzleHttp\Exception\RequestException $e
   *   Exception object.
   */
  protected function handleRequestException(RequestException $e) {
    // Throws error in case of httpClient request failure.
    $this->logger->error($e->getRequest()->getMethod() . ' ' . $e->getRequest()->getUri() . ':<br/>' . $e->getResponse()->getBody());
    throw new NotFoundHttpException();
  }

}
