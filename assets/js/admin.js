jQuery(function ($) {
    'use strict';

    var i18n = (typeof content2htmlDeploy !== 'undefined' && content2htmlDeploy.i18n) ? content2htmlDeploy.i18n : {};

    // --- Tabs: Content / Navigation / Forms / Deployment target / -----
    // --- Markdown export --------------------------------------------------
    var $tabs = $('.content2html-tabs .nav-tab');
    var $panels = $('.content2html-tab-panel');
    var $transferSection = $('#content2html-transfer-section, #content2html-transfer-hr, #content2html-save-button-wrap');
    var STORAGE_KEY = 'content2htmlActiveTab';

    function activateTab(tabId) {
        if (!$panels.filter('[data-tab-panel="' + tabId + '"]').length) {
            return;
        }

        $tabs.removeClass('nav-tab-active');
        $tabs.filter('[data-tab="' + tabId + '"]').addClass('nav-tab-active');

        $panels.removeClass('content2html-tab-active');
        $panels.filter('[data-tab-panel="' + tabId + '"]').addClass('content2html-tab-active');

        // "Deployment" (Deploy all etc.) doesn't belong to the Markdown
        // export (which runs independently of SFTP/Netlify) - hide it
        // there so the Markdown section moves further up.
        $transferSection.toggle(tabId !== 'markdown');

        try {
            window.localStorage.setItem(STORAGE_KEY, tabId);
        } catch (e) {
            // localStorage may not be available (e.g. private browsing) - not a problem, only the "remember" feature is lost.
        }
    }

    if ($tabs.length) {
        $tabs.on('click', function (e) {
            e.preventDefault();
            activateTab($(this).data('tab'));
        });

        var savedTab = null;
        try {
            savedTab = window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            // ignore
        }

        activateTab(savedTab && $panels.filter('[data-tab-panel="' + savedTab + '"]').length ? savedTab : $tabs.first().data('tab'));
    }

    // --- Detecting unsaved changes ---------------------------------------
    // "Deploy all" and "Deploy assets only" read the last SAVED settings
    // from the database, not the current form values. Without this
    // warning, you could e.g. switch the target from Netlify to SFTP,
    // forget to save, and click "Deploy all" - which would then silently
    // run with the old Netlify settings.
    var $settingsForm = $('#content2html-settings-form');
    var $unsavedNotice = $('#content2html-unsaved-notice');
    var formDirty = false;

    if ($settingsForm.length) {
        $settingsForm.on('input change', 'input, textarea, select', function () {
            formDirty = true;
            $unsavedNotice.show();
        });

        $settingsForm.on('submit', function () {
            formDirty = false; // the page reloads afterwards anyway
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    /**
     * Shows a confirmation dialog if there are unsaved changes, before an
     * action runs that would instead use the saved settings. Returns
     * true if it's OK to proceed (no unsaved changes, or the user
     * confirmed proceeding anyway).
     */
    function confirmIfUnsaved(actionLabel) {
        if (!formDirty) {
            return true;
        }

        return window.confirm((i18n.unsavedChangesConfirm || '').replace('%s', actionLabel));
    }

    // --- Target switching (Netlify / SFTP) -------------------------------
    function updateTargetSections() {
        var target = $('.content2html-target-radio:checked').val();
        $('#content2html-target-netlify').toggle(target === 'netlify');
        $('#content2html-target-sftp').toggle(target === 'sftp');
    }

    function updateSftpAuthSections() {
        var method = $('.content2html-sftp-auth-radio:checked').val();
        $('.content2html-sftp-auth-password').toggle(method === 'password');
        $('.content2html-sftp-auth-key').toggle(method === 'key');
    }

    $('.content2html-target-radio').on('change', updateTargetSections);
    $('.content2html-sftp-auth-radio').on('change', updateSftpAuthSections);
    updateTargetSections();
    updateSftpAuthSections();

    // --- Navigation: only show detail fields when enabled ---------------
    function updateNavSections() {
        $('.content2html-nav-fields').each(function () {
            var $table = $(this);
            var enabled = $table.find('.content2html-nav-enabled').is(':checked');
            $table.find('.content2html-nav-detail').toggle(enabled);
        });
    }

    $('.content2html-nav-enabled').on('change', updateNavSections);
    updateNavSections();

    // --- Connection tests (SFTP / Netlify) --------------------------------
    function showTestResult($span, success, message) {
        $span.removeClass('content2html-test-ok content2html-test-fail');

        if (success) {
            $span.addClass('content2html-test-ok').html('&#10003; ' + message);
        } else {
            $span.addClass('content2html-test-fail').html('&#10007; ' + message);
        }
    }

    function ajaxPostSimple(action, data) {
        return ajaxPost(action, data);
    }

    $('#content2html-test-sftp-btn').on('click', function () {
        var $btn = $(this);
        var $result = $('#content2html-test-sftp-result');

        $btn.prop('disabled', true).text(i18n.testing);
        $result.removeClass('content2html-test-ok content2html-test-fail').text('');

        ajaxPostSimple('content2html_deploy_test_sftp', {
            sftp_host: $('#sftp_host').val(),
            sftp_port: $('#sftp_port').val(),
            sftp_username: $('#sftp_username').val(),
            sftp_auth_method: $('.content2html-sftp-auth-radio:checked').val(),
            sftp_password: $('#sftp_password').val(),
            sftp_private_key: $('#sftp_private_key').val(),
            sftp_passphrase: $('#sftp_passphrase').val(),
            sftp_remote_base_path: $('#sftp_remote_base_path').val()
        }).done(function (response) {
            if (response.success) {
                showTestResult($result, true, response.data.message);
            } else {
                showTestResult($result, false, (response.data && response.data.message) || i18n.unknownErrorPeriod);
            }
        }).fail(function () {
            showTestResult($result, false, i18n.requestToWordPressFailed);
        }).always(function () {
            $btn.prop('disabled', false).text(i18n.testConnection);
        });
    });

    $('#content2html-test-netlify-btn').on('click', function () {
        var $btn = $(this);
        var $result = $('#content2html-test-netlify-result');

        $btn.prop('disabled', true).text(i18n.testing);
        $result.removeClass('content2html-test-ok content2html-test-fail').text('');

        ajaxPostSimple('content2html_deploy_test_netlify', {
            netlify_site_id: $('#netlify_site_id').val(),
            netlify_token: $('#netlify_token').val()
        }).done(function (response) {
            if (response.success) {
                showTestResult($result, true, response.data.message);
            } else {
                showTestResult($result, false, (response.data && response.data.message) || i18n.unknownErrorPeriod);
            }
        }).fail(function () {
            showTestResult($result, false, i18n.requestToWordPressFailed);
        }).always(function () {
            $btn.prop('disabled', false).text(i18n.testConnection);
        });
    });

    // --- "Deploy all" with batch progress ---------------------------------
    var $btn = $('#content2html-deploy-all-btn');
    var $progressWrap = $('#content2html-deploy-progress');
    var $progressBar = $('#content2html-deploy-progress-bar');
    var $progressText = $('#content2html-deploy-progress-text');
    var $result = $('#content2html-deploy-result');

    function ajaxPost(action, data) {
        return $.post(content2htmlDeploy.ajaxUrl, $.extend({
            action: action,
            nonce: content2htmlDeploy.nonce
        }, data || {}));
    }

    function runBatch(total, offset) {
        ajaxPost('content2html_deploy_batch', {
            offset: offset,
            batch_size: content2htmlDeploy.batchSize
        }).done(function (response) {
            if (!response.success) {
                fail(response.data && response.data.message);
                return;
            }

            var data = response.data;
            var percent = total > 0 ? Math.round((data.processed / total) * 100) : 100;

            $progressBar.val(percent);
            $progressText.text('Generiere … ' + data.processed + ' / ' + total);

            if (data.errors && data.errors.length) {
                $result.append('<p style="color:#b32d2e;">' + data.errors.join('<br>') + '</p>');
            }

            if (data.done) {
                finalize();
            } else {
                runBatch(total, data.processed);
            }
        }).fail(function () {
            fail(i18n.errorBatchProcessing);
        });
    }

    function finalize() {
        $progressText.text(i18n.deployingToTarget);

        var skipAssets = !$('#content2html-skip-assets').is(':checked');

        ajaxPost('content2html_deploy_finalize', { skip_assets: skipAssets ? '1' : '0' }).done(function (response) {
            if (!response.success) {
                fail(response.data && response.data.message);
                return;
            }

            $progressBar.val(100);
            $progressText.text('Fertig.');

            var data = response.data || {};
            var url = data.deploy_url;

            if (url) {
                $result.append('<p>Deploy: <a href="' + url + '" target="_blank" rel="noopener">' + url + '</a> (' + (data.state || 'processing') + ')</p>');
            } else {
                $result.append('<p>' + (data.message || i18n.deploymentComplete) + '</p>');
            }

            if (data.wp_content_files_sample && data.wp_content_files_sample.length) {
                var list = '<ul style="margin-left:20px; list-style:disc;">' +
                    data.wp_content_files_sample.map(function (f) { return '<li><code>' + f + '</code></li>'; }).join('') +
                    '</ul>';
                $result.append('<p>Beispiel-Pfade (relativ zum Ziel-Verzeichnis):</p>' + list);
            }

            $btn.prop('disabled', false).text(i18n.deployAll);
        }).fail(function () {
            fail(i18n.errorFinalizing);
        });
    }

    function fail(message) {
        $result.append('<p style="color:#b32d2e;">' + i18n.errorPrefix + ' ' + (message || i18n.unknownError) + '</p>');
        $btn.prop('disabled', false).text(i18n.deployAll);
    }

    $btn.on('click', function () {
        if (!confirmIfUnsaved(i18n.deployAll)) {
            return;
        }

        $btn.prop('disabled', true).text(i18n.running);
        $result.empty();
        $progressWrap.show();
        $progressBar.val(0);
        $progressText.text(i18n.starting);

        ajaxPost('content2html_deploy_start').done(function (response) {
            if (!response.success) {
                fail(response.data && response.data.message);
                return;
            }

            var total = response.data.total;

            // First-upload protection: as long as assets have never been
            // uploaded for this target, "skip assets" must not be
            // selectable - otherwise a page could go live with no
            // CSS/images at all.
            var $skipCheckbox = $('#content2html-skip-assets');
            if (!response.data.assets_ever_uploaded) {
                $skipCheckbox.prop('checked', true).prop('disabled', true);
            } else {
                $skipCheckbox.prop('disabled', false);
            }

            if (total === 0) {
                fail(i18n.noMatchingPosts);
                return;
            }

            runBatch(total, 0);
        }).fail(function () {
            fail(i18n.errorStartingDeploy);
        });
    });

    // --- Deploy assets only ------------------------------------------------
    $('#content2html-deploy-assets-only-btn').on('click', function () {
        if (!confirmIfUnsaved(i18n.deployAssetsOnly)) {
            return;
        }

        var $assetsBtn = $(this);

        $assetsBtn.prop('disabled', true).text(i18n.deployingAssets);
        $result.empty();

        ajaxPost('content2html_deploy_assets_only').done(function (response) {
            if (!response.success) {
                $result.append('<p style="color:#b32d2e;">' + i18n.errorPrefix + ' ' + ((response.data && response.data.message) || i18n.unknownError) + '</p>');
                return;
            }

            var data = response.data || {};
            var url = data.deploy_url;

            if (url) {
                $result.append('<p>' + i18n.assetsDeployedWithUrl + ' <a href="' + url + '" target="_blank" rel="noopener">' + url + '</a></p>');
            } else {
                $result.append('<p>' + (data.message || i18n.assetsDeployed) + '</p>');
            }
        }).fail(function () {
            $result.append('<p style="color:#b32d2e;">' + i18n.errorRequestFailed + '</p>');
        }).always(function () {
            $assetsBtn.prop('disabled', false).text(i18n.deployAssetsOnly);
        });
    });

    // --- Field browser (Content tab) --------------------------------------
    var $fbModal = $('#content2html-field-browser-modal');
    var $fbPostSelect = $('#content2html-field-browser-post-select');
    var $fbSearch = $('#content2html-field-browser-search');
    var $fbShowEmpty = $('#content2html-field-browser-show-empty');
    var $fbResults = $('#content2html-field-browser-results');
    var $fbCommon = $('#content2html-field-browser-common');
    var $fbCommonResults = $('#content2html-field-browser-common-results');
    var fbFields = []; // full, unfiltered list from the last successful fetch

    // The most frequently used fields, with clean, hand-picked marker
    // names (rather than the generic auto-derived ones) - shown as a
    // quick-access shortcut above the full, searchable list.
    var FB_COMMON_FIELDS = [
        {path: 'title->rendered', marker: '###title###'},
        {path: 'content->rendered', marker: '###content###'},
        {path: 'excerpt->rendered', marker: '###excerpt###'},
        {path: 'slug', marker: '###slug###'},
        {path: 'link', marker: '###permalink###'},
        {path: 'date', marker: '###date###'}
    ];

    function fbBuildRowHtml(field, forcedMarker) {
        var badge = field.is_array
            ? '<span class="content2html-field-array-badge">' + i18n.fieldBrowserArrayBadge + '</span>'
            : (field.is_linked ? '<span class="content2html-field-linked-badge">' + i18n.fieldBrowserLinkedBadge + '</span>' : '');
        // A plain (unresolved) array field would silently produce the
        // literal text "Array" if used directly in an injection rule
        // (PHP's array-to-string conversion) - not clickable-to-insert,
        // to prevent building a rule that looks fine but is broken.
        var notInsertable = field.is_array && !field.is_linked;
        var rowClass = 'content2html-field-row' + (notInsertable ? ' content2html-field-row-disabled' : '');

        return '<div class="' + rowClass + '"'
            + ' data-path="' + $('<div>').text(field.path).html() + '"'
            + ' data-not-insertable="' + (notInsertable ? '1' : '') + '"'
            + (forcedMarker ? ' data-marker="' + $('<div>').text(forcedMarker).html() + '"' : '')
            + '>'
            + '<code>' + $('<div>').text(field.path).html() + '</code>'
            + '<span class="content2html-field-preview">' + $('<div>').text(field.preview).html() + '</span>'
            + badge
            + '</div>';
    }

    function fbRenderFields(fields) {
        if (fields.length === 0) {
            $fbResults.html('<p class="description">' + i18n.fieldBrowserNoMatch + '</p>');
            return;
        }

        var html = '';
        fields.forEach(function (field) {
            html += fbBuildRowHtml(field, null);
        });

        $fbResults.html(html);
    }

    function fbRenderCommonFields() {
        var byPath = {};
        fbFields.forEach(function (field) {
            byPath[field.path] = field;
        });

        var html = '';
        FB_COMMON_FIELDS.forEach(function (common) {
            var field = byPath[common.path];

            if (field) {
                html += fbBuildRowHtml(field, common.marker);
            }
        });

        if (html === '') {
            $fbCommon.hide();
        } else {
            $fbCommonResults.html(html);
            $fbCommon.show();
        }
    }

    function fbApplyFilter() {
        var term = $fbSearch.val().toLowerCase();
        var showEmpty = $fbShowEmpty.is(':checked');

        var visible = fbFields.filter(function (field) {
            return showEmpty || !field.is_empty;
        });

        if (term !== '') {
            visible = visible.filter(function (field) {
                return field.path.toLowerCase().indexOf(term) !== -1
                    || field.preview.toLowerCase().indexOf(term) !== -1;
            });
        }

        fbRenderFields(visible);
    }

    function fbLoadPosts() {
        $fbPostSelect.prop('disabled', true).html('<option value="">' + i18n.fieldBrowserLoadingPosts + '</option>');

        ajaxPost('content2html_field_browser_posts', {}).done(function (response) {
            if (!response.success) {
                $fbPostSelect.html('<option value="">' + ((response.data && response.data.message) || i18n.unknownError) + '</option>');
                return;
            }

            var posts = (response.data && response.data.posts) || [];

            if (posts.length === 0) {
                $fbPostSelect.html('<option value="">' + i18n.fieldBrowserNoPosts + '</option>');
                return;
            }

            var options = '<option value="">' + i18n.fieldBrowserSelectPost + '</option>';
            posts.forEach(function (post) {
                options += '<option value="' + post.id + '">' + $('<div>').text(post.title).html() + ' (' + post.post_type + ')</option>';
            });

            $fbPostSelect.prop('disabled', false).html(options);
        }).fail(function () {
            $fbPostSelect.html('<option value="">' + i18n.errorRequestFailed + '</option>');
        });
    }

    function fbLoadFields(postId) {
        fbFields = [];
        $fbCommon.hide();
        $fbResults.html('<p class="description">' + i18n.fieldBrowserLoadingFields + '</p>');

        ajaxPost('content2html_field_browser_fields', {post_id: postId}).done(function (response) {
            if (!response.success) {
                $fbResults.html('<p style="color:#b32d2e;">' + ((response.data && response.data.message) || i18n.unknownError) + '</p>');
                return;
            }

            fbFields = (response.data && response.data.fields) || [];
            fbRenderCommonFields();
            fbApplyFilter();
        }).fail(function () {
            $fbResults.html('<p style="color:#b32d2e;">' + i18n.errorRequestFailed + '</p>');
        });
    }

    $('#content2html-field-browser-open-btn').on('click', function () {
        $fbModal.show();
        $fbSearch.val('');
        $fbShowEmpty.prop('checked', false);
        fbFields = [];
        $fbCommon.hide();
        $fbResults.html('<p class="description">' + i18n.fieldBrowserSelectPrompt + '</p>');
        fbLoadPosts();
    });

    $('#content2html-field-browser-close-btn').on('click', function () {
        $fbModal.hide();
    });

    $fbModal.on('click', function (event) {
        if (event.target === this) {
            $fbModal.hide();
        }
    });

    $fbPostSelect.on('change', function () {
        var postId = $(this).val();
        $fbSearch.val('');

        if (postId) {
            fbLoadFields(postId);
        } else {
            fbFields = [];
            $fbCommon.hide();
            $fbResults.html('<p class="description">' + i18n.fieldBrowserSelectPrompt + '</p>');
        }
    });

    $fbSearch.on('input', fbApplyFilter);
    $fbShowEmpty.on('change', fbApplyFilter);

    $fbResults.add($fbCommonResults).on('click', '.content2html-field-row', function () {
        var $row = $(this);

        if ($row.data('not-insertable')) {
            var originalWarningHtml = $row.html();
            $row.append(' <em>' + i18n.fieldBrowserNotInsertable + '</em>');
            setTimeout(function () {
                $row.html(originalWarningHtml);
            }, 4000);
            return;
        }

        var path = $row.data('path');
        var marker = $row.data('marker') || ('###' + String(path).replace(/->/g, '_').replace(/[^a-zA-Z0-9_]/g, '_') + '###');
        var $textarea = $('#data_injection_rules');
        var newLine = path + ' => ' + marker;
        var current = $textarea.val();

        $textarea.val(current === '' ? newLine : current.replace(/\n*$/, '') + '\n' + newLine);
        $textarea.trigger('change'); // so the "unsaved changes" tracking picks this up

        var originalHtml = $row.html();
        $row.append(' <em>(' + i18n.fieldBrowserInserted + ')</em>');
        setTimeout(function () {
            $row.html(originalHtml);
        }, 1200);
    });

    // --- Class browser (Content tab) --------------------------------------
    var $cbModal = $('#content2html-class-browser-modal');
    var $cbPostSelect = $('#content2html-class-browser-post-select');
    var $cbResults = $('#content2html-class-browser-results');
    var $classMap = $('#class_map');

    function cbMappedClasses() {
        var mapped = {};
        String($classMap.val()).split('\n').forEach(function (line) {
            var parts = line.split('=>');
            if (parts.length >= 1) {
                var source = parts[0].trim();
                if (source !== '') {
                    mapped[source] = true;
                }
            }
        });
        return mapped;
    }

    function cbRenderClasses(classes) {
        if (classes.length === 0) {
            $cbResults.html('<p class="description">' + i18n.classBrowserNoClasses + '</p>');
            return;
        }

        var mapped = cbMappedClasses();
        var html = '';

        classes.forEach(function (item) {
            var isMapped = !!mapped[item['class']];
            var rowClass = 'content2html-field-row' + (isMapped ? ' content2html-field-row-disabled' : '');
            var badge = isMapped ? '<span class="content2html-field-linked-badge">' + i18n.classBrowserAlreadyMapped + '</span>' : '';

            html += '<div class="' + rowClass + '"'
                + ' data-class="' + $('<div>').text(item['class']).html() + '"'
                + ' data-mapped="' + (isMapped ? '1' : '') + '"'
                + '>'
                + '<code>' + $('<div>').text(item['class']).html() + '</code>'
                + '<span class="content2html-field-preview">' + $('<div>').text('<' + item.tag + '> ' + item.preview).html() + '</span>'
                + badge
                + '</div>';
        });

        $cbResults.html(html);
    }

    function cbLoadClasses(postId) {
        $cbResults.html('<p class="description">' + i18n.classBrowserLoadingClasses + '</p>');

        ajaxPost('content2html_class_browser_classes', {post_id: postId}).done(function (response) {
            if (!response.success) {
                $cbResults.html('<p style="color:#b32d2e;">' + ((response.data && response.data.message) || i18n.unknownError) + '</p>');
                return;
            }

            cbRenderClasses((response.data && response.data.classes) || []);
        }).fail(function () {
            $cbResults.html('<p style="color:#b32d2e;">' + i18n.errorRequestFailed + '</p>');
        });
    }

    $('#content2html-class-browser-open-btn').on('click', function () {
        $cbModal.show();

        // The browser only ever shows classes matching a configured
        // prefix (see Content2HTML_ClassBrowser) - with nothing
        // configured there's nothing it COULD find, so skip straight to
        // telling the designer what to do first instead of making them
        // pick a post and discover "no matches" themselves.
        if ($('#remove_class_prefixes').val().trim() === '') {
            $cbPostSelect.prop('disabled', true).html('<option value=""></option>');
            $cbResults.html('<p class="description">' + i18n.classBrowserPrefixesRequired + '</p>');
            return;
        }

        $cbResults.html('<p class="description">' + i18n.classBrowserSelectPrompt + '</p>');

        // Reuses the same generic post list as the field browser - no
        // need for a second, near-identical endpoint.
        $cbPostSelect.prop('disabled', true).html('<option value="">' + i18n.fieldBrowserLoadingPosts + '</option>');

        ajaxPost('content2html_field_browser_posts', {}).done(function (response) {
            if (!response.success) {
                $cbPostSelect.html('<option value="">' + ((response.data && response.data.message) || i18n.unknownError) + '</option>');
                return;
            }

            var posts = (response.data && response.data.posts) || [];

            if (posts.length === 0) {
                $cbPostSelect.html('<option value="">' + i18n.fieldBrowserNoPosts + '</option>');
                return;
            }

            var options = '<option value="">' + i18n.fieldBrowserSelectPost + '</option>';
            posts.forEach(function (post) {
                options += '<option value="' + post.id + '">' + $('<div>').text(post.title).html() + ' (' + post.post_type + ')</option>';
            });

            $cbPostSelect.prop('disabled', false).html(options);
        }).fail(function () {
            $cbPostSelect.html('<option value="">' + i18n.errorRequestFailed + '</option>');
        });
    });

    $('#content2html-class-browser-close-btn').on('click', function () {
        $cbModal.hide();
    });

    $cbModal.on('click', function (event) {
        if (event.target === this) {
            $cbModal.hide();
        }
    });

    $cbPostSelect.on('change', function () {
        var postId = $(this).val();

        if (postId) {
            cbLoadClasses(postId);
        } else {
            $cbResults.html('<p class="description">' + i18n.classBrowserSelectPrompt + '</p>');
        }
    });

    $cbResults.on('click', '.content2html-field-row', function () {
        var $row = $(this);

        if ($row.data('mapped')) {
            return; // already in the mapping table - nothing to do
        }

        var className = $row.data('class');
        var current = $classMap.val();
        var newLine = className + ' => ';

        $classMap.val(current === '' ? newLine : current.replace(/\n*$/, '') + '\n' + newLine);
        $classMap.trigger('change'); // so the "unsaved changes" tracking picks this up
        $classMap.focus();
        // Places the cursor right after "=> " so the designer can just
        // start typing their own class name.
        var pos = $classMap.val().length;
        $classMap[0].setSelectionRange(pos, pos);

        var originalHtml = $row.html();
        $row.append(' <em>(' + i18n.classBrowserAdded + ')</em>');
        setTimeout(function () {
            $row.html(originalHtml);
        }, 1200);
    });
});
