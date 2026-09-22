<?php
/**
 * Signatures for the WPGraphQL functions this plugin calls.
 *
 * WPGraphQL is an optional integration, not a dependency: it is not in
 * composer.json and is not installed in CI, so PHPStan cannot see these
 * functions and reports every call as undefined. Every call site is already
 * guarded by function_exists(), so the calls are correct — the analyser simply
 * has no declaration to check them against.
 *
 * This file is scanned for symbols only and never loaded at runtime.
 *
 * @see https://www.wpgraphql.com/functions
 */

if (!function_exists('register_graphql_object_type')) {
    /**
     * @param string              $type_name
     * @param array<string,mixed> $config
     * @return void
     */
    function register_graphql_object_type($type_name, array $config)
    {
    }
}

if (!function_exists('register_graphql_field')) {
    /**
     * @param string              $type_name
     * @param string              $field_name
     * @param array<string,mixed> $config
     * @return void
     */
    function register_graphql_field($type_name, $field_name, array $config)
    {
    }
}
