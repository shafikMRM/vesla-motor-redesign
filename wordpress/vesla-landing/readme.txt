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

= A car was saved hours ago and is still not on the website =

This is the one thing to set up properly, and it takes five minutes.

Writing the public page is carried by WordPress's scheduled tasks. Those are
not a real timer: WordPress only checks whether anything is due when somebody
asks it for a page. On this set-up your visitors are served plain HTML files
and never ask WordPress for anything, so the only person whose visit can
trigger it is whoever is logged into the admin. Save a car on a quiet
afternoon and it can sit unpublished until someone next opens a screen.

The fix is to have the server run it on a clock instead.

**1. Add the cron job in cPanel**

In cPanel, open **Advanced -> Cron Jobs**. Under "Add New Cron Job":

* **Common Settings** - choose "Every 5 Minutes (*/5 * * * *)". That fills in
  the five boxes for you: Minute `*/5`, and Hour, Day, Month and Weekday all `*`.
* **Command** - paste this, then correct the path to match your account:

    /usr/local/bin/php -q /home/USERNAME/public_html/cms/wp-cron.php >/dev/null 2>&1

Replace `USERNAME` with your cPanel username, and make sure the path is where
WordPress actually lives — if the site is served from public_html and
WordPress sits in a "cms" folder inside it, the line above is already right.
The exact line for this installation, with the path filled in, is shown on the
Landing Page screen whenever something is waiting to be published.

Press **Add New Cron Job**. cPanel will email you the output of every run
unless you clear the notification address; the `>/dev/null 2>&1` on the end is
what keeps those emails empty.

**2. Tell WordPress to stop trying on its own**

Edit `wp-config.php` (cPanel -> File Manager, in the WordPress folder) and add
this line anywhere above the line that says "That's all, stop editing":

    define( 'DISABLE_WP_CRON', true );

Without this, WordPress keeps checking on every admin page load as well, which
is wasted work and can have two publishes overlapping.

**Until that is done**, nothing is lost — a save is remembered, and the Landing
Page screen tells you plainly when something has been waiting, with a
**Publish now** button that writes it out immediately. The **Republish** button
always works straight away and never waits for any of this.
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

READ THIS FIRST: the content export is not a backup.

It carries every word and every vehicle. It does NOT carry a single
photograph. Restore from it alone into an empty install and you get the
complete site with no pictures on it -- and photographs are the one thing
this site is short of, so that is not a small gap.

A real backup of this site is two things together:

  1. a database dump (mysqldump, or your host's backup tool), and
  2. the wp-content/uploads folder.

Take both. The content export is for putting the words and the cars into
version control alongside the code, and for moving content between installs
that already share a media library. It is not a substitute for either of the
two above, and nothing in this plugin is.

= What the export does about pictures =

Every picture on this site is stored as a media library id, and an id means
nothing in another install -- id 88 there is a different picture, or none.
So the export records the FILE each id points at as well as the id:

    "media": { "88": "2026/09/f3dcc04d50b14e1988b2864a1b92c41d-.webp" }

On restore each of those files is looked up in the media library being
restored into, and every reference is renumbered to whatever id it has
there. So if you carried the uploads folder across and let WordPress import
the media, the photographs reattach themselves to the right cars even though
the numbers have all changed.

If a file is not in that media library, its reference is left alone and
simply does not resolve. The car then shows as one with no photograph yet,
which every part of this site already handles. The import reports how many
were found and how many were not, so you know before looking at the site.

= The three files, and which one to use =

**A copy for safe keeping.** Landing Page -> Backups -> "Download a copy".
Every stored setting as JSON, including the address enquiries are sent to.
A recovery file. Keep it somewhere private and do NOT commit it.

**Automatic backups.** One before every save, into
wp-content/uploads/vesla-backups/, last ten kept, restored from the same
panel. These also hold the enquiry address.

**The content export.** Landing Page -> Backups -> "Write the content
export". Writes content-export.json to the folder named under "Publish the
public page" -> "Folder to write the content export into". This is the one
meant to be committed: the enquiry address is left out, and so is everything
under Enquiries. It sorts identically every time, so a commit shows the
values that changed rather than a reshuffled file.

= Restoring content into a fresh install =

1. Install and activate the plugin. It seeds itself with the starter copy;
   that is expected and about to be replaced.

2. Bring the pictures over first, if you want any: copy wp-content/uploads
   across and let WordPress index the media, or import the media library by
   whatever route your host offers. Do this BEFORE step 3 -- the re-matching
   only finds what is already there.

3. Import content-export.json, either from Landing Page -> Backups -> "Load
   a copy back in", or with WP-CLI:

       wp eval '$d = json_decode( file_get_contents( "content-export.json" ), true );
                $r = Vesla_Store::import_content( $d );
                echo is_wp_error( $r ) ? $r->get_error_message() : print_r( $r, true );'

   It reports settings written, cars updated, cars created, and how many
   pictures were found and lost.

   Settings are replaced wholesale. Cars are matched on the car's own number
   rather than the WordPress post id, so a car that already exists is updated
   in place and one that does not is created carrying the same number. That
   is what keeps every car's web address the same -- the addresses are built
   from the car number, not the post id.

4. Set the address enquiries are sent to, under "Contact section & enquiry
   form". The export never carries it.

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
