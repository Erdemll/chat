Merhaba {{ $recipientName }},

Henüz görüntülemediğiniz {{ count($items) }} mesajda sizden bahsedildi.

@foreach ($items as $item)
{{ $item['sender'] }}
{{ $item['preview'] }}
{{ $item['created_at'] }}

@endforeach
Mesajları görüntüle: {{ $chatUrl }}

TEPENET İletişim
