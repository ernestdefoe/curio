<?php

namespace Ernestdefoe\Curio\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class CurioTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-curio');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'collector', 'email' => 'collector@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'visitor', 'email' => 'visitor@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 3],
                ['user_id' => 4, 'group_id' => 3],
            ],
            'curio_works' => [
                ['id' => 1, 'source' => 'deezer', 'source_id' => '302127', 'title' => 'Discovery (orig)', 'creator' => 'Daft Punk', 'year' => 2001, 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 30],
            ],
            'curio_work_texts' => [
                ['work_id' => 1, 'locale' => 'en', 'title' => 'Discovery', 'description' => 'The second album.', 'description_source' => 'wikipedia', 'source_url' => 'https://en.wikipedia.org/wiki/Discovery_(album)', 'fetched_at' => $now],
            ],
        ]);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function get(string $path, array $query = [], ?int $as = null): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query));
    }

    private function typeId(string $slug): int
    {
        return (int) $this->database()->table('curio_types')->where('slug', $slug)->value('id');
    }

    private function collection(int $as, array $attributes, string $type = 'games'): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/curio-collections', [
            'authenticatedAs' => $as,
            'json' => ['data' => [
                'type' => 'curio-collections',
                'attributes' => $attributes,
                'relationships' => ['type' => ['data' => ['type' => 'curio-types', 'id' => (string) $this->typeId($type)]]],
            ]],
        ]));
    }

    private function item(int $as, int $collection, array $attributes, ?int $work = null): ResponseInterface
    {
        $relationships = ['collection' => ['data' => ['type' => 'curio-collections', 'id' => (string) $collection]]];
        if ($work) {
            $relationships['work'] = ['data' => ['type' => 'curio-works', 'id' => (string) $work]];
        }

        return $this->send($this->request('POST', '/api/curio-items', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-items', 'attributes' => $attributes, 'relationships' => $relationships]],
        ]));
    }

    private function newCollection(int $as, array $attributes = [], string $type = 'games'): int
    {
        $response = $this->collection($as, $attributes + ['name' => 'Shelf of '.$as], $type);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->json($response)['data']['id'];
    }

    #[Test]
    public function presets_are_listed_to_guests(): void
    {
        $response = $this->get('/api/curio-types');
        $this->assertSame(200, $response->getStatusCode());

        $slugs = array_column(array_column($this->json($response)['data'], 'attributes'), 'slug');
        $this->assertSame(['games', 'music', 'books', 'films', 'boardgames', 'lego', 'cards', 'comics', 'other'], $slugs);

        $games = $this->json($response)['data'][0]['attributes'];
        $this->assertSame('Jeux vidéo', $games['names']['fr']);
        $this->assertSame('platform', $games['fields'][0]['key']);
        // Games search comes from Marquee, which is not installed here.
        $this->assertFalse($games['searchable']);
        $this->assertTrue($this->json($response)['data'][2]['attributes']['searchable']);
    }

    #[Test]
    public function guests_cannot_create_collections(): void
    {
        $response = $this->send($this->request('POST', '/api/curio-collections', [
            'json' => ['data' => ['type' => 'curio-collections', 'attributes' => ['name' => 'Nope']]],
        ]));

        $this->assertContains($response->getStatusCode(), [400, 401, 403]);
    }

    #[Test]
    public function members_without_the_permission_cannot_create(): void
    {
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-curio.create')->delete();

        $this->assertSame(403, $this->collection(4, ['name' => 'Nope'])->getStatusCode());
        $response = $this->send($this->request('POST', '/api/curio/lookup', ['authenticatedAs' => 4, 'json' => ['type' => 1, 'q' => 'dune']]));
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function members_create_collections_with_a_slug(): void
    {
        $response = $this->collection(3, ['name' => 'The Nintendo shelf', 'description' => 'Switch first.']);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        $this->assertSame('the-nintendo-shelf', $data['attributes']['slug']);
        $this->assertSame('public', $data['attributes']['visibility']);
        $this->assertSame('3', $data['relationships']['user']['data']['id']);
    }

    #[Test]
    public function visibility_is_enforced(): void
    {
        $public = $this->newCollection(3, ['name' => 'Everyone']);
        $members = $this->newCollection(3, ['name' => 'Members', 'visibility' => 'members']);
        $private = $this->newCollection(3, ['name' => 'Mine', 'visibility' => 'private']);

        $ids = fn (ResponseInterface $r) => array_map('intval', array_column($this->json($r)['data'], 'id'));

        $this->assertSame([$public], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]])));
        $this->assertSame([$public, $members], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]], 4)));
        $this->assertSame([$public, $members, $private], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]], 3)));
        $this->assertSame([$public, $members, $private], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]], 1)));

        $this->assertSame(404, $this->get("/api/curio-collections/$private", [], 4)->getStatusCode());
        $this->assertSame(404, $this->get("/api/curio-collections/$members")->getStatusCode());

        // Items follow their collection.
        $this->assertSame(201, $this->item(3, $private, ['title' => 'Secret'])->getStatusCode());
        $this->assertSame(0, count($this->json($this->get('/api/curio-items', ['filter' => ['user' => 3]], 4))['data']));
    }

    #[Test]
    public function only_the_owner_adds_items(): void
    {
        $theirs = $this->newCollection(3);

        $response = $this->item(4, $theirs, ['title' => 'Planted']);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());

        $response = $this->send($this->request('PATCH', '/api/curio-collections/'.$theirs, [
            'authenticatedAs' => 4,
            'json' => ['data' => ['type' => 'curio-collections', 'id' => (string) $theirs, 'attributes' => ['name' => 'Renamed']]],
        ]));
        $this->assertSame('Shelf of 3', $this->database()->table('curio_collections')->where('id', $theirs)->value('name'), (string) $response->getBody());
    }

    #[Test]
    public function fields_are_cleaned_and_counted(): void
    {
        $id = $this->newCollection(3);

        $response = $this->item(3, $id, ['title' => 'Metroid Dread', 'year' => 2021, 'fields' => [
            'platform' => 'nintendo-switch', 'region' => 'not-a-region', 'cib' => '1', 'sneaky' => '<script>',
        ]]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $item = $this->json($response)['data']['attributes'];

        $this->assertEquals(['platform' => 'nintendo-switch', 'cib' => true], $item['fields']);
        $this->assertSame('nintendo-switch', $item['facet']);

        $this->item(3, $id, ['title' => 'EarthBound', 'status' => 'wishlist', 'fields' => ['platform' => 'snes']]);
        $this->item(3, $id, ['title' => 'Hades', 'status' => 'trade', 'fields' => ['platform' => 'nintendo-switch']]);

        $collection = $this->json($this->get("/api/curio-collections/$id"))['data']['attributes'];
        $this->assertSame(2, $collection['ownedCount']);
        $this->assertSame(1, $collection['wishCount']);
        $this->assertSame(1, $collection['tradeCount']);
        // Wanted items are not on the shelf, so they are not in the breakdown.
        $this->assertEquals(['nintendo-switch' => 2], $collection['facets']);
    }

    #[Test]
    public function a_hidden_wishlist_stays_hidden(): void
    {
        $id = $this->newCollection(3, ['wishlistPublic' => false]);
        $this->item(3, $id, ['title' => 'Owned one']);
        $this->item(3, $id, ['title' => 'Wanted one', 'status' => 'wishlist']);

        $titles = fn (?int $as) => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id]], $as))['data'], 'attributes'), 'title');

        $this->assertSame(['Owned one'], $titles(4));
        $this->assertSame(['Owned one'], $titles(null));
        $this->assertSame(['Owned one', 'Wanted one'], $titles(3));
        $this->assertSame(0, $this->json($this->get("/api/curio-collections/$id", [], 4))['data']['attributes']['wishCount']);
    }

    #[Test]
    public function the_price_paid_is_only_sent_to_the_owner(): void
    {
        $id = $this->newCollection(3);
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Boxed', 'pricePaid' => 49.5, 'priceCurrency' => 'EUR', 'barcode' => '045496590420']))['data']['id'];

        $mine = $this->json($this->get("/api/curio-items/$item", [], 3))['data']['attributes'];
        $this->assertEquals(49.5, $mine['pricePaid']);
        $this->assertSame('EUR', $mine['priceCurrency']);
        $this->assertSame('0045496590420', $mine['barcode']);

        foreach ([4, null] as $as) {
            $theirs = $this->json($this->get("/api/curio-items/$item", [], $as))['data']['attributes'];
            $this->assertArrayNotHasKey('pricePaid', $theirs);
            $this->assertArrayNotHasKey('priceCurrency', $theirs);
            $this->assertArrayNotHasKey('barcode', $theirs);
        }
    }

    #[Test]
    public function items_filter_by_status_facet_and_words(): void
    {
        $id = $this->newCollection(3);
        foreach ([
            ['title' => 'Celeste', 'fields' => ['platform' => 'nintendo-switch']],
            ['title' => 'Hades', 'status' => 'trade', 'fields' => ['platform' => 'pc']],
            ['title' => 'Chrono Trigger', 'status' => 'wishlist', 'fields' => ['platform' => 'snes']],
            ['title' => 'Hollow Knight', 'subtitle' => 'Team Cherry', 'fields' => ['platform' => 'nintendo-switch']],
        ] as $attributes) {
            $this->item(3, $id, $attributes);
        }

        $titles = fn (array $filter) => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id] + $filter]))['data'], 'attributes'), 'title');

        $this->assertSame(['Celeste', 'Hades', 'Hollow Knight'], $titles(['status' => 'owned']));
        $this->assertSame(['Chrono Trigger'], $titles(['status' => 'wishlist']));
        $this->assertSame(['Celeste', 'Hollow Knight'], $titles(['facet' => 'nintendo-switch']));
        $this->assertSame(['Hollow Knight'], $titles(['q' => 'cherry']));
        $this->assertSame(['Hollow Knight'], $titles(['q' => 'HOLLOW']));
        // Wildcards are words, not patterns.
        $this->assertSame([], $titles(['q' => '50% off']));
    }

    #[Test]
    public function the_owner_reorders_items(): void
    {
        $id = $this->newCollection(3);
        $ids = [];
        foreach (['A', 'B', 'C'] as $title) {
            $ids[] = (int) $this->json($this->item(3, $id, ['title' => $title]))['data']['id'];
        }

        $order = [$ids[2], $ids[0], $ids[1]];
        $patch = fn (int $as) => $this->send($this->request('PATCH', "/api/curio-collections/$id", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-collections', 'id' => (string) $id, 'attributes' => ['order' => $order]]],
        ]));

        // Not someone else.
        $patch(4);
        $titles = fn () => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id], 'sort' => 'position']))['data'], 'attributes'), 'title');
        $this->assertSame(['A', 'B', 'C'], $titles());

        $this->assertSame(200, $patch(3)->getStatusCode());
        $this->assertSame(['C', 'A', 'B'], $titles());
    }

    #[Test]
    public function looked_up_titles_follow_the_readers_language(): void
    {
        $id = $this->newCollection(3, [], 'music');
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Discovery (orig)'], 1))['data']['id'];
        $typed = (int) $this->json($this->item(3, $id, ['title' => 'My own name'], 1))['data']['id'];

        $list = $this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id]]))['data'];
        $this->assertSame('Discovery', $list[0]['attributes']['displayTitle']);
        // A title the member typed is theirs.
        $this->assertSame('My own name', $list[1]['attributes']['displayTitle']);

        $shown = $this->json($this->get("/api/curio-items/$item"));
        $work = collect($shown['included'])->firstWhere('type', 'curio-works')['attributes'];
        $this->assertSame('The second album.', $work['description']);
        $this->assertSame('wikipedia', $work['descriptionSource']);
        $this->assertSame(30, $work['languages']);
        $this->assertNotNull($typed);
    }

    #[Test]
    public function a_known_barcode_is_answered_without_asking_anyone(): void
    {
        $this->app();
        resolve(BarcodeLookup::class)->remember('0724384960650', 1, 'Discovery', ['format' => '2-lp'], 'deezer');

        $response = $this->send($this->request('POST', '/api/curio/lookup', [
            'authenticatedAs' => 3,
            'json' => ['type' => $this->typeId('music'), 'q' => '724384960650'],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $this->assertTrue($body['barcode']['known']);
        $this->assertSame(1, $body['results'][0]['workId']);
        $this->assertSame(['format' => '2-lp'], $body['barcode']['fields']);
    }

    #[Test]
    public function an_item_with_a_barcode_teaches_the_forum(): void
    {
        $id = $this->newCollection(3);
        $this->item(3, $id, ['title' => 'Breath of the Wild', 'barcode' => '045496590420', 'fields' => ['platform' => 'nintendo-switch']]);

        $row = $this->database()->table('curio_barcodes')->where('code', '0045496590420')->first();
        $this->assertNotNull($row);
        $this->assertSame('Breath of the Wild', $row->title);
        $this->assertSame('member', $row->source);
    }

    #[Test]
    public function guests_cannot_look_things_up(): void
    {
        $response = $this->send($this->request('POST', '/api/curio/lookup', ['json' => ['type' => 1, 'q' => 'dune']]));
        $this->assertContains($response->getStatusCode(), [400, 401, 403]);
    }

    #[Test]
    public function photos_are_stored_as_webp_and_capped(): void
    {
        $id = $this->newCollection(3);
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Photographed']))['data']['id'];

        $upload = function (int $as) use ($item) {
            $path = tempnam(sys_get_temp_dir(), 'curio').'.png';
            $image = imagecreatetruecolor(40, 60);
            imagepng($image, $path);

            return $this->send($this->request('POST', "/api/curio-items/$item/photos", ['authenticatedAs' => $as])
                ->withUploadedFiles(['photo' => new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'box.png', 'image/png')]));
        };

        $this->assertSame(403, $upload(4)->getStatusCode());

        $response = $upload(3);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $photos = $this->json($response)['data']['attributes']['photos'];
        $this->assertCount(1, $photos);
        $this->assertStringEndsWith('.webp', $photos[0]['url']);
        $this->assertStringEndsWith('-sm.webp', $photos[0]['small']);

        for ($i = 0; $i < 5; $i++) {
            $upload(3);
        }
        $this->assertSame(422, $upload(3)->getStatusCode());

        $remove = $this->send($this->request('DELETE', "/api/curio-items/$item/photos/0", ['authenticatedAs' => 3]));
        $this->assertCount(5, $this->json($remove)['data']['attributes']['photos']);
    }

    #[Test]
    public function a_type_in_use_cannot_be_deleted(): void
    {
        $this->newCollection(3);

        $response = $this->send($this->request('DELETE', '/api/curio-types/'.$this->typeId('games'), ['authenticatedAs' => 1]));
        $this->assertSame(422, $response->getStatusCode());

        $response = $this->send($this->request('DELETE', '/api/curio-types/'.$this->typeId('other'), ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $response = $this->send($this->request('DELETE', '/api/curio-types/'.$this->typeId('lego'), ['authenticatedAs' => 3]));
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function the_overview_counts_public_collections_only(): void
    {
        $public = $this->newCollection(3);
        $private = $this->newCollection(3, ['visibility' => 'private']);
        $this->item(3, $public, ['title' => 'Seen']);
        $this->item(3, $private, ['title' => 'Unseen']);
        $this->item(3, $private, ['title' => 'Unseen too']);

        $body = $this->json($this->get('/api/curio/overview'));
        $this->assertSame(1, $body['items']);
        $this->assertSame(1, $body['types'][(string) $this->typeId('games')]['items']);
    }

    #[Test]
    public function lists_cost_a_fixed_number_of_queries(): void
    {
        $id = $this->newCollection(3, [], 'music');
        for ($i = 0; $i < 12; $i++) {
            $this->item(3, $id, ['title' => "Record $i", 'status' => $i % 3 ? 'owned' : 'wishlist'], $i % 2 ? 1 : null);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->newCollection(3, ['name' => "More $i"]);
        }

        // The repeated-query detector fails these if anything is fetched per row.
        $this->assertSame(200, $this->get('/api/curio-items', ['filter' => ['collection' => $id], 'include' => 'collection.user,collection.type'], 4)->getStatusCode());
        $this->assertSame(200, $this->get('/api/curio-items', ['filter' => ['status' => 'owned'], 'include' => 'collection.user,collection.type', 'sort' => '-createdAt'])->getStatusCode());
        $this->assertSame(200, $this->get('/api/curio-collections', ['filter' => ['user' => 3]], 4)->getStatusCode());
    }
}
