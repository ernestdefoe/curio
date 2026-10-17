<x-mail::html.notification>
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-curio.email.match.body', ['recipient_display_name' => $user->display_name, 'owner' => $blueprint->from?->display_name ?? '', 'title' => $blueprint->item->title]) }}</p>
        <p><a href="{{ $url->to('forum')->route('curio.collection', ['id' => $blueprint->item->collection_id]) }}?item={{ $blueprint->item->id }}">{{ $translator->trans('ernestdefoe-curio.email.match.link') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
