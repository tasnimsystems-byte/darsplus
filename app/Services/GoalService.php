<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class GoalService
{
    public const STATUSES = ['not_started', 'in_progress', 'completed', 'needs_intervention'];

    /** "Delayed" is never stored: it is what an unfinished goal becomes once its end date has passed. */
    public static function displayStatus(array $goal, ?string $today = null): string
    {
        return $goal['status'] !== 'completed' && $goal['end_date'] < ($today ?? date('Y-m-d')) ? 'delayed' : $goal['status'];
    }

    /** @return array{total: int, completed: int, delayed: int, needs_intervention: int, average_progress: ?int} */
    public static function summary(array $goals, ?string $today = null): array
    {
        $summary = ['total' => count($goals), 'completed' => 0, 'delayed' => 0, 'needs_intervention' => 0, 'average_progress' => null];
        foreach ($goals as $goal) {
            $status = self::displayStatus($goal, $today);
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        if ($goals !== []) {
            $summary['average_progress'] = (int) round(array_sum(array_column($goals, 'progress_percent')) / count($goals));
        }
        return $summary;
    }

    /**
     * When a goal has actions, its progress is the share of them that is done.
     * A goal without actions keeps the percentage reported manually.
     */
    public static function recalculate(Database $db, int $goalId): void
    {
        $counts = $db->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'done'), 0) AS done FROM goal_actions WHERE goal_id = ? AND status <> 'cancelled'",
            [$goalId]
        );
        if ((int) $counts['total'] === 0) {
            return;
        }
        $percent = (int) round((int) $counts['done'] / (int) $counts['total'] * 100);
        $status = $db->value('SELECT status FROM annual_goals WHERE id = ?', [$goalId]);
        $update = ['progress_percent' => $percent];
        if ($status === 'not_started' && $percent > 0) {
            $update['status'] = 'in_progress';
        }
        $db->update('annual_goals', $update, ['id' => $goalId]);
    }
}
