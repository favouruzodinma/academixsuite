<?php require_once __DIR__ . '/includes/handlers/other-handler.php'; ?>
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Certificate </h1>
                <div class="">
                    <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <span class="text-secondary-light">/ Certificate </span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Certificate
            </button>
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
                                    <th scope="col">Name</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Certificate Name</th>
                                    <th scope="col">Background Image</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">01</label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png" alt="Marvin McKinney"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Marvin McKinney</h6>
                                                <span>Roll No: <span class="fw-semibold">12</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 1 (A)</td>
                                    <td>Transfer Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img2.png" alt="Kathryn Murphy"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Kathryn Murphy</h6>
                                                <span>Roll No: <span class="fw-semibold">18</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 2 (B)</td>
                                    <td>Character Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img3.png" alt="Devon Lane"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Devon Lane</h6>
                                                <span>Roll No: <span class="fw-semibold">21</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 3 (A)</td>
                                    <td>Sports Achievement Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img4.png" alt="Cody Fisher"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Cody Fisher</h6>
                                                <span>Roll No: <span class="fw-semibold">9</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 4 (C)</td>
                                    <td>Merit Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img5.png" alt="Theresa Webb"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Theresa Webb</h6>
                                                <span>Roll No: <span class="fw-semibold">15</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 5 (B)</td>
                                    <td>Attendance Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img6.png" alt="Darrell Steward"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Darrell Steward</h6>
                                                <span>Roll No: <span class="fw-semibold">5</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 6 (A)</td>
                                    <td>Scholarship Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img7.png" alt="Leslie Alexander"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Leslie Alexander</h6>
                                                <span>Roll No: <span class="fw-semibold">11</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 7 (B)</td>
                                    <td>Excellence Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img8.png" alt="Guy Hawkins"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Guy Hawkins</h6>
                                                <span>Roll No: <span class="fw-semibold">17</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 8 (A)</td>
                                    <td>Science Fair Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img9.png" alt="Brooklyn Simmons"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Brooklyn Simmons</h6>
                                                <span>Roll No: <span class="fw-semibold">22</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 9 (C)</td>
                                    <td>Best Student Award</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/avatar-img10.png" alt="Kristin Watson"
                                                class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Kristin Watson</h6>
                                                <span>Roll No: <span class="fw-semibold">19</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Class 10 (A)</td>
                                    <td>Completion Certificate</td>
                                    <td><img src="https://academixsuite.com/tenant/assets/images/thumbs/background-img.png" alt="Image"></td>
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
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModal">
                                                        <i class="ri-eye-line"></i>
                                                        View
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-printer-line"></i>
                                                        Print
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button"
                                                        class="edit-sidebar-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        type="button" data-bs-toggle="modal"
                                                        data-bs-target="#exampleModalDelete">
                                                        <i class="ri-delete-bin-6-line"></i>
                                                        Delete
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

<!-- Add sidebar start -->
<div
    class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Add New Fees Type</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form method="POST" action="" class="d-flex flex-column p-20">
        <input type="hidden" name="action" value="create_certificate">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="">
                    <label for="certificateName"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Certificate Name
                    </label>
                    <input type="text" class="form-control" id="certificateName" name="name" placeholder="Enter certificate name">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctClass" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class
                    </label>
                    <select id="seelctClass" name="class_id" class="form-control form-select">
                        <option value="Select a Class" selected disabled>Select Class</option>
                        <option value="One">One</option>
                        <option value="Two">Two</option>
                        <option value="Three">Three</option>
                        <option value="Four">Four</option>
                        <option value="Five">Five</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctSection"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section
                    </label>
                    <select id="seelctSection" name="section_id" class="form-control form-select">
                        <option value="Select a Section" selected disabled>Select Section</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctStudent"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student
                    </label>
                    <select id="seelctStudent" name="student_id" class="form-control form-select">
                        <option value="Select a Student" selected disabled>Select Student</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Date
                    </label>
                    <input type="date" class="form-control" id="seelctDate" name="date">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="footerLeftText"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Footer Left Text
                    </label>
                    <input type="text" class="form-control" id="footerLeftText" name="footer_left" placeholder="Enter Footer Left Text">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="footerRightText"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Footer Right Text
                    </label>
                    <input type="text" class="form-control" id="footerRightText" name="footer_right" placeholder="Enter Footer Right Text">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Background Image <span class="text-danger-600">*</span> </label>
                    <div
                        class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                        <span class="drop-zone__prompt">Darg & drop a file here or click</span>
                        <input type="file" name="background_image" class="drop-zone__input">
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                    <button type="reset"
                        class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                        Cancel
                    </button>
                    <button type="submit"
                        class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">
                        Save
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
<!-- Add sidebar end -->

