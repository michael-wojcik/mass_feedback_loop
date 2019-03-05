/**
 * @file
 * Mass Feedback Loop custom JS.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.theme.throbber = () => '<div class="ajax-progress ajax-progress-throbber"><div class="throbber">&nbsp;</div></div>';

  Drupal.theme.fullscreenProgress = () => '<div class="ajax-progress ajax-progress-fullscreen">&nbsp;</div>';

  Drupal.theme.addRow = (tableID, rowData) => {
    // Gets a reference to the table
    const tableRef = document.getElementById(tableID);
    // Inserts a row in the table.
    const newRow = tableRef.insertRow();
    // Gets length of table row.
    const numCol = tableRef.rows[0].cells.length;
    // Adds new cells.
    //rowData.map((cellData) => $(newRow.insertCell()).append(cellData));
    for (let key in rowData) {
      $(newRow.insertCell()).append(rowData[key]);
    }
    /*
    for (let i = 0; i < numCol; i++) {
      // Insert a cell in the row.
      //let newCell = newRow.insertCell();

      // Gets date.

      $(newRow.insertCell()).append(rowData.feedback.submit_date);
      // Append a text node to the cell
      //let newText = document.createTextNode('New top row');
      //newCell.appendChild(newText);
    }
    */
  };

  function refreshFeedbackTable($table) {
    // Clears old rows.
    $table.find('tr:not(:first)').remove();
    // Adds progress indicator while data is fetched.
    $table.after($(Drupal.theme('fullscreenProgress')).attr('id', 'mass_feedback_loop_fullscreen_progress'));
    // Fetches data.
    $.getJSON('http://mass.local/modules/custom/mass_feedback_loop/js/ajax/sample_get.json', (data) => {
      // Builds table rows.
      data.forEach((item) => {
        const feedback = item.feedback;
        const tags = item.tags;
        const rowData = {};

        // Builds "Date".
        const date = new Date(feedback.submit_date);
        rowData.submit_date = `${date.getMonth() + 1}/${date.getDate()}/${date.getFullYear()}`;

        // Builds "Did You Find?".
        rowData.info_found = feedback.info_found ? 'Yes' : 'No';

        // Builds "Source Page".
        // Uses data stored in drupalSettings object on initial page load.
        // @see \Drupal\mass_feedback_loop\Form\MassFeedbackLoopAuthorInterfaceForm
        rowData.sourcePage = drupalSettings.massFeedbackLoop.flaggedContent[feedback.node_id].link;

        // Builds "Feedback Text".
        rowData.text = feedback.text;

        // Builds "Feedback Text".
        rowData.tags = '';

        // Adds row.
        Drupal.theme('addRow', $table.selector.slice(1), rowData);
      });
      // Removes progress indicator after rebuilding table.
      $('#mass_feedback_loop_fullscreen_progress').remove();
    });
  }

  Drupal.behaviors.massFeedbackLoop = {
    attach: function (context, settings) {
      //$('select').combobox();

      const $filterByPage = $('#mass-feedback-loop-filter-by-page', context);
      const $filterByTag = $('#mass-feedback-loop-filter-by-tag', context);
      const $table = $('#mass-feedback-loop-table', context);

      // Adds <select> options based on existing feedback tags.
      $filterByTag.once('massFeedbackLoop').each(() => {
        const tags = drupalSettings.massFeedbackLoop.feedbackTags;
        for (let prop in tags) {
          if (tags.hasOwnProperty(prop)) {
            //$filterByTag.append(new Option(tags[prop], prop));
          }
        }
      });

      $filterByPage.on('autocompleteclose', (e) => {
        // Refreshes tabular data.
        //refreshFeedbackTable($table);
      });

      $filterByTag.on('change', (e) => {
        //console.log($filterByTag.val());
        //console.log($(this));
      });
    }
  };
})(jQuery, Drupal);
