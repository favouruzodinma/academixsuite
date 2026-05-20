<?php

/**
 * School Discovery Portal - Find Schools to Enroll Your Child
 * Professional design with refined typography and spacing.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/school_discovery.log');

if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

$searchQuery = $_GET['search'] ?? '';
$state = $_GET['state'] ?? '';
$city = $_GET['city'] ?? '';
$curriculum = $_GET['curriculum'] ?? '';
$schoolType = $_GET['type'] ?? '';
$minFee = isset($_GET['min_fee']) && $_GET['min_fee'] !== '' ? floatval($_GET['min_fee']) : null;
$maxFee = isset($_GET['max_fee']) && $_GET['max_fee'] !== '' ? floatval($_GET['max_fee']) : null;
$sortBy = $_GET['sort'] ?? 'name';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$states = [];
$cities = [];
$curriculums = [];
$schoolTypes = [];

try {
    $db = Database::getPlatformConnection();

    $queryParams = [];
    $whereConditions = ["s.status IN ('active', 'trial')"];

    if (!empty($searchQuery)) {
        $whereConditions[] = "(s.name LIKE ? OR s.description LIKE ? OR s.city LIKE ? OR s.state LIKE ?)";
        $searchTerm = "%{$searchQuery}%";
        $queryParams = array_merge($queryParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }

    if (!empty($state)) {
        $whereConditions[] = "s.state = ?";
        $queryParams[] = $state;
    }

    if (!empty($city)) {
        $whereConditions[] = "s.city = ?";
        $queryParams[] = $city;
    }

    if (!empty($curriculum)) {
        $whereConditions[] = "s.curriculum = ?";
        $queryParams[] = $curriculum;
    }

    if (!empty($schoolType)) {
        $whereConditions[] = "s.school_type = ?";
        $queryParams[] = $schoolType;
    }

    if ($minFee !== null) {
        $whereConditions[] = "(s.fee_range_to >= ? OR s.fee_range_from >= ?)";
        $queryParams[] = $minFee;
        $queryParams[] = $minFee;
    }

    if ($maxFee !== null) {
        $whereConditions[] = "(s.fee_range_from <= ? OR s.fee_range_to <= ?)";
        $queryParams[] = $maxFee;
        $queryParams[] = $maxFee;
    }

    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

    $countQuery = "SELECT COUNT(*) as total FROM schools s {$whereClause}";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($queryParams);
    $totalCount = $countStmt->fetch()['total'] ?? 0;
    $totalPages = ceil($totalCount / $limit);

    $sortOptions = [
        'name' => 's.name ASC',
        'name_desc' => 's.name DESC',
        'rating' => 's.avg_rating DESC',
        'newest' => 's.created_at DESC',
        'oldest' => 's.created_at ASC',
        'fee_low' => 's.fee_range_from ASC',
        'fee_high' => 's.fee_range_to DESC',
        'popular' => 's.total_reviews DESC'
    ];
    $orderBy = $sortOptions[$sortBy] ?? 's.name ASC';

    $schoolsQuery = "
        SELECT 
            s.*, 
            p.name as plan_name,
            (SELECT COUNT(*) FROM school_admins sa WHERE sa.school_id = s.id) as admin_count,
            (SELECT GROUP_CONCAT(DISTINCT c.city) FROM schools c WHERE c.state = s.state AND c.status IN ('active', 'trial') LIMIT 5) as other_cities_in_state
        FROM schools s 
        LEFT JOIN plans p ON s.plan_id = p.id 
        {$whereClause}
        ORDER BY {$orderBy}
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $db->prepare($schoolsQuery);
    $stmt->execute($queryParams);
    $schools = $stmt->fetchAll();

    $statesStmt = $db->query("
        SELECT DISTINCT state 
        FROM schools 
        WHERE state IS NOT NULL AND state != '' AND status IN ('active', 'trial')
        ORDER BY state
    ");
    $states = $statesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    if ($state) {
        $citiesStmt = $db->prepare("
            SELECT DISTINCT city 
            FROM schools 
            WHERE city IS NOT NULL AND city != '' AND state = ? AND status IN ('active', 'trial')
            ORDER BY city
        ");
        $citiesStmt->execute([$state]);
        $cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } else {
        $citiesStmt = $db->query("
            SELECT DISTINCT city 
            FROM schools 
            WHERE city IS NOT NULL AND city != '' AND status IN ('active', 'trial')
            ORDER BY city
            LIMIT 50
        ");
        $cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    $curriculumsStmt = $db->query("
        SELECT DISTINCT curriculum 
        FROM schools 
        WHERE curriculum IS NOT NULL AND curriculum != '' AND status IN ('active', 'trial')
        ORDER BY curriculum
    ");
    $curriculums = $curriculumsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $schoolTypesStmt = $db->query("
        SELECT DISTINCT school_type 
        FROM schools 
        WHERE school_type IS NOT NULL AND school_type != '' AND status IN ('active', 'trial')
        ORDER BY school_type
    ");
    $schoolTypes = $schoolTypesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $feeRangesStmt = $db->query("
        SELECT 
            MIN(fee_range_from) as min_fee,
            MAX(fee_range_to) as max_fee
        FROM schools 
        WHERE status IN ('active', 'trial') 
        AND fee_range_from > 0
    ");
    $feeRanges = $feeRangesStmt->fetch();
    $globalMinFee = $feeRanges['min_fee'] ?? 0;
    $globalMaxFee = $feeRanges['max_fee'] ?? 1000000;
} catch (Exception $e) {
    error_log("Database error in school discovery: " . $e->getMessage());
    $schools = [];
    $states = [];
    $cities = [];
    $curriculums = [];
    $schoolTypes = [];
    $totalPages = 1;
    $globalMinFee = 0;
    $globalMaxFee = 1000000;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Find a School | AcademixSuite</title>
    <meta name="description" content="Browse verified schools across Nigeria. Compare fees, read parent reviews, and find the perfect school for your child.">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(function_exists('academix_logo_url') ? academix_logo_url() : '/tenant/assets/images/logo.png'); ?>">

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
            -moz-osx-font-smoothing: grayscale;
        }

        .text-balance { text-wrap: balance; }

        .nav-blur {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
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
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(125, 255, 118, 0.06) 0%, transparent 70%);
            top: -200px;
            right: -200px;
            pointer-events: none;
        }

        .hero-glow-2 {
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(125, 255, 118, 0.04) 0%, transparent 70%);
            bottom: -150px;
            left: -100px;
            pointer-events: none;
        }

        .search-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .search-container:focus-within {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(125, 255, 118, 0.4);
            box-shadow: 0 0 0 4px rgba(125, 255, 118, 0.1);
        }

        .school-card {
            background: #ffffff;
            border: 1px solid var(--neutral-200);
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        .school-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: 0 32px 48px -16px rgba(19, 69, 47, 0.15);
        }

        .card-image-wrap {
            position: relative;
            height: 180px;
            overflow: hidden;
            background: var(--primary-muted);
        }

        .card-image-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-muted) 0%, #d4e3da 100%);
        }

        .card-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .school-card:hover .card-image-wrap img {
            transform: scale(1.05);
        }

        .card-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .card-badge-premium {
            background: var(--neutral-800);
            color: white;
        }

        .card-badge-new {
            background: #d4a853;
            color: var(--neutral-900);
        }

        .card-badge-popular {
            background: var(--primary);
            color: white;
        }

        .card-fave {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(4px);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--neutral-500);
            transition: all 0.2s ease;
        }

        .card-fave:hover {
            background: white;
            color: #e74c3c;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--neutral-200);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px -8px rgba(19, 69, 47, 0.1);
        }

        .filter-panel {
            background: white;
            border: 1px solid var(--neutral-200);
            border-radius: 20px;
            padding: 1.5rem;
        }

        .filter-select {
            width: 100%;
            padding: 0.625rem 1rem;
            background: var(--neutral-50);
            border: 1px solid var(--neutral-200);
            border-radius: 12px;
            font-size: 0.875rem;
            color: var(--neutral-800);
            transition: all 0.2s ease;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235a6752' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(19, 69, 47, 0.08);
        }

        .filter-radio label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0;
            font-size: 0.875rem;
            cursor: pointer;
            transition: color 0.15s ease;
        }

        .filter-radio label:hover {
            color: var(--primary);
        }

        .filter-radio input[type="radio"] {
            accent-color: var(--primary);
            width: 16px;
            height: 16px;
        }

        .fee-input {
            width: 100%;
            padding: 0.625rem 0.75rem;
            background: var(--neutral-50);
            border: 1px solid var(--neutral-200);
            border-radius: 12px;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .fee-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(19, 69, 47, 0.08);
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            font-weight: 600;
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
            cursor: pointer;
        }

        .btn-secondary:hover {
            background: var(--neutral-200);
            border-color: var(--neutral-300);
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            color: var(--neutral-600);
            font-weight: 500;
            font-size: 0.8125rem;
            border-radius: 10px;
            transition: all 0.2s ease;
            border: none;
            background: transparent;
            cursor: pointer;
        }

        .btn-ghost:hover {
            background: var(--neutral-100);
            color: var(--neutral-800);
        }

        .tag-filter {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            background: var(--primary-muted);
            color: var(--primary);
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .tag-filter button {
            border: none;
            background: none;
            cursor: pointer;
            color: inherit;
            opacity: 0.6;
            padding: 0;
            line-height: 1;
        }

        .tag-filter button:hover {
            opacity: 1;
        }

        .results-header {
            background: white;
            border: 1px solid var(--neutral-200);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
        }

        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--neutral-500);
        }

        .pagination-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 0.75rem;
            border: 1px solid var(--neutral-200);
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--neutral-600);
            background: white;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-muted);
        }

        .pagination-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
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

        .rating-stars i {
            color: #d4a853;
        }

        .rating-stars i:last-child,
        .rating-stars .far.fa-star {
            color: var(--neutral-300);
        }

        @media (max-width: 640px) {
            .school-card { border-radius: 16px; }
            .filter-panel { border-radius: 16px; padding: 1.25rem; }
            .card-image-wrap { height: 160px; }
            .stat-card { padding: 1rem; }
        }

        @media (min-width: 1024px) {
            .filter-panel { position: sticky; top: 88px; }
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--neutral-100); }
        ::-webkit-scrollbar-thumb { background: var(--neutral-300); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--neutral-400); }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .skeleton {
            background: linear-gradient(90deg, var(--neutral-100) 25%, var(--neutral-50) 50%, var(--neutral-100) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s ease-in-out infinite;
            border-radius: 8px;
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>

<body>

    <!-- NAV -->
    <nav class="nav-blur fixed top-0 left-0 right-0 z-50 h-16 flex items-center">
        <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between">
            <a href="./" class="flex items-center gap-2.5">
                <img src="<?php echo htmlspecialchars(function_exists('academix_logo_url') ? academix_logo_url() : '/tenant/assets/images/logo.png'); ?>" alt="AcademixSuite" class="h-8 w-auto">
            </a>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-neutral-600">
                <a href="#schools" class="hover:text-primary transition">Schools</a>
                <a href="#how-it-works" class="hover:text-primary transition">How It Works</a>
                <a href="#why-choose" class="hover:text-primary transition">Why Academix</a>
                <a href="#faq" class="hover:text-primary transition">FAQ</a>
            </div>
            <div class="flex items-center gap-3">
                <a href="./login.php" class="btn-ghost text-sm hidden sm:inline-flex">Sign In</a>
                <a href="/register" class="btn-primary text-sm whitespace-nowrap">
                    <i class="fas fa-plus"></i>
                    Register School
                </a>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero-gradient relative min-h-[70vh] flex items-center pt-16 overflow-hidden">
        <div class="hero-pattern"></div>
        <div class="hero-glow"></div>
        <div class="hero-glow-2"></div>

        <div class="w-full max-w-5xl mx-auto px-4 sm:px-6 py-16 md:py-24 relative z-10">
            <div class="text-center max-w-3xl mx-auto" data-aos="fade-up">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/10 text-white/80 text-xs font-medium mb-6">
                    <span class="w-2 h-2 rounded-full bg-accent"></span>
                    <?php echo number_format($totalCount); ?> verified schools across Nigeria
                </div>

                <h1 class="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-extrabold text-white leading-[1.1] mb-6 text-balance">
                    Find the perfect school<br>
                    <span class="text-[#7DFF76]">for your child</span>
                </h1>

                <p class="text-lg md:text-xl text-white/70 max-w-xl mx-auto mb-10 font-light leading-relaxed">
                    Browse verified schools, compare fees, read parent reviews, and discover the best educational fit in your area.
                </p>

                <form method="GET" action="" id="searchForm" class="max-w-2xl mx-auto">
                    <div class="search-container flex items-center px-5 py-1.5 gap-3">
                        <i class="fas fa-search text-white/50 text-lg"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>"
                            placeholder="Search by school name, location, or curriculum..."
                            class="w-full py-3 bg-transparent text-white placeholder-white/50 text-sm focus:outline-none">
                        <button type="submit"
                            class="btn-primary whitespace-nowrap px-6 py-2.5 text-sm font-semibold bg-white text-primary hover:bg-white/90 hover:text-primary shadow-lg hover:shadow-xl">
                            Search
                        </button>
                    </div>
                </form>

                <div class="flex flex-wrap justify-center gap-6 md:gap-10 mt-10 text-sm" data-aos="fade-up" data-aos-delay="100">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-white"><?php echo number_format($totalCount); ?></div>
                        <div class="text-white/50 text-xs mt-1">Schools</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-white"><?php echo number_format(count($states) ?: 36); ?></div>
                        <div class="text-white/50 text-xs mt-1">States</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-white">4.8</div>
                        <div class="text-white/50 text-xs mt-1">Parent Rating</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-white"><?php echo number_format($totalCount > 0 ? array_sum(array_column($schools, 'total_reviews')) : 0); ?>+</div>
                        <div class="text-white/50 text-xs mt-1">Reviews</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- STATS STRIP -->
    <section class="bg-white border-b border-neutral-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
            <div class="flex items-center gap-4 text-sm text-neutral-500 overflow-x-auto no-scrollbar">
                <span class="font-medium text-neutral-700 whitespace-nowrap">Popular searches:</span>
                <a href="?curriculum=Nigerian" class="px-3 py-1.5 bg-neutral-100 rounded-full hover:bg-primary-muted hover:text-primary transition whitespace-nowrap">Nigerian Curriculum</a>
                <a href="?curriculum=British" class="px-3 py-1.5 bg-neutral-100 rounded-full hover:bg-primary-muted hover:text-primary transition whitespace-nowrap">British Curriculum</a>
                <a href="?type=day" class="px-3 py-1.5 bg-neutral-100 rounded-full hover:bg-primary-muted hover:text-primary transition whitespace-nowrap">Day Schools</a>
                <a href="?type=boarding" class="px-3 py-1.5 bg-neutral-100 rounded-full hover:bg-primary-muted hover:text-primary transition whitespace-nowrap">Boarding Schools</a>
                <a href="?type=mixed" class="px-3 py-1.5 bg-neutral-100 rounded-full hover:bg-primary-muted hover:text-primary transition whitespace-nowrap">Mixed Schools</a>
            </div>
        </div>
    </section>

    <!-- MAIN: FILTER + SCHOOLS -->
    <main id="schools" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-12">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

            <!-- FILTER SIDEBAR -->
            <aside class="lg:col-span-1">
                <div class="filter-panel">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="font-bold text-neutral-800">Filters</h2>
                        <button type="button" onclick="clearFilters()" class="text-xs text-primary font-medium hover:underline">Clear all</button>
                    </div>

                    <form method="GET" action="" id="filterForm" class="space-y-6">

                        <div>
                            <label class="section-title block mb-2.5"><i class="fas fa-location-dot text-primary mr-1.5"></i>Location</label>
                            <div class="space-y-2.5">
                                <select name="state" onchange="updateCityOptions(this.value); this.form.submit()" class="filter-select">
                                    <option value="">All states</option>
                                    <?php foreach ($states as $stateOption): ?>
                                        <option value="<?php echo htmlspecialchars($stateOption); ?>" <?php echo $state === $stateOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($stateOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="city" onchange="this.form.submit()" class="filter-select">
                                    <option value="">All cities</option>
                                    <?php foreach ($cities as $cityOption): ?>
                                        <option value="<?php echo htmlspecialchars($cityOption); ?>" <?php echo $city === $cityOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($cityOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="section-title block mb-2.5"><i class="fas fa-naira-sign text-primary mr-1.5"></i>Annual Fee Range</label>
                            <div class="flex gap-2">
                                <input type="number" name="min_fee" value="<?php echo $minFee !== null ? $minFee : ''; ?>" placeholder="Min" class="fee-input w-1/2">
                                <input type="number" name="max_fee" value="<?php echo $maxFee !== null ? $maxFee : ''; ?>" placeholder="Max" class="fee-input w-1/2">
                            </div>
                            <button type="button" onclick="applyFeeFilter()" class="btn-secondary w-full mt-2.5 text-xs">Apply Fee Range</button>
                        </div>

                        <div>
                            <label class="section-title block mb-2.5">Curriculum</label>
                            <div class="filter-radio space-y-0.5 max-h-40 overflow-y-auto pr-1">
                                <label><input type="radio" name="curriculum" value="" <?php echo empty($curriculum) ? 'checked' : ''; ?> onchange="this.form.submit()"><span>All</span></label>
                                <?php foreach ($curriculums as $curr): ?>
                                    <label><input type="radio" name="curriculum" value="<?php echo htmlspecialchars($curr); ?>" <?php echo $curriculum === $curr ? 'checked' : ''; ?> onchange="this.form.submit()"><span><?php echo htmlspecialchars($curr); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <label class="section-title block mb-2.5">School Type</label>
                            <div class="filter-radio space-y-0.5 max-h-40 overflow-y-auto pr-1">
                                <label><input type="radio" name="type" value="" <?php echo empty($schoolType) ? 'checked' : ''; ?> onchange="this.form.submit()"><span>All</span></label>
                                <?php foreach ($schoolTypes as $type): ?>
                                    <label><input type="radio" name="type" value="<?php echo htmlspecialchars($type); ?>" <?php echo $schoolType === $type ? 'checked' : ''; ?> onchange="this.form.submit()"><span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                        <input type="hidden" name="page" value="1">

                        <?php if (!empty($searchQuery) || !empty($state) || !empty($city) || !empty($curriculum) || !empty($schoolType) || $minFee || $maxFee): ?>
                            <div class="pt-4 flex flex-wrap gap-1.5 border-t border-neutral-200">
                                <?php if ($searchQuery): ?><span class="tag-filter"><i class="fas fa-search text-xs"></i> "<?php echo htmlspecialchars($searchQuery); ?>" <button type="button" onclick="removeFilter('search')">&times;</button></span><?php endif; ?>
                                <?php if ($state): ?><span class="tag-filter"><i class="fas fa-map-pin text-xs"></i> <?php echo htmlspecialchars($state); ?> <button type="button" onclick="removeFilter('state')">&times;</button></span><?php endif; ?>
                                <?php if ($city): ?><span class="tag-filter"><?php echo htmlspecialchars($city); ?> <button type="button" onclick="removeFilter('city')">&times;</button></span><?php endif; ?>
                                <?php if ($curriculum): ?><span class="tag-filter"><?php echo htmlspecialchars($curriculum); ?> <button type="button" onclick="removeFilter('curriculum')">&times;</button></span><?php endif; ?>
                                <?php if ($schoolType): ?><span class="tag-filter"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $schoolType))); ?> <button type="button" onclick="removeFilter('type')">&times;</button></span><?php endif; ?>
                                <?php if ($minFee): ?><span class="tag-filter">Min: ₦<?php echo number_format($minFee); ?> <button type="button" onclick="removeFilter('min_fee')">&times;</button></span><?php endif; ?>
                                <?php if ($maxFee): ?><span class="tag-filter">Max: ₦<?php echo number_format($maxFee); ?> <button type="button" onclick="removeFilter('max_fee')">&times;</button></span><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="btn-primary w-full">Apply Filters</button>
                    </form>
                </div>
            </aside>

            <!-- SCHOOL LIST -->
            <section class="lg:col-span-3">

                <!-- Results header -->
                <div class="results-header flex flex-wrap items-center justify-between gap-3 mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-neutral-800">
                            <?php echo number_format($totalCount); ?> school<?php echo $totalCount !== 1 ? 's' : ''; ?>
                            <?php if ($searchQuery): ?>
                                <span class="text-neutral-500 font-normal text-base">for &ldquo;<?php echo htmlspecialchars($searchQuery); ?>&rdquo;</span>
                            <?php endif; ?>
                        </h2>
                        <?php if ($state || $city): ?>
                            <p class="text-xs text-neutral-500 mt-0.5">
                                <i class="fas fa-map-pin text-primary/60 mr-1"></i>
                                <?php echo trim(($city ? htmlspecialchars($city) . ', ' : '') . ($state ? htmlspecialchars($state) : '')); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-neutral-500 font-medium">Sort:</label>
                        <select name="sort" onchange="updateSort(this.value)" class="filter-select py-2 pl-3 pr-8 text-sm w-auto">
                            <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                            <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                            <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                            <option value="fee_low" <?php echo $sortBy === 'fee_low' ? 'selected' : ''; ?>>Fee: Low to High</option>
                            <option value="fee_high" <?php echo $sortBy === 'fee_high' ? 'selected' : ''; ?>>Fee: High to Low</option>
                            <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                            <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                        </select>
                    </div>
                </div>

                <?php if (count($schools) > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                        <?php foreach ($schools as $school):
                            $badgeClass = 'card-badge-new';
                            $badgeText = '';
                            if ($school['plan_name'] === 'Enterprise' || $school['plan_name'] === 'Premium') {
                                $badgeClass = 'card-badge-premium';
                                $badgeText = 'Premium';
                            } elseif (strtotime($school['created_at']) > strtotime('-30 days')) {
                                $badgeClass = 'card-badge-new';
                                $badgeText = 'New';
                            } elseif (($school['total_reviews'] ?? 0) > 50) {
                                $badgeClass = 'card-badge-popular';
                                $badgeText = 'Popular';
                            }

                            $rating = $school['avg_rating'] ?? 0;
                            $fullStars = floor($rating);
                            $hasHalf = ($rating - $fullStars) >= 0.5;

                            $feeFrom = $school['fee_range_from'] ?? 0;
                            $feeTo = $school['fee_range_to'] ?? 0;
                            $avgFee = ($feeFrom + $feeTo) / 2;

                            $schoolInitial = substr($school['name'], 0, 1);
                            $schoolLogoPath = trim((string)($school['logo_path'] ?? ''));
                            $schoolCardLogo = $schoolLogoPath !== ''
                                ? (function_exists('school_logo_url') ? school_logo_url($school) : '/' . ltrim($schoolLogoPath, '/'))
                                : '';
                        ?>
                            <div class="school-card" data-aos="fade-up" data-aos-delay="<?php echo min(($loopIndex ?? 0) * 50, 300); ?>">
                                <div class="card-image-wrap">
                                    <?php if ($schoolCardLogo !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($schoolCardLogo); ?>" alt="<?php echo htmlspecialchars($school['name']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="card-image-placeholder">
                                            <span class="text-5xl font-bold text-primary/20"><?php echo htmlspecialchars($schoolInitial); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($badgeText): ?>
                                        <span class="card-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                                    <?php endif; ?>

                                    <button class="card-fave" title="Save to favorites" onclick="event.preventDefault()">
                                        <i class="far fa-heart"></i>
                                    </button>
                                </div>

                                <div class="p-5 pt-4 flex flex-col flex-1">
                                    <h3 class="font-bold text-neutral-800 text-base leading-snug mb-1"><?php echo htmlspecialchars($school['name']); ?></h3>

                                    <div class="flex items-center gap-1.5 text-xs text-neutral-500 mb-3">
                                        <i class="fas fa-map-pin text-primary/60 text-[10px]"></i>
                                        <span><?php echo htmlspecialchars($school['city'] . ', ' . $school['state']); ?></span>
                                    </div>

                                    <?php if ($rating > 0): ?>
                                        <div class="flex items-center gap-2 mb-3">
                                            <span class="rating-stars text-xs">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?php if ($i <= $fullStars): ?><i class="fas fa-star"></i>
                                                    <?php elseif ($i == $fullStars + 1 && $hasHalf): ?><i class="fas fa-star-half-alt"></i>
                                                    <?php else: ?><i class="far fa-star"></i>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </span>
                                            <span class="text-xs font-medium text-neutral-600"><?php echo number_format($rating, 1); ?></span>
                                            <span class="text-xs text-neutral-400">(<?php echo $school['total_reviews'] ?? 0; ?>)</span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex flex-wrap gap-1.5 mb-4">
                                        <?php if ($school['school_type']): ?>
                                            <span class="px-2.5 py-1 bg-neutral-100 rounded-md text-[11px] font-medium text-neutral-600"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $school['school_type']))); ?></span>
                                        <?php endif; ?>
                                        <?php if ($school['curriculum']): ?>
                                            <span class="px-2.5 py-1 bg-neutral-100 rounded-md text-[11px] font-medium text-neutral-600"><?php echo htmlspecialchars($school['curriculum']); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($avgFee > 0): ?>
                                        <div class="flex items-center justify-between py-2.5 px-3 bg-primary-muted rounded-xl mb-4">
                                            <span class="text-xs text-neutral-600 font-medium">Average fee</span>
                                            <span class="text-sm font-bold text-primary">₦<?php echo number_format($avgFee); ?><span class="text-xs font-normal text-primary/70">/yr</span></span>
                                        </div>
                                    <?php endif; ?>

                                    <a href="<?php echo htmlspecialchars(function_exists('school_portal_url') ? school_portal_url($school['slug'], '', true) : './school_profile.php?slug=' . urlencode($school['slug']), ENT_QUOTES, 'UTF-8'); ?>"
                                        class="btn-primary w-full mt-auto">
                                        View School
                                        <i class="fas fa-arrow-right text-xs"></i>
                                    </a>
                                </div>
                            </div>
                        <?php $loopIndex = ($loopIndex ?? 0) + 1; endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="mt-12 flex justify-center">
                            <nav class="flex items-center gap-2">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $page - 1]); ?>" class="pagination-btn"><i class="fas fa-chevron-left text-xs"></i></a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $i]); ?>"
                                        class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $page + 1]); ?>" class="pagination-btn"><i class="fas fa-chevron-right text-xs"></i></a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="bg-white rounded-2xl border border-neutral-200 p-12 md:p-16 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-neutral-100 flex items-center justify-center mx-auto mb-5">
                            <i class="fas fa-search text-2xl text-neutral-400"></i>
                        </div>
                        <h3 class="text-xl font-bold text-neutral-800 mb-2">No schools found</h3>
                        <p class="text-neutral-500 text-sm max-w-sm mx-auto mb-6">
                            We couldn&rsquo;t find any schools matching your criteria. Try adjusting your filters or search terms.
                        </p>
                        <button onclick="clearFilters()" class="btn-primary">Clear All Filters</button>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- HOW IT WORKS -->
    <section id="how-it-works" class="bg-white py-16 md:py-20 border-t border-neutral-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-12">
                <span class="section-title text-primary font-bold">How It Works</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-800 mt-3 mb-4 text-balance">
                    Find the right school in three simple steps
                </h2>
                <p class="text-neutral-500 text-sm">We make it easy to discover, compare, and connect with schools that match your needs.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-12">
                <div class="text-center" data-aos="fade-up">
                    <div class="w-14 h-14 rounded-xl bg-primary-muted flex items-center justify-center mx-auto mb-5">
                        <i class="fas fa-sliders text-xl text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 mb-2">1. Filter & Search</h3>
                    <p class="text-sm text-neutral-500 leading-relaxed">Browse schools by location, curriculum, fee range, and type. Use our smart filters to narrow down your options.</p>
                </div>
                <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                    <div class="w-14 h-14 rounded-xl bg-primary-muted flex items-center justify-center mx-auto mb-5">
                        <i class="fas fa-building-columns text-xl text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 mb-2">2. Compare Schools</h3>
                    <p class="text-sm text-neutral-500 leading-relaxed">View detailed profiles with fee info, facilities, parent reviews, and contact details side by side.</p>
                </div>
                <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                    <div class="w-14 h-14 rounded-xl bg-primary-muted flex items-center justify-center mx-auto mb-5">
                        <i class="fas fa-paper-plane text-xl text-primary"></i>
                    </div>
                    <h3 class="font-bold text-neutral-800 mb-2">3. Apply or Visit</h3>
                    <p class="text-sm text-neutral-500 leading-relaxed">Contact schools directly through the platform or submit applications online with just a few clicks.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- WHY CHOOSE -->
    <section id="why-choose" class="py-16 md:py-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-12">
                <span class="section-title text-primary font-bold">Why AcademixSuite</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-800 mt-3 mb-4 text-balance">
                    Built for parents who want the best
                </h2>
                <p class="text-neutral-500 text-sm">We are on a mission to make school discovery transparent, honest, and stress-free.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="stat-card" data-aos="fade-up">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-check-circle text-primary"></i>
                    </div>
                    <h4 class="font-bold text-neutral-800 text-sm mb-1">Verified Data</h4>
                    <p class="text-xs text-neutral-500">Every school is manually checked and verified before listing.</p>
                </div>
                <div class="stat-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-star text-primary"></i>
                    </div>
                    <h4 class="font-bold text-neutral-800 text-sm mb-1">Honest Reviews</h4>
                    <p class="text-xs text-neutral-500">Real parent reviews, no bots, no fake testimonials.</p>
                </div>
                <div class="stat-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-map-location-dot text-primary"></i>
                    </div>
                    <h4 class="font-bold text-neutral-800 text-sm mb-1">Local & Accurate</h4>
                    <p class="text-xs text-neutral-500">Fees, contacts, and facility info you can rely on.</p>
                </div>
                <div class="stat-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="w-10 h-10 rounded-lg bg-primary-muted flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-shield-halved text-primary"></i>
                    </div>
                    <h4 class="font-bold text-neutral-800 text-sm mb-1">Free to Use</h4>
                    <p class="text-xs text-neutral-500">School discovery is completely free for parents.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="hero-gradient relative overflow-hidden py-16 md:py-20">
        <div class="hero-pattern"></div>
        <div class="max-w-3xl mx-auto px-4 text-center relative z-10">
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mb-4 text-balance" data-aos="fade-up">
                Ready to find the perfect school?
            </h2>
            <p class="text-white/70 text-base md:text-lg mb-8 max-w-lg mx-auto" data-aos="fade-up" data-aos-delay="50">
                Join thousands of parents who have found the right school for their children through AcademixSuite.
            </p>
            <div class="flex flex-wrap justify-center gap-4" data-aos="fade-up" data-aos-delay="100">
                <a href="#schools" class="inline-flex items-center gap-2 bg-white text-primary font-bold px-8 py-3.5 rounded-xl hover:bg-white/90 transition shadow-lg">
                    Start Searching
                    <i class="fas fa-arrow-right text-sm"></i>
                </a>
                <a href="/register" class="inline-flex items-center gap-2 bg-white/10 text-white font-semibold px-8 py-3.5 rounded-xl border border-white/20 hover:bg-white/20 transition">
                    Register Your School
                </a>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="bg-white py-16 md:py-20 border-t border-neutral-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <span class="section-title text-primary font-bold">FAQ</span>
                <h2 class="text-3xl font-extrabold text-neutral-800 mt-3">Frequently asked questions</h2>
            </div>
            <div class="space-y-0 divide-y divide-neutral-200">
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>How are schools verified?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">Each school goes through a manual verification process where we confirm their physical address, contact information, and operational status before they are listed on our platform.</p>
                </details>
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>Is this service free for parents?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">Yes, browsing schools and reading reviews is completely free for parents. There is no charge to search, compare, or contact schools through our platform.</p>
                </details>
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>Can I submit a review for a school?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">Absolutely! We encourage parents to leave honest reviews about their experiences. All reviews are moderated to ensure authenticity and helpfulness.</p>
                </details>
                <details class="group py-5">
                    <summary class="flex items-center justify-between cursor-pointer font-semibold text-neutral-800 list-none">
                        <span>How can I list my school on AcademixSuite?</span>
                        <i class="fas fa-plus text-xs text-neutral-400 group-open:rotate-45 transition-transform"></i>
                    </summary>
                    <p class="mt-3 text-sm text-neutral-500 leading-relaxed">School owners and administrators can register their school by clicking the &ldquo;Register School&rdquo; button in the top navigation. Fill in your details, and our team will verify and activate your listing.</p>
                </details>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer-section">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-16">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-8 lg:gap-12">

                <div class="sm:col-span-2 lg:col-span-2">
                    <img src="<?php echo htmlspecialchars(function_exists('academix_logo_url') ? academix_logo_url() : '/tenant/assets/images/logo.png'); ?>" alt="AcademixSuite" class="h-8 w-auto mb-4 brightness-0 invert">
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
                        <li><a href="../features/student-management/" class="hover:text-white transition">Student Management</a></li>
                        <li><a href="../features/attendance-tracking/" class="hover:text-white transition">Attendance</a></li>
                        <li><a href="../features/fee-management/" class="hover:text-white transition">Fee & Billing</a></li>
                        <li><a href="../features/gradebook/" class="hover:text-white transition">Gradebook</a></li>
                        <li><a href="../features/timetable/" class="hover:text-white transition">Timetable</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-heading">Resources</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="../help-center/" class="hover:text-white transition">Help Center</a></li>
                        <li><a href="../documentation/" class="hover:text-white transition">Documentation</a></li>
                        <li><a href="../tutorials/" class="hover:text-white transition">Video Tutorials</a></li>
                        <li><a href="../faq/" class="hover:text-white transition">FAQ</a></li>
                        <li><a href="../blog/" class="hover:text-white transition">Blog</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-heading">Company</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="../about/" class="hover:text-white transition">About Us</a></li>
                        <li><a href="../pricing/" class="hover:text-white transition">Pricing</a></li>
                        <li><a href="../careers/" class="hover:text-white transition">Careers</a></li>
                        <li><a href="../contact/" class="hover:text-white transition">Contact</a></li>
                        <li><a href="/register" class="hover:text-white transition">Register School</a></li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 pt-8 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4 text-xs text-white/40">
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="../terms/" class="hover:text-white/60 transition">Terms of Service</a>
                    <a href="../privacy/" class="hover:text-white/60 transition">Privacy Policy</a>
                    <a href="../data-security/" class="hover:text-white/60 transition">Data Security</a>
                    <a href="../cookies/" class="hover:text-white/60 transition">Cookie Policy</a>
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

        function buildQueryString(params) {
            const url = new URLSearchParams(window.location.search);
            Object.keys(params).forEach(k => params[k] ? url.set(k, params[k]) : url.delete(k));
            return url.toString();
        }

        function updateSort(val) {
            const url = new URLSearchParams(window.location.search);
            url.set('sort', val);
            url.set('page', '1');
            window.location.search = url.toString();
        }

        function clearFilters() {
            window.location.href = window.location.pathname;
        }

        function removeFilter(name) {
            const url = new URLSearchParams(window.location.search);
            url.delete(name);
            url.set('page', '1');
            window.location.search = url.toString();
        }

        function applyFeeFilter() {
            const min = document.querySelector('input[name="min_fee"]').value;
            const max = document.querySelector('input[name="max_fee"]').value;
            const url = new URLSearchParams(window.location.search);
            if (min) url.set('min_fee', min); else url.delete('min_fee');
            if (max) url.set('max_fee', max); else url.delete('max_fee');
            url.set('page', '1');
            window.location.search = url.toString();
        }

        function updateCityOptions(state) {}

        document.querySelectorAll('details summary').forEach(summary => {
            summary.addEventListener('click', function(e) {
                const details = this.closest('details');
                const icon = this.querySelector('i');
                if (details.open) {
                    icon.classList.replace('fa-plus', 'fa-minus');
                }
            });
        });
    </script>
</body>
</html>
<?php
function buildQueryString($newParams = [])
{
    $params = $_GET;
    foreach ($newParams as $k => $v) $params[$k] = $v;
    return http_build_query($params);
}
?>
