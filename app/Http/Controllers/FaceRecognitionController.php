<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FaceRecognitionController extends Controller
{
    protected $apiBaseUrl = 'http://127.0.0.1:5000';

    public function index()
    {
        return view('recognition.index');
    }

    public function enroll()
    {
        return view('recognition.enroll');
    }

    public function users()
    {
        try {
            $response = Http::timeout(5)->get($this->apiBaseUrl . '/api/users');
            $users = $response->successful() ? $response->json()['users'] : [];
        } catch (\Exception $e) {
            $users = [];
        }
        
        return view('recognition.users', compact('users'));
    }

    public function deleteUser($name)
    {
        try {
            Http::timeout(5)->delete($this->apiBaseUrl . '/api/users/' . $name);
        } catch (\Exception $e) {
            // Handle error
        }
        
        return redirect()->route('users')->with('success', "User '{$name}' deleted successfully.");
    }
}