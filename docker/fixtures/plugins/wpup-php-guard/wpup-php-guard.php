<?php
/**
 * Plugin Name: Wpup PHP Guard
 * Description: Test fixture that only declares a minimum PHP version (8.2). It does nothing else.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * License: GPL-2.0-or-later
 */

// Intentionally empty: WordPress refuses to activate this plugin on PHP < 8.2.
if (! defined('ABSPATH')) {
    exit;
}
