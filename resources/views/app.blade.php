<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="user-authenticated" content="{{ auth()->check() }}">
        @if(auth()->check())
            <meta name="user-data" content="{{ json_encode(auth()->user()) }}">
        @endif
        <title>انجمن پرسش و پاسخ</title>
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
        <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
        <link rel="preload" as="style" href="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/Vazirmatn-font-face.css" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/Vazirmatn-font-face.css"></noscript>


        @vite(['resources/css/app.css'])
    </head>
    <body>
    </body>
</html>
