<?php

return [
    'title' => 'المستندات',
    'upload' => 'رفع مستند',
    'upload_button' => 'رفع',
    'fields' => [
        'title' => 'العنوان',
        'file' => 'الملف',
        'category' => 'الفئة',
        'visibility' => 'من يحق له العرض',
        'size' => 'الحجم',
        'uploaded' => 'رُفع بواسطة',
    ],
    'file_help' => 'الأنواع المسموحة: :types. الحجم الأقصى: :max MB.',
    'visibility' => [
        'restricted' => 'مقيَّد',
        'staff' => 'جميع الطاقم',
    ],
    'visibility_help' => '"مقيَّد": فقط من رفع المستند ومن يحق له عرض السجل المرتبط به.',
    'categories' => [
        'work_plan' => 'خطة عمل',
        'report' => 'تقرير',
        'protocol' => 'محضر',
        'curriculum' => 'منهاج تعليمي',
        'assessment' => 'مستند تقييم',
        'other' => 'أخرى',
    ],
    'empty' => 'لا توجد مستندات للعرض.',
    'download_named' => 'تنزيل :name',
    'delete_named' => 'حذف :name',
    'confirm_delete' => 'هل تريد حذف المستند ":name"؟',
    'uploaded' => 'تم رفع المستند.',
    'deleted' => 'تم حذف المستند.',
    'errors' => [
        'missing' => 'يجب اختيار ملف.',
        'too_large' => 'الملف كبير جدًا. الحجم الأقصى هو :max MB.',
        'failed' => 'فشل رفع الملف. يُرجى المحاولة مجددًا.',
        'type' => 'نوع الملف غير مسموح، أو أن محتوى الملف لا يطابق امتداده.',
    ],
    'attached' => 'مستندات مرفقة',
];
