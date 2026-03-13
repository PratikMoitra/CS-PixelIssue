/**
 * CS Meta Sync — Admin JS
 *
 * Handles the "Sync Now" button AJAX interaction.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $button  = $('#cs-meta-sync-now');
        var $spinner = $('#cs-meta-sync-spinner');
        var $result  = $('#cs-meta-sync-result');

        $button.on('click', function (e) {
            e.preventDefault();

            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.removeClass('success error').hide().text('');

            $.post(csMetaSync.ajax_url, {
                action: 'cs_meta_sync_now',
                nonce: csMetaSync.nonce
            })
            .done(function (response) {
                if (response.success && response.data) {
                    var d = response.data;
                    var msg = 'Sync complete — ' + d.total + ' products sent, ' +
                              d.success + ' succeeded, ' + d.errors + ' errors.';
                    if (d.message) {
                        msg += '\n' + d.message;
                    }
                    $result.addClass('success').text(msg).show();
                } else {
                    $result.addClass('error').text(response.data || 'Sync failed.').show();
                }
            })
            .fail(function (xhr) {
                $result.addClass('error').text('Request failed: ' + xhr.statusText).show();
            })
            .always(function () {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });
    });

})(jQuery);
