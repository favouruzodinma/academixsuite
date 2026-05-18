<?php
/**
 * School Profile & Enrollment Page - Professional School Landing Page
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/school_profile.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

// Load configuration
$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

// Get school slug from URL
$schoolSlug = trim((string)($_GET['slug'] ?? (function_exists('school_subdomain_slug') ? school_subdomain_slug() : '')), '/');
if (function_exists('redirect_legacy_school_url_to_subdomain')) {
    redirect_legacy_school_url_to_subdomain($schoolSlug, '', $_GET);
}

if (empty($schoolSlug)) {
    header('Location: /academixsuite/public/schools.php');
    exit;
}

// Initialize variables
$school = null;
$contacts = [];
$facilities = [];
$gallery = [];
$reviews = [];
$enrollmentStatus = 'open';
$enrollmentSuccess = false;
$enrollmentError = '';

// Get school details
try {
    $db = Database::getPlatformConnection();
    
    // Get school basic info
    $stmt = $db->prepare("
        SELECT 
            s.*, 
            p.name as plan_name,
            (SELECT COUNT(*) FROM school_reviews sr WHERE sr.school_id = s.id AND sr.is_approved = 1) as total_reviews,
            (SELECT AVG(rating) FROM school_reviews sr WHERE sr.school_id = s.id AND sr.is_approved = 1) as avg_rating
        FROM schools s 
        LEFT JOIN plans p ON s.plan_id = p.id 
        WHERE s.slug = ? AND s.status IN ('active', 'trial')
    ");
    $stmt->execute([$schoolSlug]);
    $school = $stmt->fetch();
    
    if (!$school) {
        header('HTTP/1.0 404 Not Found');
        die("School not found or is no longer active.");
    }
    
    // Get school contacts
    $stmt = $db->prepare("SELECT * FROM school_contacts WHERE school_id = ? ORDER BY is_primary DESC, sort_order, type");
    $stmt->execute([$school['id']]);
    $contacts = $stmt->fetchAll();
    
    // Get school facilities
    $stmt = $db->prepare("SELECT * FROM school_facilities WHERE school_id = ? AND is_active = 1 ORDER BY sort_order");
    $stmt->execute([$school['id']]);
    $facilities = $stmt->fetchAll();
    
    // Get school gallery
    $stmt = $db->prepare("SELECT * FROM school_gallery WHERE school_id = ? ORDER BY sort_order LIMIT 12");
    $stmt->execute([$school['id']]);
    $gallery = $stmt->fetchAll();
    
    // Get approved reviews
    $stmt = $db->prepare("
        SELECT * FROM school_reviews 
        WHERE school_id = ? AND is_approved = 1 
        ORDER BY helpful_count DESC, created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$school['id']]);
    $reviews = $stmt->fetchAll();
    
    // Check enrollment status
    $enrollmentStatus = $school['admission_status'] ?? 'open';
    $admissionDeadline = $school['admission_deadline'] ?? null;
    
    if ($admissionDeadline && strtotime($admissionDeadline) < time()) {
        $enrollmentStatus = 'closed';
    }
    
    // Handle enrollment form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enrollment_submit'])) {
        $enrollmentError = processEnrollment($school['id'], $db);
        if (empty($enrollmentError)) {
            $enrollmentSuccess = true;
            
            // Send confirmation email
            sendEnrollmentConfirmation($_POST);
        }
    }
    
} catch (Exception $e) {
    error_log("Error loading school profile: " . $e->getMessage());
    die("Error loading school information. Please try again later.");
}

// Function to process enrollment
function processEnrollment($schoolId, $db) {
    $errors = [];
    
    // Validate required fields
    $required = [
        'parent_first_name', 'parent_last_name', 'parent_email', 'parent_phone',
        'student_first_name', 'student_last_name', 'student_gender', 'student_dob',
        'student_grade', 'academic_year'
    ];
    
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
        }
    }
    
    // Validate email
    if (!empty($_POST['parent_email']) && !filter_var($_POST['parent_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    // Validate phone
    if (!empty($_POST['parent_phone']) && !preg_match('/^[0-9\-\+\s\(\)]{10,20}$/', $_POST['parent_phone'])) {
        $errors[] = "Please enter a valid phone number.";
    }
    
    // Validate date of birth
    if (!empty($_POST['student_dob'])) {
        $dob = DateTime::createFromFormat('Y-m-d', $_POST['student_dob']);
        if (!$dob) {
            $errors[] = "Invalid date of birth format.";
        } elseif ($dob > new DateTime('-3 years')) {
            $errors[] = "Student must be at least 3 years old.";
        }
    }
    
    // If there are errors, return them
    if (!empty($errors)) {
        return implode("<br>", $errors);
    }
    
    // Generate unique request number
    $requestNumber = 'ENR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Insert enrollment request
        $stmt = $db->prepare("
            INSERT INTO enrollment_requests (
                school_id, request_number, parent_first_name, parent_last_name,
                parent_email, parent_phone, parent_address, student_first_name,
                student_last_name, student_gender, student_date_of_birth,
                student_grade_level, student_previous_school, enrollment_type,
                academic_year, academic_term, special_requirements, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $schoolId,
            $requestNumber,
            htmlspecialchars($_POST['parent_first_name']),
            htmlspecialchars($_POST['parent_last_name']),
            htmlspecialchars($_POST['parent_email']),
            htmlspecialchars($_POST['parent_phone']),
            htmlspecialchars($_POST['parent_address'] ?? ''),
            htmlspecialchars($_POST['student_first_name']),
            htmlspecialchars($_POST['student_last_name']),
            htmlspecialchars($_POST['student_gender']),
            $_POST['student_dob'],
            htmlspecialchars($_POST['student_grade']),
            htmlspecialchars($_POST['student_previous_school'] ?? ''),
            htmlspecialchars($_POST['enrollment_type'] ?? 'new'),
            htmlspecialchars($_POST['academic_year']),
            htmlspecialchars($_POST['academic_term'] ?? ''),
            htmlspecialchars($_POST['special_requirements'] ?? ''),
            'pending'
        ]);
        
        $enrollmentId = $db->lastInsertId();
        
        // Handle file uploads if any
        $uploadedDocuments = [];
        if (!empty($_FILES['documents'])) {
            foreach ($_FILES['documents']['name'] as $key => $name) {
                if ($_FILES['documents']['error'][$key] === UPLOAD_ERR_OK) {
                    $tempName = $_FILES['documents']['tmp_name'][$key];
                    $fileSize = $_FILES['documents']['size'][$key];
                    
                    // Validate file type
                    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
                    $fileType = mime_content_type($tempName);
                    
                    if (in_array($fileType, $allowedTypes) && $fileSize <= 5 * 1024 * 1024) { // 5MB max
                        $uploadDir = __DIR__ . '/../uploads/enrollment/' . $schoolId . '/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                        $filePath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($tempName, $filePath)) {
                            $uploadedDocuments[] = [
                                'name' => $name,
                                'path' => $fileName
                            ];
                            
                            // Insert document record
                            $docStmt = $db->prepare("
                                INSERT INTO enrollment_documents 
                                (enrollment_request_id, document_type, document_name, file_path, file_size)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $docStmt->execute([
                                $enrollmentId,
                                'application',
                                $name,
                                $fileName,
                                $fileSize
                            ]);
                        }
                    }
                }
            }
        }
        
        // Update enrollment request with documents info
        if (!empty($uploadedDocuments)) {
            $updateStmt = $db->prepare("
                UPDATE enrollment_requests SET documents_submitted = ? WHERE id = ?
            ");
            $updateStmt->execute([
                json_encode($uploadedDocuments),
                $enrollmentId
            ]);
        }
        
        $db->commit();
        
        // Store success message in session
        $_SESSION['enrollment_success'] = true;
        $_SESSION['request_number'] = $requestNumber;
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Enrollment error: " . $e->getMessage());
        return "An error occurred while submitting your application. Please try again.";
    }
    
    return '';
}

// Function to send confirmation email
function sendEnrollmentConfirmation($data) {
    // In production, implement actual email sending
    // For now, just log the email
    $message = "New enrollment request submitted:\n";
    $message .= "Request Number: " . ($_SESSION['request_number'] ?? 'N/A') . "\n";
    $message .= "Parent: " . $data['parent_first_name'] . " " . $data['parent_last_name'] . "\n";
    $message .= "Email: " . $data['parent_email'] . "\n";
    $message .= "Student: " . $data['student_first_name'] . " " . $data['student_last_name'] . "\n";
    
    error_log("Enrollment email: " . $message);
}

// Check for success message from session
if (isset($_SESSION['enrollment_success']) && $_SESSION['enrollment_success']) {
    $enrollmentSuccess = true;
    $requestNumber = $_SESSION['request_number'] ?? '';
    unset($_SESSION['enrollment_success']);
    unset($_SESSION['request_number']);
}

// Parse JSON fields
$accreditations = !empty($school['accreditations']) ? json_decode($school['accreditations'], true) : [];
$affiliations = !empty($school['affiliations']) ? json_decode($school['affiliations'], true) : [];
$extracurricular = !empty($school['extracurricular_activities']) ? json_decode($school['extracurricular_activities'], true) : [];
$sports = !empty($school['sports_facilities']) ? json_decode($school['sports_facilities'], true) : [];

// Calculate rating stats
$ratingStats = [
    '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0,
    'total' => 0, 'average' => $school['avg_rating'] ?? 0
];

foreach ($reviews as $review) {
    $rating = round($review['rating']);
    if (isset($ratingStats[$rating])) {
        $ratingStats[$rating]++;
        $ratingStats['total']++;
    }
}

// Get school initials for logo fallback
function getSchoolInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return substr($initials, 0, 2); // Max 2 letters
}

$schoolInitials = getSchoolInitials($school['name']);
$hasLogo = !empty($school['logo_path']) && file_exists(__DIR__ . '/../' . $school['logo_path']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo htmlspecialchars($school['city']); ?>, <?php echo htmlspecialchars($school['state']); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(substr($school['description'] ?? 'Learn more about our school', 0, 160)); ?>">
    
    <!-- Custom fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        /* Custom palette */
        :root {
            --bg-soft: #f3f6f0;
            --primary-deep: #13452f;
            --primary-light: #2d6a4f;
            --dark-charcoal: #22281f;
            --accent-warm: #c79b5e;
        }

        body {
            background-color: var(--bg-soft);
            color: var(--dark-charcoal);
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, .font-mono-head {
            font-family: 'Space Mono', monospace;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        /* Custom utilities */
        .bg-primary-deep { background-color: var(--primary-deep); }
        .text-primary-deep { color: var(--primary-deep); }
        .border-primary-deep { border-color: var(--primary-deep); }
        .bg-primary-light { background-color: var(--primary-light); }
        .text-primary-light { color: var(--primary-light); }
        .bg-dark-charcoal { background-color: var(--dark-charcoal); }
        .text-dark-charcoal { color: var(--dark-charcoal); }
        .bg-soft-bg { background-color: var(--bg-soft); }
        .accent-gold { color: var(--accent-warm); }
        .bg-accent-gold { background-color: var(--accent-warm); }

        /* Navigation */
        .school-nav {
            background-color: #ffffff;
            border-bottom: 1px solid rgba(19, 69, 47, 0.1);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
        }

        /* Hero Section */
        .school-hero {
            position: relative;
            min-height: 70vh;
            background: linear-gradient(135deg, var(--primary-deep) 0%, var(--primary-light) 100%);
            overflow: hidden;
        }
        
        .school-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 60" width="60" height="60"><path d="M30 5 L55 20 L55 40 L30 55 L5 40 L5 20 Z" fill="%23ffffff" opacity="0.03"/></svg>');
            background-size: 40px;
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        /* School logo placeholder */
        .logo-placeholder {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-deep), var(--primary-light));
            color: white;
            font-size: 2rem;
            font-weight: 700;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Tab Navigation */
        .tab-container {
            border-bottom: 2px solid rgba(19, 69, 47, 0.1);
            background: white;
        }
        
        .tab-button {
            padding: 1rem 1.5rem;
            font-weight: 600;
            color: #64748b;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            position: relative;
        }
        
        .tab-button:hover {
            color: var(--primary-deep);
        }
        
        .tab-button.active {
            color: var(--primary-deep);
            border-bottom-color: var(--primary-deep);
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-deep);
        }

        /* Cards */
        .info-card {
            background: white;
            border-radius: 24px;
            border: 1px solid rgba(19, 69, 47, 0.1);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 30px -10px rgba(19, 69, 47, 0.1);
            border-color: var(--primary-deep);
        }

        /* Facility Item */
        .facility-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .facility-item:hover {
            background: white;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.05);
        }

        /* Review Card */
        .review-card {
            background: white;
            border-radius: 20px;
            border: 1px solid rgba(19, 69, 47, 0.1);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .review-card:hover {
            box-shadow: 0 12px 24px -8px rgba(19, 69, 47, 0.1);
        }

        /* Rating Stars */
        .rating-stars i {
            color: var(--accent-warm);
            margin-right: 2px;
        }

        .rating-stars i.empty {
            color: #d4d9d0;
        }

        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--accent-warm);
            border-radius: 4px;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-open {
            background: rgba(19, 69, 47, 0.1);
            color: var(--primary-deep);
        }

        .status-closed {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .status-waiting {
            background: rgba(199, 155, 94, 0.1);
            color: #b45309;
        }

        /* Form Elements */
        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-deep);
            box-shadow: 0 0 0 3px rgba(19, 69, 47, 0.1);
        }

        .form-input.error {
            border-color: #dc2626;
        }

        /* Gallery */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .gallery-item {
            position: relative;
            height: 250px;
            border-radius: 20px;
            overflow: hidden;
            cursor: pointer;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .gallery-item:hover img {
            transform: scale(1.05);
        }

        .gallery-item-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            padding: 1.5rem 1rem 1rem;
            color: white;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .gallery-item:hover .gallery-item-overlay {
            opacity: 1;
        }

        /* Back to Top */
        .back-to-top {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--primary-deep);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(19, 69, 47, 0.3);
            z-index: 1000;
        }

        .back-to-top:hover {
            transform: translateY(-5px);
            background: var(--primary-light);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #e2e8f0;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-deep);
            border-radius: 10px;
        }

        /* Enrollment Steps */
        .enrollment-step {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .enrollment-step.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
        }

        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin: 0 auto 0.5rem;
            transition: all 0.3s ease;
        }

        .step-item.active .step-number {
            background: var(--primary-deep);
            color: white;
        }

        .step-item.completed .step-number {
            background: var(--accent-warm);
            color: white;
        }

        .step-title {
            font-size: 0.875rem;
            color: #64748b;
        }

        .step-item.active .step-title {
            color: var(--primary-deep);
            font-weight: 600;
        }

        .step-line {
            position: absolute;
            top: 20px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #e2e8f0;
            z-index: -1;
        }

        .step-item:last-child .step-line {
            display: none;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .school-hero {
                min-height: 60vh;
            }
            
            .logo-placeholder {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="school-nav sticky top-0 z-50 py-4 px-4 md:px-6">
        <div class="container max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4">
                <?php if ($hasLogo): ?>
                    <img src="<?php echo htmlspecialchars($school['logo_path']); ?>" 
                         alt="<?php echo htmlspecialchars($school['name']); ?>" 
                         class="h-12 w-auto rounded-lg shadow-sm">
                <?php else: ?>
                    <div class="logo-placeholder">
                        <?php echo $schoolInitials; ?>
                    </div>
                <?php endif; ?>
                <div>
                    <h1 class="font-mono-head text-xl text-dark-charcoal hidden md:block">
                        <?php echo htmlspecialchars($school['name']); ?>
                    </h1>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <?php if ($enrollmentStatus === 'open'): ?>
                <a href="#enrollment" 
                   class="bg-primary-deep text-white px-5 py-2.5 rounded-full text-sm font-bold hover:bg-primary-light transition shadow-md whitespace-nowrap">
                    Apply Now
                </a>
                <?php endif; ?>
                <a href="../schools.php" 
                   class="text-dark-charcoal/70 hover:text-primary-deep transition px-3 py-2 text-sm font-medium">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Schools
                </a>
            </div>
        </div>
    </nav>

    <!-- Success Modal -->
    <?php if ($enrollmentSuccess): ?>
    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-8 text-center shadow-2xl" data-aos="zoom-in">
            <div class="w-20 h-20 bg-primary-deep/10 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-check text-3xl text-primary-deep"></i>
            </div>
            <h2 class="font-mono-head text-2xl text-dark-charcoal mb-3">Application Submitted!</h2>
            <p class="text-dark-charcoal/70 mb-6">
                Thank you for applying to <?php echo htmlspecialchars($school['name']); ?>. 
                Your application has been received and is under review.
            </p>
            <?php if (!empty($requestNumber)): ?>
            <div class="bg-soft-bg p-4 rounded-xl mb-6">
                <p class="text-sm text-dark-charcoal/60">Your Application Number:</p>
                <p class="text-lg font-mono-head text-primary-deep"><?php echo $requestNumber; ?></p>
                <p class="text-xs text-dark-charcoal/50 mt-2">Please save this number for future reference</p>
            </div>
            <?php endif; ?>
            <p class="text-sm text-dark-charcoal/60 mb-6">
                The school administration will contact you within 3-5 business days.
            </p>
            <button onclick="closeSuccessModal()" 
                    class="w-full bg-primary-deep text-white py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                Continue Browsing
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="school-hero">
        <div class="container max-w-7xl mx-auto px-4 h-full flex items-center">
            <div class="hero-content text-white py-16 md:py-24">
                <div class="max-w-3xl">
                    <?php if ($enrollmentStatus === 'open'): ?>
                    <span class="status-badge status-open bg-white/20 text-white mb-6">
                        <i class="fas fa-circle text-xs"></i>
                        Admissions Open for <?php echo date('Y'); ?>
                    </span>
                    <?php elseif ($enrollmentStatus === 'waiting_list'): ?>
                    <span class="status-badge status-waiting bg-white/20 text-white mb-6">
                        <i class="fas fa-clock text-xs"></i>
                        Waiting List Only
                    </span>
                    <?php else: ?>
                    <span class="status-badge status-closed bg-white/20 text-white mb-6">
                        <i class="fas fa-lock text-xs"></i>
                        Admissions Closed
                    </span>
                    <?php endif; ?>
                    
                    <h1 class="text-5xl md:text-7xl font-mono-head mb-6">
                        <?php echo htmlspecialchars($school['name']); ?>
                    </h1>
                    
                    <p class="text-xl md:text-2xl text-white/80 mb-8 max-w-2xl">
                        <?php echo htmlspecialchars($school['mission_statement'] ?? 'Nurturing minds, building futures since ' . ($school['establishment_year'] ?? date('Y'))); ?>
                    </p>
                    
                    <div class="flex flex-wrap gap-6 text-white/90">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-map-pin text-accent-gold"></i>
                            <span><?php echo htmlspecialchars($school['city'] . ', ' . $school['state']); ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-graduation-cap text-accent-gold"></i>
                            <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $school['school_type'] ?? 'Secondary'))); ?> School</span>
                        </div>
                        <?php if ($school['establishment_year']): ?>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-calendar-alt text-accent-gold"></i>
                            <span>Est. <?php echo $school['establishment_year']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Quick Stats Bar -->
    <div class="bg-white border-b border-primary-deep/10">
        <div class="container max-w-7xl mx-auto px-4">
            <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-primary-deep/10">
                <div class="py-6 px-4 text-center">
                    <div class="text-3xl font-mono-head text-primary-deep mb-1">
                        <?php echo $school['average_class_size'] ?? '25'; ?>
                    </div>
                    <div class="text-sm text-dark-charcoal/60">Average Class Size</div>
                </div>
                <div class="py-6 px-4 text-center">
                    <div class="text-3xl font-mono-head text-primary-deep mb-1">
                        <?php echo $school['teacher_student_ratio'] ?? '1:20'; ?>
                    </div>
                    <div class="text-sm text-dark-charcoal/60">Teacher-Student Ratio</div>
                </div>
                <div class="py-6 px-4 text-center">
                    <div class="text-3xl font-mono-head text-primary-deep mb-1">
                        <?php echo count($facilities); ?>
                    </div>
                    <div class="text-sm text-dark-charcoal/60">Facilities</div>
                </div>
                <div class="py-6 px-4 text-center">
                    <div class="flex items-center justify-center gap-2 mb-1">
                        <span class="text-3xl font-mono-head text-primary-deep">
                            <?php echo number_format($ratingStats['average'], 1); ?>
                        </span>
                        <span class="rating-stars text-sm">
                            <?php 
                            $fullStars = floor($ratingStats['average']);
                            $hasHalfStar = ($ratingStats['average'] - $fullStars) >= 0.5;
                            for ($i = 1; $i <= 5; $i++): 
                            ?>
                            <?php if ($i <= $fullStars): ?>
                            <i class="fas fa-star"></i>
                            <?php elseif ($i === $fullStars + 1 && $hasHalfStar): ?>
                            <i class="fas fa-star-half-alt"></i>
                            <?php else: ?>
                            <i class="far fa-star"></i>
                            <?php endif; ?>
                            <?php endfor; ?>
                        </span>
                    </div>
                    <div class="text-sm text-dark-charcoal/60"><?php echo $ratingStats['total']; ?> Reviews</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-container sticky top-[73px] z-40 bg-white">
        <div class="container max-w-7xl mx-auto px-4">
            <nav class="flex overflow-x-auto">
                <button onclick="showTab('overview')" class="tab-button active" id="tab-overview">
                    <i class="fas fa-info-circle mr-2"></i>Overview
                </button>
                <button onclick="showTab('facilities')" class="tab-button" id="tab-facilities">
                    <i class="fas fa-building mr-2"></i>Facilities
                </button>
                <button onclick="showTab('gallery')" class="tab-button" id="tab-gallery">
                    <i class="fas fa-images mr-2"></i>Gallery
                </button>
                <button onclick="showTab('reviews')" class="tab-button" id="tab-reviews">
                    <i class="fas fa-star mr-2"></i>Reviews
                </button>
                <button onclick="showTab('contact')" class="tab-button" id="tab-contact">
                    <i class="fas fa-phone mr-2"></i>Contact
                </button>
                <button onclick="showTab('enrollment')" class="tab-button" id="tab-enrollment">
                    <i class="fas fa-file-alt mr-2"></i>Admission
                </button>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container max-w-7xl mx-auto px-4 py-12">
        <!-- Overview Tab -->
        <div id="overview-tab" class="tab-content active">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- About Section -->
                    <div class="info-card p-8">
                        <h2 class="font-mono-head text-2xl text-dark-charcoal mb-6">About Our School</h2>
                        <div class="prose max-w-none">
                            <p class="text-dark-charcoal/80 leading-relaxed mb-6 text-lg">
                                <?php echo nl2br(htmlspecialchars($school['description'] ?? 'No description available.')); ?>
                            </p>
                            
                            <?php if (!empty($school['vision_statement'])): ?>
                            <div class="bg-primary-deep/5 p-6 rounded-xl mb-6">
                                <h3 class="font-mono-head text-primary-deep mb-3 flex items-center">
                                    <i class="fas fa-eye mr-2"></i>Our Vision
                                </h3>
                                <p class="text-dark-charcoal/80">
                                    <?php echo nl2br(htmlspecialchars($school['vision_statement'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($school['principal_message'])): ?>
                            <div class="border-l-4 border-accent-gold pl-6 my-8">
                                <?php if (!empty($school['principal_name'])): ?>
                                <h3 class="font-mono-head text-dark-charcoal mb-2">
                                    <?php echo htmlspecialchars($school['principal_name']); ?>
                                </h3>
                                <p class="text-sm text-dark-charcoal/60 mb-4">Principal</p>
                                <?php endif; ?>
                                <p class="text-dark-charcoal/80 italic leading-relaxed">
                                    "<?php echo nl2br(htmlspecialchars($school['principal_message'])); ?>"
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Extracurricular Activities -->
                    <?php if (!empty($extracurricular)): ?>
                    <div class="info-card p-8">
                        <h2 class="font-mono-head text-2xl text-dark-charcoal mb-6">Extracurricular Activities</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($extracurricular as $activity): ?>
                            <div class="facility-item">
                                <i class="fas fa-star text-accent-gold mr-3"></i>
                                <span class="text-dark-charcoal/80"><?php echo htmlspecialchars($activity); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Sports Facilities -->
                    <?php if (!empty($sports)): ?>
                    <div class="info-card p-8">
                        <h2 class="font-mono-head text-2xl text-dark-charcoal mb-6">Sports & Recreation</h2>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <?php foreach ($sports as $sport): ?>
                            <div class="facility-item flex-col text-center">
                                <i class="fas fa-running text-2xl text-accent-gold mb-2"></i>
                                <span class="text-dark-charcoal/80 text-sm"><?php echo htmlspecialchars($sport); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Quick Facts -->
                    <div class="info-card p-6">
                        <h3 class="font-mono-head text-lg text-dark-charcoal mb-4">Quick Facts</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between py-2 border-b border-primary-deep/10">
                                <span class="text-dark-charcoal/60">School Type</span>
                                <span class="font-semibold text-dark-charcoal">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $school['school_type'] ?? 'Secondary'))); ?>
                                </span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-primary-deep/10">
                                <span class="text-dark-charcoal/60">Curriculum</span>
                                <span class="font-semibold text-dark-charcoal">
                                    <?php echo htmlspecialchars($school['curriculum'] ?? 'Nigerian'); ?>
                                </span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-primary-deep/10">
                                <span class="text-dark-charcoal/60">Boarding</span>
                                <span class="font-semibold <?php echo $school['boarding_available'] ? 'text-primary-deep' : 'text-rose-600'; ?>">
                                    <?php echo $school['boarding_available'] ? 'Available' : 'Not Available'; ?>
                                </span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-primary-deep/10">
                                <span class="text-dark-charcoal/60">Transportation</span>
                                <span class="font-semibold <?php echo $school['transportation_available'] ? 'text-primary-deep' : 'text-rose-600'; ?>">
                                    <?php echo $school['transportation_available'] ? 'Available' : 'Not Available'; ?>
                                </span>
                            </div>
                            <div class="flex justify-between py-2">
                                <span class="text-dark-charcoal/60">School Hours</span>
                                <span class="font-semibold text-dark-charcoal">
                                    <?php echo htmlspecialchars($school['school_hours'] ?? '8:00 AM - 3:00 PM'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Accreditation & Affiliation -->
                    <?php if (!empty($accreditations) || !empty($affiliations)): ?>
                    <div class="info-card p-6">
                        <h3 class="font-mono-head text-lg text-dark-charcoal mb-4">Accreditations</h3>
                        <div class="space-y-3">
                            <?php foreach ($accreditations as $accreditation): ?>
                            <div class="flex items-center p-3 bg-primary-deep/5 rounded-lg">
                                <i class="fas fa-award text-accent-gold mr-3"></i>
                                <span class="text-sm text-dark-charcoal/80"><?php echo htmlspecialchars($accreditation); ?></span>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($affiliations as $affiliation): ?>
                            <div class="flex items-center p-3 bg-primary-deep/5 rounded-lg">
                                <i class="fas fa-handshake text-accent-gold mr-3"></i>
                                <span class="text-sm text-dark-charcoal/80"><?php echo htmlspecialchars($affiliation); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Fee Information -->
                    <?php if (($school['fee_range_from'] ?? 0) > 0): ?>
                    <div class="info-card p-6">
                        <h3 class="font-mono-head text-lg text-dark-charcoal mb-4">Annual Fees</h3>
                        <div class="text-center p-6 bg-primary-deep/5 rounded-xl">
                            <div class="text-3xl font-mono-head text-primary-deep mb-1">
                                ₦<?php echo number_format($school['fee_range_from']); ?> - ₦<?php echo number_format($school['fee_range_to']); ?>
                            </div>
                            <p class="text-sm text-dark-charcoal/60">Per academic year</p>
                        </div>
                        <p class="text-xs text-dark-charcoal/50 text-center mt-4">
                            <i class="fas fa-info-circle mr-1"></i>
                            Fees may vary by grade level
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Facilities Tab -->
        <div id="facilities-tab" class="tab-content hidden">
            <div class="info-card p-8">
                <h2 class="font-mono-head text-2xl text-dark-charcoal mb-8">School Facilities</h2>
                
                <?php if (!empty($facilities)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($facilities as $facility): ?>
                    <div class="facility-item">
                        <div class="w-12 h-12 bg-primary-deep/10 rounded-xl flex items-center justify-center mr-4">
                            <?php if ($facility['icon']): ?>
                            <i class="<?php echo htmlspecialchars($facility['icon']); ?> text-xl text-primary-deep"></i>
                            <?php else: ?>
                            <i class="fas fa-building text-xl text-primary-deep"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 class="font-semibold text-dark-charcoal mb-1"><?php echo htmlspecialchars($facility['name']); ?></h3>
                            <?php if (!empty($facility['description'])): ?>
                            <p class="text-sm text-dark-charcoal/60"><?php echo htmlspecialchars($facility['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-building text-4xl text-dark-charcoal/20 mb-4"></i>
                    <p class="text-dark-charcoal/60">No facilities information available.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Gallery Tab -->
        <div id="gallery-tab" class="tab-content hidden">
            <div class="info-card p-8">
                <h2 class="font-mono-head text-2xl text-dark-charcoal mb-8">School Gallery</h2>
                
                <?php if (!empty($gallery)): ?>
                <div class="gallery-grid">
                    <?php foreach ($gallery as $index => $image): ?>
                    <div class="gallery-item" onclick="openImageModal(<?php echo $index; ?>)">
                        <img src="<?php echo htmlspecialchars($image['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($image['caption'] ?? 'School Image'); ?>">
                        <?php if (!empty($image['caption'])): ?>
                        <div class="gallery-item-overlay">
                            <p class="text-sm"><?php echo htmlspecialchars($image['caption']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-images text-4xl text-dark-charcoal/20 mb-4"></i>
                    <p class="text-dark-charcoal/60">No gallery images available.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reviews Tab -->
        <div id="reviews-tab" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Rating Summary -->
                <div class="lg:col-span-1">
                    <div class="info-card p-6 sticky top-[150px]">
                        <div class="text-center mb-6">
                            <div class="text-5xl font-mono-head text-dark-charcoal mb-2">
                                <?php echo number_format($ratingStats['average'], 1); ?>
                            </div>
                            <div class="rating-stars text-2xl mb-2">
                                <?php 
                                $fullStars = floor($ratingStats['average']);
                                $hasHalfStar = ($ratingStats['average'] - $fullStars) >= 0.5;
                                for ($i = 1; $i <= 5; $i++): 
                                ?>
                                <?php if ($i <= $fullStars): ?>
                                <i class="fas fa-star"></i>
                                <?php elseif ($i === $fullStars + 1 && $hasHalfStar): ?>
                                <i class="fas fa-star-half-alt"></i>
                                <?php else: ?>
                                <i class="far fa-star"></i>
                                <?php endif; ?>
                                <?php endfor; ?>
                            </div>
                            <p class="text-dark-charcoal/60">Based on <?php echo $ratingStats['total']; ?> reviews</p>
                        </div>
                        
                        <div class="space-y-3">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                            <div class="flex items-center">
                                <span class="text-sm text-dark-charcoal/60 w-8"><?php echo $i; ?>★</span>
                                <div class="flex-1 mx-3">
                                    <div class="progress-bar">
                                        <div class="progress-fill" 
                                             style="width: <?php echo $ratingStats['total'] > 0 ? ($ratingStats[$i] / $ratingStats['total'] * 100) : 0; ?>%"></div>
                                    </div>
                                </div>
                                <span class="text-sm text-dark-charcoal/60 w-10 text-right">
                                    <?php echo $ratingStats[$i]; ?>
                                </span>
                            </div>
                            <?php endfor; ?>
                        </div>
                        
                        <button onclick="openReviewModal()" 
                                class="w-full mt-6 bg-primary-deep text-white py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                            <i class="fas fa-pen mr-2"></i>Write a Review
                        </button>
                    </div>
                </div>
                
                <!-- Reviews List -->
                <div class="lg:col-span-2">
                    <div class="info-card p-8">
                        <h2 class="font-mono-head text-2xl text-dark-charcoal mb-6">Parent Reviews</h2>
                        
                        <?php if (!empty($reviews)): ?>
                        <div class="space-y-6">
                            <?php foreach ($reviews as $review): ?>
                            <div class="review-card">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 bg-primary-deep/10 rounded-full flex items-center justify-center text-primary-deep font-bold text-lg">
                                            <?php echo strtoupper(substr($review['parent_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-dark-charcoal"><?php echo htmlspecialchars($review['parent_name']); ?></h4>
                                            <?php if ($review['student_name']): ?>
                                            <p class="text-sm text-dark-charcoal/60">Parent of <?php echo htmlspecialchars($review['student_name']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="rating-stars mb-1">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?php echo $i > $review['rating'] ? ' empty' : ''; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <p class="text-xs text-dark-charcoal/50">
                                            <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <?php if ($review['title']): ?>
                                <h5 class="font-semibold text-dark-charcoal mb-3"><?php echo htmlspecialchars($review['title']); ?></h5>
                                <?php endif; ?>
                                
                                <p class="text-dark-charcoal/70 mb-4 leading-relaxed">
                                    <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                </p>
                                
                                <?php if ($review['pros'] || $review['cons']): ?>
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <?php if ($review['pros']): ?>
                                    <div class="bg-primary-deep/5 p-4 rounded-lg">
                                        <h6 class="font-semibold text-primary-deep mb-2 flex items-center">
                                            <i class="fas fa-thumbs-up mr-2"></i>Pros
                                        </h6>
                                        <p class="text-sm text-dark-charcoal/70"><?php echo htmlspecialchars($review['pros']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($review['cons']): ?>
                                    <div class="bg-rose-50 p-4 rounded-lg">
                                        <h6 class="font-semibold text-rose-800 mb-2 flex items-center">
                                            <i class="fas fa-thumbs-down mr-2"></i>Cons
                                        </h6>
                                        <p class="text-sm text-rose-700"><?php echo htmlspecialchars($review['cons']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="flex justify-between items-center pt-4 border-t border-primary-deep/10">
                                    <?php if ($review['is_verified']): ?>
                                    <span class="text-sm text-primary-deep font-medium flex items-center">
                                        <i class="fas fa-check-circle mr-1"></i> Verified Parent
                                    </span>
                                    <?php endif; ?>
                                    <button onclick="markHelpful(<?php echo $review['id']; ?>)" 
                                            class="text-sm text-dark-charcoal/50 hover:text-primary-deep transition flex items-center">
                                        <i class="far fa-thumbs-up mr-1"></i>
                                        Helpful (<?php echo $review['helpful_count']; ?>)
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-star text-4xl text-dark-charcoal/20 mb-4"></i>
                            <h3 class="text-xl font-mono-head text-dark-charcoal mb-2">No Reviews Yet</h3>
                            <p class="text-dark-charcoal/60 mb-6">Be the first to review this school!</p>
                            <button onclick="openReviewModal()" 
                                    class="bg-primary-deep text-white px-6 py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                                Write First Review
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Tab -->
        <div id="contact-tab" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Contact Information -->
                <div class="info-card p-8">
                    <h2 class="font-mono-head text-2xl text-dark-charcoal mb-8">Contact Information</h2>
                    
                    <div class="space-y-6">
                        <?php if (!empty($contacts)): ?>
                        <?php foreach ($contacts as $contact): ?>
                        <div class="flex items-start p-4 bg-soft-bg rounded-xl">
                            <div class="w-12 h-12 bg-primary-deep/10 rounded-xl flex items-center justify-center mr-4">
                                <?php switch($contact['type']): case 'phone': ?>
                                    <i class="fas fa-phone text-primary-deep"></i>
                                    <?php break; case 'email': ?>
                                    <i class="fas fa-envelope text-primary-deep"></i>
                                    <?php break; case 'address': ?>
                                    <i class="fas fa-map-marker-alt text-primary-deep"></i>
                                    <?php break; case 'website': ?>
                                    <i class="fas fa-globe text-primary-deep"></i>
                                    <?php break; default: ?>
                                    <i class="fas fa-link text-primary-deep"></i>
                                <?php endswitch; ?>
                            </div>
                            <div class="flex-1">
                                <?php if ($contact['label']): ?>
                                <h4 class="font-semibold text-dark-charcoal"><?php echo htmlspecialchars($contact['label']); ?></h4>
                                <?php endif; ?>
                                <p class="text-dark-charcoal/70 mt-1">
                                    <?php if ($contact['type'] === 'email'): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($contact['value']); ?>" 
                                       class="text-primary-deep hover:underline">
                                        <?php echo htmlspecialchars($contact['value']); ?>
                                    </a>
                                    <?php elseif ($contact['type'] === 'phone'): ?>
                                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['value'])); ?>" 
                                       class="text-primary-deep hover:underline">
                                        <?php echo htmlspecialchars($contact['value']); ?>
                                    </a>
                                    <?php elseif ($contact['type'] === 'website'): ?>
                                    <a href="<?php echo htmlspecialchars($contact['value']); ?>" 
                                       target="_blank" 
                                       class="text-primary-deep hover:underline">
                                        <?php echo htmlspecialchars($contact['value']); ?>
                                    </a>
                                    <?php else: ?>
                                    <?php echo htmlspecialchars($contact['value']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($contact['is_primary']): ?>
                            <span class="bg-primary-deep/10 text-primary-deep text-xs px-2 py-1 rounded-full font-medium">
                                Primary
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-address-book text-4xl text-dark-charcoal/20 mb-4"></i>
                            <p class="text-dark-charcoal/60">No contact information available.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Contact Form -->
                <div class="info-card p-8">
                    <h2 class="font-mono-head text-2xl text-dark-charcoal mb-8">Send a Message</h2>
                    
                    <form id="contactForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">First Name *</label>
                                <input type="text" name="first_name" required 
                                       class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Last Name *</label>
                                <input type="text" name="last_name" required 
                                       class="form-input">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Email Address *</label>
                                <input type="email" name="email" required 
                                       class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Phone Number</label>
                                <input type="tel" name="phone" 
                                       class="form-input">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Subject *</label>
                            <input type="text" name="subject" required 
                                   class="form-input">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Message *</label>
                            <textarea name="message" rows="5" required 
                                      class="form-input resize-none"></textarea>
                        </div>
                        
                        <div>
                            <button type="submit" 
                                    class="w-full bg-primary-deep text-white py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                                Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Enrollment Tab -->
        <div id="enrollment-tab" class="tab-content hidden">
            <?php if ($enrollmentStatus !== 'open'): ?>
            <div class="info-card p-12 text-center">
                <div class="w-20 h-20 bg-dark-charcoal/5 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-lock text-3xl text-dark-charcoal/30"></i>
                </div>
                <h3 class="font-mono-head text-2xl text-dark-charcoal mb-4">Admissions Currently Closed</h3>
                <p class="text-dark-charcoal/60 mb-6 max-w-md mx-auto">
                    <?php if ($enrollmentStatus === 'closed'): ?>
                    The admission period has ended. Please check back later.
                    <?php elseif ($enrollmentStatus === 'waiting_list'): ?>
                    Admissions are on waiting list only. Please contact us for more information.
                    <?php else: ?>
                    Admissions are not currently open.
                    <?php endif; ?>
                </p>
                <?php if ($school['admission_deadline'] && strtotime($school['admission_deadline']) > time()): ?>
                <p class="text-sm text-dark-charcoal/50">
                    Next admission deadline: <?php echo date('F j, Y', strtotime($school['admission_deadline'])); ?>
                </p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="info-card p-8">
                <!-- Enrollment Progress -->
                <div class="step-indicator">
                    <?php 
                    $steps = ['Parent Info', 'Student Info', 'Documents', 'Review'];
                    foreach ($steps as $index => $step):
                    ?>
                    <div class="step-item <?php echo $index === 0 ? 'active' : ''; ?>" id="step-item-<?php echo $index + 1; ?>">
                        <div class="step-number" id="step-number-<?php echo $index + 1; ?>">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="step-title"><?php echo $step; ?></div>
                        <?php if ($index < count($steps) - 1): ?>
                        <div class="step-line"></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Enrollment Form -->
                <form method="POST" action="" enctype="multipart/form-data" id="enrollmentForm">
                    <!-- Step 1: Parent Information -->
                    <div id="step-1" class="enrollment-step active">
                        <h3 class="font-mono-head text-xl text-dark-charcoal mb-6">Parent/Guardian Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">First Name *</label>
                                <input type="text" name="parent_first_name" required class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Last Name *</label>
                                <input type="text" name="parent_last_name" required class="form-input">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Email Address *</label>
                                <input type="email" name="parent_email" required class="form-input">
                                <p class="text-xs text-dark-charcoal/50 mt-2">Admission updates will be sent here</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Phone Number *</label>
                                <input type="tel" name="parent_phone" required class="form-input">
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Residential Address</label>
                            <textarea name="parent_address" rows="3" class="form-input resize-none"></textarea>
                        </div>
                        
                        <div class="flex justify-end mt-8">
                            <button type="button" onclick="nextStep(2)" 
                                    class="bg-primary-deep text-white px-6 py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                                Continue to Student Information
                                <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Student Information -->
                    <div id="step-2" class="enrollment-step">
                        <h3 class="font-mono-head text-xl text-dark-charcoal mb-6">Student Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Student's First Name *</label>
                                <input type="text" name="student_first_name" required class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Student's Last Name *</label>
                                <input type="text" name="student_last_name" required class="form-input">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Gender *</label>
                                <select name="student_gender" required class="form-input">
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Date of Birth *</label>
                                <input type="date" name="student_dob" required class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Grade Level *</label>
                                <select name="student_grade" required class="form-input">
                                    <option value="">Select Grade</option>
                                    <option value="nursery">Nursery</option>
                                    <option value="kg">Kindergarten</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="grade-<?php echo $i; ?>">Grade <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Previous School (if any)</label>
                                <input type="text" name="student_previous_school" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Enrollment Type</label>
                                <select name="enrollment_type" class="form-input">
                                    <option value="new">New Student</option>
                                    <option value="transfer">Transfer Student</option>
                                    <option value="re_enrollment">Re-enrollment</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Academic Year *</label>
                                <select name="academic_year" required class="form-input">
                                    <option value="">Select Year</option>
                                    <option value="2024-2025">2024-2025</option>
                                    <option value="2025-2026">2025-2026</option>
                                    <option value="2026-2027">2026-2027</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Academic Term</label>
                                <select name="academic_term" class="form-input">
                                    <option value="">Select Term</option>
                                    <option value="first">First Term</option>
                                    <option value="second">Second Term</option>
                                    <option value="third">Third Term</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Special Requirements or Notes</label>
                            <textarea name="special_requirements" rows="3" class="form-input resize-none"></textarea>
                            <p class="text-xs text-dark-charcoal/50 mt-2">
                                Please mention any medical conditions, learning needs, or other requirements
                            </p>
                        </div>
                        
                        <div class="flex justify-between mt-8">
                            <button type="button" onclick="prevStep(1)" 
                                    class="px-6 py-3 border-2 border-dark-charcoal/20 text-dark-charcoal rounded-xl font-semibold hover:bg-dark-charcoal/5 transition">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back
                            </button>
                            <button type="button" onclick="nextStep(3)" 
                                    class="bg-primary-deep text-white px-6 py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                                Continue to Documents
                                <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Documents -->
                    <div id="step-3" class="enrollment-step">
                        <h3 class="font-mono-head text-xl text-dark-charcoal mb-6">Required Documents</h3>
                        
                        <div class="bg-soft-bg rounded-xl p-6 mb-6">
                            <h4 class="font-semibold text-dark-charcoal mb-4">Documents Checklist</h4>
                            <ul class="space-y-3">
                                <li class="flex items-center text-dark-charcoal/70">
                                    <i class="fas fa-check-circle text-primary-deep mr-3"></i>
                                    <span>Student's Birth Certificate</span>
                                </li>
                                <li class="flex items-center text-dark-charcoal/70">
                                    <i class="fas fa-check-circle text-primary-deep mr-3"></i>
                                    <span>Previous School Report Card</span>
                                </li>
                                <li class="flex items-center text-dark-charcoal/70">
                                    <i class="fas fa-check-circle text-primary-deep mr-3"></i>
                                    <span>Immunization Records</span>
                                </li>
                                <li class="flex items-center text-dark-charcoal/70">
                                    <i class="fas fa-check-circle text-primary-deep mr-3"></i>
                                    <span>Passport Photograph (2 copies)</span>
                                </li>
                                <li class="flex items-center text-dark-charcoal/70">
                                    <i class="fas fa-check-circle text-primary-deep mr-3"></i>
                                    <span>Parent/Guardian ID</span>
                                </li>
                            </ul>
                            <p class="text-sm text-dark-charcoal/50 mt-4">
                                <i class="fas fa-info-circle mr-1"></i>
                                You can submit these now or bring them during the interview
                            </p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-dark-charcoal/70 mb-2">Upload Documents (Optional)</label>
                            <div class="border-2 border-dashed border-primary-deep/20 rounded-xl p-8 text-center hover:border-primary-deep transition cursor-pointer"
                                 onclick="document.getElementById('fileUpload').click()">
                                <i class="fas fa-cloud-upload-alt text-3xl text-dark-charcoal/30 mb-4"></i>
                                <p class="text-dark-charcoal/60 mb-2">Drag & drop files here or click to browse</p>
                                <p class="text-xs text-dark-charcoal/50 mb-4">PDF, JPG, PNG up to 5MB each</p>
                                <button type="button" class="bg-primary-deep/10 text-primary-deep px-4 py-2 rounded-lg font-semibold hover:bg-primary-deep/20 transition">
                                    Browse Files
                                </button>
                                <input type="file" name="documents[]" multiple 
                                       accept=".pdf,.jpg,.jpeg,.png"
                                       class="hidden" id="fileUpload">
                            </div>
                            <div id="fileList" class="mt-4 space-y-2"></div>
                        </div>
                        
                        <div class="flex justify-between mt-8">
                            <button type="button" onclick="prevStep(2)" 
                                    class="px-6 py-3 border-2 border-dark-charcoal/20 text-dark-charcoal rounded-xl font-semibold hover:bg-dark-charcoal/5 transition">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back
                            </button>
                            <button type="button" onclick="nextStep(4)" 
                                    class="bg-primary-deep text-white px-6 py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                                Review Application
                                <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 4: Review & Submit -->
                    <div id="step-4" class="enrollment-step">
                        <h3 class="font-mono-head text-xl text-dark-charcoal mb-6">Review Your Application</h3>
                        
                        <div class="bg-soft-bg rounded-xl p-6 mb-6">
                            <h4 class="font-semibold text-dark-charcoal mb-4">Application Summary</h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h5 class="font-medium text-dark-charcoal/70 mb-2">Parent Information</h5>
                                    <div class="space-y-2 text-sm text-dark-charcoal/80" id="review-parent-name"></div>
                                </div>
                                <div>
                                    <h5 class="font-medium text-dark-charcoal/70 mb-2">Student Information</h5>
                                    <div class="space-y-2 text-sm text-dark-charcoal/80" id="review-student-name"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-4 mb-6">
                            <label class="flex items-center">
                                <input type="checkbox" name="terms" required 
                                       class="w-4 h-4 text-primary-deep rounded focus:ring-primary-deep">
                                <span class="ml-2 text-sm text-dark-charcoal/70">
                                    I confirm that all information provided is accurate
                                </span>
                            </label>
                            
                            <label class="flex items-center">
                                <input type="checkbox" name="privacy" required 
                                       class="w-4 h-4 text-primary-deep rounded focus:ring-primary-deep">
                                <span class="ml-2 text-sm text-dark-charcoal/70">
                                    I agree to the <a href="#" class="text-primary-deep hover:underline">Privacy Policy</a>
                                </span>
                            </label>
                        </div>
                        
                        <?php if (!empty($enrollmentError)): ?>
                        <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 mb-6">
                            <div class="flex items-center text-rose-800">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <span class="font-medium"><?php echo $enrollmentError; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex justify-between">
                            <button type="button" onclick="prevStep(3)" 
                                    class="px-6 py-3 border-2 border-dark-charcoal/20 text-dark-charcoal rounded-xl font-semibold hover:bg-dark-charcoal/5 transition">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back
                            </button>
                            <button type="submit" name="enrollment_submit" 
                                    class="bg-primary-deep text-white px-8 py-3 rounded-xl font-semibold hover:bg-primary-light transition">
                                Submit Application
                                <i class="fas fa-check ml-2"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer - School Specific -->
    <footer class="bg-dark-charcoal text-white/70 mt-16">
        <div class="container max-w-7xl mx-auto px-4 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <!-- School Info -->
                <div class="col-span-1 md:col-span-2">
                    <div class="flex items-center gap-3 mb-4">
                        <?php if ($hasLogo): ?>
                            <img src="<?php echo htmlspecialchars($school['logo_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($school['name']); ?>" 
                                 class="h-10 w-auto">
                        <?php else: ?>
                            <div class="w-10 h-10 bg-primary-deep rounded-lg flex items-center justify-center text-white font-bold">
                                <?php echo $schoolInitials; ?>
                            </div>
                        <?php endif; ?>
                        <span class="font-mono-head text-xl text-white"><?php echo htmlspecialchars($school['name']); ?></span>
                    </div>
                    <p class="text-white/60 text-sm leading-relaxed max-w-md">
                        <?php echo htmlspecialchars(substr($school['description'] ?? '', 0, 200)) . '...'; ?>
                    </p>
                </div>
                
                <!-- Quick Links -->
                <div>
                    <h4 class="font-mono-head text-white text-lg mb-4">Quick Links</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#overview" class="hover:text-accent-gold transition">Overview</a></li>
                        <li><a href="#facilities" class="hover:text-accent-gold transition">Facilities</a></li>
                        <li><a href="#gallery" class="hover:text-accent-gold transition">Gallery</a></li>
                        <li><a href="#reviews" class="hover:text-accent-gold transition">Reviews</a></li>
                        <li><a href="#enrollment" class="hover:text-accent-gold transition">Admission</a></li>
                    </ul>
                </div>
                
                <!-- Contact Info -->
                <div>
                    <h4 class="font-mono-head text-white text-lg mb-4">Contact</h4>
                    <ul class="space-y-2 text-sm">
                        <?php foreach ($contacts as $contact): ?>
                            <?php if ($contact['type'] === 'phone'): ?>
                            <li><i class="fas fa-phone text-accent-gold mr-2"></i> <?php echo htmlspecialchars($contact['value']); ?></li>
                            <?php elseif ($contact['type'] === 'email'): ?>
                            <li><i class="fas fa-envelope text-accent-gold mr-2"></i> <?php echo htmlspecialchars($contact['value']); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <li><i class="fas fa-map-pin text-accent-gold mr-2"></i> <?php echo htmlspecialchars($school['city'] . ', ' . $school['state']); ?></li>
                    </ul>
                </div>
            </div>
            
            <div class="border-t border-white/10 mt-8 pt-8 text-center text-white/50 text-sm">
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school['name']); ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <div class="back-to-top" onclick="scrollToTop()">
        <i class="fas fa-chevron-up"></i>
    </div>

    <!-- Scripts -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true
        });

        // Tab Navigation
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.remove('hidden');
            
            // Activate selected tab button
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Update URL hash
            window.location.hash = tabName;
            
            // Scroll to top of tab content
            document.getElementById(tabName + '-tab').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }

        // Check URL hash on load
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            if (hash && ['overview', 'facilities', 'gallery', 'reviews', 'contact', 'enrollment'].includes(hash)) {
                showTab(hash);
            }
            
            // Set max date for DOB
            const today = new Date();
            const minDate = new Date();
            minDate.setFullYear(today.getFullYear() - 18);
            const maxDate = new Date();
            maxDate.setFullYear(today.getFullYear() - 3);
            
            const dobInput = document.querySelector('input[name="student_dob"]');
            if (dobInput) {
                dobInput.max = maxDate.toISOString().split('T')[0];
                dobInput.min = minDate.toISOString().split('T')[0];
            }
        });

        // Enrollment Form Steps
        let currentStep = 1;
        
        function nextStep(step) {
            if (!validateStep(currentStep)) {
                return;
            }
            
            // Update step indicators
            document.querySelectorAll('.step-item').forEach(item => {
                item.classList.remove('active');
            });
            document.getElementById('step-item-' + step).classList.add('active');
            
            // Update step numbers
            for (let i = 1; i < step; i++) {
                document.getElementById('step-number-' + i).classList.add('completed');
            }
            
            // Hide current step, show next
            document.getElementById('step-' + currentStep).classList.remove('active');
            document.getElementById('step-' + step).classList.add('active');
            
            if (step === 4) {
                updateReviewSummary();
            }
            
            currentStep = step;
            
            // Scroll to top of form
            document.getElementById('enrollment-tab').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }
        
        function prevStep(step) {
            // Hide current step
            document.getElementById('step-' + currentStep).classList.remove('active');
            
            // Show previous step
            document.getElementById('step-' + step).classList.add('active');
            
            // Update step indicator
            document.querySelectorAll('.step-item').forEach(item => {
                item.classList.remove('active');
            });
            document.getElementById('step-item-' + step).classList.add('active');
            
            currentStep = step;
        }
        
        function validateStep(step) {
            let isValid = true;
            const stepElement = document.getElementById('step-' + step);
            
            // Get all required inputs in this step
            const requiredInputs = stepElement.querySelectorAll('[required]');
            
            requiredInputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('error');
                    
                    // Show error message
                    let errorDiv = input.parentNode.querySelector('.error-message');
                    if (!errorDiv) {
                        errorDiv = document.createElement('div');
                        errorDiv.className = 'error-message text-rose-600 text-xs mt-1';
                        input.parentNode.appendChild(errorDiv);
                    }
                    errorDiv.textContent = 'This field is required';
                } else {
                    input.classList.remove('error');
                    const errorDiv = input.parentNode.querySelector('.error-message');
                    if (errorDiv) {
                        errorDiv.remove();
                    }
                }
            });
            
            // Email validation in step 1
            if (step === 1) {
                const emailInput = document.querySelector('input[name="parent_email"]');
                if (emailInput && emailInput.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(emailInput.value)) {
                        isValid = false;
                        emailInput.classList.add('error');
                        
                        let errorDiv = emailInput.parentNode.querySelector('.error-message');
                        if (!errorDiv) {
                            errorDiv = document.createElement('div');
                            errorDiv.className = 'error-message text-rose-600 text-xs mt-1';
                            emailInput.parentNode.appendChild(errorDiv);
                        }
                        errorDiv.textContent = 'Please enter a valid email address';
                    }
                }
            }
            
            return isValid;
        }
        
        function updateReviewSummary() {
            // Parent Information
            const parentFirstName = document.querySelector('input[name="parent_first_name"]').value;
            const parentLastName = document.querySelector('input[name="parent_last_name"]').value;
            const parentEmail = document.querySelector('input[name="parent_email"]').value;
            const parentPhone = document.querySelector('input[name="parent_phone"]').value;
            
            document.getElementById('review-parent-name').innerHTML = `
                <p><strong>Name:</strong> ${parentFirstName} ${parentLastName}</p>
                <p><strong>Email:</strong> ${parentEmail}</p>
                <p><strong>Phone:</strong> ${parentPhone}</p>
            `;
            
            // Student Information
            const studentFirstName = document.querySelector('input[name="student_first_name"]').value;
            const studentLastName = document.querySelector('input[name="student_last_name"]').value;
            const studentDob = document.querySelector('input[name="student_dob"]').value;
            const studentGrade = document.querySelector('select[name="student_grade"] option:checked').text;
            
            document.getElementById('review-student-name').innerHTML = `
                <p><strong>Name:</strong> ${studentFirstName} ${studentLastName}</p>
                <p><strong>DOB:</strong> ${new Date(studentDob).toLocaleDateString()}</p>
                <p><strong>Grade:</strong> ${studentGrade}</p>
            `;
        }

        // File Upload Handling
        document.getElementById('fileUpload').addEventListener('change', function(e) {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            Array.from(e.target.files).forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between bg-soft-bg p-3 rounded-lg';
                
                fileItem.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-file text-primary-deep mr-3"></i>
                        <div>
                            <p class="text-sm text-dark-charcoal/80">${file.name}</p>
                            <p class="text-xs text-dark-charcoal/50">${formatFileSize(file.size)}</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeFile(${index})" class="text-rose-500 hover:text-rose-700">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                fileList.appendChild(fileItem);
            });
        });
        
        function removeFile(index) {
            const dt = new DataTransfer();
            const input = document.getElementById('fileUpload');
            const { files } = input;
            
            for (let i = 0; i < files.length; i++) {
                if (index !== i) {
                    dt.items.add(files[i]);
                }
            }
            
            input.files = dt.files;
            
            // Trigger change event to update display
            const event = new Event('change');
            input.dispatchEvent(event);
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Close Success Modal
        function closeSuccessModal() {
            const modal = document.querySelector('.fixed.inset-0.bg-black');
            if (modal) {
                modal.remove();
            }
        }

        // Scroll to Top
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Show/Hide Back to Top button
        window.addEventListener('scroll', function() {
            const backToTop = document.querySelector('.back-to-top');
            if (window.scrollY > 500) {
                backToTop.style.display = 'flex';
            } else {
                backToTop.style.display = 'none';
            }
        });

        // Share Functions
        function shareOnFacebook() {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent(`Check out ${document.title}`);
            window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}&quote=${text}`, '_blank');
        }
        
        function shareOnTwitter() {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent(`Check out ${document.title}`);
            window.open(`https://twitter.com/intent/tweet?url=${url}&text=${text}`, '_blank');
        }
        
        function shareViaWhatsApp() {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent(`Check out ${document.title}`);
            window.open(`https://wa.me/?text=${text}%20${url}`, '_blank');
        }

        // Contact Form Submission
        document.getElementById('contactForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Simple validation
            const formData = new FormData(this);
            let isValid = true;
            
            for (let [key, value] of formData) {
                if (key !== 'phone' && !value.trim()) {
                    isValid = false;
                    alert('Please fill in all required fields');
                    break;
                }
            }
            
            if (isValid) {
                alert('Thank you for your message! We will contact you soon.');
                this.reset();
            }
        });

        // Review Modal (simplified)
        function openReviewModal() {
            alert('Review functionality coming soon. Please contact the school directly to leave a review.');
        }
        
        function markHelpful(reviewId) {
            alert('Thank you for your feedback!');
        }
        
        function openImageModal(index) {
            // In production, implement a lightbox
            const images = document.querySelectorAll('.gallery-item img');
            if (images[index]) {
                window.open(images[index].src, '_blank');
            }
        }

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    </script>
</body>
</html>
