<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;

final class GradeLevelController extends CrudController
{
    protected function definition(): array
    {
        return [
            'table' => 'grade_levels', 'entity' => 'grade_level', 'path' => '/grade-levels',
            'lang' => 'grade_levels', 'module' => 'structure', 'order' => 't.level_number',
            'unique' => [['name'], ['level_number']],
            'fields' => [
                'name' => ['rules' => 'required|max:40', 'list' => true, 'attrs' => 'maxlength="40"'],
                'level_number' => ['type' => 'number', 'rules' => 'required|int|min:1|max:14', 'list' => true,
                    'help' => 'grade_levels.level_number_help', 'attrs' => 'min="1" max="14"'],
            ],
        ];
    }
}
