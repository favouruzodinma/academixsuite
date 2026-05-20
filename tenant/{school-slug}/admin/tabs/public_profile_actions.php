<?php
/**
 * Public-profile editing actions.
 *
 * Required by tenant/{school-slug}/admin/general.php; returns an array which
 * the parent file json_encodes. Inputs come from $_POST. Schema lookups are
 * defensive (SHOW COLUMNS) so the file is forward-compatible.
 *
 * Required parent-scope variables:
 *   - $action      (the action string)
 *   - $schoolSlug  (current tenant slug)
 *   - $school      (current tenant school row — at minimum 'id' and 'logo_path')
 */

$school = is_array($school ?? null) ? $school : ($GLOBALS['SCHOOL_DATA'] ?? []);
$schoolSlug = (string) ($schoolSlug ?? ($GLOBALS['SCHOOL_SLUG'] ?? ''));
if (empty($school) && $schoolSlug !== '' && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school) || empty($school['id'])) {
    return ['success' => false, 'message' => 'School context missing'];
}

$platformDb = \Database::getPlatformConnection();
$schoolId   = (int) $school['id'];

$columnsOf = static function (string $table) use ($platformDb): array {
    try {
        $stmt = $platformDb->query("SHOW COLUMNS FROM `{$table}`");
        return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    } catch (Throwable $e) {
        error_log("public_profile_actions: SHOW COLUMNS {$table}: " . $e->getMessage());
        return [];
    }
};
$tableExists = static function (string $table) use ($platformDb): bool {
    try {
        $s = $platformDb->prepare('SHOW TABLES LIKE ?'); $s->execute([$table]);
        return (bool) $s->fetchColumn();
    } catch (Throwable $e) { return false; }
};

// MIME → forced extension. Never trust the user-supplied filename.
$imageMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$saveImage = static function (array $f, string $purpose) use ($schoolId, $imageMime): ?string {
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) return null;
    if (($f['size'] ?? 0) > 5 * 1024 * 1024) return null;
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string) finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($f['tmp_name']);
    }
    if (!isset($imageMime[$mime])) return null;
    $ext = $imageMime[$mime];
    $root = dirname(__DIR__, 4); // four levels up from tabs/ to docroot
    $rel  = 'assets/uploads/schools/' . $schoolId . '/profile';
    $abs  = $root . '/' . $rel;
    if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) return null;
    $name = $purpose . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $abs . '/' . $name)) return null;
    return $rel . '/' . $name;
};

