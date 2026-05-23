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
    <h1 class="fw-semibold mb-4 h6 text-primary-light">Teacher Attendance</h1>
    <div class="">
      <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
      <a href="javascript:void(0)" class="text-secondary-light hover-text-primary hover-underline d-none"> /
        Teacher Attendance</a>
      <span class="text-secondary-light">/ Teacher Attendance</span>
    </div>
  </div>
  <a href="add-new-teacher.html" class="btn btn-primary-600 d-flex align-items-center gap-6 d-none">
    <span class="d-flex text-md">
      <i class="ri-add-large-line"></i>
    </span>
    Add Teacher
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
                                                <label for="class"
                                                    class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Subject</label>
                                                <select id="class" class="form-control form-select">
                                                    <option value="English">English</option>
                                                    <option value="Bangla">Bangla</option>
                                                    <option value="Math">Math</option>
                                                    <option value="Physics">Physics</option>
                                                    <option value="Chemistry">Chemistry</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label for="date"
                                                    class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Date</label>
                                                <input type="date" class="form-control" id="date">
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
                                    <th scope="col">Admission No</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Attendance</th>
                                    <th scope="col">Note</th>
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
                                    <td><span class="text-primary-600">AD52365</span></td>
                                    <td>
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img1.png"
                                                alt="Marvin McKinney Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Marvin McKinney</h6>
                                            </div>
                                        </div>
                                    </td>

                                    <td>Mathematics</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio1" id="p1">
                                                <label class="form-check-label" for="p1">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio1" id="l1">
                                                <label class="form-check-label" for="l1">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio1" id="a1">
                                                <label class="form-check-label" for="a1">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio1" id="h1">
                                                <label class="form-check-label" for="h1">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio1" id="ho1">
                                                <label class="form-check-label" for="ho1">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img2.png"
                                                alt="Cody Fisher Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Cody Fisher</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Physics</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio2" id="p2">
                                                <label class="form-check-label" for="p2">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio2" id="l2">
                                                <label class="form-check-label" for="l2">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio2" id="a2">
                                                <label class="form-check-label" for="a2">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio2" id="h2">
                                                <label class="form-check-label" for="h2">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio2" id="ho2">
                                                <label class="form-check-label" for="ho2">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img3.png"
                                                alt="Jenny Wilson Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jenny Wilson</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Biology</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio3" id="p3">
                                                <label class="form-check-label" for="p3">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio3" id="l3">
                                                <label class="form-check-label" for="l3">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio3" id="a3">
                                                <label class="form-check-label" for="a3">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio3" id="h3">
                                                <label class="form-check-label" for="h3">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio3" id="ho3">
                                                <label class="form-check-label" for="ho3">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img4.png"
                                                alt="Guy Hawkins Image" class="flex-shrink-0 me-12 radius-8">
                                            <div class="">
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Guy Hawkins</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>English</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio4" id="p4">
                                                <label class="form-check-label" for="p4">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio4" id="l4">
                                                <label class="form-check-label" for="l4">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio4" id="a4">
                                                <label class="form-check-label" for="a4">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio4" id="h4">
                                                <label class="form-check-label" for="h4">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio4" id="ho4">
                                                <label class="form-check-label" for="ho4">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img5.png"
                                                alt="Esther Howard Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Esther Howard</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Math</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio5" id="p5">
                                                <label class="form-check-label" for="p5">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio5" id="l5">
                                                <label class="form-check-label" for="l5">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio5" id="a5">
                                                <label class="form-check-label" for="a5">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio5" id="h5">
                                                <label class="form-check-label" for="h5">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio5" id="ho5">
                                                <label class="form-check-label" for="ho5">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img6.png"
                                                alt="Jane Cooper Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Jane Cooper</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Chemistry</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio6" id="p6">
                                                <label class="form-check-label" for="p6">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio6" id="l6">
                                                <label class="form-check-label" for="l6">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio6" id="a6">
                                                <label class="form-check-label" for="a6">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio6" id="h6">
                                                <label class="form-check-label" for="h6">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio6" id="ho6">
                                                <label class="form-check-label" for="ho6">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img7.png"
                                                alt="Robert Fox Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Robert Fox</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Biology</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio7" id="p7">
                                                <label class="form-check-label" for="p7">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio7" id="l7">
                                                <label class="form-check-label" for="l7">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio7" id="a7">
                                                <label class="form-check-label" for="a7">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio7" id="h7">
                                                <label class="form-check-label" for="h7">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio7" id="ho7">
                                                <label class="form-check-label" for="ho7">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img8.png"
                                                alt="Theresa Webb Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Theresa Webb</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Mathematics</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio8" id="p8">
                                                <label class="form-check-label" for="p8">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio8" id="l8">
                                                <label class="form-check-label" for="l8">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio8" id="a8">
                                                <label class="form-check-label" for="a8">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio8" id="h8">
                                                <label class="form-check-label" for="h8">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio8" id="ho8">
                                                <label class="form-check-label" for="ho8">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img9.png"
                                                alt="Courtney Henry Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Courtney Henry</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Physics</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio9" id="p9">
                                                <label class="form-check-label" for="p9">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio9" id="l9">
                                                <label class="form-check-label" for="l9">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio9" id="a9">
                                                <label class="form-check-label" for="a9">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio9" id="h9">
                                                <label class="form-check-label" for="h9">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio9" id="ho9">
                                                <label class="form-check-label" for="ho9">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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
                                        <div class="d-flex align-items-center flex-grow-1">
                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img6.png"
                                                alt="Wade Warren Image" class="flex-shrink-0 me-12 radius-8">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1">Wade Warren</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>Biology</td>
                                    <td>
                                        <div class="d-flex align-items-center flex-wrap gap-28">
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio10" id="p10">
                                                <label class="form-check-label" for="p10">Present</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio10" id="l10">
                                                <label class="form-check-label" for="l10">Late</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio10" id="a10">
                                                <label class="form-check-label" for="a10">Absent</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio10" id="h10">
                                                <label class="form-check-label" for="h10">Halfday</label>
                                            </div>
                                            <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                <input class="form-check-input" type="radio" name="radio10" id="ho10">
                                                <label class="form-check-label" for="ho10">Holiday</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td><input type="text" class="form-control" placeholder="Write note..."></td>
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