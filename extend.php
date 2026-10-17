<?php

use Ernestdefoe\Curio\Access;
use Ernestdefoe\Curio\Api;
use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Console\RefreshTexts;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Search;
use Ernestdefoe\Curio\Settings;
use Ernestdefoe\Curio\Storage\Images;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\Search\Database\DatabaseSearchDriver;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/collections', 'curio.browse')
        ->route('/collections/{id:\d+}[-{slug}]', 'curio.collection')
        ->route('/u/{username}/collections', 'user.curio'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Filesystem())
        ->disk(Images::DISK, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/curio",
            'url' => $url->to('forum')->path('assets/curio'),
            'visibility' => 'public',
        ]),

    (new Extend\Settings())
        ->default(Settings::MAX_UPLOAD_KB, 8192),

    new Extend\ApiResource(Api\TypeResource::class),
    new Extend\ApiResource(Api\CollectionResource::class),
    new Extend\ApiResource(Api\ItemResource::class),
    new Extend\ApiResource(Api\WorkResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewCurio')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-curio.view')),
            Schema\Boolean::make('canCreateCurio')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-curio.create')),
            Schema\Boolean::make('canModerateCurio')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-curio.moderate')),
        ]),

    (new Extend\Routes('api'))
        ->post('/curio/lookup', 'curio.lookup', Api\LookupController::class)
        ->get('/curio/overview', 'curio.overview', Api\OverviewController::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Collection::class, Search\CollectionSearcher::class)
        ->addFilter(Search\CollectionSearcher::class, Search\Filter\CollectionUserFilter::class)
        ->addFilter(Search\CollectionSearcher::class, Search\Filter\CollectionTypeFilter::class)
        ->addFilter(Search\CollectionSearcher::class, Search\Filter\FeaturedFilter::class)
        ->addSearcher(Item::class, Search\ItemSearcher::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\ItemCollectionFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\ItemUserFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\StatusFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\FacetFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\TitleFilter::class),

    (new Extend\ModelVisibility(Collection::class))
        ->scope(Access\ScopeCollectionVisibility::class),
    (new Extend\ModelVisibility(Item::class))
        ->scope(Access\ScopeItemVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Collection::class, Access\CollectionPolicy::class)
        ->modelPolicy(Item::class, Access\CollectionPolicy::class),

    (new Extend\Console())
        ->command(RefreshTexts::class)
        ->schedule(RefreshTexts::class, function (Event $event): void {
            $event->daily()->withoutOverlapping();
        }),
];
