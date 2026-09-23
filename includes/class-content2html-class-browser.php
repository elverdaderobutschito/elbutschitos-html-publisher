<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Powers the "Browse available classes" tool next to the Class Mapping
 * textarea (Content tab) - fetches a single post/page's RENDERED HTML
 * (content.rendered, the exact same content this plugin's own export
 * pipeline works with) and lists every distinct CSS class found on it
 * that matches one of the currently configured "Remove CSS class
 * prefixes" - so a designer who doesn't know Gutenberg/page-builder
 * internals can discover the actual class names on a real page instead
 * of having to guess them.
 */
class Content2HTML_ClassBrowser {
    /**
     * @param string[] $prefixes The currently configured class prefixes
     *                           (see Content2HTML_Generator's
     *                           classPrefixesToRemove) - only classes
     *                           matching one of these are relevant here;
     *                           everything else (a theme's own utility
     *                           classes, Bootstrap classes the designer
     *                           already controls, etc.) is deliberately
     *                           left out, since those already work fine
     *                           and don't need mapping.
     *
     * @return array<int, array{class: string, tag: string, preview: string}>
     *
     * @throws RuntimeException if the post/page can't be found or the
     *                          REST request fails.
     */
    public static function getClasses(int $postId, string $postType, array $prefixes): array {
        if ($prefixes === []) {
            return [];
        }

        $restBase = Content2HTML_BatchController::restBaseForPostType($postType);
        $route = '/wp/v2/' . $restBase . '/' . $postId;

        $request = new WP_REST_Request('GET', $route);
        // Same context as actual generation time (see
        // Content2HTML_GeneratorFactory::provideData()) - the rendered
        // HTML must match what really ends up in the export.
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

        $html = is_array($decoded) ? ($decoded['content']['rendered'] ?? '') : '';

        if (!is_string($html) || trim($html) === '') {
            return [];
        }

        return self::extractClasses($html, $prefixes);
    }

    /**
     * @param string[] $prefixes
     *
     * @return array<int, array{class: string, tag: string, preview: string}>
     */
    private static function extractClasses(string $html, array $prefixes): array {
        $dom = content2html_str_get_html($html);

        if ($dom === false) {
            return [];
        }

        $seen = [];
        $result = [];

        try {
            foreach ($dom->find('[class]') as $tag) {
                $classes = preg_split('/\s+/', trim((string) $tag->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);

                foreach ($classes as $class) {
                    // Deduplicate by class name - a designer testing
                    // several instances of the same block type (e.g.
                    // three separate Columns blocks) should see
                    // "wp-block-columns" once, not three times.
                    if (isset($seen[$class]) || !self::matchesAnyPrefix($class, $prefixes)) {
                        continue;
                    }

                    $seen[$class] = true;
                    $result[] = [
                        'class' => $class,
                        'tag' => $tag->tag,
                        'preview' => self::preview($tag),
                    ];
                }
            }
        } finally {
            $dom->clear();
        }

        return $result;
    }

    /**
     * @param string[] $prefixes
     */
    private static function matchesAnyPrefix(string $class, array $prefixes): bool {
        foreach ($prefixes as $prefix) {
            // Matches both "wp-block-columns" (starts with the prefix)
            // and a bare "wp" (the prefix with its trailing dash
            // removed) - same rule the actual removal logic uses (see
            // Content2HTML_Generator::removeWPClassTokens()).
            if (strpos($class, $prefix) === 0 || $class === rtrim($prefix, '-')) {
                return true;
            }
        }

        return false;
    }

    private static function preview(object $tag): string {
        $text = wp_strip_all_tags((string) $tag->innertext());
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '...' : $text;
        }

        return strlen($text) > 60 ? substr($text, 0, 60) . '...' : $text;
    }
}
