<x-mail::html.notification>
    <x-slot:body>
        <p>{{ $translator->trans('ernestdefoe-curio.email.new_items.body', ['recipient_display_name' => $user->display_name, 'owner' => $blueprint->from?->display_name ?? '', 'name' => $blueprint->collection->name]) }}</p>
        <p><a href="{{ $url->to('forum')->route('curio.collection', ['id' => $blueprint->collection->id.'-'.$blueprint->collection->slug]) }}">{{ $translator->trans('ernestdefoe-curio.email.new_items.link') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
