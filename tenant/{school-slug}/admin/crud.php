<?php
$currentPage = basename(__FILE__);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/crud.log');

if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start(['cookie_lifetime' => 86400, 'read_and_close' => false]);
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

if (empty($schoolSlug)) {
    header('HTTP/1.1 400 Bad Request');
    die('School identifier missing');
}

$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if ($_SESSION['school_auth']['school_slug'] === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

if ($userType !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit;
}

require_once __DIR__ . '/../../../includes/autoload.php';

$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
    }
} catch (Exception $e) {
    error_log("DB connection error: " . $e->getMessage());
    $schoolDb = null;
}

$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

if ($schoolDb) {
    try {
        $userStmt = $schoolDb->prepare("
            SELECT u.*, r.name as role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND u.school_id = ?
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
            } elseif (isset($_SESSION['school_user']['name'])) {
                $adminUser = [
                    'name' => $_SESSION['school_user']['name'],
                    'role_name' => 'Administrator'
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching admin user: " . $e->getMessage());
    }
}

$apiBaseUrl = '../../api/crud.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Manager - <?php echo htmlspecialchars($school['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>
<body>

<div class="body-overlay"></div>
<button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Settings">
    <i class="ri-settings-3-line animate-spin"></i>
</button>
<div class="theme-customization-sidebar w-100 bg-base h-100vh overflow-y-auto position-fixed end-0 top-0">
    <div class="d-flex align-items-center gap-3 py-16 px-24 justify-content-between border-bottom">
        <div>
            <h6 class="text-sm dark:text-white">Theme Settings</h6>
            <p class="text-xs mb-0 text-neutral-500 dark:text-neutral-200">Customize and preview instantly</p>
        </div>
        <button data-slot="button" class="theme-customization-sidebar__close text-neutral-900 bg-transparent text-hover-primary-600 d-flex text-xl">
            <i class="ri-close-fill"></i>
        </button>
    </div>
    <div class="d-flex flex-column gap-48 p-24 overflow-y-auto flex-grow-1">
        <div class="theme-setting-item">
            <h6 class="fw-medium text-primary-light text-md mb-3">Theme Mode</h6>
            <div class="d-grid grid-cols-3 gap-3 dark-light-mode">
                <button type="button" class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl active" data-theme="light" aria-label="light"><i class="ri-sun-line"></i></button>
                <button type="button" class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl" data-theme="dark" aria-label="dark"><i class="ri-moon-line"></i></button>
                <button type="button" class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl" data-theme="system" aria-label="system"><i class="ri-computer-line"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
<?php include_once('includes/sidebar.php'); ?>
<main class="dashboard-main">
    <?php include_once('includes/header.php'); ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Data Manager</h1>
                <div>
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light"> / Data Manager</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row align-items-center mb-24">
                    <div class="col-md-4">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Select Table</label>
                        <select id="tableSelector" class="form-control form-select">
                            <option value="">-- Choose a table --</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div id="tableInfo" class="text-sm text-secondary-light mt-16"></div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end justify-content-md-end mt-md-0 mt-16">
                        <button type="button" class="btn btn-primary-600 d-flex align-items-center gap-6" id="addNewBtn" disabled>
                            <i class="ri-add-large-line"></i>
                            <span>Add New Record</span>
                        </button>
                    </div>
                </div>

                <div id="crudTableWrapper" style="display:none;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-top border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <form class="navbar-search dt-search m-0" onsubmit="return false;">
                                <input type="text" class="dt-input bg-transparent radius-4" id="crudSearch" placeholder="Search...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span>Rows per page:</span>
                            <div class="dt-length">
                                <select id="crudPerPage" class="dt-input form-control form-select">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table bordered-table mb-0" id="crudTable">
                            <thead>
                                <tr></tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-top border-neutral-200">
                        <div class="text-sm text-secondary-light" id="crudInfo"></div>
                        <nav>
                            <ul class="pagination mb-0" id="crudPagination"></ul>
                        </nav>
                    </div>
                </div>

                <div id="noTableSelected" class="text-center py-48">
                    <i class="ri-database-2-line text-primary-light" style="font-size: 64px;"></i>
                    <h5 class="mt-16 text-secondary-light">Select a table from the dropdown above to manage its data</h5>
                </div>
            </div>
        </div>
    </div>

    <footer class="d-footer">
        <div>
            <p class="mb-0 text-center">&copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
        </div>
    </footer>
</main>

<div class="modal fade" id="crudModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-header">
                <h5 class="modal-title" id="crudModalTitle">Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="crudModalBody">
                <div id="crudFormLoading" class="text-center py-24">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <form id="crudForm" style="display:none;">
                    <input type="hidden" name="record_id" id="recordId" value="">
                    <div id="crudFormFields"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="crudSaveBtn" class="btn btn-primary-600 border border-primary-600 text-md px-24 py-12 radius-8">Save</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are you sure you want to delete this record?</h6>
                <p class="text-sm text-secondary-light mt-8" id="deleteRecordInfo"></p>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmDeleteBtn" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">Yes, Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="toastModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content radius-16 bg-base border-0">
            <div class="modal-body text-center py-32 px-24">
                <span class="mb-16 fs-1 line-height-1 d-block" id="toastIcon">
                    <iconify-icon icon="ri:checkmark-circle-fill" class="text-success-600" style="font-size: 48px;"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-8" id="toastTitle">Success</h6>
                <p class="text-sm text-secondary-light mb-0" id="toastMessage">Operation completed successfully.</p>
                <button type="button" class="btn btn-primary-600 mt-24 px-32" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/flatpickr.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
const API_BASE = '<?php echo $apiBaseUrl; ?>';
const SCHOOL_SLUG = '<?php echo $schoolSlug; ?>';

let currentTable = '';
let currentPage = 1;
let currentPerPage = 25;
let currentSearch = '';
let currentSortBy = '';
let currentSortDir = 'DESC';
let currentSchema = null;
let currentRelated = {};
let allTables = [];
let editingId = null;
let deleteId = null;

function getApiUrl(action, params) {
    const p = new URLSearchParams({ school_slug: SCHOOL_SLUG, action: action });
    for (const [k, v] of Object.entries(params || {})) {
        p.set(k, v);
    }
    return API_BASE + '?' + p.toString();
}

function apiPost(action, data) {
    return $.ajax({
        url: API_BASE,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(Object.assign({ school_slug: SCHOOL_SLUG, action: action }, data)),
        dataType: 'json'
    });
}

function showToast(icon, title, message, isError) {
    $('#toastIcon').html(icon);
    $('#toastTitle').text(title).css('color', isError ? '#dc2626' : '');
    $('#toastMessage').text(message);
    $('#toastModal').modal('show');
}

function showError(msg) {
    showToast(
        '<iconify-icon icon="ri:error-warning-fill" class="text-danger-600" style="font-size:48px;"></iconify-icon>',
        'Error', msg, true
    );
}

function showSuccess(msg) {
    showToast(
        '<iconify-icon icon="ri:checkmark-circle-fill" class="text-success-600" style="font-size:48px;"></iconify-icon>',
        'Success', msg, false
    );
}

function loadTables() {
    $.getJSON(getApiUrl('tables'), function(res) {
        if (res.success && res.tables) {
            allTables = res.tables;
            const sel = $('#tableSelector');
            sel.empty().append('<option value="">-- Choose a table --</option>');
            allTables.forEach(function(t) {
                sel.append('<option value="' + t.name + '">' + t.label + '</option>');
            });
        }
    }).fail(function() {
        showError('Failed to load tables');
    });
}

function loadSchema(table) {
    return $.getJSON(getApiUrl('schema', { table: table }));
}

function loadData(table, page, perPage, search, sortBy, sortDir) {
    const params = {
        table: table,
        page: page || currentPage,
        per_page: perPage || currentPerPage,
        sort_by: sortBy || currentSortBy,
        sort_dir: sortDir || currentSortDir
    };
    if (search) params.search = search;
    return $.getJSON(getApiUrl('list', params));
}

function getColumnLabel(colName, schema) {
    const labels = {
        'id': 'ID',
        'school_id': 'School',
        'campus_id': 'Campus',
        'created_at': 'Created At',
        'updated_at': 'Updated At',
        'deleted_at': 'Deleted At',
        'is_active': 'Active',
        'status': 'Status',
    };
    if (labels[colName]) return labels[colName];
    return colName.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
}

function shouldShowColumn(colName, schema) {
    const hidden = ['password', 'remember_token', 'api_token', 'verification_token', 'reset_token',
                    'oauth_token', 'oauth_token_secret', 'two_factor_secret', 'two_factor_recovery_codes',
                    'deleted_at'];
    if (hidden.includes(colName)) return false;
    if (colName === 'school_id') return false;
    if (colName === 'campus_id') return schema.columns[colName] && Object.keys(schema.foreign_keys).includes(colName) ? false : true;
    return true;
}

function shouldShowInForm(colName, schema) {
    const skip = ['created_at', 'updated_at', 'deleted_at', 'school_id'];
    if (skip.includes(colName)) return false;
    if (colName === schema.auto_increment) return false;
    return true;
}

function formatValue(val, colName, colInfo, schema) {
    if (val === null || val === undefined || val === '') return '<span class="text-secondary-light">--</span>';

    if (colName === 'is_active' || colName === 'status') {
        if (colName === 'status') {
            if (String(val).toLowerCase() === 'active') {
                return '<span class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>';
            } else if (String(val).toLowerCase() === 'inactive') {
                return '<span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>';
            }
            return '<span class="px-24 py-4 radius-4 fw-medium text-sm">' + htmlEscape(String(val)) + '</span>';
        }
        return val ? '<span class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Yes</span>'
                   : '<span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">No</span>';
    }

    if (colInfo.type === 'date' || colInfo.type === 'datetime' || colInfo.type === 'timestamp') {
        if (colInfo.type === 'date') return val;
        const d = new Date(val.replace(' ', 'T'));
        if (!isNaN(d)) return d.toLocaleString();
        return htmlEscape(String(val));
    }

    if (colInfo.type === 'text' || colInfo.type === 'mediumtext' || colInfo.type === 'longtext') {
        const s = String(val);
        return s.length > 100 ? htmlEscape(s.substring(0, 100)) + '...' : htmlEscape(s);
    }

    if ((colInfo.type === 'tinyint' || colInfo.type === 'int' || colInfo.type === 'decimal' || colInfo.type === 'float' || colInfo.type === 'double') && colName === 'id') {
        return htmlEscape(String(val));
    }

    return htmlEscape(String(val));
}

function htmlEscape(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function renderTable(data, schema) {
    const columns = schema.columns;
    const colNames = Object.keys(columns).filter(function(c) { return shouldShowColumn(c, schema); });
    const pk = schema.primary_key || 'id';

    let thead = '<th scope="col" class="text-center" style="width:60px;">#</th>';
    colNames.forEach(function(c) {
        const label = getColumnLabel(c, schema);
        const sortedBy = currentSortBy === c;
        const dir = sortedBy && currentSortDir === 'ASC' ? 'DESC' : 'ASC';
        const icon = sortedBy ? (currentSortDir === 'ASC' ? '&#x25B2;' : '&#x25BC;') : '';
        thead += '<th scope="col" class="sortable" data-sort="' + c + '" data-dir="' + dir + '" style="cursor:pointer;">' + label + ' ' + icon + '</th>';
    });
    thead += '<th scope="col" style="width:80px;">Action</th>';
    $('#crudTable thead').html('<tr>' + thead + '</tr>');

    let tbody = '';
    const rows = data.data || [];
    const start = (data.page - 1) * data.per_page + 1;

    if (rows.length === 0) {
        tbody = '<tr><td colspan="' + (colNames.length + 2) + '" class="text-center py-20"><p class="text-secondary-light mb-0">No records found</p></td></tr>';
    } else {
        rows.forEach(function(row, idx) {
            const recordPk = row[pk];
            tbody += '<tr>';
            tbody += '<td class="text-center">' + (start + idx) + '</td>';
            colNames.forEach(function(c) {
                const colInfo = columns[c];
                let val = row[c];
                if (c !== pk && schema.foreign_keys[c] && val) {
                    const ref = schema.foreign_keys[c];
                    const refLabel = ref.table + '.' + ref.column;
                    tbody += '<td>' + formatValue(val, c, colInfo, schema) + '</td>';
                } else {
                    tbody += '<td>' + formatValue(val, c, colInfo, schema) + '</td>';
                }
            });
            tbody += '<td>';
            tbody += '<div class="btn-group">';
            tbody += '<button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">';
            tbody += '<iconify-icon icon="tabler:dots-vertical"></iconify-icon>';
            tbody += '</button>';
            tbody += '<ul class="dropdown-menu dropdown-menu-lg-end border p-12">';
            tbody += '<li><button class="edit-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-id="' + recordPk + '"><i class="ri-edit-2-line"></i> Edit</button></li>';
            tbody += '<li><button class="delete-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-id="' + recordPk + '"><i class="ri-delete-bin-6-line"></i> Delete</button></li>';
            tbody += '</ul>';
            tbody += '</div>';
            tbody += '</td>';
            tbody += '</tr>';
        });
    }

    $('#crudTable tbody').html(tbody);

    $('#crudInfo').text('Showing ' + start + ' to ' + Math.min(start + rows.length - 1, data.total) + ' of ' + data.total + ' entries');

    renderPagination(data);
}

function renderPagination(data) {
    const totalPages = data.total_pages;
    const page = data.page;
    let html = '';

    if (totalPages <= 1) {
        $('#crudPagination').html('');
        return;
    }

    html += '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (page - 1) + '">Previous</a></li>';

    const maxVisible = 5;
    let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }

    for (let i = startPage; i <= endPage; i++) {
        html += '<li class="page-item' + (i === page ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
    }

    html += '<li class="page-item' + (page >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (page + 1) + '">Next</a></li>';

    $('#crudPagination').html(html);
}

function refreshData() {
    if (!currentTable) return;
    $('#crudTableWrapper').addClass('opacity-50');
    loadData(currentTable, currentPage, currentPerPage, currentSearch, currentSortBy, currentSortDir)
        .done(function(res) {
            if (res.success === false) {
                showError(res.error || 'Failed to load data');
                return;
            }
            renderTable(res, res.schema);
        })
        .fail(function(xhr) {
            let msg = 'Failed to load data';
            try {
                const r = JSON.parse(xhr.responseText);
                msg = r.error || msg;
            } catch(e) {}
            showError(msg);
        })
        .always(function() {
            $('#crudTableWrapper').removeClass('opacity-50');
        });
}

function buildFormFields(schema, record, related) {
    const columns = schema.columns;
    const colNames = Object.keys(columns);
    let html = '';

    colNames.forEach(function(colName) {
        if (!shouldShowInForm(colName, schema)) return;
        const colInfo = columns[colName];
        const label = getColumnLabel(colName, schema);
        const value = record ? record[colName] : '';
        const isRequired = colInfo.nullable === false && colName !== schema.auto_increment;
        const requiredAttr = isRequired ? ' required' : '';

        html += '<div class="mb-20">';
        html += '<label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">' + label + (isRequired ? ' <span class="text-danger">*</span>' : '') + '</label>';

        if (related[colName] && related[colName].options) {
            html += '<select class="form-control form-select" name="' + colName + '" id="field_' + colName + '"' + requiredAttr + '>';
            html += '<option value="">-- Select --</option>';
            related[colName].options.forEach(function(opt) {
                const selected = String(opt.value) === String(value) ? ' selected' : '';
                html += '<option value="' + htmlEscape(String(opt.value)) + '"' + selected + '>' + htmlEscape(String(opt.label)) + '</option>';
            });
            html += '</select>';
        } else if (colInfo.type === 'text' || colInfo.type === 'mediumtext' || colInfo.type === 'longtext') {
            html += '<textarea class="form-control" name="' + colName + '" id="field_' + colName + '" rows="4"' + requiredAttr + '>' + htmlEscape(String(value !== null && value !== undefined ? value : '')) + '</textarea>';
        } else if (colInfo.type === 'tinyint' && (colName === 'is_active' || colName === 'status' && colInfo.full_type === 'tinyint')) {
            html += '<select class="form-control form-select" name="' + colName + '" id="field_' + colName + '"' + requiredAttr + '>';
            const checked = value == 1 || value === '1' || value === true;
            html += '<option value="1"' + (checked ? ' selected' : '') + '>Yes</option>';
            html += '<option value="0"' + (!checked ? ' selected' : '') + '>No</option>';
            html += '</select>';
        } else if (colInfo.type === 'enum' || (colInfo.full_type && colInfo.full_type.startsWith('enum'))) {
            const enumValues = colInfo.full_type.match(/'([^']+)'/g) || [];
            html += '<select class="form-control form-select" name="' + colName + '" id="field_' + colName + '"' + requiredAttr + '>';
            enumValues.forEach(function(ev) {
                const v = ev.replace(/'/g, '');
                const selected = v === String(value) ? ' selected' : '';
                html += '<option value="' + htmlEscape(v) + '"' + selected + '>' + htmlEscape(v.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); })) + '</option>';
            });
            html += '</select>';
        } else if (colInfo.type === 'date') {
            html += '<input type="date" class="form-control" name="' + colName + '" id="field_' + colName + '" value="' + htmlEscape(String(value || '')) + '"' + requiredAttr + '>';
        } else if (colInfo.type === 'datetime' || colInfo.type === 'timestamp') {
            html += '<input type="datetime-local" class="form-control" name="' + colName + '" id="field_' + colName + '" value="' + htmlEscape(String(value || '')) + '"' + requiredAttr + '>';
        } else if (colInfo.type === 'int' || colInfo.type === 'bigint' || colInfo.type === 'smallint' || colInfo.type === 'tinyint') {
            html += '<input type="number" class="form-control" name="' + colName + '" id="field_' + colName + '" value="' + htmlEscape(String(value !== null && value !== undefined ? value : '')) + '"' + requiredAttr + '>';
        } else if (colInfo.type === 'decimal' || colInfo.type === 'float' || colInfo.type === 'double') {
            html += '<input type="number" step="0.01" class="form-control" name="' + colName + '" id="field_' + colName + '" value="' + htmlEscape(String(value !== null && value !== undefined ? value : '')) + '"' + requiredAttr + '>';
        } else if (colInfo.type === 'password') {
            html += '<input type="password" class="form-control" name="' + colName + '" id="field_' + colName + '"' + requiredAttr + '>';
        } else if (colInfo.type === 'email') {
            html += '<input type="email" class="form-control" name="' + colName + '" id="field_' + colName + '" value="' + htmlEscape(String(value || '')) + '"' + requiredAttr + '>';
        } else {
            html += '<input type="text" class="form-control" name="' + colName + '" id="field_' + colName + '" value="' + htmlEscape(String(value || '')) + '"' + requiredAttr + '>';
        }

        if (schema.foreign_keys[colName]) {
            const ref = schema.foreign_keys[colName];
            html += '<small class="text-secondary-light">References: ' + ref.table + '</small>';
        }

        html += '</div>';
    });

    return html;
}

function openCreateModal() {
    editingId = null;
    $('#crudModalTitle').text('Add New ' + currentSchema.table.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }));
    $('#crudFormLoading').show();
    $('#crudForm').hide();
    $('#recordId').val('');

    if (currentRelated && Object.keys(currentRelated).length > 0) {
        $('#crudFormFields').html(buildFormFields(currentSchema, null, currentRelated));
        $('#crudFormLoading').hide();
        $('#crudForm').show();
    } else {
        loadSchema(currentTable).done(function(res) {
            if (res.success === false) {
                showError(res.error || 'Failed to load schema');
                return;
            }
            currentSchema = res.schema;
            currentRelated = res.related || {};
            $('#crudFormFields').html(buildFormFields(currentSchema, null, currentRelated));
            $('#crudFormLoading').hide();
            $('#crudForm').show();
        }).fail(function() {
            showError('Failed to load schema');
        });
    }

    $('#crudModal').modal('show');
}

