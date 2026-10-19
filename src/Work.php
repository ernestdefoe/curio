<?php

namespace Ernestdefoe\Curio;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A title a look-up found, shared by every member who owns it.
 *
 * @property int $id
 * @property string $source
 * @property string $source_id
 * @property string $title
 * @property string|null $creator
 * @property int|null $year
 * @property string|null $cover
 * @property string|null $wikidata_id
 * @property array{developer?: list<string>, publisher?: list<string>, released?: string, platforms?: list<string>, genres?: list<string>}|null $facts
 * @property int $languages
 * @property Carbon $refreshed_at
 * @property Carbon $created_at
 * @property \Illuminate\Database\Eloquent\Collection<int, WorkText> $texts
 */
class Work extends AbstractModel
{
    protected $table = 'curio_works';

    public $timestamps = false;

    protected $casts = [
        'year' => 'integer',
        'languages' => 'integer',
        'facts' => 'array',
        'refreshed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return HasMany<WorkText, $this> */
    public function texts(): HasMany
    {
        return $this->hasMany(WorkText::class);
    }

    /** The text for $locale, falling back to English. */
    public function textFor(string $locale): ?WorkText
    {
        $base = explode('-', str_replace('_', '-', $locale))[0];

        return $this->texts->firstWhere('locale', $base) ?? $this->texts->firstWhere('locale', 'en');
    }
}
