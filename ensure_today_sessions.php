<?php
// ensure_today_sessions.php
// Helper to ensure a session_instance exists for each timetable session scheduled for today

require_once __DIR__ . '/config/database.php';

if (!function_exists('ensure_todays_session_instances')) {
    function ensure_todays_session_instances(PDO $conn): void {
        try {
            $sql = "
                INSERT INTO session_instances (timetable_session_id, session_date, status)
                SELECT ts.id, CURDATE(), 'scheduled'
                FROM timetable_sessions ts
                JOIN classes c ON ts.class_id = c.id
                LEFT JOIN session_instances si
                    ON si.timetable_session_id = ts.id
                    AND si.session_date = CURDATE()
                WHERE si.id IS NULL
                  AND c.status = 'active'
                  AND ts.status <> 'cancelled'
                  AND ts.day_of_week = DAYNAME(CURDATE())
            ";
            $conn->exec($sql);
        } catch (Throwable $e) {
            // best-effort only; don't block page load
        }
    }
}

?>


