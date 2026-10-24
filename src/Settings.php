<?php

namespace Ernestdefoe\Curio;

final class Settings
{
    public const UPC_KEY = 'ernestdefoe-curio.upcitemdb_key';

    public const DEEPL_KEY = 'ernestdefoe-curio.deepl_key';

    public const MAX_UPLOAD_KB = 'ernestdefoe-curio.max_upload_kb';

    public const IGDB_CLIENT_ID = 'ernestdefoe-curio.igdb_client_id';

    public const IGDB_CLIENT_SECRET = 'ernestdefoe-curio.igdb_client_secret';

    /** The forum's own eBay app (optional): barcodes the free services miss are asked of eBay's Browse API. */
    public const EBAY_APP_ID = 'ernestdefoe-curio.ebay_app_id';

    public const EBAY_CERT_ID = 'ernestdefoe-curio.ebay_cert_id';

    /** An eBay marketplace id (EBAY_FR…); blank = from the forum's language. */
    public const EBAY_MARKETPLACE = 'ernestdefoe-curio.ebay_marketplace';

    /** Optional paid barcode services, asked after the free ones and before eBay. */
    public const BARCODELOOKUP_KEY = 'ernestdefoe-curio.barcodelookup_key';

    public const EANSEARCH_TOKEN = 'ernestdefoe-curio.eansearch_token';

    /** Barcode scanning and look-ups. On by default; off hides the Scan button and nothing is asked about a barcode. */
    public const BARCODES = 'ernestdefoe-curio.barcodes';

    /** Offer the "Recently added" widget to Bespoke and FoF Forum Widgets. Off until an admin turns it on. */
    public const WIDGET = 'ernestdefoe-curio.widget';

    /** The /collections page: "tags" (Flarum's tags page look), "covers" (cover tiles) or "compact" (a row of small tabs). */
    public const BROWSE_LAYOUT = 'ernestdefoe-curio.browse_layout';

    public const BROWSE_LAYOUTS = ['tags', 'covers', 'compact'];

    /** Compact tabs: the type's colour as the tab's "background", or on its icon and name ("text"). */
    public const TYPE_COLOR = 'ernestdefoe-curio.type_color';

    /** The Collections page's "Latest changes" row (progress and notes). On by default. */
    public const LATEST_CHANGES = 'ernestdefoe-curio.latest_changes';

    /** Where "My collections" appears: "both", "sidebar" (index sidebar), "menu" (account menu) or "none". */
    public const SHORTCUT = 'ernestdefoe-curio.shortcut';

    public const SHORTCUTS = ['both', 'sidebar', 'menu', 'none'];

    /** A cover of another shape than its frame: "blur" (whole, on a blurred copy), "plain" (whole, on a plain ground) or "crop". */
    public const COVER_FIT = 'ernestdefoe-curio.cover_fit';

    public const COVER_FITS = ['blur', 'plain', 'crop'];

    /** Leave types with nothing in them off the Collections page. Off: every switched-on type shows, like the Tags page. */
    public const HIDE_EMPTY = 'ernestdefoe-curio.hide_empty_types';
}
