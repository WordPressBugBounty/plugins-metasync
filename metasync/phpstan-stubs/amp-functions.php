<?php
/**
 * PHPStan stubs for optional AMP-plugin functions.
 *
 * These functions ship with the AMP plugin and are guarded at runtime via
 * func_is_available(); PHPStan has no symbol source for them in this repo,
 * so their declarations are provided here for static analysis only. This
 * file is never loaded at runtime — phpstan.neon references it via scanFiles.
 */

namespace {
    function amp_is_request(): bool
    {
        return false;
    }

    function is_amp_endpoint(): bool
    {
        return false;
    }
}
