<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;

final class SubjectController extends CrudController
{
    protected function definition(): array
    {
        return [
            'table' => 'subjects', 'entity' => 'subject', 'path' => '/subjects',
            'lang' => 'subjects', 'module' => 'structure', 'order' => 't.name',
            'unique' => [['name']], 'search' => ['t.name', 't.code'],
            'fields' => [
                'name' => ['rules' => 'required|max:100', 'list' => true, 'attrs' => 'maxlength="100"'],
                'code' => ['rules' => 'max:20', 'list' => true, 'attrs' => 'maxlength="20" dir="ltr"'],
                'is_graded' => ['type' => 'checkbox', 'list' => true, 'default' => 1, 'help' => 'subjects.is_graded_help'],
                'is_active' => ['type' => 'checkbox', 'list' => true, 'default' => 1],
            ],
        ];
    }
}
