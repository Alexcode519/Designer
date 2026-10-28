# Snippets Album Designer

A WordPress plugin that lets Snippets Photography clients upload their photos and design their own album spreads online. When the client approves, you download print-ready files and send them to your printer.

No monthly fees and no third-party service: everything runs on your own WordPress site.

## What it does

**For the client** (works on phone, tablet and computer, from any country):

- Starts their own album on the Album Designer page: enters their names, email, album size and cover type, and gets their private link by email. (Or you create the album for them and send the link.)
- Can ask for their link to be emailed again with "Already started?" on the same page.
- Opens their private link. No account or app needed.
- Uploads photos (JPEG or PNG). Large photos are sent in small pieces, so the hosting upload limit doesn't matter.
- Lays out each spread with photo boxes they can place anywhere: drag a box to move it, pull its corner or edge handles to resize it (the size shows in mm), turn it with the round handle, and stack boxes over each other (bring forward, send back). Boxes snap to the page edges, the middle fold, the safe area and other boxes.
- Starts a spread from one of 13 layouts (up to 12 photos per spread), then changes anything.
- Drags photos from the photo panel (always beside the spread) onto a box to fill it, or onto an empty part of the spread to add a new box. Photos can also be dropped straight from their computer. On a phone: tap a photo, then tap a box or an empty spot.
- Chooses per box whether the photo fills the box (cropped; zoom with the mouse wheel, + and − or the slider, and "Move photo in box" to reposition it) or shows whole. Swaps the photos of two boxes, duplicates or deletes boxes.
- Sees red trim lines (anything outside is cut off) and green safe-area lines (keep faces inside) on every spread.
- Sees the cover as the first spread (back cover left, front cover right) and can change it there: a bound cover such as leather or linen in a choice of colours, shown with stitching, or a printed photo cover (hard or soft) they design like a spread. Auto-fill puts the first photo on an empty printed cover.
- Sees a tick and "Spread N" (or "Cover") on photos already used.
- Undoes and redoes changes (buttons or Ctrl+Z / Ctrl+Y). Arrow keys nudge the selected box by 1 mm (10 mm with Shift); Delete removes it.
- Uses **Auto-fill** to lay out all their photos (or only the ones they select) in one click, in one of three styles: Classic (framed), Bold (edge to edge) or Story (more photos per spread). They can change anything afterwards.
- Adds, deletes and reorders spreads, within the minimum and maximum you set.
- Sees a warning on a photo, and under the spread, when it is enlarged too much to print sharply.
- Adds text with **+ Text**: six bundled fonts (serif, fine, clean, modern, two scripts), size, colour, alignment and rotation. Side handles change the width and re-wrap the words; corner handles make the text bigger or smaller. The print file uses the same fonts and line breaks.
- Sets a background colour on any spread or printed cover (or on every spread at once).
- Leaves notes on any spread or the cover for you, and sees your notes and replies there. Spreads with open notes show a badge.
- Turns the pages in **Preview book**, a full-screen flip-through of the cover and every spread. The first time they approve, the preview opens so they look through the whole album before approving at the end.
- Sees the number of spreads and pages, the cover, and any extras with prices (extra spreads beyond those included, cover upgrades). Nothing is charged.
- Works in a dark editor, so the photos stand out, with three views: **List** (every spread one under the other; the one being edited is live, click any other to edit it), **Grid** (the whole album small; drag spreads to reorder, duplicate or delete) and **Preview** (the album as it will print, without guides).
- Everything saves automatically. Clicking **Approve for printing** locks the album.

**For you:**

- See every client under **Client albums**: names, email, status, size, photos, spreads and when they last worked on it. Sort by **Last change** to see who's active.
- Get an email when a client starts an album and when they approve. The client gets a confirmation email too.
- Optionally set an **access code** in Settings so only clients you give it to can start an album.
- Create an album yourself under **Client albums > Add client album** if you prefer, and load the client's photos into it from the **Photos** box on the album page, so they only have to design.
- Read and answer the client's notes in the **Notes on spreads** box (the client gets an email; you get one when they leave a note, at most one per 15 minutes per album). Open notes show in the album list.
- Clients who have not finished get a friendly reminder email after a set number of days without changes (Settings > Reminders; default 7 days, up to 2 reminders).
- Click **Build print files** to get a PDF and a ZIP of JPEG spreads at 300 dpi, with 3 mm bleed on every edge. The PDF has the trim and bleed boxes set. A printed photo cover comes first, sized from the printer specs in Settings (back cover, spine and front cover plus the wrap); for leather and linen covers the album page and the approval email show the material and colour to order.
- Click **Unlock for changes** if a client needs to edit an approved album.

