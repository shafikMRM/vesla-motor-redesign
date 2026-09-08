=== Vesla Landing Page ===
Contributors: veslamotors
Tags: landing page, one page, car dealer, showroom
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later

The whole one-page site, edited from a single screen. No code, no page builder.

== Description ==

One page, one editing screen. Everything a visitor sees — the cars, the
wording, the photographs, the colours, the phone numbers — is edited under
**Landing Page** in the WordPress menu, and saved with one button.

Nothing is written into the files. If it is on the page, it is in the editor.

= What you can change =

* **Logo and contact details** — used in the top bar, the footer, the contact
  list and the WhatsApp button on every car. Entered once.
* **The cars** — add, remove and drag into order. The Make and Body filter
  menus, the price bands, the sorting, the counter and the listing information
  search engines read are all built from this list, so there is nothing to keep
  in step by hand.
* **Every section** — opening section, reasons to buy, cars, how cars are
  checked, what buyers get, company record, ownership, buying cars from
  visitors, questions and answers, contact, footer.
* **Each section can be switched off** without deleting anything. The list down
  the left of the editor shows a grey dot beside anything hidden.
* **Colours** — taken from your logo. Change the brand colour and the whole
  page follows.
* **The enquiry form** — sent to the address you choose, and a copy of every
  enquiry is kept under Landing Page → Enquiries in case email ever fails.

== Installation ==

= From the WordPress admin (easiest) =

1. Plugins → Add New → Upload Plugin.
2. Choose `vesla-landing.zip` and press Install Now, then Activate.
3. A page called **Home** is created with the shortcode already on it, and is
   set as your front page if you did not already have one.
4. Go to **Landing Page** in the menu and start editing.

= By cPanel File Manager =

1. Log in to cPanel and open **File Manager**.
2. Go to `public_html/wp-content/plugins/`.
3. Upload `vesla-landing.zip`, then right-click it and choose **Extract**.
4. Delete the .zip afterwards to keep the folder tidy.
5. In WordPress, go to Plugins and press **Activate** under Vesla Landing Page.

= Putting it on a page yourself =

If you would rather choose the page, create one and put this in it:

    [vesla_landing]

Then set it as the front page under Settings → Reading.

A full-width, blank page template gives the best result — the page brings its
own header and footer, so a theme that adds a second set of both will look
doubled up. Most themes offer a template called something like "Full width",
"Blank", "Canvas" or "Elementor Full Width".

== Frequently Asked Questions ==

= I changed something and the page looks the same =

Press Save changes, then reload the page with Ctrl+F5 (Cmd+Shift+R on a Mac).
If your host has caching, or you use a caching plugin, clear that too.

= The photographs are the sample ones. How do I use mine? =

Landing Page → Cars for sale → open a car → Photograph → Choose image. Upload
yours in the usual WordPress media window. The samples are only there so the
page is not empty on day one.

= Can I have more or fewer than four points in the strip under the heading? =

Yes. Add or remove them under "Reasons-to-buy strip". Four fits neatly across a
desktop screen; more will wrap onto a second row.

= Enquiries are not arriving by email =

Shared hosting very often cannot send mail reliably on its own. Every enquiry
is also saved under **Landing Page → Enquiries**, so nothing is lost while you
sort it out. The usual fix is an SMTP plugin pointed at your own mailbox.

The Enquiries screen has an **Emailed** column showing which ones reached you,
and a filter to list just the failures. If three in a row fail, a warning
appears on your dashboard by itself — you do not have to go looking.

= I use LiteSpeed Cache / WP Rocket / another caching plugin =

It will work as it is. The form recovers by itself if the security token on a
cached copy of the page has expired: it quietly fetches a fresh one and sends
again, and the visitor sees nothing.

If you would rather it never came up, exclude the landing page from the cache:

* **LiteSpeed Cache** — Cache → Excludes → "Do Not Cache URIs", add the page's
  path (`/` if it is your front page).
* **WP Rocket** — Advanced Rules → "Never Cache (URLs)".
* **W3 Total Cache** — Page Cache → Advanced → "Never cache the following
  pages".

