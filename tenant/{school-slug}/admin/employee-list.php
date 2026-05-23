<?php require_once __DIR__ . '/includes/handlers/hrm-handler.php'; ?>
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
    <h1 class="fw-semibold mb-4 h6 text-primary-light">Employee List</h1>
    <div class="">
      <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
      <a href="employee-list.html" class="text-secondary-light hover-text-primary hover-underline "> /
        HRM</a>
      <span class="text-secondary-light">/ Employee List</span>
    </div>
  </div>
  <a href="add-new-student.html" class="btn btn-primary-600 d-flex align-items-center gap-6 ">
    <span class="d-flex text-md">
      <i class="ri-add-large-line"></i>
    </span>
    Add Employee
  </a>
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
                            <div class="dropdown">
                                <button type="button"
                                    class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        Filter
                                    </span>
                                    <span class="">
                                        <i class="ri-arrow-down-s-line"></i>
                                    </span>
                                </button>
                                <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                    <div
                                        class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                        <span class="fw-semibold text-lg text-primary-light">Filter</span>
                                        <button type="button">
                                            <i class="ri-close-large-line"></i>
                                        </button>
                                    </div>

                                    <form action="#" class="p-16">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label for="subject"
                                                    class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                                <select id="subject" class="form-control form-select">
                                                    <option value="Select">Select Subject</option>
                                                    <option value="Match">Match</option>
                                                    <option value="English">English</option>
                                                    <option value="Bangla">Bangla</option>
                                                    <option value="Economics">Economics</option>
                                                    <option value="Physics">Physics</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label for="status"
                                                    class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
                                                <select id="status" class="form-control form-select">
                                                    <option value="Select">Select Status</option>
                                                    <option value="Active">Active</option>
                                                    <option value="Inactive">Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <button type="reset"
                                                    class="btn btn-danger-200 text-danger-600 w-100">Reset</button>
                                            </div>
                                            <div class="col-6">
                                                <button type="submit" class="btn btn-primary-600 w-100">Apply</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
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
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Phone Number</th>
                                    <th scope="col">Join Date</th>
                                    <th scope="col">Status</th>
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
                                    <td><span class="text-primary-600">AD52365</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img1.png"
                                                alt="Marvin McKinney Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Marvin McKinney</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Mathematics</td>
                                    <td>1 (A), 2(A), 3(A)</td>
                                    <td>chinthaka@hotmail.com</td>
                                    <td>209.555.0104</td>
                                    <td>05 May 2012</td>
                                    <td> <span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">
                                                01
                                            </label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52365</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img2.png"
                                                alt="Ralph Edwards Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Ralph Edwards</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Physics</td>
                                    <td>9 (A), 10 (B), </td>
                                    <td>mobileip@mac.com</td>
                                    <td>209.555.0104</td>
                                    <td>05 May 2012</td>
                                    <td> <span
                                            class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img3.png"
                                                alt="Courtney Henry Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Courtney Henry</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Biology</td>
                                    <td>6 (A), 7 (B)</td>
                                    <td>courtney@edu.com</td>
                                    <td>209.555.0134</td>
                                    <td>18 Jan 2014</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-user-3-line"></i>View Student</a></li>
                                                <li><a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img4.png"
                                                alt="Eleanor Pena Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Eleanor Pena</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Chemistry</td>
                                    <td>8 (B), 9 (A)</td>
                                    <td>eleanor.pena@school.org</td>
                                    <td>209.555.0189</td>
                                    <td>22 Aug 2016</td>
                                    <td><span
                                            class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-user-3-line"></i>View Student</a></li>
                                                <li><a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img5.png"
                                                alt="Cody Fisher Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Cody Fisher</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>English</td>
                                    <td>5 (A), 6 (A)</td>
                                    <td>cody.fisher@school.com</td>
                                    <td>209.555.0192</td>
                                    <td>14 Mar 2015</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li><a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-user-3-line"></i>View Student</a></li>
                                                <li><a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-edit-2-line"></i>Edit</a></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-error-warning-line"></i>Inactive</button></li>
                                                <li><button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button></li>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img6.png"
                                                alt="Devon Lane Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Devon Lane</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Geography</td>
                                    <td>7 (C), 8 (A)</td>
                                    <td>devon@edu.org</td>
                                    <td>209.555.0119</td>
                                    <td>09 Jul 2018</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img7.png"
                                                alt="Bessie Cooper Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Bessie Cooper</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>History</td>
                                    <td>9 (B), 10 (A)</td>
                                    <td>bessie.cooper@school.org</td>
                                    <td>209.555.0156</td>
                                    <td>23 Feb 2013</td>
                                    <td><span
                                            class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img8.png"
                                                alt="Arlene McCoy Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Arlene McCoy</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Economics</td>
                                    <td>11 (B), 12 (A)</td>
                                    <td>arlene.mccoy@edu.org</td>
                                    <td>209.555.0172</td>
                                    <td>16 Oct 2019</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img9.png"
                                                alt="Annette Black Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Annette Black</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>ICT</td>
                                    <td>8 (A), 9 (B)</td>
                                    <td>annette@school.edu</td>
                                    <td>209.555.0195</td>
                                    <td>05 May 2020</td>
                                    <td><span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
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
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img2.png"
                                                alt="Guy Hawkins Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Guy Hawkins</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Accounting</td>
                                    <td>10 (A), 11 (A)</td>
                                    <td>guy.hawkins@edu.com</td>
                                    <td>209.555.0184</td>
                                    <td>11 Dec 2017</td>
                                    <td><span
                                            class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i>
                                                        View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i>
                                                        Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i>
                                                        Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">11</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52375</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img9.png"
                                                alt="Theresa Webb Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Theresa Webb</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Computer Science</td>
                                    <td>11 (B), 12 (A)</td>
                                    <td>theresa.webb@school.edu</td>
                                    <td>209.555.0217</td>
                                    <td>29 Mar 2019</td>
                                    <td>
                                        <span
                                            class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i> View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i> Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i> Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">12</label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600">AD52376</span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img6.png"
                                                alt="Kathryn Murphy Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Kathryn Murphy</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Environmental Science</td>
                                    <td>9 (A), 10 (B)</td>
                                    <td>kathryn.murphy@college.edu</td>
                                    <td>209.555.0259</td>
                                    <td>03 Feb 2020</td>
                                    <td>
                                        <span
                                            class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>
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
                                                    <a href="student-list.html"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-user-3-line"></i> View Student
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="javascript:void(0)"
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-edit-2-line"></i> Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                        <i class="ri-error-warning-line"></i> Inactive
                                                    </button>
                                                </li>
                                                <li>
                                                    <button
                                                        class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                        data-bs-toggle="modal" data-bs-target="#exampleModalDelete"><i
                                                            class="ri-delete-bin-6-line"></i>Delete</button>
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
                        class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8">
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
</script>

</body>

</html>