# SPMS – מבנה מסד הנתונים (ERD)

גרסה 0.1 · נלווה ל-[01-specification.md](01-specification.md)

## מוסכמות

- מנוע InnoDB, קידוד `utf8mb4` (collation: `utf8mb4_unicode_ci`).
- מפתח ראשי `id BIGINT UNSIGNED AUTO_INCREMENT` בכל טבלה.
- `created_at`, `updated_at` בכל טבלה; `created_by`, `updated_by` (FK ל-`users`) בטבלאות תוכן.
- **Soft delete** (`deleted_at`) בטבלאות המסומנות ב-🗑; בשאר – מחיקה אמיתית או אי-מחיקה (יומנים).
- **הפרדת שנים:** טבלאות המסומנות ב-📅 נושאות `academic_year_id` (ישירות או דרך אב), וכל שאילתה מסוננת לפיו.
- סטטוסים וסוגים נשמרים כ-`VARCHAR` עם רשימת ערכים סגורה בקוד (ולא `ENUM`), כדי לאפשר הרחבה ללא שינוי סכימה.
- הערה לשמות: הדרישה מציינת `grades` לשכבות. בחרתי **`grade_levels`** כדי למנוע בלבול עם "grades" במובן ציונים.

סה"כ 66 טבלאות בתשעה תחומים.

---

## 0. ריבוי בתי ספר (הוחלט: רשת)

```mermaid
erDiagram
    schools ||--o{ academic_years : ""
    schools ||--o{ user_roles : "role granted at"
    schools ||--o{ students : ""
    schools ||--o{ teachers : ""
    schools ||--o{ subjects : ""
    schools ||--o{ grade_levels : ""

    schools {
        bigint id PK
        varchar name
        varchar code UK
        varchar default_locale "he|ar"
        bool is_active
        datetime deleted_at
    }
```

**כללי הבידוד בין בתי ספר**
- 🏫 **`school_id NOT NULL` ישיר** בטבלאות השורש: `academic_years`, `grade_levels`, `subjects`, `rooms`, `teachers`, `students`, `guardians`, `school_settings`, `assessment_types`, `pedagogical_domains`, `risk_criteria`, `trainings`, `tasks`, `documents`, `notifications`, `audit_logs`. בתרשימים שבהמשך העמודה אינה מצוירת שוב, אך היא קיימת.
- **עדכון שלב ד (החלטות D27, D34):** `term_grades`, `attendance_summaries` ו-`indicator_measurements` לא נוצרו – הנתונים מחושבים מהרשומות. הסכימה בפועל נמצאת ב-`database/migrations/`, והיא הקובעת.
- **עדכון שלב ג (החלטה D17):** גם טבלאות-הבת של מבנה בית הספר (`academic_terms`, `classes`, `class_enrollments`, `teaching_assignments`, `teacher_subjects`, `staff_positions`, `calendar_events`) נושאות `school_id`, כדי שהקשרים ביניהן יהיו מפתחות זרים מורכבים. הכלל יחול גם על הטבלאות של השלבים הבאים.
- אילוצי הייחודיות בטבלאות השורש הם **לכל בית ספר**: `UNIQUE(school_id, name)`, `UNIQUE(school_id, student_code)` וכו' (המסומן `UK` בתרשימים).
- `users` גלובלית (אדם אחד יכול לעבוד בשני בתי ספר); השיוך נעשה ב-`user_roles(user_id, role_id, school_id, academic_year_id)`. `school_id = NULL` שמור לתפקידי רשת (מנהל מערכת, מפקח רשת).
- `roles`: תפקידי המערכת גלובליים (`school_id NULL`); תפקיד מותאם שבית ספר יוצר נושא את ה-`school_id` שלו.
- **אכיפה:** בית הספר הפעיל נשמר ב-session לאחר ההתחברות; `ScopedRepository` מוסיף `school_id = ?` לכל שאילתה, ו-FK מורכבים `(school_id, id)` בקשרים בין טבלאות שורש מונעים ברמת המסד קישור תלמיד של בית ספר א' לכיתה של בית ספר ב'. לכל מודול תיכתב בדיקת "דליפה בין בתי ספר".

---

## 1. זהות, הרשאות וביקורת

