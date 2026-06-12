@extends('layouts.app')

@section('title', 'Registered Users - Smart Lock')

@section('content')
<div class="bg-gray-800 rounded-lg p-6">
    <h2 class="text-xl font-bold mb-4"><i class="fas fa-users text-blue-400 mr-2"></i>Registered Users</h2>

    @if(empty($users))
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-user-slash text-6xl mb-4"></i>
            <p class="text-lg">No users registered yet.</p>
            <a href="{{ route('enroll') }}" class="inline-block mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded transition">
                <i class="fas fa-user-plus mr-1"></i>Register New User
            </a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-700 text-left">
                        <th class="py-3 px-4">Name</th>
                        <th class="py-3 px-4">Registered At</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                    @php
                        $userName = is_string($user) ? $user : ($user['name'] ?? 'Unknown');
                    @endphp
                    <tr class="border-b border-gray-700 hover:bg-gray-700/50">
                        <td class="py-3 px-4 font-medium">
                            <i class="fas fa-user-circle text-blue-400 mr-2"></i>
                            {{ $userName }}
                        </td>
                        <td class="py-3 px-4 text-gray-400 text-sm">
                            N/A
                        </td>
                        <td class="py-3 px-4 text-right">
                            <form action="{{ route('users.delete', $userName) }}" method="POST" 
                                  onsubmit="return confirm('Delete user {{ $userName }}?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1 bg-red-600 hover:bg-red-700 rounded text-sm transition">
                                    <i class="fas fa-trash mr-1"></i>Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
</final_file_content>