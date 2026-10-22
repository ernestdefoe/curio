# Curio

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/curio.svg)](https://packagist.org/packages/ernestdefoe/curio)

A [Flarum 2](https://flarum.org) extension for showing off what members collect: games, vinyl, books, films, LEGO, cards, anything. Every member can build collections with photos and details, mark what they own, want or would trade, and scan a barcode to add an item. Titles and descriptions show in each reader's language.

![A collection](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/collection.jpg)

## Features

- **Collections on every profile.** A Collections tab with the member's collections, what they own, want and trade, and their wishlist.
- **A page for each collection.** It has a hero built from the member's own covers and a breakdown by platform or format, which you can click to filter. You can also filter by owned, wanted and open to trade, search inside the collection, sort, and switch between grid and list. The owner drags items into order with a mouse or a finger.
- **Items with photos and details.** Each item has up to six photos (re-encoded as WebP, with the phone's location data removed), the type's own details (platform, edition, region…), condition, the date it was acquired, notes, a favourite star, and the owner's **rating** (five stars, in half stars). Collections sort by rating, and the stars show on the grid, in the list and on the item.
- **Look it up, or scan it.** Search by title, or scan or type a barcode or ISBN. The cover and details fill in:
  - **Books** come from Open Library and **albums** from Deezer and MusicBrainz. Neither needs a key.
  - **Games** come from **IGDB** when you add a free Twitch app's client ID and secret: cover, developer, publisher, release date, platforms and genres (genre names are translated). Each result shows the platforms it came out on; click one to add that version with its platform chosen. When the game is on only one platform the forum knows, that platform is filled in too. Without the keys, games come through Marquee as before (its results show their platforms too).
  - **Trading cards** come from free sources with no key, chosen per type: **Pokémon** from [TCGdex](https://tcgdex.dev) (card names, sets and rarities in the reader's language where TCGdex has it), **Magic: The Gathering** from [Scryfall](https://scryfall.com/docs/api), **Yu-Gi-Oh!** from [YGOPRODeck](https://ygoprodeck.com/api-guide/), or all three at once. A **Card language** menu beside the search picks which printings are searched: every language TCGdex has (including Japanese, Korean, Chinese, Thai and Indonesian, with their own sets and promos like `201/SV-P`) and every language Scryfall has; a Pokémon's English name finds its cards in any language. Yu-Gi-Oh! stays in English (YGOPRODeck's data). Each result shows its set and number; a Yu-Gi-Oh! card offers its printings to pick from. The set, card number, rarity and language fill in.
  - **Films and TV** (and games, without IGDB keys) come through [Marquee](https://github.com/ernestdefoe/marquee) when it is installed.
  - **Any other barcode** (a game box, a Blu-ray) goes to UPCitemdb. The platform or format is read from the product's name, for example "Nintendo Switch", "PS5" or "4K".
- **The forum keeps every barcode it learns.** A box anyone has scanned is never looked up again, so UPCitemdb's free 100 lookups a day is plenty for most forums. When nobody knows a barcode, the first member fills it in by hand, and the next person who scans it gets their entry.
- **Every language the forum speaks.** Each looked-up title is matched to Wikidata, which supplies its name in every language, and its description comes from that language's Wikipedia, with credit. When Wikipedia has nothing in a language, an optional DeepL key translates the English description. The admin's own labels (types, details, choices) are edited per installed language. Curio ships in English and French.
- **Show it off.** From any item you own, start a discussion about it. The discussion opens with the item as its hero: the cover, details, condition, how many members have it, and the description in the reader's language. Everyone else gets "I have this too" and "Add to my wishlist" right there, and the item links back to its discussion.
- **Works with Marquee and Shelf.** With [Marquee](https://github.com/ernestdefoe/marquee) installed, its cards get **Add to collection**. With [Shelf](https://github.com/ernestdefoe/shelf), collections move to Curio: `php flarum curio:import-shelf` copies members' "In my collection" titles across once, and running it again adds nothing.
- **Import and export.** A collection downloads as a CSV file, and a Curio export, a Discogs collection export, or a Goodreads or StoryGraph library export imports into one. Ratings come across from all four. Each has its own permission.
- **Wishlists and trades.** Mark items as wanted or open to trade. A wishlist can be hidden on its own.
- **"I have this too" / "Add to my wishlist"** on anyone's item copies it, cover and all, into one of your collections.
- **Who sees what.** Each collection can be seen by everyone, by members only, or by its owner only. The price paid is private and is only ever sent to the owner.
- **A Collections page** (`/collections`, with a sidebar link) has what the forum collects by type, recently added items (**Owned**, **Wishlist** and **Open to trade**, each its own list), the **latest changes** (**Status**: who marked what as completed or in progress; **Ratings**: who rated what, in stars), featured collections, and a search across every collection. By default the types are drawn exactly like Flarum's own Tags page (coloured tiles, with the latest collection along the foot); **cover tiles** and **compact tabs** (one row of small buttons, filled with the type's colour or with it on the icon and name only) are the other choices in the settings. Signed-in members get **Add an item** right there, which asks which collection (or makes a new one).
- **One click to your own.** Members get **My collections** in the forum sidebar and in their account menu.
- **Types you can shape.** It starts with video games, vinyl & CDs, books, films & TV, board games, LEGO & models, trading cards, comics & manga, and other. You can add, rename, reorder or remove types, and choose each one's icon, colour, cover shape (portrait, square or landscape), look-up source and details.
- **A widget, if you want one.** "Recently added" can be offered to [Bespoke](https://github.com/ernestdefoe/bespoke) and [FoF Forum Widgets](https://github.com/FriendsOfFlarum/forum-widgets-core). It is off until you turn it on in Curio's settings; with FoF it then shows in FoF's layout editor, where it can be moved or removed.

![An item, in French](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/fr-item.jpg)

![Adding an item](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/add-details.jpg)

![A game looked up, with the platforms each result came out on](https://raw.githubusercontent.com/ernestdefoe/curio/main/.github/screenshots/add-lookup.jpg)

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
| Import spreadsheets into their collections | Members | Importing a CSV file into their own collection |
| Export their collections as spreadsheets | Members | Downloading their own collection as a CSV file |
| Remove anyone's collections and feature them | Moderators | Moderation, and choosing the featured collections |

## Settings

- **Collections page layout.** Like the Tags page (the default), cover tiles, or compact tabs.
- **Colour of the compact tabs.** The type's colour as the tab's background (the default), or on its icon and name only.
- **Show "Latest changes"** (on by default). The row with members' latest progress and ratings, from collections the reader can see.
- **Offer the "Recently added" widget** (off by default).
- **IGDB client ID and secret** (optional). Game look-ups on IGDB. [Create a Twitch app](https://dev.twitch.tv/console/apps/create) (any name, OAuth redirect URL `http://localhost`, category Application Integration), then copy its Client ID and make a New Secret. The forum keeps its Twitch token until a day before it expires, keeps searches for a day, and sends IGDB at most four requests a second and 2,000 a day.
- **UPCitemdb key** (optional). Without a key the forum gets 100 product barcode lookups a day. Every barcode it resolves is kept, so it never asks twice. [Get a key](https://www.upcitemdb.com/wp/docs/main/development/getting-started/).
- **DeepL key** (optional). Translates a description when Wikipedia has none in a language. Keys ending in `:fx` use DeepL's free plan.
- **Largest photo** (default 8 MB). Photos are resized and re-encoded, so what is stored is far smaller.

## Good to know

- **Lean.** On every page Curio costs one small script and three permission flags. Its pages and windows load only when they are opened. A collection's counts, cover preview and breakdown are stored with it, so cards and profiles never count rows. List pages make a fixed number of queries however many items they show, and the Collections page's counts are worked out every ten minutes and shared.
- **Look-ups are polite.** They run on the server behind the forum's CSRF protection and are limited per member. A service that is down is left alone for a minute. Covers are only downloaded from the known image hosts and are kept on the forum, so a dead link never blanks a shelf.
- **Descriptions stay fresh.** A daily task (`php flarum curio:refresh-texts`) re-reads titles and descriptions for the works that have gone longest without, a few at a time. A language installed later fills in the first time someone opens an item in it.
- **Scanning with the camera works in every current browser.** Chrome and Edge on Android, macOS and ChromeOS use their own built-in barcode reader. Everywhere else (Safari, Firefox, every iPhone and iPad browser, Chrome and Edge on Windows and Linux) a small reader (about 43 KB) downloads the first time someone taps Scan, and never with the forum's other pages. A typed barcode, or a USB or Bluetooth scanner, works in the same field.
- **Who sees the lists.** Recently added, wishlists and latest changes only ever show items from collections the reader may open. Wanted items show only where their owner left the wishlist visible. Ratings are shown to whoever can see the item, as on the item itself. Imported rows do not count as changes, so an import never floods the list.
- **Card look-ups keep within each service's rules.** Searches are kept for a day, each service gets at most 8–10 requests a second and 5,000 a day from the forum, and every request names Curio. YGOPRODeck asks sites not to hotlink its pictures, so Yu-Gi-Oh! results show none until the card is added, when its picture is downloaded once and served by the forum (like every other cover).
- **Wikipedia descriptions** are CC BY-SA and are credited with a link on each item.
- **Limits:** 50 collections per member, 2,000 items per collection, 6 photos per item. An import file is at most 2 MB, fills a collection up to its 2,000 items, and a member can import five times an hour; covers and descriptions for imported rows are found in the background, 25 every five minutes.

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
