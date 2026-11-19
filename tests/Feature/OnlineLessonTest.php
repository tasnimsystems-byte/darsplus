<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\LiveKit;
use Tests\TestCase;

/**
 * Online lessons: the teacher opens a live meeting from today's lesson; only the lesson's students
 * (the class, or a study group's members) and staff who see the lesson enter it; the token carries the
 * rights of each role; moderation and ending are the teacher's, and go through the media server.
 */
final class OnlineLessonTest extends TestCase
{
    private int $school;
    private int $year;
    private int $classA;
    private int $classB;
    private int $teacherUser;
    private int $lesson;
    private int $groupLesson;
    /** @var array<string, int> student id by name */
    private array $s = [];
    /** @var array<string, int> user id by name */
    private array $u = [];
    /** @var array<int, array{0: string, 1: array}> calls to the room service */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        LiveKit::override(
            ['enabled' => true, 'url' => 'wss://live.example.test', 'api_url' => '', 'api_key' => 'APIkey', 'api_secret' => 'secret-of-the-test-server-0123456789', 'token_minutes' => 90],
            function (string $url, array $headers, string $body): array {
                $method = substr($url, strrpos($url, '/') + 1);
                $this->calls[] = [$method, json_decode($body, true), $url, $headers];
                if ($method === 'ListParticipants') {
                    return [200, json_encode(['participants' => [
                        ['identity' => 'u' . $this->u['rami'], 'tracks' => [['sid' => 'TR_mic', 'type' => 'AUDIO', 'source' => 'MICROPHONE'], ['sid' => 'TR_cam', 'type' => 'VIDEO', 'source' => 'CAMERA']]],
                        ['identity' => 'u' . $this->teacherUser, 'tracks' => [['sid' => 'TR_tmic', 'type' => 'AUDIO', 'source' => 'MICROPHONE']]],
                    ]])];
                }
                return [200, '{}'];
            }
        );
        $db = App::db();
        $this->school = $this->createSchool(null, 'ar');
        $this->year = $this->createYear($this->school, '2026-27', true, date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+200 days')));
        $level = $this->createLevel($this->school, 'الثامن', 8);
        $this->classA = $this->createClass($this->school, $this->year, $level, 'الثامن 1');
        $this->classB = $this->createClass($this->school, $this->year, $level, 'الثامن 2');
        $this->teacherUser = $this->createUser('t.teacher', [['subject_teacher', $this->school]], ['full_name' => 'سلمى معلمة']);
        $teacher = $this->createTeacher($this->school, 'معلمة', $this->teacherUser);
        $math = $this->createSubject($this->school, 'الرياضيات');
        $assignment = $this->assign($this->school, $this->year, $teacher, $this->classA, $math);
        $this->lesson = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $assignment,
            'lesson_date' => date('Y-m-d'), 'period_number' => 2, 'status' => 'held']);

        foreach (['rami' => $this->classA, 'sami' => $this->classA, 'lina' => $this->classB] as $name => $class) {
            $this->u[$name] = $this->createUser('s.' . $name, [['student', $this->school]], ['full_name' => $name]);
            $this->s[$name] = $this->createStudent($this->school, 'طالب', $class, ['first_name' => $name, 'user_id' => $this->u[$name]]);
        }
        // A study group of classes A and B: Lina (B) is a member, Sami (A) is not.
        $group = $db->insert('study_groups', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'روّاد']);
        $science = $this->createSubject($this->school, 'العلوم');
        $groupA = $this->assign($this->school, $this->year, $teacher, $this->classA, $science);
        $groupB = $this->assign($this->school, $this->year, $teacher, $this->classB, $science);
        $db->run('UPDATE teaching_assignments SET study_group_id = ? WHERE id IN (?, ?)', [$group, $groupA, $groupB]);
        foreach (['rami', 'lina'] as $member) {
            $db->insert('study_group_students', ['school_id' => $this->school, 'group_id' => $group, 'student_id' => $this->s[$member]]);
        }
        $this->groupLesson = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $groupA,
            'lesson_date' => date('Y-m-d'), 'period_number' => 4, 'status' => 'held']);
    }

    protected function tearDown(): void
    {
        LiveKit::override(null);
        parent::tearDown();
    }

    private function start(int $lesson): int
    {
        $this->loginAs($this->teacherUser, $this->school);
        $response = $this->post("/attendance/sessions/$lesson/online");
        $id = (int) App::db()->value('SELECT MAX(id) FROM online_meetings WHERE lesson_session_id = ?', [$lesson]);
        self::assertGreaterThan(0, $id);
        $this->assertRedirect("/online/$id", $response);
        return $id;
    }

    /** @return array the claims of a token response, after checking its signature */
    private function claims(\App\Core\Response $response): array
    {
        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true);
        self::assertSame('wss://live.example.test', $data['url']);
        [$head, $body, $signature] = explode('.', $data['token']);
        self::assertSame(LiveKit::base64url(hash_hmac('sha256', "$head.$body", 'secret-of-the-test-server-0123456789', true)), $signature);
        return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    }

    public function testTeacherOpensAndHostsTheLesson(): void
    {
        $this->loginAs($this->teacherUser, $this->school);
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertStringContainsString('/attendance/sessions/' . $this->lesson . '/online', $page, 'The lesson page offers to start.');

        $id = $this->start($this->lesson);
        self::assertSame($id, $this->start($this->lesson), 'Starting again returns to the same meeting.');
        $room = $this->get("/online/$id");
        self::assertSame(200, $room->status);
        self::assertStringContainsString('connect-src \'self\' wss://live.example.test https://live.example.test', $room->headers['Content-Security-Policy']);
        self::assertStringContainsString('camera=(self)', $room->headers['Permissions-Policy']);
        self::assertStringContainsString('id="meet-end-form"', $room->body);
        self::assertStringContainsString('livekit-client.umd.js', $room->body);

        $claims = $this->claims($this->post("/online/$id/token"));
        self::assertSame('APIkey', $claims['iss']);
        self::assertSame('u' . $this->teacherUser, $claims['sub']);
        self::assertTrue($claims['video']['roomAdmin']);
        self::assertSame(App::db()->value('SELECT room_name FROM online_meetings WHERE id = ?', [$id]), $claims['video']['room']);
        self::assertSame('host', json_decode($claims['metadata'], true)['role']);
        self::assertEqualsWithDelta(time() + 90 * 60, $claims['exp'], 5);

        // The lesson page shows the live meeting with enter and end.
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertStringContainsString('/online/' . $id . '/end', $page);
    }

    public function testStudentsOfTheLessonEnterWithLimitedRights(): void
    {
        $id = $this->start($this->lesson);

        $this->loginAs($this->u['rami'], $this->school);
        self::assertStringContainsString('/online/' . $id, $this->get('/me')->body, 'A banner on the portal home.');
        self::assertStringContainsString('/online/' . $id, $this->get('/me/live')->body);
        self::assertSame(200, $this->get("/online/$id")->status);
        $claims = $this->claims($this->post("/online/$id/token"));
        self::assertSame('u' . $this->u['rami'], $claims['sub']);
        self::assertSame('rami طالب', $claims['name']);
        self::assertArrayNotHasKey('roomAdmin', $claims['video']);
        self::assertSame(['camera', 'microphone'], $claims['video']['canPublishSources'], 'No screen sharing unless the teacher allows it.');
        $this->post("/online/$id/token");
        $row = App::db()->one('SELECT role, joins, student_id FROM online_meeting_participants WHERE meeting_id = ? AND user_id = ?', [$id, $this->u['rami']]);
        self::assertSame(['student', 2, $this->s['rami']], [$row['role'], (int) $row['joins'], (int) $row['student_id']]);

        // A student cannot moderate or end.
        self::assertSame(403, $this->post("/online/$id/moderate", ['action' => 'mute_all'])->status);
        self::assertSame(403, $this->post("/online/$id/end")->status);

        // A student of another class does not enter, nor sees it.
        $this->loginAs($this->u['lina'], $this->school);
        self::assertSame(403, $this->get("/online/$id")->status);
        self::assertSame(403, $this->post("/online/$id/token")->status);
        self::assertStringNotContainsString('/online/' . $id, $this->get('/me/live')->body);
    }

    public function testStudyGroupMeetingIsForItsMembersOfAllClasses(): void
    {
        $id = $this->start($this->groupLesson);
        foreach (['rami' => 200, 'lina' => 200, 'sami' => 403] as $name => $status) {
            $this->loginAs($this->u[$name], $this->school);
            self::assertSame($status, $this->post("/online/$id/token")->status, $name);
        }
        $this->loginAs($this->u['lina'], $this->school);
        self::assertStringContainsString('/online/' . $id, $this->get('/me/live')->body, 'A member from the other class sees it.');
    }

    public function testOtherStaffAndParents(): void
    {
        $id = $this->start($this->lesson);
        // Another teacher who does not see this lesson.
        $other = $this->createUser('t.other', [['subject_teacher', $this->school]]);
        $this->createTeacher($this->school, 'آخر', $other);
        $this->loginAs($other, $this->school);
        self::assertSame(403, $this->get("/online/$id")->status);
        self::assertSame(404, $this->post("/attendance/sessions/{$this->lesson}/online")->status);

        // The principal sees every lesson and moderates.
        $principal = $this->createUser('t.principal', [['principal', $this->school]]);
        $this->loginAs($principal, $this->school);
        self::assertTrue($this->claims($this->post("/online/$id/token"))['video']['roomAdmin']);

        // A parent sees the live lesson but does not enter it.
        $parent = $this->createUser('p.parent', [['parent', $this->school]]);
        App::db()->insert('guardians', ['school_id' => $this->school, 'student_id' => $this->s['rami'], 'full_name' => 'أم رامي', 'user_id' => $parent]);
        $this->loginAs($parent, $this->school);
        $live = $this->get('/me/live')->body;
        self::assertStringContainsString(e(__('online.live_parent')), $live);
        self::assertStringNotContainsString('href="' . url('/online/' . $id) . '"', $live);
        self::assertSame(403, $this->post("/online/$id/token")->status);
    }

    public function testModerationGoesThroughTheServer(): void
    {
        $id = $this->start($this->lesson);
        $room = (string) App::db()->value('SELECT room_name FROM online_meetings WHERE id = ?', [$id]);
        $this->loginAs($this->u['rami'], $this->school);
        $this->post("/online/$id/token");
        $this->loginAs($this->teacherUser, $this->school);
        $this->post("/online/$id/token");
        $rami = 'u' . $this->u['rami'];

        $this->calls = [];
        self::assertSame(200, $this->post("/online/$id/moderate", ['action' => 'mute', 'identity' => $rami])->status);
        $mute = array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'MutePublishedTrack'));
        self::assertCount(1, $mute);
        self::assertSame(['room' => $room, 'identity' => $rami, 'track_sid' => 'TR_mic', 'muted' => true], $mute[0][1]);
        self::assertSame('https://live.example.test/twirp/livekit.RoomService/MutePublishedTrack', $mute[0][2]);
        self::assertStringStartsWith('Bearer ', $mute[0][3]['Authorization']);

        // Mute everyone: the students, never the teacher.
        $this->calls = [];
        $this->post("/online/$id/moderate", ['action' => 'mute_all']);
        self::assertSame(['TR_mic'], array_values(array_map(static fn ($c) => $c[1]['track_sid'], array_filter($this->calls, static fn ($c) => $c[0] === 'MutePublishedTrack'))));

        // Lock the microphones: kept on the meeting, and new tokens carry it.
        $this->calls = [];
        $data = json_decode($this->post("/online/$id/moderate", ['action' => 'lock_mics'])->body, true);
        self::assertTrue($data['micsLocked']);
        $update = array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'UpdateParticipant'));
        self::assertSame(['CAMERA'], $update[0][1]['permission']['canPublishSources']);
        $this->loginAs($this->u['rami'], $this->school);
        self::assertSame(['camera'], $this->claims($this->post("/online/$id/token"))['video']['canPublishSources']);
        $this->loginAs($this->teacherUser, $this->school);

        $this->calls = [];
        $this->post("/online/$id/moderate", ['action' => 'allow_screen', 'identity' => $rami]);
        self::assertSame(['CAMERA', 'SCREEN_SHARE', 'SCREEN_SHARE_AUDIO'], $this->calls[0][1]['permission']['canPublishSources']);
        // Unlocking the microphones keeps the screen the teacher allowed; so does entering again.
        $this->calls = [];
        $this->post("/online/$id/moderate", ['action' => 'unlock_mics']);
        $update = array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'UpdateParticipant'));
        self::assertSame(['CAMERA', 'MICROPHONE', 'SCREEN_SHARE', 'SCREEN_SHARE_AUDIO'], $update[0][1]['permission']['canPublishSources']);
        $this->loginAs($this->u['rami'], $this->school);
        self::assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $this->claims($this->post("/online/$id/token"))['video']['canPublishSources']);
        $this->loginAs($this->teacherUser, $this->school);
        $this->post("/online/$id/moderate", ['action' => 'remove', 'identity' => $rami]);
        self::assertSame('RemoveParticipant', end($this->calls)[0]);

        // Nobody outside the meeting, nor the teacher themselves, nor unknown actions.
        self::assertSame(422, $this->post("/online/$id/moderate", ['action' => 'remove', 'identity' => 'u999999'])->status);
        self::assertSame(422, $this->post("/online/$id/moderate", ['action' => 'mute', 'identity' => 'u' . $this->teacherUser])->status);
        self::assertSame(422, $this->post("/online/$id/moderate", ['action' => 'explode'])->status);

        // The server unreachable: a clear error, not a crash.
        LiveKit::override(LiveKit::config(), static fn (): array => [503, '']);
        self::assertSame(502, $this->post("/online/$id/moderate", ['action' => 'mute', 'identity' => $rami])->status);
    }

    public function testEndingAndAttendance(): void
    {
        $id = $this->start($this->lesson);
        $this->loginAs($this->u['rami'], $this->school);
        $this->post("/online/$id/token");
        $this->loginAs($this->teacherUser, $this->school);

        $this->calls = [];
        $this->assertRedirect("/attendance/sessions/{$this->lesson}", $this->post("/online/$id/end"));
        self::assertSame('DeleteRoom', $this->calls[0][0]);
        self::assertNotNull(App::db()->value('SELECT ended_at FROM online_meetings WHERE id = ?', [$id]));
        self::assertSame(410, $this->post("/online/$id/token")->status);
        $this->assertRedirect("/attendance/sessions/{$this->lesson}", $this->get("/online/$id"));

        // The lesson page tells who joined, and offers to mark the others absent.
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertStringContainsString('data-online-joined="1"', $page);
        self::assertStringContainsString('data-online-joined="0"', $page);
        self::assertStringContainsString('data-online-mark-absent', $page);

        $this->loginAs($this->u['rami'], $this->school);
        self::assertStringNotContainsString('/online/' . $id, $this->get('/me/live')->body);
    }

    public function testOnlyTodayAndOnlyWhenEnabled(): void
    {
        $past = App::db()->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => App::db()->value('SELECT teaching_assignment_id FROM lesson_sessions WHERE id = ?', [$this->lesson]),
            'lesson_date' => date('Y-m-d', strtotime('-1 day')), 'period_number' => 2, 'status' => 'held']);
        $this->loginAs($this->teacherUser, $this->school);
        $this->post("/attendance/sessions/$past/online");
        self::assertNull(App::db()->value('SELECT id FROM online_meetings WHERE lesson_session_id = ?', [$past]));

        // A meeting left open yesterday is no longer live.
        $old = App::db()->insert('online_meetings', ['school_id' => $this->school, 'lesson_session_id' => $past, 'room_name' => 'dp-old',
            'started_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $this->assertRedirect("/attendance/sessions/$past", $this->get("/online/$old"));

        LiveKit::override(['enabled' => false, 'url' => '', 'api_key' => '', 'api_secret' => '']);
        $this->post("/attendance/sessions/{$this->lesson}/online");
        self::assertNull(App::db()->value('SELECT id FROM online_meetings WHERE lesson_session_id = ?', [$this->lesson]));
        self::assertStringContainsString(e(__('online.card_disabled')), $this->get("/attendance/sessions/{$this->lesson}")->body);
    }
}
