<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Okunmamış bahsetmeler</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <p>Merhaba {{ $recipientName }},</p>
    <p>Henüz görüntülemediğiniz {{ count($items) }} mesajda sizden bahsedildi.</p>
    @foreach ($items as $item)
        <div style="margin: 20px 0; padding: 12px; border-left: 3px solid #dc2626;">
            <strong>{{ $item['sender'] }}</strong>
            <p style="margin: 8px 0;">{{ $item['preview'] }}</p>
            <small>{{ $item['created_at'] }}</small>
        </div>
    @endforeach
    <p><a href="{{ $chatUrl }}">Mesajları görüntüle</a></p>
    <p>TEPENET İletişim</p>
</body>
</html>
