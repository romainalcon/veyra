{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $pageUrl }}</link>
        <description>{{ $description ?? $title }}</description>
        <language>{{ $locale }}</language>
        <lastBuildDate>{{ $updated->toRssString() }}</lastBuildDate>
        <atom:link href="{{ $feedUrl }}" rel="self" type="application/rss+xml"/>
@foreach ($entries as $entry)
        <item>
            <title>{{ $entry['title'] }}</title>
            <link>{{ $entry['link'] }}</link>
            <guid isPermaLink="false">{{ $entry['id'] }}</guid>
            <pubDate>{{ $entry['updated']->toRssString() }}</pubDate>
            <description>{{ $entry['content'] }}</description>
        </item>
@endforeach
    </channel>
</rss>
