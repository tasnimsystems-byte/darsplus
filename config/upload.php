<?php

declare(strict_types=1);

return [
    'max_bytes' => (int) env('UPLOAD_MAX_MB', 10) * 1024 * 1024,

    // extension => MIME types that finfo may legitimately report for it.
    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ],

    'categories' => ['work_plan', 'report', 'protocol', 'curriculum', 'assessment', 'other'],
];
