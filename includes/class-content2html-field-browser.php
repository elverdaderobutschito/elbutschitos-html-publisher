<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Powers the "Available fields" browser next to the Data Injection
 * Rules textarea (Content tab) - fetches a single post/page's REST API
 * data (reusing Content2HTML_GeneratorFactory's existing rest_do_request()
 * plumbing, not a new HTTP/cURL call) and flattens it into a list of
 * ready-to-use "path => value preview" entries, in the exact "->" syntax
 * the plugin's own injection rules expect.
 */
class Content2HTML_FieldBrowser {
    /**
     * WordPress core REST fields that are really just a foreign key (a
     * plain ID) into another endpoint - "author" is a user ID, not
     * anything human-readable by itself. Resolving these automatically
     * saves the user from having to already know about the
     * "sourcePath|endpoint|dataPoint" syntax just to discover that it's
     * needed here.
     */
    private const LINKABLE_SINGLE = [
        'author' => 'users',
        'featured_media' => 'media',
    ];

    /**
     * Same idea, but for fields that hold an ARRAY of IDs (a post can
     * have several categories/tags) - only the first ID is fetched as a
     * representative sample, since every entry in the array has the same
     * shape.
     */
    private const LINKABLE_ARRAY = [
        'categories' => 'categories',
        'tags' => 'tags',
    ];

    /**
     * @return array<int, array{path: string, preview: string, is_array: bool, is_linked: bool}>
     *
     * @throws RuntimeException if the post/page can't be found or the
     *                          REST request fails.
     */
    public static function getFields(int $postId, string $postType): array {
        $restBase = Content2HTML_BatchController::restBaseForPostType($postType);
        $route = '/wp/v2/' . $restBase . '/' . $postId;

        $request = new WP_REST_Request('GET', $route);
        // Must match the context used at actual generation time (see
        // Content2HTML_GeneratorFactory::provideData()) - otherwise this
        // browser could show fields (e.g. edit-context-only "raw"
        // variants) that aren't actually available when the site is
        // really generated.
        $request->set_param('context', 'view');

        $response = rest_do_request($request);

        if ($response->is_error()) {
            $error = $response->as_error();
            throw new RuntimeException(esc_html(sprintf(
                /* translators: %s: error message */
                __('Could not load this post: %s', 'elbutschitos-html-publisher'),
                $error->get_error_message()
            )));
        }

        $server = rest_get_server();
        $data = $server->response_to_data($response, false);
        $decoded = json_decode((string) wp_json_encode($data), true);

        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        self::walk($decoded, '', $fields);
        self::appendLinkedFields($decoded, $fields);

        return $fields;
    }

    /**
     * Follows the known core "foreign key" fields (author, featured
     * media, categories, tags) one level deep and appends the linked
     * resource's own fields, already formatted as
     * "sourceField|endpoint|leafPath" - exactly the syntax the plugin's
     * own injection rules need for these. The plain ID field itself (see
     * walk()) is left in the list too, so both are visible.
     */
    private static function appendLinkedFields(array $decoded, array &$fields): void {
        foreach (self::LINKABLE_SINGLE as $field => $endpoint) {
            if (!isset($decoded[$field]) || !is_numeric($decoded[$field])) {
                continue;
            }

            $id = (int) $decoded[$field];

            if ($id > 0) {
                self::fetchAndAppendLinked($field, $endpoint, $id, $fields);
            }
        }

        foreach (self::LINKABLE_ARRAY as $field => $endpoint) {
            if (!isset($decoded[$field]) || !is_array($decoded[$field]) || $decoded[$field] === []) {
                continue;
            }

            $firstId = (int) reset($decoded[$field]);

            if ($firstId > 0) {
                self::fetchAndAppendLinked($field, $endpoint, $firstId, $fields);
            }
        }
    }

