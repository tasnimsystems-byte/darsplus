<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccessScope;
use App\Services\LiveKit;
use App\Services\OnlineLessons;
use RuntimeException;

/**
 * Online lessons: the teacher opens a live meeting from the lesson page; the lesson's students enter it
 * from their portal. Sound, video and screens go through the LiveKit server – this controller only
 * decides who enters and with what rights, signs their access token and asks the server to moderate.
 * Nothing is recorded.
 */
final class OnlineLessonController extends Controller
{
    /** Opens (or returns to) the live meeting of a lesson of today. */
    public function start(Request $request, int $id): Response
    {
        if (!LiveKit::enabled()) {
            return $this->redirect("/attendance/sessions/$id", null, 'online.errors.disabled');
        }
        $scope = new AccessScope($this->db(), $this->auth());
        [$condition, $params] = $scope->assignmentCondition('ta');
        $session = $this->db()->one(
            "SELECT ls.*, ta.study_group_id, ta.teacher_id, ta.academic_year_id, y.is_locked
               FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
               JOIN academic_years y ON y.id = ta.academic_year_id
              WHERE ls.id = ? AND ls.school_id = ? AND $condition",
            [$id, $this->schoolId(), ...$params]
        ) ?? throw new HttpException(404);
        if (!$scope->canEditAssignment((int) $session['teaching_assignment_id'])) {
            throw new HttpException(403);
        }
        if ($session['is_locked'] || $session['status'] === 'cancelled' || $session['lesson_date'] !== date('Y-m-d')) {
            return $this->redirect("/attendance/sessions/$id", null, 'online.errors.not_today');
        }
        // A study group's lesson has one meeting for all its classes.
        $together = [$id];
        if ($session['study_group_id'] !== null) {
            $together = array_map('intval', $this->db()->column(
                'SELECT ls.id FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
                  WHERE ta.school_id = ? AND ta.study_group_id = ? AND ta.teacher_id = ? AND ta.academic_year_id = ?
                    AND ls.lesson_date = ? AND ls.period_number = ?',
                [$this->schoolId(), $session['study_group_id'], $session['teacher_id'], $session['academic_year_id'], $session['lesson_date'], $session['period_number']]
            )) ?: [$id];
        }
        $live = OnlineLessons::liveFor($this->db(), $together);
        if ($live !== null) {
            return Response::redirect('/online/' . (int) $live['id']);
        }
        $meeting = $this->db()->insert('online_meetings', [
            'school_id' => $this->schoolId(), 'lesson_session_id' => $id,
            'room_name' => 'dp-' . bin2hex(random_bytes(12)), 'started_by' => $this->auth()->id(),
        ]);
        $this->audit('create', 'online_meeting', $meeting, null, ['lesson_session' => $id]);
        return Response::redirect("/online/$meeting");
    }

    /** @return array{0: array, 1: array{role: string, student: ?array}} */
    private function enter(int $id): array
    {
        $meeting = OnlineLessons::meeting($this->db(), $this->schoolId(), $id) ?? throw new HttpException(404);
        $access = OnlineLessons::access($this->db(), $this->auth(), $meeting) ?? throw new HttpException(403);
        return [$meeting, $access];
    }

    private function backPath(array $meeting, string $role): string
    {
        return $role === 'student' ? '/me/live' : '/attendance/sessions/' . (int) $meeting['lesson_session_id'];
    }

