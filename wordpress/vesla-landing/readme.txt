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

== Setting up on the live server ==

Work through this once, in order, when the site goes live. Every step here is
something that fails **quietly** if it is skipped — the site keeps loading and
looking correct while something behind it does not work. Nothing here needs to
be repeated afterwards.

= 1. Put WordPress in public_html/cms =

Visitors are served plain HTML from `public_html`. WordPress is the editor
behind it and belongs in `public_html/cms`.

It must be a folder under the same domain, not a subdomain. The enquiry form
checks that the request came from this same address before it accepts it, so
a WordPress on `cms.yourdomain.com` is a different origin and every enquiry is
refused. It is also what lets the published pages load the car photographs
straight out of the media library.

**If you skip it:** enquiries are rejected, and the photographs on the
published pages point at an address that does not exist.

= 2. Fill in the three addresses =

Landing Page → Publish the public page:

* **Web address of the public site** — `https://yourdomain.com`. Exactly the
  form that actually serves: if the site answers on `www.`, write `www.`, and
  if it is https, write https.
* **Web address WordPress will be at on the live site** — `https://yourdomain.com/cms`.
  Leave it empty and `/cms` under the public address is assumed, which is
  correct if you followed step 1. Fill it in if WordPress is anywhere else.
* **Folder to write index.html into** — the full path to `public_html`, e.g.
  `/home/USERNAME/public_html`. Leave it empty and the folder one level above
  WordPress is used, which is right for the layout in step 1.

**If you skip it:** the published pages carry whatever address WordPress
happens to be installed at. On a site built on somebody's own machine that
means every photograph, the sharing image, the data feed and the enquiry
endpoint point at a computer no visitor can reach. The pages look fine in the
editor and are broken for everybody else.

= 3. Turn publishing on, then press Republish =

Tick **Write the public page when I save**, save, then press **Republish**
once and confirm it reports success. That writes `index.html`, every car page,
`sitemap.xml`, `robots.txt` and a copy of the stylesheets and scripts into the
folder from step 2.

**If you skip it:** there is no public site at all, or an old one that never
changes again. The editor will not warn you that saving does nothing.

**Why the pages do not open from inside WordPress.** Certified, Sell your car,
About, Stock and Contact are written as files by Republish. They are not
WordPress pages, and WordPress does not know their addresses: opening
`/certified/` while logged into the admin gives "page not found", while the
same address on the live site works perfectly. That is expected, and it has
always been true of Contact.

The reason is the layout in step 1. Visitors are served plain files from
`public_html`; WordPress lives in `public_html/cms` and is the editor, not the
site. A car's page is the one exception — WordPress does know about those,
because the cars are stored in WordPress — which is why a car page opens from
the admin and these do not.

What follows from it, and is worth knowing before it puzzles somebody:

* **There is no preview.** Switching a page on and saving does not show it
  anywhere until Republish has run. Check it on the public address.
* **A page you have switched on but not republished does not exist.** It is
  not in the sitemap either, which is deliberate: a sitemap entry for a page
  that is not there sends a search engine to a 404 on your own instructions.
* **Turning a page off removes it.** Switching the page off and republishing
  deletes its folder, takes it out of the sitemap and stops the homepage
  linking to it. Off means off: somebody who kept the address gets your host's
  "not found", not the old page. Switch it back on, republish, and it returns
  exactly as it was — the page is written from the settings every time, so
  nothing is lost by turning it off for a while.

  One exception, and it is deliberate. If anything other than the page's own
  `index.html` is sitting in that folder — a file you put there, something
  another tool wrote — **nothing is deleted at all**, and the page stays up.
  A publish that quietly removed somebody else's work would be a far worse
  fault than a page that lingers. If a page will not go away, look in its
  folder for a file that does not belong to it.

= 4. Add the cron job, and switch WordPress's own timer off =

This is the step most likely to be skipped and the most damaging to skip.

Writing the public page is carried by WordPress's scheduled tasks, and those
are not a clock: WordPress only checks whether anything is due when somebody
asks it for a page. Visitors here are served plain HTML and never ask
WordPress for anything — so on a working, busy website, nothing whatsoever
triggers it except somebody logging into the admin.

In cPanel, **Advanced → Cron Jobs**, under "Add New Cron Job":

* **Common Settings** — choose "Every 5 Minutes (*/5 * * * *)"
* **Command** — paste this, with your own account name and path:

    /usr/local/bin/php -q /home/USERNAME/public_html/cms/wp-cron.php >/dev/null 2>&1

The exact line for your installation, with the path already filled in, is
printed on the Landing Page screen whenever something is waiting to be
published. The `>/dev/null 2>&1` at the end is what stops cPanel emailing you
every five minutes.

Then edit `wp-config.php` and add this above the "stop editing" line:

    define( 'DISABLE_WP_CRON', true );

**If you skip it:** a car added by a salesperson can sit unpublished for hours
— until an administrator happens to open a screen. The site shows the old
stock the whole time and nothing appears to be wrong. The admin will tell you
when this has happened, and offers a Publish now button, but that is a
symptom being managed rather than the problem being fixed.

= 5. Make the site able to send email =

Two separate things, both needed.

**The mailbox it sends from.** Enquiry emails are sent from
`no-reply@yourdomain.com` — built from your domain automatically, with the
visitor's own address put in Reply-To so pressing Reply in your mail client
reaches the customer. Create that mailbox (or at least that address) in cPanel
→ **Email Accounts**. A From address the domain does not actually host is what
SPF and DMARC exist to reject.