    /**
     * Fetches one linked resource (e.g. /wp/v2/users/1) and appends its
     * fields to $fields with the "sourceField|endpoint|" prefix. Failures
     * here (the linked resource being gone, a REST error etc.) are
     * deliberately swallowed - this is an enrichment on top of the main
     * field list, not something that should break the whole browser if
     * it doesn't work out for one field.
     */
    private static function fetchAndAppendLinked(string $sourceField, string $endpoint, int $id, array &$fields): void {
        try {
            $request = new WP_REST_Request('GET', '/wp/v2/' . $endpoint . '/' . $id);
            $request->set_param('context', 'view');
            $response = rest_do_request($request);

            if ($response->is_error()) {
                return;
            }

            $server = rest_get_server();
            $data = $server->response_to_data($response, false);
            $linkedDecoded = json_decode((string) wp_json_encode($data), true);

            if (!is_array($linkedDecoded)) {
                return;
            }

            $linkedFields = [];
            self::walk($linkedDecoded, '', $linkedFields);

            foreach ($linkedFields as $linkedField) {
                $fields[] = [
                    'path' => $sourceField . '|' . $endpoint . '|' . $linkedField['path'],
                    'preview' => $linkedField['preview'],
                    'is_array' => $linkedField['is_array'],
                    'is_linked' => true,
                    'is_empty' => $linkedField['is_empty'],
                ];
            }
        } catch (Throwable $e) {
            // Swallowed deliberately - see the docblock above.
        }
    }

    /**
     * Recursively walks the decoded REST response. Arrays get one of two
     * treatments:
     *  - a plain list of scalars (e.g. categories/tags - term IDs) is
     *    recorded as ONE flagged entry rather than being recursed into,
     *    since the raw IDs aren't directly useful in an injection rule -
     *    the existing "sourcePath|endpoint|dataPoint" syntax (see the
     *    tutorial) is the right tool for those instead.
     *  - anything else (a list of objects, e.g. Yoast's og_image, or an
     *    associative object) is recursed into normally, since the actual
     *    structured data is already present in the response - no
     *    additional REST call needed to make use of it.
     */
    private static function walk(array $data, string $prefix, array &$fields): void {
        if (self::isList($data) && self::allScalars($data)) {
            $fields[] = [
                'path' => $prefix,
                'preview' => self::listPreview($data),
                'is_array' => true,
                'is_linked' => false,
                'is_empty' => $data === [],
            ];
            return;
        }

        foreach ($data as $key => $value) {
            // "protected" is WordPress' own internal flag (can this field
            // only be read/edited by users with the right capability?),
            // not actual content - never useful as an injection target,
            // so it's left out entirely rather than just hidden behind
            // the "show empty fields" toggle.
            if ($key === 'protected') {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '->' . $key;

            if (is_array($value)) {
                self::walk($value, $path, $fields);
            } else {
                $fields[] = [
                    'path' => $path,
                    'preview' => self::scalarPreview($value),
                    'is_array' => false,
                    'is_linked' => false,
                    'is_empty' => $value === null || $value === '',
                ];
            }
        }
    }

    /**
     * PHP 7.4-compatible equivalent of PHP 8.1's array_is_list() (this
     * plugin's minimum supported PHP version is 7.4).
     */
    private static function isList(array $arr): bool {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private static function allScalars(array $arr): bool {
        foreach ($arr as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    private static function listPreview(array $arr): string {
        $sample = array_slice(array_map('strval', $arr), 0, 3);
        $preview = '[' . count($arr) . ($arr !== [] ? '] ' . implode(', ', $sample) : ']');

        return count($arr) > 3 ? $preview . ', ...' : $preview;
    }

    private static function scalarPreview($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '(empty)';
        }

        $text = wp_strip_all_tags((string) $value);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        // Fall back to the byte-based functions if mbstring isn't
        // available (not guaranteed on every hosting environment,
        // unlike most other functions this plugin relies on) - slightly
        // less accurate for multi-byte truncation, but this is only a
        // short preview string, not anything written to the generated
        // site.
        if (function_exists('mb_strlen')) {
            return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '...' : $text;
        }

        return strlen($text) > 80 ? substr($text, 0, 80) . '...' : $text;
    }
}
