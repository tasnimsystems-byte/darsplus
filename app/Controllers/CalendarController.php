<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Validator;

final class CalendarController extends CrudController
{
    public const TYPES = ['holiday', 'exam', 'meeting', 'event', 'other'];

    protected function definition(): array
    {
        $types = [];
        foreach (self::TYPES as $type) {
            $types[$type] = __('calendar.types.' . $type);
        }
        return [
            'table' => 'calendar_events', 'entity' => 'calendar_event', 'path' => '/calendar',
            'lang' => 'calendar', 'module' => 'structure', 'scope' => 'year', 'order' => 't.start_date, t.id',
            'nameField' => 'title',
            'filters' => ['event_type' => ['label' => 'calendar.fields.event_type', 'options' => $types, 'sql' => 't.event_type = ?']],
            'fields' => [
                'title' => ['rules' => 'required|max:150', 'list' => true, 'attrs' => 'maxlength="150"'],
                'event_type' => ['type' => 'select', 'rules' => 'required', 'options' => $types, 'list' => true, 'default' => 'event'],
                'start_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'end_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
            ],
        ];
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['end_date'] < $data['start_date']) {
            $v->addError('end_date', __('app.errors.date_order'));
        }
    }
}
