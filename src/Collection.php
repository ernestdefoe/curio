<?php

namespace Ernestdefoe\Curio;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One member's collection of one type.
 *
 * @property int $id
 * @property int $user_id
 * @property int $type_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $visibility
 * @property bool $wishlist_public
 * @property Carbon|null $featured_at
 * @property int $position
 * @property int $owned_count
 * @property int $wish_count
 * @property int $trade_count
 * @property list<string>|null $preview
 * @property array<string, int>|null $facets
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User|null $user
 * @property Type|null $type
 */
class Collection extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const VISIBILITIES = ['public', 'members', 'private'];

    public const MAX_PER_USER = 50;

    public const MAX_ITEMS = 2000;

    protected $table = 'curio_collections';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'type_id' => 'integer',
        'wishlist_public' => 'boolean',
        'featured_at' => 'datetime',
        'position' => 'integer',
        'owned_count' => 'integer',
        'wish_count' => 'integer',
        'trade_count' => 'integer',
        'preview' => 'array',
        'facets' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Type, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function isOwnedBy(User $actor): bool
    {
        return ! $actor->isGuest() && $this->user_id === $actor->id;
    }

    /** Whether $actor may see the wanted items in it. */
    public function showsWishlistTo(User $actor): bool
    {
        return $this->wishlist_public || $this->isOwnedBy($actor);
    }
}
