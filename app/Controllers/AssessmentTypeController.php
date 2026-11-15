<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;

final class AssessmentTypeController extends CrudController
{
    public const CATEGORIES = ['exam', 'assignment', 'project', 'alternative', 'periodic'];

    protected function definition(): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $category) {
            $categories[$category] = __('assessment_types.categories.' . $category);
        }
        return [
            'table' => 'assessment_types', 'entity' => 'assessment_type', 'path' => '/assessment-types',
            'lang' => 'assessment_types', 'module' => 'assessments', 'order' => 't.id', 'unique' => [['name']],
            'fields' => [
                'name' => ['rules' => 'required|max:80', 'list' => true, 'attrs' => 'maxlength="80"'],
                'category' => ['type' => 'select', 'rules' => 'required', 'options' => $categories, 'list' => true],
                'is_numeric' => ['type' => 'checkbox', 'list' => true, 'default' => 1, 'help' => 'assessment_types.numeric_help'],
            ],
        ];
    }
}