<!-- Edit sidebar start -->
<div
    class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Edit Fees Type</h5>
        <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form method="POST" action="" class="d-flex flex-column p-20">
        <input type="hidden" name="action" value="update_certificate">
        <input type="hidden" name="id" id="edit_id" value="">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="">
                    <label for="certificateNameEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Certificate Name
                    </label>
                    <input type="text" class="form-control" id="certificateNameEdit" name="name"
                        placeholder="Enter certificate name">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctClassEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class
                    </label>
                    <select id="seelctClassEdit" name="class_id" class="form-control form-select">
                        <option value="Select a Class" selected disabled>Select Class</option>
                        <option value="One">One</option>
                        <option value="Two">Two</option>
                        <option value="Three">Three</option>
                        <option value="Four">Four</option>
                        <option value="Five">Five</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctSectionEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section
                    </label>
                    <select id="seelctSectionEdit" name="section_id" class="form-control form-select">
                        <option value="Select a Section" selected disabled>Select Section</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctStudentEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student
                    </label>
                    <select id="seelctStudentEdit" name="student_id" class="form-control form-select">
                        <option value="Select a Student" selected disabled>Select Student</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="seelctDateEdit" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Date
                    </label>
                    <input type="date" class="form-control" id="seelctDateEdit" name="date">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="footerLeftTextEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Footer Left Text
                    </label>
                    <input type="text" class="form-control" id="footerLeftTextEdit" name="footer_left"
                        placeholder="Enter Footer Left Text">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label for="footerRightTextEdit"
                        class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Footer Right Text
                    </label>
                    <input type="text" class="form-control" id="footerRightTextEdit" name="footer_right"
                        placeholder="Enter Footer Right Text">
                </div>
            </div>
            <div class="col-sm-6">
                <div class="">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Background
                        Image <span class="text-danger-600">*</span> </label>
                    <div
                        class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                        <span class="drop-zone__prompt">Darg & drop a file here or click</span>
                        <input type="file" name="background_image" class="drop-zone__input">
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                    <button type="reset"
                        class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                        Cancel
                    </button>
                    <button type="submit"
                        class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">
                        Update
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
<!-- Edit sidebar end -->

<!-- View Certificate start -->
<div class="modal fade" id="exampleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="p-0 text-end mb-16">
                <button type="button" class="btn-close invert" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <img src="https://academixsuite.com/tenant/assets/images/thumbs/certificate-img.png" alt="Certificate Image">
            </div>
        </div>
    </div>
</div>
<!-- View Certificate end -->

<!-- Modal Delete Event start -->
<div class="modal fade" id="exampleModalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are your sure you want to Suspend this teacher
                </h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="reset"
                        class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="button"
                        class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
                        Yes, Suspend
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal Delete Event end -->

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

    // Sidebar js start
    $('.my-sidebar-btn').on('click', function () {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-my-sidebar, .overlay').on('click', function () {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    $('.edit-sidebar-btn').on('click', function () {
        $('.edit-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-edit-sidebar, .overlay').on('click', function () {
        $('.edit-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });
    // Sidebar js end

    // ========================== Drag & Drop Upload photo Js start ========================
    document.querySelectorAll(".drop-zone__input").forEach((inputElement) => {
        const dropZoneElement = inputElement.closest(".drop-zone");

        dropZoneElement.addEventListener("click", (e) => {
            inputElement.click();
        });

        inputElement.addEventListener("change", (e) => {
            if (inputElement.files.length) {
                updateThumbnail(dropZoneElement, inputElement.files[0]);
            }
        });

        dropZoneElement.addEventListener("dragover", (e) => {
            e.preventDefault();
            dropZoneElement.classList.add("drop-zone--over");
        });

        ["dragleave", "dragend"].forEach((type) => {
            dropZoneElement.addEventListener(type, (e) => {
                dropZoneElement.classList.remove("drop-zone--over");
            });
        });

        dropZoneElement.addEventListener("drop", (e) => {
            e.preventDefault();

            if (e.dataTransfer.files.length) {
                inputElement.files = e.dataTransfer.files;
                updateThumbnail(dropZoneElement, e.dataTransfer.files[0]);
            }

            dropZoneElement.classList.remove("drop-zone--over");
        });
    });

    /**
     * Updates the thumbnail on a drop zone element.
     *
     * @param {HTMLElement} dropZoneElement
     * @param {File} file
     */
    function updateThumbnail(dropZoneElement, file) {
        let thumbnailElement = dropZoneElement.querySelector(".drop-zone__thumb");

        // First time - remove the prompt
        if (dropZoneElement.querySelector(".drop-zone__prompt")) {
            dropZoneElement.querySelector(".drop-zone__prompt").remove();
        }

        // First time - there is no thumbnail element, so lets create it
        if (!thumbnailElement) {
            thumbnailElement = document.createElement("div");
            thumbnailElement.classList.add("drop-zone__thumb");
            dropZoneElement.appendChild(thumbnailElement);
        }

        thumbnailElement.dataset.label = file.name;

        // Show thumbnail for image files
        if (file.type.startsWith("image/")) {
            const reader = new FileReader();

            reader.readAsDataURL(file);
            reader.onload = () => {
                thumbnailElement.style.backgroundImage = `url('${reader.result}')`;
            };
        } else {
            thumbnailElement.style.backgroundImage = null;
        }
    }
    // ========================== Drag & Drop Upload photo Js end ========================.
</script>

</body>

</html>