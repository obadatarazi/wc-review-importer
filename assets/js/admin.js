/* global wcri, jQuery */
(function ($) {
  'use strict';

  var $notices = $('#wcri-notices');
  var strings  = (wcri && wcri.strings) ? wcri.strings : {};

  function t(key, fallback) {
    return strings[key] || fallback;
  }

  function scrollToNotices() {
    if ($notices.length) {
      $('html, body').animate({ scrollTop: $notices.offset().top - 40 }, 200);
    }
  }

  function showNotice(html) {
    if (!$notices.length) {
      return;
    }
    $notices.html(html);
    scrollToNotices();
  }

  function prependNotice(html) {
    if (!$notices.length) {
      return;
    }
    $notices.prepend(html);
    scrollToNotices();
  }

  function setFormLoading($form, loading, $btn, loadingLabel, defaultHtml) {
    $form.toggleClass('wcri-form--loading', loading);
    if ($btn && $btn.length) {
      $btn.prop('disabled', loading);
      if (loading) {
        $btn.addClass('is-loading').html('<span class="wcri-btn-icon">⏳</span> ' + loadingLabel);
      } else {
        $btn.removeClass('is-loading').html(defaultHtml);
      }
    }
  }

  function ajaxRequest(action, formData, $form, $btn, loadingLabel, defaultHtml) {
    formData.append('action', action);
    formData.append('nonce', wcri.nonce);

    setFormLoading($form, true, $btn, loadingLabel, defaultHtml);

    return $.ajax({
      url: wcri.ajax_url,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false
    }).always(function () {
      setFormLoading($form, false, $btn, loadingLabel, defaultHtml);
    });
  }

  /* ── Drop-zone ─────────────────────────────────── */
  var $zone   = $('#wcri-dropzone');
  var $input  = $('#wcri-file-input');
  var $label  = $('#wcri-file-name');

  if ($zone.length) {
    $zone.on('dragover dragenter', function (e) {
      e.preventDefault();
      $zone.addClass('wcri-drop-hover');
    });

    $zone.on('dragleave drop', function () {
      $zone.removeClass('wcri-drop-hover');
    });

    $zone.on('drop', function (e) {
      e.preventDefault();
      var files = e.originalEvent.dataTransfer.files;
      if (files.length) {
        $input[0].files = files;
        showFileName(files[0].name);
      }
    });

    $input.on('change', function () {
      if (this.files.length) {
        showFileName(this.files[0].name);
      }
    });

    function showFileName(name) {
      $label.text('📄 ' + name).addClass('visible');
      $zone.find('.wcri-dropzone-icon').text('✅');
    }
  }

  /* ── Star picker ───────────────────────────────── */
  var $picker = $('#wcri-star-picker');
  if ($picker.length) {
    var $stars  = $picker.find('.wcri-star');
    var $hidden = $('#wcri-rating-input');
    var current = parseInt($hidden.val(), 10) || 5;

    setStars(current);

    $stars.on('click', function () {
      current = parseInt($(this).data('value'), 10);
      $hidden.val(current);
      setStars(current);
    });

    $stars.on('mouseenter', function () {
      highlightStars(parseInt($(this).data('value'), 10));
    });

    $picker.on('mouseleave', function () {
      setStars(current);
    });

    function setStars(val) {
      $stars.each(function () {
        var starVal = parseInt($(this).data('value'), 10);
        $(this).toggleClass('active', starVal <= val);
      });
    }

    function highlightStars(val) {
      $stars.each(function () {
        var starVal = parseInt($(this).data('value'), 10);
        $(this).toggleClass('active', starVal <= val);
      });
    }
  }

  /* ── Avatar preview ────────────────────────────── */
  var $avatarInput   = $('#wcri-avatar-input');
  var $avatarPreview = $('#wcri-avatar-preview');

  if ($avatarInput.length && $avatarPreview.length) {
    $avatarInput.on('change', function () {
      var file = this.files && this.files[0];
      if (!file) {
        $avatarPreview.empty().prop('hidden', true);
        return;
      }
      var reader = new FileReader();
      reader.onload = function (e) {
        $avatarPreview
          .html('<img src="' + e.target.result + '" alt="">')
          .prop('hidden', false);
      };
      reader.readAsDataURL(file);
    });
  }

  function resetAddForm($form) {
    $form[0].reset();
    $('#wcri-rating-input').val(5);
    if ($picker.length) {
      $picker.find('.wcri-star').each(function () {
        var starVal = parseInt($(this).data('value'), 10);
        $(this).toggleClass('active', starVal <= 5);
      });
    }
    var $select = $form.find('.wc-product-search');
    if ($select.length) {
      $select.val(null).trigger('change');
    }
    $avatarPreview.empty().prop('hidden', true);
  }

  /* ── Add review (AJAX) ─────────────────────────── */
  var $addForm = $('#wcri-add-form');
  if ($addForm.length) {
    var $addBtn     = $addForm.find('.wcri-btn--primary');
    var addBtnHtml  = $addBtn.html();

    $addForm.on('submit', function (e) {
      e.preventDefault();

      var $select = $addForm.find('.wc-product-search');
      var ids     = $select.val();
      if (!ids || !ids.length) {
        window.alert(t('select_product', 'Please select at least one product.'));
        return;
      }

      var name = $.trim($addForm.find('[name="reviewer_name"]').val());
      if (!name) {
        window.alert(t('name_required', 'Please enter a reviewer name.'));
        return;
      }

      var formData = new FormData($addForm[0]);

      ajaxRequest('wcri_add_single', formData, $addForm, $addBtn, t('saving', 'Saving…'), addBtnHtml)
        .done(function (response) {
          if (!response || !response.success) {
            var msg = (response && response.data && response.data.message)
              ? response.data.message
              : t('error_generic', 'Something went wrong. Please try again.');
            prependNotice('<div class="wcri-notice wcri-notice--warning"><p>' + msg + '</p></div>');
            return;
          }

          var html = response.data.html || '';
          if (html) {
            showNotice(html);
          } else {
            prependNotice('<div class="wcri-notice wcri-notice--success"><p>✅ ' + (response.data.message || t('review_saved', 'Review saved successfully.')) + '</p></div>');
          }

          resetAddForm($addForm);
        })
        .fail(function () {
          prependNotice('<div class="wcri-notice wcri-notice--warning"><p>' + t('error_generic', 'Something went wrong. Please try again.') + '</p></div>');
        });
    });
  }

  /* ── Bulk import (AJAX) ────────────────────────── */
  var $importForm = $('#wcri-import-form');
  if ($importForm.length) {
    var $importBtn    = $importForm.find('.wcri-btn--primary');
    var importBtnHtml = $importBtn.html();

    $importForm.on('submit', function (e) {
      e.preventDefault();

      var fileInput = $importForm.find('#wcri-file-input')[0];
      if (!fileInput || !fileInput.files.length) {
        window.alert(t('file_required', 'Please choose a CSV or XLSX file.'));
        return;
      }

      var formData = new FormData($importForm[0]);

      ajaxRequest('wcri_import', formData, $importForm, $importBtn, t('importing', 'Importing…'), importBtnHtml)
        .done(function (response) {
          if (!response || !response.success) {
            var msg = (response && response.data && response.data.message)
              ? response.data.message
              : t('error_generic', 'Something went wrong. Please try again.');
            prependNotice('<div class="wcri-notice wcri-notice--warning"><p>' + msg + '</p></div>');
            return;
          }

          if (response.data.html) {
            showNotice(response.data.html);
          }
        })
        .fail(function () {
          prependNotice('<div class="wcri-notice wcri-notice--warning"><p>' + t('error_generic', 'Something went wrong. Please try again.') + '</p></div>');
        });
    });
  }

  /* ── Delete review (AJAX) ──────────────────────── */
  $(document).on('click', '.wcri-delete-btn', function () {
    var $btn = $(this);
    var id   = $btn.data('review-id');

    if (!window.confirm(t('confirm_delete', 'Delete this review? This cannot be undone.'))) {
      return;
    }

    $btn.prop('disabled', true).text('…');

    $.post(wcri.ajax_url, {
      action: 'wcri_delete_review',
      nonce: wcri.nonce,
      review_id: id
    })
      .done(function (response) {
        if (response && response.success) {
          $btn.closest('tr').fadeOut(200, function () {
            $(this).remove();
          });
          prependNotice('<div class="wcri-notice wcri-notice--success"><p>✅ ' + t('deleted', 'Review deleted.') + '</p></div>');
        } else {
          var msg = (response && response.data && response.data.message)
            ? response.data.message
            : t('error_generic', 'Something went wrong. Please try again.');
          window.alert(msg);
          $btn.prop('disabled', false).text('🗑');
        }
      })
      .fail(function () {
        window.alert(t('error_generic', 'Something went wrong. Please try again.'));
        $btn.prop('disabled', false).text('🗑');
      });
  });

}(jQuery));