Either way, do not cache `/wp-admin/admin-ajax.php`. Some hosts do by default,
and that stops enquiries reaching you at all.

= Can an Editor see the enquiries? =

No. Enquiries contain customers' names, phone numbers and email addresses, so
they are restricted to administrators — the ones who can also reach Settings.
Editors and Authors cannot open them, and cannot reach them by URL either.

= Can I switch the animations off? =

Yes — Phone bar, loading screen & colours → "Use the animations". There is also
a setting to honour a visitor's own "reduce motion" preference, which is off by
default only because Windows battery saver reports it even when the visitor has
not asked for it.

= Will it work with my SEO plugin? =

Yes. If you use Yoast, Rank Math or similar, switch off "Let this plugin write
the search-engine information" under "Search engines & sharing" so the two do
not both write it.

== Backing up and moving the content ==

The database holds everything an administrator has typed: the settings and
every vehicle. The code and the published pages can be kept in version
control; the content cannot, unless it is written out as a file. Three
different things do that, and they are not interchangeable.

**A copy for safe keeping.** Landing Page -> Backups -> "Download a copy".
Every stored setting as JSON, including the address enquiries are sent to.
This is a recovery file. Keep it somewhere private and do NOT commit it.

**Automatic backups.** One is taken before every save, into
wp-content/uploads/vesla-backups/, and the last ten are kept. Put one back
from the same panel. These also hold the enquiry address, and the uploads
folder is not for committing.

**The content export.** Landing Page -> Backups -> "Write the content
export". This writes every setting and every vehicle to content-export.json
in the folder named under "Publish the public page" -> "Folder to write the
content export into". Unlike the two above, this one is meant to be
committed: the address enquiries are delivered to is left out of it, and so
is everything under Enquiries. It is sorted the same way every time, so a
commit shows the values that changed rather than a reshuffled file.

= Restoring content into a fresh install =

1. Install and activate the plugin. It will seed itself with the starter
   copy; that is expected and about to be replaced.

2. Copy content-export.json into the folder the export setting points at, or
   anywhere the site can read.

3. Import it. Either from Landing Page -> Backups -> "Load a copy back in",
   or, with WP-CLI:

       wp eval '$d = json_decode( file_get_contents( "content-export.json" ), true );
                $r = Vesla_Store::import_content( $d );
                echo is_wp_error( $r ) ? $r->get_error_message() : print_r( $r, true );'

   Settings are replaced wholesale. Vehicles are matched on the car's own
   number rather than the WordPress post id, so a car that already exists is
   updated in place and one that does not is created carrying the same
   number. That is what keeps every car's web address the same after a
   restore -- the addresses are built from the car number, not the post id.

4. Two things the export deliberately does not carry, because neither is
   content:

   * The address enquiries are sent to. Set it again under "Contact section
     & enquiry form".
   * Pictures. The export records which picture each field points at, by its
     media library id, and those ids only mean something in the install they
     came from. Move the uploads folder and the media library across as
     well, or set the pictures again.

5. Press Save once. That rebuilds the taxonomies behind the vehicle list and
   writes the public pages out again.

== Changelog ==

= 1.1.0 =
* Enquiries list now shows Name, Phone, Email, Car and whether the email
  actually reached you, with click-to-call and click-to-email links.
* Filter the list by "Email failed", and sort by delivery.
* Opening an enquiry now shows all of its details, not just the message.
* A dashboard warning appears by itself if three enquiries in a row fail to
  send, so a broken mail server cannot go unnoticed.
* Export all enquiries to CSV.
* The form now recovers on its own from an expired security token on a cached
  page, instead of losing the enquiry.
* Enquiry submissions are rate limited (5 per 15 minutes per visitor).
* SECURITY: enquiries are now restricted to administrators. Previously an
  Editor could reach them directly by URL.

= 1.0.0 =
* First release. One-screen editor for the whole page, editable car list with
  drag-to-reorder, enquiry form with server-side checking and a stored copy of
  every enquiry, colour settings taken from the logo, and per-section on/off.
