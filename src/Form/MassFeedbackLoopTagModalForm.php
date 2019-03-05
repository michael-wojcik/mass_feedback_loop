<?php

namespace Drupal\mass_feedback_loop\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\mass_feedback_loop\Service\MassFeedbackLoopContentFetcher;

/**
 * Class MassFeedbackLoopTagModalForm.
 */
class MassFeedbackLoopTagModalForm extends FormBase {

  /**
   * Custom service to fetch content used in feedback author interface.
   *
   * @var \Drupal\mass_feedback_loop\Service\MassFeedbackLoopContentFetcher
   */
  protected $contentFetcher;

  /**
   * {@inheritdoc}
   */
  public function __construct(MassFeedbackLoopContentFetcher $content_fetcher) {
    $this->contentFetcher = $content_fetcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mass_feedback_loop.content_fetcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mass_feedback_loop_tag_modal_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    $action = NULL,
    $comment_id = NULL,
    $tag_id = NULL,
    $tag_unique_id = NULL
  ) {
    if ($action == 'add') {
      $this->buildAddTagModalForm($form, $comment_id);
    }
    else {
      $this->buildRemoveTagModalForm($form, $comment_id, $tag_id, $tag_unique_id);
    }

    return $form;
  }

  /**
   * Helper function to build Add Tag modal form.
   *
   * @param array $form
   *   Form array.
   * @param int $comment_id
   *   Comment ID number.
   */
  protected function buildAddTagModalForm(array &$form, $comment_id) {
    // Fetches tags.
    $tags = $this->contentFetcher->fetchAllTags();

    // Builds list of tags.
    $tag_select_list = ['' => $this->t('- Select a tag -')] + $tags;

    $form['select_tag'] = [
      '#type' => 'select',
      '#title' => $this->t('Select a tag'),
      '#options' => $tag_select_list,
      '#required' => TRUE,
    ];
    $form['add_tag'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add tag'),
      '#attributes' => [
        'class' => [
          'use-ajax',
          'use-ajax-submit',
        ],
      ],
      '#ajax' => [
        'callback' => [$this, 'submitModalFormAjax'],
        'event' => 'click',
      ],
    ];
    $form['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#ajax' => [
        'callback' => [$this, 'cancelModalFormAjax'],
        'event' => 'click',
      ],
    ];

    // Hidden values needed during form submission.
    $form['action'] = [
      '#type' => 'hidden',
      '#value' => 'add',
    ];
    $form['comment_id'] = [
      '#type' => 'hidden',
      '#value' => $comment_id,
    ];
  }

  /**
   * Helper function to build Remove Tag modal form.
   *
   * @param array $form
   *   Form array.
   * @param int $comment_id
   *   Comment ID number.
   * @param int $tag_id
   *   Tag ID.
   * @param int $tag_unique_id
   *   Tag unique ID.
   */
  protected function buildRemoveTagModalForm(array &$form, $comment_id, $tag_id, $tag_unique_id) {
    $form['text'] = [
      '#markup' => $this->t('Are you sure you want to remove this tag?'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
    $form['remove_tag'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove tag'),
      '#attributes' => [
        'class' => [
          'use-ajax',
          'use-ajax-submit',
        ],
      ],
      '#ajax' => [
        'callback' => [$this, 'submitModalFormAjax'],
        'event' => 'click',
      ],
    ];
    $form['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#ajax' => [
        'callback' => [$this, 'cancelModalFormAjax'],
        'event' => 'click',
      ],
    ];

    // Hidden values needed during form submission.
    $form['action'] = [
      '#type' => 'hidden',
      '#value' => 'remove',
    ];
    $form['comment_id'] = [
      '#type' => 'hidden',
      '#value' => $comment_id,
    ];
    $form['tag_id'] = [
      '#type' => 'hidden',
      '#value' => $tag_id,
    ];
    $form['tag_unique_id'] = [
      '#type' => 'hidden',
      '#value' => $tag_unique_id,
    ];
  }

  /**
   * Custom modal submit function.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AJAX response object.
   */
  public function submitModalFormAjax(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    // Sends any requests to external API.
    $action = $form_state->getValue('action');
    if ($action == 'add') {
      // Adds tag remotely via API.
      $this->contentFetcher->addTag($form_state->getValue('comment_id'), $form_state->getValue('select_tag'));
    }
    elseif ($action == 'remove') {
      // Removes tag remotely via API.
      $this->contentFetcher->removeTag($form_state->getValue('comment_id'), $form_state->getValue('tag_id'), $form_state->getValue('tag_unique_id'));
    }
    // Triggers custom event to be used in mass_feedback_loop.index.js.
    // Custom event reloads page to update results while using URL query params.
    $response->addCommand(new InvokeCommand('html', 'trigger', ['submitModalFormAjax.massFeedbackLoop']));
    // Refreshes results table.
    $response->addCommand(new InvokeCommand('select[name="filter_by_tag"]', 'change'));
    // Closes modal dialog box.
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * Custom modal close function.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AJAX response object.
   */
  public function cancelModalFormAjax(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
