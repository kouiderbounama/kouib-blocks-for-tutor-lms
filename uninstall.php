<?php
/**
 * Kouib Blocks uninstaller.
 *
 * Deletes everything the plugin created: the settings option, the cache-key
 * registry, and every transient, post meta and scheduled event it uses.
 *
 * Course content itself (courses, course categories and enrollments) belongs
 * to Tutor LMS and is intentionally left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Settings and the active-cache-keys registry.
delete_option( 'kouib_settings' );
delete_option( 'kouib_active_cache_keys' );

// 2. Every transient the plugin created. Cache keys start with one of the
//    kouib_* / kouibc_* / kouibcat_* prefixes below (rendered grids, category
//    grids, cat pages, search results, platform stats, per-IP rate limits and
//    the admin notice). A namespaced prefix makes a single LIKE safe — no
//    foreign transient is affected.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kouib\_%' OR option_name LIKE '_transient_timeout_kouib\_%' OR option_name LIKE '_transient_kouibc\_%' OR option_name LIKE '_transient_timeout_kouibc\_%' OR option_name LIKE '_transient_kouibcat\_%' OR option_name LIKE '_transient_timeout_kouibcat\_%'"
);

// 3. Post meta written on Tutor courses (student count snapshot).
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_kouib_students_count'"
);

// 4. Scheduled bookkeeping events.
wp_clear_scheduled_hook( 'kouib_delayed_students_update' );
wp_clear_scheduled_hook( 'kouib_sync_students_batch' );