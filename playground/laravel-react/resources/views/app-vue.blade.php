{{--
    The same Laravel application, rendered by the Vue packages.

    Inertia's server side does not know which renderer will mount: the page name and
    props are identical, and the root view decides. That is what makes a second
    entrypoint enough to run the Vue renderers against real server payloads, rather
    than a second Laravel application.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" href="/favicon.ico" sizes="any">

        @vite(['resources/css/app.css', 'resources/js/vue/app.ts'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }} (Vue)</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
