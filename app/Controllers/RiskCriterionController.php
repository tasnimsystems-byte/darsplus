<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Validator;
use App\Services\RiskIndicatorService;

/** The school's own thresholds for early identification. */
final class RiskCriterionController extends CrudController
{
    protected function definition(): array
    {
        $types = [];
        foreach (RiskIndicatorService::TYPES as $type) {
            $types[$type] = __('risk_criteria.types.' . $type);
        }
        return [
            'table' => 'risk_criteria', 'entity' => 'risk_criterion', 'path' => '/risk/criteria', 'lang' => 'risk_criteria',
            'module' => 'risk', 'order' => 't.id', 'unique' => [['name']],
            'fields' => [
                'name' => ['rules' => 'required|max:150', 'list' => true, 'attrs' => 'maxlength="150"'],
                'criterion_type' => ['type' => 'select', 'rules' => 'required', 'options' => $types, 'list' => true],
                'threshold' => ['rules' => ['regex:/^\d{1,3}([.,]\d)?$/'], 'list' => true, 'help' => 'risk_criteria.threshold_help',
                    'attrs' => 'inputmode="decimal" dir="ltr" maxlength="5"'],
                'min_count' => ['type' => 'number', 'rules' => 'int|min:1|max:500', 'list' => true, 'help' => 'risk_criteria.min_count_help',
                    'attrs' => 'min="1" max="500"'],
                'is_active' => ['type' => 'checkbox', 'list' => true, 'default' => 1],
            ],
        ];
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        $needsThreshold = in_array($data['criterion_type'], ['low_average', 'decline', 'multi_subject'], true);
        $needsCount = in_array($data['criterion_type'], ['multi_subject', 'absences'], true);
        if ($needsThreshold && $data['threshold'] === null) {
            $v->addError('threshold', __('validation.required', ['field' => __('risk_criteria.fields.threshold')]));
        }
        if ($needsThreshold && $data['threshold'] !== null && (float) str_replace(',', '.', $data['threshold']) > 100) {
            $v->addError('threshold', __('validation.max', ['field' => __('risk_criteria.fields.threshold'), 'arg' => 100]));
        }
        if ($needsCount && $data['min_count'] === null) {
            $v->addError('min_count', __('validation.required', ['field' => __('risk_criteria.fields.min_count')]));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        $data['threshold'] = $data['threshold'] === null ? null : str_replace(',', '.', $data['threshold']);
        return $data;
    }
}
