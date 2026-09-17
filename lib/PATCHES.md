# Patches applied to lib/simple_html_dom.php

This file is a third-party library (simplehtmldom,
http://sourceforge.net/projects/simplehtmldom/, MIT license), bundled
directly rather than pulled in via Composer. We have made a small number
of targeted modifications to the upstream source. **If you ever update
this file to a newer upstream version, re-apply every patch below** -
none of them are optional, they all exist for a specific reason (mostly
WordPress.org plugin review requirements).

## 1. Direct access guard

Added near the top of the file, right after the opening `<?php` and the
file's own header docblock:

```php
if (!defined('ABSPATH')) {
    exit;
}
```

Why: required by the WordPress.org Plugin Check tool - prevents the file
from being executed directly if somehow requested over the web.

## 2. Prefixed global function names

Three functions were declared in the global PHP namespace with generic
names that could collide with another plugin bundling the same library.
Renamed (definition **and** all internal call sites within this file):

| Original name       | Renamed to                       |
|----------------------|-----------------------------------|
| `file_get_html()`    | `content2html_file_get_html()`    |
| `str_get_html()`     | `content2html_str_get_html()`     |
| `dump_html_tree()`   | `content2html_dump_html_tree()`   |

Note: `str_get_html()` is also called from
`lib/class-content2html-generator.php` (`tidyHtml()` method) - that call
site needs updating too if you rename these again.

Why: flagged by the WordPress.org review
(`WordPress.NamingConventions.PrefixAllGlobals`) - global function names
without a plugin-specific prefix risk fatal "cannot redeclare" errors if
another active plugin bundles the same library.

## 3. Prefixed global constants

Three `define()`d constants, same reasoning as above. Renamed (both the
`define()` call and every place that reads the constant):

| Original name              | Renamed to                              |
|------------------------------|-------------------------------------------|
| `DEFAULT_TARGET_CHARSET`    | `CONTENT2HTML_DEFAULT_TARGET_CHARSET`    |
| `DEFAULT_BR_TEXT`           | `CONTENT2HTML_DEFAULT_BR_TEXT`           |
| `DEFAULT_SPAN_TEXT`         | `CONTENT2HTML_DEFAULT_SPAN_TEXT`         |

Note: the `HDOM_*` constants (`HDOM_TYPE_ELEMENT`, `HDOM_INFO_BEGIN`
etc.) were not flagged by this earlier review pass and were initially
left unprefixed - a later review round did flag them after all, see
section 5 below for how they were subsequently renamed.

## 4. Escaping in dump() / dump_node()

These two debug/diagnostic methods build up HTML-ish output via string
concatenation and `echo`. All variable interpolation is wrapped in
WordPress's own `esc_html()` (not plain `htmlspecialchars()` - the
WordPress.org automated scanner specifically does not recognize
`htmlspecialchars()` as sufficient escaping, only WordPress's own
`esc_*()` functions).

Two spots additionally carry a `// phpcs:ignore
WordPress.Security.EscapeOutput.OutputNotEscaped` comment (in `dump()`
and at the final `echo $string;` in `dump_node()`) - these are false
positives the automated scanner still flags even after the `esc_html()`
fix, because it can't trace escaping across the many lines where
`$string` gets built up incrementally. Both are documented inline with
why they're safe to ignore. Neither method is ever called anywhere in
this plugin's own code (confirmed via a full-codebase search) - they
only exist because they're part of the upstream library.

## 5. Prefixed the two global classes, all HDOM_* constants, and $debug_object

The two classes declared in the global PHP namespace, all 18 `HDOM_*`
constants, and the global `$debug_object` variable were still unprefixed
- flagged by a later WordPress.org review round ("Generic
function/class/define/namespace/option names") as the same
collision/naming risk described in sections 2 and 3. Renamed (definition
and all internal usages within this file):

| Original name          | Renamed to                        |
|--------------------------|--------------------------------------|
| `class simple_html_dom` | `class Content2HTML_SimpleHtmlDom` |
| `class simple_html_dom_node` | `class Content2HTML_SimpleHtmlDomNode` |
| `HDOM_*` (18 constants) | `CONTENT2HTML_HDOM_*`             |
| `$debug_object`         | `$content2html_debug_object`      |

Why: same `WordPress.NamingConventions.PrefixAllGlobals` reasoning as
sections 2 and 3 - a generic global class name, constant, or variable
risks a fatal "cannot redeclare" error if another active plugin bundles
the same library.

## Related: loading guard (not in this file)

`lib/class-content2html-generator.php` guards its `require_once` of this
file with:

```php
if (!class_exists('Content2HTML_SimpleHtmlDom', false)) {
    require_once __DIR__ . '/simple_html_dom.php';
}
```

This protects against a fatal "cannot redeclare class" error if another
active plugin has already loaded its own copy of simplehtmldom. Not a
patch to this file itself, but relevant context if you're touching how
this library gets loaded.
