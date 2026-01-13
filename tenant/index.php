<?php

/**
 * School Discovery Portal - Find Schools to Enroll Your Child
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/school_discovery.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Schools | AcademixSuite - Nigeria's Premier School Discovery Platform</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;400;600;800&family=Space+Grotesk:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --accent: #f43f5e;
            --surface: #ffffff;
            --bg: #f8fafc;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg);
            color: #0f172a;
            overflow-x: hidden;
        }

        h1,
        h2,
        h3,
        .font-heading {
            font-family: 'Space Grotesk', sans-serif;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Glassmorphism Navigation */
        .glass-nav {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 1rem;
            margin: 1rem auto;
            width: 95%;
            max-width: 1200px;
        }

        /* School Card Styling */
        .school-card {
            transition: all 0.5s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            z-index: 1;
        }

        .school-card:hover {
            transform: translateY(-10px) scale(1.02);
            z-index: 10;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
        }

        /* Animated Blobs */
        .blob {
            position: absolute;
            width: 300px;
            height: 300px;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.2), rgba(124, 58, 237, 0.2));
            filter: blur(80px);
            border-radius: 50%;
            z-index: -1;
            animation: move 20s infinite alternate;
        }

        @keyframes move {
            from {
                transform: translate(-10%, -10%);
            }

            to {
                transform: translate(20%, 20%);
            }
        }

        /* Fee Range Slider */
        .range-slider {
            -webkit-appearance: none;
            width: 100%;
            height: 6px;
            border-radius: 5px;
            background: #e2e8f0;
            outline: none;
        }

        .range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 3px solid white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .range-slider::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 3px solid white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .glass-nav {
                padding: 0.75rem 1rem;
            }

            .hero-mesh {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .map-container {
                height: 250px;
            }

            .school-card {
                margin-bottom: 1rem;
            }
        }

        /* Loading Animation */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        /* Rating Stars */
        .rating-stars {
            display: inline-flex;
            align-items: center;
        }

        .rating-stars i {
            color: #fbbf24;
        }

        .rating-stars i:last-child {
            color: #e5e7eb;
        }

        /* Fee Badge */
        .fee-badge {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            font-weight: bold;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
    </style>
</head>

<body>

    <div class="blob" style="top: 10%; left: -5%;"></div>
    <div class="blob" style="bottom: 10%; right: -5%; background: rgba(244, 63, 94, 0.1);"></div>

    <nav class="glass-nav sticky top-2 md:top-4 flex items-center justify-between px-4 md:px-8 py-3 md:py-4 z-[1000]">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg md:rounded-xl flex items-center justify-center text-white shadow-lg">
                <i class="fas fa-school text-sm md:text-base"></i>
            </div>
            <span class="text-lg md:text-xl font-bold tracking-tight">Academix<span class="text-indigo-600">Suite</span></span>
        </div>

        <div class="hidden lg:flex items-center space-x-6 font-medium text-slate-600">
            <a href="#search" class="hover:text-indigo-600 transition">Find Schools</a>
            <a href="#why-choose" class="hover:text-indigo-600 transition">Why Choose</a>
            <a href="#how-it-works" class="hover:text-indigo-600 transition">How It Works</a>
            <a href="#faq" class="hover:text-indigo-600 transition">FAQ</a>
        </div>

        <div class="flex items-center space-x-4">
            <a href="/academixsuite/tenant/login.php" class="text-sm font-bold text-slate-700 hover:text-indigo-600 transition">School Login</a>
            <a href="/academixsuite/public/register.php" class="bg-slate-900 text-white px-4 md:px-6 py-2 md:py-2.5 rounded-full text-sm font-bold hover:bg-indigo-600 transition shadow-xl">
                Register School
            </a>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="hero-mesh relative px-4 md:px-6 pt-12 md:pt-16 pb-12 md:pb-24 overflow-hidden">
        <div class="container mx-auto text-center relative z-10">
            <div data-aos="fade-down" class="mb-6">
                <span class="bg-indigo-100 text-indigo-600 px-3 md:px-4 py-1 md:py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border border-indigo-200">
                    <?php echo number_format($totalCount); ?>+ Verified Schools
                </span>
            </div>
            <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-7xl font-black text-slate-900 leading-[1.1] md:leading-[1.05] mb-6 md:mb-8" data-aos="fade-up">
                Find the Perfect <br class="hidden md:block">
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 via-purple-600 to-rose-500">School for Your Child</span>
            </h1>
            <p class="text-base md:text-xl lg:text-2xl text-slate-500 max-w-3xl mx-auto mb-8 md:mb-12 font-light leading-relaxed" data-aos="fade-up" data-aos-delay="100">
                Discover Nigeria's best educational institutions with verified reviews, detailed profiles, and smart location-based recommendations.
            </p>

            <!-- Quick Stats -->
            <div class="flex flex-wrap justify-center items-center gap-6 md:gap-10 mb-8 md:mb-12" data-aos="fade-up" data-aos-delay="150">
                <div class="text-center">
                    <p class="text-2xl md:text-4xl font-black text-indigo-600 stat-counter" data-count="<?php echo $totalCount; ?>">0</p>
                    <p class="text-xs md:text-sm text-slate-500">Active Schools</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl md:text-4xl font-black text-purple-600">36</p>
                    <p class="text-xs md:text-sm text-slate-500">States Covered</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl md:text-4xl font-black text-rose-600">4.8</p>
                    <p class="text-xs md:text-sm text-slate-500">Avg. Parent Rating</p>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="max-w-3xl mx-auto" data-aos="fade-up" data-aos-delay="200">
                <form method="GET" action="" id="searchForm" class="relative">
                    <div class="relative">
                        <i class="fas fa-search absolute left-6 top-1/2 transform -translate-y-1/2 text-slate-400 text-lg"></i>
                        <input type="text"
                            name="search"
                            placeholder="Search schools by name, location, or curriculum..."
                            value="<?php echo htmlspecialchars($searchQuery); ?>"
                            class="w-full pl-16 pr-6 py-4 md:py-6 bg-white border border-slate-200 rounded-xl md:rounded-2xl text-base md:text-lg outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 transition-all shadow-lg">
                        <button type="submit" class="absolute right-3 top-1/2 transform -translate-y-1/2 bg-indigo-600 text-white px-4 md:px-6 py-2 md:py-3 rounded-lg md:rounded-xl font-bold hover:bg-indigo-700 transition shadow-lg shadow-indigo-200">
                            Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 md:px-6 py-8 md:py-12">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Filters Sidebar -->
            <aside class="lg:col-span-1">
                <div class="bg-white rounded-xl md:rounded-2xl p-6 border border-slate-200 shadow-sm sticky top-24">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-bold text-slate-900 text-lg">Filter Schools</h3>
                        <button type="button" onclick="clearFilters()" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                            Clear All
                        </button>
                    </div>

                    <form method="GET" action="" id="filterForm" class="space-y-6">
                        <!-- Location Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-3">
                                <i class="fas fa-map-marker-alt text-indigo-600 mr-2"></i>Location
                            </label>
                            <div class="space-y-3">
                                <select name="state" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 transition" onchange="updateCityOptions(this.value)">
                                    <option value="">All States</option>
                                    <?php foreach ($states as $stateOption): ?>
                                        <option value="<?php echo htmlspecialchars($stateOption); ?>" <?php echo $state === $stateOption ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($stateOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="city" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 transition" onchange="this.form.submit()">
                                    <option value="">All Cities</option>
                                    <?php if ($state): ?>
                                        <?php foreach ($cities as $cityOption): ?>
                                            <option value="<?php echo htmlspecialchars($cityOption); ?>" <?php echo $city === $cityOption ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cityOption); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Fee Range Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-3">
                                <i class="fas fa-money-bill-wave text-indigo-600 mr-2"></i>Annual Fee Range
                            </label>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between text-sm text-slate-600">
                                    <span>₦<?php echo number_format($globalMinFee); ?></span>
                                    <span>₦<?php echo number_format($globalMaxFee); ?></span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <div>
                                        <label class="text-xs text-slate-500">Min (₦)</label>
                                        <input type="number"
                                            name="min_fee"
                                            value="<?php echo $minFee !== null ? $minFee : ''; ?>"
                                            placeholder="Min"
                                            class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 outline-none focus:ring-1 focus:ring-indigo-100 focus:border-indigo-500 transition text-sm">
                                    </div>
                                    <div>
                                        <label class="text-xs text-slate-500">Max (₦)</label>
                                        <input type="number"
                                            name="max_fee"
                                            value="<?php echo $maxFee !== null ? $maxFee : ''; ?>"
                                            placeholder="Max"
                                            class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 outline-none focus:ring-1 focus:ring-indigo-100 focus:border-indigo-500 transition text-sm">
                                    </div>
                                </div>
                                <button type="button" onclick="applyFeeFilter()" class="w-full bg-indigo-100 text-indigo-600 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-200 transition">
                                    Apply Fee Filter
                                </button>
                            </div>
                        </div>

                        <!-- Curriculum Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-3">
                                <i class="fas fa-book-open text-indigo-600 mr-2"></i>Curriculum
                            </label>
                            <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                                <?php foreach ($curriculums as $curriculumOption): ?>
                                    <label class="flex items-center cursor-pointer hover:bg-slate-50 p-2 rounded-lg transition">
                                        <input type="radio"
                                            name="curriculum"
                                            value="<?php echo htmlspecialchars($curriculumOption); ?>"
                                            class="hidden peer"
                                            onchange="this.form.submit()"
                                            <?php echo $curriculum === $curriculumOption ? 'checked' : ''; ?>>
                                        <div class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center mr-3 peer-checked:bg-indigo-500 peer-checked:border-indigo-500">
                                            <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                                        </div>
                                        <span class="text-slate-700 peer-checked:text-indigo-600 peer-checked:font-medium text-sm">
                                            <?php echo htmlspecialchars($curriculumOption); ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- School Type Filter -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-3">
                                <i class="fas fa-building text-indigo-600 mr-2"></i>School Type
                            </label>
                            <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                                <?php foreach ($schoolTypes as $typeOption): ?>
                                    <label class="flex items-center cursor-pointer hover:bg-slate-50 p-2 rounded-lg transition">
                                        <input type="radio"
                                            name="type"
                                            value="<?php echo htmlspecialchars($typeOption); ?>"
                                            class="hidden peer"
                                            onchange="this.form.submit()"
                                            <?php echo $schoolType === $typeOption ? 'checked' : ''; ?>>
                                        <div class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center mr-3 peer-checked:bg-indigo-500 peer-checked:border-indigo-500">
                                            <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                                        </div>
                                        <span class="text-slate-700 peer-checked:text-indigo-600 peer-checked:font-medium text-sm">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $typeOption))); ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Hidden inputs -->
                        <input type="hidden" name="page" value="1">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">

                        <!-- Active Filters Display -->
                        <?php if ($searchQuery || $state || $city || $curriculum || $schoolType || $minFee !== null || $maxFee !== null): ?>
                            <div class="pt-4 border-t border-slate-200">
                                <p class="text-sm font-semibold text-slate-700 mb-2">Active Filters:</p>
                                <div class="flex flex-wrap gap-2">
                                    <?php if ($searchQuery): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            Search: <?php echo htmlspecialchars($searchQuery); ?>
                                            <button type="button" onclick="removeFilter('search')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($state): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            State: <?php echo htmlspecialchars($state); ?>
                                            <button type="button" onclick="removeFilter('state')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($city): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            City: <?php echo htmlspecialchars($city); ?>
                                            <button type="button" onclick="removeFilter('city')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($curriculum): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            Curriculum: <?php echo htmlspecialchars($curriculum); ?>
                                            <button type="button" onclick="removeFilter('curriculum')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($schoolType): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            Type: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $schoolType))); ?>
                                            <button type="button" onclick="removeFilter('type')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($minFee !== null): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            Min Fee: ₦<?php echo number_format($minFee); ?>
                                            <button type="button" onclick="removeFilter('min_fee')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($maxFee !== null): ?>
                                        <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-medium">
                                            Max Fee: ₦<?php echo number_format($maxFee); ?>
                                            <button type="button" onclick="removeFilter('max_fee')" class="ml-1 hover:text-red-600">×</button>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="w-full bg-indigo-600 text-white px-4 py-3 rounded-lg font-semibold hover:bg-indigo-700 transition shadow-md mt-6">
                            Apply Filters
                        </button>
                    </form>
                </div>

                <!-- Map Section -->
                <div class="mt-6 bg-white rounded-xl md:rounded-2xl p-6 border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-900 text-lg mb-4">
                        <i class="fas fa-map text-indigo-600 mr-2"></i>Schools Map
                    </h3>
                    <div class="map-container bg-gradient-to-br from-slate-100 to-slate-200">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="text-center">
                                <i class="fas fa-map-marked-alt text-4xl text-slate-400 mb-3"></i>
                                <p class="text-slate-600 font-medium">Interactive Map</p>
                                <p class="text-slate-500 text-sm mt-1">School locations visualized</p>
                            </div>
                        </div>
                        <div class="map-overlay"></div>
                    </div>
                    <p class="text-slate-500 text-sm mt-4">
                        <i class="fas fa-info-circle text-indigo-500 mr-1"></i>
                        Map shows school locations based on your filters
                    </p>
                </div>
            </aside>

            <!-- Schools List -->
            <section class="lg:col-span-3">
                <!-- Results Header -->
                <div class="bg-white rounded-xl md:rounded-2xl p-6 border border-slate-200 shadow-sm mb-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <h2 class="text-xl md:text-2xl font-bold text-slate-900">
                                <?php echo number_format($totalCount); ?> Schools Found
                            </h2>
                            <p class="text-slate-500 text-sm mt-1">
                                <?php if ($searchQuery): ?>Showing results for "<?php echo htmlspecialchars($searchQuery); ?>"<?php endif; ?>
                                <?php if ($state): ?> in <?php echo htmlspecialchars($state); ?><?php endif; ?>
                                    <?php if ($minFee !== null || $maxFee !== null): ?>
                                        with fees
                                        <?php if ($minFee !== null): ?>from ₦<?php echo number_format($minFee); ?><?php endif; ?>
                                        <?php if ($maxFee !== null): ?>to ₦<?php echo number_format($maxFee); ?><?php endif; ?>
                                    <?php endif; ?>
                            </p>
                        </div>

                        <div class="flex items-center gap-4">
                            <select name="sort"
                                onchange="updateSort(this.value)"
                                class="px-4 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 transition text-sm">
                                <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Sort by: Name A-Z</option>
                                <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Sort by: Name Z-A</option>
                                <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>Sort by: Highest Rated</option>
                                <option value="fee_low" <?php echo $sortBy === 'fee_low' ? 'selected' : ''; ?>>Sort by: Fee Low to High</option>
                                <option value="fee_high" <?php echo $sortBy === 'fee_high' ? 'selected' : ''; ?>>Sort by: Fee High to Low</option>
                                <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Sort by: Newest</option>
                                <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Sort by: Oldest</option>
                                <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>Sort by: Most Popular</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Schools Grid -->
                <?php if (count($schools) > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($schools as $school):
                            // Determine badge type
                            $badgeClass = '';
                            $badgeText = '';
                            if ($school['plan_name'] === 'Enterprise' || $school['plan_name'] === 'Premium') {
                                $badgeClass = 'badge-premium';
                                $badgeText = 'Premium';
                            } elseif (strtotime($school['created_at']) > strtotime('-30 days')) {
                                $badgeClass = 'badge-new';
                                $badgeText = 'New';
                            } elseif ($school['total_reviews'] > 50) {
                                $badgeClass = 'badge-popular';
                                $badgeText = 'Popular';
                            }

                            // Calculate rating
                            $rating = $school['avg_rating'] ?? 0;
                            $fullStars = floor($rating);
                            $hasHalfStar = ($rating - $fullStars) >= 0.5;

                            // Parse facilities if they exist
                            $facilities = [];
                            if (!empty($school['facilities'])) {
                                $facilities = json_decode($school['facilities'], true) ?: [];
                            }

                            // Calculate fee range
                            $feeFrom = $school['fee_range_from'] ?? 0;
                            $feeTo = $school['fee_range_to'] ?? 0;
                            $avgFee = ($feeFrom + $feeTo) / 2;
                        ?>
                            <div class="school-card bg-white rounded-xl md:rounded-2xl overflow-hidden border border-slate-200 shadow-sm hover:shadow-lg transition-all duration-300" data-aos="fade-up">
                                <!-- School Image -->
                                <div class="relative h-48 overflow-hidden">
                                    <?php if ($school['logo_path']): ?>
                                        <img src="<?php echo htmlspecialchars($school['logo_path']); ?>"
                                            alt="<?php echo htmlspecialchars($school['name']); ?>"
                                            class="w-full h-full object-cover transform hover:scale-105 transition-transform duration-300">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center">
                                            <i class="fas fa-school text-4xl text-indigo-300"></i>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Badge -->
                                    <?php if ($badgeClass && $badgeText): ?>
                                        <div class="absolute top-4 left-4">
                                            <span class="<?php echo $badgeClass; ?> school-badge shadow-md">
                                                <i class="fas fa-star text-xs"></i>
                                                <?php echo $badgeText; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Fee Badge -->
                                    <?php if ($avgFee > 0): ?>
                                        <div class="absolute bottom-4 right-4">
                                            <span class="fee-badge shadow-md">
                                                <i class="fas fa-money-bill-wave text-xs"></i>
                                                ₦<?php echo number_format($avgFee); ?>/yr
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Quick Actions -->
                                    <div class="absolute top-4 right-4 flex gap-2">
                                        <button class="w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center text-slate-700 hover:text-indigo-600 transition shadow-md">
                                            <i class="fas fa-heart text-sm"></i>
                                        </button>
                                        <button class="w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center text-slate-700 hover:text-indigo-600 transition shadow-md">
                                            <i class="fas fa-share-alt text-sm"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- School Info -->
                                <div class="p-6">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="flex-1">
                                            <h3 class="font-bold text-slate-900 text-lg mb-1 truncate">
                                                <?php echo htmlspecialchars($school['name']); ?>
                                            </h3>
                                            <div class="flex items-center text-slate-500 text-sm mb-3">
                                                <i class="fas fa-map-marker-alt text-xs mr-1"></i>
                                                <span class="truncate">
                                                    <?php
                                                    $locationParts = array_filter([$school['city'], $school['state']]);
                                                    echo htmlspecialchars(implode(', ', $locationParts));
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <?php if ($rating > 0): ?>
                                                <div class="rating-stars text-sm">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <?php if ($i <= $fullStars): ?>
                                                            <i class="fas fa-star"></i>
                                                        <?php elseif ($i === $fullStars + 1 && $hasHalfStar): ?>
                                                            <i class="fas fa-star-half-alt"></i>
                                                        <?php else: ?>
                                                            <i class="far fa-star"></i>
                                                        <?php endif; ?>
                                                    <?php endfor; ?>
                                                </div>
                                                <p class="text-xs text-slate-500 mt-1"><?php echo number_format($rating, 1); ?> (<?php echo $school['total_reviews'] ?? 0; ?>)</p>
                                            <?php else: ?>
                                                <p class="text-xs text-slate-500">No ratings yet</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <p class="text-slate-600 text-sm mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars($school['description'] ?? 'A reputable educational institution providing quality education.'); ?>
                                    </p>

                                    <!-- Quick Facts -->
                                    <div class="grid grid-cols-2 gap-3 mb-4">
                                        <div class="flex items-center text-slate-600 text-sm">
                                            <i class="fas fa-graduation-cap text-indigo-500 mr-2 text-xs"></i>
                                            <span><?php echo htmlspecialchars($school['school_type'] ?? 'Secondary'); ?></span>
                                        </div>
                                        <div class="flex items-center text-slate-600 text-sm">
                                            <i class="fas fa-book text-purple-500 mr-2 text-xs"></i>
                                            <span><?php echo htmlspecialchars($school['curriculum'] ?? 'Nigerian'); ?></span>
                                        </div>
                                        <?php if ($school['establishment_year']): ?>
                                            <div class="flex items-center text-slate-600 text-sm">
                                                <i class="fas fa-calendar-alt text-rose-500 mr-2 text-xs"></i>
                                                <span>Est. <?php echo $school['establishment_year']; ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($facilities)): ?>
                                            <div class="flex items-center text-slate-600 text-sm">
                                                <i class="fas fa-wifi text-emerald-500 mr-2 text-xs"></i>
                                                <span><?php echo count($facilities); ?> facilities</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Fee Range -->
                                    <?php if ($feeFrom > 0 || $feeTo > 0): ?>
                                        <div class="mb-4">
                                            <div class="flex justify-between text-xs text-slate-500 mb-1">
                                                <span>Annual Fee Range:</span>
                                                <span class="font-semibold text-slate-700">
                                                    ₦<?php echo number_format($feeFrom); ?> - ₦<?php echo number_format($feeTo); ?>
                                                </span>
                                            </div>
                                            <div class="w-full bg-slate-100 rounded-full h-2">
                                                <?php
                                                $percentage = $globalMaxFee > 0 ? min(100, ($avgFee / $globalMaxFee) * 100) : 0;
                                                ?>
                                                <div class="bg-gradient-to-r from-emerald-400 to-emerald-600 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                                        <div>
                                            <span class="text-sm font-semibold text-slate-900">
                                                Admission:
                                            </span>
                                            <span class="text-sm <?php echo ($school['admission_status'] ?? 'open') === 'open' ? 'text-emerald-600' : 'text-rose-600'; ?> ml-1">
                                                <?php echo ucfirst($school['admission_status'] ?? 'open'); ?>
                                            </span>
                                        </div>
                                        <a href="./school_profile.php?slug=<?php echo urlencode($school['slug']); ?>"
                                            class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-700 transition shadow-md shadow-indigo-200">
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="mt-8 flex justify-center">
                            <nav class="flex items-center space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $page - 1]); ?>"
                                        class="px-4 py-2 border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition">
                                        <i class="fas fa-chevron-left mr-2"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                if ($startPage > 1): ?>
                                    <a href="?<?php echo buildQueryString(['page' => 1]); ?>"
                                        class="px-4 py-2 border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition">
                                        1
                                    </a>
                                    <?php if ($startPage > 2): ?>
                                        <span class="px-2 py-2 text-slate-500">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $i]); ?>"
                                        class="px-4 py-2 border rounded-lg transition <?php echo $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-700 hover:bg-slate-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <span class="px-2 py-2 text-slate-500">...</span>
                                    <?php endif; ?>
                                    <a href="?<?php echo buildQueryString(['page' => $totalPages]); ?>"
                                        class="px-4 py-2 border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition">
                                        <?php echo $totalPages; ?>
                                    </a>
                                <?php endif; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo buildQueryString(['page' => $page + 1]); ?>"
                                        class="px-4 py-2 border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition">
                                        Next <i class="fas fa-chevron-right ml-2"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- No Results -->
                    <div class="bg-white rounded-xl md:rounded-2xl p-12 border border-slate-200 shadow-sm text-center">
                        <div class="w-24 h-24 rounded-full bg-indigo-100 flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-school text-3xl text-indigo-600"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3">No Schools Found</h3>
                        <p class="text-slate-500 mb-6 max-w-md mx-auto">
                            We couldn't find any schools matching your criteria. Try adjusting your filters or search terms.
                        </p>
                        <button onclick="clearFilters()" class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-indigo-700 transition shadow-md">
                            Clear All Filters
                        </button>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- Why Choose Section -->
    <section id="why-choose" class="py-16 md:py-24 bg-slate-50 px-4 md:px-6 mt-12">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-12 md:mb-20">
                <h2 class="text-indigo-600 font-black uppercase tracking-[0.4em] text-xs mb-4">Why Trust Us</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">Find the Best Fit for Your Child</h3>
                <p class="text-slate-500 text-base md:text-lg">We help you make informed decisions with verified information and parent reviews.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-12">
                <div class="text-center" data-aos="fade-up">
                    <div class="w-16 h-16 rounded-2xl bg-indigo-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-shield-alt text-2xl text-indigo-600"></i>
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 mb-3">Verified Information</h4>
                    <p class="text-slate-600">All schools are thoroughly vetted and verified for authenticity and compliance.</p>
                </div>

                <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                    <div class="w-16 h-16 rounded-2xl bg-purple-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-star text-2xl text-purple-600"></i>
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 mb-3">Parent Reviews</h4>
                    <p class="text-slate-600">Read genuine reviews from other parents to make better decisions.</p>
                </div>

                <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                    <div class="w-16 h-16 rounded-2xl bg-rose-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-map-marked-alt text-2xl text-rose-600"></i>
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 mb-3">Smart Location</h4>
                    <p class="text-slate-600">Find schools near you with detailed location information and directions.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="how-it-works" class="py-16 md:py-24 bg-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-12 md:mb-20">
                <h2 class="text-indigo-600 font-black uppercase tracking-[0.4em] text-xs mb-4">Simple Process</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">How It Works</h3>
                <p class="text-slate-500 text-base md:text-lg">Find and connect with schools in just a few simple steps.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div class="text-center" data-aos="fade-up">
                    <div class="relative mb-6">
                        <div class="w-12 h-12 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xl font-bold mx-auto relative z-10">
                            1
                        </div>
                        <div class="absolute top-6 left-1/2 w-full h-0.5 bg-slate-200 hidden md:block"></div>
                    </div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">Search Schools</h4>
                    <p class="text-slate-600 text-sm">Use filters to find schools matching your preferences</p>
                </div>

                <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                    <div class="relative mb-6">
                        <div class="w-12 h-12 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xl font-bold mx-auto relative z-10">
                            2
                        </div>
                        <div class="absolute top-6 left-1/2 w-full h-0.5 bg-slate-200 hidden md:block"></div>
                    </div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">Compare Options</h4>
                    <p class="text-slate-600 text-sm">View detailed profiles, reviews, and facilities</p>
                </div>

                <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                    <div class="relative mb-6">
                        <div class="w-12 h-12 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xl font-bold mx-auto relative z-10">
                            3
                        </div>
                        <div class="absolute top-6 left-1/2 w-full h-0.5 bg-slate-200 hidden md:block"></div>
                    </div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">Contact Schools</h4>
                    <p class="text-slate-600 text-sm">Directly connect with school administration</p>
                </div>

                <div class="text-center" data-aos="fade-up" data-aos-delay="300">
                    <div class="relative mb-6">
                        <div class="w-12 h-12 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xl font-bold mx-auto">
                            4
                        </div>
                    </div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">Begin Enrollment</h4>
                    <p class="text-slate-600 text-sm">Start the admission process with your chosen school</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section id="faq" class="py-16 md:py-24 bg-slate-50 px-4 md:px-6">
        <div class="container mx-auto max-w-4xl">
            <div class="text-center mb-12 md:mb-20">
                <h2 class="text-indigo-600 font-black uppercase tracking-[0.4em] text-xs mb-4">Need Help?</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">Frequently Asked Questions</h3>
                <p class="text-slate-500 text-base md:text-lg">Find answers to common questions about finding schools.</p>
            </div>

            <div class="space-y-4 md:space-y-6">
                <?php
                $faqs = [
                    [
                        'question' => 'How do I know if a school is verified?',
                        'answer' => 'All schools displayed on our platform undergo a rigorous verification process. Look for the verification badge next to the school name.'
                    ],
                    [
                        'question' => 'Can I contact schools directly?',
                        'answer' => 'Yes! Each school profile has contact information and a direct link to their portal for inquiries and applications.'
                    ],
                    [
                        'question' => 'Are the school fees accurate?',
                        'answer' => 'We work with schools to maintain up-to-date fee information. However, we recommend contacting the school directly for the most current fee structure.'
                    ],
                    [
                        'question' => 'How do I filter schools by location?',
                        'answer' => 'Use the location filter in the sidebar to select state and city. You can also use the search bar to find schools in specific areas.'
                    ],
                    [
                        'question' => 'Can I save schools to compare later?',
                        'answer' => 'Yes! Click the heart icon on any school card to save it to your favorites list for later comparison.'
                    ]
                ];

                foreach ($faqs as $index => $faq):
                ?>
                    <div class="bg-white rounded-xl md:rounded-2xl overflow-hidden border border-slate-200">
                        <button class="faq-question w-full text-left p-6 flex justify-between items-center hover:bg-slate-50 transition"
                            onclick="toggleFAQ(<?php echo $index; ?>)">
                            <span class="font-semibold text-slate-900 text-base md:text-lg"><?php echo $faq['question']; ?></span>
                            <i class="fas fa-chevron-down text-slate-400 transition-transform" id="faq-icon-<?php echo $index; ?>"></i>
                        </button>
                        <div class="faq-answer px-6 pb-6 hidden" id="faq-answer-<?php echo $index; ?>">
                            <p class="text-slate-600"><?php echo $faq['answer']; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-16 md:py-24 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-4 md:px-6">
        <div class="container mx-auto text-center">
            <h2 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">Ready to Find the Perfect School?</h2>
            <p class="text-lg md:text-xl text-indigo-100 mb-8 md:mb-12 max-w-2xl mx-auto">
                Start your search today and discover Nigeria's best educational institutions for your child.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4 md:gap-6">
                <a href="#search"
                    class="bg-white text-indigo-600 px-6 md:px-10 py-3 md:py-4 rounded-xl md:rounded-2xl font-bold text-lg hover:scale-105 transition shadow-xl">
                    Search Schools Now
                </a>
                <a href="/academixsuite/tenant/login.php"
                    class="bg-transparent border-2 border-white text-white px-6 md:px-10 py-3 md:py-4 rounded-xl md:rounded-2xl font-bold text-lg hover:bg-white/10 transition">
                    School Administrator Login
                </a>
            </div>
        </div>
    </section>

    <footer class="bg-slate-900 text-white pt-12 md:pt-20 pb-8 md:pb-12 px-4 md:px-6">
        <div class="container mx-auto grid grid-cols-1 md:grid-cols-4 gap-8 md:gap-12 mb-10 md:mb-16">
            <div class="col-span-1 md:col-span-2">
                <div class="flex items-center space-x-2 mb-6 md:mb-8">
                    <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-lg md:rounded-xl flex items-center justify-center text-white shadow-lg">
                        <i class="fas fa-school text-sm md:text-base"></i>
                    </div>
                    <span class="text-lg md:text-2xl font-bold tracking-tight text-white">Academix<span class="text-indigo-400">Suite</span></span>
                </div>
                <p class="text-slate-400 text-sm md:text-base max-w-sm">
                    Nigeria's premier school discovery and management platform connecting parents with quality educational institutions.
                </p>
            </div>
            <div>
                <h5 class="font-bold text-white uppercase text-sm tracking-widest mb-6 md:mb-8">For Parents</h5>
                <ul class="space-y-3 md:space-y-4 text-slate-400 font-medium text-sm">
                    <li><a href="#search" class="hover:text-white transition">Find Schools</a></li>
                    <li><a href="#why-choose" class="hover:text-white transition">Why Choose</a></li>
                    <li><a href="#how-it-works" class="hover:text-white transition">How It Works</a></li>
                    <li><a href="#faq" class="hover:text-white transition">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h5 class="font-bold text-white uppercase text-sm tracking-widest mb-6 md:mb-8">For Schools</h5>
                <ul class="space-y-3 md:space-y-4 text-slate-400 font-medium text-sm">
                    <li><a href="/academixsuite/tenant/login.php" class="hover:text-white transition">School Login</a></li>
                    <li><a href="/academixsuite/public/register.php" class="hover:text-white transition">Register School</a></li>
                    <li><a href="/academixsuite/public/pricing.php" class="hover:text-white transition">Pricing</a></li>
                    <li><a href="/academixsuite/public/contact.php" class="hover:text-white transition">Contact Support</a></li>
                </ul>
            </div>
        </div>
        <div class="text-center pt-8 border-t border-slate-800">
            <p class="text-slate-400 font-bold text-xs uppercase tracking-[0.3em]">
                © <?php echo date('Y'); ?> AcademixSuite. Empowering Nigerian Education.
            </p>
        </div>
    </footer>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS animations
        AOS.init({
            duration: 800,
            once: true,
            offset: 100
        });

        // Helper function to build query strings
        function buildQueryString(params = {}) {
            const currentParams = new URLSearchParams(window.location.search);

            // Update with new params
            Object.keys(params).forEach(key => {
                if (params[key] !== undefined && params[key] !== '') {
                    currentParams.set(key, params[key]);
                } else {
                    currentParams.delete(key);
                }
            });

            return currentParams.toString();
        }

        // Update sort function
        function updateSort(sortValue) {
            const params = new URLSearchParams(window.location.search);
            params.set('sort', sortValue);
            params.set('page', 1);
            window.location.search = params.toString();
        }

        // Clear all filters
        function clearFilters() {
            window.location.href = window.location.pathname;
        }

        // Remove specific filter
        function removeFilter(filterName) {
            const params = new URLSearchParams(window.location.search);
            params.delete(filterName);
            params.set('page', 1);
            window.location.search = params.toString();
        }

        // Apply fee filter
        function applyFeeFilter() {
            const minFee = document.querySelector('input[name="min_fee"]').value;
            const maxFee = document.querySelector('input[name="max_fee"]').value;
            const params = new URLSearchParams(window.location.search);

            if (minFee) params.set('min_fee', minFee);
            else params.delete('min_fee');

            if (maxFee) params.set('max_fee', maxFee);
            else params.delete('max_fee');

            params.set('page', 1);
            window.location.search = params.toString();
        }

        // FAQ toggle function
        function toggleFAQ(index) {
            const answer = document.getElementById('faq-answer-' + index);
            const icon = document.getElementById('faq-icon-' + index);

            if (answer.classList.contains('hidden')) {
                answer.classList.remove('hidden');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                answer.classList.add('hidden');
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }

        // Animate counter
        function animateCounter(element) {
            const target = parseInt(element.getAttribute('data-count'));
            const duration = 2000;
            const steps = 60;
            const increment = target / steps;
            let current = 0;

            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    element.textContent = target.toLocaleString();
                    clearInterval(timer);
                } else {
                    element.textContent = Math.floor(current).toLocaleString();
                }
            }, duration / steps);
        }

        // Initialize counter animation
        document.addEventListener('DOMContentLoaded', function() {
            const counter = document.querySelector('.stat-counter');
            if (counter) {
                setTimeout(() => {
                    animateCounter(counter);
                }, 500);
            }
        });

        // Scroll to top function
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Add scroll to top button
        window.addEventListener('scroll', function() {
            const scrollTopBtn = document.getElementById('scrollTopBtn');
            if (!scrollTopBtn) return;

            if (window.scrollY > 500) {
                scrollTopBtn.classList.remove('hidden');
            } else {
                scrollTopBtn.classList.add('hidden');
            }
        });

        // AJAX function to load cities based on state
        async function updateCityOptions(state) {
            if (!state) {
                // Reset to all cities
                const citySelect = document.querySelector('select[name="city"]');
                citySelect.innerHTML = '<option value="">All Cities</option>';
                return;
            }

            try {
                const response = await fetch(`../api/get-cities.php?state=${encodeURIComponent(state)}`);
                const cities = await response.json();

                const citySelect = document.querySelector('select[name="city"]');
                citySelect.innerHTML = '<option value="">All Cities</option>';

                cities.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    citySelect.appendChild(option);
                });
            } catch (error) {
                console.error('Error loading cities:', error);
            }
        }
    </script>

    <!-- Scroll to Top Button -->
    <button onclick="scrollToTop()" id="scrollTopBtn"
        class="fixed bottom-6 right-6 w-12 h-12 bg-indigo-600 text-white rounded-full shadow-lg hover:bg-indigo-700 transition z-50 hidden">
        <i class="fas fa-chevron-up"></i>
    </button>

</body>

</html>

<?php
// Helper function to build query strings
function buildQueryString($newParams = [])
{
    $params = $_GET;
    foreach ($newParams as $key => $value) {
        $params[$key] = $value;
    }
    return http_build_query($params);
}
?>