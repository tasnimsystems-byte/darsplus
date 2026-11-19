<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccessScope;
use App\Services\LessonAttachments;
use App\Services\Portal;

/**
 * A file attached to a lesson's homework, for whoever may see that lesson:
 *   - staff with attendance.view who see the lesson's assignment (the access scope);
 *   - a student or parent in the portal, when the lesson is one of their child's (Portal::assignments)
 *     and it took place.
 * Anyone else gets 404, as if there were no such file. Always served as a download.
 */
final class LessonAttachmentController extends Controller
{
    public function download(Request $request, int $id): Response
    {
        $attachment = $this->db()->one(
            "SELECT la.*, ls.teaching_assignment_id, ls.status
               FROM lesson_attachments la JOIN lesson_sessions ls ON ls.id = la.lesson_session_id
              WHERE la.id = ? AND la.school_id = ? AND la.kind = 'file'",
            [$id, $this->schoolId()]
        );
        if ($attachment === null || !$this->maySee($attachment)) {
            throw new HttpException(404);
        }
        $path = LessonAttachments::path($attachment) ?? throw new HttpException(404);
        $this->audit('download', 'lesson_attachment', $id);
        return Response::download($path, (string) $attachment['original_name'], (string) $attachment['mime_type']);
    }

    private function maySee(array $attachment): bool
    {
        $assignment = (int) $attachment['teaching_assignment_id'];
        if (can('attendance.view')) {
            $scope = new AccessScope($this->db(), $this->auth());
            [$condition, $params] = $scope->assignmentCondition('ta');
            if ($this->db()->value("SELECT 1 FROM teaching_assignments ta WHERE ta.id = ? AND $condition", [$assignment, ...$params]) !== null) {
                return true;
            }
        }
        if (can('portal.view') && $attachment['status'] === 'held') {
            foreach (Portal::students($this->db(), $this->auth()) as $student) {
                if (in_array($assignment, Portal::assignments($this->db(), $student), true)) {
                    return true;
                }
            }
        }
        return false;
    }
}
