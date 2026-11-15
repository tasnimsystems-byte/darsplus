<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\UserRepository;
use App\Services\Passwords;

final class ProfileController extends Controller
{
    public function showPassword(Request $request): Response
    {
        return $this->view('auth/password', ['forced' => (bool) $this->auth()->user()['must_change_password']], 'layouts/auth');
    }

    public function updatePassword(Request $request): Response
    {
        $users = new UserRepository($this->db());
        $userId = (int) $this->auth()->id();
        $current = (string) ($request->post['current_password'] ?? '');
        $new = (string) ($request->post['new_password'] ?? '');

        $v = Validator::make(
            ['new_password' => $new],
            ['new_password' => 'required|password'],
            'auth.fields'
        );
        if (!password_verify($current, (string) $users->passwordHash($userId))) {
            $v->addError('current_password', __('auth.wrong_current_password'));
        }
        if ($new !== (string) ($request->post['new_password_confirmation'] ?? '')) {
            $v->addError('new_password_confirmation', __('auth.password_mismatch'));
        }
        if ($new !== '' && $new === $current) {
            $v->addError('new_password', __('auth.password_same'));
        }
        if ($v->fails()) {
            return $this->invalid('/password', $v->errors(), []);
        }

        $users->update($userId, [
            'password_hash' => Passwords::hash($new), 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('change_password', 'user', $userId);
        // This session continues; every other session of the user ends at its next request.
        App::session()->regenerate();
        App::session()->set('auth_time', time());
        return $this->redirect('/', __('auth.password_changed'));
    }

    public function year(Request $request): Response
    {
        $this->auth()->selectYear($request->int('year_id'));
        // Detail pages belong to one year, so return to the section's list instead.
        $back = preg_match('#^(/[a-z-]*)#', $request->str('back'), $m) ? $m[1] : '/';
        return Response::redirect($back);
    }

    /** Language switch — available before login too, so it is not behind authentication. */
    public function locale(Request $request): Response
    {
        $locale = $request->str('locale');
        if (isset(Lang::SUPPORTED[$locale])) {
            App::session()->set('locale', $locale);
            if ($this->auth()->check()) {
                (new UserRepository($this->db()))->update((int) $this->auth()->id(), ['locale' => $locale]);
            }
        }
        // Only ever redirect to a local path.
        $back = $request->str('back');
        return Response::redirect(preg_match('#^/[A-Za-z0-9/_-]*$#', $back) ? $back : '/');
    }
}
