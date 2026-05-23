<?php
if (!defined('ACADEMIX_SCHOOL_ADMIN_BOOTSTRAPPED')) {
    require_once __DIR__ . '/includes/admin-bootstrap.php';
}
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Exam Result </h1>
                <div class="">
                    <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <span class="text-secondary-light">/ Exam Result </span>
                </div>
            </div>
        </div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">

                    <div
                        class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
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
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span class="">
                                Rows per page:
                            </span>
                            <div class="dt-length">
                                <select name="dataTable_length" aria-controls="dataTable"
                                    class="dt-input form-control form-select">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="p-0">
                        <table class="table bordered-table mb-0 data-table" id="dataTable" data-page-length='10'>
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
                                    <th scope="col">Admission No</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Roll No</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Exam</th>
                                    <th scope="col">Grand Total </th>
                                    <th scope="col">Percent (%) </th>
                                    <th scope="col">Grade </th>
                                    <th scope="col">Result </th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">
                                                01
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-primary-600">AD52365</span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Kathryn Murphy</h6>

                                            </div>
                                        </div>
                                    </td>
                                    <td>12</td>
                                    <td>Class 1 (A)</td>
                                    <td>Monthly Test</td>
                                    <td>644</td>
                                    <td>92</td>
                                    <td>A+</td>
                                    <td>
                                        <span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">01</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52365</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Kathryn Murphy</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>12</td>
                                    <td>Class 1 (A)</td>
                                    <td>Monthly Test</td>
                                    <td>644</td>
                                    <td>92</td>
                                    <td>A+</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">02</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52366</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img2.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jerome Bell</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>14</td>
                                    <td>Class 2 (B)</td>
                                    <td>Final Exam</td>
                                    <td>578</td>
                                    <td>82</td>
                                    <td>A</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">03</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52367</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img3.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Theresa Webb</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>16</td>
                                    <td>Class 3 (C)</td>
                                    <td>Mid Term</td>
                                    <td>430</td>
                                    <td>70</td>
                                    <td>B+</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">04</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52368</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img4.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Cody Fisher</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>19</td>
                                    <td>Class 4 (A)</td>
                                    <td>Quarterly Test</td>
                                    <td>380</td>
                                    <td>64</td>
                                    <td>B</td>
                                    <td><span
                                            class="bg-danger-100 text-danger-600 px-16 py-2 radius-4 fw-medium text-sm">Fail</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">05</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52369</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img5.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Annette Black</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>10</td>
                                    <td>Class 5 (B)</td>
                                    <td>Final Exam</td>
                                    <td>698</td>
                                    <td>96</td>
                                    <td>A+</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">06</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52370</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img6.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jenny Wilson</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>07</td>
                                    <td>Class 6 (A)</td>
                                    <td>Half Yearly</td>
                                    <td>612</td>
                                    <td>89</td>
                                    <td>A</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">07</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52371</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img7.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Darlene Robertson</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>18</td>
                                    <td>Class 7 (C)</td>
                                    <td>Monthly Test</td>
                                    <td>325</td>
                                    <td>58</td>
                                    <td>C</td>
                                    <td><span
                                            class="bg-danger-100 text-danger-600 px-16 py-2 radius-4 fw-medium text-sm">Fail</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">08</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52372</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img8.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Wade Warren</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>22</td>
                                    <td>Class 8 (A)</td>
                                    <td>Final Exam</td>
                                    <td>510</td>
                                    <td>75</td>
                                    <td>B+</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">09</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52373</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img9.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Esther Howard</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>09</td>
                                    <td>Class 9 (B)</td>
                                    <td>Mid Term</td>
                                    <td>285</td>
                                    <td>46</td>
                                    <td>D</td>
                                    <td><span
                                            class="bg-danger-100 text-danger-600 px-16 py-2 radius-4 fw-medium text-sm">Fail</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">10</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52374</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img10.png" alt="Image"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Guy Hawkins</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>11</td>
                                    <td>Class 10 (C)</td>
                                    <td>Final Exam</td>
                                    <td>715</td>
                                    <td>98</td>
                                    <td>A+</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-eye-line"></i> View
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

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
</script>

</body>

</html>
