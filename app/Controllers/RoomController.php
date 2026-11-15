<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;

final class RoomController extends CrudController
{
    protected function definition(): array
    {
        return [
            'table' => 'rooms', 'entity' => 'room', 'path' => '/rooms',
            'lang' => 'rooms', 'module' => 'structure', 'order' => 't.name', 'unique' => [['name']],
            'fields' => [
                'name' => ['rules' => 'required|max:60', 'list' => true, 'attrs' => 'maxlength="60"'],
                'capacity' => ['type' => 'number', 'rules' => 'int|min:1|max:2000', 'list' => true, 'attrs' => 'min="1" max="2000"'],
            ],
        ];
    }
}
