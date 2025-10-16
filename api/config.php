<?php
/**
 * DEPRECATED: This file is deprecated as of 2025-10-15
 *
 * Database credentials should no longer be hardcoded.
 * Use config-loader.php instead, which reads from .env file.
 *
 * This file is kept for backwards compatibility during transition.
 */

trigger_error(
    'api/config.php is deprecated. Use config-loader.php instead.',
    E_USER_DEPRECATED
);

// Load the new config system
require_once __DIR__ . '/config-loader.php';
