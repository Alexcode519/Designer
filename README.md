# Snippets Album Designer

A WordPress plugin that lets Snippets Photography clients upload their photos and design their own album spreads online. When the client approves, you download print-ready files and send them to your printer.

No monthly fees and no third-party service: everything runs on your own WordPress site.

## What it does

**For the client** (works on phone, tablet and computer, from any country):

- Opens a private link you send them. No account or app needed.
- Uploads photos (JPEG or PNG). Large photos are sent in small pieces, so the hosting upload limit doesn't matter.
- Picks a layout for each spread from 10 options, then drags photos into the spaces (on a phone: tap a photo, then tap a space).
- Taps a placed photo to zoom it and drag it into position.
- Adds, deletes and reorders spreads, within the minimum and maximum you set.
- Sees a "Low quality" warning when a photo is enlarged too much to print sharply.
- Everything saves automatically. Clicking **Approve for printing** locks the album.

**For you:**

- Create one album per client under **Client albums > Add client album**: name, email, size and number of spreads.
- Get an email when a client approves. The client gets a confirmation email too.
- Click **Build print files** to get a PDF and a ZIP of JPEG spreads at 300 dpi, with 3 mm bleed on every edge. The PDF has the trim and bleed boxes set.
- Click **Unlock for changes** if a client needs to edit an approved album.

## Install

1. On GitHub, open this repository and choose **Code > Download ZIP**.
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the ZIP and click **Activate**.
3. The plugin creates an **Album Designer** page with the `[snippets_album_designer]` shortcode. You can restyle it in Elementor, but keep the shortcode (use Elementor's Shortcode widget). The page is hidden from search engines.
4. Under **Client albums > Settings**, check the album sizes, bleed and the email address for approvals.

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

- Covers (spreads only for now)
- Text on pages
- Background colours other than white
- Taking payment for upgrades or extra spreads

## Developer notes

- PHP 7.4+, WordPress 6.2+, with the GD image library (standard on most hosts). No build step.
- `includes/render.php` and `assets/editor.js` share the crop logic. If you change one, change the other.
- Filters: `sad_layouts` adds or changes spread layouts. `sad_chunk_size` changes the upload piece size.
