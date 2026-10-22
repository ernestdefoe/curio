<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Members could already import and export their own collections: they still can, until an admin narrows it.
return Migration::addPermissions([
    'ernestdefoe-curio.import' => Group::MEMBER_ID,
    'ernestdefoe-curio.export' => Group::MEMBER_ID,
]);
