<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes WordPress forms (Contact Form 7, Gravity Forms, native Gutenberg
 * form blocks etc.) functional on the generated static page - without
 * WordPress/PHP running in the background, they would otherwise just
 * post into the void.
 *
 * Two strategies, depending on the deployment target:
 *  - Netlify: HTML forms automatically get data-netlify="true", method="POST"
 *    (Netlify Forms only intercepts POST requests; a missing or GET method
 *    is corrected) plus a hidden form-name field (Netlify Forms then detects
 *    them automatically at deploy time, no server code needed).
 *  - SFTP (classic PHP hosting): the form's action is rewritten to a
 *    bundled PHP handler that emails the submitted data.
 */
class Content2HTML_Forms {
    private const HANDLER_TEMPLATE_PATH = CONTENT2HTML_DEPLOY_DIR . 'includes/form-handler-template.txt';
    private const HANDLER_FILENAME = 'form-handler.php';
    private const VALIDATION_SCRIPT_SOURCE = CONTENT2HTML_DEPLOY_DIR . 'includes/form-validate.js';
    private const VALIDATION_SCRIPT_FILENAME = 'content2html-form-validate.js';

    /**
     * Local build directories must never contain executable PHP or an
     * active .htaccess (WP.org review: "Writing data to disallowed or
     * incorrect locations"). These files are therefore written locally
     * under harmless, non-executable dummy names and only renamed to
     * their real, active name at actual SFTP upload time (see
     * Content2HTML_SftpUploader::uploadFile()) - on the WordPress
     * installation itself, they never exist under an executable/active
     * name.
     */
    private const SFTP_DUMMY_TO_REAL_FILENAME = [
        'content2html-form-handler.source.txt' => self::HANDLER_FILENAME,
        'content2html-htaccess.source.txt' => '.htaccess',
        'content2html-form-validate.source.txt' => self::VALIDATION_SCRIPT_FILENAME,
    ];

    /**
     * Exposes the dummy-to-real filename mapping to the SFTP uploader,
     * which performs the actual rename at upload time.
     */
    public static function getSftpDummyToRealFilenameMap(): array {
        return self::SFTP_DUMMY_TO_REAL_FILENAME;
    }

    /**
     * Configures the generator to match the current target and form
     * settings. No-op if forms are not enabled.
     */
    public static function applyToGenerator(Content2HTML_Generator $generator, array $settings): void {
        if (empty($settings['forms_enabled'])) {
            return;
        }

        // Client-side required-field/email validation runs along
        // regardless of the target - purely in the browser, no server
        // overhead. The messages are translated here (server-side, where
        // WordPress' i18n is available) and passed to the static JS via
        // data attributes, so the generated site shows validation
        // messages in whatever language this WordPress install is
        // configured for - not hard-coded to one language regardless of
        // the site's actual audience.
        $validationScriptPath = '/' . self::VALIDATION_SCRIPT_FILENAME;
        $validationMessages = [
            'validation_msg_required' => __('This field is required.', 'elbutschitos-html-publisher'),
            'validation_msg_select_one' => __('Please select at least one option.', 'elbutschitos-html-publisher'),
        ];

        if ($settings['target'] === 'netlify') {
            $generator->setFormHandling('netlify', array_merge([
                'redirect_url' => self::safeguardNetlifyRedirect($settings['form_redirect_url']),
                'validation_script' => $validationScriptPath,
                'honeypot_field' => $settings['form_honeypot_field'] !== '' ? $settings['form_honeypot_field'] : '_gotcha',
            ], $validationMessages));
            return;
        }

        $customAction = trim($settings['form_custom_action']);

        $generator->setFormHandling('handler', array_merge([
            'handler_path' => $customAction !== '' ? $customAction : '/' . self::HANDLER_FILENAME,
            'honeypot_field' => $settings['form_honeypot_field'] !== '' ? $settings['form_honeypot_field'] : '_gotcha',
            'redirect_url' => $settings['form_redirect_url'],
            'validation_script' => $validationScriptPath,
        ], $validationMessages));
    }

