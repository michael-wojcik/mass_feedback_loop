<?php

/**
 * @file
 * Custom Views-related code for Mass Feedback Loop module.
 */

/**
 * Implements hook_views_data().
 */
function mass_feedback_loop_views_data() {
  $data = [];
  // Base data.
  $data['mass_feedback_loop_api']['table']['group'] = t('Fitbit profile');
  $data['mass_feedback_loop_api']['table']['base'] = [
    'title' => t('Fitbit profile'),
    'help' => t('Fitbit profile data provided by the Fitbit API\'s User Profile endpoint.'),
    'query_id' => 'fitbit',
  ];
  return $data;
}