function openEditModal(id) {
    editingId = id;
    $('#crudModalTitle').text('Edit ' + currentSchema.table.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }));
    $('#crudFormLoading').show();
    $('#crudForm').hide();
    $('#recordId').val(id);

    $.when(
        loadSchema(currentTable),
        apiPost('get', { table: currentTable, id: id })
    ).done(function(schemaRes, getRes) {
        const schemaData = schemaRes[0];
        const getData = getRes[0];

        if (schemaData.success === false) { showError(schemaData.error || 'Schema error'); return; }
        if (getData.success === false) { showError(getData.error || 'Failed to load record'); return; }
        if (!getData.data) { showError('Record not found'); return; }

        currentSchema = schemaData.schema;
        currentRelated = schemaData.related || {};
        $('#crudFormFields').html(buildFormFields(currentSchema, getData.data, currentRelated));
        $('#crudFormLoading').hide();
        $('#crudForm').show();
    }).fail(function() {
        showError('Failed to load record');
    });

    $('#crudModal').modal('show');
}

function saveRecord() {
    const data = {};
    $('#crudFormFields :input').each(function() {
        const name = $(this).attr('name');
        if (name) {
            data[name] = $(this).val();
        }
    });

    $('#crudSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

    const action = editingId ? 'update' : 'create';
    const payload = { table: currentTable, data: data };
    if (editingId) payload.id = editingId;

    apiPost(action, payload)
        .done(function(res) {
            if (res.success === false) {
                showError(res.error || 'Failed to save record');
                return;
            }
            $('#crudModal').modal('hide');
            showSuccess(editingId ? 'Record updated successfully' : 'Record created successfully');
            refreshData();
        })
        .fail(function(xhr) {
            let msg = 'Failed to save record';
            try {
                const r = JSON.parse(xhr.responseText);
                msg = r.error || msg;
            } catch(e) {}
            showError(msg);
        })
        .always(function() {
            $('#crudSaveBtn').prop('disabled', false).text('Save');
        });
}

function confirmDelete(id) {
    deleteId = id;
    const pk = currentSchema.primary_key || 'id';
    $('#deleteRecordInfo').text('Record #' + id);
    $('#deleteModal').modal('show');
}

function executeDelete() {
    if (!deleteId) return;
    $('#confirmDeleteBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Deleting...');

    apiPost('delete', { table: currentTable, id: deleteId })
        .done(function(res) {
            if (res.success === false) {
                showError(res.error || 'Failed to delete record');
                return;
            }
            $('#deleteModal').modal('hide');
            showSuccess('Record deleted successfully');
            refreshData();
        })
        .fail(function(xhr) {
            let msg = 'Failed to delete record';
            try {
                const r = JSON.parse(xhr.responseText);
                msg = r.error || msg;
            } catch(e) {}
            showError(msg);
        })
        .always(function() {
            $('#confirmDeleteBtn').prop('disabled', false).text('Yes, Delete');
        });
}

$(document).ready(function() {
    loadTables();

    $('#tableSelector').on('change', function() {
        const table = $(this).val();
        if (!table) {
            $('#crudTableWrapper').hide();
            $('#noTableSelected').show();
            $('#addNewBtn').prop('disabled', true);
            $('#tableInfo').text('');
            currentTable = '';
            return;
        }

        currentTable = table;
        currentPage = 1;
        currentSearch = '';
        currentSortBy = '';
        currentSortDir = 'DESC';
        $('#crudSearch').val('');

        const label = allTables.find(function(t) { return t.name === table; });
        $('#tableInfo').text(label ? label.label : table);
        $('#addNewBtn').prop('disabled', false);
        $('#noTableSelected').hide();
        $('#crudTableWrapper').show();

        loadSchema(table).done(function(res) {
            if (res.success === false) {
                showError(res.error || 'Failed to load schema');
                return;
            }
            currentSchema = res.schema;
            currentRelated = res.related || {};
            refreshData();
        }).fail(function() {
            showError('Failed to load table schema');
        });
    });

    $(document).on('click', '.sortable', function() {
        const col = $(this).data('sort');
        const dir = $(this).data('dir');
        currentSortBy = col;
        currentSortDir = dir;
        refreshData();
    });

    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const page = parseInt($(this).data('page'));
        if (!isNaN(page)) {
            currentPage = page;
            refreshData();
        }
    });

    $('#crudSearch').on('keyup', function() {
        currentSearch = $(this).val();
        currentPage = 1;
        refreshData();
    });

    $('#crudPerPage').on('change', function() {
        currentPerPage = parseInt($(this).val());
        currentPage = 1;
        refreshData();
    });

    $('#addNewBtn').on('click', openCreateModal);

    $(document).on('click', '.edit-btn', function() {
        const id = $(this).data('id');
        openEditModal(id);
    });

    $(document).on('click', '.delete-btn', function() {
        const id = $(this).data('id');
        confirmDelete(id);
    });

    $('#crudSaveBtn').on('click', saveRecord);

    $('#confirmDeleteBtn').on('click', executeDelete);

    $('#crudForm').on('submit', function(e) {
        e.preventDefault();
        saveRecord();
    });
});
</script>
</body>
</html>
