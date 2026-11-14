<feed xmlns="http://www.w3.org/2005/Atom">
    <title>Tantra product roadmap</title>
    <subtitle>What we are building and what just shipped.</subtitle>
    <id>{{ $page }}</id>
    <link rel="self" type="application/atom+xml" href="{{ $self }}"/>
    <link rel="alternate" type="text/html" href="{{ $page }}"/>
    <updated>{{ $updated->toAtomString() }}</updated>
    <author><name>Tantra</name><uri>https://usetantra.com</uri></author>
@foreach ($items as $item)
@php($card = $item->publicArray())
@php($when = $item->shipped_at ?? $item->created_at)
    <entry>
        <title>{{ $item->shipped_at ? 'Shipped' : 'New in '.$item->column->label() }}: {{ $item->title }}</title>
        <id>urn:tantra:roadmap:{{ $item->id }}{{ $item->shipped_at ? ':shipped' : '' }}</id>
        <link rel="alternate" type="text/html" href="{{ $page }}#item-{{ $item->id }}"/>
        <updated>{{ $when->toAtomString() }}</updated>
        <published>{{ $when->toAtomString() }}</published>
@if ($item->category)
        <category term="{{ $item->category }}"/>
@endif
        <summary type="text">{{ trim(($card['summary'] ?? '')."\n\n".($card['detail'] ?? '')) ?: $item->title }}</summary>
    </entry>
@endforeach
</feed>
