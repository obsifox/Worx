<?php
/**
 * Complete data cleanup when the Worx plugin is deleted.
 *
 * Removes the global settings row, runtime flags and every per-image
 * analysis record. Optimized files themselves are intentionally kept:
 * they are now the live media of the site.
 *
 * @package Worx
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Global settings + runtime flags (single-row architecture).
delete_option( 'worx_settings' );
delete_option( 'worx_pending_meta' );
delete_option( '_worx_wizard_redirect' );
delete_transient( 'worx_hub_stats' );

// Per-image analysis records.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_worx_metadata'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
