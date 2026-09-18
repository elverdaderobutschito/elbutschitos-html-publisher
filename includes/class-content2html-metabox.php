<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content2HTML_MetaBox {
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('save_post', [$this, 'saveTemplateSelection']);
    }

    public function register(): void {
        $settings = Content2HTML_Settings::getSettings();

        foreach ($settings['post_types'] as $postType) {
            add_meta_box(
                'content2html_deploy_single',
                __('HTML Publisher', 'elbutschitos-html-publisher'),
                [$this, 'render'],
                $postType,
                'side',
                'high'
            );
        }
    }

    public function render(WP_Post $post): void {
        $settings = Content2HTML_Settings::getSettings();

        if (empty($settings['template_path'])) {
            echo '<p>' . sprintf(
                /* translators: %s: "HTML Publisher" settings page link text */
                esc_html__('Please set up a template file under %s first.', 'elbutschitos-html-publisher'),
                '<em>' . esc_html__('HTML Publisher', 'elbutschitos-html-publisher') . '</em>'
            ) . '</p>';
            return;
        }

        wp_nonce_field('content2html_deploy_single_box', 'content2html_deploy_single_nonce');
        wp_nonce_field('content2html_template_select', 'content2html_template_nonce');

        if (!empty($settings['extra_templates'])) {
            $currentTemplateId = get_post_meta($post->ID, '_content2html_template_id', true);
            ?>
            <p>
                <label for="content2html_template_id"><?php esc_html_e('Template for this page', 'elbutschitos-html-publisher'); ?></label><br>
                <select id="content2html_template_id" name="content2html_template_id" style="width: 100%;">
                    <option value=""><?php esc_html_e('Default', 'elbutschitos-html-publisher'); ?></option>
                    <?php foreach ($settings['extra_templates'] as $tpl): ?>
                        <option value="<?php echo esc_attr($tpl['id']); ?>" <?php selected($currentTemplateId, $tpl['id']); ?>>
                            <?php echo esc_html($tpl['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('Applied the next time this page is saved.', 'elbutschitos-html-publisher'); ?></span>
            </p>
            <?php
        }
        ?>
        <p>
            <button type="button" class="button button-primary" id="content2html-deploy-single-btn"
                    data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
                <?php esc_html_e('Deploy', 'elbutschitos-html-publisher'); ?>
            </button>
        </p>
        <?php if ($settings['target'] === 'netlify'): ?>
            <p class="description">
                <?php esc_html_e('Note: with Netlify as the target, this button triggers a full rebuild and redeploy of the entire site (Netlify cannot update a single page in isolation).', 'elbutschitos-html-publisher'); ?>
            </p>
        <?php endif; ?>
        <div id="content2html-deploy-single-status" style="margin-top: 8px; font-size: 12px;"></div>
        <?php
    }

    /**
     * Saves the per-page template selection as post meta - runs through
     * WordPress' normal save process (the "Update"/"Publish" button),
     * NOT through our AJAX deploy button.
     */
    public function saveTemplateSelection(int $postId): void {
        if (!isset($_POST['content2html_template_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['content2html_template_nonce'])), 'content2html_template_select')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $templateId = sanitize_text_field(wp_unslash($_POST['content2html_template_id'] ?? ''));

        if ($templateId === '') {
            delete_post_meta($postId, '_content2html_template_id');
        } else {
            update_post_meta($postId, '_content2html_template_id', $templateId);
        }
    }

    public function enqueueAssets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_enqueue_script(
            'content2html-deploy-single',
            CONTENT2HTML_DEPLOY_URL . 'assets/js/single.js',
            ['jquery'],
            CONTENT2HTML_DEPLOY_VERSION,
            true
        );

        wp_localize_script('content2html-deploy-single', 'content2htmlDeploySingle', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('content2html_deploy_ajax'),
            'i18n' => [
                'deploying' => __('Deploying …', 'elbutschitos-html-publisher'),
                'deploy' => __('Deploy', 'elbutschitos-html-publisher'),
                'done' => __('Done.', 'elbutschitos-html-publisher'),
                'error' => __('Error:', 'elbutschitos-html-publisher'),
                'unknownError' => __('Unknown error', 'elbutschitos-html-publisher'),
                'requestError' => __('Error processing the request.', 'elbutschitos-html-publisher'),
            ],
        ]);
    }
}
