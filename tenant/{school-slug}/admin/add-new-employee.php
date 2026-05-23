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
    <h1 class="fw-semibold mb-4 h6 text-primary-light">Add New Employee</h1>
    <div class="">
      <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
      <a href="employee-list.html" class="text-secondary-light hover-text-primary hover-underline "> /
        Employee List</a>
      <span class="text-secondary-light">/ Add New Employee</span>
    </div>
  </div>
  <a href="add-new-employee.html" class="btn btn-primary-600 d-flex align-items-center gap-6 d-none">
    <span class="d-flex text-md">
      <i class="ri-add-large-line"></i>
    </span>
    Add Employee
  </a>
</div>

        <form action="#" class="mt-24">
            <div class="row gy-3">
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Personal Info</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="employeeID"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Employee
                                            ID* <span class="text-danger-600">* </span> </label>
                                        <input type="text" class="form-control" id="employeeID"
                                            placeholder="Enter Employee ID">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="fullName"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Full Name
                                            <span class="text-danger-600">*</span> </label>
                                        <input type="text" class="form-control" id="fullName"
                                            placeholder="Enter your Full Name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="employeeDepartment"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Department
                                        </label>
                                        <select id="employeeDepartment" class="form-control form-select">
                                            <option value="Select a Department" disabled>Select a Department</option>
                                            <option value="English">English</option>
                                            <option value="Mathematics">Mathematics</option>
                                            <option value="Physics">Physics</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="employeeDesignation"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Designation
                                        </label>
                                        <select id="employeeDesignation" class="form-control form-select">
                                            <option value="Select a Class" disabled>Select a Designation</option>
                                            <option value="Team Lead">Team Lead</option>
                                            <option value="Software Engineer">Software Engineer</option>
                                            <option value="Frontend Developer">Frontend Developer</option>
                                            <option value="Backend Developer">Backend Developer</option>
                                            <option value="Full Stack Developer">Full Stack Developer</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="gender"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                        <select id="gender" class="form-control form-select">
                                            <option value="Select section" disabled>Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="dateOfBirth"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Date Of
                                            Birth <span class="text-danger-600">*</span> </label>
                                        <input type="date" class="form-control" id="dateOfBirth">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="fathersName"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Fathers
                                            Name </label>
                                        <input type="text" class="form-control" id="fathersName"
                                            placeholder="Enter Fathers Name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="mothersName"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Mothers
                                            Name </label>
                                        <input type="text" class="form-control" id="mothersName"
                                            placeholder="Enter mothers Name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="meritalStatus"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Merital
                                            Status</label>
                                        <select id="meritalStatus" class="form-control form-select">
                                            <option value="Select a Merital Status" disabled>Select a Merital Status
                                            </option>
                                            <option value="Married">Married</option>
                                            <option value="Unmarried">Unmarried</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="contractType"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Contract
                                            Type</label>
                                        <select id="contractType" class="form-control form-select">
                                            <option value="Select a Contract Type" disabled>Select a Contract Type
                                            </option>
                                            <option value="Contractual">Contractual</option>
                                            <option value="Hourly">Hourly</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="employeeShift"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Shift</label>
                                        <select id="employeeShift" class="form-control form-select">
                                            <option value="Select a shift" disabled>Select a shift</option>
                                            <option value="Day Shift">Day Shift</option>
                                            <option value="Night Shift">Night Shift</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="workLocation"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Work
                                            Location </label>
                                        <input type="text" class="form-control" id="workLocation"
                                            placeholder="Enter work location">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="joinDate"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Join
                                            Date</label>
                                        <input type="date" class="form-control" id="joinDate">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="phoneNumber"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Phone
                                            Number <span class="text-danger-600">*</span> </label>
                                        <input type="tel" class="form-control" id="phoneNumber"
                                            placeholder="Enter your Phone Number">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="employeeEmails"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Email
                                            <span class="text-danger-600">*</span> </label>
                                        <input type="text" class="form-control" id="employeeEmails"
                                            placeholder="Enter your Email">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Employee
                                            Photo <span class="text-danger-600">*</span> </label>
                                        <div
                                            class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                                            <span class="drop-zone__prompt">Darg & drop a file here or click</span>
                                            <input type="file" name="myFile" class="drop-zone__input">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Medical Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="bloodGroup"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Blood
                                            Group</label>
                                        <select id="bloodGroup" class="form-control form-select">
                                            <option value="Select blood group" disabled>Select blood group</option>
                                            <option value="A+">A+</option>
                                            <option value="AB+">AB+</option>
                                            <option value="A-">A-</option>
                                            <option value="AB-">AB-</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="medicalHeight"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Height
                                        </label>
                                        <input type="text" class="form-control" id="medicalHeight"
                                            placeholder="Enter height">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="medicalWeight"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Weight
                                        </label>
                                        <input type="text" class="form-control" id="medicalWeight"
                                            placeholder="Enter Weight">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Bank Details </h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="bankAccountNumber"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Bank
                                            Account Number </label>
                                        <input type="text" class="form-control" id="bankAccountNumber"
                                            placeholder="Enter bank account number">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="bankName"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Bank Name
                                        </label>
                                        <input type="text" class="form-control" id="bankName"
                                            placeholder="Enter bank name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="iFSCCode"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">IFSC Code
                                        </label>
                                        <input type="text" class="form-control" id="iFSCCode"
                                            placeholder="Enter IFSC Code">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="nationalIdentificationNumber"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">National
                                            Identification Number</label>
                                        <input type="text" class="form-control" id="nationalIdentificationNumber"
                                            placeholder="Enter national identification number">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Upload Documents</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="docName"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Doc Name
                                        </label>
                                        <input type="text" class="form-control" id="docName"
                                            placeholder="Enter Doc Name">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Upload
                                            File</label>
                                        <div
                                            class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                                            <span class="drop-zone__prompt">Darg & drop a file here or click</span>
                                            <input type="file" name="myFile" class="drop-zone__input">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-6">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Previous School Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="schoolNamee"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">School
                                            Name </label>
                                        <input type="text" class="form-control" id="schoolNamee"
                                            placeholder="Enter School Name">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="Address"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Address
                                        </label>
                                        <input type="text" class="form-control" id="Address"
                                            placeholder="Enter Address">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-6">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Address</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="currentAddress"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Current
                                            Address </label>
                                        <input type="text" class="form-control" id="currentAddress"
                                            placeholder="Enter Current Address">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="permanentAddress"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Permanent
                                            Address </label>
                                        <input type="text" class="form-control" id="permanentAddress"
                                            placeholder="Enter Permanent Address">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Employee Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="facebookLink"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Facebook
                                        </label>
                                        <input type="text" id="facebookLink" class="form-control"
                                            placeholder="Enter your facebook link">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="linkedInLink"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">LinkedIn
                                        </label>
                                        <input type="text" id="linkedInLink" class="form-control"
                                            placeholder="Enter your LinkedIn link">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="instagramLink"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Instagram
                                        </label>
                                        <input type="text" id="instagramLink" class="form-control"
                                            placeholder="Enter your Instagram link">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="youTubeLink"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">YouTube
                                        </label>
                                        <input type="text" id="youTubeLink" class="form-control"
                                            placeholder="Enter your YouTube link">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div
                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Login Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="myEmail"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Email
                                            <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="email" class="form-control" id="myEmail" placeholder="Enter Email">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="your-password"
                                            class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Password
                                            <span class="text-danger-600">*</span>
                                        </label>
                                        <div class="position-relative">
                                            <input type="password" id="your-password" class="form-control"
                                                placeholder="Enter your password">
                                            <span
                                                class="toggle-password ri-eye-line cursor-pointer position-absolute end-0 top-50 translate-middle-y me-16 text-secondary-light"
                                                data-toggle="#your-password"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="reset"
                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                            Reset
                        </button>
                        <button type="submit"
                            class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </form>
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
    // ================== Password Show Hide Js Start ==========
    function initializePasswordToggle(toggleSelector) {
        $(toggleSelector).on('click', function () {
            $(this).toggleClass("ri-eye-off-line");
            var input = $($(this).attr("data-toggle"));
            if (input.attr("type") === "password") {
                input.attr("type", "text");
            } else {
                input.attr("type", "password");
            }
        });
    }
    // Call the function
    initializePasswordToggle('.toggle-password');
    // ========================= Password Show Hide Js End ===========================

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
    // ========================== Drag & Drop Upload photo Js end ========================

</script>

</body>

</html>