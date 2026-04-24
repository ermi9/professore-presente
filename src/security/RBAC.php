<?php

class RBAC {
    
    // Define what each role can access
    private static $permissions = [
        'admin' => [
            'admin.create_professor',
            'admin.list_professors',
            'auth.profile'
        ],
        'professor' => [
            'professor.create_course',
            'professor.list_courses',
            'professor.create_exam',
            'professor.list_exams',
            'professor.manage_queue',
            'auth.profile'
        ],
        'student' => [
            'student.enroll_course',
            'student.list_courses',
            'student.join_queue',
            'student.view_queue',
            'auth.profile'
        ]
    ];

    /**
     * Check if user role has permission for an action
     * 
     * @param string $role - User's role (admin, professor, student)
     * @param string $permission - Action to check (e.g., 'admin.create_professor')
     * @return bool - true if allowed, false if denied
     */
    public static function hasPermission($role, $permission) {
        if (!isset(self::$permissions[$role])) {
            return false;
        }

        return in_array($permission, self::$permissions[$role]);
    }

    /**
     * Enforce permission - return error if not allowed
     * 
     * @param string $role - User's role
     * @param string $permission - Action to check
     */
    public static function enforce($role, $permission) {
        if (!self::hasPermission($role, $permission)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Access denied. You do not have permission for this action.']);
            exit;
        }
    }
}
?>
