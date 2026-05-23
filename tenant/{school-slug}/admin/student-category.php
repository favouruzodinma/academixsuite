<?php require_once __DIR__ . '/includes/handlers/other-handler.php'; ?>
<?php
$csrf = academix_admin_csrf_token();
$categories = [];
try {
    $stmt = $schoolDb->prepare("SELECT * FROM student_categories WHERE school_id = ? ORDER BY id DESC");
    $stmt->execute([$school['id']]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!-- meta tags and other links -->
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="description"
    content="Modern Education Admin Dashboard for schools, colleges, universities, and eLearning platforms. Includes student and course management, attendance, exams, payments, analytics, and a fully responsive clean UI—ideal for LMS, coaching centers, and academic admin systems.">
  <meta name="keywords"
    content="Education Admin Dashboard, School Admin Panel, College Dashboard, University Dashboard, LMS Dashboard, eLearning Admin Template, Student Management System, Course Management, Education Template, Study Dashboard, Online Learning Dashboard, Academic Admin Panel, Bootstrap Dashboard, React Education Dashboard, Next.js Education Template">
  <meta name="robots" content="INDEX,FOLLOW">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Title -->
  <title> <?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?></title>
  <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>

<body>

  <!-- Theme Customization Structure Start -->




<!-- Theme Customization Structure End -->

  <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300">
  </div>
<?php include_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="dashboard-main">
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">

        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h6 class="fw-semibold mb-4">Student Categories</h6>
                <div class="">
                    <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>

                    <span class="text-secondary-light">/ Student Categories</span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6 ">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                New Category
            </button>
        </div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body dataTable-wrapper p-0">

                    <div
                        class="d-flex flex-wrap align-items-center gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="dropdown">
                            <button type="button"
                                class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20 "
                                data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                    <i class="ri-file-upload-line text-md line-height-1"></i>
                                    Export
                                </span>
                                <span class="">
                                    <i class="ri-arrow-down-s-line"></i>
                                </span>
                            </button>
                            <ul class="dropdown-menu p-12 border bg-base shadow">
                                <li>
                                    <button type="button"
                                        class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"
                                        data-bs-toggle="modal" data-bs-target="#exampleModalView">
                                        <i class="ri-file-3-line"></i>
                                        PDF
                                    </button>
                                </li>
                                <li>
                                    <button type="button"
                                        class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"
                                        data-bs-toggle="modal" data-bs-target="#exampleModalEdit">
                                        <i class="ri-file-excel-line"></i>
                                        Excel
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <form class="navbar-search dt-search m-0">
                            <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable"
                                name="search" placeholder="Search...">
                            <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                        </form>

                    </div>

                    <div class="p-0">
                        <table class="table data-table bordered-table mb-0" id="dataTable" data-page-length='10'>
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">
                                                S.L
                                            </label>
                                        </div>
                                    </th>
                                    <th scope="col">Category Name</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl = 1; foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label"><?php echo str_pad($sl++, 2, '0', STR_PAD_LEFT); ?></label>
                                        </div>
                                    </td>
                                    <td><span class=""><?php echo academix_admin_e($cat['name']); ?></span></td>
                                    <td>
                                        <?php 
                                            $stCls = strtolower($cat['status'] ?? 'Active') === 'active' ? 'bg-success-100 text-success-600' : 'bg-danger-100 text-danger-600';
                                        ?>
                                        <span class="<?php echo $stCls; ?> px-24 py-4 radius-4 fw-medium text-sm"><?php echo academix_admin_e($cat['status'] ?? 'Active'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button"
                                                        onclick="editCategory(<?php echo (int)$cat['id']; ?>, '<?php echo academix_admin_e(str_replace("'", "\\'", $cat['name'])); ?>', '<?php echo academix_admin_e($cat['status'] ?? 'Active'); ?>')">
                                                        <i class="ri-edit-2-line"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button"
                                                        onclick="deleteCategory(<?php echo (int)$cat['id']; ?>, '<?php echo academix_admin_e(str_replace("'", "\\'", $cat['name'])); ?>')">
                                                        <i class="ri-delete-bin-6-line"></i>Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-secondary-light py-24">No categories found. Click "New Category" to add one.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- add new category sidebar start -->
<div
    class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Add New Student Category</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form method="POST" action="" class="d-flex flex-column gap-20 p-20">
        <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
        <input type="hidden" name="action" value="create_student_category">
        <div class="">
            <label for="studentCategoryName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student
                Category Name </label>
            <input type="text" class="form-control" id="studentCategoryName" name="name" placeholder="Enter Student Category Name">
        </div>
        <div class="">
            <label for="studentStatus" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
            <select id="studentStatus" name="status" class="form-control form-select">
                <option value="Select a class" disabled>Select a Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
            <button type="reset"
                class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                Cancel
            </button>
            <button type="submit" class="btn btn-primary-600 text-md px-48 py-12 radius-8">
                Save
            </button>
        </div>
    </form>
</div>
<!-- add new category sidebar ebd -->

<!-- edit category sidebar start -->
<div
    class="my-sidebar-edit bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Edit Student Category</h5>
        <button type="button" class="close-my-sidebar-edit text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form method="POST" action="" class="d-flex flex-column gap-20 p-20">
        <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
        <input type="hidden" name="action" value="update_student_category">
        <input type="hidden" name="id" id="edit_category_id" value="">
        <div class="">
            <label for="edit_studentCategoryName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student
                Category Name </label>
            <input type="text" class="form-control" id="edit_studentCategoryName" name="name" placeholder="Enter Student Category Name">
        </div>
        <div class="">
            <label for="edit_studentStatus" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
            <select id="edit_studentStatus" name="status" class="form-control form-select">
                <option value="Select a status" disabled>Select a Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
            <button type="reset"
                class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                Cancel
            </button>
            <button type="submit" class="btn btn-primary-600 text-md px-48 py-12 radius-8">
                Update
            </button>
        </div>
    </form>
</div>
<!-- edit category sidebar end -->

<!-- delete category modal start -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteCategoryModalLabel">Delete Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                <input type="hidden" name="action" value="delete_student_category">
                <input type="hidden" name="id" id="delete_category_id" value="">
                <div class="modal-body">
                    <p>Are you sure you want to delete the category: <strong id="delete_category_name"></strong>?</p>
                    <p class="text-danger-600 text-sm">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- delete category modal end -->

  <!-- jQuery library js -->
  <script src="assets/js/lib/jquery-3.7.1.min.js"></script>
  <!-- Bootstrap js -->
  <script src="assets/js/lib/bootstrap.bundle.min.js"></script>
  <!-- Apex Chart js -->
  <script src="assets/js/lib/apexcharts.min.js"></script>
  <!-- Iconify Font js -->
  <script src="assets/js/lib/iconify-icon.min.js"></script>
  <!-- Data Table js -->
  <script src="assets/js/lib/dataTables.min.js"></script>
  
  <!-- jQuery UI js -->
  <script src="assets/js/lib/jquery-ui.min.js"></script>
  
  <!-- main js -->
  <script src="assets/js/app.js"></script>

<script>
    let table = new DataTable('#dataTable');

    // ✅ Data Table start
    $('.data-table').each(function () {
        const $table = $(this);
        const tableInstance = new DataTable(this);

        // Handle search input (inside same wrapper)
        $table.closest('.dataTable-wrapper').find('.dt-search .dt-input').on('keyup', function () {
            tableInstance.search(this.value).draw();
        });

        // Handle page length change (inside same wrapper)
        $table.closest('.dataTable-wrapper').find('.dt-length .dt-input').on('change', function () {
            const value = $(this).val();
            tableInstance.page.len(value).draw();
        });
    });
    // ✅ Data Table end

    // Add sidebar js start
    $('.my-sidebar-btn').on('click', function () {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-my-sidebar, .overlay').on('click', function () {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });
    // Add sidebar js end

    // Edit sidebar js start
    function editCategory(id, name, status) {
        $('#edit_category_id').val(id);
        $('#edit_studentCategoryName').val(name);
        $('#edit_studentStatus').val(status);
        $('.my-sidebar-edit').addClass('active');
        $('.overlay').addClass('active');
    }
    $('.close-my-sidebar-edit').on('click', function () {
        $('.my-sidebar-edit').removeClass('active');
        $('.overlay').removeClass('active');
    });
    // Edit sidebar js end

    // Delete modal js start
    function deleteCategory(id, name) {
        $('#delete_category_id').val(id);
        $('#delete_category_name').text(name);
        $('#deleteCategoryModal').modal('show');
    }
    // Delete modal js end
</script>

</body>

</html>