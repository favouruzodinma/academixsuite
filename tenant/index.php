<?php

/**
 * School Discovery Portal - Find Schools to Enroll Your Child
 * Colour palette: #f3f6f0 (background), #13452f (primary), #22281f (dark)
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/school_discovery.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

// Load configuration
$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

// Initialize variables
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

// Get all states and cities from database
$states = [];
$cities = [];
$curriculums = [];
$schoolTypes = [];

try {
    $db = Database::getPlatformConnection();

    // Get all active schools with additional fields
    $queryParams = [];
    $whereConditions = ["s.status IN ('active', 'trial')"];

    // Build search conditions
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

    // Fee range filtering
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

    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM schools s {$whereClause}";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($queryParams);
    $totalCount = $countStmt->fetch()['total'] ?? 0;
    $totalPages = ceil($totalCount / $limit);

    // Sorting options
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

    // Get schools with pagination and additional data
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

    // Get unique values for filters
    $statesStmt = $db->query("
        SELECT DISTINCT state 
        FROM schools 
        WHERE state IS NOT NULL AND state != '' AND status IN ('active', 'trial')
        ORDER BY state
    ");
    $states = $statesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Get cities based on selected state
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

    // Get fee ranges for display
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
    <title>Find Schools | AcademixSuite - Nigerian School Discovery</title>

    <!-- Custom fonts: more grounded, less "corporate AI" -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* Custom palette: #f3f6f0 (background soft), #13452f (forest), #22281f (charcoal) */
        :root {
            --bg-soft: #f3f6f0;
            --primary-deep: #13452f;
            --primary-light: #2d6a4f;
            --dark-charcoal: #22281f;
            --accent-warm: #7DFF76;
        }

        body {
            background-color: var(--bg-soft);
            color: var(--dark-charcoal);
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        h1,
        h2,
        h3,
        .font-mono-head {
            font-family: 'Space Mono', monospace;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        /* Custom utility for primary deep */
        .bg-primary-deep {
            background-color: var(--primary-deep);
        }

        .text-primary-deep {
            color: var(--primary-deep);
        }

        .border-primary-deep {
            border-color: var(--primary-deep);
        }

        .bg-primary-light {
            background-color: var(--primary-light);
        }

        .text-primary-light {
            color: var(--primary-light);
        }

        .bg-dark-charcoal {
            background-color: var(--dark-charcoal);
        }

        .text-dark-charcoal {
            color: var(--dark-charcoal);
        }

        .bg-soft-bg {
            background-color: var(--bg-soft);
        }

        .accent-gold {
            color: var(--accent-warm);
        }

        .bg-accent-gold {
            background-color: var(--accent-warm);
        }

        /* Navigation: solid, not glassy — feels more robust */
        .solid-nav {
            background-color: #ffffffd9;
            backdrop-filter: blur(8px);
            border-bottom: 2px solid #13452f20;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
        }

        /* School card: textured border */
        .school-card {
            background-color: #ffffff;
            border: 1px solid #dde3d8;
            border-radius: 28px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 18px -8px rgba(0, 0, 0, 0.08);
        }

        .school-card:hover {
            transform: translateY(-6px);
            border-color: var(--primary-deep);
            box-shadow: 0 24px 38px -14px rgba(19, 69, 47, 0.18);
        }

        /* filter sidebar card */
        .filter-card {
            background-color: #ffffff;
            border-radius: 28px;
            border: 1px solid #dde3d8;
            padding: 1.5rem 1.2rem;
        }

        /* mobile-first spacing */
        @media (max-width: 640px) {
            .school-card {
                border-radius: 24px;
            }

            .filter-card {
                padding: 1.2rem 1rem;
                border-radius: 24px;
            }

            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            h1 {
                font-size: 2.2rem;
                line-height: 1.2;
            }
        }

        /* range slider style */
        .range-slider {
            -webkit-appearance: none;
            width: 100%;
            height: 6px;
            background: #dde3d8;
            border-radius: 10px;
            outline: none;
        }

        .range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            background: var(--primary-deep);
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
            cursor: pointer;
        }

        .range-slider::-moz-range-thumb {
            width: 22px;
            height: 22px;
            background: var(--primary-deep);
            border-radius: 50%;
            border: 3px solid white;
            cursor: pointer;
        }

        /* custom badge styles */
        .badge-premium {
            background-color: #22281f;
            color: white;
            font-weight: 600;
            padding: 0.3rem 0.9rem;
            border-radius: 40px;
            font-size: 0.7rem;
            letter-spacing: 0.02em;
        }

        .badge-new {
            background-color: #c79b5e;
            color: #22281f;
            font-weight: 600;
            padding: 0.3rem 0.9rem;
            border-radius: 40px;
            font-size: 0.7rem;
        }

        .badge-popular {
            background-color: #13452f;
            color: white;
            font-weight: 600;
            padding: 0.3rem 0.9rem;
            border-radius: 40px;
            font-size: 0.7rem;
        }

        .fee-badge {
            background: #22281f;
            color: white;
            font-weight: 600;
            padding: 0.3rem 0.9rem;
            border-radius: 40px;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
        }

        /* rating stars */
        .rating-stars i {
            color: #c79b5e;
            margin-right: 1px;
        }

        .rating-stars i:last-child {
            color: #d4d9d0;
        }

        /* faq */
        .faq-item {
            border-bottom: 1px solid #dde3d8;
        }

        .faq-question {
            font-weight: 600;
            color: var(--dark-charcoal);
        }

        /* map placeholder style */
        .map-placeholder {
            background-color: #e2e8dd;
            background-image: radial-gradient(circle at 20px 20px, #c0cbbc 2px, transparent 2px), radial-gradient(circle at 60px 80px, #c0cbbc 2px, transparent 2px);
            background-size: 60px 60px, 120px 120px;
            min-height: 200px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #13452f;
            font-weight: 600;
        }

        /* active filter tag */
        .filter-tag {
            background-color: #e2eee5;
            color: #13452f;
            padding: 0.2rem 0.8rem;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* custom scroll */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #e0e6db;
        }

        ::-webkit-scrollbar-thumb {
            background: #13452f;
            border-radius: 10px;
        }

        /* Newsletter form styling */
        .newsletter-input {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        .newsletter-input:focus {
            border-color: var(--accent-warm);
            outline: none;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .footer-link {
            transition: color 0.2s ease;
        }
        
        .footer-link:hover {
            color: var(--accent-warm);
        }
        
        .footer-heading {
            position: relative;
            display: inline-block;
        }
        
        .footer-heading:after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 2px;
            background-color: var(--accent-warm);
        }
    </style>
</head>

<body class="antialiased">

    <!-- navigation: solid, functional, mobile-optimized -->
    <nav class="solid-nav sticky top-0 z-30 w-full py-3 px-4 md:px-6 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <img src="./assets/images/logo.png" alt="AcademixSuite" class="h-10 w-auto">
        </div>

        <!-- mobile: hide nav links, keep essential -->
        <div class="hidden md:flex items-center space-x-8 text-sm font-semibold text-dark-charcoal/80">
            <a href="#search" class="hover:text-primary-deep transition">Find</a>
            <a href="#why-choose" class="hover:text-primary-deep transition">Why</a>
            <a href="#how-it-works" class="hover:text-primary-deep transition">How</a>
            <a href="#faq" class="hover:text-primary-deep transition">FAQ</a>
        </div>

        <div class="flex items-center gap-3">
            <a href="./login.php" class="hidden sm:inline-block text-sm font-bold text-dark-charcoal/80 hover:text-primary-deep transition">Login</a>
            <a href="../public/register.php" class="bg-dark-charcoal text-white px-5 py-2.5 rounded-full text-sm font-bold hover:bg-primary-deep transition shadow-md whitespace-nowrap">
                Register School
            </a>
        </div>
    </nav>

    <!-- HERO: robust, earthy -->
    <section class="relative pt-10 pb-16 md:pt-16 md:pb-24 px-4 overflow-hidden">
        <!-- subtle organic pattern -->
        <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 60 60%22 width=%2260%22 height=%2260%22><path d=%22M30 5 L55 20 L55 40 L30 55 L5 40 L5 20 Z%22 fill=%22%2313452f%22 opacity=%220.3%22/></svg>'); background-size: 40px;"></div>

        <div class="container max-w-6xl mx-auto text-center relative z-10">
            <div data-aos="fade-down" class="mb-5">
                <span class="bg-primary-deep/10 text-primary-deep px-4 py-1.5 rounded-full text-xs font-mono-head uppercase tracking-wide border border-primary-deep/20">
                    <?php echo number_format($totalCount); ?>+ verified schools
                </span>
            </div>
            <h1 class="text-4xl sm:text-5xl md:text-7xl font-mono-head text-dark-charcoal leading-[1.1] mb-6" data-aos="fade-up">
                find the school<br>that <span class="text-primary-deep underline decoration-accent-gold decoration-4">fits</span>
            </h1>
            <p class="text-base md:text-xl text-dark-charcoal/70 max-w-2xl mx-auto mb-10 font-light" data-aos="fade-up" data-aos-delay="80">
                Real parent reviews. Honest fee details. No generic listings.
            </p>

            <!-- compact search bar for mobile -->
            <div class="max-w-xl mx-auto" data-aos="fade-up" data-aos-delay="120">
                <form method="GET" action="" id="searchForm">
                    <div class="flex items-center bg-white rounded-full border border-primary-deep/20 pl-5 pr-2 py-1 shadow-sm">
                        <i class="fas fa-search text-primary-deep/60 mr-2"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="school name, location, curriculum..." class="w-full py-3 bg-transparent text-sm focus:outline-none">
                        <button type="submit" class="bg-primary-deep text-white px-5 py-2 rounded-full text-sm font-semibold hover:bg-dark-charcoal transition whitespace-nowrap">Go</button>
                    </div>
                </form>
            </div>

            <!-- quick stat row, mobile friendly -->
            <div class="flex flex-wrap justify-center gap-6 md:gap-12 mt-10 text-sm">
                <div><span class="font-mono-head text-2xl text-primary-deep"><?php echo number_format($totalCount); ?></span> <span class="text-dark-charcoal/60">schools</span></div>
                <div><span class="font-mono-head text-2xl text-primary-deep">36</span> <span class="text-dark-charcoal/60">states</span></div>
                <div><span class="font-mono-head text-2xl text-primary-deep">4.8</span> <span class="text-dark-charcoal/60">parent rating</span></div>
            </div>
        </div>
    </section>

    <!-- MAIN: filter + schools grid, mobile-first stacked -->
    <main class="container max-w-6xl mx-auto px-4 pb-16">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 md:gap-8">
            <!-- FILTER SIDEBAR - collapsible on mobile? We'll keep it as is but scrollable -->
            <aside class="lg:col-span-1">
                <div class="filter-card sticky top-24 max-h-[calc(100vh-100px)] overflow-y-auto p-5">
                    <div class="flex justify-between items-center mb-5">
                        <h3 class="font-mono-head text-dark-charcoal text-lg">filter</h3>
                        <button type="button" onclick="clearFilters()" class="text-xs text-primary-deep underline">clear all</button>
                    </div>

                    <form method="GET" action="" id="filterForm" class="space-y-6">
                        <!-- location -->
                        <div>
                            <label class="block text-xs font-bold text-dark-charcoal/70 mb-2 tracking-wide"><i class="fas fa-location-dot text-primary-deep mr-1"></i>state</label>
                            <select name="state" onchange="updateCityOptions(this.value); this.form.submit()" class="w-full px-4 py-3 bg-soft-bg border border-primary-deep/20 rounded-xl text-dark-charcoal text-sm focus:ring-1 focus:ring-primary-deep">
                                <option value="">all states</option>
                                <?php foreach ($states as $stateOption): ?>
                                    <option value="<?php echo htmlspecialchars($stateOption); ?>" <?php echo $state === $stateOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($stateOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-dark-charcoal/70 mb-2 tracking-wide">city</label>
                            <select name="city" onchange="this.form.submit()" class="w-full px-4 py-3 bg-soft-bg border border-primary-deep/20 rounded-xl text-dark-charcoal text-sm">
                                <option value="">all cities</option>
                                <?php foreach ($cities as $cityOption): ?>
                                    <option value="<?php echo htmlspecialchars($cityOption); ?>" <?php echo $city === $cityOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($cityOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- fee range min/max (simpler on mobile) -->
                        <div>
                            <label class="block text-xs font-bold text-dark-charcoal/70 mb-2"><i class="fas fa-naira-sign text-primary-deep mr-1"></i>annual fee (₦)</label>
                            <div class="flex gap-2">
                                <input type="number" name="min_fee" value="<?php echo $minFee !== null ? $minFee : ''; ?>" placeholder="min" class="w-1/2 px-3 py-2 bg-soft-bg border border-primary-deep/20 rounded-xl text-sm">
                                <input type="number" name="max_fee" value="<?php echo $maxFee !== null ? $maxFee : ''; ?>" placeholder="max" class="w-1/2 px-3 py-2 bg-soft-bg border border-primary-deep/20 rounded-xl text-sm">
                            </div>
                            <button type="button" onclick="applyFeeFilter()" class="w-full mt-2 bg-primary-deep/10 text-primary-deep px-3 py-2 rounded-xl text-sm font-semibold">apply fee</button>
                        </div>

                        <!-- curriculum radio list, scrollable but compact -->
                        <div>
                            <label class="block text-xs font-bold text-dark-charcoal/70 mb-2">curriculum</label>
                            <div class="space-y-2 max-h-36 overflow-y-auto pr-1">
                                <?php foreach ($curriculums as $curr): ?>
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="radio" name="curriculum" value="<?php echo htmlspecialchars($curr); ?>" <?php echo $curriculum === $curr ? 'checked' : ''; ?> onchange="this.form.submit()" class="accent-primary-deep">
                                        <span><?php echo htmlspecialchars($curr); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- school type radio -->
                        <div>
                            <label class="block text-xs font-bold text-dark-charcoal/70 mb-2">type</label>
                            <div class="space-y-2 max-h-36 overflow-y-auto pr-1">
                                <?php foreach ($schoolTypes as $type): ?>
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="radio" name="type" value="<?php echo htmlspecialchars($type); ?>" <?php echo $schoolType === $type ? 'checked' : ''; ?> onchange="this.form.submit()" class="accent-primary-deep">
                                        <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- hidden sort/page -->
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                        <input type="hidden" name="page" value="1">

                        <!-- active filter tags (mobile friendly) -->
                        <?php if (!empty($searchQuery) || !empty($state) || !empty($city) || !empty($curriculum) || !empty($schoolType) || $minFee || $maxFee): ?>
                            <div class="pt-3 flex flex-wrap gap-2 border-t border-primary-deep/10">
                                <?php if ($searchQuery): ?><span class="filter-tag"><i class="fas fa-magnifying-glass"></i> <?php echo htmlspecialchars($searchQuery); ?> <button type="button" onclick="removeFilter('search')" class="ml-1">✕</button></span><?php endif; ?>
                                <?php if ($state): ?><span class="filter-tag"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($state); ?> <button type="button" onclick="removeFilter('state')" class="ml-1">✕</button></span><?php endif; ?>
                                <?php if ($city): ?><span class="filter-tag"><?php echo htmlspecialchars($city); ?> <button type="button" onclick="removeFilter('city')" class="ml-1">✕</button></span><?php endif; ?>
                                <?php if ($curriculum): ?><span class="filter-tag"><?php echo htmlspecialchars($curriculum); ?> <button type="button" onclick="removeFilter('curriculum')" class="ml-1">✕</button></span><?php endif; ?>
                                <?php if ($schoolType): ?><span class="filter-tag"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $schoolType))); ?> <button type="button" onclick="removeFilter('type')" class="ml-1">✕</button></span><?php endif; ?>
                                <?php if ($minFee): ?><span class="filter-tag">min ₦<?php echo number_format($minFee); ?> <button type="button" onclick="removeFilter('min_fee')" class="ml-1">✕</button></span><?php endif; ?>
                                <?php if ($maxFee): ?><span class="filter-tag">max ₦<?php echo number_format($maxFee); ?> <button type="button" onclick="removeFilter('max_fee')" class="ml-1">✕</button></span><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="w-full bg-primary-deep text-white py-3 rounded-xl font-bold text-sm hover:bg-dark-charcoal transition">update results</button>
                    </form>

                    <!-- map placeholder: simple pattern, non-distracting -->
                    <div class="mt-6 map-placeholder text-sm flex-col gap-1 p-4">
                        <i class="fas fa-map-location-dot text-2xl opacity-60"></i>
                        <span>schools map view</span>
                        <span class="text-[10px] opacity-60">based on your filters</span>
                    </div>
                </div>
            </aside>

            <!-- SCHOOL LIST SECTION -->
            <section class="lg:col-span-3">
                <!-- results header + sort (mobile row) -->
                <div class="bg-white rounded-2xl p-5 mb-6 border border-primary-deep/10 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-mono-head text-xl text-dark-charcoal"><?php echo number_format($totalCount); ?> schools</h2>
                        <p class="text-xs text-dark-charcoal/60">
                            <?php if ($searchQuery): ?> for "<?php echo htmlspecialchars($searchQuery); ?>"<?php endif; ?>
                            <?php if ($state): ?> in <?php echo htmlspecialchars($state); ?><?php endif; ?>
                        </p>
                    </div>
                    <select name="sort" onchange="updateSort(this.value)" class="px-3 py-2 bg-soft-bg border border-primary-deep/20 rounded-xl text-sm">
                        <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>name A-Z</option>
                        <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>name Z-A</option>
                        <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>highest rated</option>
                        <option value="fee_low" <?php echo $sortBy === 'fee_low' ? 'selected' : ''; ?>>fee low-high</option>
                        <option value="fee_high" <?php echo $sortBy === 'fee_high' ? 'selected' : ''; ?>>fee high-low</option>
                        <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>newest</option>
                    </select>
                </div>

                <!-- grid: 1 column on mobile, 2 on medium, 3 on large -->
                <?php if (count($schools) > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                        <?php foreach ($schools as $school):
                            $badgeClass = '';
                            $badgeText = '';
                            if ($school['plan_name'] === 'Enterprise' || $school['plan_name'] === 'Premium') {
                                $badgeClass = 'badge-premium';
                                $badgeText = 'premium';
                            } elseif (strtotime($school['created_at']) > strtotime('-30 days')) {
                                $badgeClass = 'badge-new';
                                $badgeText = 'new';
                            } elseif (($school['total_reviews'] ?? 0) > 50) {
                                $badgeClass = 'badge-popular';
                                $badgeText = 'popular';
                            }

                            $rating = $school['avg_rating'] ?? 0;
                            $fullStars = floor($rating);
                            $hasHalf = ($rating - $fullStars) >= 0.5;

                            $feeFrom = $school['fee_range_from'] ?? 0;
                            $feeTo = $school['fee_range_to'] ?? 0;
                            $avgFee = ($feeFrom + $feeTo) / 2;
                        ?>
                            <div class="school-card p-5 flex flex-col">
                                <!-- top row: badge + icons -->
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <?php if ($badgeText): ?>
                                            <span class="<?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-2">
                                        <button class="w-8 h-8 bg-soft-bg rounded-full text-dark-charcoal/70"><i class="far fa-heart"></i></button>
                                    </div>
                                </div>
                                <!-- logo / placeholder -->
                                <div class="w-full h-32 rounded-xl bg-primary-deep/5 mb-4 flex items-center justify-center text-primary-deep/40 text-3xl">
                                    <?php if ($school['logo_path']): ?>
                                        <img src="<?php echo htmlspecialchars($school['logo_path']); ?>" class="h-full w-full object-cover rounded-xl">
                                    <?php else: ?>
                                        <i class="fas fa-tree"></i>
                                    <?php endif; ?>
                                </div>
                                <!-- school name + location -->
                                <h3 class="font-mono-head text-dark-charcoal text-lg mb-1"><?php echo htmlspecialchars($school['name']); ?></h3>
                                <div class="flex items-center gap-1 text-xs text-dark-charcoal/60 mb-2">
                                    <i class="fas fa-map-pin text-primary-deep/70"></i>
                                    <span><?php echo htmlspecialchars($school['city'] . ', ' . $school['state']); ?></span>
                                </div>
                                <!-- rating -->
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?php if ($i <= $fullStars): ?><i class="fas fa-star"></i>
                                            <?php elseif ($i == $fullStars + 1 && $hasHalf): ?><i class="fas fa-star-half-alt"></i>
                                            <?php else: ?><i class="far fa-star"></i>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </span>
                                    <span class="text-xs text-dark-charcoal/50">(<?php echo $school['total_reviews'] ?? 0; ?>)</span>
                                </div>
                                <!-- fee indicator -->
                                <?php if ($avgFee > 0): ?>
                                    <div class="flex items-center justify-between text-sm mb-2">
                                        <span class="text-dark-charcoal/60">fee</span>
                                        <span class="font-mono-head text-primary-deep">₦<?php echo number_format($avgFee); ?>/yr</span>
                                    </div>
                                <?php endif; ?>
                                <!-- quick type/curriculum chips -->
                                <div class="flex flex-wrap gap-2 mt-1 mb-4">
                                    <span class="bg-soft-bg px-3 py-1 rounded-full text-xs"><?php echo htmlspecialchars($school['school_type'] ?? 'secondary'); ?></span>
                                    <span class="bg-soft-bg px-3 py-1 rounded-full text-xs"><?php echo htmlspecialchars($school['curriculum'] ?? 'nigeria'); ?></span>
                                </div>
                                <!-- view details button -->
                                <a href="<?php echo htmlspecialchars(function_exists('school_portal_url') ? school_portal_url($school['slug'], '', true) : './school_profile.php?slug=' . urlencode($school['slug']), ENT_QUOTES, 'UTF-8'); ?>" class="mt-auto w-full bg-dark-charcoal text-white text-center py-3 rounded-xl text-sm font-bold hover:bg-primary-deep transition">view details</a>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- pagination, mobile optimized -->
                    <?php if ($totalPages > 1): ?>
                        <div class="mt-12 flex justify-center">
                            <nav class="flex flex-wrap gap-2">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $page - 1]); ?>" class="px-4 py-2 border border-primary-deep/20 rounded-xl text-dark-charcoal hover:bg-primary-deep/5 transition"><i class="fas fa-chevron-left"></i></a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $i]); ?>" class="px-4 py-2 border rounded-xl transition <?php echo $i == $page ? 'bg-primary-deep text-white border-primary-deep' : 'border-primary-deep/20 text-dark-charcoal hover:bg-primary-deep/5'; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $page + 1]); ?>" class="px-4 py-2 border border-primary-deep/20 rounded-xl text-dark-charcoal hover:bg-primary-deep/5"><i class="fas fa-chevron-right"></i></a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- no results, warm message -->
                    <div class="bg-white rounded-2xl p-12 text-center border border-primary-deep/10">
                        <i class="fas fa-tree text-5xl text-primary-deep/30 mb-4"></i>
                        <p class="font-mono-head text-xl text-dark-charcoal">no schools match</p>
                        <p class="text-dark-charcoal/60 text-sm max-w-sm mx-auto mt-2">try adjusting filters or search terms.</p>
                        <button onclick="clearFilters()" class="mt-5 bg-primary-deep text-white px-6 py-3 rounded-xl text-sm font-semibold">clear filters</button>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- WHY CHOOSE (earthy, human) -->
    <section id="why-choose" class="bg-primary-deep/5 py-16 px-4">
        <div class="container max-w-5xl mx-auto text-center">
            <h2 class="font-mono-head text-primary-deep text-sm tracking-widest mb-3">why parents trust us</h2>
            <p class="text-2xl md:text-4xl font-mono-head text-dark-charcoal max-w-2xl mx-auto mb-12">no fluff. just real schools & honest feedback.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div><i class="fas fa-check-circle text-3xl text-primary-deep mb-3"></i><h4 class="font-bold">verified data</h4><p class="text-sm text-dark-charcoal/70">every school is manually checked</p></div>
                <div><i class="fas fa-star text-3xl text-primary-deep mb-3"></i><h4 class="font-bold">parent reviews</h4><p class="text-sm text-dark-charcoal/70">no bots, just parents</p></div>
                <div><i class="fas fa-location-dot text-3xl text-primary-deep mb-3"></i><h4 class="font-bold">local & accurate</h4><p class="text-sm text-dark-charcoal/70">fees, contacts, facilities</p></div>
            </div>
        </div>
    </section>

    <!-- CTA: simple -->
    <section class="py-16 px-4 bg-dark-charcoal text-white">
        <div class="container max-w-3xl mx-auto text-center">
            <p class="font-mono-head text-2xl md:text-4xl mb-6">ready to find the right school?</p>
            <a href="#search" class="inline-block bg-accent-gold text-dark-charcoal px-8 py-4 rounded-full font-bold text-sm hover:bg-primary-deep hover:text-white transition">start searching</a>
        </div>
    </section>

    <!-- PROFESSIONAL FOOTER - REDESIGNED -->
    <footer class="bg-[#13452f] text-gray-300 pt-16 pb-8 border-t border-[#7DFF76]/20">
        <div class="container max-w-7xl mx-auto px-4">
            <!-- Top Section: Quick Links -->
            <div class="flex flex-wrap justify-center gap-6 md:gap-12 mb-12 pb-8 border-b border-gray-700/30">
                <a href="../volunteer/" class="text-sm hover:text-[#7DFF76] transition flex items-center gap-2"><i class="fas fa-hands-helping text-[#7DFF76] text-xs"></i>Be a Volunteer</a>
                <a href="../success-stories/" class="text-sm hover:text-[#7DFF76] transition flex items-center gap-2"><i class="fas fa-star text-[#7DFF76] text-xs"></i>Success Stories</a>
                <a href="../support/" class="text-sm hover:text-[#7DFF76] transition flex items-center gap-2"><i class="fas fa-comments text-[#7DFF76] text-xs"></i>Support Forum</a>
                <a href="../internships/" class="text-sm hover:text-[#7DFF76] transition flex items-center gap-2"><i class="fas fa-briefcase text-[#7DFF76] text-xs"></i>Internships</a>
                <a href="../help/" class="text-sm hover:text-[#7DFF76] transition flex items-center gap-2"><i class="fas fa-question-circle text-[#7DFF76] text-xs"></i>Help Center</a>
            </div>

            <!-- Main Footer Content -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 mb-12">
                <!-- Column 1: Transform Your School Management -->
                <div class="lg:col-span-3">
                    <h3 class="footer-heading text-white font-mono-head text-lg mb-6 pb-2">Transform Your School Management</h3>
                    <p class="text-sm text-gray-400 leading-relaxed mb-6">
                        Experience the future of education administration with our comprehensive, cloud-based platform. Streamline operations, enhance communication, and boost academic performance.
                    </p>
                    <a href="../demo-request/" class="inline-flex items-center gap-2 bg-[#7DFF76] text-[#1a1f18] px-6 py-3 rounded-lg font-bold text-sm hover:bg-[#13452f] hover:text-white transition group">
                        <span>Request Demo</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 25 25" fill="currentColor" class="group-hover:translate-x-1 transition">
                            <path d="M11.9,22.5c-0.7-0.7-0.7-1.8,0-2.5c0,0,0,0,0,0l5.7-5.7H2.8c-1,0-1.8-0.8-1.8-1.8s0.8-1.8,1.8-1.8h14.9L11.9,5 c-0.3-0.3-0.5-0.8-0.5-1.3c0-1,0.8-1.8,1.8-1.8c0.5,0,0.9,0.2,1.3,0.5l8.7,8.7c0.1,0.1,0.2,0.2,0.2,0.3c0,0,0,0.1,0.1,0.1 c0,0,0,0,0,0l0,0c0,0.1,0.1,0.1,0.1,0.2c0,0,0,0.1,0.1,0.2l0,0c0,0,0,0,0,0c0,0,0,0.1,0,0.1c0,0.2,0,0.5,0,0.7c0,0,0,0.1,0,0.1 c0,0,0,0,0,0l0,0c0,0,0,0.1,0,0.2c0,0.1-0.1,0.1-0.1,0.2l0,0c0,0,0,0,0,0c0,0,0,0.1-0.1,0.1c-0.1,0.1-0.1,0.2-0.2,0.3l-8.7,8.7 C13.8,23.2,12.6,23.2,11.9,22.5C11.9,22.5,11.9,22.5,11.9,22.5z"></path>
                        </svg>
                    </a>
                </div>

                <!-- Column 2: Platform Features (Accordion-style on mobile) -->
                <div class="lg:col-span-2">
                    <div class="border-b lg:border-0 border-gray-700/30 pb-4 lg:pb-0">
                        <button class="lg:hidden w-full flex justify-between items-center text-white font-mono-head text-base" onclick="toggleSection('features')">
                            Platform Features
                            <i class="fas fa-plus text-[#7DFF76] text-sm transition-transform" id="features-icon"></i>
                        </button>
                        <h3 class="hidden lg:block footer-heading text-white font-mono-head text-lg mb-6">Platform Features</h3>
                        <div id="features-content" class="mt-4 lg:mt-0 hidden lg:block">
                            <ul class="space-y-3 text-sm">
                                <li><a href="../features/student-management/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Student Management</a></li>
                                <li><a href="../features/attendance-tracking/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Attendance Tracking</a></li>
                                <li><a href="../features/fee-management/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Fee & Billing System</a></li>
                                <li><a href="../features/gradebook/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Digital Gradebook</a></li>
                                <li><a href="../features/timetable/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Timetable Management</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Column 3: Support & Resources -->
                <div class="lg:col-span-2">
                    <div class="border-b lg:border-0 border-gray-700/30 pb-4 lg:pb-0">
                        <button class="lg:hidden w-full flex justify-between items-center text-white font-mono-head text-base" onclick="toggleSection('support')">
                            Support & Resources
                            <i class="fas fa-plus text-[#7DFF76] text-sm transition-transform" id="support-icon"></i>
                        </button>
                        <h3 class="hidden lg:block footer-heading text-white font-mono-head text-lg mb-6">Support & Resources</h3>
                        <div id="support-content" class="mt-4 lg:mt-0 hidden lg:block">
                            <ul class="space-y-3 text-sm">
                                <li><a href="../documentation/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Documentation</a></li>
                                <li><a href="../help-center/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Help Center</a></li>
                                <li><a href="../tutorials/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Video Tutorials</a></li>
                                <li><a href="../faq/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>FAQ</a></li>
                                <li><a href="../contact/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Contact Support</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Column 4: Company -->
                <div class="lg:col-span-2">
                    <div class="border-b lg:border-0 border-gray-700/30 pb-4 lg:pb-0">
                        <button class="lg:hidden w-full flex justify-between items-center text-white font-mono-head text-base" onclick="toggleSection('company')">
                            Company
                            <i class="fas fa-plus text-[#7DFF76] text-sm transition-transform" id="company-icon"></i>
                        </button>
                        <h3 class="hidden lg:block footer-heading text-white font-mono-head text-lg mb-6">Company</h3>
                        <div id="company-content" class="mt-4 lg:mt-0 hidden lg:block">
                            <ul class="space-y-3 text-sm">
                                <li><a href="../about/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>About AcademixSuite</a></li>
                                <li><a href="../pricing/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Pricing Plans</a></li>
                                <li><a href="../careers/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Careers</a></li>
                                <li><a href="../blog/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Blog & Insights</a></li>
                                <li><a href="../affiliate/" class="hover:text-[#7DFF76] transition flex items-center gap-2"><span class="w-1 h-1 bg-[#7DFF76] rounded-full"></span>Affiliate Program</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Column 5: Newsletter -->
                <div class="lg:col-span-3">
                    <h3 class="footer-heading text-white font-mono-head text-lg mb-6">Stay Updated</h3>
                    <p class="text-sm text-gray-400 mb-4">
                        Get the latest updates on school management trends, platform features, and educational technology insights delivered to your inbox.
                    </p>
                    <form class="mb-6">
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="email" placeholder="Enter your email address" class="newsletter-input flex-1 px-4 py-3 bg-[#2a3028] border border-gray-700 rounded-lg text-sm text-white placeholder-gray-500 focus:border-[#c79b5e] focus:outline-none">
                            <button type="submit" class="bg-[#7DFF76] text-[#1a1f18] px-6 py-3 rounded-lg font-bold text-sm hover:bg-[#13452f] hover:text-white transition whitespace-nowrap">
                                Subscribe
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            By subscribing, you agree to our <a href="../privacy/" class="text-[#c79b5e] hover:underline">Privacy Policy</a>.
                        </p>
                    </form>
                    <div class="text-sm text-gray-400">
                        <p><strong>Contact:</strong> <a href="mailto:support@academixsuite.com" class="hover:text-[#c79b5e]">support@academixsuite.com</a></p>
                        <p><strong>Sales:</strong> <a href="mailto:sales@academixsuite.com" class="hover:text-[#c79b5e]">sales@academixsuite.com</a></p>
                    </div>
                </div>
            </div>

            <!-- Bottom Section: Logo, Legal, Copyright -->
            <div class="border-t border-gray-700/30 pt-8">
                <div class="flex flex-col lg:flex-row justify-between items-center gap-6">
                    <!-- Logo and Copyright -->
                    <div class="flex flex-col items-center lg:items-start gap-4">
                        <div class="flex items-center gap-3">
                            <img src="./assets/images/logo.png" alt="AcademixSuite" class="h-10 w-auto">
                        </div>
                        <div class="flex flex-wrap justify-center lg:justify-start gap-4 text-xs text-gray-500">
                            <a href="../terms/" class="hover:text-[#c79b5e] transition">Terms of Service</a>
                            <span>•</span>
                            <a href="../privacy/" class="hover:text-[#c79b5e] transition">Privacy Policy</a>
                            <span>•</span>
                            <a href="../data-security/" class="hover:text-[#c79b5e] transition">Data Security</a>
                            <span>•</span>
                            <a href="../cookies/" class="hover:text-[#c79b5e] transition">Cookie Policy</a>
                        </div>
                        <div class="text-xs text-gray-500 text-center lg:text-left">
                            <span>© <?php echo date('Y'); ?> AcademixSuite. All rights reserved.</span>
                            <p class="mt-2 max-w-md">A comprehensive school management platform for educational institutions worldwide.</p>
                        </div>
                    </div>

                    <!-- Social Links -->
                    <div class="flex gap-4">
                        <a href="#" class="w-10 h-10 bg-[#2a3028] rounded-full flex items-center justify-center hover:bg-[#c79b5e] hover:text-[#1a1f18] transition"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="w-10 h-10 bg-[#2a3028] rounded-full flex items-center justify-center hover:bg-[#c79b5e] hover:text-[#1a1f18] transition"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="w-10 h-10 bg-[#2a3028] rounded-full flex items-center justify-center hover:bg-[#c79b5e] hover:text-[#1a1f18] transition"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="w-10 h-10 bg-[#2a3028] rounded-full flex items-center justify-center hover:bg-[#c79b5e] hover:text-[#1a1f18] transition"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- scripts -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ duration: 600, once: true });

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

        function updateCityOptions(state) {
            if (!state) return;
            // In a real scenario, you'd fetch via AJAX; we use simple refresh now (form auto-submit)
        }

        // Mobile accordion functionality for footer
        function toggleSection(section) {
            const content = document.getElementById(section + '-content');
            const icon = document.getElementById(section + '-icon');
            
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                icon.classList.remove('fa-plus');
                icon.classList.add('fa-minus');
            } else {
                content.classList.add('hidden');
                icon.classList.remove('fa-minus');
                icon.classList.add('fa-plus');
            }
        }

        // Close all mobile accordions on resize above lg
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                ['features', 'support', 'company'].forEach(section => {
                    const content = document.getElementById(section + '-content');
                    const icon = document.getElementById(section + '-icon');
                    if (content) {
                        content.classList.remove('hidden');
                        if (icon) {
                            icon.classList.remove('fa-plus', 'fa-minus');
                            icon.classList.add('fa-plus');
                        }
                    }
                });
            }
        });
    </script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
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
