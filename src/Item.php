<?php

namespace Ernestdefoe\Curio;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing in a collection.
 *
 * @property int $id
 * @property int $collection_id
 * @property int $user_id
 * @property int|null $work_id
 * @property string $title
 * @property string|null $subtitle
 * @property int|null $year
 * @property string $status
 * @property string|null $condition
 * @property Carbon|null $acquired_on
 * @property array<string, mixed>|null $fields
 * @property string|null $facet
 * @property string|null $notes
 * @property list<string>|null $photos
 * @property string|null $barcode
 * @property string|null $price_paid
 * @property string|null $price_currency
 * @property bool $favourite
 * @property int|null $rating
 * @property string|null $progress
 * @property int $position
 * @property int|null $discussion_id
 * @property bool $needs_match
 * @property Carbon|null $progress_changed_at
 * @property Carbon|null $notes_updated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection|null $collection
 * @property Work|null $work
 * @property User|null $user
 */
class Item extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const STATUSES = ['owned', 'wishlist', 'trade'];

    public const CONDITIONS = ['sealed', 'mint', 'very_good', 'good', 'fair', 'poor'];

    /** Where the owner is with it: not started, in progress, completed. */
    public const PROGRESS = ['pending', 'in_progress', 'completed'];

    public const MAX_PHOTOS = 6;

    protected $table = 'curio_items';

    public $timestamps = true;

    protected $casts = [
        'collection_id' => 'integer',
        'user_id' => 'integer',
        'work_id' => 'integer',
        'year' => 'integer',
        'acquired_on' => 'date',
        'fields' => 'array',
        'photos' => 'array',
        'favourite' => 'boolean',
        'rating' => 'integer',
        'needs_match' => 'boolean',
        'position' => 'integer',
        'progress_changed_at' => 'datetime',
        'notes_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The picture shown for it: the member's first photo, else the looked-up cover. */
    public function coverPath(): ?string
    {
        return $this->photos[0] ?? $this->work?->cover;
    }
}