```mermaid
erDiagram
    users ||--o{ user_roles : "has"
    roles ||--o{ user_roles : ""
    roles ||--o{ role_permissions : ""
    permissions ||--o{ role_permissions : ""
    users ||--o{ user_scopes : "restricted to"
    users ||--o{ login_attempts : ""
    users ||--o{ audit_logs : "performed"
    users ||--o{ notifications : "receives"

    users {
        bigint id PK
        varchar username UK
        varchar email UK
        varchar password_hash
        varchar full_name
        varchar locale
        bool is_active
        bool must_change_password
        datetime last_login_at
        datetime deleted_at
    }
    roles {
        bigint id PK
        varchar code UK
        varchar name
        bool is_system
    }
    permissions {
        bigint id PK
        varchar module
        varchar action
        varchar code UK "module.action"
    }
    role_permissions {
        bigint role_id FK
        bigint permission_id FK
    }
    user_roles {
        bigint user_id FK
        bigint role_id FK
        bigint academic_year_id FK "nullable"
    }
    user_scopes {
        bigint id PK
        bigint user_id FK
        bigint academic_year_id FK
        varchar scope_type "all|grade_level|class|subject"
        bigint scope_id "nullable"
    }
    login_attempts {
        bigint id PK
        varchar username
        varchar ip_address
        bool success
        datetime attempted_at
    }
    audit_logs {
        bigint id PK
        bigint user_id FK
        varchar action "create|update|delete|view|export|login"
        varchar entity_type
        bigint entity_id
        json old_values
        json new_values
        varchar ip_address
        datetime created_at
    }
    notifications {
        bigint id PK
        bigint user_id FK
        varchar type
        varchar title
        varchar link
        datetime read_at
    }
```

**קשרים מרכזיים**
- משתמש ↔ תפקיד: רבים-לרבים. שיוך יכול להיות תלוי-שנה (מחנך השנה אינו בהכרח מחנך בשנה הבאה).
- `user_scopes` מוסיף היקף ידני מעבר להיקף הנגזר אוטומטית מ-`teaching_assignments` ומ-`classes.homeroom_teacher_id`.
- `audit_logs` לקריאה והוספה בלבד; אין לו עדכון או מחיקה דרך היישום.

---

## 2. מבנה בית הספר

```mermaid
erDiagram
    academic_years ||--o{ academic_terms : ""
    academic_years ||--o{ classes : ""
    grade_levels ||--o{ classes : ""
    classes ||--o{ class_enrollments : ""
    students ||--o{ class_enrollments : ""
    students ||--o{ student_guardians : ""
    guardians ||--o{ student_guardians : ""
    teachers ||--o| users : "login (optional)"
    teachers ||--o{ teaching_assignments : ""
    classes ||--o{ teaching_assignments : ""
    subjects ||--o{ teaching_assignments : ""
    teachers ||--o{ staff_positions : ""
    teachers ||--o{ teacher_subjects : ""
    subjects ||--o{ teacher_subjects : ""
    academic_years ||--o{ calendar_events : ""

    school_settings {
        bigint id PK
        varchar setting_key UK
        text setting_value
    }
    academic_years {
        bigint id PK
        varchar name UK
        date start_date
        date end_date
        bool is_current
        bool is_locked
    }
    academic_terms {
        bigint id PK
        bigint academic_year_id FK
        varchar name
        tinyint sort_order
        date start_date
        date end_date
    }
    grade_levels {
        bigint id PK
        varchar name UK
        tinyint level_number
    }
    classes {
        bigint id PK
        bigint academic_year_id FK
        bigint grade_level_id FK
        varchar name
        bigint homeroom_teacher_id FK
        bigint room_id FK
        datetime deleted_at
    }
    subjects {
        bigint id PK
        varchar name UK
        varchar code
        bool is_active
    }
    rooms {
        bigint id PK
        varchar name UK
        smallint capacity
    }
    teachers {
        bigint id PK
        bigint user_id FK "nullable, UK"
        varchar first_name
        varchar last_name
        varchar staff_code UK
        varchar phone
        bool is_active
        datetime deleted_at
    }
    teacher_subjects {
        bigint teacher_id FK
        bigint subject_id FK
    }
    staff_positions {
        bigint id PK
        bigint teacher_id FK
        bigint academic_year_id FK
        varchar position_type
        bigint grade_level_id FK "nullable"
        bigint subject_id FK "nullable"
        text responsibilities
    }
    students {
        bigint id PK
        varchar student_code UK "internal id"
        varchar first_name
        varchar last_name
        char gender "nullable"
        date birth_date "nullable"
        varchar status "active|left|graduated"
        datetime deleted_at
    }
    guardians {
        bigint id PK
        varchar full_name
        varchar phone
        varchar email
    }
    student_guardians {
        bigint student_id FK
        bigint guardian_id FK
        varchar relation
        bool is_primary
    }
    class_enrollments {
        bigint id PK
        bigint student_id FK
        bigint class_id FK
        date enrolled_on
        date left_on "nullable"
    }
    teaching_assignments {
        bigint id PK
        bigint academic_year_id FK
        bigint teacher_id FK
        bigint class_id FK
        bigint subject_id FK
        decimal weekly_hours
        varchar group_name "nullable"
    }
    calendar_events {
        bigint id PK
        bigint academic_year_id FK
        varchar title
        varchar event_type
        date start_date
        date end_date
    }
```

