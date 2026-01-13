<?php
/**
 * School Profile & Enrollment Page
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/school_profile.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => false,
    ]);
}

// Load configuration
$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

// Get school slug from URL
$schoolSlug = $_GET['slug'] ?? '';
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
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | AcademixSuite</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;400;600;800&family=Space+Grotesk:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css" />
    
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --accent: #f43f5e;
        }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc;
            color: #0f172a;
        }

        h1, h2, h3, .font-heading { font-family: 'Space Grotesk', sans-serif; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

        /* Hero Section */
        .school-hero {
            position: relative;
            height: 70vh;
            min-height: 500px;
            overflow: hidden;
        }
        .school-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.7));
            z-index: 1;
        }
        .school-hero-content {
            position: relative;
            z-index: 2;
        }

        /* Tab Navigation */
        .tab-button {
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        .tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
        }

        /* Gallery Swiper */
        .swiper-slide {
            height: 300px;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .swiper-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Facility Card */
        .facility-card {
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }
        .facility-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }

        /* Review Card */
        .review-card {
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        .review-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        }

        /* Rating Stars */
        .rating-stars {
            display: inline-flex;
            align-items: center;
        }
        .rating-stars i {
            color: #fbbf24;
        }
        .rating-stars i.empty {
            color: #e5e7eb;
        }

        /* Progress Bar */
        .rating-progress {
            height: 8px;
            background-color: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        .rating-progress-fill {
            height: 100%;
            background-color: #fbbf24;
            border-radius: 4px;
        }

        /* Enrollment Form */
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

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .status-open {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-closed {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .status-waiting {
            background-color: #fef3c7;
            color: #92400e;
        }

        /* Back to Top */
        .back-to-top {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--primary);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
            z-index: 1000;
        }
        .back-to-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm sticky top-0 z-50">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <a href="./index.php" class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center text-white">
                        <i class="fas fa-school text-sm"></i>
                    </div>
                    <span class="text-lg font-bold">Academix<span class="text-indigo-600">Suite</span></span>
                </a>
                
                <div class="flex items-center space-x-6">
                    <a href="./" class="text-slate-700 hover:text-indigo-600 transition font-medium">
                        <i class="fas fa-search mr-2"></i>Find Schools
                    </a>
                    <a href="#enrollment" class="bg-indigo-600 text-white px-4 py-2 rounded-lg font-semibold hover:bg-indigo-700 transition">
                        Apply Now
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Success Modal -->
    <?php if ($enrollmentSuccess): ?>
    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-8 text-center" data-aos="zoom-in">
            <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-check text-3xl text-emerald-600"></i>
            </div>
            <h3 class="text-2xl font-bold text-slate-900 mb-3">Application Submitted!</h3>
            <p class="text-slate-600 mb-6">
                Thank you for applying to <?php echo htmlspecialchars($school['name']); ?>. 
                Your application has been received and is under review.
            </p>
            <?php if (!empty($requestNumber)): ?>
            <div class="bg-slate-50 p-4 rounded-lg mb-6">
                <p class="text-sm text-slate-500">Your Application Number:</p>
                <p class="text-lg font-bold text-indigo-600"><?php echo $requestNumber; ?></p>
                <p class="text-xs text-slate-500 mt-2">Please save this number for future reference</p>
            </div>
            <?php endif; ?>
            <p class="text-sm text-slate-500 mb-6">
                The school administration will contact you within 3-5 business days.
            </p>
            <button onclick="closeSuccessModal()" class="w-full bg-indigo-600 text-white py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
                Continue Browsing
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="school-hero" style="background-image: url('<?php echo !empty($gallery[0]['image_url']) ? htmlspecialchars($gallery[0]['image_url']) : '../assets/default-school.png'; ?>'); background-size: cover; background-position: center;">
        <div class="container mx-auto px-4 h-full flex items-end">
            <div class="school-hero-content text-white pb-12 w-full">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                    <div>
                        <?php if ($enrollmentStatus === 'open'): ?>
                        <span class="status-badge status-open mb-4">
                            <i class="fas fa-circle text-xs"></i>
                            Admissions Open
                        </span>
                        <?php elseif ($enrollmentStatus === 'waiting_list'): ?>
                        <span class="status-badge status-waiting mb-4">
                            <i class="fas fa-clock text-xs"></i>
                            Waiting List Only
                        </span>
                        <?php else: ?>
                        <span class="status-badge status-closed mb-4">
                            <i class="fas fa-lock text-xs"></i>
                            Admissions Closed
                        </span>
                        <?php endif; ?>
                        
                        <h1 class="text-4xl md:text-6xl font-black mb-4"><?php echo htmlspecialchars($school['name']); ?></h1>
                        
                        <div class="flex items-center space-x-6 text-lg">
                            <div class="flex items-center">
                                <i class="fas fa-map-marker-alt mr-2"></i>
                                <span><?php echo htmlspecialchars($school['city'] . ', ' . $school['state']); ?></span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-graduation-cap mr-2"></i>
                                <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $school['school_type'] ?? 'Secondary'))); ?> School</span>
                            </div>
                            <?php if ($school['establishment_year']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-calendar-alt mr-2"></i>
                                <span>Est. <?php echo $school['establishment_year']; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/20 max-w-md">
                        <div class="flex items-center justify-between mb-4">
                            <div class="text-3xl font-bold">
                                <?php echo number_format($ratingStats['average'], 1); ?>/5.0
                            </div>
                            <div class="rating-stars text-xl">
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
                        </div>
                        <p class="text-white/80 mb-2">
                            Based on <?php echo $ratingStats['total']; ?> parent reviews
                        </p>
                        <?php if ($enrollmentStatus === 'open'): ?>
                        <a href="#enrollment" 
                           class="w-full bg-white text-indigo-600 py-3 rounded-lg font-bold text-center block hover:bg-gray-100 transition">
                            Apply for Admission
                        </a>
                        <?php else: ?>
                        <button class="w-full bg-gray-400 text-white py-3 rounded-lg font-bold cursor-not-allowed">
                            Admissions Closed
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-8">
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200 mb-8">
            <nav class="flex space-x-8 overflow-x-auto">
                <button onclick="showTab('overview')" 
                        class="tab-button py-4 text-lg font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap active"
                        id="tab-overview">
                    <i class="fas fa-info-circle mr-2"></i>Overview
                </button>
                <button onclick="showTab('facilities')" 
                        class="tab-button py-4 text-lg font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap"
                        id="tab-facilities">
                    <i class="fas fa-building mr-2"></i>Facilities
                </button>
                <button onclick="showTab('gallery')" 
                        class="tab-button py-4 text-lg font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap"
                        id="tab-gallery">
                    <i class="fas fa-images mr-2"></i>Gallery
                </button>
                <button onclick="showTab('reviews')" 
                        class="tab-button py-4 text-lg font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap"
                        id="tab-reviews">
                    <i class="fas fa-star mr-2"></i>Reviews
                </button>
                <button onclick="showTab('contact')" 
                        class="tab-button py-4 text-lg font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap"
                        id="tab-contact">
                    <i class="fas fa-phone mr-2"></i>Contact
                </button>
                <button onclick="showTab('enrollment')" 
                        class="tab-button py-4 text-lg font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap"
                        id="tab-enrollment">
                    <i class="fas fa-file-alt mr-2"></i>Admission
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div id="tab-content">
            <!-- Overview Tab -->
            <div id="overview-tab" class="tab-content active">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Main Content -->
                    <div class="lg:col-span-2">
                        <!-- School Description -->
                        <div class="bg-white rounded-xl p-8 shadow-sm mb-8">
                            <h2 class="text-2xl font-bold text-slate-900 mb-6">About Our School</h2>
                            <div class="prose max-w-none">
                                <p class="text-slate-600 mb-6 text-lg">
                                    <?php echo nl2br(htmlspecialchars($school['description'] ?? 'No description available.')); ?>
                                </p>
                                
                                <?php if (!empty($school['mission_statement'])): ?>
                                <div class="bg-indigo-50 p-6 rounded-lg mb-6">
                                    <h3 class="font-bold text-indigo-900 mb-3 flex items-center">
                                        <i class="fas fa-bullseye mr-2"></i>Our Mission
                                    </h3>
                                    <p class="text-indigo-800">
                                        <?php echo nl2br(htmlspecialchars($school['mission_statement'])); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($school['vision_statement'])): ?>
                                <div class="bg-purple-50 p-6 rounded-lg mb-6">
                                    <h3 class="font-bold text-purple-900 mb-3 flex items-center">
                                        <i class="fas fa-eye mr-2"></i>Our Vision
                                    </h3>
                                    <p class="text-purple-800">
                                        <?php echo nl2br(htmlspecialchars($school['vision_statement'])); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($school['principal_message'])): ?>
                                <div class="border-l-4 border-amber-500 pl-6 my-8">
                                    <h3 class="font-bold text-slate-900 mb-3">Message from the Principal</h3>
                                    <?php if (!empty($school['principal_name'])): ?>
                                    <p class="text-slate-600 mb-2">
                                        <strong><?php echo htmlspecialchars($school['principal_name']); ?></strong>
                                    </p>
                                    <?php endif; ?>
                                    <p class="text-slate-600 italic">
                                        "<?php echo nl2br(htmlspecialchars($school['principal_message'])); ?>"
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                            <div class="bg-white p-6 rounded-xl shadow-sm text-center">
                                <div class="text-3xl font-bold text-indigo-600 mb-2">
                                    <?php echo $school['average_class_size'] ?? '25'; ?>
                                </div>
                                <div class="text-sm text-slate-500">Avg. Class Size</div>
                            </div>
                            <div class="bg-white p-6 rounded-xl shadow-sm text-center">
                                <div class="text-3xl font-bold text-purple-600 mb-2">
                                    <?php echo $school['teacher_student_ratio'] ?? '1:20'; ?>
                                </div>
                                <div class="text-sm text-slate-500">Teacher-Student Ratio</div>
                            </div>
                            <div class="bg-white p-6 rounded-xl shadow-sm text-center">
                                <div class="text-3xl font-bold text-emerald-600 mb-2">
                                    <?php echo $school['establishment_year'] ?? '2000'; ?>
                                </div>
                                <div class="text-sm text-slate-500">Year Established</div>
                            </div>
                            <div class="bg-white p-6 rounded-xl shadow-sm text-center">
                                <div class="text-3xl font-bold text-rose-600 mb-2">
                                    <?php echo count($facilities); ?>
                                </div>
                                <div class="text-sm text-slate-500">Facilities</div>
                            </div>
                        </div>
                        
                        <!-- Extracurricular Activities -->
                        <?php if (!empty($extracurricular)): ?>
                        <div class="bg-white rounded-xl p-8 shadow-sm mb-8">
                            <h2 class="text-2xl font-bold text-slate-900 mb-6">Extracurricular Activities</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($extracurricular as $activity): ?>
                                <div class="flex items-center p-4 bg-slate-50 rounded-lg">
                                    <i class="fas fa-futbol text-indigo-600 mr-3 text-xl"></i>
                                    <span class="text-slate-700"><?php echo htmlspecialchars($activity); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Sports Facilities -->
                        <?php if (!empty($sports)): ?>
                        <div class="bg-white rounded-xl p-8 shadow-sm">
                            <h2 class="text-2xl font-bold text-slate-900 mb-6">Sports & Recreation</h2>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <?php foreach ($sports as $sport): ?>
                                <div class="flex flex-col items-center p-4 bg-gradient-to-br from-slate-50 to-white rounded-xl border border-slate-100">
                                    <i class="fas fa-running text-2xl text-indigo-600 mb-2"></i>
                                    <span class="text-slate-700 text-sm font-medium"><?php echo htmlspecialchars($sport); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Quick Facts -->
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 text-lg">Quick Facts</h3>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center py-2 border-b border-slate-100">
                                    <span class="text-slate-600">School Type</span>
                                    <span class="font-semibold text-slate-900">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $school['school_type'] ?? 'Secondary'))); ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-slate-100">
                                    <span class="text-slate-600">Curriculum</span>
                                    <span class="font-semibold text-slate-900">
                                        <?php echo htmlspecialchars($school['curriculum'] ?? 'Nigerian'); ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-slate-100">
                                    <span class="text-slate-600">Boarding</span>
                                    <span class="font-semibold <?php echo $school['boarding_available'] ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                        <?php echo $school['boarding_available'] ? 'Available' : 'Not Available'; ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-slate-100">
                                    <span class="text-slate-600">Transportation</span>
                                    <span class="font-semibold <?php echo $school['transportation_available'] ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                        <?php echo $school['transportation_available'] ? 'Available' : 'Not Available'; ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-slate-100">
                                    <span class="text-slate-600">Meals Provided</span>
                                    <span class="font-semibold <?php echo $school['meal_provided'] ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                        <?php echo $school['meal_provided'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="text-slate-600">School Hours</span>
                                    <span class="font-semibold text-slate-900">
                                        <?php echo htmlspecialchars($school['school_hours'] ?? '8:00 AM - 3:00 PM'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Accreditation -->
                        <?php if (!empty($accreditations) || !empty($affiliations)): ?>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 text-lg">Accreditation & Affiliation</h3>
                            <div class="space-y-3">
                                <?php foreach ($accreditations as $accreditation): ?>
                                <div class="flex items-center p-3 bg-indigo-50 rounded-lg">
                                    <i class="fas fa-award text-indigo-600 mr-3"></i>
                                    <span class="text-sm text-slate-700"><?php echo htmlspecialchars($accreditation); ?></span>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php foreach ($affiliations as $affiliation): ?>
                                <div class="flex items-center p-3 bg-purple-50 rounded-lg">
                                    <i class="fas fa-handshake text-purple-600 mr-3"></i>
                                    <span class="text-sm text-slate-700"><?php echo htmlspecialchars($affiliation); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Fee Information -->
                        <?php if (($school['fee_range_from'] ?? 0) > 0): ?>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 text-lg">Annual Fee Range</h3>
                            <div class="space-y-4">
                                <div class="text-center p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-lg">
                                    <div class="text-3xl font-bold text-indigo-600 mb-1">
                                        ₦<?php echo number_format($school['fee_range_from']); ?> - ₦<?php echo number_format($school['fee_range_to']); ?>
                                    </div>
                                    <p class="text-sm text-slate-600">Per academic year</p>
                                </div>
                                <p class="text-sm text-slate-500 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Fees may vary based on grade level and boarding options
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Share School -->
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 text-lg">Share This School</h3>
                            <div class="flex space-x-3">
                                <button onclick="shareOnFacebook()" class="flex-1 bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition flex items-center justify-center">
                                    <i class="fab fa-facebook-f mr-2"></i> Facebook
                                </button>
                                <button onclick="shareOnTwitter()" class="flex-1 bg-blue-400 text-white py-2 rounded-lg hover:bg-blue-500 transition flex items-center justify-center">
                                    <i class="fab fa-twitter mr-2"></i> Twitter
                                </button>
                                <button onclick="shareViaWhatsApp()" class="flex-1 bg-green-500 text-white py-2 rounded-lg hover:bg-green-600 transition flex items-center justify-center">
                                    <i class="fab fa-whatsapp mr-2"></i> WhatsApp
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Facilities Tab -->
            <div id="facilities-tab" class="tab-content hidden">
                <div class="bg-white rounded-xl p-8 shadow-sm">
                    <h2 class="text-2xl font-bold text-slate-900 mb-8">School Facilities</h2>
                    
                    <?php if (!empty($facilities)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($facilities as $facility): ?>
                        <div class="facility-card bg-white rounded-xl p-6">
                            <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center mb-4">
                                <?php if ($facility['icon']): ?>
                                <i class="<?php echo htmlspecialchars($facility['icon']); ?> text-xl text-indigo-600"></i>
                                <?php else: ?>
                                <i class="fas fa-building text-xl text-indigo-600"></i>
                                <?php endif; ?>
                            </div>
                            <h3 class="font-bold text-slate-900 text-lg mb-2"><?php echo htmlspecialchars($facility['name']); ?></h3>
                            <?php if (!empty($facility['description'])): ?>
                            <p class="text-slate-600 text-sm"><?php echo htmlspecialchars($facility['description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-building text-4xl text-slate-300 mb-4"></i>
                        <p class="text-slate-500">No facilities information available.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gallery Tab -->
            <div id="gallery-tab" class="tab-content hidden">
                <div class="bg-white rounded-xl p-8 shadow-sm">
                    <h2 class="text-2xl font-bold text-slate-900 mb-8">School Gallery</h2>
                    
                    <?php if (!empty($gallery)): ?>
                    <div class="swiper gallery-swiper mb-8">
                        <div class="swiper-wrapper">
                            <?php foreach ($gallery as $image): ?>
                            <div class="swiper-slide">
                                <img src="<?php echo htmlspecialchars($image['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($image['caption'] ?? 'School Image'); ?>"
                                     class="w-full h-full object-cover">
                                <?php if (!empty($image['caption'])): ?>
                                <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-4 text-white">
                                    <p class="text-sm"><?php echo htmlspecialchars($image['caption']); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="swiper-pagination"></div>
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                    </div>
                    
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($gallery as $index => $image): ?>
                        <div class="cursor-pointer" onclick="openImageModal(<?php echo $index; ?>)">
                            <img src="<?php echo htmlspecialchars($image['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($image['caption'] ?? 'School Image'); ?>"
                                 class="w-full h-48 object-cover rounded-lg hover:opacity-90 transition">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-images text-4xl text-slate-300 mb-4"></i>
                        <p class="text-slate-500">No gallery images available.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Reviews Tab -->
            <div id="reviews-tab" class="tab-content hidden">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Rating Summary -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-xl p-6 shadow-sm sticky top-24">
                            <div class="text-center mb-6">
                                <div class="text-5xl font-bold text-slate-900 mb-2"><?php echo number_format($ratingStats['average'], 1); ?></div>
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
                                <p class="text-slate-600">Based on <?php echo $ratingStats['total']; ?> reviews</p>
                            </div>
                            
                            <div class="space-y-3">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <div class="flex items-center">
                                    <span class="text-sm text-slate-600 w-8"><?php echo $i; ?>★</span>
                                    <div class="flex-1 mx-3">
                                        <div class="rating-progress">
                                            <div class="rating-progress-fill" 
                                                 style="width: <?php echo $ratingStats['total'] > 0 ? ($ratingStats[$i] / $ratingStats['total'] * 100) : 0; ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="text-sm text-slate-600 w-10 text-right">
                                        <?php echo $ratingStats[$i]; ?>
                                    </span>
                                </div>
                                <?php endfor; ?>
                            </div>
                            
                            <button onclick="openReviewModal()" 
                                    class="w-full mt-6 bg-indigo-600 text-white py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
                                <i class="fas fa-pen mr-2"></i>Write a Review
                            </button>
                        </div>
                    </div>
                    
                    <!-- Reviews List -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-xl p-8 shadow-sm">
                            <h2 class="text-2xl font-bold text-slate-900 mb-6">Parent Reviews</h2>
                            
                            <?php if (!empty($reviews)): ?>
                            <div class="space-y-6">
                                <?php foreach ($reviews as $review): ?>
                                <div class="review-card rounded-xl p-6">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <div class="flex items-center mb-2">
                                                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600 font-bold mr-3">
                                                    <?php echo strtoupper(substr($review['parent_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <h4 class="font-bold text-slate-900"><?php echo htmlspecialchars($review['parent_name']); ?></h4>
                                                    <?php if ($review['student_name']): ?>
                                                    <p class="text-sm text-slate-500">Parent of <?php echo htmlspecialchars($review['student_name']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="rating-stars mb-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star<?php echo $i > $review['rating'] ? ' empty' : ''; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <p class="text-sm text-slate-500">
                                                <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <?php if ($review['title']): ?>
                                    <h5 class="font-semibold text-slate-900 mb-2"><?php echo htmlspecialchars($review['title']); ?></h5>
                                    <?php endif; ?>
                                    
                                    <p class="text-slate-600 mb-4"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                                    
                                    <?php if ($review['pros'] || $review['cons']): ?>
                                    <div class="grid grid-cols-2 gap-4 mb-4">
                                        <?php if ($review['pros']): ?>
                                        <div class="bg-emerald-50 p-4 rounded-lg">
                                            <h6 class="font-semibold text-emerald-800 mb-2 flex items-center">
                                                <i class="fas fa-thumbs-up mr-2"></i>Pros
                                            </h6>
                                            <p class="text-sm text-emerald-700"><?php echo htmlspecialchars($review['pros']); ?></p>
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
                                    
                                    <div class="flex justify-between items-center pt-4 border-t border-slate-100">
                                        <?php if ($review['is_verified']): ?>
                                        <span class="text-sm text-emerald-600 font-medium flex items-center">
                                            <i class="fas fa-check-circle mr-1"></i> Verified Parent
                                        </span>
                                        <?php endif; ?>
                                        <button onclick="markHelpful(<?php echo $review['id']; ?>)" 
                                                class="text-sm text-slate-500 hover:text-indigo-600 transition flex items-center">
                                            <i class="far fa-thumbs-up mr-1"></i>
                                            Helpful (<?php echo $review['helpful_count']; ?>)
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-12">
                                <i class="fas fa-star text-4xl text-slate-300 mb-4"></i>
                                <h3 class="text-xl font-bold text-slate-900 mb-2">No Reviews Yet</h3>
                                <p class="text-slate-500 mb-6">Be the first to review this school!</p>
                                <button onclick="openReviewModal()" 
                                        class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
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
                    <div class="bg-white rounded-xl p-8 shadow-sm">
                        <h2 class="text-2xl font-bold text-slate-900 mb-8">Contact Information</h2>
                        
                        <div class="space-y-6">
                            <?php if (!empty($contacts)): ?>
                            <?php foreach ($contacts as $contact): ?>
                            <div class="flex items-start p-4 bg-slate-50 rounded-lg">
                                <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center mr-4">
                                    <?php switch($contact['type']): case 'phone': ?>
                                        <i class="fas fa-phone text-indigo-600"></i>
                                        <?php break; case 'email': ?>
                                        <i class="fas fa-envelope text-indigo-600"></i>
                                        <?php break; case 'address': ?>
                                        <i class="fas fa-map-marker-alt text-indigo-600"></i>
                                        <?php break; case 'website': ?>
                                        <i class="fas fa-globe text-indigo-600"></i>
                                        <?php break; default: ?>
                                        <i class="fas fa-link text-indigo-600"></i>
                                    <?php endswitch; ?>
                                </div>
                                <div>
                                    <?php if ($contact['label']): ?>
                                    <h4 class="font-semibold text-slate-900"><?php echo htmlspecialchars($contact['label']); ?></h4>
                                    <?php endif; ?>
                                    <p class="text-slate-600 mt-1">
                                        <?php if ($contact['type'] === 'email'): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($contact['value']); ?>" 
                                           class="text-indigo-600 hover:underline">
                                            <?php echo htmlspecialchars($contact['value']); ?>
                                        </a>
                                        <?php elseif ($contact['type'] === 'phone'): ?>
                                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['value'])); ?>" 
                                           class="text-indigo-600 hover:underline">
                                            <?php echo htmlspecialchars($contact['value']); ?>
                                        </a>
                                        <?php elseif ($contact['type'] === 'website'): ?>
                                        <a href="<?php echo htmlspecialchars($contact['value']); ?>" 
                                           target="_blank" 
                                           class="text-indigo-600 hover:underline">
                                            <?php echo htmlspecialchars($contact['value']); ?>
                                        </a>
                                        <?php else: ?>
                                        <?php echo htmlspecialchars($contact['value']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if ($contact['is_primary']): ?>
                                <span class="ml-auto bg-indigo-100 text-indigo-800 text-xs px-2 py-1 rounded-full font-medium">
                                    Primary
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-address-book text-4xl text-slate-300 mb-4"></i>
                                <p class="text-slate-500">No contact information available.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Contact Form -->
                    <div class="bg-white rounded-xl p-8 shadow-sm">
                        <h2 class="text-2xl font-bold text-slate-900 mb-8">Send a Message</h2>
                        
                        <form id="contactForm" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">First Name *</label>
                                    <input type="text" name="first_name" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Last Name *</label>
                                    <input type="text" name="last_name" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Email Address *</label>
                                    <input type="email" name="email" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">Phone Number</label>
                                    <input type="tel" name="phone" 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Subject *</label>
                                <input type="text" name="subject" required 
                                       class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Message *</label>
                                <textarea name="message" rows="5" required 
                                          class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition"></textarea>
                            </div>
                            
                            <div>
                                <button type="submit" 
                                        class="w-full bg-indigo-600 text-white py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
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
                <div class="bg-white rounded-xl p-8 shadow-sm text-center">
                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-lock text-3xl text-slate-400"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-slate-900 mb-4">Admissions Currently Closed</h3>
                    <p class="text-slate-600 mb-6 max-w-md mx-auto">
                        <?php if ($enrollmentStatus === 'closed'): ?>
                        The admission period for <?php echo htmlspecialchars($school['name']); ?> has ended. 
                        Please check back later for the next admission cycle.
                        <?php elseif ($enrollmentStatus === 'waiting_list'): ?>
                        Admissions are currently on waiting list only. Please contact the school directly for more information.
                        <?php else: ?>
                        Admissions are not currently open. Please check back later.
                        <?php endif; ?>
                    </p>
                    <?php if ($school['admission_deadline'] && strtotime($school['admission_deadline']) > time()): ?>
                    <p class="text-sm text-slate-500">
                        Next admission deadline: <?php echo date('F j, Y', strtotime($school['admission_deadline'])); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-xl p-8 shadow-sm">
                    <!-- Enrollment Progress -->
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <?php 
                            $steps = ['Personal', 'Student', 'Documents', 'Review'];
                            foreach ($steps as $index => $step):
                            ?>
                            <div class="text-center flex-1">
                                <div class="w-10 h-10 rounded-full border-2 <?php echo $index === 0 ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 text-slate-400'; ?> flex items-center justify-center mx-auto mb-2 font-semibold">
                                    <?php echo $index + 1; ?>
                                </div>
                                <span class="text-sm <?php echo $index === 0 ? 'text-indigo-600 font-semibold' : 'text-slate-500'; ?>">
                                    <?php echo $step; ?>
                                </span>
                            </div>
                            <?php if ($index < count($steps) - 1): ?>
                            <div class="flex-1 h-0.5 bg-slate-200 mx-2"></div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center">
                            <p class="text-sm text-slate-500">
                                Step 1 of 4: Parent/Guardian Information
                            </p>
                        </div>
                    </div>
                    
                    <!-- Enrollment Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="enrollmentForm" class="space-y-8">
                        <!-- Step 1: Parent Information -->
                        <div id="step-1" class="enrollment-step active">
                            <h3 class="text-xl font-bold text-slate-900 mb-6">Parent/Guardian Information</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        First Name *
                                    </label>
                                    <input type="text" name="parent_first_name" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Last Name *
                                    </label>
                                    <input type="text" name="parent_last_name" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Email Address *
                                    </label>
                                    <input type="email" name="parent_email" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                    <p class="text-xs text-slate-500 mt-2">Admission updates will be sent here</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Phone Number *
                                    </label>
                                    <input type="tel" name="parent_phone" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                            </div>
                            
                            <div class="mt-6">
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Residential Address
                                </label>
                                <textarea name="parent_address" rows="3" 
                                          class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition"></textarea>
                            </div>
                            
                            <div class="flex justify-end mt-8">
                                <button type="button" onclick="nextStep(2)" 
                                        class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
                                    Next: Student Information
                                </button>
                            </div>
                        </div>
                        
                        <!-- Step 2: Student Information -->
                        <div id="step-2" class="enrollment-step">
                            <h3 class="text-xl font-bold text-slate-900 mb-6">Student Information</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Student's First Name *
                                    </label>
                                    <input type="text" name="student_first_name" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Student's Last Name *
                                    </label>
                                    <input type="text" name="student_last_name" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Gender *
                                    </label>
                                    <select name="student_gender" required 
                                            class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Date of Birth *
                                    </label>
                                    <input type="date" name="student_dob" required 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Grade Level *
                                    </label>
                                    <select name="student_grade" required 
                                            class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
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
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Previous School (if any)
                                    </label>
                                    <input type="text" name="student_previous_school" 
                                           class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Enrollment Type
                                    </label>
                                    <select name="enrollment_type" 
                                            class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                        <option value="new">New Student</option>
                                        <option value="transfer">Transfer Student</option>
                                        <option value="re_enrollment">Re-enrollment</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Academic Year *
                                    </label>
                                    <select name="academic_year" required 
                                            class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                        <option value="">Select Year</option>
                                        <option value="2024-2025">2024-2025</option>
                                        <option value="2025-2026">2025-2026</option>
                                        <option value="2026-2027">2026-2027</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-2">
                                        Academic Term
                                    </label>
                                    <select name="academic_term" 
                                            class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition">
                                        <option value="">Select Term</option>
                                        <option value="first">First Term</option>
                                        <option value="second">Second Term</option>
                                        <option value="third">Third Term</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-6">
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Special Requirements or Notes
                                </label>
                                <textarea name="special_requirements" rows="3" 
                                          class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition"></textarea>
                                <p class="text-xs text-slate-500 mt-2">
                                    Please mention any medical conditions, learning needs, or other requirements
                                </p>
                            </div>
                            
                            <div class="flex justify-between mt-8">
                                <button type="button" onclick="prevStep(1)" 
                                        class="px-6 py-3 border border-slate-300 text-slate-700 rounded-lg font-semibold hover:bg-slate-50 transition">
                                    Back
                                </button>
                                <button type="button" onclick="nextStep(3)" 
                                        class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
                                    Next: Documents
                                </button>
                            </div>
                        </div>
                        
                        <!-- Step 3: Documents -->
                        <div id="step-3" class="enrollment-step">
                            <h3 class="text-xl font-bold text-slate-900 mb-6">Required Documents</h3>
                            
                            <div class="bg-slate-50 rounded-xl p-6 mb-6">
                                <h4 class="font-semibold text-slate-900 mb-4">Documents Checklist</h4>
                                <ul class="space-y-3">
                                    <li class="flex items-center">
                                        <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                                        <span>Student's Birth Certificate</span>
                                    </li>
                                    <li class="flex items-center">
                                        <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                                        <span>Previous School Report Card</span>
                                    </li>
                                    <li class="flex items-center">
                                        <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                                        <span>Immunization Records</span>
                                    </li>
                                    <li class="flex items-center">
                                        <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                                        <span>Passport Photograph (2 copies)</span>
                                    </li>
                                    <li class="flex items-center">
                                        <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                                        <span>Parent/Guardian ID</span>
                                    </li>
                                </ul>
                                <p class="text-sm text-slate-500 mt-4">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    You can submit these documents now or bring them during the interview
                                </p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Upload Documents (Optional)
                                </label>
                                <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center">
                                    <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-4"></i>
                                    <p class="text-slate-600 mb-2">Drag & drop files here or click to browse</p>
                                    <p class="text-sm text-slate-500 mb-4">
                                        PDF, JPG, PNG up to 5MB each
                                    </p>
                                    <input type="file" name="documents[]" multiple 
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           class="hidden" id="fileUpload">
                                    <button type="button" onclick="document.getElementById('fileUpload').click()" 
                                            class="bg-indigo-100 text-indigo-600 px-4 py-2 rounded-lg font-semibold hover:bg-indigo-200 transition">
                                        Browse Files
                                    </button>
                                </div>
                                <div id="fileList" class="mt-4 space-y-2"></div>
                            </div>
                            
                            <div class="flex justify-between mt-8">
                                <button type="button" onclick="prevStep(2)" 
                                        class="px-6 py-3 border border-slate-300 text-slate-700 rounded-lg font-semibold hover:bg-slate-50 transition">
                                    Back
                                </button>
                                <button type="button" onclick="nextStep(4)" 
                                        class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-indigo-700 transition">
                                    Next: Review & Submit
                                </button>
                            </div>
                        </div>
                        
                        <!-- Step 4: Review & Submit -->
                        <div id="step-4" class="enrollment-step">
                            <h3 class="text-xl font-bold text-slate-900 mb-6">Review Your Application</h3>
                            
                            <div class="bg-slate-50 rounded-xl p-6 mb-6">
                                <h4 class="font-semibold text-slate-900 mb-4">Application Summary</h4>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <h5 class="font-medium text-slate-700 mb-2">Parent Information</h5>
                                        <div class="space-y-2">
                                            <p class="text-sm text-slate-600" id="review-parent-name"></p>
                                            <p class="text-sm text-slate-600" id="review-parent-email"></p>
                                            <p class="text-sm text-slate-600" id="review-parent-phone"></p>
                                        </div>
                                    </div>
                                    <div>
                                        <h5 class="font-medium text-slate-700 mb-2">Student Information</h5>
                                        <div class="space-y-2">
                                            <p class="text-sm text-slate-600" id="review-student-name"></p>
                                            <p class="text-sm text-slate-600" id="review-student-dob"></p>
                                            <p class="text-sm text-slate-600" id="review-student-grade"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="terms" required 
                                           class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                    <span class="ml-2 text-sm text-slate-700">
                                        I confirm that all information provided is accurate to the best of my knowledge
                                    </span>
                                </label>
                            </div>
                            
                            <div class="mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="privacy" required 
                                           class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                    <span class="ml-2 text-sm text-slate-700">
                                        I agree to the <a href="#" class="text-indigo-600 hover:underline">Privacy Policy</a> and 
                                        <a href="#" class="text-indigo-600 hover:underline">Terms of Service</a>
                                    </span>
                                </label>
                            </div>
                            
                            <?php if (!empty($enrollmentError)): ?>
                            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 mb-6">
                                <div class="flex items-center text-rose-800">
                                    <i class="fas fa-exclamation-circle mr-2"></i>
                                    <span class="font-medium">Error: <?php echo $enrollmentError; ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="flex justify-between">
                                <button type="button" onclick="prevStep(3)" 
                                        class="px-6 py-3 border border-slate-300 text-slate-700 rounded-lg font-semibold hover:bg-slate-50 transition">
                                    Back
                                </button>
                                <button type="submit" name="enrollment_submit" 
                                        class="bg-emerald-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-emerald-700 transition">
                                    Submit Application
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-slate-900 text-white mt-12">
        <div class="container mx-auto px-4 py-8">
            <div class="text-center">
                <p class="text-slate-400 text-sm">
                    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school['name']); ?>. All rights reserved.
                </p>
                <p class="text-slate-500 text-xs mt-2">
                    Part of the AcademixSuite network of schools
                </p>
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

        // Initialize Swiper
        const swiper = new Swiper('.gallery-swiper', {
            slidesPerView: 1,
            spaceBetween: 10,
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
            },
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },
            breakpoints: {
                640: {
                    slidesPerView: 1,
                    spaceBetween: 20,
                },
                768: {
                    slidesPerView: 1,
                    spaceBetween: 30,
                },
                1024: {
                    slidesPerView: 1,
                    spaceBetween: 40,
                },
            },
        });

        // Tab Navigation
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.remove('hidden');
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab button
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Scroll to top of tab content
            document.getElementById(tabName + '-tab').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }

        // Enrollment Form Steps
        let currentStep = 1;
        
        function nextStep(step) {
            // Validate current step before proceeding
            if (!validateStep(currentStep)) {
                return;
            }
            
            // Hide current step
            document.getElementById('step-' + currentStep).classList.remove('active');
            
            // Show next step
            document.getElementById('step-' + step).classList.add('active');
            
            // Update progress indicator
            updateProgress(step);
            
            // Update review summary
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
            
            // Update progress indicator
            updateProgress(step);
            
            currentStep = step;
            
            // Scroll to top of form
            document.getElementById('enrollment-tab').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }
        
        function validateStep(step) {
            let isValid = true;
            const stepElement = document.getElementById('step-' + step);
            
            // Get all required inputs in this step
            const requiredInputs = stepElement.querySelectorAll('[required]');
            
            requiredInputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('border-rose-500');
                    
                    // Show error message
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'text-rose-600 text-sm mt-1';
                    errorDiv.textContent = 'This field is required';
                    
                    // Remove existing error
                    const existingError = input.parentNode.querySelector('.text-rose-600');
                    if (existingError) {
                        existingError.remove();
                    }
                    
                    input.parentNode.appendChild(errorDiv);
                } else {
                    input.classList.remove('border-rose-500');
                    
                    // Remove error message
                    const existingError = input.parentNode.querySelector('.text-rose-600');
                    if (existingError) {
                        existingError.remove();
                    }
                }
            });
            
            // Special validation for email
            if (step === 1) {
                const emailInput = document.querySelector('input[name="parent_email"]');
                if (emailInput && emailInput.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(emailInput.value)) {
                        isValid = false;
                        emailInput.classList.add('border-rose-500');
                        
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'text-rose-600 text-sm mt-1';
                        errorDiv.textContent = 'Please enter a valid email address';
                        emailInput.parentNode.appendChild(errorDiv);
                    }
                }
            }
            
            return isValid;
        }
        
        function updateProgress(step) {
            // Update step numbers
            const steps = document.querySelectorAll('[id^="step-"]');
            steps.forEach((stepEl, index) => {
                const stepNum = index + 1;
                if (stepNum === step) {
                    stepEl.classList.add('active');
                } else {
                    stepEl.classList.remove('active');
                }
            });
            
            // Update progress indicator in UI
            const progressText = document.querySelector('.text-slate-500.text-center');
            if (progressText) {
                progressText.textContent = `Step ${step} of 4: ${getStepTitle(step)}`;
            }
        }
        
        function getStepTitle(step) {
            const titles = {
                1: 'Parent/Guardian Information',
                2: 'Student Information',
                3: 'Documents',
                4: 'Review & Submit'
            };
            return titles[step] || '';
        }
        
        function updateReviewSummary() {
            // Parent Information
            const parentFirstName = document.querySelector('input[name="parent_first_name"]').value;
            const parentLastName = document.querySelector('input[name="parent_last_name"]').value;
            const parentEmail = document.querySelector('input[name="parent_email"]').value;
            const parentPhone = document.querySelector('input[name="parent_phone"]').value;
            
            document.getElementById('review-parent-name').textContent = `${parentFirstName} ${parentLastName}`;
            document.getElementById('review-parent-email').textContent = parentEmail;
            document.getElementById('review-parent-phone').textContent = parentPhone;
            
            // Student Information
            const studentFirstName = document.querySelector('input[name="student_first_name"]').value;
            const studentLastName = document.querySelector('input[name="student_last_name"]').value;
            const studentDob = document.querySelector('input[name="student_dob"]').value;
            const studentGrade = document.querySelector('select[name="student_grade"]').value;
            
            document.getElementById('review-student-name').textContent = `${studentFirstName} ${studentLastName}`;
            document.getElementById('review-student-dob').textContent = `DOB: ${formatDate(studentDob)}`;
            document.getElementById('review-student-grade').textContent = `Grade: ${formatGrade(studentGrade)}`;
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
        
        function formatGrade(grade) {
            const gradeMap = {
                'nursery': 'Nursery',
                'kg': 'Kindergarten',
                'grade-1': 'Grade 1',
                'grade-2': 'Grade 2',
                'grade-3': 'Grade 3',
                'grade-4': 'Grade 4',
                'grade-5': 'Grade 5',
                'grade-6': 'Grade 6',
                'grade-7': 'Grade 7',
                'grade-8': 'Grade 8',
                'grade-9': 'Grade 9',
                'grade-10': 'Grade 10',
                'grade-11': 'Grade 11',
                'grade-12': 'Grade 12'
            };
            return gradeMap[grade] || grade;
        }
        
        // File Upload Handling
        document.getElementById('fileUpload').addEventListener('change', function(e) {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            Array.from(e.target.files).forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between bg-white p-3 rounded-lg border border-slate-200';
                
                fileItem.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-file text-slate-400 mr-3"></i>
                        <div>
                            <p class="text-sm text-slate-700">${file.name}</p>
                            <p class="text-xs text-slate-500">${formatFileSize(file.size)}</p>
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
                const file = files[i];
                if (index !== i) {
                    dt.items.add(file);
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
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
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
        document.getElementById('contactForm').addEventListener('submit', function(e) {
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
                // In a real application, you would send this to your server
                alert('Thank you for your message! We will contact you soon.');
                this.reset();
            }
        });
        
        // Review Modal (simplified version)
        function openReviewModal() {
            alert('Review functionality would open a modal for submitting reviews. In a production environment, this would connect to your backend.');
        }
        
        function markHelpful(reviewId) {
            // In production, make an AJAX call to update helpful count
            alert('Marked as helpful! In production, this would update the database.');
        }
        
        // Image Modal (simplified version)
        function openImageModal(index) {
            // In production, implement a lightbox/gallery modal
            const image = document.querySelectorAll('.swiper-slide img')[index];
            window.open(image.src, '_blank');
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set default values for enrollment form
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
            
            // Handle URL hash to open specific tab
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById('tab-' + hash)) {
                showTab(hash);
            }
        });
    </script>
</body>
</html>