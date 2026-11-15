<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;

final class DomainController extends CrudController
{
    protected function definition(): array
    {
        return [
            'table' => 'pedagogical_domains', 'entity' => 'pedagogical_domain', 'path' => '/domains',
            'lang' => 'domains', 'module' => 'work_plan', 'order' => 't.name', 'unique' => [['name']],
            'fields' => ['name' => ['rules' => 'required|max:100', 'list' => true, 'attrs' => 'maxlength="100"']],
        ];
    }
}