**קשרים מרכזיים**
- **כיתה שייכת לשנה.** "ז1 תשפ"ז" ו"ז1 תשפ"ח" הן שתי רשומות – כך ההיסטוריה נשמרת מעצמה.
- **תלמיד ומורה אינם תלויי-שנה;** השיוך לשנה נעשה דרך `class_enrollments` ו-`teaching_assignments`. מעבר שנה = יצירת רישומים חדשים, ללא נגיעה בישנים.
- `class_enrollments`: `UNIQUE(student_id, class_id)`; העברת כיתה באמצע שנה = סגירת `left_on` ופתיחת רישום חדש.
- `teaching_assignments`: `UNIQUE(academic_year_id, teacher_id, class_id, subject_id, group_name)` – הציר שממנו נגזר היקף הגישה של מורה.
- `teachers.user_id` אופציונלי: מורה יכול להתקיים ברשומות ללא חשבון כניסה.

---

## 3. תוכנית העבודה הבית-ספרית 📅

```mermaid
erDiagram
    academic_years ||--o| work_plans : ""
    work_plans ||--o{ plan_objectives : "מטרות"
    plan_objectives ||--o{ annual_goals : "יעדים"
    pedagogical_domains ||--o{ annual_goals : ""
    annual_goals ||--o{ goal_indicators : "KPI"
    annual_goals ||--o{ goal_actions : ""
    annual_goals ||--o{ goal_progress_updates : ""
    goal_indicators ||--o{ indicator_measurements : ""
    users ||--o{ annual_goals : "owner"
    users ||--o{ goal_actions : "owner"

    work_plans {
        bigint id PK
        bigint academic_year_id FK "UK"
        text vision
        varchar status "draft|approved"
        bigint approved_by FK
    }
    plan_objectives {
        bigint id PK
        bigint work_plan_id FK
        varchar title
        text description
        tinyint sort_order
    }
    pedagogical_domains {
        bigint id PK
        varchar name UK
    }
    annual_goals {
        bigint id PK
        bigint plan_objective_id FK
        bigint domain_id FK
        varchar title
        text description
        text baseline_description
        text target_description
        bigint owner_user_id FK
        date start_date
        date end_date
        tinyint progress_percent
        varchar status
        text effectiveness_review
        datetime deleted_at
    }
    goal_indicators {
        bigint id PK
        bigint goal_id FK
        varchar name
        varchar unit
        decimal baseline_value
        decimal target_value
        decimal current_value
    }
    indicator_measurements {
        bigint id PK
        bigint indicator_id FK
        date measured_on
        decimal value
        varchar note
    }
    goal_actions {
        bigint id PK
        bigint goal_id FK
        varchar title
        bigint owner_user_id FK
        date start_date
        date due_date
        text resources
        decimal budget "nullable"
        varchar status
        datetime completed_at
    }
    goal_progress_updates {
        bigint id PK
        bigint goal_id FK
        tinyint progress_percent
        varchar status
        text note
        bigint created_by FK
    }
```

