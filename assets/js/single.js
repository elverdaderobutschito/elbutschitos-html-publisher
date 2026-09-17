jQuery(function ($) {
    'use strict';

    var i18n = (typeof content2htmlDeploySingle !== 'undefined' && content2htmlDeploySingle.i18n) ? content2htmlDeploySingle.i18n : {};

    $('#content2html-deploy-single-btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#content2html-deploy-single-status');
        var postId = $btn.data('post-id');

        $btn.prop('disabled', true).text(i18n.deploying);
        $status.text('');

        $.post(content2htmlDeploySingle.ajaxUrl, {
            action: 'content2html_deploy_single',
            nonce: content2htmlDeploySingle.nonce,
            post_id: postId
        }).done(function (response) {
            if (response.success) {
                var data = response.data || {};
                var url = data.deploy_url;

                if (url) {
                    $status.html((data.message || i18n.done) + '<br><a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>');
                } else {
                    $status.text(data.message || i18n.done);
                }
            } else {
                $status.text(i18n.error + ' ' + ((response.data && response.data.message) || i18n.unknownError));
            }
        }).fail(function () {
            $status.text(i18n.requestError);
        }).always(function () {
            $btn.prop('disabled', false).text(i18n.deploy);
        });
    });
});
