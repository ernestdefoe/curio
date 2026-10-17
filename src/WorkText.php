<?php

namespace Ernestdefoe\Curio;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $work_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $description
 * @property string|null $description_source
 * @property string|null $source_url
 * @property Carbon $fetched_at
 */
class WorkText extends AbstractModel
{
    protected $table = 'curio_work_texts';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $casts = ['work_id' => 'integer', 'fetched_at' => 'datetime'];
}