**קשרים מרכזיים**
- היררכיה: שנה → תוכנית עבודה (אחת לשנה) → מטרות → יעדים → מדדים ופעולות.
- `progress_percent` של יעד מחושב מהפעולות שהושלמו, עם אפשרות לעדכון ידני מנומק (נשמר ב-`goal_progress_updates`, כך שנשמרת היסטוריית ההתקדמות).
- הסטטוס "מתעכב" **מחושב** (מועד עבר ולא הושלם) ואינו נשמר ידנית; "דורש התערבות" נקבע ידנית.

---

## 4. תוכניות לימודים 📅

```mermaid
erDiagram
    academic_years ||--o{ curriculum_plans : ""
    subjects ||--o{ curriculum_plans : ""
    grade_levels ||--o{ curriculum_plans : ""
    curriculum_plans ||--o{ curriculum_units : ""
    curriculum_units ||--o{ curriculum_topics : ""
    curriculum_topics ||--o{ curriculum_topics : "sub-topic"
    curriculum_topics ||--o{ curriculum_progress : ""
    teaching_assignments ||--o{ curriculum_progress : ""

    curriculum_plans {
        bigint id PK
        bigint academic_year_id FK
        bigint subject_id FK
        bigint grade_level_id FK
        bigint class_id FK "nullable = whole level"
        bigint owner_teacher_id FK
        varchar title
        varchar status "draft|approved"
        datetime deleted_at
    }
    curriculum_units {
        bigint id PK
        bigint plan_id FK
        bigint term_id FK
        varchar title
        tinyint sort_order
        date planned_start
        date planned_end
    }
    curriculum_topics {
        bigint id PK
        bigint unit_id FK
        bigint parent_topic_id FK "nullable"
        varchar title
        text learning_objectives
        text skills
        text teaching_methods
        text assessment_methods
        date planned_week_start
        decimal planned_hours
        smallint sort_order
    }
    curriculum_progress {
        bigint id PK
        bigint topic_id FK
        bigint teaching_assignment_id FK
        varchar status "not_started|in_progress|done|skipped"
        date actual_date
        decimal actual_hours
        text note
    }
```

**קשרים מרכזיים**
- תוכנית אחת לשכבה+מקצוע (או לכיתה מסוימת); **הביצוע נרשם לכל שיבוץ הוראה בנפרד** – `UNIQUE(topic_id, teaching_assignment_id)`. כך אפשר להשוות תכנון מול ביצוע בין כיתות מקבילות.
- תצוגה חודשית/שבועית נגזרת מ-`planned_week_start`; אין טבלאות נפרדות לכל רמת זמן.

---

## 5. הערכה, הישגים ונוכחות 📅

```mermaid
erDiagram
    teaching_assignments ||--o{ assessments : ""
    academic_terms ||--o{ assessments : ""
    assessment_types ||--o{ assessments : ""
    assessments ||--o{ assessment_results : ""
    students ||--o{ assessment_results : ""
    students ||--o{ term_grades : ""
    academic_terms ||--o{ term_grades : ""
    subjects ||--o{ term_grades : ""
    teaching_assignments ||--o{ lesson_sessions : ""
    lesson_sessions ||--o{ attendance_records : ""
    students ||--o{ attendance_records : ""

    assessment_types {
        bigint id PK
        varchar name UK
        varchar category "exam|assignment|project|alternative|periodic"
        bool is_numeric
    }
    assessments {
        bigint id PK
        bigint teaching_assignment_id FK
        bigint term_id FK
        bigint type_id FK
        varchar title
        date assessment_date
        decimal max_score
        decimal weight
        varchar status "draft|published"
        datetime deleted_at
    }
    assessment_results {
        bigint id PK
        bigint assessment_id FK
        bigint student_id FK
        decimal score "nullable"
        varchar result_status "graded|absent|exempt|pending"
        varchar verbal_evaluation
        text comment
    }
    term_grades {
        bigint id PK
        bigint student_id FK
        bigint subject_id FK
        bigint term_id FK
        bigint class_id FK
        decimal calculated_grade
        decimal final_grade
        text teacher_comment
    }
    lesson_sessions {
        bigint id PK
        bigint teaching_assignment_id FK
        date lesson_date
        tinyint period_number
        varchar status "held|cancelled"
        bigint recorded_by FK
        datetime recorded_at
    }
    attendance_records {
        bigint id PK
        bigint lesson_session_id FK
        bigint student_id FK
        varchar status "present|absent|late|justified"
        varchar note
    }
```

