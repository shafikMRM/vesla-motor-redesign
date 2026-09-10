<?php
/**
 * Plugin Name:       Vesla Landing Page
 * Plugin URI:        https://veslamotors.com/
 * Description:       The whole one-page site — cars, text, photographs, colours and contact details — edited from a single screen under “Landing Page”. Put the shortcode [vesla_landing] on a page to show it.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Vesla Motors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vesla-landing
 * Domain Path:       /languages
 *
 * @package Vesla_Landing
 */

/*
 * ─────────────────────────────────────────────────────────────────────────────
 *  CONTENTS
 *
 *    1 · SETUP                              Constants, and the one place the plugin is wired together.
 *    2 · THE SCHEMA                         Every editable field, described once.
 *    3 · STORAGE, DEFAULTS AND SANITISING   One option row.
 *    4 · THE EDITOR SCREEN                  One scrolling screen for the whole page.
 *    5 · RENDERING THE PAGE                 Builds the markup, the head tags and the structured data.
 *    6 · THE ENQUIRY FORM                   One validation path behind two doors.
 *    7 · THE ENQUIRIES SCREENS              Makes the stored copy visible — a delivery flag nobody can see is not a safety net.
 *    8 · THE API                            What the static front end reads and posts to.
 *    9 · PUBLISHING THE STATIC PAGE         Writes index.
 *   10 · ACTIVATION                         First run: seed the content and build the page.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  ONE FILE, ON PURPOSE — but the rule that matters is unchanged: section 2,
 *  the schema, describes every editable field, and three things are generated
 *  from it and nothing else — the editor screen, the save-time sanitiser and
 *  the shipped defaults. Add a field there and all three follow. Add it
 *  anywhere else and it will be unsaveable, uncleaned or blank.
 *
 *  The sanitiser walks the SCHEMA, not the submitted data. That direction is
 *  the safety property: anything posted that the schema does not describe is
 *  dropped rather than stored.
 *
 *  Escaping on output, always: esc_html for text, esc_attr inside attributes,
 *  esc_url for every href and src, wp_json_encode for anything handed to
 *  JavaScript. There are no exceptions in this file; do not start one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  THINGS THAT LOOK WRONG AND ARE NOT
 *
 *  · The enquiry checks run in this order and must stay in it:
 *        rate limit → nonce → honeypot → validation → mail → store
 *    The limit is first or it is not a limit; the store is last and happens
 *    whether or not the mail sent, because shared hosting frequently cannot
 *    send mail and a silently lost sales lead is the worst thing this plugin
 *    could produce. Section 7 is what makes that stored copy visible.
 *
 *  · Enquiries are gated on `manage_options`, not `edit_posts`. With
 *    capability_type => 'post' an EDITOR could read every customer's phone
 *    number by typing edit.php?post_type=vesla_enquiry. Hiding a menu item is
 *    not access control.
 *
 *  · A stale nonce answers with its own code so app.js can fetch a fresh one
 *    and resubmit once. Most cPanel hosts run a full-page cache, which serves
 *    the nonce minted when the page was cached — past its lifetime EVERY
 *    visitor is rejected, at exactly the moment the site is busy enough to be
 *    cached.
 *
 *  · The REST enquiry route has no nonce at all, deliberately: a static HTML
 *    file has no PHP to print one. Origin, honeypot and rate limit stand in.
 *
 *  · Rate limiting hashes the IP with the site salt and never stores it raw.
 *    REMOTE_ADDR only — X-Forwarded-For is trivially forged.
 *
 *  · contact → form_to must never leave the server. It is the destination
 *    inbox, often private. Vesla_Rest::PRIVATE_FIELDS is the denylist.
 *
 *  · Section 9 writes the page with the cars ALREADY IN THE MARKUP. The test
 *    that matters: load the published file with JavaScript disabled and the
 *    listings must be visible. If they are not, nothing else about the SEO
 *    work matters.
 *
 *  · assets/ holds the files a browser downloads — stylesheets, scripts,
 *    photographs. They cannot live in this file: a browser needs real URLs it
 *    can cache, and the published static page loads them directly.
 *    admin.css / admin.js are in there too, and are excluded from the copy
 *    that goes to the public site.
 *
 *  Prefixes: vesla_ for functions, options, actions and meta keys; Vesla_ for
 *  classes; vesla-landing for the text domain. No direct $wpdb queries.
 * ─────────────────────────────────────────────────────────────────────────────
 */

defined( 'ABSPATH' ) || exit;

/* ========================================================================== */
/*  1 · SETUP
 *
 *  Constants, and the one place the plugin is wired together.
 */
/* ========================================================================== */

define( 'VESLA_VERSION',  '1.1.0' );
define( 'VESLA_FILE',     __FILE__ );
define( 'VESLA_BASENAME', plugin_basename( __FILE__ ) );
define( 'VESLA_DIR',      plugin_dir_path( __FILE__ ) );
define( 'VESLA_URL',      plugin_dir_url( __FILE__ ) );

/* ========================================================================== */
/*  2 · THE SCHEMA
 *
 *  Every editable field, described once. Generates the editor, the sanitiser and the defaults.
 */
/* ========================================================================== */

class Vesla_Schema {
	/**
	 * Field types understood by the admin renderer and the sanitiser:
	 *
	 *   text      one line of plain text
	 *   textarea  several lines of plain text
	 *   rich      a few lines that may carry links and bold (wp_kses_post)
	 *   url       a link, internal (#stock) or external
	 *   email     an email address
	 *   tel       a telephone link target
	 *   number    a whole number, with min/max
	 *   toggle    on/off
	 *   select    one of `choices`
	 *   color     a hex colour
	 *   image     a WordPress media item (stores the attachment ID)
	 *   gallery   several media items, in order (stores the IDs, comma separated)
	 *   checks    tick any number from a list kept elsewhere in the settings
	 *   repeater  a list of rows, each built from `fields`
	 */
	/**
	 * What a 'rich' field is allowed to contain.
	 *
	 * Deliberately narrower than wp_kses_post(). The purpose of these fields is
	 * a link or a bold word inside a sentence -- that is the whole SEO value --
	 * so headings, images, tables and everything structural stay out. An
	 * administrator cannot break the page layout with the editor, and there is
	 * no tag here that can carry script: kses drops on* handlers and anything
	 * not listed, and href is filtered to the protocols below.
	 */
	public static function rich_tags() {
		return array(
			'a'      => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * The options for a `checks` field, from wherever its schema points.
	 *
	 * One per line, blanks dropped, duplicates dropped — a list typed by hand
	 * will have both, and the same feature offered twice is a tick box that
	 * cannot be told from its twin.
	 *
	 * @param array $def A field definition carrying `options_from`.
	 * @return string[]
	 */
	public static function options_for( $def ) {
		if ( empty( $def['options_from'] ) || ! is_array( $def['options_from'] ) ) {
			return array();
		}
		list( $section, $key ) = $def['options_from'];
		$raw = Vesla_Settings::get( $section, $key, array() );

		$out = array();

		/* Rows now, lines before. Both are read: a site upgraded from the older
		   shape still has a block of text sitting in this setting, and throwing
		   that away would silently empty every car's feature list. */
		$values = is_array( $raw )
			? array_map( function ( $row ) { return isset( $row['name'] ) ? $row['name'] : ''; }, $raw )
			: preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( (array) $values as $one ) {
			$one = trim( (string) $one );
			if ( '' !== $one && ! in_array( $one, $out, true ) ) {
				$out[] = $one;
			}
		}
		return $out;
	}

	/** Clean a rich value for storage or output. */
	public static function rich( $html ) {
		$html = wp_kses( (string) $html, self::rich_tags(), array( 'http', 'https', 'mailto', 'tel' ) );

		/* A link opened in a new tab without rel=noopener hands the new page a
		   handle back to this one. TinyMCE sets this itself, but the value can
		   also arrive from the Text tab, where nothing does. */
		$html = preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( $m ) {
				$attr = $m[1];
				if ( false !== stripos( $attr, 'target=' ) && false === stripos( $attr, 'rel=' ) ) {
					$attr .= ' rel="noopener"';
				}
				return '<a' . $attr . '>';
			},
			$html
		);

		return $html;
	}

	/**
	 * The declared type of a field, addressed by dotted path.
	 *
	 *   'chairman.text'   a field on a section
	 *   'faq.items.a'     a field on a row of a repeater
	 *
	 * This is what makes the rest of the plugin schema-driven rather than
	 * hard-coded: mark a field 'rich' in the schema and the editor, the
	 * sanitiser, the page and the SEO output all follow, with no call site to
	 * remember to update.
	 */
	public static function type_of( $path ) {
		$parts = explode( '.', (string) $path );
		$all   = self::get();
		if ( ! isset( $all[ $parts[0] ]['fields'] ) || ! isset( $parts[1] ) ) {
			return '';
		}
		$def = isset( $all[ $parts[0] ]['fields'][ $parts[1] ] ) ? $all[ $parts[0] ]['fields'][ $parts[1] ] : null;
		if ( ! $def ) {
			return '';
		}
		if ( isset( $parts[2] ) ) {
			$def = isset( $def['fields'][ $parts[2] ] ) ? $def['fields'][ $parts[2] ] : null;
			if ( ! $def ) {
				return '';
			}
		}
		return isset( $def['type'] ) ? $def['type'] : 'text';
	}

	/**
	 * Every section-level 'image' field, as dotted paths.
	 *
	 * Repeater images are left out on purpose: they travel with their own row
	 * (a car's photograph arrives inside that car), so listing them here would
	 * be the same picture twice with two different addresses.
	 */
	public static function image_paths() {
		$out = array();
		foreach ( self::get() as $section_key => $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			foreach ( $section['fields'] as $key => $def ) {
				if ( isset( $def['type'] ) && 'image' === $def['type'] ) {
					$out[] = $section_key . '.' . $key;
				}
			}
		}
		return $out;
	}

	/** Every 'rich' field in the schema, as dotted paths. */
	public static function rich_paths() {
		$out = array();
		foreach ( self::get() as $sec => $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			foreach ( $section['fields'] as $key => $def ) {
				if ( isset( $def['type'] ) && 'rich' === $def['type'] ) {
					$out[] = $sec . '.' . $key;
				}
				if ( ! empty( $def['fields'] ) ) {
					foreach ( $def['fields'] as $sub => $sdef ) {
						if ( isset( $sdef['type'] ) && 'rich' === $sdef['type'] ) {
							$out[] = $sec . '.' . $key . '.' . $sub;
						}
					}
				}
			}
		}
		return $out;
	}

	public static function get() {
		return array(

			/* ───────────────────────────────────────────────────────────────
			   BRAND & CONTACT — used in several places, so it is asked once
			   ─────────────────────────────────────────────────────────────── */
			'brand' => array(
				'title'  => __( 'Logo & contact details', 'vesla-landing' ),
				'blurb'  => __( 'These are used all over the page — in the top bar, the footer, the contact list and the WhatsApp buttons on every car. Change them here and they change everywhere.', 'vesla-landing' ),
				'fields' => array(
					'logo' => array(
						'type'  => 'image',
						'label' => __( 'Logo image', 'vesla-landing' ),
						'help'  => __( 'The shield. Appears in the top bar, in the footer, on the loading screen and large on the right of the opening section. A PNG with a transparent background works best.', 'vesla-landing' ),
						
						'default_file' => 'assets/brand/vesla-logo.png',
					),
					'name_top' => array(
						'type'  => 'text',
						'label' => __( 'Company name — first line', 'vesla-landing' ),
						'help'  => __( 'The large word next to the logo in the top bar. Usually the brand name on its own.', 'vesla-landing' ),
						
					),
					'name_bottom' => array(
						'type'  => 'text',
						'label' => __( 'Company name — second line', 'vesla-landing' ),
						'help'  => __( 'The small spaced-out word underneath the first line.', 'vesla-landing' ),
						
					),
					'phone_sales' => array(
						'type'  => 'text',
						'label' => __( 'Sales phone number', 'vesla-landing' ),
						'help'  => __( 'Shown in the contact list and the footer, and used by the “Call sales” button on phones. Write it the way you want it read, e.g. +971 58 106 5885.', 'vesla-landing' ),
						
					),
					/* phone_toll and phone_toll_dial were here. The top bar was the
					   only thing that read them -- the help text claimed the footer
					   did too, and it did not -- so with the bar's buttons gone they
					   drove nothing at all. A setting that does nothing is worse than
					   no setting: it invites somebody to type a number in and wonder
					   why it never appears.

					   The toll-free number itself is not lost. It is a row in the
					   Contact section's channels, which is what puts it on the front
					   page, the Contact page and the footer. */
					'phone_landline' => array(
						'type'  => 'text',
						'label' => __( 'Landline number', 'vesla-landing' ),
						'help'  => __( 'The showroom’s own number, written as a customer would read it. This is also the number handed to Google as the business telephone.', 'vesla-landing' ),
					),
					'phone_landline_dial' => array(
						'type'  => 'text',
						'label' => __( 'Landline number for dialling', 'vesla-landing' ),
						'help'  => __( 'The same number with no spaces and the country code, e.g. +97143922444. This is what the phone actually dials.', 'vesla-landing' ),
					),
					'whatsapp' => array(
						'type'  => 'text',
						'label' => __( 'WhatsApp number', 'vesla-landing' ),
						'help'  => __( 'Country code and number with no spaces, plus or zeros — e.g. 971581065885. Every car gets a WhatsApp button that opens a chat to this number with the car already written in the message.', 'vesla-landing' ),
						
					),
					'email' => array(
						'type'  => 'email',
						'label' => __( 'Email address for enquiries', 'vesla-landing' ),
						'help'  => __( 'Where the enquiry form sends to, and what is shown in the contact list.', 'vesla-landing' ),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   TOP BAR
			   ─────────────────────────────────────────────────────────────── */
			'header' => array(
				'title'  => __( 'Top bar menu', 'vesla-landing' ),
				'blurb'  => __( 'The menu that stays at the top of the screen as visitors scroll. Each link jumps to a section further down the page.', 'vesla-landing' ),
				'fields' => array(
					'menu' => array(
						'type'   => 'repeater',
						'label'  => __( 'Menu links', 'vesla-landing' ),
						'help'   => __( 'The link target must start with # and match a section below — for example #stock jumps to the cars. Sections you have switched off should be removed from this menu.', 'vesla-landing' ),
						'row_label' => __( 'Menu link', 'vesla-landing' ),
						/* Without this, every collapsed row reads 'Menu link' and the list
						   tells the admin nothing about which link they are about to open.
						   With it the rows read Stock, Certified, Record ... */
						'row_title' => array( 'label' ),
						'fields' => array(
							'label' => array( 'type' => 'text', 'label' => __( 'Wording shown', 'vesla-landing' ), ),
							'link' => array(
								'type'  => 'select',
								'label' => __( 'Jumps to', 'vesla-landing' ),
								'help'  => __( 'Picking from the list is why a menu link cannot point at a section that does not exist. Where a section has a page of its own, the menu link goes to that page instead of scrolling down this one, so the menu means the same thing wherever the reader is standing.', 'vesla-landing' ),
								'choices' => array(
									'' => __( '— not set —', 'vesla-landing' ),
									'#stock' => __( 'Stock — the cars', 'vesla-landing' ),
									'#certified' => __( 'Certified — how a car is checked', 'vesla-landing' ),
									'#why' => __( 'Why Vesla', 'vesla-landing' ),
									'#record' => __( 'About — the record and the ownership', 'vesla-landing' ),
									'#chairman' => __( 'About — the ownership (the same page as above)', 'vesla-landing' ),
									'#sell' => __( 'Sell your car', 'vesla-landing' ),
									'#finance' => __( 'Finance', 'vesla-landing' ),
									'#faq' => __( 'Questions and answers', 'vesla-landing' ),
									'#contact' => __( 'Contact', 'vesla-landing' ),
									'#top' => __( 'Back to the top', 'vesla-landing' ),
								),
								
							),
						),
						
					),
					/* cta_label and cta_link were here, and drew the button in the top
					   right. The button is gone, so they are too -- see the note beside
					   the toll-free pair in the brand section. The menu below is what
					   the header offers now. */
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   HERO
			   ─────────────────────────────────────────────────────────────── */
			'hero' => array(
				'title'  => __( 'Opening section', 'vesla-landing' ),
				'blurb'  => __( 'The first thing a visitor sees: the big heading, a short paragraph, two buttons, and the row of figures underneath.', 'vesla-landing' ),
				'fields' => array(
					'style' => array(
						'type'    => 'select',
						'label'   => __( 'Opening section style', 'vesla-landing' ),
						'default' => 'classic',
						'options' => array(
							'classic' => __( 'Classic — heading, paragraph and the shield', 'vesla-landing' ),
							'video'   => __( 'Film — the same words over a film', 'vesla-landing' ),
						),
						'help'    => __( 'Both use the wording and the figures below. Changing this changes how they are presented, not what they say — there is one set of words and it is edited in one place. The film style needs a film and a poster picture before it will turn on.', 'vesla-landing' ),
					),
					'video' => array(
						'type'        => 'video',
						'label'       => __( 'Film for the opening section', 'vesla-landing' ),
						'mimes'       => array( 'video/mp4' ),
						'max_bytes'   => 26214400,   // 25 MB — refused above this
						'heavy_bytes' => 10485760,   // 10 MB — saved, warned about strongly
						'warn_bytes'  => 4194304,    //  4 MB — saved, cost named
						'help'        => __( 'MP4, no larger than 25MB. It is decoration: it plays muted, on a loop, with no controls, and the words sit over it. Everything a reader needs is in the text and the poster, so a film that never loads costs nothing but the film — but the longer the file, the longer most people see a still picture instead of it. Measured figures are given when you choose one.', 'vesla-landing' ),
					),
					'poster' => array(
						'type'  => 'image',
						'label' => __( 'Poster picture for the film', 'vesla-landing' ),
						'help'  => __( 'Shown before the film loads, and instead of it wherever it will not play — a phone saving power, a browser refusing to start it on its own, or a reader who has asked for less movement. Required: the film style will not turn on without one, because the alternative is a black box where the opening should be.', 'vesla-landing' ),
					),
					'overlay' => array(
						'type'    => 'number',
						'label'   => __( 'How dark over the film, as a percentage', 'vesla-landing' ),
						'default' => 35,
						'min'     => 0, 'max' => 60,
						'help'    => __( 'A gradient, not a flat wash: heaviest behind the words and clearing toward the other side, so the film is still a film. Raise it for a bright or busy clip where the text stops being legible.', 'vesla-landing' ),
					),
					'eyebrow' => array(
						'type'  => 'text',
						'label' => __( 'Small line above the heading', 'vesla-landing' ),
						'help'  => __( 'Set in small spaced-out capitals.', 'vesla-landing' ),

					),
					'heading' => array(
						'type'  => 'text',
						'label' => __( 'Big heading', 'vesla-landing' ),
						'help'  => __( 'The largest text on the page. It animates in one word at a time, so two to four words works best.', 'vesla-landing' ),
						
					),
					'lead' => array(
						'type'  => 'textarea',
						'label' => __( 'Paragraph under the heading', 'vesla-landing' ),
						'help'  => __( 'One or two sentences. Keep it short — this is the first thing anyone reads.', 'vesla-landing' ),
						
					),
					'btn1_label' => array( 'type' => 'text', 'label' => __( 'First button — wording', 'vesla-landing' ), ),
					'btn1_link'  => array( 'type' => 'url',  'label' => __( 'First button — jumps to', 'vesla-landing' ), ),
					'btn2_label' => array( 'type' => 'text', 'label' => __( 'Second button — wording', 'vesla-landing' ), 'help' => __( 'Leave empty to show only one button.', 'vesla-landing' ), ),
					'btn2_link'  => array( 'type' => 'url',  'label' => __( 'Second button — jumps to', 'vesla-landing' ), ),
					'show_logo'  => array(
						'type'  => 'toggle',
						'label' => __( 'Show the logo on the right', 'vesla-landing' ),
						'help'  => __( 'The large shield beside the heading. It is hidden automatically on phones, where there is no room for it.', 'vesla-landing' ),
						
					),
					'stats' => array(
						'type'   => 'repeater',
						'label'  => __( 'Row of figures', 'vesla-landing' ),
						'help'   => __( 'The numbers at the bottom of the opening section. They count up when a visitor scrolls to them.', 'vesla-landing' ),
						'row_label' => __( 'Figure', 'vesla-landing' ),
						'row_title' => array( 'value', 'label' ),
						'fields' => array(
							'value'  => array( 'type' => 'text', 'label' => __( 'The number', 'vesla-landing' ), 'help' => __( 'Digits only if you want it to count up, e.g. 36. Write it as text like 1988 for a year that should not count.', 'vesla-landing' ), ),
							'count'  => array( 'type' => 'toggle', 'label' => __( 'Count up to it', 'vesla-landing' ), ),
							'suffix' => array( 'type' => 'text', 'label' => __( 'Symbol after the number', 'vesla-landing' ), 'help' => __( 'Such as + or ×. Leave empty for none.', 'vesla-landing' ), ),
							'label'  => array( 'type' => 'text', 'label' => __( 'Wording underneath', 'vesla-landing' ), ),
						),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   TRUST STRIP
			   ─────────────────────────────────────────────────────────────── */
			'trust' => array(
				'title'  => __( 'Reasons-to-buy strip', 'vesla-landing' ),
				'blurb'  => __( 'The narrow dark band directly under the opening section, with a small icon beside each point.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'items' => array(
						'type'   => 'repeater',
						'label'  => __( 'Points', 'vesla-landing' ),
						'help'   => __( 'Four fits neatly across a desktop screen. More than four will wrap onto a second row.', 'vesla-landing' ),
						'row_label' => __( 'Point', 'vesla-landing' ),
						'row_title' => array( 'title' ),
						'fields' => array(
							'icon'  => array(
								'type'  => 'select',
								'label' => __( 'Icon', 'vesla-landing' ),
								'choices' => array(
									'shield'  => __( 'Shield — trust, guarantee', 'vesla-landing' ),
									'award'   => __( 'Medal — award, recognition', 'vesla-landing' ),
									'spanner' => __( 'Spanner — servicing, workshop', 'vesla-landing' ),
									'doc'     => __( 'Document — paperwork, report', 'vesla-landing' ),
									'clock'   => __( 'Clock — speed, history', 'vesla-landing' ),
									'car'     => __( 'Car — vehicles, stock', 'vesla-landing' ),
								),
								
							),
							'title' => array( 'type' => 'text', 'label' => __( 'Bold line', 'vesla-landing' ), ),
							'text'  => array( 'type' => 'text', 'label' => __( 'Line underneath', 'vesla-landing' ), ),
						),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   STOCK
			   ─────────────────────────────────────────────────────────────── */
			'spotlight' => array(
				'title'  => __( 'Spotlight', 'vesla-landing' ),
				/* The cars are picked here but they are not edited here; this
				   button is how somebody looking for them finds them. */
				'screen_link'       => 'edit.php?post_type=vesla_vehicle',
				'screen_link_label' => __( 'Edit the cars', 'vesla-landing' ),
				'blurb'  => __( 'A turning row of three to five cars, above the grid. It shows two either side of the one facing forward, moves on its own, and stops the moment anybody touches it. Every car in it is also in the grid below, so nothing is only reachable through here.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'lead'    => array( 'type' => 'rich', 'label' => __( 'Paragraph under the heading', 'vesla-landing' ), ),
					'cars' => array(
						'type'   => 'repeater',
						/* Five, not eight. The flow places two either side of the one
						   facing forward, so five is exactly full; a sixth car would sit
						   at a distance where it is neither readable nor gone, which is
						   what the strip of eight this replaced looked like from the
						   third card out. */
						'label'  => __( 'Cars in the Spotlight', 'vesla-landing' ),
						'help'   => __( 'Three to five, in the order you want them shown — the middle one faces forward when the page loads. Each one needs a photograph, because up here the photograph is the whole card. Fewer than three and the Spotlight stays off. Leave this empty and the five most recently added cars that have a photograph are used instead, so it is never blank.', 'vesla-landing' ),
						'row_label' => __( 'Spotlight car', 'vesla-landing' ),
						'row_title' => array( 'car' ),
						'max'    => 5,
						'fields' => array(
							'car' => array( 'type' => 'car', 'label' => __( 'Car', 'vesla-landing' ), ),
						),
					),
				),
			),

			'stock' => array(
				'title'  => __( 'Cars for sale', 'vesla-landing' ),
				/* The cars themselves are edited under Vehicles. This button is how
				   somebody looking for them here finds them. */
				'screen_link'       => 'edit.php?post_type=vesla_vehicle',
				'screen_link_label' => __( 'Edit the cars', 'vesla-landing' ),
				'blurb'  => __( 'The grid of cars, and the filters above it. The make and body-type filter menus build themselves from whatever cars you add below, so there is no separate list to keep in step.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'lead'    => array( 'type' => 'rich', 'label' => __( 'Paragraph under the heading', 'vesla-landing' ), ),
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give this section a page of its own', 'vesla-landing' ),
						'help'  => __( 'Publishes /stock/ as a page in its own right, showing the whole grid, with the filters and the search in full. The homepage keeps its shorter version of the same section, and both read the settings on this screen — there is no second copy of the wording to keep in step. The page appears the next time the site is republished.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
						'help'  => __( 'The heading at the top of the page, and the title a search engine shows. Leave it empty to use the section heading above.', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph on the page', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace this before the page goes live. It opens the page under the heading, and is what a search engine shows as the description. Write it to open properly: the line on the homepage is a tease and this is the same subject arriving in full, so repeating that line here reads as padding to anyone who has just come from it.', 'vesla-landing' ),
					),
					'page_more_label' => array(
						'type'  => 'text',
						'label' => __( 'Link on the homepage through to the page', 'vesla-landing' ),
						'help'  => __( 'Appears on the homepage under the short version of this section, once the page above is switched on. Say where it goes rather than "read more": somebody deciding whether to press it is helped by "See the five stages" and not at all by "more".', 'vesla-landing' ),
					),

					/* ── the strip of makes above the grid ──
					   The list of makes is not entered anywhere: it is built from the
					   cars, the same way the Make menu is, so adding a Bentley to the
					   stock puts Bentley in the strip and selling the last one takes
					   it out again. There is nothing to keep in step.

					   The only thing that cannot be worked out from the cars is the
					   marque's logo, because that is a picture somebody has to supply.
					   Until one is attached a make shows as its name set in the
					   site's own type, which is a deliberate fallback rather than a
					   placeholder: a strip of wordmarks is a respectable thing to
					   ship, and it is what the strip falls back to for any make whose
					   logo is missing. */
					'brands_enabled' => array(
						'type'    => 'toggle',
						'label'   => __( 'Show the strip of makes above the grid', 'vesla-landing' ),
						'default' => 1,
						'help'    => __( 'A row of the makes you have in stock, above the cars. Tapping one filters the grid to that make; tapping it again clears it. It scrolls sideways when there are more makes than fit.', 'vesla-landing' ),
					),
					'brands_all_label' => array(
						'type'    => 'text',
						'label'   => __( 'Wording on the “everything” tile', 'vesla-landing' ),
						'default' => 'All makes',
						'help'    => __( 'The first tile in the strip, which clears the filter.', 'vesla-landing' ),
					),
					/* The logos are NOT here. They live on the make itself, under
					   Vehicles → Car brands, because that list already exists, is
					   already tied to every car of that make, and appears and
					   disappears with the stock. A second list of makes kept by hand
					   in the settings would be a list that disagrees with the cars
					   the first time somebody adds a marque and forgets. */


					'per_page' => array(
						'type'  => 'number',
						'label' => __( 'How many cars to show before the “Show more” button', 'vesla-landing' ),
						'help'  => __( 'Visitors see this many to begin with, then the same number again each time they press the button.', 'vesla-landing' ),
						'min' => 2, 'max' => 48, 
					),
					'more_label' => array( 'type' => 'text', 'label' => __( '“Show more” button — wording', 'vesla-landing' ), ),
					'empty_text' => array(
						'type'  => 'text',
						'label' => __( 'Message when the filters match no cars', 'vesla-landing' ),
						
					),
					'currency' => array(
						'type'  => 'text',
						'label' => __( 'Currency shown before every price', 'vesla-landing' ),
						
					),
					'price_note' => array( 'type' => 'text', 'label' => __( 'Small wording under each price', 'vesla-landing' ), ),
					'badge'      => array( 'type' => 'text', 'label' => __( 'Corner badge on each photo', 'vesla-landing' ), 'help' => __( 'Leave empty to remove the badge.', 'vesla-landing' ), ),
					'reserved_label' => array( 'type' => 'text', 'label' => __( 'Badge on a reserved car', 'vesla-landing' ), ),
					'reserved_note'  => array(
						'type'  => 'text',
						'label' => __( 'Wording shown instead of the buttons, on a reserved car', 'vesla-landing' ),
						'help'  => __( 'A reserved car keeps its card and loses its Enquire and WhatsApp buttons. This is what stands in their place, so the card explains itself rather than simply going quiet.', 'vesla-landing' ),
					),
					'sold_label'     => array( 'type' => 'text', 'label' => __( 'Badge on a sold car', 'vesla-landing' ), ),
					'sold_note'      => array( 'type' => 'text', 'label' => __( 'Wording shown instead of the buttons, on a sold car', 'vesla-landing' ), ),
					'arrived_label'  => array( 'type' => 'text', 'label' => __( 'Badge on a just-arrived car', 'vesla-landing' ), ),
					'warranty_note' => array( 'type' => 'text', 'label' => __( 'Small wording under the price, second line', 'vesla-landing' ), 'help' => __( 'Leave empty to remove it.', 'vesla-landing' ), ),
					'enquire_label' => array( 'type' => 'text', 'label' => __( 'Button on each car — wording', 'vesla-landing' ), ),
					'search_label' => array(
						'type'  => 'text',
						'label' => __( 'Search box — label above it', 'vesla-landing' ),
						'help'  => __( 'The word over the search box in the row of filters.', 'vesla-landing' ),
					),
					'search_hint' => array(
						'type'  => 'text',
						'label' => __( 'Search box — grey hint inside it', 'vesla-landing' ),
						'help'  => __( 'Shown in grey inside the empty box, as an example of what can be typed. It disappears as soon as somebody types, so it is a hint and never an instruction.', 'vesla-landing' ),
					),
					'sound_on_label' => array(
						'type'  => 'text',
						'label' => __( 'Sound button — wording when sound is on', 'vesla-landing' ),
						'show_if' => 'sound_enabled',
						
					),
					'sound_off_label' => array(
						'type'  => 'text',
						'label' => __( 'Sound button — wording when sound is off', 'vesla-landing' ),
						'show_if' => 'sound_enabled',
						
					),
					'sound_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Play a short sound when a visitor picks a car', 'vesla-landing' ),
						'help'  => __( 'Visitors can switch it off themselves; this decides whether the option is there at all.', 'vesla-landing' ),
						
					),
					'cars' => array(
						/* Still here, and still the single definition of what a car is — the
						   Vehicles screen, the sanitiser, the API, the car page and the
						   published files all read these field definitions.
						
						   What has changed is where a car is EDITED. It used to be twenty-four
						   rows inside this one settings form; each car is now a Vehicle of its
						   own, with its own screen, status and search. `admin => false` keeps
						   the definition and takes the repeater off this form, so there is one
						   place to edit a car rather than two that can disagree. */
						'admin' => false,
						'type'   => 'repeater',
						'label'  => __( 'The cars', 'vesla-landing' ),
						'help'   => __( 'Add, remove and reorder cars here. Everything else — the filters, the sorting, the count, and the listing information search engines read — is built from this list.', 'vesla-landing' ),
						'row_label' => __( 'Car', 'vesla-landing' ),
						'row_title' => array( 'make', 'model' ),
						'fields' => array(
							'photo' => array( 'group' => __( 'Photographs', 'vesla-landing' ), 'type' => 'image', 'label' => __( 'Photograph', 'vesla-landing' ), 'help' => __( 'Landscape works best, around 1280 × 800. A car with no photograph shows a plain tile with its first letter instead, so it is fine to add one before the pictures arrive.', 'vesla-landing' ), ),
							'photo_alt' => array(
								'type'  => 'text',
								'label' => __( 'Describe the photograph', 'vesla-landing' ),
								'help'  => __( 'What is in the picture, for somebody who cannot see it — “silver saloon, front three-quarter view, in the showroom”. Not the car’s name: that is the heading right beside it, and repeating it tells a blind visitor nothing they did not already have.', 'vesla-landing' ),
							),
							'status' => array(
								'group' => __( 'Where this car is up to', 'vesla-landing' ),
								'type'  => 'select',
								'label' => __( 'Status', 'vesla-landing' ),
								'help'  => __( 'On the floor is the normal state. Reserved keeps the car in the grid with a badge and takes the Enquire button off it, so a car somebody has already put a deposit on stops generating telephone calls. Sold takes it out of the grid altogether and puts it on the sold page, with its photographs and without its price.', 'vesla-landing' ),
								'choices' => array(
									''         => __( 'On the floor', 'vesla-landing' ),
									'reserved' => __( 'Reserved — deposit taken', 'vesla-landing' ),
									'sold'     => __( 'Sold', 'vesla-landing' ),
								),
							),
							'arrived' => array(
								'type'  => 'select',
								'label' => __( 'Just arrived', 'vesla-landing' ),
								'help'  => __( 'Puts a "just arrived" badge on the card. Nothing else changes. Take it off when the car stops being new to the floor — a badge that is on every car says nothing.', 'vesla-landing' ),
								'choices' => array(
									''    => __( 'No', 'vesla-landing' ),
									'yes' => __( 'Yes', 'vesla-landing' ),
								),
							),
							'make'  => array( 'group' => __( 'What the car is', 'vesla-landing' ), 'type' => 'brand', 'label' => __( 'Brand', 'vesla-landing' ), 'help' => __( 'Chosen from the brands under Vehicles → Car brands. Add the brand there first, with its logo, and it appears on this list. This is also what fills the “Make” filter and the strip of makes above the cars.', 'vesla-landing' ), ),
							'model' => array( 'type' => 'text', 'label' => __( 'Model', 'vesla-landing' ), ),
							'year'  => array( 'type' => 'number', 'label' => __( 'Year', 'vesla-landing' ), 'min' => 1950, 'max' => 2100, ),
							'price' => array( 'type' => 'number', 'label' => __( 'Price', 'vesla-landing' ), 'help' => __( 'Numbers only, no commas or currency — the currency and the thousands separators are added for you.', 'vesla-landing' ), 'min' => 0, 'max' => 100000000, ),
							'km'    => array( 'group' => __( 'Engine and drive', 'vesla-landing' ), 'type' => 'number', 'label' => __( 'Mileage in kilometres', 'vesla-landing' ), 'public_label' => __( 'Mileage', 'vesla-landing' ), 'min' => 0, 'max' => 10000000, ),
							'body' => array(
								'type'  => 'select',
								'label' => __( 'Body type', 'vesla-landing' ),
								'help'  => __( 'This also fills the “Body” filter on the page. A list rather than a typed box, so two cars can never spell the same body type differently and split the filter in two.', 'vesla-landing' ),
								'choices' => array(
									'' => __( '— not set —', 'vesla-landing' ),
									'SUV' => __( 'SUV', 'vesla-landing' ),
									'Sedan' => __( 'Sedan', 'vesla-landing' ),
									'Coupe' => __( 'Coupe', 'vesla-landing' ),
									'Hatchback' => __( 'Hatchback', 'vesla-landing' ),
									'Pickup' => __( 'Pickup', 'vesla-landing' ),
									'Convertible' => __( 'Convertible', 'vesla-landing' ),
									'Wagon' => __( 'Wagon', 'vesla-landing' ),
									'Van' => __( 'Van', 'vesla-landing' ),
									'MPV' => __( 'MPV', 'vesla-landing' ),
								),
								
							),
							'trans' => array(
								'type'  => 'select',
								'label' => __( 'Gearbox', 'vesla-landing' ),
								'choices' => array(
									'Automatic' => __( 'Automatic', 'vesla-landing' ),
									'Manual' => __( 'Manual', 'vesla-landing' ),
								),
								
							),
							'fuel' => array(
								'type'  => 'select',
								'label' => __( 'Fuel', 'vesla-landing' ),
								'choices' => array(
									'Petrol' => __( 'Petrol', 'vesla-landing' ),
									'Diesel' => __( 'Diesel', 'vesla-landing' ),
									'Hybrid' => __( 'Hybrid', 'vesla-landing' ),
									'Plug-in hybrid' => __( 'Plug-in hybrid', 'vesla-landing' ),
									'Electric' => __( 'Electric', 'vesla-landing' ),
								),
								
							),
							'seats' => array( 'type' => 'number', 'label' => __( 'Seats', 'vesla-landing' ), 'min' => 1, 'max' => 20, ),

							/* ---- Detail shown on the car's own page ------------------------------
							   Every one of these may be left empty. The page prints a row only when
							   there is something in it, so a car entered in a hurry shows a short,
							   honest list rather than a long list of blanks or invented values. */

							'ref' => array(
								'type'  => 'text',
								'label' => __( 'Stock reference', 'vesla-landing' ),
								'help'  => __( 'Your own reference for this car, shown to the customer so they can quote it on the phone.', 'vesla-landing' ),
							),
							'colour_out' => array(
								'group' => __( 'Body and cabin', 'vesla-landing' ), 'type'  => 'text',
								'label' => __( 'Exterior colour', 'vesla-landing' ),
							),
							'colour_in' => array(
								'type'  => 'text',
								'label' => __( 'Interior colour', 'vesla-landing' ),
							),
							'engine' => array(
								'type'  => 'text',
								'label' => __( 'Engine', 'vesla-landing' ),
								'help'  => __( 'As you would say it to a customer, for example 3.0L V6 or 2.0L Turbo.', 'vesla-landing' ),
							),
							'power' => array(
								'type'  => 'number',
								'label' => __( 'Power in horsepower', 'vesla-landing' ),
	'public_label' => __( 'Power', 'vesla-landing' ),
								'min' => 0, 'max' => 3000,
							),
							'drive' => array(
								'type'  => 'select',
								'label' => __( 'Driven wheels', 'vesla-landing' ),
								'choices' => array(
									'' => __( '— not set —', 'vesla-landing' ),
									'Front-wheel drive' => __( 'Front-wheel drive', 'vesla-landing' ),
									'Rear-wheel drive'  => __( 'Rear-wheel drive', 'vesla-landing' ),
									'All-wheel drive'   => __( 'All-wheel drive', 'vesla-landing' ),
									'4x4'               => __( '4x4', 'vesla-landing' ),
								),
							),
							'doors' => array(
								'type'  => 'number',
								'label' => __( 'Doors', 'vesla-landing' ),
								'min' => 0, 'max' => 8,
							),
							'spec' => array(
								'group' => __( 'History and paperwork', 'vesla-landing' ), 'type'  => 'select',
								'label' => __( 'Regional specification', 'vesla-landing' ),
								'help'  => __( 'Buyers in the UAE ask this first — GCC specification is built for the heat here.', 'vesla-landing' ),
								'choices' => array(
									'' => __( '— not set —', 'vesla-landing' ),
									'GCC'      => __( 'GCC', 'vesla-landing' ),
									'Japanese' => __( 'Japanese', 'vesla-landing' ),
									'European' => __( 'European', 'vesla-landing' ),
									'American' => __( 'American', 'vesla-landing' ),
									'Other'    => __( 'Other', 'vesla-landing' ),
								),
							),
							'owners' => array(
								'type'  => 'number',
								'label' => __( 'Previous owners', 'vesla-landing' ),
								'min' => 0, 'max' => 20,
							),
							'service' => array(
								'type'  => 'select',
								'label' => __( 'Service history', 'vesla-landing' ),
								'choices' => array(
									'' => __( '— not set —', 'vesla-landing' ),
									'Full service history'    => __( 'Full service history', 'vesla-landing' ),
									'Partial service history' => __( 'Partial service history', 'vesla-landing' ),
									'No service history'      => __( 'No service history', 'vesla-landing' ),
								),
							),
							'warranty_until' => array(
								'type'  => 'text',
								'label' => __( 'Warranty until', 'vesla-landing' ),
								'help'  => __( 'Left empty, the car’s page says nothing about warranty rather than implying one.', 'vesla-landing' ),
							),
							'video' => array(
								'type'  => 'url',
								'label' => __( 'Video of this car — YouTube or Vimeo link', 'vesla-landing' ),
								'help'  => __( 'Paste the address of the video as it appears in the browser bar. It is shown under the photographs, and is not loaded until somebody presses play — a video that loads itself would cost every visitor the download whether they watch it or not. Leave empty for no video.', 'vesla-landing' ),
							),
							'gallery' => array(
								'type'  => 'gallery',
								'max'   => 10,
								'label' => __( 'More photographs — up to ten', 'vesla-landing' ),
								'help'  => __( 'These are what the arrows on the car’s page move between, in the order you add them here — drag one to move it. The main photograph above is always the first of them, so ten here makes eleven in all. A car with only the one photograph gets no arrows and no thumbnails, because there is nothing to move to.', 'vesla-landing' ),
							),
							'trim' => array(
								'type'  => 'text',
								'label' => __( 'Trim', 'vesla-landing' ),
								'help'  => __( 'The version, for example Trend, GXR or Sport.', 'vesla-landing' ),
							),
							'steering' => array(
								'type'  => 'select',
								'label' => __( 'Steering side', 'vesla-landing' ),
								'choices' => array(
									''           => __( '— not set —', 'vesla-landing' ),
									'Left Hand'  => __( 'Left hand', 'vesla-landing' ),
									'Right Hand' => __( 'Right hand', 'vesla-landing' ),
								),
							),
							'cylinders' => array(
								'type'  => 'number',
								'label' => __( 'Number of cylinders', 'vesla-landing' ),
								'min' => 0, 'max' => 16,
							),
							'engine_cc' => array(
								'type'  => 'number',
								'label' => __( 'Engine capacity in cc', 'vesla-landing' ),
								'min' => 0, 'max' => 10000,
							),
							'target_market' => array(
								'type'  => 'text',
								'label' => __( 'Target market', 'vesla-landing' ),
								'help'  => __( 'For example: UAE (can be exported).', 'vesla-landing' ),
							),
							'features' => array(
								'group' => __( 'Equipment', 'vesla-landing' ), 'type'  => 'checks',
								'label' => __( 'Features', 'vesla-landing' ),
								'help'  => __( 'Tick whatever this car has. The list comes from “The list of features you can tick on a car”, near the top of this section — edit it there and every car is offered the same wording.', 'vesla-landing' ),
								/* Resolved when the field is drawn and when it is
								   saved, never while the schema is being built:
								   reading a setting at build time would send
								   Vesla_Settings::defaults() back into the schema
								   it is in the middle of assembling. */
								'options_from' => array( 'features', 'offered' ),
							),
							'summary' => array(
								'group' => __( 'Description', 'vesla-landing' ), 'type'  => 'rich',
								'label' => __( 'About this car', 'vesla-landing' ),
								'help'  => __( 'A short paragraph, shown on the car’s own page under “About this car”. Bold and links are allowed.', 'vesla-landing' ),
							),

							/* Answered again for one car. See Vesla_Render::setting(). */
							'v_enabled' => array(
								'group' => __( 'This car’s page', 'vesla-landing' ),
								'type'  => 'select',
								'label' => __( 'Give this car a page of its own', 'vesla-landing' ),
								'help'  => __( 'Off hides this one car’s page and nothing else — the car stays in the grid on the front page. Useful for a car that is reserved but not yet sold.', 'vesla-landing' ),
								'choices' => array(
									'' => __( 'Whatever Vehicle pages says', 'vesla-landing' ),
									'on' => __( 'Yes', 'vesla-landing' ),
									'off' => __( 'No', 'vesla-landing' ),
								),
							),
							'v_back_label' => array(
								'type'  => 'text',
								'label' => __( 'Link back to the car list', 'vesla-landing' ),
								'help'  => __( 'Leave blank to use the wording under Vehicle pages. Anything typed here applies to this car only.', 'vesla-landing' ),
							),
							'v_overview_title' => array(
								'type'  => 'text',
								'label' => __( 'Heading above this car’s details', 'vesla-landing' ),
							),
							'v_spec_title' => array(
								'type'  => 'text',
								'label' => __( 'Heading above the specification list', 'vesla-landing' ),
							),
							'v_about_title' => array(
								'type'  => 'text',
								'label' => __( 'Heading above the description', 'vesla-landing' ),
							),
							'v_features_title' => array(
								'type'  => 'text',
								'label' => __( 'Heading above the features list', 'vesla-landing' ),
							),
							'v_seats_label' => array(
								'type'  => 'text',
								'label' => __( 'Word after the number of seats', 'vesla-landing' ),
							),
							'v_finance_enabled' => array(
								'type'  => 'select',
								'label' => __( 'Show the finance calculator on this car', 'vesla-landing' ),
								'help'  => __( 'A car sold outright, or one no lender will finance, can have the calculator taken off without affecting any other.', 'vesla-landing' ),
								'choices' => array(
									'' => __( 'Whatever Vehicle pages says', 'vesla-landing' ),
									'on' => __( 'Yes', 'vesla-landing' ),
									'off' => __( 'No', 'vesla-landing' ),
								),
							),
							'v_finance_title' => array(
								'type'  => 'text',
								'label' => __( 'Heading above the finance calculator', 'vesla-landing' ),
							),
							'v_finance_down_pct' => array(
								'type'  => 'number',
								'label' => __( 'Deposit this car’s calculator starts at, as a percentage', 'vesla-landing' ),
								'min' => 0, 'max' => 90,
							),
							'v_finance_rate' => array(
								'type'  => 'text',
								'label' => __( 'Interest rate for this car, as a percentage', 'vesla-landing' ),
								'help'  => __( 'A number, for example 3.49. Leave it blank and the rate under Vehicle pages is used.', 'vesla-landing' ),
							),
							'v_finance_years' => array(
								'type'  => 'number',
								'label' => __( 'Loan length this car’s calculator starts at, in years', 'vesla-landing' ),
								'min' => 0, 'max' => 10,
							),
							'v_finance_note' => array(
								'type'  => 'text',
								'label' => __( 'Small print under this car’s calculator', 'vesla-landing' ),
							),
						),
						// seeded from the bundled sample photos on activation
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   VEHICLE PAGES
			   ─────────────────────────────────────────────────────────────── */
			'vehicle' => array(
				'title'  => __( 'Vehicle pages', 'vesla-landing' ),
				'blurb'  => __( 'The page a car gets to itself when somebody presses its card — the gallery, the specification, the description and the finance calculator. One setting here changes every car’s page at once; what each page actually says about a car is on that car, under Cars. The web address is built from the make, model, year and stock number, so it stays readable and stays put.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give every car a page of its own', 'vesla-landing' ),
						'help'  => __( 'On, and each car has its own web address that can be sent to a customer, indexed by Google and opened in a new tab. Off, and the cars are listed on the front page only — existing car addresses then stop working, so turn it off only if nothing links to them.', 'vesla-landing' ),
					),
					'base' => array(
						'type'  => 'text',
						'label' => __( 'The folder car pages live under', 'vesla-landing' ),
						'help'  => __( 'The middle of the address: with “cars”, a car is at /cars/toyota-hilux-2021-14/. Changing it changes every car’s address at once, and anything already linking to the old ones stops working — so change it before you start sharing links, not after.', 'vesla-landing' ),
					),
					'video_label' => array( 'type' => 'text', 'label' => __( 'Wording on the video button', 'vesla-landing' ), ),
					'finance_ask_label' => array(
						'type'  => 'text',
						'label' => __( 'Finance calculator — wording on the button under the monthly figure', 'vesla-landing' ),
						'help'  => __( 'Pressing it opens the enquiry form with the deposit, the term and the monthly figure already attached, so the call back starts from the numbers they were looking at.', 'vesla-landing' ),
					),
					'share_label' => array(
						'type'  => 'text',
						'label' => __( 'Share button — wording', 'vesla-landing' ),
						'help'  => __( 'On a phone this opens WhatsApp, Messages and the rest. On a computer there is usually nothing to open, so it copies the address instead — the next two settings are what it says when it has.', 'vesla-landing' ),
					),
					'share_copied_label' => array(
						'type'  => 'text',
						'label' => __( 'Share button — after the address has been copied', 'vesla-landing' ),
						'help'  => __( 'Replaces the button wording for two seconds, then changes back.', 'vesla-landing' ),
					),
					'share_failed_label' => array(
						'type'  => 'text',
						'label' => __( 'Share button — if the browser refuses to copy', 'vesla-landing' ),
						'help'  => __( 'Rare, and nothing the visitor did: some browsers refuse the clipboard outright. Say what happened rather than apologising.', 'vesla-landing' ),
					),
					'no_photo_text' => array(
						'type'  => 'text',
						'label' => __( 'Shown where a car has no photographs yet', 'vesla-landing' ),
						'help'  => __( 'Most of the stock is listed before it has been photographed. Saying so is better than an empty frame, which reads as a picture that failed to load — and it tells a buyer the car is real and the photographs are coming.', 'vesla-landing' ),
					),
					'transition_ms' => array(
						'type'  => 'number',
						'label' => __( 'How long the loading screen shows when a car is opened, in milliseconds', 'vesla-landing' ),
						'help'  => __( 'Pressing a car does not reload the browser — the page is fetched and swapped in behind this screen, and it is fetched already as soon as the pointer touches the card, so it is usually there before the press finishes. This is the shortest the screen stays up. Around 500 is enough that it reads as deliberate rather than as a flicker; much more than that and it is the screen, not the loading, that people are waiting for.', 'vesla-landing' ),
						'min' => 0, 'max' => 5000,
					),
					'back_label' => array(
						'type'  => 'text',
						'label' => __( 'Link back to the car list', 'vesla-landing' ),
					),
					'overview_title' => array(
						'type'  => 'text',
						'label' => __( 'Heading above the car’s details', 'vesla-landing' ),
					),
					'spec_title' => array(
						'type'  => 'text',
						'label' => __( 'Heading above the specification list', 'vesla-landing' ),
					),
					'about_title' => array(
						'type'  => 'text',
						'label' => __( 'Heading above the description', 'vesla-landing' ),
					),
					'features_title' => array(
						'type'  => 'text',
						'label' => __( 'Heading above the features list', 'vesla-landing' ),
					),
					'seats_label' => array(
						'type'  => 'text',
						'label' => __( 'Word after the number of seats', 'vesla-landing' ),
						'help'  => __( 'For example: seats, or Seater.', 'vesla-landing' ),
					),
					'finance_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Show the finance calculator', 'vesla-landing' ),
					),
					'finance_title' => array(
						'type'  => 'text',
						'label' => __( 'Heading above the finance calculator', 'vesla-landing' ),
						'show_if' => 'finance_enabled',
					),
					'finance_down_pct' => array(
						'type'  => 'number',
						'label' => __( 'Deposit the calculator starts at, as a percentage', 'vesla-landing' ),
						'min' => 0, 'max' => 90,
						'show_if' => 'finance_enabled',
					),
					'finance_rate' => array(
						'type'  => 'text',
						'label' => __( 'Interest rate per year, as a percentage', 'vesla-landing' ),
						'help'  => __( 'A number, for example 2.79. This is a guide only — the calculator says so underneath.', 'vesla-landing' ),
						'show_if' => 'finance_enabled',
					),
					'finance_years' => array(
						'type'  => 'number',
						'label' => __( 'Loan length the calculator starts at, in years', 'vesla-landing' ),
						'min' => 1, 'max' => 10,
						'show_if' => 'finance_enabled',
					),
					'finance_note' => array(
						'type'  => 'text',
						'label' => __( 'Small print under the calculator', 'vesla-landing' ),
						'help'  => __( 'This figure is an estimate, and saying so is not optional — an indicative monthly payment presented as a quote is a promise the showroom has not made.', 'vesla-landing' ),
						'show_if' => 'finance_enabled',
					),
				),
			),
			/* ───────────────────────────────────────────────────────────────
			   FEATURES
			   ─────────────────────────────────────────────────────────────── */
			'features' => array(
				'title'  => __( 'Features', 'vesla-landing' ),
				'blurb'  => __( 'The equipment you can tick on a car. Everything ticked here is offered on every car, so a feature is worded the same way on every listing. Tick them per car under Cars.', 'vesla-landing' ),
				'fields' => array(
					'offered' => array(
						'type'  => 'checks',
						'label' => __( 'What you can tick on a car', 'vesla-landing' ),
						'help'  => __( 'Untick anything this showroom never lists — it stops being offered, and stops showing on cars that already had it. The number beside each one is how many cars have it at the moment.', 'vesla-landing' ),
						/* Resolved when the field is drawn and when it is saved, never
						   while the schema is being built: reading a setting at build
						   time would send Vesla_Settings::defaults() back into the
						   schema it is in the middle of assembling. */
						'options_from' => array( 'features', 'catalogue' ),
						/* Draws the per-car count beside each line, so an admin can see
						   what unticking one would actually take off the site. */
						'count_cars'   => true,
					),
					'catalogue' => array(
						'type'  => 'textarea',
						'label' => __( 'The wording available to tick, one per line', 'vesla-landing' ),
						'help'  => __( 'Add a line and it appears in the list above, ready to be offered. Change a line and it changes on every car that had it. Delete a line and it goes from everywhere at once — if you only want to stop offering something, untick it above instead, which keeps the wording here to switch back on later.', 'vesla-landing' ),
						'rows'  => 12,
					),
				),
			),
			/* ───────────────────────────────────────────────────────────────
			   CERTIFIED
			   ─────────────────────────────────────────────────────────────── */
			'certified' => array(
				'title'  => __( 'How cars are checked', 'vesla-landing' ),
				'blurb'  => __( 'The dark section listing the stages every car goes through. Each stage draws a line above it as the visitor scrolls to it.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'lead'    => array( 'type' => 'rich', 'label' => __( 'Paragraph under the heading', 'vesla-landing' ), ),
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give this section a page of its own', 'vesla-landing' ),
						'help'  => __( 'Publishes /certified/ as a page in its own right, showing the five stages in full. The homepage keeps its shorter version of the same section, and both read the settings on this screen — there is no second copy of the wording to keep in step. The page appears the next time the site is republished.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
						'help'  => __( 'The heading at the top of the page, and the title a search engine shows. Leave it empty to use the section heading above.', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph on the page', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace this before the page goes live. It opens the page under the heading, and is what a search engine shows as the description. Write it to open properly: the line on the homepage is a tease and this is the same subject arriving in full, so repeating that line here reads as padding to anyone who has just come from it.', 'vesla-landing' ),
					),
					'page_more_label' => array(
						'type'  => 'text',
						'label' => __( 'Link on the homepage through to the page', 'vesla-landing' ),
						'help'  => __( 'Appears on the homepage under the short version of this section, once the page above is switched on. Say where it goes rather than "read more": somebody deciding whether to press it is helped by "See the five stages" and not at all by "more".', 'vesla-landing' ),
					),
					'stages'  => array(
						'type'   => 'repeater',
						'label'  => __( 'The stages', 'vesla-landing' ),
						'help'   => __( 'The numbers are added automatically in the order the stages appear, so reordering them renumbers them for you.', 'vesla-landing' ),
						'row_label' => __( 'Stage', 'vesla-landing' ),
						'row_title' => array( 'title' ),
						'fields' => array(
							'title' => array( 'type' => 'text', 'label' => __( 'Stage name', 'vesla-landing' ), ),
							'text'  => array( 'type' => 'rich', 'label' => __( 'What happens at this stage', 'vesla-landing' ), ),
						),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   WHY
			   ─────────────────────────────────────────────────────────────── */
			'why' => array(
				'title'  => __( 'What buyers get', 'vesla-landing' ),
				'blurb'  => __( 'A row of short points on a white background, each in its own panel.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give this section a page of its own', 'vesla-landing' ),
						'help'  => __( 'Publishes /why/ showing this section in full. The homepage keeps its copy of the same section, and both read the fields on this screen. Until this is on, a menu link pointing here scrolls down the homepage instead.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
						'help'  => __( 'The heading at the top of the page and the title a search engine shows. Leave it empty to use the section heading above.', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph on the page', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace before the page goes live.', 'vesla-landing' ),
					),
					'page_more_label' => array(
						'type'  => 'text',
						'label' => __( 'Link on the homepage through to the page', 'vesla-landing' ),
						'help'  => __( 'Say where it goes rather than "read more".', 'vesla-landing' ),
					),
					'cards'   => array(
						'type'   => 'repeater',
						'label'  => __( 'Points', 'vesla-landing' ),
						'row_label' => __( 'Point', 'vesla-landing' ),
						'row_title' => array( 'title' ),
						'fields' => array(
							'title' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
							'text'  => array( 'type' => 'rich', 'label' => __( 'Wording', 'vesla-landing' ), ),
						),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   RECORD
			   ─────────────────────────────────────────────────────────────── */
			'record' => array(
				'title'  => __( 'Company record', 'vesla-landing' ),
				'blurb'  => __( 'Text on the left, a table of facts on the right.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'lead'    => array( 'type' => 'rich', 'label' => __( 'Main paragraph', 'vesla-landing' ), ),
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give this section a page of its own', 'vesla-landing' ),
						'help'  => __( 'Publishes /about/ as a page in its own right, showing the record, the ownership and the branches in full. The homepage keeps its shorter version of the same section, and both read the settings on this screen — there is no second copy of the wording to keep in step. The page appears the next time the site is republished.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
						'help'  => __( 'The heading at the top of the page, and the title a search engine shows. Leave it empty to use the section heading above.', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph on the page', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace this before the page goes live. It opens the page under the heading, and is what a search engine shows as the description. Write it to open properly: the line on the homepage is a tease and this is the same subject arriving in full, so repeating that line here reads as padding to anyone who has just come from it.', 'vesla-landing' ),
					),
					'page_more_label' => array(
						'type'  => 'text',
						'label' => __( 'Link on the homepage through to the page', 'vesla-landing' ),
						'help'  => __( 'Appears on the homepage under the short version of this section, once the page above is switched on. Say where it goes rather than "read more": somebody deciding whether to press it is helped by "See the five stages" and not at all by "more".', 'vesla-landing' ),
					),
					'note'    => array(
						'type'  => 'rich',
						'label' => __( 'Small note in the box underneath', 'vesla-landing' ),
						'help'  => __( 'Use this to say where a figure came from, or to be open about something you have chosen not to state. Leave empty to hide the box.', 'vesla-landing' ),
						
					),
					'glance'  => array(
						'type'   => 'repeater',
						'label'  => __( 'Table of facts', 'vesla-landing' ),
						'row_label' => __( 'Row', 'vesla-landing' ),
						'row_title' => array( 'label' ),
						'fields' => array(
							'label' => array( 'type' => 'text', 'label' => __( 'Left column', 'vesla-landing' ), ),
							'value' => array( 'type' => 'text', 'label' => __( 'Right column', 'vesla-landing' ), ),
						),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   CHAIRMAN / OWNERSHIP
			   ─────────────────────────────────────────────────────────────── */
			'chairman' => array(
				'title'  => __( 'Ownership', 'vesla-landing' ),
				'blurb'  => __( 'A dark section about who owns the company, with a monogram or a photograph on the left.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'text'    => array( 'type' => 'rich', 'label' => __( 'Paragraph', 'vesla-landing' ), ),
					'portrait' => array(
						'type'  => 'image',
						'label' => __( 'Photograph', 'vesla-landing' ),
						'help'  => __( 'A portrait of the chairman, if you have one to publish. Leave empty and the initials below are shown instead — which is better than a stock photograph of somebody else.', 'vesla-landing' ),
						
					),
					'monogram' => array(
						'type'  => 'text',
						'label' => __( 'Initials to show when there is no photograph', 'vesla-landing' ),
						'help'  => __( 'Two to four letters.', 'vesla-landing' ),
						
					),
					'rows' => array(
						'type'   => 'repeater',
						'label'  => __( 'Details', 'vesla-landing' ),
						'row_label' => __( 'Detail', 'vesla-landing' ),
						'row_title' => array( 'label' ),
						'fields' => array(
							'label' => array( 'type' => 'text', 'label' => __( 'Left column', 'vesla-landing' ), ),
							'value' => array( 'type' => 'text', 'label' => __( 'Right column', 'vesla-landing' ), ),
						),
						
					),
					'note' => array(
						'type'  => 'textarea',
						'label' => __( 'Small note underneath', 'vesla-landing' ),
						'help'  => __( 'Leave empty to hide it.', 'vesla-landing' ),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   SELL / ESTIMATOR
			   ─────────────────────────────────────────────────────────────── */
			'sell' => array(
				'title'  => __( 'Buying cars from visitors', 'vesla-landing' ),
				'blurb'  => __( 'Text on the left and the price estimator on the right.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'lead'    => array( 'type' => 'rich', 'label' => __( 'First paragraph', 'vesla-landing' ), ),
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give this section a page of its own', 'vesla-landing' ),
						'help'  => __( 'Publishes /sell/ as a page in its own right, showing the estimator and how you buy in full. The homepage keeps its shorter version of the same section, and both read the settings on this screen — there is no second copy of the wording to keep in step. The page appears the next time the site is republished.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
						'help'  => __( 'The heading at the top of the page, and the title a search engine shows. Leave it empty to use the section heading above.', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph on the page', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace this before the page goes live. It opens the page under the heading, and is what a search engine shows as the description. Write it to open properly: the line on the homepage is a tease and this is the same subject arriving in full, so repeating that line here reads as padding to anyone who has just come from it.', 'vesla-landing' ),
					),
					'page_more_label' => array(
						'type'  => 'text',
						'label' => __( 'Link on the homepage through to the page', 'vesla-landing' ),
						'help'  => __( 'Appears on the homepage under the short version of this section, once the page above is switched on. Say where it goes rather than "read more": somebody deciding whether to press it is helped by "See the five stages" and not at all by "more".', 'vesla-landing' ),
					),
					'body'    => array( 'type' => 'rich', 'label' => __( 'Second paragraph', 'vesla-landing' ), ),
					'note'    => array( 'type' => 'rich', 'label' => __( 'Small note underneath', 'vesla-landing' ), ),
					'est_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Show the price estimator', 'vesla-landing' ),
						'help'  => __( 'IMPORTANT: the estimator works out a rough range from the prices of your own stock. It is an indication, not a valuation — do not remove the wording below that says so.', 'vesla-landing' ),
						
					),
					'est_title'  => array( 'type' => 'text', 'label' => __( 'Estimator heading', 'vesla-landing' ), ),
					'est_sub'    => array( 'type' => 'text', 'label' => __( 'Estimator sub-heading', 'vesla-landing' ), ),
					'est_out_label' => array( 'type' => 'text', 'label' => __( 'Wording above the estimated figure', 'vesla-landing' ), ),
					'est_note'   => array( 'type' => 'textarea', 'label' => __( 'Small print under the estimated figure', 'vesla-landing' ), ),
					'est_send_title' => array( 'type' => 'text', 'label' => __( 'Heading over the send-it-to-us boxes', 'vesla-landing' ), ),
					'est_send_label' => array( 'type' => 'text', 'label' => __( 'Button under the estimate — wording', 'vesla-landing' ), ),
					'est_year_range' => array(
						'type'  => 'number',
						'label' => __( 'How many years back the Year menu goes', 'vesla-landing' ),
						'help'  => __( 'The estimator offers this many years to choose from, counting back from this year. Raise it if you take older cars in part-exchange.', 'vesla-landing' ),
						'min' => 1, 'max' => 60, 
					),
					'est_residual_floor' => array(
						'type'  => 'number',
						'label' => __( 'Lowest percentage of value a car can fall to', 'vesla-landing' ),
						'help'  => __( 'However old a car is, the estimator never values it below this share of the going rate for its make. Without a floor the sum trends towards nothing, which is not what an old car is worth.', 'vesla-landing' ),
						'min' => 1, 'max' => 90, 
					),
					'est_depreciation' => array(
						'type'  => 'number',
						'label' => __( 'How much value a car loses each year, as a percentage', 'vesla-landing' ),
						'help'  => __( 'Used by the estimator only. 11 means a car is worth about 11% less for each year of age.', 'vesla-landing' ),
						'min' => 0, 'max' => 40, 
					),
					'est_spread' => array(
						'type'  => 'number',
						'label' => __( 'How wide the estimated range is, as a percentage either side', 'vesla-landing' ),
						'help'  => __( 'The estimator shows a range, not a single figure, because it has not seen the car. 8 means the range runs from 8% below the calculated value to 8% above it. Widen it if the figures are coming out tighter than a valuer would honestly commit to.', 'vesla-landing' ),
						'min' => 0, 'max' => 40,
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   FAQ
			   ─────────────────────────────────────────────────────────────── */
			'faq' => array(
				'title'  => __( 'Questions and answers', 'vesla-landing' ),
				'blurb'  => __( 'A list of questions that open when clicked. These are also handed to Google as the page’s question-and-answer information, so the wording here should be the real answer a customer would be given.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Give this section a page of its own', 'vesla-landing' ),
						'help'  => __( 'Publishes /faq/ showing this section in full. The homepage keeps its copy of the same section, and both read the fields on this screen. Until this is on, a menu link pointing here scrolls down the homepage instead.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
						'help'  => __( 'The heading at the top of the page and the title a search engine shows. Leave it empty to use the section heading above.', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph on the page', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace before the page goes live.', 'vesla-landing' ),
					),
					'page_more_label' => array(
						'type'  => 'text',
						'label' => __( 'Link on the homepage through to the page', 'vesla-landing' ),
						'help'  => __( 'Say where it goes rather than "read more".', 'vesla-landing' ),
					),
					'items'   => array(
						'type'   => 'repeater',
						'label'  => __( 'Questions', 'vesla-landing' ),
						'row_label' => __( 'Question', 'vesla-landing' ),
						'row_title' => array( 'q' ),
						'fields' => array(
							'q' => array( 'type' => 'text', 'label' => __( 'Question', 'vesla-landing' ), ),
							'a' => array( 'type' => 'rich', 'label' => __( 'Answer', 'vesla-landing' ), ),
						),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   CONTACT
			   ─────────────────────────────────────────────────────────────── */
			'contact' => array(
				'title'  => __( 'Contact section & enquiry form', 'vesla-landing' ),
				'blurb'  => __( 'The dark section at the bottom, with your contact details on the left and the enquiry form on the right — and the separate Contact page, which is built from the same details.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ), ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ), ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ), ),
					'lead'    => array( 'type' => 'rich', 'label' => __( 'Paragraph under the heading', 'vesla-landing' ), ),

					/* ── the separate Contact page ──
					   A page of its own at /contact/, built from everything already
					   entered here rather than from a second set of fields: the same
					   channels, the same hours, the same enquiry form, the same
					   branches and map. Only its own opening lines are new, because a
					   page needs a heading of its own -- the section's heading is
					   written to be read after somebody has scrolled the whole front
					   page, and that is not what a visitor arriving cold at /contact/
					   has done. */
					'page_enabled' => array(
						'type'    => 'toggle',
						'label'   => __( 'Also publish a separate Contact page', 'vesla-landing' ),
						'default' => 1,
						'help'    => __( 'A page of its own at /contact/, carrying the same details, hours, form and branches as this section — entered once, shown in both places. The header’s Contact button points at it.', 'vesla-landing' ),
					),
					'page_eyebrow' => array(
						'type'    => 'text',
						'label'   => __( 'Contact page — small line above the heading', 'vesla-landing' ),
						'default' => 'Talk to us',
					),
					'page_heading' => array(
						'type'    => 'text',
						'label'   => __( 'Contact page — heading', 'vesla-landing' ),
						'default' => 'Come and see the car.',
					),
					'page_lead' => array(
						'type'    => 'rich',
						'label'   => __( 'Contact page — paragraph under the heading', 'vesla-landing' ),
						'default' => 'Call, message or write — whichever suits. Someone who knows the stock answers, not a call centre, and if the car you are asking about has gone we will say so rather than sell you another one.',
					),
					'hours' => array(
						'type'   => 'repeater',
						'label'  => __( 'Opening hours', 'vesla-landing' ),
						'help'   => __( 'One row per day. Leave a day’s times empty, or tick Closed, and the page says the showroom is closed that day rather than guessing. These are also handed to Google, which is what lets it show “Open now” in search results.', 'vesla-landing' ),
						'row_label' => __( 'Day', 'vesla-landing' ),
						'row_title' => array( 'day' ),
						'fields' => array(
							'day' => array(
								'type'  => 'select',
								'label' => __( 'Day', 'vesla-landing' ),
								'choices' => array(
									'Monday'    => __( 'Monday', 'vesla-landing' ),
									'Tuesday'   => __( 'Tuesday', 'vesla-landing' ),
									'Wednesday' => __( 'Wednesday', 'vesla-landing' ),
									'Thursday'  => __( 'Thursday', 'vesla-landing' ),
									'Friday'    => __( 'Friday', 'vesla-landing' ),
									'Saturday'  => __( 'Saturday', 'vesla-landing' ),
									'Sunday'    => __( 'Sunday', 'vesla-landing' ),
								),
							),
							'opens' => array(
								'type'  => 'text',
								'label' => __( 'Opens', 'vesla-landing' ),
								'help'  => __( 'Twenty-four hour clock, e.g. 09:00.', 'vesla-landing' ),
							),
							'closes' => array(
								'type'  => 'text',
								'label' => __( 'Closes', 'vesla-landing' ),
								'help'  => __( 'Twenty-four hour clock, e.g. 21:00.', 'vesla-landing' ),
							),
							'closed' => array(
								'type'  => 'toggle',
								'label' => __( 'Closed all day', 'vesla-landing' ),
							),
						),
					),
					'hours_title' => array(
						'type'  => 'text',
						'label' => __( 'Heading above the opening hours', 'vesla-landing' ),
					),
					'hours_closed_label' => array(
						'type'  => 'text',
						'label' => __( 'Wording for a day the showroom is closed', 'vesla-landing' ),
					),
					'channels' => array(
						'type'   => 'repeater',
						'label'  => __( 'Ways to get in touch', 'vesla-landing' ),
						'help'   => __( 'Leave the link empty for a line that is information only, such as an address that is not on a map yet.', 'vesla-landing' ),
						'row_label' => __( 'Contact line', 'vesla-landing' ),
						'row_title' => array( 'label' ),
						'fields' => array(
							'label' => array( 'type' => 'text', 'label' => __( 'Left column', 'vesla-landing' ), ),
							'value' => array( 'type' => 'text', 'label' => __( 'What is shown', 'vesla-landing' ), ),
							'link'  => array( 'type' => 'url',  'label' => __( 'Where it goes when clicked', 'vesla-landing' ), 'help' => __( 'Start with tel: for a phone number, mailto: for an email, or https:// for a web address.', 'vesla-landing' ), ),
						),
						
					),
					'form_title'  => array( 'type' => 'text', 'label' => __( 'Form heading', 'vesla-landing' ), ),
					'form_submit' => array( 'type' => 'text', 'label' => __( 'Send button — wording', 'vesla-landing' ), ),
					'form_success' => array(
						'type'  => 'text',
						'label' => __( 'Message shown after a successful send', 'vesla-landing' ),
						
					),
					'form_to' => array(
						'type'  => 'email',
						'label' => __( 'Send enquiries to', 'vesla-landing' ),
						'help'  => __( 'Leave empty to use the enquiry email address from the first section.', 'vesla-landing' ),
						
					),
					'form_l_name'  => array( 'type' => 'text', 'label' => __( 'Field label — name', 'vesla-landing' ), ),
					'form_l_phone' => array( 'type' => 'text', 'label' => __( 'Field label — phone', 'vesla-landing' ), ),
					'form_l_email' => array( 'type' => 'text', 'label' => __( 'Field label — email', 'vesla-landing' ), ),
					'form_l_car'   => array( 'type' => 'text', 'label' => __( 'Field label — car of interest', 'vesla-landing' ), ),
					'form_l_note'  => array( 'type' => 'text', 'label' => __( 'Field label — message', 'vesla-landing' ), ),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   FORM MESSAGES
			   ─────────────────────────────────────────────────────────────── */
			'messages' => array(
				'title'  => __( 'Enquiry form wording', 'vesla-landing' ),
				'blurb'  => __( 'What the form says when something is wrong, or when it has sent. These are the only words most visitors will read on the way to contacting you, so they are worth getting right — say what to do, not what failed.', 'vesla-landing' ),
				'fields' => array(

					'wa_text' => array(
						'type'  => 'textarea',
						'label' => __( 'WhatsApp message, written for the visitor', 'vesla-landing' ),
						'help'  => __( 'Filled in for them when they press the WhatsApp button on a car, so all they have to do is press send. Write %1$s where the car should appear and %2$s where the price should.', 'vesla-landing' ),
						
					),
					'send_fail' => array(
						'type'  => 'text',
						'label' => __( 'Shown when the enquiry could not be sent', 'vesla-landing' ),
						'help'  => __( 'This is the one message a lost sale reads, so put a phone number in it — the visitor is already trying to reach you and the form has just failed them.', 'vesla-landing' ),
						
					),
					'sending' => array(
						'type'  => 'text',
						'label' => __( 'Shown while the enquiry is being sent', 'vesla-landing' ),
						
					),
					'showing' => array(
						'type'  => 'text',
						'label' => __( 'The line above the cars saying how many are shown', 'vesla-landing' ),
						'help'  => __( 'Write %1$s where the number being shown should appear, and %2$s for the total.', 'vesla-landing' ),
						
					),
					'seats_word' => array(
						'type'  => 'text',
						'label' => __( 'The word after the number of seats', 'vesla-landing' ),
						'help'  => __( 'Appears on every car, e.g. “Petrol · 5 seats”.', 'vesla-landing' ),
						
					),

					/* ── what the form says when a field is wrong ──
					   These are used by BOTH the checks in the browser and the
					   checks on the server. They were separate lists and had
					   already drifted apart: the browser said "That number is
					   too short to dial" where the server said "That phone
					   number does not look right" about the same number. One
					   list means they cannot disagree again. */
					'err_name_required' => array(
						'type' => 'text', 'label' => __( 'Name — left empty', 'vesla-landing' ),
						
					),
					'err_name_short' => array(
						'type' => 'text', 'label' => __( 'Name — too short', 'vesla-landing' ),
						
					),
					'err_name_long' => array(
						'type' => 'text', 'label' => __( 'Name — too long', 'vesla-landing' ),
						
					),
					'err_name_letters' => array(
						'type' => 'text', 'label' => __( 'Name — contains digits or symbols', 'vesla-landing' ),
						
					),
					'err_phone_required' => array(
						'type' => 'text', 'label' => __( 'Phone — left empty', 'vesla-landing' ),
						
					),
					'err_phone_short' => array(
						'type' => 'text', 'label' => __( 'Phone — too short', 'vesla-landing' ),
						
					),
					'err_phone_long' => array(
						'type' => 'text', 'label' => __( 'Phone — too long', 'vesla-landing' ),
						
					),
					'err_phone_country' => array(
						'type' => 'text', 'label' => __( 'Phone — starts with + but is incomplete', 'vesla-landing' ),
						
					),
					'err_phone_letters' => array(
						'type'  => 'text',
						'label' => __( 'Phone — letters were removed as they typed', 'vesla-landing' ),
						'help'  => __( 'Letters cannot be typed into the phone box at all; this explains why they vanished, so nobody thinks the box is broken.', 'vesla-landing' ),
						
					),
					'err_email_invalid' => array(
						'type' => 'text', 'label' => __( 'Email — not a valid address', 'vesla-landing' ),
						
					),
					'err_email_long' => array(
						'type' => 'text', 'label' => __( 'Email — too long', 'vesla-landing' ),
						
					),
					'err_car_long' => array(
						'type' => 'text', 'label' => __( 'Car of interest — too long', 'vesla-landing' ),
						
					),
					'err_note_long' => array(
						'type' => 'text', 'label' => __( 'Message — too long', 'vesla-landing' ),
						
					),
					'err_blocked' => array(
						'type'  => 'text',
						'label' => __( 'Shown when a field contains code or angle brackets', 'vesla-landing' ),
						'help'  => __( 'Text that looks like code is refused rather than quietly altered, because the enquiry is emailed to you and may be read in a mail program that would run it.', 'vesla-landing' ),
						
					),
					'err_summary_one' => array(
						'type' => 'text', 'label' => __( 'Summary under the button — one field wrong', 'vesla-landing' ),
						
					),
					'err_summary_many' => array(
						'type'  => 'text',
						'label' => __( 'Summary under the button — several fields wrong', 'vesla-landing' ),
						'help'  => __( 'Write %s where the number of fields should appear.', 'vesla-landing' ),
						
					),
					'err_rate_limited' => array(
						'type'  => 'text',
						'label' => __( 'Shown when somebody sends too many enquiries too quickly', 'vesla-landing' ),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   FOOTER
			   ─────────────────────────────────────────────────────────────── */
			'footer' => array(
				'title'  => __( 'Footer', 'vesla-landing' ),
				'blurb'  => __( 'The four columns at the very bottom of the page, and the small print under them.', 'vesla-landing' ),
				'fields' => array(
					'watermark' => array(
						'type'  => 'image',
						'label' => __( 'The large mark on the right of the footer', 'vesla-landing' ),
						'help'  => __( 'The shield printed big at the far right, behind the columns. Leave it empty and the site’s own logo is used. A PNG with a transparent background works best — anything with a white square around it will show as a white block.', 'vesla-landing' ),
					),
					'blurb' => array( 'type' => 'rich', 'label' => __( 'Sentence under the logo', 'vesla-landing' ), ),
					'badges' => array(
						'type'   => 'repeater',
						'label'  => __( 'Small tags under that sentence', 'vesla-landing' ),
						'row_label' => __( 'Tag', 'vesla-landing' ),
						'row_title' => array( 'text' ),
						'fields' => array( 'text' => array( 'type' => 'text', 'label' => __( 'Wording', 'vesla-landing' ), ) ),
						
					),
					'nav_title' => array( 'type' => 'text', 'label' => __( 'Second column — heading', 'vesla-landing' ), ),
					'nav' => array(
						'type'   => 'repeater',
						'label'  => __( 'Second column — links', 'vesla-landing' ),
						'row_label' => __( 'Link', 'vesla-landing' ),
						'row_title' => array( 'label' ),
						'fields' => array(
							'label' => array( 'type' => 'text', 'label' => __( 'Wording', 'vesla-landing' ), ),
							'link'  => array( 'type' => 'url',  'label' => __( 'Jumps to', 'vesla-landing' ), ),
						),
						
					),
					'contact_title' => array( 'type' => 'text', 'label' => __( 'Third column — heading', 'vesla-landing' ), ),
					'contact' => array(
						'type'   => 'repeater',
						'label'  => __( 'Third column — contact lines', 'vesla-landing' ),
						'row_label' => __( 'Contact line', 'vesla-landing' ),
						'row_title' => array( 'value' ),
						'fields' => array(
							'value' => array( 'type' => 'text', 'label' => __( 'What is shown', 'vesla-landing' ), ),
							'label' => array( 'type' => 'text', 'label' => __( 'Small wording underneath', 'vesla-landing' ), ),
							'link'  => array( 'type' => 'url',  'label' => __( 'Where it goes when clicked', 'vesla-landing' ), ),
						),
						
					),
					'where_title' => array( 'type' => 'text', 'label' => __( 'Fourth column — heading', 'vesla-landing' ), ),
					'where_text'  => array( 'type' => 'rich', 'label' => __( 'Fourth column — wording', 'vesla-landing' ), ),
					'where_note'  => array( 'type' => 'text', 'label' => __( 'Fourth column — smaller line underneath', 'vesla-landing' ), ),
					'cta_label'   => array( 'type' => 'text', 'label' => __( 'Fourth column — button wording', 'vesla-landing' ), 'help' => __( 'Leave empty to remove the button.', 'vesla-landing' ), ),
					'cta_link'    => array( 'type' => 'url',  'label' => __( 'Fourth column — button jumps to', 'vesla-landing' ), ),
					'copyright'   => array(
						'type'  => 'text',
						'label' => __( 'Copyright line', 'vesla-landing' ),
						'help'  => __( 'Write {year} where you want the current year, and it will always be right without anyone updating it in January.', 'vesla-landing' ),
						
					),
					'meta' => array( 'type' => 'text', 'label' => __( 'Small line on the right', 'vesla-landing' ), ),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   PHONE BAR + LOADER + LOOK
			   ─────────────────────────────────────────────────────────────── */
			'extras' => array(
				'title'  => __( 'Phone bar, loading screen & colours', 'vesla-landing' ),
				'blurb'  => __( 'Smaller settings that affect the whole page.', 'vesla-landing' ),
				'fields' => array(
					'actbar_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Show the call bar at the bottom on phones', 'vesla-landing' ),
						'help'  => __( 'A bar with a call button and a WhatsApp button, fixed to the bottom of the screen on phones only. It appears once the visitor has scrolled past the opening section.', 'vesla-landing' ),
						
					),
					'actbar_call' => array( 'type' => 'text', 'label' => __( 'Call bar — first button wording', 'vesla-landing' ), ),
					'actbar_wa'   => array( 'type' => 'text', 'label' => __( 'Call bar — second button wording', 'vesla-landing' ), ),

					'totop_at' => array(
						'type'  => 'number',
						'label' => __( 'Back-to-top button appears after scrolling this far', 'vesla-landing' ),
						'help'  => __( 'As a percentage of one screen height, not pixels: 80 means it appears once the visitor has scrolled about four fifths of a screen. A fixed pixel figure is a different point on a phone than on a desktop, which is why this is a share.', 'vesla-landing' ),
						'min' => 10, 'max' => 400, 
					),
					'actbar_at' => array(
						'type'  => 'number',
						'label' => __( 'Phone call bar appears after scrolling this far', 'vesla-landing' ),
						'help'  => __( 'Also a percentage of one screen height. Keep it below the back-to-top figure so the call buttons arrive first.', 'vesla-landing' ),
						'min' => 10, 'max' => 400, 
					),
					'loader_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Show the loading screen', 'vesla-landing' ),
						'help'  => __( 'The brief screen with the logo that appears while the page loads. It always clears itself, and any click or scroll dismisses it immediately.', 'vesla-landing' ),
						
					),
					'tidy_admin' => array(
						'type'  => 'toggle',
						'label' => __( 'Hide the WordPress menus this site does not use', 'vesla-landing' ),
						'help'  => __( 'Posts and Comments. This is a one-page dealership site with no blog and no comment threads, and every unused menu is one more place for somebody to lose an afternoon. Nothing is deleted — turn this off and both come straight back, and anything already written is still there.', 'vesla-landing' ),
					),
					'loader_min' => array(
						'type'  => 'number',
						'label' => __( 'Shortest the loading screen stays, in milliseconds', 'vesla-landing' ),
						'help'  => __( 'The number most people mean by “make the loader one second”. On a fast connection the page is ready almost at once, and a screen that appears and vanishes inside three frames reads as a fault rather than as a loader. 400 is the shortest that still looks deliberate; the mark finishes drawing itself at 820, so anything up to about 800 shows the whole animation.', 'vesla-landing' ),
						'min' => 0, 'max' => 3000,
					),
					'loader_max' => array(
						'type'  => 'number',
						'label' => __( 'Longest the loading screen may stay, in seconds', 'vesla-landing' ),
						'help'  => __( 'A safety limit, not a duration — the screen clears as soon as the page is ready, whichever comes first. This only matters when something on the page never finishes loading at all; without it the screen would stay for ever.', 'vesla-landing' ),
						'min' => 1, 'max' => 10,
					),

					'opening_show' => array(
						'type'    => 'select',
						'label'   => __( 'Show the opening', 'vesla-landing' ),
						'default' => 'first',
						'options' => array(
							'never' => __( 'Never', 'vesla-landing' ),
							'first' => __( 'On somebody’s first visit only', 'vesla-landing' ),
							'every' => __( 'Every visit', 'vesla-landing' ),
						),
						'help'    => __( 'The shield arriving on its own before the page, built from the logo already on this site — no video file, nothing to upload and nothing extra to download. Off means the markup is not printed at all.', 'vesla-landing' ),
					),
					'opening_max' => array(
						'type'  => 'number',
						'label' => __( 'Longest the opening may stay, in seconds', 'vesla-landing' ),
						'default' => 4,
						'help'  => __( 'A ceiling, not a duration. The opening runs about 1.7 seconds and clears itself; this only matters if something goes wrong, and it makes sure nobody is ever held behind it.', 'vesla-landing' ),
						'min' => 1, 'max' => 10,
					),
					'opening_skip_label' => array(
						'type'    => 'text',
						'label'   => __( 'Wording on the skip button', 'vesla-landing' ),
						'default' => 'Skip',
						'help'    => __( 'Visible from the first frame. Somebody who does not want to watch must never have to wait to say so.', 'vesla-landing' ),
					),

					'motion_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Use the animations', 'vesla-landing' ),
						'help'  => __( 'The fades, the counting figures and the cards that tilt under the mouse. Switching this off leaves the page fully readable and a little faster on old machines.', 'vesla-landing' ),
						
					),
					'respect_reduced_motion' => array(
						'type'  => 'toggle',
						'label' => __( 'Honour the visitor’s “reduce motion” setting', 'vesla-landing' ),
						'help'  => __( 'Recommended for accessibility. It is off by default only because Windows battery saver reports this setting even when the visitor has not asked for it, which switched the animations off unexpectedly. Turn it on if your audience needs it.', 'vesla-landing' ),
						
					),

					'color_accent' => array(
						'type'  => 'color',
						'label' => __( 'Brand colour', 'vesla-landing' ),
						'help'  => __( 'Taken from the logo. Used for the heading at the top, the underlines, the small rules and the main buttons.', 'vesla-landing' ),
						
					),
					'color_accent_text' => array(
						'type'  => 'color',
						'label' => __( 'Brand colour, darker — for text on white', 'vesla-landing' ),
						'help'  => __( 'A bright yellow cannot be read on a white background, so a darker version of the same colour is used for prices and small headings. Keep this dark enough to read comfortably.', 'vesla-landing' ),
						
					),
					'color_ink' => array(
						'type'  => 'color',
						'label' => __( 'Dark background colour', 'vesla-landing' ),
						
					),
					'color_paper' => array(
						'type'  => 'color',
						'label' => __( 'Light background colour', 'vesla-landing' ),
						'help'  => __( 'Used for the tinted sections such as the questions and the company record.', 'vesla-landing' ),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   PUBLISHING THE STATIC PAGE
			   ─────────────────────────────────────────────────────────────── */
			'publish' => array(
				'title'  => __( 'Publish the public page', 'vesla-landing' ),
				'blurb'  => __( 'For the set-up where visitors are served a plain HTML file and WordPress sits behind it as the editor. Every time you save, the page is written out again with all of the wording and every car already in it — so it loads instantly and search engines can read it without running anything.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Write the public page when I save', 'vesla-landing' ),
						'help'  => __( 'Leave this off if visitors see the page WordPress itself renders. Turn it on only for the separate-HTML-file set-up. Because visitors then never load a WordPress page, WordPress’s scheduled tasks have nothing to run them — so the server needs a real cron job calling wp-cron.php every five minutes, and wp-config.php needs DISABLE_WP_CRON set to true. Without it the page is only written when somebody opens this screen, and you will be told here when that has left something waiting. The readme has the exact wording for cPanel.', 'vesla-landing' ),
						
					),
					'site_url' => array(
						'type'  => 'text',
						'label' => __( 'Web address of the public site', 'vesla-landing' ),
						'help'  => __( 'Where visitors actually go — https://veslamotors.com, not the /cms address you are reading this on. Leave empty and the address one level above WordPress is used. It is needed because the published page has to point at its own stylesheet and pictures, and because search engines require full addresses in the listing data.', 'vesla-landing' ),
						
					),
					'cms_url' => array(
						'type'  => 'text',
						'label' => __( 'Web address WordPress will be at on the live site', 'vesla-landing' ),
						'help'  => __( 'Where WordPress itself answers once the site is live — usually the /cms address under your public site. The published page carries the photographs, the data feed and the enquiry endpoint from here, and while you are editing they all point at the computer you are editing on, which no visitor can reach. Leave empty and cms/ under the public address is used.', 'vesla-landing' ),
					),
					'export_path' => array(
						'type'  => 'text',
						'label' => __( 'Folder to write the content export into', 'vesla-landing' ),
						'help'  => __( 'The settings and every vehicle, written to content-export.json in this folder. Point it at the project folder you keep in version control and the words and the cars are versioned alongside the code — without it, a repository holds the site’s code and none of its content. Leave empty and the file is written inside the plugin’s own data folder.', 'vesla-landing' ),
					),
					'path' => array(
						'type'  => 'text',
						'label' => __( 'Folder to write index.html into', 'vesla-landing' ),
						'help'  => __( 'The full path to your public folder — usually /home/youraccount/public_html. Leave empty and the folder one level above WordPress is used, which is right when WordPress lives in a “cms” folder inside it. If the folder cannot be written to you will be told, loudly, rather than the page silently staying as it was.', 'vesla-landing' ),
						
					),
				),
			),

			/* ───────────────────────────────────────────────────────────────
			   SEARCH ENGINES
			   ─────────────────────────────────────────────────────────────── */
			/* ───────────────────────────────────────────────────────────────
			   WHERE WE ARE
			   ─────────────────────────────────────────────────────────────── */
			'map' => array(
				'title'  => __( 'Where we are', 'vesla-landing' ),
				'blurb'  => __( 'The strip under the footer: a map with a pin on each branch, and the address beside it. The coordinates are also handed to Google, which is what puts the showroom on the map in search results.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array( 'type' => 'toggle', 'label' => __( 'Show this section', 'vesla-landing' ) ),
					'eyebrow' => array( 'type' => 'text', 'label' => __( 'Small line above the heading', 'vesla-landing' ) ),
					'heading' => array( 'type' => 'text', 'label' => __( 'Heading', 'vesla-landing' ) ),
					'zoom'    => array(
						'type'  => 'number',
						'label' => __( 'How closely the map is zoomed in', 'vesla-landing' ),
						'help'  => __( 'Roughly: 12 shows the city, 15 the district, 17 the street.', 'vesla-landing' ),
						'min' => 3, 'max' => 20,
					),
					'tile_url' => array(
						'type'  => 'text',
						'label' => __( 'Where the map pictures come from', 'vesla-landing' ),
						'help'  => __( 'An address with {z}, {x} and {y} in it. The default needs no account and no key, and it shows only what is on the map — no other business is pinned on it, so the only marker a visitor sees is ours. If the map ever fills with “API key required”, that supplier has started charging: paste a different one here and nothing else has to change.', 'vesla-landing' ),
					),
					'tile_credit' => array(
						'type'  => 'text',
						'label' => __( 'Credit shown in the corner of the map', 'vesla-landing' ),
						'help'  => __( 'Whoever supplies the pictures requires this. Do not remove it without checking their terms.', 'vesla-landing' ),
					),

					'branches' => array(
						'type'   => 'repeater',
						'label'  => __( 'Branches', 'vesla-landing' ),
						'help'   => __( 'Each one gets a pin. The latitude and longitude are the numbers Google Maps shows when you right-click a place and copy the coordinates — the first is latitude, the second longitude.', 'vesla-landing' ),
						'row_label' => __( 'Branch', 'vesla-landing' ),
						'row_title' => array( 'name' ),
						'fields' => array(
							'name'    => array( 'type' => 'text', 'label' => __( 'Branch name', 'vesla-landing' ) ),
							'address' => array( 'type' => 'textarea', 'label' => __( 'Address', 'vesla-landing' ) ),
							'phone'   => array( 'type' => 'text', 'label' => __( 'Telephone shown for this branch', 'vesla-landing' ) ),
							'lat'     => array( 'type' => 'text', 'label' => __( 'Latitude', 'vesla-landing' ) ),
							'lng'     => array( 'type' => 'text', 'label' => __( 'Longitude', 'vesla-landing' ) ),
							'link_label' => array( 'type' => 'text', 'label' => __( 'Wording on the directions button', 'vesla-landing' ) ),
						),
					),
				),
			),

			'seo' => array(
				'title'  => __( 'Search engines & sharing', 'vesla-landing' ),
				'blurb'  => __( 'What Google shows in its results, and what appears when somebody shares the page on WhatsApp or Facebook. If you already use an SEO plugin such as Yoast or Rank Math, switch the first setting off and let that plugin handle it instead.', 'vesla-landing' ),
				'fields' => array(
					'enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Let this plugin write the search-engine information', 'vesla-landing' ),
						'help'  => __( 'Switch off if an SEO plugin is already doing it, so the two do not both write it.', 'vesla-landing' ),
						
					),
					'description' => array(
						'type'  => 'textarea',
						'label' => __( 'Description shown in search results', 'vesla-landing' ),
						'help'  => __( 'About 150 characters. Google may show its own wording instead, but this is what you are asking for.', 'vesla-landing' ),
						
					),
					'share_image' => array(
						'type'  => 'image',
						'label' => __( 'Image used when the page is shared', 'vesla-landing' ),
						'help'  => __( 'Around 1200 × 630. A good photograph of a car works better than the logo here.', 'vesla-landing' ),
						
					),
					'business_name' => array( 'type' => 'text', 'label' => __( 'Business name', 'vesla-landing' ), ),
					'city'          => array( 'type' => 'text', 'label' => __( 'City', 'vesla-landing' ), ),
					'country_code'  => array( 'type' => 'text', 'label' => __( 'Country code', 'vesla-landing' ), 'help' => __( 'Two letters, e.g. AE for the United Arab Emirates.', 'vesla-landing' ), ),
					'founded'       => array( 'type' => 'text', 'label' => __( 'Year founded', 'vesla-landing' ), ),
					'profiles' => array(
						'type'      => 'repeater',
						'label'     => __( 'Where else this showroom is, officially', 'vesla-landing' ),
						'help'      => __( 'The showroom’s own pages on other sites — Instagram, Facebook, LinkedIn, a Dubizzle dealer page, a Google Business listing. Google uses these to confirm that this website and those accounts are the same company. Only accounts this showroom controls: linking somebody else’s page tells Google the wrong thing.', 'vesla-landing' ),
						'row_label' => __( 'Profile', 'vesla-landing' ),
						'row_title' => array( 'url' ),
						'fields'    => array(
							'url' => array( 'type' => 'url', 'label' => __( 'Web address', 'vesla-landing' ), 'help' => __( 'The full address, e.g. https://www.instagram.com/veslamotors', 'vesla-landing' ) ),
						),
					),
					'parent_name'   => array( 'type' => 'text', 'label' => __( 'Parent company name', 'vesla-landing' ), 'help' => __( 'Leave empty if there is none.', 'vesla-landing' ), ),
					'parent_url'    => array( 'type' => 'url',  'label' => __( 'Parent company website', 'vesla-landing' ), ),
				),
			),
			'finance' => array(
				'title'  => __( 'Finance', 'vesla-landing' ),
				'blurb'  => __( 'The finance page. The calculator on it uses the same rate, deposit and term as the one on every car page — those are set under Vehicle pages, and changing them there changes both. Everything on this screen is the wording around it.', 'vesla-landing' ),
				'fields' => array(
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Publish the finance page', 'vesla-landing' ),
						'help'  => __( 'Publishes /finance/ the next time the site is republished. Nothing on the homepage changes: finance has no section there, so this page is reached from the menu — and because there is no section to fall back to, switching this off ALSO takes the Finance row out of the menu and the footer until you switch it back on. Every other page keeps its menu row when its page is off, because every other page has a section on the homepage for the link to scroll to instead.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace before the page goes live.', 'vesla-landing' ),
					),
					'steps_title' => array( 'type' => 'text', 'label' => __( 'Heading over the steps', 'vesla-landing' ) ),
					'steps' => array(
						'type'   => 'repeater',
						'label'  => __( 'How it works — the steps', 'vesla-landing' ),
						'row_label' => __( 'Step', 'vesla-landing' ),
						'row_title' => array( 'title' ),
						'help'   => __( 'PLACEHOLDER — the starter rows are questions, not answers. Nobody here knows how your finance is arranged, and a plugin guessing at it would put a false promise on a page a buyer makes a decision from.', 'vesla-landing' ),
						'fields' => array(
							'title' => array( 'type' => 'text', 'label' => __( 'Step', 'vesla-landing' ) ),
							'text'  => array( 'type' => 'textarea', 'label' => __( 'What happens', 'vesla-landing' ) ),
						),
					),
					'docs_title' => array( 'type' => 'text', 'label' => __( 'Heading over the documents list', 'vesla-landing' ) ),
					'docs' => array(
						'type'   => 'repeater',
						'label'  => __( 'What to bring', 'vesla-landing' ),
						'row_label' => __( 'Document', 'vesla-landing' ),
						'row_title' => array( 'item' ),
						'help'   => __( 'PLACEHOLDER — what a bank asks for is a question for your bank, not for this plugin.', 'vesla-landing' ),
						'fields' => array(
							'item' => array( 'type' => 'text', 'label' => __( 'Document', 'vesla-landing' ) ),
							'note' => array( 'type' => 'text', 'label' => __( 'Small note beside it', 'vesla-landing' ) ),
						),
					),
					'calc_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Show the calculator on this page', 'vesla-landing' ),
						'help'  => __( 'The same sum as the one on a car page, with the price typed in rather than taken from a car. The rate, the deposit and the term come from Vehicle pages, so the two can never quote differently.', 'vesla-landing' ),
					),
					'calc_title' => array( 'type' => 'text', 'label' => __( 'Heading over the calculator', 'vesla-landing' ) ),
					'calc_price_label' => array( 'type' => 'text', 'label' => __( 'Wording on the price box', 'vesla-landing' ) ),
					'calc_note' => array(
						'type'  => 'textarea',
						'label' => __( 'Small print under the monthly figure', 'vesla-landing' ),
						'help'  => __( 'This is an estimate a buyer may act on. Say plainly that it is one, and that the real figure depends on the finance they are approved for.', 'vesla-landing' ),
					),
					'ask_label' => array( 'type' => 'text', 'label' => __( 'Wording on the button under the figure', 'vesla-landing' ) ),
				),
			),
			'sold' => array(
				'title'  => __( 'Sold cars', 'vesla-landing' ),
				'blurb'  => __( 'A page of what has already gone, with the photographs and without the prices. A car appears here when its status is set to Sold on the car itself; nothing has to be listed twice.', 'vesla-landing' ),
				'fields' => array(
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Publish the sold page', 'vesla-landing' ),
						'help'  => __( 'Worth having: a page of cars that have gone is evidence that they go. It carries no prices.', 'vesla-landing' ),
					),
					'page_heading' => array( 'type' => 'text', 'label' => __( 'Page heading', 'vesla-landing' ), ),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace before the page goes live.', 'vesla-landing' ),
					),
					'empty_text' => array(
						'type'  => 'text',
						'label' => __( 'Wording when nothing has been sold yet', 'vesla-landing' ),
						'help'  => __( 'Shown instead of the grid while no car is marked Sold, so the page is never simply blank.', 'vesla-landing' ),
					),
				),
			),
			'privacy' => array(
				'title'  => __( 'Privacy policy', 'vesla-landing' ),
				'blurb'  => __( 'The privacy page, off until you switch it on. What is in it now is WordPress own starter text, written for a blog with comments and profile pictures — none of which this site has. Replace it before switching the page on.', 'vesla-landing' ),
				'fields' => array(
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Publish the privacy page', 'vesla-landing' ),
						'help'  => __( 'Publishes /privacy/ the next time the site is republished. Read what is below first: the starter text names things this site does not do.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph', 'vesla-landing' ),
						'help'  => __( 'Optional. Leave it empty and the page starts with the policy itself.', 'vesla-landing' ),
					),
					'body' => array(
						'type'  => 'rich',
						'label' => __( 'The policy', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — this is WordPress starter text and most of it is about a blog. What this site actually does with personal information: the enquiry form stores a name, a telephone number, an optional email address and a message; the rate limit keeps a one-way hash of the sender IP address and never the address itself; and visitor statistics are collected only if you have switched them on under Visitor statistics. Nothing else is gathered. Have somebody who knows UAE requirements write the real thing.', 'vesla-landing' ),
					),
				),
			),
			'terms' => array(
				'title'  => __( 'Terms', 'vesla-landing' ),
				'blurb'  => __( 'The terms page, off until you switch it on. There is no starter text for this one: what your terms are is a question for whoever writes them, not for a plugin.', 'vesla-landing' ),
				'fields' => array(
					'page_enabled' => array(
						'type'  => 'toggle',
						'label' => __( 'Publish the terms page', 'vesla-landing' ),
						'help'  => __( 'Publishes /terms/ the next time the site is republished.', 'vesla-landing' ),
					),
					'page_heading' => array(
						'type'  => 'text',
						'label' => __( 'Page heading', 'vesla-landing' ),
					),
					'page_intro' => array(
						'type'  => 'textarea',
						'label' => __( 'Opening paragraph', 'vesla-landing' ),
						'help'  => __( 'Optional. Leave it empty and the page starts with the terms themselves.', 'vesla-landing' ),
					),
					'body' => array(
						'type'  => 'rich',
						'label' => __( 'The terms', 'vesla-landing' ),
						'help'  => __( 'PLACEHOLDER — replace this before switching the page on. Nothing is written for you here, because a plugin inventing your terms of business would be worse than an empty page.', 'vesla-landing' ),
					),
				),
			),
			'analytics' => array(
				'title'  => __( 'Visitor statistics', 'vesla-landing' ),
				'blurb'  => __( 'Counts how many people visit and which pages they read. Off until you fill in an account, and nothing is loaded at all while it is off — no script, no cookie, no notice to write. Whichever you choose, the code goes on the published pages as well as this one.', 'vesla-landing' ),
				'fields' => array(
					'provider' => array(
						'type'    => 'select',
						'label'   => __( 'Which service', 'vesla-landing' ),
						'choices' => array(
							''          => __( 'None — count nothing', 'vesla-landing' ),
							'ga4'       => __( 'Google Analytics', 'vesla-landing' ),
							'plausible' => __( 'Plausible', 'vesla-landing' ),
						),
						'help'    => __( 'Google Analytics is free and the most widely known. Plausible is paid, sets no cookies and needs no cookie banner, which is why it is offered here as well.', 'vesla-landing' ),
					),
					'ga_id' => array(
						'type'  => 'text',
						'label' => __( 'Google Analytics measurement ID', 'vesla-landing' ),
						'help'  => __( 'Begins with G- and is found in Google Analytics under Admin, Data streams. Only used when Google Analytics is chosen above.', 'vesla-landing' ),
					),
					'plausible_domain' => array(
						'type'  => 'text',
						'label' => __( 'Plausible site domain', 'vesla-landing' ),
						'help'  => __( 'The domain exactly as it is registered in Plausible, e.g. veslamotors.com. Only used when Plausible is chosen above.', 'vesla-landing' ),
					),
					'plausible_host' => array(
						'type'  => 'url',
						'label' => __( 'Plausible address — only if self-hosted', 'vesla-landing' ),
						'help'  => __( 'Leave empty to use plausible.io. Fill this in only if you run Plausible on your own server.', 'vesla-landing' ),
					),
				),
			),
		);
	}

	/**
	 * Every field, flattened to `section.key => definition`, which is what the
	 * sanitiser and the defaults builder want. Repeater children are not
	 * flattened — a repeater is handled as a single unit.
	 */
	public static function flat() {
		$flat = array();
		foreach ( self::get() as $section_key => $section ) {
			foreach ( $section['fields'] as $key => $def ) {
				$flat[ $section_key . '.' . $key ] = $def;
			}
		}
		return $flat;
	}
}

/* ========================================================================== */
/*  3 · STORAGE, DEFAULTS AND SANITISING
 *
 *  One option row. Reads merge over defaults so upgrades never blank a new field.
 */
/* ========================================================================== */

/**
 * Where the content actually lives.
 *
 * It used to be one `vesla_landing` option: the whole site -- seventeen
 * sections and every car -- serialised into a single 21 KB blob, autoloaded on
 * every request including the ones that never look at it. Nothing could be
 * queried: asking "which cars are SUVs under 80,000" meant unserialising all of
 * it in PHP and looping. And two admins saving at once silently overwrote each
 * other, because the unit of writing was the entire site.
 *
 * Two tables now:
 *
 *   {prefix}vesla_cars     one row per car, real columns, real indexes. This is
 *                          the only list long enough, and queried hard enough,
 *                          to earn a table of its own.
 *
 *   {prefix}vesla_content  everything else, addressed by
 *                          section / field / row / sub-field. The other
 *                          repeaters hold three to six rows of pure copy;
 *                          giving each its own table would be twelve tables of
 *                          four rows -- more code, not better code.
 *
 * The rest of the plugin still sees the same nested array it always did, so the
 * schema remains the one source of truth for structure, sanitising and
 * defaults. This class changes only where the values are kept.
 */
class Vesla_Store {
	const CONTENT = 'vesla_content';
	const CARS    = 'vesla_cars';

	/** Marks a value that is not part of a repeater row. */
	const NO_ROW = -1;

	/**
	 * Marks a list that is deliberately empty.
	 *
	 * A repeater with no rows writes no rows, and a field with no rows in the
	 * table is indistinguishable from a field nobody has ever touched -- so
	 * Vesla_Settings::all() merged the seed's rows back underneath it and the
	 * list an administrator had just emptied reappeared on the next page load.
	 * One row carrying this marker is the difference between “nothing here”
	 * and “nothing said about this”.
	 */
	const EMPTY_LIST = -2;

	/* Held on the class, not as a function static, so that a write can drop it.
	   Without that, the editor saves and then redraws itself from the copy it
	   read before the save -- the admin presses Save and sees the old text. */
	private static $cache = null;

	public static function content_table() {
		global $wpdb;
		return $wpdb->prefix . self::CONTENT;
	}

	public static function cars_table() {
		global $wpdb;
		return $wpdb->prefix . self::CARS;
	}

	/* =======================================================================
	   THE TABLES
	   ======================================================================= */

	/**
	 * Create or update both tables. Safe on every activation: dbDelta compares
	 * what exists against what is asked for and alters only the difference.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$content = self::content_table();
		$cars    = self::cars_table();

		/* One row per value, and the unique key IS the address of that value,
		   so writing the same field twice updates rather than duplicates. */
		dbDelta(
			"CREATE TABLE $content (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				section VARCHAR(64) NOT NULL,
				field VARCHAR(64) NOT NULL,
				row_no SMALLINT NOT NULL DEFAULT -1,
				sub_field VARCHAR(64) NOT NULL DEFAULT '',
				value LONGTEXT NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY place (section,field,row_no,sub_field),
				KEY section (section)
			) $collate;"
		);

		/* Real columns, and the list of them comes from the schema rather than
		   being written out here. Adding a field to the car repeater therefore
		   adds a column, with no second place to remember. `position` keeps the
		   admin's ordering, which is content in its own right and cannot be
		   recovered from any other column. */
		$cols = '';
		foreach ( self::car_columns() as $name => $type ) {
			$cols .= "\t\t\t\t$name $type," . "\n";
		}

		dbDelta(
			"CREATE TABLE $cars (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				position SMALLINT NOT NULL DEFAULT 0,
$cols				PRIMARY KEY  (id),
				KEY position (position),
				KEY make (make),
				KEY body (body),
				KEY price (price)
			) $collate;"
		);

		/* Remember the shape just built, so a field added in a later version is
		   noticed without waiting for somebody to deactivate and reactivate the
		   plugin -- which is how a missing column becomes a failed save that
		   loses the admin's work. */
		update_option( 'vesla_cars_shape', self::shape_hash(), false );
	}

	/**
	 * The car table's columns, derived from the car fields in the schema.
	 *
	 * Types are chosen from what the field IS, not from what it is called: a
	 * number gets a numeric column so it can be compared and sorted, a rich or
	 * long field gets TEXT because 191 characters is not a paragraph.
	 */
	public static function car_columns() {
		$out = array();
		foreach ( self::car_fields() as $key => $def ) {
			$type = isset( $def['type'] ) ? $def['type'] : 'text';
			switch ( $type ) {
				case 'number':
					$out[ $key ] = 'BIGINT NOT NULL DEFAULT 0';
					break;
				case 'image':
					$out[ $key ] = 'BIGINT UNSIGNED NOT NULL DEFAULT 0';
					break;
				case 'gallery':
					$out[ $key ] = "VARCHAR(255) NOT NULL DEFAULT ''";
					break;
				case 'toggle':
					$out[ $key ] = 'TINYINT NOT NULL DEFAULT 0';
					break;
				case 'rich':
				case 'textarea':
				case 'checks':
					$out[ $key ] = 'TEXT NULL';
					break;
				default:
					$out[ $key ] = "VARCHAR(191) NOT NULL DEFAULT ''";
			}
		}

		/* Not a schema field. It names a photograph shipped inside the plugin
		   and is only ever set by the seeder, so it has no editor row -- but it
		   still has to survive a save. */
		$out['photo_file'] = "VARCHAR(255) NOT NULL DEFAULT ''";

		return $out;
	}

	/** The car fields as the schema declares them. */
	public static function car_fields() {
		$all = Vesla_Schema::get();
		return isset( $all['stock']['fields']['cars']['fields'] )
			? $all['stock']['fields']['cars']['fields']
			: array();
	}

	private static function shape_hash() {
		return md5( wp_json_encode( self::car_columns() ) );
	}

	/**
	 * Add columns for fields that appeared since the table was made.
	 *
	 * Cheap enough to run on every admin request: one autoloaded option read
	 * and a string compare, and dbDelta only runs when they differ.
	 */
	public static function maybe_upgrade() {
		if ( ! self::ready() ) {
			return;
		}
		if ( get_option( 'vesla_cars_shape' ) !== self::shape_hash() ) {
			self::install();
		}

		/* Once per site. The car page's wording used to live in Cars under
		   popup_* names, from when the car opened in a popup rather than on a
		   page of its own. Moving the names without moving the stored values
		   would leave every heading on every car page blank. */
		if ( ! get_option( 'vesla_vehicle_section' ) ) {
			self::move_vehicle_settings();
			update_option( 'vesla_vehicle_section', 1, false );
		}

		/* Once per site. The Spotlight's controls used to be five fields inside
		   Cars for sale, from when it was a "featured strip" bolted to the grid.
		   Moving the names without moving the stored values would empty the
		   heading and silently drop every car somebody had chosen. */
		if ( ! get_option( 'vesla_spotlight_section' ) ) {
			self::move_spotlight_settings();
			update_option( 'vesla_spotlight_section', 1, false );
		}

		/* Once per site. The feature list used to be repeater rows, one row
		   per feature; it is now a block of wording plus a tick against each
		   line that is offered. Without this the list reads as empty after
		   the update and every car silently loses its features. */
		if ( ! get_option( 'vesla_features_flattened' ) ) {
			self::flatten_features();
			update_option( 'vesla_features_flattened', 1, false );
		}

		/* Once per site. Photographs stored as URLs become attachments, so they
		   survive the move to another host. */
		if ( ! get_option( 'vesla_photos_upgraded' ) && is_admin() ) {
			$done = self::upgrade_photos();
			update_option( 'vesla_photos_upgraded', 1, false );
			if ( $done['matched'] || $done['sideloaded'] ) {
				update_option( 'vesla_photos_upgrade_report', $done, false );
			}
		}

		/* Once per site. The sample photographs that ship inside the plugin
		   have no attachment record, so WordPress never made sized copies of
		   them and they carry no srcset -- which meant a 300px card was
		   downloading a 514KB full-size JPEG, five times over, on a page that
		   also shows them again in the grid. Adopting them into the media
		   library is the fix at the source: WordPress makes the sized variants
		   and the browser picks a small one. */
		if ( ! get_option( 'vesla_bundled_photos_adopted' ) && is_admin() ) {
			$done = self::adopt_bundled_photos();
			update_option( 'vesla_bundled_photos_adopted', 1, false );
			if ( ! empty( $done['adopted'] ) ) {
				update_option( 'vesla_bundled_photos_report', $done, false );
			}
		}
	}

	/**
	 * Bring the plugin's own sample photographs into the media library.
	 *
	 * A file inside the plugin folder is not an attachment, so it has no sized
	 * variants and no srcset, and every card that shows it downloads the full
	 * thing however small the card is. Copying each one in once gives it both.
	 *
	 * The bundled file is left in photo_file rather than cleared, for the same
	 * reason upgrade_photos() leaves it: if the attachment is ever deleted from
	 * the library, the render path falls back to it and the car still has a
	 * picture instead of a letter.
	 *
	 * Files are shared between cars where they repeat, so the same photograph
	 * is never copied in twice.
	 */
	public static function adopt_bundled_photos() {
		global $wpdb;
		$report = array( 'checked' => 0, 'adopted' => 0, 'reused' => 0, 'failed' => 0 );

		if ( ! self::ready() ) {
			return $report;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		/* A car is a post with meta, not a row in the cars table -- the table
		   exists but the stock does not live in it. Reading the wrong one gave
		   a confident "checked 0" against four cars that plainly needed it,
		   which is the kind of clean pass that means nothing was looked at. */
		$ids = get_posts( array(
			'post_type'      => Vesla_Vehicle::TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts'    => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		if ( ! $ids ) {
			return $report;
		}

		$seen = array();   // bundled path => attachment id, so a shared photo is copied once

		foreach ( $ids as $post_id ) {
			$row = array(
				'id'         => $post_id,
				'photo'      => get_post_meta( $post_id, Vesla_Vehicle::META . 'photo', true ),
				'photo_file' => get_post_meta( $post_id, Vesla_Vehicle::META . 'photo_file', true ),
			);
			$file = trim( (string) $row['photo_file'] );
			if ( ! empty( $row['photo'] ) || '' === $file ) {
				continue;
			}
			/* Only the plugin's own files. Anything else is either already an
			   attachment or a URL, and upgrade_photos() owns those. */
			if ( 0 !== strpos( $file, 'assets/' ) ) {
				continue;
			}
			$report['checked']++;

			if ( isset( $seen[ $file ] ) ) {
				update_post_meta( $post_id, Vesla_Vehicle::META . "photo", $seen[ $file ] );
				$report['reused']++;
				continue;
			}

			$src = VESLA_DIR . $file;
			if ( ! file_exists( $src ) ) {
				$report['failed']++;
				continue;
			}

			/* Copied into the uploads folder rather than attached where it
			   lies: an attachment pointing inside a plugin folder is one
			   plugin update away from being a broken image. */
			$up = wp_upload_dir();
			if ( ! empty( $up['error'] ) ) {
				$report['failed']++;
				continue;
			}
			$name = wp_unique_filename( $up['path'], basename( $file ) );
			$dest = trailingslashit( $up['path'] ) . $name;
			if ( ! @copy( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$report['failed']++;
				continue;
			}

			$type = wp_check_filetype( $dest );
			$id   = wp_insert_attachment(
				array(
					'post_mime_type' => $type['type'] ? $type['type'] : 'image/jpeg',
					'post_title'     => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
					'post_status'    => 'inherit',
				),
				$dest
			);
			if ( is_wp_error( $id ) || ! $id ) {
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$report['failed']++;
				continue;
			}
			/* This is what makes the sized copies, and therefore the srcset. */
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );

			update_post_meta( $post_id, Vesla_Vehicle::META . "photo", $id );
			$seen[ $file ] = $id;
			$report['adopted']++;
		}

		if ( $report['adopted'] || $report['reused'] ) {
			self::forget_cars();
			do_action( 'vesla_content_saved' );
		}
		return $report;
	}

	/**
	 * Repeater rows -> a catalogue of wording, with everything ticked.
	 *
	 * Everything already in the list was on offer, so everything comes
	 * across ticked: an upgrade that quietly stopped offering half the
	 * equipment would be indistinguishable from a bug. Whoever wants a
	 * shorter list can untick lines afterwards, which is reversible; a
	 * migration that dropped them would not be.
	 */
	private static function flatten_features() {
		$all = self::read();
		if ( empty( $all['features'] ) || ! empty( $all['features']['catalogue'] ) ) {
			return;   // nothing stored, or already converted
		}

		$names = array();

		/* Rows, as the repeater wrote them. */

		if ( ! empty( $all['features']['items'] ) && is_array( $all['features']['items'] ) ) {
			foreach ( $all['features']['items'] as $row ) {
				$names[] = isset( $row['name'] ) ? (string) $row['name'] : '';
			}
		}

		/* And the textarea that came before the repeater, for a site that

		   skipped that version. */

		if ( ! $names && ! empty( $all['features']['list'] ) ) {
			$names = preg_split( '/\r\n|\r|\n/', (string) $all['features']['list'] );
		}

		$clean = array();
		foreach ( $names as $one ) {
			$one = trim( $one );
			if ( '' !== $one && ! in_array( $one, $clean, true ) ) {
				$clean[] = $one;
			}
		}
		if ( ! $clean ) {
			return;
		}

		$all['features'] = array(

			'catalogue' => implode( "\n", $clean ),

			'offered'   => implode( "\n", $clean ),
		);

		self::write( $all );
	}

	/**
	 * Cars.popup_* and friends -> their own Vehicle pages section.
	 *
	 * Anything already answered stays answered. A key the old section never
	 * held is left for the schema's own default to fill, rather than being
	 * written as an empty string -- an empty heading is not a heading.
	 */
	private static function move_spotlight_settings() {
		$all = self::read();
		if ( empty( $all['stock'] ) || ! empty( $all['spotlight'] ) ) {
			return;
		}

		$map = array(
			'featured_enabled'  => 'enabled',
			'spotlight_eyebrow' => 'eyebrow',
			'spotlight_heading' => 'heading',
			'spotlight_lead'    => 'lead',
			'featured'          => 'cars',
		);

		/* On by default, the way featured_enabled was: a site that never touched
		   the old toggle had the strip, and must not lose it here. */
		$moved = array( 'enabled' => 1 );
		foreach ( $map as $was => $now ) {
			if ( isset( $all['stock'][ $was ] ) && '' !== $all['stock'][ $was ] ) {
				$moved[ $now ] = $all['stock'][ $was ];
			}
			unset( $all['stock'][ $was ] );
		}

		$all['spotlight'] = $moved;
		self::write( $all );
	}

	private static function move_vehicle_settings() {
		$all = self::read();
		if ( empty( $all['stock'] ) || ! empty( $all['vehicle'] ) ) {
			return;
		}

		$map = array(
			'back_label'           => 'back_label',
			'seats_label'          => 'seats_label',
			'popup_overview_title' => 'overview_title',
			'popup_spec_title'     => 'spec_title',
			'popup_about_title'    => 'about_title',
			'popup_features_title' => 'features_title',
			'finance_enabled'      => 'finance_enabled',
			'finance_title'        => 'finance_title',
			'finance_down_pct'     => 'finance_down_pct',
			'finance_rate'         => 'finance_rate',
			'finance_years'        => 'finance_years',
			'finance_note'         => 'finance_note',
		);

		$moved = array( 'enabled' => 1 );
		foreach ( $map as $was => $now ) {
			if ( isset( $all['stock'][ $was ] ) && '' !== $all['stock'][ $was ] ) {
				$moved[ $now ] = $all['stock'][ $was ];
			}
			unset( $all['stock'][ $was ] );
		}
		unset( $all['stock']['popup_close_label'], $all['stock']['details_label'] );

		$all['vehicle'] = $moved;
		self::write( $all );
	}

	/* ── out of the table, into the list ────────────────────────────── */

	/**
	 * Copy every car out of the old table into a post of its own. Once.
	 *
	 * The old table is not touched. It stays exactly as it is until somebody
	 * is satisfied the list is right — a migration that deletes its own source
	 * on the way past leaves nothing to compare against and nothing to go back
	 * to. Vesla_Store::drop_legacy() removes it later, deliberately.
	 *
	 * THE ONE THING THAT MATTERS MOST
	 * A car's web address is built from its number. Those addresses are in
	 * search results and in messages people were sent. So the number comes
	 * across with the car and becomes the post's _vesla_car_id — it is NOT
	 * re-derived from the new post ID, which would silently repoint every
	 * link the showroom has ever shared.
	 *
	 * @return array{made:int,skipped:int,rows:int}
	 */
	public static function migrate_to_posts() {
		global $wpdb;
		$report = array( 'made' => 0, 'skipped' => 0, 'rows' => 0 );
		if ( ! self::ready() ) {
			return $report;
		}

		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::cars_table() . ' ORDER BY position ASC, id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB -- table name from $wpdb->prefix.
		$report['rows'] = count( (array) $rows );
		$fields = self::car_fields();

		foreach ( (array) $rows as $i => $r ) {
			$car_id = (int) $r['id'];

			/* Already brought across. Checked every time, so running this twice
				   cannot make twenty-four cars into forty-eight. */
			$found = get_posts(
				array(
					'post_type'   => Vesla_Vehicle::TYPE,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => Vesla_Vehicle::ID_META,   // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => $car_id,                   // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $found ) {
				$report['skipped']++;
				continue;
			}

			$title = trim(
				( isset( $r['year'] ) && $r['year'] ? $r['year'] . ' ' : '' )
				. ( isset( $r['make'] ) ? $r['make'] : '' ) . ' '
				. ( isset( $r['model'] ) ? $r['model'] : '' )
				. ( ! empty( $r['trim'] ) ? ' ' . $r['trim'] : '' )
			);

			$post_id = wp_insert_post(
				array(
					'post_type'   => Vesla_Vehicle::TYPE,
					'post_status' => 'publish',
					'post_title'  => '' !== $title ? $title : sprintf( __( 'Car %d', 'vesla-landing' ), $car_id ),
					/* The order they were in stays the order they are in. */
					'menu_order'  => isset( $r['position'] ) ? (int) $r['position'] : $i,
				),
				true
			);
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			$clean = array();
			foreach ( $fields as $key => $def ) {
				$value = array_key_exists( $key, $r ) ? $r[ $key ] : '';
				$type  = isset( $def['type'] ) ? $def['type'] : 'text';
				/* Zero in a numeric column means “not set”, and the page wants an
					   empty string there: a price of 0 prints as “AED 0”. */
				if ( 'number' === $type || 'image' === $type ) {
					$value = (int) $value ? (int) $value : '';
				}
				$clean[ $key ] = $value;
				update_post_meta( $post_id, Vesla_Vehicle::META . $key, $value );
			}

			/* Carried explicitly, because it is a column rather than a schema
			   field and the loop above only walks the schema. */
			if ( isset( $r['photo_file'] ) ) {
				update_post_meta( $post_id, Vesla_Vehicle::META . 'photo_file', (string) $r['photo_file'] );
			}

			update_post_meta( $post_id, Vesla_Vehicle::ID_META, $car_id );
			update_post_meta( $post_id, '_vesla_auto_title', $title );
			Vesla_Vehicle::sync_terms_public( $post_id, $clean );
			$report['made']++;
		}

		return $report;
	}

	/**
	 * Drop the old table, once the list has been checked.
	 *
	 * Separate from the migration on purpose, and never automatic: this is the
	 * only step that cannot be undone.
	 */
	public static function drop_legacy() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::cars_table() ); // phpcs:ignore WordPress.DB -- table name from $wpdb->prefix.
		delete_option( 'vesla_cars_shape' );
	}
	/** Have the tables been created yet? */
	public static function ready() {
		global $wpdb;
		static $ok = null;
		if ( null !== $ok ) {
			return $ok;
		}
		$table = self::content_table();
		$ok    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		return $ok;
	}

	/* =======================================================================
	   READING
	   ======================================================================= */

	/**
	 * Rebuild the nested array the rest of the plugin expects.
	 *
	 * Two queries, not one per field, and cached for the request -- a single
	 * page render asks for values dozens of times.
	 */
	public static function read() {
		global $wpdb;
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		if ( ! self::ready() ) {
			return array();
		}

		$out  = array();
		$rows = $wpdb->get_results( 'SELECT section,field,row_no,sub_field,value FROM ' . self::content_table(), ARRAY_A ); // phpcs:ignore WordPress.DB -- table name comes from $wpdb->prefix.

		foreach ( (array) $rows as $r ) {
			$row_no = (int) $r['row_no'];
			if ( self::EMPTY_LIST === $row_no ) {
				$out[ $r['section'] ][ $r['field'] ] = array();
			} elseif ( self::NO_ROW === $row_no ) {
				$out[ $r['section'] ][ $r['field'] ] = $r['value'];
			} else {
				$out[ $r['section'] ][ $r['field'] ][ $row_no ][ $r['sub_field'] ] = $r['value'];
			}
		}

		/* Rows carry their index, but a list with a gap is no longer a list by
		   the time it reaches JSON -- it encodes as an object, and a front end
		   looping over it gets nothing. */
		foreach ( $out as $section => $fields ) {
			foreach ( $fields as $key => $value ) {
				if ( is_array( $value ) ) {
					ksort( $value );
					$out[ $section ][ $key ] = array_values( $value );
				}
			}
		}

		$out['stock']['cars'] = self::cars();

		self::$cache = $out;
		return $out;
	}

	/** Every car, in the order the admin arranged them. */
	/**
	 * Every car on the floor, in order.
	 *
	 * THE SEAM. Everything downstream — the grid, the filters, the API, a
	 * car's own page, the structured data, the sitemap and the published
	 * files — asks this one method and nothing else. That is what made it
	 * possible to move the cars out of a private table and into the Vehicles
	 * list by rewriting a single function.
	 *
	 * Published only. A car saved as a draft is one being written up, and it
	 * has no business on the website until somebody presses Publish.
	 *
	 * The `id` is the car's own number, kept from before the move, because a
	 * car's web address is built from it and those addresses are already out
	 * in the world.
	 */
	/** Held for the request, and dropped whenever a car changes. */
	private static $cars = null;

	public static function forget_cars() {
		self::$cars = null;
	}

	public static function cars() {
		if ( null !== self::$cars ) {
			return self::$cars;
		}

		$posts = get_posts(
			array(
				'post_type'        => Vesla_Vehicle::TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
				'suppress_filters' => false,
			)
		);

		$fields = self::car_fields();
		$out    = array();

		foreach ( $posts as $post ) {
			$id  = (int) get_post_meta( $post->ID, Vesla_Vehicle::ID_META, true );
			$car = array( 'id' => $id ? $id : (int) $post->ID );

			foreach ( $fields as $key => $def ) {
				$value = get_post_meta( $post->ID, Vesla_Vehicle::META . $key, true );
				$type  = isset( $def['type'] ) ? $def['type'] : 'text';

				if ( 'number' === $type || 'image' === $type ) {
					/* Zero is what “not set” looks like in a number field, and both
					   the editor and the page want an empty string there: a price of
					   0 would print as “AED 0”, and “0 hp” is worse than saying
					   nothing about power at all. */
					$car[ $key ] = (int) $value ? (int) $value : '';
				} else {
					$car[ $key ] = (string) $value;
				}
			}

			/* Not a schema field, and it still has to travel. It is the path of a
			   photograph that ships inside the plugin, written by the seeder for
			   the sample cars, and the renderer falls back to it whenever a car has
			   no uploaded picture. Leaving it out of this loop is what silently
			   took the photograph off every seeded car. */
			$car['photo_file'] = (string) get_post_meta( $post->ID, Vesla_Vehicle::META . 'photo_file', true );

			$out[] = $car;
		}

		self::$cars = $out;
		return self::$cars;
	}

	/* =======================================================================
	   WRITING
	   ======================================================================= */

	/**
	 * Store an already-sanitised settings array.
	 *
	 * What arrives here has been through the schema sanitiser, which remains
	 * the only thing that decides what is allowed. This writes what it is given
	 * and validates nothing: one gatekeeper, not two that can drift apart.
	 */
	/**
	 * Replace the stored content with $data.
	 *
	 * This function DELETEs the table before it writes, which is the right
	 * shape for the job -- see the note further down -- and also the most
	 * dangerous function in the plugin. It has exactly two legitimate
	 * callers: a sanitised settings save, and first-time seeding. Both hand
	 * it a complete picture of every section.
	 *
	 * It has been handed a partial one, from a script that called
	 * update_option() with a single section in it. The delete ran, three rows
	 * went back, and everything an administrator had ever typed was gone --
	 * silently, because the seed defaults underneath make the site look
	 * unchanged. Hence the two guards below: refuse a payload that does not
	 * cover the schema, and keep a copy of what is about to be deleted.
	 *
	 * @param array $data  Every section, as the sanitiser produces it.
	 * @return true|WP_Error
	 */
	public static function write( $data ) {
		global $wpdb;
		if ( ! is_array( $data ) || ! self::ready() ) {
			return new WP_Error( 'vesla_store_unready', __( 'The content tables are not ready to be written to.', 'vesla-landing' ) );
		}

		/* ── guard one: is this the whole picture? ──

		   Refused outright rather than merged into what is already stored.
		   Merging would make the write succeed and hide the fact that the
		   caller sent the wrong thing, which is how a bug like this survives
		   long enough to lose somebody's afternoon. */
		$want = array_keys( Vesla_Schema::get() );
		$have = array_keys( array_filter( $data, 'is_array' ) );
		$missing = array_diff( $want, $have );
		if ( $missing && self::read() ) {
			return new WP_Error(
				'vesla_store_partial',
				sprintf(
					/* translators: %s: comma-separated list of setting sections. */
					__( 'Refusing to save: this would have replaced every setting with a copy that is missing %s. Nothing has been changed.', 'vesla-landing' ),
					implode( ', ', $missing )
				),
				array( 'missing' => array_values( $missing ) )
			);
		}

		$cars = isset( $data['stock']['cars'] ) ? $data['stock']['cars'] : null;
		unset( $data['stock']['cars'] );

		/* ── guard two: keep what is about to be destroyed ── */
		self::snapshot();

		/* ── guard three: say who did this ──

		   A value in this table changed once with no save anyone could account
		   for, and working backwards from the data took hours and did not
		   settle it. One line naming the caller would have answered it in
		   seconds. Written only when something actually differs, so an ordinary
		   save of an unchanged form stays silent. */
		$before = array();
		foreach ( self::rows() as $r ) {
			$before[ $r['section'] . '.' . $r['field'] . '.' . $r['row_no'] . '.' . $r['sub_field'] ] = $r['value'];
		}

		$wpdb->query( 'START TRANSACTION' );

		/* Rewritten wholesale rather than diffed. A repeater that lost a row
		   must lose it here too, and working out which rows went is more code
		   and more ways to be wrong than writing the list again. */
		$wpdb->query( 'DELETE FROM ' . self::content_table() ); // phpcs:ignore WordPress.DB

		foreach ( $data as $section => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field => $value ) {
				if ( is_array( $value ) ) {
					if ( ! $value ) {
						self::put( $section, $field, self::EMPTY_LIST, '', '' );
						continue;
					}
					foreach ( $value as $i => $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						foreach ( $row as $sub => $sub_value ) {
							self::put( $section, $field, (int) $i, (string) $sub, $sub_value );
						}
					}
				} else {
					self::put( $section, $field, self::NO_ROW, '', $value );
				}
			}
		}

		if ( is_array( $cars ) ) {
			self::write_cars( $cars );
		}

		$wpdb->query( 'COMMIT' );

		/* Dropped before the log reads back, so log_changes() sees the table
		   rather than the copy this write has just made stale. */
		self::$cache = null;
		self::log_changes( $before );

		return true;
	}

	/**
	 * Values that never leave this database, whatever asks for them.
	 *
	 * A content export is meant to be committed to a repository, which is a
	 * different audience from a backup sitting in uploads. The address
	 * enquiries are delivered to is the showroom's internal routing, not
	 * page copy, and it has no business in a file that gets pushed. Listed
	 * as section.field so the rule is readable rather than inferred.
	 *
	 * The enquiries themselves are not here because they are not settings:
	 * they are a private post type this export never looks at.
	 */
	const NEVER_EXPORT = array( 'contact.form_to' );

	/**
	 * Everything an administrator has put into this site, in one file.
	 *
	 * The settings table and the vehicles together — the repository holds the
	 * code and the published pages, and without this it holds none of the
	 * content that produced them. Rebuilt from here, a fresh install comes
	 * back with the same words and the same cars.
	 *
	 * Everything is sorted on the way out. The rows arrive from MySQL in no
	 * particular order and post meta in whatever order it was written, so an
	 * unsorted export reshuffles itself on every run and a commit shows a
	 * few hundred moved lines instead of the one value that changed.
	 *
	 * @return array
	 */
	public static function export_content() {
		$rows = array();
		foreach ( self::rows() as $r ) {
			if ( in_array( $r['section'] . '.' . $r['field'], self::NEVER_EXPORT, true ) ) {
				continue;
			}
			$rows[] = array(
				'section'   => (string) $r['section'],
				'field'     => (string) $r['field'],
				'row_no'    => (int) $r['row_no'],
				'sub_field' => (string) $r['sub_field'],
				'value'     => (string) $r['value'],
			);
		}
		usort( $rows, static function ( $a, $b ) {
			return array( $a['section'], $a['field'], $a['row_no'], $a['sub_field'] )
				<=> array( $b['section'], $b['field'], $b['row_no'], $b['sub_field'] );
		} );

		$cars = array();
		foreach ( get_posts( array(
			'post_type'   => Vesla_Vehicle::TYPE,
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) ) as $post ) {
			$meta = array();
			foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
				/* Only this plugin's own fields. WordPress and any other plugin
				   keep their bookkeeping alongside, and none of it is content. */
				if ( 0 !== strpos( $key, Vesla_Vehicle::META ) ) {
					continue;
				}
				$meta[ $key ] = isset( $values[0] ) ? (string) $values[0] : '';
			}
			ksort( $meta );

			$cars[] = array(
				/* The car's own number, not the post id. A restore into a fresh
				   install gets new post ids, and every published address is built
				   from this number — so this is what has to survive. */
				'car_id'     => (int) get_post_meta( $post->ID, Vesla_Vehicle::ID_META, true ),
				'slug'       => (string) $post->post_name,
				'title'      => (string) $post->post_title,
				'status'     => (string) $post->post_status,
				'menu_order' => (int) $post->menu_order,
				'meta'       => $meta,
			);
		}
		usort( $cars, static function ( $a, $b ) {
			return array( $a['car_id'], $a['slug'] ) <=> array( $b['car_id'], $b['slug'] );
		} );

		/* ── the pictures, by name as well as by number ──

		   Every picture on this site is stored as a media library id, and an id
		   means nothing anywhere else: restore into a fresh install and id 88
		   is either a different picture or no picture. Recording the file each
		   id points at gives a restore something to match on, so an install
		   whose uploads folder has been carried across can find the same
		   photographs again even though WordPress has renumbered them.

		   It is a re-match, not a backup. If the file is not in the media
		   library of the install being restored into, nothing here can conjure
		   it -- see the readme. */
		$media = array();
		foreach ( self::media_ids( $rows, $cars ) as $id ) {
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( '' !== $file ) {
				$media[ (string) $id ] = $file;
			}
		}
		ksort( $media );

		/* No timestamp, deliberately.

		   This file is committed, and the repository already records when it
		   was written and by whom. A generated timestamp inside it only means
		   the file reports itself as changed every time it is written, so an
		   export taken to check nothing has changed shows up as a change. */
		return array(
			'plugin'   => 'vesla-landing',
			'version'  => VESLA_VERSION,
			'site'     => home_url(),
			'media'    => $media,
			'settings' => $rows,
			'vehicles' => $cars,
		);
	}

	/** Settings paths, as section.field, whose value is one attachment id. */
	private static function image_setting_paths() {
		return Vesla_Schema::image_paths();
	}

	/** Car meta keys holding attachment ids => whether the key holds a list. */
	private static function car_media_keys() {
		$out = array();
		foreach ( self::car_fields() as $key => $def ) {
			$type = isset( $def['type'] ) ? $def['type'] : '';
			if ( 'image' === $type ) {
				$out[ Vesla_Vehicle::META . $key ] = false;
			} elseif ( 'gallery' === $type ) {
				$out[ Vesla_Vehicle::META . $key ] = true;
			}
		}
		return $out;
	}

	/** Every attachment id referenced by these settings and these cars. */
	private static function media_ids( $rows, $cars ) {
		$paths = self::image_setting_paths();
		$keys  = self::car_media_keys();
		$ids   = array();

		foreach ( $rows as $r ) {
			if ( in_array( $r['section'] . '.' . $r['field'], $paths, true ) ) {
				$ids[] = (int) $r['value'];
			}
		}
		foreach ( $cars as $car ) {
			foreach ( $keys as $key => $is_list ) {
				if ( ! isset( $car['meta'][ $key ] ) ) {
					continue;
				}
				foreach ( self::split_ids( $car['meta'][ $key ], $is_list ) as $one ) {
					$ids[] = $one;
				}
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/** A stored value as a list of ids. A gallery is comma separated. */
	private static function split_ids( $value, $is_list ) {
		if ( ! $is_list ) {
			return array_filter( array( (int) $value ) );
		}
		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $value ) ) ) );
	}

	/**
	 * Put a content export back, settings and vehicles both.
	 *
	 * A car is matched on its own number rather than its post id, because a
	 * fresh install hands out different post ids and every published address
	 * is built from the car number. Match found, the car is updated in place
	 * and keeps its id; no match, a new one is created carrying the number
	 * from the file. Either way the URLs come out the same, which is the
	 * whole point of restoring rather than retyping.
	 *
	 * @param array $data Decoded export.
	 * @return array|WP_Error Counts, or why not.
	 */
	public static function import_content( $data ) {
		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new WP_Error( 'vesla_export_unreadable', __( 'That file is not a content export this plugin can read.', 'vesla-landing' ) );
		}

		/* ── find the pictures again ──

		   The ids in the file belong to the install it came from. Each one was
		   recorded with the file it points at, so the same photograph can be
		   found here under whatever number WordPress has given it. Anything
		   not found keeps its old id and simply does not resolve, which is the
		   same state as a car nobody has photographed yet -- every part of
		   this site already degrades cleanly when a picture is missing. */
		$remap  = array();
		$missing = 0;
		foreach ( (array) ( isset( $data['media'] ) ? $data['media'] : array() ) as $was => $file ) {
			$now = self::attachment_by_file( (string) $file );
			if ( ! $now ) {
				$missing++;
				continue;
			}
			if ( (int) $was !== $now ) {
				$remap[ (int) $was ] = $now;
			}
		}

		$settings = $data['settings'];
		if ( $remap ) {
			$paths = self::image_setting_paths();
			foreach ( $settings as $i => $r ) {
				if ( ! isset( $r['section'], $r['field'] ) ) {
					continue;
				}
				if ( in_array( $r['section'] . '.' . $r['field'], $paths, true ) ) {
					$id = (int) ( isset( $r['value'] ) ? $r['value'] : 0 );
					if ( $id && isset( $remap[ $id ] ) ) {
						$settings[ $i ]['value'] = (string) $remap[ $id ];
					}
				}
			}
		}

		$put = self::put_rows( $settings );
		if ( is_wp_error( $put ) ) {
			return $put;
		}

		$made = 0;
		$kept = 0;
		foreach ( (array) ( isset( $data['vehicles'] ) ? $data['vehicles'] : array() ) as $car ) {
			if ( ! is_array( $car ) || empty( $car['car_id'] ) ) {
				continue;
			}
			$id = self::car_post_id( (int) $car['car_id'] );
			$fields = array(
				'post_type'   => Vesla_Vehicle::TYPE,
				'post_title'  => isset( $car['title'] ) ? (string) $car['title'] : '',
				'post_name'   => isset( $car['slug'] ) ? (string) $car['slug'] : '',
				'post_status' => isset( $car['status'] ) ? (string) $car['status'] : 'publish',
				'menu_order'  => isset( $car['menu_order'] ) ? (int) $car['menu_order'] : 0,
			);
			if ( $id ) {
				$fields['ID'] = $id;
				wp_update_post( $fields );
				$kept++;
			} else {
				$id = wp_insert_post( $fields );
				if ( ! $id || is_wp_error( $id ) ) {
					continue;
				}
				$made++;
			}

			$media_keys = self::car_media_keys();
			foreach ( (array) ( isset( $car['meta'] ) ? $car['meta'] : array() ) as $key => $value ) {
				/* The prefix is checked again on the way in. A file is a file, and
				   this one decides what goes into post meta. */
				if ( 0 !== strpos( (string) $key, Vesla_Vehicle::META ) ) {
					continue;
				}
				$value = (string) $value;
				if ( $remap && isset( $media_keys[ $key ] ) ) {
					$moved = array();
					foreach ( self::split_ids( $value, $media_keys[ $key ] ) as $one ) {
						$moved[] = isset( $remap[ $one ] ) ? $remap[ $one ] : $one;
					}
					$value = implode( ',', $moved );
				}
				update_post_meta( $id, (string) $key, $value );
			}
			update_post_meta( $id, Vesla_Vehicle::ID_META, (int) $car['car_id'] );
		}

		self::$cache = null;
		do_action( 'vesla_content_saved' );

		return array(
			'settings'       => count( $settings ),
			'updated'        => $kept,
			'created'        => $made,
			'pictures_found' => count( $data['media'] ?? array() ) - $missing,
			'pictures_lost'  => $missing,
		);
	}

	/**
	 * The attachment whose file is this, or 0.
	 *
	 * Matched on _wp_attached_file, which is the path inside uploads and the
	 * one thing about a picture that survives being moved between installs.
	 */
	private static function attachment_by_file( $file ) {
		global $wpdb;
		if ( '' === $file ) {
			return 0;
		}
		$id = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no core API matches an attachment by its file.
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
			$file
		) );
		return $id ? (int) $id : 0;
	}

	/** The post holding a given car number, or 0. */
	private static function car_post_id( $car_id ) {
		$found = get_posts( array(
			'post_type'   => Vesla_Vehicle::TYPE,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => Vesla_Vehicle::ID_META,
			'meta_value'  => (int) $car_id,
		) );
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * The stored rows exactly as they are, for export.
	 *
	 * Not Vesla_Settings::all(), which merges the seed defaults in. What is
	 * worth backing up is what somebody typed; the defaults arrive on their
	 * own wherever this file is loaded again.
	 */
	public static function rows() {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT section,field,row_no,sub_field,value FROM ' . self::content_table(), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Write a set of exported rows back, replacing what is stored.
	 *
	 * Goes through the same delete-and-write shape as write(), and takes the
	 * same snapshot first, but skips the schema-completeness guard: an export
	 * is by definition only the rows that differed from the defaults, so it is
	 * legitimately partial in a way a settings save never is.
	 *
	 * @param array $rows Rows as produced by self::rows().
	 * @return true|WP_Error
	 */
	public static function put_rows( $rows ) {
		global $wpdb;
		if ( ! self::ready() || ! is_array( $rows ) ) {
			return new WP_Error( 'vesla_store_unready', __( 'The content tables are not ready to be written to.', 'vesla-landing' ) );
		}

		/* Everything worth writing is worked out BEFORE anything is deleted.

		   A section this build has never heard of is dropped rather than
		   stored: the schema decides what may exist -- the same rule the
		   sanitiser follows -- so a file from a newer version cannot smuggle
		   fields into this one. And if that leaves nothing at all, the table is
		   never touched. Deleting first and discovering afterwards that the
		   file was useless is the exact shape of the accident this whole
		   section of the plugin exists to prevent. */
		$known = array_keys( Vesla_Schema::get() );
		$keep  = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['section'], $r['field'] ) ) {
				continue;
			}
			if ( ! in_array( (string) $r['section'], $known, true ) ) {
				continue;
			}
			$keep[] = $r;
		}
		if ( ! $keep ) {
			return new WP_Error( 'vesla_import_empty', __( 'That file held no settings this version recognises. Nothing was changed.', 'vesla-landing' ) );
		}

		self::snapshot();

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( 'DELETE FROM ' . self::content_table() ); // phpcs:ignore WordPress.DB
		foreach ( $keep as $r ) {
			self::put(
				(string) $r['section'],
				(string) $r['field'],
				isset( $r['row_no'] ) ? (int) $r['row_no'] : self::NO_ROW,
				isset( $r['sub_field'] ) ? (string) $r['sub_field'] : '',
				isset( $r['value'] ) ? (string) $r['value'] : ''
			);
		}
		$wpdb->query( 'COMMIT' );
		self::$cache = null;

		do_action( 'vesla_content_saved' );
		return true;
	}

	/** How many snapshots are kept before the oldest is dropped. */
	const KEEP_SNAPSHOTS = 10;

	/**
	 * Copy the whole content table into a file before it is deleted.
	 *
	 * Written to uploads rather than a database row on purpose: the failure
	 * this exists for is the table being emptied, and a backup that lives in
	 * the same table is no backup at all. A file also survives the plugin
	 * being deactivated and can be read by a person with FTP and no MySQL.
	 *
	 * Nothing here may stop a save. A backup that refuses the write it was
	 * protecting has made things worse, so every failure is swallowed and
	 * noted rather than raised.
	 *
	 * @return string|false Path written, or false.
	 */
	public static function snapshot() {
		global $wpdb;
		if ( ! self::ready() ) {
			return false;
		}
		$rows = $wpdb->get_results( 'SELECT section,field,row_no,sub_field,value FROM ' . self::content_table(), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return false; // nothing to lose
		}

		$dir = self::snapshot_dir();
		if ( ! $dir ) {
			return false;
		}

		$file = $dir . '/content-' . gmdate( 'Ymd-His' ) . '.json';
		$body = wp_json_encode(
			array(
				'saved_at' => gmdate( 'c' ),
				'site'     => home_url(),
				'rows'     => $rows,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! $body || false === @file_put_contents( $file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- a backup must never be the reason a save fails.
			return false;
		}

		self::prune_snapshots();
		return $file;
	}

	/**
	 * Where snapshots live, created on demand and closed to the web.
	 */
	public static function snapshot_dir() {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( $up['basedir'] ) . 'vesla-backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		/* The backups hold every word on the site and, in the contact section,
		   the address enquiries are sent to. Uploads is world-readable, so the
		   directory is shut on both kinds of server rather than trusting that
		   nobody will guess a filename. */
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied
<IfModule !mod_authz_core.c>
Deny from all
</IfModule>
" ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
		return $dir;
	}

	/** Every snapshot on disk, newest first. */
	public static function snapshots() {
		$dir = self::snapshot_dir();
		if ( ! $dir ) {
			return array();
		}
		$files = glob( $dir . '/content-*.json' );
		$files = is_array( $files ) ? $files : array();
		rsort( $files );
		return $files;
	}

	private static function prune_snapshots() {
		$files = self::snapshots();
		foreach ( array_slice( $files, self::KEEP_SNAPSHOTS ) as $old ) {
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Put a snapshot file back, row for row.
	 *
	 * @param string $file Full path to one of self::snapshots().
	 * @return true|WP_Error
	 */
	public static function restore_snapshot( $file ) {
		global $wpdb;
		$dir = self::snapshot_dir();
		/* Only a file this plugin wrote, in the folder it wrote it to. The path
		   arrives from a form, and a restore that will read any path on the
		   server is a way to load arbitrary JSON into the site's content. */
		if ( ! $dir || dirname( (string) $file ) !== $dir || ! preg_match( '/^content-\d{8}-\d{6}\.json$/', basename( (string) $file ) ) || ! is_readable( $file ) ) {
			return new WP_Error( 'vesla_snapshot_unknown', __( 'That backup could not be found.', 'vesla-landing' ) );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || empty( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			return new WP_Error( 'vesla_snapshot_unreadable', __( 'That backup file could not be read.', 'vesla-landing' ) );
		}

		self::snapshot(); // what is here now, before it goes

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( 'DELETE FROM ' . self::content_table() ); // phpcs:ignore WordPress.DB
		foreach ( $data['rows'] as $r ) {
			if ( ! isset( $r['section'], $r['field'] ) ) {
				continue;
			}
			self::put(
				$r['section'],
				$r['field'],
				isset( $r['row_no'] ) ? (int) $r['row_no'] : self::NO_ROW,
				isset( $r['sub_field'] ) ? (string) $r['sub_field'] : '',
				isset( $r['value'] ) ? $r['value'] : ''
			);
		}
		$wpdb->query( 'COMMIT' );
		self::$cache = null;
		return true;
	}

	/**
	 * Note in the log which stored values a write actually changed.
	 *
	 * @param array<string,string> $before Flattened rows from before the write.
	 */
	private static function log_changes( $before ) {
		$after = array();
		foreach ( self::rows() as $r ) {
			$after[ $r['section'] . '.' . $r['field'] . '.' . $r['row_no'] . '.' . $r['sub_field'] ] = $r['value'];
		}

		$moved = array();
		foreach ( array_keys( $before + $after ) as $k ) {
			$was = isset( $before[ $k ] ) ? $before[ $k ] : null;
			$now = isset( $after[ $k ] ) ? $after[ $k ] : null;
			if ( $was !== $now ) {
				$moved[] = $k;
			}
		}
		if ( ! $moved ) {
			return;
		}

		$who = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- deliberate: this is the audit line.
			'[vesla] content written by %s: %d value%s changed (%s)%s',
			$who && $who->exists() ? $who->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : 'nobody signed in' ),
			count( $moved ),
			1 === count( $moved ) ? '' : 's',
			implode( ', ', array_slice( $moved, 0, 12 ) ),
			count( $moved ) > 12 ? ', …' : ''
		) );
	}

	private static function put( $section, $field, $row_no, $sub, $value ) {
		global $wpdb;
		$wpdb->replace(
			self::content_table(),
			array(
				'section'   => (string) $section,
				'field'     => (string) $field,
				'row_no'    => (int) $row_no,
				'sub_field' => (string) $sub,
				'value'     => (string) $value,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Write the car list, keeping the rows that are already there.
	 *
	 * It used to DELETE every row and insert the list again, which was simple
	 * and wrong: every car took a new id on every save. A car's page asks for one
	 * car by id, so a page somebody already had open stopped being able to load
	 * any of them the moment an admin pressed Save -- and a cached or published
	 * page pointed at ids that no longer existed.
	 *
	 * Rows are matched by position, which is the only handle the editor gives:
	 * it posts a list, not a set of records. Reordering therefore still moves
	 * ids about, but the ordinary case -- editing a price, adding a photograph,
	 * saving the screen untouched -- leaves every id where it was.
	 */
	/**
	 * Write a set of cars back, by number.
	 *
	 * Almost nothing calls this now — a car is edited on its own screen, and
	 * that screen writes its own meta. What is left is the one-time
	 * photograph upgrade, which reads every car, changes one field on some of
	 * them and hands the lot back.
	 *
	 * So it matches on the car's number and updates; it does not create, and
	 * it does not delete. A caller handing back a list with a car missing
	 * from it means to change the ones it has, never to remove the rest —
	 * deleting a car is something somebody does on purpose, in the list.
	 */
	private static function write_cars( $cars ) {
		if ( ! is_array( $cars ) ) {
			return;
		}
		$fields = self::car_fields();

		foreach ( $cars as $car ) {
			$id = isset( $car['id'] ) ? (int) $car['id'] : 0;
			if ( ! $id ) {
				continue;
			}
			$found = get_posts(
				array(
					'post_type'   => Vesla_Vehicle::TYPE,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => Vesla_Vehicle::ID_META,  // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => $id,                      // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( ! $found ) {
				continue;
			}
			foreach ( $fields as $key => $def ) {
				if ( array_key_exists( $key, $car ) ) {
					update_post_meta( $found[0], Vesla_Vehicle::META . $key, $car[ $key ] );
				}
			}
			if ( array_key_exists( 'photo_file', $car ) ) {
				update_post_meta( $found[0], Vesla_Vehicle::META . 'photo_file', (string) $car['photo_file'] );
			}
		}
	}

	/* =======================================================================
	   MOVING AN EXISTING SITE ACROSS
	   ======================================================================= */

	/**
	 * Copy the old option into the tables, once.
	 *
	 * The option is left in place rather than deleted. If this ever goes wrong
	 * on somebody's site, the only copy of their content should not already be
	 * gone; uninstall.php removes it properly when they mean it.
	 */

	/**
	 * Turn any stored photograph URL into a media library attachment, once.
	 *
	 * WHY THIS EXISTS
	 * A photograph kept as a URL is a photograph tied to the hostname it was
	 * uploaded under. Move the site and every one of them points at a machine
	 * that is no longer serving it, with nothing on screen to say so. An
	 * attachment ID is resolved at render time and follows the site.
	 *
	 * WHAT IT DELIBERATELY SKIPS
	 * The bundled samples at assets/cars/. Those ship inside the plugin, their
	 * address is built from VESLA_URL, and they already follow the site on
	 * their own. Sideloading them would copy the plugin's own files into the
	 * media library and leave the admin with a library full of stock pictures
	 * of cars they do not own.
	 *
	 * WHAT IT DOES NOT DO
	 * Throw anything away. Where no attachment can be found or fetched, the URL
	 * is left exactly where it was: a photograph that still displays is worth
	 * more than a tidy data model, and the render path reads the URL as a
	 * fallback anyway.
	 *
	 * @return array{checked:int,matched:int,sideloaded:int,kept:int}
	 */
	public static function upgrade_photos() {
		$report = array( 'checked' => 0, 'matched' => 0, 'sideloaded' => 0, 'kept' => 0 );

		$all = self::read();
		if ( empty( $all['stock']['cars'] ) ) {
			return $report;
		}

		$changed = false;

		foreach ( $all['stock']['cars'] as $i => $car ) {
			$file = isset( $car['photo_file'] ) ? trim( (string) $car['photo_file'] ) : '';

			/* Already an attachment, or nothing stored: leave it alone. */
			if ( ! empty( $car['photo'] ) || '' === $file ) {
				continue;
			}

			/* A bundled sample. Not an upload, nothing to migrate. */
			if ( 0 === strpos( $file, 'assets/' ) || false === strpos( $file, '://' ) ) {
				continue;
			}

			$report['checked']++;

			/* Already in the library under that URL. Much the commoner case:
			   the admin uploaded it through WordPress and something stored the
			   URL rather than the id. */
			$id = attachment_url_to_postid( $file );

			if ( ! $id ) {
				/* Not in the library. Fetch it and put it there -- but only if
				   this is running somewhere that can, which the front of a
				   website is not. */
				if ( ! function_exists( 'media_sideload_image' ) ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
				}
				$side = media_sideload_image( $file, 0, null, 'id' );
				if ( ! is_wp_error( $side ) ) {
					$id = (int) $side;
					$report['sideloaded']++;
				}
			} else {
				$report['matched']++;
			}

			if ( $id ) {
				$all['stock']['cars'][ $i ]['photo'] = $id;
				/* photo_file is left in place on purpose. If the attachment is
				   ever deleted from the library, the render path falls back to
				   it rather than showing a car with no picture. */
				$changed = true;
			} else {
				$report['kept']++;
			}
		}

		if ( $changed ) {
			self::write( $all );
		}

		return $report;
	}

	public static function migrate() {
		if ( get_option( 'vesla_landing_migrated' ) ) {
			return;
		}
		self::install();
		$old = get_option( 'vesla_landing', array() );
		if ( is_array( $old ) && $old ) {
			self::write( $old );

			/* The blob is kept, but moved aside and taken off autoload. Left where
			   it was it would still be unserialised on every single request while
			   being read by nothing -- the exact cost this change set out to remove
			   -- and two copies of the content under the live name is how somebody
			   later edits the wrong one. Under a backup name it is recoverable and
			   inert, and uninstall.php clears it. */
			add_option( 'vesla_landing_pre_tables', $old, '', 'no' );
			delete_option( 'vesla_landing' );
		}
		update_option( 'vesla_landing_migrated', 1, false );
	}
}

class Vesla_Settings {
	const OPTION = 'vesla_landing';

	const GROUP  = 'vesla_landing_group';

	/* The merged array for this request. Cleared on save, so the editor redraws
	   from what it just stored rather than from what it read on the way in. */
	private static $all = null;

	/**
	 * Throw away the copy held for this request.
	 *
	 * Saving a car happens on its own screen now, outside the settings form,
	 * so nothing else on that request knows the stock has changed. Without
	 * this the republish that follows a save would write the previous list.
	 */
	public static function forget() {
		self::$all = null;
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );

		/* The editor still posts to options.php, so the nonce, the capability
		   check and the schema sanitiser all run exactly as before -- this only
		   redirects the result. Returning the old value means WordPress writes
		   nothing to the option row: the blob is gone, and the tables are the
		   only copy.

		   pre_update_option runs AFTER sanitize_option, so what arrives here has
		   already been through the schema. */
		add_filter(
			'pre_update_option_' . self::OPTION,
			array( __CLASS__, 'store_instead' ),
			10,
			2
		);
	}

	/**
	 * Divert a settings save into the tables.
	 *
	 * @param mixed $value The sanitised array on its way to the option row.
	 * @param mixed $old   What is in the option row now.
	 * @return mixed $old, so nothing is written there.
	 */
	public static function store_instead( $value, $old ) {
		if ( is_array( $value ) ) {
			/* Read before the write, while it still says what it said. */
			$was_on = (int) self::get( 'publish', 'enabled', 0 );

			$written = Vesla_Store::write( $value );
			if ( is_wp_error( $written ) ) {
				/* Refused, and said so. The alternative -- a save that reports
				   success while the content is unchanged, or worse, replaced --
				   is the failure this guard exists to stop. */
				self::complain( $written->get_error_code(), $written->get_error_message() );
				return $old;
			}

			/* A setting that decides whether the public site keeps updating is
			   worth a line in the log when it moves. Somebody turning it off by
			   accident is invisible from the outside for as long as nobody looks
			   at the live site. */
			$now_on = isset( $value['publish']['enabled'] ) ? (int) $value['publish']['enabled'] : $was_on;
			if ( $was_on !== $now_on ) {
				$who = wp_get_current_user();
				error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- deliberate: this is the audit line.
					'[vesla] publish.enabled %s -> %s by %s',
					$was_on ? 'on' : 'off',
					$now_on ? 'on' : 'off',
					$who && $who->exists() ? $who->user_login : 'unknown'
				) );
			}

			self::$all = null; // the copy read before the save is now stale

			/**
			 * Fires once the content has been stored.
			 *
			 * Everything that must happen on a save hangs off this, and it
			 * exists because `update_option_vesla_landing` no longer can: the
			 * filter above returns $old, so update_option() sees no change,
			 * writes nothing, and never fires its own action. That silently
			 * stopped the static page being republished and the API version
			 * being bumped. The trigger has to come from where the write really
			 * happens, not from an option that no longer holds anything.
			 */
			update_option( 'vesla_content_saved_at', time(), false );
			do_action( 'vesla_content_saved' );
		}
		return $old;
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   READING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The whole settings array, with defaults filled in for anything missing.
	 *
	 * The merge matters on upgrades: a site saved under version 1.0 has no key
	 * for a field added in 1.1, and without this every such field would read as
	 * empty until the admin happened to press Save.
	 */
	public static function all() {
		if ( null !== self::$all ) {
			return self::$all;
		}
		$saved    = Vesla_Store::read();
		$defaults = self::defaults();

		$out = array();
		foreach ( $defaults as $section => $fields ) {
			$out[ $section ] = isset( $saved[ $section ] ) && is_array( $saved[ $section ] )
				? array_merge( $fields, $saved[ $section ] )
				: $fields;
		}
		self::$all = $out;
		return $out;
	}

	/**
	 * One value. `Vesla_Settings::get( 'hero', 'heading' )`.
	 */
	public static function get( $section, $key = null, $fallback = '' ) {
		$all = self::all();
		if ( ! isset( $all[ $section ] ) ) {
			return $fallback;
		}
		if ( null === $key ) {
			return $all[ $section ];
		}
		return isset( $all[ $section ][ $key ] ) && '' !== $all[ $section ][ $key ]
			? $all[ $section ][ $key ]
			: $fallback;
	}

	/**
	 * Sections nobody has changed a word of.
	 *
	 * Every field compared against data/seed.json at render time rather than
	 * recorded once, so a section drops off this list the moment somebody
	 * edits it and the answer cannot go stale.
	 *
	 * This exists because the starter copy is convincing. It is written in
	 * the site's own voice and reads as though somebody at the showroom wrote
	 * it, so a section that has never been looked at is indistinguishable
	 * from one that has been checked and approved. Opening hours are the case
	 * that matters: seven plausible rows, none of them anybody's actual
	 * trading times, handed straight to Google.
	 *
	 * @return array<string,true> Section keys, as a lookup.
	 */
	public static function untouched_sections() {
		static $same = null;
		if ( null !== $same ) {
			return $same;
		}
		$same = array();
		$now  = self::all();
		$seed = self::defaults();

		foreach ( Vesla_Schema::get() as $key => $section ) {
			if ( ! isset( $seed[ $key ], $now[ $key ] ) ) {
				continue;
			}
			$untouched = true;
			foreach ( $section['fields'] as $field => $def ) {
				/* A picture is not copy. Whether the logo has been chosen says
				   nothing about whether the words around it were read. */
				if ( isset( $def['type'] ) && in_array( $def['type'], array( 'image', 'gallery' ), true ) ) {
					continue;
				}
				$a = isset( $now[ $key ][ $field ] ) ? $now[ $key ][ $field ] : null;
				$b = isset( $seed[ $key ][ $field ] ) ? $seed[ $key ][ $field ] : null;
				if ( self::as_text( $a ) !== self::as_text( $b ) ) {
					$untouched = false;
					break;
				}
			}
			if ( $untouched ) {
				$same[ $key ] = true;
			}
		}
		return $same;
	}

	/**
	 * A value flattened to text, for comparing like with like.
	 *
	 * Everything in the content table is a string, because that is what a
	 * database column holds; the seed is JSON and keeps its types, so a
	 * toggle reads as 1 there and "1" here and a strict comparison calls
	 * every section with a number in it edited. Casting both sides to text
	 * compares what was actually written rather than how it survived the
	 * round trip.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function as_text( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::as_text( $v );
			}
			/* Sorted, because the order is not information.

			   A repeater row is rebuilt from a SELECT with no ORDER BY, so the
			   fields inside it come back in whatever order the database felt
			   like. Encoding that straight to JSON made the comparison depend on
			   it: the same unchanged data read twice produced two different
			   strings, and a section flickered in and out of the list between
			   page loads. Sorting the keys compares the content rather than the
			   order it happened to arrive in, and the row indexes sort
			   numerically, so a list still keeps its sequence. */
			ksort( $out );
			return (string) wp_json_encode( $out );
		}
		if ( null === $value || is_bool( $value ) ) {
			$value = $value ? '1' : '';
		}
		return (string) $value;
	}

	/** Is a section switched on? Sections with no toggle are always on. */
	public static function enabled( $section ) {
		$all = self::all();
		if ( ! isset( $all[ $section ] ) || ! array_key_exists( 'enabled', $all[ $section ] ) ) {
			return true;
		}
		return (bool) $all[ $section ]['enabled'];
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   DEFAULTS
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The starting content, read from data/seed.json.
	 *
	 * The schema describes the shape of a field; it no longer carries the words
	 * that go in it. Copy in a PHP array is copy an editor cannot reach and a
	 * translator cannot see, and it made a 5,500 line file most of which was
	 * text rather than logic.
	 *
	 * The seed is only ever a starting point. It is written to the tables once,
	 * on first activation, and from then on the tables are the truth -- an admin
	 * editing the page changes the database, never this file, and a plugin
	 * update that ships new seed copy does not touch a site that already has
	 * content of its own.
	 *
	 * The schema still decides which fields EXIST. A key in the seed that no
	 * field claims is ignored, and a field with nothing in the seed starts
	 * empty; neither is an error, and that is what lets a plugin update add a
	 * field without a migration.
	 */
	public static function defaults() {
		static $seed = null;

		if ( null === $seed ) {
			$seed = array();
			$file = VESLA_DIR . 'data/seed.json';
			if ( is_readable( $file ) ) {
				$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a file shipped inside the plugin, not a remote request.
				if ( is_array( $json ) ) {
					$seed = $json;
				}
			}
		}

		$out = array();
		foreach ( Vesla_Schema::get() as $section_key => $section ) {
			$out[ $section_key ] = array();
			foreach ( $section['fields'] as $key => $def ) {
				$out[ $section_key ][ $key ] = isset( $seed[ $section_key ][ $key ] )
					? $seed[ $section_key ][ $key ]
					: self::blank( $def );
			}
		}
		return $out;
	}

	/**
	 * What a field holds when the seed says nothing about it.
	 *
	 * Taken from the field's TYPE, which is structure and does belong in the
	 * schema — a repeater with no seed is an empty list, not an empty string,
	 * and handing the editor a string where it expects rows is a fatal.
	 */
	private static function blank( $def ) {
		$type = isset( $def['type'] ) ? $def['type'] : 'text';
		if ( 'repeater' === $type ) {
			return array();
		}
		if ( 'select' === $type && ! empty( $def['choices'] ) ) {
			$keys = array_keys( $def['choices'] );
			return (string) reset( $keys );
		}
		return '';
	}

	/**
	 * First install. Seeds the option, and fills the car list from the sample
	 * photographs bundled with the plugin so the site is not an empty grid on
	 * the day it goes live.
	 */
	public static function activate() {
		Vesla_Store::install();

		/* An existing site upgrading from the option-based version: move its
		   content across before deciding whether it needs seeding. */
		Vesla_Store::migrate();

		if ( Vesla_Store::read() ) {
			return; // already has content; never overwrite a real site
		}

		Vesla_Store::write( self::defaults() );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   SANITISING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Walks the schema, not the submitted data.
	 *
	 * That direction is the whole safety property: anything posted that the
	 * schema does not describe is dropped rather than stored, so a crafted form
	 * submission cannot smuggle an extra key into the option and out again
	 * through the renderer.
	 */
	/**
	 * Tell the admin something went wrong, if there is an admin to tell.
	 *
	 * add_settings_error() lives in wp-admin and is not loaded on the front of
	 * the site or in WP-CLI. The sanitiser is normally only reached through
	 * options.php, where it is present -- but a fatal error inside a save is a
	 * blank screen and a lost form, so this never assumes it.
	 */
	private static function complain( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message, 'error' );
		}
	}
	/**
	 * How many settings arrived, before any of them are trusted.
	 *
	 * PHP stops parsing a POST after max_input_vars variables and says
	 * nothing: no warning, no error, the request simply arrives short. This
	 * form has around 1,700 inputs and the default ceiling is 1,000, so
	 * everything below roughly two thirds of the way down the page -- which
	 * on this site was the whole of the search-engine section -- reached the
	 * sanitiser as absent, and absent means blank. Every save silently
	 * emptied it.
	 *
	 * Two defences, because either alone is not enough:
	 *
	 *   __json  admin.js packs the entire form into this one field before
	 *           submitting, so the request carries a handful of variables
	 *           however many settings the site grows to. This is the fix.
	 *
	 *   __end   a marker printed as the very last input on the form. With
	 *           scripting off there is no packing, so if the marker did not
	 *           arrive the POST was cut short -- and the only safe thing to
	 *           do with a truncated form is refuse it. Saving what did
	 *           arrive is what caused the damage in the first place.
	 */
	public static function sanitize( $input ) {
		$clean  = array();
		$schema = Vesla_Schema::get();
		$input  = is_array( $input ) ? $input : array();

		if ( isset( $input['__json'] ) ) {
			/* options.php has already unslashed this, in common with every
			   other value here -- see the note in sanitize_field(). */
			$packed = json_decode( (string) $input['__json'], true );
			if ( is_array( $packed ) ) {
				$input = $packed;
			} else {
				self::complain( 'vesla_unreadable', __( 'Nothing was saved: the page sent something this plugin could not read. Reload the editor and try again.', 'vesla-landing' ) );
				return Vesla_Store::read();
			}
		} elseif ( isset( $input['__start'] ) && ! isset( $input['__end'] ) ) {
			self::complain( 'vesla_truncated', __( 'Nothing was saved. This page has more settings on it than the server is willing to accept in one go, and saving part of them would have emptied the rest. Switch JavaScript on and save again — the editor then sends everything as a single value. If that is not possible, ask your host to raise max_input_vars to 5000.', 'vesla-landing' ) );
			return Vesla_Store::read();
		}

		unset( $input['__json'], $input['__start'], $input['__end'] );

		/* What is stored now, so that a field the form did not send can be kept
		   rather than blanked. See the note on $absent below. */
		$stored = Vesla_Store::read();

		foreach ( $schema as $section_key => $section ) {
			$clean[ $section_key ] = array();
			$sent   = isset( $input[ $section_key ] ) && is_array( $input[ $section_key ] );
			$posted = $sent ? $input[ $section_key ] : array();
			$was    = isset( $stored[ $section_key ] ) && is_array( $stored[ $section_key ] )
				? $stored[ $section_key ] : array();

			foreach ( $section['fields'] as $key => $def ) {
				/* Not on this form, so not in this post, so nothing to clean --
				   and treating absent as blank here would empty it. */
				if ( isset( $def['admin'] ) && false === $def['admin'] ) {
					continue;
				}
				$raw = isset( $posted[ $key ] ) ? $posted[ $key ] : null;

				/* ── absent is not the same as empty ──

				   A text box that was on the form and left blank posts an empty
				   string; a field that was never on the form posts nothing at all.
				   Those two arrive here indistinguishable unless they are told
				   apart, and treating the second as the first is how a save of one
				   part of this screen used to wipe the rest.

				   Some kinds of field genuinely post nothing when they are empty,
				   and for those, within a section that WAS sent, absent really does
				   mean empty: an unticked checkbox, a tick-list with nothing
				   chosen, a repeater whose last row was just removed, a gallery
				   emptied of pictures. Without this exception the last row of a
				   list could never be deleted -- it would be restored by the very
				   guard meant to protect it.

				   Where the whole SECTION is missing, even those keep what they
				   had, because then nothing about them was being said at all. */
				$blank_when_absent = array( 'toggle', 'checks', 'repeater', 'gallery' );
				$absent = ( null === $raw )
					&& ( ! $sent || ! in_array( $def['type'], $blank_when_absent, true ) );
				if ( $absent && array_key_exists( $key, $was ) ) {
					$clean[ $section_key ][ $key ] = $was[ $key ];
					continue;
				}

				if ( 'repeater' === $def['type'] ) {
					$clean[ $section_key ][ $key ] = self::sanitize_repeater( $raw, $def );
					continue;
				}
				$clean[ $section_key ][ $key ] = self::sanitize_field( $raw, $def );
			}
		}

		/* The bundled-photograph carry-over that used to live here is gone with
		   the repeater. A car keeps its own photo_file in its own meta now, and
		   saving the settings form does not touch it.

		   Vesla_Store::cars() is read straight from the Vehicles list, so this
		   form no longer carries stock at all. */

		/* One rule that no single field can enforce, because it is about two of
		   them at once: the film style needs a poster.

		   Not a warning. Without a poster there is nothing to show before the
		   film has loaded, nothing where autoplay is refused, and nothing for a
		   reader who has asked for less movement -- and the front page's
		   opening section would be a black rectangle with words on it in every
		   one of those cases. So the style falls back rather than the save
		   failing: the words, the buttons and the shield are all still there in
		   classic, which is a working page rather than a broken one.

		   Checked against the CLEANED values, not the posted ones, so a poster
		   that was itself rejected a moment ago counts as absent. */
		if ( isset( $clean['hero'] ) && is_array( $clean['hero'] )
			&& isset( $clean['hero']['style'] ) && 'video' === $clean['hero']['style']
			&& empty( $clean['hero']['poster'] ) ) {
			$clean['hero']['style'] = 'classic';
			self::complain(
				'vesla_hero_poster',
				__( 'The opening section was left on the classic style: the film style needs a poster picture and there is not one. The poster is what is shown before the film loads, and instead of it wherever it will not play — without it the top of the front page would be a black rectangle for anybody on a slow connection, on a phone saving power, or asking for less movement. Add a poster and choose the film style again.', 'vesla-landing' )
			);
		}

		return $clean;
	}

	private static function sanitize_repeater( $raw, $def ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rows = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean_row = array();
			$has_value = false;

			foreach ( $def['fields'] as $sub_key => $sub_def ) {
				$value = isset( $row[ $sub_key ] ) ? $row[ $sub_key ] : null;
				$clean_row[ $sub_key ] = self::sanitize_field( $value, $sub_def );

				/* Only fields the admin actually types into count as evidence that
				   the row was meant. A select or a toggle always comes back holding
				   something -- an untouched 'Gearbox' still says Automatic -- so
				   counting those made the emptiness test below always true, and a row
				   added by mistake and never filled in was stored and printed as a
				   blank card. */
				$sub_type = isset( $sub_def['type'] ) ? $sub_def['type'] : 'text';
				if ( in_array( $sub_type, array( 'select', 'toggle' ), true ) ) {
					continue;
				}
				if ( '' !== $clean_row[ $sub_key ] && 0 !== $clean_row[ $sub_key ] ) {
					$has_value = true;
				}
			}
			/* a row where every box was left empty is a row the admin added and
			   then abandoned — storing it would print a blank card */
			if ( $has_value ) {
				if ( isset( $row['photo_file'] ) ) {
					$clean_row['photo_file'] = sanitize_text_field( (string) $row['photo_file'] );
				}
				$rows[] = $clean_row;
			}
		}
		return array_values( $rows );
	}

	/**
	 * One value, cleaned by its own field definition.
	 *
	 * Public because a car is edited on a post screen now as well as in the
	 * settings form, and both doors have to clean a price the same way. One
	 * sanitiser, one schema — a second copy is a second set of rules to
	 * forget to update.
	 */
	public static function clean_one( $value, $def ) {
		return self::sanitize_field( $value, $def );
	}

	private static function sanitize_field( $value, $def ) {
		/* No wp_unslash() below, deliberately. This runs as a register_setting
		   sanitize_callback, and options.php has already unslashed the posted
		   value before it reaches here. Unslashing a second time silently ate
		   one level of backslashes from every text field: a Windows publish
		   path typed as C:\Users\... was stored as C:Users...
		   The enquiry form reads $_POST directly and DOES still need
		   wp_unslash; that is a different path and must keep it. */
		$type = isset( $def['type'] ) ? $def['type'] : 'text';

		switch ( $type ) {
			case 'toggle':
				return empty( $value ) ? 0 : 1;

			case 'number':
				if ( '' === $value || null === $value ) {
					return '';
				}
				$n = (int) $value;
				if ( isset( $def['min'] ) ) { $n = max( (int) $def['min'], $n ); }
				if ( isset( $def['max'] ) ) { $n = min( (int) $def['max'], $n ); }
				return $n;

			case 'image':
				return $value ? absint( $value ) : '';

			case 'video':
				/* Refused with a reason, and the previous value dropped rather
				   than a bad one kept. Every branch here says what to do about
				   it: being told "invalid file" by a screen that then forgets
				   what you chose is the worst of both.

				   The ceiling lives in the field definition rather than in a
				   constant here, because the answer is a property of the slot
				   -- a hero backdrop and a lower band would not want the same
				   number, and the sanitiser should not have to know which is
				   which. */
				$vid = $value ? absint( $value ) : 0;
				if ( ! $vid ) {
					return '';
				}

				$allow = ! empty( $def['mimes'] ) ? (array) $def['mimes'] : array( 'video/mp4' );
				$mime  = (string) get_post_mime_type( $vid );
				if ( ! in_array( $mime, $allow, true ) ) {
					self::complain(
						'vesla_video_type',
						sprintf(
							/* translators: 1: the type this field accepts. 2: the file's type. */
							__( 'The film was not saved: this field takes %1$s and that file is %2$s. Export it in the right format and choose it again.', 'vesla-landing' ),
							implode( ' or ', $allow ),
							$mime ? $mime : __( 'of a type this site could not read', 'vesla-landing' )
						)
					);
					return '';
				}

				$cap   = ! empty( $def['max_bytes'] ) ? (int) $def['max_bytes'] : 10485760;
				$path  = get_attached_file( $vid );
				$bytes = ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
				if ( $bytes > $cap ) {
					self::complain(
						'vesla_video_size',
						sprintf(
							/* translators: 1: the file's size. 2: the limit. */
							__( 'The film was not saved: it is %1$s and the limit is %2$s. This sits at the top of the front page, so its weight is paid by every first-time visitor before they have read a word — re-export it smaller rather than raising the limit. Ten seconds at 1280 × 720, H.264, CRF about 28, no audio track, comes in well under the cap.', 'vesla-landing' ),
							size_format( $bytes, 1 ),
							size_format( $cap )
						)
					);
					return '';
				}

				/* Above the cap it is refused; above either warning line it is
				   kept and the cost is named. The difference matters: a heavy
				   film is a bad idea rather than a broken one, and refusing it
				   would be this screen overruling a decision that belongs to
				   the administrator.

				   The two warning lines are set from measurement rather than
				   taste. Timed on this page at four sizes, on a throttled
				   connection, the figure that moves is not LCP -- it is how
				   long somebody looks at the poster before the film appears. */
				$heavy = ! empty( $def['heavy_bytes'] ) ? (int) $def['heavy_bytes'] : 0;
				$warn  = ! empty( $def['warn_bytes'] ) ? (int) $def['warn_bytes'] : 0;

				if ( $heavy && $bytes > $heavy ) {
					self::complain(
						'vesla_video_heavy',
						sprintf(
							/* translators: 1: the file's size. 2: the size above which this warns strongly. */
							__( 'The film was saved, but it is %1$s, and above %2$s the film is something most visitors will never see move. Measured on this page: a 12MB film first appears about 17 seconds into the visit on a slow connection and about 6 seconds on 4G; a 25MB one takes about 24 seconds and 7 seconds. Until then the poster is what is on screen, so nothing is broken — but a film nobody reaches is bandwidth spent on a still picture. Under 4MB it arrives while people are still reading the heading.', 'vesla-landing' ),
							size_format( $bytes, 1 ),
							size_format( $heavy )
						)
					);
				} elseif ( $warn && $bytes > $warn ) {
					self::complain(
						'vesla_video_heavy',
						sprintf(
							/* translators: 1: the file's size. 2: the size above which this warns. */
							__( 'The film was saved, but it is %1$s. Above %2$s it starts to be felt on a phone on mobile data, and the film appears later: measured on this page, about 14 seconds into the visit on a slow connection against about 8 seconds for a small clip. It plays muted with no controls, so quality past "recognisable" is paid for and not seen.', 'vesla-landing' ),
							size_format( $bytes, 1 ),
							size_format( $warn )
						)
					);
				}

				return $vid;

			case 'checks':
				/* Only what the list actually offers survives. A value posted
				   that is not on the list is dropped rather than stored, which
				   is the same rule the whole sanitiser follows: the schema
				   decides what may exist, not the browser. */
				$allowed = Vesla_Schema::options_for( $def );
				$picked  = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
				$keep    = array();
				foreach ( (array) $picked as $one ) {
					$one = trim( (string) $one );
					if ( '' !== $one && in_array( $one, $allowed, true ) && ! in_array( $one, $keep, true ) ) {
						$keep[] = $one;
					}
				}
				/* Stored in the list's own order, not the order they were
				   posted, so every car reads its features the same way down. */
				$ordered = array();
				foreach ( $allowed as $one ) {
					if ( in_array( $one, $keep, true ) ) {
						$ordered[] = $one;
					}
				}
				return implode( "\n", $ordered );

			case 'gallery':
				/* A list of attachment IDs. Kept as a string rather than an
				   array because it is one value in one column, and the order is
				   the order the admin picked -- which is the running order of
				   the photographs, so it is content and must not be sorted. */
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
				$max = isset( $def['max'] ) ? (int) $def['max'] : 10;
				return implode( ',', array_slice( $ids, 0, $max ) );

			case 'select':
				$choices = isset( $def['choices'] ) ? array_keys( $def['choices'] ) : array();
				$value   = sanitize_text_field( (string) $value );
				return in_array( $value, $choices, true ) ? $value : ( isset( $def['default'] ) ? $def['default'] : '' );

			case 'color':
				$hex = sanitize_hex_color( (string) $value );
				return $hex ? $hex : ( isset( $def['default'] ) ? $def['default'] : '' );

			case 'email':
				$email = sanitize_email( (string) $value );
				return $email ? $email : '';

			case 'url':
				$url = trim( (string) $value );
				if ( '' === $url ) {
					return '';
				}
				/* In-page jumps (#stock) are the common case here and are not
				   URLs as esc_url_raw understands them, so they are allowed
				   through explicitly after being stripped to a safe fragment. */
				if ( 0 === strpos( $url, '#' ) ) {
					return '#' . sanitize_title( substr( $url, 1 ) );
				}
				return esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'rich':
				return Vesla_Schema::rich( $value );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}

/* ========================================================================== */
/*  4 · THE EDITOR SCREEN
 *
 *  One scrolling screen for the whole page. Drawn entirely from the schema.
 */
/* ========================================================================== */

class Vesla_Admin {
	const SLUG = 'vesla-landing';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . VESLA_BASENAME, array( __CLASS__, 'action_links' ) );
		/* Late, so it runs after everything else has registered its menus. */
		add_action( 'admin_menu', array( __CLASS__, 'tidy_menu' ), 999 );
	}


	/**
	 * Take the menus this site never uses out of the sidebar.
	 *
	 * Posts and Comments only. Nothing that anyone might need is touched:
	 * Pages stays, because the Vehicles page lives there; Media stays,
	 * because that is where the photographs are; Appearance, Plugins, Users,
	 * Tools and Settings all stay.
	 *
	 * Hidden, not removed. The post types still exist and anything already
	 * written is untouched -- switching the setting off brings both menus
	 * straight back. And it is only the menu: a direct link to edit.php still
	 * works, so this cannot lock anybody out of their own site.
	 */
	public static function tidy_menu() {
		if ( ! Vesla_Settings::get( 'extras', 'tidy_admin', 0 ) ) {
			return;
		}
		remove_menu_page( 'edit.php' );
		remove_menu_page( 'edit-comments.php' );
	}
	public static function menu() {
		add_menu_page(
			__( 'Landing Page', 'vesla-landing' ),
			__( 'Landing Page', 'vesla-landing' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-admin-customizer',
			3
		);

		/* The editor needs its own submenu row, added by hand.
		 *
		 * WordPress usually inserts one automatically -- add_submenu_page()
		 * copies the parent in as the first child the first time a child is
		 * added. That never happened here, because the Enquiries row comes from
		 * the post type's show_in_menu and wp-admin/menu.php registers those
		 * BEFORE the admin_menu hook runs. So by the time add_menu_page() above
		 * executes, a submenu already exists and the copy is skipped.
		 *
		 * A parent's link is simply its first child's link, so the result was a
		 * 'Landing Page' menu that opened Enquiries, with the editor reachable
		 * only by typing admin.php?page=vesla-landing. Adding the row and
		 * moving it to the front fixes both the missing entry and the link. */
		add_submenu_page(
			self::SLUG,
			__( 'Edit page', 'vesla-landing' ),
			__( 'Edit page', 'vesla-landing' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		global $submenu;
		if ( ! empty( $submenu[ self::SLUG ] ) ) {
			$first = array();
			$rest  = array();
			foreach ( $submenu[ self::SLUG ] as $row ) {
				if ( isset( $row[2] ) && self::SLUG === $row[2] ) {
					$first[] = $row;
				} else {
					$rest[] = $row;
				}
			}
			$submenu[ self::SLUG ] = array_merge( $first, $rest );
		}
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Edit page', 'vesla-landing' ) . '</a>'
		);
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media(); // the image pickers
		/* Loads wp.editor and TinyMCE. admin.js starts an editor only when a
		   'rich' field is first clicked, so this is the bootstrap, not 26 running
		   editors. Note that WordPress serves the TinyMCE half only when
		   user_can_richedit() passes -- if it does not, the fields stay plain
		   textareas holding the same markup, which still saves correctly. */
		wp_enqueue_editor();
		wp_enqueue_style( 'vesla-admin', VESLA_URL . 'assets/admin.css', array(), vesla_asset_ver( 'assets/admin.css' ) );
		wp_enqueue_script( 'vesla-admin', VESLA_URL . 'assets/admin.js', array( 'jquery' ), vesla_asset_ver( 'assets/admin.js' ), true );
		wp_localize_script(
			'vesla-admin',
			'VeslaAdminL10n',
			array(
				'chooseImage' => __( 'Choose image', 'vesla-landing' ),
				'useImage'    => __( 'Use this image', 'vesla-landing' ),
				'chooseVideo' => __( 'Choose video', 'vesla-landing' ),
				'useVideo'    => __( 'Use this video', 'vesla-landing' ),
				'confirmDrop' => __( 'Remove this item? It will be gone once you save.', 'vesla-landing' ),
				'unsaved'     => __( 'You have unsaved changes. Leave without saving?', 'vesla-landing' ),
				'empty'       => __( 'Nothing here yet — press the button below to add the first one.', 'vesla-landing' ),
				'editRich'    => __( 'Formatting…', 'vesla-landing' ),
				'richHint'    => __( 'Select a word, then use B for bold or the link button.', 'vesla-landing' ),
				'packEmpty'   => __( 'Nothing was sent, because this page could not read its own fields. Reload the page and try again — your saved settings have not been changed.', 'vesla-landing' ),
			)
		);
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   THE SCREEN
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Each offered feature, and how many cars have it ticked.
	 *
	 * @return array<string,int>
	 */
	/**
	 * Take a copy, put one back.
	 *
	 * Deliberately at the top of the screen rather than filed away at the
	 * bottom: it is worth reaching for BEFORE doing something risky, and a
	 * recovery route nobody knows about is not a recovery route. Kept folded
	 * shut so it is not in the way the rest of the time.
	 */
	public static function backups_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$content = isset( $_GET['vesla_content'] ) ? sanitize_key( wp_unslash( $_GET['vesla_content'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- reading a result, not acting on one.
		if ( $content ) {
			$said = array(
				'ok'       => array( 'success', sprintf( __( 'Content export written to %s.', 'vesla-landing' ), Vesla_Publisher::export_file() ) ),
				'nofolder' => array( 'error', __( 'That folder does not exist and could not be created. Check the path in “Publish the public page”.', 'vesla-landing' ) ),
				'failed'   => array( 'error', __( 'The content export could not be written. Check the folder is writable.', 'vesla-landing' ) ),
			);
			if ( isset( $said[ $content ] ) ) {
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					esc_attr( $said[ $content ][0] ),
					esc_html( $said[ $content ][1] )
				);
			}
		}

		$notes = array(
			'ok'         => array( 'success', __( 'Settings imported.', 'vesla-landing' ) ),
			'nofile'     => array( 'error', __( 'No file was chosen.', 'vesla-landing' ) ),
			'unreadable' => array( 'error', __( 'That file is not a settings export this plugin can read.', 'vesla-landing' ) ),
			'failed'     => array( 'error', __( 'Nothing was imported. Your settings have not been changed.', 'vesla-landing' ) ),
		);
		$said = isset( $_GET['vesla_import'] ) ? sanitize_key( wp_unslash( $_GET['vesla_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- reading a result, not acting on one.
		if ( isset( $notes[ $said ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notes[ $said ][0] ),
				esc_html( $notes[ $said ][1] )
			);
		}
		$restored = isset( $_GET['vesla_restored'] ) ? sanitize_key( wp_unslash( $_GET['vesla_restored'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $restored ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'ok' === $restored ? 'success' : 'error',
				'ok' === $restored
					? esc_html__( 'That backup has been put back.', 'vesla-landing' )
					: esc_html__( 'That backup could not be put back. Nothing has been changed.', 'vesla-landing' )
			);
		}

		$shots = Vesla_Store::snapshots();
		?>
		<details class="vesla-backups">
			<summary><?php esc_html_e( 'Backups — take a copy, or put one back', 'vesla-landing' ); ?></summary>
			<p class="description">
				<?php esc_html_e( 'A copy is taken automatically every time these settings are saved, and the last ten are kept. You can also download one yourself before making a big change.', 'vesla-landing' ); ?>
			</p>

			<p>
				<a class="button" href="<?php echo esc_url( Vesla_Publisher::export_url() ); ?>">
					<?php esc_html_e( 'Download a copy', 'vesla-landing' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( Vesla_Publisher::content_export_url() ); ?>">
					<?php esc_html_e( 'Write the content export', 'vesla-landing' ); ?>
				</a>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: a file path. */
					esc_html__( 'The second one writes every setting and every vehicle to %s, ready to be committed alongside the code. Nothing is downloaded — it is written straight to that folder.', 'vesla-landing' ),
					'<code>' . esc_html( Vesla_Publisher::export_file() ) . '</code>'
				);
				?>
			</p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vesla_import">
				<?php wp_nonce_field( 'vesla_import' ); ?>
				<input type="file" name="vesla_import" accept="application/json,.json" required>
				<button type="submit" class="button"><?php esc_html_e( 'Load a copy back in', 'vesla-landing' ); ?></button>
				<span class="description"><?php esc_html_e( 'This replaces every setting with the ones in the file. A backup of what is here now is taken first.', 'vesla-landing' ); ?></span>
			</form>

			<?php if ( $shots ) : ?>
				<h3><?php esc_html_e( 'Automatic backups', 'vesla-landing' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vesla_restore">
					<?php wp_nonce_field( 'vesla_restore' ); ?>
					<select name="snapshot">
						<?php foreach ( $shots as $one ) : ?>
							<?php
							/* The filename carries the time it was taken, in UTC. Shown
							   in the site's own timezone, because that is the clock the
							   person reading it was working to. */
							$when = (int) filemtime( $one );
							?>
							<option value="<?php echo esc_attr( $one ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: date and time, 2: how long ago. */
										__( '%1$s — %2$s ago', 'vesla-landing' ),
										wp_date( 'j M Y, H:i', $when ),
										human_time_diff( $when, time() )
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Put this one back', 'vesla-landing' ); ?></button>
				</form>
			<?php endif; ?>
		</details>
		<?php
	}

	public static function features_in_use() {
		$fields = Vesla_Store::car_fields();
		if ( empty( $fields['features'] ) ) {
			return array();
		}
		$out = array();
		foreach ( Vesla_Schema::options_for( $fields['features'] ) as $name ) {
			$out[ $name ] = 0;
		}
		foreach ( Vesla_Store::cars() as $car ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $car['features'] ) as $one ) {
				$one = trim( $one );
				if ( '' !== $one && isset( $out[ $one ] ) ) {
					$out[ $one ]++;
				}
			}
		}
		return $out;
	}

	/**
	 * Hidden inputs carrying every section this form does not show.
	 *
	 * The sanitiser walks the schema and stores what it is given, so a section
	 * absent from the post is a section wiped. A single-section screen has to
	 * hand back everything it is not editing.
	 */
	private static function carry_over( $values, $editing ) {
		$opt = Vesla_Settings::OPTION;
		foreach ( Vesla_Schema::get() as $section_key => $section ) {
			if ( in_array( $section_key, $editing, true ) ) {
				continue;
			}
			foreach ( $section['fields'] as $key => $def ) {
				$value = isset( $values[ $section_key ][ $key ] ) ? $values[ $section_key ][ $key ] : '';
				self::carry_value( $opt . '[' . $section_key . '][' . $key . ']', $value );
			}
		}
	}

	/** One value as hidden inputs, however deeply it nests. */
	private static function carry_value( $name, $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				self::carry_value( $name . '[' . $k . ']', $v );
			}
			return;
		}
		printf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( $name ),
			esc_attr( (string) $value )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this page.', 'vesla-landing' ) );
		}

		$schema = Vesla_Schema::get();
		$values = Vesla_Settings::all();
		$opt    = Vesla_Settings::OPTION;
		?>
		<div class="wrap vesla-wrap">

			<div class="vesla-topbar">
				<div>
					<h1><?php esc_html_e( 'Landing Page', 'vesla-landing' ); ?></h1>
					<p class="vesla-sub">
						<?php esc_html_e( 'Everything on the public page is edited here. Change what you need, then press Save changes at the bottom — or use the button in this bar, which does the same thing.', 'vesla-landing' ); ?>
					</p>
				</div>
				<div class="vesla-topbar-act">
					<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'View the page', 'vesla-landing' ); ?>
					</a>
					<?php if ( Vesla_Publisher::enabled() ) : ?>
						<?php /* Saving republishes on its own; this is for the times it
						         needs doing without an edit — after fixing folder
						         permissions, or restoring a file somebody deleted. */ ?>
						<a class="button" href="<?php echo esc_url( Vesla_Publisher::republish_url() ); ?>"
						   title="<?php esc_attr_e( 'Write the public page again from what is saved here', 'vesla-landing' ); ?>">
							<?php esc_html_e( 'Republish', 'vesla-landing' ); ?>
						</a>
						<?php $written = Vesla_Publisher::last_written(); ?>
						<?php if ( $written ) : ?>
							<span class="vesla-written"><?php echo $written; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in last_written(). ?></span>
						<?php endif; ?>
					<?php endif; ?>
					<button type="submit" form="vesla-form" class="button button-primary button-hero">
						<?php esc_html_e( 'Save changes', 'vesla-landing' ); ?>
					</button>
				</div>
			</div>

			<?php self::backups_panel(); ?>

			<div class="vesla-layout">

				<!-- the list on the left jumps within this screen; it never reloads,
				     so an unsaved change in one section is not lost by looking at another -->
				<?php
				/* Worked out once, here, and read by both the list and the panels. */
				$fresh = Vesla_Settings::untouched_sections();
				?>
				<nav class="vesla-nav" aria-label="<?php esc_attr_e( 'Page sections', 'vesla-landing' ); ?>">
					<p class="vesla-nav-title"><?php esc_html_e( 'Sections, top to bottom', 'vesla-landing' ); ?></p>
					<ul>
						<?php foreach ( $schema as $key => $section ) : ?>
							<li>
								<a href="#vesla-<?php echo esc_attr( $key ); ?>" data-target="vesla-<?php echo esc_attr( $key ); ?>">
									<span><?php echo esc_html( $section['title'] ); ?></span>
									<?php if ( isset( $fresh[ $key ] ) ) : ?>
										<b class="vesla-fresh" title="<?php esc_attr_e( 'Still the starter copy — nobody has changed a word of this section', 'vesla-landing' ); ?>"><?php esc_html_e( 'starter', 'vesla-landing' ); ?></b>
									<?php endif; ?>
									<?php if ( array_key_exists( 'enabled', $section['fields'] ) ) : ?>
										<em class="vesla-dot<?php echo empty( $values[ $key ]['enabled'] ) ? ' is-off' : ''; ?>"
										    data-dot-for="<?php echo esc_attr( $opt . '[' . $key . '][enabled]' ); ?>"
										    title="<?php esc_attr_e( 'Whether this section is shown on the page', 'vesla-landing' ); ?>"></em>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="vesla-nav-foot">
						<?php esc_html_e( 'A grey dot means that section is hidden from visitors.', 'vesla-landing' ); ?>
						<?php if ( $fresh ) : ?>
							<br>
							<?php
							printf(
								esc_html(
									/* translators: %d: how many sections. */
									_n(
										'%d section below is still the starter copy, unread by anyone here.',
										'%d sections below are still the starter copy, unread by anyone here.',
										count( $fresh ),
										'vesla-landing'
									)
								),
								count( $fresh )
							);
							?>
						<?php endif; ?>
					</p>
				</nav>

				<form method="post" action="options.php" id="vesla-form" class="vesla-form">
					<?php settings_fields( Vesla_Settings::GROUP ); ?>
					<?php /* The first half of the truncation marker. If the matching one at
					         the bottom of this form does not arrive with it, PHP stopped
					         reading the POST part-way and the save is refused rather than
					         applied to whatever happened to fit. */ ?>
					<input type="hidden" name="<?php echo esc_attr( Vesla_Settings::OPTION ); ?>[__start]" value="1">

					<?php foreach ( $schema as $section_key => $section ) : ?>
						<section class="vesla-panel" id="vesla-<?php echo esc_attr( $section_key ); ?>">
							<header class="vesla-panel-head">
								<h2><?php echo esc_html( $section['title'] ); ?></h2>
								<?php if ( ! empty( $section['blurb'] ) ) : ?>
									<p class="vesla-panel-blurb"><?php echo esc_html( $section['blurb'] ); ?></p>
								<?php endif; ?>
								<?php if ( isset( $fresh[ $section_key ] ) ) : ?>
									<p class="vesla-panel-fresh">
										<?php esc_html_e( 'Every word below is still the starter copy this plugin shipped with. It is written to sound like the showroom, so it will not look wrong — which is exactly why it is worth reading before it goes live.', 'vesla-landing' ); ?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $section['screen_link'] ) ) : ?>
									<p class="vesla-panel-go">
										<a class="button" href="<?php echo esc_url( admin_url( $section['screen_link'] ) ); ?>">
											<?php echo esc_html( $section['screen_link_label'] ); ?>
										</a>
									</p>
								<?php endif; ?>
							</header>

							<div class="vesla-fields">
								<?php
								foreach ( $section['fields'] as $key => $def ) {
									/* A field edited on a screen of its own is not drawn here. */
									if ( isset( $def['admin'] ) && false === $def['admin'] ) {
										continue;
									}
									$name  = $opt . '[' . $section_key . '][' . $key . ']';
									$value = isset( $values[ $section_key ][ $key ] ) ? $values[ $section_key ][ $key ] : '';
									self::field( $name, $value, $def, $section_key . '_' . $key );
								}
								?>
							</div>
						</section>
					<?php endforeach; ?>

					<div class="vesla-save">
						<?php submit_button( __( 'Save changes', 'vesla-landing' ), 'primary button-hero', 'submit', false ); ?>
						<span class="vesla-save-note"><?php esc_html_e( 'Saves every section on this screen at once.', 'vesla-landing' ); ?></span>
					</div>
					<?php /* Last input on the form, deliberately. See __start above. */ ?>
					<input type="hidden" name="<?php echo esc_attr( Vesla_Settings::OPTION ); ?>[__end]" value="1">
				</form>
			</div>
		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   ONE FIELD
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function field( $name, $value, $def, $id ) {
		$type  = isset( $def['type'] ) ? $def['type'] : 'text';
		$label = isset( $def['label'] ) ? $def['label'] : '';
		$help  = isset( $def['help'] ) ? $def['help'] : '';

		if ( 'repeater' === $type ) {
			self::repeater( $name, $value, $def, $id );
			return;
		}

		$row_class = 'vesla-row vesla-row--' . $type;
		if ( 'toggle' === $type ) {
			$row_class .= ' vesla-row--switch';
		}

		/* A field may declare 'show_if' => '<another field in this section>',
		 * and it is then shown only while that checkbox is ticked.
		 *
		 * The row is hidden, never omitted: its input still posts, so the wording
		 * an admin typed survives switching the feature off and back on. Turning
		 * a hidden field into an absent one would hand the sanitiser nothing and
		 * quietly blank it.
		 *
		 * PHP sets the opening state and admin.js keeps it in step afterwards, so
		 * the screen is correct before any script runs.
		 */
		$show_if   = isset( $def['show_if'] ) ? $def['show_if'] : '';
		$show_name = '';
		$hide_now  = false;
		if ( $show_if && preg_match( '/^(.*)\[([^\]]+)\]$/', $name, $m ) ) {
			$show_name = $m[1] . '[' . $show_if . ']';
			if ( preg_match( '/\[([^\]]+)\]$/', $m[1], $sec ) ) {
				$hide_now = ! Vesla_Settings::get( $sec[1], $show_if, 0 );
			}
		}
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>"
		     <?php if ( $show_name ) : ?>data-show-if="<?php echo esc_attr( $show_name ); ?>"<?php endif; ?>
		     <?php echo $hide_now ? 'hidden' : ''; ?>>
			<label class="vesla-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="vesla-control">
				<?php self::control( $name, $value, $def, $id ); ?>
				<?php if ( $help ) : ?>
					<p class="vesla-help"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function control( $name, $value, $def, $id ) {
		$type = isset( $def['type'] ) ? $def['type'] : 'text';

		switch ( $type ) {
			case 'toggle':
				?>
				<label class="vesla-switch">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
					       name="<?php echo esc_attr( $name ); ?>" value="1"
					       <?php checked( 1, (int) $value ); ?>>
					<span class="vesla-switch-track" aria-hidden="true"></span>
					<span class="vesla-switch-text">
						<span class="on"><?php esc_html_e( 'Shown', 'vesla-landing' ); ?></span>
						<span class="off"><?php esc_html_e( 'Hidden', 'vesla-landing' ); ?></span>
					</span>
				</label>
				<?php
				break;

			case 'rich':
				/* A plain textarea holding the markup, upgraded to TinyMCE by
				   admin.js the first time it is clicked. Deferring matters: this
				   screen carries the whole page, and starting an editor for every
				   rich field on load would cost seconds before anything is usable.
				   It also degrades honestly -- with the upgrade never applied the
				   field is still editable, just as tags. */
				?>
				<div class="vesla-rich" data-vesla-rich="1">
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
					          rows="4" class="vesla-input vesla-rich-input"><?php echo esc_textarea( $value ); ?></textarea>
				</div>
				<?php
				break;

			case 'textarea':
				?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
				          rows="3" class="vesla-input"><?php echo esc_textarea( $value ); ?></textarea>
				<?php
				break;

			case 'checks':
				$options = Vesla_Schema::options_for( $def );
				$on      = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $value ) ) );

				/* How many cars use each line, for the one list that defines them.
				   Unticking something there takes it off the site, and this count
				   is the difference between knowing that beforehand and finding
				   out afterwards. Computed only for the field that asks. */
				$counts = ! empty( $def['count_cars'] ) ? self::features_in_use() : array();
				?>
				<?php if ( ! $options ) : ?>
					<p class="vesla-help vesla-checks-empty">
						<?php esc_html_e( 'Nothing to tick yet — add some features to the list near the top of this section first.', 'vesla-landing' ); ?>
					</p>
				<?php else : ?>
					<div class="vesla-checks" id="<?php echo esc_attr( $id ); ?>">
						<?php foreach ( $options as $n => $one ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]"
								       value="<?php echo esc_attr( $one ); ?>"
								       <?php checked( in_array( $one, $on, true ) ); ?>>
								<span><?php echo esc_html( $one ); ?></span>
								<?php if ( isset( $counts[ $one ] ) ) : ?>
									<em class="vesla-checks-n<?php echo $counts[ $one ] ? '' : ' is-zero'; ?>">
										<?php
										echo $counts[ $one ]
											? esc_html( number_format_i18n( $counts[ $one ] ) )
											: esc_html__( 'unused', 'vesla-landing' );
										?>
									</em>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<?php /* Posted even when nothing is ticked. Browsers send no
						         checkbox at all in that case, and a field that is
						         simply absent is one the sanitiser never sees --
						         so unticking the last feature would not save. */ ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="">
					</div>
				<?php endif; ?>
				<?php
				break;

			case 'gallery':
				$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) );
				$max = isset( $def['max'] ) ? (int) $def['max'] : 10;
				?>
				<div class="vesla-gal" data-vesla-gallery data-max="<?php echo esc_attr( $max ); ?>">
					<ul class="vesla-gal-list">
						<?php foreach ( $ids as $one ) : ?>
							<?php $thumb = wp_get_attachment_image_url( $one, 'thumbnail' ); ?>
							<?php if ( ! $thumb ) : continue; endif; ?>
							<li data-id="<?php echo esc_attr( $one ); ?>">
								<img src="<?php echo esc_url( $thumb ); ?>" alt="">
								<button type="button" class="vesla-gal-x" aria-label="<?php esc_attr_e( 'Remove this photograph', 'vesla-landing' ); ?>">&times;</button>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="vesla-gal-empty"<?php echo $ids ? ' hidden' : ''; ?>>
						<?php esc_html_e( 'No photographs yet.', 'vesla-landing' ); ?>
					</p>
					<div class="vesla-gal-act">
						<button type="button" class="button vesla-gal-add"><?php esc_html_e( 'Add photographs', 'vesla-landing' ); ?></button>
						<span class="vesla-gal-n"></span>
					</div>
					<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
					       value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" class="vesla-gal-ids">
				</div>
				<?php
				break;

			case 'car':
				/* A select of the cars in stock, storing the car's own id. Used
				   by the featured strip, where the order of the rows is the
				   order they appear in -- which is why this is a repeater of
				   selects rather than a list of tickboxes. Tickboxes cannot be
				   put in an order. */
				$car_list = Vesla_Rest::cars();
				$car_value = (string) $value;
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="vesla-input">
					<option value=""><?php esc_html_e( '— choose a car —', 'vesla-landing' ); ?></option>
					<?php foreach ( (array) $car_list as $one ) : ?>
						<?php
						$one_id    = (string) ( isset( $one['id'] ) ? $one['id'] : '' );
						$one_label = trim(
							( ! empty( $one['year'] ) ? $one['year'] . ' ' : '' )
							. ( isset( $one['make'] ) ? $one['make'] : '' ) . ' '
							. ( isset( $one['model'] ) ? $one['model'] : '' )
						);
						if ( '' === $one_id ) { continue; }
						?>
						<option value="<?php echo esc_attr( $one_id ); ?>" <?php selected( $car_value, $one_id ); ?>>
							<?php echo esc_html( $one_label ); ?>
						</option>
					<?php endforeach; ?>
					<?php
					/* A car that has been sold or deleted since it was featured.
					   Kept and named rather than dropped, so the row does not
					   silently become a different car when somebody saves. */
					if ( '' !== $car_value && ! in_array( $car_value, array_map( 'strval', wp_list_pluck( (array) $car_list, 'id' ) ), true ) ) :
						?>
						<option value="<?php echo esc_attr( $car_value ); ?>" selected>
							<?php
							printf(
								/* translators: %s: a car's reference number. */
								esc_html__( 'Car %s — no longer in stock', 'vesla-landing' ),
								esc_html( $car_value )
							);
							?>
						</option>
					<?php endif; ?>
				</select>
				<?php
				break;

			case 'brand':
				/* A select of the brands that exist, not a box to type a make
				   into. The order is deliberate: a brand is created once on
				   the Car brands screen, with its logo, and a car then picks
				   from that list. Typing the make on the car was the other way
				   round -- it invented brands as a side effect of saving a
				   car, which is how "Porche" becomes a second marque nobody
				   meant to create.

				   What is STORED is unchanged: the brand's name, as a string,
				   exactly as the text field stored it. Everything downstream
				   -- the grid, the filters, the strip, the published data, the
				   term the car is attached to on save -- reads that string and
				   none of it had to change. */
				$brand_terms = get_terms( array(
					'taxonomy'   => 'vesla_make',
					'hide_empty' => false,
					'fields'     => 'names',
				) );
				if ( is_wp_error( $brand_terms ) ) {
					$brand_terms = array();
				}
				$brand_value = (string) $value;
				$brand_link  = admin_url( 'edit-tags.php?taxonomy=vesla_make&post_type=vesla_vehicle' );
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="vesla-input">
					<option value=""><?php esc_html_e( '— choose a brand —', 'vesla-landing' ); ?></option>
					<?php foreach ( $brand_terms as $brand_name ) : ?>
						<option value="<?php echo esc_attr( $brand_name ); ?>" <?php selected( $brand_value, $brand_name ); ?>>
							<?php echo esc_html( $brand_name ); ?>
						</option>
					<?php endforeach; ?>
					<?php
					/* A make this car already holds that is not on the list --
					   a brand renamed or deleted since. Kept and marked rather
					   than dropped: silently changing what a car is because
					   somebody opened its screen would be the worst kind of
					   data loss, the kind nobody notices. */
					if ( '' !== $brand_value && ! in_array( $brand_value, (array) $brand_terms, true ) ) :
						?>
						<option value="<?php echo esc_attr( $brand_value ); ?>" selected>
							<?php
							printf(
								/* translators: %s: the make stored on this car. */
								esc_html__( '%s — no longer in the brand list', 'vesla-landing' ),
								esc_html( $brand_value )
							);
							?>
						</option>
					<?php endif; ?>
				</select>
				<?php if ( ! $brand_terms ) : ?>
					<p class="vesla-help">
						<?php
						printf(
							/* translators: 1: opening link tag. 2: closing link tag. */
							esc_html__( 'There are no brands yet. %1$sAdd one under Car brands%2$s — with its logo — and it will be on this list.', 'vesla-landing' ),
							'<a href="' . esc_url( $brand_link ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
							'</a>'
						);
						?>
					</p>
				<?php endif; ?>
				<?php
				break;

			case 'select':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="vesla-input">
					<?php foreach ( $def['choices'] as $ck => $cl ) : ?>
						<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $value, $ck ); ?>>
							<?php echo esc_html( $cl ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'number':
				?>
				<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
				       value="<?php echo esc_attr( $value ); ?>" class="vesla-input vesla-input--num"
				       <?php echo isset( $def['min'] ) ? 'min="' . esc_attr( $def['min'] ) . '"' : ''; ?>
				       <?php echo isset( $def['max'] ) ? 'max="' . esc_attr( $def['max'] ) . '"' : ''; ?>
				       step="1" inputmode="numeric">
				<?php
				break;

			case 'color':
				?>
				<span class="vesla-color">
					<input type="color" id="<?php echo esc_attr( $id ); ?>_pick"
					       value="<?php echo esc_attr( $value ? $value : '#000000' ); ?>"
					       class="vesla-color-pick" aria-label="<?php esc_attr_e( 'Pick a colour', 'vesla-landing' ); ?>">
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
					       value="<?php echo esc_attr( $value ); ?>" class="vesla-input vesla-input--hex"
					       spellcheck="false" placeholder="#000000">
				</span>
				<?php
				break;

			case 'image':
				$img_id  = absint( $value );
				$preview = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
				/* a bundled file is shown until a real media item is chosen */
				if ( ! $preview && ! empty( $def['default_file'] ) ) {
					$preview = VESLA_URL . $def['default_file'];
				}
				?>
				<div class="vesla-image" data-vesla-image>
					<div class="vesla-image-preview<?php echo $preview ? '' : ' is-empty'; ?>">
						<?php if ( $preview ) : ?>
							<img src="<?php echo esc_url( $preview ); ?>" alt="">
						<?php else : ?>
							<span><?php esc_html_e( 'No image chosen', 'vesla-landing' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="vesla-image-act">
						<button type="button" class="button vesla-image-pick"><?php esc_html_e( 'Choose image', 'vesla-landing' ); ?></button>
						<button type="button" class="button-link vesla-image-clear"<?php echo $img_id ? '' : ' hidden'; ?>>
							<?php esc_html_e( 'Remove', 'vesla-landing' ); ?>
						</button>
					</div>
					<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
					       value="<?php echo esc_attr( $img_id ? $img_id : '' ); ?>" class="vesla-image-id">
				</div>
				<?php
				break;

			case 'video':
				$vid_id   = absint( $value );
				$vid_src  = $vid_id ? wp_get_attachment_url( $vid_id ) : '';
				$vid_path = $vid_id ? get_attached_file( $vid_id ) : '';
				$vid_size = ( $vid_path && file_exists( $vid_path ) ) ? (int) filesize( $vid_path ) : 0;
				$vid_warn  = ! empty( $def['warn_bytes'] ) ? (int) $def['warn_bytes'] : 0;
				$vid_heavy = ! empty( $def['heavy_bytes'] ) ? (int) $def['heavy_bytes'] : 0;
				$vid_cap   = ! empty( $def['max_bytes'] ) ? (int) $def['max_bytes'] : 26214400;
				?>
				<div class="vesla-image" data-vesla-image data-vesla-media="video">
					<div class="vesla-image-preview<?php echo $vid_src ? '' : ' is-empty'; ?>">
						<?php if ( $vid_src ) : ?>
							<?php /* Muted and with controls: this is the one place the film
							         should be scrubbable, because it is the only place anybody
							         is looking at it as a file rather than as a backdrop. */ ?>
							<video src="<?php echo esc_url( $vid_src ); ?>" muted playsinline controls preload="metadata"></video>
						<?php else : ?>
							<span><?php esc_html_e( 'No film chosen', 'vesla-landing' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="vesla-image-act">
						<button type="button" class="button vesla-image-pick"><?php esc_html_e( 'Choose film', 'vesla-landing' ); ?></button>
						<button type="button" class="button-link vesla-image-clear"<?php echo $vid_id ? '' : ' hidden'; ?>>
							<?php esc_html_e( 'Remove', 'vesla-landing' ); ?>
						</button>
					</div>
					<?php if ( $vid_size ) : ?>
						<?php /* Three states, and the wording of each is measured rather
						         than guessed: the figures come from timing this page at
						         four film sizes on a throttled connection. */ ?>
						<p class="vesla-field-note<?php echo ( $vid_warn && $vid_size > $vid_warn ) ? ' is-warn' : ''; ?>">
							<?php
							if ( $vid_heavy && $vid_size > $vid_heavy ) {
								printf(
									/* translators: 1: the file's size. 2: the size above which this warns strongly. 3: the hard limit. */
									esc_html__( 'This film is %1$s, which is above %2$s and near the %3$s limit. Measured on this page, a film this size first appears roughly 20 to 24 seconds into a visit on a slow connection, and 6 to 7 seconds on 4G. Until then the poster is what people see — nothing is broken, but this is a film most visitors will never watch move.', 'vesla-landing' ),
									esc_html( size_format( $vid_size, 1 ) ),
									esc_html( size_format( $vid_heavy ) ),
									esc_html( size_format( $vid_cap ) )
								);
							} elseif ( $vid_warn && $vid_size > $vid_warn ) {
								printf(
									/* translators: 1: the file's size. 2: the size above which this warns. */
									esc_html__( 'This film is %1$s, above the %2$s comfortable mark. Measured on this page it first appears around 14 seconds into a visit on a slow connection, against about 8 seconds for a small clip. It plays muted with no controls, so quality past "recognisable" is paid for and not seen.', 'vesla-landing' ),
									esc_html( size_format( $vid_size, 1 ) ),
									esc_html( size_format( $vid_warn ) )
								);
							} else {
								printf(
									/* translators: 1: the file's size. 2: the hard limit. */
									esc_html__( 'This film is %1$s, comfortably inside the %2$s limit — it will be playing while people are still reading the heading.', 'vesla-landing' ),
									esc_html( size_format( $vid_size, 1 ) ),
									esc_html( size_format( $vid_cap ) )
								);
							}
							?>
						</p>
					<?php endif; ?>
					<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
					       value="<?php echo esc_attr( $vid_id ? $vid_id : '' ); ?>" class="vesla-image-id">
				</div>
				<?php
				break;

			case 'email':
			case 'url':
			case 'tel':
			case 'text':
			default:
				$input_type = ( 'email' === $type ) ? 'email' : 'text';

				/* The datalist that briefly lived here is gone with the field
				   it served. It let the Make be typed with suggestions, which
				   was the right fix for the wrong design: a car should not be
				   able to invent a brand at all. The 'brand' case above is a
				   list of what exists and nothing else. */
				?>
				<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $id ); ?>"
				       name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
				       class="vesla-input">
				<?php
				break;
		}
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   REPEATER
	   ═══════════════════════════════════════════════════════════════════════ */

	private static function repeater( $name, $value, $def, $id ) {
		$rows      = is_array( $value ) ? $value : array();
		$row_label = isset( $def['row_label'] ) ? $def['row_label'] : __( 'Item', 'vesla-landing' );
		?>
		<div class="vesla-rep" data-vesla-rep data-name="<?php echo esc_attr( $name ); ?>"
		     data-row-label="<?php echo esc_attr( $row_label ); ?>">

			<div class="vesla-rep-head">
				<h3><?php echo esc_html( $def['label'] ); ?></h3>
				<span class="vesla-rep-count"></span>
			</div>
			<?php if ( ! empty( $def['help'] ) ) : ?>
				<p class="vesla-help vesla-help--block"><?php echo esc_html( $def['help'] ); ?></p>
			<?php endif; ?>

			<div class="vesla-rep-rows">
				<?php foreach ( $rows as $i => $row ) : ?>
					<?php self::repeater_row( $name, $def, $i, $row, $id ); ?>
				<?php endforeach; ?>
			</div>

			<p class="vesla-rep-empty"<?php echo $rows ? ' hidden' : ''; ?>>
				<?php esc_html_e( 'Nothing here yet — press the button below to add the first one.', 'vesla-landing' ); ?>
			</p>

			<button type="button" class="button vesla-rep-add">
				<?php
				/* translators: %s: the kind of thing being added, e.g. "Car" */
				printf( esc_html__( '+ Add %s', 'vesla-landing' ), esc_html( strtolower( $row_label ) ) );
				?>
			</button>

			<!-- The blank row the Add button clones. `__i__` is replaced with the
			     next index in JavaScript; keeping it as markup here means a new
			     row is built from exactly the same code as a saved one. -->
			<script type="text/html" class="vesla-rep-tpl">
				<?php self::repeater_row( $name, $def, '__i__', array(), $id, true ); ?>
			</script>
		</div>
		<?php
	}

	private static function repeater_row( $name, $def, $index, $row, $id_base, $is_template = false ) {
		$title_keys = isset( $def['row_title'] ) ? $def['row_title'] : array();
		$title      = '';
		foreach ( $title_keys as $tk ) {
			if ( ! empty( $row[ $tk ] ) ) {
				$title .= ( $title ? ' ' : '' ) . $row[ $tk ];
			}
		}
		$row_label = isset( $def['row_label'] ) ? $def['row_label'] : __( 'Item', 'vesla-landing' );
		?>
		<div class="vesla-rep-row" data-rep-row>
			<div class="vesla-rep-row-head">
				<button type="button" class="vesla-rep-drag" aria-label="<?php esc_attr_e( 'Drag to reorder', 'vesla-landing' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'vesla-landing' ); ?>">⋮⋮</button>
				<span class="vesla-rep-n"></span>
				<span class="vesla-rep-title" data-rep-title data-keys="<?php echo esc_attr( implode( ',', $title_keys ) ); ?>">
					<?php echo esc_html( $title ? $title : $row_label ); ?>
				</span>
				<button type="button" class="vesla-rep-toggle" aria-expanded="false">
					<?php esc_html_e( 'Edit', 'vesla-landing' ); ?>
				</button>
				<button type="button" class="vesla-rep-del" aria-label="<?php esc_attr_e( 'Remove', 'vesla-landing' ); ?>" title="<?php esc_attr_e( 'Remove', 'vesla-landing' ); ?>">×</button>
			</div>

			<div class="vesla-rep-row-body" hidden>
				<?php
				/* Thirty fields in one column is a wall, and a wall is what stops
				   somebody filling a car in properly. A field may name the group it
				   belongs to, and the heading is printed the first time that group
				   appears -- so the order of the schema is the order of the form and
				   there is no second list to keep in step with it. */
				$group = '';
				foreach ( $def['fields'] as $sub_key => $sub_def ) {
					if ( ! empty( $sub_def['group'] ) && $sub_def['group'] !== $group ) {
						$group = $sub_def['group'];
						printf( '<p class="vesla-rep-group">%s</p>', esc_html( $group ) );
					}
					$sub_name  = $name . '[' . $index . '][' . $sub_key . ']';
					$sub_value = isset( $row[ $sub_key ] ) ? $row[ $sub_key ] : ( isset( $sub_def['default'] ) ? $sub_def['default'] : '' );
					$sub_id    = $id_base . '_' . $index . '_' . $sub_key;
					?>
					<div class="vesla-row vesla-row--<?php echo esc_attr( $sub_def['type'] ); ?>" data-sub="<?php echo esc_attr( $sub_key ); ?>">
						<label class="vesla-label" for="<?php echo esc_attr( $sub_id ); ?>"><?php echo esc_html( $sub_def['label'] ); ?></label>
						<div class="vesla-control">
							<?php self::control( $sub_name, $sub_value, $sub_def, $sub_id ); ?>
							<?php if ( ! empty( $sub_def['help'] ) ) : ?>
								<p class="vesla-help"><?php echo esc_html( $sub_def['help'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<?php
				}
				/* the bundled-photo path travels with the row so seeded images
				   are not lost the first time the admin presses Save */
				if ( isset( $row['photo_file'] ) ) {
					printf(
						'<input type="hidden" name="%s" value="%s">',
						esc_attr( $name . '[' . $index . '][photo_file]' ),
						esc_attr( $row['photo_file'] )
					);
				}
				?>
			</div>
		</div>
		<?php
	}
}

/* ========================================================================== */
/*  5 · RENDERING THE PAGE
 *
 *  Builds the markup, the head tags and the structured data. Holds no content of its own.
 */
/* ========================================================================== */

class Vesla_Render {
	/**
	 * Echo a stored value, escaped according to what the schema says it is.
	 *
	 * A 'rich' field is printed as markup so the administrator's bold word and
	 * in-copy link survive to the page -- which is the point: a link a crawler
	 * can follow has to be a real <a href> in the HTML, not something a script
	 * inserts later. Everything else is escaped as text, exactly as before.
	 *
	 * Passing the path rather than a flag is what keeps this honest: the
	 * decision lives in the schema, so a field promoted to 'rich' later needs
	 * no change here.
	 */
	public static function t( $path, $value ) {
		if ( 'rich' === Vesla_Schema::type_of( $path ) ) {
			echo Vesla_Schema::rich( $value ); // phpcs:ignore WordPress.Security.EscapeOutput -- kses'd against the allowlist above.
			return;
		}
		echo esc_html( (string) $value );
	}

	/**
	 * The same value with every tag removed.
	 *
	 * For the meta description and the JSON-LD, where markup is not content: a
	 * <strong> inside acceptedAnswer.text is a defect Google reports, and a
	 * description tag containing tags gets rewritten or dropped.
	 */
	public static function plain( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	public static function init() {
		add_shortcode( 'vesla_landing', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'analytics_tag' ), 4 );
		add_action( 'wp_head', array( __CLASS__, 'car_head' ), 5 );
		add_filter( 'document_title_parts', array( __CLASS__, 'car_title' ) );

		/* WordPress writes its own canonical, pointing at wherever it happens to
		   be installed. Ours points at the address the site is actually published
		   under, and two canonicals on one page is worse than none: a crawler
		   shown both is entitled to trust neither. Removed only on this page, so
		   every other page on the site keeps core's. */
		add_action(
			'template_redirect',
			static function () {
				if ( ( self::is_landing() || self::is_vehicle() ) && Vesla_Settings::get( 'seo', 'enabled', 0 ) ) {
					remove_action( 'wp_head', 'rel_canonical' );
				}
			}
		);
		add_action( 'wp_head', array( __CLASS__, 'loader_boot' ), 6 );

		/* The full-width template. Registered for every page so it can be
		   chosen by hand in the editor, and loaded from the plugin folder
		   because the theme has no idea it exists. */
		self::route();
		add_filter( 'theme_page_templates', array( __CLASS__, 'register_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ) );
	}
	/** Is the shortcode on the page being shown? Assets load only if so. */
	/* =======================================================================
	   ONE PAGE PER VEHICLE
	   /vehicle/audi-tt-rs-2018-577/
	   A real URL, because a car somebody wants is a thing
	   they send to a friend, bookmark, or come back to next week — and a state
	   inside another page is none of those. It is also the only version a
	   search engine can index: a listing that exists only after a click is a
	   listing that exists only for people who already found the site.
	   The id is on the end of the slug on purpose. Two cars can be the same
	   make, model and year, and the id is what tells them apart; put it first
	   and the URL reads as a number, put it last and it reads as a car.
	   ======================================================================= */
	/* =======================================================================
	   THE VEHICLE PAGE
	   ======================================================================= */
	/**
	 * Everything known about one car, on its own page.
	 *
	 * Rendered on the server, not fetched. This is the page a search engine
	 * indexes and a link preview reads, and both of them see markup, not the
	 * result of a script they never ran.
	 */
	public static function vehicle_page() {
		$car = self::current();
		if ( ! $car ) {
			return;
		}

		$photos = self::car_photos( $car );
		$fills  = self::car_photo_fills( $car );

		$labels = self::car_spec_rows();

		$name   = trim( ( $car['year'] ? $car['year'] . ' ' : '' ) . $car['make'] . ' ' . $car['model'] );

		$stock  = Vesla_Settings::get( 'stock' );
		$veh    = Vesla_Settings::get( 'vehicle' );

		$cur    = (string) $stock['currency'];

		$rows = array();
		foreach ( $labels as $row ) {
			$k = $row['key'];
			if ( 'features' === $k ) {
				continue;
			}
			$v = isset( $car[ $k ] ) ? $car[ $k ] : '';
			if ( '' === $v || 0 === $v || '0' === $v ) {
				continue;
			}
			if ( 'km' === $k ) {
				$v = number_format_i18n( (int) $v ) . ' km';
			} elseif ( 'power' === $k ) {
				$v = $v . ' hp';
			} elseif ( 'engine_cc' === $k ) {
				$v = number_format_i18n( (int) $v ) . ' cc';
			} elseif ( 'seats' === $k ) {
				$v = $v . ' ' . self::setting( $car, 'seats_label' );
			}
			$rows[ $row['label'] ] = (string) $v;
		}
		$features = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $car['features'] ) ) ) );
		?>
		<div class="veh-page">
			<?php self::header_bar(); ?>

			<main id="main">
				<div class="shell vp-top">
					<?php /* The same three steps the structured data publishes, shown on
					         the page as well: a reader arriving from a search result has
					         no idea where in the site they have landed, and one arrow
					         labelled "All cars" does not tell them. */ ?>
					<nav class="vp-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'vesla-landing' ); ?>">
						<a href="<?php echo esc_url( self::site_link() ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
						<span aria-hidden="true">/</span>
						<a class="vp-back" href="<?php echo esc_url( self::site_link( '#stock' ) ); ?>">
							<?php echo esc_html( self::setting( $car, 'back_label' ) ); ?>
						</a>
						<span aria-hidden="true">/</span>
						<?php /* The page you are on is not a link to itself. */ ?>
						<span aria-current="page"><?php echo esc_html( $name ); ?></span>
					</nav>
				</div>

				<div class="shell vp-grid">
					<!-- ── the photographs ─────────────────────────────── -->
					<div class="vp-gal<?php echo count( $photos ) > 1 ? ' has-many' : ''; ?>">
						<div class="vp-stage" data-count="<?php echo esc_attr( count( $photos ) ); ?>">
							<?php if ( $photos ) : ?>
								<?php foreach ( $photos as $i => $src ) : ?>
									<?php
									/* The backdrop. Decorative by definition -- it is the same
									   picture as the one in front of it, so announcing it would
									   read the car out twice. Absolutely positioned inside a box
									   that already has its aspect ratio, so it moves nothing:
									   layout shift on this page stays at zero.
									
									   Nothing is drawn where there is no photograph. Twenty-four
									   of twenty-five cars have none, and a blurred backdrop of
									   nothing is just a grey box with a filter on it. */
									$fill = isset( $fills[ $i ] ) && '' !== $fills[ $i ] ? $fills[ $i ] : $src;
									?>
									<?php
									/* Only the first pair carries a src.
									
									   loading="lazy" does nothing for these: every slide is stacked on
									   top of the first, absolutely positioned and inside the viewport, so
									   the browser considers all of them visible and fetched all twenty at
									   once -- two megabytes to show one photograph.
									
									   The rest carry the address in data-src, and vehicle.js loads a slide
									   as it is reached along with the one after it. The markup still names
									   every photograph, so nothing is hidden from a crawler. */
									$now = ( 0 === $i );
									?>
									<img<?php echo $now ? ' src="' . esc_url( $fill ) . '"' : ''; ?>
									     data-src="<?php echo esc_url( $fill ); ?>"
									     alt="" aria-hidden="true" role="presentation"
									     class="vp-fill<?php echo $now ? ' is-on' : ''; ?>"
									     width="1200" height="800" decoding="async">
									<img<?php echo $now ? ' src="' . esc_url( $src ) . '" fetchpriority="high"' : ''; ?>
									     data-src="<?php echo esc_url( $src ); ?>"
									     alt="<?php echo esc_attr( $name . ' — ' . ( $i + 1 ) ); ?>"
									     class="vp-shot<?php echo $now ? ' is-on' : ''; ?>"
									     width="1200" height="800" decoding="async">
								<?php endforeach; ?>
							<?php else : ?>
								<?php /* A letter alone in a large empty frame reads as a picture that
								         failed to load. The shield and a line of text say what is
								         actually true: the car is here, the photographs are not yet. */ ?>
								<div class="ph vp-nophoto">
									<?php echo self::logo_img( 'class="vp-nophoto-mark" aria-hidden="true"', 86 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
									<?php if ( $veh['no_photo_text'] ) : ?>
										<p><?php echo esc_html( $veh['no_photo_text'] ); ?></p>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<?php if ( count( $photos ) > 1 ) : ?>
								<button type="button" class="vp-nav vp-prev" aria-label="<?php esc_attr_e( 'Previous photograph', 'vesla-landing' ); ?>">
									<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4l-8 8 8 8"/></svg>
								</button>
								<button type="button" class="vp-nav vp-next" aria-label="<?php esc_attr_e( 'Next photograph', 'vesla-landing' ); ?>">
									<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4l8 8-8 8"/></svg>
								</button>
								<span class="vp-count">1 / <?php echo (int) count( $photos ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( count( $photos ) > 1 ) : ?>
							<div class="vp-thumbs">
								<?php foreach ( $photos as $i => $src ) : ?>
									<button type="button" class="vp-th<?php echo 0 === $i ? ' is-on' : ''; ?>" aria-label="<?php echo esc_attr( $i + 1 ); ?>">
										<img src="<?php echo esc_url( isset( $fills[ $i ] ) && '' !== $fills[ $i ] ? $fills[ $i ] : $src ); ?>"
											     alt="" width="160" height="120" loading="lazy" decoding="async">
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php
						/* The video, if this car has one.
						 *
						 * A facade, not an iframe: an embedded player pulls several hundred
						 * kilobytes and sets third-party cookies on every visitor, including
						 * the ones who never press play. Nothing of YouTube's is loaded until
						 * somebody asks for it, so a car page with a video costs the same as
						 * one without until it is wanted.
						 *
						 * A link rather than a button, and one that works on its own: with no
						 * scripting it opens the video where it lives, which is the whole
						 * behaviour, just somewhere else.
						 */
						$video = self::video_embed( isset( $car['video'] ) ? $car['video'] : '' );
						?>
						<?php if ( $video ) : ?>
							<div class="vp-video">
								<a class="vp-video-go" href="<?php echo esc_url( $video['watch'] ); ?>"
								   data-embed="<?php echo esc_url( $video['embed'] ); ?>"
								   target="_blank" rel="noopener">
									<span class="vp-video-play" aria-hidden="true">
										<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
									</span>
									<span class="vp-video-txt"><?php echo esc_html( Vesla_Settings::get( 'vehicle', 'video_label', __( 'Watch the video', 'vesla-landing' ) ) ); ?></span>
								</a>
							</div>
						<?php endif; ?>
					</div>

					<!-- ── the facts ───────────────────────────────────── -->
					<aside class="vp-side">
						<?php /* The badge, the note under the price and the warranty line all
						         appear on the card in the grid. A reader who pressed that card
						         to find out more should not arrive somewhere that says less
						         about the car than the card did. */ ?>
						<?php if ( $stock['badge'] || $car['ref'] ) : ?>
							<p class="vp-tags">
								<?php if ( $stock['badge'] ) : ?>
									<span class="vp-badge"><?php echo esc_html( $stock['badge'] ); ?></span>
								<?php endif; ?>
								<?php if ( $car['ref'] ) : ?>
									<span class="vp-ref"><?php echo esc_html( $car['ref'] ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>

						<h1 class="vp-title"><?php echo esc_html( $name . ( $car['trim'] ? ' ' . $car['trim'] : '' ) ); ?></h1>

						<?php if ( $car['price'] ) : ?>
							<p class="vp-price">
								<?php echo esc_html( $cur . ' ' . number_format_i18n( (int) $car['price'] ) ); ?>
								<?php if ( $stock['price_note'] ) : ?>
									<small><?php echo esc_html( $stock['price_note'] ); ?></small>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<?php if ( $stock['warranty_note'] ) : ?>
							<p class="vp-warranty"><?php echo esc_html( $stock['warranty_note'] ); ?></p>
						<?php endif; ?>

						<ul class="vp-head">
							<?php foreach ( array( 'year', 'km', 'steering', 'spec' ) as $k ) : ?>
								<?php if ( empty( $car[ $k ] ) ) { continue; } ?>
								<li>
									<span><?php echo esc_html( self::spec_label( $k ) ); ?></span>
									<b><?php echo esc_html( 'km' === $k ? number_format_i18n( (int) $car[ $k ] ) . ' km' : $car[ $k ] ); ?></b>
								</li>
							<?php endforeach; ?>
						</ul>

						<div class="vp-act">
							<a class="btn btn-solid" href="<?php echo esc_url( self::site_link( '#contact' ) ); ?>"
							   data-car="<?php echo esc_attr( $name ); ?>">
								<?php echo esc_html( $stock['enquire_label'] ); ?>
							</a>
							<?php $wa = preg_replace( '/\D/', '', (string) Vesla_Settings::get( 'brand', 'whatsapp', '' ) ); ?>
							<?php if ( $wa ) : ?>
								<a class="btn btn-line" target="_blank" rel="noopener"
								   href="<?php echo esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( sprintf( '%s — %s', $name, self::permalink( $car ) ) ) ); ?>">WhatsApp</a>
							<?php endif; ?>
						</div>
					</aside>
				</div>

				<!-- ── the detail ────────────────────────────────────── -->
				<div class="shell vp-detail">
					<?php /* The specification and the calculator side by side. They are the
					         two things a buyer reads together - "what is it" and "what would
					         it cost me a month" - and stacking them put a screen of empty
					         space between the question and the answer. */ ?>
					<div class="vp-pair">
					<?php if ( $rows ) : ?>
						<section class="vp-block vp-over">
							<h2><?php echo esc_html( self::setting( $car, 'overview_title' ) ); ?></h2>
							<dl class="vp-spec">
								<?php foreach ( $rows as $label => $value ) : ?>
									<div class="vp-row"><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
								<?php endforeach; ?>
							</dl>
						</section>
					<?php endif; ?>

					<?php if ( self::setting( $car, 'finance_enabled' ) && $car['price'] ) : ?>
						<?php /* The rate, the deposit and the term travel on the section,
						         because they can differ per car. The script falls back to the
						         site-wide figures only where a car has not answered. */ ?>
						<section class="vp-block vp-fin"
							data-price="<?php echo esc_attr( (int) $car['price'] ); ?>"
							data-rate="<?php echo esc_attr( self::setting( $car, 'finance_rate' ) ); ?>"
							data-down="<?php echo esc_attr( self::setting( $car, 'finance_down_pct' ) ); ?>"
							data-years="<?php echo esc_attr( self::setting( $car, 'finance_years' ) ); ?>"
							data-note="<?php echo esc_attr( self::setting( $car, 'finance_note' ) ); ?>">
							<h2><?php echo esc_html( self::setting( $car, 'finance_title' ) ); ?></h2>
							<div class="vp-fin-in"></div>
						</section>
					<?php endif; ?>
					</div>
					<?php if ( $car['summary'] ) : ?>
						<section class="vp-block vp-about">
							<?php echo Vesla_Schema::rich( $car['summary'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- kses'd against the allowlist. ?>
						</section>
					<?php endif; ?>

					<?php if ( $features ) : ?>
						<section class="vp-block">
							<h2><?php echo esc_html( self::setting( $car, 'features_title' ) ); ?></h2>
							<ul class="vp-feat">
								<?php foreach ( $features as $f ) : ?>
									<li><?php echo esc_html( $f ); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endif; ?>

				</div>
			</main>

			<?php /* No map here. A car's page is about the car; where the showroom
			         is belongs on the front page, and repeating a full map under
			         every one of twenty-four cars pushed the footer off the screen
			         and loaded a tile server for a question nobody asked here.
			         The address and the telephone number are in the footer. */ ?>
			<?php self::footer(); ?>

		</div>

		<?php
	}

	/** The customer-facing label for one car field. */

	/* =======================================================================
	   A CAR'S OWN PAGE, FOR MACHINES

	   Everything below describes one car to a crawler or a chat app: its
	   canonical address, what a shared link looks like, where it sits in
	   the site, and the structured record behind the rich result.

	   The rule running through all of it is that a missing value is left
	   out, never emitted empty. Twenty-four of the twenty-five cars have
	   no photograph; an og:image pointing at nothing turns a large share
	   card into a broken one, and an empty image in the structured data
	   fails validation outright. No value is better than a wrong one.
	   ======================================================================= */


	/**
	 * The car's name in the browser tab, not the page's.
	 *
	 * Every car address resolves to the one Vehicles page, so WordPress builds
	 * the document title from the queried object and every car came out titled
	 * “Vehicles”. Twenty-four pages sharing one title is not a cosmetic
	 * problem: it is the single line a search result shows, and identical
	 * titles are how a crawler decides pages are duplicates of each other.
	 *
	 * The same string as og:title, from the same place, so a shared link and a
	 * search result cannot disagree about what the page is.
	 */
	public static function car_title( $parts ) {
		$car = self::current();
		if ( ! self::is_vehicle() || ! $car ) {
			return $parts;
		}
		$parts['title'] = trim(
			( $car['year'] ? $car['year'] . ' ' : '' ) . $car['make'] . ' ' . $car['model']
			. ( $car['trim'] ? ' ' . $car['trim'] : '' )
		);
		unset( $parts['tagline'] );
		return $parts;
	}

	/**
	 * A Vehicle-pages setting, as it applies to ONE car.
	 *
	 * The section under Vehicle pages is the answer for every car. A car may
	 * answer any of them again for itself, and blank means “the one under
	 * Vehicle pages” — so a showroom that wants the same wording everywhere
	 * fills nothing in, and one that needs a different interest rate on a
	 * single car types it on that car and nowhere else.
	 *
	 * Blank rather than a copy of the default, deliberately: copying the
	 * default into every car would freeze it there, and changing the wording
	 * later would then change nothing on any car already saved.
	 *
	 * The two switches carry three states rather than two, because a toggle
	 * cannot say “whatever the site says” — only yes or no.
	 */
	public static function setting( $car, $key ) {
		$own = isset( $car[ 'v_' . $key ] ) ? $car[ 'v_' . $key ] : '';

		if ( 'on' === $own ) {
			return 1;
		}
		if ( 'off' === $own ) {
			return 0;
		}
		if ( '' !== $own && null !== $own ) {
			return $own;
		}
		return Vesla_Settings::get( 'vehicle', $key, '' );
	}

	/**
	 * Does this car have a page of its own?
	 *
	 * Asked before the route answers, before the card links to it, before it
	 * is written to the published site and before it goes in the sitemap — so
	 * switching one car off cannot leave a link pointing at a 404 behind it.
	 */
	public static function page_on( $car ) {
		return (bool) self::setting( $car, 'enabled' );
	}
	/** The identifier this car is known by everywhere in the graph. */
	/**
	 * A link to somewhere on the front page, from a car's page.
	 *
	 * Which site that is depends on who is being served: WordPress while it is
	 * answering a request, the published root while the publisher is writing
	 * files. Getting it wrong sends a reader of the static site into the admin
	 * installation, which is the one place they must never end up.
	 */
	public static function site_link( $fragment = '' ) {
		$base = self::$static_build
			? trailingslashit( Vesla_Publisher::site_url() )
			: home_url( '/' );
		return self::rel( $base . ltrim( $fragment, '/' ) );
	}
	public static function car_id( $car ) {
		return self::permalink( $car ) . '#car';
	}

	/**
	 * The sentence under a car in a search result, or beside a shared link.
	 *
	 * The admin's own words first. Failing that, the facts that are stored,
	 * in the order a buyer asks for them -- which is a real description of a
	 * real car, not filler. If there are no facts either, the caller falls
	 * back to the site description rather than printing an empty tag.
	 */
	public static function car_description( $car ) {
		$own = self::plain( isset( $car['summary'] ) ? $car['summary'] : '' );
		$own = trim( preg_replace( '/\s+/u', ' ', $own ) );
		if ( '' !== $own ) {
			/* Search engines cut this off around 160 characters and chat apps
			   sooner. Cut on a word so the preview does not end mid-syllable. */
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $own ) > 160 ) {
				$own = rtrim( mb_substr( $own, 0, 157 ) );
				$cut = mb_strrpos( $own, ' ' );
				if ( $cut > 100 ) {
					$own = mb_substr( $own, 0, $cut );
				}
				$own .= '…';
			}
			return $own;
		}

		$bits = array();
		if ( ! empty( $car['km'] ) ) {
			$bits[] = sprintf( __( '%s km', 'vesla-landing' ), number_format_i18n( (int) $car['km'] ) );
		}
		foreach ( array( 'trans', 'fuel', 'body', 'colour_out' ) as $k ) {
			if ( ! empty( $car[ $k ] ) ) {
				$bits[] = (string) $car[ $k ];
			}
		}
		if ( ! $bits ) {
			return '';
		}

		$name = trim( ( $car['year'] ? $car['year'] . ' ' : '' ) . $car['make'] . ' ' . $car['model'] );
		return sprintf(
			/* translators: 1: the car, 2: a comma separated list of its details. */
			__( '%1$s for sale in Dubai — %2$s.', 'vesla-landing' ),
			$name,
			implode( ', ', $bits )
		);
	}

	/**
	 * The spread of prices on the floor, as schema.org's priceRange.
	 *
	 * Sold cars are left out: they are not what the showroom sells for now.
	 * One car, or several at one price, gives a single figure rather than a
	 * range from a number to itself.
	 *
	 * @return string e.g. "AED 38,000 - AED 350,000", or '' when nothing is priced.
	 */
	public static function price_range() {
		$prices = array();
		foreach ( Vesla_Rest::cars() as $car ) {
			if ( ! empty( $car['sold'] ) ) {
				continue;
			}
			$price = isset( $car['price'] ) ? (int) $car['price'] : 0;
			if ( $price > 0 ) {
				$prices[] = $price;
			}
		}
		if ( ! $prices ) {
			return '';
		}

		$currency = trim( (string) Vesla_Settings::get( 'stock', 'currency', '' ) );
		$money    = static function ( $n ) use ( $currency ) {
			return trim( $currency . ' ' . number_format_i18n( $n ) );
		};

		$low  = min( $prices );
		$high = max( $prices );
		return $low === $high ? $money( $low ) : $money( $low ) . ' - ' . $money( $high );
	}

	/**
	 * The picture a shared link shows: this car's, or the site's, or none.
	 *
	 * Falling back to the site's own sharing image is deliberate. Almost none
	 * of the stock has been photographed yet, and a link with no picture at
	 * all is posted as a bare line of text -- the showroom's own image is a
	 * truthful stand-in, because it does not claim to be that car.
	 */
	public static function car_share_image( $car ) {
		if ( ! empty( $car['photo'] ) ) {
			$src = wp_get_attachment_image_src( absint( $car['photo'] ), 'large' );
			if ( $src ) {
				return array( 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2], 'own' => true );
			}
		}
		if ( ! empty( $car['photo_file'] ) ) {
			/* A sample shipped inside the plugin. Vesla_Rest::image() is what
			   measures those, and it caches the getimagesize() call -- which
			   matters here because wp_head runs on every request. */
			$img = Vesla_Rest::image( 0, $car['photo_file'] );
			if ( $img && ! empty( $img['url'] ) ) {
				return array( 'url' => $img['url'], 'width' => (int) $img['width'], 'height' => (int) $img['height'], 'own' => true );
			}
		}

		$id  = absint( Vesla_Settings::get( 'seo', 'share_image', 0 ) );
		$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
		if ( $src ) {
			return array( 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2], 'own' => false );
		}
		return null;
	}

	/**
	 * Everything in <head> that belongs to this one car.
	 *
	 * Runs instead of the landing page's head(), not as well as it: two
	 * canonicals, two og:titles and two dealer records on one page describe
	 * nothing usefully.
	 */
	public static function car_head() {
		$car = self::current();
		if ( ! $car || ! Vesla_Settings::get( 'seo', 'enabled', 0 ) ) {
			return;
		}

		$link  = self::permalink( $car );
		$site  = trailingslashit( Vesla_Publisher::site_url() );
		$name  = trim( ( $car['year'] ? $car['year'] . ' ' : '' ) . $car['make'] . ' ' . $car['model'] );
		$title = trim( $name . ' — ' . get_bloginfo( 'name' ) );
		$desc  = self::car_description( $car );
		if ( '' === $desc ) {
			$desc = (string) Vesla_Settings::get( 'seo', 'description', '' );
		}
		$share = self::car_share_image( $car );

		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $link ) );
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		printf( '<meta property="og:type" content="product">' . "\n" );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $link ) );

		if ( $share ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $share['url'] ) );
			if ( $share['width'] && $share['height'] ) {
				printf( '<meta property="og:image:width" content="%d">' . "\n", $share['width'] );
				printf( '<meta property="og:image:height" content="%d">' . "\n", $share['height'] );
			}
			/* The alt text says what the picture IS. When the picture is the
			   showroom's rather than this car's, saying so is the difference
			   between a stand-in and a misrepresentation. */
			$alt = $share['own']
				? trim( (string) ( isset( $car['photo_alt'] ) ? $car['photo_alt'] : '' ) )
				: sprintf( __( '%s showroom', 'vesla-landing' ), get_bloginfo( 'name' ) );
			if ( '' !== $alt ) {
				printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
				printf( '<meta name="twitter:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
			}
			printf( '<meta name="twitter:card" content="summary_large_image">' . "\n" );
			printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $share['url'] ) );
		} else {
			/* No picture anywhere. A summary card is the honest shape for a
			   link with nothing to show; summary_large_image reserves a panel
			   that would then be drawn empty. */
			printf( '<meta name="twitter:card" content="summary">' . "\n" );
		}
		printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $desc ) );

		/* ─ the structured record ─ */
		$currency = (string) Vesla_Settings::get( 'stock', 'currency', '' );

		$entry = array(
			'@type'         => 'Car',
			'@id'           => self::car_id( $car ),
			'name'          => $name,
			'url'           => $link,
			'itemCondition' => 'https://schema.org/UsedCondition',
		);
		if ( '' !== $desc ) {
			$entry['description'] = $desc;
		}
		if ( $car['make'] ) {
			$entry['brand'] = array( '@type' => 'Brand', 'name' => $car['make'] );
		}
		$simple = array(
			'model'               => 'model',
			'bodyType'            => 'body',
			'fuelType'            => 'fuel',
			'vehicleTransmission' => 'trans',
			'color'               => 'colour_out',
			'vehicleInteriorColor' => 'colour_in',
			'driveWheelConfiguration' => 'drive',
			'vehicleIdentificationNumber' => 'ref',
		);
		foreach ( $simple as $prop => $key ) {
			if ( ! empty( $car[ $key ] ) ) {
				$entry[ $prop ] = (string) $car[ $key ];
			}
		}
		$numbers = array(
			'vehicleModelDate'      => 'year',
			'seatingCapacity'       => 'seats',
			'numberOfDoors'         => 'doors',
			'numberOfPreviousOwners' => 'owners',
		);
		foreach ( $numbers as $prop => $key ) {
			if ( ! empty( $car[ $key ] ) ) {
				$entry[ $prop ] = 'year' === $key ? (string) (int) $car[ $key ] : (int) $car[ $key ];
			}
		}
		if ( ! empty( $car['km'] ) ) {
			$entry['mileageFromOdometer'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $car['km'],
				'unitCode' => 'KMT',
			);
		}
		if ( ! empty( $car['engine_cc'] ) ) {
			$entry['vehicleEngine'] = array(
				'@type'            => 'EngineSpecification',
				'engineDisplacement' => array(
					'@type'    => 'QuantitativeValue',
					'value'    => (int) $car['engine_cc'],
					'unitCode' => 'CMQ',
				),
			);
			if ( ! empty( $car['cylinders'] ) ) {
				$entry['vehicleEngine']['engineType'] = sprintf(
					/* translators: the number of cylinders. */
					__( '%d cylinder', 'vesla-landing' ),
					(int) $car['cylinders']
				);
			}
		}

		/* Omitted, not emitted empty: an image property holding "" is a
		   validation error, and a stand-in picture of the showroom is not a
		   picture OF THIS CAR -- which is what this property means. So the
		   share card may fall back and the structured record may not. */
		if ( $share && $share['own'] ) {
			$entry['image'] = $share['url'];
		}

		$features = array_values( array_filter( array_map( 'trim',
			preg_split( '/\r\n|\r|\n/', (string) $car['features'] ) ) ) );
		if ( $features ) {
			$entry['additionalProperty'] = array();
			foreach ( $features as $one ) {
				$entry['additionalProperty'][] = array(
					'@type' => 'PropertyValue',
					'name'  => $one,
					'value' => true,
				);
			}
		}

		if ( ! empty( $car['price'] ) && '' !== $currency ) {
			$entry['offers'] = array(
				'@type'         => 'Offer',
				'url'           => $link,
				'price'         => (int) $car['price'],
				'priceCurrency' => $currency,
				'availability'  => 'https://schema.org/InStock',
				'itemCondition' => 'https://schema.org/UsedCondition',
				/* The same dealer record the front page publishes, referenced
				   rather than repeated. */
				'seller'        => array( '@id' => $site . '#dealer' ),
			);
		}

		$crumbs = array(
			array( '@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo( 'name' ), 'item' => $site ),
			array( '@type' => 'ListItem', 'position' => 2, 'name' => (string) Vesla_Settings::get( 'stock', 'heading', __( 'Cars', 'vesla-landing' ) ), 'item' => $site . '#stock' ),
			/* The last crumb carries no item: it is the page you are on, and
			   a link to itself is what Google's own guidance says to leave out. */
			array( '@type' => 'ListItem', 'position' => 3, 'name' => $name ),
		);

		echo '<script type="application/ld+json">'
			. wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => array(
						$entry,
						array( '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs ),
					),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
			. '</script>' . "\n";
	}
	public static function spec_label( $key ) {
		foreach ( self::car_spec_rows() as $row ) {
			if ( $row['key'] === $key ) {
				return $row['label'];
			}
		}
		return $key;
	}

	const VAR = 'vesla_car';

	public static function route() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'page_state' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'bare_page' ) );

		/* The folder car pages live under is a setting, and a rewrite rule that
		   no longer matches it makes every car a 404 -- with nothing on screen
		   to say why. Flushed once, the first request after it changes. */
		add_action( 'init', static function () {
			if ( get_option( 'vesla_route_base' ) !== self::base() ) {
				self::sync_page();
				self::rewrite();
				flush_rewrite_rules( false );
				update_option( 'vesla_route_base', self::base(), false );
			}
		}, 20 );

		add_filter( 'query_vars', array( __CLASS__, 'query_var' ) );

		add_action( 'template_redirect', array( __CLASS__, 'maybe_404' ) );
	}

	/**
	 * The folder car pages answer under, cleaned.
	 *
	 * One value, read by the rewrite rule, by permalink(), by the sitemap and
	 * by the static build. They have to agree: a route at one path and links
	 * written at another is a site where every car is a 404.
	 */
	public static function base() {
		$base = sanitize_title( (string) Vesla_Settings::get( 'vehicle', 'base', '' ) );
		return '' === $base ? 'cars' : $base;
	}

	public static function rewrite() {
		/* page_id is what makes this a real page rather than a bare query:
			   WordPress resolves it, is_page() answers true, and the template
			   hierarchy runs the way it does for anything else in Pages. */
		$page = self::page_id();
		$q    = 'index.php?' . self::VAR . '=$matches[1]';
		if ( $page ) {
			$q .= '&page_id=' . $page;
		}
		add_rewrite_rule(
			'^' . preg_quote( self::base(), '/' ) . '/([^/]+)/?$',
			$q,
			'top'
		);
	}

	public static function query_var( $vars ) {
		$vars[] = self::VAR;

		return $vars;
	}

	/** The slug for one car. */

	public static function slug( $car ) {
		$parts = array( $car['make'], $car['model'], $car['year'], $car['id'] );

		return sanitize_title( implode( '-', array_filter( $parts, 'strlen' ) ) );
	}

	public static function permalink( $car ) {
		/* During a publish this has to be the address on the PUBLISHED site, not
		   on WordPress. The same call writes the card links, the canonical, the
		   og:url and every @id in the structured data -- so getting it right in
		   one place is the difference between a static site whose cars work and
		   one where every link goes back to the admin installation. */
		if ( self::$static_build ) {
			return self::public_permalink( $car );
		}
		return home_url( user_trailingslashit( self::base() . '/' . self::slug( $car ) ) );
	}

	/**
	 * The same address on the PUBLISHED site rather than on WordPress.
	 *
	 * The static files sit at the public root; WordPress sits at /cms. A link
	 * written from home_url() inside a published file would send every reader
	 * of the static site back into the admin installation.
	 */
	public static function public_permalink( $car ) {
		return trailingslashit( Vesla_Publisher::site_url() )
			. self::base() . '/' . self::slug( $car ) . '/';
	}

	/** The id at the end of a slug, or 0. */

	public static function id_from_slug( $slug ) {
		return (int) preg_replace( '/^.*?(\d+)$/', '$1', (string) $slug );
	}

	/** The car this request is for, or null. */
	/**
	 * The car this request is about.
	 *
	 * $forced exists for the publisher, which renders every car in one PHP
	 * process and has no query var to go by. It also defeats the static
	 * cache below, which would otherwise hand car two the same row as car
	 * one and publish twenty-five identical pages.
	 */
	public static $forced = null;

	public static function current() {
		if ( null !== self::$forced ) {
			return self::$forced;
		}
		static $car = false;
		if ( false !== $car ) {
			return $car;
		}
		$slug = get_query_var( self::VAR );
		$car  = null;
		if ( $slug ) {
			$id = self::id_from_slug( $slug );
			foreach ( Vesla_Store::cars() as $c ) {
				if ( (int) $c['id'] === $id ) {
					$car = $c;
					break;
				}
			}
		}
		return $car;
	}

	public static function is_vehicle() {
		return (bool) get_query_var( self::VAR );
	}

	/**
	 * A slug with no car behind it: 410 if we used to have it, 404 if not.
	 *
	 * READ THIS BEFORE REASONING ABOUT WHAT A SOLD CAR ANSWERS. This runs on
	 * a WordPress route, and on the published site WordPress never sees the
	 * request: a car's address is a folder of static HTML sitting in front of
	 * it, and the web server answers from disk without PHP being involved. So
	 * for public traffic -- which is all traffic that matters here -- none of
	 * what follows happens.
	 *
	 * What a sold car actually answers on the live site is build_sold(): a
	 * tombstone page saying "This car has been sold", served as an ordinary
	 * static file with status 200, carrying "noindex, follow" and a canonical
	 * pointing at itself, and left out of sitemap.xml. Not a 410. The status
	 * code is the web server's to give and it has an existing file to hand
	 * back, so 200 is the only thing it can say; the noindex is what takes the
	 * page out of the index instead, more slowly than a 410 would but without
	 * needing PHP in front of every car.
	 *
	 * This function is therefore reached only where WordPress answers the
	 * route itself -- previewing a car from the admin, and any deployment that
	 * stopped publishing static files. It is kept, and kept correct, because
	 * it is right for that case and because the two ways of retiring a car
	 * should not drift apart. The reasoning below is about that case.
	 *
	 * A sold car's address stays in search results and in people's messages
	 * for months. Answering 200 with nothing on it teaches a crawler the page
	 * is fine and keeps it in the index, so that is never right -- which is
	 * why the static tombstone carries a noindex rather than being an empty
	 * 200, and why deleting the folder outright would be worse than either.
	 *
	 * Between the other two:
	 *
	 *   410 for a car that HAS been published and is now gone. It means
	 *       “this existed and has been withdrawn”, and search engines drop a
	 *       410 markedly faster than a 404 -- which is what you want for a
	 *       car somebody else has already bought.
	 *
	 *   404 for a slug that was never ours: a typo, a guess, a stale link
	 *       from somewhere else. 410 there would be a claim we cannot make.
	 *
	 * NOT a redirect to the car list. A visitor asking “is this Hilux still
	 * available” is not answered by a page of other cars, and a crawler shown
	 * a hub in place of every retired listing treats those redirects as soft
	 * 404s anyway -- so it costs the honesty and buys nothing.
	 */
	public static function maybe_404() {
		$car = self::current();
		if ( ! self::is_vehicle() ) {
			return;
		}
		/* A car can have its own page switched off while staying in the grid
			   -- reserved, say. Its address then answers the same way a car we
			   never had does, because as far as the web is concerned there is no
			   page there. */
		if ( $car && self::page_on( $car ) ) {
			return;
		}
		$slug = (string) get_query_var( self::VAR );
		$gone = in_array( $slug, self::gone_slugs(), true );
		$code = $gone ? 410 : 404;

		global $wp_query;
		$wp_query->set_404();
		status_header( $code );
		nocache_headers();
	}

	/**
	 * Slugs this site has published a car page for and no longer has.
	 *
	 * Kept because a 410 is a statement about the past, and nothing else on
	 * the site remembers the past: the cars table holds what is on the floor
	 * today. Trimmed to the most recent few hundred, so a showroom that turns
	 * over its stock for years does not accumulate an unbounded option.
	 */
	const GONE_OPTION = 'vesla_gone_cars';

	public static function gone_slugs() {
		$gone = get_option( self::GONE_OPTION );
		return is_array( $gone ) ? $gone : array();
	}

	/**
	 * Note which published slugs have disappeared since last time.
	 *
	 * Called by the publisher, which is the only moment the site knows both
	 * what it used to show and what it shows now.
	 */
	public static function record_slugs( array $live ) {
		$was  = get_option( 'vesla_live_cars' );
		$was  = is_array( $was ) ? $was : array();
		$gone = self::gone_slugs();

		foreach ( array_diff( $was, $live ) as $slug ) {
			if ( ! in_array( $slug, $gone, true ) ) {
				$gone[] = $slug;
			}
		}
		/* A car that has come back -- relisted, or an id reused -- is not gone. */
		$gone = array_values( array_diff( $gone, $live ) );
		if ( count( $gone ) > 400 ) {
			$gone = array_slice( $gone, -400 );
		}

		update_option( 'vesla_live_cars', array_values( $live ), false );
		update_option( self::GONE_OPTION, $gone, false );
		return $gone;
	}

	/* =======================================================================
	   THE VEHICLES PAGE

	   One real page, in the Pages list, that every car address is served
	   through: /cars/toyota-hilux-2021-14/ loads the page whose slug is
	   “cars”, and the car is picked out of the address.

	   Why a page and not twenty-four of them: the cars are rows in a table
	   that changes weekly. Pages made from those rows would have to be
	   created, renamed and deleted to follow, the Pages list would fill with
	   entries nobody may safely edit, and every one of them would be a copy
	   of data that already exists somewhere else. One page cannot fall out
	   of step with the stock, because it holds none of it.

	   Why a page at all, rather than a rewrite rule on its own: with page_id
	   in the query WordPress is answering a real page. is_page() is true,
	   the template hierarchy runs, the admin can see the thing that serves
	   these addresses, and anything else on the site that expects a queried
	   object finds one.
	   ======================================================================= */

	const PAGE_OPTION = 'vesla_vehicle_page';

	/**
	 * The Vehicles page, made if it is not there.
	 *
	 * Remade if it has been deleted or put in the trash, because without it
	 * every car address on the site stops resolving -- and “I tidied up the
	 * Pages list” is not a reason for the stock to disappear.
	 */
	public static function page_id() {
		$id = (int) get_option( self::PAGE_OPTION );
		if ( $id ) {
			$post = get_post( $id );
			if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				return $id;
			}
		}

		$base = self::base();

		/* Somebody may already have made a page at this address by hand. Use
			   it rather than making a second one that cannot win the URL. */
		$found = get_page_by_path( $base );
		if ( $found ) {
			update_option( self::PAGE_OPTION, (int) $found->ID, false );
			return (int) $found->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Vehicles', 'vesla-landing' ),
				'post_name'      => $base,
				'post_content'   => __( 'Every car has its own address under this page. What each one says is edited under Landing Page, in the Cars section — nothing typed here is shown to a visitor.', 'vesla-landing' ),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_option( self::PAGE_OPTION, (int) $id, false );
		return (int) $id;
	}

	/**
	 * Keep the page's slug and the car addresses saying the same thing.
	 *
	 * The folder is a setting. If it is changed and the page keeps its old
	 * slug, WordPress owns one address and the rewrite rule points at
	 * another, and the pair of them serve nothing.
	 */
	public static function sync_page() {
		$id = self::page_id();
		if ( ! $id ) {
			return;
		}
		$post = get_post( $id );
		if ( $post && $post->post_name !== self::base() ) {
			wp_update_post( array( 'ID' => $id, 'post_name' => self::base() ) );
		}
	}

	/** Say, in the Pages list, what this page is for. */
	public static function page_state( $states, $post ) {
		if ( (int) $post->ID === (int) get_option( self::PAGE_OPTION ) ) {
			$states['vesla_vehicles'] = sprintf(
				/* translators: %s: a URL path, for example /cars/. */
				__( 'Serves every car page (%s)', 'vesla-landing' ),
				'/' . self::base() . '/'
			);
		}
		return $states;
	}

	/**
	 * The page on its own, with no car named, goes to the car list.
	 *
	 * Not a listing of its own: the front page already has the grid, with the
	 * filters and the structured data attached to it, and a second copy would
	 * be the same stock at a second address competing with the first.
	 */
	public static function bare_page() {
		$id = (int) get_option( self::PAGE_OPTION );
		if ( ! $id || ! is_page( $id ) || self::is_vehicle() ) {
			return;
		}
		wp_safe_redirect( home_url( '/#stock' ), 302 );
		exit;
	}

	/**
	 * A link a visitor follows, made relative to the site it is published on.
	 *
	 * The published files carry absolute addresses everywhere, which is right
	 * for a canonical or an og:url — those must name the one true address of a
	 * page, and a crawler cannot resolve a relative one. It is wrong for a
	 * link somebody clicks: it pins every menu item on every car page to a
	 * domain that does not answer yet, so the whole masthead is dead in a
	 * preview, on a staging host, and anywhere the site is opened before it
	 * goes live.
	 *
	 * Root-relative works in all of those AND on the live domain, so it is
	 * simply the better form for an anchor. The metadata keeps its absolute
	 * URLs; only the things a person clicks come through here.
	 */
	public static function rel( $url ) {
		if ( ! self::$static_build ) {
			return $url;
		}
		$root = trailingslashit( Vesla_Publisher::site_url() );
		if ( 0 === strpos( $url, $root ) ) {
			return '/' . substr( $url, strlen( $root ) );
		}
		return $url;
	}
	/** Is this render a car's own page rather than the front page? */
	private static function away_from_home() {
		/* Anywhere a '#section' link cannot resolve, because the sections are on
		   the homepage and the reader is not.
		
		   $forced is set while the publisher writes a car's file; the query var
		   is what WordPress goes by when it is answering a request; $page_key is
		   set while it writes one of the other pages.
		
		   The pages were the gap. A car page has always turned '#contact' into an
		   absolute link back to the homepage, and the new pages did not -- so the
		   footer's Contact link, the back-to-top and the skip link all pointed at
		   ids that only exist on a page the reader had left. Three dead links on
		   every page, in the chrome, where they are on every page at once. */
		return ( null !== self::$forced )
			|| ( '' !== self::$page_key )
			|| ( ! self::$static_build && self::is_vehicle() );
	}

	/**
	 * A menu link, corrected for the page it is being printed on.
	 *
	 * The header, the footer and the brand lockup are shared between the
	 * front page and every car page, and their links are stored as fragments
	 * -- #stock, #contact, #top. On the front page those are right. On a car
	 * page there is no #stock to scroll to, so the browser did nothing at all:
	 * the address bar gained a fragment and the page sat still. Every link in
	 * the masthead was dead on every car page.
	 *
	 * So a bare fragment is sent back to the front page, carrying its
	 * fragment with it. Anything already absolute, or a tel:/mailto:, is left
	 * exactly as the admin typed it.
	 */
	/**
	 * Menu choices that exist only as a page.
	 *
	 * Every other choice names a section the homepage still has, so switching
	 * its page off leaves the link working -- it scrolls instead of navigating.
	 * These have nothing to scroll to, so the row is left out entirely.
	 */
	public static function page_only_links() {
		return array( '#finance' => 'finance' );
	}

	/**
	 * Where a link in the menu, the footer or the header actually goes.
	 *
	 * @param string $link     What the admin chose, usually a '#section'.
	 * @param bool   $navigate Menu links only. Once a section has a page of its
	 *                         own, a MENU item pointing at it goes to the page
	 *                         rather than scrolling down the homepage -- the
	 *                         menu then means the same thing on every page of
	 *                         the site, which it cannot do if it scrolls here
	 *                         and navigates there.
	 *
	 *                         Off by default, and that default matters: the skip
	 *                         link is "Skip to the cars" and has to stay an
	 *                         in-page jump. A skip link that loads another page
	 *                         is not a skip link.
	 */
	public static function menu_href( $link, $navigate = false ) {
		$link = (string) $link;

		if ( $navigate && '' !== $link && '#' === $link[0] ) {
			/* Sections that have moved to a page of their own. Ownership was
			   folded into About with the record, so both anchors land there --
			   which is also what keeps a saved menu row reading '#chairman'
			   from pointing at a section the homepage no longer has. */
			$moved = array(
				'#certified' => 'certified',
				'#record'    => 'about',
				'#chairman'  => 'about',
				'#sell'      => 'sell',
				'#stock'     => 'stock',
				'#why'       => 'why',
				'#faq'       => 'faq',
				'#finance'   => 'finance',
			);
			if ( isset( $moved[ $link ] ) && self::page_live( $moved[ $link ] ) ) {
				return self::rel( self::page_url( $moved[ $link ] ) );
			}

			/* Contact is the exception: it had a page of its own long before any of
			   these, written by its own publisher step rather than listed in
			   pages(), so page_live() does not know about it. The menu pointed at
			   the homepage's contact section while /contact/ sat there published --
			   the one menu item with a page that was not being used. */
			if ( '#contact' === $link && Vesla_Settings::get( 'contact', 'page_enabled', 1 ) ) {
				return self::rel( trailingslashit( Vesla_Publisher::site_url() ) . 'contact/' );
			}
		}

		/* A link whose only destination is a page, with no section on the
		   homepage to fall back to. With that page switched off there is nowhere
		   for it to go, so it reports no destination and the caller leaves the
		   row out -- rather than sending a reader to the top of the site, which
		   is not where the link said it went.
		
		   A list rather than a test for '#finance', because the next page with
		   no homepage section will have the same problem, and a menu row that
		   points nowhere is not a thing worth fixing once. */
		if ( $navigate && isset( self::page_only_links()[ $link ] ) ) {
			$only = self::page_only_links();
			$key  = $only[ $link ];
			return self::page_live( $key ) ? self::rel( self::page_url( $key ) ) : '';
		}

		if ( '' === $link || '#' !== $link[0] || ! self::away_from_home() ) {
			return $link;
		}
		/* '#top' is the top of the page, and the top of the front page is the
			   front page itself -- no fragment needed, and none that exists. */
		return self::site_link( '#top' === $link ? '' : $link );
	}
	public static function is_landing() {
		if ( is_admin() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( $post->post_content, 'vesla_landing' );
	}

	const TEMPLATE = 'vesla-blank.php';

	public static function register_template( $templates ) {
		$templates[ self::TEMPLATE ] = __( 'Vesla Landing Page (full width)', 'vesla-landing' );
		return $templates;
	}

	/**
	 * A page template that lives in a plugin rather than the theme has to be
	 * loaded by hand — WordPress only looks inside the active theme.
	 */
	public static function use_template( $template ) {
		/* A vehicle page is not a WordPress page at all -- it has no post behind
		   it -- so it would otherwise fall through to whatever the theme does with
		   an unknown query, which on a block theme is the 404. */
		if ( self::is_vehicle() ) {
			$ours = VESLA_DIR . self::TEMPLATE;
			return file_exists( $ours ) ? $ours : $template;
		}

		if ( ! is_page() ) {
			return $template;
		}
		$chosen = get_page_template_slug( get_queried_object_id() );
		if ( self::TEMPLATE !== $chosen ) {
			return $template;
		}
		$ours = VESLA_DIR . self::TEMPLATE;
		return file_exists( $ours ) ? $ours : $template;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   ASSETS
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function assets() {
		if ( ! self::is_landing() && ! self::is_vehicle() ) {
			return;
		}
		$motion = (bool) Vesla_Settings::get( 'extras', 'motion_enabled', 0 );

		/* No stylesheet from Google. The three families are served from this
		   plugin's own assets/fonts, declared as @font-face at the top of
		   styles.css -- so the text needs one origin, not three, and there is no
		   DNS lookup and TLS handshake to a third party before a word is painted.
		   The old dependency on 'vesla-fonts' goes with it. */
		wp_enqueue_style( 'vesla-styles', VESLA_URL . 'assets/styles.css', array(), vesla_asset_ver( 'assets/styles.css' ) );
		/* The car page's own script. Loaded on a car's page, and on the landing
		   page too -- the cards there swap a car in without a reload, and the
		   swapped-in markup needs the same slider, depth and calculator a directly
		   opened page gets. It is small, and does nothing until a .veh-page is on
		   screen. */
		wp_enqueue_script( 'vesla-vehicle', VESLA_URL . 'assets/vehicle.js', array(), vesla_asset_ver( 'assets/vehicle.js' ), true );

		/* The map's own script. Tiny, and it does nothing but watch for the
		   section to come near the viewport -- Leaflet itself is fetched only
		   then, from this plugin, never from a CDN. */
		/* Only where there is a map. A car's page no longer carries one, and a
		   script whose first line is a querySelectorAll that finds nothing is
		   still a file fetched, parsed and run on every one of those pages. */
		if ( Vesla_Settings::enabled( 'map' ) && self::is_landing() ) {
			wp_enqueue_script( 'vesla-map', VESLA_URL . 'assets/map.js', array(), vesla_asset_ver( 'assets/map.js' ), true );
		}
		if ( self::is_vehicle() ) {
			wp_add_inline_script( 'vesla-vehicle', 'window.VESLA_DATA = ' . wp_json_encode( self::js_data_vehicle() ) . ';', 'before' );
		}

		if ( $motion ) {
			/* after styles.css on purpose — the composable transforms in the
			   motion layer are meant to win over the plain hover rules */
			wp_enqueue_style( 'vesla-motion', VESLA_URL . 'assets/motion.css', array( 'vesla-styles' ), vesla_asset_ver( 'assets/motion.css' ) );
		}

		/* The admin's colour choices are written as overrides of the same
		   custom properties the stylesheet defines, rather than by rewriting
		   the stylesheet — so a colour change is one small inline block and the
		   cached CSS file is untouched. */
		wp_add_inline_style( 'vesla-styles', self::css_vars() );

		/* The landing page's script, on the landing page only. It is written
		   around the car grid, the filter menus and the estimator -- none of which
		   exist on a car's own page -- and because the whole file is one IIFE, the
		   first missing element took the header and footer down with it. The car
		   page has its own, much smaller script instead. */
		if ( self::is_landing() ) {
			wp_enqueue_script( 'vesla-app', VESLA_URL . 'assets/app.js', array(), vesla_asset_ver( 'assets/app.js' ), true );
			wp_add_inline_script( 'vesla-app', 'window.VESLA_DATA = ' . wp_json_encode( self::js_data() ) . ';', 'before' );

			if ( $motion ) {
				/* last, so it enhances markup app.js has already rendered */
				wp_enqueue_script( 'vesla-motion', VESLA_URL . 'assets/motion.js', array( 'vesla-app' ), vesla_asset_ver( 'assets/motion.js' ), true );
			}
		}
	}

	private static function css_vars() {
		$accent = Vesla_Settings::get( 'extras', 'color_accent', '#F2ED28' );
		$deep   = Vesla_Settings::get( 'extras', 'color_accent_text', '#67620C' );
		$ink    = Vesla_Settings::get( 'extras', 'color_ink', '#141415' );
		$paper  = Vesla_Settings::get( 'extras', 'color_paper', '#F5F5F3' );

		$lit  = self::lighten( $accent, 0.34 );
		$dim  = self::darken( $accent, 0.30 );
		$void = self::darken( $ink, 0.35 );
		$soft = self::lighten( $ink, 0.05 );

		$css  = ':root{';
		$css .= '--amber:' . $accent . ';';
		$css .= '--amber-lit:' . $lit . ';';
		$css .= '--amber-dim:' . $dim . ';';
		$css .= '--amber-deep:' . $deep . ';';
		$css .= '--amber-glow:linear-gradient(180deg,' . self::lighten( $accent, 0.62 ) . ' 0%,' . $accent . ' 48%,' . self::darken( $accent, 0.18 ) . ' 100%);';
		$css .= '--amber-text:linear-gradient(180deg,' . self::lighten( $accent, 0.78 ) . ' 0%,' . self::lighten( $accent, 0.08 ) . ' 46%,' . self::darken( $accent, 0.22 ) . ' 100%);';
		$css .= '--ink:' . $ink . ';';
		$css .= '--void:' . $void . ';';
		$css .= '--ink-soft:' . $soft . ';';
		$css .= '--pearl:' . $paper . ';';
		$css .= '}';

		/* The reduced-motion blocks in the stylesheets are parked behind an
		   impossible width. Honouring the setting is therefore an opt-in that
		   re-states the essentials rather than un-parking them from here. */
		if ( Vesla_Settings::get( 'extras', 'respect_reduced_motion', 0 ) ) {
			$css .= '@media (prefers-reduced-motion:reduce){'
				. 'html{scroll-behavior:auto}'
				. '*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}'
				. '.reveal{opacity:1!important;transform:none!important}'
				. '.m-cast{opacity:1!important;animation:none!important}'
				. '.stages li::before,.hero-stats li::before{transform:none!important}'
				. '.card,.btn,.btn-line,.btn-gold{transform:none!important}'
				. '.m-on .card-media img{opacity:1!important}'
				/* The film hero: the words appear together rather than in
				   sequence, and the film neither drifts nor fades in. The
				   script has already declined to play it at all, so the poster
				   is what is on screen and this makes sure nothing moves over
				   it. The delays have to go too, or the staggered words would
				   still arrive one after another, just instantly each. */
				. '.hero-v .reveal{opacity:1!important;transform:none!important;transition:none!important}'
				. '.hero-v .v-1,.hero-v .v-2,.hero-v .v-3,.hero-v .v-4,.hero-v .v-5{transition-delay:0s!important}'
				. '.hero-v-film{transform:none!important;transition:none!important}'
				. '}';
		}
		return $css;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   DATA FOR THE SCRIPT
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Everything the front end needs, in one shape.
	 *
	 * PUBLIC on purpose: the REST endpoint returns this same array rather than
	 * assembling a second one. Two builders of "the payload" is how a headless
	 * front end ends up with a field the rendered page has and the API does
	 * not — so there is one, and both doors call it.
	 *
	 * NOTHING PRIVATE MAY BE ADDED HERE. This array is printed into the page
	 * source and served over the API; it is as public as the page itself. The
	 * enquiry destination (contact → form_to) was in here once, which handed
	 * a private inbox to anyone who read the page source.
	 */
	/**
	 * The rows a car's own page draws, in schema order.
	 *
	 * Five car fields are left out because the page renders them itself: the
	 * photograph, the make and the model (they are the heading), the price (its
	 * own line) and the description (its own block). Everything else follows
	 * from the schema, so a field added there appears on the page by itself.
	 */
	/**
	 * The fields the CARD needs — nothing more.
	 *
	 * Everything else about a car is fetched when its page is opened. Twenty
	 * fields for every one of twenty-four cars, including a paragraph of
	 * description each, is most of the page's weight spent on detail almost
	 * nobody scrolls to; and it is all in the HTML of the published page too,
	 * which doubles it.
	 */
	public static function card_fields() {
		/* What a card draws, and therefore what js_data() ships. status and
		   arrived are here because cardFor() draws the badges from them: left
		   out, the server-rendered card had its badges and the first re-render
		   in the browser silently dropped them. */
		return array( 'make', 'model', 'year', 'price', 'km', 'body', 'trans', 'fuel', 'seats', 'status', 'arrived' );
	}

	/** Every car field and its type, for the browser to coerce values by. */
	public static function car_field_types() {
		$out  = array( 'id' => 'number' );
		$card = self::card_fields();
		foreach ( Vesla_Store::car_fields() as $key => $def ) {
			if ( ! in_array( $key, $card, true ) ) {
				continue;
			}
			$out[ $key ] = isset( $def['type'] ) ? $def['type'] : 'text';
		}
		$out['img'] = 'text';
		$out['url'] = 'text';
		return $out;
	}

	/** The same, for the detail a car's page fetches. */
	public static function detail_field_types() {
		$out = array();
		foreach ( Vesla_Store::car_fields() as $key => $def ) {
			if ( 'photo' === $key || 'gallery' === $key ) {
				continue; // both arrive resolved, as `photos`
			}
			$out[ $key ] = isset( $def['type'] ) ? $def['type'] : 'text';
		}
		$out['photos'] = 'list';
		return $out;
	}

	/**
	 * One car, in full, for its own page.
	 *
	 * @param int $id A row id from the cars table.
	 * @return array|null
	 */
	public static function car_detail( $id ) {
		foreach ( Vesla_Store::cars() as $car ) {
			if ( (int) $car['id'] !== (int) $id ) {
				continue;
			}

			$out = array( 'id' => (int) $car['id'] );
			foreach ( Vesla_Store::car_fields() as $key => $def ) {
				if ( 'photo' === $key || 'gallery' === $key ) {
					continue;
				}
				$type = isset( $def['type'] ) ? $def['type'] : 'text';
				if ( 'number' === $type ) {
					$out[ $key ] = (int) $car[ $key ];
				} elseif ( 'rich' === $type ) {
					/* Cleaned on the way in and again here: the one car value
					   the page writes as markup rather than as text. */
					$out[ $key ] = Vesla_Schema::rich( $car[ $key ] );
				} else {
					$out[ $key ] = (string) $car[ $key ];
				}
			}
			$out['photos'] = self::car_photos( $car );
			return $out;
		}
		return null;
	}

	/** A car's photographs, main one first, resolved to URLs. */
	/**
	 * A small copy of each photograph, in the same order, for the backdrop.
	 *
	 * The stage shows the whole photograph rather than cropping it, so a
	 * picture that is not 3:2 leaves bars down the sides. Filling them with a
	 * blur of the picture itself is what every gallery does, and it costs one
	 * more request per photograph -- so it is the MEDIUM size, not the full
	 * one. Blurred to 40px, nobody can tell, and it is a tenth of the bytes.
	 *
	 * An entry is an empty string where there is no smaller copy to use: a
	 * sample bundled with the plugin has no WordPress sizes, and its full file
	 * is already on the page, so the backdrop reuses that rather than
	 * fetching anything at all.
	 *
	 * @return string[] index aligned with car_photos()
	 */
	public static function car_photo_fills( $car ) {
		$fills = array();

		if ( ! empty( $car['photo'] ) ) {
			$id  = absint( $car['photo'] );
			$url = wp_get_attachment_image_url( $id, 'medium_large' );
			if ( ! $url ) {
				$url = wp_get_attachment_image_url( $id, 'medium' );
			}
			if ( wp_get_attachment_image_url( $id, 'full' ) ) {
				$fills[] = $url ? $url : '';
			}
		} elseif ( ! empty( $car['photo_file'] ) ) {
			$fills[] = '';   // bundled: the full file is already here
		}

		foreach ( explode( ',', (string) $car['gallery'] ) as $gid ) {
			$gid = absint( $gid );
			if ( ! $gid || ! wp_get_attachment_image_url( $gid, 'full' ) ) {
				continue;
			}
			$url = wp_get_attachment_image_url( $gid, 'medium_large' );
			if ( ! $url ) {
				$url = wp_get_attachment_image_url( $gid, 'medium' );
			}
			$fills[] = $url ? $url : '';
		}
		return $fills;
	}

	/**
	 * A pasted video address, turned into the two forms the page needs.
	 *
	 * Whoever fills this in pastes what is in the browser bar, which is a watch
	 * page, not an embed. Both are worked out here: the watch address for the
	 * link itself, so it works with no scripting and opens where the video
	 * lives, and the embed address for the player that replaces it on a press.
	 *
	 * Only YouTube and Vimeo, and only after the id has been matched. Building
	 * an iframe src from whatever was typed would put an arbitrary third-party
	 * address inside a frame on the site, which is not a thing an address box in
	 * a settings screen should be able to do.
	 *
	 * Returns null when there is nothing usable, so the caller prints nothing.
	 */
	public static function video_embed( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}

		/* youtu.be/ID · youtube.com/watch?v=ID · /embed/ID · /shorts/ID */
		if ( preg_match( '#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,20})#i', $url, $m ) ) {
			$id = $m[1];
			return array(
				'watch' => 'https://www.youtube.com/watch?v=' . $id,
				/* nocookie, because the visitor pressed play on a car, not on an
				   advertising profile. */
				'embed' => 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0',
			);
		}

		if ( preg_match( '#vimeo\.com/(?:video/)?([0-9]{6,12})#i', $url, $m ) ) {
			$id = $m[1];
			return array(
				'watch' => 'https://vimeo.com/' . $id,
				'embed' => 'https://player.vimeo.com/video/' . $id . '?autoplay=1',
			);
		}

		return null;
	}

	public static function car_photos( $car ) {
		$photos = array();

		if ( ! empty( $car['photo'] ) ) {
			$url = wp_get_attachment_image_url( absint( $car['photo'] ), 'full' );
			if ( $url ) {
				$photos[] = $url;
			}
		} elseif ( ! empty( $car['photo_file'] ) ) {
			$photos[] = VESLA_URL . ltrim( $car['photo_file'], '/' );
		}

		foreach ( explode( ',', (string) $car['gallery'] ) as $gid ) {
			$gid = absint( $gid );
			if ( ! $gid ) {
				continue;
			}
			/* The uploaded file, not a cropped size. The gallery shows the whole
			   photograph rather than a fill of a fixed box, so WordPress's own crops
			   would show the admin something different from what they uploaded. */
			$url = wp_get_attachment_image_url( $gid, 'full' );
			if ( $url && ! in_array( $url, $photos, true ) ) {
				$photos[] = $url;
			}
		}
		return $photos;
	}
	public static function car_spec_rows() {
		/* Everything a car knows about itself is shown. This list is not a
		   trimming of it -- each of these appears somewhere better:
		
		     make, model, year   the heading
		     price               the panel beside the photographs
		     photo, gallery      the gallery itself
		     summary             the About this car block
		     features            its own ticked list
		     photo_alt           written for a screen reader, not for reading;
		                         it is the alt attribute on the photograph
		
		   Anything else the admin has filled in appears in the grid, and the
		   grid is built from the schema rather than from a list of columns --
		   so a field added to a car tomorrow shows up here without being
		   added here as well. */
		$skip = array( 'photo', 'photo_file', 'photo_alt', 'gallery', 'video', 'make', 'model', 'price', 'summary' );
		$out  = array();
		foreach ( Vesla_Store::car_fields() as $key => $def ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			/* public_label is the customer's wording where the editor's is too
			   technical: an admin needs to read 'Mileage in kilometres' to know
			   what to type, a buyer just wants 'Mileage'. */
			$out[] = array(
				'key'   => $key,
				'label' => isset( $def['public_label'] ) ? $def['public_label'] : ( isset( $def['label'] ) ? $def['label'] : $key ),
			);
		}
		return $out;
	}
	/**
	 * The slice of js_data() a car's own page actually uses.
	 *
	 * vehicle.js reads four keys and no others -- finance, labels, currency,
	 * locale. The full payload also carries the whole stock array, carSpec, and
	 * the estimator, loader and motion settings, none of which a car page has a
	 * use for: roughly 12KB of JSON, 23% of the page, fetched and parsed on
	 * every visit to draw one car.
	 *
	 * js_data() itself is left alone -- the landing page and the REST endpoint
	 * both take it whole, and the payload shape is a contract with a separately
	 * deployed front end.
	 */
	public static function js_data_vehicle() {
		$all  = self::js_data();
		$keep = array( 'finance', 'labels', 'currency', 'locale' );

		$out = array();
		foreach ( $keep as $key ) {
			if ( isset( $all[ $key ] ) ) {
				$out[ $key ] = $all[ $key ];
			}
		}
		return $out;
	}

	public static function js_data() {
		$stock = Vesla_Settings::get( 'stock' );
		$cars  = array();

		$fields = Vesla_Store::car_fields();

		foreach ( (array) $stock['cars'] as $car ) {
			/* A sold car is off the floor, so it is not in the payload the grid
			   redraws from either. Vesla_Rest::cars() filters the same way; this
			   loop is the other place the stock is read. */
			if ( 'sold' === Vesla_Render::car_status( $car ) ) {
				continue;
			}
			$img = '';
			if ( ! empty( $car['photo'] ) ) {
				$img = wp_get_attachment_image_url( absint( $car['photo'] ), 'large' );
			} elseif ( ! empty( $car['photo_file'] ) ) {
				$img = VESLA_URL . ltrim( $car['photo_file'], '/' );
			}
			/* Only what a CARD draws. The rest of this car -- the colours, the
			   engine, the description, the other photographs -- is fetched when
			   somebody opens it, which is the difference between shipping one
			   field set and shipping twenty-four of them to a visitor who may
			   open none. */
			$row = array( 'id' => (int) $car['id'] );
			foreach ( self::card_fields() as $key ) {
				$type = isset( $fields[ $key ]['type'] ) ? $fields[ $key ]['type'] : 'text';
				$row[ $key ] = 'number' === $type ? (int) $car[ $key ] : (string) $car[ $key ];
			}
			$row['img'] = $img ? $img : '';
			/* Where the card goes. Built here rather than in the browser: the
			   slug rules live in PHP, and a link the page has to compute is a
			   link that cannot be in the markup a crawler reads. */
			/* Empty where the car's own page is switched off, so nothing on the
			   front page offers a link to an address that answers 404. */
			$row['url'] = self::page_on( $car ) ? self::permalink( $car ) : '';

			$cars[] = $row;
		}

		$wa = preg_replace( '/\D/', '', (string) Vesla_Settings::get( 'brand', 'whatsapp', '' ) );

		return array(
			'stock'    => $cars,

			/* Which rows a car's own page draws, in schema order, and what to
			   call each one. Built here rather than listed in JavaScript so a car
			   field added to the schema appears on the page by itself.

			   The five below are left out because the page renders them itself:
			   the photograph, the make and model (they are the heading), the price
			   (its own line) and the description (its own block).

			   public_label is the customer's wording where the editor's is too
			   technical -- an admin needs to read "Mileage in kilometres" to know
			   what to type; a buyer just wants "Mileage". */
			'carSpec' => self::car_spec_rows(),

			/* The type of every car field, so the browser can coerce what it
			   receives without a second list of key names to keep in step with
			   this one. app.js rebuilds each car from exactly these keys. */
			'carFields' => self::car_field_types(),
			'detailFields' => self::detail_field_types(),

			/* The finance calculator's starting numbers. The calculation runs
			   in the browser so the sliders move without a round trip, but
			   every number it starts from is set in WordPress -- an interest
			   rate hard-coded in a script is a rate nobody can correct. */
			'finance' => array(
				'on'      => (bool) Vesla_Settings::get( 'vehicle', 'finance_enabled', 0 ),
				'title'   => (string) Vesla_Settings::get( 'vehicle', 'finance_title', '' ),
				'downPct' => (float) Vesla_Settings::get( 'vehicle', 'finance_down_pct', 20 ),
				'rate'    => (float) str_replace( ',', '.', (string) Vesla_Settings::get( 'vehicle', 'finance_rate', '0' ) ),
				'years'   => (int) Vesla_Settings::get( 'vehicle', 'finance_years', 5 ),
				'note'    => (string) Vesla_Settings::get( 'vehicle', 'finance_note', '' ),
			),
			'carBase'  => Vesla_Render::base(),
			'carHold'  => (int) Vesla_Settings::get( 'vehicle', 'transition_ms', 500 ),
			'perPage'  => (int) Vesla_Settings::get( 'stock', 'per_page', 8 ),
			'currency' => (string) Vesla_Settings::get( 'stock', 'currency', 'AED' ),
			'locale'   => str_replace( '_', '-', get_locale() ),
			'labels'   => array(
				'badge'     => (string) Vesla_Settings::get( 'stock', 'badge', '' ),
				'reserved'     => (string) Vesla_Settings::get( 'stock', 'reserved_label', '' ),
				'reservedNote' => (string) Vesla_Settings::get( 'stock', 'reserved_note', '' ),
				'soldLabel'    => (string) Vesla_Settings::get( 'stock', 'sold_label', '' ),
				'soldNote'     => (string) Vesla_Settings::get( 'stock', 'sold_note', '' ),
				'arrived'      => (string) Vesla_Settings::get( 'stock', 'arrived_label', '' ),
				'priceNote' => (string) Vesla_Settings::get( 'stock', 'price_note', '' ),
				'warranty'  => (string) Vesla_Settings::get( 'stock', 'warranty_note', '' ),
				'enquire'   => (string) Vesla_Settings::get( 'stock', 'enquire_label', 'Enquire' ),
			'overviewTitle' => (string) Vesla_Settings::get( 'vehicle', 'overview_title', '' ),
				'prev'       => __( 'Previous photograph', 'vesla-landing' ),
				'next'       => __( 'Next photograph', 'vesla-landing' ),
				'loading'    => __( 'Loading the details…', 'vesla-landing' ),
				'loadFail'   => __( 'The rest of this car could not be loaded. Please try again, or call us.', 'vesla-landing' ),
				/* The car page's own buttons. Read here so they reach vehicle.js,
				   which is the only script a car page loads. */
				'finAsk'      => (string) Vesla_Settings::get( 'vehicle', 'finance_ask_label', __( 'Ask us about these figures', 'vesla-landing' ) ),
				'share'       => (string) Vesla_Settings::get( 'vehicle', 'share_label', __( 'Share', 'vesla-landing' ) ),
				'shareCopied' => (string) Vesla_Settings::get( 'vehicle', 'share_copied_label', __( 'Link copied', 'vesla-landing' ) ),
				'shareFailed' => (string) Vesla_Settings::get( 'vehicle', 'share_failed_label', __( 'Could not copy', 'vesla-landing' ) ),
				'finDown'    => __( 'Deposit', 'vesla-landing' ),
				'finYears'   => __( 'Loan length in years', 'vesla-landing' ),
				'finPerMonth' => __( 'per month', 'vesla-landing' ),
				'featuresTitle' => (string) Vesla_Settings::get( 'vehicle', 'features_title', '' ),
				'specTitle' => (string) Vesla_Settings::get( 'vehicle', 'spec_title', '' ),
				'aboutTitle' => (string) Vesla_Settings::get( 'vehicle', 'about_title', '' ),
				'soundOn'   => (string) Vesla_Settings::get( 'stock', 'sound_on_label', 'Sound on' ),
				'soundOff'  => (string) Vesla_Settings::get( 'stock', 'sound_off_label', 'Sound off' ),

				/* Editable, because they carry the showroom's voice. */
				'seats'     => (string) Vesla_Settings::get( 'messages', 'seats_word', 'seats' ),
				'showing'   => (string) Vesla_Settings::get( 'messages', 'showing', '' ),
				'waText'    => (string) Vesla_Settings::get( 'messages', 'wa_text', '' ),
				'sending'   => (string) Vesla_Settings::get( 'messages', 'sending', '' ),
				'sendFail'  => (string) Vesla_Settings::get( 'messages', 'send_fail', '' ),

				/* Left as translatable strings: plumbing, not voice. `waAria` is
				   read only by screen readers, and the other two are fragments
				   nobody would sit down to reword. Putting them on the editor
				   screen would cost an administrator more in scrolling past
				   them than it could ever return. */
				'moreLeft'  => __( '(%s more)', 'vesla-landing' ),
				'waAria'    => __( 'WhatsApp us about the %s', 'vesla-landing' ),
				'under'     => __( 'Under %s', 'vesla-landing' ),
			),

			/* Every message the form can show, and the numbers behind them,
			   from the same place the server-side checks read. */
			'messages'  => array_map( 'strval', (array) Vesla_Settings::get( 'messages' ) ),
			'limits'    => Vesla_Enquiry::limits(),
			'whatsapp'  => $wa,
			/* Where enquiries are sent is deliberately absent: the browser
			   posts to the endpoint and the server decides the destination.
			   It used to be here, from the days of the mailto: link, which
			   published whatever private inbox the showroom had chosen. */
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'restUrl'   => esc_url_raw( rest_url( 'vesla/v1/' ) ),
			/* Lets a published page tell whether anything has been saved
			   since it was written, and skip re-rendering if not. */
			'version'   => Vesla_Rest::version(),
			'formOk'    => (string) Vesla_Settings::get( 'contact', 'form_success', '' ),
			'estimator' => array(
				'depreciation' => (int) Vesla_Settings::get( 'sell', 'est_depreciation', 11 ),
				'spread'       => (int) Vesla_Settings::get( 'sell', 'est_spread', 8 ),
				'yearRange'    => (int) Vesla_Settings::get( 'sell', 'est_year_range', 16 ),
				'floor'        => (int) Vesla_Settings::get( 'sell', 'est_residual_floor', 18 ),
			),
			/* Percentages of one screen height rather than fixed pixels: 700px
			   is most of the way down a phone and barely half of a desktop. */
			'scroll'    => array(
				'totopAt'  => (int) Vesla_Settings::get( 'extras', 'totop_at', 80 ),
				'actbarAt' => (int) Vesla_Settings::get( 'extras', 'actbar_at', 60 ),
			),
			'loader'    => array(
				'enabled' => (bool) Vesla_Settings::get( 'extras', 'loader_enabled', 0 ),
				'maxMs'   => max( 1000, (int) Vesla_Settings::get( 'extras', 'loader_max', 6 ) * 1000 ),
			),
			'motion'    => (bool) Vesla_Settings::get( 'extras', 'motion_enabled', 0 ),
			'reduced'   => (bool) Vesla_Settings::get( 'extras', 'respect_reduced_motion', 0 ),
		);
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   HEAD — description, sharing, structured data
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The head tags and structured data.
	 *
	 * Public and callable directly: Vesla_Publisher writes these into the
	 * static file, so a link previewer that never runs a script still gets the
	 * description, the sharing image and the AutoDealer markup.
	 *
	 * `$force` skips the is_landing() test, which is about which WordPress
	 * page is being viewed and means nothing when writing a file.
	 */

	/**
	 * Opening hours, folded into runs of days that share the same times.
	 *
	 * Stored one row per day because that is what Google needs, but nobody
	 * reads seven identical lines: a showroom open the same hours all week
	 * should say so once. Runs are consecutive only, so a Friday that differs
	 * splits the week rather than being quietly absorbed.
	 *
	 * @return array<int,array{days:string,times:string}>
	 */
	public static function hours_groups() {
		$rows = Vesla_Settings::get( 'contact', 'hours', array() );
		if ( ! is_array( $rows ) || ! $rows ) {
			return array();
		}

		$closed_word = (string) Vesla_Settings::get( 'contact', 'hours_closed_label', '' );

		$out  = array();
		$run  = null;
		foreach ( $rows as $row ) {
			$day = isset( $row['day'] ) ? (string) $row['day'] : '';
			if ( '' === $day ) {
				continue;
			}
			$opens  = isset( $row['opens'] ) ? trim( (string) $row['opens'] ) : '';
			$closes = isset( $row['closes'] ) ? trim( (string) $row['closes'] ) : '';
			$shut   = ! empty( $row['closed'] ) || '' === $opens || '' === $closes;

			$times = $shut ? $closed_word : $opens . ' – ' . $closes;

			if ( null !== $run && $run['times'] === $times ) {
				$run['last'] = $day;
				continue;
			}
			if ( null !== $run ) {
				$out[] = $run;
			}
			$run = array( 'first' => $day, 'last' => $day, 'times' => $times );
		}
		if ( null !== $run ) {
			$out[] = $run;
		}

		$fold = array();
		foreach ( $out as $g ) {
			if ( '' === $g['times'] ) {
				continue; // closed, and the admin has given no wording for it
			}
			$fold[] = array(
				'days'  => $g['first'] === $g['last'] ? $g['first'] : $g['first'] . ' – ' . $g['last'],
				'times' => $g['times'],
			);
		}
		return $fold;
	}

	/**
	 * The visitor-counting tag, or nothing at all.
	 *
	 * Printed rather than enqueued because it has to reach the PUBLISHED files
	 * too, and those are written by Vesla_Publisher without WordPress's script
	 * queue ever running. One method, called from both places, so the counted
	 * page and the page people actually visit cannot disagree.
	 *
	 * Nothing is emitted while this is off -- no script tag, no request to a
	 * third party, and so nothing to declare in a cookie notice. An account
	 * that has been filled in but whose provider was set back to None counts
	 * as off: the menu is the switch.
	 */
	public static function analytics_tag() {
		$provider = (string) Vesla_Settings::get( 'analytics', 'provider', '' );

		if ( 'ga4' === $provider ) {
			$id = trim( (string) Vesla_Settings::get( 'analytics', 'ga_id', '' ) );
			/* Google's own format. Checked because a wrong id here fails silently
			   -- the script loads, reports nothing, and looks like it is working. */
			if ( ! preg_match( '/^G-[A-Z0-9]{4,20}$/i', $id ) ) {
				return;
			}
			$src = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id );
			?>
<script async src="<?php echo esc_url( $src ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', <?php echo wp_json_encode( $id ); ?>);
</script>
			<?php
			return;
		}

		if ( 'plausible' === $provider ) {
			$domain = trim( (string) Vesla_Settings::get( 'analytics', 'plausible_domain', '' ) );
			if ( '' === $domain ) {
				return;
			}
			/* Whatever was typed, reduced to a bare host: somebody pasting the
			   address out of the browser bar is the common case, and Plausible
			   wants the domain on its own. */
			$domain = preg_replace( '#^https?://#i', '', $domain );
			$domain = trim( (string) preg_replace( '#[/?].*$#', '', $domain ) );
			if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain ) ) {
				return;
			}

			$host = trim( (string) Vesla_Settings::get( 'analytics', 'plausible_host', '' ) );
			$host = $host ? untrailingslashit( esc_url_raw( $host ) ) : 'https://plausible.io';
			?>
<script defer data-domain="<?php echo esc_attr( $domain ); ?>" src="<?php echo esc_url( $host . '/js/script.js' ); ?>"></script>
			<?php
		}
	}

	/**
	 * The cars, as schema.org ListItems.
	 *
	 * Pulled out of head() so the homepage and /stock/ cannot end up describing
	 * different stock. $limit is how many to include: the homepage shows eight
	 * and was publishing all twenty-four, telling a search engine about cars
	 * that were not on the page it was reading. /stock/ passes 0 and gets them
	 * all, because /stock/ does show them all.
	 */
	private static function stock_items( $limit = 0 ) {
		$stock = Vesla_Settings::get( 'stock' );
		if ( ! Vesla_Settings::enabled( 'stock' ) || empty( $stock['cars'] ) ) {
			return array();
		}
			$currency = Vesla_Settings::get( 'stock', 'currency', '' );
			$items    = array();
			$position = 0;

			foreach ( $stock['cars'] as $car ) {
				if ( empty( $car['make'] ) && empty( $car['model'] ) ) {
					continue;
				}
				$position++;

				$photo = '';
				if ( ! empty( $car['photo'] ) ) {
					$photo = wp_get_attachment_image_url( absint( $car['photo'] ), 'large' );
				} elseif ( ! empty( $car['photo_file'] ) ) {
					$photo = VESLA_URL . ltrim( $car['photo_file'], '/' );
				}

				/* The same identifier the car's own page publishes, so the listing
				   here and the page over there are one thing described twice
				   rather than two cars that happen to match. Without it a crawler
				   is entitled to treat them as separate stock. */
				$entry = array(
					'@id'           => self::car_id( $car ),
					'@type'         => 'Car',
					'name'          => trim( $car['make'] . ' ' . $car['model'] ),
					'brand'         => array( '@type' => 'Brand', 'name' => $car['make'] ),
					'model'         => $car['model'],
					'itemCondition' => 'https://schema.org/UsedCondition',
				);
				if ( $car['year'] ) {
					$entry['vehicleModelDate'] = (string) $car['year'];
				}
				if ( $car['body'] ) {
					$entry['bodyType'] = $car['body'];
				}
				if ( $car['fuel'] ) {
					$entry['fuelType'] = $car['fuel'];
				}
				if ( $car['trans'] ) {
					$entry['vehicleTransmission'] = $car['trans'];
				}
				if ( $car['seats'] ) {
					$entry['seatingCapacity'] = (int) $car['seats'];
				}
				if ( $car['km'] ) {
					$entry['mileageFromOdometer'] = array(
						'@type'    => 'QuantitativeValue',
						'value'    => (int) $car['km'],
						'unitCode' => 'KMT',
					);
				}
				if ( $photo ) {
					$entry['image'] = $photo;
				}
				if ( $car['price'] && $currency ) {
					$entry['offers'] = array(
						'@type'         => 'Offer',
						'price'         => (int) $car['price'],
						'priceCurrency' => $currency,
						'availability'  => 'https://schema.org/InStock',
						'seller'        => array( '@id' => $url . '#dealer' ),
					);
				}

				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $position,
					'item'     => $entry,
				);

				if ( $limit && $position >= $limit ) {
					break;
				}
			}

		return $items;
	}

	public static function head( $force = false ) {
		if ( ! $force && ! self::is_landing() ) {
			return;
		}
		if ( ! Vesla_Settings::get( 'seo', 'enabled', 0 ) ) {
			return;
		}
		$seo   = Vesla_Settings::get( 'seo' );
		/* get_permalink() needs a post in context and returns false without
		   one — which is exactly the case when Vesla_Publisher writes the
		   static file, and produced "url":false and an empty og:url in it.
		   The site's own address is right in both situations. */
		if ( self::$static_build ) {
			/* The published file is served from the public root, and every
			   absolute address in it has to say so. */
			$url = trailingslashit( Vesla_Publisher::site_url() );
		} else {
			$url = self::is_landing() ? get_permalink() : home_url( '/' );
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		$title = get_bloginfo( 'name' );
		/* The sharing picture, with its size. Facebook, LinkedIn and WhatsApp
		   fetch the image separately from the page, and on the FIRST share -- the
		   one that matters -- they often have not finished before the preview is
		   drawn. Given the dimensions up front they reserve the space and show a
		   large card straight away; without them the first share of a page is
		   commonly just a bare link. */
		$share_id  = absint( $seo['share_image'] );
		$share_src = $share_id ? wp_get_attachment_image_src( $share_id, 'full' ) : false;
		$share     = $share_src ? $share_src[0] : '';

		/* Carried over from the static page's <head>. The browser chrome on a
		   phone takes its colour from this, so without it the address bar stays
		   default-grey above a black page. Read from the colour setting rather
		   than hard-coded, so it follows if the brand colour is changed. */
		/* Preload ONLY the two faces that paint above the fold: Barlow 400 for
		 * the body text and the Playfair file behind the hero headline (one
		 * variable file covers 500 to 700, so the h1's 600 needs nothing extra).
		 *
		 * Only the latin subsets, and only these two. Preloading all twelve would
		 * make the largest paint later rather than sooner -- every one of them
		 * would compete with the hero photograph for the same few hundred
		 * kilobytes of an opening connection.
		 *
		 * crossorigin is required even same-origin: fonts are fetched in CORS
		 * mode, and a preload without it is simply downloaded twice.
		 */
		$font_base = self::$static_build
			? trailingslashit( Vesla_Publisher::site_url() ) . 'assets/fonts/'
			: VESLA_URL . 'assets/fonts/';
		foreach ( array( 'barlow-400-latin.woff2', 'playfair-display-500-latin.woff2' ) as $f ) {
			printf(
				'<link rel="preload" as="font" type="font/woff2" crossorigin href="%s">' . "
",
				esc_url( $font_base . $f )
			);
		}

		/* The opening's mark, fetched with the fonts rather than after them.

		   The whole point of the opening is that it is the first thing on
		   screen, and an opening that starts on an empty frame is worse than
		   no opening at all. The script will not begin until the image is
		   really there, so without this the curtain sits blank for as long as
		   the fetch takes. The same file is the hero's shield and the loading
		   screen's mark, so this is one request that three things wait on --
		   it would be worth preloading even if the opening were off, which is
		   why it is not conditional on the setting. */
		printf( '<link rel="preload" as="image" fetchpriority="high" href="%s">' . "
", esc_url( self::logo_url() ) );

		printf( '<meta name="theme-color" content="%s">' . "
", esc_attr( Vesla_Settings::get( 'extras', 'color_ink', '#141415' ) ) );

		/* A favicon only if the site has not set its own. WordPress's Site Icon
		   wins wherever it exists — this is a fallback so the browser tab is
		   never the blank default, not an override of the admin's choice. */
		if ( ! has_site_icon() ) {
			printf( '<link rel="icon" href="%s">' . "
", esc_url( self::logo_url() ) );
		}

		/* The canonical URL, and deliberately the PUBLIC address rather than
		   whatever this request arrived on. The same page answers at the
		   published static root and again at /cms where WordPress serves it, and
		   two URLs holding one page's content is the duplicate that splits its
		   ranking between them. site_url() is the address the admin has declared
		   as public, in the exact www and https form that actually serves. */
		printf( '<link rel="canonical" href="%s">' . "
", esc_url( trailingslashit( Vesla_Publisher::site_url() ) ) );

		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $seo['description'] ) );
		printf( '<meta property="og:type" content="website">' . "\n" );
		/* Underscored, unlike <html lang>: Open Graph wants en_AE where the lang
		   attribute wants en-AE, and the two are not interchangeable. */
		printf( '<meta property="og:locale" content="%s">' . "
", esc_attr( get_locale() ) );
		printf( '<meta property="og:site_name" content="%s">' . "
", esc_attr( $title ) );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $seo['description'] ) );
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		if ( $share ) {
			$share_alt = (string) get_post_meta( $share_id, '_wp_attachment_image_alt', true );
			printf( '<meta property="og:image" content="%s">' . "
", esc_url( $share ) );
			printf( '<meta property="og:image:width" content="%d">' . "
", (int) $share_src[1] );
			printf( '<meta property="og:image:height" content="%d">' . "
", (int) $share_src[2] );
			/* The alt text says what the picture is. The media library's own alt is
			   preferred, because whoever uploaded it knew what it showed; the
			   fallback names the showroom, which is the sentence a car page already
			   uses when it borrows this same picture. An og:image with no alt is a
			   card that reads as blank to anybody using a screen reader on whichever
			   platform it was shared to. */
			if ( '' === $share_alt ) {
				$share_alt = sprintf( __( '%s showroom', 'vesla-landing' ), get_bloginfo( 'name' ) );
			}
			printf( '<meta property="og:image:alt" content="%s">' . "
", esc_attr( $share_alt ) );
			printf( '<meta name="twitter:image:alt" content="%s">' . "
", esc_attr( $share_alt ) );
			printf( '<meta name="twitter:card" content="summary_large_image">' . "
" );
			printf( '<meta name="twitter:image" content="%s">' . "
", esc_url( $share ) );
		} else {
			/* Nothing to show, so the small card rather than one that reserves a
			   panel and then draws it empty. Same reasoning as a car page. */
			printf( '<meta name="twitter:card" content="summary">' . "
" );
		}

		/* Twitter falls back to og:title and og:description where its own are
		   absent, so these were not strictly missing -- but every car page states
		   them outright, and a home page that is the one exception is the kind of
		   inconsistency that becomes a bug the next time either is edited. */
		printf( '<meta name="twitter:title" content="%s">' . "
", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s">' . "
", esc_attr( $seo['description'] ) );

		// Structured data: the dealer, and the questions, built from what is stored.
		$graph = array();

		$dealer = array(
			'@type'       => 'AutoDealer',
			'@id'         => $url . '#dealer',
			'name'        => $seo['business_name'],
			'url'         => $url,
			'description' => $seo['description'],
			/* The landline where there is one. Google treats this as the
			   business number, and a mobile in that position is why a listing
			   sometimes shows the wrong one. The sales number is the fallback
			   so a site that has not filled the landline in is unchanged. */
			'telephone'   => Vesla_Settings::get(
				'brand',
				'phone_landline',
				Vesla_Settings::get( 'brand', 'phone_sales', '' )
			),
			'email'       => Vesla_Settings::get( 'brand', 'email', '' ),
			'address'     => array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $seo['city'],
				'addressCountry'  => $seo['country_code'],
			),
		);
		if ( $seo['founded'] ) {
			$dealer['foundingDate'] = $seo['founded'];
		}

		/* What it costs to buy here, read off the floor rather than typed.

		   Google shows this against a business listing, and a figure somebody
		   entered once is wrong within a month -- stock turns over. Working it
		   out from the cars actually for sale means it is right by
		   construction, and it is recalculated on every publish because this
		   whole node is built at render time.

		   Omitted rather than guessed when nothing has a price on it. */
		$range = self::price_range();
		if ( '' !== $range ) {
			$dealer['priceRange'] = $range;
		}

		/* The showroom's own accounts elsewhere.

		   sameAs is how a search engine is told that this website and those
		   profiles are one company rather than several with a similar name.
		   Blank rows are dropped and the property is omitted entirely when
		   there is nothing in it -- an empty sameAs is a validation error, and
		   a wrong one is worse than none. */
		$profiles = array();
		foreach ( (array) Vesla_Settings::get( 'seo', 'profiles', array() ) as $row ) {
			$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
			if ( '' !== $url && ! in_array( $url, $profiles, true ) ) {
				$profiles[] = $url;
			}
		}
		if ( $profiles ) {
			$dealer['sameAs'] = $profiles;
		}
		/* openingHoursSpecification, one entry per day the showroom is open.
		   This is what lets Google show "Open now" against the listing, and it
		   has to be the machine form -- a sentence in the page saying nine to
		   nine is invisible to it. Days with no times are simply absent, which
		   is how the vocabulary says "closed". */
		$spec = array();
		foreach ( (array) Vesla_Settings::get( 'contact', 'hours', array() ) as $row ) {
			$day    = isset( $row['day'] ) ? (string) $row['day'] : '';
			$opens  = isset( $row['opens'] ) ? trim( (string) $row['opens'] ) : '';
			$closes = isset( $row['closes'] ) ? trim( (string) $row['closes'] ) : '';
			if ( '' === $day || ! empty( $row['closed'] ) || '' === $opens || '' === $closes ) {
				continue;
			}
			$spec[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => 'https://schema.org/' . $day,
				'opens'     => $opens,
				'closes'    => $closes,
			);
		}
		if ( $spec ) {
			$dealer['openingHoursSpecification'] = $spec;
		}

		if ( $share ) {
			$dealer['image'] = $share;
		}
		if ( $seo['parent_name'] ) {
			$dealer['parentOrganization'] = array_filter(
				array(
					'@type' => 'Organization',
					'name'  => $seo['parent_name'],
					'url'   => $seo['parent_url'],
				)
			);
		}
		$graph[] = $dealer;

		/* The questions are emitted only when the section is actually shown.
		   Marking up an FAQ that a visitor cannot see on the page is exactly
		   what the structured-data guidance says not to do. */
		/* Only while /faq/ is off. Once the questions have a page of their own
		   that page is the one that should answer them in a search result, and
		   the same FAQPage on two URLs is two nodes competing for one set of
		   questions. */
		$faq = Vesla_Settings::get( 'faq' );
		if ( ! self::page_live( 'faq' ) && Vesla_Settings::enabled( 'faq' ) && ! empty( $faq['items'] ) ) {
			$entities = array();
			foreach ( $faq['items'] as $item ) {
				if ( ! $item['q'] || ! $item['a'] ) {
					continue;
				}
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $item['q'],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => self::plain( $item['a'] ) ),
				);
			}
			if ( $entities ) {
				$graph[] = array(
					'@type'      => 'FAQPage',
					'@id'        => $url . '#faq',
					'mainEntity' => $entities,
				);
			}
		}

		/* The cars actually shown on this page. Built by stock_items(), which
		   /stock/ also uses -- one builder, so the two pages cannot disagree
		   about what is on the floor. */
		$items = self::stock_items( (int) Vesla_Settings::get( 'stock', 'per_page', 8 ) );
		if ( $items ) {
			$graph[] = array(
				'@type'           => 'ItemList',
				'@id'             => $url . '#stock',
				'name'            => Vesla_Settings::get( 'stock', 'heading', '' ),
				'numberOfItems'   => count( $items ),
				'itemListElement' => $items,
			);
		}

		echo '<script type="application/ld+json">'
			. wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>' . "\n";
	}

	/**
	 * The loading screen's own script, printed in the head.
	 *
	 * It cannot wait for app.js in the footer: the scroll lock has to be on
	 * before the page paints, or the loader appears over a page that has
	 * already been scrolled. Kept to a few lines with no dependencies, and
	 * armed so that nothing later on the page can leave a reader stuck behind
	 * it — every exit route below is a separate guarantee.
	 */
	public static function loader_boot() {
		if ( ! self::is_landing() || ! Vesla_Settings::get( 'extras', 'loader_enabled', 0 ) ) {
			return;
		}
		/* The backstop. One image that never resolves means the load event never
		   fires, and without a ceiling the curtain would be permanent. */
		$max = max( 1000, (int) Vesla_Settings::get( 'extras', 'loader_max', 6 ) * 1000 );

		/* The shortest time the curtain stays up, and the number people actually
		   mean by "make the loader one second". loader_max is only a cap on how
		   long to wait for a slow page -- on a fast one the curtain lifted almost
		   at once, which looked like it had stopped working. */
		$min = (int) Vesla_Settings::get( 'extras', 'loader_min', 400 );
		$min = max( 0, min( $min, $max ) );
		?>
<style id="vesla-loader-css">html.is-loading{overflow:hidden}</style>
<script id="vesla-loader-js">
(function(){
	var root = document.documentElement;
	root.classList.add('is-loading');
	/* Scripting is on. The stock grid renders every car into the HTML so that
	   each one has a real link a crawler can follow and somebody without
	   JavaScript can use; this class is what lets the stylesheet fold the
	   later ones away again for everybody else, before they are painted. */
	root.classList.add('has-js');

	/* classList, never a string replace on className: any other classList write
	   on <html> re-serialises the attribute, and a replace(' is-loading','')
	   would then match nothing and leave the page permanently unscrollable. */
	var unlock = function(){ root.classList.remove('is-loading'); };
	var MIN = <?php echo (int) $min; ?>, MAX = <?php echo (int) $max; ?>, t0 = Date.now(), done = false;

	var lift = function(){
		if (done) return;
		done = true;
		setTimeout(function(){
			var pre = document.getElementById('preload');
			if (pre) {
				pre.classList.add('done');
				pre.setAttribute('aria-hidden','true');
				/* Kept, not removed. Once the html class comes off it is display:none
			   and cannot take a click, and moving to a car reuses this same
			   curtain rather than building a second one. 420 is the fade length
			   in the stylesheet; the two are one number in two places. */
			setTimeout(function(){
				unlock();
				pre.classList.remove('done');

				/* Arriving at /#stock lands at the top of the page without this.

				   The browser jumps to a fragment as soon as it has the element,
				   which on this page is while the curtain is still up and the html
				   class has scrolling locked -- so the jump is swallowed and the
				   reader is left at the masthead wondering why the link did
				   nothing. It is done again here, once there is somewhere to go.

				   Only when the page has not been scrolled in the meantime: if
				   somebody has already started reading, moving them is worse than
				   the fragment being missed. */
				try {
					var id = location.hash.slice(1);
					var el = id && document.getElementById(id);
					if (el && window.pageYOffset < 4) {
						el.scrollIntoView();
					}
				} catch (e) {}
			}, 420);
			}
			unlock();
		}, Math.max(0, MIN - (Date.now() - t0)));
	};

	/* A pristine copy of the figure. A CSS animation that has finished does not
	   restart by being shown again, so each navigation swaps in a fresh copy
	   rather than revealing a mark that has already flooded. */
	document.addEventListener('DOMContentLoaded', function(){
		/* Looked up here, not held from outside: this script runs in <head>,
		   before the curtain exists in the document. */
		var el = document.getElementById('preload');
		var art = el && el.querySelector('.pl-art');
		if (art) { window.__veslaArt = art.cloneNode(true); }
	});

	if (document.readyState === 'complete') lift();
	else window.addEventListener('load', lift);
	setTimeout(lift, MAX);

	/* Anyone who has already decided to act gets out immediately, and this is
	   the last line of defence: whatever else fails, a scroll, a tap or a key
	   press takes the curtain down. */
	['pointerdown','keydown','wheel','touchstart'].forEach(function(ev){
		window.addEventListener(ev, lift, { passive:true, once:true });
	});
})();
</script>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   THE PAGE
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function shortcode() {
		ob_start();
		self::opening();
		self::loader();
		self::header_bar();
		self::hero();
		self::trust();
		/* The showpiece first, then the filter, then the grid. The strip of
		   makes stays next to the thing it filters; the featured flow is not a
		   filter and belongs above both. */
		self::spotlight();
		self::brand_strip();
		self::stock( true );
		self::certified( true );
		self::why();
		self::record( true );
		/* Absorbed into /about/, where it sits under the record it belongs to.
		   It used to stand between the cars and the enquiry form, which is the
		   worst place on the page for it: a reader who has just chosen a car is
		   on their way to the form, and the owner's statement is not what they
		   stopped for. Kept on the homepage while /about/ is switched off, so
		   turning the page off never loses the section outright. */
		if ( ! self::page_live( 'about' ) ) {
			self::chairman();
		}
		self::sell( true );
		self::faq();
		self::contact();
		/* Above the footer, not below it. The footer is the end of the page, and
		   nothing belongs after an ending -- but where the showroom is belongs
		   immediately before it, once somebody has read the cars and decided to
		   come and look. */
		self::map_section();
		self::footer();
		self::floating();
		return ob_get_clean();
	}

	/**
	 * The Contact page: /contact/, its own address and its own heading.
	 *
	 * Built entirely from what the Contact section already holds -- the same
	 * channels, hours, enquiry form, branches and map. Nothing here is a
	 * second copy of anything: contact() and map_section() are the same
	 * methods the front page calls, so a change to the phone number or the
	 * opening hours reaches both places because there is only one place to
	 * change it. Only the opening lines are the page's own, because a heading
	 * written to be read after scrolling the whole front page is the wrong
	 * heading for somebody arriving here cold from a search result.
	 *
	 * No opening animation and no loading curtain. Both are arrival pieces for
	 * the front page; somebody who has come here has come to find a phone
	 * number, and putting a film in front of that would be theatre at the
	 * expense of the one thing the page is for.
	 */
	/**
	 * The Contact page's own three lines, with their defaults.
	 *
	 * In one place because three different callers need them -- the page, its
	 * head and the file's title -- and Vesla_Settings::get() does not consult
	 * the schema's 'default'. It reads what is stored and takes a fallback
	 * from the caller, which is this codebase's idiom; the schema default is
	 * what fills the editor's field on a fresh install. Written out three
	 * times, the two would drift the first time anybody edited one of them.
	 */
	private static function contact_page_copy() {
		return array(
			'eyebrow' => (string) Vesla_Settings::get( 'contact', 'page_eyebrow', __( 'Talk to us', 'vesla-landing' ) ),
			'heading' => (string) Vesla_Settings::get( 'contact', 'page_heading', __( 'Come and see the car.', 'vesla-landing' ) ),
			'lead'    => (string) Vesla_Settings::get( 'contact', 'page_lead', __( 'Call, message or write — whichever suits. Someone who knows the stock answers, not a call centre, and if the car you are asking about has gone we will say so rather than sell you another one.', 'vesla-landing' ) ),
		);
	}

	public static function contact_page() {
		$c = self::contact_page_copy();
		self::header_bar();
		?>
		<main class="cpage" id="top">
			<section class="cpage-head">
				<div class="shell">
					<?php if ( '' !== $c['eyebrow'] ) : ?>
						<p class="eyebrow reveal"><?php echo esc_html( $c['eyebrow'] ); ?></p>
					<?php endif; ?>
					<h1 class="reveal"><?php echo esc_html( $c['heading'] ); ?></h1>
					<?php if ( '' !== $c['lead'] ) : ?>
						<p class="cpage-lead reveal"><?php Vesla_Render::t( 'contact.page_lead', $c['lead'] ); ?></p>
					<?php endif; ?>
				</div>
			</section>
		</main>
		<?php
		/* Forced: this page is the section, so switching the section off on the
		   front page must not leave this page with a heading and nothing under
		   it. */
		self::contact( true );
		self::map_section();
		self::footer();
		self::floating();
	}

	/**
	 * The Contact page's own head: title, description, canonical, sharing.
	 *
	 * Its own, and that matters -- the front page's title and description
	 * describe a showroom's whole stock, and a search result for "vesla motors
	 * contact" that reads like the front page is a result nobody clicks. The
	 * canonical is the published address rather than whatever WordPress is
	 * installed at, for the same reason every other canonical here is.
	 */
	/* ═══════════════════════════════════════════════════════════════════════
	   THE OTHER PAGES

	   The landing page, the cars and Contact were the whole site. These are the
	   rest -- Certified, Sell, About, Stock -- and every one of them shows
	   sections the landing page ALREADY shows, reading the same settings. There
	   is no second copy of the wording anywhere: editing Certified changes the
	   strip on the homepage and the page, because they are one set of fields.

	   Each is published as a file, exactly as Contact is. There is deliberately
	   no WordPress route: a car page has one because WordPress owns the car
	   post type, and Contact never did. Consequence worth knowing -- these
	   pages do not exist until Republish runs, and cannot be previewed at the
	   WordPress address before then.
	   ═══════════════════════════════════════════════════════════════════════ */

	/** Set while the publisher writes one of these, the way $forced is for cars. */
	public static $page_key = '';

	/**
	 * The pages, and where each one reads from.
	 *
	 * `owner` is the settings section that switches the page on and supplies its
	 * heading -- the arrangement Contact already has in `contact`, rather than a
	 * new screen listing pages somewhere else.
	 *
	 * `sections` are rendered in order, full length. The homepage keeps its own
	 * shorter versions of the same sections; both read one set of fields.
	 */
	public static function pages() {
		return array(
			'certified' => array(
				'slug'     => 'certified',
				'owner'    => 'certified',
				'sections' => array( 'certified' ),
			),
			'sell' => array(
				'slug'     => 'sell',
				'owner'    => 'sell',
				'sections' => array( 'sell' ),
			),
			'about' => array(
				'slug'     => 'about',
				/* Owned by `record` because the 1988 story leads the page. Ownership
				   and the branches follow it, which is why Record and Ownership stop
				   being menu items -- they are two parts of one answer. */
				'owner'    => 'record',
				'sections' => array( 'record', 'chairman', 'map_section' ),
			),
			'stock' => array(
				'slug'     => 'stock',
				'owner'    => 'stock',
				'sections' => array( 'brand_strip', 'stock' ),
			),
			'sold' => array(
				'slug'     => 'sold',
				'owner'    => 'sold',
				'sections' => array( 'sold_page' ),
			),
			'why' => array(
				'slug'     => 'why',
				'owner'    => 'why',
				'sections' => array( 'why' ),
			),
			'faq' => array(
				'slug'     => 'faq',
				'owner'    => 'faq',
				'sections' => array( 'faq' ),
			),
			/* Finance has no section on the homepage at all -- it is reached from
			   the menu, not scrolled to. Its renderer exists only for this page. */
			'finance' => array(
				'slug'     => 'finance',
				'owner'    => 'finance',
				'sections' => array( 'finance_page' ),
			),
			/* These two are not a section of the homepage rendered somewhere else:
			   they are a page of prose and nothing more. `rich` names the field on
			   the owning section that holds it. */
			'privacy' => array(
				'slug'     => 'privacy',
				'owner'    => 'privacy',
				'sections' => array(),
				'rich'     => 'body',
			),
			'terms' => array(
				'slug'     => 'terms',
				'owner'    => 'terms',
				'sections' => array(),
				'rich'     => 'body',
			),
		);
	}

	/** Is this page switched on? Off unless somebody has said otherwise. */
	public static function page_live( $key ) {
		$pages = self::pages();
		if ( ! isset( $pages[ $key ] ) ) {
			return false;
		}
		return (bool) Vesla_Settings::get( $pages[ $key ]['owner'], 'page_enabled', 0 );
	}

	/** The published address of one of these pages. */
	public static function page_url( $key ) {
		$pages = self::pages();
		if ( ! isset( $pages[ $key ] ) ) {
			return '';
		}
		return trailingslashit( Vesla_Publisher::site_url() ) . $pages[ $key ]['slug'] . '/';
	}

	/** The page's own heading, which is also its title and its h1. */
	public static function page_title( $key ) {
		$pages = self::pages();
		if ( ! isset( $pages[ $key ] ) ) {
			return '';
		}
		$owner = $pages[ $key ]['owner'];
		$head  = (string) Vesla_Settings::get( $owner, 'page_heading', '' );
		if ( '' === $head ) {
			/* Falls back to the section's own heading rather than inventing one,
			   so a page switched on before anybody writes a title still says
			   something true. */
			$head = (string) Vesla_Settings::get( $owner, 'heading', '' );
		}
		return $head;
	}

	/**
	 * Everything this page puts in its head.
	 *
	 * Canonical, description and og from the page's own settings; a
	 * BreadcrumbList so a search result shows where it sits. No @id on the
	 * crumbs, matching the car pages -- the only @ids on this site are the
	 * dealer, the FAQ, the stock list and each car, and a second definition of
	 * any of those would be worse than none.
	 */
	/**
	 * The site's sharing picture, as og:image with its dimensions.
	 *
	 * Shared by every page's head. Every page but the homepage was sharing as
	 * a bare link because only the homepage printed one, and a link with no
	 * card is a link nobody presses. There is no per-page picture and none is
	 * proposed: one nobody ever changes is worse than one that is shared.
	 *
	 * The dimensions matter as much as the picture. WhatsApp and Facebook
	 * fetch it separately and often have not finished before the preview is
	 * drawn; told the size up front they reserve the space and draw a large
	 * card on the FIRST share, which is the share that matters.
	 */
	public static function share_image_ld() {
		$share = absint( Vesla_Settings::get( 'seo', 'share_image', 0 ) );
		if ( ! $share ) {
			return;
		}
		$src = wp_get_attachment_image_src( $share, 'full' );
		if ( ! $src ) {
			return;
		}
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $src[0] ) );
		printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $src[1] );
		printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $src[2] );
		$alt = trim( (string) get_post_meta( $share, '_wp_attachment_image_alt', true ) );
		if ( '' !== $alt ) {
			printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
		}
	}

	/**
	 * A BreadcrumbList for a page one step below the homepage.
	 *
	 * Shared because the contact page needs the same thing and building it
	 * there separately is how two breadcrumbs end up disagreeing about the name
	 * of the site. No @id: the only @ids here belong to the dealer, the FAQ,
	 * the two stock lists and each car, and a crumb trail is not a thing worth
	 * naming twice.
	 */
	public static function breadcrumb_ld( $name ) {
		if ( ! Vesla_Settings::get( 'seo', 'enabled', 0 ) || '' === trim( (string) $name ) ) {
			return;
		}
		$site = trailingslashit( Vesla_Publisher::site_url() );
		echo '<script type="application/ld+json">'
			. wp_json_encode(
				array(
					'@context'        => 'https://schema.org',
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array( '@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo( 'name' ), 'item' => $site ),
						array( '@type' => 'ListItem', 'position' => 2, 'name' => $name ),
					),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
			. '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_json_encode escapes.
	}

	public static function page_head() {
		$key   = self::$page_key;
		$pages = self::pages();
		if ( '' === $key || ! isset( $pages[ $key ] ) ) {
			return;
		}
		$owner = $pages[ $key ]['owner'];
		$name  = Vesla_Settings::get( 'seo', 'business_name', get_bloginfo( 'name' ) );
		$url   = self::page_url( $key );
		$head  = self::page_title( $key );
		$desc  = self::plain( (string) Vesla_Settings::get( $owner, 'page_intro', '' ) );
		if ( '' === $desc ) {
			$desc = self::plain( (string) Vesla_Settings::get( $owner, 'lead', '' ) );
		}
		if ( '' === $desc && ! empty( $pages[ $key ]['rich'] ) ) {
			/* Privacy and Terms have no lead and no intro -- they are a page of
			   prose and nothing else. The opening of that prose is a truer
			   description than no description at all, which is what a search engine
			   was being given. */
			$desc = self::plain( (string) Vesla_Settings::get( $owner, $pages[ $key ]['rich'], '' ) );
		}
		$desc  = $desc ? wp_html_excerpt( $desc, 155, '…' ) : '';
		$title = trim( $head . ' — ' . $name );

		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
		if ( $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:type" content="website">' . "\n" );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		if ( $desc ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );

		self::share_image_ld();

		if ( ! Vesla_Settings::get( 'seo', 'enabled', 0 ) ) {
			return;
		}
		self::breadcrumb_ld( $head );

		/* The two pages that carry a kind of their own, and only those two. The
		   questions belong to /faq/ now, and the definitive list of cars belongs
		   to /stock/ -- which is where all of them actually are. Nothing else
		   gets a type: repeating the dealer or the organisation on every page is
		   the duplication this is trying to avoid. */
		if ( 'faq' === $key ) {
			$faq = Vesla_Settings::get( 'faq' );
			$qs  = array();
			foreach ( (array) $faq['items'] as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$qs[] = array(
					'@type'          => 'Question',
					'name'           => $item['q'],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => self::plain( $item['a'] ) ),
				);
			}
			if ( $qs ) {
				echo '<script type="application/ld+json">'
					. wp_json_encode(
						array(
							'@context'   => 'https://schema.org',
							'@type'      => 'FAQPage',
							'@id'        => $url . '#faq',
							'mainEntity' => $qs,
						),
						JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
					)
					. '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_json_encode escapes.
			}
		}

		if ( 'stock' === $key ) {
			/* All of them, and a different @id from the homepage's eight. Two lists
			   describing different sets of cars are two lists; the same @id would
			   have made them one node contradicting itself. */
			$items = self::stock_items( 0 );
			if ( $items ) {
				echo '<script type="application/ld+json">'
					. wp_json_encode(
						array(
							'@context'        => 'https://schema.org',
							'@type'           => 'ItemList',
							'@id'             => $url . '#stock-all',
							'name'            => $head,
							'numberOfItems'   => count( $items ),
							'itemListElement' => $items,
						),
						JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
					)
					. '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_json_encode escapes.
			}
		}
	}

	/**
	 * The page itself: the site's header, one h1, the sections, the footer.
	 *
	 * The h1 is the page's own heading and is the ONLY one on the page -- the
	 * sections below it open at h2, which is what they already do on the
	 * homepage, where the hero holds the h1.
	 */
	public static function page_body() {
		$key   = self::$page_key;
		$pages = self::pages();
		if ( '' === $key || ! isset( $pages[ $key ] ) ) {
			return;
		}
		$page  = $pages[ $key ];
		$owner = $page['owner'];
		$head  = self::page_title( $key );
		$intro = (string) Vesla_Settings::get( $owner, 'page_intro', '' );
		?>
		<div class="page page-<?php echo esc_attr( $key ); ?>">
			<?php self::header_bar(); ?>

			<main id="main">
				<div class="shell page-top">
					<nav class="vp-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'vesla-landing' ); ?>">
						<a href="<?php echo esc_url( self::site_link() ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
						<span aria-hidden="true">/</span>
						<span aria-current="page"><?php echo esc_html( $head ); ?></span>
					</nav>
					<h1 class="page-title"><?php echo esc_html( $head ); ?></h1>
					<?php if ( '' !== $intro ) : ?>
						<p class="page-intro"><?php Vesla_Render::t( $owner . '.page_intro', $intro ); ?></p>
					<?php endif; ?>
				</div>

				<?php
				/* Full length, the same methods the homepage calls. One set of
				   markup, one set of fields, two places it appears. */
				foreach ( $page['sections'] as $section ) {
					if ( is_callable( array( __CLASS__, $section ) ) ) {
						call_user_func( array( __CLASS__, $section ) );
					}
				}
				?>

				<?php
				/* A page that is prose rather than sections. Run through the same
				   filter the rich fields elsewhere go through, so what an editor can
				   put on a legal page is what they can put anywhere else and no
				   more. */
				if ( ! empty( $page['rich'] ) ) :
					$prose = (string) Vesla_Settings::get( $owner, $page['rich'], '' );
					if ( '' !== trim( $prose ) ) :
						?>
						<section class="sec">
							<div class="shell prose reveal">
								<?php echo wp_kses_post( wpautop( $prose ) ); ?>
							</div>
						</section>
						<?php
					endif;
				endif;
				?>
			</main>

			<?php self::footer(); ?>
			<?php
			/* The same floating chrome the homepage has -- back to top, and the
			   call and WhatsApp bar. A reader who has just read the certification
			   stages is exactly the reader that bar is for, and a page without it
			   is a page they have to scroll back up to act from. */
			self::floating();
			?>
		</div>
		<?php
	}

	public static function contact_head() {
		$c    = self::contact_page_copy();
		$name = Vesla_Settings::get( 'seo', 'business_name', get_bloginfo( 'name' ) );
		/* Vesla_Publisher's, not this class's: the published address, which is
		   what every canonical on this site is written from. WordPress sits at
		   /cms and a canonical pointing there would name the admin install as
		   the real page. */
		$url  = trailingslashit( Vesla_Publisher::site_url() ) . 'contact/';
		$desc = self::plain( $c['lead'] );
		$desc = $desc ? wp_html_excerpt( $desc, 155, '…' ) : '';
		$head = trim( $c['heading'] . ' — ' . $name );

		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
		if ( $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:type" content="website">' . "\n" );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $head ) );
		if ( $desc ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		self::share_image_ld();
		self::breadcrumb_ld( $c['heading'] );
	}

	/**
	 * The first page of cars, as markup.
	 *
	 * app.js builds these in the browser, which is fine for a visitor and
	 * useless for everything else: a crawler that does not run scripts, a
	 * WhatsApp link preview, a reader with JavaScript off. A car dealer whose
	 * listings only exist after a fetch has no listings as far as those are
	 * concerned.
	 *
	 * So the same cards are built here too. The markup matches cardFor() in
	 * app.js class for class — if one changes, change the other, because the
	 * stylesheet serves both and app.js replaces these wholesale the first time
	 * a filter is touched.
	 *
	 * Only the first page is drawn, matching what a visitor sees before
	 * pressing "Show more". Every car, including the ones past that point, is
	 * still described in the ItemList structured data, which is what actually
	 * produces listing rich results.
	 */
	public static function cards_html( $limit = 0 ) {
		$cars = Vesla_Rest::cars();
		if ( ! $cars ) {
			return '';
		}

		/* Cheapest first, matching the sort menu's default. */
		usort( $cars, function ( $a, $b ) {
			if ( ! $a['price'] && ! $b['price'] ) { return 0; }
			if ( ! $a['price'] ) { return 1; }
			if ( ! $b['price'] ) { return -1; }
			return $a['price'] - $b['price'];
		} );

		/* Every car, not the first page of them.
		
		   The grid is rebuilt by app.js the moment it runs, so what is written
		   here is what a crawler reads and what somebody with no JavaScript
		   gets. Writing only the first eight meant the other sixteen had no
		   link anywhere on the site: they were in the sitemap and described in
		   the ItemList, but nothing linked to them, which is the one thing an
		   internal link is for.
		
		   The ones past the first page are marked rather than dropped. The
		   stylesheet folds them away where scripting is on -- so the page looks
		   exactly as it did and app.js still owns the Show more button -- and
		   leaves them showing where it is not, because with no script there is
		   no way to reveal them and a hidden car is worse than a long page.
		   display:none also means their photographs are never fetched, so this
		   costs the markup of sixteen cards and nothing else. */
		$limit = $limit ? (int) $limit : (int) Vesla_Settings::get( 'stock', 'per_page', 8 );
		$limit = max( 1, $limit );

		$ctx = self::card_context();
		$out = '';
		foreach ( $cars as $i => $car ) {
			$out .= self::card_html( $car, $ctx, array(
				'index' => $i,
				'later' => $i >= $limit,
				'eager' => $i < 3,
			) );
		}
		return $out;
	}

	/**
	 * Everything a card needs that is the same for every card.
	 *
	 * Read once and handed to card_html() rather than looked up inside it:
	 * eight settings reads per card across twenty-four cards is two hundred
	 * lookups to render one grid, and every one of them returns the same
	 * answer.
	 */
	private static function card_context() {
		return array(
			'currency' => (string) Vesla_Settings::get( 'stock', 'currency', '' ),
			'badge'    => (string) Vesla_Settings::get( 'stock', 'badge', '' ),
			'note'     => (string) Vesla_Settings::get( 'stock', 'price_note', '' ),
			'warranty' => (string) Vesla_Settings::get( 'stock', 'warranty_note', '' ),
			'enquire'  => (string) Vesla_Settings::get( 'stock', 'enquire_label', '' ),
			'seats_w'  => (string) Vesla_Settings::get( 'messages', 'seats_word', 'seats' ),
			'wa'       => preg_replace( '/\D/', '', (string) Vesla_Settings::get( 'brand', 'whatsapp', '' ) ),
			'wa_text'  => (string) Vesla_Settings::get( 'messages', 'wa_text', '' ),
		);
	}

	/**
	 * ONE CAR'S CARD, AND THE ONLY CARD TEMPLATE ON THIS SITE.
	 *
	 * The grid calls this and so does the featured strip above it. That is the
	 * point of it existing: two places drawing a car from two copies of this
	 * markup would drift the first time anybody changed the price line, and
	 * they would drift silently, because both would still look like cards.
	 *
	 * @param array $car  A car as Vesla_Rest::cars() returns it.
	 * @param array $ctx  card_context(), or null to read it here.
	 * @param array $args index — position, for the entrance stagger.
	 *                    later — fold it away past the first page (grid only).
	 *                    eager — fetch its photograph at high priority rather
	 *                            than lazily. True for what is on screen at
	 *                            once and false for everything else.
	 */
	/** 'reserved', 'sold', or '' for a car that is simply on the floor. */
	public static function car_status( $car ) {
		$s = isset( $car['status'] ) ? (string) $car['status'] : '';
		return in_array( $s, array( 'reserved', 'sold' ), true ) ? $s : '';
	}

	/**
	 * Is this car new to the floor?
	 *
	 * Reads both shapes it arrives in. The stored field is the string 'yes'
	 * from the editor; the payload app.js re-renders from carries the answer as
	 * a boolean, and card_html() is handed that payload -- so a check for 'yes'
	 * alone was false for every card the grid drew, which is all of them.
	 */
	public static function car_arrived( $car ) {
		if ( ! isset( $car['arrived'] ) ) {
			return false;
		}
		$v = $car['arrived'];
		return true === $v || 'yes' === $v || 1 === $v || '1' === $v;
	}

	public static function card_html( $car, $ctx = null, $args = array() ) {
		if ( null === $ctx ) {
			$ctx = self::card_context();
		}
		$args = array_merge( array( 'index' => 0, 'later' => false, 'eager' => false ), $args );

		$i        = (int) $args['index'];
		$later    = (bool) $args['later'];
		$eager    = (bool) $args['eager'];
		$badge    = $ctx['badge'];
		$note     = $ctx['note'];
		$warranty = $ctx['warranty'];
		$enquire  = $ctx['enquire'];
		$wa       = $ctx['wa'];
		$wa_text  = $ctx['wa_text'];

		$name  = trim( $car['make'] . ' ' . $car['model'] );
		/* A sold car shows no price. What it went for is between the showroom
		   and the buyer, and a price beside SOLD reads as an offer rather than
		   a record. */
		$price = ( $car['price'] && 'sold' !== Vesla_Render::car_status( $car ) )
			? trim( $ctx['currency'] . ' ' . number_format_i18n( (int) $car['price'] ) )
			: '';
		$img   = $car['image'];

		$specs = array();
		if ( $car['km'] ) { $specs[] = number_format_i18n( $car['km'] ) . ' km'; }
		if ( $car['body'] ) { $specs[] = $car['body']; }
		if ( $car['trans'] ) { $specs[] = $car['trans']; }
		$last = array();
		if ( $car['fuel'] ) { $last[] = $car['fuel']; }
		if ( $car['seats'] ) { $last[] = $car['seats'] . ' ' . $ctx['seats_w']; }
		if ( $last ) { $specs[] = implode( ' · ', $last ); }

		ob_start();
		?>
			<article class="card<?php echo $later ? ' card-later' : ''; ?>"
			         style="animation-delay:<?php echo (int) ( min( $i, 9 ) * 45 ); ?>ms">
				<div class="card-media<?php echo $img && $img['url'] ? ' has-photo' : ''; ?>">
					<?php
					/* The certified tag, and then whichever of the three states this car
					   is in. Reserved and sold are facts about availability and come
					   first; "just arrived" is a nudge and sits after. */
					$status  = Vesla_Render::car_status( $car );
					$labels  = Vesla_Settings::get( 'stock' );
					?>
					<?php if ( $badge && 'sold' !== $status ) : ?><span class="tag"><?php echo esc_html( $badge ); ?></span><?php endif; ?>
					<?php if ( 'reserved' === $status ) : ?>
						<span class="tag tag-reserved"><?php echo esc_html( $labels['reserved_label'] ); ?></span>
					<?php elseif ( 'sold' === $status ) : ?>
						<span class="tag tag-sold"><?php echo esc_html( $labels['sold_label'] ); ?></span>
					<?php endif; ?>
					<?php if ( Vesla_Render::car_arrived( $car ) && 'sold' !== $status ) : ?>
						<span class="tag tag-arrived"><?php echo esc_html( $labels['arrived_label'] ); ?></span>
					<?php endif; ?>
					<?php if ( $img && $img['url'] ) : ?>
						<?php /* width and height are always written: without them the
						         grid reflows as each photograph lands, which is the
						         single biggest cause of a shifting page. Cards below
						         the first row load lazily. */ ?>
						<img src="<?php echo esc_url( $img['url'] ); ?>"
						     alt="<?php echo esc_attr( $img['alt'] ? $img['alt'] : $name ); ?>"
						     width="<?php echo (int) ( $img['width'] ? $img['width'] : 640 ); ?>"
						     height="<?php echo (int) ( $img['height'] ? $img['height'] : 400 ); ?>"
						     <?php if ( ! empty( $img['srcset'] ) ) : ?>srcset="<?php echo esc_attr( $img['srcset'] ); ?>"<?php endif; ?>
						     <?php echo $eager ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
						     decoding="async">
					<?php else : ?>
						<div class="ph" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></div>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<div class="card-top">
						<div>
							<h3>
						<?php /* One link per card, wrapping the title. The photograph
						         and the body are made clickable by CSS stretching this
						         link across the card -- so there is ONE link a crawler
						         and a screen reader see, not three pointing at the same
						         place, and the buttons below it still work. */ ?>
						<?php if ( Vesla_Render::page_on( $car ) ) : ?>
							<a class="card-link" href="<?php echo esc_url( Vesla_Render::rel( Vesla_Render::permalink( $car ) ) ); ?>">
								<?php echo esc_html( $name ); ?>
							</a>
						<?php else : ?>
							<?php /* No page for this one, so no link: a card that goes nowhere is
							         worse than a card that plainly does not. */ ?>
							<?php echo esc_html( $name ); ?>
						<?php endif; ?>
					</h3>
							<?php if ( $car['year'] ) : ?><span class="yr"><?php echo (int) $car['year']; ?></span><?php endif; ?>
						</div>
						<?php if ( $price ) : ?>
							<div class="price"><?php echo esc_html( $price ); ?>
								<?php if ( $note ) : ?><small><?php echo esc_html( $note ); ?></small><?php endif; ?>
								<?php if ( $warranty ) : ?><em><?php echo esc_html( $warranty ); ?></em><?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( $specs ) : ?>
						<ul class="spec">
							<?php foreach ( $specs as $sp ) : ?><li><b><?php echo esc_html( $sp ); ?></b></li><?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<div class="card-act">
						<?php
						/* The point of the reserved state. A car somebody has already put a
						   deposit on goes on being looked at, and every Enquire press on it
						   is a telephone call the showroom answers with "that one has gone".
						   The card stays -- it is still worth seeing what has been moving --
						   and the two buttons that ask about it do not. */
						$quiet = '' !== Vesla_Render::car_status( $car );
						?>
						<?php if ( $quiet ) : ?>
							<span class="card-quiet"><?php echo esc_html( 'sold' === Vesla_Render::car_status( $car ) ? $labels['sold_note'] : $labels['reserved_note'] ); ?></span>
						<?php else : ?>
							<a class="btn btn-line js-enq" href="<?php echo esc_url( self::menu_href( '#contact' ) ); ?>"><?php echo esc_html( $enquire ); ?></a>
						<?php endif; ?>
						<?php if ( $wa && ! $quiet ) : ?>
							<a class="btn btn-wa"
							   href="<?php echo esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( sprintf( $wa_text, $name, $price ) ) ); ?>"
							   target="_blank" rel="noopener"
							   aria-label="<?php echo esc_attr( sprintf( __( 'WhatsApp us about the %s', 'vesla-landing' ), $name ) ); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2zm5.6 14.2c-.2.7-1.4 1.3-2 1.4-.5.1-1.1.1-1.8-.1-.4-.1-1-.3-1.7-.6-3-1.3-4.9-4.3-5.1-4.5-.1-.2-1.2-1.5-1.2-2.9s.7-2 1-2.3c.2-.3.5-.4.7-.4h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.4.5-.3.3c-.1.1-.2.3 0 .5.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.2.1.4.1.5-.1l.8-.9c.2-.2.3-.2.5-.1l2 1c.2.1.4.2.4.3.1.2.1.7-.1 1.3z"/></svg>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</article>
		<?php
		return ob_get_clean();
	}

	/* ── helpers ───────────────────────────────────────────────────────── */

	/**
	 * The logo, with its real dimensions written out.
	 *
	 * Without width and height the browser cannot reserve the space before the
	 * file arrives, so everything under it jumps as it lands. That shift is the
	 * single biggest contributor to the layout-stability score, and the logo is
	 * the worst offender because it sits at the very top of the page and in the
	 * hero — every pixel it moves, the whole page moves.
	 *
	 * The attributes are the image's INTRINSIC size, not its displayed size.
	 * The browser only needs the ratio; CSS decides how big it actually is.
	 */
	/**
	 * The bundled shield, at the smallest copy that covers the size asked for.
	 *
	 * The master is 2050px wide because it has to be; the masthead draws it at
	 * forty. Sending the master to every visitor of every page cost 262KB for
	 * a mark the size of a thumbnail, which was the single heaviest thing on
	 * the front page — heavier than any photograph of a car.
	 *
	 * @param int $need the widest the mark is ever drawn, in CSS pixels.
	 */
	private static function logo_file( $need ) {
		/* Twice the drawn size, so it still looks right on a retina screen. */
		foreach ( array( 96, 160, 640 ) as $w ) {
			if ( $need * 2 <= $w ) {
				$file = 'assets/brand/vesla-logo-' . $w . '.png';
				if ( file_exists( VESLA_DIR . $file ) ) {
					return $file;
				}
			}
		}
		return 'assets/brand/vesla-logo.png';
	}

	private static function logo_img( $attrs = '', $need = 48 ) {
		$id  = absint( Vesla_Settings::get( 'brand', 'logo', 0 ) );
		$img = Vesla_Rest::image( $id, $id ? '' : self::logo_file( $need ) );

		if ( ! $img || ! $img['url'] ) {
			return '';
		}

		return sprintf(
			'<img src="%s" alt=""%s%s decoding="async" %s>',
			esc_url( $img['url'] ),
			$img['width'] ? ' width="' . (int) $img['width'] . '"' : '',
			$img['height'] ? ' height="' . (int) $img['height'] . '"' : '',
			$attrs
		);
	}

	private static function logo_url( $need = 96 ) {
		$id = absint( Vesla_Settings::get( 'brand', 'logo', 0 ) );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		return VESLA_URL . self::logo_file( $need );
	}

	/** The brand lockup: the shield plus the two lines of the company name. */
	private static function lockup( $context = 'bar' ) {
		$top    = Vesla_Settings::get( 'brand', 'name_top', '' );
		$bottom = Vesla_Settings::get( 'brand', 'name_bottom', '' );
		?>
		<a class="brand<?php echo 'foot' === $context ? ' brand-foot' : ''; ?>" href="<?php echo esc_url( self::menu_href( '#top' ) ); ?>"
		   aria-label="<?php echo esc_attr( trim( $top . ' ' . $bottom ) ); ?>">
			<?php echo self::logo_img( 'class="brand-mark"', 48 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
			<span class="brand-txt"><b><?php echo esc_html( $top ); ?></b><i><?php echo esc_html( $bottom ); ?></i></span>
		</a>
		<?php
	}

	private static function icon( $name ) {
		$paths = array(
			'shield'  => '<path d="M12 3l7.5 3.4v5c0 4.4-3.1 8.3-7.5 9.6-4.4-1.3-7.5-5.2-7.5-9.6v-5z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
			'award'   => '<circle cx="12" cy="9" r="5.2"/><path d="M8.4 13.2L7 21l5-2.4 5 2.4-1.4-7.8"/>',
			'spanner' => '<path d="M14.6 5.4a4 4 0 0 0 5.2 5.2l-8.4 8.4a2.6 2.6 0 1 1-3.7-3.7z"/><path d="M6.5 4.5l2.7 2.7"/>',
			'doc'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9.5 14.2l1.9 1.9 3.6-3.9"/>',
			'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
			'car'     => '<path d="M4 15h16"/><path d="M6 15l1.6-5.2A2 2 0 0 1 9.5 8.4h5a2 2 0 0 1 1.9 1.4L18 15"/><path d="M5 15v3h3v-3M16 15v3h3v-3"/>',
		);
		$d = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['shield'];
		echo '<svg viewBox="0 0 24 24" aria-hidden="true">' . $d . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed literals above
	}

	/* ── loader ────────────────────────────────────────────────────────── */

	/** Set while Vesla_Publisher is writing the static file. */
	public static $static_build = false;

	/**
	 * The opening: the shield arrives on its own, then settles into the hero.
	 *
	 * What replaced the film, and cheaper in every direction: no upload, no
	 * encode, no megabyte to fetch before anything can happen. It is the logo
	 * the site already loads, on the site's own ground, moved with transform
	 * and opacity and nothing else.
	 *
	 * Three movements. It fades up from 0.94 over 900ms; it drifts on to 1.04
	 * over 400ms so it is never quite still; then over 450ms it scales and
	 * translates on to the hero shield while the ground fades out from under
	 * it, and the hero's own copy rises a beat behind. About 1.75s in total.
	 *
	 * The ground is var(--void) flat throughout -- there is no colour walk to
	 * do any more, because a transparent PNG on the page's own ground has
	 * nothing to reconcile. The film needed one; this does not.
	 *
	 * Nothing is printed at all when it is switched off, and with scripting
	 * off the markup is inert: the overlay is display:none until the script
	 * opens it, so a reader without JavaScript gets the page and no curtain.
	 */
	private static function opening() {
		$show = (string) Vesla_Settings::get( 'extras', 'opening_show', 'first' );
		if ( 'never' === $show ) {
			return;
		}
		$logo = self::logo_url();
		if ( ! $logo ) {
			return;
		}
		$max  = max( 1, (int) Vesla_Settings::get( 'extras', 'opening_max', 4 ) );
		$skip = trim( (string) Vesla_Settings::get( 'extras', 'opening_skip_label', '' ) );
		$skip = '' !== $skip ? $skip : __( 'Skip', 'vesla-landing' );

		/* Whether prefers-reduced-motion is consulted at all -- the same gate
		   the rest of the site answers to, so the setting governs this too
		   rather than the browser deciding on its own. See the long note
		   beside the check itself. */
		$respect = (bool) Vesla_Settings::get( 'extras', 'respect_reduced_motion', 0 );
		?>
<div class="vesla-open" id="vesla-open">
	<img class="vesla-open-mark" id="vesla-open-mark" src="<?php echo esc_url( $logo ); ?>" alt=""
	     decoding="async" fetchpriority="high">
	<button type="button" class="vesla-open-skip" id="vesla-open-skip"><?php echo esc_html( $skip ); ?></button>
</div>
<script id="vesla-open-js">
(function(){
	var root = document.documentElement;
	var box  = document.getElementById('vesla-open');
	var mark = document.getElementById('vesla-open-mark');
	var skip = document.getElementById('vesla-open-skip');
	if (!box || !mark) { return; }

	var SHOW = '<?php echo esc_js( $show ); ?>';
	var MAX  = <?php echo (int) $max; ?> * 1000;
	var SEEN = 'vesla-open-seen';
	var LAST = 'vesla-open-last';
	var GAP  = 600000;   /* ten minutes, as a gap between showings */

	/* Timings, in one place so the sequence can be read as a whole:
	   900 in, 400 drifting, 450 settling -- about 1.75s door to door. */
	var IN = 900, HOLD = 400, OUT = 450, BEAT = 120;

	/* ASKED FOR is the whole difficulty, and why this is behind a setting
	   rather than read straight from the browser. Windows reports
	   "prefers-reduced-motion: reduce" from the Visual effects switch and
	   from battery saver, neither of which is a considered accessibility
	   choice -- SPI_GETCLIENTAREAANIMATION is what Chrome, Edge and Firefox
	   all map the query to on Windows, so an ordinary laptop with animations
	   dimmed reports the same thing as somebody with vestibular illness.

	   So the query is only consulted when the administrator has switched
	   "Respect reduced motion" on. Off, the opening plays for everyone; on,
	   it is removed for anyone whose browser asks, and the reduced-motion CSS
	   block Vesla_Render prints is emitted by the same setting, so the two
	   cannot disagree.

	   DO NOT replace this with a bare matchMedia call. That is the bug this
	   comment exists to prevent, and it looks like a fix. */
	var RESPECT = <?php echo $respect ? 'true' : 'false'; ?>;
	var reduced = false;
	if (RESPECT) {
		try { reduced = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
	}
	if (reduced) { if (box.parentNode) { box.parentNode.removeChild(box); } return; }

	/* Reading storage can throw, not only writing it: Safari with cookies
	   blocked throws on the read. A browser that will not remember simply
	   sees the opening again, which is the harmless way to be wrong. */
	var read  = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
	var write = function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} };

	var running = false, arrival = false;
	var ceiling = null, phase = null, beat = null;

	/* Where the mark is going, measured from the DOM at the moment of asking.

	   THE HEADER'S MARK, not the hero's. The hero shield was the obvious
	   target and the wrong one: it is display:none under 821px and it does not
	   exist at all under the film hero style, so the settle fell back to a
	   plain cross-fade for every phone visitor and for everybody seeing the
	   film. That is most of the traffic getting the fallback rather than the
	   thing that was built. The header mark is present at every width and
	   under both styles, so there is now no ordinary case without a target.

	   #bar rather than a bare .brand-mark: the footer prints the same lockup
	   through the same function, and an unqualified selector would sometimes
	   find the footer's copy instead and send the mark off the bottom of the
	   page.

	   Nothing is hard-coded and nothing can be -- the bar's mark moves with
	   the viewport like everything else, and getBoundingClientRect is read at
	   the moment of the settle rather than stored.

	   Both pictures come from logo_img()/logo_url(), which resolve the same
	   brand.logo attachment, so they are the SAME artwork at two rendered
	   sizes and whatever padding it carries cancels between them. That is why
	   this is a ratio of two rectangles rather than a table of measured
	   constants. If the header is ever given a different mark, this stops
	   being true and the scale needs deriving instead of assuming. */
	var landing = function () {
		var img = document.querySelector('#bar .brand-mark');
		if (!img) { return null; }
		var s = img.getBoundingClientRect();
		/* Kept, though there is no longer a width at which this is expected to
		   fire: the bar's mark is not hidden at any breakpoint. It stands for a
		   header that has been removed or restyled away, and the answer to that
		   is still a cross-fade rather than a guess. */
		if (!s.width || !s.height) { return null; }

		/* The mark's own UNTRANSFORMED box. offsetWidth rather than a
		   rectangle, because by this point the mark is carrying the drift and
		   getBoundingClientRect would measure 1.04 of itself. */
		var mw = mark.offsetWidth, mh = mark.offsetHeight;
		if (!mw || !mh) { return null; }
		var mr = mark.getBoundingClientRect();
		var cx = mr.left + mr.width / 2, cy = mr.top + mr.height / 2;

		var k  = s.width / mw;
		var tx = (s.left + s.width / 2) - cx;
		var ty = (s.top + s.height / 2) - cy;

		/* translate before scale, origin at the centre: the centre lands on
		   the shield's centre and the mark scales about that point. The other
		   way round the translation would itself be scaled. */
		return 'translate(' + tx.toFixed(2) + 'px,' + ty.toFixed(2) + 'px) scale(' + k.toFixed(4) + ')';
	};

	var closed = false, onFade = null;
	var shut = function () {
		if (closed) { return; }
		closed = true;
		box.removeEventListener('transitionend', onFade);
		box.classList.remove('is-entering');
		box.classList.remove('is-holding');
		box.classList.remove('is-settling');
		box.classList.remove('is-going');
		box.classList.remove('is-open');
		mark.style.transform = '';
		root.classList.remove('vesla-open-hold');
		root.classList.remove('vesla-open-run');
	};

	var end = function () {
		if (!running) { return; }
		running = false;
		clearTimeout(ceiling); clearTimeout(phase); clearTimeout(beat);

		/* Two ways out, and which is available is a question about the page
		   rather than a setting: a shield on screen to land on, or not. */
		/* is-entering and is-holding STAY ON, deliberately. is-entering is what
		   makes the mark visible at all -- taking it off here dropped the mark
		   straight back to the base rule's opacity:0 and scale(.94), so it
		   blinked out of existence at the exact moment it was supposed to
		   travel. The settle overrides what it needs to: the transform comes
		   from the inline style, which beats both, and is-settling is later in
		   the stylesheet than either, so its transition wins on equal
		   specificity. */
		var target = landing();

		if (target) {
			box.classList.add('is-settling');
			mark.style.transform = target;
			/* The beat. The hero is let go 120ms in so it rises behind the
			   mark rather than with it. This is a timer and is meant to be
			   one -- it is choreography, an offset between two movements, not
			   a test for whether anything has finished. The thing that must
			   never go back to a clock, deciding the overlay is done, is on
			   transitionend below. */
			beat = setTimeout(function () { root.classList.remove('vesla-open-hold'); }, BEAT);
		} else {
			box.classList.add('is-going');
			/* The same beat on this path too. There is no shield to land on
			   here, but the page still wants to arrive behind the curtain
			   rather than under it -- and the film hero, which has no shield
			   by definition, always takes this branch. */
			beat = setTimeout(function () { root.classList.remove('vesla-open-hold'); }, BEAT);
		}
		root.classList.remove('vesla-open-run');

		/* Taken away when the fade has actually finished, not on a timer. A
		   fixed delay was wrong here once already: with the browser busy the
		   transition did not begin for nearly 300ms after the class was set,
		   and the tidy-up arrived mid-dissolve. The event knows when it is
		   really over and a clock does not. The timer stays only as a
		   backstop, for a transition that never fires at all -- a backgrounded
		   tab, say -- so nobody is stranded behind the curtain. */
		onFade = function (e) {
			if (e.target === box && e.propertyName === 'opacity') { shut(); }
		};
		box.addEventListener('transitionend', onFade);
		setTimeout(shut, OUT + 1200);
	};

	var start = function (lock) {
		if (running) { return; }
		running = true;
		arrival = !!lock;
		write(LAST, String(Date.now()));

		box.classList.add('is-open');
		if (lock) { root.classList.add('vesla-open-run'); }
		/* Hold the hero down while the mark is over it, so there is something
		   left to rise when it lands. */
		root.classList.add('vesla-open-hold');

		/* The ceiling. Whatever happens, nobody is kept here. */
		ceiling = setTimeout(end, MAX);

		/* One frame between the element being shown and the class that moves
		   it, or the browser resolves both together and there is no
		   transition to run at all -- the mark would simply appear. */
		requestAnimationFrame(function () {
			requestAnimationFrame(function () {
				box.classList.add('is-entering');
				phase = setTimeout(function () {
					box.classList.add('is-holding');
					phase = setTimeout(end, HOLD);
				}, IN);
			});
		});
	};

	/* The loading screen comes off when the opening has ACTUALLY started, not
	   when it was asked to.

	   transitionstart is the honest signal, and it is the same distinction
	   the film drew with 'playing': a suppressed loader with nothing behind
	   it is a blank arrival. If the image never decodes, or the transition
	   never runs, this never fires and the loading screen carries on doing
	   its job untouched. */
	mark.addEventListener('transitionstart', function once (e) {
		if (e.propertyName !== 'opacity' && e.propertyName !== 'transform') { return; }
		mark.removeEventListener('transitionstart', once);
		if (arrival) { root.classList.remove('is-loading'); }
	});

	if (skip) { skip.addEventListener('click', end); }
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { end(); } });

	/* ── one: arrival ──
	   Started only once the mark is really there. Opening on an empty frame
	   is the one thing a curtain must not do, and a cached image is complete
	   before this line runs, so the common path costs nothing. */
	var arriving = true;
	if (SHOW === 'first') {
		if (read(SEEN)) { arriving = false; }
		write(SEEN, '1');
	}
	if (arriving) {
		if (mark.complete && mark.naturalWidth) { start(true); }
		else {
			mark.addEventListener('load', function () { start(true); });
			mark.addEventListener('error', function () {
				if (box.parentNode) { box.parentNode.removeChild(box); }
			});
		}
	}

	/* ── two: the Home link, from somewhere down the page ──
	   Deliberately NOT prevented and deliberately not locking the page. Home
	   is an ordinary same-page anchor and the browser's own jump is what puts
	   the reader at the top; the overlay simply covers it while it happens. */
	document.addEventListener('click', function (e) {
		var a = (e.target && e.target.closest) ? e.target.closest('a[href]') : null;
		if (!a || !/#top$/.test(a.getAttribute('href') || '')) { return; }
		if (running) { return; }
		/* Already up here: an opening between the click and the same view is
		   not an introduction, it is a delay. One screen down is the bar. */
		var y = window.scrollY || window.pageYOffset || 0;
		if (y <= window.innerHeight) { return; }
		/* And not twice in ten minutes, however often Home is pressed. */
		var last = parseInt(read(LAST) || '0', 10);
		if (last && (Date.now() - last) < GAP) { return; }
		if (mark.complete && mark.naturalWidth) { start(false); }
	});
})();
</script>
		<?php
	}

	private static function loader() {
		/* A published file arrives with its content already in it, so there is
		   nothing to cover up while waiting. */
		if ( self::$static_build ) {
			return;
		}
		/* Printed whenever a car can be opened from here, even with the opening
		   curtain switched off: moving to a car reuses this node, and without it
		   the transition would have nothing to show. It is display:none until
		   something adds the html class. */
		if ( ! Vesla_Settings::get( 'extras', 'loader_enabled', 0 )
			&& ! Vesla_Settings::get( 'vehicle', 'enabled', 1 ) ) {
			return;
		}
		$logo = self::logo_url();
		?>
		<div class="preload" id="preload" role="status" aria-label="<?php esc_attr_e( 'Loading', 'vesla-landing' ); ?>">
			<?php /* The artwork is written by the server and animated by the
			         stylesheet, so it is already moving in the first painted frame
			         rather than waiting for a script. That is the whole point of
			         it: a curtain that starts moving once the page behind it is
			         built has animated AFTER the wait instead of during it.
			
			         Decorative, so hidden from the accessibility tree; the status
			         role on the wrapper is what a screen reader announces. */ ?>
			<div class="pl-art" aria-hidden="true">
			<svg class="pl-wires" viewBox="200 95 500 250" aria-hidden="true" focusable="false">
				<?php /* FOUR sibling groups, in this order: tracks, pulses, elbows,
				         tips. The stylesheet reaches the pulses and the tips by their
				         position among these siblings, so reordering them would stop
				         the animation without breaking anything visible in markup. */ ?>
				<g class="pl-track"><path d="M240,120 L364.26,120 L382,150.72"/><path d="M240,160 L339.42,160 L357.28,172.98"/><path d="M240,200 L327.91,200 L343.75,203.37"/><path d="M240,240 L327.91,240 L343.75,236.63"/><path d="M240,280 L339.42,280 L357.28,267.02"/><path d="M240,320 L364.26,320 L382,289.28"/><path d="M660,120 L535.74,120 L518,150.72"/><path d="M660,160 L560.58,160 L542.72,172.98"/><path d="M660,200 L572.09,200 L556.25,203.37"/><path d="M660,240 L572.09,240 L556.25,236.63"/><path d="M660,280 L560.58,280 L542.72,267.02"/><path d="M660,320 L535.74,320 L518,289.28"/></g>
				<g class="pl-pulses"><path class="pl-pulse" d="M382,150.72 L364.26,120 L240,120" style="stroke-dasharray:26 160;stroke-dashoffset:186;animation-delay:0ms"/><path class="pl-pulse" d="M357.28,172.98 L339.42,160 L240,160" style="stroke-dasharray:26 121;stroke-dashoffset:147;animation-delay:140ms"/><path class="pl-pulse pl-gold" d="M343.75,203.37 L327.91,200 L240,200" style="stroke-dasharray:26 104;stroke-dashoffset:130;animation-delay:280ms"/><path class="pl-pulse" d="M343.75,236.63 L327.91,240 L240,240" style="stroke-dasharray:26 104;stroke-dashoffset:130;animation-delay:420ms"/><path class="pl-pulse" d="M357.28,267.02 L339.42,280 L240,280" style="stroke-dasharray:26 121;stroke-dashoffset:147;animation-delay:560ms"/><path class="pl-pulse" d="M382,289.28 L364.26,320 L240,320" style="stroke-dasharray:26 160;stroke-dashoffset:186;animation-delay:700ms"/><path class="pl-pulse" d="M518,150.72 L535.74,120 L660,120" style="stroke-dasharray:26 160;stroke-dashoffset:186;animation-delay:140ms"/><path class="pl-pulse" d="M542.72,172.98 L560.58,160 L660,160" style="stroke-dasharray:26 121;stroke-dashoffset:147;animation-delay:280ms"/><path class="pl-pulse pl-gold" d="M556.25,203.37 L572.09,200 L660,200" style="stroke-dasharray:26 104;stroke-dashoffset:130;animation-delay:420ms"/><path class="pl-pulse" d="M556.25,236.63 L572.09,240 L660,240" style="stroke-dasharray:26 104;stroke-dashoffset:130;animation-delay:560ms"/><path class="pl-pulse" d="M542.72,267.02 L560.58,280 L660,280" style="stroke-dasharray:26 121;stroke-dashoffset:147;animation-delay:700ms"/><path class="pl-pulse" d="M518,289.28 L535.74,320 L660,320" style="stroke-dasharray:26 160;stroke-dashoffset:186;animation-delay:840ms"/></g>
				<g class="pl-elbow"><circle cx="382" cy="150.72" r="4" style="animation-delay:0ms"/><circle cx="357.28" cy="172.98" r="4" style="animation-delay:140ms"/><circle cx="343.75" cy="203.37" r="4" style="animation-delay:280ms"/><circle cx="343.75" cy="236.63" r="4" style="animation-delay:420ms"/><circle cx="357.28" cy="267.02" r="4" style="animation-delay:560ms"/><circle cx="382" cy="289.28" r="4" style="animation-delay:700ms"/><circle cx="518" cy="150.72" r="4" style="animation-delay:140ms"/><circle cx="542.72" cy="172.98" r="4" style="animation-delay:280ms"/><circle cx="556.25" cy="203.37" r="4" style="animation-delay:420ms"/><circle cx="556.25" cy="236.63" r="4" style="animation-delay:560ms"/><circle cx="542.72" cy="267.02" r="4" style="animation-delay:700ms"/><circle cx="518" cy="289.28" r="4" style="animation-delay:840ms"/></g>
				<g class="pl-tips"><circle class="pl-tip" cx="240" cy="120" r="4" style="animation-delay:0ms"/><circle class="pl-tip" cx="240" cy="160" r="4" style="animation-delay:140ms"/><circle class="pl-tip pl-gold-tip" cx="240" cy="200" r="4.5" style="animation-delay:280ms"/><circle class="pl-tip" cx="240" cy="240" r="4" style="animation-delay:420ms"/><circle class="pl-tip" cx="240" cy="280" r="4" style="animation-delay:560ms"/><circle class="pl-tip" cx="240" cy="320" r="4" style="animation-delay:700ms"/><circle class="pl-tip" cx="660" cy="120" r="4" style="animation-delay:140ms"/><circle class="pl-tip" cx="660" cy="160" r="4" style="animation-delay:280ms"/><circle class="pl-tip pl-gold-tip" cx="660" cy="200" r="4.5" style="animation-delay:420ms"/><circle class="pl-tip" cx="660" cy="240" r="4" style="animation-delay:560ms"/><circle class="pl-tip" cx="660" cy="280" r="4" style="animation-delay:700ms"/><circle class="pl-tip" cx="660" cy="320" r="4" style="animation-delay:840ms"/></g>
			</svg>
				<?php /* Two stacked copies of the shield: a dim one so the mark is
				         present before the flood reaches it, and a bright one clipped
				         open from the left. */ ?>
				<span class="pl-logo">
					<img class="pl-base" src="<?php echo esc_url( $logo ); ?>" alt="" decoding="async">
					<img class="pl-flood" src="<?php echo esc_url( $logo ); ?>" alt="" decoding="async">
				</span>
			</div>
		</div>
		<?php
	}

	/* ── header ────────────────────────────────────────────────────────── */

	private static function header_bar() {
		$menu = (array) Vesla_Settings::get( 'header', 'menu', array() );
		?>
		<a class="skip" href="<?php echo esc_url( self::menu_href( '#stock' ) ); ?>"><?php esc_html_e( 'Skip to the cars', 'vesla-landing' ); ?></a>

		<header class="bar" id="bar">
			<div class="shell bar-in">
				<?php self::lockup( 'bar' ); ?>

				<nav class="nav" id="nav" aria-label="<?php esc_attr_e( 'Main menu', 'vesla-landing' ); ?>">
					<?php foreach ( $menu as $item ) : ?>
						<?php if ( $item['label'] ) : ?>
							<?php /* A row with no destination is left out, not drawn dead. */ ?>
							<?php $href = self::menu_href( $item['link'], true ); ?>
							<?php if ( '' !== $href ) : ?>
								<a href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					<?php endforeach; ?>
				</nav>

				<?php /* The bar's two buttons -- the toll-free number and the CTA --
				         were removed here on request. The header is the lockup and the
				         menu now. Contact is still reachable from the menu, and the
				         phone numbers are still on the Contact page and in the footer,
				         so nothing has been taken away from the reader, only from this
				         row. */ ?>

				<button class="burger" id="burger" type="button"
				        aria-label="<?php esc_attr_e( 'Menu', 'vesla-landing' ); ?>" aria-expanded="false" aria-controls="nav">
					<span></span><span></span><span></span>
				</button>
			</div>
		</header>
		<?php
	}

	/* ── hero ──────────────────────────────────────────────────────────── */

	/**
	 * The opening section's words: eyebrow, heading, paragraph, both buttons.
	 *
	 * ONE COPY, PRINTED BY BOTH STYLES, and that is the whole point of it
	 * being a function. The classic hero and the film hero are two
	 * presentations of the same section, not two sections -- so the words are
	 * written once here and read from one set of settings fields. Editing the
	 * heading changes whichever style is switched on, and there is no second
	 * place to forget.
	 *
	 * It also makes the search-engine guarantee structural rather than a
	 * promise kept by hand. The h1 is a real h1 in the HTML that leaves the
	 * server, both links are real anchors with real hrefs, and the paragraph
	 * is text -- in BOTH styles, because there is only one piece of code that
	 * can produce them. Nothing here is injected by script and nothing waits
	 * on the film: the film is decoration painted behind words that are
	 * already in the document.
	 *
	 * @param array $h     The hero section's settings.
	 * @param bool  $video Whether the film style is in force. Adds the
	 *                     stagger classes and nothing else -- never a change
	 *                     to what is said or to the shape of the markup.
	 */
	private static function hero_copy( $h, $video = false ) {
		$n1 = $video ? ' v-1' : '';
		$n2 = $video ? ' v-2' : '';
		$n3 = $video ? ' v-3' : '';
		$n4 = $video ? ' v-4' : '';
		?>
		<?php if ( $h['eyebrow'] ) : ?>
			<p class="eyebrow reveal<?php echo esc_attr( $n1 ); ?>"><?php echo esc_html( $h['eyebrow'] ); ?></p>
		<?php endif; ?>
		<h1 class="reveal<?php echo esc_attr( $n2 ); ?>"><?php echo esc_html( $h['heading'] ); ?></h1>
		<?php if ( $h['lead'] ) : ?>
			<p class="hero-lead reveal<?php echo esc_attr( $n3 ); ?>"><?php echo esc_html( $h['lead'] ); ?></p>
		<?php endif; ?>
		<div class="hero-act reveal<?php echo esc_attr( $n4 ); ?>">
			<?php if ( $h['btn1_label'] ) : ?>
				<a class="btn btn-gold btn-lg" href="<?php echo esc_url( $h['btn1_link'] ); ?>"><?php echo esc_html( $h['btn1_label'] ); ?></a>
			<?php endif; ?>
			<?php if ( $h['btn2_label'] ) : ?>
				<a class="btn btn-line btn-lg" href="<?php echo esc_url( $h['btn2_link'] ); ?>"><?php echo esc_html( $h['btn2_label'] ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The row of figures under the opening section.
	 *
	 * Shared by both styles for the same reason the words are: these four are
	 * the showroom's credibility and they sit above the fold, so which
	 * presentation is switched on must not decide whether they exist. They
	 * were briefly absent from the film style and that was a content
	 * regression, not a design choice.
	 *
	 * @param array $h     The hero section's settings.
	 * @param bool  $video Whether the film style is in force. Adds the last
	 *                     step of the stagger, so the figures arrive after the
	 *                     buttons rather than with them.
	 */
	private static function hero_stats( $h, $video = false ) {
		if ( empty( $h['stats'] ) ) {
			return;
		}
		?>
		<ul class="hero-stats reveal<?php echo $video ? ' v-5' : ''; ?>">
			<?php foreach ( $h['stats'] as $s ) : ?>
				<li>
					<?php if ( $s['count'] && is_numeric( $s['value'] ) ) : ?>
						<b data-count="<?php echo esc_attr( $s['value'] ); ?>"
						   <?php echo $s['suffix'] ? 'data-suffix="' . esc_attr( $s['suffix'] ) . '"' : ''; ?>>0</b>
					<?php else : ?>
						<b><?php echo esc_html( $s['value'] . $s['suffix'] ); ?></b>
					<?php endif; ?>
					<span><?php echo esc_html( $s['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private static function hero() {
		$h = Vesla_Settings::get( 'hero' );

		/* The film style is only ever honoured with a poster behind it. The
		   sanitiser already refuses to store the one without the other, so
		   this is the second of two locks rather than the only one -- settings
		   can arrive from an import or an older database, and the failure it
		   guards against is the top of the front page being a black rectangle. */
		$style  = isset( $h['style'] ) ? (string) $h['style'] : 'classic';
		$poster = isset( $h['poster'] ) ? (int) $h['poster'] : 0;

		/* Resolved to a real URL here, not inside hero_video(), so that a
		   poster whose file has been deleted from the library since falls
		   straight through to the classic style. Deciding it there and calling
		   back would be a loop, because this function reads the setting again. */
		$poster_url = $poster ? wp_get_attachment_image_url( $poster, 'full' ) : '';
		if ( 'video' === $style && $poster_url ) {
			self::hero_video( $h, $poster, $poster_url );
			return;
		}
		?>
		<section class="hero" id="top">
			<div class="hero-bg" aria-hidden="true"></div>
			<div class="shell hero-in">
				<div class="hero-copy">
					<?php self::hero_copy( $h, false ); ?>
				</div>

				<?php if ( $h['show_logo'] ) : ?>
					<div class="hero-logo reveal d1" aria-hidden="true">
						<?php
						/* Above the fold: fetched early and never lazily.
						
						   340 is the width the stylesheet draws it at, and saying so matters:
						   left to the default this call asked for a 48px mark and was handed the
						   96px file, which the page then stretched across three hundred and
						   forty. That is why the shield looked soft here and crisp everywhere
						   else -- it was a 96px picture blown up more than three times. */
						?>
						<?php echo self::logo_img( 'fetchpriority="high"', 340 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
					</div>
				<?php endif; ?>

				<?php self::hero_stats( $h, false ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The opening section as a film with the words over it.
	 *
	 * THE FILM IS DECORATION. Everything a reader or a crawler needs is in the
	 * markup before the film is mentioned: the h1, the paragraph and both
	 * links come from hero_copy(), the same function the classic style uses,
	 * and they are in the HTML that leaves the server. If the file never
	 * loads, if autoplay is refused, if scripting is off, if the connection
	 * dies after the HTML -- the section is still the poster, the words and
	 * the buttons, and it still reads. That is the order things are built in
	 * here, and it is not an accident.
	 *
	 * The poster is an ordinary <img>, not the video's poster attribute, and
	 * the film is transparent until it is genuinely playing. That way there is
	 * never a black rectangle: the picture is painted immediately by the same
	 * markup that would be there with no script at all, and the film fades in
	 * over it if and when it arrives. The video's own poster attribute would
	 * have fetched the same file a second time.
	 *
	 * The height is fixed in CSS -- clamp(280px, 50vh, 600px) -- so the space
	 * is reserved before anything loads and nothing below can be pushed down.
	 * No aspect-ratio box is needed when the box does not depend on the media.
	 */
	private static function hero_video( $h, $poster_id, $poster ) {
		$src = '';
		if ( ! empty( $h['video'] ) ) {
			$src = (string) wp_get_attachment_url( (int) $h['video'] );
		}
		$poster_alt = trim( (string) get_post_meta( $poster_id, '_wp_attachment_image_alt', true ) );

		/* 0-60 in the editor, carried as a fraction so the gradient can scale
		   every stop from one number. */
		$dark    = max( 0, min( 60, (int) ( isset( $h['overlay'] ) ? $h['overlay'] : 35 ) ) );
		$respect = (bool) Vesla_Settings::get( 'extras', 'respect_reduced_motion', 0 );
		?>
		<section class="hero hero-v" id="top" style="--scrim:<?php echo esc_attr( number_format( $dark / 100, 3, '.', '' ) ); ?>">
			<div class="hero-v-media" aria-hidden="true">
				<img class="hero-v-poster" src="<?php echo esc_url( $poster ); ?>"
				     alt="<?php echo esc_attr( $poster_alt ); ?>" fetchpriority="high" decoding="async">
				<?php if ( $src ) : ?>
					<?php /* preload="metadata", never "auto": this sits above the fold and
					         must not race the words and the pictures for the connection.
					         No controls, no sound, nothing clickable -- it is a backdrop. */ ?>
					<video class="hero-v-film" id="hero-v-film" muted loop playsinline preload="metadata" tabindex="-1">
						<source src="<?php echo esc_url( $src ); ?>" type="video/mp4">
					</video>
				<?php endif; ?>
				<div class="hero-v-scrim"></div>
			</div>
			<div class="shell hero-v-in">
				<div class="hero-copy">
					<?php self::hero_copy( $h, true ); ?>
				</div>
				<?php self::hero_stats( $h, true ); ?>
			</div>
		</section>
		<?php if ( $src ) : ?>
		<script id="hero-v-js">
		(function(){
			var sec  = document.getElementById('top');
			var film = document.getElementById('hero-v-film');
			if (!sec || !film) { return; }

			/* Gated on the setting, exactly as everything else is. Windows
			   answers "reduce" for its Visual effects switch and for battery
			   saver, neither of which is a considered choice, so the browser is
			   only consulted when the administrator has asked for it to be.
			   When it is: the poster stays and the film is never fetched
			   beyond its metadata. */
			var RESPECT = <?php echo $respect ? 'true' : 'false'; ?>;
			var reduced = false;
			if (RESPECT) {
				try { reduced = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
			}
			if (reduced) { return; }

			var started = false, visible = true;

			var attempt = function () {
				if (!visible) { return; }
				var p;
				try { p = film.play(); } catch (e) { p = null; }
				/* Refused is a normal answer, not an error: iOS in Low Power
				   Mode refuses even a muted film. The poster is already on
				   screen and stays there, so there is nothing to do about it
				   and nothing to tell anybody. */
				if (p && p.catch) { p.catch(function () {}); }
			};

			/* Playback waits for the words. The film must never be the reason
			   the heading is late, so nothing is asked of the network for it
			   until the page has finished loading everything that matters. */
			var begin = function () {
				if (started) { return; }
				started = true;
				attempt();
			};
			if (document.readyState === 'complete') { begin(); }
			else { window.addEventListener('load', begin); }

			film.addEventListener('playing', function () { sec.classList.add('is-playing'); });
			/* A file that will not decode leaves the poster where it is. */
			film.addEventListener('error', function () { sec.classList.remove('is-playing'); });

			/* Out of view it is paused. A looping film playing to nobody is
			   battery and bandwidth spent on nothing, and this is the top of
			   the page -- it is out of view for most of the visit. */
			if ('IntersectionObserver' in window) {
				new IntersectionObserver(function (entries) {
					entries.forEach(function (en) {
						visible = en.isIntersecting;
						if (!visible) { try { film.pause(); } catch (e) {} }
						else if (started) { attempt(); }
					});
				}, { threshold: 0.01 }).observe(sec);
			}
		})();
		</script>
		<?php endif; ?>
		<?php
	}

	/* ── trust ─────────────────────────────────────────────────────────── */

	private static function trust() {
		if ( ! Vesla_Settings::enabled( 'trust' ) ) {
			return;
		}
		$items = (array) Vesla_Settings::get( 'trust', 'items', array() );
		if ( ! $items ) {
			return;
		}
		?>
		<section class="trust" aria-label="<?php esc_attr_e( 'Why buy from us', 'vesla-landing' ); ?>">
			<div class="shell">
				<ul class="trust-in">
					<?php /* .reveal and nothing else: the stagger is worked out by
					         position in motion.js, because .trust-in is a grid row, so
					         four items arriving together get their own delays without
					         any being written here. They inherit the two-way behaviour
					         and the reduced-motion gate along with it. */ ?>
					<?php foreach ( $items as $it ) : ?>
						<li class="reveal">
							<?php self::icon( $it['icon'] ); ?>
							<span><b><?php echo esc_html( $it['title'] ); ?></b><?php echo esc_html( $it['text'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php
	}

	/* ── stock ─────────────────────────────────────────────────────────── */

	/**
	 * The Spotlight: a turning row of photographs above the grid.
	 *
	 * THREE TO FIVE CARS, AND THE COUNT IS THE DESIGN. The flow places two
	 * either side of the one facing forward, so five is exactly full. A sixth
	 * would sit at a distance where it is neither readable nor gone, which is
	 * what the old strip of eight looked like from the third card out.
	 *
	 * PHOTOGRAPHS, NOT CARDS. The grid below is where a car's spec, its
	 * Enquire and its WhatsApp belong; there is no sense in a second copy of
	 * all that above it, half of it turned forty degrees away and unreadable.
	 * Up here the picture is the argument, with the name and the price under
	 * the one facing forward and nothing at all under the rest. That is also
	 * why a car with no photograph cannot be in it -- in the grid a missing
	 * photo falls back to a letter on a tile and is one of twenty-four; here
	 * it would be the whole slide.
	 *
	 * WHAT IS IN THE MARKUP IS A PLAIN ROW OF LINKED PHOTOGRAPHS. The 3D flow
	 * and the automatic turn are both added by script, by putting .is-flow on
	 * the stage; without it the row is an ordinary horizontal scroller. That
	 * order matters for more than taste: a crawler and a reader with no
	 * JavaScript both get five real photographs with five real hrefs in normal
	 * flow, rather than a stack of absolutely positioned tiles on top of each
	 * other.
	 *
	 * No structured data is emitted here. These cars are already in the
	 * ItemList the page publishes, and a second node for the same car would
	 * collide on @id -- which is the rule the earlier search-engine work
	 * settled and this does not get to reopen.
	 */
	private static function spotlight() {
		if ( ! Vesla_Settings::enabled( 'spotlight' ) ) {
			return;
		}
		$cars = Vesla_Rest::cars();
		if ( ! $cars ) {
			return;
		}

		$shot = static function ( $car ) {
			return ! empty( $car['image'] ) && ! empty( $car['image']['url'] );
		};

		$by_id = array();
		foreach ( $cars as $one ) {
			$by_id[ (string) $one['id'] ] = $one;
		}

		/* Chosen by hand, in the order they were chosen -- minus any that has
		   since been sold or has lost its photograph. */
		$picked = array();
		foreach ( (array) Vesla_Settings::get( 'spotlight', 'cars', array() ) as $row ) {
			$cid = isset( $row['car'] ) ? trim( (string) $row['car'] ) : '';
			if ( '' !== $cid && isset( $by_id[ $cid ] ) && ! isset( $picked[ $cid ] ) && $shot( $by_id[ $cid ] ) ) {
				$picked[ $cid ] = $by_id[ $cid ];
			}
		}
		$picked = array_slice( array_values( $picked ), 0, 5 );

		/* Nothing chosen, or everything chosen has been sold: the five most
		   recently added that have a photograph.

		   A car's id rises as stock is added, which is the only "newest" this
		   data actually knows -- the year on a car is its model year, not when
		   it arrived on the floor. */
		if ( ! $picked ) {
			$newest = array();
			foreach ( $cars as $one ) {
				if ( $shot( $one ) ) {
					$newest[] = $one;
				}
			}
			usort( $newest, static function ( $a, $b ) {
				return (int) $b['id'] - (int) $a['id'];
			} );
			$picked = array_slice( $newest, 0, 5 );
		}

		/* Three is the fewest this shape has. Two photographs cannot be two
		   either side of a middle one; they are a pair, and a pair belongs in
		   the grid. Below three the Spotlight stays off rather than showing a
		   lopsided version of itself, and the help text on the setting says
		   so, so an empty section is an answer rather than a puzzle. */
		if ( count( $picked ) < 3 ) {
			return;
		}

		$count = count( $picked );
		$start = (int) floor( ( $count - 1 ) / 2 );
		$cur   = (string) Vesla_Settings::get( 'stock', 'currency', '' );
		$eyeb  = (string) Vesla_Settings::get( 'spotlight', 'eyebrow', '' );
		$head  = (string) Vesla_Settings::get( 'spotlight', 'heading', '' );
		$lead  = (string) Vesla_Settings::get( 'spotlight', 'lead', '' );
		/* The badge stays with the cars: it is the same chip the grid's cards
		   carry, and one wording for it is the point of it being one setting. */
		$badge = (string) Vesla_Settings::get( 'stock', 'badge', '' );
		?>
		<section class="sec sec-mist spot" id="spotlight"<?php echo $head ? ' aria-labelledby="spot-h"' : ' aria-label="' . esc_attr__( 'Spotlight', 'vesla-landing' ) . '"'; ?>>
			<div class="shell">
				<?php if ( $eyeb ) : ?><p class="eyebrow reveal"><?php echo esc_html( $eyeb ); ?></p><?php endif; ?>
				<?php if ( $head ) : ?><h2 class="reveal" id="spot-h"><?php echo esc_html( $head ); ?></h2><?php endif; ?>
				<?php if ( $lead ) : ?><p class="sec-lead reveal"><?php Vesla_Render::t( 'spotlight.lead', $lead ); ?></p><?php endif; ?>
				<?php
				/* role=group with a carousel roledescription, NOT role=listbox.
				   A listbox's options may not contain interactive content, and
				   every one of these tiles holds a link. The keyboard behaviour
				   asked for is here either way -- the stage takes focus and the
				   arrow keys move it -- but claiming a role the markup
				   contradicts would make it worse for a screen reader, not
				   better. */
				?>
				<div class="spot-stage" id="spot" tabindex="0"
				     role="group" aria-roledescription="<?php esc_attr_e( 'carousel', 'vesla-landing' ); ?>"
				     aria-label="<?php esc_attr_e( 'Spotlight — use the left and right arrow keys', 'vesla-landing' ); ?>"
				     data-start="<?php echo (int) $start; ?>">
					<div class="spot-track" id="spot-track">
						<?php
						foreach ( $picked as $n => $car ) :
							$name  = trim( $car['make'] . ' ' . $car['model'] );
							$price = $car['price'] ? trim( $cur . ' ' . number_format_i18n( (int) $car['price'] ) ) : '';
							$img   = $car['image'];
							$href  = Vesla_Render::page_on( $car ) ? Vesla_Render::rel( Vesla_Render::permalink( $car ) ) : '';
							?>
							<figure class="spot-item" role="group"
							        aria-roledescription="<?php esc_attr_e( 'slide', 'vesla-landing' ); ?>"
							        aria-label="<?php echo esc_attr( sprintf( __( '%1$d of %2$d', 'vesla-landing' ), $n + 1, $count ) ); ?>">
								<div class="spot-shot">
									<?php if ( $badge ) : ?><span class="spot-tag"><?php echo esc_html( $badge ); ?></span><?php endif; ?>
									<?php
									/* ONE eager photograph, the one facing forward.
									   Everything else is lazy -- the same measurement the
									   old strip was built on: at three eager it put 1.5MB
									   and five requests in front of the first paint.

									   width and height are always written, and the tile
									   carries an aspect-ratio to match, so the stage can
									   be measured before a single photograph has landed.
									   Without that the flow lifts the tiles out of flow
									   at whatever height they happen to be, and the page
									   below jumps as each one arrives. */
									?>
									<img src="<?php echo esc_url( $img['url'] ); ?>"
									     alt="<?php echo esc_attr( $img['alt'] ? $img['alt'] : $name ); ?>"
									     width="<?php echo (int) ( $img['width'] ? $img['width'] : 640 ); ?>"
									     height="<?php echo (int) ( $img['height'] ? $img['height'] : 400 ); ?>"
									     <?php if ( ! empty( $img['srcset'] ) ) : ?>srcset="<?php echo esc_attr( $img['srcset'] ); ?>"<?php endif; ?>
									     sizes="(max-width:820px) 78vw, 460px"
									     <?php echo $n === $start ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
									     decoding="async">
								</div>
								<figcaption class="spot-cap">
									<h3 class="spot-name">
										<?php
										/* One link per tile, wrapping the name and stretched
										   across the photograph by CSS -- so there is ONE
										   link a crawler and a screen reader see, not two
										   pointing at the same place. A tile whose car has
										   no page of its own is plainly not a link, rather
										   than a link that goes nowhere. */
										?>
										<?php if ( $href ) : ?>
											<a class="spot-link" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $name ); ?>
										<?php endif; ?>
									</h3>
									<?php if ( $price ) : ?><p class="spot-price"><?php echo esc_html( $price ); ?></p><?php endif; ?>
								</figcaption>
							</figure>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
				/* The dots are meaningless without the flow -- there is nothing to
				   step through in a row that scrolls -- so the script reveals them,
				   the way the old scrubber was revealed.

				   THERE IS NO PAUSE BUTTON, by request. The dots are the stop
				   control instead: pressing one stops the turn for good, and so
				   does a drag or an arrow key. Hovering or focusing the stage
				   holds it while you are there. Content that moves on its own
				   does need SOME way to stop it, and that is the way. */
				?>
				<div class="spot-nav" id="spot-nav">
					<div class="spot-dots" id="spot-dots" role="group"
					     aria-label="<?php esc_attr_e( 'Choose a car', 'vesla-landing' ); ?>">
						<?php foreach ( $picked as $n => $car ) : ?>
							<button type="button" class="spot-dot" data-go="<?php echo (int) $n; ?>"
							        aria-current="<?php echo $n === $start ? 'true' : 'false'; ?>"
							        aria-label="<?php echo esc_attr( sprintf( __( 'Show the %s', 'vesla-landing' ), trim( $car['make'] . ' ' . $car['model'] ) ) ); ?>"></button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * The strip of makes, above the grid.
	 *
	 * Built from the cars rather than from a list somebody maintains: the
	 * makes are counted off Vesla_Rest::cars(), which is the same source the
	 * grid and the Make menu use. Add a Bentley and Bentley appears; sell the
	 * last one and it goes. There is nothing here to keep in step, which is
	 * the whole reason it is not a settings repeater of its own.
	 *
	 * The one thing that cannot be derived is the marque's logo, because that
	 * is a picture somebody has to supply. Where one is attached it is used;
	 * where it is not, the make is set in the site's own type. That is a
	 * fallback rather than a placeholder -- a strip of wordmarks is a
	 * respectable thing to ship, and it means the strip is never broken by a
	 * missing file.
	 *
	 * Buttons rather than links: this filters what is already on the page, it
	 * does not navigate. Without scripting the grid already shows every car,
	 * so nothing here is load-bearing -- the same bargain the Make menu beside
	 * it has always made.
	 */
	private static function brand_strip() {
		if ( ! Vesla_Settings::enabled( 'stock' ) || ! Vesla_Settings::get( 'stock', 'brands_enabled', 1 ) ) {
			return;
		}
		$cars = Vesla_Rest::cars();
		if ( ! $cars ) {
			return;
		}

		$counts = array();
		foreach ( $cars as $car ) {
			$make = trim( (string) ( isset( $car['make'] ) ? $car['make'] : '' ) );
			if ( '' === $make ) {
				continue;
			}
			if ( ! isset( $counts[ $make ] ) ) {
				$counts[ $make ] = 0;
			}
			$counts[ $make ]++;
		}
		/* One make is not a choice, and a strip offering it would be a row of
		   one tile that filters to what is already shown. */
		if ( count( $counts ) < 2 ) {
			return;
		}
		/* Most stock first, so the strip opens on what the showroom actually
		   has rather than on whatever is alphabetically lucky. Ties keep their
		   alphabetical order, which arsort preserves for equal values here
		   because the array was built in insertion order. */
		arsort( $counts );

		/* Logos come off the make's own term, under Vehicles → Car brands.

		   Not from a list in the settings, which is what this used to read and
		   which was the wrong shape: a hand-kept list of makes beside an
		   automatic one is two lists that disagree the first time somebody
		   adds a marque and forgets. The term already exists for every make in
		   stock, is already tied to every car of that make, and appears and
		   disappears with the cars -- so the mapping cannot drift, because
		   there is no second mapping to drift from.

		   Anything pointing at a deleted attachment resolves to '' and falls
		   back to the wordmark rather than printing a broken image. */
		$logos = array();
		foreach ( array_keys( $counts ) as $make ) {
			$url = Vesla_Vehicle::brand_logo( $make );
			if ( $url ) {
				$logos[ strtolower( $make ) ] = $url;
			}
		}

		$all = Vesla_Settings::get( 'stock', 'brands_all_label', __( 'All makes', 'vesla-landing' ) );

		/* The tiles arrive one after another, and the delay is written inline
		   rather than left to motion.js's auto-stagger. Two reasons, both
		   found by measuring: the auto-stagger only tagged two of the seven
		   tiles, and even where it did, nothing came of it -- styles.css sets
		   `transition` on html.m-on .reveal as a shorthand, which resets
		   transition-delay to zero, and at (0,2,1) it outranks both motion.css's
		   var(--m-rd) rule and the hand-written .d1/.d2/.d3 classes.

		   An inline delay is the one thing that beats all of that, and for a
		   row whose length is known at render time it is also the plainest
		   thing to read. Capped so a showroom carrying twenty makes does not
		   leave the last of them arriving two seconds late. */
		$step = 70;
		$cap  = 9;
		$i    = 0;
		$delay = function () use ( &$i, $step, $cap ) {
			$ms = min( $i, $cap ) * $step;
			$i++;
			return $ms ? ' style="transition-delay:' . (int) $ms . 'ms"' : '';
		};
		?>
		<section class="makes" aria-label="<?php esc_attr_e( 'Browse by make', 'vesla-landing' ); ?>">
			<div class="shell">
				<div class="makes-row" id="makes">
					<button type="button" class="make is-on reveal" data-make="" aria-pressed="true"<?php echo $delay(); // phpcs:ignore WordPress.Security.EscapeOutput -- integer built above. ?>>
						<span class="make-name"><?php echo esc_html( $all ); ?></span>
						<em class="make-n"><?php echo esc_html( number_format_i18n( array_sum( $counts ) ) ); ?></em>
					</button>
					<?php foreach ( $counts as $make => $n ) : ?>
						<?php $logo = isset( $logos[ strtolower( $make ) ] ) ? $logos[ strtolower( $make ) ] : ''; ?>
						<button type="button" class="make reveal" data-make="<?php echo esc_attr( $make ); ?>" aria-pressed="false"<?php echo $delay(); // phpcs:ignore WordPress.Security.EscapeOutput -- integer built above. ?>>
							<?php if ( $logo ) : ?>
								<?php /* alt is empty and the name follows in text: the logo is
								         a picture of a word that is already there, and a screen
								         reader should hear it once. */ ?>
								<img class="make-logo" src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy" decoding="async">
								<span class="make-name is-quiet"><?php echo esc_html( $make ); ?></span>
							<?php else : ?>
								<span class="make-name"><?php echo esc_html( $make ); ?></span>
							<?php endif; ?>
							<em class="make-n"><?php echo esc_html( number_format_i18n( $n ) ); ?></em>
						</button>
					<?php endforeach; ?>
				</div>
				<?php /* The scroll indicator. Decorative and driven by script, so it
				         is hidden from assistive technology and starts hidden: without
				         JavaScript the row still scrolls natively and this would be a
				         bar that never moved. */ ?>
				<div class="makes-bar" id="makes-bar" aria-hidden="true"><span></span></div>
			</div>
		</section>
		<?php
	}

	/**
	 * @param bool $short Homepage version. The grid, the filters and the search
	 *                    are identical either way -- this is what the homepage
	 *                    is for, and shortening it would be shortening the site.
	 *                    All $short adds is a link to /stock/ under the cars.
	 */
	private static function stock( $short = false ) {
		if ( ! Vesla_Settings::enabled( 'stock' ) ) {
			return;
		}
		$short = $short && self::page_live( 'stock' );
		$s = Vesla_Settings::get( 'stock' );
		?>
		<section class="sec" id="stock">
			<div class="shell">
				<?php if ( $s['eyebrow'] ) : ?><p class="eyebrow reveal"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?>
				<h2 class="reveal"><?php echo esc_html( $s['heading'] ); ?></h2>
				<?php if ( $s['lead'] ) : ?><p class="sec-lead reveal"><?php Vesla_Render::t( 'stock.lead', $s['lead'] ); ?></p><?php endif; ?>

				<div class="filters reveal" role="group" aria-label="<?php esc_attr_e( 'Filter the cars', 'vesla-landing' ); ?>">
					<?php /* First, because it is the one control somebody arrives already
					         knowing how to use, and the only one that will find a car by
					         something the menus do not offer -- a trim, a year, a colour.
					         type=search so a phone offers the right keyboard and browsers
					         draw their own clear button. */ ?>
					<label class="f-find"><?php echo esc_html( Vesla_Settings::get( 'stock', 'search_label', __( 'Search', 'vesla-landing' ) ) ); ?>
						<input type="search" id="f-search" autocomplete="off" spellcheck="false"
						       maxlength="40" placeholder="<?php echo esc_attr( Vesla_Settings::get( 'stock', 'search_hint', __( 'Make, model, year…', 'vesla-landing' ) ) ); ?>">
					</label>
					<label><?php esc_html_e( 'Make', 'vesla-landing' ); ?>
						<select id="f-make"><option value=""><?php esc_html_e( 'All makes', 'vesla-landing' ); ?></option></select>
					</label>
					<label><?php esc_html_e( 'Body', 'vesla-landing' ); ?>
						<select id="f-body"><option value=""><?php esc_html_e( 'All bodies', 'vesla-landing' ); ?></option></select>
					</label>
					<label><?php esc_html_e( 'Max price', 'vesla-landing' ); ?>
						<select id="f-price"><option value=""><?php esc_html_e( 'Any price', 'vesla-landing' ); ?></option></select>
					</label>
					<label><?php esc_html_e( 'Sort', 'vesla-landing' ); ?>
						<select id="f-sort">
							<option value="price-asc"><?php esc_html_e( 'Price: low to high', 'vesla-landing' ); ?></option>
							<option value="price-desc"><?php esc_html_e( 'Price: high to low', 'vesla-landing' ); ?></option>
							<option value="year-desc"><?php esc_html_e( 'Year: newest', 'vesla-landing' ); ?></option>
							<option value="km-asc"><?php esc_html_e( 'Mileage: lowest', 'vesla-landing' ); ?></option>
						</select>
					</label>
					<button class="btn btn-line" id="f-reset" type="button"><?php esc_html_e( 'Reset', 'vesla-landing' ); ?></button>
				</div>

				<div class="resbar">
					<p class="count" id="count" aria-live="polite"></p>
					<?php if ( $s['sound_enabled'] ) : ?>
						<button class="btn-sound" id="sound" type="button" aria-pressed="true">
							<svg viewBox="0 0 24 24" aria-hidden="true">
								<path d="M4 9.5v5h3.5L12 18V6L7.5 9.5z"/>
								<g class="waves"><path d="M15.5 9.2a4 4 0 0 1 0 5.6"/><path d="M18 6.8a7.4 7.4 0 0 1 0 10.4"/></g>
								<g class="cross"><path d="M16 9.8l4.5 4.4"/><path d="M20.5 9.8L16 14.2"/></g>
							</svg>
							<span id="sound-lbl"><?php echo esc_html( $s['sound_on_label'] ); ?></span>
						</button>
						<audio id="sfx-pick" src="<?php echo esc_url( VESLA_URL . 'assets/audio/gta_vice_city_notify.mp3' ); ?>" preload="auto"></audio>
					<?php endif; ?>
				</div>

				<?php /* Filled here, not only by the script — see cards_html(). */ ?>
				<div class="grid" id="grid"><?php echo self::cards_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped within. ?></div>
				<p class="empty" id="empty" hidden><?php echo esc_html( $s['empty_text'] ); ?></p>

				<div class="more-wrap" id="more-wrap" hidden>
					<button class="btn btn-line btn-lg" id="more" type="button">
						<?php echo esc_html( $s['more_label'] ); ?> <span id="more-n" class="more-n"></span>
					</button>
				</div>
				<?php
				/* Outside more-wrap on purpose. That div starts hidden and app.js
				   unhides it only when there are cards left to reveal, so a link
				   inside it would vanish with scripting off and on any filter that
				   leaves nothing more to show -- which is exactly when somebody
				   wants the full list. */
				if ( $short ) {
					self::page_more( 'stock' );
				}
				?>
			</div>
		</section>
		<?php
	}

	/* ── certified ─────────────────────────────────────────────────────── */

	/**
	 * @param bool $short Homepage version: the heading, the lead and a link
	 *                    through, without the five stages. Defaults to false so
	 *                    every existing call site renders exactly what it did.
	 */
	private static function certified( $short = false ) {
		if ( ! Vesla_Settings::enabled( 'certified' ) ) {
			return;
		}
		/* Only shortened once there is somewhere to send them. With the page off
		   the homepage keeps the whole section, because half a section and no
		   link is worse than the long version. */
		$short = $short && self::page_live( 'certified' );
		$c = Vesla_Settings::get( 'certified' );
		?>
		<section class="sec sec-dark" id="certified">
			<div class="shell">
				<?php if ( $c['eyebrow'] ) : ?><p class="eyebrow reveal"><?php echo esc_html( $c['eyebrow'] ); ?></p><?php endif; ?>
				<h2 class="reveal"><?php echo esc_html( $c['heading'] ); ?></h2>
				<?php if ( $c['lead'] ) : ?><p class="sec-lead reveal"><?php Vesla_Render::t( 'certified.lead', $c['lead'] ); ?></p><?php endif; ?>
				<?php if ( $short ) : ?>
					<?php self::page_more( 'certified' ); ?>
				<?php else : ?>
				<ol class="stages">
					<?php foreach ( (array) $c['stages'] as $i => $st ) : ?>
						<li class="reveal">
							<span class="num"><?php echo esc_html( str_pad( $i + 1, 2, '0', STR_PAD_LEFT ) ); ?></span>
							<h3><?php echo esc_html( $st['title'] ); ?></h3>
							<p><?php Vesla_Render::t( 'certified.stages.text', $st['text'] ); ?></p>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The link from a homepage section to the page that carries it in full.
	 *
	 * Prints nothing when the page is switched off, which is what keeps the
	 * homepage exactly as it was until somebody publishes a page: no dangling
	 * link to a file that is not there.
	 */
	private static function page_more( $key ) {
		if ( ! self::page_live( $key ) ) {
			return;
		}
		$label = (string) Vesla_Settings::get( self::pages()[ $key ]['owner'], 'page_more_label', '' );
		if ( '' === $label ) {
			$label = __( 'Read more', 'vesla-landing' );
		}
		?>
		<p class="sec-more reveal">
			<a class="btn btn-line" href="<?php echo esc_url( self::rel( self::page_url( $key ) ) ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		</p>
		<?php
	}

	/* ── why ───────────────────────────────────────────────────────────── */

	private static function why() {
		if ( ! Vesla_Settings::enabled( 'why' ) ) {
			return;
		}
		$w = Vesla_Settings::get( 'why' );
		?>
		<section class="sec" id="why">
			<div class="shell">
				<?php if ( $w['eyebrow'] ) : ?><p class="eyebrow reveal"><?php echo esc_html( $w['eyebrow'] ); ?></p><?php endif; ?>
				<h2 class="reveal"><?php echo esc_html( $w['heading'] ); ?></h2>
				<div class="why-grid">
					<?php foreach ( (array) $w['cards'] as $card ) : ?>
						<article class="reveal">
							<h3><?php echo esc_html( $card['title'] ); ?></h3>
							<p><?php Vesla_Render::t( 'why.cards.text', $card['text'] ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/* ── record ────────────────────────────────────────────────────────── */

	/**
	 * @param bool $short Homepage version: the headline and the lead, without
	 *                    the at-a-glance table, which belongs on /about/ where
	 *                    there is room to read it.
	 */
	private static function record( $short = false ) {
		if ( ! Vesla_Settings::enabled( 'record' ) ) {
			return;
		}
		$short = $short && self::page_live( 'about' );
		$r = Vesla_Settings::get( 'record' );
		?>
		<section class="sec sec-mist" id="record">
			<div class="shell record-in">
				<div class="reveal">
					<?php if ( $r['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $r['eyebrow'] ); ?></p><?php endif; ?>
					<h2><?php echo esc_html( $r['heading'] ); ?></h2>
					<?php if ( $r['lead'] ) : ?><p class="sec-lead"><?php Vesla_Render::t( 'record.lead', $r['lead'] ); ?></p><?php endif; ?>
					<?php if ( $r['note'] ) : ?><p class="note"><?php Vesla_Render::t( 'record.note', $r['note'] ); ?></p><?php endif; ?>
				</div>
				<?php if ( $short ) : ?>
					<?php self::page_more( 'about' ); ?>
				<?php elseif ( ! empty( $r['glance'] ) ) : ?>
					<dl class="glance reveal">
						<?php foreach ( $r['glance'] as $g ) : ?>
							<div><dt><?php echo esc_html( $g['label'] ); ?></dt><dd><?php echo esc_html( $g['value'] ); ?></dd></div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/* ── chairman ──────────────────────────────────────────────────────── */

	private static function chairman() {
		if ( ! Vesla_Settings::enabled( 'chairman' ) ) {
			return;
		}
		$c = Vesla_Settings::get( 'chairman' );
		/* The intrinsic size travels with the address.
		
		   Without width and height on the tag the browser has nothing to reserve
		   for the portrait, so everything below it jumps once the file arrives --
		   and this one loads lazily, which means it arrives exactly when somebody
		   is reading. The stylesheet crops the picture into a plate of a fixed
		   size, so these two numbers only ever supply the ratio, never the size
		   it is drawn at. */
		$shot = $c['portrait'] ? wp_get_attachment_image_src( absint( $c['portrait'] ), 'large' ) : false;
		?>
		<section class="sec sec-dark" id="chairman">
			<div class="shell chair-in">
				<?php if ( $shot ) : ?>
					<?php /* The plate is not hidden from a screen reader the way the monogram
					   version is, but the photograph carries an empty alt all the same: the
					   chairman’s name and title are set in the list immediately beside it,
					   and repeating them here would read the same person out twice. Writing
					   a description of a face nobody here has seen is not an option. */ ?>
					<div class="chair-mark has-shot reveal">
						<img class="chair-portrait"
						     src="<?php echo esc_url( $shot[0] ); ?>"
						     width="<?php echo esc_attr( (int) $shot[1] ); ?>"
						     height="<?php echo esc_attr( (int) $shot[2] ); ?>"
						     alt=""
						     loading="lazy" decoding="async">
					</div>
				<?php else : ?>
					<div class="chair-mark reveal" aria-hidden="true">
						<span class="chair-mono"><?php echo esc_html( $c['monogram'] ); ?></span>
						<span class="chair-rule"></span>
					</div>
				<?php endif; ?>
				<div class="reveal d1">
					<?php if ( $c['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $c['eyebrow'] ); ?></p><?php endif; ?>
					<h2><?php echo esc_html( $c['heading'] ); ?></h2>
					<?php if ( $c['text'] ) : ?><p class="sec-lead"><?php Vesla_Render::t( 'chairman.text', $c['text'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $c['rows'] ) ) : ?>
						<dl class="chair-id">
							<?php foreach ( $c['rows'] as $row ) : ?>
								<div><dt><?php echo esc_html( $row['label'] ); ?></dt><dd><?php echo esc_html( $row['value'] ); ?></dd></div>
							<?php endforeach; ?>
						</dl>
					<?php endif; ?>
					<?php if ( $c['note'] ) : ?><p class="note"><?php echo esc_html( $c['note'] ); ?></p><?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/* ── sell ──────────────────────────────────────────────────────────── */

	/**
	 * @param bool $short Homepage version: the heading, the lead and a link,
	 *                    without the longer explanation underneath.
	 *
	 *                    The estimator stays on BOTH. It is the most engaging
	 *                    thing on the homepage and it captures a lead on its
	 *                    own, so moving it to /sell/ would cost enquiries from
	 *                    everyone who never got that far.
	 */
	private static function sell( $short = false ) {
		if ( ! Vesla_Settings::enabled( 'sell' ) ) {
			return;
		}
		$short = $short && self::page_live( 'sell' );
		$s = Vesla_Settings::get( 'sell' );
		?>
		<section class="sec" id="sell">
			<div class="shell sell-in">
				<div class="reveal">
					<?php if ( $s['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?>
					<h2><?php echo esc_html( $s['heading'] ); ?></h2>
					<?php if ( $s['lead'] ) : ?><p class="sec-lead"><?php Vesla_Render::t( 'sell.lead', $s['lead'] ); ?></p><?php endif; ?>
					<?php if ( ! $short ) : ?>
						<?php if ( $s['body'] ) : ?><p><?php Vesla_Render::t( 'sell.body', $s['body'] ); ?></p><?php endif; ?>
						<?php if ( $s['note'] ) : ?><p class="note"><?php Vesla_Render::t( 'sell.note', $s['note'] ); ?></p><?php endif; ?>
					<?php else : ?>
						<?php self::page_more( 'sell' ); ?>
					<?php endif; ?>
				</div>

				<?php
				/* Borrowed from the enquiry form rather than a second set of settings:
				   the same three boxes should be called the same three things wherever
				   they appear, and one of them being renamed and the other not is how
				   that stops being true. */
				$est_send = array(
					'title' => (string) Vesla_Settings::get( 'sell', 'est_send_title', __( 'Want us to look at it properly?', 'vesla-landing' ) ),
					'label' => (string) Vesla_Settings::get( 'sell', 'est_send_label', __( 'Send us this valuation', 'vesla-landing' ) ),
				);
				$c_labels = array(
					'name'  => (string) Vesla_Settings::get( 'contact', 'form_l_name', __( 'Name', 'vesla-landing' ) ),
					'phone' => (string) Vesla_Settings::get( 'contact', 'form_l_phone', __( 'Phone', 'vesla-landing' ) ),
					'email' => (string) Vesla_Settings::get( 'contact', 'form_l_email', __( 'Email', 'vesla-landing' ) ),
				);
				?>
				<?php if ( $s['est_enabled'] ) : ?>
					<form class="est reveal" id="est" novalidate>
						<h3><?php echo esc_html( $s['est_title'] ); ?></h3>
						<?php if ( $s['est_sub'] ) : ?><p class="est-sub"><?php echo esc_html( $s['est_sub'] ); ?></p><?php endif; ?>
						<label><?php esc_html_e( 'Make', 'vesla-landing' ); ?><select id="e-make"></select></label>
						<label><?php esc_html_e( 'Year', 'vesla-landing' ); ?><select id="e-year"></select></label>
						<label><?php esc_html_e( 'Mileage', 'vesla-landing' ); ?>
							<select id="e-km">
								<option value="1.06"><?php esc_html_e( 'Under 40,000 km', 'vesla-landing' ); ?></option>
								<option value="1" selected><?php esc_html_e( '40,000 – 90,000 km', 'vesla-landing' ); ?></option>
								<option value="0.88"><?php esc_html_e( '90,000 – 150,000 km', 'vesla-landing' ); ?></option>
								<option value="0.74"><?php esc_html_e( 'Over 150,000 km', 'vesla-landing' ); ?></option>
							</select>
						</label>
						<label><?php esc_html_e( 'Condition', 'vesla-landing' ); ?>
							<select id="e-cond">
								<option value="1.08"><?php esc_html_e( 'Excellent', 'vesla-landing' ); ?></option>
								<option value="1" selected><?php esc_html_e( 'Good', 'vesla-landing' ); ?></option>
								<option value="0.85"><?php esc_html_e( 'Fair — needs work', 'vesla-landing' ); ?></option>
							</select>
						</label>
						<div class="est-out">
							<span><?php echo esc_html( $s['est_out_label'] ); ?></span>
							<strong id="e-out">—</strong>
						</div>
						<?php if ( $s['est_note'] ) : ?><p class="note"><?php echo esc_html( $s['est_note'] ); ?></p><?php endif; ?>

						<?php /* The estimator used to end here, at a number.
						         It answered the visitor's question and asked nothing back, so
						         somebody who had just told us the make, the year, the mileage and
						         the condition of a car they want to sell left without us knowing
						         they existed. The details go with the enquiry, so the call back
						         starts from the figure they were shown rather than from nothing.

						         Asked AFTER the valuation, never before: the number is the reason
						         they filled it in, and putting a name and telephone box in front of
						         it would turn a useful tool into a form. */ ?>
						<div class="est-send">
							<h4><?php echo esc_html( $est_send['title'] ); ?></h4>
							<label><?php echo esc_html( $c_labels['name'] ); ?> <span class="req" aria-hidden="true">*</span>
								<input type="text" id="s-name" name="name" autocomplete="name" maxlength="60" spellcheck="false" aria-describedby="e-s-name">
								<span class="field-msg" id="e-s-name"></span>
							</label>
							<label><?php echo esc_html( $c_labels['phone'] ); ?> <span class="req" aria-hidden="true">*</span>
								<input type="tel" id="s-phone" name="phone" autocomplete="tel" maxlength="24"
								       inputmode="tel" pattern="[0-9+()\-\s]{7,24}" aria-describedby="e-s-phone">
								<span class="field-msg" id="e-s-phone"></span>
							</label>
							<label><?php echo esc_html( $c_labels['email'] ); ?>
								<input type="email" id="s-email" name="email" autocomplete="email" maxlength="254" inputmode="email" spellcheck="false" aria-describedby="e-s-email">
								<span class="field-msg" id="e-s-email"></span>
							</label>
							<?php /* The same box no person can see that the enquiry form carries. */ ?>
							<div class="vesla-hp" aria-hidden="true">
								<label>
									<?php esc_html_e( 'Leave this field empty', 'vesla-landing' ); ?>
									<input type="text" id="s-website" name="website" tabindex="-1" autocomplete="off">
								</label>
							</div>
							<button class="btn btn-gold" type="submit" id="s-go"><?php echo esc_html( $est_send['label'] ); ?></button>
							<p class="form-msg" id="s-out" role="status" aria-live="polite"></p>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/* ── faq ───────────────────────────────────────────────────────────── */

	private static function faq() {
		if ( ! Vesla_Settings::enabled( 'faq' ) ) {
			return;
		}
		$f = Vesla_Settings::get( 'faq' );
		if ( empty( $f['items'] ) ) {
			return;
		}
		?>
		<section class="sec sec-mist" id="faq">
			<div class="shell">
				<?php if ( $f['eyebrow'] ) : ?><p class="eyebrow reveal"><?php echo esc_html( $f['eyebrow'] ); ?></p><?php endif; ?>
				<h2 class="reveal"><?php echo esc_html( $f['heading'] ); ?></h2>
				<div class="faq reveal d1" id="faq-list">
					<?php foreach ( $f['items'] as $i => $item ) : ?>
						<div class="faq-item">
							<button class="faq-head" type="button" aria-expanded="false" aria-controls="faq-a<?php echo esc_attr( $i ); ?>">
								<?php echo esc_html( $item['q'] ); ?>
								<span class="sign" aria-hidden="true"></span>
							</button>
							<div class="faq-body" id="faq-a<?php echo esc_attr( $i ); ?>">
								<p><?php Vesla_Render::t( 'faq.items.a', $item['a'] ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/* -- where we are ---------------------------------------------------- */

	/**
	 * Where a point sits on the world tile grid at a given zoom.
	 *
	 * The standard Web Mercator projection every slippy map uses. Returns
	 * fractional tile coordinates, because the fraction is what says where
	 * inside the tile the point falls -- which is what lets the pin land on
	 * the building rather than on the tile's corner.
	 *
	 * @return array{0:float,1:float}
	 */
	/**
	 * The Google Maps address for one branch, built from that branch's own row.
	 *
	 * There used to be a "Directions link" field beside the latitude and the
	 * longitude, and it won. So the pin and the button read different fields and
	 * could point at different places -- and did: the stored link was written
	 * once by hand and never moved again, so correcting the coordinates moved
	 * the pin on the page and left the button pointing where it always had.
	 *
	 * COORDINATES DECIDE, NOT THE NAME. A name or an address has to be geocoded,
	 * which means matched, and a match can be wrong -- there is more than one
	 * "service centre" in Ras Al Khor. Coordinates are not matched; they are a
	 * position. The name rides along as the pin's label so a driver sees where
	 * they are going, but it never decides where that is.
	 *
	 * The one thing that would route to a door rather than to a point is a
	 * Google Place ID, and there is no way to derive one from this record
	 * without asking Google. It would also be a second field that can disagree
	 * with the coordinates, which is the fault this replaced.
	 *
	 * Returns '' when there are no usable coordinates, and the caller prints no
	 * button at all rather than one that goes nowhere.
	 */
	public static function branch_directions( $b ) {
		$lat = trim( (string) ( isset( $b['lat'] ) ? $b['lat'] : '' ) );
		$lng = trim( (string) ( isset( $b['lng'] ) ? $b['lng'] : '' ) );
		if ( '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return '';
		}

		/* The address is a textarea and arrives with its line breaks in it. */
		$label = trim( (string) ( isset( $b['name'] ) ? $b['name'] : '' ) );
		$addr  = (string) ( isset( $b['address'] ) ? $b['address'] : '' );
		$addr  = trim( (string) preg_replace( '/[[:space:]]+/', ' ', $addr ) );
		$name  = trim( $label . ( '' !== $addr ? ', ' . $addr : '' ) );

		/* q=lat,lng(label) -- the long-standing Google form that carries a
		   position and a name together. The position is what it navigates to; the
		   label is only what it shows. Parentheses are part of the format, so a
		   pair inside the name would end the label early and is replaced. */
		$url = 'https://www.google.com/maps?q=' . rawurlencode( $lat ) . ',' . rawurlencode( $lng );
		if ( '' !== $name ) {
			/* A pair of brackets inside the name would close the label early, so
			   they become spaces -- and the spaces that leaves are collapsed, or
			   "Showroom 101 (Old Market), Ras Al Khor" arrives with a gap and a
			   stranded comma. */
			$name = str_replace( array( '(', ')' ), ' ', $name );
			$name = trim( (string) preg_replace( '/[[:space:]]+,/', ',', (string) preg_replace( '/[[:space:]]+/', ' ', $name ) ) );
			$url .= '(' . rawurlencode( $name ) . ')';
		}
		return $url;
	}

	/**
	 * The finance page's body.
	 *
	 * The only page renderer that is not also a homepage section, because
	 * finance has no homepage section: it is reached from the menu.
	 *
	 * The calculator is the SAME SUM as the one on a car page, and reads the
	 * same rate, deposit and term from the vehicle settings. Two calculators
	 * quoting different monthly figures for the same car would be worse than
	 * having only one, so there is one set of numbers and this page borrows it.
	 * What differs is only where the price comes from -- typed here, taken from
	 * the car there.
	 */
	/**
	 * The sold page: what has already gone.
	 *
	 * The same card as the grid, through the same card_html(). There is no
	 * second template -- a sold card differs by what card_html() already knows
	 * about a sold car: the SOLD badge instead of the certified one, no price,
	 * and no buttons asking about a car nobody can buy.
	 */
	private static function sold_page() {
		$s    = Vesla_Settings::get( 'sold' );
		$cars = Vesla_Rest::cars( true );
		?>
		<section class="sec" id="sold">
			<div class="shell">
				<?php if ( ! $cars ) : ?>
					<p class="sec-lead reveal"><?php echo esc_html( $s['empty_text'] ); ?></p>
				<?php else : ?>
					<div class="grid">
						<?php
						$ctx = null;
						foreach ( $cars as $i => $car ) {
							echo self::card_html( $car, $ctx, array( 'i' => $i, 'later' => false, 'eager' => $i < 4 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped within.
						}
						?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private static function finance_page() {
		$f   = Vesla_Settings::get( 'finance' );
		$veh = Vesla_Settings::get( 'vehicle' );
		$cur = (string) Vesla_Settings::get( 'stock', 'currency', '' );
		?>
		<section class="sec" id="finance">
			<div class="shell">

				<?php if ( ! empty( $f['steps'] ) ) : ?>
					<?php if ( $f['steps_title'] ) : ?>
						<h2 class="reveal"><?php echo esc_html( $f['steps_title'] ); ?></h2>
					<?php endif; ?>
					<ol class="stages">
						<?php foreach ( (array) $f['steps'] as $i => $st ) : ?>
							<li class="reveal">
								<span class="num"><?php echo esc_html( str_pad( $i + 1, 2, '0', STR_PAD_LEFT ) ); ?></span>
								<h3><?php echo esc_html( $st['title'] ); ?></h3>
								<p><?php Vesla_Render::t( 'finance.steps.text', $st['text'] ); ?></p>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>

				<div class="fin-grid">
					<?php if ( ! empty( $f['docs'] ) ) : ?>
						<div class="reveal">
							<?php if ( $f['docs_title'] ) : ?>
								<h2><?php echo esc_html( $f['docs_title'] ); ?></h2>
							<?php endif; ?>
							<ul class="fin-docs">
								<?php foreach ( (array) $f['docs'] as $d ) : ?>
									<li>
										<b><?php echo esc_html( $d['item'] ); ?></b>
										<?php if ( ! empty( $d['note'] ) ) : ?>
											<span><?php echo esc_html( $d['note'] ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php if ( $f['calc_enabled'] ) : ?>
						<?php /* The figures travel on the element, the way a car page
						         carries them on .vp-fin, so the script never has to know
						         which page it is on. */ ?>
						<form class="est fin-calc reveal" id="fin-calc" novalidate
						      data-rate="<?php echo esc_attr( $veh['finance_rate'] ); ?>"
						      data-down="<?php echo esc_attr( $veh['finance_down_pct'] ); ?>"
						      data-years="<?php echo esc_attr( $veh['finance_years'] ); ?>">
							<h3><?php echo esc_html( $f['calc_title'] ); ?></h3>
							<label class="fin-price">
								<?php echo esc_html( $f['calc_price_label'] ); ?>
								<input type="number" id="fc-price" inputmode="numeric" min="0" step="1000"
								       placeholder="<?php echo esc_attr( $cur . ' 100,000' ); ?>">
							</label>
							<div class="fin-row">
								<label><?php esc_html_e( 'Deposit', 'vesla-landing' ); ?>
									<output id="fc-down-v"></output>
									<input type="range" id="fc-down" min="0" max="60" step="5" value="<?php echo esc_attr( (int) $veh['finance_down_pct'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Loan length in years', 'vesla-landing' ); ?>
									<output id="fc-years-v"></output>
									<input type="range" id="fc-years" min="1" max="8" step="1" value="<?php echo esc_attr( (int) $veh['finance_years'] ); ?>">
								</label>
							</div>
							<div class="est-out">
								<span><?php esc_html_e( 'Estimated monthly payment', 'vesla-landing' ); ?></span>
								<strong id="fc-month">—</strong>
							</div>
							<?php if ( $f['calc_note'] ) : ?>
								<p class="note"><?php Vesla_Render::t( 'finance.calc_note', $f['calc_note'] ); ?></p>
							<?php endif; ?>
							<?php if ( $f['ask_label'] ) : ?>
								<p class="fin-ask-wrap">
									<a class="btn btn-gold" id="fc-ask" href="<?php echo esc_url( self::site_link( '#contact' ) ); ?>">
										<?php echo esc_html( $f['ask_label'] ); ?>
									</a>
								</p>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	public static function map_section() {
		if ( ! Vesla_Settings::enabled( 'map' ) ) {
			return;
		}
		$m = Vesla_Settings::get( 'map' );

		$branches = array();
		foreach ( (array) $m['branches'] as $b ) {
			if ( trim( (string) $b['name'] ) || trim( (string) $b['address'] ) ) {
				$branches[] = $b;
			}
		}
		if ( ! $branches ) {
			return;
		}
		?>
		<section class="sec-map" id="where">
			<div class="shell">
				<?php if ( $m['eyebrow'] ) : ?>
					<p class="eyebrow reveal"><?php echo esc_html( $m['eyebrow'] ); ?></p>
				<?php endif; ?>
				<?php if ( $m['heading'] ) : ?>
					<h2 class="reveal"><?php echo esc_html( $m['heading'] ); ?></h2>
				<?php endif; ?>

				<div class="map-wrap reveal d1">
					<?php foreach ( $branches as $b ) : ?>
						<?php
						$lat = trim( (string) $b['lat'] );
						$lng = trim( (string) $b['lng'] );
						$has = ( '' !== $lat && '' !== $lng && is_numeric( $lat ) && is_numeric( $lng ) );
						?>
						<article class="map-card">
							<?php if ( $has ) : ?>
							<?php
							/* Every value the map needs travels on the element, so map.js reads
							   the settings rather than carrying its own copy of them. */

							$data = array(
								'data-vesla-map' => '1',
								'data-lat'       => $lat,
								'data-lng'       => $lng,
								'data-zoom'      => (int) $m['zoom'],
								'data-tiles'     => (string) $m['tile_url'],
								'data-credit'    => (string) $m['tile_credit'],
								'data-name'      => (string) $b['name'],
								'data-address'   => (string) $b['address'],
								'data-phone'     => (string) $b['phone'],

								'data-assets'    => VESLA_URL . 'assets/',
							);
							$attrs = '';
							foreach ( $data as $k => $v ) {
								$attrs .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
							}
							?>
							<?php /* The skeleton is inside the frame and is what shows until the
							         tiles arrive — and what stays if they never do. A frame that
							         quietly holds its shape reads as "the map is coming"; a
							         spinner that never stops reads as broken. */ ?>
							<div class="map-frame"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- each value escaped above. ?>>
								<div class="map-skel" aria-hidden="true"></div>
							</div>
							<?php endif; ?>

							<?php /* A sibling of the map, never a child of it: Leaflet rewrites the
							         position of the element it initialises into, and a card inside
							         that element is moved with it. */ ?>
							<div class="map-body">
								<h3><?php echo esc_html( $b['name'] ); ?></h3>
								<?php if ( $b['address'] ) : ?>
									<p class="map-addr"><?php echo nl2br( esc_html( $b['address'] ) ); ?></p>
								<?php endif; ?>
								<?php if ( $b['phone'] ) : ?>
									<p class="map-tel">
										<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $b['phone'] ) ); ?>">
											<?php echo esc_html( $b['phone'] ); ?>
										</a>
									</p>
								<?php endif; ?>
								<?php
								/* Both, not either: a branch with a button wording but no
								   coordinates would otherwise draw a button with an empty
								   address, which looks like a link and does nothing. */
								$dirs = self::branch_directions( $b );
								?>
								<?php if ( $b['link_label'] && '' !== $dirs ) : ?>
									<?php /* Google, deliberately: the map on the page is ours and shows
									         only our pin, but directions are a thing people finish in
									         the app already on their phone. */ ?>
									<a class="btn btn-solid map-go"
										href="<?php echo esc_url( $dirs ); ?>"
										target="_blank" rel="noopener">
										<?php echo esc_html( $b['link_label'] ); ?>
									</a>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/* ── contact ───────────────────────────────────────────────────────── */

	/**
	 * @param bool $force Render even when the section is switched off on the
	 *                    front page. The Contact page is this section's whole
	 *                    reason for existing, so "do not show it on the front
	 *                    page" must not empty the page built around it.
	 */
	private static function contact( $force = false ) {
		if ( ! $force && ! Vesla_Settings::enabled( 'contact' ) ) {
			return;
		}
		$c = Vesla_Settings::get( 'contact' );
		?>
		<section class="sec sec-dark" id="contact">
			<div class="shell contact-in">
				<div class="reveal">
					<?php if ( $c['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $c['eyebrow'] ); ?></p><?php endif; ?>
					<h2><?php echo esc_html( $c['heading'] ); ?></h2>
					<?php if ( $c['lead'] ) : ?><p class="sec-lead"><?php Vesla_Render::t( 'contact.lead', $c['lead'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $c['channels'] ) ) : ?>
						<ul class="chan">
							<?php foreach ( $c['channels'] as $ch ) : ?>
								<li>
									<span><?php echo esc_html( $ch['label'] ); ?></span>
									<?php if ( $ch['link'] ) : ?>
										<a href="<?php echo esc_url( $ch['link'] ); ?>"
										   <?php echo ( 0 === strpos( $ch['link'], 'http' ) ) ? 'target="_blank" rel="noopener"' : ''; ?>>
											<?php echo esc_html( $ch['value'] ); ?>
										</a>
									<?php else : ?>
										<em><?php echo esc_html( $ch['value'] ); ?></em>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php
					/* The showroom's hours, printed as a short list rather than seven lines.
					   The same rows also go into the page's structured data, where Google
					   reads them -- a sentence saying nine to nine is invisible to it. */
					$hours = self::hours_groups();
					?>
					<?php if ( $hours ) : ?>
						<div class="hours">
							<?php if ( $c['hours_title'] ) : ?>
								<p class="hours-t"><?php echo esc_html( $c['hours_title'] ); ?></p>
							<?php endif; ?>
							<ul>
								<?php foreach ( $hours as $h ) : ?>
									<li><span><?php echo esc_html( $h['days'] ); ?></span><b><?php echo esc_html( $h['times'] ); ?></b></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<form class="enq reveal" id="enq" novalidate>
					<h3><?php echo esc_html( $c['form_title'] ); ?></h3>
					<label><?php echo esc_html( $c['form_l_name'] ); ?> <span class="req" aria-hidden="true">*</span>
						<input type="text" id="q-name" name="name" required autocomplete="name" maxlength="60" spellcheck="false" aria-describedby="e-q-name">
						<span class="field-msg" id="e-q-name"></span>
					</label>
					<label><?php echo esc_html( $c['form_l_phone'] ); ?> <span class="req" aria-hidden="true">*</span>
						<input type="tel" id="q-phone" name="phone" required autocomplete="tel" maxlength="24"
						       inputmode="tel" pattern="[0-9+()\-\s]{7,24}" aria-describedby="e-q-phone">
						<span class="field-msg" id="e-q-phone"></span>
					</label>
					<label><?php echo esc_html( $c['form_l_email'] ); ?>
						<input type="email" id="q-email" name="email" autocomplete="email" maxlength="254" inputmode="email" spellcheck="false" aria-describedby="e-q-email">
						<span class="field-msg" id="e-q-email"></span>
					</label>
					<label><?php echo esc_html( $c['form_l_car'] ); ?>
						<input type="text" id="q-car" name="car" maxlength="80" aria-describedby="e-q-car">
						<span class="field-msg" id="e-q-car"></span>
					</label>
					<label><?php echo esc_html( $c['form_l_note'] ); ?>
						<textarea id="q-note" name="message" rows="3" maxlength="1000" aria-describedby="e-q-note"></textarea>
						<span class="field-msg" id="e-q-note"></span>
					</label>
					<?php /* What kind of enquiry this is. 'general' unless something sets it --
					         pressing Enquire on a car makes it a car enquiry, and arriving from
					         a finance quote makes it finance. Hidden rather than a menu: the
					         visitor already told us by which button they pressed, and asking
					         them to say it again is a question with a knowable answer. */ ?>
					<input type="hidden" id="q-type" name="type" value="general">
					<input type="hidden" id="q-details" name="details" value="">
					<?php wp_nonce_field( 'vesla_enquiry', 'vesla_nonce' ); ?>
					<?php /* A box no person can see, reach by keyboard, or be offered by autofill.
					         Bots fill in every field they find; anything typed here did not come
					         from a customer. Kept out of the tab order and hidden from screen
					         readers so it never reaches anybody it would confuse. */ ?>
					<div class="vesla-hp" aria-hidden="true">
						<label>
							<?php esc_html_e( 'Leave this field empty', 'vesla-landing' ); ?>
							<input type="text" id="q-website" name="website" tabindex="-1" autocomplete="off">
						</label>
					</div>
					<button class="btn btn-gold btn-lg" type="submit"><?php echo esc_html( $c['form_submit'] ); ?></button>
					<p class="form-msg" id="q-out" role="status" aria-live="polite"></p>
				</form>
			</div>
		</section>
		<?php
	}

	/* ── footer ────────────────────────────────────────────────────────── */

	private static function footer() {
		$f = Vesla_Settings::get( 'footer' );
		?>
		<footer class="foot">
			<div class="shell foot-in">
				<div class="foot-brand reveal">
					<?php self::lockup( 'foot' ); ?>
					<?php if ( $f['blurb'] ) : ?><p><?php Vesla_Render::t( 'footer.blurb', $f['blurb'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $f['badges'] ) ) : ?>
						<ul class="foot-badges">
							<?php foreach ( $f['badges'] as $b ) : ?>
								<li><?php echo esc_html( $b['text'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php
					/* The mark, under the wordmark and the badges it belongs to.
					
					   It used to be positioned absolutely and centred on the footer's own
					   height, which laid it across the contact column and straight through
					   the button on the right. In the flow of this column it cannot reach
					   either, whatever the footer's contents turn out to be — and it is
					   under the name it is the mark for, which is where it reads as
					   identification rather than as decoration behind the text.
					
					   The picture is a setting of its own, falling back to the site logo:
					   the header wants a mark that survives being drawn at forty pixels,
					   and this one is printed a couple of hundred high on a near-black
					   ground, where a different rendering of the shield reads better. */
					$wm = absint( $f['watermark'] );
					?>
					<div class="foot-wm" aria-hidden="true">
						<?php
						/* medium_large, not large.
						
						   The mark is drawn at most 280 CSS pixels wide, so the 768px file is
						   already twice what a retina screen needs and the 1024 one is a
						   megabyte spent on a footer nobody scrolls to twice. It is emitted by
						   hand rather than through wp_get_attachment_image() for the same
						   reason: that helper writes a sizes attribute of 100vw, which sends a
						   phone after the widest file in the set for a picture the width of a
						   business card. */
						$src = $wm ? wp_get_attachment_image_src( $wm, 'medium_large' ) : false;
						if ( $src ) :
						?>
						<img src="<?php echo esc_url( $src[0] ); ?>"
						     width="<?php echo esc_attr( (int) $src[1] ); ?>"
						     height="<?php echo esc_attr( (int) $src[2] ); ?>"
						     alt="" loading="lazy" decoding="async">
						<?php else : ?>
						<?php echo self::logo_img( 'loading="lazy"', 280 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! empty( $f['nav'] ) ) : ?>
					<nav class="foot-col reveal" aria-labelledby="fl-explore">
						<p class="foot-lbl" id="fl-explore"><?php echo esc_html( $f['nav_title'] ); ?></p>
						<ul class="foot-nav">
							<?php foreach ( $f['nav'] as $n ) : ?>
								<?php $fhref = self::menu_href( $n['link'], true ); ?>
								<?php if ( '' !== $fhref ) : ?>
									<li><a href="<?php echo esc_url( $fhref ); ?>"><?php echo esc_html( $n['label'] ); ?></a></li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>

				<?php if ( ! empty( $f['contact'] ) ) : ?>
					<div class="foot-col reveal">
						<p class="foot-lbl"><?php echo esc_html( $f['contact_title'] ); ?></p>
						<ul class="foot-contact">
							<?php foreach ( $f['contact'] as $ct ) : ?>
								<li>
									<?php if ( $ct['link'] ) : ?>
										<a href="<?php echo esc_url( self::menu_href( $ct['link'] ) ); ?>"
										   <?php echo ( 0 === strpos( $ct['link'], 'http' ) ) ? 'target="_blank" rel="noopener"' : ''; ?>>
											<?php echo esc_html( $ct['value'] ); ?>
										</a>
									<?php else : ?>
										<span><?php echo esc_html( $ct['value'] ); ?></span>
									<?php endif; ?>
									<span><?php echo esc_html( $ct['label'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<div class="foot-col reveal">
					<p class="foot-lbl"><?php echo esc_html( $f['where_title'] ); ?></p>
					<p class="foot-where">
						<?php Vesla_Render::t( 'footer.where_text', $f['where_text'] ); ?>
						<?php if ( $f['where_note'] ) : ?><em><?php echo esc_html( $f['where_note'] ); ?></em><?php endif; ?>
					</p>
					<?php if ( $f['cta_label'] ) : ?>
						<a class="btn btn-gold" href="<?php echo esc_url( self::menu_href( $f['cta_link'] ) ); ?>"><?php echo esc_html( $f['cta_label'] ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="shell foot-bar">
				<?php /* Conditional, like the line beside it. Emptying the field in
				         the admin is how this line is removed, and an unconditional
				         <p> would leave an empty element holding its own line height
				         where the text used to be -- a gap rather than a removal. */ ?>
				<?php if ( trim( (string) $f['copyright'] ) !== '' ) : ?>
					<p class="copy"><?php echo esc_html( str_replace( '{year}', gmdate( 'Y' ), $f['copyright'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $f['meta'] ) : ?><p class="foot-meta"><?php echo esc_html( $f['meta'] ); ?></p><?php endif; ?>

				<?php
				/* Privacy and Terms, listed here and nowhere else.
				
				   They were published and unreachable: nothing on the site linked to
				   either, which makes them orphans -- pages a crawler only finds
				   because the sitemap mentions them, and a reader never finds at all.
				   The footer is where a reader looks for them, so that is where they
				   go.
				
				   Built from page_live() rather than from a repeater somebody fills
				   in: a link to a legal page that has been switched off is worse than
				   no link, and this way the two cannot disagree. */
				$legal = array();
				foreach ( array( 'privacy', 'terms' ) as $lk ) {
					if ( self::page_live( $lk ) ) {
						$legal[] = array(
							'url'   => self::rel( self::page_url( $lk ) ),
							'label' => self::page_title( $lk ),
						);
					}
				}
				?>
				<?php if ( $legal ) : ?>
					<ul class="foot-legal">
						<?php foreach ( $legal as $l ) : ?>
							<li><a href="<?php echo esc_url( $l['url'] ); ?>"><?php echo esc_html( $l['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</footer>
		<?php
	}

	/* ── floating controls ─────────────────────────────────────────────── */

	private static function floating() {
		$e    = Vesla_Settings::get( 'extras' );
		$wa   = preg_replace( '/\D/', '', (string) Vesla_Settings::get( 'brand', 'whatsapp', '' ) );
		$tel  = preg_replace( '/[^\d+]/', '', (string) Vesla_Settings::get( 'brand', 'phone_sales', '' ) );
		?>
		<button class="totop" id="totop" type="button" aria-label="<?php esc_attr_e( 'Back to top', 'vesla-landing' ); ?>">&uarr;</button>

		<?php if ( $e['actbar_enabled'] && ( $tel || $wa ) ) : ?>
			<div class="actbar" id="actbar">
				<div class="shell actbar-in">
					<?php if ( $tel ) : ?>
						<a class="btn btn-gold" href="<?php echo esc_url( 'tel:' . $tel ); ?>"><?php echo esc_html( $e['actbar_call'] ); ?></a>
					<?php endif; ?>
					<?php if ( $wa ) : ?>
						<a class="btn btn-line" href="<?php echo esc_url( 'https://wa.me/' . $wa ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $e['actbar_wa'] ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ── colour helpers ────────────────────────────────────────────────── */

	private static function mix( $hex, $amount, $toward ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '#' . $hex;
		}
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$c   = hexdec( substr( $hex, $i * 2, 2 ) );
			$c   = (int) round( $c + ( $toward - $c ) * $amount );
			$out .= str_pad( dechex( max( 0, min( 255, $c ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	private static function lighten( $hex, $amount ) {
		return self::mix( $hex, $amount, 255 );
	}

	private static function darken( $hex, $amount ) {
		return self::mix( $hex, $amount, 0 );
	}
}

/* ========================================================================== */
/*  6 · THE ENQUIRY FORM
 *
 *  One validation path behind two doors. Stores every enquiry whether or not the mail sends.
 */
/* ========================================================================== */


/* =========================================================================
   A CAR IS A POST

   Each car in the showroom is one entry in a Vehicles list: its own row,
   its own edit screen, its own status. It is the shape WordPress already
   knows how to search, sort, filter, draft, restore and hand to another
   plugin — none of which a row in a private table gets.

   The fields are NOT declared here. They are the same Vesla_Store schema
   the rest of the plugin uses, drawn by the same field renderer and
   cleaned by the same sanitiser. Add a field to the schema and it appears
   on this screen, in the API, on the car's page and in the published file,
   without being named a second time anywhere.
   ========================================================================= */

/**
 * The version to hang on one asset's URL: when that file last changed.
 *
 * VESLA_VERSION is the PLUGIN's version, and it only moves when the plugin is
 * released. Every stylesheet and script was therefore served as ?ver=1.1.0 no
 * matter how many times it was edited, so a browser that had cached one went
 * on serving the old copy — and a change that was live on the server looked,
 * to whoever had the page open, as though it had never been made.
 *
 * Keyed to the file itself: a file that changed gets a new address, and one
 * that did not stays cached. Falls back to the plugin version if the file
 * cannot be read, which is what a stale-but-working cache is worth.
 */
function vesla_asset_ver( $file ) {
	static $seen = array();
	if ( isset( $seen[ $file ] ) ) {
		return $seen[ $file ];
	}
	$path = VESLA_DIR . $file;
	$when = file_exists( $path ) ? filemtime( $path ) : 0;
	$seen[ $file ] = $when ? (string) $when : VESLA_VERSION;
	return $seen[ $file ];
}
final class Vesla_Vehicle {

	const TYPE = 'vesla_vehicle';

	/** Where a field's value is kept. Underscored, so it stays out of the
	    Custom Fields box — these are edited by the panel, not by hand. */
	const META = '_vesla_';

	/** The car's own number, which its web address is built from. */
	const ID_META = '_vesla_car_id';

	/* The three facts worth filtering a list of cars by. Kept as taxonomies
	   rather than only as meta so the admin gets WordPress's own dropdown
	   filters and counts for free, and kept in step from the fields on save
	   so nobody has to enter a make twice. */
	const TAX = array(
		'vesla_make' => 'make',
		'vesla_body' => 'body',
		'vesla_fuel' => 'fuel',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::TYPE . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_list' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		/* Anything that changes a car invalidates what the front end holds. */
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'changed' ) );
		add_action( 'trashed_post', array( __CLASS__, 'changed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'changed' ) );
		add_action( 'deleted_post', array( __CLASS__, 'changed' ) );

		/* ── the Car brands screen ── */
		add_action( 'vesla_make_add_form_fields', array( __CLASS__, 'brand_add_field' ) );
		add_action( 'vesla_make_edit_form_fields', array( __CLASS__, 'brand_edit_field' ) );
		add_action( 'created_vesla_make', array( __CLASS__, 'brand_save' ) );
		add_action( 'edited_vesla_make', array( __CLASS__, 'brand_save' ) );
		add_filter( 'manage_edit-vesla_make_columns', array( __CLASS__, 'brand_columns' ) );
		add_filter( 'manage_vesla_make_custom_column', array( __CLASS__, 'brand_column' ), 10, 3 );
		/* A logo changes what the strip paints, so it invalidates the same
		   caches a car does. */
		add_action( 'created_vesla_make', array( __CLASS__, 'changed' ) );
		add_action( 'edited_vesla_make', array( __CLASS__, 'changed' ) );
		add_action( 'delete_vesla_make', array( __CLASS__, 'changed' ) );
	}

	/* ── the Car brands screen ───────────────────────────────────────────
	   The picker is the same one the settings screen uses -- admin.js binds
	   .vesla-image-pick by delegation on the document, so a control printed
	   on a taxonomy screen works without a line of new script, provided the
	   media library is loaded. */

	private static function brand_control( $id ) {
		$url = $id ? wp_get_attachment_image_url( (int) $id, 'medium' ) : '';
		?>
		<div class="vesla-image" data-vesla-image style="max-width:320px">
			<div class="vesla-image-preview<?php echo $url ? '' : ' is-empty'; ?>">
				<?php if ( $url ) : ?>
					<img src="<?php echo esc_url( $url ); ?>" alt="">
				<?php else : ?>
					<span><?php esc_html_e( 'No logo yet', 'vesla-landing' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="vesla-image-act">
				<button type="button" class="button vesla-image-pick"><?php esc_html_e( 'Choose logo', 'vesla-landing' ); ?></button>
				<button type="button" class="button-link vesla-image-clear"<?php echo $id ? '' : ' hidden'; ?>>
					<?php esc_html_e( 'Remove', 'vesla-landing' ); ?>
				</button>
			</div>
			<input type="hidden" name="<?php echo esc_attr( self::LOGO_META ); ?>"
			       value="<?php echo esc_attr( $id ? (int) $id : '' ); ?>" class="vesla-image-id">
		</div>
		<p class="description">
			<?php esc_html_e( 'Shown in the strip of makes above the cars. A transparent PNG or an SVG sits best — the tiles are white, so a logo with its own white box will show its edges. Leave it empty and the brand appears as its name instead.', 'vesla-landing' ); ?>
		</p>
		<?php
	}

	public static function brand_add_field() {
		wp_enqueue_media();
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Logo', 'vesla-landing' ); ?></label>
			<?php self::brand_control( 0 ); ?>
		</div>
		<?php
	}

	public static function brand_edit_field( $term ) {
		wp_enqueue_media();
		$id = get_term_meta( $term->term_id, self::LOGO_META, true );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Logo', 'vesla-landing' ); ?></label></th>
			<td><?php self::brand_control( $id ); ?></td>
		</tr>
		<?php
	}

	public static function brand_save( $term_id ) {
		/* Nonce checked by WordPress before these hooks run; the capability is
		   the one the taxonomy itself is registered with. */
		if ( ! isset( $_POST[ self::LOGO_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$id = absint( wp_unslash( $_POST[ self::LOGO_META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $id ) {
			update_term_meta( $term_id, self::LOGO_META, $id );
		} else {
			delete_term_meta( $term_id, self::LOGO_META );
		}
	}

	public static function brand_columns( $columns ) {
		/* The logo first, because it is the one thing this screen is for and
		   the one thing you cannot tell from the name. */
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'name' === $key ) {
				$out['vesla_logo'] = __( 'Logo', 'vesla-landing' );
			}
			$out[ $key ] = $label;
		}
		unset( $out['description'], $out['slug'] );
		return $out;
	}

	public static function brand_column( $content, $column, $term_id ) {
		if ( 'vesla_logo' !== $column ) {
			return $content;
		}
		$id  = (int) get_term_meta( $term_id, self::LOGO_META, true );
		$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		if ( ! $url ) {
			return '<span style="opacity:.5">' . esc_html__( 'shown as its name', 'vesla-landing' ) . '</span>';
		}
		return '<img src="' . esc_url( $url ) . '" alt="" style="max-width:56px;max-height:34px;object-fit:contain;vertical-align:middle">';
	}

	public static function changed( $post_id = 0 ) {
		if ( $post_id && get_post_type( $post_id ) !== self::TYPE ) {
			return;
		}
		Vesla_Settings::forget();
		Vesla_Store::forget_cars();
		do_action( 'vesla_content_saved' );
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels' => array(
					'name'               => __( 'Vehicles', 'vesla-landing' ),
					'singular_name'      => __( 'Vehicle', 'vesla-landing' ),
					'menu_name'          => __( 'Vehicles', 'vesla-landing' ),
					'add_new'            => __( 'Add a car', 'vesla-landing' ),
					'add_new_item'       => __( 'Add a car', 'vesla-landing' ),
					'edit_item'          => __( 'Edit car', 'vesla-landing' ),
					'new_item'           => __( 'New car', 'vesla-landing' ),
					'search_items'       => __( 'Search cars', 'vesla-landing' ),
					'not_found'          => __( 'No cars yet. Press “Add a car”.', 'vesla-landing' ),
					'not_found_in_trash' => __( 'No cars in the bin.', 'vesla-landing' ),
					'all_items'          => __( 'All cars', 'vesla-landing' ),
				),
				/* Not publicly queryable, deliberately. A car is served at
					   /cars/<slug>/ by this plugin's own route, and letting WordPress
					   serve it a second time at ?post_type=vesla_vehicle would be the
					   same car at two addresses — which is the duplicate the canonical
					   work exists to prevent. */
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,   // the public API is vesla/v1, not wp/v2
				'menu_position'       => 4,
				'menu_icon'           => 'dashicons-dashboard',
				'has_archive'         => false,
				'rewrite'             => false,
				'hierarchical'        => false,
				/* The title is written from the make, model and year on save, and
					   page-attributes is what gives the list a drag order. */
				'supports'            => array( 'title', 'page-attributes' ),
			)
		);

		foreach ( self::TAX as $tax => $field ) {
			/* Makes get a screen of their own; body and fuel do not.

			   All three are still filled in from the car's own fields on save,
			   so none of them is somewhere anybody has to type. What makes the
			   make different is that it now carries a picture: the marque's
			   logo, which cannot be worked out from a car and has to be
			   attached once, somewhere. A term is the right somewhere. It
			   already exists for every make in stock, it is already tied to
			   every car of that make, and it appears and disappears with the
			   stock -- so the list of brands can never drift out of step with
			   the cars the way a hand-kept list in the settings would. */
			$is_make = ( 'vesla_make' === $tax );

			register_taxonomy(
				$tax,
				self::TYPE,
				array(
					'labels' => $is_make
						? array(
							'name'          => __( 'Car brands', 'vesla-landing' ),
							'singular_name' => __( 'Car brand', 'vesla-landing' ),
							'menu_name'     => __( 'Car brands', 'vesla-landing' ),
							'all_items'     => __( 'All car brands', 'vesla-landing' ),
							'edit_item'     => __( 'Edit car brand', 'vesla-landing' ),
							'update_item'   => __( 'Update car brand', 'vesla-landing' ),
							'add_new_item'  => __( 'Add a car brand', 'vesla-landing' ),
							'new_item_name' => __( 'Brand name — spelled as it is on the cars', 'vesla-landing' ),
							'search_items'  => __( 'Search car brands', 'vesla-landing' ),
							'not_found'     => __( 'No brands yet. They appear here as you add cars.', 'vesla-landing' ),
							'back_to_items' => __( '← Back to car brands', 'vesla-landing' ),
						)
						: array( 'name' => self::tax_label( $field ) ),
					'public'            => false,
					'show_ui'           => $is_make,
					'show_in_menu'      => $is_make,
					'show_admin_column' => false,
					'hierarchical'      => false,
					'rewrite'           => false,
					/* No free-tagging box on the car's own screen: the make is
					   typed into the car's Make field and this is kept in step
					   from there. Two places to set one thing is how they end up
					   disagreeing. */
					'meta_box_cb'       => false,
				)
			);
		}
	}

	/** Where a brand's logo is kept, on the make term. */
	const LOGO_META = 'vesla_brand_logo';

	/**
	 * The logo for a make, by name, or '' when there is not one.
	 *
	 * Looked up by name rather than by term id because that is what a car
	 * carries -- the make is a string on the car and a term beside it, and the
	 * string is the one the grid, the filters and the strip all read.
	 */
	public static function brand_logo( $make, $size = 'medium' ) {
		$make = trim( (string) $make );
		if ( '' === $make ) {
			return '';
		}
		$term = get_term_by( 'name', $make, 'vesla_make' );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$id = (int) get_term_meta( $term->term_id, self::LOGO_META, true );
		if ( ! $id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, $size );
		return $url ? $url : '';
	}

	private static function tax_label( $field ) {
		$fields = Vesla_Store::car_fields();
		return isset( $fields[ $field ]['label'] ) ? $fields[ $field ]['label'] : $field;
	}

	/* ── the edit screen ──────────────────────────────────────────── */

	public static function boxes() {
		add_meta_box(
			'vesla_car',
			__( 'The car', 'vesla-landing' ),
			array( __CLASS__, 'panel' ),
			self::TYPE,
			'normal',
			'high'
		);
	}

	public static function panel( $post ) {
		wp_nonce_field( 'vesla_car_save', 'vesla_car_nonce' );
		$fields = Vesla_Store::car_fields();
		$group  = '';
		echo '<div class="vesla-wrap vesla-carbox"><div class="vesla-fields">';
		foreach ( $fields as $key => $def ) {
			if ( ! empty( $def['group'] ) && $def['group'] !== $group ) {
				$group = $def['group'];
				printf( '<p class="vesla-rep-group">%s</p>', esc_html( $group ) );
			}
			Vesla_Admin::field(
				'vesla_car[' . $key . ']',
				get_post_meta( $post->ID, self::META . $key, true ),
				$def,
				'vesla_car_' . $key
			);

			/* The Make field is the link to the brand, and nothing on this
			   screen said so. It is the whole mapping -- there is no second
			   control, and a car cannot point at the wrong brand -- but an
			   invisible mapping is one nobody trusts, and being asked twice
			   where to map a car to its brand is what that looks like. So it
			   says so, and shows what this car currently resolves to. */
			if ( 'make' === $key ) {
				self::brand_hint( (string) get_post_meta( $post->ID, self::META . 'make', true ) );
			}
		}
		echo '</div></div>';
	}

	/**
	 * What brand this car resolves to, printed under the Make field.
	 *
	 * Four states, and each says what to do next rather than only what is
	 * true: nothing typed, a brand with a logo, a brand without one, and a
	 * make that is not a brand yet because the car has not been saved.
	 */
	private static function brand_hint( $make ) {
		$make = trim( $make );
		/* An ordinary paragraph, NOT a flex row. Flex made every text node
		   between the tags its own box, so the sentence broke into five
		   fragments that wrapped independently and read as gibberish. The
		   logo only ever needed to sit on the line, which vertical-align
		   does. */
		echo '<div class="vesla-row"><div class="vesla-label"></div><div class="vesla-control"><p class="vesla-help" id="vesla-brand-hint">';

		if ( '' === $make ) {
			esc_html_e( 'Pick the brand this car belongs to. Brands are added once, with their logos, under Vehicles → Car brands.', 'vesla-landing' );
			echo '</p></div></div>';
			return;
		}

		$term = get_term_by( 'name', $make, 'vesla_make' );
		$url  = $term && ! is_wp_error( $term ) ? self::brand_logo( $make, 'thumbnail' ) : '';
		$link = admin_url( 'edit-tags.php?taxonomy=vesla_make&post_type=' . self::TYPE );

		if ( $url ) {
			printf(
				'<img src="%s" alt="" style="max-width:40px;max-height:26px;object-fit:contain;vertical-align:middle;margin-right:8px">',
				esc_url( $url )
			);
			printf(
				/* translators: %s: the make, e.g. Audi. */
				esc_html__( 'This car is a %s, and that brand has a logo — it shows in the strip above the cars.', 'vesla-landing' ),
				'<strong>' . esc_html( $make ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
			);
		} elseif ( $term && ! is_wp_error( $term ) ) {
			printf(
				/* translators: 1: the make. 2: opening link tag. 3: closing link tag. */
				esc_html__( 'This car is a %1$s. That brand has no logo yet, so it shows as its name — %2$sadd one under Car brands%3$s.', 'vesla-landing' ),
				'<strong>' . esc_html( $make ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
				'<a href="' . esc_url( $link ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
				'</a>'
			);
		} else {
			/* Only reachable for a car whose brand has been renamed or deleted
			   since it was set -- the picker cannot produce this state. */
			printf(
				/* translators: 1: the make stored on this car. 2: opening link tag. 3: closing link tag. */
				esc_html__( 'This car says %1$s, which is no longer one of the brands — it has been renamed or removed. Pick its brand again, or restore it under %2$sCar brands%3$s.', 'vesla-landing' ),
				'<strong>' . esc_html( $make ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
				'<a href="' . esc_url( $link ) . '">', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
				'</a>'
			);
		}

		echo '</p></div></div>';
	}

	public static function save( $post_id, $post ) {
		/* Every one of these is a way this fires when nobody pressed Save:
			   an autosave, a revision, a bulk edit, a quick edit. Writing the
			   panel's values then would blank a car that was never on screen. */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['vesla_car_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vesla_car_nonce'] ) ), 'vesla_car_save' ) ) {
			return;
		}

		/* wp_unslash here and NOT in the sanitiser: this door is a raw $_POST,
			   where options.php had already unslashed the one the settings form
			   comes through. Unslashing twice is what once ate the backslashes
			   out of a Windows path. */
		$in = isset( $_POST['vesla_car'] ) && is_array( $_POST['vesla_car'] )
			? wp_unslash( $_POST['vesla_car'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value is cleaned by the schema below.
			: array();

		$clean = array();
		foreach ( Vesla_Store::car_fields() as $key => $def ) {
			$raw = isset( $in[ $key ] ) ? $in[ $key ] : null;
			$val = Vesla_Settings::clean_one( $raw, $def );
			$clean[ $key ] = $val;
			update_post_meta( $post_id, self::META . $key, $val );
		}

		/* The number the car's web address is built from. Set once and never
			   again: it is what keeps a link sent to a customer last month
			   pointing at the same car today. */
		if ( ! get_post_meta( $post_id, self::ID_META, true ) ) {
			update_post_meta( $post_id, self::ID_META, $post_id );
		}

		self::sync_terms( $post_id, $clean );
		self::sync_title( $post_id, $post, $clean );
	}

	/** Keep the filter dropdowns in step with the car's own fields. */
	/** The migration fills these in too, and it is not inside this class. */
	public static function sync_terms_public( $post_id, $clean ) {
		self::sync_terms( $post_id, $clean );
	}

	private static function sync_terms( $post_id, $clean ) {
		foreach ( self::TAX as $tax => $field ) {
			$value = isset( $clean[ $field ] ) ? trim( (string) $clean[ $field ] ) : '';
			wp_set_object_terms( $post_id, '' === $value ? array() : array( $value ), $tax, false );
		}
	}

	/**
	 * The title, written from the car unless somebody has typed their own.
	 *
	 * A list of twenty-four cars is only usable if the rows say what the cars
	 * are, and asking somebody to type “2020 Ford EcoSport” into a title box
	 * directly above the boxes where they typed Ford, EcoSport and 2020 is
	 * asking them to say it twice and to keep the two in step for ever.
	 */
	private static function sync_title( $post_id, $post, $clean ) {
		$made = trim(
			( $clean['year'] ? $clean['year'] . ' ' : '' )
			. $clean['make'] . ' ' . $clean['model']
			. ( $clean['trim'] ? ' ' . $clean['trim'] : '' )
		);
		if ( '' === $made ) {
			return;
		}

		$now = trim( $post->post_title );
		$was = (string) get_post_meta( $post_id, '_vesla_auto_title', true );

		/* Left alone if it holds anything other than what this last wrote:
			   that means a person typed it, and a person's wording wins. */
		if ( '' !== $now && $now !== $was && __( 'Auto Draft' ) !== $now ) {
			return;
		}
		if ( $now === $made ) {
			return;
		}

		remove_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10 );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $made ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		update_post_meta( $post_id, '_vesla_auto_title', $made );
	}

	/* ── the list ───────────────────────────────────────────────── */

	public static function columns( $columns ) {
		$out = array( 'cb' => isset( $columns['cb'] ) ? $columns['cb'] : '' );
		$out['vesla_photo'] = __( 'Photo', 'vesla-landing' );
		$out['title']       = __( 'Car', 'vesla-landing' );
		$out['vesla_price'] = __( 'Price', 'vesla-landing' );
		$out['vesla_km']    = __( 'Mileage', 'vesla-landing' );
		$out['vesla_ref']   = __( 'Stock ref', 'vesla-landing' );
		$out['date']        = isset( $columns['date'] ) ? $columns['date'] : __( 'Date' );
		return $out;
	}

	public static function column( $column, $post_id ) {
		$get = function ( $k ) use ( $post_id ) {
			return get_post_meta( $post_id, self::META . $k, true );
		};
		switch ( $column ) {
			case 'vesla_photo':
				$id  = absint( $get( 'photo' ) );
				$img = $id ? wp_get_attachment_image( $id, array( 60, 45 ) ) : '';
				if ( $img ) {
					echo $img; // phpcs:ignore WordPress.Security.EscapeOutput -- core builds this.
				} else {
					/* Said plainly rather than left blank: most of the stock is
					   listed before it is photographed, and a blank cell does
					   not tell anyone which ones still need doing. */
					echo '<span class="vesla-nophoto">' . esc_html__( 'none yet', 'vesla-landing' ) . '</span>';
				}
				break;
			case 'vesla_price':
				$p = (int) $get( 'price' );
				echo $p
					? esc_html( Vesla_Settings::get( 'stock', 'currency', '' ) . ' ' . number_format_i18n( $p ) )
					: '&#8212;';
				break;
			case 'vesla_km':
				$k = (int) $get( 'km' );
				echo $k ? esc_html( number_format_i18n( $k ) . ' km' ) : '&#8212;';
				break;
			case 'vesla_ref':
				$r = (string) $get( 'ref' );
				echo '' !== $r ? esc_html( $r ) : '&#8212;';
				break;
		}
	}

	public static function sortable( $columns ) {
		$columns['vesla_price'] = 'vesla_price';
		$columns['vesla_km']    = 'vesla_km';
		return $columns;
	}

	/**
	 * The list's own order, and the numeric sorts.
	 *
	 * Ordered by menu_order so the drag handles on the Quick Edit screen mean
	 * something, and so the order here is the order on the website.
	 */
	public static function order_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== self::TYPE ) {
			return;
		}
		$by = $query->get( 'orderby' );
		if ( 'vesla_price' === $by || 'vesla_km' === $by ) {
			$query->set( 'meta_key', self::META . ( 'vesla_price' === $by ? 'price' : 'km' ) );
			$query->set( 'orderby', 'meta_value_num' );
			return;
		}
		if ( ! $by ) {
			$query->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
		}
	}

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::TYPE !== $screen->post_type ) {
			return;
		}
		/* The same stylesheet and script the settings screen uses, because the
		   panel is drawn by the same field renderer -- the gallery picker, the
		   tick lists and the rich editors are all that code. */
		wp_enqueue_media();

		/* And the editor, which this screen was missing.

		   admin.js upgrades a 'rich' field to TinyMCE the first time it is
		   clicked, but it checks for wp.editor first and quietly does nothing
		   when it is absent -- which is honest degradation, and also why this
		   went unnoticed: "About this car" looked like a plain textarea and
		   behaved like one, on the screen where the longest prose on the site
		   is written. The settings screen loaded this and the car screen did
		   not, so the same field type behaved differently depending on where
		   you met it.

		   This is the bootstrap only. No editor starts until a field is
		   clicked, and where user_can_richedit() fails WordPress serves the
		   plain half and the field still saves the same markup. */
		wp_enqueue_editor();
		wp_enqueue_style( 'vesla-admin', VESLA_URL . 'assets/admin.css', array(), vesla_asset_ver( 'assets/admin.css' ) );
		wp_enqueue_script( 'vesla-admin', VESLA_URL . 'assets/admin.js', array( 'jquery' ), vesla_asset_ver( 'assets/admin.js' ), true );
	}
}
class Vesla_Enquiry {
	const ACTION = 'vesla_enquiry';
	const TYPE   = 'vesla_enquiry';

	/** Rate limit: this many submissions per window, per address. */
	const RATE_MAX    = 5;
	const RATE_WINDOW = 900; // 15 minutes, in seconds.

	/**
	 * The field limits, defined ONCE and handed to the browser.
	 *
	 * These are not settings — a showroom has no reason to decide that a name
	 * may be 60 characters rather than 55 — but they must not be written down
	 * twice either. They used to be: the numbers lived here AND as literals in
	 * app.js, so a change in one place quietly created a form that accepted
	 * what the server then rejected. Vesla_Render::js_data() sends this array
	 * to the browser, so both ends count to the same numbers.
	 */
	public static function limits() {
		return array(
			'nameMin'   => 2,
			'nameMax'   => 60,
			'phoneMin'  => 7,
			'phoneMax'  => 15,
			'emailMax'  => 254,
			'carMax'    => 80,
			'noteMax'   => 1000,
		);
	}

	/**
	 * A message the administrator can edit, from the Enquiry form wording
	 * section. The same call is used by the browser (through js_data) and by
	 * the checks below, which is what stops the two disagreeing — and they had
	 * already disagreed: the browser said "That number is too short to dial"
	 * where this file said "That phone number does not look right" about the
	 * very same number.
	 */
	private static function msg( $key ) {
		return (string) Vesla_Settings::get( 'messages', $key, '' );
	}

	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );

		/* A second, deliberately tiny endpoint whose only job is to hand back a
		   fresh nonce. See the note on `stale_nonce` in handle(). */
		add_action( 'wp_ajax_vesla_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_vesla_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
	}

	/**
	 * Character length, without assuming mbstring is installed.
	 *
	 * WordPress recommends the extension but does not require it, and a
	 * stripped-down cPanel PHP build can be missing it. An undefined
	 * mb_strlen() is a fatal error, and the one endpoint on this site that must
	 * never fail is this one — a fatal here is a lost sales lead with no trace.
	 *
	 * The fallback counts UTF-8 characters rather than bytes, so a name written
	 * in Arabic is not judged three times as long as it is.
	 */
	private static function len( $s ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s, 'UTF-8' );
		}
		return strlen( preg_replace( '/[\x80-\xBF]/', '', (string) $s ) );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   1 · RATE LIMITING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The visitor's address, hashed.
	 *
	 * The raw address is never stored or logged: it is personal data, it is not
	 * needed to count submissions, and hashing it with the site's own salt means
	 * a database dump cannot be turned back into a list of who visited.
	 *
	 * REMOTE_ADDR only. X-Forwarded-For is trivially forged, and trusting it by
	 * default would let one bot present a different address on every request and
	 * walk straight through this limit. A site genuinely behind a proxy can opt
	 * in through the filter, having decided its proxy is trustworthy.
	 */
	private static function client_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		/**
		 * Filters the address used for rate limiting.
		 *
		 * Only override this if the site sits behind a proxy or CDN you control
		 * and whose forwarding header you can trust.
		 *
		 * @param string $ip The address from REMOTE_ADDR.
		 */
		$ip = apply_filters( 'vesla_client_ip', $ip );

		return hash( 'sha256', wp_salt( 'nonce' ) . '|' . $ip );
	}

	/** How many submissions this address has made inside the current window. */
	private static function rate_count() {
		$count = get_transient( 'vesla_rl_' . self::client_hash() );
		return $count ? (int) $count : 0;
	}

	/**
	 * Records one submission against this address.
	 *
	 * The window is not extended on each hit: the transient keeps the expiry it
	 * was created with, so five attempts buy a wait of at most fifteen minutes
	 * rather than a lockout that renews itself for as long as someone keeps
	 * trying. A real customer who mistypes their number five times is not
	 * someone to lock out for the afternoon.
	 */
	private static function rate_bump() {
		$key   = 'vesla_rl_' . self::client_hash();
		$count = self::rate_count();
		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   2 · THE ENDPOINT
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The admin-ajax entry point, used by the page this plugin renders itself.
	 *
	 * It does no checking of its own: it reshapes the request and hands it to
	 * submit(). The REST route used by the headless front end does exactly the
	 * same. One validation path, two doors into it — which is the only way the
	 * two can be guaranteed to answer identically.
	 */
	public static function handle() {
		/* The nonce belongs to THIS door only. A same-origin form rendered by
		   this plugin can carry one; a Next.js front end on another origin
		   cannot meaningfully get one, so the REST door is protected by the
		   honeypot and the rate limit instead — see class-vesla-rest.php. */
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			self::rate_bump(); // a bot replaying rubbish still spends its allowance
			wp_send_json_error(
				array(
					'code'    => 'stale_nonce',
					'message' => __( 'This page has been open a while. Please try once more.', 'vesla-landing' ),
				),
				403
			);
		}

		$result = self::submit(
			array(
				'name'    => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
				'phone'   => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
				'email'   => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
				'car'     => isset( $_POST['car'] ) ? wp_unslash( $_POST['car'] ) : '',
				'message' => isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '',
				'website' => isset( $_POST['website'] ) ? wp_unslash( $_POST['website'] ) : '',
				'type'    => isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : '',
				'details' => isset( $_POST['details'] ) ? wp_unslash( $_POST['details'] ) : '',
			)
		);

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		$status = ( 'rate_limited' === $result['code'] ) ? 429 : 200;
		wp_send_json_error(
			array(
				'code'    => $result['code'],
				'fields'  => isset( $result['fields'] ) ? $result['fields'] : array(),
				'message' => $result['message'],
			),
			$status
		);
	}

	/**
	 * Everything that decides whether an enquiry is accepted.
	 *
	 * Called by BOTH doors — admin-ajax for the page this plugin renders, and
	 * the REST route for a headless front end. Never duplicate a check into a
	 * caller: the reason this function exists is that the browser checks and
	 * the server checks had already drifted apart once, and two server-side
	 * copies would drift the same way.
	 *
	 * @param array $in Raw, unslashed values: name, phone, email, car, message, website.
	 * @return array{ok:bool,code:string,message:string,fields?:array,id?:int}
	 */
	public static function submit( array $in ) {
		/* ── Rate limit first, before anything is validated and long before a
		   post is created. Checking it first is what makes it a limit: doing
		   the work and then refusing to save it would still let someone drive
		   load through the site all day. */
		if ( self::rate_count() >= self::RATE_MAX ) {
			return array(
				'ok'      => false,
				'code'    => 'rate_limited',
				'message' => self::msg( 'err_rate_limited' ),
			);
		}

		$name  = sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' );
		$phone = sanitize_text_field( isset( $in['phone'] ) ? $in['phone'] : '' );
		$car   = sanitize_text_field( isset( $in['car'] ) ? $in['car'] : '' );
		$note  = sanitize_textarea_field( isset( $in['message'] ) ? $in['message'] : '' );

		/* Read after the car, because an untyped post -- a prerendered page from
		   before this field existed -- is filed by whether it names one. */
		$type    = self::read_type( isset( $in['type'] ) ? $in['type'] : '', $car );
		$details = self::read_details( isset( $in['details'] ) ? $in['details'] : '' );

		/* The raw value is kept as well as the cleaned one. sanitize_email()
		   returns an empty string for something like "bad" rather than
		   flagging it, so testing only the cleaned value made an invalid
		   address indistinguishable from no address at all — the browser told
		   the visitor it was wrong and the server quietly dropped it. */
		$email_raw = trim( (string) ( isset( $in['email'] ) ? $in['email'] : '' ) );
		$email     = sanitize_email( $email_raw );

		/* ── The honeypot. A box no person can see, reach by keyboard, or be
		   offered by autofill; bots fill in every field they find. Answered
		   with the same success message a real send gets, so whoever is on the
		   other end learns nothing about why it did not arrive. */
		if ( ! empty( $in['website'] ) ) {
			self::rate_bump();
			return array(
				'ok'      => true,
				'code'    => 'ok',
				'message' => Vesla_Settings::get( 'contact', 'form_success', '' ),
			);
		}

		/* ── Validation. The same rules, the same wording and the same numbers
		   the browser applies — all three read from one place. */
		$errors = array();
		$lim    = self::limits();

		if ( '' === $name ) {
			$errors['q-name'] = self::msg( 'err_name_required' );
		} elseif ( self::len( $name ) < $lim['nameMin'] ) {
			$errors['q-name'] = self::msg( 'err_name_short' );
		} elseif ( self::len( $name ) > $lim['nameMax'] ) {
			$errors['q-name'] = self::msg( 'err_name_long' );
		} elseif ( ! preg_match( '/^[\p{L}\p{M}][\p{L}\p{M}\s\'’.-]*$/u', $name ) ) {
			$errors['q-name'] = self::msg( 'err_name_letters' );
		}

		$digits = preg_replace( '/\D/', '', $phone );
		if ( '' === $phone ) {
			$errors['q-phone'] = self::msg( 'err_phone_required' );
		} elseif ( preg_match( '/[^0-9+()\-\s]/', $phone ) ) {
			$errors['q-phone'] = self::msg( 'err_phone_letters' );
		} elseif ( strlen( $digits ) < $lim['phoneMin'] ) {
			$errors['q-phone'] = self::msg( 'err_phone_short' );
		} elseif ( strlen( $digits ) > $lim['phoneMax'] ) {
			$errors['q-phone'] = self::msg( 'err_phone_long' );
		} elseif ( '+' === substr( trim( $phone ), 0, 1 ) && strlen( $digits ) < 8 ) {
			$errors['q-phone'] = self::msg( 'err_phone_country' );
		}

		if ( '' !== $email_raw ) {
			if ( self::len( $email_raw ) > $lim['emailMax'] ) {
				$errors['q-email'] = self::msg( 'err_email_long' );
			} elseif ( '' === $email || ! is_email( $email ) ) {
				$errors['q-email'] = self::msg( 'err_email_invalid' );
			}
		}
		if ( self::len( $car ) > $lim['carMax'] ) {
			$errors['q-car'] = self::msg( 'err_car_long' );
		}
		if ( self::len( $note ) > $lim['noteMax'] ) {
			$errors['q-note'] = self::msg( 'err_note_long' );
		}

		/* Anything that looks like markup or a script is refused outright. This
		   text is emailed and may be read in an HTML mail client, so it is not
		   only this site being protected. */
		$fields = array( 'q-name' => $name, 'q-phone' => $phone, 'q-car' => $car, 'q-note' => $note );
		foreach ( $fields as $field => $v ) {
			if ( preg_match( '/[<>]|javascript:|\son\w+\s*=/i', $v ) ) {
				$errors[ $field ] = self::msg( 'err_blocked' );
			}
		}

		if ( $errors ) {
			/* A failed submission still counts, or the limit is sidestepped by
			   sending rubbish. */
			self::rate_bump();
			return array(
				'ok'      => false,
				'code'    => 'invalid',
				'fields'  => $errors,
				'message' => 1 === count( $errors )
					? self::msg( 'err_summary_one' )
					: sprintf( self::msg( 'err_summary_many' ), count( $errors ) ),
			);
		}

		self::rate_bump();

		/* ── Send, then store regardless of whether the send worked. */
		$sent = self::send( compact( 'name', 'phone', 'email', 'car', 'note', 'type', 'details' ) );
		$id   = self::store( compact( 'name', 'phone', 'email', 'car', 'note', 'type', 'details' ), $sent );

		/* Storage is what decides what the visitor is told, not the email.
		 *
		 * From where they are standing the enquiry HAS succeeded once it is in
		 * the database: the showroom has it, it is in the list, it is in the
		 * CSV, and the administrator gets a notice the moment three in a row
		 * fail to send. Telling a customer "that did not send" when their
		 * message was in fact captured reads as a failure they must work
		 * around, and a good proportion of them simply leave instead of
		 * telephoning.
		 *
		 * A failed send is the showroom's problem to fix, and every signal for
		 * it points at the showroom -- the cross in the list, the tooltip, the
		 * dashboard notice -- not at the customer.
		 *
		 * If the INSERT itself failed there is nothing anywhere, and that is
		 * the one case where the visitor must be told to call.
		 */
		if ( ! $id ) {
			return array(
				'ok'      => false,
				'code'    => 'not_stored',
				'message' => self::msg( 'send_fail' ),
			);
		}

		return array(
			'ok'      => true,
			'code'    => 'ok',
			'id'      => $id,
			'message' => Vesla_Settings::get( 'contact', 'form_success', __( 'Thank you — we will be in touch.', 'vesla-landing' ) ),
		);
	}

	/**
	 * Hands back a fresh nonce.
	 *
	 * There is nothing to protect here — a nonce is not a secret, and for a
	 * logged-out visitor it is tied only to the action and the day. What stops
	 * this being a way around the form's protections is that a nonce alone
	 * proves nothing: the rate limit, the honeypot and the validation all still
	 * apply to whatever is then submitted with it.
	 */
	public static function refresh_nonce() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   3 · SENDING
	   ═══════════════════════════════════════════════════════════════════════ */

	private static function send( $d ) {
		$to = Vesla_Settings::get( 'contact', 'form_to', '' );
		if ( ! $to ) {
			$to = Vesla_Settings::get( 'brand', 'email', get_option( 'admin_email' ) );
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$labels = self::types();
		$kind   = isset( $labels[ $d['type'] ] ) ? $labels[ $d['type'] ] : $labels['general'];

		/* The kind leads the subject: this is read on a phone as often as not,
		   where the subject line is most of what shows in the list. */
		$subject = sprintf(
			/* translators: 1: the kind of enquiry. 2: the car of interest, or the sender's name. */
			__( '%1$s — %2$s', 'vesla-landing' ),
			$kind,
			$d['car'] ? $d['car'] : $d['name']
		);

		$body = implode(
			"\n",
			array(
				__( 'Kind:', 'vesla-landing' ) . ' ' . $kind,
				__( 'Name:', 'vesla-landing' ) . ' ' . $d['name'],
				__( 'Phone:', 'vesla-landing' ) . ' ' . $d['phone'],
				__( 'Email:', 'vesla-landing' ) . ' ' . ( $d['email'] ? $d['email'] : '—' ),
				__( 'Car of interest:', 'vesla-landing' ) . ' ' . ( $d['car'] ? $d['car'] : '—' ) . self::detail_block( $d['details'] ),
				'',
				$d['note'] ? $d['note'] : '—',
				'',
				'—',
				/* translators: %s: the website address. */
				sprintf( __( 'Sent from %s', 'vesla-landing' ), home_url( '/' ) ),
			)
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		/* From: this site's own domain, never the visitor's address. A From the
		   sending server is not authorised for is what gets mail rejected by
		   SPF and DMARC. The visitor goes in Reply-To, where it belongs and
		   where pressing Reply in the mail client will find it. */
		$headers[] = sprintf( 'From: %s <%s>', $site, self::from_address() );
		if ( $d['email'] ) {
			$headers[] = 'Reply-To: ' . $d['name'] . ' <' . $d['email'] . '>';
		}

		return wp_mail( $to, $subject, $body, $headers );
	}

	/** An address on this site's own domain, for the From header. */
	private static function from_address() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', (string) $host );
		return 'no-reply@' . ( $host ? $host : 'localhost' );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   4 · STORING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * What kind of enquiry this is.
	 *
	 * Every one of these used to arrive as the same thing, so a trade-in and
	 * "is this still available" sat in one list looking identical and were
	 * worked in the order they came. The key is stored; the label is only ever
	 * for reading.
	 */
	public static function types() {
		return array(
			'general'  => __( 'General enquiry', 'vesla-landing' ),
			'car'      => __( 'Car enquiry', 'vesla-landing' ),
			'finance'  => __( 'Finance', 'vesla-landing' ),
			'trade_in' => __( 'Selling / trade-in', 'vesla-landing' ),
		);
	}

	/**
	 * The posted type, or the best guess when nothing usable was sent.
	 *
	 * Guessing rather than defaulting flat to 'general' matters for the pages
	 * already out there: a prerendered car page from before this existed posts
	 * no type at all, but it does post a car, and that is enough to file it.
	 */
	private static function read_type( $raw, $car ) {
		$key = sanitize_key( (string) $raw );
		if ( isset( self::types()[ $key ] ) ) {
			return $key;
		}
		return '' !== $car ? 'car' : 'general';
	}

	/**
	 * The figures the visitor was looking at when they pressed send.
	 *
	 * Sent as one JSON object rather than a fixed set of columns, because what
	 * is worth keeping differs by type -- a trade-in carries the car and the
	 * range it was valued at, a finance enquiry carries deposit, term and the
	 * monthly figure on screen. Everything is flattened to short strings: this
	 * is read by a person, never computed with.
	 */
	private static function read_details( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $label => $value ) {
			if ( count( $out ) >= 12 ) {
				break;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) $label );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			/* Same refusal the message body gets: this is emailed, and may be
			   read in an HTML mail client. */
			if ( preg_match( '/[<>]|javascript:|\son\w+\s*=/i', $label . ' ' . $value ) ) {
				continue;
			}
			$out[ self::cut( $label, 40 ) ] = self::cut( $value, 120 );
		}
		return $out;
	}

	private static function cut( $text, $max ) {
		return self::len( $text ) > $max ? mb_substr( $text, 0, $max ) : $text;
	}

	/**
	 * What follows the name in the list when there is no car to name.
	 *
	 * A trade-in has no car of ours by definition, so it used to read
	 * "Ahmed — general enquiry" alongside every other typeless row.
	 */
	private static function title_tail( $type ) {
		$labels = self::types();
		if ( 'general' !== $type && isset( $labels[ $type ] ) ) {
			return mb_strtolower( $labels[ $type ] );
		}
		return __( 'general enquiry', 'vesla-landing' );
	}

	/**
	 * The figures the visitor was looking at, as lines under the car.
	 *
	 * Appended to a line already in the message rather than added as its own
	 * element, so an enquiry carrying nothing extra reads exactly as it did.
	 */
	private static function detail_block( $details ) {
		if ( empty( $details ) ) {
			return '';
		}
		$out = '';
		foreach ( $details as $label => $value ) {
			$out .= "
" . $label . ': ' . $value;
		}
		return "
" . $out;
	}

	private static function store( $d, $sent ) {
		$id = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'private',
				'post_title'   => sprintf(
					'%s — %s',
					$d['name'],
					$d['car'] ? $d['car'] : self::title_tail( $d['type'] )
				),
				'post_content' => $d['note'],
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, '_vesla_phone', $d['phone'] );
		update_post_meta( $id, '_vesla_email', $d['email'] );
		update_post_meta( $id, '_vesla_car', $d['car'] );
		update_post_meta( $id, '_vesla_type', $d['type'] );
		if ( $d['details'] ) {
			update_post_meta( $id, '_vesla_details', $d['details'] );
		}
		update_post_meta( $id, '_vesla_emailed', $sent ? 'yes' : 'no' );

		return $id;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   5 · THE POST TYPE
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Enquiries are kept as a private post type so they can be read in the
	 * admin even when email is down. Never public, never in search.
	 *
	 * SECURITY — the capability mapping is the important part here.
	 *
	 * This used to be `capability_type => 'post'`, which maps the type's
	 * capabilities onto edit_posts / edit_others_posts / read_private_posts.
	 * An Editor holds all of those. Hiding the submenu under a parent page that
	 * requires manage_options hid the link but not the screen: anyone with the
	 * Editor role could read every customer's name, telephone number and email
	 * address by typing edit.php?post_type=vesla_enquiry into the address bar.
	 *
	 * Every capability is therefore mapped onto manage_options, so the
	 * capability check itself refuses rather than merely the menu being absent.
	 * These are customer records; they belong to whoever administers the site.
	 */
	public static function register_type() {
		register_post_type(
			self::TYPE,
			array(
				'labels' => array(
					'name'               => __( 'Enquiries', 'vesla-landing' ),
					'singular_name'      => __( 'Enquiry', 'vesla-landing' ),
					'menu_name'          => __( 'Enquiries', 'vesla-landing' ),
					'search_items'       => __( 'Search enquiries', 'vesla-landing' ),
					'not_found'          => __( 'No enquiries yet.', 'vesla-landing' ),
					'not_found_in_trash' => __( 'No enquiries in the bin.', 'vesla-landing' ),
					'all_items'          => __( 'Enquiries', 'vesla-landing' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'vesla-landing',
				'show_in_rest'        => false, // never over the REST API
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'map_meta_cap'        => true,
				'capabilities'        => array(
					/* Nobody writes an enquiry by hand; they arrive from the form. */
					'create_posts'           => 'do_not_allow',

					/* Only PRIMITIVE capabilities below. Do not add 'edit_post',
					   'read_post' or 'delete_post' here: WordPress treats whatever
					   those three point at as a META capability from then on, site
					   wide (_post_type_meta_capabilities). Pointing them at
					   'manage_options' turned manage_options into a meta cap that
					   needs a post ID, so every check without one returned false and
					   the whole admin menu vanished for everybody. With
					   map_meta_cap => true the per-post checks are derived from the
					   primitives anyway, so the protection here is unchanged. */
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'delete_private_posts'   => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'delete_published_posts' => 'manage_options',
				),
				'supports'  => array( 'title', 'editor' ),
				'menu_icon' => 'dashicons-email',
			)
		);
	}
}

/* ========================================================================== */
/*  7 · THE ENQUIRIES SCREENS
 *
 *  Makes the stored copy visible — a delivery flag nobody can see is not a safety net.
 */
/* ========================================================================== */

class Vesla_Enquiry_Admin {
	const TYPE = 'vesla_enquiry';

	public static function init() {
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::TYPE . '_sortable_columns', array( __CLASS__, 'sortable' ) );

		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_query' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );

		add_action( 'admin_post_vesla_export', array( __CLASS__, 'export_csv' ) );

		/* The report is drawn at the top of the Enquiries screen rather than on
		   a page of its own. Two menu rows for one subject made the admin pick
		   between "the list" and "the figures" before knowing which they wanted,
		   and the list screen already carries the search, the filters and the
		   bulk actions a separate report page would have had to reimplement. */
		add_action( 'all_admin_notices', array( __CLASS__, 'report_panel' ) );

		/* The report had its own menu row for a while. The row is gone, but the
		   page stays registered so a bookmark still works -- registered and then
		   removed from the menu, which is the supported way to have a reachable
		   admin page with no row of its own. Without it the URL dies on
		   WordPress's bare "you are not allowed to access this page", which is
		   not what happened and sends the admin hunting for a permissions fault
		   that does not exist. */
		add_action( 'admin_menu', array( __CLASS__, 'redirect_old_report' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'mail_notice' ) );

		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
	}

	private static function on_list_screen() {
		$screen = get_current_screen();
		return $screen && 'edit-' . self::TYPE === $screen->id;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   THE LIST
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Columns, in the order somebody working through the morning's enquiries
	 * would want them: who, how to reach them, what about, did it arrive.
	 *
	 * The default "Title" column is replaced rather than added to — the title
	 * is only ever "name — car", which is the next three columns run together.
	 */
	public static function columns( $columns ) {
		return array(
			'cb'           => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'vesla_name'   => __( 'Name', 'vesla-landing' ),
			'vesla_phone'  => __( 'Phone', 'vesla-landing' ),
			'vesla_email'  => __( 'Email', 'vesla-landing' ),
			'vesla_type'   => __( 'Kind', 'vesla-landing' ),
			'vesla_car'    => __( 'Car', 'vesla-landing' ),
			'vesla_mailed' => __( 'Emailed', 'vesla-landing' ),
			'date'         => __( 'Received', 'vesla-landing' ),
		);
	}

	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'vesla_name':
				$title = get_the_title( $post_id );
				$name  = trim( explode( '—', $title )[0] );
				printf(
					'<strong><a class="row-title" href="%s">%s</a></strong>',
					esc_url( get_edit_post_link( $post_id ) ),
					esc_html( $name ? $name : __( '(no name)', 'vesla-landing' ) )
				);
				break;

			case 'vesla_type':
				$type   = (string) get_post_meta( $post_id, '_vesla_type', true );
				$labels = Vesla_Enquiry::types();
				/* An enquiry stored before the field existed has no type. Say so
				   plainly rather than calling it general, which would be a guess
				   dressed up as a fact. */
				if ( ! isset( $labels[ $type ] ) ) {
					echo '<span class="vesla-dash">—</span>';
					break;
				}
				printf(
					'<span class="vesla-kind vesla-kind-%s">%s</span>',
					esc_attr( $type ),
					esc_html( $labels[ $type ] )
				);
				break;

			case 'vesla_phone':
				$phone = get_post_meta( $post_id, '_vesla_phone', true );
				if ( ! $phone ) {
					echo '<span class="vesla-dash">—</span>';
					break;
				}
				/* Click to call. Most of these are answered on a phone or a
				   desktop with a softphone, and retyping a number read off a
				   screen is how digits get transposed. */
				printf(
					'<a href="tel:%s">%s</a>',
					esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ),
					esc_html( $phone )
				);
				break;

			case 'vesla_email':
				$email = get_post_meta( $post_id, '_vesla_email', true );
				if ( ! $email ) {
					echo '<span class="vesla-dash">—</span>';
					break;
				}
				printf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $email ) );
				break;

			case 'vesla_car':
				$car = get_post_meta( $post_id, '_vesla_car', true );
				echo $car ? esc_html( $car ) : '<span class="vesla-dash">—</span>';
				break;

			case 'vesla_mailed':
				self::mailed_badge( get_post_meta( $post_id, '_vesla_emailed', true ) );
				break;
		}
	}

	private static function mailed_badge( $flag ) {
		if ( 'yes' === $flag ) {
			printf(
				'<span class="vesla-flag vesla-flag--ok"><span class="dashicons dashicons-yes-alt"></span> %s</span>',
				esc_html__( 'Sent', 'vesla-landing' )
			);
			return;
		}
		/* Not merely a cross: the cross says the mail failed, the words say
		   what that means for the person reading the screen. */
		printf(
			'<span class="vesla-flag vesla-flag--bad" title="%s"><span class="dashicons dashicons-dismiss"></span> %s</span>',
			esc_attr__( 'The enquiry was saved here but the email did not go out. Contact this customer directly.', 'vesla-landing' ),
			esc_html__( 'Not sent', 'vesla-landing' )
		);
	}

	public static function sortable( $columns ) {
		$columns['vesla_mailed'] = 'vesla_mailed';
		$columns['vesla_car']    = 'vesla_car';
		return $columns;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   FILTERING
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function filter_dropdown( $post_type ) {
		if ( self::TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['vesla_mailed'] ) ? sanitize_text_field( wp_unslash( $_GET['vesla_mailed'] ) ) : '';
		$failed  = self::count_failed();
		?>
		<label class="screen-reader-text" for="vesla_mailed"><?php esc_html_e( 'Filter by delivery', 'vesla-landing' ); ?></label>
		<select name="vesla_mailed" id="vesla_mailed">
			<option value=""><?php esc_html_e( 'All enquiries', 'vesla-landing' ); ?></option>
			<option value="no" <?php selected( $current, 'no' ); ?>>
				<?php
				printf(
					/* translators: %d: how many enquiries failed to send. */
					esc_html__( 'Email failed (%d)', 'vesla-landing' ),
					(int) $failed
				);
				?>
			</option>
			<option value="yes" <?php selected( $current, 'yes' ); ?>><?php esc_html_e( 'Emailed successfully', 'vesla-landing' ); ?></option>
		</select>
		<?php
		$kind = isset( $_GET['vesla_type'] ) ? sanitize_key( wp_unslash( $_GET['vesla_type'] ) ) : '';
		?>
		<label class="screen-reader-text" for="vesla_type"><?php esc_html_e( 'Filter by kind', 'vesla-landing' ); ?></label>
		<select name="vesla_type" id="vesla_type">
			<option value=""><?php esc_html_e( 'Every kind', 'vesla-landing' ); ?></option>
			<?php foreach ( Vesla_Enquiry::types() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $kind, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public static function apply_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		/* Both filters are on the same screen and can be set at once, so the
		   clauses are collected and set once at the end. Setting 'meta_query'
		   twice would silently drop the first. */
		$meta = array();

		if ( ! empty( $_GET['vesla_mailed'] ) ) {
			$want = sanitize_text_field( wp_unslash( $_GET['vesla_mailed'] ) );
			if ( in_array( $want, array( 'yes', 'no' ), true ) ) {
				/* 'no' has to include rows with no flag at all — an enquiry
				   stored by an older version has no _vesla_emailed key, and
				   leaving those out of the failure list is exactly the kind of
				   quiet omission this screen exists to prevent. */
				$meta[] = 'no' === $want
					? array(
						'relation' => 'OR',
						array( 'key' => '_vesla_emailed', 'value' => 'no' ),
						array( 'key' => '_vesla_emailed', 'compare' => 'NOT EXISTS' ),
					)
					: array( 'key' => '_vesla_emailed', 'value' => 'yes' );
			}
		}

		if ( ! empty( $_GET['vesla_type'] ) ) {
			$kind = sanitize_key( wp_unslash( $_GET['vesla_type'] ) );
			if ( isset( Vesla_Enquiry::types()[ $kind ] ) ) {
				/* Same reasoning as the delivery filter above: an enquiry from
				   before the field existed has no _vesla_type, and a car named on
				   it is the only evidence of what it was. Asking for car enquiries
				   therefore has to include those. */
				$meta[] = 'car' === $kind
					? array(
						'relation' => 'OR',
						array( 'key' => '_vesla_type', 'value' => 'car' ),
						array(
							'relation' => 'AND',
							array( 'key' => '_vesla_type', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_vesla_car', 'value' => '', 'compare' => '!=' ),
						),
					)
					: array( 'key' => '_vesla_type', 'value' => $kind );
			}
		}

		if ( $meta ) {
			if ( count( $meta ) > 1 ) {
				$meta['relation'] = 'AND';
			}
			$query->set( 'meta_query', $meta );
		}

		/* The same range the panel above is reporting on, so the rows underneath
		   are the rows being counted. Without this the screen would say "3 in
		   this period" above a table listing every one of them. */
		$from = self::clean_date( 'from' );
		$to   = self::clean_date( 'to' );
		if ( $from || $to ) {
			$date = array( 'inclusive' => true );
			if ( $from ) {
				$date['after'] = $from . ' 00:00:00';
			}
			if ( $to ) {
				$date['before'] = $to . ' 23:59:59';
			}
			$query->set( 'date_query', array( $date ) );
		}

		$orderby = $query->get( 'orderby' );
		if ( 'vesla_mailed' === $orderby ) {
			$query->set( 'meta_key', '_vesla_emailed' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'vesla_car' === $orderby ) {
			$query->set( 'meta_key', '_vesla_car' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	private static function count_failed() {
		$q = new WP_Query(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_vesla_emailed', 'value' => 'no' ),
					array( 'key' => '_vesla_emailed', 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		return (int) $q->found_posts;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   THE ENQUIRY ITSELF
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The post type supports only title and editor, so opening an enquiry used
	 * to show the message and nothing else — the phone number, the email
	 * address and the car are all post meta, and post meta is invisible without
	 * something to draw it. Which made the single most useful screen in the
	 * plugin the least useful one.
	 */
	public static function metabox() {
		add_meta_box(
			'vesla_enquiry_details',
			__( 'Enquiry details', 'vesla-landing' ),
			array( __CLASS__, 'metabox_html' ),
			self::TYPE,
			'side',
			'high'
		);
	}

	public static function metabox_html( $post ) {
		$phone  = get_post_meta( $post->ID, '_vesla_phone', true );
		$email  = get_post_meta( $post->ID, '_vesla_email', true );
		$car    = get_post_meta( $post->ID, '_vesla_car', true );
		$mailed = get_post_meta( $post->ID, '_vesla_emailed', true );
		$type    = (string) get_post_meta( $post->ID, '_vesla_type', true );
		$details = get_post_meta( $post->ID, '_vesla_details', true );
		$labels  = Vesla_Enquiry::types();

		/* Read-only on purpose. These are a record of what a customer actually
		   typed; making them editable would let the record quietly drift away
		   from what was received, and there is no reason to change it. */
		?>
		<p class="description" style="margin-top:0">
			<?php esc_html_e( 'What the customer submitted. Kept as received and not editable.', 'vesla-landing' ); ?>
		</p>
		<table class="vesla-meta">
			<tr>
				<th><?php esc_html_e( 'Kind', 'vesla-landing' ); ?></th>
				<td>
					<?php if ( isset( $labels[ $type ] ) ) : ?>
						<span class="vesla-kind vesla-kind-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $labels[ $type ] ); ?></span>
					<?php else : ?>
						<span class="vesla-dash">—</span>
						<br><span class="description"><?php esc_html_e( 'Received before enquiries recorded a kind.', 'vesla-landing' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( is_array( $details ) && $details ) : ?>
				<tr>
					<th><?php esc_html_e( 'On screen', 'vesla-landing' ); ?></th>
					<td>
						<?php /* The figures the customer was looking at when they sent it --
						         the valuation they were quoted, or the deposit and term behind
						         the monthly figure. Ringing back without these means asking
						         them to fill it in again. */ ?>
						<ul class="vesla-details">
							<?php foreach ( $details as $label => $value ) : ?>
								<li><b><?php echo esc_html( $label ); ?>:</b> <?php echo esc_html( $value ); ?></li>
							<?php endforeach; ?>
						</ul>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Received', 'vesla-landing' ); ?></th>
				<td>
					<?php
					echo esc_html(
						get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post )
					);
					?>
					<br><span class="description">
						<?php
						printf(
							/* translators: %s: how long ago the enquiry arrived, e.g. "2 hours". */
							esc_html__( '%s ago', 'vesla-landing' ),
							esc_html( human_time_diff( get_post_timestamp( $post ), time() ) )
						);
						?>
					</span>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Phone', 'vesla-landing' ); ?></th>
				<td>
					<?php if ( $phone ) : ?>
						<a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
					<?php else : ?>
						<span class="vesla-dash">—</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Email', 'vesla-landing' ); ?></th>
				<td>
					<?php if ( $email ) : ?>
						<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
					<?php else : ?>
						<span class="vesla-dash">—</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Car', 'vesla-landing' ); ?></th>
				<td><?php echo $car ? esc_html( $car ) : '<span class="vesla-dash">—</span>'; ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Email to you', 'vesla-landing' ); ?></th>
				<td><?php self::mailed_badge( $mailed ); ?></td>
			</tr>
		</table>

		<?php if ( 'yes' !== $mailed ) : ?>
			<div class="notice notice-error inline" style="margin:12px 0 0">
				<p style="margin:.5em 0">
					<strong><?php esc_html_e( 'This one never reached your inbox.', 'vesla-landing' ); ?></strong><br>
					<?php esc_html_e( 'It is safe here, but nobody was told about it by email. Contact the customer directly, then set up SMTP so the next one arrives.', 'vesla-landing' ); ?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   THE WARNING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Shown when the most recent enquiries have all failed to send.
	 *
	 * Consecutive failures, not a total: one failure years ago is history,
	 * three in a row is a mail server that is not working now. This is the part
	 * that means an administrator does not have to already suspect a problem in
	 * order to discover one.
	 */
	public static function mail_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'toplevel_page_vesla-landing', 'edit-' . self::TYPE ), true ) ) {
			return;
		}

		$recent = get_posts(
			array(
				'post_type'        => self::TYPE,
				'post_status'      => array( 'private', 'publish' ),
				'numberposts'      => 3,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		if ( count( $recent ) < 3 ) {
			return;
		}
		foreach ( $recent as $id ) {
			if ( 'no' !== get_post_meta( $id, '_vesla_emailed', true ) ) {
				return; // one of the last three got through; the server works
			}
		}

		$link = add_query_arg(
			array( 'post_type' => self::TYPE, 'vesla_mailed' => 'no' ),
			admin_url( 'edit.php' )
		);
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Website enquiries are not reaching your inbox.', 'vesla-landing' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'The last three enquiries were saved on this site but could not be emailed to you. Nothing has been lost — but nobody is being told when a customer gets in touch.', 'vesla-landing' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'This is almost always the hosting rather than the website: shared servers frequently cannot send mail on their own. Installing an SMTP plugin and pointing it at your own mailbox is the usual fix.', 'vesla-landing' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $link ); ?>">
					<?php esc_html_e( 'Read the enquiries that did not send', 'vesla-landing' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
	/* =======================================================================
	   THE REPORT SCREEN
	   ======================================================================= */
	/**
	 * A page of its own, under Landing Page.
	 *
	 * The export used to be one small button in the toolbar above the enquiry
	 * list, which is a fine place for a button and a poor place for a report:
	 * nobody looking for "how are enquiries going" thinks to open the list and
	 * read the toolbar. This gives the question a page, and the download sits
	 * where somebody would go looking for it.
	 *
	 * Priority 20 so it lands after the Enquiries row that the post type adds.
	 */

	/**
	 * Enquiries in a date range, counted and broken down.
	 *
	 * @param string $from Y-m-d, or '' for no lower bound.
	 * @param string $to   Y-m-d, or '' for no upper bound.
	 * @return WP_Post[]
	 */
	private static function in_range( $from, $to ) {
		$args = array(
			'post_type'        => self::TYPE,
			'post_status'      => array( 'private', 'publish' ),
			'numberposts'      => -1,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		);

		/* Dates are inclusive at both ends, which is what a person means by
		   "1st to the 7th" — a plain <= on the 7th would stop at midnight and
		   silently drop that whole day. */
		$date = array();
		if ( $from ) {
			$date['after'] = $from . ' 00:00:00';
		}
		if ( $to ) {
			$date['before'] = $to . ' 23:59:59';
		}
		if ( $date ) {
			$date['inclusive']  = true;
			$args['date_query'] = array( $date );
		}

		return get_posts( $args );
	}

	/** A posted date, or '' if it is not one. Never trusted into a query raw. */
	private static function clean_date( $key ) {
		if ( empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a read-only filter, nothing is changed.
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$d   = DateTime::createFromFormat( 'Y-m-d', $raw );
		return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
	}

	/**
	 * The figures, printed above the enquiry table.
	 *
	 * all_admin_notices puts this after the heading and before the list, which
	 * is where a summary belongs: the shape of things first, then the rows. One
	 * screen means the date range drives the table, the counts and the CSV
	 * together, so the number at the top cannot disagree with the rows below.
	 */
	/**
	 * Send the retired report URL to the screen that replaced it.
	 *
	 * On admin_menu, not admin_init: wp-admin/menu.php decides an unknown page
	 * does not exist and dies at line 375, while admin_init does not run until
	 * later in admin.php. admin_menu fires at line 168, before that check.
	 *
	 * Registering the page and then removing its row does NOT work here --
	 * remove_submenu_page() takes it out of $submenu, which is exactly what
	 * user_can_access_admin_page() looks at, so the URL still dies.
	 */
	public static function redirect_old_report() {
		if ( ! isset( $_GET['page'] ) || 'vesla-enquiry-report' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- reading which screen was requested, changing nothing.
			return;
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::TYPE ) );
		exit;
	}
	public static function report_panel() {
		if ( ! self::on_list_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$from = self::clean_date( 'from' );
		$to   = self::clean_date( 'to' );
		$rows = self::in_range( $from, $to );
		$now     = current_time( 'timestamp' );
		$week    = 0;
		$month   = 0;
		$emailed = 0;
		$by_car  = array();
		foreach ( $rows as $post ) {
			$when = get_post_timestamp( $post );
			if ( $when > $now - WEEK_IN_SECONDS ) {
				$week++;
			}
			if ( $when > $now - MONTH_IN_SECONDS ) {
				$month++;
			}
			if ( 'yes' === get_post_meta( $post->ID, '_vesla_emailed', true ) ) {
				$emailed++;
			}
			$car = trim( (string) get_post_meta( $post->ID, '_vesla_car', true ) );
			if ( '' === $car ) {
				$car = __( 'No car named', 'vesla-landing' );
			}
			$by_car[ $car ] = isset( $by_car[ $car ] ) ? $by_car[ $car ] + 1 : 1;
		}
		arsort( $by_car );

		$csv = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'vesla_export',
					'from'   => $from,
					'to'     => $to,
				),
				admin_url( 'admin-post.php' )
			),
			'vesla_export',
			'vesla_export_nonce'
		);
		$total = count( $rows );
		?>
		<div class="vesla-report">
			<form method="get" class="vesla-report-filter">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( self::TYPE ); ?>">
				<label for="vr-from"><?php esc_html_e( 'From', 'vesla-landing' ); ?></label>
				<input type="date" id="vr-from" name="from" value="<?php echo esc_attr( $from ); ?>">
				<label for="vr-to"><?php esc_html_e( 'To', 'vesla-landing' ); ?></label>
				<input type="date" id="vr-to" name="to" value="<?php echo esc_attr( $to ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'vesla-landing' ); ?></button>
				<?php if ( $from || $to ) : ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::TYPE ) ); ?>">
						<?php esc_html_e( 'Clear', 'vesla-landing' ); ?>
					</a>
				<?php endif; ?>
				<a class="button button-primary" href="<?php echo esc_url( $csv ); ?>">
					<?php esc_html_e( 'Download CSV', 'vesla-landing' ); ?>
				</a>
			</form>

			<div class="vesla-report-cards">
				<div class="vesla-report-card">
					<b><?php echo esc_html( number_format_i18n( $total ) ); ?></b>
					<span><?php echo $from || $to ? esc_html__( 'In this period', 'vesla-landing' ) : esc_html__( 'Enquiries in total', 'vesla-landing' ); ?></span>
				</div>
				<div class="vesla-report-card">
					<b><?php echo esc_html( number_format_i18n( $week ) ); ?></b>
					<span><?php esc_html_e( 'Last 7 days', 'vesla-landing' ); ?></span>
				</div>
				<div class="vesla-report-card">
					<b><?php echo esc_html( number_format_i18n( $month ) ); ?></b>
					<span><?php esc_html_e( 'Last 30 days', 'vesla-landing' ); ?></span>
				</div>
				<div class="vesla-report-card">
					<b><?php echo esc_html( number_format_i18n( $emailed ) ); ?></b>
					<span><?php esc_html_e( 'Emailed out successfully', 'vesla-landing' ); ?></span>
				</div>
			</div>

			<?php if ( $total !== $emailed ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: a number of enquiries. */
							esc_html__( '%s of these were stored but could not be emailed. They are safe here — this is why every enquiry is written down as well as sent.', 'vesla-landing' ),
							esc_html( number_format_i18n( $total - $emailed ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $by_car ) : ?>
				<details class="vesla-report-cars"<?php echo count( $by_car ) < 8 ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Which cars are being asked about', 'vesla-landing' ); ?></summary>
					<table class="widefat striped vesla-report-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Car', 'vesla-landing' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Enquiries', 'vesla-landing' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Share', 'vesla-landing' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $by_car as $car => $n ) : ?>
							<?php $pct = $total ? round( 100 * $n / $total ) : 0; ?>
							<tr>
								<td><?php echo esc_html( $car ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $n ) ); ?></td>
								<td>
									<span class="vesla-bar" style="width:<?php echo esc_attr( max( 2, $pct ) ); ?>%"></span>
									<i><?php echo esc_html( $pct . '%' ); ?></i>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   EXPORT
	   ═══════════════════════════════════════════════════════════════════════ */

	/** The kind as a reader sees it, blank for rows stored before it existed. */
	private static function kind_label( $post_id ) {
		$type   = (string) get_post_meta( $post_id, '_vesla_type', true );
		$labels = Vesla_Enquiry::types();
		return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
	}

	/** The figures flattened onto one cell, so a spreadsheet keeps them together. */
	private static function details_line( $post_id ) {
		$details = get_post_meta( $post_id, '_vesla_details', true );
		if ( ! is_array( $details ) || ! $details ) {
			return '';
		}
		$bits = array();
		foreach ( $details as $label => $value ) {
			$bits[] = $label . ': ' . $value;
		}
		return implode( '; ', $bits );
	}

	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export enquiries.', 'vesla-landing' ) );
		}
		check_admin_referer( 'vesla_export', 'vesla_export_nonce' );

		/* The same range the report screen is showing, so the file matches the
		   figures the admin was just looking at. Both go through clean_date(),
		   so a hand-edited URL cannot put anything into the query. */
		$from = self::clean_date( 'from' );
		$to   = self::clean_date( 'to' );
		$rows = self::in_range( $from, $to );

		$name = 'enquiries';
		if ( $from || $to ) {
			$name .= '-' . ( $from ? $from : 'start' ) . '-to-' . ( $to ? $to : gmdate( 'Y-m-d' ) );
		} else {
			$name .= '-' . gmdate( 'Y-m-d' );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name . '.csv' );

		$out = fopen( 'php://output', 'w' );

		/* A byte-order mark, because the overwhelmingly likely destination is
		   Excel — which without it reads UTF-8 as the local codepage and turns
		   every accented name into mojibake. */
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array(
				__( 'Received', 'vesla-landing' ),
				__( 'Name', 'vesla-landing' ),
				__( 'Phone', 'vesla-landing' ),
				__( 'Email', 'vesla-landing' ),
				__( 'Kind', 'vesla-landing' ),
				__( 'Car', 'vesla-landing' ),
				__( 'On screen', 'vesla-landing' ),
				__( 'Message', 'vesla-landing' ),
				__( 'Emailed', 'vesla-landing' ),
			)
		);

		foreach ( $rows as $post ) {
			fputcsv(
				$out,
				array(
					get_the_date( 'Y-m-d H:i', $post ),
					trim( explode( '—', $post->post_title )[0] ),
					self::csv_safe( get_post_meta( $post->ID, '_vesla_phone', true ) ),
					self::csv_safe( get_post_meta( $post->ID, '_vesla_email', true ) ),
					self::csv_safe( self::kind_label( $post->ID ) ),
					self::csv_safe( get_post_meta( $post->ID, '_vesla_car', true ) ),
					self::csv_safe( self::details_line( $post->ID ) ),
					self::csv_safe( $post->post_content ),
					'yes' === get_post_meta( $post->ID, '_vesla_emailed', true ) ? 'yes' : 'no',
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Defuses CSV formula injection.
	 *
	 * A value beginning = + - or @ is treated as a formula by Excel and by
	 * Google Sheets. A "name" of `=HYPERLINK("http://…","Click")` becomes a
	 * live link in the dealer's spreadsheet, and worse is possible. Prefixing a
	 * single quote makes the cell text, which is what it always was.
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   A LITTLE STYLING
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function styles() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-' . self::TYPE, self::TYPE ), true ) ) {
			return;
		}
		?>
		<style>
			.vesla-flag{ display:inline-flex; align-items:center; gap:4px; font-weight:600; white-space:nowrap; }
			.vesla-flag .dashicons{ font-size:17px; width:17px; height:17px; }
			.vesla-flag--ok{ color:#008a20; }
			.vesla-flag--bad{ color:#b32d2e; }
			.vesla-dash{ color:#a7aaad; }
			.column-vesla_mailed{ width:110px; }
			.column-vesla_phone,.column-vesla_email{ width:180px; }
			.vesla-meta{ width:100%; border-collapse:collapse; }
			.vesla-meta th{ text-align:left; vertical-align:top; padding:7px 10px 7px 0; width:96px; color:#50575e; font-weight:600; }
			.vesla-meta td{ padding:7px 0; vertical-align:top; word-break:break-word; }
		</style>
		<?php
	}
}

/* ========================================================================== */
/*  8 · THE API
 *
 *  What the static front end reads and posts to. Same origin, so no CORS.
 */
/* ========================================================================== */

class Vesla_Rest {
	const NS = 'vesla/v1';

	/**
	 * Settings that must never leave the server.
	 *
	 * `form_to` is where enquiries are delivered — often a private inbox that
	 * appears nowhere on the public site. It was in the payload once, which
	 * published it to anyone who read the page source. Listed here by section
	 * so adding a private field later is one line, not an audit.
	 */
	const PRIVATE_FIELDS = array(
		'contact' => array( 'form_to' ),
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		/* Any save of the settings invalidates whatever a front end cached. */
		add_action( 'vesla_content_saved', array( __CLASS__, 'bump_version' ), 10, 0 );
	}

	public static function bump_version() {
		update_option( 'vesla_landing_version', time(), false );
	}

	public static function version() {
		return (int) get_option( 'vesla_landing_version', 0 );
	}

	public static function routes() {
		$public = '__return_true';

		register_rest_route( self::NS, '/landing', array(
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => array( __CLASS__, 'landing' ),
		) );

		/* One car, in full. Read-only and public, like /landing -- it returns
		   exactly what the page would have shown anyway, only later. */
		register_rest_route(
			self::NS,
			'/car/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'car' ),
				'permission_callback' => $public,
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route( self::NS, '/enquiry', array(
			'methods'             => 'POST',
			'permission_callback' => $public,
			'callback'            => array( __CLASS__, 'enquiry' ),
		) );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   READ
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function landing( $req ) {
		$sections = array();

		foreach ( Vesla_Settings::all() as $name => $fields ) {
			$sections[ $name ] = self::public_fields( $name, $fields );
			/* A section that is switched off is returned with enabled:false
			   rather than omitted. A front end has to tell "the showroom
			   turned this off" from "this plugin is older than my code and
			   has no such section", and an absent key cannot say both. */
			$sections[ $name ]['enabled'] = Vesla_Settings::enabled( $name );
		}

		$payload = array(
			'ok'      => true,
			'version' => self::version(),
			'site'    => array(
				'name'   => get_bloginfo( 'name' ),
				'url'    => home_url( '/' ),
				'locale' => str_replace( '_', '-', get_locale() ),
			),
			/* The same array printed into the rendered page: stock, labels,
			   messages, limits, estimator, scroll, whatsapp, currency. */
			'data'    => self::with_full_images( Vesla_Render::js_data() ),
			'content' => $sections,

			/* Which fields carry markup rather than plain text, as dotted paths.
			   A separate front end cannot tell the difference by looking: a value
			   is just a string, and guessing by sniffing for '<' is how an escaped
			   ampersand in ordinary copy ends up rendered as a tag. Read from the
			   schema, so a field promoted to rich later appears here on its own.

			   These values have already been through the allowlist on save, so
			   they hold only links, bold, italic and lists -- but a front end
			   should still be the one deciding to trust its own origin. */
			'rich'    => Vesla_Schema::rich_paths(),

			/* Image fields resolved to something a front end can use.

			   Stored, these are attachment IDs -- 'share_image' is the string
			   '12'. That is enough for WordPress, which can look 12 up, and
			   useless to anything else: a separate front end has no way to turn
			   it into a URL, so the picture chosen for sharing simply never
			   appeared. Width and height come too, so a page can reserve the
			   space before the file lands, and alt because it is the
			   administrator's words and there is no other way to read them. */
			'images'  => self::images(),
		);

		return self::cacheable( $req, $payload );
	}

	/**
	 * Every section-level image, resolved from an attachment ID.
	 *
	 * Keyed by the same dotted paths the schema reports, so a field added as an
	 * image later appears here on its own with nothing to change.
	 */
	private static function images() {
		$out = array();
		foreach ( Vesla_Schema::image_paths() as $path ) {
			list( $section, $key ) = explode( '.', $path, 2 );

			$id = (int) Vesla_Settings::get( $section, $key, 0 );
			if ( ! $id ) {
				/* Reported as null rather than dropped. A front end has to tell
				   "no picture chosen" from "this build of the plugin has no
				   such field", and a missing key cannot say both. */
				$out[ $path ] = null;
				continue;
			}

			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( ! $src ) {
				$out[ $path ] = null; // the attachment was deleted from under us
				continue;
			}

			$out[ $path ] = array(
				'id'     => $id,
				'url'    => $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
				'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}
		return $out;
	}

	/**
	 * One car's full detail, for its own page.
	 *
	 * Answers 404 for an id that is not in stock rather than an empty object:
	 * a car that has been sold and removed is a different thing from a car
	 * with no details filled in, and the page says something different in
	 * each case.
	 */
	public static function car( $req ) {
		$detail = Vesla_Render::car_detail( (int) $req['id'] );

		if ( ! $detail ) {
			return new WP_Error(
				'vesla_no_car',
				__( 'That car is no longer listed.', 'vesla-landing' ),
				array( 'status' => 404 )
			);
		}

		return self::cacheable(
			$req,
			array(
				'ok'      => true,
				'version' => self::version(),
				'car'     => $detail,
			)
		);
	}

	/**
	 * Replaces each car's plain image URL with the full description.
	 *
	 * js_data() is shaped for the page WordPress renders itself, where a URL is
	 * enough because the markup around it already carries the dimensions. A
	 * separate front end has nothing to go on: without the intrinsic width and
	 * height it cannot reserve the space, and the grid jumps as every
	 * photograph lands. `alt` matters for the same reason — it is a field an
	 * administrator fills in and the front end has no other way to read it.
	 *
	 * `img` is left in place beside the new key. Something out there may be
	 * reading it, and this shape is a contract with a separately deployed
	 * front end: add keys, never repurpose them.
	 */
	private static function with_full_images( $data ) {
		$full = self::cars();
		$by   = array();
		foreach ( $full as $c ) {
			$by[ $c['make'] . '|' . $c['model'] . '|' . $c['year'] ] = $c['image'];
		}
		foreach ( $data['stock'] as $i => $car ) {
			$key = $car['make'] . '|' . $car['model'] . '|' . $car['year'];
			$data['stock'][ $i ]['image'] = isset( $by[ $key ] ) ? $by[ $key ] : null;
		}
		return $data;
	}

	/**
	 * A section's fields with the private ones removed and images resolved.
	 */
	private static function public_fields( $section, $fields ) {
		$hide = isset( self::PRIVATE_FIELDS[ $section ] ) ? self::PRIVATE_FIELDS[ $section ] : array();
		$out  = array();

		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $hide, true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$rows = array();
				foreach ( $value as $row ) {
					$rows[] = is_array( $row ) ? self::public_fields( $section, $row ) : $row;
				}
				$out[ $key ] = $rows;
				continue;
			}

			$out[ $key ] = $value;

			/* An attachment ID is right for the database and useless to a front
			   end, which needs a URL and the intrinsic width and height —
			   without those it cannot reserve space and the page shifts as each
			   picture lands. Added alongside rather than replacing the ID, so
			   an editor round-trip still has the value to save back. */
			if ( self::is_attachment_field( $key, $value ) ) {
				$out[ $key . '_image' ] = self::image( (int) $value );
			}
		}

		return $out;
	}

	private static function is_attachment_field( $key, $value ) {
		return is_numeric( $value ) && (int) $value > 0
			&& in_array( $key, array( 'logo', 'photo', 'portrait', 'share_image' ), true );
	}

	/**
	 * An image described completely enough to render without a layout shift.
	 */
	public static function image( $id, $fallback_file = '' ) {
		$id = (int) $id;

		if ( $id && wp_attachment_is_image( $id ) ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src ) {
				return array(
					'id'     => $id,
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'srcset' => (string) wp_get_attachment_image_srcset( $id, 'full' ),
					'sizes'  => (string) wp_get_attachment_image_sizes( $id, 'full' ),
				);
			}
		}

		if ( $fallback_file ) {
			/* A file shipped with the plugin has no attachment record, so its
			   size must be measured. Cached: getimagesize() opens the file, and
			   this runs for every car on every request. */
			$key  = 'vesla_imgsize_' . md5( $fallback_file );
			$size = get_transient( $key );
			if ( false === $size ) {
				$size = @getimagesize( VESLA_DIR . ltrim( $fallback_file, '/' ) );
				set_transient( $key, $size ? $size : array( 0, 0 ), DAY_IN_SECONDS );
			}
			return array(
				'id'     => 0,
				'url'    => VESLA_URL . ltrim( $fallback_file, '/' ),
				'width'  => isset( $size[0] ) ? (int) $size[0] : 0,
				'height' => isset( $size[1] ) ? (int) $size[1] : 0,
				'alt'    => '',
				/* One file, one width. A bundled sample has no smaller copies to
				   offer, so the srcset names the only size that exists rather
				   than promising widths that do not. */
				'srcset' => isset( $size[0] ) && $size[0]
					? VESLA_URL . ltrim( $fallback_file, '/' ) . ' ' . (int) $size[0] . 'w'
					: '',
				'sizes'  => '',
			);
		}

		return null;
	}

	/**
	 * The cars, normalised.
	 *
	 * One definition of what a car is, shared by the API, the server-rendered
	 * cards and the structured data. A row with no make and no model was
	 * started and abandoned in the editor and is dropped rather than published
	 * as a nameless listing.
	 */
	/**
	 * @param bool $sold_only Sold cars instead of the ones on the floor. The
	 *                        sold page passes true; everything else takes the
	 *                        default and never sees a sold car.
	 */
	public static function cars( $sold_only = false ) {
		$stock = Vesla_Settings::get( 'stock' );
		$out   = array();

		foreach ( (array) $stock['cars'] as $car ) {
			$make  = trim( (string) $car['make'] );
			$model = trim( (string) $car['model'] );
			if ( '' === $make && '' === $model ) {
				continue;
			}

			/* Sold cars leave the floor. Filtered here rather than in the grid,
			   because the grid is not the only thing reading this -- the payload
			   app.js re-renders from, the ItemList in the structured data and the
			   price range all come through here, and a sold car showing in any one
			   of them is the same wrong answer in a different place. */
			$is_sold = 'sold' === Vesla_Render::car_status( $car );
			if ( $is_sold !== (bool) $sold_only ) {
				continue;
			}

			$image = self::image(
				isset( $car['photo'] ) ? $car['photo'] : 0,
				isset( $car['photo_file'] ) ? $car['photo_file'] : ''
			);

			/* Alt text, in the order somebody actually meant it:
			 *
			 *   1. what the admin wrote about THIS photograph
			 *   2. what the media library holds for the attachment
			 *   3. nothing
			 *
			 * The car's name is deliberately NOT a fallback any more. It sits
			 * in the heading immediately beside the picture, and a screen
			 * reader announcing "Audi TT RS, image, Audi TT RS" has been told
			 * the same thing twice and nothing about the photograph. An empty
			 * alt on an image whose caption is right next to it is the correct
			 * answer, not a gap.
			 */
			if ( $image && '' === $image['alt'] && ! empty( $car['photo_alt'] ) ) {
				$image['alt'] = trim( (string) $car['photo_alt'] );
			}

			$out[] = array(
				/* The row's own id. Without it the card's link had no id on the end
				   of its slug, and every card on the server-rendered page pointed at
				   a URL that matched no car. */
				'id'    => isset( $car['id'] ) ? (int) $car['id'] : 0,
				'make'  => $make,
				'model' => $model,
				'year'  => (int) $car['year'],
				'km'    => (int) $car['km'],
				'price' => (int) $car['price'],
				'body'  => (string) $car['body'],
				'trans' => (string) $car['trans'],
				'fuel'  => (string) $car['fuel'],
				'seats' => (int) $car['seats'],
				'image' => $image,
				/* app.js rebuilds these cards, and cardFor() draws the same badges
				   card_html() does -- so it needs the same two facts. */
				'status'  => Vesla_Render::car_status( $car ),
				'arrived' => Vesla_Render::car_arrived( $car ),
			);
		}

		return $out;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   WRITE
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The enquiry form.
	 *
	 * No nonce, and it cannot have one: a static HTML file has no PHP to print
	 * it with. Three things stand in for it —
	 *
	 *   · the Origin/Referer must be this site. Cheap, and it stops the whole
	 *     class of another website posting through a visitor's browser. It is
	 *     not proof of anything on its own, which is why it is not alone.
	 *   · the honeypot, unchanged.
	 *   · the IP rate limit, unchanged, checked before anything is created.
	 *
	 * All the real checking is inside Vesla_Enquiry::submit(), the same
	 * function the rendered page's admin-ajax door calls. Storage happens
	 * before mail, From is on our own domain and the visitor goes in Reply-To
	 * — none of which changes here, because none of it lives here.
	 */
	public static function enquiry( $req ) {
		if ( ! self::same_site( $req ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'bad_origin',
					'message' => Vesla_Settings::get( 'messages', 'send_fail', '' ),
					'fields'  => array(),
				),
				403
			);
		}

		$p = $req->get_json_params();
		if ( ! is_array( $p ) ) {
			$p = $req->get_params();
		}

		$result = Vesla_Enquiry::submit(
			array(
				'name'    => isset( $p['name'] ) ? $p['name'] : '',
				'phone'   => isset( $p['phone'] ) ? $p['phone'] : '',
				'email'   => isset( $p['email'] ) ? $p['email'] : '',
				'car'     => isset( $p['car'] ) ? $p['car'] : '',
				'message' => isset( $p['message'] ) ? $p['message'] : '',
				'website' => isset( $p['website'] ) ? $p['website'] : '',
				'type'    => isset( $p['type'] ) ? $p['type'] : '',
				'details' => isset( $p['details'] ) ? $p['details'] : '',
			)
		);

		if ( ! empty( $result['ok'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'message' => $result['message'] ), 200 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $result['code'],
				'message' => $result['message'],
				/* keyed by the same field names the built-in form uses, so one
				   front end can drive either door */
				'fields'  => isset( $result['fields'] ) ? $result['fields'] : array(),
			),
			'rate_limited' === $result['code'] ? 429 : 422
		);
	}

	/**
	 * Did this request come from our own pages?
	 *
	 * Origin is sent on cross-origin and same-origin POSTs by every browser
	 * that matters. Referer is the fallback for the handful that omit Origin on
	 * same-origin requests. If NEITHER is present the request did not come from
	 * a browser form at all — curl, say — and there is nothing to compare, so
	 * it is allowed through to the honeypot, the rate limit and the validation
	 * rather than being refused on an absence.
	 */
	private static function same_site( $req ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		$origin = $req->get_header( 'origin' );
		if ( $origin ) {
			return wp_parse_url( $origin, PHP_URL_HOST ) === $home;
		}

		$referer = $req->get_header( 'referer' );
		if ( $referer ) {
			return wp_parse_url( $referer, PHP_URL_HOST ) === $home;
		}

		return true;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   CACHING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Content is edited rarely and read constantly, which is the exact shape a
	 * conditional request is for: a front end that re-fetches on every page
	 * build pays for a 304 and no body when nothing has changed.
	 *
	 * The ETag carries the settings version, so saving in the admin changes it
	 * immediately — see bump_version(), hooked to the option update.
	 */
	private static function cacheable( $req, array $payload ) {
		$etag = '"v' . self::version() . '-' . md5( wp_json_encode( $payload ) ) . '"';

		if ( trim( (string) $req->get_header( 'if_none_match' ) ) === $etag ) {
			$res = new WP_REST_Response( null, 304 );
			$res->header( 'ETag', $etag );
			$res->header( 'Cache-Control', 'public, max-age=60' );
			return $res;
		}

		$res = new WP_REST_Response( $payload, 200 );
		$res->header( 'ETag', $etag );
		$res->header( 'Cache-Control', 'public, max-age=60' );
		return $res;
	}
}

/* ========================================================================== */
/*  9 · PUBLISHING THE STATIC PAGE
 *
 *  Writes index.html with the content already in the markup. This is what keeps SEO working.
 */
/* ========================================================================== */

class Vesla_Publisher {
	/** The scheduled job that writes the site. */
	const EVENT = 'vesla_publish';

	/**
	 * How close together two automatic publishes may run, in seconds.
	 *
	 * Long enough that saving a car, then its price, then its photograph
	 * costs one publish rather than three; short enough that nobody sits
	 * looking at a stale page wondering whether it worked. The manual
	 * Republish button ignores this entirely -- somebody who has just asked
	 * for it is waiting for it.
	 */
	const DEBOUNCE = 30;

	/**
	 * How late an owed publish may be before the screen says so, in seconds.
	 *
	 * Long enough not to nag about the ordinary gap between saving and the
	 * next request coming along, short enough that nobody spends an
	 * afternoon believing a car is on the site when it is not.
	 */
	const OVERDUE = 300;

	/** Whether this request has already arranged to publish when it ends. */
	private static $after_response = false;

	public static function init() {
		/* Every save republishes. An editor who changes the phone number and
		   sees the live page unchanged has, reasonably, concluded the plugin
		   is broken — so publishing is not a thing to remember to do. */
		add_action( 'vesla_content_saved', array( __CLASS__, 'on_save' ), 20 );

		/* The same writing, reached the other way: WP-Cron runs this when a
		   request ended before it could publish, or when the throttle deferred
		   it. Registered always, so an event left due by an older version is
		   still picked up rather than firing into nothing. */
		add_action( self::EVENT, array( __CLASS__, 'run_queued' ) );

		add_action( 'admin_post_vesla_republish', array( __CLASS__, 'handle_republish' ) );
		add_action( 'admin_post_vesla_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_vesla_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_vesla_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_vesla_content_export', array( __CLASS__, 'handle_content_export' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   WHERE
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The document root to write into.
	 *
	 * The default is the parent of the WordPress directory, which is exactly
	 * right for the intended layout — WordPress in public_html/cms/, the static
	 * page in public_html/ — and harmlessly wrong nowhere, because publishing
	 * is off until somebody turns it on.
	 */
	public static function target_dir() {
		$set = trim( (string) Vesla_Settings::get( 'publish', 'path', '' ) );
		if ( $set ) {
			return untrailingslashit( $set );
		}
		return untrailingslashit( dirname( untrailingslashit( ABSPATH ) ) );
	}

	/**
	 * The address the published page is served from.
	 *
	 * Not the same as home_url(): WordPress sits in a sub-folder and visitors
	 * never see it. Defaults to one level up, which is right for the intended
	 * cms/ layout.
	 */
	public static function site_url() {
		$set = trim( (string) Vesla_Settings::get( 'publish', 'site_url', '' ) );
		if ( $set ) {
			return untrailingslashit( $set );
		}
		$home = untrailingslashit( home_url() );
		$up   = dirname( $home );
		/* dirname() of "https://site.com" gives "https:" — keep the host. */
		return ( $up && 0 === strpos( $up, 'http' ) && strlen( $up ) > 8 ) ? $up : $home;
	}

	/**
	 * The address WordPress itself will answer on once the site is live.
	 *
	 * This is the other half of site_url(), and the two are not interchangeable.
	 * Everything the publisher composes -- the canonical, the stylesheet, the
	 * addresses inside the listing data -- is built from site_url(), so it is
	 * right by construction. But everything WordPress hands back already
	 * finished -- a photograph from the media library, the REST feed, the
	 * enquiry endpoint -- carries the address WordPress is installed at, and
	 * nothing was rewriting those. On the machine the editing happens on that
	 * is a local address no visitor can reach, so the published page went out
	 * with every car photograph pointing at somebody's own computer.
	 *
	 * Defaults to cms/ beneath the public site, which is the layout the rest of
	 * this screen describes. Set it explicitly if WordPress lives elsewhere.
	 */
	public static function cms_url() {
		$set = trim( (string) Vesla_Settings::get( 'publish', 'cms_url', '' ) );
		return untrailingslashit( $set ? $set : self::site_url() . '/cms' );
	}

	/**
	 * Copies the stylesheets, scripts and bundled pictures next to the page.
	 *
	 * So the public site does not reach into wp-content for its own stylesheet.
	 * It did, and that is a real fragility rather than a tidiness point:
	 * deactivating the plugin, renaming its folder or a half-finished update
	 * would have left the live site unstyled — a WordPress problem becoming a
	 * shop-window problem. Copied here, public_html stands on its own and only
	 * needs WordPress when something is edited.
	 *
	 * Pictures an administrator uploads are NOT copied: those live in the media
	 * library and are served from wp-content/uploads, which is content rather
	 * than plumbing and is where WordPress will look for them.
	 */
	private static function copy_assets( $dir ) {
		$src  = VESLA_DIR . 'assets';
		$dest = $dir . '/assets';
		if ( ! is_dir( $src ) ) {
			return true;
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $it as $item ) {
			$to = $dest . DIRECTORY_SEPARATOR . $it->getSubPathName();
			if ( $item->isDir() ) {
				if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
					return false;
				}
				continue;
			}
			if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
				return false;
			}
			/* The index.php files are WordPress directory-listing guards. They
			   have no business in a public web root, where they would be a PHP
			   file sitting in a folder that is otherwise entirely static. */
			/* The editor screen's own stylesheet and script are in assets/ so
			   the plugin has one asset folder rather than two — but they are
			   for wp-admin and have no business on the public site. */
			if ( in_array( $item->getFilename(), array( 'admin.css', 'admin.js' ), true ) ) {
				continue;
			}
			/* Only when the source is newer, so republishing does not rewrite
			   several megabytes of photographs on every save. */
			if ( file_exists( $to ) && filemtime( $to ) >= $item->getMTime() ) {
				continue;
			}
			if ( ! @copy( $item->getPathname(), $to ) ) {
				return false;
			}
		}
		return true;
	}

	public static function target_file() {
		return self::target_dir() . '/index.html';
	}

	public static function enabled() {
		return (bool) Vesla_Settings::get( 'publish', 'enabled', 0 );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   PUBLISH
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Something a visitor can see has changed. Note it; do not write it yet.
	 *
	 * This used to publish inline, which put twenty-six files between the
	 * editor and their own save -- about seven tenths of a second on this
	 * machine, longer on shared hosting, and paid again every time the hook
	 * fired twice in one request. Saving a car is not the moment to rebuild
	 * the site; it is the moment to remember that the site needs rebuilding.
	 *
	 * So the work is marked as owing and picked up once the response has
	 * gone. Nothing is dropped on the way: a publish that cannot run now
	 * leaves the mark in place and a job due to come back for it.
	 */
	public static function on_save() {
		if ( ! self::enabled() ) {
			return;
		}

		update_option( 'vesla_publish_pending', time(), false );

		/* Once per request, however many times this hook fires -- and it does
		   fire more than once, from the settings write and from a car save. */
		if ( ! self::$after_response ) {
			self::$after_response = true;
			add_action( 'shutdown', array( __CLASS__, 'run_after_response' ), 999 );
		}

		/* Due a little way out, deliberately not now.

		   An event that is already due gets picked up inside this very
		   request -- measured at about a second added to the save, which is
		   the whole thing being avoided. Dated forward, nothing here finds
		   work to do and the save leaves at once; the next request that comes
		   along after the window carries it instead.

		   Left standing whatever happens next, because without it an edit
		   could sit owing forever with nothing due to come back for it. */
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE, self::EVENT );
		}
	}

	/**
	 * Publish once the browser has its answer.
	 *
	 * Under PHP-FPM the response can be handed back and PHP kept running
	 * underneath it, so the writing happens here, behind a save that has
	 * already returned.
	 *
	 * Everywhere else -- plain CGI, which is what this was first tested on,
	 * and mod_php -- there is no way to release the response early, and
	 * doing the work here would put it back in front of the editor: the
	 * exact thing this change exists to stop. So it is not done here at
	 * all, and the event is left standing for another request to carry.
	 */
	public static function run_after_response() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			self::run_queued();
			return;
		}
		/* Nothing else happens here, on purpose.

		   The obvious move is to poke wp-cron.php so the writing starts at
		   once. It was written that way and then measured: the spawn cost
		   about a second on the machine this was built on -- longer than the
		   publish it was trying to get out of the way of, and paid by the
		   editor, which is the exact thing being fixed here.

		   WordPress already spawns cron by itself on the next request that
		   finds work due, and after a save there is always a next request:
		   the redirect back to the screen. So the event is left standing and
		   somebody else's request carries it. A moment later, and free. */
	}


	/**
	 * Write the site, if it is owed and not too soon after the last time.
	 *
	 * Reached from both directions -- the end of the request that saved, and
	 * the scheduled job -- so it must be safe to call with nothing to do and
	 * safe to call twice. The pending mark is what makes it so: whichever
	 * arrives first clears it and does the work, and the other finds nothing
	 * owing and leaves.
	 */
	public static function run_queued() {
		if ( ! self::enabled() || ! get_option( 'vesla_publish_pending' ) ) {
			return;   // nothing owing, or publishing is switched off
		}

		$last = (int) get_option( 'vesla_publish_last_run', 0 );
		if ( $last && time() - $last < self::DEBOUNCE ) {
			/* Too soon after the last one. The mark stays, and a job is left due
			   for when the window closes, so this delays a publish rather than
			   dropping one. */
			if ( ! wp_next_scheduled( self::EVENT ) ) {
				wp_schedule_single_event( $last + self::DEBOUNCE, self::EVENT );
			}
			return;
		}

		/* Cleared before the work, not after. A publish that dies half way
		   through must not leave a mark that starts it again on every request
		   from now on; the failure is reported instead. */
		delete_option( 'vesla_publish_pending' );
		update_option( 'vesla_publish_last_run', time(), false );

		self::record( self::publish() );
	}

	/**
	 * Remember how the last write went.
	 *
	 * Stored rather than thrown. Nothing is watching an automatic run, and
	 * by the time it happens the editor's save has already been answered --
	 * so a failure has to wait somewhere until somebody is looking. The
	 * notice is raised on the next screen; see notices().
	 */
	private static function record( $result ) {
		update_option(
			'vesla_publish_status',
			is_wp_error( $result )
				? array( 'ok' => false, 'message' => $result->get_error_message(), 'at' => time() )
				: array( 'ok' => true, 'message' => $result, 'at' => time() ),
			false
		);
	}

	/**
	 * Writes index.html. Returns the path written, or WP_Error.
	 *
	 * Every failure is reported with the reason and the path. A publisher that
	 * fails quietly is worse than one that does not exist: the site looks
	 * published and is serving whatever was there before.
	 */
	public static function publish() {
		$dir  = self::target_dir();
		$file = self::target_file();

		/* The one folder this run is allowed to touch, when something has
		   said so.

		   A publisher driven from a script writes wherever the stored
		   settings point, and during testing that twice turned out to be a
		   working copy of this repository -- harmless both times, and only
		   because the output happened to be identical. Naming the intended
		   folder in VESLA_PUBLISH_ONLY turns that from something you have to
		   remember into something the publisher refuses. Nothing defines it
		   in ordinary use, so this costs one constant lookup. */
		if ( defined( 'VESLA_PUBLISH_ONLY' ) ) {
			$only = untrailingslashit( str_replace( '\\', '/', (string) VESLA_PUBLISH_ONLY ) );
			$here = untrailingslashit( str_replace( '\\', '/', $dir ) );
			if ( $only !== $here ) {
				return new WP_Error(
					'vesla_publish_guard',
					sprintf(
						/* translators: 1: the folder the settings point at. 2: the only folder this run may write to. */
						__( 'Refused: the settings point at %1$s, but this run may only publish into %2$s.', 'vesla-landing' ),
						$dir,
						$only
					)
				);
			}
		}

		if ( ! is_dir( $dir ) ) {
			return new WP_Error(
				'vesla_no_dir',
				sprintf(
					/* translators: %s: a folder path. */
					__( 'The publish folder does not exist: %s', 'vesla-landing' ),
					$dir
				)
			);
		}
		if ( ! is_writable( $dir ) ) {
			return new WP_Error(
				'vesla_not_writable',
				sprintf(
					/* translators: %s: a folder path. */
					__( 'The publish folder is not writable: %s — check its permissions in cPanel (755 on the folder, and owned by your account).', 'vesla-landing' ),
					$dir
				)
			);
		}
		if ( file_exists( $file ) && ! is_writable( $file ) ) {
			return new WP_Error(
				'vesla_file_locked',
				sprintf(
					/* translators: %s: a file path. */
					__( 'index.html exists but cannot be overwritten: %s', 'vesla-landing' ),
					$file
				)
			);
		}

		if ( ! self::copy_assets( $dir ) ) {
			return new WP_Error(
				'vesla_assets_failed',
				sprintf(
					/* translators: %s: a folder path. */
					__( 'The page was not written because its stylesheets and pictures could not be copied into %s/assets. Check that the folder is writable.', 'vesla-landing' ),
					$dir
				)
			);
		}

		$html = self::build();

		/* Written to a temporary file and moved into place. A direct write is
		   not atomic: a visitor arriving mid-write gets half a page, and if PHP
		   dies part-way the site is left holding it. */
		$tmp = $file . '.tmp-' . wp_generate_password( 6, false );
		if ( false === file_put_contents( $tmp, $html ) ) {
			return new WP_Error( 'vesla_write_failed', __( 'Could not write the page.', 'vesla-landing' ) );
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return new WP_Error( 'vesla_move_failed', __( 'Could not put the new page in place.', 'vesla-landing' ) );
		}

		/* The car pages, after the front page and not before: the front page is
		   what links to them, so publishing them first would leave a window in
		   which pages exist that nothing points at. Either way the whole run is
		   reported, and a failure here is returned rather than swallowed -- a
		   publish that quietly wrote one file out of twenty-six is worse than
		   one that says it failed. */
		$cars = Vesla_Settings::get( 'vehicle', 'enabled', 1 )
			? self::publish_cars( $dir )
			: array( 'cars' => 0, 'sold' => 0, 'slugs' => array() );
		if ( is_wp_error( $cars ) ) {
			return $cars;
		}

		/* The Contact page, after the cars and before the sitemap: the sitemap
		   lists it, and listing a page that has not been written yet is how a
		   crawler is sent to a 404. */
		$cpage = self::publish_contact( $dir );
		if ( is_wp_error( $cpage ) ) {
			return $cpage;
		}

		/* Before the sitemap, for the same reason the Contact page is written
		   before it: the sitemap lists what was actually put on disk. */
		$pages = self::publish_pages( $dir );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$index = self::write_index_files( $dir, $cars['slugs'], $pages );
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		update_option( 'vesla_last_publish', array(
			'at'   => time(),
			'cars' => (int) $cars['cars'],
			'sold' => (int) $cars['sold'],
		), false );

		return $file;
	}

	/* =======================================================================
	   THE CAR PAGES, THE SITEMAP AND ROBOTS.TXT

	   The front page is one file. Every car is another, and the two have to
	   be published together or the cards on the front page link at addresses
	   that do not exist yet.

	   The whole cars/ folder is built beside the live one and swapped in at
	   the end. Writing into the live folder would mean a visitor arriving
	   mid-publish could be served a directory that is half old and half new,
	   and a run that failed part-way would leave it that way permanently.
	   ======================================================================= */

	/**
	 * Every car page, into a folder that is swapped in when all of them are
	 * written and none before.
	 *
	 * @return array|WP_Error  counts, or the first thing that went wrong.
	 */
	private static function publish_cars( $dir ) {
		$cars = Vesla_Store::cars();
		$base = Vesla_Render::base();
		$live = $dir . DIRECTORY_SEPARATOR . $base;
		$new  = $live . '.new-' . wp_generate_password( 6, false );

		if ( ! wp_mkdir_p( $new ) ) {
			return new WP_Error(
				'vesla_cars_dir',
				sprintf(
					/* translators: %s: a folder path. */
					__( 'The car pages were not written: %s could not be created. Check that the publish folder is writable.', 'vesla-landing' ),
					$new
				)
			);
		}

		$slugs   = array();
		$written = 0;

		foreach ( $cars as $car ) {
			if ( '' === trim( $car['make'] . $car['model'] ) ) {
				continue;   // a blank row in the table is not a car
			}
			if ( ! Vesla_Render::page_on( $car ) ) {
				continue;   // this one is deliberately without a page
			}
			$slug     = Vesla_Render::slug( $car );
			$slugs[]  = $slug;
			$folder   = $new . DIRECTORY_SEPARATOR . $slug;

			if ( ! wp_mkdir_p( $folder ) ) {
				self::rmdir_all( $new );
				return new WP_Error(
					'vesla_car_dir',
					sprintf(
						/* translators: %s: a folder path. */
						__( 'The car pages were not written: %s could not be created.', 'vesla-landing' ),
						$folder
					)
				);
			}

			$html = self::build_car( $car );
			if ( false === file_put_contents( $folder . DIRECTORY_SEPARATOR . 'index.html', $html ) ) {
				self::rmdir_all( $new );
				return new WP_Error(
					'vesla_car_write',
					sprintf(
						/* translators: %s: a car. */
						__( 'The car pages were not written: the file for %s could not be saved.', 'vesla-landing' ),
						$slug
					)
				);
			}
			$written++;
		}

		/* Cars that have left the floor since the last publish. They get a page
		   saying so rather than a hole: a static host cannot answer 410, so the
		   choice on this side is between a page that explains and the host's own
		   404. The page carries noindex, so a search engine drops it either way,
		   and a link somebody was sent last month still arrives somewhere that
		   answers the question. WordPress itself does answer 410 -- see
		   Vesla_Render::maybe_404(). */
		$gone = Vesla_Render::record_slugs( $slugs );
		$sold = 0;
		foreach ( $gone as $slug ) {
			$folder = $new . DIRECTORY_SEPARATOR . $slug;
			if ( ! wp_mkdir_p( $folder ) ) {
				continue;   // not worth failing the whole publish over
			}
			if ( false !== file_put_contents( $folder . DIRECTORY_SEPARATOR . 'index.html', self::build_sold( $slug ) ) ) {
				$sold++;
			}
		}

		/* The swap. The old folder is moved aside first, because rename() onto
		   an existing directory fails on Windows and on some hosts. */
		$old = $live . '.old-' . wp_generate_password( 6, false );
		if ( is_dir( $live ) && ! @rename( $live, $old ) ) {
			self::rmdir_all( $new );
			return new WP_Error(
				'vesla_cars_swap',
				sprintf(
					/* translators: %s: a folder path. */
					__( 'The new car pages were written but could not be put in place: %s could not be moved aside. Nothing was changed.', 'vesla-landing' ),
					$live
				)
			);
		}
		if ( ! @rename( $new, $live ) ) {
			/* Put back what was there. Better the previous set of car pages than
			   no car pages at all. */
			if ( is_dir( $old ) ) {
				@rename( $old, $live );
			}
			self::rmdir_all( $new );
			return new WP_Error(
				'vesla_cars_swap2',
				__( 'The new car pages could not be put in place. The previous ones are still live.', 'vesla-landing' )
			);
		}
		if ( is_dir( $old ) ) {
			self::rmdir_all( $old );
		}

		return array( 'cars' => $written, 'sold' => $sold, 'slugs' => $slugs );
	}

	/**
	 * Delete a directory this class made, and only one this class made.
	 *
	 * The name check is the safety rail: this is only ever called on the
	 * .new- and .old- folders it creates itself, and a recursive delete that
	 * can be pointed anywhere is a bug waiting for a bad argument.
	 */
	private static function rmdir_all( $path ) {
		if ( ! is_dir( $path ) || ! preg_match( '/\.(new|old)-[A-Za-z0-9]{6}$/', $path ) ) {
			return false;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		return @rmdir( $path );
	}

	/** One car's page, as a complete document. */
	/**
	 * The Contact page as a static file, and the folder to put it in.
	 *
	 * Written the same way as the front page and for the same reason: to a
	 * temporary file and moved into place, so a visitor arriving mid-write
	 * never gets half a page.
	 */
	/**
	 * Writes every page that is switched on, and returns the slugs written.
	 *
	 * Written the way the Contact page and the cars are: to a temporary file and
	 * renamed into place, because a direct write is not atomic and a visitor
	 * arriving mid-write gets half a page.
	 *
	 * A page that is switched off is not written and, more to the point, is not
	 * returned -- the sitemap is built from what comes back, so it cannot list a
	 * page that is not there.
	 */
	/**
	 * Delete the folder of a page that has been switched off.
	 *
	 * Deliberately timid, because this is a recursive-delete shaped problem
	 * and the blast radius of getting it wrong is somebody's website. Three
	 * things have to be true before anything is removed:
	 *
	 *   1. the slug came from Vesla_Render::pages(), so it is one of ours and
	 *      never a value from a request or a settings field;
	 *   2. the folder holds exactly one entry, and it is index.html -- if
	 *      anything else is in there it was not put there by this plugin, and
	 *      deleting somebody else's work is worse than a page that lingers;
	 *   3. the file goes first and the folder only if that succeeded, so a
	 *      failure leaves the folder standing rather than half-emptied.
	 *
	 * Returns true only when the page is actually gone.
	 */
	private static function remove_page( $dir, $slug ) {
		$folder = $dir . DIRECTORY_SEPARATOR . $slug;
		if ( ! is_dir( $folder ) ) {
			return false;
		}

		$found = scandir( $folder );
		if ( ! is_array( $found ) ) {
			return false;
		}
		$found = array_values( array_diff( $found, array( '.', '..' ) ) );
		if ( array( 'index.html' ) !== $found ) {
			return false;
		}

		if ( ! @unlink( $folder . DIRECTORY_SEPARATOR . 'index.html' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return (bool) @rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function publish_pages( $dir ) {
		$written = array();

		foreach ( Vesla_Render::pages() as $key => $page ) {
			if ( ! Vesla_Render::page_live( $key ) ) {
				/* Off means off. Leaving the file behind meant a page switched
				   off because something on it was wrong stayed readable by
				   anybody holding the address -- out of the sitemap, out of the
				   menu, and still served. The car pages have never had this
				   problem because that whole folder is rebuilt on every publish;
				   these are written in place, so removal has to be deliberate. */
				self::remove_page( $dir, $page['slug'] );
				continue;
			}

			$folder = $dir . DIRECTORY_SEPARATOR . $page['slug'];
			if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
				return new WP_Error(
					'vesla_page_dir',
					sprintf(
						/* translators: 1: a page name. 2: a folder path. */
						__( 'The %1$s page was not written: its folder could not be created at %2$s.', 'vesla-landing' ),
						$key,
						$folder
					)
				);
			}

			$html = self::build_page( $key );
			if ( ! $html ) {
				return new WP_Error(
					'vesla_page_empty',
					sprintf(
						/* translators: %s: a page name. */
						__( 'The %s page came out empty and was not written.', 'vesla-landing' ),
						$key
					)
				);
			}

			$file = $folder . DIRECTORY_SEPARATOR . 'index.html';
			$tmp  = $file . '.tmp-' . wp_generate_password( 6, false );
			if ( false === file_put_contents( $tmp, $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error(
					'vesla_page_write',
					/* translators: %s: a page name. */
					sprintf( __( 'Could not write the %s page.', 'vesla-landing' ), $key )
				);
			}
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error(
					'vesla_page_move',
					/* translators: %s: a page name. */
					sprintf( __( 'Could not put the new %s page in place.', 'vesla-landing' ), $key )
				);
			}

			$written[] = $page['slug'];
		}

		return $written;
	}

	private static function publish_contact( $dir ) {
		if ( ! Vesla_Settings::get( 'contact', 'page_enabled', 1 ) ) {
			return true;
		}
		$folder = $dir . DIRECTORY_SEPARATOR . 'contact';
		if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
			return new WP_Error(
				'vesla_contact_dir',
				sprintf(
					/* translators: %s: a folder path. */
					__( 'The Contact page was not written: its folder could not be created at %s.', 'vesla-landing' ),
					$folder
				)
			);
		}
		$html = self::build_contact();
		if ( ! $html ) {
			return new WP_Error( 'vesla_contact_empty', __( 'The Contact page came out empty and was not written.', 'vesla-landing' ) );
		}
		$file = $folder . DIRECTORY_SEPARATOR . 'index.html';
		$tmp  = $file . '.tmp-' . wp_generate_password( 6, false );
		if ( false === file_put_contents( $tmp, $html ) ) {
			return new WP_Error( 'vesla_contact_write', __( 'Could not write the Contact page.', 'vesla-landing' ) );
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return new WP_Error( 'vesla_contact_move', __( 'Could not put the new Contact page in place.', 'vesla-landing' ) );
		}
		return true;
	}

	/**
	 * One of the pages listed in Vesla_Render::pages(), as a complete document.
	 *
	 * $page_key does for these what $forced does for a car: document() takes
	 * callbacks that accept nothing, so the page being written has to be said
	 * somewhere both of them can read it. Cleared afterwards either way.
	 */
	private static function build_page( $key ) {
		$name = Vesla_Settings::get( 'seo', 'business_name', get_bloginfo( 'name' ) );
		$head = Vesla_Render::page_title( $key );

		Vesla_Render::$page_key = $key;
		$html = self::document(
			trim( $head . ' — ' . $name ),
			array( 'Vesla_Render', 'page_head' ),
			array( 'Vesla_Render', 'page_body' ),
			'page'
		);
		Vesla_Render::$page_key = '';
		return $html;
	}

	private static function build_contact() {
		$name = Vesla_Settings::get( 'seo', 'business_name', get_bloginfo( 'name' ) );
		$head = Vesla_Settings::get( 'contact', 'page_heading', __( 'Come and see the car.', 'vesla-landing' ) );
		/* Contact is away from the homepage too, and its skip link said so:
		   "Skip to the cars" pointed at #stock, which is not on it. Borrowing
		   $page_key is what tells menu_href that -- contact is not in pages(), so
		   nothing else reads the value, only away_from_home() does. */
		Vesla_Render::$page_key = 'contact';
		$html = self::document(
			trim( $head . ' — ' . $name ),
			array( 'Vesla_Render', 'contact_head' ),
			array( 'Vesla_Render', 'contact_page' ),
			'contact'
		);
		Vesla_Render::$page_key = '';
		return $html;
	}

	private static function build_car( $car ) {
		Vesla_Render::$forced = $car;
		$html = self::document(
			trim( ( $car['year'] ? $car['year'] . ' ' : '' ) . $car['make'] . ' ' . $car['model'] )
				. ' — ' . Vesla_Settings::get( 'seo', 'business_name', get_bloginfo( 'name' ) ),
			array( 'Vesla_Render', 'car_head' ),
			array( 'Vesla_Render', 'vehicle_page' ),
			'vehicle'
		);
		Vesla_Render::$forced = null;
		return $html;
	}

	/**
	 * The page left behind where a car used to be.
	 *
	 * noindex, because it is not a page anybody should find in a search; it
	 * exists for the person who follows a link they were sent.
	 */
	private static function build_sold( $slug = '' ) {
		$site = trailingslashit( self::site_url() );
		$back = (string) Vesla_Settings::get( 'vehicle', 'back_label', __( 'All cars', 'vesla-landing' ) );
		$here = '' !== $slug ? $site . 'cars/' . rawurlencode( $slug ) . '/' : '';
		return self::document(
			__( 'This car has been sold', 'vesla-landing' ),
			static function () use ( $here ) {
				echo '<meta name="robots" content="noindex, follow">' . "\n";
				/* And its own address, which nothing else here says.
				
				   Every sold car gets these same words, so without this there are as
				   many identical documents as there have been sales, none of which
				   names the address it belongs at. It points at itself and nowhere
				   else: sending a noindex page's canonical somewhere else is two
				   contradictory instructions about the same page. */
				if ( '' !== $here ) {
					printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $here ) );
				}
			},
			static function () use ( $site, $back ) {
				?>
				<div class="veh-page veh-gone">
					<div class="shell">
						<h1><?php esc_html_e( 'This car has been sold.', 'vesla-landing' ); ?></h1>
						<p><?php esc_html_e( 'It was here, and it has gone. The rest of the stock is on the main page, and it changes weekly.', 'vesla-landing' ); ?></p>
						<a class="btn btn-solid" href="<?php echo esc_url( Vesla_Render::rel( $site . '#stock' ) ); ?>"><?php echo esc_html( $back ); ?></a>
					</div>
				</div>
				<?php
			},
			'vehicle'
		);
	}

	/**
	 * sitemap.xml and robots.txt.
	 *
	 * Written every publish rather than generated on request, because there is
	 * no PHP on the published site to generate anything. Each car carries the
	 * date the content was last saved: a lastmod that moves every time the file
	 * is rewritten teaches a crawler that the date means nothing.
	 */
	private static function write_index_files( $dir, array $slugs, array $pages = array() ) {
		$site = trailingslashit( self::site_url() );
		$when = gmdate( 'Y-m-d', (int) get_option( 'vesla_content_saved_at', time() ) );

		$urls = array( array( 'loc' => $site, 'pri' => '1.0', 'freq' => 'daily' ) );

		/* Listed only when it is actually written. A sitemap entry for a page
		   that does not exist is a crawler sent to a 404 by the site itself,
		   which is worse than not listing it -- and the same setting governs
		   both, so the two cannot disagree. Monthly and 0.5: the address and
		   the opening hours change, but not weekly the way the stock does. */
		/* The pages that were actually written, in the order pages() lists them.
		   Below the homepage and above the cars: they are what a reader browses
		   towards a car through, so that is where they sit. */
		foreach ( $pages as $slug ) {
			$urls[] = array( 'loc' => $site . $slug . '/', 'pri' => '0.7', 'freq' => 'weekly' );
		}

		if ( Vesla_Settings::get( 'contact', 'page_enabled', 1 ) ) {
			$urls[] = array( 'loc' => $site . 'contact/', 'pri' => '0.5', 'freq' => 'monthly' );
		}

		foreach ( $slugs as $slug ) {
			$urls[] = array(
				'loc'  => $site . Vesla_Render::base() . '/' . $slug . '/',
				'pri'  => '0.8',
				'freq' => 'weekly',
			);
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $urls as $u ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $u['loc'] ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . $when . "</lastmod>\n";
			$xml .= "\t\t<changefreq>" . $u['freq'] . "</changefreq>\n";
			$xml .= "\t\t<priority>" . $u['pri'] . "</priority>\n";
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>' . "\n";

		/* Disallow nothing: there is nothing on the published site that is not
		   meant to be found. The one line that earns its place is the sitemap,
		   which is how a crawler is told the car pages exist without having to
		   discover every one of them through the front page. */
		$robots  = "User-agent: *\n";
		$robots .= "Allow: /\n\n";
		$robots .= 'Sitemap: ' . esc_url_raw( $site . 'sitemap.xml' ) . "\n";

		foreach ( array( 'sitemap.xml' => $xml, 'robots.txt' => $robots ) as $name => $body ) {
			$path = $dir . DIRECTORY_SEPARATOR . $name;
			$tmp  = $path . '.tmp-' . wp_generate_password( 6, false );
			if ( false === file_put_contents( $tmp, $body ) || ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
				return new WP_Error(
					'vesla_index_files',
					sprintf(
						/* translators: %s: a file name. */
						__( 'The pages were published but %s could not be written. Search engines will not be told about the car pages until this is fixed.', 'vesla-landing' ),
						$name
					)
				);
			}
		}
		return true;
	}
	/**
	 * The complete document.
	 *
	 * Content first, script second. Everything a crawler needs is in the
	 * markup before app.js is even requested — which is the entire point of
	 * this file, and the thing to check if it is ever changed: load the result
	 * with JavaScript disabled and the cars must still be there.
	 */
	/**
	 * Any published page: the front page, a car, or the note where a car was.
	 *
	 * One builder for all of them, so the head, the stylesheets, the data
	 * block and the script tags cannot drift apart between page types -- which
	 * they would, because only one of them gets looked at regularly.
	 *
	 * @param string   $title  the <title>.
	 * @param callable $head   prints the rest of <head>.
	 * @param callable $body   prints the page.
	 * @param string   $kind   'landing' or 'vehicle' -- which scripts to load.
	 */
	private static function document( $title, $head, $body, $kind = 'landing' ) {
		$locale   = str_replace( '_', '-', get_locale() );
		/* The copied assets, addressed from the public site rather than from
		   inside WordPress. Absolute rather than relative because the same
		   addresses appear in the listing data, where search engines require
		   full URLs. */
		$assets   = self::site_url() . '/assets/';
		$rest     = rest_url( Vesla_Rest::NS . '/' );
		$motion   = (bool) Vesla_Settings::get( 'extras', 'motion_enabled', 0 );
		$seo_on   = (bool) Vesla_Settings::get( 'seo', 'enabled', 0 );

		Vesla_Render::$static_build = true;

		ob_start();
		?><!DOCTYPE html>
<html lang="<?php echo esc_attr( $locale ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<?php
/* Whatever this kind of page puts in its head. Written into the file rather
   than fetched, because a link previewer reads the markup once and never
   runs a script. */
call_user_func( $head );
Vesla_Render::analytics_tag();
?>
<link rel="preconnect" href="<?php echo esc_url( home_url() ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $assets . 'styles.css?v=' . vesla_asset_ver( 'assets/styles.css' ) ); ?>">
<?php if ( $motion ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $assets . 'motion.css?v=' . vesla_asset_ver( 'assets/motion.css' ) ); ?>">
<?php endif; ?>
<script>
/* The endpoint, and the content that was true when this file was written.
   app.js uses the second to render immediately and the first to refresh. */
window.VESLA_REST = <?php echo wp_json_encode( $rest ); ?>;
window.VESLA_DATA = <?php echo wp_json_encode( 'vehicle' === $kind ? Vesla_Render::js_data_vehicle() : Vesla_Render::js_data() ); ?>;
window.VESLA_PRERENDERED = true;
</script>
</head>
<body class="vesla-static vesla-<?php echo esc_attr( $kind ); ?>">
<?php
/* The page itself — the same methods WordPress renders, so there is one set
   of templates and they cannot drift. */
call_user_func( $body );
?>
<?php
/* The front page and a car's page run different scripts, and running the
   wrong one is not harmless: app.js reaches straight for the filter menus
   and throws on a page that has none, taking the rest of its work with it. */
if ( 'vehicle' === $kind ) :
?>
<script src="<?php echo esc_url( $assets . 'vehicle.js?v=' . vesla_asset_ver( 'assets/vehicle.js' ) ); ?>" defer></script>
<?php else : ?>
<script src="<?php echo esc_url( $assets . 'app.js?v=' . vesla_asset_ver( 'assets/app.js' ) ); ?>" defer></script>
<?php if ( $motion ) : ?>
<script src="<?php echo esc_url( $assets . 'motion.js?v=' . vesla_asset_ver( 'assets/motion.js' ) ); ?>" defer></script>
<?php endif; ?>
<?php endif; ?>
<!-- published <?php echo esc_html( gmdate( 'c' ) ); ?> -->
</body>
</html>
<?php
		$html = ob_get_clean();
		Vesla_Render::$static_build = false;

		/* Anything the renderer emitted pointing inside the plugin — the logo,
		   the seed car photographs — now has a copy beside the page.
		
		   Twice, because the same addresses appear again inside the JSON data
		   block with their slashes escaped. A plain replace matched only the
		   markup, so the copies app.js reads still pointed into wp-content and
		   the asset folder beside the page went unused by exactly the code that
		   redraws the grid. */
		$html = self::rebase( $html, VESLA_URL . 'assets/', self::site_url() . '/assets/' );

		/* Then everything else WordPress put its own address on: the media
		   library photographs, the REST feed app.js refreshes from, the enquiry
		   endpoint, the preconnect hint. These are not composed here -- they
		   arrive already finished from wp_get_attachment_url(), rest_url() and
		   admin_url() -- so here is the only place they can be corrected.

		   Second, not first: the plugin's own assets sit underneath the
		   WordPress address, so rebasing WordPress first would leave the rule
		   above nothing to match and the copies beside the page unused.

		   Skipped when the two are the same address, which means this is not
		   the split layout publishing is for and there is nothing to move. */
		$wp = untrailingslashit( home_url() );
		if ( $wp && $wp !== self::site_url() ) {
			$html = self::rebase( $html, $wp, self::cms_url() );
		}
		return $html;
	}

	/**
	 * Swaps one address for another everywhere it appears in a built page.
	 *
	 * Twice over, because the same addresses appear again inside the JSON data
	 * block with their slashes escaped. A plain replace matched only the
	 * markup, so the copies app.js reads still pointed into wp-content and the
	 * asset folder beside the page went unused by exactly the code that redraws
	 * the grid.
	 */
	private static function rebase( $html, $from, $to ) {
		$html = str_replace( $from, $to, $html );
		return str_replace(
			str_replace( '/', '\/', $from ),
			str_replace( '/', '\/', $to ),
			$html
		);
	}

	/** The front page. */
	private static function build() {
		$seo_on = (bool) Vesla_Settings::get( 'seo', 'enabled', 0 );
		return self::document(
			Vesla_Settings::get( 'seo', 'business_name', get_bloginfo( 'name' ) ),
			static function () use ( $seo_on ) {
				/* The same head tags WordPress serves: description, sharing, and
				   the AutoDealer / FAQPage / ItemList structured data. Written
				   into the file rather than fetched, because a link previewer
				   reads the markup once and never runs a script. */
				if ( $seo_on ) {
					Vesla_Render::head( true );
				}
			},
			static function () {
				echo Vesla_Render::shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped inside.
			},
			'landing'
		);
	}
	/* ═══════════════════════════════════════════════════════════════════════
	   THE BUTTON
	   ═══════════════════════════════════════════════════════════════════════ */

	public static function handle_republish() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to publish this page.', 'vesla-landing' ) );
		}
		check_admin_referer( 'vesla_republish' );

		$result = self::publish();
		/* It counts as a publish, so the automatic one does not immediately
		   repeat work somebody has just asked for and watched finish. */
		update_option( 'vesla_publish_last_run', time(), false );
		delete_option( 'vesla_publish_pending' );

		update_option(
			'vesla_publish_status',
			is_wp_error( $result )
				? array( 'ok' => false, 'message' => $result->get_error_message(), 'at' => time() )
				: array( 'ok' => true, 'message' => $result, 'at' => time() ),
			false
		);

		wp_safe_redirect(
			add_query_arg(
				'vesla_published',
				is_wp_error( $result ) ? 'failed' : 'ok',
				admin_url( 'admin.php?page=' . Vesla_Admin::SLUG )
			)
		);
		exit;
	}

	/**
	 * How long a publish has been owed past when it should have happened.
	 *
	 * The whole automatic path leans on WordPress's scheduled tasks, and
	 * those only run when somebody requests a WordPress page. On the
	 * set-up this plugin exists for, visitors are served plain HTML and
	 * never touch WordPress at all -- so the only traffic is whoever is
	 * logged in here, and a car saved by a salesperson can sit unpublished
	 * until somebody happens to open a screen. The answer is a real cron
	 * job on the server; until there is one, the least this can do is not
	 * pretend everything is fine.
	 *
	 * @return int Seconds late, or 0 when nothing is owed or it is not late yet.
	 */
	public static function overdue() {
		if ( ! self::enabled() ) {
			return 0;
		}
		$pending = (int) get_option( 'vesla_publish_pending' );
		if ( ! $pending ) {
			return 0;
		}

		/* Nothing on the schedule at all is worse than late, not better: it
		   means the work is owed and nothing whatever is coming for it. Judge
		   it from when it was first owed. */
		$due  = (int) wp_next_scheduled( self::EVENT );
		$due  = $due ? $due : $pending + self::DEBOUNCE;
		$late = time() - $due;

		return $late > self::OVERDUE ? $late : 0;
	}

	/**
	 * The line to hand cPanel's Cron Jobs screen.
	 *
	 * Built from this installation rather than written out as an example,
	 * so it names the path WordPress is actually at on this server and can
	 * be copied without being edited.
	 */
	public static function cron_line() {
		return '*/5 * * * * /usr/local/bin/php -q ' . str_replace( '\\', '/', untrailingslashit( ABSPATH ) ) . '/wp-cron.php >/dev/null 2>&1';
	}

	public static function republish_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=vesla_republish' ), 'vesla_republish' );
	}

	public static function export_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=vesla_export' ), 'vesla_export' );
	}

	public static function content_export_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=vesla_content_export' ), 'vesla_content_export' );
	}

	/** Where content-export.json is written. */
	public static function export_file() {
		$dir = trim( (string) Vesla_Settings::get( 'publish', 'export_path', '' ) );
		if ( '' === $dir ) {
			$dir = VESLA_DIR . 'data';
		}
		return untrailingslashit( $dir ) . '/content-export.json';
	}

	/**
	 * Write the content export to its fixed path, for committing.
	 *
	 * A fixed name rather than a timestamped one, deliberately: the value of
	 * this file is the diff between one commit and the next, and a new
	 * filename every time gives a history of additions rather than a history
	 * of changes.
	 */
	public static function handle_content_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this content.', 'vesla-landing' ) );
		}
		check_admin_referer( 'vesla_content_export' );

		$back = admin_url( 'admin.php?page=' . Vesla_Admin::SLUG );
		$file = self::export_file();
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			wp_safe_redirect( add_query_arg( 'vesla_content', 'nofolder', $back ) );
			exit;
		}

		$body = wp_json_encode(
			Vesla_Store::export_content(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! $body || false === @file_put_contents( $file, $body . "
" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- reported below rather than thrown.
			wp_safe_redirect( add_query_arg( 'vesla_content', 'failed', $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'vesla_content', 'ok', $back ) );
		exit;
	}

	/**
	 * Send every stored setting back as a JSON file.
	 *
	 * The point of this is that it does not depend on anybody being careful.
	 * Someone about to do something risky can take their own copy first, and
	 * the copy is a plain file they hold rather than a row in the table that
	 * the risky thing might empty.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export these settings.', 'vesla-landing' ) );
		}
		check_admin_referer( 'vesla_export' );

		$body = wp_json_encode(
			array(
				'plugin'   => 'vesla-landing',
				'version'  => VESLA_VERSION,
				'site'     => home_url(),
				'saved_at' => gmdate( 'c' ),
				/* The stored rows, not the merged view. What an administrator has
				   actually changed is the thing worth keeping; the defaults come
				   back on their own from seed.json wherever this is restored. */
				'rows'     => Vesla_Store::rows(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$name = 'vesla-settings-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( (string) $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- a JSON file, not markup.
		exit;
	}

	/**
	 * Read an exported file back in, over the top of what is stored.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import these settings.', 'vesla-landing' ) );
		}
		check_admin_referer( 'vesla_import' );

		$back = admin_url( 'admin.php?page=' . Vesla_Admin::SLUG );
		if ( empty( $_FILES['vesla_import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['vesla_import']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked by is_uploaded_file.
			wp_safe_redirect( add_query_arg( 'vesla_import', 'nofile', $back ) );
			exit;
		}

		$raw  = (string) file_get_contents( $_FILES['vesla_import']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			wp_safe_redirect( add_query_arg( 'vesla_import', 'unreadable', $back ) );
			exit;
		}

		$done = Vesla_Store::put_rows( $data['rows'] );
		wp_safe_redirect( add_query_arg( 'vesla_import', is_wp_error( $done ) ? 'failed' : 'ok', $back ) );
		exit;
	}

	/**
	 * Put back one of the automatic snapshots taken before a save.
	 */
	public static function handle_restore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to restore these settings.', 'vesla-landing' ) );
		}
		check_admin_referer( 'vesla_restore' );

		$back = admin_url( 'admin.php?page=' . Vesla_Admin::SLUG );
		$file = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
		/* The path is checked inside restore_snapshot() against the folder the
		   plugin writes to and the filename pattern it writes. */
		$done = Vesla_Store::restore_snapshot( $file );
		wp_safe_redirect( add_query_arg( 'vesla_restored', is_wp_error( $done ) ? 'failed' : 'ok', $back ) );
		exit;
	}

	/**
	 * When the public page was last written, for the top bar.
	 *
	 * Shown always, not only after a failure. “Is the live site up to date?”
	 * should be answerable by looking rather than by guessing, and it sits
	 * beside the Republish button because that is where somebody who has just
	 * wondered will already be looking.
	 *
	 * @return string Escaped, ready to print, or '' when it has never run.
	 */
	public static function last_written() {
		$status = get_option( 'vesla_publish_status' );
		if ( ! $status || empty( $status['at'] ) ) {
			return '';
		}
		return esc_html(
			sprintf(
				/* translators: 1: how long ago, 2: whether it worked. */
				__( 'Last written %1$s ago — %2$s', 'vesla-landing' ),
				human_time_diff( (int) $status['at'], time() ),
				empty( $status['ok'] ) ? __( 'it failed', 'vesla-landing' ) : __( 'it worked', 'vesla-landing' )
			)
		);
	}

	/**
	 * Loud on failure, quiet on success.
	 *
	 * A publisher that cannot write is the one thing here that must never be
	 * discovered later: the site keeps serving the previous page and looks
	 * fine, so nothing about the running site says the last three edits never
	 * went live.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		/* The settings screen, and the car screens.

		   A publish that has not happened is most urgent to whoever just
		   added the car, and they are not on the settings screen -- they are
		   looking at the list they just added a row to. */
		$on_settings = ( 'toplevel_page_' . Vesla_Admin::SLUG === $screen->id );
		$on_cars     = in_array( $screen->id, array( 'edit-' . Vesla_Vehicle::TYPE, Vesla_Vehicle::TYPE ), true );
		if ( ! $on_settings && ! $on_cars ) {
			return;
		}

		/* Being told is not the same permission as being able to fix it.

		   Cars use the ordinary post capabilities, so the person adding stock
		   is typically an Editor with no manage_options at all. Gating this
		   on administrator would hide "your car is not on the website" from
		   the one person who needs to know it, which is the whole point of
		   showing it here. So they are told, in words that suit what they can
		   actually do about it, and everything else on this screen stays
		   administrator business. */
		$type      = get_post_type_object( Vesla_Vehicle::TYPE );
		$may_edit  = $type && current_user_can( $type->cap->edit_posts );
		$may_admin = current_user_can( 'manage_options' );
		if ( ! $may_admin && ! $may_edit ) {
			return;
		}

		/* ── publishing switched off while a published site exists ──

		   The worst version of this is silent: the setting is off, every save
		   looks like it worked, and the public site quietly stops matching the
		   editor for weeks. There is no error to notice because nothing failed.
		   So it is said out loud, on the screen where the saving happens. */
		if ( $may_admin && $on_settings && ! self::enabled() && file_exists( self::target_file() ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><p><code>%s</code></p></div>',
				esc_html__( 'The public page is no longer being updated.', 'vesla-landing' ),
				esc_html__( 'There is a published page on disk, but “Write the public page when I save” is switched off — so what visitors see is frozen at whatever it said when it was last written, and saving here will not change it.', 'vesla-landing' ),
				esc_html( self::target_file() )
			);
		}

		/* ── owed, and nothing has come to collect it ──

		   Placed above the status check on purpose: a site that has never
		   published successfully has no status to read, and that is exactly
		   the site where this matters most. */
		$late = self::overdue();
		if ( $late && ! $may_admin ) {
			/* No cron line and no button: neither is any use to somebody who
			   cannot act on them. What they need is the fact and who to ask. */
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p></div>',
				esc_html__( 'Your changes are saved, but the website has not been updated yet.', 'vesla-landing' ),
				esc_html(
					sprintf(
						/* translators: %s: a length of time, e.g. "2 hours". */
						__( 'A change has been waiting %s to reach the public site, so visitors are still seeing the previous version. Nothing is lost. Ask an administrator to open Landing Page and press Publish now.', 'vesla-landing' ),
						human_time_diff( (int) get_option( 'vesla_publish_pending' ), time() )
					)
				)
			);
		}

		if ( $late && $may_admin ) {
			$waiting = human_time_diff( (int) get_option( 'vesla_publish_pending' ), time() );
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><p>%s</p><p><code>%s</code></p><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
				esc_html__( 'Saved here, but not yet on the public page.', 'vesla-landing' ),
				esc_html(
					sprintf(
						/* translators: %s: a length of time, e.g. "2 hours". */
						__( 'A change has been waiting %s to be written out. Until it is, visitors are still being served the previous version of the site.', 'vesla-landing' ),
						$waiting
					)
				),
				esc_html__( 'Writing the page is carried by WordPress’s scheduled tasks, and those only run when somebody asks WordPress for a page. This site serves its visitors plain HTML, so they never do — which leaves whoever happens to be logged in here. Ask your host to run this every five minutes and it stops being your job:', 'vesla-landing' ),
				esc_html( self::cron_line() ),
				esc_html__( 'In the meantime, this writes it out straight away:', 'vesla-landing' ),
				esc_url( self::republish_url() ),
				esc_html__( 'Publish now', 'vesla-landing' )
			);
		}

		/* Everything past here is for whoever configures the site, and only
		   on the screen where they configure it. */
		if ( ! $may_admin || ! $on_settings ) {
			return;
		}

		$status = get_option( 'vesla_publish_status' );
		if ( ! $status || empty( $status['at'] ) ) {
			return;
		}


		if ( empty( $status['ok'] ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
				esc_html__( 'The public page could not be updated.', 'vesla-landing' ),
				esc_html( $status['message'] ),
				esc_html__( 'Your changes are saved here, but the page visitors see has not changed. Fix the folder above and press Republish.', 'vesla-landing' )
			);
			return;
		}

		if ( isset( $_GET['vesla_published'] ) && 'ok' === $_GET['vesla_published'] ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <code>%s</code></p></div>',
				esc_html__( 'The public page has been rewritten:', 'vesla-landing' ),
				esc_html( $status['message'] )
			);
		}
	}
}

/* ========================================================================== */
/*  10 · ACTIVATION AND WIRING
 *
 *  First run, and the hooks that start everything above.
 */
/* ========================================================================== */

add_action(
	'plugins_loaded',
	function () {
		/* Both of these have to wait for `init`.
		 *
		 * WordPress 6.7 warns when a textdomain is loaded before init, and the
		 * schema is full of __() calls -- so asking Vesla_Store to check the car
		 * columns here dragged the translations in early too, and every request
		 * logged a notice. The check itself is cheap, and one action later is
		 * still long before anything reads a car.
		 */
		add_action(
			'init',
			function () {
				load_plugin_textdomain( 'vesla-landing', false, dirname( VESLA_BASENAME ) . '/languages' );
				Vesla_Store::maybe_upgrade();
			},
			1
		);

		Vesla_Settings::init();
		Vesla_Admin::init();
		Vesla_Render::init();
		Vesla_Vehicle::init();
		Vesla_Enquiry::init();
		Vesla_Rest::init();
		Vesla_Publisher::init();
		if ( is_admin() ) {
			Vesla_Enquiry_Admin::init();
		}
	}
);

add_action( 'init', array( 'Vesla_Enquiry', 'register_type' ) );

/**
 * First activation: seed the content and, if this is a fresh site, build the
 * page that shows it. Nothing here overwrites anything that already exists.
 */
register_activation_hook(
	__FILE__,
	function () {
		Vesla_Settings::activate();
		Vesla_Enquiry::register_type();

		if ( ! get_option( 'vesla_landing_page_id' ) ) {
			$existing = get_posts(
				array(
					'post_type'   => 'page',
					's'           => '[vesla_landing]',
					'numberposts' => 1,
					'post_status' => 'any',
				)
			);
			if ( $existing ) {
				update_option( 'vesla_landing_page_id', $existing[0]->ID );
			} else {
				$id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => __( 'Home', 'vesla-landing' ),
						'post_content' => '[vesla_landing]',
					)
				);
				if ( $id && ! is_wp_error( $id ) ) {
					update_option( 'vesla_landing_page_id', $id );
					/* without this the page renders inside the theme's own
					   header, footer and content column — see the note at the
					   top of templates/vesla-blank.php */
					update_post_meta( $id, '_wp_page_template', 'vesla-blank.php' );
					/* a one-page site wants this page as its front page */
					if ( 'posts' === get_option( 'show_on_front' ) ) {
						update_option( 'show_on_front', 'page' );
						update_option( 'page_on_front', $id );
					}
				}
			}
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* And nothing due. A deactivated plugin whose publish job is still on the
   schedule wakes up to an action nothing is listening for. */
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( Vesla_Publisher::EVENT );
	}
);

/**
 * Prompt on first run. Shown once, and only until the page has been opened —
 * a notice that never goes away is a notice people stop reading.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) || get_option( 'vesla_landing_seen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_vesla-landing' === $screen->id ) {
			update_option( 'vesla_landing_seen', 1 );
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Vesla Landing Page is ready.', 'vesla-landing' ),
			esc_html__( 'Sample cars and wording have been loaded so you can see the page working. Edit all of it from one screen:', 'vesla-landing' ),
			esc_url( admin_url( 'admin.php?page=vesla-landing' ) ),
			esc_html__( 'Open the editor', 'vesla-landing' )
		);
	}
);
