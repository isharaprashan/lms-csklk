<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();

// Find an existing active course
$stmt = $pdo->query("SELECT c.id, c.title, c.tutor_id, l.id as lesson_id, l.title as lesson_title 
                     FROM courses c 
                     INNER JOIN lessons l ON l.course_id = c.id 
                     WHERE c.status = 'approved' AND c.is_archived = 0 
                     LIMIT 1");
$row = $stmt->fetch();

if ($row) {
    $lesson_id = $row['lesson_id'];
    $upload_dir = __DIR__ . '/../uploads/lesson_resources/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    // Clean existing dummy resources for this lesson
    $pdo->prepare("DELETE FROM lesson_resources WHERE lesson_id = ?")->execute([$lesson_id]);

    // Create 1: Lecture Notes PDF
    $pdf_file = 'res_demo_lecture_notes_' . time() . '.pdf';
    file_put_contents($upload_dir . $pdf_file, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Contents 4 0 R/Parent 2 0 R>>endobj 4 0 obj<</Length 50>>stream\nBT /F1 24 Tf 100 700 Td (Lecture Notes: Chapter 1) Tj ET\nendstream\nendobj xref 0 5 0000000000 65535 f 0000000009 00000 n 0000000058 00000 n 0000000115 00000 n 0000000210 00000 n trailer<</Size 5/Root 1 0 R>>startxref 310 %%EOF");
    $pdo->prepare("INSERT INTO lesson_resources (lesson_id, file_name, file_path, file_type, file_size) VALUES (?, 'Lecture_1_Comprehensive_Notes.pdf', ?, 'pdf', ?)")
        ->execute([$lesson_id, 'uploads/lesson_resources/' . $pdf_file, filesize($upload_dir . $pdf_file)]);

    // Create 2: Lab Assignment DOCX
    $doc_file = 'res_demo_lab_assignment_' . time() . '.docx';
    file_put_contents($upload_dir . $doc_file, "PK\x03\x04 Lab Assignment Word Document for Practical Exercise");
    $pdo->prepare("INSERT INTO lesson_resources (lesson_id, file_name, file_path, file_type, file_size) VALUES (?, 'Lab_Exercise_Assignment.docx', ?, 'docx', ?)")
        ->execute([$lesson_id, 'uploads/lesson_resources/' . $doc_file, filesize($upload_dir . $doc_file)]);

    // Create 3: Architecture Diagram PNG
    $img_file = 'res_demo_diagram_' . time() . '.png';
    file_put_contents($upload_dir . $img_file, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa74X\x1d\x00\x00\x00\x00IEND\xaeB`\x82");
    $pdo->prepare("INSERT INTO lesson_resources (lesson_id, file_name, file_path, file_type, file_size) VALUES (?, 'System_Architecture_Diagram.png', ?, 'png', ?)")
        ->execute([$lesson_id, 'uploads/lesson_resources/' . $img_file, filesize($upload_dir . $img_file)]);

    echo "Seeded 3 demo resources for course '{$row['title']}' (ID: {$row['id']}), Lesson: '{$row['lesson_title']}' (ID: {$lesson_id})\n";
} else {
    echo "No approved course found to seed.\n";
}
