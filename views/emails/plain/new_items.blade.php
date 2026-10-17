<x-mail::plain.notification>
{!! $translator->trans('ernestdefoe-curio.email.new_items.body', ['recipient_display_name' => $user->display_name, 'owner' => $blueprint->from?->display_name ?? '', 'name' => $blueprint->collection->name]) !!}

{!! $url->to('forum')->route('curio.collection', ['id' => $blueprint->collection->id.'-'.$blueprint->collection->slug]) !!}
</x-mail::plain.notification>
