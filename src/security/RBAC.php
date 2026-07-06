<?php

class RBAC {

    private static $permissions = [
        'admin' => [
            'admin.create_professor',
            'admin.list_professors',
            'admin.list_students',
            'admin.view_stats',
            'auth.profile'
        ],
        'professor' => [
            'professor.create_course',
            'professor.list_courses',
            'professor.create_exam',
            'professor.list_exams',
            'professor.manage_queue',
            'professor.manage_timetable',
            'professor.manage_announcements',
            'professor.view_enrolled_students',
            'professor.view_stats',
            'auth.profile'
        ],
        'student' => [
            'student.enroll_course',
            'student.list_courses',
            'student.list_exams',
            'student.join_queue',
            'student.view_queue',
            'student.view_timetable',
            'student.view_announcements',
            'auth.profile'
        ]
    ];

    public static function hasPermission($role, $permission) {
        if (!isset(self::$permissions[$role])) return false;
        return in_array($permission, self::$permissions[$role]);
    }

    public static function enforce($role, $permission) {
        if (!self::hasPermission($role, $permission)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Access denied.']);
            exit;
        }
    }
}
?>
