<?php
/**
 * Signature for the graphql-php class this plugin instantiates.
 *
 * GraphQL\Deferred ships inside WPGraphQL, which is an optional integration
 * rather than a dependency: it is not in composer.json and is not installed in
 * CI, so PHPStan has no declaration for it. The single call site is guarded by
 * class_exists(), so the code is correct — the analyser just has nothing to
 * check it against.
 *
 * This file is scanned for symbols only and never loaded at runtime.
 *
 * @see https://github.com/webonyx/graphql-php/blob/master/src/Deferred.php
 */

namespace GraphQL;

if (!\class_exists('GraphQL\Deferred')) {
    /**
     * A promise whose resolution the executor defers until the current
     * resolution pass has finished.
     */
    class Deferred
    {
        /**
         * @param callable():mixed $executor
         */
        public function __construct(callable $executor)
        {
        }
    }
}
