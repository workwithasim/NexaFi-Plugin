/**
 * NexaFi Admin Dashboard JavaScript
 *
 * Modal handling, AJAX details view, quick status updates,
 * representative management, and policy confirmation guards.
 *
 * @package NexaFi_Financing
 */

jQuery(document).ready(function ($) {
  'use strict';

  var config = window.nexafi_admin_obj || {};

  // =========================================================================
  // 1. Application Detail Modal Handling
  // =========================================================================
  var $detailModal = $('#nexafi-detail-modal');
  var $modalContent = $('#nexafi-modal-content');
  var $modalRef = $('#nexafi-modal-ref');

  $(document).on('click', '.nexafi-view-detail-btn, .nexafi-view-detail-link', function (e) {
    e.preventDefault();
    var appId = $(this).data('app-id');
    if (!appId) return;

    $modalRef.text('Loading...');
    $modalContent.html('<div class="nexafi-modal-spinner"><span class="spinner is-active"></span> Loading application details...</div>');
    $detailModal.fadeIn(200);

    $.ajax({
      url: config.ajax_url,
      type: 'GET',
      data: {
        action: 'nexafi_admin_get_app_details',
        security: config.nonce,
        app_id: appId,
      },
      success: function (res) {
        if (res.success && res.data) {
          $modalRef.text(res.data.reference || '');
          $modalContent.html(res.data.html || '');
        } else {
          $modalContent.html('<p class="notice notice-error" style="padding:12px;">Failed to load record details.</p>');
        }
      },
      error: function () {
        $modalContent.html('<p class="notice notice-error" style="padding:12px;">Server communication error.</p>');
      },
    });
  });

  // Close modals
  $(document).on('click', '.nexafi-modal-close, .nexafi-modal-close-btn, .nexafi-modal-backdrop', function () {
    $('.nexafi-modal').fadeOut(180);
  });

  $(document).keyup(function (e) {
    if (e.key === 'Escape') {
      $('.nexafi-modal').fadeOut(180);
    }
  });

  // =========================================================================
  // 2. Quick Status Dropdown Update via AJAX
  // =========================================================================
  $(document).on('change', '.nexafi-quick-status-select', function () {
    var $select = $(this);
    var appId = $select.data('app-id');
    var newStatus = $select.val();

    $select.css({ opacity: '0.5' });

    $.ajax({
      url: config.ajax_url,
      type: 'POST',
      data: {
        action: 'nexafi_admin_update_app_status',
        security: config.nonce,
        app_id: appId,
        status: newStatus,
      },
      success: function (res) {
        $select.css({ opacity: '1', backgroundColor: '#ecfdf5', borderColor: '#10b981' });
        setTimeout(function () {
          $select.css({ backgroundColor: '', borderColor: '' });
        }, 1200);
      },
      error: function () {
        $select.css({ opacity: '1', borderColor: '#ef4444' });
        alert('Could not update status. Please try again.');
      },
    });
  });

  // =========================================================================
  // 3. Representative Add / Edit Modal & Photo Uploader
  // =========================================================================
  var $repModal = $('#nexafi-rep-modal');
  var repMediaFrame = null;

  function setRepPhoto(url) {
    if (url && typeof url === 'string' && url.trim() !== '') {
      $('#rep_field_photo').val(url.trim());
      $('#rep_photo_preview_img').attr('src', url.trim()).show();
      $('#rep_photo_placeholder').hide();
      $('#nexafi-upload-btn-text').text('Change Photo');
    } else {
      $('#rep_field_photo').val('');
      $('#rep_photo_preview_img').attr('src', '').hide();
      $('#rep_photo_placeholder').show();
      $('#nexafi-upload-btn-text').text('Upload Photo');
    }
  }

  // Upload Photo button handler
  $('#nexafi-upload-photo-btn').on('click', function (e) {
    e.preventDefault();
    if (repMediaFrame) {
      repMediaFrame.open();
      return;
    }

    if (typeof wp !== 'undefined' && wp.media) {
      repMediaFrame = wp.media({
        title: 'Select Representative Photo',
        button: { text: 'Use this photo' },
        multiple: false,
        library: { type: 'image' },
      });

      repMediaFrame.on('select', function () {
        var attachment = repMediaFrame.state().get('selection').first().toJSON();
        if (attachment && attachment.url) {
          setRepPhoto(attachment.url);
        }
      });

      repMediaFrame.open();
    } else {
      alert('WordPress Media Library could not be loaded.');
    }
  });

  // Delete / Remove photo button handler
  $('#nexafi-remove-photo-btn').on('click', function (e) {
    e.preventDefault();
    setRepPhoto('');
  });

  $('#nexafi-add-rep-btn').on('click', function () {
    $('#rep-modal-title').text('Add Representative');
    $('#rep_db_id').val('0');
    $('#rep_field_id').val('').prop('readonly', false);
    $('#rep_field_name').val('');
    $('#rep_field_email').val('');
    $('#rep_field_phone').val('');
    setRepPhoto('');
    $('#rep_field_notes').val('');
    $('#rep_field_active').prop('checked', true);
    $('#nexafi-modal-delete-rep-link').hide().attr('href', '#');
    $repModal.fadeIn(200);
  });

  $(document).on('click', '.nexafi-edit-rep-btn', function () {
    var $btn = $(this);
    var deleteUrl = $btn.closest('tr').find('.nexafi-delete-rep-btn').attr('href');
    $('#rep-modal-title').text('Edit Representative');
    $('#rep_db_id').val($btn.data('id'));
    $('#rep_field_id').val($btn.data('rep-id')).prop('readonly', false);
    $('#rep_field_name').val($btn.data('name'));
    $('#rep_field_email').val($btn.data('email'));
    $('#rep_field_phone').val($btn.data('phone'));
    setRepPhoto($btn.data('photo') || '');
    $('#rep_field_notes').val($btn.data('notes'));
    $('#rep_field_active').prop('checked', parseInt($btn.data('active'), 10) === 1);
    if (deleteUrl) {
      $('#nexafi-modal-delete-rep-link').attr('href', deleteUrl).show();
    } else {
      $('#nexafi-modal-delete-rep-link').hide();
    }
    $repModal.fadeIn(200);
  });

  // Toggle active status via AJAX
  $(document).on('click', '.nexafi-toggle-rep-btn', function () {
    var $btn = $(this);
    var repId = $btn.data('id');
    var isCurrentlyActive = parseInt($btn.data('active'), 10) === 1;
    var newActive = isCurrentlyActive ? 0 : 1;

    $btn.prop('disabled', true).text('Updating...');

    $.ajax({
      url: config.ajax_url,
      type: 'POST',
      data: {
        action: 'nexafi_admin_toggle_rep',
        security: config.nonce,
        id: repId,
        active: newActive,
      },
      success: function (res) {
        if (res.success) {
          location.reload();
        } else {
          alert('Could not update representative state.');
          $btn.prop('disabled', false).text(isCurrentlyActive ? 'Deactivate' : 'Activate');
        }
      },
      error: function () {
        alert('Server communication error.');
        $btn.prop('disabled', false).text(isCurrentlyActive ? 'Deactivate' : 'Activate');
      },
    });
  });

  // =========================================================================
  // 4. Settings Policy Authorization Guard
  // =========================================================================
  $('input[name="nexafi_decision_mode"]').on('change', function () {
    var mode = $(this).val();
    var $box = $('#nexafi-authorization-confirm-box');
    if (mode === 'final_authorized') {
      $box.slideDown(200);
    } else {
      $box.slideUp(200);
    }
  });

  // =========================================================================
  // 5. Email Logs: Resend Confirmation Email
  // =========================================================================
  $(document).on('click', '.nexafi-resend-email-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var logId = $btn.data('log-id');
    var appId = $btn.data('app-id');
    var originalHtml = $btn.html();

    if ($btn.prop('disabled')) return;

    $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;visibility:visible;"></span> Sending...');

    $.ajax({
      url: config.ajax_url,
      type: 'POST',
      data: {
        action: 'nexafi_admin_resend_email',
        security: config.nonce,
        log_id: logId || 0,
        app_id: appId || 0,
      },
      success: function (res) {
        $btn.prop('disabled', false).html(originalHtml);

        var msg = (res && res.data && res.data.message) ? res.data.message : 'Email operation finished.';
        var isSuccess = !!(res && res.success);

        // Update notice banner if on Email Logs page
        var $noticeBox = $('#nexafi-resend-notice-box');
        var $noticeText = $('#nexafi-resend-notice-text');
        if ($noticeBox.length) {
          $noticeBox.removeClass('notice-success notice-error')
                    .addClass(isSuccess ? 'notice-success' : 'notice-error')
                    .show();
          $noticeText.text(msg);
          $('html, body').animate({ scrollTop: $noticeBox.offset().top - 40 }, 200);
        }

        // Update modal notice if inside detail modal
        var $modalNotice = $('#nexafi-modal-email-notice');
        if ($modalNotice.length) {
          $modalNotice.css({
            background: isSuccess ? '#dcfce7' : '#fee2e2',
            color: isSuccess ? '#15803d' : '#b91c1c',
            border: isSuccess ? '1px solid #86efac' : '1px solid #fca5a5'
          }).text(msg).slideDown(200);
        }

        // Update table row if on log page
        if (logId) {
          var $row = $('#nexafi-log-row-' + logId);
          if ($row.length && isSuccess) {
            $row.find('.nexafi-log-status-cell').html('<span class="nexafi-badge nexafi-badge-success" style="background:#dcfce7;color:#15803d;padding:4px 8px;border-radius:4px;font-weight:700;font-size:11px;display:inline-block;">&#10003; Sent</span>');
            $row.find('.nexafi-log-error-cell').html('<span style="color:#15803d;font-size:12px;">Resent successfully</span>');
          }
        }
      },
      error: function () {
        $btn.prop('disabled', false).html(originalHtml);
        alert('Server communication error while trying to resend email.');
      }
    });
  });

  // =========================================================================
  // 6. Email Logs: Delete Email Log from Database
  // =========================================================================
  $(document).on('click', '.nexafi-delete-email-log-btn', function (e) {
    var $btn = $(this);
    var logId = $btn.data('log-id');
    var nonce = $btn.data('nonce');
    var href = $btn.attr('href');

    if (!confirm('Are you sure you want to delete this log entry?')) {
      e.preventDefault();
      return false;
    }

    if (!logId || !nonce) {
      // Let standard link navigation proceed if no data-log-id
      return true;
    }

    e.preventDefault();

    var $row = $('#nexafi-log-row-' + logId);
    $row.css({ opacity: '0.4' });

    $.ajax({
      url: config.ajax_url,
      type: 'POST',
      data: {
        action: 'nexafi_admin_delete_email_log',
        security: nonce,
        log_id: logId,
      },
      success: function (res) {
        if (res.success) {
          $row.css({ backgroundColor: '#fee2e2' }).fadeOut(300, function () {
            $(this).remove();

            // Decrement badge count
            var $badge = $('.nexafi-admin-badge-count');
            if ($badge.length) {
              var countMatch = $badge.text().match(/\d+/);
              if (countMatch) {
                var newCount = Math.max(0, parseInt(countMatch[0], 10) - 1);
                $badge.text(newCount + ' logs');
              }
            }

            // If no more rows, show empty state row
            var $tbody = $('table.wp-list-table tbody');
            if ($tbody.length && $tbody.find('tr').length === 0) {
              $tbody.html('<tr><td colspan="8" style="text-align:center;padding:24px;color:#64748b;">No email records found.</td></tr>');
            }
          });
        } else {
          // If AJAX failed on server, fallback to standard link
          window.location.href = href;
        }
      },
      error: function () {
        // Fallback to standard HTTP GET delete
        window.location.href = href;
      }
    });
  });
});
