<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'ernestdefoe-curio.view' => Group::GUEST_ID,
    'ernestdefoe-curio.create' => Group::MEMBER_ID,
    'ernestdefoe-curio.moderate' => Group::MODERATOR_ID,
]);
