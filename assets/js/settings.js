/* global jQuery, billtoWcSettings */
(function ($) {
    'use strict';

    $(function () {
        var $button = $('#billto-wc-test-connection');
        var $result = $('#billto-wc-test-result');

        $button.on('click', function () {
            $button.prop('disabled', true);
            $result.text(billtoWcSettings.testing).css('color', '');

            $.post(billtoWcSettings.ajaxUrl, {
                action: 'billto_wc_test_connection',
                nonce: billtoWcSettings.nonce
            })
                .done(function (response) {
                    var ok = response && response.success;
                    var message = response && response.data && response.data.message ? response.data.message : '';
                    $result.text(message).css('color', ok ? '#00a32a' : '#d63638');
                })
                .fail(function () {
                    $result.text('Request failed.').css('color', '#d63638');
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });
    });
})(jQuery);
