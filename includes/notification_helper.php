<?php
/**
 * Notification Helper Functions & Formatters
 */

if (!function_exists('format_notification_time_ago')) {
    function format_notification_time_ago($timestamp) {
        if (empty($timestamp)) return 'Recently';
        $time = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = max(1, floor($diff / 60));
            return $mins . 'm ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h ago';
        } elseif ($diff < 172800) {
            return 'Yesterday';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . 'd ago';
        } else {
            return date('M d, Y', $time);
        }
    }
}

if (!function_exists('format_notification_data')) {
    function format_notification_data($notif) {
        $msg = $notif['message'] ?? '';
        $msg_lower = strtolower($msg);
        $type = !empty($notif['type']) ? strtolower($notif['type']) : 'system';
        $title = $notif['title'] ?? '';
        $link = $notif['link'] ?? '';

        // Auto-infer type if default system
        if ($type === 'system') {
            if (strpos($msg_lower, 'certificate') !== false || strpos($msg_lower, 'cert') !== false) {
                $type = 'certificate';
            } elseif (strpos($msg_lower, 'lesson') !== false || strpos($msg_lower, 'course') !== false || strpos($msg_lower, 'syllabus') !== false || strpos($msg_lower, 'enrolled') !== false) {
                $type = 'course';
            } elseif (strpos($msg_lower, 'payment') !== false || strpos($msg_lower, 'slip') !== false || strpos($msg_lower, 'bank') !== false || strpos($msg_lower, 'approved') !== false) {
                $type = 'payment';
            } elseif (strpos($msg_lower, 'question') !== false || strpos($msg_lower, 'answer') !== false || strpos($msg_lower, 'reply') !== false || strpos($msg_lower, 'forum') !== false || strpos($msg_lower, 'review') !== false) {
                $type = 'qa';
            }
        }

        // Auto-infer title if empty
        if (empty($title)) {
            switch ($type) {
                case 'certificate':
                    $title = 'Certificate Update';
                    break;
                case 'course':
                    $title = 'Course & Lesson Update';
                    break;
                case 'payment':
                    $title = 'Payment & Enrollment';
                    break;
                case 'qa':
                    $title = 'Discussion & Q&A';
                    break;
                default:
                    $title = 'System Notification';
                    break;
            }
        }

        // Auto-infer target link if empty
        if (empty($link)) {
            switch ($type) {
                case 'certificate':
                    $link = 'profile.php#certificates';
                    break;
                case 'course':
                    $link = 'my_courses.php';
                    break;
                case 'payment':
                    $link = 'profile.php#enrollments';
                    break;
                case 'qa':
                    $link = 'dashboard.php';
                    break;
                default:
                    $link = 'notifications.php';
                    break;
            }
        }

        // Visual configs by type
        $type_configs = [
            'course' => [
                'icon' => 'bi-mortarboard-fill',
                'color' => '#0284c7',
                'bg' => '#e0f2fe',
                'badge' => 'Course'
            ],
            'certificate' => [
                'icon' => 'bi-award-fill',
                'color' => '#d97706',
                'bg' => '#fef3c7',
                'badge' => 'Certificate'
            ],
            'payment' => [
                'icon' => 'bi-credit-card-2-front-fill',
                'color' => '#059669',
                'bg' => '#d1fae5',
                'badge' => 'Payment'
            ],
            'qa' => [
                'icon' => 'bi-chat-left-text-fill',
                'color' => '#7c3aed',
                'bg' => '#ede9fe',
                'badge' => 'Community'
            ],
            'system' => [
                'icon' => 'bi-bell-fill',
                'color' => '#e11d48',
                'bg' => '#ffe4e6',
                'badge' => 'System'
            ]
        ];

        $config = $type_configs[$type] ?? $type_configs['system'];

        return [
            'id' => (int)$notif['id'],
            'title' => $title,
            'message' => $msg,
            'type' => $type,
            'link' => $link,
            'is_read' => (int)$notif['is_read'],
            'created_at' => $notif['created_at'],
            'time_ago' => format_notification_time_ago($notif['created_at']),
            'icon' => $config['icon'],
            'color' => $config['color'],
            'bg' => $config['bg'],
            'badge' => $config['badge']
        ];
    }
}

if (!function_exists('create_user_notification')) {
    function create_user_notification($pdo, $user_id, $message, $type = 'system', $title = null, $link = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
            return $stmt->execute([$user_id, $title, $message, $type, $link]);
        } catch (PDOException $e) {
            // Fallback for legacy schema
            try {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                return $stmt->execute([$user_id, $message]);
            } catch (PDOException $ex) {
                return false;
            }
        }
    }
}
