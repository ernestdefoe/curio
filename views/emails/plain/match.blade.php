<x-mail::plain.notification>
{!! $translator->trans('ernestdefoe-curio.email.match.body', ['recipient_display_name' => $user->display_name, 'owner' => $blueprint->from?->display_name ?? '', 'title' => $blueprint->item->title]) !!}

{!! $url->to('forum')->route('curio.collection', ['id' => $blueprint->item->collection_id]) !!}?item={{ $blueprint->item->id }}
</x-mail::plain.notification>
