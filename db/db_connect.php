<?php
require_once __DIR__ . '/session.php';

// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'computerscience_lms');

function getDBConnection()
{
    try {
        // Try connecting to the database directly
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Ensure role, tutor_id and other updates exist
        ensureMigrations($pdo);

        return $pdo;
    } catch (PDOException $e) {
        // If database doesn't exist, we connect to host and initialize it
        if ($e->getCode() === 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
            return initializeDatabase();
        }
        throw $e;
    }
}

function ensureMigrations($pdo)
{
    // 1. Check and add 'role' to 'users'
    try {
        $pdo->query("SELECT role FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'student' AFTER academic_id");
    }

    // 2. Check and add 'tutor_id' to 'courses'
    try {
        $pdo->query("SELECT tutor_id FROM courses LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN tutor_id INT NULL AFTER tutor_avatar");
        try {
            $pdo->exec("ALTER TABLE courses ADD CONSTRAINT fk_courses_tutor FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE SET NULL");
        } catch (PDOException $ex) {
        }
    }

    // 3. Check and add 'user_id' to 'forum_replies'
    try {
        $pdo->query("SELECT user_id FROM forum_replies LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE forum_replies ADD COLUMN user_id INT NULL AFTER qa_id");
        try {
            $pdo->exec("ALTER TABLE forum_replies ADD CONSTRAINT fk_replies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
        } catch (PDOException $ex) {
        }
    }

    // 3.1 Check and add 'bio', 'subject', 'qualifications' to 'users'
    try {
        $pdo->query("SELECT bio FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER status");
    }
    try {
        $pdo->query("SELECT subject FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN subject VARCHAR(100) NULL AFTER bio");
    }
    try {
        $pdo->query("SELECT qualifications FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN qualifications VARCHAR(255) NULL AFTER subject");
    }
    try {
        $pdo->query("SELECT nic FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN nic VARCHAR(20) NULL AFTER qualifications");
    }
    try {
        $pdo->query("SELECT dob FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN dob DATE NULL AFTER nic");
    }

    // 3.1.1 Check and add Google OAuth & OTP verification columns to 'users'
    $isNewEmailVerifiedColumn = false;
    try {
        $pdo->query("SELECT google_id FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER dob");
    }
    try {
        $pdo->query("SELECT auth_provider FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN auth_provider ENUM('local', 'google') DEFAULT 'local' AFTER google_id");
    }
    try {
        $pdo->query("SELECT email_verified FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER auth_provider");
        $isNewEmailVerifiedColumn = true;
    }
    try {
        $pdo->query("SELECT otp_code FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(6) NULL AFTER email_verified");
    }
    try {
        $pdo->query("SELECT otp_expires_at FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_code");
    }

    // Auto-verify pre-existing users so existing accounts are never locked out
    if ($isNewEmailVerifiedColumn) {
        try {
            $pdo->exec("UPDATE users SET email_verified = 1 WHERE email_verified = 0 OR email_verified IS NULL");
        } catch (PDOException $ex) {
        }
    }

    // 3.2 Check and add 'target_audience', 'price' to 'courses'
    try {
        $pdo->query("SELECT target_audience FROM courses LIMIT 1");
        // Ensure target_audience is TEXT so it can store multiple comma-separated selections
        try {
            $pdo->exec("ALTER TABLE courses MODIFY COLUMN target_audience TEXT NULL");
        } catch (PDOException $ex) {
        }
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN target_audience TEXT NULL AFTER category");
    }
    try {
        $pdo->query("SELECT price FROM courses LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00 AFTER target_audience");
    }

    // 3.3 Check and add 'status' to 'courses'
    try {
        $pdo->query("SELECT status FROM courses LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER thumbnail");
        // Update existing courses to 'approved' so we don't block access to seeded/existing courses
        $pdo->exec("UPDATE courses SET status = 'approved'");
    }

    // 3.4 Ensure bank_payments table exists
    try {
        $pdo->query("SELECT id FROM bank_payments LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bank_payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `course_id` VARCHAR(50) NOT NULL,
            `full_name` VARCHAR(150) NOT NULL,
            `slip_path` VARCHAR(255) NOT NULL,
            `status` VARCHAR(20) DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 3.5 Ensure notifications table exists
    try {
        $pdo->query("SELECT id FROM notifications LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `message` TEXT NOT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 3.6 Ensure bank_accounts table exists and seed defaults if empty
    try {
        $pdo->query("SELECT id FROM bank_accounts LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bank_accounts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `bank_name` VARCHAR(100) NOT NULL,
            `branch` VARCHAR(100) NOT NULL,
            `account_number` VARCHAR(50) NOT NULL,
            `account_name` VARCHAR(100) NOT NULL,
            `option_label` VARCHAR(50) DEFAULT 'Option 1',
            `status` VARCHAR(20) DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    $bankCheck = $pdo->query("SELECT COUNT(*) FROM bank_accounts");
    if ($bankCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO bank_accounts (bank_name, branch, account_number, account_name, option_label, status) VALUES
        ('Commercial Bank', 'Colombo Fort', '8012993041', 'Computerscience.lk (Pvt) Ltd', 'Option 1', 'active'),
        ('Sampath Bank', 'Colombo Head Office', '0104889201', 'Computerscience.lk (Pvt) Ltd', 'Option 2', 'active')");
    }

    // 3.7 Ensure site_announcements table exists and seed default records
    try {
        $pdo->query("SELECT id FROM site_announcements LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `site_announcements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `content` TEXT NOT NULL,
            `badge_text` VARCHAR(50) NULL,
            `status` VARCHAR(20) DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    $announcementCheck = $pdo->query("SELECT COUNT(*) FROM site_announcements");
    if ($announcementCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO site_announcements (title, content, badge_text, status) VALUES
        ('Registration open for Algorithms 2026 Batch', 'The new academic program covering complex sorting paradigms and computational models starts next Tuesday. Check requirements in the course directory.', 'July 15, 2026', 'active'),
        ('Apache and MySQL database maintenance', 'The local XAMPP database instances will be offline briefly on Sunday morning for index optimizations. Direct AJAX operations will be paused.', 'July 10, 2026', 'active')");
    }

    // 3.7.1 Ensure lesson_progress table exists (video watch progress tracking)
    try {
        $pdo->query("SELECT user_id FROM lesson_progress LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lesson_progress` (
            `user_id` INT NOT NULL,
            `lesson_id` VARCHAR(50) NOT NULL,
            `position_seconds` DECIMAL(10,2) DEFAULT 0,
            `duration_seconds` DECIMAL(10,2) DEFAULT 0,
            `progress_percent` DECIMAL(5,2) DEFAULT 0,
            `completed` TINYINT(1) DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `lesson_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT completed FROM lesson_progress LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN completed TINYINT(1) DEFAULT 0 AFTER progress_percent");
    }

    // 3.7.2 Ensure quiz_results table and analytics columns exist
    try {
        $pdo->query("SELECT user_id FROM quiz_results LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `quiz_results` (
            `user_id` INT NOT NULL,
            `course_id` VARCHAR(50) NOT NULL,
            `score` INT NOT NULL,
            `total_questions` INT DEFAULT 0,
            `status` VARCHAR(20) DEFAULT 'completed',
            `attempts_count` INT DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `course_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT total_questions FROM quiz_results LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_results ADD COLUMN total_questions INT DEFAULT 0 AFTER score");
    }
    try {
        $pdo->query("SELECT status FROM quiz_results LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_results ADD COLUMN status VARCHAR(20) DEFAULT 'completed' AFTER total_questions");
    }
    try {
        $pdo->query("SELECT attempts_count FROM quiz_results LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_results ADD COLUMN attempts_count INT DEFAULT 1 AFTER status");
    }

    // 3.7.3 Ensure quiz_attempts table exists
    try {
        $pdo->query("SELECT id FROM quiz_attempts LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `quiz_attempts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `course_id` VARCHAR(50) NOT NULL,
            `attempt_number` INT NOT NULL DEFAULT 1,
            `score` INT NOT NULL DEFAULT 0,
            `total_questions` INT NOT NULL DEFAULT 0,
            `status` VARCHAR(20) DEFAULT 'in_progress',
            `current_question_index` INT DEFAULT 0,
            `question_started_at` INT NULL,
            `answers_json` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT current_question_index FROM quiz_attempts LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN current_question_index INT DEFAULT 0 AFTER status");
    }

    try {
        $pdo->query("SELECT lesson_id FROM quiz_attempts LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN lesson_id VARCHAR(50) NULL AFTER course_id");
    }

    try {
        $pdo->query("SELECT question_started_at FROM quiz_attempts LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN question_started_at INT NULL AFTER current_question_index");
    }

    // 3.7.4 Ensure course_quiz_settings table exists
    try {
        $pdo->query("SELECT course_id FROM course_quiz_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `course_quiz_settings` (
            `course_id` VARCHAR(50) PRIMARY KEY,
            `max_attempts` INT DEFAULT 3,
            `pass_percentage` INT DEFAULT 50,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 3.7.5 Ensure explanation and dynamic question columns in quizzes table
    try {
        $pdo->query("SELECT explanation FROM quizzes LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN explanation TEXT NULL AFTER answer_index");
        $pdo->exec("UPDATE quizzes SET explanation = 'Refer to the fundamental principles discussed in the core lesson modules.' WHERE explanation IS NULL OR explanation = ''");
    }

    try {
        $pdo->query("SELECT lesson_id FROM quizzes LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN lesson_id VARCHAR(50) NULL AFTER course_id");
    }

    try {
        $pdo->query("SELECT question_type FROM quizzes LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN question_type VARCHAR(20) DEFAULT 'mcq' AFTER question");
    }

    try {
        $pdo->query("SELECT image_path FROM quizzes LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN image_path VARCHAR(255) NULL AFTER question_type");
    }

    try {
        $pdo->query("SELECT time_limit_seconds FROM quizzes LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN time_limit_seconds INT DEFAULT 30 AFTER image_path");
    }

    try {
        $pdo->query("SELECT correct_answer FROM quizzes LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN correct_answer TEXT NULL AFTER answer_index");
    }

    // Make option_1 through option_4 NULLable for text questions
    try {
        $pdo->exec("ALTER TABLE quizzes MODIFY COLUMN option_1 VARCHAR(255) NULL");
        $pdo->exec("ALTER TABLE quizzes MODIFY COLUMN option_2 VARCHAR(255) NULL");
        $pdo->exec("ALTER TABLE quizzes MODIFY COLUMN option_3 VARCHAR(255) NULL");
        $pdo->exec("ALTER TABLE quizzes MODIFY COLUMN option_4 VARCHAR(255) NULL");
    } catch (PDOException $e) {
    }

    // Ensure legacy courses without status are set to approved
    try {
        $pdo->exec("UPDATE courses SET status = 'approved' WHERE status IS NULL");
    } catch (PDOException $ex) {
    }

    // 3.8 Ensure course_categories table exists
    try {
        $pdo->query("SELECT id FROM course_categories LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `course_categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL UNIQUE,
            `status` VARCHAR(20) DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed default course categories
        $default_cats = [
            'Computer Science',
            'Programming',
            'Software Engineering',
            'Web Development',
            'Artificial Intelligence',
            'Cyber Security'
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO course_categories (name, status) VALUES (?, 'active')");
        foreach ($default_cats as $cat) {
            $ins->execute([$cat]);
        }
    }

    // 3.9 Ensure target_audiences table exists
    try {
        $pdo->query("SELECT id FROM target_audiences LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `target_audiences` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL UNIQUE,
            `status` VARCHAR(20) DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed default target audiences
        $default_auds = [
            'BIT External Degree (UCSC) - Year 1',
            'BIT External Degree (UCSC) - Year 2',
            'BIT External Degree (UCSC) - Year 3',
            'B.Sc. in Computer Science - Batch 2024',
            'B.Sc. in Computer Science - Batch 2025',
            'B.Sc. in Computer Science - Batch 2026',
            'General Public / Self-Paced'
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO target_audiences (name, status) VALUES (?, 'active')");
        foreach ($default_auds as $aud) {
            $ins->execute([$aud]);
        }
    }

    // 3.10 Ensure site_settings table exists
    try {
        $pdo->query("SELECT setting_key FROM site_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
            `setting_key` VARCHAR(50) PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('site_logo', 'assets/logo.png')");
    }

    // Ensure default certificate delivery note settings exist
    try {
        $default_settings = [
            'cert_cod_title' => 'Cash on Delivery & Courier Details:',
            'cert_cod_fee_note' => 'LKR 1,500 Cash on Delivery fee for embossed certificate printing, security hard-folder, and island-wide registered courier handling (Payable in Cash to the courier delivery rider upon package arrival). The digital e-certificate remains 100% free.',
            'cert_cod_timeframe_note' => 'Dispatched within 24–48 hours after application approval. Island-wide doorstep delivery takes 2 to 4 working days.',
            'cert_cod_custom_notice' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_oauth_enabled' => '1'
        ];
        $insertSettingStmt = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($default_settings as $k => $v) {
            $insertSettingStmt->execute([$k, $v]);
        }
    } catch (PDOException $ex) {
    }
    try {
        $pdo->query("SELECT updated_at FROM quiz_results LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE quiz_results ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }

    // 3.8 Ensure hero_settings table exists with target design columns
    try {
        $pdo->query("SELECT id FROM hero_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `hero_settings` (
            `id` INT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NOT NULL,
            `button_text` VARCHAR(100) NOT NULL,
            `button_url` VARCHAR(255) NOT NULL,
            `secondary_button_text` VARCHAR(100) DEFAULT 'Know More',
            `secondary_button_url` VARCHAR(255) DEFAULT '#courses-section',
            `phone_number` VARCHAR(100) DEFAULT 'Call Us : 011 234 5678',
            `enrolled_students_count` VARCHAR(100) DEFAULT '30K Enrolled Students',
            `bg_image` VARCHAR(255) NULL,
            `bg_image_1` VARCHAR(255) NULL,
            `bg_image_2` VARCHAR(255) NULL,
            `bg_image_3` VARCHAR(255) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT secondary_button_text FROM hero_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN secondary_button_text VARCHAR(100) DEFAULT 'Know More' AFTER button_url");
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN secondary_button_url VARCHAR(255) DEFAULT '#courses-section' AFTER secondary_button_text");
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN phone_number VARCHAR(100) DEFAULT 'Call Us : 011 234 5678' AFTER secondary_button_url");
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN enrolled_students_count VARCHAR(100) DEFAULT '30K Enrolled Students' AFTER phone_number");
    }

    try {
        $pdo->query("SELECT bg_image_1 FROM hero_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN bg_image_1 VARCHAR(255) NULL AFTER bg_image");
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN bg_image_2 VARCHAR(255) NULL AFTER bg_image_1");
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN bg_image_3 VARCHAR(255) NULL AFTER bg_image_2");
        $pdo->exec("UPDATE hero_settings SET bg_image_1 = bg_image WHERE bg_image IS NOT NULL AND bg_image_1 IS NULL");
    }

    $heroCheck = $pdo->query("SELECT COUNT(*) FROM hero_settings");
    if ($heroCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO hero_settings (id, title, description, button_text, button_url, secondary_button_text, secondary_button_url, phone_number, enrolled_students_count, bg_image_1, bg_image_2, bg_image_3) VALUES
        (1, 'Enhance Your Skills With Our Online Courses', 'Dive into a World of Knowledge with Our Comprehensive and Engaging Online Courses Designed for Skill Enhancement', 'Apply Now', '#courses-section', 'Know More', '#courses-section', 'Call Us : 011 234 5678', '30K Enrolled Students', NULL, NULL, NULL)");
    }


    // Clean up auto-created default teacher and admin accounts if present
    try {
        $pdo->exec("DELETE FROM users WHERE email IN ('sanduni@computerscience.lk', 'admin@computerscience.lk')");
    } catch (PDOException $ex) {
    }

    // 3.11 Ensure certificate_templates table exists
    try {
        $pdo->query("SELECT id FROM certificate_templates LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `certificate_templates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `description` VARCHAR(255) NULL,
            `type` ENUM('builtin', 'custom') DEFAULT 'custom',
            `background_image` VARCHAR(255) NULL,
            `theme_color` VARCHAR(50) DEFAULT '#0f4c81',
            `border_style` VARCHAR(50) DEFAULT 'custom_bg',
            `font_family` VARCHAR(100) DEFAULT 'Cinzel',
            `signature_title_1` VARCHAR(100) DEFAULT 'Director of Academic Affairs',
            `signature_name_1` VARCHAR(100) DEFAULT 'Academic Director',
            `signature_title_2` VARCHAR(100) DEFAULT 'Dean of Computer Science',
            `signature_name_2` VARCHAR(100) DEFAULT 'Senior Faculty Lead',
            `institution_name` VARCHAR(150) DEFAULT 'Computerscience.lk',
            `sub_title` VARCHAR(255) DEFAULT 'Advanced Computer Science & IT Learning Academy',
            `is_default` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Clean up any legacy builtin templates
    try {
        $pdo->exec("DELETE FROM certificate_templates WHERE type = 'builtin'");
    } catch (PDOException $ex) {
    }

    // 3.12 Ensure template_id column exists in certificate_requests
    try {
        $pdo->query("SELECT template_id FROM certificate_requests LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE certificate_requests ADD COLUMN template_id INT NULL AFTER certificate_code");
    }

    // 3.13 Ensure certificate_image column exists in certificate_requests
    try {
        $pdo->query("SELECT certificate_image FROM certificate_requests LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE certificate_requests ADD COLUMN certificate_image VARCHAR(255) NULL AFTER template_id");
    }

    // 3.14 Ensure email_sent_at column exists in certificate_requests
    try {
        $pdo->query("SELECT email_sent_at FROM certificate_requests LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE certificate_requests ADD COLUMN email_sent_at DATETIME NULL AFTER admin_notes");
    }

    // 3.15 Ensure smtp_settings table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `smtp_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `smtp_host` VARCHAR(255) DEFAULT 'smtp.gmail.com',
            `smtp_port` INT DEFAULT 587,
            `smtp_user` VARCHAR(255) DEFAULT '',
            `smtp_pass` VARCHAR(255) DEFAULT '',
            `smtp_secure` ENUM('tls', 'ssl') DEFAULT 'tls',
            `from_email` VARCHAR(255) DEFAULT 'noreply@computerscience.lk',
            `from_name` VARCHAR(255) DEFAULT 'Computerscience.lk Academy',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $chkSmtp = $pdo->query("SELECT COUNT(*) FROM smtp_settings")->fetchColumn();
        if ($chkSmtp == 0) {
            $pdo->exec("INSERT INTO smtp_settings (smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, from_email, from_name) VALUES ('smtp.gmail.com', 587, '', '', 'tls', 'certificates@computerscience.lk', 'Computerscience.lk Academy')");
        }
    } catch (PDOException $e) {
    }

    // 3.16 Legacy alias table smtp_configs check
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `smtp_configs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `host` VARCHAR(150) NOT NULL DEFAULT 'smtp.gmail.com',
            `port` INT NOT NULL DEFAULT 587,
            `username` VARCHAR(150) NOT NULL DEFAULT '',
            `password` VARCHAR(255) NOT NULL DEFAULT '',
            `encryption` VARCHAR(20) NOT NULL DEFAULT 'tls',
            `from_email` VARCHAR(150) NOT NULL DEFAULT 'noreply@computerscience.lk',
            `from_name` VARCHAR(150) NOT NULL DEFAULT 'Computerscience.lk Academy',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (PDOException $e) {
    }

    // 7. Seed primary Super Admin account if not exists
    ensureSuperAdminExists($pdo);
}

function ensureSuperAdminExists($pdo)
{
    try {
        $passHash = password_hash('superadmin20', PASSWORD_BCRYPT);

        // Update legacy dev.ishara20@gmail to dev.ishara20@gmail.com if present
        $pdo->prepare("UPDATE users SET email = 'dev.ishara20@gmail.com', role = 'super_admin', status = 'active', password_hash = ? WHERE email = 'dev.ishara20@gmail'")->execute([$passHash]);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute(['dev.ishara20@gmail.com']);
        $superAdmin = $stmt->fetch();

        if (!$superAdmin) {
            $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, avatar, academic_id, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([
                'Super Admin',
                'dev.ishara20@gmail.com',
                $passHash,
                'https://ui-avatars.com/api/?name=Super+Admin&background=0f4c81&color=fff',
                'SPAD-000001',
                'super_admin',
                'active'
            ]);
        } else {
            // Update role to super_admin and refresh password hash
            $updateStmt = $pdo->prepare("UPDATE users SET role = 'super_admin', status = 'active', password_hash = ? WHERE id = ?");
            $updateStmt->execute([$passHash, $superAdmin['id']]);
        }
    } catch (PDOException $e) {
        // Silently continue
    }
}

function initializeDatabase()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Connect to the newly created database
        $pdo->exec("USE `" . DB_NAME . "`");

        // Create tables
        $queries = [
            // Users table
            "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(100) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `avatar` VARCHAR(255) DEFAULT 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&h=100&fit=crop',
                `academic_id` VARCHAR(20) DEFAULT '202604',
                `role` VARCHAR(20) DEFAULT 'student',
                `status` VARCHAR(20) DEFAULT 'active',
                `bio` TEXT NULL,
                `subject` VARCHAR(100) NULL,
                `qualifications` VARCHAR(255) NULL,
                `nic` VARCHAR(20) NULL,
                `dob` DATE NULL,
                `google_id` VARCHAR(255) NULL UNIQUE,
                `auth_provider` ENUM('local', 'google') DEFAULT 'local',
                `email_verified` TINYINT(1) DEFAULT 0,
                `otp_code` VARCHAR(6) NULL,
                `otp_expires_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Courses table
            "CREATE TABLE IF NOT EXISTS `courses` (
                `id` VARCHAR(50) PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `category` VARCHAR(100) NOT NULL,
                `target_audience` VARCHAR(100) NULL,
                `price` DECIMAL(10,2) DEFAULT 0.00,
                `level` VARCHAR(50) NOT NULL,
                `duration` INT NOT NULL,
                `enrolled_count` INT DEFAULT 0,
                `rating` DECIMAL(3,2) DEFAULT 0.00,
                `review_count` INT DEFAULT 0,
                `tutor_name` VARCHAR(100) NOT NULL,
                `tutor_title` VARCHAR(100) NOT NULL,
                `tutor_avatar` VARCHAR(255) NOT NULL,
                `tutor_id` INT NULL,
                `short_description` TEXT NOT NULL,
                `long_description` TEXT NOT NULL,
                `thumbnail` VARCHAR(255) NOT NULL,
                `status` VARCHAR(20) DEFAULT 'pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`tutor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Lessons table
            "CREATE TABLE IF NOT EXISTS `lessons` (
                `id` VARCHAR(50) PRIMARY KEY,
                `course_id` VARCHAR(50) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `duration` VARCHAR(50) NOT NULL,
                `video_url` VARCHAR(255) NOT NULL,
                `content` TEXT NOT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Quizzes table
            "CREATE TABLE IF NOT EXISTS `quizzes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `question_id` VARCHAR(50) NOT NULL UNIQUE,
                `course_id` VARCHAR(50) NOT NULL,
                `question` TEXT NOT NULL,
                `option_1` VARCHAR(255) NOT NULL,
                `option_2` VARCHAR(255) NOT NULL,
                `option_3` VARCHAR(255) NOT NULL,
                `option_4` VARCHAR(255) NOT NULL,
                `answer_index` INT NOT NULL,
                FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Forum Topics table
            "CREATE TABLE IF NOT EXISTS `forum_topics` (
                `qa_id` VARCHAR(50) PRIMARY KEY,
                `course_id` VARCHAR(50) NOT NULL,
                `user_id` INT NULL,
                `student_name` VARCHAR(100) NOT NULL,
                `student_avatar` VARCHAR(255) NOT NULL,
                `question` TEXT NOT NULL,
                `timestamp` DATETIME NOT NULL,
                FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Forum Replies table
            "CREATE TABLE IF NOT EXISTS `forum_replies` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `qa_id` VARCHAR(50) NOT NULL,
                `user_id` INT NULL,
                `replier_name` VARCHAR(100) NOT NULL,
                `replier_avatar` VARCHAR(255) NOT NULL,
                `content` TEXT NOT NULL,
                `timestamp` DATETIME NOT NULL,
                FOREIGN KEY (`qa_id`) REFERENCES `forum_topics`(`qa_id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Enrollments table
            "CREATE TABLE IF NOT EXISTS `enrollments` (
                `user_id` INT NOT NULL,
                `course_id` VARCHAR(50) NOT NULL,
                PRIMARY KEY (`user_id`, `course_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Completed Lessons table
            "CREATE TABLE IF NOT EXISTS `completed_lessons` (
                `user_id` INT NOT NULL,
                `lesson_id` VARCHAR(50) NOT NULL,
                PRIMARY KEY (`user_id`, `lesson_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Lesson Progress table (video watch progress tracking)
            "CREATE TABLE IF NOT EXISTS `lesson_progress` (
                `user_id` INT NOT NULL,
                `lesson_id` VARCHAR(50) NOT NULL,
                `position_seconds` DECIMAL(10,2) DEFAULT 0,
                `duration_seconds` DECIMAL(10,2) DEFAULT 0,
                `progress_percent` DECIMAL(5,2) DEFAULT 0,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `lesson_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Quiz Results table
            "CREATE TABLE IF NOT EXISTS `quiz_results` (
                `user_id` INT NOT NULL,
                `course_id` VARCHAR(50) NOT NULL,
                `score` INT NOT NULL,
                PRIMARY KEY (`user_id`, `course_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Bank Payments table
            "CREATE TABLE IF NOT EXISTS `bank_payments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `course_id` VARCHAR(50) NOT NULL,
                `full_name` VARCHAR(150) NOT NULL,
                `slip_path` VARCHAR(255) NOT NULL,
                `status` VARCHAR(20) DEFAULT 'pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Notifications table
            "CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `message` TEXT NOT NULL,
                `is_read` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];

        foreach ($queries as $query) {
            $pdo->exec($query);
        }

        // Seed data from mock_data.json if courses table is empty
        $checkStmt = $pdo->query("SELECT COUNT(*) FROM `courses`");
        if ($checkStmt->fetchColumn() == 0) {
            seedFromMockData($pdo);
        }

        // Run migrations/ensure columns exist
        ensureMigrations($pdo);

        return $pdo;
    } catch (PDOException $e) {
        die("Database initialization failed: " . $e->getMessage());
    }
}

function seedFromMockData($pdo)
{
    $mockFile = __DIR__ . '/mock_data.json';
    if (!file_exists($mockFile)) {
        return;
    }

    $data = json_decode(file_get_contents($mockFile), true);
    if (!$data) {
        return;
    }

    $defaultPassHash = password_hash('password123', PASSWORD_BCRYPT);

    $teacherId = null;

    // 1. Seed courses
    $courseStmt = $pdo->prepare("INSERT INTO `courses` 
        (id, title, category, level, duration, enrolled_count, rating, review_count, tutor_name, tutor_title, tutor_avatar, tutor_id, short_description, long_description, thumbnail) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $lessonStmt = $pdo->prepare("INSERT INTO `lessons` (id, course_id, title, duration, video_url, content, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $quizStmt = $pdo->prepare("INSERT INTO `quizzes` (question_id, course_id, question, option_1, option_2, option_3, option_4, answer_index) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $topicStmt = $pdo->prepare("INSERT INTO `forum_topics` (qa_id, course_id, student_name, student_avatar, question, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $replyStmt = $pdo->prepare("INSERT INTO `forum_replies` (qa_id, replier_name, replier_avatar, content, timestamp) VALUES (?, ?, ?, ?, ?)");

    // 2. Create the default student in users table
    $studentData = $data['student'];
    $userStmt = $pdo->prepare("INSERT INTO `users` (name, email, password_hash, avatar, academic_id, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $userStmt->execute([
        $studentData['name'],
        $studentData['email'],
        $defaultPassHash,
        $studentData['avatar'],
        '202604',
        'student',
        'active'
    ]);

    $studentId = $pdo->lastInsertId();

    foreach ($data['courses'] as $course) {
        $courseStmt->execute([
            $course['id'],
            $course['title'],
            $course['category'],
            $course['level'],
            $course['duration'],
            $course['enrolled_count'],
            $course['rating'],
            $course['review_count'],
            $course['tutor']['name'],
            $course['tutor']['title'],
            $course['tutor']['avatar'],
            $teacherId,
            $course['short_description'],
            $course['long_description'],
            $course['thumbnail']
        ]);

        // Lessons
        if (isset($course['lessons'])) {
            foreach ($course['lessons'] as $idx => $lesson) {
                $lessonStmt->execute([
                    $lesson['id'],
                    $course['id'],
                    $lesson['title'],
                    $lesson['duration'],
                    $lesson['video_url'],
                    $lesson['content'],
                    $idx
                ]);
            }
        }

        // Quizzes
        if (isset($course['quiz'])) {
            foreach ($course['quiz'] as $quiz) {
                $quizStmt->execute([
                    $quiz['question_id'],
                    $course['id'],
                    $quiz['question'],
                    $quiz['options'][0] ?? '',
                    $quiz['options'][1] ?? '',
                    $quiz['options'][2] ?? '',
                    $quiz['options'][3] ?? '',
                    $quiz['answer_index']
                ]);
            }
        }

        // Forum Topics (Q&A)
        if (isset($course['qa'])) {
            foreach ($course['qa'] as $qa) {
                $topicStmt->execute([
                    $qa['qa_id'],
                    $course['id'],
                    $qa['student_name'],
                    $qa['student_avatar'],
                    $qa['question'],
                    $qa['timestamp']
                ]);

                if (isset($qa['answers'])) {
                    foreach ($qa['answers'] as $reply) {
                        $replyStmt->execute([
                            $qa['qa_id'],
                            $reply['replier_name'],
                            $reply['replier_avatar'],
                            $reply['content'],
                            $reply['timestamp']
                        ]);
                    }
                }
            }
        }
    }

    // Seed default enrollments, completions, and quiz scores
    $enrollStmt = $pdo->prepare("INSERT INTO `enrollments` (user_id, course_id) VALUES (?, ?)");
    foreach ($data['enrolled_courses'] as $ec) {
        $enrollStmt->execute([$studentId, $ec]);
    }

    $completeStmt = $pdo->prepare("INSERT INTO `completed_lessons` (user_id, lesson_id) VALUES (?, ?)");
    foreach ($studentData['completed_lessons'] as $cl) {
        $completeStmt->execute([$studentId, $cl]);
    }

    $scoreStmt = $pdo->prepare("INSERT INTO `quiz_results` (user_id, course_id, score) VALUES (?, ?, ?)");
    foreach ($studentData['quiz_results'] as $c_id => $score) {
        $scoreStmt->execute([$studentId, $c_id, $score]);
    }
}

// Global Helper function to retrieve any setting key from site_settings with fallback default
function get_site_setting($key, $default = '')
{
    static $settings_cache = [];
    if (array_key_exists($key, $settings_cache)) {
        return $settings_cache[$key];
    }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && $val !== '') {
            $settings_cache[$key] = $val;
            return $val;
        }
    } catch (Exception $e) {
    }
    $settings_cache[$key] = $default;
    return $default;
}

// Global Helper function to retrieve current active site logo with cache buster
function get_site_logo()
{
    static $logo_path = null;
    if ($logo_path !== null) {
        return $logo_path;
    }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_logo' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if (!empty($val)) {
            $logo_path = $val;
            return $logo_path;
        }
    } catch (Exception $e) {
    }

    $logo_path = 'assets/logo.png';
    return $logo_path;
}

// Global Helper function to retrieve user profile picture or initial letters default avatar URL
function get_user_avatar($avatar = null, $name = 'User', $background = '0f4c81', $color = 'fff')
{
    if (!empty($avatar)) {
        $avatar = trim($avatar);
        if (preg_match('~^https?://~i', $avatar) || strpos($avatar, 'data:') === 0) {
            return $avatar;
        }
        $clean_path = ltrim($avatar, '/');
        if (file_exists(__DIR__ . '/../' . $clean_path)) {
            return $clean_path;
        }
    }
    $displayName = !empty($name) ? trim($name) : 'User';
    return 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=' . urlencode($background) . '&color=' . urlencode($color);
}
?>