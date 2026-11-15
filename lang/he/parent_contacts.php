<?php

return [
    'title' => 'קשר עם הורים',
    'add' => 'תיעוד קשר חדש',
    'edit' => 'עריכת התיעוד',
    'fields' => [
        'contacted_on' => 'תאריך',
        'student_id' => 'תלמיד/ה',
        'guardian_id' => 'הורה / אפוטרופוס',
        'contact_type' => 'סוג הקשר',
        'internal_notes' => 'תיעוד פנימי',
        'shared_summary' => 'סיכום למסירה להורים',
        'decisions' => 'החלטות והמלצות',
        'follow_up_date' => 'מועד מעקב',
        'follow_up_status' => 'מעקב',
    ],
    'types' => [
        'meeting' => 'פגישה',
        'call' => 'שיחת טלפון',
        'message' => 'הודעה',
        'inquiry' => 'פנייה של ההורים',
    ],
    'follow_up' => [
        'none' => 'לא נדרש',
        'open' => 'ממתין לטיפול',
        'done' => 'טופל',
    ],
    'staff' => 'איש הצוות',
    'guardian_help' => 'הורים מוגדרים בכרטיס התלמיד.',
    'internal_help' => 'לצוות בלבד. אינו מופיע בסיכום המודפס להורים. אין להזכיר תלמידים אחרים בשמם.',
    'shared_help' => 'הטקסט היחיד שנמסר למשפחה. לנסח בהתאם.',
    'errors' => [
        'future' => 'התאריך אינו יכול להיות בעתיד.',
        'guardian_mismatch' => 'ההורה שנבחר אינו משויך לתלמיד זה.',
    ],
    'guardian_fields' => [
        'full_name' => 'שם מלא',
        'relation' => 'קרבה',
        'phone' => 'טלפון',
        'email' => 'דוא"ל',
        'is_primary' => 'איש קשר ראשי',
    ],
    'record' => 'תיעוד קשר עם הורים',
    'print_summary' => 'סיכום להורים',
    'summary_title' => 'סיכום שיחה',
    'guardians' => 'הורים ואפוטרופוסים',
    'add_guardian' => 'הוספת הורה',
];
