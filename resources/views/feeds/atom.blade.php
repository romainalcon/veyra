{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="{{ $locale }}">
    <title>{{ $title }}</title>
@if ($description)
    <subtitle>{{ $description }}</subtitle>
@endif
    <id>{{ $feedUrl }}</id>
    <link rel="self" type="application/atom+xml" href="{{ $feedUrl }}"/>
    <link rel="alternate" type="text/html" href="{{ $pageUrl }}"/>
    <updated>{{ $updated->toAtomString() }}</updated>
@foreach ($entries as $entry)
    <entry>
        <id>{{ $entry['id'] }}</id>
        <title>{{ $entry['title'] }}</title>
        <link rel="alternate" type="text/html" href="{{ $entry['link'] }}"/>
        <published>{{ $entry['published']->toAtomString() }}</published>
        <updated>{{ $entry['updated']->toAtomString() }}</updated>
        <author><name>{{ $title }}</name></author>
        <content type="text">{{ $entry['content'] }}</content>
    </entry>
@endforeach
</feed>
