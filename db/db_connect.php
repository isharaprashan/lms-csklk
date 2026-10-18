<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/config.php';

// Set application timezone
date_default_timezone_set('Asia/Colombo');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'computerscience_lms');

function getDBConnection()
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        // Try connecting to the database directly
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $pe) {
            // Fallback to alternate host (127.0.0.1 <-> localhost) if needed
            $altHost = (DB_HOST === 'localhost') ? '127.0.0.1' : 'localhost';
            $altDsn = "mysql:host=" . $altHost . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($altDsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        // Run migrations only if not recently validated, avoiding heavy 85+ query overhead on every connection
        static $migrationsRun = false;
        if (!$migrationsRun) {
            $migrationsRun = true;
            $migrationLock = __DIR__ . '/.migration_check_lock';
            if (!file_exists($migrationLock) || (time() - filemtime($migrationLock) > 21600)) {
                ensureMigrations($pdo);
                @touch($migrationLock);
            }
        }

        return $pdo;
    } catch (PDOException $e) {
        // If database doesn't exist, we connect to host and initialize it
        if ($e->getCode() === 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
            $pdo = initializeDatabase();
            return $pdo;
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
    try {
        $pdo->query("SELECT phone FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER dob");
    }
    try {
        $pdo->query("SELECT address FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN address TEXT NULL AFTER phone");
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
    try {
        $pdo->query("SELECT must_change_password FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER otp_expires_at");
    }
    try {
        $pdo->query("SELECT temp_password_created_at FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN temp_password_created_at DATETIME NULL AFTER must_change_password");
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

    // 3.3.1 Check and add 'is_archived' and 'deleted_at' to 'courses'
    try {
        $pdo->query("SELECT is_archived FROM courses LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN is_archived TINYINT(1) DEFAULT 0 AFTER status");
    }
    try {
        $pdo->query("SELECT deleted_at FROM courses LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN deleted_at DATETIME NULL AFTER is_archived");
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

    // 3.5 Ensure notifications table exists and has rich metadata columns
    try {
        $pdo->query("SELECT id FROM notifications LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `title` VARCHAR(255) NULL,
            `message` TEXT NOT NULL,
            `type` VARCHAR(50) DEFAULT 'system',
            `link` VARCHAR(255) NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT title FROM notifications LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NULL AFTER user_id");
    }

    try {
        $pdo->query("SELECT type FROM notifications LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(50) DEFAULT 'system' AFTER message");
    }

    try {
        $pdo->query("SELECT link FROM notifications LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL AFTER type");
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
            `category` VARCHAR(50) DEFAULT 'notice',
            `status` VARCHAR(20) DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT category FROM site_announcements LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE site_announcements ADD COLUMN category VARCHAR(50) DEFAULT 'notice' AFTER badge_text");
    }

    $announcementCheck = $pdo->query("SELECT COUNT(*) FROM site_announcements");
    if ($announcementCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO site_announcements (title, content, badge_text, category, status) VALUES
        ('Registration open for Algorithms 2026 Batch', 'The new academic program covering complex sorting paradigms and computational models starts next Tuesday. Check requirements in the course directory.', 'July 15, 2026', 'launch', 'active'),
        ('Special Weekend Workshop on Cloud & Microservices', 'Join industry architects for an intensive live session on Kubernetes and distributed microservice patterns with exclusive early-bird access.', 'Special Offer', 'offer', 'active'),
        ('Scheduled Apache and MySQL database maintenance', 'The local XAMPP database instances will be offline briefly on Sunday morning for index optimizations. Direct operations will resume promptly.', 'Maintenance', 'notice', 'active')");
    }

    // 3.7.0 Ensure promotional_banners table exists and seed default records
    try {
        $pdo->query("SELECT id FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `promotional_banners` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `subtitle` VARCHAR(255) NULL,
            `image_path` VARCHAR(255) NOT NULL,
            `details_content` TEXT NOT NULL,
            `cta_button_text` VARCHAR(100) DEFAULT 'Learn More',
            `cta_button_url` VARCHAR(255) NULL,
            `display_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Ensure all promotional_banners columns exist if upgraded
    try {
        $pdo->query("SELECT subtitle FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE promotional_banners ADD COLUMN subtitle VARCHAR(255) NULL AFTER title");
    }
    try {
        $pdo->query("SELECT details_content FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE promotional_banners ADD COLUMN details_content TEXT NOT NULL AFTER image_path");
    }
    try {
        $pdo->query("SELECT cta_button_text FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE promotional_banners ADD COLUMN cta_button_text VARCHAR(100) DEFAULT 'Learn More' AFTER details_content");
    }
    try {
        $pdo->query("SELECT cta_button_url FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE promotional_banners ADD COLUMN cta_button_url VARCHAR(255) NULL AFTER cta_button_text");
    }
    try {
        $pdo->query("SELECT display_order FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE promotional_banners ADD COLUMN display_order INT DEFAULT 0 AFTER cta_button_url");
    }
    try {
        $pdo->query("SELECT is_active FROM promotional_banners LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE promotional_banners ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER display_order");
    }

    $bannerCheck = $pdo->query("SELECT COUNT(*) FROM promotional_banners");
    if ($bannerCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO promotional_banners (title, subtitle, image_path, details_content, cta_button_text, cta_button_url, display_order, is_active) VALUES
        ('Full-Stack Software Engineering 2026 Batch', 'Master Modern Web Development, Cloud Infrastructure & DevOps with Industry Mentors', 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?w=1200&auto=format&fit=crop&q=80', 'Embark on an intensive, immersive journey through modern full-stack development. Covering HTML5, Modern CSS, React, PHP, Node.js, relational database architecture, Docker containers, and automated deployment pipelines.<br><br><strong>Key Features:</strong><br>• Hands-on project portfolio building<br>• Live weekly interactive mentor sessions<br>• Verified Certificate of Academic Completion<br>• Career readiness and technical interview preparation<br><br>Learn more and register at: https://computerscience.lk/bootcamp-curriculum', '', '', 1, 1),
        ('AI & Data Science Advanced Masterclass', 'Deep Learning, Neural Networks & Machine Learning Pipelines', 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=1200&auto=format&fit=crop&q=80', 'Unlock high-demand skills in Artificial Intelligence and Computational Data Science. Learn predictive modelling, natural language processing with modern transformer architectures, and deep neural networks with Python, NumPy, Pandas, Scikit-Learn, and PyTorch.<br><br><strong>Curriculum Includes:</strong><br>• Comprehensive theoretical and hands-on modules<br>• Full source code and datasets access<br>• Lifetime access to recorded lecture labs<br><br>Detailed syllabus: https://computerscience.lk/ai-curriculum', '', '', 2, 1),
        ('Cloud Architecture & DevOps Certification Workshop', 'Accelerate Your Infrastructure Skills with Kubernetes, CI/CD, and Microservices', 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=1200&auto=format&fit=crop&q=80', 'Designed for software engineers and aspiring cloud solutions architects looking to elevate their system design capabilities. Deep dive into Kubernetes orchestration, infrastructure as code (Terraform), robust microservice communication, and multi-region high availability.<br><br><strong>Highlights:</strong><br>• Enterprise cloud architectural case studies<br>• Interactive sandbox environments<br>• Digital credential verification ID for LinkedIn<br><br>Workshop details: https://computerscience.lk/devops-program', '', '', 3, 1)");
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

    // Ensure quizzes are strictly MCQ only
    try {
        $pdo->exec("UPDATE quizzes SET question_type = 'mcq', 
            option_1 = IF(option_1 IS NULL OR option_1 = '', 'Option A', option_1), 
            option_2 = IF(option_2 IS NULL OR option_2 = '', 'Option B', option_2), 
            option_3 = IF(option_3 IS NULL OR option_3 = '', 'Option C', option_3), 
            option_4 = IF(option_4 IS NULL OR option_4 = '', 'Option D', option_4), 
            answer_index = IF(answer_index < 0, 0, answer_index) 
            WHERE question_type != 'mcq' OR question_type IS NULL");
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
            'google_oauth_enabled' => '1',
            'site_favicon' => 'assets/logo.png'
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

    // 3.11 Ensure lesson_assignments and assignment_submissions tables exist
    try {
        $pdo->query("SELECT id FROM lesson_assignments LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lesson_assignments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `lesson_id` VARCHAR(50) NOT NULL UNIQUE,
            `course_id` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `instructions` TEXT NULL,
            `attachment_path` VARCHAR(255) NULL,
            `attachment_name` VARCHAR(255) NULL,
            `attachment_size` INT NULL,
            `due_date` DATETIME NULL,
            `created_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`course_id`),
            INDEX (`lesson_id`),
            FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    try {
        $pdo->query("SELECT id FROM assignment_submissions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `assignment_submissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `assignment_id` INT NOT NULL,
            `lesson_id` VARCHAR(50) NOT NULL,
            `course_id` VARCHAR(50) NOT NULL,
            `user_id` INT NOT NULL,
            `submission_file` VARCHAR(255) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_size` INT NOT NULL,
            `student_notes` TEXT NULL,
            `status` VARCHAR(20) DEFAULT 'submitted',
            `grade` DECIMAL(5,2) NULL,
            `feedback` TEXT NULL,
            `graded_at` DATETIME NULL,
            `graded_by` INT NULL,
            `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_student_assignment` (`assignment_id`, `user_id`),
            INDEX (`course_id`),
            INDEX (`lesson_id`),
            INDEX (`user_id`),
            FOREIGN KEY (`assignment_id`) REFERENCES `lesson_assignments`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Ensure grading columns exist on assignment_submissions
    try {
        $pdo->query("SELECT grade FROM assignment_submissions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN grade DECIMAL(5,2) NULL AFTER status");
    }
    try {
        $pdo->query("SELECT feedback FROM assignment_submissions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN feedback TEXT NULL AFTER grade");
    }
    try {
        $pdo->query("SELECT graded_at FROM assignment_submissions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN graded_at DATETIME NULL AFTER feedback");
    }
    try {
        $pdo->query("SELECT graded_by FROM assignment_submissions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN graded_by INT NULL AFTER graded_at");
    }

    // Ensure upload directories exist
    if (function_exists('root_path')) {
        $tasksDir = root_path('uploads/assignments/tasks/');
        if (!file_exists($tasksDir))
            @mkdir($tasksDir, 0755, true);
        $subsDir = root_path('uploads/assignments/submissions/');
        if (!file_exists($subsDir))
            @mkdir($subsDir, 0755, true);
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

    // Ensure hero_image_path and hero_image_alt columns exist (hero portrait image manager)
    try {
        $pdo->query("SELECT hero_image_path FROM hero_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN hero_image_path VARCHAR(255) NULL AFTER bg_image_3");
    }
    try {
        $pdo->query("SELECT hero_image_alt FROM hero_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE hero_settings ADD COLUMN hero_image_alt VARCHAR(255) DEFAULT 'Student with books' AFTER hero_image_path");
    }

    // Ensure social media URL columns exist in hero_settings
    $social_columns = [
        'facebook_url' => "VARCHAR(255) DEFAULT '#'",
        'twitter_url' => "VARCHAR(255) DEFAULT '#'",
        'telegram_url' => "VARCHAR(255) DEFAULT '#'",
        'instagram_url' => "VARCHAR(255) DEFAULT '#'",
        'youtube_url' => "VARCHAR(255) NULL",
        'linkedin_url' => "VARCHAR(255) NULL",
        'whatsapp_url' => "VARCHAR(255) NULL"
    ];
    foreach ($social_columns as $col => $col_def) {
        try {
            $pdo->query("SELECT $col FROM hero_settings LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE hero_settings ADD COLUMN $col $col_def");
        }
    }

    $heroCheck = $pdo->query("SELECT COUNT(*) FROM hero_settings");
    if ($heroCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO hero_settings (id, title, description, button_text, button_url, secondary_button_text, secondary_button_url, phone_number, enrolled_students_count, bg_image_1, bg_image_2, bg_image_3, hero_image_path, hero_image_alt, facebook_url, twitter_url, telegram_url, instagram_url, youtube_url, linkedin_url, whatsapp_url) VALUES
        (1, 'Enhance Your Skills With Our Online Courses', 'Dive into a World of Knowledge with Our Comprehensive and Engaging Online Courses Designed for Skill Enhancement', 'Apply Now', '#courses-section', 'Know More', '#courses-section', 'Call Us : 011 234 5678', '30K Enrolled Students', NULL, NULL, NULL, NULL, 'Student with books', '#', '#', '#', '#', NULL, NULL, NULL)");
    }

    // 3.8B Ensure about_settings table exists
    try {
        $pdo->query("SELECT id FROM about_settings LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `about_settings` (
            `id` INT PRIMARY KEY,
            `badge_text` VARCHAR(100) DEFAULT 'About Computerscience.lk',
            `title` VARCHAR(255) DEFAULT 'Empowering the Next Generation of Tech Leaders & Innovators',
            `subtitle` TEXT NULL,
            `bg_image` VARCHAR(255) DEFAULT 'uploads/about/default_about_bg.jpg',
            `overlay_fade_style` VARCHAR(50) DEFAULT 'dark_vignette',
            `overlay_opacity` DECIMAL(3,2) DEFAULT 0.65,
            `story_badge` VARCHAR(100) DEFAULT 'Our Heritage & Journey',
            `story_title` VARCHAR(255) DEFAULT 'Bridging Higher Academics with Real-World Software Engineering',
            `story_content` TEXT NULL,
            `mission_title` VARCHAR(150) DEFAULT 'Our Mission',
            `mission_content` TEXT NULL,
            `vision_title` VARCHAR(150) DEFAULT 'Our Vision',
            `vision_content` TEXT NULL,
            `stat_1_number` VARCHAR(50) DEFAULT '30,000+',
            `stat_1_label` VARCHAR(100) DEFAULT 'Enrolled Students',
            `stat_2_number` VARCHAR(50) DEFAULT '120+',
            `stat_2_label` VARCHAR(100) DEFAULT 'Certified Courses',
            `stat_3_number` VARCHAR(50) DEFAULT '50+',
            `stat_3_label` VARCHAR(100) DEFAULT 'Expert Mentors',
            `stat_4_number` VARCHAR(50) DEFAULT '98.5%',
            `stat_4_label` VARCHAR(100) DEFAULT 'Student Satisfaction',
            `pillar_1_icon` VARCHAR(50) DEFAULT 'bi-cpu',
            `pillar_1_title` VARCHAR(100) DEFAULT 'Industry-Aligned Curricula',
            `pillar_1_desc` VARCHAR(255) DEFAULT 'Engineered alongside software architects to match cutting-edge industry demands.',
            `pillar_2_icon` VARCHAR(50) DEFAULT 'bi-award-fill',
            `pillar_2_title` VARCHAR(100) DEFAULT 'Recognized Certificates',
            `pillar_2_desc` VARCHAR(255) DEFAULT 'Earn verifiable digital credentials and embossed hard-copy diplomas delivered island-wide.',
            `pillar_3_icon` VARCHAR(50) DEFAULT 'bi-camera-video-fill',
            `pillar_3_title` VARCHAR(100) DEFAULT 'Live & Interactive Classes',
            `pillar_3_desc` VARCHAR(255) DEFAULT 'Real-time lecture streams with hands-on coding demonstrations and instant tutor Q&A.',
            `pillar_4_icon` VARCHAR(50) DEFAULT 'bi-people-fill',
            `pillar_4_title` VARCHAR(100) DEFAULT 'Supportive Community',
            `pillar_4_desc` VARCHAR(255) DEFAULT 'Join peer collaborative study groups, assignment code reviews, and career guidance.',
            `contact_email` VARCHAR(100) DEFAULT 'info@computerscience.lk',
            `contact_phone` VARCHAR(100) DEFAULT '011 234 5678',
            `contact_address` VARCHAR(255) DEFAULT 'Colombo, Western Province, Sri Lanka',
            `contact_hours` VARCHAR(100) DEFAULT 'Mon - Sat: 8.30 AM - 6.00 PM',
            `cta_title` VARCHAR(255) DEFAULT 'Start Your Computing Journey Today',
            `cta_desc` VARCHAR(255) DEFAULT 'Explore our comprehensive course catalog, build production-ready projects, and achieve recognized certification.',
            `cta_btn_text` VARCHAR(100) DEFAULT 'Browse Courses',
            `cta_btn_url` VARCHAR(255) DEFAULT 'index.php#courses-section',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Ensure all columns exist in about_settings if table was previously created with fewer columns
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM about_settings");
        if ($colStmt) {
            $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('bg_image', $existingCols)) {
                $pdo->exec("ALTER TABLE about_settings ADD COLUMN bg_image VARCHAR(255) DEFAULT 'uploads/about/default_about_bg.jpg' AFTER subtitle");
            }
            if (!in_array('overlay_fade_style', $existingCols)) {
                $pdo->exec("ALTER TABLE about_settings ADD COLUMN overlay_fade_style VARCHAR(50) DEFAULT 'dark_vignette' AFTER bg_image");
            }
            if (!in_array('overlay_opacity', $existingCols)) {
                $pdo->exec("ALTER TABLE about_settings ADD COLUMN overlay_opacity DECIMAL(3,2) DEFAULT 0.65 AFTER overlay_fade_style");
            }
        }
    } catch (PDOException $ex) {
    }

    try {
        $aboutCheck = $pdo->query("SELECT COUNT(*) FROM about_settings");
        if ($aboutCheck && $aboutCheck->fetchColumn() == 0) {
            $stmtInitAbout = $pdo->prepare("INSERT INTO about_settings (
                id, badge_text, title, subtitle, bg_image, overlay_fade_style, overlay_opacity,
                story_badge, story_title, story_content, mission_title, mission_content, vision_title, vision_content,
                stat_1_number, stat_1_label, stat_2_number, stat_2_label, stat_3_number, stat_3_label, stat_4_number, stat_4_label,
                pillar_1_icon, pillar_1_title, pillar_1_desc,
                pillar_2_icon, pillar_2_title, pillar_2_desc,
                pillar_3_icon, pillar_3_title, pillar_3_desc,
                pillar_4_icon, pillar_4_title, pillar_4_desc,
                contact_email, contact_phone, contact_address, contact_hours,
                cta_title, cta_desc, cta_btn_text, cta_btn_url
            ) VALUES (
                1, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?
            )");
            $stmtInitAbout->execute([
                'About Computerscience.lk',
                'Empowering the Next Generation of Tech Leaders & Innovators',
                'Computerscience.lk is Sri Lanka\'s dedicated online academic portal designed to impart university-grade computer science education, practical software engineering skills, and globally accredited certifications to aspiring learners.',
                'uploads/about/default_about_bg.jpg',
                'dark_vignette',
                0.65,
                'Our Heritage & Journey',
                'Bridging Higher Academics with Real-World Software Engineering',
                'Founded with a bold vision to make high-caliber computer science training universally accessible, Computerscience.lk has evolved into a premier learning destination. We merge traditional theoretical computer science fundamentals with the velocity of modern tech stacks.' . "\n\n" . 'Whether mastering algorithms, full-stack software development, cloud systems, or cybersecurity, our students gain actionable competencies backed by verifiable course credentials and hands-on portfolio work.',
                'Our Mission',
                'To democratize access to world-class computational education, offering students and professionals rigorous, practical courses, hands-on development experience, and industry mentorship that accelerates their technical careers.',
                'Our Vision',
                'To be the preeminent technology learning hub in South Asia, fostering a culture of innovation, critical computational problem-solving, and continuous academic advancement.',
                '30,000+', 'Enrolled Students',
                '120+', 'Certified Courses',
                '50+', 'Expert Mentors',
                '98.5%', 'Student Satisfaction',
                'bi-cpu', 'Industry-Aligned Curricula', 'Engineered alongside software architects to match cutting-edge industry demands.',
                'bi-award-fill', 'Recognized Certificates', 'Earn verifiable digital credentials and embossed hard-copy diplomas delivered island-wide.',
                'bi-camera-video-fill', 'Live & Interactive Classes', 'Real-time lecture streams with hands-on coding demonstrations and instant tutor Q&A.',
                'bi-people-fill', 'Supportive Community', 'Join peer collaborative study groups, assignment code reviews, and career guidance.',
                'info@computerscience.lk', '011 234 5678', 'Colombo, Western Province, Sri Lanka', 'Mon - Sat: 8.30 AM - 6.00 PM',
                'Start Your Computing Journey Today',
                'Explore our comprehensive course catalog, build production-ready projects, and achieve recognized certification.',
                'Browse Courses',
                'index.php#courses-section'
            ]);
        }
    } catch (PDOException $ex) {
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

    // 3.17 Ensure lesson_resources table exists (Lesson supplementary files / attachments)
    try {
        $pdo->query("SELECT id FROM lesson_resources LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lesson_resources` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `lesson_id` VARCHAR(50) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(255) NOT NULL,
            `file_type` VARCHAR(50) NOT NULL,
            `file_size` INT NOT NULL,
            `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`lesson_id`),
            FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 3.18 Ensure password_resets table exists (Forgot / Reset Password tokens)
    try {
        $pdo->query("SELECT id FROM password_resets LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            INDEX (`email`),
            INDEX (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // 3.19 Ensure admin_password_resets table exists (Admin & Super Admin Password Reset tokens)
    try {
        $pdo->query("SELECT id FROM admin_password_resets LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'super_admin') NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `is_used` TINYINT(1) DEFAULT 0,
            INDEX (`email`),
            INDEX (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
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
                `phone` VARCHAR(30) NULL,
                `address` TEXT NULL,
                `google_id` VARCHAR(255) NULL UNIQUE,
                `auth_provider` ENUM('local', 'google') DEFAULT 'local',
                `email_verified` TINYINT(1) DEFAULT 0,
                `otp_code` VARCHAR(6) NULL,
                `otp_expires_at` DATETIME NULL,
                `must_change_password` TINYINT(1) DEFAULT 0,
                `temp_password_created_at` DATETIME NULL,
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
                `is_archived` TINYINT(1) DEFAULT 0,
                `deleted_at` DATETIME NULL,
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

            // Lesson Resources table
            "CREATE TABLE IF NOT EXISTS `lesson_resources` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `lesson_id` VARCHAR(50) NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `file_path` VARCHAR(255) NOT NULL,
                `file_type` VARCHAR(50) NOT NULL,
                `file_size` INT NOT NULL,
                `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`lesson_id`),
                FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Password Resets table
            "CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL,
                `token` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                INDEX (`email`),
                INDEX (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // Admin Password Resets table
            "CREATE TABLE IF NOT EXISTS `admin_password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL,
                `token` VARCHAR(255) NOT NULL,
                `role` ENUM('admin', 'super_admin') NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `is_used` TINYINT(1) DEFAULT 0,
                INDEX (`email`),
                INDEX (`token`)
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

// Global Helper function to retrieve current active site favicon with cache buster
function get_site_favicon()
{
    static $favicon_path = null;
    if ($favicon_path !== null) {
        return $favicon_path;
    }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_favicon' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if (!empty($val)) {
            $favicon_path = $val;
            return $favicon_path;
        }
    } catch (Exception $e) {
    }

    if (file_exists(__DIR__ . '/../assets/favicon.ico')) {
        $favicon_path = 'assets/favicon.ico';
    } elseif (file_exists(__DIR__ . '/../assets/favicon.png')) {
        $favicon_path = 'assets/favicon.png';
    } else {
        $favicon_path = get_site_logo();
    }
    return $favicon_path;
}

// Global Helper function to retrieve user profile picture or initial letters default avatar URL
function get_user_avatar($avatar = null, $name = 'User', $background = '0f4c81', $color = 'fff', $prefix = '')
{
    if (!empty($avatar)) {
        $avatar = trim($avatar);
        if (preg_match('~^https?://~i', $avatar) || strpos($avatar, 'data:') === 0) {
            return $avatar;
        }
        $clean_path = ltrim($avatar, '/');
        if (file_exists(__DIR__ . '/../' . $clean_path)) {
            return $prefix . $clean_path;
        }
        if (file_exists(__DIR__ . '/../admin/' . $clean_path)) {
            return $prefix . 'admin/' . $clean_path;
        }
        if (strpos($clean_path, 'uploads/') === 0 || strpos($clean_path, 'upload/') === 0 || strpos($clean_path, 'assets/') === 0) {
            return $prefix . $clean_path;
        }
    }
    $displayName = !empty($name) ? trim($name) : 'User';
    return 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=' . urlencode($background) . '&color=' . urlencode($color);
}

// Global Helper function to retrieve Login page split-screen background image
function get_login_page_image()
{
    static $login_img = null;
    if ($login_img !== null) {
        return $login_img;
    }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'login_page_image' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if (!empty($val)) {
            $login_img = $val;
            return $login_img;
        }
    } catch (Exception $e) {
    }

    $login_img = 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=1600&auto=format&fit=crop&q=85';
    return $login_img;
}

// Global Helper function to retrieve Register page split-screen background image
function get_register_page_image()
{
    static $register_img = null;
    if ($register_img !== null) {
        return $register_img;
    }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'register_page_image' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if (!empty($val)) {
            $register_img = $val;
            return $register_img;
        }
    } catch (Exception $e) {
    }

    $register_img = 'https://images.unsplash.com/photo-1523240795612-9a054b0db644?w=1600&auto=format&fit=crop&q=85';
    return $register_img;
}

// Global Helper function to retrieve About Us page settings and customizable details
function get_about_settings($pdo = null)
{
    static $about_cached = null;
    if ($about_cached !== null) {
        return $about_cached;
    }
    $defaults = [
        'id' => 1,
        'badge_text' => 'About Computerscience.lk',
        'title' => 'Empowering the Next Generation of Tech Leaders & Innovators',
        'subtitle' => 'Computerscience.lk is Sri Lanka’s dedicated online academic portal designed to impart university-grade computer science education, practical software engineering skills, and globally accredited certifications to aspiring learners.',
        'bg_image' => 'uploads/about/default_about_bg.jpg',
        'overlay_fade_style' => 'dark_vignette',
        'overlay_opacity' => 0.65,
        'story_badge' => 'Our Heritage & Journey',
        'story_title' => 'Bridging Higher Academics with Real-World Software Engineering',
        'story_content' => "Founded with a bold vision to make high-caliber computer science training universally accessible, Computerscience.lk has evolved into a premier learning destination. We merge traditional theoretical computer science fundamentals with the velocity of modern tech stacks.\n\nWhether mastering algorithms, full-stack software development, cloud systems, or cybersecurity, our students gain actionable competencies backed by verifiable course credentials and hands-on portfolio work.",
        'mission_title' => 'Our Mission',
        'mission_content' => 'To democratize access to world-class computational education, offering students and professionals rigorous, practical courses, hands-on development experience, and industry mentorship that accelerates their technical careers.',
        'vision_title' => 'Our Vision',
        'vision_content' => 'To be the preeminent technology learning hub in South Asia, fostering a culture of innovation, critical computational problem-solving, and continuous academic advancement.',
        'stat_1_number' => '30,000+',
        'stat_1_label' => 'Enrolled Students',
        'stat_2_number' => '120+',
        'stat_2_label' => 'Certified Courses',
        'stat_3_number' => '50+',
        'stat_3_label' => 'Expert Mentors',
        'stat_4_number' => '98.5%',
        'stat_4_label' => 'Student Satisfaction',
        'pillar_1_icon' => 'bi-cpu',
        'pillar_1_title' => 'Industry-Aligned Curricula',
        'pillar_1_desc' => 'Engineered alongside software architects to match cutting-edge industry demands.',
        'pillar_2_icon' => 'bi-award-fill',
        'pillar_2_title' => 'Recognized Certificates',
        'pillar_2_desc' => 'Earn verifiable digital credentials and embossed hard-copy diplomas delivered island-wide.',
        'pillar_3_icon' => 'bi-camera-video-fill',
        'pillar_3_title' => 'Live & Interactive Classes',
        'pillar_3_desc' => 'Real-time lecture streams with hands-on coding demonstrations and instant tutor Q&A.',
        'pillar_4_icon' => 'bi-people-fill',
        'pillar_4_title' => 'Supportive Community',
        'pillar_4_desc' => 'Join peer collaborative study groups, assignment code reviews, and career guidance.',
        'contact_email' => 'info@computerscience.lk',
        'contact_phone' => '011 234 5678',
        'contact_address' => 'Colombo, Western Province, Sri Lanka',
        'contact_hours' => 'Mon - Sat: 8.30 AM - 6.00 PM',
        'cta_title' => 'Start Your Computing Journey Today',
        'cta_desc' => 'Explore our comprehensive course catalog, build production-ready projects, and achieve recognized certification.',
        'cta_btn_text' => 'Browse Courses',
        'cta_btn_url' => 'index.php#courses-section'
    ];

    try {
        if (!$pdo) {
            $pdo = getDBConnection();
        }
        $stmt = $pdo->query("SELECT * FROM about_settings WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            $about_cached = array_merge($defaults, array_filter($row, fn($v) => $v !== null && $v !== ''));
            return $about_cached;
        }
    } catch (Exception $e) {
    }

    $about_cached = $defaults;
    return $about_cached;
}

/**
 * Check if a student has completed all quizzes for a specific course
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $course_id
 * @return array ['all_completed' => bool, 'total_quizzes' => int, 'completed_quizzes' => int, 'missing_quiz_titles' => array]
 */
function check_course_quizzes_completed($pdo, $user_id, $course_id)
{
    if (!$pdo || empty($user_id) || empty($course_id)) {
        return [
            'all_completed' => true,
            'total_quizzes' => 0,
            'completed_quizzes' => 0,
            'missing_quiz_titles' => []
        ];
    }

    // 1. Fetch all distinct quiz groups in this course
    $quizLessonsStmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quizzes WHERE course_id = ? AND lesson_id IS NOT NULL AND lesson_id != ''");
    $quizLessonsStmt->execute([$course_id]);
    $lesson_quiz_ids = $quizLessonsStmt->fetchAll(PDO::FETCH_COLUMN);

    $courseWideQuizStmt = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE course_id = ? AND (lesson_id IS NULL OR lesson_id = '')");
    $courseWideQuizStmt->execute([$course_id]);
    $has_course_wide_quiz = ((int) $courseWideQuizStmt->fetchColumn() > 0);

    $total_quizzes = count($lesson_quiz_ids) + ($has_course_wide_quiz ? 1 : 0);

    // If no quizzes exist for this course, it is satisfied
    if ($total_quizzes === 0) {
        return [
            'all_completed' => true,
            'total_quizzes' => 0,
            'completed_quizzes' => 0,
            'missing_quiz_titles' => []
        ];
    }

    $completed_quizzes_count = 0;
    $missing_quiz_titles = [];

    // 2. Check each lesson-level quiz
    if (!empty($lesson_quiz_ids)) {
        foreach ($lesson_quiz_ids as $l_id) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND lesson_id = ? AND status IN ('completed', 'finalized')");
            $checkStmt->execute([$user_id, $course_id, $l_id]);
            $isDone = ((int) $checkStmt->fetchColumn() > 0);

            if ($isDone) {
                $completed_quizzes_count++;
            } else {
                $lTitleStmt = $pdo->prepare("SELECT title FROM lessons WHERE id = ?");
                $lTitleStmt->execute([$l_id]);
                $title = $lTitleStmt->fetchColumn() ?: "Lesson Quiz";
                $missing_quiz_titles[] = $title;
            }
        }
    }

    // 3. Check course-wide quiz if present
    if ($has_course_wide_quiz) {
        $checkCwStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND (lesson_id IS NULL OR lesson_id = '') AND status IN ('completed', 'finalized')");
        $checkCwStmt->execute([$user_id, $course_id]);
        $isCwDone = ((int) $checkCwStmt->fetchColumn() > 0);

        if (!$isCwDone) {
            $resCwStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_results WHERE user_id = ? AND course_id = ? AND (status IN ('completed', 'finalized') OR score > 0)");
            $resCwStmt->execute([$user_id, $course_id]);
            $isCwDone = ((int) $resCwStmt->fetchColumn() > 0);
        }

        if ($isCwDone) {
            $completed_quizzes_count++;
        } else {
            $missing_quiz_titles[] = "Final Course Assessment Quiz";
        }
    }

    $all_completed = ($completed_quizzes_count >= $total_quizzes);

    return [
        'all_completed' => $all_completed,
        'total_quizzes' => $total_quizzes,
        'completed_quizzes' => $completed_quizzes_count,
        'missing_quiz_titles' => $missing_quiz_titles
    ];
}

/**
 * Helper function to verify if a student has submitted all assignments configured for a course
 * 
 * @param PDO $pdo
 * @param int $user_id
 * @param string $course_id
 * @return array ['all_completed' => bool, 'total_assignments' => int, 'completed_assignments' => int, 'missing_assignment_titles' => array]
 */
function check_course_assignments_completed($pdo, $user_id, $course_id)
{
    if (!$pdo || empty($user_id) || empty($course_id)) {
        return [
            'all_completed' => true,
            'total_assignments' => 0,
            'completed_assignments' => 0,
            'missing_assignment_titles' => [],
            'all_graded' => true,
            'graded_assignments' => 0,
            'pending_grading_assignments' => 0,
            'pending_grading_titles' => [],
            'unsubmitted_titles' => [],
            'average_grade' => null
        ];
    }

    try {
        // Fetch all assignments for this course
        $stmt = $pdo->prepare("SELECT a.id, a.title, a.lesson_id, l.title as lesson_title 
                               FROM lesson_assignments a 
                               INNER JOIN lessons l ON l.id = a.lesson_id 
                               WHERE a.course_id = ? 
                               ORDER BY l.sort_order ASC");
        $stmt->execute([$course_id]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_assignments = count($assignments);
        if ($total_assignments === 0) {
            return [
                'all_completed' => true,
                'total_assignments' => 0,
                'completed_assignments' => 0,
                'missing_assignment_titles' => [],
                'all_graded' => true,
                'graded_assignments' => 0,
                'pending_grading_assignments' => 0,
                'pending_grading_titles' => [],
                'unsubmitted_titles' => [],
                'average_grade' => null
            ];
        }

        // Fetch submissions with status and grade by this student for this course
        $subStmt = $pdo->prepare("SELECT assignment_id, status, grade, feedback FROM assignment_submissions WHERE user_id = ? AND course_id = ?");
        $subStmt->execute([$user_id, $course_id]);
        $submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        $submission_map = [];
        foreach ($submissions as $sub) {
            $submission_map[$sub['assignment_id']] = $sub;
        }

        $completed_count = 0;
        $graded_count = 0;
        $missing_titles = [];
        $pending_grading_titles = [];
        $unsubmitted_titles = [];
        $grades = [];

        foreach ($assignments as $asg) {
            $asgId = $asg['id'];
            $asgLabel = ($asg['lesson_title'] ?: "Lesson") . ": " . $asg['title'];
            if (isset($submission_map[$asgId])) {
                $completed_count++;
                $sub = $submission_map[$asgId];
                if ($sub['status'] === 'graded' && $sub['grade'] !== null) {
                    $graded_count++;
                    $grades[] = (float)$sub['grade'];
                } else {
                    $pending_grading_titles[] = $asgLabel;
                }
            } else {
                $missing_titles[] = $asgLabel;
                $unsubmitted_titles[] = $asgLabel;
            }
        }

        $all_completed = ($completed_count >= $total_assignments);
        $all_graded = ($total_assignments === 0 || $graded_count >= $total_assignments);

        return [
            'all_completed' => $all_completed,
            'total_assignments' => $total_assignments,
            'completed_assignments' => $completed_count,
            'missing_assignment_titles' => $missing_titles,
            'all_graded' => $all_graded,
            'graded_assignments' => $graded_count,
            'pending_grading_assignments' => ($completed_count - $graded_count),
            'pending_grading_titles' => $pending_grading_titles,
            'unsubmitted_titles' => $unsubmitted_titles,
            'average_grade' => (count($grades) > 0 ? round(array_sum($grades) / count($grades), 2) : null)
        ];
    } catch (PDOException $e) {
        return [
            'all_completed' => true,
            'total_assignments' => 0,
            'completed_assignments' => 0,
            'missing_assignment_titles' => [],
            'all_graded' => true,
            'graded_assignments' => 0,
            'pending_grading_assignments' => 0,
            'pending_grading_titles' => [],
            'unsubmitted_titles' => [],
            'average_grade' => null
        ];
    }
}

/**
 * Check if all required course assignments have marks assigned by teacher
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $course_id
 * @return array
 */
function check_course_assignments_graded($pdo, $user_id, $course_id)
{
    return check_course_assignments_completed($pdo, $user_id, $course_id);
}
?>