**קשרים מרכזיים**
- **ציון אינו קיים ללא הקשר:** `assessment_results` → `assessments` → (שיבוץ הוראה ⇒ מקצוע + כיתה + מורה) + תקופה + סוג. כל ה-FK הם `NOT NULL`.
- `UNIQUE(assessment_id, student_id)`. תלמיד נעדר מסומן `absent` עם `score = NULL` ואינו נכנס לממוצע (לא מחושב כאפס).
- `term_grades` שומר גם ציון מחושב (ממוצע משוקלל) וגם ציון סופי שקבע המורה – הפער גלוי. `UNIQUE(student_id, subject_id, term_id)`. הוא שומר `class_id` כדי שממוצעים היסטוריים לא ישתנו אם תלמיד עבר כיתה.
- ממוצעי כיתה/שכבה/בית ספר **מחושבים בשאילתה** ואינם נשמרים – אין סיכון לנתון מצרפי שאינו מסונכרן.
- **נוכחות מלאה לפי שיעור (הוחלט):** מורה פותח `lesson_session` (שיבוץ + תאריך + מספר שיעור, `UNIQUE` על השלושה) ומסמן נוכחות; `UNIQUE(lesson_session_id, student_id)`. כברירת מחדל נשמרות רק חריגות (נעדר/מאחר/מוצדק) ותלמיד ללא רשומה בשיעור שדווח נחשב נוכח – כך ההזנה מהירה והטבלה קטנה פי 10 בערך. שיעורי נוכחות לתלמיד/כיתה/תקופה מחושבים בשאילתה.
- כיוון שאין במערכת מערכת שעות, השיעור נפתח ידנית על ידי המורה ("השיעורים שלי היום"). ייבוא מערכת שעות יאפשר בעתיד פתיחה אוטומטית – ראו החלטה פתוחה 7 במסמך האפיון.

---

## 6. איתור והתערבות 📅

```mermaid
erDiagram
    risk_criteria ||--o{ risk_indications : ""
    students ||--o{ risk_indications : ""
    academic_terms ||--o{ risk_indications : ""
    risk_indications }o--o| student_interventions : "led to"
    students ||--o{ student_interventions : ""
    student_interventions ||--o{ intervention_actions : ""
    student_interventions ||--o{ intervention_reviews : ""
    users ||--o{ student_interventions : "owner"

    risk_criteria {
        bigint id PK
        varchar name
        varchar criterion_type "low_avg|decline|absences|multi_subject_fail"
        json parameters "thresholds"
        bool is_active
    }
    risk_indications {
        bigint id PK
        bigint student_id FK
        bigint criterion_id FK
        bigint term_id FK
        json evidence "values that triggered"
        varchar review_status "new|monitoring|intervention|dismissed"
        bigint reviewed_by FK
        datetime reviewed_at
        text review_note
    }
    student_interventions {
        bigint id PK
        bigint student_id FK
        bigint academic_year_id FK
        bigint subject_id FK "nullable"
        varchar difficulty_area
        text situation_description
        text baseline
        text improvement_goal
        text success_measure
        bigint owner_user_id FK
        date start_date
        date review_date
        varchar status "active|completed|paused|cancelled"
        text recommendations
        datetime deleted_at
    }
    intervention_actions {
        bigint id PK
        bigint intervention_id FK
        varchar description
        bigint owner_user_id FK
        date due_date
        varchar status
    }
    intervention_reviews {
        bigint id PK
        bigint intervention_id FK
        date review_date
        text findings
        varchar progress_rating
        text next_steps
        bigint created_by FK
    }
```

