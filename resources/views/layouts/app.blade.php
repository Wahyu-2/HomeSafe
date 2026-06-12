<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Smart Lock - Face Recognition')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    @stack('styles')
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <nav class="bg-gray-800 border-b border-gray-700 px-6 py-3">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <a href="{{ route('home') }}" class="text-xl font-bold text-blue-400">
                <i class="fas fa-lock mr-2"></i>Smart Lock
            </a>
            <div class="flex gap-4">
                <a href="{{ route('home') }}" class="px-3 py-2 rounded hover:bg-gray-700 transition">
                    <i class="fas fa-camera mr-1"></i>Recognition
                </a>
                <a href="{{ route('enroll') }}" class="px-3 py-2 rounded hover:bg-gray-700 transition">
                    <i class="fas fa-user-plus mr-1"></i>Enroll
                </a>
                <a href="{{ route('users') }}" class="px-3 py-2 rounded hover:bg-gray-700 transition">
                    <i class="fas fa-users mr-1"></i>Users
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-6">
        @if(session('success'))
            <div class="bg-green-600 text-white px-4 py-2 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>