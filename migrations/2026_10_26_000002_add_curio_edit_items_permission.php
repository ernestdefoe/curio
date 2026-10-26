<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Moderators may add, change and remove items in anyone's collection: to help a member, or to take down what breaks the rules.
return Migration::addPermissions([
    'ernestdefoe-curio.editItems' => Group::MODERATOR_ID,
]);
