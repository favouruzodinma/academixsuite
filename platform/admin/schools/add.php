<?php

/**
 * Add School Provisioning - Super Admin Interface
 * Now includes PHP session and DB connection
 */

// Start session and load required files
require_once __DIR__ . '/../../../includes/autoload.php';

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}
// Get super admin data
$superAdmin = $_SESSION['super_admin'];


// Generate CSRF token
$csrfToken = generateCsrfToken();

// Fetch available plans from database
$db = Database::getPlatformConnection();
$stmt = $db->prepare("SELECT id, name, slug, price_monthly, student_limit FROM plans WHERE is_active = 1 ORDER BY sort_order");
$stmt->execute();
$plans = $stmt->fetchAll();

// Get Nigerian states
$nigerianStates = [
    'Abia',
    'Adamawa',
    'Akwa Ibom',
    'Anambra',
    'Bauchi',
    'Bayelsa',
    'Benue',
    'Borno',
    'Cross River',
    'Delta',
    'Ebonyi',
    'Edo',
    'Ekiti',
    'Enugu',
    'Gombe',
    'Imo',
    'Jigawa',
    'Kaduna',
    'Kano',
    'Katsina',
    'Kebbi',
    'Kogi',
    'Kwara',
    'Lagos',
    'Nasarawa',
    'Niger',
    'Ogun',
    'Ondo',
    'Osun',
    'Oyo',
    'Plateau',
    'Rivers',
    'Sokoto',
    'Taraba',
    'Yobe',
    'Zamfara',
    'FCT Abuja'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Provision New School | AcademixSuite Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        :root {
            --brand-primary: #2563eb;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--brand-bg);
            color: #1e293b;
            -webkit-tap-highlight-color: transparent;
        }

        /* Mobile-optimized scrollbar */
        ::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .sidebar-link {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 3px solid transparent;
        }

        .sidebar-link:hover {
            background: #f1f5f9;
            color: var(--brand-primary);
        }

        .active-link {
            background: #eff6ff;
            color: var(--brand-primary);
            border-left-color: var(--brand-primary);
            font-weight: 600;
        }

        .dropdown-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dropdown-open .dropdown-content {
            max-height: 500px;
        }

        .dropdown-open .chevron {
            transform: rotate(180deg);
        }

        /* Mobile-first responsive design */
        @media (max-width: 640px) {
            .mobile-stack {
                flex-direction: column;
            }

            .mobile-full {
                width: 100%;
            }

            .mobile-text-center {
                text-align: center;
            }

            .mobile-p-4 {
                padding: 1rem;
            }

            .mobile-space-y-4>*+* {
                margin-top: 1rem;
            }
        }

        @media (max-width: 768px) {
            .tablet-hide {
                display: none;
            }

            .tablet-full {
                width: 100%;
            }
        }

        /* Touch-friendly sizes */
        .touch-target {
            min-height: 44px;
            min-width: 44px;
        }

        .glass-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .form-card {
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border-radius: 20px;
        }

        /* Step indicator */
        .step-indicator {
            position: relative;
            z-index: 1;
        }

        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 32px;
            height: 32px;
            background: #f1f5f9;
            border-radius: 50%;
            z-index: -1;
        }

        .step-indicator.active::before {
            background: #2563eb;
        }

        .step-indicator.completed::before {
            background: #10b981;
        }

        .step-line {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #e2e8f0;
            z-index: -2;
        }

        .step-line.active {
            background: #2563eb;
        }

        .step-line.completed {
            background: #10b981;
        }

        /* Form styling */
        .form-group {
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            background: white;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-input.error {
            border-color: #ef4444;
        }

        .error-message {
            display: none;
            font-size: 12px;
            color: #ef4444;
            margin-top: 4px;
            font-weight: 500;
        }

        .error-message.show {
            display: block;
        }

        /* Step transitions */
        .step-content {
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.3s ease, transform 0.3s ease;
            display: none;
        }

        .step-content.active {
            opacity: 1;
            transform: translateY(0);
            display: block;
        }

        /* File upload */
        .file-upload {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }

        /* Success animation */
        @keyframes successPulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        .success-pulse {
            animation: successPulse 0.6s ease-in-out;
        }

        /* Mobile menu overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.toggle-slider {
            background-color: #2563eb;
        }

        input:checked+.toggle-slider:before {
            transform: translateX(26px);
        }

        /* Add smooth transitions for step content */
        .step-content {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        /* Better focus states */
        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-input.error:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        /* Button hover effects */
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Loading animation */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .loading-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }

        .alert-error {
            background-color: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }

        .alert-info {
            background-color: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e40af;
        }
    </style>
</head>

<body class="antialiased overflow-hidden selection:bg-blue-100">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden" onclick="mobileSidebarToggle()"></div>

    <div class="flex h-screen overflow-hidden">

        <?php include '../filepath/sidebar.php'; ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">

            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">School Provisioning</h1>
                        <span class="px-2 py-0.5 bg-emerald-600 text-[10px] text-white font-black rounded uppercase">Provisioning</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="../index.php" class="hidden sm:flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-blue-600 text-sm font-medium transition">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Registry</span>
                    </a>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-clock"></i>
                        <span id="timestamp"><?php echo date('D, M d, Y h:i A'); ?></span>
                    </div>
                </div>
            </header>

            <!-- Display any flash messages -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="mx-4 lg:mx-8 mt-4">
                    <?php foreach ($_SESSION['flash'] as $type => $message): ?>
                        <div class="alert alert-<?php echo $type; ?>">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endforeach; ?>
                    <?php unset($_SESSION['flash']); ?>
                </div>
            <?php endif; ?>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- Progress Steps -->
                <div class="max-w-6xl mx-auto mb-8">
                    <div class="relative">
                        <div class="flex justify-between mb-6">
                            <div class="step-indicator active" id="stepIndicator1">
                                <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center">
                                    <span class="text-white text-sm font-bold">1</span>
                                </div>
                                <div class="absolute top-full left-1/2 transform -translate-x-1/2 mt-2 whitespace-nowrap">
                                    <span class="text-xs font-bold text-blue-600">School Info</span>
                                </div>
                            </div>
                            <div class="step-indicator" id="stepIndicator2">
                                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center">
                                    <span class="text-slate-500 text-sm font-bold">2</span>
                                </div>
                                <div class="absolute top-full left-1/2 transform -translate-x-1/2 mt-2 whitespace-nowrap">
                                    <span class="text-xs font-bold text-slate-400">Admin Setup</span>
                                </div>
                            </div>
                            <div class="step-indicator" id="stepIndicator3">
                                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center">
                                    <span class="text-slate-500 text-sm font-bold">3</span>
                                </div>
                                <div class="absolute top-full left-1/2 transform -translate-x-1/2 mt-2 whitespace-nowrap">
                                    <span class="text-xs font-bold text-slate-400">Subscription</span>
                                </div>
                            </div>
                            <div class="step-indicator" id="stepIndicator4">
                                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center">
                                    <span class="text-slate-500 text-sm font-bold">4</span>
                                </div>
                                <div class="absolute top-full left-1/2 transform -translate-x-1/2 mt-2 whitespace-nowrap">
                                    <span class="text-xs font-bold text-slate-400">Review</span>
                                </div>
                            </div>
                        </div>
                        <div class="step-line absolute top-4 left-0 w-full" id="stepLine1"></div>
                        <div class="step-line absolute top-4 left-1/3 w-1/3" id="stepLine2"></div>
                        <div class="step-line absolute top-4 left-2/3 w-1/3" id="stepLine3"></div>
                    </div>
                </div>

                <!-- Form Container -->
                <div class="max-w-6xl mx-auto">
                    <form id="provisionForm" action="process_provision.php" method="POST" enctype="multipart/form-data" class="bg-white form-card p-6 lg:p-8">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <div class="flex justify-between items-start mb-8">
                            <div>
                                <h2 class="text-xl font-black text-slate-900 mb-2" id="stepTitle">School Provisioning</h2>
                                <p class="text-sm text-slate-500" id="stepDescription">Fill in the essential details to onboard a new school to AcademixSuite</p>
                            </div>
                            <div class="hidden lg:flex items-center gap-2 px-4 py-2 bg-blue-50 rounded-lg">
                                <i class="fas fa-bolt text-blue-600"></i>
                                <span class="text-xs font-bold text-blue-600 uppercase">Auto-Slug: <span id="slugPreview">school-<?php echo time(); ?></span></span>
                            </div>
                        </div>

                        <!-- Step 1: School Information -->
                        <div id="step1" class="step-content active">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Left Column -->
                                <div class="space-y-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            School Name <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text"
                                            id="schoolName"
                                            name="school_name"
                                            class="form-input"
                                            placeholder="Enter full school name"
                                            required
                                            onkeyup="updateSlugPreview()">
                                        <div id="nameError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            School Type <span class="text-red-500">*</span>
                                        </label>
                                        <select id="schoolType" name="school_type" class="form-input" required>
                                            <option value="">Select school type</option>
                                            <option value="university">University / College</option>
                                            <option value="secondary" selected>Secondary School</option>
                                            <option value="primary">Primary School</option>
                                            <option value="vocational">Vocational Institute</option>
                                            <option value="training">Training Center</option>
                                            <option value="online">Online Academy</option>
                                        </select>
                                        <div id="typeError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Country & State <span class="text-red-500">*</span>
                                        </label>
                                        <div class="grid grid-cols-2 gap-4">
                                            <select id="country" name="country" class="form-input" required>
                                                <option value="">Select country</option>
                                                <option value="Nigeria" selected>Nigeria</option>
                                                <option value="Ghana">Ghana</option>
                                                <option value="Kenya">Kenya</option>
                                                <option value="South Africa">South Africa</option>
                                            </select>
                                            <select id="state" name="state" class="form-input" required>
                                                <option value="">Select state</option>
                                                <?php foreach ($nigerianStates as $state): ?>
                                                    <option value="<?php echo htmlspecialchars($state); ?>"><?php echo htmlspecialchars($state); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div id="locationError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Address <span class="text-red-500">*</span>
                                        </label>
                                        <textarea id="address"
                                            name="address"
                                            class="form-input"
                                            rows="3"
                                            placeholder="Full physical address of the school"
                                            required></textarea>
                                        <div id="addressError" class="error-message"></div>
                                    </div>
                                </div>

                                <!-- Right Column -->
                                <div class="space-y-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            School Email <span class="text-red-500">*</span>
                                        </label>
                                        <input type="email"
                                            id="schoolEmail"
                                            name="school_email"
                                            class="form-input"
                                            placeholder="contact@school.edu"
                                            required>
                                        <div id="emailError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Phone Number <span class="text-red-500">*</span>
                                        </label>
                                        <input type="tel"
                                            id="phone"
                                            name="phone"
                                            class="form-input"
                                            placeholder="+234 801 234 5678"
                                            required>
                                        <div id="phoneError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Website URL
                                        </label>
                                        <input type="url"
                                            id="website"
                                            name="website"
                                            class="form-input"
                                            placeholder="https://www.school.edu">
                                        <div id="websiteError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            School Logo
                                        </label>
                                        <div id="logoUpload" class="file-upload" onclick="document.getElementById('logoFile').click()">
                                            <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-3"></i>
                                            <p class="text-sm font-medium text-slate-700 mb-1">Drop logo here or click to upload</p>
                                            <p class="text-xs text-slate-500">PNG, JPG up to 5MB</p>
                                            <input type="file"
                                                id="logoFile"
                                                name="logo"
                                                class="hidden"
                                                accept=".png,.jpg,.jpeg,.webp"
                                                onchange="previewLogo(this)">
                                        </div>
                                        <div id="logoPreview" class="mt-4 hidden">
                                            <div class="flex items-center gap-3">
                                                <img id="previewImage" class="w-16 h-16 rounded-lg object-cover border border-slate-200">
                                                <div>
                                                    <p id="fileName" class="text-sm font-medium text-slate-700"></p>
                                                    <button type="button"
                                                        onclick="removeLogo()"
                                                        class="text-xs text-red-500 hover:text-red-700 mt-1">
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Estimated Capacity -->
                            <div class="border-t border-slate-100 pt-8">
                                <div class="mb-6">
                                    <h3 class="text-lg font-bold text-slate-900">School Capacity</h3>
                                    <p class="text-sm text-slate-500">Estimated capacity for resource allocation</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="form-group">
                                        <label class="form-label">Estimated Students</label>
                                        <div class="relative">
                                            <input type="number"
                                                id="studentCount"
                                                name="max_students"
                                                class="form-input pr-12"
                                                min="1"
                                                max="100000"
                                                value="500"
                                                required>
                                            <span class="absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-500">students</span>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Staff Members</label>
                                        <div class="relative">
                                            <input type="number"
                                                id="staffCount"
                                                name="max_staff"
                                                class="form-input pr-12"
                                                min="1"
                                                max="5000"
                                                value="50"
                                                required>
                                            <span class="absolute right-4 top-1/2 transform -translate-y-1/2 text-slate-500">staff</span>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">City/Town</label>
                                        <input type="text"
                                            id="city"
                                            name="city"
                                            class="form-input"
                                            placeholder="Enter city/town"
                                            required>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex justify-between pt-8 border-t border-slate-100">
                                <button type="button"
                                    onclick="window.location.href='../index.php'"
                                    class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                    Cancel Provision
                                </button>
                                <button type="button"
                                    onclick="nextStep(2)"
                                    class="px-8 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center gap-2 touch-target">
                                    Continue to Admin Setup
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Admin Setup -->
                        <div id="step2" class="step-content">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Primary Admin -->
                                <div class="space-y-6">
                                    <h3 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-4">Primary Administrator</h3>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Full Name <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text"
                                            id="adminName"
                                            name="admin_name"
                                            class="form-input"
                                            placeholder="Dr. Sarah Thompson"
                                            required>
                                        <div id="adminNameError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Email Address <span class="text-red-500">*</span>
                                        </label>
                                        <input type="email"
                                            id="adminEmail"
                                            name="admin_email"
                                            class="form-input"
                                            placeholder="admin@school.edu"
                                            required>
                                        <div id="adminEmailError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Phone Number <span class="text-red-500">*</span>
                                        </label>
                                        <input type="tel"
                                            id="adminPhone"
                                            name="admin_phone"
                                            class="form-input"
                                            placeholder="+234 801 234 5678"
                                            required>
                                        <div id="adminPhoneError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Position / Title <span class="text-red-500">*</span>
                                        </label>
                                        <select id="adminTitle" name="admin_title" class="form-input" required>
                                            <option value="">Select position</option>
                                            <option value="principal">Principal</option>
                                            <option value="headteacher">Head Teacher</option>
                                            <option value="administrator">Administrator</option>
                                            <option value="director">Director</option>
                                            <option value="proprietor">Proprietor</option>
                                        </select>
                                        <div id="adminTitleError" class="error-message"></div>
                                    </div>
                                </div>

                                <!-- Additional Settings -->
                                <div class="space-y-6">
                                    <h3 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-4">Access Configuration</h3>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Initial Password <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <input type="password"
                                                id="adminPassword"
                                                name="admin_password"
                                                class="form-input pr-12"
                                                placeholder="Generate strong password"
                                                required>
                                            <button type="button"
                                                onclick="generatePassword()"
                                                class="absolute right-4 top-1/2 transform -translate-y-1/2 text-blue-600 hover:text-blue-700">
                                                <i class="fas fa-redo"></i>
                                            </button>
                                        </div>
                                        <div class="text-xs text-slate-400 mt-2">Password will be sent via secure email</div>
                                        <div id="passwordError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Admin Role <span class="text-red-500">*</span>
                                        </label>
                                        <select id="adminRole" name="admin_role" class="form-input" required>
                                            <option value="">Select role</option>
                                            <option value="owner">Owner (Full Access)</option>
                                            <option value="admin">Administrator</option>
                                            <option value="principal">Principal</option>
                                            <option value="accountant">Accountant</option>
                                        </select>
                                        <div id="roleError" class="error-message"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">
                                            Security Settings
                                        </label>
                                        <div class="space-y-3">
                                            <label class="flex items-center gap-3">
                                                <input type="checkbox" id="twoFactor" name="two_factor" class="rounded border-slate-300">
                                                <span class="text-sm text-slate-700">Require Two-Factor Authentication</span>
                                            </label>
                                            <label class="flex items-center gap-3">
                                                <input type="checkbox" id="sessionTimeout" name="session_timeout" class="rounded border-slate-300" checked>
                                                <span class="text-sm text-slate-700">Auto-logout after 30 minutes</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex justify-between pt-8 border-t border-slate-100">
                                <button type="button"
                                    onclick="previousStep(1)"
                                    class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target flex items-center gap-2">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to School Info
                                </button>
                                <button type="button"
                                    onclick="nextStep(3)"
                                    class="px-8 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center gap-2 touch-target">
                                    Continue to Subscription
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 3: Subscription -->
                        <div id="step3" class="step-content">
                            <div class="space-y-8">
                                <h3 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-4">Subscription Plan Selection</h3>

                                <!-- Subscription Cards -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6" id="planSelection">
                                    <?php foreach ($plans as $index => $plan): ?>
                                        <div class="border-2 <?php echo $plan['slug'] === 'growth' ? 'border-blue-500 bg-blue-50' : 'border-slate-200'; ?> rounded-2xl p-6 hover:border-blue-500 transition-colors cursor-pointer"
                                            onclick="selectPlan('<?php echo $plan['slug']; ?>', <?php echo $plan['id']; ?>, <?php echo $plan['price_monthly']; ?>, <?php echo $plan['student_limit']; ?>)">
                                            <?php if ($plan['slug'] === 'growth'): ?>
                                                <div class="absolute top-4 right-4 bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full">
                                                    RECOMMENDED
                                                </div>
                                            <?php endif; ?>
                                            <div class="flex justify-between items-start mb-4">
                                                <div>
                                                    <h4 class="font-bold text-slate-900 text-lg"><?php echo htmlspecialchars($plan['name']); ?></h4>
                                                    <p class="text-slate-500 text-sm"><?php echo $plan['slug'] === 'starter' ? 'Perfect for small schools' : ($plan['slug'] === 'growth' ? 'For growing schools' : 'For large institutions'); ?></p>
                                                </div>
                                                <div class="w-6 h-6 rounded-full border-2 <?php echo $plan['slug'] === 'growth' ? 'border-blue-500 bg-blue-500' : 'border-slate-300'; ?>"></div>
                                            </div>
                                            <div class="mb-6">
                                                <div class="text-3xl font-black text-slate-900">₦<?php echo number_format($plan['price_monthly'], 2); ?><span class="text-sm text-slate-500 font-normal">/month</span></div>
                                                <p class="text-xs text-slate-400 mt-1">Billed annually at ₦<?php echo number_format($plan['price_monthly'] * 12 * 0.85, 2); ?></p>
                                            </div>
                                            <ul class="space-y-3 mb-6">
                                                <li class="flex items-center gap-2 text-sm">
                                                    <i class="fas fa-check text-emerald-500"></i>
                                                    <span>Up to <?php echo number_format($plan['student_limit']); ?> students</span>
                                                </li>
                                                <li class="flex items-center gap-2 text-sm">
                                                    <i class="fas fa-check text-emerald-500"></i>
                                                    <span><?php echo $plan['slug'] === 'enterprise' ? 'Premium' : ($plan['slug'] === 'growth' ? 'Advanced' : 'Basic'); ?> analytics</span>
                                                </li>
                                                <li class="flex items-center gap-2 text-sm">
                                                    <i class="fas fa-check text-emerald-500"></i>
                                                    <span><?php echo $plan['slug'] === 'enterprise' ? '24/7 dedicated' : ($plan['slug'] === 'growth' ? 'Priority' : 'Email'); ?> support</span>
                                                </li>
                                                <?php if ($plan['slug'] === 'enterprise'): ?>
                                                    <li class="flex items-center gap-2 text-sm">
                                                        <i class="fas fa-check text-emerald-500"></i>
                                                        <span>API access</span>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Hidden plan input -->
                                <input type="hidden" id="planId" name="plan_id" value="2">
                                <input type="hidden" id="planSlug" name="plan_slug" value="growth">

                                <!-- Billing Options -->
                                <div class="border-t border-slate-100 pt-8">
                                    <h4 class="font-bold text-slate-900 mb-4">Billing Configuration</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="form-group">
                                            <label class="form-label">Billing Cycle</label>
                                            <div class="flex gap-4">
                                                <label class="flex items-center gap-2 cursor-pointer">
                                                    <input type="radio" name="billing_cycle" value="monthly" class="text-blue-600">
                                                    <span class="text-sm">Monthly</span>
                                                </label>
                                                <label class="flex items-center gap-2 cursor-pointer">
                                                    <input type="radio" name="billing_cycle" value="yearly" class="text-blue-600" checked>
                                                    <span class="text-sm">Yearly (Save 15%)</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Trial Period</label>
                                            <select id="trialPeriod" name="trial_period" class="form-input">
                                                <option value="0">No Trial</option>
                                                <option value="7" selected>7 Days</option>
                                                <option value="14">14 Days</option>
                                                <option value="30">30 Days</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Navigation Buttons -->
                                <div class="flex justify-between pt-8 border-t border-slate-100">
                                    <button type="button"
                                        onclick="previousStep(2)"
                                        class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target flex items-center gap-2">
                                        <i class="fas fa-arrow-left"></i>
                                        Back to Admin Setup
                                    </button>
                                    <button type="button"
                                        onclick="nextStep(4)"
                                        class="px-8 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center gap-2 touch-target">
                                        Continue to Review
                                        <i class="fas fa-arrow-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: Review -->
                        <div id="step4" class="step-content">
                            <div class="space-y-8">
                                <h3 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-4">Review & Provision</h3>

                                <!-- Summary Cards -->
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <!-- School Summary -->
                                    <div class="bg-slate-50 rounded-xl p-6">
                                        <h4 class="font-bold text-slate-900 mb-4 flex items-center gap-2">
                                            <i class="fas fa-school text-blue-600"></i>
                                            School Details
                                        </h4>
                                        <div class="space-y-4">
                                            <div>
                                                <p class="text-xs text-slate-500 uppercase font-bold">Name</p>
                                                <p id="reviewSchoolName" class="text-sm font-medium">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-slate-500 uppercase font-bold">Type & Location</p>
                                                <p id="reviewSchoolType" class="text-sm font-medium">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-slate-500 uppercase font-bold">Contact</p>
                                                <p id="reviewContact" class="text-sm font-medium">-</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Admin Summary -->
                                    <div class="bg-slate-50 rounded-xl p-6">
                                        <h4 class="font-bold text-slate-900 mb-4 flex items-center gap-2">
                                            <i class="fas fa-user-shield text-blue-600"></i>
                                            Administrator
                                        </h4>
                                        <div class="space-y-4">
                                            <div>
                                                <p class="text-xs text-slate-500 uppercase font-bold">Primary Admin</p>
                                                <p id="reviewAdminName" class="text-sm font-medium">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-slate-500 uppercase font-bold">Contact</p>
                                                <p id="reviewAdminContact" class="text-sm font-medium">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-slate-500 uppercase font-bold">Role</p>
                                                <p id="reviewAdminRole" class="text-sm font-medium">-</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Subscription Summary -->
                                <div class="bg-blue-50 rounded-xl p-6">
                                    <h4 class="font-bold text-slate-900 mb-4 flex items-center gap-2">
                                        <i class="fas fa-credit-card text-blue-600"></i>
                                        Subscription & Billing
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div>
                                            <p class="text-xs text-slate-500 uppercase font-bold">Plan Selected</p>
                                            <p id="reviewPlan" class="text-sm font-medium">-</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-slate-500 uppercase font-bold">Billing Cycle</p>
                                            <p id="reviewBilling" class="text-sm font-medium">-</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-slate-500 uppercase font-bold">Monthly Cost</p>
                                            <p id="reviewCost" class="text-sm font-medium">-</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Final Notes -->
                                <div class="border border-slate-200 rounded-xl p-6">
                                    <h4 class="font-bold text-slate-900 mb-4">Additional Notes</h4>
                                    <textarea id="finalNotes"
                                        name="notes"
                                        class="form-input w-full"
                                        rows="3"
                                        placeholder="Add any special instructions or notes for the school setup..."></textarea>
                                </div>

                                <!-- Terms & Conditions -->
                                <div class="border-t border-slate-100 pt-6">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input type="checkbox" id="termsAgreement" name="terms_agreed" class="mt-1" required>
                                        <div>
                                            <span class="text-sm text-slate-700">
                                                I confirm that all provided information is accurate and I have permission to onboard this school. I understand that this provisioning request will create a new school database with separate credentials.
                                            </span>
                                            <div id="termsError" class="error-message mt-2"></div>
                                        </div>
                                    </label>
                                </div>

                                <!-- Navigation Buttons -->
                                <div class="flex justify-between pt-8 border-t border-slate-100">
                                    <button type="button"
                                        onclick="previousStep(3)"
                                        class="px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target flex items-center gap-2">
                                        <i class="fas fa-arrow-left"></i>
                                        Back to Subscription
                                    </button>
                                    <button type="submit"
                                        id="submitBtn"
                                        class="px-8 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition flex items-center gap-2 touch-target">
                                        <i class="fas fa-rocket"></i>
                                        Provision School
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Loading Modal -->
    <div id="loadingModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4">
            <div class="text-center">
                <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-spinner fa-spin text-blue-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Processing Provisioning</h3>
                <p class="text-slate-600 mb-4">Setting up school database and accounts. This may take a moment...</p>
                <div class="w-full bg-slate-100 rounded-full h-2">
                    <div id="progressBar" class="bg-blue-600 h-2 rounded-full w-0 transition-all duration-500"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4 transform transition-all">
            <div class="text-center">
                <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6 success-pulse">
                    <i class="fas fa-check text-emerald-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2">School Provisioned Successfully!</h3>
                <p class="text-slate-600 mb-4" id="successMessage"></p>
                <div class="space-y-3">
                    <button onclick="closeSuccessModal()"
                        class="w-full py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                        Continue to Schools Registry
                    </button>
                    <button onclick="window.location.href='../index.php'"
                        class="w-full py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                        View All Schools
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div id="errorModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4">
            <div class="text-center">
                <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-red-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2" id="errorTitle">Provisioning Failed</h3>
                <p class="text-slate-600 mb-4" id="errorMessage"></p>
                <button onclick="closeErrorModal()"
                    class="w-full py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                    Try Again
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let selectedPlan = {
            slug: 'growth',
            id: 2,
            price: <?php echo $plans[1]['price_monthly'] ?? 49.99; ?>,
            name: 'Growth'
        };
        let formData = {};

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Provisioning form initialized');

            // Set initial timestamp
            updateTimestamp();
            setInterval(updateTimestamp, 60000);

            // Set default school type if not already set
            const schoolTypeSelect = document.getElementById('schoolType');
            if (schoolTypeSelect && !schoolTypeSelect.value) {
                schoolTypeSelect.value = 'secondary';
                console.log('Set default school type to:', schoolTypeSelect.value);
            }

            // Set default admin role
            const adminRoleSelect = document.getElementById('adminRole');
            if (adminRoleSelect && !adminRoleSelect.value) {
                adminRoleSelect.value = 'owner';
            }

            // Set default admin title
            const adminTitleSelect = document.getElementById('adminTitle');
            if (adminTitleSelect && !adminTitleSelect.value) {
                adminTitleSelect.value = 'principal';
            }

            // Initialize plan selection - FIXED: Use correct plan data
            const growthPlanPrice = <?php echo isset($plans[1]) ? $plans[1]['price_monthly'] : 49.99; ?>;
            const growthPlanLimit = <?php echo isset($plans[1]) ? $plans[1]['student_limit'] : 500; ?>;
            selectPlan('growth', 2, growthPlanPrice, growthPlanLimit);

            // Add event listeners for better UX
            initializeEventListeners();

            // Debug: Log all form fields
            console.log('Initial form state:');
            console.log('School Name:', document.getElementById('schoolName')?.value);
            console.log('School Type:', document.getElementById('schoolType')?.value);
            console.log('Current Step:', currentStep);

            // Make sure step 1 is visible
            showStep(1);
        });

        // Show specific step
        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.step-content').forEach(el => {
                el.classList.remove('active');
                el.style.display = 'none';
            });

            // Hide all indicators
            document.querySelectorAll('.step-indicator').forEach(el => {
                el.classList.remove('active', 'completed');
            });

            // Hide all lines
            document.querySelectorAll('.step-line').forEach(el => {
                el.classList.remove('completed');
            });

            // Show requested step
            const stepEl = document.getElementById(`step${step}`);
            const indicator = document.getElementById(`stepIndicator${step}`);

            if (stepEl) {
                stepEl.style.display = 'block';
                setTimeout(() => {
                    stepEl.classList.add('active');
                }, 50);
            }

            if (indicator) {
                indicator.classList.add('active');
            }

            // Mark previous steps as completed
            for (let i = 1; i < step; i++) {
                const prevIndicator = document.getElementById(`stepIndicator${i}`);
                const prevLine = document.getElementById(`stepLine${i}`);

                if (prevIndicator) prevIndicator.classList.add('completed');
                if (prevLine) prevLine.classList.add('completed');
            }

            currentStep = step;
            updateStepHeader();

            // Populate review data if step 4
            if (step === 4) {
                populateReviewData();
            }

            console.log(`Now showing step ${step}`);
        }

        // Update timestamp
        function updateTimestamp() {
            const now = new Date();
            const options = {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            document.getElementById('timestamp').textContent = now.toLocaleDateString('en-US', options);
        }

        // Generate slug preview
        function updateSlugPreview() {
            const name = document.getElementById('schoolName').value;
            if (name) {
                let slug = name.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .trim();
                document.getElementById('slugPreview').textContent = slug.substring(0, 50);
            }
        }

        // Initialize event listeners
        function initializeEventListeners() {
            console.log('Initializing event listeners...');

            // School name to city auto-fill
            document.getElementById('schoolName')?.addEventListener('blur', function() {
                const name = this.value;
                if (name && !document.getElementById('city').value) {
                    const cityMatch = name.match(/(?:^|\s)(\w+)(?:\s+(?:School|Academy|College|University|Institute))?$/i);
                    if (cityMatch && cityMatch[1]) {
                        document.getElementById('city').value = cityMatch[1];
                    }
                }
            });

            // Auto-fill country if not set
            document.getElementById('country')?.addEventListener('focus', function() {
                if (!this.value) {
                    this.value = 'Nigeria';
                }
            });

            // Fix: Add proper click handlers for ALL navigation buttons
            setupNavigationButtons();

            // Real-time validation for step 1
            const step1Fields = ['schoolName', 'schoolType', 'country', 'state', 'city', 'schoolEmail', 'phone', 'address'];
            step1Fields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('blur', validateField);
                    if (field.tagName === 'SELECT') {
                        field.addEventListener('change', validateField);
                    }
                }
            });

            // Password generator button
            document.querySelector('button[onclick="generatePassword()"]')?.addEventListener('click', function(e) {
                e.preventDefault();
                generatePassword();
            });

            // Logo upload
            document.getElementById('logoUpload')?.addEventListener('click', function() {
                document.getElementById('logoFile').click();
            });

            document.getElementById('logoFile')?.addEventListener('change', function(e) {
                previewLogo(this);
            });
        }

        // Setup navigation buttons
        function setupNavigationButtons() {
            console.log('Setting up navigation buttons...');

            // Remove existing onclick handlers and add proper event listeners
            const buttons = [{
                    selector: '[onclick*="nextStep(2)"]',
                    step: 2,
                    label: 'Continue to Admin Setup'
                },
                {
                    selector: '[onclick*="nextStep(3)"]',
                    step: 3,
                    label: 'Continue to Subscription'
                },
                {
                    selector: '[onclick*="nextStep(4)"]',
                    step: 4,
                    label: 'Continue to Review'
                }
            ];

            buttons.forEach(btn => {
                const button = document.querySelector(btn.selector);
                if (button) {
                    console.log(`Found button: ${btn.label}`);
                    // Remove old onclick
                    button.removeAttribute('onclick');
                    // Add new event listener
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        console.log(`${btn.label} clicked, current step: ${currentStep}, target step: ${btn.step}`);
                        nextStep(btn.step);
                    });
                } else {
                    console.warn(`Button not found: ${btn.selector}`);
                }
            });

            // Back buttons
            document.querySelectorAll('[onclick*="previousStep"]').forEach(btn => {
                const onclick = btn.getAttribute('onclick');
                const match = onclick?.match(/previousStep\((\d+)\)/);
                if (match) {
                    const step = parseInt(match[1]);
                    btn.removeAttribute('onclick');
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        console.log(`Back button clicked, going to step ${step}`);
                        previousStep(step);
                    });
                }
            });

            // Cancel button
            document.querySelector('[onclick*="window.location.href"]')?.addEventListener('click', function(e) {
                e.preventDefault();
                const match = this.getAttribute('onclick')?.match(/window\.location\.href='([^']+)'/);
                if (match) {
                    window.location.href = match[1];
                }
            });
        }

        // Real-time field validation
        function validateField(e) {
            const field = e.target;
            const fieldId = field.id;
            const value = field.value.trim();

            // Clear previous error for this field
            const errorElement = document.getElementById(fieldId + 'Error');
            if (errorElement) {
                errorElement.classList.remove('show');
                errorElement.textContent = '';
            }
            field.classList.remove('error');
            field.style.borderColor = '';
            field.style.boxShadow = '';
        }

        // Step Navigation - FIXED: Proper step transitions
        function nextStep(step) {
            console.log(`nextStep called: from ${currentStep} to ${step}`);

            // Don't allow going to same step
            if (step === currentStep) {
                console.warn(`Already on step ${step}, not moving`);
                return false;
            }

            // Validate current step before moving
            if (!validateCurrentStep()) {
                console.log(`Validation failed for step ${currentStep}`);
                highlightInvalidFields();
                return false;
            }

            // Save data from current step
            saveStepData(currentStep);
            console.log('Step data saved:', formData);

            // Transition to next step
            showStep(step);

            console.log(`Successfully moved to step ${step}`);
            return true;
        }

        function previousStep(step) {
            console.log(`previousStep: from ${currentStep} to ${step}`);

            // Don't allow going to same step
            if (step === currentStep) {
                console.warn(`Already on step ${step}, not moving`);
                return false;
            }

            // No validation needed when going back
            showStep(step);
            return true;
        }

        function updateStepHeader() {
            const titles = {
                1: 'School Provisioning',
                2: 'Administrator Setup',
                3: 'Subscription Plan Selection',
                4: 'Review & Finalize Provisioning'
            };

            const descriptions = {
                1: 'Fill in the essential details to onboard a new school to AcademixSuite',
                2: 'Configure primary administrator access and security settings',
                3: 'Select the appropriate subscription plan based on your school\'s needs',
                4: 'Review all details before launching the provisioning process'
            };

            const titleEl = document.getElementById('stepTitle');
            const descEl = document.getElementById('stepDescription');

            if (titleEl) titleEl.textContent = titles[currentStep] || 'School Provisioning';
            if (descEl) descEl.textContent = descriptions[currentStep] || '';
        }

        function saveStepData(step) {
            try {
                if (step === 1) {
                    formData.school = {
                        name: document.getElementById('schoolName')?.value || '',
                        type: document.getElementById('schoolType')?.value || '',
                        country: document.getElementById('country')?.value || '',
                        state: document.getElementById('state')?.value || '',
                        city: document.getElementById('city')?.value || '',
                        address: document.getElementById('address')?.value || '',
                        email: document.getElementById('schoolEmail')?.value || '',
                        phone: document.getElementById('phone')?.value || '',
                        website: document.getElementById('website')?.value || '',
                        max_students: document.getElementById('studentCount')?.value || 500,
                        max_staff: document.getElementById('staffCount')?.value || 50
                    };
                    console.log('Saved school data:', formData.school);
                } else if (step === 2) {
                    formData.admin = {
                        name: document.getElementById('adminName')?.value || '',
                        email: document.getElementById('adminEmail')?.value || '',
                        phone: document.getElementById('adminPhone')?.value || '',
                        title: document.getElementById('adminTitle')?.value || '',
                        role: document.getElementById('adminRole')?.value || '',
                        password: document.getElementById('adminPassword')?.value || '',
                        twoFactor: document.getElementById('twoFactor')?.checked || false,
                        sessionTimeout: document.getElementById('sessionTimeout')?.checked || false
                    };
                    console.log('Saved admin data:', formData.admin);
                }
            } catch (error) {
                console.error('Error saving step data:', error);
            }
        }

        function validateCurrentStep() {
            console.log(`Validating step ${currentStep}...`);

            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => {
                el.classList.remove('show');
                el.textContent = '';
            });

            document.querySelectorAll('.form-input').forEach(el => {
                el.classList.remove('error');
                el.style.borderColor = '';
                el.style.boxShadow = '';
            });

            if (currentStep === 1) {
                return validateStep1();
            } else if (currentStep === 2) {
                return validateStep2();
            } else if (currentStep === 4) {
                return validateStep4();
            }

            // Step 3 doesn't need validation
            return true;
        }

        function highlightInvalidFields() {
            // This function visually highlights all invalid fields
            if (currentStep === 1) {
                const fields = ['schoolName', 'schoolType', 'country', 'state', 'city', 'schoolEmail', 'phone', 'address'];
                fields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    const errorElement = document.getElementById(fieldId + 'Error');

                    if (field && !field.value.trim() && fieldId !== 'website') {
                        const fieldName = fieldId === 'schoolType' ? 'School type' :
                            fieldId === 'schoolEmail' ? 'School email' :
                            fieldId.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());

                        if (errorElement) {
                            errorElement.textContent = `${fieldName} is required`;
                            errorElement.classList.add('show');
                        }
                        field.classList.add('error');
                        field.style.borderColor = '#ef4444';
                        field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                    }
                });
            }
        }

        function validateStep1() {
            let isValid = true;
            const errors = [];

            // School Name
            const name = document.getElementById('schoolName')?.value.trim();
            if (!name) {
                showError('schoolName', 'School name is required');
                errors.push('School name is required');
                isValid = false;
            }

            // School Type
            const type = document.getElementById('schoolType')?.value;
            if (!type) {
                showError('schoolType', 'Please select a school type');
                errors.push('School type is required');
                isValid = false;
            }

            // Country
            const country = document.getElementById('country')?.value;
            if (!country) {
                showError('country', 'Please select a country');
                errors.push('Country is required');
                isValid = false;
            }

            // State
            const state = document.getElementById('state')?.value;
            if (!state) {
                showError('state', 'Please select a state');
                errors.push('State is required');
                isValid = false;
            }

            // City
            const city = document.getElementById('city')?.value.trim();
            if (!city) {
                showError('city', 'City/town is required');
                errors.push('City/town is required');
                isValid = false;
            }

            // School Email
            const email = document.getElementById('schoolEmail')?.value.trim();
            if (!email) {
                showError('schoolEmail', 'School email is required');
                errors.push('School email is required');
                isValid = false;
            } else if (!isValidEmail(email)) {
                showError('schoolEmail', 'Please enter a valid email address');
                errors.push('Invalid email format');
                isValid = false;
            }

            // Phone
            const phone = document.getElementById('phone')?.value.trim();
            if (!phone) {
                showError('phone', 'Phone number is required');
                errors.push('Phone number is required');
                isValid = false;
            }

            // Address
            const address = document.getElementById('address')?.value.trim();
            if (!address) {
                showError('address', 'Address is required');
                errors.push('Address is required');
                isValid = false;
            }

            if (!isValid) {
                console.log('Step 1 validation errors:', errors);
            } else {
                console.log('Step 1 validation passed!');
            }

            return isValid;
        }

        function validateStep2() {
            let isValid = true;
            const errors = [];

            // Admin Name
            const adminName = document.getElementById('adminName')?.value.trim();
            if (!adminName) {
                showError('adminName', 'Administrator name is required');
                errors.push('Administrator name is required');
                isValid = false;
            }

            // Admin Email
            const adminEmail = document.getElementById('adminEmail')?.value.trim();
            if (!adminEmail) {
                showError('adminEmail', 'Administrator email is required');
                errors.push('Administrator email is required');
                isValid = false;
            } else if (!isValidEmail(adminEmail)) {
                showError('adminEmail', 'Please enter a valid email address');
                errors.push('Invalid admin email format');
                isValid = false;
            }

            // Admin Role
            const adminRole = document.getElementById('adminRole')?.value;
            if (!adminRole) {
                showError('adminRole', 'Please select an admin role');
                errors.push('Admin role is required');
                isValid = false;
            }

            // Password
            const password = document.getElementById('adminPassword')?.value;
            if (!password) {
                showError('adminPassword', 'Administrator password is required');
                errors.push('Password is required');
                isValid = false;
            } else if (password.length < 6) {
                showError('adminPassword', 'Password must be at least 6 characters');
                errors.push('Password too short');
                isValid = false;
            }

            // Admin Phone
            const adminPhone = document.getElementById('adminPhone')?.value.trim();
            if (!adminPhone) {
                showError('adminPhone', 'Administrator phone is required');
                errors.push('Administrator phone is required');
                isValid = false;
            }

            // Admin Title
            const adminTitle = document.getElementById('adminTitle')?.value;
            if (!adminTitle) {
                showError('adminTitle', 'Administrator title is required');
                errors.push('Administrator title is required');
                isValid = false;
            }

            if (!isValid) {
                console.log('Step 2 validation errors:', errors);
            } else {
                console.log('Step 2 validation passed!');
            }

            return isValid;
        }

        function validateStep4() {
            let isValid = true;

            // Terms agreement
            const termsAgreed = document.getElementById('termsAgreement')?.checked;
            if (!termsAgreed) {
                const termsError = document.getElementById('termsError');
                if (termsError) {
                    termsError.textContent = 'You must agree to the terms to proceed';
                    termsError.classList.add('show');
                }
                isValid = false;
            }

            return isValid;
        }

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function showError(fieldId, message) {
            const errorElement = document.getElementById(fieldId + 'Error');
            const inputElement = document.getElementById(fieldId);

            if (errorElement && inputElement) {
                errorElement.textContent = message;
                errorElement.classList.add('show');
                inputElement.classList.add('error');
                inputElement.style.borderColor = '#ef4444';
                inputElement.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';

                // Focus on first error
                if (!document.querySelector('.form-input.error:focus')) {
                    inputElement.focus();
                }
            }
        }

        function selectPlan(planSlug, planId, planPrice, studentLimit) {
            console.log('Selecting plan:', {
                planSlug,
                planId,
                planPrice,
                studentLimit
            });

            selectedPlan = {
                slug: planSlug,
                id: planId,
                price: planPrice,
                studentLimit: studentLimit,
                name: planSlug === 'starter' ? 'Starter' : planSlug === 'growth' ? 'Growth' : 'Enterprise'
            };

            console.log('Selected plan:', selectedPlan);

            // Update hidden inputs
            const planIdInput = document.getElementById('planId');
            const planSlugInput = document.getElementById('planSlug');

            if (planIdInput) planIdInput.value = planId;
            if (planSlugInput) planSlugInput.value = planSlug;

            console.log('Updated hidden inputs:', {
                planId: planIdInput?.value,
                planSlug: planSlugInput?.value
            });

            // Update UI - remove all selections first
            const planCards = document.querySelectorAll('#planSelection > div');
            console.log(`Found ${planCards.length} plan cards`);

            planCards.forEach(card => {
                card.classList.remove('border-blue-500', 'bg-blue-50');
                const indicator = card.querySelector('.rounded-full');
                if (indicator) {
                    indicator.classList.remove('border-blue-500', 'bg-blue-500');
                    indicator.classList.add('border-slate-300');
                }
            });

            // Add selection to clicked card
            const selectedCard = document.querySelector(`[onclick*="${planSlug}"]`);
            if (selectedCard) {
                console.log('Found selected card:', selectedCard);
                selectedCard.classList.add('border-blue-500', 'bg-blue-50');
                const selectedIndicator = selectedCard.querySelector('.rounded-full');
                if (selectedIndicator) {
                    selectedIndicator.classList.add('border-blue-500', 'bg-blue-500');
                    selectedIndicator.classList.remove('border-slate-300');
                }
            } else {
                console.warn(`Could not find card for plan: ${planSlug}`);
                // Try alternative selector
                const altCard = document.querySelector(`[onclick*="selectPlan('${planSlug}')"]`);
                if (altCard) {
                    console.log('Found card with alternative selector');
                    altCard.classList.add('border-blue-500', 'bg-blue-50');
                    const indicator = altCard.querySelector('.rounded-full');
                    if (indicator) {
                        indicator.classList.add('border-blue-500', 'bg-blue-500');
                        indicator.classList.remove('border-slate-300');
                    }
                }
            }

            // Update student count if needed
            const studentCountInput = document.getElementById('studentCount');
            if (studentCountInput) {
                if (studentCountInput.value > studentLimit) {
                    studentCountInput.value = studentLimit;
                }
                studentCountInput.max = studentLimit;
                console.log('Updated student count max to:', studentLimit);
            }
        }

        function populateReviewData() {
            console.log('Populating review data with:', formData);

            // School details
            if (formData.school) {
                const schoolNameEl = document.getElementById('reviewSchoolName');
                const schoolTypeEl = document.getElementById('reviewSchoolType');
                const contactEl = document.getElementById('reviewContact');

                if (schoolNameEl) schoolNameEl.textContent = formData.school.name || 'Not provided';
                if (schoolTypeEl) schoolTypeEl.textContent =
                    `${(formData.school.type || '').toUpperCase()} • ${formData.school.city || ''}, ${formData.school.state || ''}`;
                if (contactEl) contactEl.textContent =
                    `${formData.school.email || ''} • ${formData.school.phone || ''}`;
            }

            // Admin details
            if (formData.admin) {
                const adminNameEl = document.getElementById('reviewAdminName');
                const adminContactEl = document.getElementById('reviewAdminContact');
                const adminRoleEl = document.getElementById('reviewAdminRole');

                if (adminNameEl) adminNameEl.textContent =
                    `${formData.admin.name || ''} (${formData.admin.title || ''})`;
                if (adminContactEl) adminContactEl.textContent =
                    `${formData.admin.email || ''} • ${formData.admin.phone || ''}`;
                if (adminRoleEl) adminRoleEl.textContent =
                    (formData.admin.role || '').toUpperCase();
            }

            // Subscription details
            const planName = selectedPlan.name || 'Growth';
            const planPrice = selectedPlan.price || 49.99;
            const planEl = document.getElementById('reviewPlan');
            const costEl = document.getElementById('reviewCost');
            const billingEl = document.getElementById('reviewBilling');

            if (planEl) planEl.textContent = planName;
            if (costEl) costEl.textContent =
                `₦${planPrice.toLocaleString('en-US', {minimumFractionDigits: 2})}/month`;

            // Billing cycle
            const billingCycle = document.querySelector('input[name="billing_cycle"]:checked');
            if (billingEl) {
                billingEl.textContent = billingCycle ? billingCycle.value.toUpperCase() : 'YEARLY';
            }

            console.log('Review data populated:', {
                plan: planName,
                price: planPrice,
                billing: billingEl?.textContent
            });
        }

        function generatePassword() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let password = '';
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            const passwordInput = document.getElementById('adminPassword');
            if (passwordInput) {
                passwordInput.value = password;
                passwordInput.type = 'text'; // Show password temporarily
                setTimeout(() => {
                    passwordInput.type = 'password';
                }, 2000);
            }
        }

        // Logo upload preview
        function previewLogo(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (file.size > 5 * 1024 * 1024) { // 5MB limit
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewImage = document.getElementById('previewImage');
                    const fileName = document.getElementById('fileName');
                    const logoPreview = document.getElementById('logoPreview');
                    const logoUpload = document.getElementById('logoUpload');

                    if (previewImage) previewImage.src = e.target.result;
                    if (fileName) fileName.textContent = file.name;
                    if (logoPreview) logoPreview.classList.remove('hidden');
                    if (logoUpload) logoUpload.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        }

        function removeLogo() {
            const logoFile = document.getElementById('logoFile');
            const logoPreview = document.getElementById('logoPreview');
            const logoUpload = document.getElementById('logoUpload');

            if (logoFile) logoFile.value = '';
            if (logoPreview) logoPreview.classList.add('hidden');
            if (logoUpload) logoUpload.style.display = 'block';
        }

        // Update the error handling part:
        document.getElementById('provisionForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log('Form submission started');

            if (!validateCurrentStep()) {
                console.log('Final validation failed');
                return;
            }

            // Save all data
            saveStepData(1);
            saveStepData(2);

            // Show loading modal
            showLoadingModal();

            try {
                const formDataObj = new FormData(this);

                // Log form data for debugging
                console.log('Submitting form data:');
                for (let [key, value] of formDataObj.entries()) {
                    if (key !== 'admin_password') { // Don't log passwords
                        console.log(`${key}: ${value}`);
                    }
                }

                console.log('Sending actual form data...');
                const response = await fetch('process_provision.php', {
                    method: 'POST',
                    body: formDataObj,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                console.log('Response status:', response.status);

                // Get the raw text first
                const rawText = await response.text();
                console.log('Raw response text (first 500 chars):', rawText.substring(0, 500));

                let result;
                try {
                    result = JSON.parse(rawText);
                    console.log('Parsed JSON response:', result);
                } catch (jsonError) {
                    console.error('Failed to parse JSON response:', jsonError);

                    // Try to extract error from HTML
                    let errorMessage = 'Server returned invalid JSON. ';
                    if (rawText.includes('<b>Warning</b>') || rawText.includes('<b>Fatal error</b>')) {
                        // Extract PHP error from HTML
                        const match = rawText.match(/<b>(.*?)<\/b>:\s*(.*?) in .*? on line/);
                        if (match) {
                            errorMessage += 'PHP Error: ' + match[1] + ' - ' + match[2];
                        } else {
                            errorMessage += 'Check PHP error logs.';
                        }
                    }

                    hideLoadingModal();
                    showErrorModal(
                        'Server Error',
                        errorMessage + '<br><br>' +
                        '<details><summary>Raw Response</summary><pre>' +
                        rawText.substring(0, 1000) + '</pre></details>'
                    );
                    return;
                }

                if (result.success) {
                    showSuccessModal(result.message, result.school_slug, result.admin_email);
                } else {
                    showErrorModal(
                        'Provisioning Failed',
                        result.message + '<br><br>' +
                        (result.debug ? '<details><summary>Debug Info</summary><pre>' +
                            JSON.stringify(result.debug, null, 2) + '</pre></details>' : '')
                    );
                }

            } catch (error) {
                console.error('Network error:', error);
                showErrorModal(
                    'Network Error',
                    'Unable to connect to server:<br><br>' +
                    error.message + '<br><br>' +
                    'Please check:<br>' +
                    '1. PHP is running<br>' +
                    '2. File exists at process_provision.php<br>' +
                    '3. Check browser console for details'
                );
            } finally {
                hideLoadingModal();
            }
        });

        function showLoadingModal() {
            const modal = document.getElementById('loadingModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';

                // Animate progress bar
                const progressBar = document.getElementById('progressBar');
                if (progressBar) {
                    let progress = 0;
                    const interval = setInterval(() => {
                        progress += 10;
                        progressBar.style.width = `${progress}%`;

                        if (progress >= 90) {
                            clearInterval(interval);
                        }
                    }, 500);
                }
            }
        }

        function hideLoadingModal() {
            const modal = document.getElementById('loadingModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';

                const progressBar = document.getElementById('progressBar');
                if (progressBar) {
                    progressBar.style.width = '0%';
                }
            }
        }

        function showSuccessModal(message, schoolSlug, adminEmail) {
            const modal = document.getElementById('successModal');
            const successMessage = document.getElementById('successMessage');

            if (modal && successMessage) {
                successMessage.innerHTML = `
                <strong>${formData.school?.name || 'School'}</strong> has been successfully provisioned.<br><br>
                <strong>School URL:</strong> /school/${schoolSlug || 'school-slug'}<br>
                <strong>Admin Email:</strong> ${adminEmail || formData.admin?.email || ''}<br><br>
                Credentials have been sent to the administrator.
            `;
                modal.classList.remove('hidden');
            }
        }

        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            if (modal) {
                modal.classList.add('hidden');
            }
            window.location.href = '../index.php';
        }

        function showErrorModal(title, message) {
            const modal = document.getElementById('errorModal');
            const errorTitle = document.getElementById('errorTitle');
            const errorMessage = document.getElementById('errorMessage');

            if (modal && errorTitle && errorMessage) {
                errorTitle.textContent = title;
                errorMessage.textContent = message;
                modal.classList.remove('hidden');
            }
        }

        function closeErrorModal() {
            const modal = document.getElementById('errorModal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        // Debug helper - expose functions to console
        window.debugForm = {
            getCurrentStep: () => currentStep,
            getFormData: () => formData,
            getSelectedPlan: () => selectedPlan,
            validateStep1: () => validateStep1(),
            validateStep2: () => validateStep2(),
            nextStep: (step) => nextStep(step),
            previousStep: (step) => previousStep(step),
            showStep: (step) => showStep(step),
            selectPlan: (slug, id, price, limit) => selectPlan(slug, id, price, limit)
        };

        // Quick test function
        function autoFillTestData() {
            console.log('Auto-filling test data...');

            // School data
            document.getElementById('schoolName').value = 'Test Academy School';
            document.getElementById('schoolType').value = 'secondary';
            document.getElementById('country').value = 'Nigeria';
            document.getElementById('state').value = 'Lagos';
            document.getElementById('city').value = 'Lagos';
            document.getElementById('schoolEmail').value = 'test@academy.edu';
            document.getElementById('phone').value = '+2348012345678';
            document.getElementById('address').value = '123 Test Street, Lagos';
            document.getElementById('website').value = 'https://testacademy.edu';
            document.getElementById('studentCount').value = '300';
            document.getElementById('staffCount').value = '30';

            // Admin data
            document.getElementById('adminName').value = 'John Doe';
            document.getElementById('adminEmail').value = 'john@academy.edu';
            document.getElementById('adminPhone').value = '+2348012345679';
            document.getElementById('adminTitle').value = 'principal';
            document.getElementById('adminRole').value = 'owner';
            document.getElementById('adminPassword').value = 'Test@123';

            // Update slug preview
            updateSlugPreview();

            console.log('Test data filled. You can now navigate through steps.');
        }

        // Expose test function
        window.autoFillTestData = autoFillTestData;

        // Quick navigation test
        function testNavigation() {
            console.log('Testing navigation...');
            autoFillTestData();
            setTimeout(() => nextStep(2), 100);
            setTimeout(() => nextStep(3), 500);
            setTimeout(() => nextStep(4), 1000);
        }

        window.testNavigation = testNavigation;
    </script>
</body>

</html>