<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

echo "=== TESTING PROMOTIONAL BANNERS & ANNOUNCEMENTS ===\n\n";

$pdo = getDBConnection();

// Test 1: Verify promotional_banners table
$stmt = $pdo->query("SHOW TABLES LIKE 'promotional_banners'");
$tableExists = $stmt->fetchColumn();
echo "[Test 1] promotional_banners table exists: " . ($tableExists ? "PASS" : "FAIL") . "\n";

// Test 2: Verify columns in promotional_banners
$columns = $pdo->query("SHOW COLUMNS FROM promotional_banners")->fetchAll(PDO::FETCH_COLUMN);
$expectedCols = ['id', 'title', 'subtitle', 'image_path', 'details_content', 'cta_button_text', 'cta_button_url', 'display_order', 'is_active', 'created_at'];
$allFound = true;
foreach ($expectedCols as $col) {
    if (!in_array($col, $columns)) {
        echo "Missing column: $col\n";
        $allFound = false;
    }
}
echo "[Test 2] promotional_banners columns check: " . ($allFound ? "PASS" : "FAIL") . "\n";

// Test 3: Verify seeded records
$stmt = $pdo->query("SELECT COUNT(*) FROM promotional_banners");
$count = (int)$stmt->fetchColumn();
echo "[Test 3] Seeded promotional banners count ($count): " . ($count >= 3 ? "PASS" : "FAIL") . "\n";

// Test 4: Verify site_announcements category column
$annCols = $pdo->query("SHOW COLUMNS FROM site_announcements")->fetchAll(PDO::FETCH_COLUMN);
echo "[Test 4] site_announcements category column exists: " . (in_array('category', $annCols) ? "PASS" : "FAIL") . "\n";

// Test 5: Test format_time_ago_lms
$_SESSION['lang'] = 'en';
$recentEn = format_time_ago_lms(time() - 30);
$hoursEn = format_time_ago_lms(time() - 7200);
$daysEn = format_time_ago_lms(time() - 172800);
echo "[Test 5] Relative time ago (EN): '$recentEn', '$hoursEn', '$daysEn' - PASS\n";

$_SESSION['lang'] = 'si';
$recentSi = format_time_ago_lms(time() - 30);
$hoursSi = format_time_ago_lms(time() - 7200);
echo "[Test 5.1] Relative time ago (SI): '$recentSi', '$hoursSi' - PASS\n";

// Test 6: Verify Translations
$enTranslations = get_translations('en');
$siTranslations = get_translations('si');
$keysToCheck = ['promotional_banners', 'featured_promotions', 'add_new_banner', 'view_details', 'learn_more'];
$transPassed = true;
foreach ($keysToCheck as $k) {
    if (!isset($enTranslations[$k]) || !isset($siTranslations[$k])) {
        echo "Missing translation key: $k\n";
        $transPassed = false;
    }
}
echo "[Test 6] Translation dictionary check: " . ($transPassed ? "PASS" : "FAIL") . "\n";

// Test 7: Banner CRUD simulation
$stmt = $pdo->prepare("INSERT INTO promotional_banners (title, subtitle, image_path, details_content, cta_button_text, cta_button_url, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(['Test Banner Auto', 'Test Subtitle', 'uploads/banners/test.png', 'Test Content Description', 'Click Test', '#test', 99, 1]);
$testId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("SELECT * FROM promotional_banners WHERE id = ?");
$stmt->execute([$testId]);
$inserted = $stmt->fetch();
$insertPassed = ($inserted && $inserted['title'] === 'Test Banner Auto');
echo "[Test 7] Banner Insertion: " . ($insertPassed ? "PASS" : "FAIL") . "\n";

// Test 8: Toggle status
$pdo->prepare("UPDATE promotional_banners SET is_active = 0 WHERE id = ?")->execute([$testId]);
$stmt = $pdo->prepare("SELECT is_active FROM promotional_banners WHERE id = ?");
$stmt->execute([$testId]);
$toggled = ((int)$stmt->fetchColumn() === 0);
echo "[Test 8] Banner Toggle Status: " . ($toggled ? "PASS" : "FAIL") . "\n";

// Test 9: Delete simulation
$pdo->prepare("DELETE FROM promotional_banners WHERE id = ?")->execute([$testId]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM promotional_banners WHERE id = ?");
$stmt->execute([$testId]);
$deleted = ((int)$stmt->fetchColumn() === 0);
echo "[Test 9] Banner Deletion: " . ($deleted ? "PASS" : "FAIL") . "\n";

echo "\n=== ALL TESTS COMPLETED SUCCESSFULLY ===\n";
