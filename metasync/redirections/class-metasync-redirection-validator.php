<?php

/**
 * Shared validation helpers for redirection destinations and regex patterns.
 *
 * Centralises the checks that every write path (admin form, importers, MCP
 * tools, engine render) must agree on, so a fix in one place cannot drift
 * away from the others.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Engineering Team <support@searchatlas.com>
 */

# Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Redirection_Validator
{
    /**
     * Maximum accepted regex pattern length.
     */
    const MAX_REGEX_LENGTH = 500;

    /**
     * Reject destination syntax that slips past wp_validate_redirect.
     *
     * Browsers treat a backslash as a path separator, so '/\evil.com' passes
     * wp_validate_redirect (parse_url sees no host) yet navigates off-site.
     * A protocol-relative '//evil.com' host is likewise never an internal
     * destination no matter how it was stored.
     *
     * @param string $url Destination as stored/entered.
     * @return bool True when the syntax itself is not an evasion vector.
     */
    public static function is_safe_destination_syntax($url)
    {
        $url = (string) $url;

        if (strpos($url, '\\') !== false) {
            return false;
        }

        if (strpos($url, '//') === 0) {
            return false;
        }

        return true;
    }

    /**
     * Normalize a destination so wp_validate_redirect sees its true shape.
     *
     * Legacy/imported rows may contain backslashes; converting them to
     * forward slashes lets the host check actually run.
     *
     * @param string $url Destination to normalize.
     * @return string Normalized URL.
     */
    public static function normalize_destination($url)
    {
        return str_replace('\\', '/', (string) $url);
    }

    /**
     * Screen a regex pattern for catastrophic backtracking before it is stored.
     *
     * Applies a hard length cap and rejects nested quantification — a
     * quantified group whose body itself contains a quantifier, including
     * via alternation ('(a+)*', '((a|a)*)*') — plus the legacy quantified
     * character-class shapes.
     *
     * @param mixed $pattern Pattern without delimiters. Accepts any type: the
     *                       admin path derives it from preg_replace(), which
     *                       returns null on failure, and importers pass raw
     *                       column values straight from a foreign table.
     * @return bool True when the pattern is safe enough to store.
     */
    public static function is_regex_safe($pattern)
    {
        if (!is_string($pattern) || $pattern === '') {
            return true;
        }

        if (strlen($pattern) > self::MAX_REGEX_LENGTH) {
            return false;
        }

        if (self::has_nested_quantified_group($pattern)) {
            return false;
        }

        // Legacy guard: quantifier inside a group with the group quantified,
        // or a doubled quantifier on a character class.
        if (preg_match('/(\([^)]*[+*][^)]*\))[+*?{]|(\[[^\]]*\])[+*][+*?{]/', $pattern)) {
            return false;
        }

        return true;
    }

    /**
     * Detect a quantified group whose body contains another quantifier.
     *
     * '(a+)*' and '((a|a)*)*' blow up exponentially on backtracking; the
     * simpler '(a|a)+' stays linear and is allowed. Escaped characters and
     * character-class contents are ignored so '(?:[a+])*' is not a false hit.
     *
     * @param string $pattern Pattern without delimiters.
     * @return bool True when nested quantification is present.
     */
    private static function has_nested_quantified_group($pattern)
    {
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            if ($pattern[$i] !== ')' || $i + 1 >= $len) {
                continue;
            }

            $next = $pattern[$i + 1];
            $quantified = ($next === '*' || $next === '+' || $next === '?');
            if (!$quantified && $next === '{') {
                // Only a real {n[,m]} bound counts, not '{' as a literal.
                $quantified = (bool) preg_match('/^\{\d+(,\d*)?\}/', substr($pattern, $i + 1));
            }
            if (!$quantified) {
                continue;
            }

            // Walk back to the '(' matching this ')'.
            $depth = 1;
            $j = $i - 1;
            while ($j >= 0 && $depth > 0) {
                $char = $pattern[$j];
                if ($char === ')') {
                    $depth++;
                } elseif ($char === '(') {
                    $depth--;
                } elseif ($char === '\\') {
                    $j--; // Skip the escaped character.
                }
                $j--;
            }
            if ($depth !== 0) {
                continue; // Unbalanced; validity is checked elsewhere.
            }

            $body_start = $j + 2;
            $body = substr($pattern, $body_start, $i - $body_start);

            // Ignore escaped characters and character-class contents.
            $body = preg_replace('/\\\\./s', '', $body);
            $body = preg_replace('/\[[^\]]*\]/', '', $body);

            if ($body !== null && preg_match('/[+*]|\{\d+,?\d*\}/', $body)) {
                return true;
            }
        }

        return false;
    }
}