**קשרים מרכזיים**
- `risk_indications.evidence` שומר את הערכים שהפעילו את הקריטריון (למשל: ממוצע 52 מול סף 55) – כדי שאיש הצוות יראה **למה** ולא רק **מי**.
- אינדיקציה אינה יוצרת התערבות מעצמה. המעבר ל-`intervention` דורש `reviewed_by` ונרשם ביומן. `UNIQUE(student_id, criterion_id, term_id)` מונע כפילות בהרצות חוזרות.
- ציר הזמן של תלמיד נבנה מאיחוד: `intervention_actions` + `intervention_reviews` + `parent_contacts`.

---

## 7. צוות ותהליכי הוראה

```mermaid
erDiagram
    teachers ||--o{ teacher_development_plans : ""
    teacher_development_plans ||--o{ teacher_goals : ""
    teachers ||--o{ teacher_trainings : ""
    trainings ||--o{ teacher_trainings : ""
    teachers ||--o{ observations : "observed"
    users ||--o{ observations : "observer"
    observations ||--o{ observation_recommendations : ""

    teacher_development_plans {
        bigint id PK
        bigint teacher_id FK
        bigint academic_year_id FK
        text summary
        varchar status
    }
    teacher_goals {
        bigint id PK
        bigint plan_id FK
        varchar title
        text success_measure
        date due_date
        varchar status
    }
    trainings {
        bigint id PK
        varchar title
        varchar provider
        smallint hours
        date start_date
    }
    teacher_trainings {
        bigint teacher_id FK
        bigint training_id FK
        varchar status "planned|in_progress|completed"
    }
    observations {
        bigint id PK
        bigint teacher_id FK
        bigint observer_user_id FK
        bigint academic_year_id FK
        bigint teaching_assignment_id FK "nullable"
        varchar record_type "lesson_visit|observation|conversation"
        date observed_on
        text strengths
        text areas_for_growth
        text feedback
        bool shared_with_teacher
        datetime deleted_at
    }
    observation_recommendations {
        bigint id PK
        bigint observation_id FK
        varchar recommendation
        date follow_up_date
        varchar status
        text follow_up_note
    }
```

**קשרים מרכזיים**
- טבלת `teachers` מכילה נתוני ניהול פדגוגי בלבד. אין בה שכר, מצב משפחתי, מידע רפואי או הערכות משמעת.
- `observations` היא הטבלה הרגישה ביותר ביחס לעובדים: גישה רק לצופה, להנהלה מורשית, ולמורה עצמו – ורק כאשר `shared_with_teacher = 1`.

---

## 8. ישיבות, משימות וקשר עם הורים

```mermaid
erDiagram
    meetings ||--o{ meeting_participants : ""
    users ||--o{ meeting_participants : ""
    meetings ||--o{ meeting_agenda_items : ""
    meetings ||--o| meeting_minutes : ""
    meetings ||--o{ meeting_decisions : ""
    meeting_decisions ||--o{ tasks : "generates"
    users ||--o{ tasks : "assignee"
    tasks ||--o{ task_comments : ""
    students ||--o{ parent_contacts : ""
    guardians ||--o{ parent_contacts : ""

    meetings {
        bigint id PK
        bigint academic_year_id FK
        varchar meeting_type
        varchar title
        datetime starts_at
        datetime ends_at
        varchar location
        bigint organizer_user_id FK
        bigint student_id FK "nullable, 1:1 meetings"
        varchar status "planned|held|cancelled"
        datetime deleted_at
    }
    meeting_participants {
        bigint meeting_id FK
        bigint user_id FK
        varchar attendance "invited|attended|absent"
    }
    meeting_agenda_items {
        bigint id PK
        bigint meeting_id FK
        varchar topic
        tinyint sort_order
    }
    meeting_minutes {
        bigint id PK
        bigint meeting_id FK "UK"
        longtext content
        bigint approved_by FK
    }
    meeting_decisions {
        bigint id PK
        bigint meeting_id FK
        text decision
        bigint owner_user_id FK
        date due_date
        varchar status "open|done|cancelled"
    }
    tasks {
        bigint id PK
        varchar title
        text description
        bigint assignee_user_id FK
        bigint assigned_by FK
        date due_date
        varchar priority "low|normal|high|urgent"
        varchar status "open|in_progress|done|cancelled"
        varchar source_type "nullable"
        bigint source_id "nullable"
        datetime completed_at
        datetime deleted_at
    }
    task_comments {
        bigint id PK
        bigint task_id FK
        bigint user_id FK
        text body
    }
    parent_contacts {
        bigint id PK
        bigint student_id FK
        bigint guardian_id FK "nullable"
        bigint staff_user_id FK
        varchar contact_type "meeting|call|message|inquiry"
        datetime contacted_at
        text internal_notes
        text shared_summary
        text decisions
        date follow_up_date
        varchar follow_up_status
        datetime deleted_at
    }
```

