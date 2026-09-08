<?php
/**
 * Template Name: Vesla Landing Page (full width)
 *
 * A deliberately bare template: the document head, the page, the footer hooks.
 *
 * WHY THIS EXISTS
 * The landing page brings its own fixed header bar and its own four-column
 * footer. Rendered through a normal theme template it gets the theme's header
 * and footer as well — so the visitor is handed two of each — and inside a
 * block theme it is also dropped into `is-layout-constrained`, which on
 * Twenty Twenty-Five is a 645px column. A page designed to run edge to edge
 * was being shown in the middle third of the screen.
 *
 * wp_head() and wp_footer() are still called, because dropping them would
 * break every other plugin on the site: analytics, cookie notices, caching
 * plugins and the admin bar all hang off those two hooks.
 *
 * @package Vesla_Landing
 */

defined( 'ABSPATH' ) || exit;

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'vesla-landing-body' ); ?>>
<?php wp_body_open(); ?>

<?php
if ( Vesla_Render::is_vehicle() ) {
	/* A car's own page. There is no post behind this URL — it is a rewrite
	   rule, not an entry — so there is no loop to run. */
	Vesla_Render::vehicle_page();
} else {
	while ( have_posts() ) :
		the_post();
		/* the_content() rather than echoing the shortcode directly, so anything
		   the editor has added above or below it still appears */
		the_content();
	endwhile;
}
?>

<?php wp_footer(); ?>
</body>
</html>
