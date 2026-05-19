<?php
/**
 * School Public-Profile Editor (Tenant Admin)
 *
 * Lets a school admin customise everything that renders on the public
 * school_profile page (the {slug}.academixsuite.com landing page).
 *
 * Persistence layout:
 *   - Platform DB → `schools` row owns the headline copy, colors, hero/feature
 *     images, programs/testimonials JSON, mission/vision/principal message,
 *     admission status & deadline.
 *   - Platform DB → `school_contacts`, `school_facilities`, `school_gallery`
 *     hold structured records joined by school_id.
 *   - School DB → unchanged. The profile page reads stats/announcements/events
 *     from there but those are managed via their own admin screens.
 *
 * Every write checks the resolved $school['id'] against the session so one
 * tenant cannot edit another tenant's data.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_profile_admin.log');

if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');

require_once __DIR__ . '/../../../includes/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../../includes/session_config.php';
    session_start(academix_session_options());
}

// -- Authentication ---------------------------------------------------------
if (empty($_SESSION['school_auth'])
    || ($_SESSION['school_auth']['user_type'] ?? '') !== 'admin') {
    header('Location: ../../login.php?school_slug=' . urlencode($_SESSION['school_auth']['school_slug'] ?? ''));
    exit;
}

$schoolSlug = (string) ($_SESSION['school_auth']['school_slug'] ?? '');
$schoolId   = (int)    ($_SESSION['school_auth']['school_id']   ?? 0);
$adminName  = (string) ($_SESSION['school_auth']['name']        ?? 'Admin');

if ($schoolId <= 0 || $schoolSlug === '') {
    http_response_code(403);
    echo 'Session error. Please log out and back in.';
    exit;
}

$platformDb = Database::getPlatformConnection();

// -- Helpers ----------------------------------------------------------------
$columns_cache = [];
$columnsOf = function (string $table) use ($platformDb, &$columns_cache): array {
    if (isset($columns_cache[$table])) return $columns_cache[$table];
    try {
        $stmt = $platformDb->query("SHOW COLUMNS FROM `{$table}`");
        $cols = $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    } catch (Throwable $e) {
        error_log("school-profile editor: SHOW COLUMNS {$table}: " . $e->getMessage());
        $cols = [];
    }
    return $columns_cache[$table] = $cols;
};

$tableExists = function (string $table) use ($platformDb): bool {
    try {
        $stmt = $platformDb->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
};

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// MIME → safe-extension map used by every upload on this page.
$imageMimeExt = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$saveUploadedImage = function (array $file, string $purpose) use ($schoolId, $imageMimeExt): ?string {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return null; // 5 MB cap
    }
    $mime = function_exists('finfo_open')
        ? (function () use ($file) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m  = $fi ? finfo_file($fi, $file['tmp_name']) : '';
            if ($fi) finfo_close($fi);
            return (string) $m;
        })()
        : (function_exists('mime_content_type') ? (string) mime_content_type($file['tmp_name']) : '');

    if (!isset($imageMimeExt[$mime])) return null;
    $ext = $imageMimeExt[$mime];

    $root = dirname(__DIR__, 3);
    $rel  = 'assets/uploads/schools/' . $schoolId . '/profile';
    $abs  = $root . '/' . $rel;
    if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) {
        return null;
    }
    $name = $purpose . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = $abs . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return null;
    }
    return $rel . '/' . $name;
};

// -- CSRF -------------------------------------------------------------------
$csrfToken = Session::generateCsrfToken('school_profile_editor');

// -- Load current data ------------------------------------------------------
$stmt = $platformDb->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
$stmt->execute([$schoolId]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$school) {
    http_response_code(404);
    echo 'School record not found.';
    exit;
}
$schoolColumns = $columnsOf('schools');

$contacts = $tableExists('school_contacts')
    ? (function () use ($platformDb, $schoolId) {
        $s = $platformDb->prepare('SELECT * FROM school_contacts WHERE school_id = ? ORDER BY is_primary DESC, sort_order ASC, type ASC');
        $s->execute([$schoolId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    })()
    : [];

$facilities = $tableExists('school_facilities')
    ? (function () use ($platformDb, $schoolId) {
        $s = $platformDb->prepare('SELECT * FROM school_facilities WHERE school_id = ? ORDER BY sort_order ASC, id ASC');
        $s->execute([$schoolId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    })()
    : [];

$gallery = $tableExists('school_gallery')
    ? (function () use ($platformDb, $schoolId) {
        $s = $platformDb->prepare('SELECT * FROM school_gallery WHERE school_id = ? ORDER BY sort_order ASC, id DESC');
        $s->execute([$schoolId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    })()
    : [];

$reviews = $tableExists('school_reviews')
    ? (function () use ($platformDb, $schoolId) {
        $s = $platformDb->prepare('SELECT * FROM school_reviews WHERE school_id = ? ORDER BY created_at DESC LIMIT 30');
        $s->execute([$schoolId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    })()
    : [];

// -- Handle POST ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Session::validateCsrfToken($_POST['csrf_token'] ?? '', 'school_profile_editor')) {
        flash_set('error', 'Security token expired. Please refresh and try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {

            // ---------------- BASIC INFO / HERO / ABOUT -------------------
            case 'save_basics':
                $editableTextCols = [
                    'description', 'mission_statement', 'vision_statement', 'principal_message',
                    'landing_badge_text', 'landing_headline', 'landing_subheadline',
                    'landing_primary_cta_text', 'landing_secondary_cta_text',
                    'landing_intro_title', 'landing_intro_text',
                    'landing_highlight_title', 'landing_highlight_text',
                    'landing_cta_title', 'landing_cta_text',
                    'curriculum', 'school_type', 'address', 'city', 'state', 'country',
                    'phone', 'email', 'website',
                ];
                $sets = [];
                $vals = [];
                foreach ($editableTextCols as $col) {
                    if (in_array($col, $schoolColumns, true) && array_key_exists($col, $_POST)) {
                        $sets[] = "`$col` = ?";
                        $vals[] = trim((string) $_POST[$col]);
                    }
                }

                // Colours — validate format strictly so a bad value can't poison CSS.
                foreach (['primary_color', 'secondary_color'] as $colorCol) {
                    if (in_array($colorCol, $schoolColumns, true) && isset($_POST[$colorCol])) {
                        $v = (string) $_POST[$colorCol];
                        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                            $sets[] = "`$colorCol` = ?";
                            $vals[] = $v;
                        }
                    }
                }

                // Admission status / deadline.
                if (in_array('admission_status', $schoolColumns, true)
                    && in_array((string) ($_POST['admission_status'] ?? ''), ['open', 'closed', 'waiting_list'], true)) {
                    $sets[] = "`admission_status` = ?";
                    $vals[] = (string) $_POST['admission_status'];
                }
                if (in_array('admission_deadline', $schoolColumns, true)) {
                    $deadline = trim((string) ($_POST['admission_deadline'] ?? ''));
                    $sets[] = "`admission_deadline` = ?";
                    $vals[] = $deadline !== '' && strtotime($deadline) ? $deadline : null;
                }

                // Programs / Testimonials — stored as JSON.
                foreach (['landing_programs', 'landing_testimonials'] as $jsonCol) {
                    if (!in_array($jsonCol, $schoolColumns, true)) continue;
                    $rawList = $_POST[$jsonCol] ?? [];
                    if (!is_array($rawList)) $rawList = [];
                    $clean = [];
                    foreach ($rawList as $row) {
                        if (!is_array($row)) continue;
                        $row = array_map(static fn ($v) => trim((string) $v), $row);
                        if ($jsonCol === 'landing_programs' && $row['title'] !== '') {
                            $clean[] = ['title' => $row['title'], 'description' => $row['description'] ?? ''];
                        } elseif ($jsonCol === 'landing_testimonials' && ($row['quote'] ?? '') !== '') {
                            $clean[] = [
                                'name'  => $row['name']  ?? '',
                                'role'  => $row['role']  ?? '',
                                'quote' => $row['quote'] ?? '',
                            ];
                        }
                    }
                    $sets[] = "`$jsonCol` = ?";
                    $vals[] = json_encode($clean, JSON_UNESCAPED_UNICODE);
                }

                // Hero/feature image uploads (optional).
                foreach (['landing_hero_image', 'landing_feature_image', 'logo_path'] as $imgCol) {
                    if (!in_array($imgCol, $schoolColumns, true)) continue;
                    if (!isset($_FILES[$imgCol]) || $_FILES[$imgCol]['error'] === UPLOAD_ERR_NO_FILE) continue;
                    $savedPath = $saveUploadedImage($_FILES[$imgCol], str_replace('_', '-', $imgCol));
                    if ($savedPath !== null) {
                        $sets[] = "`$imgCol` = ?";
                        $vals[] = $savedPath;
                    }
                }

                if (!$sets) {
                    throw new Exception('No editable fields were submitted.');
                }
                $vals[] = $schoolId;
                $sql = 'UPDATE schools SET ' . implode(', ', $sets) . ' WHERE id = ?';
                $platformDb->prepare($sql)->execute($vals);

                flash_set('success', 'Profile basics updated.');
                break;

            // ---------------- CONTACTS ------------------------------------
            case 'save_contacts':
                if (!$tableExists('school_contacts')) {
                    throw new Exception('school_contacts table is not available.');
                }
                $contactCols = $columnsOf('school_contacts');
                $platformDb->beginTransaction();
                $platformDb->prepare('DELETE FROM school_contacts WHERE school_id = ?')->execute([$schoolId]);
                $rows = $_POST['contacts'] ?? [];
                if (!is_array($rows)) $rows = [];
                $sort = 0;
                $insertCols = ['school_id', 'type', 'label', 'value', 'is_primary', 'sort_order'];
                $insertCols = array_values(array_filter($insertCols, fn ($c) => $c === 'school_id' || in_array($c, $contactCols, true)));
                $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                $insertSql = 'INSERT INTO school_contacts (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";
                $ins = $platformDb->prepare($insertSql);
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $value = trim((string) ($r['value'] ?? ''));
                    if ($value === '') continue;
                    $type = in_array($r['type'] ?? '', ['email', 'phone', 'address', 'website', 'whatsapp', 'social'], true)
                        ? $r['type'] : 'phone';
                    $params = [];
                    foreach ($insertCols as $col) {
                        switch ($col) {
                            case 'school_id': $params[] = $schoolId; break;
                            case 'type':      $params[] = $type; break;
                            case 'label':     $params[] = trim((string) ($r['label'] ?? '')); break;
                            case 'value':     $params[] = $value; break;
                            case 'is_primary': $params[] = !empty($r['is_primary']) ? 1 : 0; break;
                            case 'sort_order': $params[] = $sort; break;
                            default:          $params[] = null;
                        }
                    }
                    $ins->execute($params);
                    $sort++;
                }
                $platformDb->commit();
                flash_set('success', 'Contacts saved.');
                break;

            // ---------------- FACILITIES ----------------------------------
            case 'save_facilities':
                if (!$tableExists('school_facilities')) {
                    throw new Exception('school_facilities table is not available.');
                }
                $facCols = $columnsOf('school_facilities');
                $platformDb->beginTransaction();
                $platformDb->prepare('DELETE FROM school_facilities WHERE school_id = ?')->execute([$schoolId]);
                $rows = $_POST['facilities'] ?? [];
                if (!is_array($rows)) $rows = [];
                $sort = 0;
                $insertCols = array_values(array_filter(
                    ['school_id', 'name', 'description', 'icon', 'is_active', 'sort_order'],
                    fn ($c) => $c === 'school_id' || in_array($c, $facCols, true)
                ));
                $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                $insertSql = 'INSERT INTO school_facilities (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";
                $ins = $platformDb->prepare($insertSql);
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $name = trim((string) ($r['name'] ?? ''));
                    if ($name === '') continue;
                    $params = [];
                    foreach ($insertCols as $col) {
                        switch ($col) {
                            case 'school_id':   $params[] = $schoolId; break;
                            case 'name':        $params[] = $name; break;
                            case 'description': $params[] = trim((string) ($r['description'] ?? '')); break;
                            case 'icon':        $params[] = trim((string) ($r['icon'] ?? '')); break;
                            case 'is_active':   $params[] = empty($r['is_inactive']) ? 1 : 0; break;
                            case 'sort_order':  $params[] = $sort; break;
                            default:            $params[] = null;
                        }
                    }
                    $ins->execute($params);
                    $sort++;
                }
                $platformDb->commit();
                flash_set('success', 'Facilities saved.');
                break;

            // ---------------- GALLERY ADD ---------------------------------
            case 'gallery_add':
                if (!$tableExists('school_gallery')) {
                    throw new Exception('school_gallery table is not available.');
                }
                $galCols = $columnsOf('school_gallery');
                $files = $_FILES['gallery_images'] ?? null;
                if (!$files || !is_array($files['tmp_name'])) {
                    throw new Exception('No image files were uploaded.');
                }
                $insertCols = array_values(array_filter(
                    ['school_id', 'image_url', 'caption', 'type', 'sort_order'],
                    fn ($c) => $c === 'school_id' || in_array($c, $galCols, true)
                ));
                $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                $ins = $platformDb->prepare(
                    'INSERT INTO school_gallery (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)"
                );
                $captions = $_POST['gallery_captions'] ?? [];
                $count = count($files['tmp_name']);
                $added = 0;
                for ($i = 0; $i < $count; $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $f = [
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                        'name'     => $files['name'][$i] ?? '',
                    ];
                    $path = $saveUploadedImage($f, 'gallery');
                    if (!$path) continue;
                    $params = [];
                    foreach ($insertCols as $col) {
                        switch ($col) {
                            case 'school_id':  $params[] = $schoolId; break;
                            case 'image_url':  $params[] = $path; break;
                            case 'caption':    $params[] = trim((string) ($captions[$i] ?? '')); break;
                            case 'type':       $params[] = 'campus'; break;
                            case 'sort_order': $params[] = 1000 + $i; break;
                            default:           $params[] = null;
                        }
                    }
                    $ins->execute($params);
                    $added++;
                }
                flash_set('success', "Added {$added} image(s) to the gallery.");
                break;

            // ---------------- GALLERY DELETE ------------------------------
            case 'gallery_delete':
                $imgId = (int) ($_POST['image_id'] ?? 0);
                if ($imgId <= 0) throw new Exception('Invalid image id.');
                // Strict tenant scoping — never trust the id alone.
                $sel = $platformDb->prepare('SELECT image_url FROM school_gallery WHERE id = ? AND school_id = ?');
                $sel->execute([$imgId, $schoolId]);
                $img = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$img) throw new Exception('Image not found.');
                $platformDb->prepare('DELETE FROM school_gallery WHERE id = ? AND school_id = ?')
                    ->execute([$imgId, $schoolId]);
                // Best-effort file removal.
                $abs = dirname(__DIR__, 3) . '/' . ltrim((string) $img['image_url'], '/');
                if (is_file($abs)) @unlink($abs);
                flash_set('success', 'Image removed.');
                break;

            // ---------------- REVIEW MODERATION ---------------------------
            case 'review_toggle':
                if (!$tableExists('school_reviews')) {
                    throw new Exception('school_reviews table is not available.');
                }
                $reviewId = (int) ($_POST['review_id'] ?? 0);
                $approve  = !empty($_POST['approve']) ? 1 : 0;
                $platformDb->prepare(
                    'UPDATE school_reviews SET is_approved = ? WHERE id = ? AND school_id = ?'
                )->execute([$approve, $reviewId, $schoolId]);
                flash_set('success', $approve ? 'Review approved.' : 'Review hidden.');
                break;

            default:
                throw new Exception('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($platformDb->inTransaction()) $platformDb->rollBack();
        error_log('school-profile editor error: ' . $ex->getMessage());
        flash_set('error', $ex->getMessage());
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// -- Render helpers ---------------------------------------------------------
$programs    = [];
$testimonials = [];
$pRaw = $school['landing_programs']     ?? null;
$tRaw = $school['landing_testimonials'] ?? null;
if ($pRaw) { $d = json_decode((string) $pRaw, true); if (is_array($d)) $programs    = $d; }
if ($tRaw) { $d = json_decode((string) $tRaw, true); if (is_array($d)) $testimonials = $d; }

$publicUrl = 'https://' . $e($schoolSlug) . '.academixsuite.com/';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>School Profile Editor · <?php echo $e($school['name']); ?></title>
<?php flash_render(); ?>
<style>
:root { --ink:#161616; --muted:#666; --line:#e4e1d8; --paper:#fff; --soft:#f5f5f0; --accent:<?php echo $e($school['primary_color'] ?? '#7c73ff'); ?>; }
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,sans-serif;background:#fafaf7;color:var(--ink);line-height:1.5}
.wrap{max-width:1080px;margin:0 auto;padding:24px 16px 80px}
header.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px;flex-wrap:wrap}
h1{font-size:24px;margin:0}
.lede{color:var(--muted);font-size:14px;margin-top:4px}
.preview-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;background:var(--accent);color:#fff;border-radius:10px;font-weight:600;font-size:14px}
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:24px;overflow-x:auto}
.tabs button{background:none;border:0;padding:12px 18px;font-size:14px;font-weight:500;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
.tabs button.active{color:var(--ink);border-bottom-color:var(--accent)}
.panel{display:none;background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;margin-bottom:16px}
.panel.active{display:block}
.panel h2{margin:0 0 4px;font-size:18px}
.panel .hint{font-size:13px;color:var(--muted);margin-bottom:20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
.field{margin-bottom:16px}
input[type=text], input[type=email], input[type=url], input[type=tel], input[type=date], input[type=color], select, textarea{
  width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;background:#fff;color:var(--ink)
}
textarea{min-height:90px;resize:vertical}
.row-actions{display:flex;gap:8px;margin-top:8px}
button.primary{background:var(--ink);color:#fff;border:0;padding:11px 22px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer}
button.secondary{background:#fff;color:var(--ink);border:1px solid var(--line);padding:9px 16px;border-radius:10px;font-size:13px;font-weight:500;cursor:pointer}
button.danger{background:#fff5f5;color:#b62121;border:1px solid #f7c4c4;padding:7px 12px;border-radius:8px;font-size:12px;cursor:pointer}
.repeater{border:1px dashed var(--line);border-radius:12px;padding:14px;margin-bottom:10px;background:var(--soft)}
.repeater .grid{margin-bottom:10px}
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-top:16px}
.gallery-grid figure{margin:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;position:relative}
.gallery-grid img{width:100%;height:120px;object-fit:cover;display:block}
.gallery-grid figcaption{font-size:11px;padding:8px;color:var(--muted)}
.gallery-grid form{position:absolute;top:6px;right:6px}
.flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
.flash.success{background:#e8f7ec;color:#1f6c33;border:1px solid #b6dfc3}
.flash.error{background:#fdecea;color:#a3271a;border:1px solid #f3c2bb}
table.reviews{width:100%;border-collapse:collapse;margin-top:8px}
table.reviews th, table.reviews td{padding:10px;border-bottom:1px solid var(--line);text-align:left;font-size:13px;vertical-align:top}
table.reviews th{color:var(--muted);font-weight:600;background:var(--soft)}
.pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}
.pill.approved{background:#e8f7ec;color:#1f6c33}
.pill.pending{background:#fff7e6;color:#9a6b00}
.sticky-save{position:sticky;bottom:0;background:#fff;border-top:1px solid var(--line);padding:14px 0;margin-top:24px;display:flex;justify-content:flex-end;gap:8px}
.colour-pair{display:flex;gap:8px;align-items:center}
.colour-pair input[type=color]{width:48px;height:42px;padding:2px;border-radius:8px}
.colour-pair input[type=text]{width:120px}
.image-preview{margin-top:8px;max-width:240px;border-radius:10px;border:1px solid var(--line)}
</style>
</head>
<body>
<div class="wrap">

  <header class="top">
    <div>
      <h1>School Profile Editor</h1>
      <p class="lede">Edit what visitors see at <a href="<?php echo $publicUrl; ?>" target="_blank" style="color:var(--accent)"><?php echo $e(rtrim($publicUrl, '/')); ?></a></p>
    </div>
    <a class="preview-btn" href="<?php echo $publicUrl; ?>" target="_blank">View live page →</a>
  </header>

  <nav class="tabs">
    <button data-tab="basics" class="active">Hero & About</button>
    <button data-tab="programs">Programs & Testimonials</button>
    <button data-tab="contacts">Contacts</button>
    <button data-tab="facilities">Facilities</button>
    <button data-tab="gallery">Gallery</button>
    <button data-tab="reviews">Reviews</button>
  </nav>

  <!-- ================= BASICS ================= -->
  <section class="panel active" id="panel-basics">
    <h2>Hero, About, Admissions</h2>
    <p class="hint">The headline area visitors see first, plus your school's story and current admission status.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
      <input type="hidden" name="action" value="save_basics">

      <div class="grid">
        <div class="field">
          <label>School name (display only)</label>
          <input type="text" value="<?php echo $e($school['name']); ?>" disabled>
        </div>
        <div class="field">
          <label>School type</label>
          <select name="school_type">
            <?php foreach (['nursery','primary','secondary','comprehensive','international','montessori','boarding','day'] as $opt): ?>
              <option value="<?php echo $opt; ?>" <?php echo ($school['school_type'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo ucfirst($opt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Curriculum</label>
          <input type="text" name="curriculum" value="<?php echo $e($school['curriculum'] ?? ''); ?>" placeholder="e.g. Nigerian / British / Montessori">
        </div>
      </div>

      <h3 style="margin-top:24px;font-size:15px">Hero section</h3>
      <div class="grid">
        <div class="field">
          <label>Badge text</label>
          <input type="text" name="landing_badge_text" value="<?php echo $e($school['landing_badge_text'] ?? ''); ?>" placeholder="Admissions open">
        </div>
        <div class="field">
          <label>Primary CTA label</label>
          <input type="text" name="landing_primary_cta_text" value="<?php echo $e($school['landing_primary_cta_text'] ?? ''); ?>" placeholder="Apply Now">
        </div>
        <div class="field">
          <label>Secondary CTA label</label>
          <input type="text" name="landing_secondary_cta_text" value="<?php echo $e($school['landing_secondary_cta_text'] ?? ''); ?>" placeholder="Portal Login">
        </div>
      </div>
      <div class="field">
        <label>Headline</label>
        <input type="text" name="landing_headline" value="<?php echo $e($school['landing_headline'] ?? ''); ?>">
      </div>
      <div class="field">
        <label>Sub-headline</label>
        <textarea name="landing_subheadline"><?php echo $e($school['landing_subheadline'] ?? ''); ?></textarea>
      </div>
      <div class="grid">
        <div class="field">
          <label>Hero image (cap 5MB, jpg/png/webp)</label>
          <input type="file" name="landing_hero_image" accept="image/png,image/jpeg,image/webp">
          <?php if (!empty($school['landing_hero_image'])): ?>
            <img class="image-preview" src="/<?php echo $e(ltrim((string) $school['landing_hero_image'], '/')); ?>" alt="">
          <?php endif; ?>
        </div>
        <div class="field">
          <label>Feature/secondary image</label>
          <input type="file" name="landing_feature_image" accept="image/png,image/jpeg,image/webp">
          <?php if (!empty($school['landing_feature_image'])): ?>
            <img class="image-preview" src="/<?php echo $e(ltrim((string) $school['landing_feature_image'], '/')); ?>" alt="">
          <?php endif; ?>
        </div>
        <div class="field">
          <label>School logo</label>
          <input type="file" name="logo_path" accept="image/png,image/jpeg,image/webp">
          <?php if (!empty($school['logo_path'])): ?>
            <img class="image-preview" src="/<?php echo $e(ltrim((string) $school['logo_path'], '/')); ?>" alt="" style="max-width:120px">
          <?php endif; ?>
        </div>
      </div>

      <h3 style="margin-top:24px;font-size:15px">About / Story</h3>
      <div class="field">
        <label>Short description</label>
        <textarea name="description"><?php echo $e($school['description'] ?? ''); ?></textarea>
      </div>
      <div class="grid">
        <div class="field">
          <label>Intro section title</label>
          <input type="text" name="landing_intro_title" value="<?php echo $e($school['landing_intro_title'] ?? ''); ?>">
        </div>
        <div class="field">
          <label>Highlight section title</label>
          <input type="text" name="landing_highlight_title" value="<?php echo $e($school['landing_highlight_title'] ?? ''); ?>">
        </div>
      </div>
      <div class="field">
        <label>Intro section body</label>
        <textarea name="landing_intro_text"><?php echo $e($school['landing_intro_text'] ?? ''); ?></textarea>
      </div>
      <div class="field">
        <label>Highlight section body</label>
        <textarea name="landing_highlight_text"><?php echo $e($school['landing_highlight_text'] ?? ''); ?></textarea>
      </div>
      <div class="grid">
        <div class="field">
          <label>Mission statement</label>
          <textarea name="mission_statement"><?php echo $e($school['mission_statement'] ?? ''); ?></textarea>
        </div>
        <div class="field">
          <label>Vision statement</label>
          <textarea name="vision_statement"><?php echo $e($school['vision_statement'] ?? ''); ?></textarea>
        </div>
      </div>
      <div class="field">
        <label>Head of school / principal's message</label>
        <textarea name="principal_message"><?php echo $e($school['principal_message'] ?? ''); ?></textarea>
      </div>

      <h3 style="margin-top:24px;font-size:15px">Closing CTA</h3>
      <div class="field">
        <label>CTA title</label>
        <input type="text" name="landing_cta_title" value="<?php echo $e($school['landing_cta_title'] ?? ''); ?>">
      </div>
      <div class="field">
        <label>CTA body</label>
        <textarea name="landing_cta_text"><?php echo $e($school['landing_cta_text'] ?? ''); ?></textarea>
      </div>

      <h3 style="margin-top:24px;font-size:15px">Brand colours</h3>
      <div class="grid">
        <div class="field">
          <label>Primary colour (hex)</label>
          <div class="colour-pair">
            <input type="color" value="<?php echo $e($school['primary_color'] ?? '#7c73ff'); ?>" oninput="this.nextElementSibling.value=this.value">
            <input type="text" name="primary_color" value="<?php echo $e($school['primary_color'] ?? '#7c73ff'); ?>" pattern="^#[0-9A-Fa-f]{6}$">
          </div>
        </div>
        <div class="field">
          <label>Secondary colour (hex)</label>
          <div class="colour-pair">
            <input type="color" value="<?php echo $e($school['secondary_color'] ?? '#b8ff61'); ?>" oninput="this.nextElementSibling.value=this.value">
            <input type="text" name="secondary_color" value="<?php echo $e($school['secondary_color'] ?? '#b8ff61'); ?>" pattern="^#[0-9A-Fa-f]{6}$">
          </div>
        </div>
      </div>

      <h3 style="margin-top:24px;font-size:15px">Admissions</h3>
      <div class="grid">
        <div class="field">
          <label>Status</label>
          <select name="admission_status">
            <?php foreach (['open','closed','waiting_list'] as $opt): ?>
              <option value="<?php echo $opt; ?>" <?php echo ($school['admission_status'] ?? 'open') === $opt ? 'selected' : ''; ?>>
                <?php echo str_replace('_', ' ', ucfirst($opt)); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Deadline (optional)</label>
          <input type="date" name="admission_deadline" value="<?php echo $e($school['admission_deadline'] ?? ''); ?>">
        </div>
      </div>

      <h3 style="margin-top:24px;font-size:15px">Location & top-level contact</h3>
      <div class="grid">
        <div class="field"><label>Phone</label><input type="tel" name="phone" value="<?php echo $e($school['phone'] ?? ''); ?>"></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?php echo $e($school['email'] ?? ''); ?>"></div>
        <div class="field"><label>Website</label><input type="url" name="website" value="<?php echo $e($school['website'] ?? ''); ?>" placeholder="https://"></div>
        <div class="field"><label>Address</label><input type="text" name="address" value="<?php echo $e($school['address'] ?? ''); ?>"></div>
        <div class="field"><label>City</label><input type="text" name="city" value="<?php echo $e($school['city'] ?? ''); ?>"></div>
        <div class="field"><label>State</label><input type="text" name="state" value="<?php echo $e($school['state'] ?? ''); ?>"></div>
        <div class="field"><label>Country</label><input type="text" name="country" value="<?php echo $e($school['country'] ?? ''); ?>"></div>
      </div>

      <div class="sticky-save"><button type="submit" class="primary">Save changes</button></div>
    </form>
  </section>

  <!-- ================= PROGRAMS & TESTIMONIALS ================= -->
  <section class="panel" id="panel-programs">
    <h2>Programs & Testimonials</h2>
    <p class="hint">Programs appear as cards on the landing page. Testimonials are quoted under "What parents say".</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
      <input type="hidden" name="action" value="save_basics">

      <h3 style="font-size:14px;margin-bottom:12px">Programs</h3>
      <div id="programs-list">
        <?php $programs = $programs ?: [['title'=>'', 'description'=>'']];
        foreach ($programs as $i => $prog): ?>
          <div class="repeater" data-block>
            <div class="grid">
              <div class="field"><label>Title</label><input type="text" name="landing_programs[<?php echo $i; ?>][title]" value="<?php echo $e($prog['title'] ?? ''); ?>"></div>
              <div class="field"><label>Description</label><input type="text" name="landing_programs[<?php echo $i; ?>][description]" value="<?php echo $e($prog['description'] ?? ''); ?>"></div>
            </div>
            <div class="row-actions"><button type="button" class="danger" onclick="this.closest('[data-block]').remove()">Remove</button></div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="secondary" onclick="addRepeater('programs-list','landing_programs', ['title','description'])">+ Add program</button>

      <h3 style="font-size:14px;margin:24px 0 12px">Testimonials</h3>
      <div id="testimonials-list">
        <?php $testimonials = $testimonials ?: [['name'=>'', 'role'=>'', 'quote'=>'']];
        foreach ($testimonials as $i => $t): ?>
          <div class="repeater" data-block>
            <div class="grid">
              <div class="field"><label>Author name</label><input type="text" name="landing_testimonials[<?php echo $i; ?>][name]" value="<?php echo $e($t['name'] ?? ''); ?>"></div>
              <div class="field"><label>Author role</label><input type="text" name="landing_testimonials[<?php echo $i; ?>][role]" value="<?php echo $e($t['role'] ?? ''); ?>"></div>
            </div>
            <div class="field"><label>Quote</label><textarea name="landing_testimonials[<?php echo $i; ?>][quote]"><?php echo $e($t['quote'] ?? ''); ?></textarea></div>
            <div class="row-actions"><button type="button" class="danger" onclick="this.closest('[data-block]').remove()">Remove</button></div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="secondary" onclick="addRepeater('testimonials-list','landing_testimonials', ['name','role','quote'])">+ Add testimonial</button>

      <div class="sticky-save"><button type="submit" class="primary">Save programs & testimonials</button></div>
    </form>
  </section>

  <!-- ================= CONTACTS ================= -->
  <section class="panel" id="panel-contacts">
    <h2>Contact entries</h2>
    <p class="hint">Each row appears in the public page's contact section.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
      <input type="hidden" name="action" value="save_contacts">
      <div id="contacts-list">
        <?php
        $contacts = $contacts ?: [['type'=>'phone','label'=>'','value'=>'','is_primary'=>0]];
        foreach ($contacts as $i => $c): ?>
          <div class="repeater" data-block>
            <div class="grid">
              <div class="field">
                <label>Type</label>
                <select name="contacts[<?php echo $i; ?>][type]">
                  <?php foreach (['email','phone','address','website','whatsapp','social'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo ($c['type'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo ucfirst($opt); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label>Label</label><input type="text" name="contacts[<?php echo $i; ?>][label]" value="<?php echo $e($c['label'] ?? ''); ?>" placeholder="e.g. Admissions office"></div>
              <div class="field"><label>Value</label><input type="text" name="contacts[<?php echo $i; ?>][value]" value="<?php echo $e($c['value'] ?? ''); ?>" required></div>
              <div class="field" style="display:flex;align-items:center;margin-top:24px;gap:8px">
                <input type="checkbox" name="contacts[<?php echo $i; ?>][is_primary]" value="1" <?php echo !empty($c['is_primary']) ? 'checked' : ''; ?>>
                <label style="margin:0">Show as primary</label>
              </div>
            </div>
            <div class="row-actions"><button type="button" class="danger" onclick="this.closest('[data-block]').remove()">Remove</button></div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="secondary" onclick="addRepeater('contacts-list','contacts', ['type','label','value','is_primary'])">+ Add contact</button>
      <div class="sticky-save"><button type="submit" class="primary">Save contacts</button></div>
    </form>
  </section>

  <!-- ================= FACILITIES ================= -->
  <section class="panel" id="panel-facilities">
    <h2>Facilities & services</h2>
    <p class="hint">These render as "What we offer" badges/cards on the public page.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
      <input type="hidden" name="action" value="save_facilities">
      <div id="facilities-list">
        <?php
        $facilities = $facilities ?: [['name'=>'','description'=>'','icon'=>'']];
        foreach ($facilities as $i => $f): ?>
          <div class="repeater" data-block>
            <div class="grid">
              <div class="field"><label>Name</label><input type="text" name="facilities[<?php echo $i; ?>][name]" value="<?php echo $e($f['name'] ?? ''); ?>"></div>
              <div class="field"><label>Icon (Font Awesome class, optional)</label><input type="text" name="facilities[<?php echo $i; ?>][icon]" value="<?php echo $e($f['icon'] ?? ''); ?>" placeholder="fa-school"></div>
            </div>
            <div class="field"><label>Description</label><textarea name="facilities[<?php echo $i; ?>][description]"><?php echo $e($f['description'] ?? ''); ?></textarea></div>
            <div class="row-actions">
              <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:12px">
                <input type="checkbox" name="facilities[<?php echo $i; ?>][is_inactive]" value="1" <?php echo isset($f['is_active']) && (int) $f['is_active'] === 0 ? 'checked' : ''; ?>>
                Hide from public page
              </label>
              <button type="button" class="danger" onclick="this.closest('[data-block]').remove()">Remove</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="secondary" onclick="addRepeater('facilities-list','facilities', ['name','icon','description'])">+ Add facility</button>
      <div class="sticky-save"><button type="submit" class="primary">Save facilities</button></div>
    </form>
  </section>

  <!-- ================= GALLERY ================= -->
  <section class="panel" id="panel-gallery">
    <h2>Photo gallery</h2>
    <p class="hint">Up to 12 most recent images render on the landing page. Each image is capped at 5MB and must be jpg/png/webp.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
      <input type="hidden" name="action" value="gallery_add">
      <div class="field">
        <label>Add images (you can select multiple)</label>
        <input type="file" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple>
      </div>
      <button type="submit" class="primary">Upload selected</button>
    </form>

    <div class="gallery-grid">
      <?php foreach ($gallery as $img): ?>
        <figure>
          <img src="/<?php echo $e(ltrim((string) $img['image_url'], '/')); ?>" alt="">
          <?php if (!empty($img['caption'])): ?><figcaption><?php echo $e($img['caption']); ?></figcaption><?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this image?')">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
            <input type="hidden" name="action" value="gallery_delete">
            <input type="hidden" name="image_id" value="<?php echo (int) $img['id']; ?>">
            <button type="submit" class="danger" title="Delete">×</button>
          </form>
        </figure>
      <?php endforeach; ?>
      <?php if (!$gallery): ?><p style="color:var(--muted);font-size:13px">No images uploaded yet.</p><?php endif; ?>
    </div>
  </section>

  <!-- ================= REVIEWS ================= -->
  <section class="panel" id="panel-reviews">
    <h2>Parent reviews</h2>
    <p class="hint">Reviews are submitted by parents on the public profile page. Approve them here to make them visible.</p>
    <?php if (!$reviews): ?>
      <p style="color:var(--muted);font-size:14px">No reviews yet.</p>
    <?php else: ?>
      <table class="reviews">
        <thead><tr><th>Parent</th><th>Rating</th><th>Comment</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reviews as $r): ?>
          <tr>
            <td><strong><?php echo $e($r['parent_name'] ?? '—'); ?></strong>
              <?php if (!empty($r['student_name'])): ?><div style="color:var(--muted);font-size:12px">parent of <?php echo $e($r['student_name']); ?></div><?php endif; ?>
            </td>
            <td><?php echo str_repeat('★', (int) ($r['rating'] ?? 0)); ?></td>
            <td><?php echo $e($r['comment'] ?? ''); ?></td>
            <td>
              <?php if (!empty($r['is_approved'])): ?>
                <span class="pill approved">Approved</span>
              <?php else: ?>
                <span class="pill pending">Pending</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
                <input type="hidden" name="action" value="review_toggle">
                <input type="hidden" name="review_id" value="<?php echo (int) $r['id']; ?>">
                <input type="hidden" name="approve" value="<?php echo !empty($r['is_approved']) ? '0' : '1'; ?>">
                <button type="submit" class="secondary"><?php echo !empty($r['is_approved']) ? 'Hide' : 'Approve'; ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

</div>

<script>
  // ---- Tabs ----
  document.querySelectorAll('.tabs button').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
      history.replaceState(null, '', '#' + btn.dataset.tab);
    });
  });
  // Open the tab in the URL hash on load (so post-save redirects land on the right tab).
  if (location.hash) {
    const tab = location.hash.slice(1);
    const btn = document.querySelector('.tabs button[data-tab="' + tab + '"]');
    if (btn) btn.click();
  }

  // ---- Generic repeater helper ----
  function addRepeater(containerId, namePrefix, fields) {
    const list = document.getElementById(containerId);
    const idx = list.querySelectorAll('[data-block]').length;
    const block = document.createElement('div');
    block.className = 'repeater';
    block.setAttribute('data-block', '');
    let inner = '<div class="grid">';
    fields.forEach(f => {
      const isCheckbox = (f === 'is_primary' || f === 'is_inactive');
      if (isCheckbox) {
        inner += `<div class="field" style="display:flex;align-items:center;margin-top:24px;gap:8px">
          <input type="checkbox" name="${namePrefix}[${idx}][${f}]" value="1">
          <label style="margin:0">${f.replace('_',' ')}</label></div>`;
      } else if (f === 'description' || f === 'quote') {
        inner += `<div class="field" style="grid-column:1/-1"><label>${f}</label><textarea name="${namePrefix}[${idx}][${f}]"></textarea></div>`;
      } else if (f === 'type') {
        inner += `<div class="field"><label>type</label><select name="${namePrefix}[${idx}][type]">
          <option value="phone">phone</option><option value="email">email</option>
          <option value="address">address</option><option value="website">website</option>
          <option value="whatsapp">whatsapp</option><option value="social">social</option></select></div>`;
      } else {
        inner += `<div class="field"><label>${f}</label><input type="text" name="${namePrefix}[${idx}][${f}]"></div>`;
      }
    });
    inner += '</div><div class="row-actions"><button type="button" class="danger" onclick="this.closest(\'[data-block]\').remove()">Remove</button></div>';
    block.innerHTML = inner;
    list.appendChild(block);
  }
</script>
</body>
</html>