## Install

1. On GitHub, open this repository and choose **Code > Download ZIP**.
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the ZIP and click **Activate**.
3. The plugin creates an **Album Designer** page with the `[snippets_album_designer]` shortcode. You can restyle it in Elementor, but keep the shortcode (use Elementor's Shortcode widget). The page is hidden from search engines.
4. Under **Client albums > Settings**, check the album sizes, bleed, default number of spreads, access code and the email address for notifications.
5. Link to the Album Designer page from wherever you send clients (an email, your booking confirmation or a button on a private page).

To update the plugin later, upload the new ZIP the same way and choose **Replace current with uploaded**. Albums and photos are kept.

## Printer specs, reminders and prices

Under **Client albums > Settings**:

- **Spreads come in steps of**: e.g. 2 if your printer only binds an even number of spreads. Clients cannot approve until the count fits, and auto-fill rounds up.
- **Printed cover**: the wrap (mm past the trim on each edge, bleed included) and the spine width (a base plus mm per spread). Leave at 0 to make the cover the same size as a spread.
- **Reminders**: days without changes before a reminder, and how many to send. 0 days turns them off.
- **Extras and prices**: currency, spreads included and the price per extra spread. Add an upgrade price to a cover type as a third part, e.g. `Leather | Black #1c1a19 | 800`. Prices at 0 are hidden.

## Cover types

By default clients do not see the cover at all: it is left out of the start form, the editor and the print files, and you design or order covers yourself. To let clients choose and design the cover, tick **Clients design the cover** under Settings > Cover.

Under **Client albums > Settings > Cover types**, one per line:

- `Hardcover photo | printed` for a cover printed with the client's photos.
- `Leather | Black #1c1a19, Tan #b0835a` for a bound cover in those colours.

Names with linen, fabric, cloth, canvas, velvet, silk or buckram get a woven look in the editor; other bound covers look like leather.

## Album sizes

Six standard sizes are included: 8x8, 10x10 and 12x12 square, 10x8 and 12x8 landscape, and 8x10 portrait. To add more, go to **Client albums > Settings** and add one line per size:

```
Name | page width mm | page height mm
```

Use your printer's trimmed **page** size (one side of the spread, without bleed). The plugin doubles the width for the spread and adds the bleed.

## Sending an album to a client

1. **Client albums > Add client album**. Enter the album name, client name and email, size, and the minimum and maximum spreads.
2. Click **Publish**. Copy the **Client link** and email it to the client.
3. When they approve, you'll get an email. Open the album and click **Build print files**.
4. Download the PDF or the JPEG ZIP and send it to your printer.

## Hosting notes

- Photos are kept in `wp-content/uploads/snippets-albums/`, outside the Media Library. Deleting an album permanently deletes its photos.
- A 40-photo wedding album uses roughly 0.5 to 1 GB of hosting space. Delete albums once they're printed and delivered.
- Print files are built one spread at a time. A 12x12 spread at 300 dpi needs about 150 MB of PHP memory. If building fails with a memory message, lower the export resolution in Settings to 250 dpi, or ask your host to raise the PHP memory limit.
- Client photos are personal information under POPIA (and GDPR for European clients). Mention in your privacy policy that album photos are stored on your site until the album is printed.

## Not in this first version

- Image adjustments such as borders, brightness and opacity
- Taking payment for upgrades or extra spreads

## Developer notes

- PHP 7.4+, WordPress 6.2+, with the GD image library (standard on most hosts). No build step.
- `includes/render.php` and `assets/editor.js` share the crop and rotation logic. If you change one, change the other.
- Designs saved by version 0.2 and earlier (fixed layout spaces) are converted to photo boxes automatically when opened.
- Printed covers use `sad_mm_geometry()` (PHP) and `geo()` (JS): back cover + spine + front cover, with the cover wrap as bleed. The editor saves the spine and wrap it laid the cover out with (`cover.spec`) and moves the boxes when Settings change.
- Text: the editor wraps lines by measuring with the bundled fonts and saves them; `sad_draw_text()` draws the same lines on the same baselines with FreeType. Fonts are in `assets/fonts` (SIL Open Font License); `sad_fonts` filters the list.
- Reminders run on WP-Cron (`sad_daily`). On a quiet site, WP-Cron only runs when someone visits; a real cron job calling `wp-cron.php` makes them punctual.
- Filters: `sad_cover_materials` changes the cover types and colours after they are read from Settings. `sad_layouts` adds or changes spread layouts. `sad_templates` adds or changes auto-fill styles. `sad_chunk_size` changes the upload piece size.
