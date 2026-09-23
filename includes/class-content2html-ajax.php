<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content2HTML_AjaxController {
    private const NONCE_ACTION = 'content2html_deploy_ajax';
    private const QUEUE_TRANSIENT_PREFIX = 'content2html_deploy_queue_';
    private const FRONT_FILE_TRANSIENT_PREFIX = 'content2html_deploy_frontfile_';

    public function __construct() {
        add_action('wp_ajax_content2html_deploy_start', [$this, 'handleStart']);
        add_action('wp_ajax_content2html_deploy_batch', [$this, 'handleBatch']);
        add_action('wp_ajax_content2html_deploy_finalize', [$this, 'handleFinalize']);
        add_action('wp_ajax_content2html_deploy_single', [$this, 'handleSingle']);
        add_action('wp_ajax_content2html_deploy_assets_only', [$this, 'handleAssetsOnly']);
        add_action('wp_ajax_content2html_deploy_test_sftp', [$this, 'handleTestSftp']);
        add_action('wp_ajax_content2html_deploy_test_netlify', [$this, 'handleTestNetlify']);
        add_action('wp_ajax_content2html_field_browser_posts', [$this, 'handleFieldBrowserPosts']);
        add_action('wp_ajax_content2html_field_browser_fields', [$this, 'handleFieldBrowserFields']);
    }

    private function checkAccess(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'elbutschitos-html-publisher')], 403);
        }
    }

    private function queueTransientKey(): string {
        return self::QUEUE_TRANSIENT_PREFIX . get_current_user_id();
    }

    private function frontFileTransientKey(): string {
        return self::FRONT_FILE_TRANSIENT_PREFIX . get_current_user_id();
    }

    // -----------------------------------------------------------------
    // "Deploy all" flow: start -> N x batch -> finalize
    // -----------------------------------------------------------------

    public function handleStart(): void {
        $this->checkAccess();

        try {
            Content2HTML_BatchController::resetBuildDir();
            Content2HTML_BatchController::copyAssetsToBuild();
            Content2HTML_BatchController::buildFormHandler();
            $queue = Content2HTML_BatchController::buildQueue();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // In a transient rather than the options table - this is
        // temporary state, not permanent configuration.
        set_transient($this->queueTransientKey(), $queue, HOUR_IN_SECONDS);
        delete_transient($this->frontFileTransientKey());

        wp_send_json_success([
            'total' => count($queue),
            'assets_ever_uploaded' => Content2HTML_BatchController::assetsEverUploadedForCurrentTarget(),
            'has_assets' => Content2HTML_AssetsManager::hasAssets(),
        ]);
    }

    public function handleBatch(): void {
        $this->checkAccess();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
        $batchSize = isset($_POST['batch_size']) ? max(1, (int) $_POST['batch_size']) : 5;

        $queue = get_transient($this->queueTransientKey());

        if (!is_array($queue)) {
            wp_send_json_error(['message' => __('No deployment in progress found. Please start again.', 'elbutschitos-html-publisher')]);
        }

        $slice = array_slice($queue, $offset, $batchSize);
        $errors = [];
        $frontPageId = Content2HTML_BatchController::getFrontPageId();

        foreach ($slice as $item) {
            try {
                $newFiles = Content2HTML_BatchController::generateSingle((int) $item['id'], (string) $item['post_type']);

                if ($frontPageId > 0 && (int) $item['id'] === $frontPageId) {
                    foreach ($newFiles as $newFile) {
                        if (substr($newFile, -5) === '.html') {
                            set_transient($this->frontFileTransientKey(), $newFile, HOUR_IN_SECONDS);
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                $errors[] = "Post #{$item['id']}: " . $e->getMessage();
            }
        }

        wp_send_json_success([
            'processed' => $offset + count($slice),
            'total' => count($queue),
            'done' => ($offset + count($slice)) >= count($queue),
            'errors' => $errors,
        ]);
    }

    public function handleFinalize(): void {
        $this->checkAccess();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
        $skipAssets = sanitize_text_field(wp_unslash($_POST['skip_assets'] ?? '')) === '1';

        try {
            $frontFile = get_transient($this->frontFileTransientKey());

            if (is_string($frontFile) && $frontFile !== '') {
                Content2HTML_BatchController::copyToRootIndex($frontFile);
            }

            $upload = Content2HTML_BatchController::finalizeUpload($skipAssets);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        delete_transient($this->queueTransientKey());
        delete_transient($this->frontFileTransientKey());

        $response = $upload['result'] ?: ['message' => __('Deployment complete.', 'elbutschitos-html-publisher')];
        $response['assets_included'] = $upload['assetsIncluded'];

        if (isset($upload['uploadStats'])) {
            $stats = $upload['uploadStats'];
            $wpContentCount = count($stats['wp_content_files']);

            $response['message'] = sprintf(
                /* translators: 1: file count, 2: target path, 3: wp-content file count */
                __('Deployment complete: %1$d files to "%2$s". Of these, %3$d under wp-content/ (e.g. images).', 'elbutschitos-html-publisher'),
                $stats['total'],
                $upload['remoteBasePath'] ?? '',
                $wpContentCount
            );

            $response['wp_content_files_sample'] = array_slice($stats['wp_content_files'], 0, 10);
        }

        if ($upload['assetsSkipForced']) {
            $response['message'] = ($response['message'] ?? '') . ' (' . __('Note: assets were uploaded anyway, since they had never been uploaded for this target before.', 'elbutschitos-html-publisher') . ')';
        }

        wp_send_json_success($response);
    }

    // -----------------------------------------------------------------
    // Single-page deployment (meta box button)
    // -----------------------------------------------------------------

    public function handleSingle(): void {
        $this->checkAccess();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
        $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = $postId ? get_post($postId) : null;

        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'elbutschitos-html-publisher')]);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('Insufficient permissions for this post.', 'elbutschitos-html-publisher')], 403);
        }

        $settings = Content2HTML_Settings::getSettings();

        try {
            if ($settings['target'] === 'netlify') {
                // Netlify has no granular single-file update - a deploy always
                // replaces the entire site content (see the note in the
                // settings). So here: regenerate all pages and do a full
                // redeploy.
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- deliberate: this batch export can genuinely run long on larger sites, and the default PHP time limit would otherwise abort a legitimate, user-initiated deploy partway through.
                set_time_limit(0); // can take a while on larger sites

                Content2HTML_BatchController::resetBuildDir();
                Content2HTML_BatchController::copyAssetsToBuild(); // resetBuildDir() just deleted them
                Content2HTML_BatchController::buildFormHandler();
                $queue = Content2HTML_BatchController::buildQueue();
                $frontPageId = Content2HTML_BatchController::getFrontPageId();
                $frontFile = null;

                foreach ($queue as $item) {
                    $newFiles = Content2HTML_BatchController::generateSingle((int) $item['id'], (string) $item['post_type']);

                    if ($frontPageId > 0 && (int) $item['id'] === $frontPageId) {
                        foreach ($newFiles as $newFile) {
                            if (substr($newFile, -5) === '.html') {
                                $frontFile = $newFile;
                                break;
                            }
                        }
                    }
                }

                if ($frontFile !== null) {
                    Content2HTML_BatchController::copyToRootIndex($frontFile);
                }

                $uploader = Content2HTML_BatchController::buildUploader();
                $result = $uploader->uploadDirectory(Content2HTML_BatchController::getBuildDir());
                Content2HTML_BatchController::markAssetsUploadedForCurrentTarget();

                wp_send_json_success(array_merge(
                    ['message' => __('Netlify does not support single-page deploys - the entire site was rebuilt and redeployed.', 'elbutschitos-html-publisher')],
                    $result
                ));
            }

            // SFTP: only generate and specifically upload this one page.
            Content2HTML_BatchController::copyAssetsToBuild(); // in case this is the very first operation ever
            $newFiles = Content2HTML_BatchController::generateSingle($postId, $post->post_type);

            // If the page being edited happens to be the configured front
            // page, additionally refresh and upload the index.html in the
            // root directory as well.
            if ($postId === Content2HTML_BatchController::getFrontPageId()) {
                foreach ($newFiles as $newFile) {
                    if (substr($newFile, -5) === '.html' && Content2HTML_BatchController::copyToRootIndex($newFile)) {
                        $newFiles[] = 'index.html';
                        break;
                    }
                }
            }

            if (empty($newFiles)) {
                wp_send_json_error(['message' => __('No new files were generated - please check the configuration.', 'elbutschitos-html-publisher')]);
            }

            $uploader = Content2HTML_BatchController::buildUploader();
            $buildDir = Content2HTML_BatchController::getBuildDir();

            foreach ($newFiles as $relativePath) {
                $uploader->uploadFile($buildDir . '/' . $relativePath, $relativePath);
            }

            wp_send_json_success([
                'message' => __('Page deployed.', 'elbutschitos-html-publisher'),
                'files' => $newFiles,
            ]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------
    // Deploy assets only (without regenerating the WordPress content)
    // -----------------------------------------------------------------

    public function handleAssetsOnly(): void {
        $this->checkAccess();

        if (!Content2HTML_AssetsManager::hasAssets()) {
            wp_send_json_error(['message' => __('No assets set up - please upload an assets.zip first.', 'elbutschitos-html-publisher')]);
        }

        try {
            $result = Content2HTML_BatchController::uploadAssetsOnly();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result ?: ['message' => __('Assets deployed.', 'elbutschitos-html-publisher')]);
    }

    // -----------------------------------------------------------------
    // Connection tests (SFTP / Netlify) - use the current, not-yet-saved
    // form values; secret fields left empty fall back to the already
    // saved (decrypted) value, mirroring the save logic in
    // Content2HTML_Settings::handleSave().
    // -----------------------------------------------------------------

    public function handleTestSftp(): void {
        $this->checkAccess();

        $existing = Content2HTML_Settings::getSettings();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
        $rawAuthMethod = sanitize_key(wp_unslash($_POST['sftp_auth_method'] ?? ''));

        // Deliberately NOT run through sanitize_text_field() or similar:
        // these are opaque secret values (password/private
        // key/passphrase) where such sanitization could corrupt the
        // exact value needed to authenticate (e.g. stripping newlines
        // from a multi-line PEM private key). wp_unslash() alone is
        // sufficient - never echoed back as HTML, only used to attempt
        // an SFTP connection.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above in checkAccess(); these are opaque secret values where sanitize_text_field() could corrupt the exact value needed to authenticate (e.g. stripping newlines from a multi-line PEM private key).
        $rawPassword = wp_unslash($_POST['sftp_password'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above in checkAccess(); these are opaque secret values where sanitize_text_field() could corrupt the exact value needed to authenticate (e.g. stripping newlines from a multi-line PEM private key).
        $rawPrivateKey = wp_unslash($_POST['sftp_private_key'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above in checkAccess(); these are opaque secret values where sanitize_text_field() could corrupt the exact value needed to authenticate (e.g. stripping newlines from a multi-line PEM private key).
        $rawPassphrase = wp_unslash($_POST['sftp_passphrase'] ?? '');

        $settings = [
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
            'sftp_host' => sanitize_text_field(wp_unslash($_POST['sftp_host'] ?? '')),
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
            'sftp_port' => max(1, absint(wp_unslash($_POST['sftp_port'] ?? 22))),
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
            'sftp_username' => sanitize_text_field(wp_unslash($_POST['sftp_username'] ?? '')),
            'sftp_auth_method' => in_array($rawAuthMethod, ['password', 'key'], true)
                ? $rawAuthMethod
                : $existing['sftp_auth_method'],
            'sftp_password' => $rawPassword !== '' ? $rawPassword : $existing['sftp_password'],
            'sftp_private_key' => $rawPrivateKey !== '' ? $rawPrivateKey : $existing['sftp_private_key'],
            'sftp_passphrase' => $rawPassphrase !== '' ? $rawPassphrase : $existing['sftp_passphrase'],
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
            'sftp_remote_base_path' => '/' . ltrim(sanitize_text_field(wp_unslash($_POST['sftp_remote_base_path'] ?? '/')), '/'),
        ];

        try {
            $uploader = new Content2HTML_SftpUploader($settings);
            $uploader->testConnection();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success(['message' => __('Connection successful, target directory is writable.', 'elbutschitos-html-publisher')]);
    }

    public function handleTestNetlify(): void {
        $this->checkAccess();

        $existing = Content2HTML_Settings::getSettings();

        // Deliberately NOT sanitize_text_field()'d - an opaque API
        // token, never echoed back as HTML (see the same note above for
        // the SFTP credentials).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above in checkAccess(); these are opaque secret values where sanitize_text_field() could corrupt the exact value needed to authenticate (e.g. stripping newlines from a multi-line PEM private key).
        $rawToken = wp_unslash($_POST['netlify_token'] ?? '');

        $settings = [
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
            'netlify_site_id' => sanitize_text_field(wp_unslash($_POST['netlify_site_id'] ?? '')),
            'netlify_token' => $rawToken !== '' ? $rawToken : $existing['netlify_token'],
        ];

        try {
            $uploader = new Content2HTML_NetlifyUploader($settings);
            $uploader->testConnection();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success(['message' => __('Connection successful, site found.', 'elbutschitos-html-publisher')]);
    }

    // -----------------------------------------------------------------
    // Field browser (Content tab, next to Data Injection Rules)
    // -----------------------------------------------------------------

    /**
     * Returns a short list of published posts/pages (from the currently
     * enabled post types) for the field browser's post picker. Capped at
     * 200 - this is meant for picking a representative example, not for
     * browsing the entire site.
     */
    public function handleFieldBrowserPosts(): void {
        $this->checkAccess();

        $settings = Content2HTML_Settings::getSettings();

        $posts = get_posts([
            'post_type' => $settings['post_types'],
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $result = [];
        foreach ($posts as $post) {
            $result[] = [
                'id' => $post->ID,
                'title' => get_the_title($post) ?: __('(no title)', 'elbutschitos-html-publisher'),
                'post_type' => $post->post_type,
            ];
        }

        wp_send_json_success(['posts' => $result]);
    }

    /**
     * Returns the flattened "path => value preview" list for one
     * specific post/page - see Content2HTML_FieldBrowser.
     */
    public function handleFieldBrowserFields(): void {
        $this->checkAccess();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in checkAccess() (check_ajax_referer); phpcs's sniff can't trace verification across a method call.
        $postId = absint(wp_unslash($_POST['post_id'] ?? 0));
        $post = $postId > 0 ? get_post($postId) : null;

        if ($post === null) {
            wp_send_json_error(['message' => __('Post not found.', 'elbutschitos-html-publisher')]);
        }

        $settings = Content2HTML_Settings::getSettings();

        if (!in_array($post->post_type, $settings['post_types'], true)) {
            wp_send_json_error(['message' => __('This post type is not enabled under Content.', 'elbutschitos-html-publisher')]);
        }

        try {
            $fields = Content2HTML_FieldBrowser::getFields($postId, $post->post_type);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success(['fields' => $fields]);
    }
}
