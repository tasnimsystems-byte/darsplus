<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->auth()->check()) {
            return Response::redirect('/');
        }
        return $this->view('auth/login', [], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $username = $request->str('username');
        $password = (string) ($request->post['password'] ?? '');
        if ($username === '' || $password === '') {
            return $this->invalid('/login', ['login' => [__('auth.failed')]], ['username' => $username]);
        }

        [$status, $userId] = (new AuthService($this->db()))->attempt($username, $password, $request->ip());
        if ($status !== AuthService::OK) {
            // One message for wrong user and wrong password alike; a different one only for the lock.
            $key = $status === AuthService::LOCKED ? 'auth.locked' : 'auth.failed';
            return $this->invalid('/login', ['login' => [__($key)]], ['username' => $username]);
        }

        $this->auth()->login($userId);
        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        $this->audit('logout', 'user', $this->auth()->id());
        $this->auth()->logout();
        return Response::redirect('/login');
    }

    public function showSchools(Request $request): Response
    {
        return $this->view('auth/select-school', ['schools' => $this->auth()->availableSchools()], 'layouts/auth');
    }

    public function selectSchool(Request $request): Response
    {
        if (!$this->auth()->selectSchool($request->int('school_id'))) {
            throw new HttpException(403);
        }
        $this->audit('enter_school', 'school', $this->auth()->schoolId());
        return Response::redirect('/');
    }
}