**A real sending route.** Shared hosting very often cannot send mail reliably
on its own. Install an SMTP plugin and point it at a real mailbox on this
domain.

Then set **where enquiries go**: Landing Page → the contact section →
"Send enquiries to". Send yourself a test through the form on the live site
and confirm it arrives.

**If you skip it:** enquiries are still saved — they appear under Landing Page
→ Enquiries and nothing is lost — but nobody is told a customer got in touch.
The visitor is thanked as normal, so the failure is invisible from outside.
The Enquiries screen has an Emailed column, and a warning appears by itself
once three in a row have failed.

= 6. Settings → Reading → Search Engine Visibility =

Leave "Discourage search engines from indexing this site" **unticked**.

Being straight about this one: on this set-up it matters less than it usually
does. The published pages are plain files written by this plugin, and the
`robots.txt` it writes always says `Allow: /` — that tick box is a WordPress
setting and the published site never reads it. It affects the WordPress
install at `/cms`, which is not where your visitors are.

**If you skip it:** most likely nothing. Untick it anyway — it costs one
click, and it stops being harmless the moment anything is ever served by
WordPress itself.

= 7. Counting visitors (optional, but do it before you change anything else) =

Nothing here counts visitors until you set this up, and until you do there is
no way to tell which cars people look at or where they give up. It is worth
doing FIRST rather than last, because a month of figures collected before you
start changing the site is what tells you whether a change helped.

Two services are offered. **Google Analytics** is free and the one everybody
has heard of; it sets cookies, so if you need a cookie banner it is this that
makes it necessary. **Plausible** is paid (a few dollars a month), sets no
cookies and needs no banner. The steps below are for Google Analytics because
it is the one people ask for; if you pick Plausible instead, you only need the
domain — `veslamotors.com` — and there is nothing to create beyond the account.

*Google renames these screens from time to time. If a button is worded
slightly differently from what is written here, the step is still the same
one.*

**Creating the property**

1. Go to `analytics.google.com` and sign in. Use a Google account **the
   business owns** — not a personal one, and not an employee's. Moving an
   Analytics property to a different account later is awkward, and losing
   access to it means losing the history.

2. Bottom left, click the gear icon: **Admin**.

3. Under the **Property** column, click **Create property** (on a brand new
   account you may land on this screen automatically).

4. **Property name:** `Vesla Motors`.
   **Reporting time zone:** United Arab Emirates (GMT+04:00).
   **Currency:** UAE Dirham (AED).
   Getting the time zone right matters more than it looks: it decides where
   one day ends and the next begins in every report you ever read.
   Click **Next**.

5. **Business details** — industry category and business size. Neither
   changes what is collected; they only set which suggested reports Google
   shows you. Click **Next**.

6. **Business objectives** — tick **Generate leads**. Click **Create**, then
   accept the terms of service when asked.

**Creating the data stream**

7. You are now asked to choose a platform. Choose **Web**.

8. **Website URL:** `https://veslamotors.com` — exactly the form that
   actually serves, matching what you put in step 2.
   **Stream name:** `Vesla Motors website`.
   Leave **Enhanced measurement** on: it records outbound clicks and file
   downloads without any extra work.
   Click **Create stream**.

9. The **Web stream details** panel opens. Near the top right is the
   **MEASUREMENT ID**. It looks like `G-ABCD123456`. Copy it.

   If you close this panel, it is always at:
   **Admin → Data collection and modification → Data streams →** click the
   stream.

   Take the MEASUREMENT ID (`G-…`), **not** the Stream ID (a plain number)
   and not the "Google tag ID" from the install instructions.

**Putting it into the site**

10. In WordPress: **Landing Page → Visitor statistics**.

11. **Which service:** Google Analytics.
    **Google Analytics measurement ID:** paste the `G-…` code.
    Leave both Plausible boxes empty.
    Press **Save changes**.

    The ID is checked when it is used. If it is not in Google's `G-` format
    nothing is written into the page at all — which is deliberate, because a
    mistyped ID otherwise loads, reports nothing and looks like it is working.

12. Press **Republish**. The published pages carry the counting code too, and
    they are not rewritten until you do.

**Checking it works**

13. In Google Analytics, go to **Reports → Realtime**.

14. Open `https://veslamotors.com` in a different browser, or on your phone
    off the office wi-fi. Within about half a minute Realtime should show
    **1 active user**, and the page you opened.

15. If it shows nothing after two minutes:
    * View the page source and search for `googletagmanager` — if it is not
      there, either the ID was rejected (check the `G-` format) or step 12
      has not been done.
    * An ad blocker on the machine you are testing from will block the
      request. That is also why the figures will always be a little lower
      than reality.

**If you skip it:** the site works exactly as it does now. You simply have no
idea what anybody does on it, and nothing to compare against later.

= 8. Check it actually worked =

Open the public address in a browser that is not logged in. Then:

* View the page source and search for `localhost` or a machine name. There
  should be none. Anything found means step 2 is wrong.
* Open a car page directly, e.g. `/cars/some-car-2021-604/`.
* Send an enquiry through the form and confirm the email arrives.
* Change something small, save, wait five minutes, and reload the public page
  without being logged in. If it changed, step 4 is working.

== Frequently Asked Questions ==

= I changed something and the page looks the same =

Press Save changes, then reload the page with Ctrl+F5 (Cmd+Shift+R on a Mac).
If your host has caching, or you use a caching plugin, clear that too.

= A car was saved hours ago and is still not on the website =

The cron job in step 4 of "Setting up on the live server" has not been set
up, or has stopped. Nothing is lost: open Landing Page and press Publish now,
then fix the cron job so it stops being a manual job.

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
