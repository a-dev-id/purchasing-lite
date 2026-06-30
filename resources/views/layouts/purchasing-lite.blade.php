<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <title>@yield('title', 'Purchasing Lite')</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
    @php
    $currentUser = auth()->user();
    $currentUserRole = strtolower((string) ($currentUser->role ?? $currentUser->role_name ?? ''));
    $currentUserDisplayName = $currentUser && str_replace(['-', '_'], ' ', trim($currentUserRole)) === 'owner'
    ? 'OR'
    : ($currentUser->name ?? null);
    @endphp

    <div class="flex min-h-screen flex-col">
        <header class="border-b border-slate-300 bg-white">
            <div class="flex items-center justify-between px-4 py-2">
                <a href="/purchasing-lite/dashboard" class="text-inherit no-underline">
                    <h1 class="text-base font-bold text-slate-950">
                        Purchasing Lite
                    </h1>

                    <p class="mt-0.5 text-xs text-slate-600">
                        Simple purchasing system
                    </p>
                </a>

                <div class="flex items-center gap-3">
                    @if ($currentUser)
                    <div class="hidden text-right sm:block">
                        <p class="text-xs font-bold text-slate-900">
                            {{ $currentUserDisplayName }}
                        </p>
                    </div>
                    @endif

                    <form method="POST" action="/purchasing-lite/logout">
                        @csrf

                        <button type="submit" class="inline-flex items-center justify-center border border-slate-300 bg-white px-4 py-1.5 text-xs font-bold text-slate-800 shadow-sm transition hover:bg-slate-50">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3">
            @if (session('success'))
            <div class="mb-4 border border-green-300 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">
                {{ session('success') }}
            </div>
            @endif

            @if (session('error'))
            <div class="mb-4 border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
            @endif

            @yield('content')
        </main>

        <footer class="border-t border-slate-300 bg-white px-4 py-2 text-center text-xs font-medium text-slate-600">
            Nandini Jungle by Hanging Gardens &copy; {{ date('Y') }}. All rights reserved.
        </footer>
    </div>

    <div id="image-preview-modal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/70 px-4 py-6" data-image-preview-modal>
        <div class="relative max-h-full w-full max-w-5xl">
            <button type="button" class="absolute -right-2 -top-2 z-10 flex h-9 w-9 items-center justify-center bg-white text-xl font-bold leading-none text-slate-950 shadow hover:bg-slate-100" data-close-image-preview>
                &times;
            </button>

            <img src="" alt="" class="mx-auto max-h-[85vh] max-w-full border border-slate-300 bg-white object-contain shadow-xl" data-image-preview-img>
        </div>
    </div>

    @stack('scripts')

    <script>
        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href]');

            if (! link || ! link.querySelector('img')) {
                return;
            }

            const href = link.getAttribute('href') || '';

            if (! /\.(jpg|jpeg|png|gif|webp|bmp|svg)(\?.*)?$/i.test(href)) {
                return;
            }

            const modal = document.querySelector('[data-image-preview-modal]');
            const image = document.querySelector('[data-image-preview-img]');

            if (! modal || ! image) {
                return;
            }

            event.preventDefault();

            image.src = href;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        });

        document.addEventListener('click', function (event) {
            const closeButton = event.target.closest('[data-close-image-preview]');
            const modal = event.target.matches('[data-image-preview-modal]')
                ? event.target
                : closeButton ? closeButton.closest('[data-image-preview-modal]') : null;

            if (! modal) {
                return;
            }

            const image = modal.querySelector('[data-image-preview-img]');

            if (image) {
                image.src = '';
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            const modal = document.querySelector('[data-image-preview-modal]');

            if (! modal || modal.classList.contains('hidden')) {
                return;
            }

            const image = modal.querySelector('[data-image-preview-img]');

            if (image) {
                image.src = '';
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        });
    </script>
</body>

</html>