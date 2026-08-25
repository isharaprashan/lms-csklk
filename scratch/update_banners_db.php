<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();

$stmt1 = $pdo->prepare("UPDATE promotional_banners SET 
    title = ?, 
    subtitle = ?, 
    details_content = ?, 
    cta_button_text = '', 
    cta_button_url = '' 
    WHERE id = 1");
$stmt1->execute([
    'Full-Stack Software Engineering 2026 Batch',
    'Master Modern Web Development, Cloud Infrastructure & DevOps with Industry Mentors',
    'Embark on an intensive, immersive journey through modern full-stack development. Covering HTML5, Modern CSS, React, PHP, Node.js, relational database architecture, Docker containers, and automated deployment pipelines.<br><br><strong>Key Features:</strong><br>• Hands-on project portfolio building<br>• Live weekly interactive mentor sessions<br>• Verified Certificate of Academic Completion<br>• Career readiness and technical interview preparation<br><br>Learn more and register at: https://computerscience.lk/bootcamp-curriculum'
]);

$stmt2 = $pdo->prepare("UPDATE promotional_banners SET 
    title = ?, 
    subtitle = ?, 
    details_content = ?, 
    cta_button_text = '', 
    cta_button_url = '' 
    WHERE id = 2");
$stmt2->execute([
    'AI & Data Science Advanced Masterclass',
    'Deep Learning, Neural Networks & Machine Learning Pipelines',
    'Unlock high-demand skills in Artificial Intelligence and Computational Data Science. Learn predictive modelling, natural language processing with modern transformer architectures, and deep neural networks with Python, NumPy, Pandas, Scikit-Learn, and PyTorch.<br><br><strong>Curriculum Includes:</strong><br>• Comprehensive theoretical and hands-on modules<br>• Full source code and datasets access<br>• Lifetime access to recorded lecture labs<br><br>Detailed syllabus: https://computerscience.lk/ai-curriculum'
]);

$stmt3 = $pdo->prepare("UPDATE promotional_banners SET 
    title = ?, 
    subtitle = ?, 
    details_content = ?, 
    cta_button_text = '', 
    cta_button_url = '' 
    WHERE id = 3");
$stmt3->execute([
    'Cloud Architecture & DevOps Certification Workshop',
    'Accelerate Your Infrastructure Skills with Kubernetes, CI/CD, and Microservices',
    'Designed for software engineers and aspiring cloud solutions architects looking to elevate their system design capabilities. Deep dive into Kubernetes orchestration, infrastructure as code (Terraform), robust microservice communication, and multi-region high availability.<br><br><strong>Highlights:</strong><br>• Enterprise cloud architectural case studies<br>• Interactive sandbox environments<br>• Digital credential verification ID for LinkedIn<br><br>Workshop details: https://computerscience.lk/devops-program'
]);

echo "SUCCESS: Promotional banners database rows updated.\n";

$rows = $pdo->query("SELECT id, title, subtitle, details_content, cta_button_text, cta_button_url, is_active FROM promotional_banners")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
