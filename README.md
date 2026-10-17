# Curio

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/curio.svg)](https://packagist.org/packages/ernestdefoe/curio)

A [Flarum 2](https://flarum.org) extension for showing off what members collect: games, vinyl, books, films, LEGO, cards, anything. Every member can build collections with photos and details, mark what they own, want or would trade, and scan a barcode to add an item. Titles and descriptions show in each reader's language.

![A collection](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/collection.jpg)

## Features

- **Collections on every profile.** A Collections tab with the member's collections, what they own, want and trade, and their wishlist.
- **A page for each collection.** It has a hero built from the member's own covers and a breakdown by platform or format, which you can click to filter. You can also filter by owned, wanted and open to trade, search inside the collection, sort, and switch between grid and list. The owner drags items into order with a mouse or a finger.
- **Items with photos and details.** Each item has up to six photos (re-encoded as WebP, with the phone's location data removed), the type's own details (platform, edition, region…), condition, the date it was acquired, notes, and a favourite star.
- **Look it up, or scan it.** Search by title, or scan or type a barcode or ISBN. The cover and details fill in:
  - **Books** come from Open Library and **albums** from Deezer and MusicBrainz. Neither needs a key.
  - **Films, TV and games** come through [Marquee](https://github.com/ernestdefoe/marquee) when it is installed.
  - **Any other barcode** (a game box, a Blu-ray) goes to UPCitemdb. The platform or format is read from the product's name, for example "Nintendo Switch", "PS5" or "4K".
- **The forum keeps every barcode it learns.** A box anyone has scanned is never looked up again, so UPCitemdb's free 100 lookups a day is plenty for most forums. When nobody knows a barcode, the first member fills it in by hand, and the next person who scans it gets their entry.
- **Every language the forum speaks.** Each looked-up title is matched to Wikidata, which supplies its name in every language, and its description comes from that language's Wikipedia, with credit. When Wikipedia has nothing in a language, an optional DeepL key translates the English description. The admin's own labels (types, details, choices) are edited per installed language. Curio ships in English and French.
- **Show it off.** From any item you own, start a discussion about it. The discussion opens with the item as its hero: the cover, details, condition, how many members have it, and the description in the reader's language. Everyone else gets "I have this too" and "Add to my wishlist" right there, and the item links back to its discussion.
- **Works with Marquee and Shelf.** With [Marquee](https://github.com/ernestdefoe/marquee) installed, its cards get **Add to collection**. With [Shelf](https://github.com/ernestdefoe/shelf), collections move to Curio: `php flarum curio:import-shelf` copies members' "In my collection" titles across once, and running it again adds nothing.
- **Wishlists and trades.** Mark items as wanted or open to trade. A wishlist can be hidden on its own.
- **"I have this too" / "Add to my wishlist"** on anyone's item copies it, cover and all, into one of your collections.
- **Who sees what.** Each collection can be seen by everyone, by members only, or by its owner only. The price paid is private and is only ever sent to the owner.
- **A Collections page** (`/collections`, with a sidebar link) has what the forum collects by type, recently added items, featured collections, and a search across every collection.
- **Types you can shape.** It starts with video games, vinyl & CDs, books, films & TV, board games, LEGO & models, trading cards, comics & manga, and other. You can add, rename, reorder or remove types, and choose each one's icon, colour, cover shape (portrait, square or landscape), look-up source and details.
- **A widget, if you use one.** "Recently added" is offered to [Bespoke](https://github.com/ernestdefoe/bespoke) and [FoF Forum Widgets](https://github.com/FriendsOfFlarum/forum-widgets-core).

![An item, in French](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/fr-item.jpg)

![Adding an item](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/add-details.jpg)

![A barcode, scanned](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/add-barcode.jpg)

![The Collections page](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/browse.jpg)

![Collection types in the admin panel](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/admin.jpg)

## Installation

```sh
composer require ernestdefoe/curio
php flarum migrate
```

## Permissions

| Permission | Default | What it allows |
|---|---|---|
| View collections | Everyone | Seeing public collections, and members-only ones when signed in |
| Create collections, add items and photos | Members | Their own collections, items and photos, and look-ups |
| Remove anyone's collections and feature them | Moderators | Moderation, and choosing the featured collections |

## Settings

- **UPCitemdb key** (optional). Without a key the forum gets 100 product barcode lookups a day. Every barcode it resolves is kept, so it never asks twice. [Get a key](https://www.upcitemdb.com/wp/docs/main/development/getting-started/).
- **DeepL key** (optional). Translates a description when Wikipedia has none in a language. Keys ending in `:fx` use DeepL's free plan.
- **Largest photo** (default 8 MB). Photos are resized and re-encoded, so what is stored is far smaller.

## Good to know

- **Lean.** On every page Curio costs one small script and three permission flags. Its pages and windows load only when they are opened. A collection's counts, cover preview and breakdown are stored with it, so cards and profiles never count rows. List pages make a fixed number of queries however many items they show, and the Collections page's counts are worked out every ten minutes and shared.
- **Look-ups are polite.** They run on the server behind the forum's CSRF protection and are limited per member. A service that is down is left alone for a minute. Covers are only downloaded from the known image hosts and are kept on the forum, so a dead link never blanks a shelf.
- **Descriptions stay fresh.** A daily task (`php flarum curio:refresh-texts`) re-reads titles and descriptions for the works that have gone longest without, a few at a time. A language installed later fills in the first time someone opens an item in it.
- **Scanning with the camera works in every current browser.** Chrome and Edge on Android, macOS and ChromeOS use their own built-in barcode reader. Everywhere else (Safari, Firefox, every iPhone and iPad browser, Chrome and Edge on Windows and Linux) a small reader (about 43 KB) downloads the first time someone taps Scan, and never with the forum's other pages. A typed barcode, or a USB or Bluetooth scanner, works in the same field.
- **Wikipedia descriptions** are CC BY-SA and are credited with a link on each item.
- **Limits:** 50 collections per member, 2,000 items per collection, 6 photos per item.

## Updating

```sh
composer update ernestdefoe/curio
php flarum migrate
php flarum cache:clear
```

## Links

- [Packagist](https://packagist.org/packages/ernestdefoe/curio)
- [GitHub](https://github.com/ernestdefoe/curio)
- [Marquee](https://github.com/ernestdefoe/marquee) (optional, for film, TV and game search)