    public function room(Request $request, int $id): Response
    {
        [$meeting, $access] = $this->enter($id);
        if (!$meeting['is_live']) {
            return $this->redirect($this->backPath($meeting, $access['role']), null, 'online.errors.ended');
        }
        if (!LiveKit::enabled()) {
            return $this->redirect($this->backPath($meeting, $access['role']), null, 'online.errors.disabled');
        }
        $user = $this->auth()->user();
        $response = $this->view('online/room', [
            'meeting' => $meeting, 'role' => $access['role'], 'back' => $this->backPath($meeting, $access['role']),
            'identity' => OnlineLessons::identity((int) $this->auth()->id()), 'displayName' => (string) ($user['full_name'] ?? ''),
            'periods' => TimetableController::periods($this->db(), $this->schoolId(), (int) $meeting['academic_year_id']),
        ], 'layouts/meeting');
        // This page alone may use the camera, the microphone and the screen, and talk to the media server.
        $response->headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
            . "font-src 'self'; media-src 'self' blob: mediastream:; worker-src 'self' blob:; connect-src 'self' " . implode(' ', LiveKit::connectSources())
            . "; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
        $response->headers['Permissions-Policy'] = 'camera=(self), microphone=(self), display-capture=(self), geolocation=()';
        return $response;
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data, JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** The access token of the signed-in person for this meeting; also notes that they entered. */
    public function token(Request $request, int $id): Response
    {
        [$meeting, $access] = $this->enter($id);
        if (!$meeting['is_live']) {
            return $this->json(['error' => __('online.errors.ended')], 410);
        }
        if (!LiveKit::enabled()) {
            return $this->json(['error' => __('online.errors.disabled')], 503);
        }
        $userId = (int) $this->auth()->id();
        $role = $access['role'];
        $name = (string) ($this->auth()->user()['full_name'] ?? '');
        if ($role === 'student' && $access['student'] !== null) {
            $name = trim($access['student']['first_name'] . ' ' . $access['student']['last_name']);
        }
        $screen = (bool) $this->db()->value('SELECT can_share_screen FROM online_meeting_participants WHERE meeting_id = ? AND user_id = ?', [$meeting['id'], $userId]);
        $token = LiveKit::token(
            OnlineLessons::identity($userId), $name,
            OnlineLessons::grant((string) $meeting['room_name'], $role, (bool) $meeting['mics_locked'], $screen),
            ['role' => $role]
        );
        OnlineLessons::recordJoin($this->db(), $meeting, $userId, $role, $access['student'] !== null ? (int) $access['student']['id'] : null);
        return $this->json(['url' => LiveKit::url(), 'token' => $token, 'identity' => OnlineLessons::identity($userId), 'role' => $role,
            'micsLocked' => (bool) $meeting['mics_locked']]);
    }

    /** The teacher's controls: mute, stop a camera, remove, lock the microphones, allow a screen. */
    public function moderate(Request $request, int $id): Response
    {
        [$meeting, $access] = $this->enter($id);
        if ($access['role'] !== 'host') {
            throw new HttpException(403);
        }
        if (!$meeting['is_live']) {
            return $this->json(['error' => __('online.errors.ended')], 410);
        }
        $action = $request->str('action');
        $room = (string) $meeting['room_name'];
        $self = OnlineLessons::identity((int) $this->auth()->id());
        // Only people who entered this meeting can be acted on.
        $known = [];
        $screens = [];
        foreach ($this->db()->all('SELECT user_id, role, can_share_screen FROM online_meeting_participants WHERE meeting_id = ?', [$meeting['id']]) as $row) {
            $known[OnlineLessons::identity((int) $row['user_id'])] = $row['role'];
            $screens[OnlineLessons::identity((int) $row['user_id'])] = (bool) $row['can_share_screen'];
        }
        $target = $request->str('identity');
        if (in_array($action, ['mute', 'stop_camera', 'remove', 'allow_screen', 'deny_screen'], true)
            && (!isset($known[$target]) || $target === $self || $known[$target] === 'host')) {
            return $this->json(['error' => __('online.errors.participant')], 422);
        }
        try {
            switch ($action) {
                case 'mute':
                case 'stop_camera':
                    $this->muteSources($room, [$target], $action === 'mute' ? ['microphone'] : ['camera']);
                    break;
                case 'mute_all':
                    $others = array_keys(array_filter($known, static fn ($role) => $role !== 'host'));
                    $this->muteSources($room, array_diff($others, [$self]), ['microphone']);
                    break;
                case 'remove':
                    LiveKit::room('RemoveParticipant', $room, ['identity' => $target]);
                    break;
                case 'allow_screen':
                case 'deny_screen':
                    $allow = $action === 'allow_screen';
                    $this->permit($room, $target, $known[$target], (bool) $meeting['mics_locked'], $allow);
                    $this->db()->update('online_meeting_participants', ['can_share_screen' => $allow ? 1 : 0],
                        ['meeting_id' => $meeting['id'], 'user_id' => (int) substr($target, 1)]);
                    break;
                case 'lock_mics':
                case 'unlock_mics':
                    $locked = $action === 'lock_mics';
                    $this->db()->update('online_meetings', ['mics_locked' => $locked ? 1 : 0], ['id' => $meeting['id']]);
                    $present = LiveKit::participants($room);
                    foreach ($known as $identity => $role) {
                        if ($role === 'student' && isset($present[$identity])) {
                            $this->permit($room, $identity, $role, $locked, $screens[$identity] ?? false);
                        }
                    }
                    if ($locked) {
                        $this->muteSources($room, array_keys(array_filter($known, static fn ($role) => $role === 'student')), ['microphone'], $present);
                    }
                    break;
                default:
                    return $this->json(['error' => __('online.errors.action')], 422);
            }
        } catch (RuntimeException) {
            return $this->json(['error' => __('online.errors.server')], 502);
        }
        return $this->json(['ok' => true, 'micsLocked' => $action === 'lock_mics' ? true : ($action === 'unlock_mics' ? false : (bool) $meeting['mics_locked'])]);
    }

    /** Mutes the given sources of these people, as published now. */
    private function muteSources(string $room, array $identities, array $sources, ?array $present = null): void
    {
        $present ??= LiveKit::participants($room);
        foreach ($identities as $identity) {
            foreach ($present[$identity] ?? [] as $track) {
                if (in_array($track['source'], $sources, true) && $track['sid'] !== '') {
                    LiveKit::room('MutePublishedTrack', $room, ['identity' => $identity, 'track_sid' => $track['sid'], 'muted' => true]);
                }
            }
        }
    }

    private function permit(string $room, string $identity, string $role, bool $micsLocked, bool $screen): void
    {
        LiveKit::room('UpdateParticipant', $room, ['identity' => $identity, 'permission' => [
            'canSubscribe' => true, 'canPublish' => true, 'canPublishData' => true,
            'canPublishSources' => OnlineLessons::sources($role, $micsLocked, $screen, true),
        ]]);
    }

    /** Ends the meeting for everyone. */
    public function end(Request $request, int $id): Response
    {
        [$meeting, $access] = $this->enter($id);
        if ($access['role'] !== 'host') {
            throw new HttpException(403);
        }
        if ($meeting['ended_at'] === null) {
            $this->db()->update('online_meetings', ['ended_at' => date('Y-m-d H:i:s')], ['id' => $meeting['id']]);
            try {
                LiveKit::room('DeleteRoom', (string) $meeting['room_name']);
            } catch (RuntimeException) {
                // The room closes by itself once empty; the meeting is already closed here.
            }
            $this->audit('update', 'online_meeting', (int) $meeting['id'], null, ['ended' => true]);
        }
        return $this->redirect('/attendance/sessions/' . (int) $meeting['lesson_session_id'], __('online.ended_ok'));
    }

    /** The portal's list of the student's live lessons (reloaded every half minute by the page). */
    public function live(Request $request): Response
    {
        $student = OnlineLessons::ownStudent($this->db(), $this->auth());
        $isParent = $student === null;
        $current = $student ?? \App\Services\Portal::current($this->db(), $this->auth()) ?? throw new HttpException(403);
        $students = \App\Services\Portal::students($this->db(), $this->auth());
        return $this->view('portal/live', [
            'student' => $current, 'children' => count($students) > 1 ? $students : [], 'isParent' => $isParent,
            'meetings' => LiveKit::enabled() ? OnlineLessons::liveForStudent($this->db(), $current) : [],
            'enabled' => LiveKit::enabled(),
            'periods' => TimetableController::periods($this->db(), $this->schoolId(), (int) ($this->auth()->year()['id'] ?? 0)),
        ]);
    }
}