    /**
     * Prevents a "thank you" page accidentally pointing to the site's own
     * WordPress domain from being shipped on Netlify - that would make
     * the form's action point to WordPress instead of the Netlify site,
     * which would make the request completely bypass Netlify's form
     * capture (the form appears to "work", but never shows up in
     * Netlify's Forms overview). Such a case is already flagged with a
     * warning when saving the settings (see
     * Content2HTML_Settings::handleSave()), and additionally guarded against
     * here at runtime, in case the faulty setting was saved anyway.
     */
    private static function safeguardNetlifyRedirect(string $redirectUrl): string {
        if ($redirectUrl === '') {
            return '';
        }

        $redirectHost = wp_parse_url($redirectUrl, PHP_URL_HOST);
        $ownHost = wp_parse_url(home_url(), PHP_URL_HOST);

        if ($redirectHost !== null && $redirectHost === $ownHost) {
            return ''; // ignore it -> the generator removes the action, the form submits to itself
        }

        return $redirectUrl;
    }

    /**
     * Generates the configured PHP form handler in the build directory -
     * only relevant for SFTP targets (Netlify doesn't need any server
     * code of its own, see applyToGenerator()), and only if no custom
     * form target is configured (otherwise our handler would go unused
     * anyway and would just get uploaded needlessly).
     */
    public static function buildHandlerFile(string $buildDir, array $settings): void {
        if (empty($settings['forms_enabled']) || $settings['target'] === 'netlify') {
            return;
        }

        if (trim($settings['form_custom_action']) !== '') {
            return; // a custom target is configured - our handler isn't needed
        }

        $template = file_get_contents(self::HANDLER_TEMPLATE_PATH);

        if ($template === false) {
            return;
        }

        $replacements = [
            '{{RECIPIENT}}' => self::escapeForSingleQuotedPhpString($settings['form_recipient_email']),
            '{{FROM_EMAIL}}' => self::escapeForSingleQuotedPhpString($settings['form_from_email']),
            '{{SUBJECT_PREFIX}}' => self::escapeForSingleQuotedPhpString($settings['form_subject_prefix']),
            '{{REDIRECT_URL}}' => self::escapeForSingleQuotedPhpString($settings['form_redirect_url']),
            '{{HONEYPOT_FIELD}}' => self::escapeForSingleQuotedPhpString(
                $settings['form_honeypot_field'] !== '' ? $settings['form_honeypot_field'] : '_gotcha'
            ),
        ];

        $content = strtr($template, $replacements);

        $dummyFilename = array_search(self::HANDLER_FILENAME, self::SFTP_DUMMY_TO_REAL_FILENAME, true);

        file_put_contents(rtrim($buildDir, '/') . '/' . $dummyFilename, $content);

        self::ensureLogProtection($buildDir);
    }

    /**
     * Copies the static validation script into the build directory -
     * relevant for BOTH targets (Netlify and SFTP), since it's purely
     * client-side.
     */
    public static function buildValidationScript(string $buildDir, array $settings): void {
        if (empty($settings['forms_enabled'])) {
            return;
        }

        $source = self::VALIDATION_SCRIPT_SOURCE;

        if (!is_file($source)) {
            return;
        }

        // Netlify's ZIP-based deploy carries no execution risk for a
        // static JS file, so it's written under its real name there. For
        // SFTP targets, the dummy name is used and renamed to the real
        // one only at upload time (see SFTP_DUMMY_TO_REAL_FILENAME).
        $filename = $settings['target'] === 'netlify'
            ? self::VALIDATION_SCRIPT_FILENAME
            : array_search(self::VALIDATION_SCRIPT_FILENAME, self::SFTP_DUMMY_TO_REAL_FILENAME, true);

        copy($source, rtrim($buildDir, '/') . '/' . $filename);
    }

    /**
     * Protects form-handler.log (contains recipient addresses/subject
     * lines) from direct web access. Appends the rule to an existing
     * .htaccess in the build root instead of overwriting it, if one
     * already exists.
     */
    private static function ensureLogProtection(string $buildDir): void {
        $rule = "\n<Files \"form-handler.log\">\n    Require all denied\n</Files>\n";
        $htaccessDummyFilename = array_search('.htaccess', self::SFTP_DUMMY_TO_REAL_FILENAME, true);
        $htaccessPath = rtrim($buildDir, '/') . '/' . $htaccessDummyFilename;

        $existing = is_file($htaccessPath) ? (string) file_get_contents($htaccessPath) : '';

        if (strpos($existing, 'form-handler.log') !== false) {
            return; // rule already present
        }

        file_put_contents($htaccessPath, $existing . $rule);
    }

    /**
     * Prevents values from the settings (e.g. an email address containing
     * an apostrophe) from breaking out of the surrounding PHP string
     * literal in the generated handler.
     */
    private static function escapeForSingleQuotedPhpString(string $value): string {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
