/**
 * CS Meta Sync — Admin JS
 *
 * Handles the "Sync Now" button and renders verbose sync output.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $button   = $('#cs-meta-sync-now');
        var $spinner  = $('#cs-meta-sync-spinner');
        var $result   = $('#cs-meta-sync-result');
        var $verbose  = $('#cs-meta-sync-verbose');

        $button.on('click', function (e) {
            e.preventDefault();

            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.removeClass('success error').hide().text('');
            $verbose.empty().hide();

            $.post(csMetaSync.ajax_url, {
                action: 'cs_meta_sync_now',
                nonce: csMetaSync.nonce
            })
            .done(function (response) {
                if (response.success && response.data) {
                    var d = response.data;
                    var syncLabel = d.sync_type === 'manual' ? '🔧 Manual' : '⏰ Scheduled';
                    var msg = '✅ ' + syncLabel + ' sync complete — ' + d.total + ' products sent, ' +
                              d.success + ' succeeded, ' + d.errors + ' errors';
                    if (d.skipped > 0) {
                        msg += ', ' + d.skipped + ' skipped';
                    }
                    if (d.sets && d.sets.length > 0) {
                        var setsOk = d.sets.filter(function(s) { return s.status !== 'error'; }).length;
                        msg += ' | ' + d.sets.length + ' sets (' + setsOk + ' ok)';
                    }
                    if (d.message) {
                        msg += '\n' + d.message;
                    }
                    $result.addClass('success').text(msg).show();

                    // Render verbose product list.
                    if (d.items && d.items.length > 0) {
                        renderVerboseList(d.items, d.sets || []);
                    }
                } else {
                    $result.addClass('error').text('❌ ' + (response.data || 'Sync failed.')).show();
                }
            })
            .fail(function (xhr) {
                $result.addClass('error').text('❌ Request failed: ' + xhr.statusText).show();
            })
            .always(function () {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        /**
         * Render the verbose product list after sync.
         */
        function renderVerboseList(items, sets) {
            var html = '<div class="cs-verbose-header">';
            html += '<h3>📦 Synced Products (' + items.length + ')</h3>';
            html += '<button type="button" class="button cs-verbose-toggle" data-target="products">Collapse</button>';
            html += '</div>';
            html += '<div class="cs-verbose-body" data-section="products">';
            html += '<table class="cs-verbose-table">';
            html += '<thead><tr>';
            html += '<th class="cs-col-img">Image</th>';
            html += '<th class="cs-col-name">Product</th>';
            html += '<th class="cs-col-id">Retailer ID</th>';
            html += '<th class="cs-col-price">Price</th>';
            html += '<th class="cs-col-status">Status</th>';
            html += '</tr></thead><tbody>';

            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var statusClass = item.status === 'synced' ? 'cs-status-ok' : 'cs-status-err';
                var statusIcon  = item.status === 'synced' ? '✅' : '❌';
                var imgHtml     = item.image
                    ? '<img src="' + escHtml(item.image) + '" alt="' + escHtml(item.name) + '" class="cs-thumb" />'
                    : '<span class="cs-no-img">No image</span>';

                html += '<tr class="' + statusClass + '">';
                html += '<td class="cs-col-img">' + imgHtml + '</td>';
                html += '<td class="cs-col-name"><strong>' + escHtml(item.name) + '</strong><br><small>#' + item.id + '</small></td>';
                html += '<td class="cs-col-id"><code>' + escHtml(item.retailer_id) + '</code></td>';
                html += '<td class="cs-col-price">' + escHtml(item.price) + '</td>';
                html += '<td class="cs-col-status">' + statusIcon + ' ' + item.status + '</td>';
                html += '</tr>';
            }

            html += '</tbody></table></div>';

            // Product Sets section.
            if (sets && sets.length > 0) {
                html += '<div class="cs-verbose-header cs-sets-header">';
                html += '<h3>🏷️ Product Sets / Categories (' + sets.length + ')</h3>';
                html += '<button type="button" class="button cs-verbose-toggle" data-target="sets">Collapse</button>';
                html += '</div>';
                html += '<div class="cs-verbose-body" data-section="sets">';
                html += '<table class="cs-verbose-table cs-sets-table">';
                html += '<thead><tr>';
                html += '<th>Category</th>';
                html += '<th>Products</th>';
                html += '<th>Meta Set ID</th>';
                html += '<th>Status</th>';
                html += '</tr></thead><tbody>';

                for (var s = 0; s < sets.length; s++) {
                    var set = sets[s];
                    var setIcon = '✅';
                    var setClass = 'cs-status-ok';
                    if (set.status === 'created') {
                        setIcon = '🆕';
                    } else if (set.status === 'error') {
                        setIcon = '❌';
                        setClass = 'cs-status-err';
                    }

                    html += '<tr class="' + setClass + '">';
                    html += '<td><strong>' + escHtml(set.name) + '</strong></td>';
                    html += '<td>' + (set.count || 0) + '</td>';
                    html += '<td><code>' + escHtml(set.meta_id || '—') + '</code></td>';
                    html += '<td>' + setIcon + ' ' + escHtml(set.status);
                    if (set.error) {
                        html += '<br><small class="cs-set-error">' + escHtml(set.error) + '</small>';
                    }
                    html += '</td>';
                    html += '</tr>';
                }

                html += '</tbody></table></div>';
            }

            $verbose.html(html).show();

            // Toggle collapse for each section.
            $verbose.find('.cs-verbose-toggle').on('click', function () {
                var target = $(this).data('target');
                var $body = $verbose.find('[data-section="' + target + '"]');
                $body.slideToggle(200);
                $(this).text($body.is(':visible') ? 'Collapse' : 'Expand');
            });
        }

        /**
         * Simple HTML escaping.
         */
        function escHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    });

})(jQuery);
