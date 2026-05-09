<?php
/**
 * Plugin Name: Nice Hair Core
 * Description: Core plugin for project entities, seed logic and future custom Gutenberg blocks.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: OpenAI
 * Text Domain: nice-hair-core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Core/Plugin.php';

Nice_Hair_Core\Core\Plugin::boot();
