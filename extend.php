<?php

use Ernestdefoe\Curio\Access;
use Ernestdefoe\Curio\Api;
use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Console\ImportShelf;
use Ernestdefoe\Curio\Console\MatchWorks;
use Ernestdefoe\Curio\Console\RefreshTexts;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Search;
use Ernestdefoe\Curio\Settings;
use Ernestdefoe\Curio\Storage\Images;
use Ernestdefoe\Curio\Listener\LinkShownItem;
use Ernestdefoe\Curio\Notification\NewItemsBlueprint;
use Ernestdefoe\Curio\Notification\ProgressChangedBlueprint;
use Ernestdefoe\Curio\Notification\RatingChangedBlueprint;
use Ernestdefoe\Curio\Notification\WishlistAddedBlueprint;
use Ernestdefoe\Curio\Notification\WishlistMatchBlueprint;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Started;
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
        ->default(Settings::MAX_UPLOAD_KB, 8192)
        ->default(Settings::WIDGET, false)
        ->default(Settings::BROWSE_LAYOUT, 'tags')
        ->default(Settings::RANKING, 'collections')
        ->default(Settings::SHORTCUT, 'both')
        ->default(Settings::COVER_FIT, 'blur')
        ->default(Settings::HIDE_EMPTY, false)
        ->default(Settings::TYPE_COLOR, 'background')
        ->default(Settings::LATEST_CHANGES, true)
        ->default(Settings::BARCODES, true)
        // A boolean: whether to offer the "Recently added" widget to Bespoke / FoF Forum Widgets.
        ->serializeToForum('curioWidget', Settings::WIDGET, 'boolval')
        ->serializeToForum('curioBarcodes', Settings::BARCODES, 'boolval')
        ->serializeToForum('curioCoverFit', Settings::COVER_FIT, fn ($value) => in_array($value, Settings::COVER_FITS, true) ? $value : 'blur')
        ->serializeToForum('curioShortcut', Settings::SHORTCUT, fn ($value) => in_array($value, Settings::SHORTCUTS, true) ? $value : 'both'),

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

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class)
        ->endpoint('show', fn (Endpoint\Show $endpoint) => $endpoint->addDefaultInclude([
            'curioItem', 'curioItem.work', 'curioItem.collection', 'curioItem.collection.user', 'curioItem.collection.type',
        ])),

    (new Extend\Model(Discussion::class))
        ->cast('curio_item_id', 'integer'),

    (new Extend\Event())
        ->listen(Started::class, LinkShownItem::class),

    (new Extend\View())
        ->namespace('ernestdefoe-curio', __DIR__.'/views'),

    (new Extend\Notification())
        ->type(WishlistMatchBlueprint::class, ['alert', 'email'])
        ->type(NewItemsBlueprint::class, ['alert'])
        ->type(WishlistAddedBlueprint::class, ['alert'])
        ->type(ProgressChangedBlueprint::class, ['alert'])
        ->type(RatingChangedBlueprint::class, ['alert']),

    (new Extend\Routes('api'))
        ->post('/curio/lookup', 'curio.lookup', Api\LookupController::class)
        ->get('/curio/overview', 'curio.overview', Api\OverviewController::class)
        ->get('/curio/collectors', 'curio.collectors', Api\CollectorsController::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Collection::class, Search\CollectionSearcher::class)
        ->addFilter(Search\CollectionSearcher::class, Search\Filter\CollectionUserFilter::class)
        ->addFilter(Search\CollectionSearcher::class, Search\Filter\CollectionTypeFilter::class)
        ->addFilter(Search\CollectionSearcher::class, Search\Filter\FeaturedFilter::class)
        ->addSearcher(Item::class, Search\ItemSearcher::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\ItemCollectionFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\ItemUserFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\StatusFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\ProgressFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\FacetFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\TitleFilter::class)
        ->addFilter(Search\ItemSearcher::class, Search\Filter\ChangedFilter::class),

    (new Extend\ModelVisibility(Collection::class))
        ->scope(Access\ScopeCollectionVisibility::class),
    (new Extend\ModelVisibility(Item::class))
        ->scope(Access\ScopeItemVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Collection::class, Access\CollectionPolicy::class)
        ->modelPolicy(Item::class, Access\CollectionPolicy::class),

    (new Extend\Console())
        ->command(RefreshTexts::class)
        ->command(ImportShelf::class)
        ->command(MatchWorks::class)
        ->schedule(MatchWorks::class, function (Event $event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        })
        ->schedule(RefreshTexts::class, function (Event $event): void {
            $event->daily()->withoutOverlapping();
        }),
];