**קשרים מרכזיים**
- משימה יכולה לנבוע מהחלטת ישיבה, מפעולת יעד או מפעולת התערבות (`source_type` + `source_id`) – כך "לוח המשימות" אחד משרת את כל המודולים.
- `parent_contacts` מפריד פיזית בין `internal_notes` (לעולם אינו מיוצא) לבין `shared_summary` (המיועד למסירה להורה). רשומה שייכת לתלמיד אחד בלבד.

---

## 9. מסמכים ותשתית

```mermaid
erDiagram
    documents ||--o{ document_links : ""
    users ||--o{ documents : "uploaded"

    documents {
        bigint id PK
        varchar original_name
        varchar stored_name UK "random"
        varchar mime_type
        int size_bytes
        char sha256
        varchar category
        varchar visibility "restricted|staff"
        bigint uploaded_by FK
        datetime deleted_at
    }
    document_links {
        bigint id PK
        bigint document_id FK
        varchar entity_type
        bigint entity_id
    }
    migrations {
        int id PK
        varchar migration UK
        datetime ran_at
    }
```

- `document_links` פולימורפי: מסמך יכול להיות מקושר ליעד, לישיבה, להתערבות וכו'. **הרשאת הצפייה במסמך נגזרת מהרשאת הצפייה בישות המקושרת** – מי שאינו רשאי לראות את ההתערבות אינו רשאי להוריד את הקובץ שלה.
- קישורים פולימורפיים (`document_links`, `tasks.source_*`, `audit_logs.entity_*`) אינם יכולים לקבל FK אמיתי; שלמותם נאכפת בשכבת ה-Service ונבדקת בבדיקות אוטומטיות.

---

## אינדקסים עיקריים

| טבלה | אינדקס | משרת |
|---|---|---|
| `assessment_results` | `(assessment_id, student_id)` UK, `(student_id)` | גיליון ציונים, כרטיס תלמיד |
| `assessments` | `(teaching_assignment_id, term_id)`, `(term_id, type_id)` | ממוצעים לפי כיתה/מקצוע/תקופה |
| `class_enrollments` | `(class_id, left_on)`, `(student_id)` | רשימת כיתה נוכחית |
| `teaching_assignments` | `(teacher_id, academic_year_id)`, `(class_id, subject_id)` | גזירת היקף גישה |
| `term_grades` | `(term_id, subject_id)`, `(class_id, term_id)` | דוחות השוואה |
| `attendance_records` | `(lesson_session_id, student_id)` UK, `(student_id, status)` | דיווח שיעור, היעדרויות תלמיד |
| `lesson_sessions` | `(teaching_assignment_id, lesson_date, period_number)` UK | "השיעורים שלי היום" |
| `tasks` | `(assignee_user_id, status, due_date)` | "המשימות שלי", איחורים |
| `audit_logs` | `(entity_type, entity_id)`, `(user_id, created_at)` | חקירת אירוע |
| `login_attempts` | `(username, attempted_at)`, `(ip_address, attempted_at)` | הגבלת ניסיונות |
| `notifications` | `(user_id, read_at)` | מונה התראות |
| `risk_indications` | `(review_status, term_id)` | רשימת "לבדיקה" |

## כללי מחיקה (ON DELETE)

- `RESTRICT` כברירת מחדל: אי אפשר למחוק מקצוע שיש לו הערכות, כיתה שיש בה תלמידים, משתמש שהוא אחראי לפעולות.
- `CASCADE` רק מאב לילד שאין לו קיום עצמאי: יעד → מדדים/פעולות, ישיבה → סדר יום/משתתפים, הערכה → תוצאות (ההערכה עצמה במחיקה רכה).
- `SET NULL` לשדות `created_by`/`updated_by` ולקישורים אופציונליים.
