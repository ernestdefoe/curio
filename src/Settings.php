<?php

namespace Ernestdefoe\Curio;

final class Settings
{
    public const UPC_KEY = 'ernestdefoe-curio.upcitemdb_key';

    public const DEEPL_KEY = 'ernestdefoe-curio.deepl_key';

    public const MAX_UPLOAD_KB = 'ernestdefoe-curio.max_upload_kb';

    public const IGDB_CLIENT_ID = 'ernestdefoe-curio.igdb_client_id';

    public const IGDB_CLIENT_SECRET = 'ernestdefoe-curio.igdb_client_secret';

    /** Offer the "Recently added" widget to Bespoke and FoF Forum Widgets. Off until an admin turns it on. */
    public const WIDGET = 'ernestdefoe-curio.widget';

    /** The /collections page: "tags" (Flarum's tags page look) or "covers" (cover tiles). */
    public const BROWSE_LAYOUT = 'ernestdefoe-curio.browse_layout';

    /** Where "My collections" appears: "both", "sidebar" (index sidebar), "menu" (account menu) or "none". */
    public const SHORTCUT = 'ernestdefoe-curio.shortcut';

    public const SHORTCUTS = ['both', 'sidebar', 'menu', 'none'];

    /** A cover of another shape than its frame: "blur" (whole, on a blurred copy), "plain" (whole, on a plain ground) or "crop". */
    public const COVER_FIT = 'ernestdefoe-curio.cover_fit';

    public const COVER_FITS = ['blur', 'plain', 'crop'];

    /** Leave types with nothing in them off the Collections page. Off: every switched-on type shows, like the Tags page. */
    public const HIDE_EMPTY = 'ernestdefoe-curio.hide_empty_types';
}
