<?php
/**
 * Run when the plugin is DELETED from the Plugins screen — not on deactivation.
 *
 * Deliberately conservative. Deactivating leaves everything alone, and even
 * deleting keeps the enquiries: they are customer records, and a plugin that
 * silently destroys sales leads because somebody was tidying up the Plugins
 * screen would be indefensible. Only the page's own settings go.
 *
 * @package Vesla_Landing
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* The content tables. Dropped here and nowhere else: deactivating a plugin
   must not destroy the site's copy, and an admin who deactivates to test
   something expects to switch it back on and find the page intact. */
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vesla_content' ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vesla_cars' );    // phpcs:ignore WordPress.DB

delete_option( 'vesla_landing' );
delete_option( 'vesla_landing_pre_tables' ); // the pre-tables blob, kept aside at upgrade
delete_option( 'vesla_landing_migrated' );
delete_option( 'vesla_landing_seen' );
delete_option( 'vesla_landing_page_id' );
delete_option( 'vesla_landing_version' );

/* Enquiries (post type vesla_enquiry) and anything uploaded to the media
   library are intentionally left in place. Remove them by hand if you really
   want them gone — they are under Landing Page → Enquiries. */