try {
    switch ($action) {

        // ------------------------------------------------ profile_save_basics
        case 'profile_save_basics': {
            $cols = $columnsOf('schools');
            // Whitelist of editable text columns. Each value is trimmed; only
            // columns that actually exist in this install are persisted.
            $textCols = [
                'description', 'mission_statement', 'vision_statement', 'principal_message',
                'landing_badge_text', 'landing_headline', 'landing_subheadline',
                'landing_primary_cta_text', 'landing_secondary_cta_text',
                'landing_intro_title', 'landing_intro_text',
                'landing_highlight_title', 'landing_highlight_text',
                'landing_cta_title', 'landing_cta_text',
                'curriculum', 'school_type', 'address', 'city', 'state', 'country',
                'phone', 'email', 'website',
            ];
            $sets = []; $vals = [];
            foreach ($textCols as $c) {
                if (in_array($c, $cols, true) && array_key_exists($c, $_POST)) {
                    $sets[] = "`$c` = ?";
                    $vals[] = trim((string) $_POST[$c]);
                }
            }
            // Colors — must match #RRGGBB exactly.
            foreach (['primary_color', 'secondary_color'] as $cc) {
                if (in_array($cc, $cols, true) && isset($_POST[$cc])
                    && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $_POST[$cc])) {
                    $sets[] = "`$cc` = ?"; $vals[] = (string) $_POST[$cc];
                }
            }
            // Admission status / deadline.
            if (in_array('admission_status', $cols, true)
                && in_array((string) ($_POST['admission_status'] ?? ''), ['open', 'closed', 'waiting_list'], true)) {
                $sets[] = "`admission_status` = ?"; $vals[] = (string) $_POST['admission_status'];
            }
            if (in_array('admission_deadline', $cols, true)) {
                $d = trim((string) ($_POST['admission_deadline'] ?? ''));
                $sets[] = "`admission_deadline` = ?";
                $vals[] = ($d !== '' && strtotime($d)) ? $d : null;
            }
            // Programs / testimonials — JSON columns.
            foreach (['landing_programs', 'landing_testimonials'] as $jc) {
                if (!in_array($jc, $cols, true)) continue;
                $raw = $_POST[$jc] ?? [];
                $clean = [];
                if (is_array($raw)) foreach ($raw as $row) {
                    if (!is_array($row)) continue;
                    $row = array_map(static fn ($v) => trim((string) $v), $row);
                    if ($jc === 'landing_programs' && ($row['title'] ?? '') !== '') {
                        $clean[] = ['title' => $row['title'], 'description' => $row['description'] ?? ''];
                    } elseif ($jc === 'landing_testimonials' && ($row['quote'] ?? '') !== '') {
                        $clean[] = ['name' => $row['name'] ?? '', 'role' => $row['role'] ?? '', 'quote' => $row['quote']];
                    }
                }
                $sets[] = "`$jc` = ?";
                $vals[] = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
            // Image uploads.
            foreach (['landing_hero_image', 'landing_feature_image', 'logo_path'] as $ic) {
                if (!in_array($ic, $cols, true)) continue;
                if (!isset($_FILES[$ic]) || $_FILES[$ic]['error'] === UPLOAD_ERR_NO_FILE) continue;
                $saved = $saveImage($_FILES[$ic], str_replace('_', '-', $ic));
                if ($saved !== null) { $sets[] = "`$ic` = ?"; $vals[] = $saved; }
            }
            if (!$sets) return ['success' => false, 'message' => 'No fields submitted'];
            $vals[] = $schoolId;
            $platformDb->prepare('UPDATE schools SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            return ['success' => true, 'message' => 'Public profile saved.'];
        }

        // ------------------------------------------------ profile_save_contacts
        case 'profile_save_contacts': {
            if (!$tableExists('school_contacts')) {
                return ['success' => false, 'message' => 'school_contacts table missing — run the migration.'];
            }
            $cols = $columnsOf('school_contacts');
            $insertCols = array_values(array_filter(
                ['school_id', 'type', 'label', 'value', 'is_primary', 'sort_order'],
                static fn ($c) => $c === 'school_id' || in_array($c, $cols, true)
            ));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sql = 'INSERT INTO school_contacts (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";

            $platformDb->beginTransaction();
            $platformDb->prepare('DELETE FROM school_contacts WHERE school_id = ?')->execute([$schoolId]);
            $rows = is_array($_POST['contacts'] ?? null) ? $_POST['contacts'] : [];
            $stmt = $platformDb->prepare($sql);
            $sort = 0;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $value = trim((string) ($r['value'] ?? ''));
                if ($value === '') continue;
                $type = in_array($r['type'] ?? '', ['email', 'phone', 'address', 'website', 'whatsapp', 'social'], true)
                    ? $r['type'] : 'phone';
                $params = [];
                foreach ($insertCols as $c) {
                    switch ($c) {
                        case 'school_id':  $params[] = $schoolId; break;
                        case 'type':       $params[] = $type; break;
                        case 'label':      $params[] = trim((string) ($r['label'] ?? '')); break;
                        case 'value':      $params[] = $value; break;
                        case 'is_primary': $params[] = !empty($r['is_primary']) ? 1 : 0; break;
                        case 'sort_order': $params[] = $sort; break;
                        default:           $params[] = null;
                    }
                }
                $stmt->execute($params);
                $sort++;
            }
            $platformDb->commit();
            return ['success' => true, 'message' => 'Contacts saved.'];
        }

        // ----------------------------------------------- profile_save_facilities
        case 'profile_save_facilities': {
            if (!$tableExists('school_facilities')) {
                return ['success' => false, 'message' => 'school_facilities table missing — run the migration.'];
            }
            $cols = $columnsOf('school_facilities');
            $insertCols = array_values(array_filter(
                ['school_id', 'name', 'description', 'icon', 'is_active', 'sort_order'],
                static fn ($c) => $c === 'school_id' || in_array($c, $cols, true)
            ));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sql = 'INSERT INTO school_facilities (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";

            $platformDb->beginTransaction();
            $platformDb->prepare('DELETE FROM school_facilities WHERE school_id = ?')->execute([$schoolId]);
            $rows = is_array($_POST['facilities'] ?? null) ? $_POST['facilities'] : [];
            $stmt = $platformDb->prepare($sql);
            $sort = 0;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;
                $params = [];
                foreach ($insertCols as $c) {
                    switch ($c) {
                        case 'school_id':   $params[] = $schoolId; break;
                        case 'name':        $params[] = $name; break;
                        case 'description': $params[] = trim((string) ($r['description'] ?? '')); break;
                        case 'icon':        $params[] = trim((string) ($r['icon'] ?? '')); break;
                        case 'is_active':   $params[] = empty($r['is_inactive']) ? 1 : 0; break;
                        case 'sort_order':  $params[] = $sort; break;
                        default:            $params[] = null;
                    }
                }
                $stmt->execute($params);
                $sort++;
            }
            $platformDb->commit();
            return ['success' => true, 'message' => 'Facilities saved.'];
        }

        // ----------------------------------------------- profile_gallery_add
        case 'profile_gallery_add': {
            if (!$tableExists('school_gallery')) {
                return ['success' => false, 'message' => 'school_gallery table missing — run the migration.'];
            }
            $cols = $columnsOf('school_gallery');
            $insertCols = array_values(array_filter(
                ['school_id', 'image_url', 'caption', 'type', 'sort_order'],
                static fn ($c) => $c === 'school_id' || in_array($c, $cols, true)
            ));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sql = 'INSERT INTO school_gallery (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";
            $stmt = $platformDb->prepare($sql);

            $files = $_FILES['gallery_images'] ?? null;
            if (!$files || !is_array($files['tmp_name'] ?? null)) {
                return ['success' => false, 'message' => 'No image files received.'];
            }
            $captions = $_POST['gallery_captions'] ?? [];
            $added = 0;
            $count = count($files['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $f = [
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i] ?? 0,
                    'name'     => $files['name'][$i] ?? '',
                ];
                $path = $saveImage($f, 'gallery');
                if (!$path) continue;
                $params = [];
                foreach ($insertCols as $c) {
                    switch ($c) {
                        case 'school_id':  $params[] = $schoolId; break;
                        case 'image_url':  $params[] = $path; break;
                        case 'caption':    $params[] = trim((string) ($captions[$i] ?? '')); break;
                        case 'type':       $params[] = 'campus'; break;
                        case 'sort_order': $params[] = 1000 + $i; break;
                        default:           $params[] = null;
                    }
                }
                $stmt->execute($params);
                $added++;
            }
            return ['success' => true, 'message' => "Added {$added} image(s) to the gallery."];
        }

        // ----------------------------------------------- profile_gallery_delete
        case 'profile_gallery_delete': {
            $imgId = (int) ($_POST['image_id'] ?? 0);
            if ($imgId <= 0) return ['success' => false, 'message' => 'Invalid image id.'];
            $sel = $platformDb->prepare('SELECT image_url FROM school_gallery WHERE id = ? AND school_id = ?');
            $sel->execute([$imgId, $schoolId]);
            $img = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$img) return ['success' => false, 'message' => 'Image not found.'];
            $platformDb->prepare('DELETE FROM school_gallery WHERE id = ? AND school_id = ?')
                       ->execute([$imgId, $schoolId]);
            $abs = dirname(__DIR__, 4) . '/' . ltrim((string) $img['image_url'], '/');
            if (is_file($abs)) @unlink($abs);
            return ['success' => true, 'message' => 'Image removed.'];
        }

        // ----------------------------------------------- profile_review_toggle
        case 'profile_review_toggle': {
            $reviewId = (int) ($_POST['review_id'] ?? 0);
            $approve  = !empty($_POST['approve']) ? 1 : 0;
            $platformDb->prepare(
                'UPDATE school_reviews SET is_approved = ? WHERE id = ? AND school_id = ?'
            )->execute([$approve, $reviewId, $schoolId]);
            return ['success' => true, 'message' => $approve ? 'Review approved.' : 'Review hidden.'];
        }
    }
} catch (Throwable $e) {
    if ($platformDb->inTransaction()) $platformDb->rollBack();
    error_log('public_profile_actions error (' . $action . '): ' . $e->getMessage());
    return ['success' => false, 'message' => $e->getMessage()];
}

return ['success' => false, 'message' => 'Unhandled profile action.'];
