<?php
/**
 * Add School Provisioning - Super Admin Interface
 * Updated with all required fields and improved UI
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
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        return bin2hex(random_bytes(32));
    }
}
$csrfToken = generateCsrfToken();

// Fetch available plans from database
$db = Database::getPlatformConnection();
$stmt = $db->prepare("SELECT id, name, slug, price_monthly, price_yearly, student_limit, teacher_limit, storage_limit, features FROM plans WHERE is_active = 1 ORDER BY sort_order");
$stmt->execute();
$plans = $stmt->fetchAll();

// Get Nigerian states and cities
$nigerianStates = [
    'Abia' => ['Umuahia', 'Aba', 'Owerri'],
    'Adamawa' => ['Yola', 'Mubi', 'Jimeta'],
    'Akwa Ibom' => ['Uyo', 'Eket', 'Ikot Ekpene'],
    'Anambra' => ['Awka', 'Onitsha', 'Nnewi'],
    'Bauchi' => ['Bauchi', 'Azare', 'Jama\'are'],
    'Bayelsa' => ['Yenagoa', 'Brass', 'Ogbia'],
    'Benue' => ['Makurdi', 'Gboko', 'Otukpo'],
    'Borno' => ['Maiduguri', 'Bama', 'Biul'],
    'Cross River' => ['Calabar', 'Ugep', 'Ogoja'],
    'Delta' => ['Asaba', 'Warri', 'Sapele'],
    'Ebonyi' => ['Abakaliki', 'Afikpo', 'Onueke'],
    'Edo' => ['Benin City', 'Auchi', 'Ekpoma'],
    'Ekiti' => ['Ado Ekiti', 'Ikere', 'Ise'],
    'Enugu' => ['Enugu', 'Nsukka', 'Agbani'],
    'Gombe' => ['Gombe', 'Bajoga', 'Kaltungo'],
    'Imo' => ['Owerri', 'Okigwe', 'Orlu'],
    'Jigawa' => ['Dutse', 'Hadejia', 'Kazaure'],
    'Kaduna' => ['Kaduna', 'Zaria', 'Kafanchan'],
    'Kano' => ['Kano', 'Dutse', 'Wudil'],
    'Katsina' => ['Katsina', 'Funtua', 'Daura'],
    'Kebbi' => ['Birnin Kebbi', 'Argungu', 'Yauri'],
    'Kogi' => ['Lokoja', 'Okene', 'Idah'],
    'Kwara' => ['Ilorin', 'Offa', 'Omu-Aran'],
    'Lagos' => ['Lagos', 'Ikeja', 'Badagry'],
    'Nasarawa' => ['Lafia', 'Keffi', 'Karu'],
    'Niger' => ['Minna', 'Bida', 'Suleja'],
    'Ogun' => ['Abeokuta', 'Sagamu', 'Ijebu-Ode'],
    'Ondo' => ['Akure', 'Ondo', 'Owo'],
    'Osun' => ['Osogbo', 'Ife', 'Ilesa'],
    'Oyo' => ['Ibadan', 'Oyo', 'Ogbomoso'],
    'Plateau' => ['Jos', 'Bukuru', 'Shendam'],
    'Rivers' => ['Port Harcourt', 'Bonny', 'Degema'],
    'Sokoto' => ['Sokoto', 'Tambuwal', 'Gwadabawa'],
    'Taraba' => ['Jalingo', 'Bali', 'Wukari'],
    'Yobe' => ['Damaturu', 'Potiskum', 'Gashua'],
    'Zamfara' => ['Gusau', 'Kaura Namoda', 'Talata Mafara'],
    'FCT Abuja' => ['Abuja', 'Gwagwalada', 'Kuje']
];

// School types with descriptions
$schoolTypes = [
    'nursery' => 'Nursery & Daycare',
    'primary' => 'Primary School',
    'secondary' => 'Secondary School',
    'comprehensive' => 'Comprehensive School',
    'international' => 'International School',
    'montessori' => 'Montessori School',
    'boarding' => 'Boarding School',
    'day' => 'Day School'
];

// Campus types
$campusTypes = [
    'main' => 'Main Campus',
    'branch' => 'Branch Campus',
    'satellite' => 'Satellite Campus',
    'virtual' => 'Virtual/Online Campus'
];

// Curriculum options
$curriculums = [
    'Nigerian' => 'Nigerian Curriculum',
    'British' => 'British Curriculum',
    'American' => 'American Curriculum',
    'Montessori' => 'Montessori',
    'IB' => 'International Baccalaureate',
    'Bilingual' => 'Bilingual',
    'Technical' => 'Technical/Vocational'
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

    :root {
        --brand-primary: #2563eb;
        --brand-primary-dark: #1d4ed8;
        --brand-secondary: #7c3aed;
        --brand-accent: #dc2626;
        --brand-surface: #ffffff;
        --brand-bg: #f9fafb;
        --brand-border: #e5e7eb;
        --brand-text: #111827;
        --brand-text-light: #6b7280;
        --brand-success: #059669;
        --brand-warning: #d97706;
        --brand-info: #0284c7;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
        color: var(--brand-text);
        -webkit-tap-highlight-color: transparent;
        letter-spacing: -0.01em;
    }

    h1, h2, h3, h4, h5, h6 {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-weight: 700;
        letter-spacing: -0.025em;
    }

    /* Header styling */
    header {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        backdrop-filter: blur(10px);
    }

    /* Form container */
    #provisionForm {
        background: linear-gradient(135deg, #ffffff 0%, #fcfcfc 100%);
        box-shadow: 
            0 4px 6px -1px rgba(0, 0, 0, 0.05),
            0 10px 15px -3px rgba(0, 0, 0, 0.08),
            0 20px 40px -20px rgba(0, 0, 0, 0.12);
        border: 1px solid var(--brand-border);
    }

    /* Professional form controls */
    input, select, textarea {
        font-family: 'Inter', sans-serif;
        font-size: 0.95rem;
        background: #ffffff;
        border: 1.5px solid var(--brand-border);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    input:focus, select:focus, textarea:focus {
        border-color: var(--brand-primary);
        box-shadow: 
            0 0 0 3px rgba(37, 99, 235, 0.1),
            0 1px 3px 0 rgba(0, 0, 0, 0.05);
        outline: none;
        background: #ffffff;
    }

    input:hover, select:hover, textarea:hover {
        border-color: #9ca3af;
    }

    /* Professional labels */
    label {
        font-weight: 600;
        color: var(--brand-text);
        font-size: 0.875rem;
        margin-bottom: 0.5rem;
        display: block;
    }

    /* Required field indicator */
    .required-indicator {
        color: var(--brand-accent);
        font-weight: 600;
        margin-left: 2px;
    }

    /* Step progress - professional */
    .step-progress {
        position: relative;
        padding: 0 2rem;
    }

    .step-progress::before {
        content: '';
        position: absolute;
        top: 28px;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, 
            var(--brand-primary) 0%, 
            var(--brand-border) 100%);
        z-index: 1;
        opacity: 0.3;
    }

    .step-progress.active::before {
        background: linear-gradient(90deg, 
            var(--brand-primary) 50%, 
            var(--brand-border) 50%);
        opacity: 1;
    }

    .step-dot {
        position: relative;
        z-index: 2;
        width: 56px;
        height: 56px;
        background: white;
        border: 2px solid var(--brand-border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .step-dot.active {
        background: var(--brand-primary);
        border-color: var(--brand-primary);
        color: white;
        transform: scale(1.05);
        box-shadow: 
            0 0 0 4px rgba(37, 99, 235, 0.1),
            0 4px 6px rgba(37, 99, 235, 0.1);
    }

    .step-dot.completed {
        background: var(--brand-success);
        border-color: var(--brand-success);
        color: white;
        box-shadow: 0 2px 8px rgba(5, 150, 105, 0.2);
    }

    /* Professional plan cards */
    .plan-card {
        background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
        border: 2px solid var(--brand-border);
        border-radius: 16px;
        padding: 2rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        position: relative;
        overflow: hidden;
        height: 100%;
    }

    .plan-card:hover {
        transform: translateY(-4px);
        border-color: var(--brand-primary);
        box-shadow: 
            0 10px 25px -5px rgba(0, 0, 0, 0.1),
            0 20px 40px -20px rgba(0, 0, 0, 0.15);
    }

    .plan-card.selected {
        border-color: var(--brand-primary);
        background: linear-gradient(135deg, #f8fafc 0%, #f0f9ff 100%);
        box-shadow: 
            0 0 0 1px var(--brand-primary),
            0 10px 25px -5px rgba(37, 99, 235, 0.1);
    }

    .plan-card.recommended::before {
        content: 'RECOMMENDED';
        position: absolute;
        top: 15px;
        right: -35px;
        background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
        color: white;
        padding: 4px 40px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        transform: rotate(45deg);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    /* Professional buttons */
    .btn-primary {
        background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-dark) 100%);
        color: white;
        font-weight: 600;
        padding: 0.875rem 1.75rem;
        border-radius: 12px;
        border: none;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 
            0 2px 4px rgba(37, 99, 235, 0.2),
            0 4px 8px rgba(37, 99, 235, 0.1);
    }

    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 
            0 4px 12px rgba(37, 99, 235, 0.25),
            0 8px 16px rgba(37, 99, 235, 0.1);
    }

    .btn-secondary {
        background: white;
        color: var(--brand-text);
        font-weight: 600;
        padding: 0.875rem 1.75rem;
        border-radius: 12px;
        border: 1.5px solid var(--brand-border);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-secondary:hover {
        background: #f9fafb;
        border-color: var(--brand-primary);
        color: var(--brand-primary);
    }

    /* Capacity cards - professional */
    .capacity-card {
        background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
        border: 1.5px solid var(--brand-border);
        border-radius: 16px;
        padding: 1.5rem;
        transition: all 0.2s ease;
    }

    .capacity-card:hover {
        transform: translateY(-2px);
        border-color: var(--brand-border);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    /* File upload area - professional */
    .file-upload-area {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border: 2px dashed var(--brand-border);
        border-radius: 16px;
        padding: 3rem 2rem;
        text-align: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
    }

    .file-upload-area:hover,
    .file-upload-area.dragover {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border-color: var(--brand-primary);
        border-style: solid;
    }

    /* Summary cards - professional */
    .summary-card {
        background: linear-gradient(135deg, #ffffff 0%, #fcfcfc 100%);
        border: 1.5px solid var(--brand-border);
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    }

    /* Toast notifications - professional */
    .toast {
        position: fixed;
        top: 1.5rem;
        right: 1.5rem;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        color: white;
        font-weight: 600;
        z-index: 9999;
        transform: translateX(120%);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 
            0 10px 25px -5px rgba(0, 0, 0, 0.1),
            0 20px 40px -20px rgba(0, 0, 0, 0.15);
        backdrop-filter: blur(10px);
    }

    .toast.show {
        transform: translateX(0);
        opacity: 1;
    }

    .toast.success {
        background: linear-gradient(135deg, var(--brand-success) 0%, #10b981 100%);
    }

    .toast.error {
        background: linear-gradient(135deg, var(--brand-accent) 0%, #ef4444 100%);
    }

    .toast.info {
        background: linear-gradient(135deg, var(--brand-info) 0%, #0ea5e9 100%);
    }

    /* Loading animation */
    @keyframes pulse {
        0%, 100% {
            opacity: 1;
        }
        50% {
            opacity: 0.5;
        }
    }

    .animate-pulse {
        animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }

    /* Professional modals */
    .modal-overlay {
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
    }

    .modal-content {
        background: linear-gradient(135deg, #ffffff 0%, #fcfcfc 100%);
        border: 1px solid var(--brand-border);
        box-shadow: 
            0 25px 50px -12px rgba(0, 0, 0, 0.25),
            0 20px 40px -20px rgba(0, 0, 0, 0.3);
    }

    /* Section dividers */
    .section-divider {
        border-top: 1.5px solid var(--brand-border);
        opacity: 0.5;
    }

    /* Status indicators */
    .status-badge {
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .status-badge-new {
        background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
        color: white;
    }

    /* Tooltips */
    .tooltip {
        position: relative;
    }

    .tooltip:hover::before {
        content: attr(data-tooltip);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: var(--brand-text);
        color: white;
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
        font-size: 0.75rem;
        white-space: nowrap;
        z-index: 100;
        margin-bottom: 0.5rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .step-progress {
            padding: 0 1rem;
        }
        
        .step-dot {
            width: 48px;
            height: 48px;
        }
        
        .plan-card {
            padding: 1.5rem;
        }
    }

    /* Focus states for accessibility */
    *:focus-visible {
        outline: 2px solid var(--brand-primary);
        outline-offset: 2px;
        border-radius: 4px;
    }

    /* Custom scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #a1a1a1;
    }
</style>
</head>

<body class="antialiased overflow-hidden">
    <div class="flex h-screen overflow-hidden">
        <?php 
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include $sidebarPath;
        } else {
            echo '<div class="w-64 bg-slate-800"></div>';
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Header -->
            <header class="h-16 bg-white border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Provision New School</h1>
                        <span class="px-2 py-0.5 bg-gradient-to-r from-indigo-600 to-purple-600 text-[10px] text-white font-black rounded-full uppercase">
                            New
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="../index.php" class="hidden sm:flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-indigo-600 text-sm font-medium transition">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Dashboard</span>
                    </a>
                    <div class="flex items-center gap-2 text-xs text-slate-500 bg-slate-100 px-3 py-1 rounded-full">
                        <i class="fas fa-user-shield"></i>
                        <span><?php echo htmlspecialchars($superAdmin['name'] ?? 'Super Admin'); ?></span>
                    </div>
                </div>
            </header>

            <!-- Progress Steps -->
            <div class="bg-white border-b border-slate-200 px-4 lg:px-8 py-4">
                <div class="max-w-6xl mx-auto">
                    <div class="step-progress flex justify-between relative">
                        <?php
                        $steps = [
                            1 => ['icon' => 'fa-school', 'title' => 'School Details', 'desc' => 'Basic information'],
                            2 => ['icon' => 'fa-user-shield', 'title' => 'Admin Setup', 'desc' => 'Primary administrator'],
                            3 => ['icon' => 'fa-credit-card', 'title' => 'Subscription', 'desc' => 'Choose plan'],
                            4 => ['icon' => 'fa-check-circle', 'title' => 'Review', 'desc' => 'Confirm details']
                        ];
                        
                        foreach ($steps as $num => $step):
                        ?>
                        <div class="text-center" id="step<?php echo $num; ?>Indicator">
                            <div class="step-dot mx-auto mb-2" id="stepDot<?php echo $num; ?>">
                                <i class="fas <?php echo $step['icon']; ?>"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Step <?php echo $num; ?></p>
                                <p class="text-sm font-semibold text-slate-900"><?php echo $step['title']; ?></p>
                                <p class="text-xs text-slate-400"><?php echo $step['desc']; ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Form Container -->
            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-6xl mx-auto">
                    <form id="provisionForm" action="process_provision.php" method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl shadow-lg p-6 lg:p-8">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="provision_type" value="full">

                        <!-- Step 1: School Information -->
                        <div id="step1" class="step-content active">
                            <div class="mb-8">
                                <h2 class="text-2xl font-black text-slate-900 mb-2">
                                    <i class="fas fa-school text-indigo-600 mr-2"></i>
                                    School Information
                                </h2>
                                <p class="text-slate-500">Fill in the basic details about the school</p>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Left Column -->
                                <div class="space-y-6">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            School Name <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text"
                                            id="schoolName"
                                            name="name"  
                                            class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                            placeholder="Enter full school name"
                                            required
                                            oninput="updateSlugPreview()">
                                        <p class="text-xs text-slate-400 mt-2" id="slugPreviewText">
                                            URL Slug: <span id="slugPreview" class="font-mono">school-<?php echo time(); ?></span>
                                        </p>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                School Type <span class="text-red-500">*</span>
                                            </label>
                                            <select id="schoolType" name="school_type" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                                <option value="">Select type</option>
                                                <?php foreach ($schoolTypes as $value => $label): ?>
                                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Campus Type <span class="text-red-500">*</span>
                                            </label>
                                            <select id="campusType" name="campus_type" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                                <option value="">Select campus type</option>
                                                <?php foreach ($campusTypes as $value => $label): ?>
                                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Curriculum <span class="text-red-500">*</span>
                                            </label>
                                            <select id="curriculum" name="curriculum" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                                <option value="">Select curriculum</option>
                                                <?php foreach ($curriculums as $value => $label): ?>
                                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Establishment Year
                                            </label>
                                            <input type="number"
                                                id="establishmentYear"
                                                name="establishment_year"
                                                min="1900"
                                                max="<?php echo date('Y'); ?>"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="1990">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Address <span class="text-red-500">*</span>
                                        </label>
                                        <textarea id="address"
                                            name="address"
                                            rows="3"
                                            class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                            placeholder="Full physical address of the school"
                                            required></textarea>
                                    </div>
                                </div>

                                <!-- Right Column -->
                                <div class="space-y-6">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Country <span class="text-red-500">*</span>
                                            </label>
                                            <select id="country" name="country" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                                <option value="">Select country</option>
                                                <option value="Nigeria" selected>Nigeria</option>
                                                <option value="Ghana">Ghana</option>
                                                <option value="Kenya">Kenya</option>
                                                <option value="South Africa">South Africa</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                State <span class="text-red-500">*</span>
                                            </label>
                                            <select id="state" name="state" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                                <option value="">Select state</option>
                                                <?php foreach ($nigerianStates as $state => $cities): ?>
                                                <option value="<?php echo htmlspecialchars($state); ?>"><?php echo htmlspecialchars($state); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                City/Town <span class="text-red-500">*</span>
                                            </label>
                                            <div class="relative">
                                                <input type="text"
                                                    id="city"
                                                    name="city"
                                                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                    placeholder="Enter city or town"
                                                    required>
                                                <button type="button"
                                                    onclick="openCitySelector()"
                                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-indigo-600 hover:text-indigo-700 p-2">
                                                    <i class="fas fa-list"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Postal Code
                                            </label>
                                            <input type="text"
                                                id="postalCode"
                                                name="postal_code"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="000000">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Email <span class="text-red-500">*</span>
                                            </label>
                                            <input type="email"
                                                id="schoolEmail"
                                                name="email"  
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="contact@school.edu"
                                                required>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Phone <span class="text-red-500">*</span>
                                            </label>
                                            <input type="tel"
                                                id="phone"
                                                name="phone"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="+234 801 234 5678"
                                                required>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- School Description & Logo -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700 mb-2">
                                        School Description
                                    </label>
                                    <textarea id="description"
                                        name="description"
                                        rows="4"
                                        class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                        placeholder="Brief description of the school's mission, vision, and values..."></textarea>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-700 mb-2">
                                        School Logo
                                    </label>
                                    <div class="file-upload-area" onclick="document.getElementById('logoFile').click()" id="logoUploadArea">
                                        <i class="fas fa-cloud-upload-alt text-4xl text-slate-400 mb-4"></i>
                                        <p class="text-sm font-medium text-slate-700 mb-1">Click to upload or drag & drop</p>
                                        <p class="text-xs text-slate-500">PNG, JPG up to 5MB (Recommended: 500x500px)</p>
                                        <input type="file"
                                            id="logoFile"
                                            name="logo_path" 
                                            class="hidden"
                                            accept=".png,.jpg,.jpeg,.webp"
                                            onchange="previewLogo(this)">
                                    </div>
                                    <div id="logoPreview" class="mt-4 hidden">
                                        <div class="flex items-center gap-4 p-4 bg-slate-50 rounded-xl">
                                            <img id="previewImage" class="w-16 h-16 rounded-lg object-cover border-2 border-slate-200">
                                            <div class="flex-1">
                                                <p id="fileName" class="text-sm font-medium text-slate-700"></p>
                                                <p id="fileSize" class="text-xs text-slate-500"></p>
                                            </div>
                                            <button type="button"
                                                onclick="removeLogo()"
                                                class="text-red-500 hover:text-red-700 p-2">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Capacity Estimation -->
                            <div class="mt-8 pt-8 border-t border-slate-200">
                                <h3 class="text-lg font-semibold text-slate-900 mb-6">
                                    <i class="fas fa-users text-indigo-600 mr-2"></i>
                                    Capacity Estimation
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                                    <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 p-6 rounded-xl">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-semibold text-indigo-900">Students</span>
                                            <i class="fas fa-graduation-cap text-indigo-600"></i>
                                        </div>
                                        <div class="relative">
                                            <input type="number"
                                                id="studentCount"
                                                name="student_count"
                                                min="1"
                                                max="100000"
                                                value="500"
                                                class="w-full bg-transparent border-0 text-2xl font-bold text-indigo-900 focus:outline-none"
                                                onchange="updateCapacityEstimate()">
                                            <span class="absolute right-0 top-1/2 transform -translate-y-1/2 text-indigo-600 font-medium">students</span>
                                        </div>
                                        <p class="text-xs text-indigo-700 mt-2">Estimated enrollment</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-6 rounded-xl">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-semibold text-purple-900">Teachers</span>
                                            <i class="fas fa-chalkboard-teacher text-purple-600"></i>
                                        </div>
                                        <div class="relative">
                                            <input type="number"
                                                id="teacherCount"
                                                name="teacher_count"
                                                min="1"
                                                max="10000"
                                                value="50"
                                                class="w-full bg-transparent border-0 text-2xl font-bold text-purple-900 focus:outline-none"
                                                onchange="updateCapacityEstimate()">
                                            <span class="absolute right-0 top-1/2 transform -translate-y-1/2 text-purple-600 font-medium">teachers</span>
                                        </div>
                                        <p class="text-xs text-purple-700 mt-2">Teaching staff</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 p-6 rounded-xl">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-semibold text-emerald-900">Classes</span>
                                            <i class="fas fa-school text-emerald-600"></i>
                                        </div>
                                        <div class="relative">
                                            <input type="number"
                                                id="classCount"
                                                name="class_count"
                                                min="1"
                                                max="1000"
                                                value="20"
                                                class="w-full bg-transparent border-0 text-2xl font-bold text-emerald-900 focus:outline-none"
                                                onchange="updateCapacityEstimate()">
                                            <span class="absolute right-0 top-1/2 transform -translate-y-1/2 text-emerald-600 font-medium">classes</span>
                                        </div>
                                        <p class="text-xs text-emerald-700 mt-2">Classrooms</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-rose-50 to-rose-100 p-6 rounded-xl">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-semibold text-rose-900">Ratio</span>
                                            <i class="fas fa-balance-scale text-rose-600"></i>
                                        </div>
                                        <div class="text-2xl font-bold text-rose-900" id="studentTeacherRatio">10:1</div>
                                        <p class="text-xs text-rose-700 mt-2">Student-teacher ratio</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation -->
                            <div class="flex justify-between mt-12 pt-8 border-t border-slate-200">
                                <a href="../index.php" class="px-6 py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                    <i class="fas fa-times"></i>
                                    Cancel
                                </a>
                                <button type="button"
                                    onclick="nextStep(2)"
                                    class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:from-indigo-700 hover:to-purple-700 transition flex items-center gap-2 shadow-lg shadow-indigo-100">
                                    Continue to Admin Setup
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Admin Setup -->
                        <div id="step2" class="step-content hidden">
                            <div class="mb-8">
                                <h2 class="text-2xl font-black text-slate-900 mb-2">
                                    <i class="fas fa-user-shield text-indigo-600 mr-2"></i>
                                    Primary Administrator
                                </h2>
                                <p class="text-slate-500">Set up the primary school administrator account</p>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Admin Details -->
                                <div class="space-y-6">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                First Name <span class="text-red-500">*</span>
                                            </label>
                                            <input type="text"
                                                id="adminFirstName"
                                                name="admin_first_name"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="John"
                                                required>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Last Name <span class="text-red-500">*</span>
                                            </label>
                                            <input type="text"
                                                id="adminLastName"
                                                name="admin_last_name"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="Doe"
                                                required>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Email Address <span class="text-red-500">*</span>
                                        </label>
                                        <input type="email"
                                            id="adminEmail"
                                            name="admin_email"
                                            class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                            placeholder="admin@school.edu"
                                            required>
                                        <p class="text-xs text-slate-400 mt-2">Login credentials will be sent to this email</p>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Phone Number <span class="text-red-500">*</span>
                                            </label>
                                            <input type="tel"
                                                id="adminPhone"
                                                name="admin_phone"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                                placeholder="+234 801 234 5678"
                                                required>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                                Position <span class="text-red-500">*</span>
                                            </label>
                                            <select id="adminPosition" name="admin_position" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                                <option value="">Select position</option>
                                                <option value="principal">Principal</option>
                                                <option value="headteacher">Head Teacher</option>
                                                <option value="director">Director</option>
                                                <option value="proprietor">Proprietor</option>
                                                <option value="admin">Administrator</option>
                                                <option value="owner" selected>Owner</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Admin Role <span class="text-red-500">*</span>
                                        </label>
                                        <select id="adminRole" name="admin_role" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" required>
                                            <option value="">Select role</option>
                                            <option value="owner" selected>Owner (Full Access)</option>
                                            <option value="administrator">Administrator</option>
                                            <option value="principal">Principal</option>
                                            <option value="accountant">Accountant</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Security Settings -->
                                <div class="space-y-6">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Initial Password <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <input type="password"
                                                id="adminPassword"
                                                name="admin_password"
                                                class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition pr-12"
                                                placeholder="Generate strong password"
                                                required>
                                            <div class="absolute right-3 top-1/2 transform -translate-y-1/2 flex gap-2">
                                                <button type="button"
                                                    onclick="togglePasswordVisibility('adminPassword')"
                                                    class="text-slate-400 hover:text-slate-600 p-1">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button"
                                                    onclick="generatePassword()"
                                                    class="text-indigo-600 hover:text-indigo-700 p-1">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-2 flex items-center gap-2">
                                            <div id="passwordStrength" class="flex-1 h-2 bg-slate-200 rounded-full overflow-hidden">
                                                <div id="passwordStrengthBar" class="h-full w-0 transition-all duration-300"></div>
                                            </div>
                                            <span id="passwordStrengthText" class="text-xs font-medium text-slate-500">Weak</span>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Send Welcome Email
                                        </label>
                                        <div class="flex items-center gap-3">
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox"
                                                    id="sendWelcomeEmail"
                                                    name="send_welcome_email"
                                                    class="sr-only peer"
                                                    checked>
                                                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-100 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                            </label>
                                            <span class="text-sm text-slate-700">Send login credentials via email</span>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Security Settings
                                        </label>
                                        <div class="space-y-3">
                                            <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                                                <input type="checkbox"
                                                    id="require2FA"
                                                    name="require_2fa"
                                                    class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                                <span class="text-sm text-slate-700">Require Two-Factor Authentication</span>
                                            </label>
                                            <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg">
                                                <input type="checkbox"
                                                    id="forcePasswordChange"
                                                    name="force_password_change"
                                                    class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500"
                                                    checked>
                                                <span class="text-sm text-slate-700">Force password change on first login</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">
                                            Additional Notes
                                        </label>
                                        <textarea id="adminNotes"
                                            name="admin_notes"
                                            rows="3"
                                            class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                                            placeholder="Any special instructions or notes..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation -->
                            <div class="flex justify-between mt-12 pt-8 border-t border-slate-200">
                                <button type="button"
                                    onclick="previousStep(1)"
                                    class="px-6 py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to School Info
                                </button>
                                <button type="button"
                                    onclick="nextStep(3)"
                                    class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:from-indigo-700 hover:to-purple-700 transition flex items-center gap-2 shadow-lg shadow-indigo-100">
                                    Continue to Subscription
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 3: Subscription -->
                        <div id="step3" class="step-content hidden">
                            <div class="mb-8">
                                <h2 class="text-2xl font-black text-slate-900 mb-2">
                                    <i class="fas fa-credit-card text-indigo-600 mr-2"></i>
                                    Subscription Plan
                                </h2>
                                <p class="text-slate-500">Choose the perfect plan for your school's needs</p>
                            </div>

                            <!-- Plan Cards -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8" id="planSelection">
                                <?php foreach ($plans as $index => $plan): 
                                    $features = json_decode($plan['features'] ?? '[]', true) ?: [];
                                    $isRecommended = in_array($plan['slug'], ['growth', 'premium']);
                                ?>
                                <div class="plan-card <?php echo $isRecommended ? 'recommended' : ''; ?>"
                                    onclick="selectPlan('<?php echo $plan['slug']; ?>', <?php echo $plan['id']; ?>, <?php echo $plan['price_monthly']; ?>, <?php echo $plan['student_limit']; ?>)"
                                    id="planCard<?php echo $plan['slug']; ?>">
                                    <div class="flex justify-between items-start mb-6">
                                        <div>
                                            <h3 class="text-xl font-black text-slate-900"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                            <p class="text-sm text-slate-500">
                                                <?php echo $plan['slug'] === 'starter' ? 'Perfect for small schools' : 
                                                      ($plan['slug'] === 'growth' ? 'For growing schools' : 
                                                      ($plan['slug'] === 'enterprise' ? 'For large institutions' : 'Custom solution')); ?>
                                            </p>
                                        </div>
                                        <div class="w-6 h-6 rounded-full border-2 border-slate-300"></div>
                                    </div>

                                    <div class="mb-6">
                                        <div class="flex items-baseline">
                                            <span class="text-3xl font-black text-slate-900">₦<?php echo number_format($plan['price_monthly'], 2); ?></span>
                                            <span class="text-slate-500 ml-2">/month</span>
                                        </div>
                                        <?php if ($plan['price_yearly']): ?>
                                        <p class="text-xs text-slate-400 mt-1">
                                            ₦<?php echo number_format($plan['price_yearly'], 2); ?> billed yearly
                                            <?php if ($plan['price_yearly'] < ($plan['price_monthly'] * 12)): ?>
                                            (save <?php echo round((1 - ($plan['price_yearly'] / ($plan['price_monthly'] * 12))) * 100); ?>%)
                                            <?php endif; ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="space-y-3 mb-6">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-slate-700">Students</span>
                                            <span class="text-sm font-semibold text-slate-900"><?php echo number_format($plan['student_limit']); ?></span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-slate-700">Teachers</span>
                                            <span class="text-sm font-semibold text-slate-900"><?php echo number_format($plan['teacher_limit']); ?></span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-slate-700">Storage</span>
                                            <span class="text-sm font-semibold text-slate-900"><?php echo number_format($plan['storage_limit']); ?> MB</span>
                                        </div>
                                    </div>

                                    <ul class="space-y-2 mb-6">
                                        <?php foreach ($features as $feature): ?>
                                        <li class="flex items-center gap-2 text-sm">
                                            <i class="fas fa-check text-emerald-500"></i>
                                            <span class="text-slate-700"><?php echo htmlspecialchars($feature); ?></span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Hidden plan inputs -->
                            <input type="hidden" id="planId" name="plan_id" value="<?php echo $plans[0]['id'] ?? 1; ?>">
                            <input type="hidden" id="planSlug" name="plan_slug" value="<?php echo $plans[0]['slug'] ?? 'starter'; ?>">

                            <!-- Billing Options -->
                            <div class="bg-gradient-to-r from-slate-50 to-slate-100 rounded-2xl p-6 mb-8">
                                <h3 class="text-lg font-semibold text-slate-900 mb-6">Billing Configuration</h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">Billing Cycle</label>
                                        <div class="flex gap-4">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio"
                                                    name="billing_cycle"
                                                    value="monthly"
                                                    class="text-indigo-600 focus:ring-indigo-500"
                                                    checked>
                                                <span class="text-sm text-slate-700">Monthly</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio"
                                                    name="billing_cycle"
                                                    value="yearly"
                                                    class="text-indigo-600 focus:ring-indigo-500">
                                                <span class="text-sm text-slate-700">Yearly</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">Trial Period</label>
                                        <select id="trialPeriod" name="trial_period" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition">
                                            <option value="0">No Trial</option>
                                            <option value="7" selected>7 Days Free Trial</option>
                                            <option value="14">14 Days Free Trial</option>
                                            <option value="30">30 Days Free Trial</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 mb-2">Payment Method</label>
                                        <select id="paymentMethod" name="payment_method" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition">
                                            <option value="paystack">Paystack (Recommended)</option>
                                            <option value="flutterwave">Flutterwave</option>
                                            <option value="manual">Manual Payment</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Estimated Cost -->
                                <div class="mt-6 p-4 bg-white rounded-xl border border-slate-200">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <p class="text-sm text-slate-500">Estimated Monthly Cost</p>
                                            <p class="text-2xl font-black text-slate-900" id="estimatedCost">₦<?php echo number_format($plans[0]['price_monthly'] ?? 49.99, 2); ?></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm text-slate-500">Estimated Yearly Cost</p>
                                            <p class="text-xl font-bold text-emerald-600" id="estimatedYearlyCost">₦<?php echo number_format(($plans[0]['price_yearly'] ?? ($plans[0]['price_monthly'] ?? 49.99) * 12), 2); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation -->
                            <div class="flex justify-between mt-12 pt-8 border-t border-slate-200">
                                <button type="button"
                                    onclick="previousStep(2)"
                                    class="px-6 py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Admin Setup
                                </button>
                                <button type="button"
                                    onclick="nextStep(4)"
                                    class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:from-indigo-700 hover:to-purple-700 transition flex items-center gap-2 shadow-lg shadow-indigo-100">
                                    Continue to Review
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 4: Review -->
                        <div id="step4" class="step-content hidden">
                            <div class="mb-8">
                                <h2 class="text-2xl font-black text-slate-900 mb-2">
                                    <i class="fas fa-check-circle text-emerald-600 mr-2"></i>
                                    Review & Confirm
                                </h2>
                                <p class="text-slate-500">Review all details before provisioning the school</p>
                            </div>

                            <!-- Summary Cards -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                                <!-- School Summary -->
                                <div class="bg-gradient-to-br from-indigo-50 to-white rounded-2xl p-6 border border-indigo-100">
                                    <h3 class="text-lg font-semibold text-indigo-900 mb-4 flex items-center gap-2">
                                        <i class="fas fa-school"></i>
                                        School Details
                                    </h3>
                                    <div class="space-y-4">
                                        <div>
                                            <p class="text-xs text-indigo-600 uppercase font-bold">Name</p>
                                            <p id="reviewSchoolName" class="text-sm font-medium text-slate-900">-</p>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <p class="text-xs text-indigo-600 uppercase font-bold">Type</p>
                                                <p id="reviewSchoolType" class="text-sm font-medium text-slate-900">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-indigo-600 uppercase font-bold">Campus Type</p>
                                                <p id="reviewCampusType" class="text-sm font-medium text-slate-900">-</p>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <p class="text-xs text-indigo-600 uppercase font-bold">Curriculum</p>
                                                <p id="reviewCurriculum" class="text-sm font-medium text-slate-900">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-indigo-600 uppercase font-bold">Location</p>
                                                <p id="reviewLocation" class="text-sm font-medium text-slate-900">-</p>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="text-xs text-indigo-600 uppercase font-bold">Contact</p>
                                            <p id="reviewContact" class="text-sm font-medium text-slate-900">-</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Admin Summary -->
                                <div class="bg-gradient-to-br from-purple-50 to-white rounded-2xl p-6 border border-purple-100">
                                    <h3 class="text-lg font-semibold text-purple-900 mb-4 flex items-center gap-2">
                                        <i class="fas fa-user-shield"></i>
                                        Administrator
                                    </h3>
                                    <div class="space-y-4">
                                        <div>
                                            <p class="text-xs text-purple-600 uppercase font-bold">Primary Admin</p>
                                            <p id="reviewAdminName" class="text-sm font-medium text-slate-900">-</p>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <p class="text-xs text-purple-600 uppercase font-bold">Role</p>
                                                <p id="reviewAdminRole" class="text-sm font-medium text-slate-900">-</p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-purple-600 uppercase font-bold">Position</p>
                                                <p id="reviewAdminPosition" class="text-sm font-medium text-slate-900">-</p>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="text-xs text-purple-600 uppercase font-bold">Email</p>
                                            <p id="reviewAdminEmail" class="text-sm font-medium text-slate-900">-</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-purple-600 uppercase font-bold">Phone</p>
                                            <p id="reviewAdminPhone" class="text-sm font-medium text-slate-900">-</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Subscription Summary -->
                            <div class="bg-gradient-to-br from-emerald-50 to-white rounded-2xl p-6 border border-emerald-100 mb-8">
                                <h3 class="text-lg font-semibold text-emerald-900 mb-4 flex items-center gap-2">
                                    <i class="fas fa-credit-card"></i>
                                    Subscription & Billing
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <p class="text-xs text-emerald-600 uppercase font-bold">Plan Selected</p>
                                        <p id="reviewPlan" class="text-lg font-black text-slate-900">-</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-emerald-600 uppercase font-bold">Billing Cycle</p>
                                        <p id="reviewBilling" class="text-lg font-black text-slate-900">-</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-emerald-600 uppercase font-bold">Monthly Cost</p>
                                        <p id="reviewCost" class="text-lg font-black text-slate-900">-</p>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <p class="text-xs text-emerald-600 uppercase font-bold">Features</p>
                                    <p id="reviewFeatures" class="text-sm text-slate-700">-</p>
                                </div>
                            </div>

                            <!-- Technical Details -->
                            <div class="bg-slate-50 rounded-2xl p-6 mb-8">
                                <h3 class="text-lg font-semibold text-slate-900 mb-4">Technical Details</h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <p class="text-xs text-slate-500 uppercase font-bold">Database Name</p>
                                        <p id="reviewDatabaseName" class="text-sm font-mono text-slate-900">school_XXXX</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 uppercase font-bold">Access URL</p>
                                        <p id="reviewAccessURL" class="text-sm font-mono text-slate-900">/academixsuite/tenant/school-slug</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 uppercase font-bold">Estimated Setup Time</p>
                                        <p class="text-sm font-medium text-slate-900">15-30 seconds</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Terms & Conditions -->
                            <div class="border border-slate-200 rounded-2xl p-6 mb-8">
                                <h3 class="text-lg font-semibold text-slate-900 mb-4">Terms & Conditions</h3>
                                <div class="space-y-4">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input type="checkbox"
                                            id="termsAgreement"
                                            name="terms_agreed"
                                            class="mt-1 w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500"
                                            required>
                                        <div>
                                            <span class="text-sm text-slate-700">
                                                I confirm that I have permission to provision this school and all information provided is accurate. I understand that:
                                            </span>
                                            <ul class="text-sm text-slate-600 mt-2 space-y-1">
                                                <li class="flex items-center gap-2">
                                                    <i class="fas fa-check text-emerald-500 text-xs"></i>
                                                    <span>A new database will be created</span>
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <i class="fas fa-check text-emerald-500 text-xs"></i>
                                                    <span>Administrator credentials will be generated</span>
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <i class="fas fa-check text-emerald-500 text-xs"></i>
                                                    <span>System will send welcome emails</span>
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <i class="fas fa-check text-emerald-500 text-xs"></i>
                                                    <span>Provisioning cannot be automatically reversed</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </label>
                                    <div id="termsError" class="text-red-500 text-sm hidden"></div>
                                </div>
                            </div>

                            <!-- Final Actions -->
                            <div class="flex justify-between items-center pt-8 border-t border-slate-200">
                                <div class="text-sm text-slate-500">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Ready to create your new school instance
                                </div>
                                <div class="flex gap-4">
                                    <button type="button"
                                        onclick="previousStep(3)"
                                        class="px-6 py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                        <i class="fas fa-arrow-left"></i>
                                        Back to Subscription
                                    </button>
                                    <button type="submit"
                                        id="submitBtn"
                                        class="px-8 py-3 bg-gradient-to-r from-emerald-600 to-emerald-500 text-white font-bold rounded-xl hover:from-emerald-700 hover:to-emerald-600 transition flex items-center gap-2 shadow-lg shadow-emerald-100">
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

    <!-- City Selector Modal -->
    <div id="citySelectorModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] hidden">
        <div class="bg-white rounded-2xl p-6 max-w-md mx-4 w-full">
            <div class="mb-6">
                <h3 class="text-xl font-bold text-slate-900">Select City or Enter Custom</h3>
                <p class="text-sm text-slate-500 mt-1">Choose from list or type your own</p>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Select from List</label>
                    <select id="citySelect" class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition">
                        <option value="">Select a city</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Or Enter Custom City</label>
                    <input type="text"
                        id="customCity"
                        class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"
                        placeholder="Enter city name">
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="button"
                    onclick="closeCitySelector()"
                    class="flex-1 py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="button"
                    onclick="applyCitySelection()"
                    class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:from-indigo-700 hover:to-purple-700 transition">
                    Apply
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loadingModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4">
            <div class="text-center">
                <div class="w-20 h-20 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-spinner fa-spin text-indigo-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2">Provisioning School</h3>
                <p class="text-slate-600 mb-4" id="loadingMessage">Setting up database and accounts...</p>
                <div class="w-full bg-slate-100 rounded-full h-2">
                    <div id="progressBar" class="bg-gradient-to-r from-indigo-600 to-purple-600 h-2 rounded-full w-0 transition-all duration-300"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-2xl mx-4">
            <div class="text-center">
                <div class="w-24 h-24 bg-gradient-to-br from-emerald-100 to-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-check text-emerald-600 text-4xl"></i>
                </div>
                <h3 class="text-2xl font-black text-slate-900 mb-2">School Successfully Provisioned!</h3>
                <p class="text-slate-600 mb-6" id="successMessage"></p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-slate-50 p-4 rounded-xl">
                        <p class="text-sm text-slate-500 mb-1">School URL</p>
                        <p id="successSchoolURL" class="font-mono text-sm text-slate-900 truncate"></p>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-xl">
                        <p class="text-sm text-slate-500 mb-1">Admin Email</p>
                        <p id="successAdminEmail" class="font-mono text-sm text-slate-900 truncate"></p>
                    </div>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-triangle text-amber-600 text-xl"></i>
                        <div class="text-left">
                            <p class="text-sm font-medium text-amber-900">Important Note</p>
                            <p class="text-xs text-amber-700">Login credentials have been sent to the administrator's email. They should check their inbox (and spam folder).</p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4">
                    <button onclick="window.location.href='../index.php'"
                        class="flex-1 py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Back to Dashboard
                    </button>
                    <button onclick="copySchoolURL()"
                        class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:from-indigo-700 hover:to-purple-700 transition">
                        Copy School URL
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div id="errorModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-md mx-4">
            <div class="text-center">
                <div class="w-20 h-20 bg-gradient-to-br from-rose-100 to-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-rose-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900 mb-2" id="errorTitle">Provisioning Failed</h3>
                <p class="text-slate-600 mb-4" id="errorMessage"></p>
                <div class="space-y-3">
                    <button onclick="closeErrorModal()"
                        class="w-full py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:from-indigo-700 hover:to-purple-700 transition">
                        Try Again
                    </button>
                    <button onclick="window.location.reload()"
                        class="w-full py-3 border-2 border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Refresh Page
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="fixed top-4 right-4 z-[9998] space-y-2"></div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Global state
        let currentStep = 1;
        let selectedPlan = {
            slug: '<?php echo $plans[0]['slug'] ?? 'starter'; ?>',
            id: <?php echo $plans[0]['id'] ?? 1; ?>,
            price: <?php echo $plans[0]['price_monthly'] ?? 49.99; ?>,
            name: '<?php echo htmlspecialchars($plans[0]['name'] ?? 'Starter'); ?>',
            features: <?php echo json_encode(json_decode($plans[0]['features'] ?? '[]', true) ?: []); ?>
        };
        let formData = {};
        let stateCities = <?php echo json_encode($nigerianStates); ?>;

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Provisioning form initialized');
            
            // Initialize Select2 for select elements
            initializeSelect2();
            
            // Set default plan
            selectPlan(selectedPlan.slug, selectedPlan.id, selectedPlan.price, 100);
            
            // Update slug preview
            updateSlugPreview();
            
            // Update progress indicators
            updateProgressIndicators();
            
            // Password strength indicator
            document.getElementById('adminPassword')?.addEventListener('input', checkPasswordStrength);
            
            // Generate initial password
            generatePassword();
            
            // Update billing costs when billing cycle changes
            document.querySelectorAll('input[name="billing_cycle"]').forEach(radio => {
                radio.addEventListener('change', updateEstimatedCosts);
            });
        });

        function initializeSelect2() {
            // Initialize select2 for select elements
            $('select').select2({
                width: '100%',
                theme: 'bootstrap4',
                minimumResultsForSearch: 10
            }).on('select2:open', function() {
                document.querySelector('.select2-search__field').focus();
            });
            
            // State change updates city options in modal
            $('#state').on('change', function() {
                const state = $(this).val();
                const citySelect = $('#citySelect');
                
                if (state && stateCities[state]) {
                    citySelect.empty();
                    citySelect.append('<option value="">Select a city</option>');
                    
                    stateCities[state].forEach(city => {
                        citySelect.append(`<option value="${city}">${city}</option>`);
                    });
                    
                    // Reinitialize select2
                    citySelect.select2('destroy').select2({
                        width: '100%',
                        theme: 'bootstrap4'
                    });
                } else {
                    citySelect.empty();
                    citySelect.append('<option value="">No cities available for this state</option>');
                    citySelect.prop('disabled', true);
                }
            });
        }

        // City selector functions
        function openCitySelector() {
            const modal = document.getElementById('citySelectorModal');
            const state = document.getElementById('state').value;
            
            if (!state) {
                showToast('Please select a state first', 'error');
                return;
            }
            
            // Populate city select if state is selected
            if (state && stateCities[state]) {
                const citySelect = document.getElementById('citySelect');
                citySelect.innerHTML = '<option value="">Select a city</option>';
                
                stateCities[state].forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    citySelect.appendChild(option);
                });
            }
            
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeCitySelector() {
            const modal = document.getElementById('citySelectorModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function applyCitySelection() {
            const modal = document.getElementById('citySelectorModal');
            const citySelect = document.getElementById('citySelect');
            const customCity = document.getElementById('customCity');
            
            let selectedCity = '';
            
            if (customCity.value.trim()) {
                selectedCity = customCity.value.trim();
            } else if (citySelect.value) {
                selectedCity = citySelect.value;
            }
            
            if (selectedCity) {
                document.getElementById('city').value = selectedCity;
                closeCitySelector();
                showToast(`City set to: ${selectedCity}`, 'success');
            } else {
                showToast('Please select or enter a city', 'error');
            }
        }

        // Step navigation
        function nextStep(step) {
            console.log(`Moving from step ${currentStep} to step ${step}`);
            
            // Validate current step
            if (!validateStep(currentStep)) {
                showToast('Please fill in all required fields correctly.', 'error');
                return false;
            }
            
            // Save current step data
            saveStepData(currentStep);
            
            // Hide current step
            document.getElementById(`step${currentStep}`).classList.add('hidden');
            document.getElementById(`step${currentStep}`).classList.remove('active');
            
            // Show next step
            document.getElementById(`step${step}`).classList.remove('hidden');
            document.getElementById(`step${step}`).classList.add('active');
            
            // Update progress indicators
            currentStep = step;
            updateProgressIndicators();
            
            // Update review data if step 4
            if (step === 4) {
                populateReviewData();
            }
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            return true;
        }

        function previousStep(step) {
            console.log(`Moving back to step ${step}`);
            
            // Hide current step
            document.getElementById(`step${currentStep}`).classList.add('hidden');
            document.getElementById(`step${currentStep}`).classList.remove('active');
            
            // Show previous step
            document.getElementById(`step${step}`).classList.remove('hidden');
            document.getElementById(`step${step}`).classList.add('active');
            
            // Update progress indicators
            currentStep = step;
            updateProgressIndicators();
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            return true;
        }

        function updateProgressIndicators() {
            // Reset all
            for (let i = 1; i <= 4; i++) {
                const dot = document.getElementById(`stepDot${i}`);
                const indicator = document.getElementById(`step${i}Indicator`);
                
                if (dot) {
                    dot.classList.remove('active', 'completed');
                    indicator.classList.remove('active', 'completed');
                }
            }
            
            // Mark current and previous steps
            for (let i = 1; i <= currentStep; i++) {
                const dot = document.getElementById(`stepDot${i}`);
                const indicator = document.getElementById(`step${i}Indicator`);
                
                if (dot) {
                    if (i === currentStep) {
                        dot.classList.add('active');
                        indicator.classList.add('active');
                    } else {
                        dot.classList.add('completed');
                        indicator.classList.add('completed');
                    }
                }
            }
        }

        function validateStep(step) {
            let isValid = true;
            const errorFields = [];
            
            if (step === 1) {
                // Validate school information
                const requiredFields = [
                    { id: 'schoolName', name: 'School Name' },
                    { id: 'schoolType', name: 'School Type' },
                    { id: 'campusType', name: 'Campus Type' },
                    { id: 'curriculum', name: 'Curriculum' },
                    { id: 'country', name: 'Country' },
                    { id: 'state', name: 'State' },
                    { id: 'city', name: 'City' },
                    { id: 'schoolEmail', name: 'School Email' },
                    { id: 'phone', name: 'Phone Number' },
                    { id: 'address', name: 'Address' }
                ];
                
                requiredFields.forEach(field => {
                    const element = document.getElementById(field.id);
                    if (element && !element.value.trim()) {
                        element.classList.add('border-red-500');
                        errorFields.push(field.name);
                        isValid = false;
                    } else if (element) {
                        element.classList.remove('border-red-500');
                    }
                });
                
                // Validate email format
                const emailField = document.getElementById('schoolEmail');
                if (emailField && emailField.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(emailField.value)) {
                        emailField.classList.add('border-red-500');
                        errorFields.push('Valid School Email');
                        isValid = false;
                    }
                }
                
                if (errorFields.length > 0) {
                    showToast(`Missing: ${errorFields.join(', ')}`, 'error');
                }
            } else if (step === 2) {
                // Validate admin information
                const requiredFields = [
                    { id: 'adminFirstName', name: 'Admin First Name' },
                    { id: 'adminLastName', name: 'Admin Last Name' },
                    { id: 'adminEmail', name: 'Admin Email' },
                    { id: 'adminPhone', name: 'Admin Phone' },
                    { id: 'adminRole', name: 'Admin Role' },
                    { id: 'adminPassword', name: 'Admin Password' }
                ];
                
                requiredFields.forEach(field => {
                    const element = document.getElementById(field.id);
                    if (element && !element.value.trim()) {
                        element.classList.add('border-red-500');
                        errorFields.push(field.name);
                        isValid = false;
                    } else if (element) {
                        element.classList.remove('border-red-500');
                    }
                });
                
                // Validate email format
                const adminEmailField = document.getElementById('adminEmail');
                if (adminEmailField && adminEmailField.value) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(adminEmailField.value)) {
                        adminEmailField.classList.add('border-red-500');
                        errorFields.push('Valid Admin Email');
                        isValid = false;
                    }
                }
                
                // Validate password strength
                const password = document.getElementById('adminPassword')?.value;
                if (password && password.length < 8) {
                    document.getElementById('adminPassword').classList.add('border-red-500');
                    errorFields.push('Stronger Password (min 8 chars)');
                    isValid = false;
                }
                
                if (errorFields.length > 0) {
                    showToast(`Missing: ${errorFields.join(', ')}`, 'error');
                }
            } else if (step === 4) {
                // Validate terms agreement
                const termsAgreed = document.getElementById('termsAgreement')?.checked;
                if (!termsAgreed) {
                    document.getElementById('termsError').textContent = 'You must agree to the terms and conditions';
                    document.getElementById('termsError').classList.remove('hidden');
                    isValid = false;
                } else {
                    document.getElementById('termsError').classList.add('hidden');
                }
            }
            
            return isValid;
        }

        function saveStepData(step) {
            if (step === 1) {
                formData.school = {
                    name: document.getElementById('schoolName')?.value || '',
                    type: document.getElementById('schoolType')?.value || '',
                    campusType: document.getElementById('campusType')?.value || '',
                    curriculum: document.getElementById('curriculum')?.value || '',
                    country: document.getElementById('country')?.value || '',
                    state: document.getElementById('state')?.value || '',
                    city: document.getElementById('city')?.value || '',
                    postalCode: document.getElementById('postalCode')?.value || '',
                    address: document.getElementById('address')?.value || '',
                    email: document.getElementById('schoolEmail')?.value || '',
                    phone: document.getElementById('phone')?.value || '',
                    description: document.getElementById('description')?.value || '',
                    establishmentYear: document.getElementById('establishmentYear')?.value || '',
                    studentCount: parseInt(document.getElementById('studentCount')?.value) || 500,
                    teacherCount: parseInt(document.getElementById('teacherCount')?.value) || 50,
                    classCount: parseInt(document.getElementById('classCount')?.value) || 20
                };
            } else if (step === 2) {
                formData.admin = {
                    firstName: document.getElementById('adminFirstName')?.value || '',
                    lastName: document.getElementById('adminLastName')?.value || '',
                    email: document.getElementById('adminEmail')?.value || '',
                    phone: document.getElementById('adminPhone')?.value || '',
                    position: document.getElementById('adminPosition')?.value || '',
                    role: document.getElementById('adminRole')?.value || '',
                    password: document.getElementById('adminPassword')?.value || '',
                    require2FA: document.getElementById('require2FA')?.checked || false,
                    forcePasswordChange: document.getElementById('forcePasswordChange')?.checked || false,
                    sendWelcomeEmail: document.getElementById('sendWelcomeEmail')?.checked || true,
                    notes: document.getElementById('adminNotes')?.value || ''
                };
            }
        }

        function updateSlugPreview() {
            const name = document.getElementById('schoolName')?.value;
            if (name) {
                let slug = name.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .trim();
                
                // Add timestamp to ensure uniqueness
                slug = slug.substring(0, 30);
                document.getElementById('slugPreview').textContent = slug;
            }
        }

        function updateCapacityEstimate() {
            const students = parseInt(document.getElementById('studentCount')?.value) || 0;
            const teachers = parseInt(document.getElementById('teacherCount')?.value) || 0;
            
            if (teachers > 0) {
                const ratio = Math.round(students / teachers);
                document.getElementById('studentTeacherRatio').textContent = `${ratio}:1`;
            }
        }

        function selectPlan(planSlug, planId, planPrice, studentLimit) {
            console.log('Selecting plan:', planSlug);
            
            // Update selected plan
            selectedPlan.slug = planSlug;
            selectedPlan.id = planId;
            selectedPlan.price = planPrice;
            
            // Get plan name and features from the selected card
            const selectedCard = document.getElementById(`planCard${planSlug}`);
            if (selectedCard) {
                selectedPlan.name = selectedCard.querySelector('h3')?.textContent || planSlug;
                // Extract features from the list items
                const features = [];
                selectedCard.querySelectorAll('ul li span').forEach(span => {
                    features.push(span.textContent);
                });
                selectedPlan.features = features;
            }
            
            // Update hidden inputs
            document.getElementById('planId').value = planId;
            document.getElementById('planSlug').value = planSlug;
            
            // Update UI
            document.querySelectorAll('.plan-card').forEach(card => {
                card.classList.remove('selected');
                const indicator = card.querySelector('.rounded-full');
                if (indicator) {
                    indicator.classList.remove('border-indigo-600', 'bg-indigo-600');
                    indicator.classList.add('border-slate-300');
                }
            });
            
            if (selectedCard) {
                selectedCard.classList.add('selected');
                const indicator = selectedCard.querySelector('.rounded-full');
                if (indicator) {
                    indicator.classList.add('border-indigo-600', 'bg-indigo-600');
                    indicator.classList.remove('border-slate-300');
                }
            }
            
            // Update estimated costs
            updateEstimatedCosts();
        }

        function updateEstimatedCosts() {
            const price = selectedPlan.price || 49.99;
            const billingCycle = document.querySelector('input[name="billing_cycle"]:checked')?.value || 'monthly';
            
            let monthlyCost = price;
            let yearlyCost = price * 12;
            
            // If yearly plan price exists in database, use it
            const selectedPlanCard = document.getElementById(`planCard${selectedPlan.slug}`);
            if (selectedPlanCard) {
                const yearlyText = selectedPlanCard.querySelector('p.text-xs.text-slate-400')?.textContent;
                if (yearlyText && yearlyText.includes('₦')) {
                    const yearlyMatch = yearlyText.match(/₦([\d,]+\.?\d*)/);
                    if (yearlyMatch) {
                        yearlyCost = parseFloat(yearlyMatch[1].replace(/,/g, ''));
                    }
                }
            }
            
            document.getElementById('estimatedCost').textContent = `₦${monthlyCost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            document.getElementById('estimatedYearlyCost').textContent = `₦${yearlyCost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        }

        function generatePassword() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let password = '';
            
            // Ensure at least one of each required character type
            password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.charAt(Math.floor(Math.random() * 26));
            password += 'abcdefghijklmnopqrstuvwxyz'.charAt(Math.floor(Math.random() * 26));
            password += '0123456789'.charAt(Math.floor(Math.random() * 10));
            password += '!@#$%^&*'.charAt(Math.floor(Math.random() * 8));
            
            // Fill remaining characters
            for (let i = 4; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            // Shuffle the password
            password = password.split('').sort(() => 0.5 - Math.random()).join('');
            
            const passwordInput = document.getElementById('adminPassword');
            if (passwordInput) {
                passwordInput.value = password;
                checkPasswordStrength();
            }
        }

        function checkPasswordStrength() {
            const password = document.getElementById('adminPassword')?.value || '';
            const strengthBar = document.getElementById('passwordStrengthBar');
            const strengthText = document.getElementById('passwordStrengthText');
            
            let strength = 0;
            let messages = [];
            
            // Length check
            if (password.length >= 8) strength += 25;
            else messages.push('At least 8 characters');
            
            // Upper case check
            if (/[A-Z]/.test(password)) strength += 25;
            else messages.push('One uppercase letter');
            
            // Lower case check
            if (/[a-z]/.test(password)) strength += 25;
            else messages.push('One lowercase letter');
            
            // Number check
            if (/[0-9]/.test(password)) strength += 15;
            else messages.push('One number');
            
            // Special character check
            if (/[^A-Za-z0-9]/.test(password)) strength += 10;
            else messages.push('One special character');
            
            // Set strength bar
            if (strengthBar) {
                strengthBar.style.width = `${strength}%`;
                
                if (strength < 50) {
                    strengthBar.className = 'h-full bg-red-500';
                    strengthText.textContent = 'Weak';
                    strengthText.className = 'text-xs font-medium text-red-600';
                } else if (strength < 80) {
                    strengthBar.className = 'h-full bg-amber-500';
                    strengthText.textContent = 'Good';
                    strengthText.className = 'text-xs font-medium text-amber-600';
                } else {
                    strengthBar.className = 'h-full bg-emerald-500';
                    strengthText.textContent = 'Strong';
                    strengthText.className = 'text-xs font-medium text-emerald-600';
                }
            }
        }

        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.type = field.type === 'password' ? 'text' : 'password';
            }
        }

        function previewLogo(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validate file size
                if (file.size > 5 * 1024 * 1024) {
                    showToast('File size must be less than 5MB', 'error');
                    input.value = '';
                    return;
                }
                
                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    showToast('Please upload a valid image file (JPEG, PNG, WebP)', 'error');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImage').src = e.target.result;
                    document.getElementById('fileName').textContent = file.name;
                    document.getElementById('fileSize').textContent = formatFileSize(file.size);
                    document.getElementById('logoPreview').classList.remove('hidden');
                    document.getElementById('logoUploadArea').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        }

        function removeLogo() {
            document.getElementById('logoFile').value = '';
            document.getElementById('logoPreview').classList.add('hidden');
            document.getElementById('logoUploadArea').style.display = 'block';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function populateReviewData() {
            // School details
            if (formData.school) {
                document.getElementById('reviewSchoolName').textContent = formData.school.name;
                document.getElementById('reviewSchoolType').textContent = 
                    document.querySelector(`#schoolType option[value="${formData.school.type}"]`)?.textContent || formData.school.type;
                document.getElementById('reviewCampusType').textContent = 
                    document.querySelector(`#campusType option[value="${formData.school.campusType}"]`)?.textContent || formData.school.campusType;
                document.getElementById('reviewCurriculum').textContent = 
                    document.querySelector(`#curriculum option[value="${formData.school.curriculum}"]`)?.textContent || formData.school.curriculum;
                document.getElementById('reviewLocation').textContent = 
                    `${formData.school.city}, ${formData.school.state}, ${formData.school.country}`;
                document.getElementById('reviewContact').textContent = 
                    `${formData.school.email} • ${formData.school.phone}`;
            }
            
            // Admin details
            if (formData.admin) {
                document.getElementById('reviewAdminName').textContent = 
                    `${formData.admin.firstName} ${formData.admin.lastName}`;
                document.getElementById('reviewAdminRole').textContent = 
                    document.querySelector(`#adminRole option[value="${formData.admin.role}"]`)?.textContent || formData.admin.role;
                document.getElementById('reviewAdminPosition').textContent = 
                    document.querySelector(`#adminPosition option[value="${formData.admin.position}"]`)?.textContent || formData.admin.position;
                document.getElementById('reviewAdminEmail').textContent = formData.admin.email;
                document.getElementById('reviewAdminPhone').textContent = formData.admin.phone;
            }
            
            // Subscription details
            document.getElementById('reviewPlan').textContent = selectedPlan.name;
            document.getElementById('reviewCost').textContent = 
                `₦${selectedPlan.price.toLocaleString('en-US', {minimumFractionDigits: 2})}/month`;
            
            const billingCycle = document.querySelector('input[name="billing_cycle"]:checked')?.value || 'monthly';
            document.getElementById('reviewBilling').textContent = 
                billingCycle.charAt(0).toUpperCase() + billingCycle.slice(1);
            
            // Features
            document.getElementById('reviewFeatures').textContent = 
                selectedPlan.features?.slice(0, 3).join(', ') + (selectedPlan.features?.length > 3 ? '...' : '');
            
            // Database name and URL
            const slug = document.getElementById('slugPreview').textContent;
            document.getElementById('reviewDatabaseName').textContent = `school_${Date.now()}`;
            document.getElementById('reviewAccessURL').textContent = 
                `/academixsuite/tenant/${slug}`;
        }

        // Form submission
        document.getElementById('provisionForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!validateStep(4)) {
                showToast('Please agree to the terms and conditions', 'error');
                return;
            }
            
            // Show loading modal
            showLoadingModal();
            
            try {
                const formDataObj = new FormData(this);
                
                const response = await fetch('process_provision.php', {
                    method: 'POST',
                    body: formDataObj,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessModal(result);
                } else {
                    showErrorModal('Provisioning Failed', result.message || 'An unknown error occurred');
                }
            } catch (error) {
                console.error('Error:', error);
                showErrorModal('Network Error', 'Unable to connect to server. Please check your internet connection.');
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
                let progress = 0;
                const interval = setInterval(() => {
                    progress += Math.random() * 10;
                    if (progress > 90) progress = 90;
                    
                    document.getElementById('progressBar').style.width = `${progress}%`;
                }, 500);
                
                // Store interval ID for cleanup
                modal.dataset.intervalId = interval;
            }
        }

        function hideLoadingModal() {
            const modal = document.getElementById('loadingModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
                
                // Clear progress bar animation
                const intervalId = modal.dataset.intervalId;
                if (intervalId) {
                    clearInterval(intervalId);
                    document.getElementById('progressBar').style.width = '0%';
                }
            }
        }

        function showSuccessModal(result) {
            const modal = document.getElementById('successModal');
            const message = document.getElementById('successMessage');
            const schoolURL = document.getElementById('successSchoolURL');
            const adminEmail = document.getElementById('successAdminEmail');
            
            if (modal && message && schoolURL && adminEmail) {
                message.textContent = result.message || `"${formData.school?.name}" has been successfully provisioned and is ready to use.`;
                schoolURL.textContent = result.school_url || window.location.origin + '/academixsuite/tenant/' + result.school_slug;
                adminEmail.textContent = result.admin_email || formData.admin?.email;
                
                modal.classList.remove('hidden');
            }
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

        function copySchoolURL() {
            const url = document.getElementById('successSchoolURL')?.textContent;
            if (url) {
                navigator.clipboard.writeText(url).then(() => {
                    showToast('School URL copied to clipboard!', 'success');
                });
            }
        }

        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    container.removeChild(toast);
                }, 300);
            }, 5000);
        }

        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && overlay) {
                const isOpen = sidebar.classList.contains('translate-x-0');
                sidebar.classList.toggle('translate-x-0', !isOpen);
                sidebar.classList.toggle('-translate-x-full', isOpen);
                overlay.classList.toggle('hidden', isOpen);
                overlay.classList.toggle('flex', !isOpen);
            }
        }
    </script>
</body>
</html>