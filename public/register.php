<?php
/**
 * Register School / Request Demo Page
 * Professional landing page for school registration and demo requests.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

$success = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'register';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'register') {
        $schoolName = trim($_POST['school_name'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $schoolType = trim($_POST['school_type'] ?? '');
        $agree = isset($_POST['agree']);

        if (empty($schoolName) || empty($fullName) || empty($email) || empty($phone)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!$agree) {
            $error = 'You must agree to the terms and conditions.';
        } else {
            $subject = "New School Registration: {$schoolName}";
            $message = "School Name: {$schoolName}\n"
                . "Contact Person: {$fullName}\n"
                . "Email: {$email}\n"
                . "Phone: {$phone}\n"
                . "School Type: {$schoolType}\n"
                . "Submitted: " . date('Y-m-d H:i:s');
            $headers = "From: {$email}\r\n" . "Reply-To: {$email}\r\n";
            $to = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'support@academixsuite.com';
            @mail($to, $subject, $message, $headers);

            $activeTab = 'register';
            $success = 'Thank you for registering your school! Our team will contact you within 24 hours.';
        }
    } elseif ($formType === 'demo') {
        $fullName = trim($_POST['demo_name'] ?? '');
        $email = trim($_POST['demo_email'] ?? '');
        $phone = trim($_POST['demo_phone'] ?? '');
        $schoolName = trim($_POST['demo_school'] ?? '');
        $role = trim($_POST['demo_role'] ?? '');
        $message_text = trim($_POST['demo_message'] ?? '');

        if (empty($fullName) || empty($email) || empty($phone) || empty($schoolName)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $subject = "Demo Request from {$schoolName}";
            $body = "Name: {$fullName}\n"
                . "Email: {$email}\n"
                . "Phone: {$phone}\n"
                . "School: {$schoolName}\n"
                . "Role: {$role}\n"
                . "Message: {$message_text}\n"
                . "Submitted: " . date('Y-m-d H:i:s');
            $headers = "From: {$email}\r\n" . "Reply-To: {$email}\r\n";
            $to = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'support@academixsuite.com';
            @mail($to, $subject, $body, $headers);

            $activeTab = 'demo';
            $success = 'Thank you for your interest! We will reach out to schedule your personalized demo shortly.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Your School | AcademixSuite</title>
    <meta name="description" content="Register your school on AcademixSuite or request a personalized demo. Join hundreds of schools across Nigeria.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --primary: #13452f;
            --primary-light: #1e6b4a;
            --primary-dark: #0a2a1d;
            --primary-muted: #e8f0ec;
            --neutral-50: #f8faf8;
            --neutral-100: #f0f4f0;
            --neutral-200: #dde3d8;
            --neutral-300: #bcc4b5;
            --neutral-400: #9aa58e;
            --neutral-500: #7a8670;
            --neutral-600: #5a6752;
            --neutral-700: #3a4733;
            --neutral-800: #22281f;
            --neutral-900: #141a12;
            --accent: #7DFF76;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--neutral-800);
            background: var(--neutral-50);
            -webkit-font-smoothing: antialiased;
        }

        .nav-blur {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(19, 69, 47, 0.08);
        }

        .hero-gradient {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 40%, var(--primary-light) 100%);
        }

        .hero-pattern {
            position: absolute;
            inset: 0;
            opacity: 0.04;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        .hero-glow {
            position: absolute;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(125, 255, 118, 0.06) 0%, transparent 70%);
            top: -150px;
            right: -150px;
            pointer-events: none;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            background: transparent;
            color: var(--neutral-500);
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(19, 69, 47, 0.2);
        }

        .tab-btn:hover:not(.active) {
            background: var(--neutral-100);
            color: var(--neutral-800);
        }

        .form-card {
            background: white;
            border: 1px solid var(--neutral-200);
            border-radius: 24px;
            padding: 2rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--neutral-50);
            border: 1px solid var(--neutral-200);
            border-radius: 12px;
            font-size: 0.875rem;
            color: var(--neutral-800);
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(19, 69, 47, 0.08);
        }

        .form-input::placeholder {
            color: var(--neutral-400);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235a6752' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px -8px rgba(19, 69, 47, 0.3);
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--neutral-100);
            color: var(--neutral-800);
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 12px;
            transition: all 0.2s ease;
            border: 1px solid var(--neutral-200);
        }

        .btn-secondary:hover {
            background: var(--neutral-200);
        }

        .benefit-card {
            background: white;
            border: 1px solid var(--neutral-200);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .benefit-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px -8px rgba(19, 69, 47, 0.1);
            transform: translateY(-4px);
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
        }

        .footer-section {
            background: var(--primary-dark);
            color: rgba(255, 255, 255, 0.7);
        }

        .footer-section a {
            color: rgba(255, 255, 255, 0.6);
            transition: color 0.2s ease;
        }

        .footer-section a:hover {
            color: var(--accent);
        }

        .footer-heading {
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 1.25rem;
        }

        @media (max-width: 640px) {
            .form-card {
                padding: 1.25rem;
                border-radius: 20px;
            }
            .tab-btn {
                padding: 0.625rem 1rem;
                font-size: 0.8125rem;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeInUp 0.4s ease-out;
        }

        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: var(--neutral-100);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--neutral-300);
            border-radius: 10px;
        }
    </style>
</head>

<body>

    <!-- NAV -->
    <nav class="nav-blur fixed top-0 left-0 right-0 z-50 h-16 flex items-center">
        <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between">
            <a href="./" class="flex items-center gap-2.5">
                <img src="../tenant/assets/images/logo.png" alt="AcademixSuite" class="h-8 w-auto">
            </a>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-neutral-600">
                <a href="./" class="hover:text-primary transition">Home</a>
                <a href="./pricing" class="hover:text-primary transition">Pricing</a>
                <a href="./features" class="hover:text-primary transition">Features</a>
                <a href="./contact" class="hover:text-primary transition">Contact</a>
            </div>
            <div class="flex items-center gap-3">
                <a href="../tenant/login.php" class="btn-secondary text-sm">Sign In</a>
                <a href="./register" class="btn-primary text-sm whitespace-nowrap">
                    <i class="fas fa-plus"></i>
                    Register School
                </a>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero-gradient relative pt-24 pb-16 md:pb-24 overflow-hidden">
        <div class="hero-pattern"></div>
        <div class="hero-glow"></div>
        <div class="w-full max-w-5xl mx-auto px-4 sm:px-6 py-12 md:py-16 relative z-10">
            <div class="text-center max-w-3xl mx-auto" data-aos="fade-up">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/10 text-white/80 text-xs font-medium mb-6">
                    <span class="w-2 h-2 rounded-full bg-[#7DFF76]"></span>
                    Join hundreds of schools across Nigeria
                </div>
                <h1 class="text-4xl sm:text-5xl md:text-6xl font-extrabold text-white leading-[1.1] mb-6 text-balance">
                    Take your school<br>
                    <span class="text-[#7DFF76]">to the next level</span>
                </h1>
                <p class="text-lg md:text-xl text-white/70 max-w-2xl mx-auto mb-8 font-light leading-relaxed">
                    Register your school on AcademixSuite or book a personalized demo to see how our platform streamlines operations, enhances learning, and connects your entire school community.
                </p>
            </div>
        </div>
    </section>

    <!-- FORM SECTION -->
    <section class="-mt-16 md:-mt-24 relative z-20 pb-16">
        <div class="w-full max-w-4xl mx-auto px-4 sm:px-6">

            <?php if ($success): ?>
                <div class="alert-success mb-6 animate-fade-in flex items-center gap-3">
                    <i class="fas fa-check-circle text-lg"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert-error mb-6 animate-fade-in flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-lg"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <div class="form-card" data-aos="fade-up">
                <!-- TAB HEADER -->
                <div class="flex gap-2 mb-8 p-1 bg-neutral-100 rounded-xl w-full sm:w-auto">
                    <button type="button" class="tab-btn flex-1 sm:flex-none <?php echo $activeTab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register')">
                        <i class="fas fa-school mr-2"></i>Register School
                    </button>
                    <button type="button" class="tab-btn flex-1 sm:flex-none <?php echo $activeTab === 'demo' ? 'active' : ''; ?>" onclick="switchTab('demo')">
                        <i class="fas fa-laptop mr-2"></i>Request Demo
                    </button>
                </div>

                <!-- REGISTER FORM -->
                <div id="form-register" class="<?php echo $activeTab !== 'register' ? 'hidden' : ''; ?> animate-fade-in">
                    <div class="text-center mb-8">
                        <h2 class="text-2xl font-bold text-neutral-800">Register Your School</h2>
                        <p class="text-sm text-neutral-500 mt-1">Fill in your details and our team will set up your school portal.</p>
                    </div>
                    <form method="POST" action="" class="space-y-5">
                        <input type="hidden" name="form_type" value="register">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">School Name <span class="text-red-500">*</span></label>
                                <input type="text" name="school_name" required placeholder="e.g. Greenfield Academy"
                                    class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">School Type</label>
                                <select name="school_type" class="form-input form-select">
                                    <option value="">Select type</option>
                                    <option value="day">Day School</option>
                                    <option value="boarding">Boarding School</option>
                                    <option value="mixed">Mixed Day & Boarding</option>
                                    <option value="nursery">Nursery / Kindergarten</option>
                                    <option value="primary">Primary School</option>
                                    <option value="secondary">Secondary School</option>
                                    <option value="international">International School</option>
                                    <option value="tutoring">Tutoring Center</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">Your Full Name <span class="text-red-500">*</span></label>
                                <input type="text" name="full_name" required placeholder="e.g. John Doe" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">Phone Number <span class="text-red-500">*</span></label>
                                <input type="tel" name="phone" required placeholder="e.g. 08012345678" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-neutral-700 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required placeholder="e.g. john@greenfield.edu.ng" class="form-input">
                        </div>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="agree" required class="mt-1 accent-primary" style="accent-color:var(--primary);width:16px;height:16px;">
                            <span class="text-sm text-neutral-600">I agree to the <a href="#" class="text-primary font-medium hover:underline">Terms of Service</a> and <a href="#" class="text-primary font-medium hover:underline">Privacy Policy</a>. I understand my school data will be handled securely.</span>
                        </label>

                        <button type="submit" class="btn-primary w-full text-base py-3">
                            <i class="fas fa-paper-plane"></i>
                            Register My School
                        </button>

                        <p class="text-xs text-neutral-400 text-center">Already have an account? <a href="../tenant/login.php" class="text-primary font-medium hover:underline">Sign in</a></p>
                    </form>
                </div>

                <!-- DEMO FORM -->
                <div id="form-demo" class="<?php echo $activeTab !== 'demo' ? 'hidden' : ''; ?> animate-fade-in">
                    <div class="text-center mb-8">
                        <h2 class="text-2xl font-bold text-neutral-800">Request a Personalized Demo</h2>
                        <p class="text-sm text-neutral-500 mt-1">See exactly how AcademixSuite works for your school.</p>
                    </div>
                    <form method="POST" action="" class="space-y-5">
                        <input type="hidden" name="form_type" value="demo">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">Your Full Name <span class="text-red-500">*</span></label>
                                <input type="text" name="demo_name" required placeholder="e.g. Jane Smith" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">Your Role</label>
                                <select name="demo_role" class="form-input form-select">
                                    <option value="">Select role</option>
                                    <option value="school_owner">School Owner / Proprietor</option>
                                    <option value="principal">Principal / Head of School</option>
                                    <option value="admin">Administrator</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="parent">Parent</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                                <input type="email" name="demo_email" required placeholder="e.g. jane@school.edu.ng" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 mb-1.5">Phone Number <span class="text-red-500">*</span></label>
                                <input type="tel" name="demo_phone" required placeholder="e.g. 08012345678" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-neutral-700 mb-1.5">School Name <span class="text-red-500">*</span></label>
                            <input type="text" name="demo_school" required placeholder="e.g. Greenfield Academy" class="form-input">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-neutral-700 mb-1.5">What would you like to see in the demo?</label>
                            <textarea name="demo_message" rows="4" placeholder="Tell us about your school's needs and what features interest you most..." class="form-input resize-none"></textarea>
                        </div>

                        <button type="submit" class="btn-primary w-full text-base py-3">
                            <i class="fas fa-calendar-check"></i>
                            Book My Free Demo
                        </button>

                        <p class="text-xs text-neutral-400 text-center">No commitment required. Demo sessions are typically 30 minutes.</p>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- BENEFITS -->
    <section class="py-16 md:py-20 bg-white border-t border-neutral-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-12">
                <span class="text-xs font-bold uppercase tracking-[0.08em] text-primary">Why AcademixSuite</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-800 mt-3 mb-4 text-balance">
                    Everything your school needs in one platform
                </h2>
                <p class="text-neutral-500 text-sm">From administration to academics, we help you run your school efficiently.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <div class="benefit-card" data-aos="fade-up">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mb-4">
                        <i class="fas fa-users-gear text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 text-sm mb-2">Student Management</h3>
                    <p class="text-xs text-neutral-500">Admissions, records, attendance, and academic tracking all in one place.</p>
                </div>
                <div class="benefit-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mb-4">
                        <i class="fas fa-calculator text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 text-sm mb-2">Fee & Billing</h3>
                    <p class="text-xs text-neutral-500">Automated invoicing, payment tracking, receipts, and financial reports.</p>
                </div>
                <div class="benefit-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mb-4">
                        <i class="fas fa-chart-simple text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 text-sm mb-2">Digital Gradebook</h3>
                    <p class="text-xs text-neutral-500">Record scores, compute averages, and publish results to parents instantly.</p>
                </div>
                <div class="benefit-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mb-4">
                        <i class="fas fa-calendar-days text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 text-sm mb-2">Timetable Management</h3>
                    <p class="text-xs text-neutral-500">Create and manage class schedules, avoid clashes, and share with everyone.</p>
                </div>
                <div class="benefit-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mb-4">
                        <i class="fas fa-message text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 text-sm mb-2">Communication Portal</h3>
                    <p class="text-xs text-neutral-500">Direct messaging between teachers, parents, and administrators.</p>
                </div>
                <div class="benefit-card" data-aos="fade-up" data-aos-delay="250">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mb-4">
                        <i class="fas fa-shield-halved text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 text-sm mb-2">Secure & Reliable</h3>
                    <p class="text-xs text-neutral-500">Your school data is encrypted, backed up, and hosted on secure infrastructure.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-16 md:py-20">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <span class="text-xs font-bold uppercase tracking-[0.08em] text-primary">FAQ</span>
                <h2 class="text-3xl font-extrabold text-neutral-800 mt-3">Common questions</h2>
            </div>
            <div class="space-y-0 divide-y divide-neutral-200">
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>How long does it take to set up my school?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">Most schools are fully set up within 24-48 hours after registration. Our team handles the technical setup so you can start using the platform immediately.</p>
                </details>
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>Is there a free trial available?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">Yes! We offer a free trial period so you can explore all features before committing. No credit card required.</p>
                </details>
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>Can I import existing student data?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">Absolutely. We provide data migration support to help you import student records, staff information, and academic history from your existing system.</p>
                </details>
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>What support do you provide after setup?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">We offer email and phone support, video tutorials, documentation, and dedicated onboarding assistance to ensure your team gets the most out of the platform.</p>
                </details>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="hero-gradient relative overflow-hidden py-16 md:py-20">
        <div class="hero-pattern"></div>
        <div class="max-w-3xl mx-auto px-4 text-center relative z-10">
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mb-4 text-balance" data-aos="fade-up">
                Ready to transform your school?
            </h2>
            <p class="text-white/70 text-base md:text-lg mb-8 max-w-lg mx-auto" data-aos="fade-up" data-aos-delay="50">
                Join hundreds of schools already using AcademixSuite to streamline operations and improve learning outcomes.
            </p>
            <div class="flex flex-wrap justify-center gap-4" data-aos="fade-up" data-aos-delay="100">
                <a href="?tab=register" class="inline-flex items-center gap-2 bg-white text-primary font-bold px-8 py-3.5 rounded-xl hover:bg-white/90 transition shadow-lg">
                    Register Now
                    <i class="fas fa-arrow-right text-sm"></i>
                </a>
                <a href="?tab=demo" class="inline-flex items-center gap-2 bg-white/10 text-white font-semibold px-8 py-3.5 rounded-xl border border-white/20 hover:bg-white/20 transition">
                    <i class="fas fa-laptop"></i>
                    Request Demo
                </a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer-section">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-16">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-8 lg:gap-12">
                <div class="sm:col-span-2 lg:col-span-2">
                    <img src="../tenant/assets/images/logo.png" alt="AcademixSuite" class="h-8 w-auto mb-4 brightness-0 invert">
                    <p class="text-sm leading-relaxed max-w-sm">
                        A comprehensive school management platform helping educational institutions streamline operations and enhance academic performance across Nigeria.
                    </p>
                    <div class="flex gap-3 mt-6">
                        <a href="#" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-accent hover:text-primary-dark transition text-sm"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-accent hover:text-primary-dark transition text-sm"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-accent hover:text-primary-dark transition text-sm"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-accent hover:text-primary-dark transition text-sm"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div>
                    <h4 class="footer-heading">Platform</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="../features/">Student Management</a></li>
                        <li><a href="../features/">Attendance</a></li>
                        <li><a href="../features/">Fee & Billing</a></li>
                        <li><a href="../features/">Gradebook</a></li>
                        <li><a href="../features/">Timetable</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="footer-heading">Resources</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="#">Help Center</a></li>
                        <li><a href="#">Documentation</a></li>
                        <li><a href="#">Video Tutorials</a></li>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Blog</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="footer-heading">Company</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Pricing</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Contact</a></li>
                        <li><a href="./register" class="text-white/80 font-semibold">Register School</a></li>
                    </ul>
                </div>
            </div>
            <div class="mt-12 pt-8 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4 text-xs text-white/40">
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="#" class="hover:text-white/60 transition">Terms of Service</a>
                    <a href="#" class="hover:text-white/60 transition">Privacy Policy</a>
                    <a href="#" class="hover:text-white/60 transition">Data Security</a>
                </div>
                <span>&copy; <?php echo date('Y'); ?> AcademixSuite. All rights reserved.</span>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 600,
            once: true,
            easing: 'ease-out-cubic'
        });

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('[id^="form-"]').forEach(f => f.classList.add('hidden'));

            document.querySelector(`.tab-btn[onclick*="${tab}"]`).classList.add('active');
            document.getElementById(`form-${tab}`).classList.remove('hidden');

            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);

            document.getElementById(`form-${tab}`).querySelector('input')?.focus();
        }

        document.querySelectorAll('details summary').forEach(s => {
            s.addEventListener('click', function(e) {
                const icon = this.querySelector('i');
                setTimeout(() => {
                    icon.classList.toggle('fa-plus', !this.closest('details').open);
                    icon.classList.toggle('fa-minus', this.closest('details').open);
                }, 50);
            });
        });
    </script>
</body>
</html>
