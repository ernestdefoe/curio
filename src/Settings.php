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
}
